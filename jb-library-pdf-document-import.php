<?php
/**
 * PDF Media Import WP-CLI Command
 *
 * Requires WP-CLI to be installed and activated.
 *
 * Usage:
 *   wp pdf-media-scrape-and-import [--for-real] [--batch-size=<number>] [--skip-confirmations] [--stockcode-terms=<prefixes>]
 *
 * Examples:
 *   wp pdf-media-scrape-and-import --for-real
 *   wp pdf-media-scrape-and-import --skip-confirmations
 *   wp pdf-media-scrape-and-import --subdirectory-path=SDS --stockcode-terms=20
 *
 * Run the above commands from the terminal.
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once __DIR__ . '/jb-library-transport-extractor.php';

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

if ( ! function_exists( 'jb_library_get_stockcode_terms_filter' ) ) {
    function jb_library_get_stockcode_terms_filter( array $assoc_args ): array {
        if ( ! isset( $assoc_args['stockcode-terms'] ) || ! is_string( $assoc_args['stockcode-terms'] ) || '' === trim( $assoc_args['stockcode-terms'] ) ) {
            return array();
        }

        $requested_prefixes = array_values(
            array_filter(
                array_map(
                    function ( string $prefix ): string {
                        return strtoupper( trim( $prefix ) );
                    },
                    explode( ',', $assoc_args['stockcode-terms'] )
                )
            )
        );

        $valid_prefixes = array_keys( JB_LIBRARY_STOCKCODE_PREFIX_TERMS );
        $invalid_prefixes = array_diff( $requested_prefixes, $valid_prefixes );
        if ( ! empty( $invalid_prefixes ) ) {
            WP_CLI::error( 'Invalid --stockcode-terms value(s): ' . implode( ', ', $invalid_prefixes ) . '. Valid values: ' . implode( ', ', $valid_prefixes ) );
        }

        return $requested_prefixes;
    }

    function jb_library_filter_pdf_files_by_stockcode_terms( array $pdf_files, array $stockcode_prefixes ): array {
        if ( empty( $stockcode_prefixes ) ) {
            return $pdf_files;
        }

        return array_values(
            array_filter(
                $pdf_files,
                function ( string $file_path ) use ( $stockcode_prefixes ): bool {
                    $file_name = strtoupper( basename( $file_path ) );
                    foreach ( $stockcode_prefixes as $prefix ) {
                        if ( 0 === strpos( $file_name, $prefix ) ) {
                            return true;
                        }
                    }

                    return false;
                }
            )
        );
    }

    function jb_library_stockcode_filter_label( array $stockcode_prefixes ): string {
        if ( empty( $stockcode_prefixes ) ) {
            return '';
        }

        return implode( ',', $stockcode_prefixes );
    }
}

if ( ! class_exists( 'PDF_Media_Scrape_And_Import_Command' ) ) {
    class PDF_Media_Scrape_And_Import_Command {

        /**
         * Whether to actually import content.
         *
         * @var bool
         */
        private $for_real = false;

        /**
         * Wheter to skip confirmation prompts.
         *
         * @var bool
         */
        private $skip_confirmations = false;

        /**
         * Number of posts to process per batch.
         *
         * @var int
         */
        private $batch_size = 100;

        /**
         * Subdirectory path within the uploads directory to process.
         *
         * @var string
         */
        private $directory_path = '';

        /**
         * Total number of PDF files found in the specified directory.
         * @var int
         */
        private $number_of_pdfs = 0;

        /**
         * Optional stockcode terms prefixes to process.
         *
         * @var array<int,string>
         */
        private $stockcode_prefixes = array();

        /**
         * Holds unique post titles to check for duplicates.
         *
         * @var array
         */
        private $previously_imported_files = array();

        /**
         * Holds file names of skipped files.
         * @var array
         */
        private $skipped_files_to_log = array();

        /**
         * Holds the information of the files which were processed
         * @var array
         */
        private $processed_files_to_log = array();

        /**
         * Holds the information of the files which were processed
         * @var array
         */
        private $failed_imports_to_log = array();

        /**
         * The count of unreadable PDFs found during import
         * @var int
         */
        private $number_of_unreadable_pdfs = 0;

        /**
         * Total number of PDF posts imported into the media and document libraries.
         *
         * @var int
         */
        private $total_processed_files = 0;

        /**
         * Search for duplicate PDF media files.
         *
         * @param array $args Positional arguments (not used).
         * @param array $assoc_args Associative arguments (e.g., --dry-run, --start-post-id, --batch-size).
         * @return void
         * @when after_wp_load
         */
        public function __invoke( $args, $assoc_args ): void {
            // Determine if we are running in dry run mode
            $this->for_real = isset( $assoc_args['for-real'] );
            if ( $this->for_real ) {
                WP_CLI::log( 'Running in live mode, FOR REAL. Files will be imported.'  );
            } else {
                WP_CLI::log( ' Running in test mode. No files will be imported.' );
            }

            // Determine if we are running in dry run mode
            $this->skip_confirmations = isset( $assoc_args['skip-confirmations'] );
            if ( $this->skip_confirmations ) {
                WP_CLI::log( 'Cofirmations will be skipped.' );
            }

            // Set the batch size if provided
            if ( isset( $assoc_args['batch-size'] ) && is_numeric( $assoc_args['batch-size'] ) ) {
                $this->batch_size = intval( $assoc_args['batch-size'] );
            }
            WP_CLI::log( "Batch size set to: {$this->batch_size}" );

            $this->stockcode_prefixes = jb_library_get_stockcode_terms_filter( $assoc_args );
            if ( ! empty( $this->stockcode_prefixes ) ) {
                WP_CLI::log( 'Stockcode terms filter: ' . jb_library_stockcode_filter_label( $this->stockcode_prefixes ) );
            }

            // Set the directory path
            $wp_uploads_dir = wp_get_upload_dir();
            $this->directory_path = $wp_uploads_dir['basedir'] . '/';
            if ( isset( $assoc_args['subdirectory-path'] ) && is_string( $assoc_args['subdirectory-path'] ) ) {
                $this->directory_path .= rtrim( $assoc_args['subdirectory-path'], '/' ) . '/';
            }

            if ( is_dir( $this->directory_path ) ) {
                WP_CLI::confirm( "Use directory path: {$this->directory_path} ?", 'yes' );
            } else {
                WP_CLI::error( "The specified directory does not exist: {$this->directory_path}" );
                return;
            }

            // Fetch the names of any files which have already been imported
            $this->previously_imported_files = get_option( 'one-time-script-pdf-libraries-imported-file-names', array() );
            if ( ! empty( $this->previously_imported_files ) ) {
                WP_CLI::log( 'Loaded previously imported file names from options.' );
            } else {
                WP_CLI::log( 'No previously imported file names found in options. Starting from scratch.' );
            }

            // Being the import process
            $this->import_pdfs();
        }

        /**
         * Import PDF media files from the specified directory.
         *
         * @param none
         * @return void
         */
        public function import_pdfs(): void {
            WP_CLI::log( 'Starting PDF media import...' );

            // Get all PDF files in the directory
            $pdf_files = glob( $this->directory_path . '*.pdf' );
            $total_pdf_files_before_filter = count( $pdf_files );
            $pdf_files = jb_library_filter_pdf_files_by_stockcode_terms( $pdf_files, $this->stockcode_prefixes );
            if ( empty( $pdf_files ) ) {
                WP_CLI::log( 'No PDF files found in the specified directory' . ( ! empty( $this->stockcode_prefixes ) ? ' for stockcode terms ' . jb_library_stockcode_filter_label( $this->stockcode_prefixes ) : '' ) . '.' );
                return;
            }

            if ( ! empty( $pdf_files ) ) {
                // If this is for real, get all of the current document posts to check if a post already exists
                if ( $this->for_real) {
                    global $wpdb;

                    $existing_document_posts = $wpdb->get_results(
                        "SELECT ID, post_title
                        FROM $wpdb->posts
                        WHERE post_type = 'dlp_document'
                        ",
                        ARRAY_A
                    );

                    $existing_document_posts_by_name = wp_list_pluck( $existing_document_posts, 'ID', 'post_title' );
                    WP_CLI::log( 'Loaded existing document posts from the database.' );
                }

                $this->number_of_pdfs = count( $pdf_files );
                WP_CLI::log( "Found {$this->number_of_pdfs} PDF files in {$this->directory_path}." );
                if ( ! empty( $this->stockcode_prefixes ) ) {
                    WP_CLI::log( "Stockcode terms filter matched {$this->number_of_pdfs} of {$total_pdf_files_before_filter} PDF files." );
                }

                // Track whether we stopped early due to hitting the batch size
                $stopped_due_to_batch = false;

                foreach ( $pdf_files as $file_number => $file_path ) {

                     // Check if we've reached the batch size limit
                    if ( $this->total_processed_files === $this->batch_size ) {
                        WP_CLI::log( "Reached batch size limit of {$this->batch_size}. Stopping import." );
                        $stopped_due_to_batch = true;
                        break;
                    }

                    // Set up the file Importer
                    $importer = new JB_Library_File_Importer( $file_path );

                    // Universal skip: if this file path was already recorded as processed,
                    // skip it for both dry-run and for-real runs so batches resume correctly.
                    if ( in_array( $file_path, $this->previously_imported_files, true ) ) {
                        WP_CLI::log( "Skipping already imported file (recorded): {$file_path}" );
                        // Record the skipped file info for logging later
                        $this->skipped_files_to_log[$importer->file_name] = array(
                            'file_path' => $file_path,
                            'existing_post_id' => $this->for_real ? ( $existing_document_posts_by_name[ $importer->file_name ] ?? '--recorded--' ) : '--dry-run--',
                        );
                        WP_CLI::log( "Skipped files so far: " . count( $this->skipped_files_to_log ) );
                        continue;
                    }

                    // Handle for-real actions
                    if ( $this->for_real ) {
                        // Skip files that have already been imported
                        if (
                            array_key_exists(
                                $importer->file_name,
                                $existing_document_posts_by_name
                            )
                        ) {
                            WP_CLI::log( "Skipping already imported file (post exists): {$file_path} as post ID {$existing_document_posts_by_name[ $importer->file_name ]}" );

                            // Store the skipped file info for logging later
                            $this->skipped_files_to_log[$importer->file_name] = array(
                                'file_path' => $file_path,
                                'existing_post_id'   => $existing_document_posts_by_name[ $importer->file_name ],
                            );
                            WP_CLI::log( "Skipped files so far: " . count( $this->skipped_files_to_log ) );

                            // Carry on
                            continue;
                        }

                        // If the file isn't skipped, import it
                        WP_CLI::log( "Importing file ({$file_number} of {$this->number_of_pdfs}): {$file_path}" );
                        $result = $importer->import_file();
                        if ( is_wp_error( $result ) ) {
                            $this->failed_imports_to_log[$importer->file_name] = array(
                                'file_name' => $importer->file_name,
                                'file_path' => $file_path,
                                'error_message'   => $result->get_error_message(),
                            );
                            WP_CLI::error( "Failed to import file {$file_path}: " . $result->get_error_message() );
                            continue;
                        }
                        WP_CLI::log( "Successfully imported file: {$file_path} as post ID {$result}" );
                    }

                    // Handle dry-run actions
                    if ( ! $this->for_real ) {
                        if ( in_array( $file_path, $this->previously_imported_files, true ) ) {
                            WP_CLI::log( "Skipping already imported file: {$file_path}" );

                            // Store the skipped file info for logging later
                            $this->skipped_files_to_log[$importer->file_name] = array(
                                'file_path' => $file_path,
                                'existing_post_id'   => '--dry-run--',
                            );
                            WP_CLI::log( "Skipped files so far: " . count( $this->skipped_files_to_log ) );

                            // Carry on
                            continue;
                        }

                        // If the file shouldn't be skipped, simulate the import
                        WP_CLI::log( "Dry run: Would import file: {$file_path}" );
                        WP_CLI::log( "Import Details:
                            File Name: {$importer->file_name}
                            File Type: {$importer->file_type}
                            Category ID: {$importer->category_id}
                            Tag Slug: {$importer->tag_slug}
                            Author ID: {$importer->author_id}
                            Is PDF Text Readable: " . ( $importer->scraper->is_pdf_readable ? 'Yes' : 'No' )
                        );
                        WP_CLI::log( "Total processed files so far: " . $this->total_processed_files + 1 );
                    }

                    $this->processed_files_to_log[$importer->file_name] = array(
                        'file_path'    => $file_path,
                        'file_type'    => $importer->file_type,
                        'category_id'  => $importer->category_id,
                        'tag_slug'     => $importer->tag_slug,
                        'author_id'    => $importer->author_id,
                        'is_readable'  => $importer->scraper->is_pdf_readable ? 'Yes' : 'No',
                        'post_id'      =>  $this->for_real ? $importer->document_id : '--dry-run--',
                        'attachment_id' => $this->for_real ? $importer->attachment_id : '--dry-run--',
                    );

                    if( ! $importer->scraper->is_pdf_readable ) {
                        $this->number_of_unreadable_pdfs++;
                        WP_CLI::log( "Warning: The PDF text is not readable for file: {$file_path}. Total unreadable PDFs so far: {$this->number_of_unreadable_pdfs}" );
                    }

                    // Record the file as processed
                    $this->previously_imported_files[] = $file_path;
                    $this->total_processed_files++;

                    // Persist progress immediately so a subsequent batch can resume
                    update_option( 'one-time-script-pdf-libraries-imported-file-names', $this->previously_imported_files );
                    update_option( 'one-time-script-pdf-libraries-last-processed', $file_path );
                }

                // Log the results
                $this->log_results();

                // Clear, explicit final message so it's clear whether the run finished or stopped early
                if ( ! empty( $stopped_due_to_batch ) ) {
                    WP_CLI::log( "Import stopped after reaching batch size limit of {$this->batch_size}. Run again to continue processing remaining files." );
                } else {
                    WP_CLI::success( "PDF media import completed." );
                }

            }
        }

        /**
         * Handle logging the results of the import process.
         * @return void
         */
        private function log_results(): void {

            WP_CLI::log( "----------------------------------------" );
            WP_CLI::log( "Processed {$this->total_processed_files} PDFs of {$this->number_of_pdfs} PDF files found." );
            WP_CLI::log( "Skipped files: " . count( $this->skipped_files_to_log ) );
            WP_CLI::log( "Total unique files imported: " . count( $this->previously_imported_files ) );

            // Log the number of unreadable PDFs found during this import
            WP_CLI::log( "Total unreadable PDFs found during this import: {$this->number_of_unreadable_pdfs}" );
            WP_CLI::log( "Percent of unreadable PDFs in this batch: " . ( $this->number_of_pdfs > 0 ? round( ( $this->number_of_unreadable_pdfs / $this->batch_size ) * 100, 2 ) : 0 ) . "%" );

            // Log the number of unreadable PDFs found during all imports, this is the cumulative total
            $total_unreadable_pdfs = (int) get_option( 'one-time-script-pdf-libraries-unreadable-pdf-count', 0 ) + $this->number_of_unreadable_pdfs;
            update_option( 'one-time-script-pdf-libraries-unreadable-pdf-count', $total_unreadable_pdfs );
            WP_CLI::log( "Cumulative total of unreadable PDFs across all imports: {$total_unreadable_pdfs}" );
            WP_CLI::log( "Cumulative percent of unreadable PDFs across all imports: " . ( $this->number_of_pdfs > 0 ? round( ( $total_unreadable_pdfs / count( $this->previously_imported_files ) ) * 100, 2 ) : 0 ) . "%" );

            // Save the list of processed files to options
            update_option( 'one-time-script-pdf-libraries-imported-file-names', $this->previously_imported_files );
            WP_CLI::log( 'Updated the list of imported file names in options.' );
            WP_CLI::log( "----------------------------------------" );

            // Get ready to write CSV logs
            // Create the logs directory if it doesn't exist
            if ( ! is_dir( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' ) ) {
                WP_CLI::log( 'Creating logs directory...' );
                wp_mkdir_p( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' );
            }
            // Set the CSV file prefix based on mode
            $csv_preffix = $this->for_real ? 'for-real-' : 'dry-run-';

            // Write the processed files to a CSV file
            if (  ! empty( $this->processed_files_to_log ) ) {
                $processed_csv_file_path = fopen( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . $csv_preffix . 'pdf-media-import-' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $processed_csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for duplicate posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $processed_csv_file_path,
                    $this->processed_files_to_log,
                    array(
                        'file_path',
                        'file_type',
                        'category_id',
                        'tag_slug',
                        'author_id',
                        'is_readable',
                        'post_id',
                        'attachment_id',
                    ),
                );

                WP_CLI::log( "Processed files written to CSV file: {$processed_csv_file_path}" );
                fclose( $processed_csv_file_path );
            }

            // Write the skipped files to a CSV file
            if (  ! empty( $this->skipped_files_to_log ) ) {
                $skipped_csv_file_path = fopen( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . $csv_preffix . 'pdf-media-skipped' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $skipped_csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for duplicate posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $skipped_csv_file_path,
                    $this->skipped_files_to_log,
                    array(
                        'file_path',
                        'existing_post_id',
                    ),
                );

                WP_CLI::log( "Skipped files written to CSV file: {$skipped_csv_file_path}" );
                fclose( $skipped_csv_file_path );
            }

            // Log the number of failed imports during this import
            WP_CLI::log( "Total failed imports during this import: " . count( $this->failed_imports_to_log ) );
            $all_failed_imports = array_merge(
                $this->failed_imports_to_log,
                get_option( 'one-time-script-pdf-libraries-failed-imports', array() )
            );
            update_option( 'one-time-script-pdf-libraries-failed-imports', $all_failed_imports );
            WP_CLI::log( "Total failed imports across all batches: " . count( $all_failed_imports ) );

            // Write the failed files to a CSV file
            if (  ! empty( $this->failed_imports_to_log ) ) {
                $failed_csv_file_path = fopen( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . $csv_preffix . 'pdf-media-failed-imports' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $failed_csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for duplicate posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $failed_csv_file_path,
                    $this->failed_imports_to_log,
                    array(
                        'file_name',
                        'file_path',
                        'error_message',
                    ),
                );

                WP_CLI::log( "Failed file imports written to CSV file: {$failed_csv_file_path}" );
                fclose( $failed_csv_file_path );
            }
        }
    }
    WP_CLI::add_command( 'pdf-media-scrape-and-import', 'PDF_Media_Scrape_And_Import_Command' );
}

if ( class_exists( 'PDF_Media_Scrape_And_Import_Command' ) ) {
    /**
     * Clear out fields stored in wp_options related to PDF media import.
     * This is useful for resetting the import process.
     *
     * Usage:
     *  wp pdf-media-import-clear-options
     *
     * @param none
     * @return void
     */
    function clear_pdf_media_import_options(): void {
        delete_option( 'one-time-script-pdf-libraries-imported-file-names' );
        delete_option( 'one-time-script-pdf-libraries-unreadable-pdf-count' );
        WP_CLI::log( 'Cleared PDF media import options.' );
    }
    WP_CLI::add_command( 'pdf-media-import-clear-options', 'clear_pdf_media_import_options' );


    /**
     * Clear out CSV Log files stored in jb-library-maintenance/logs related to PDF media import.
     *
     * Usage:
     *  wp pdf-media-import-delete-logs
     *
     * @param none
     * @return void
     */
    function delete_pdf_media_import_log_files(): void {
        WP_CLI::confirm( 'Are you sure you want to delete all PDF media import log files? If you need a CSV record of changes, make sure to download it before continuing.', 'yes' );
        $log_files = glob( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . '*.csv' );
        if ( ! empty( $log_files ) ) {
            foreach ( $log_files as $file ) {
                @unlink( $file );
            }
            WP_CLI::log( 'Deleted all PDF media import log CSV files.' );
        } else {
            WP_CLI::log( 'No log CSV files found to delete.' );
        }
    }
    WP_CLI::add_command( 'pdf-media-import-delete-logs', 'delete_pdf_media_import_log_files' );

    function jb_library_update_dlp_document_links( $args, $assoc_args ): void {
        global $wpdb;

        $old_url = isset( $assoc_args['old-url'] ) ? trim( $assoc_args['old-url'] ) : '';
        $new_url = isset( $assoc_args['new-url'] ) ? trim( $assoc_args['new-url'] ) : '';
        $dry_run = isset( $assoc_args['dry-run'] );
        $limit = isset( $assoc_args['limit'] ) && is_numeric( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;

        if ( $old_url === '' || $new_url === '' ) {
            WP_CLI::error( 'Please provide both --old-url and --new-url.' );
            return;
        }

        $like = '%' . $wpdb->esc_like( $old_url ) . '%';
        $query = $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", '_dlp_direct_link_url', $like );
        if ( $limit > 0 ) {
            $query .= ' LIMIT ' . $limit;
        }

        $rows = $wpdb->get_results( $query );

        if ( empty( $rows ) ) {
            WP_CLI::log( 'No DLP document direct link metadata found matching the specified old URL.' );
            return;
        }

        $updated = 0;
        foreach ( $rows as $row ) {
            if ( strpos( $row->meta_value, $old_url ) === false ) {
                continue;
            }
            $new_value = str_replace( $old_url, $new_url, $row->meta_value );
            if ( $new_value === $row->meta_value ) {
                continue;
            }
            WP_CLI::log( sprintf( 'Post ID %d: %s => %s', $row->post_id, $row->meta_value, $new_value ) );
            if ( ! $dry_run ) {
                $wpdb->update(
                    $wpdb->postmeta,
                    [ 'meta_value' => $new_value ],
                    [ 'meta_id' => $row->meta_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
            }
            $updated++;
        }

        if ( $dry_run ) {
            WP_CLI::success( sprintf( 'Dry run complete. %d matching DLP document links would be updated.', $updated ) );
        } else {
            WP_CLI::success( sprintf( 'Updated %d DLP document direct link values.', $updated ) );
        }
    }

    WP_CLI::add_command( 'dlp-update-document-links', 'jb_library_update_dlp_document_links' );

    /**
     * Update stored DLP attachment source metadata.
     *
     * Usage:
     *  wp dlp-update-attachment-sources --blog-id=2 --dry-run
     *  wp dlp-update-attachment-sources --blog-id=2
     *  wp dlp-update-attachment-sources --old-value="/uploads/TDS/" --new-value="/uploads/product-documentation/TDS/" --blog-id=2 --dry-run
     */
    function jb_library_update_dlp_attachment_sources( $args, $assoc_args ): void {
        global $wpdb;

        $old_value = isset( $assoc_args['old-value'] ) ? trim( $assoc_args['old-value'] ) : '';
        $new_value = isset( $assoc_args['new-value'] ) ? trim( $assoc_args['new-value'] ) : '';
        $blog_id   = isset( $assoc_args['blog-id'] ) && is_numeric( $assoc_args['blog-id'] ) ? absint( $assoc_args['blog-id'] ) : get_current_blog_id();
        $dry_run   = isset( $assoc_args['dry-run'] );
        $limit     = isset( $assoc_args['limit'] ) && is_numeric( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;

        if ( ( '' === $old_value && '' !== $new_value ) || ( '' !== $old_value && '' === $new_value ) ) {
            WP_CLI::error( 'Please provide both --old-value and --new-value, or omit both to update SDS and TDS defaults.' );
            return;
        }

        $replacement_pairs = array();
        if ( '' !== $old_value && '' !== $new_value ) {
            $replacement_pairs[] = array(
                'old' => $old_value,
                'new' => $new_value,
            );
        } else {
            $replacement_pairs = array(
                array(
                    'old' => '/uploads/SDS/',
                    'new' => '/uploads/product-documentation/SDS/',
                ),
                array(
                    'old' => '/uploads/TDS/',
                    'new' => '/uploads/product-documentation/TDS/',
                ),
                array(
                    'old' => '/uploads/sites/2/SDS/',
                    'new' => '/uploads/product-documentation/SDS/',
                ),
                array(
                    'old' => '/uploads/sites/2/TDS/',
                    'new' => '/uploads/product-documentation/TDS/',
                ),
            );
        }

        if ( is_multisite() && $blog_id !== get_current_blog_id() ) {
            switch_to_blog( $blog_id );
        }

        $like_clauses = array();
        foreach ( $replacement_pairs as $replacement_pair ) {
            $like_clauses[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $replacement_pair['old'] ) . '%' );
        }

        $query = $wpdb->prepare(
            "SELECT meta_id, post_id, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = %s
            AND (" . implode( ' OR ', $like_clauses ) . ")
            ORDER BY post_id",
            '_dlp_attachment_source'
        );
        if ( $limit > 0 ) {
            $query .= ' LIMIT ' . $limit;
        }

        $rows = $wpdb->get_results( $query );

        if ( empty( $rows ) ) {
            WP_CLI::log( 'No DLP attachment source metadata found matching the specified old value.' );
            if ( is_multisite() && ms_is_switched() ) {
                restore_current_blog();
            }
            return;
        }

        $updated = 0;
        foreach ( $rows as $row ) {
            $replaced_value = $row->meta_value;
            foreach ( $replacement_pairs as $replacement_pair ) {
                $replaced_value = str_ireplace( $replacement_pair['old'], $replacement_pair['new'], $replaced_value );
            }

            if ( $replaced_value === $row->meta_value ) {
                continue;
            }

            WP_CLI::log( sprintf( 'Post ID %d: %s => %s', $row->post_id, $row->meta_value, $replaced_value ) );
            if ( ! $dry_run ) {
                $wpdb->update(
                    $wpdb->postmeta,
                    array( 'meta_value' => $replaced_value ),
                    array( 'meta_id' => $row->meta_id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }
            $updated++;
        }

        if ( is_multisite() && ms_is_switched() ) {
            restore_current_blog();
        }

        if ( $dry_run ) {
            WP_CLI::success( sprintf( 'Dry run complete. %d DLP attachment source values would be updated.', $updated ) );
        } else {
            WP_CLI::success( sprintf( 'Updated %d DLP attachment source values.', $updated ) );
        }
    }

    WP_CLI::add_command( 'dlp-update-attachment-sources', 'jb_library_update_dlp_attachment_sources' );

    /**
     * Clear Document Library Pro table data transients.
     *
     * Usage:
     *  wp dlp-clear-table-cache --blog-id=2
     */
    function jb_library_clear_dlp_table_cache( $args, $assoc_args ): void {
        global $wpdb;

        $blog_id = isset( $assoc_args['blog-id'] ) && is_numeric( $assoc_args['blog-id'] ) ? absint( $assoc_args['blog-id'] ) : get_current_blog_id();

        if ( is_multisite() && $blog_id !== get_current_blog_id() ) {
            switch_to_blog( $blog_id );
        }

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                WHERE option_name LIKE %s
                OR option_name LIKE %s",
                '\_transient\_dlp\_%\_data',
                '\_transient\_timeout\_dlp\_%\_data'
            )
        );

        if ( is_multisite() && ms_is_switched() ) {
            restore_current_blog();
        }

        WP_CLI::success( sprintf( 'Deleted %d Document Library Pro table cache transient rows.', (int) $deleted ) );
    }

    WP_CLI::add_command( 'dlp-clear-table-cache', 'jb_library_clear_dlp_table_cache' );

    /**
     * Check that the PDF parser is able to read the content of PDF files in a specified directory.
     *
     * Usage:
     *  wp check-pdf-media-detail-for-sds
     *
     * @param array $assoc_args
     * - Arguments include:
     *  --subdirectory-path - Subdirectory path for the group of PDFs to be processed
     *  --batch-size - Number of PDF files to process in each batch (default: 100)
     *  --stockcode-terms - Stockcode prefix terms to process, such as "20" or "20,CR"
     * @return void
     */
    function check_pdf_media_sds_content( array $args, array $assoc_args = []): void {

        // Set the batch size
        if ( $assoc_args['batch-size'] && is_numeric( $assoc_args['batch-size'] ) ) {
            $batch_size = intval( $assoc_args['batch-size'] );
        } else {
            $batch_size = 100;
        }
        WP_CLI::confirm( "Batch size set to: {$batch_size}. Continue?", 'yes' );

        $stockcode_prefixes = jb_library_get_stockcode_terms_filter( $assoc_args );
        if ( ! empty( $stockcode_prefixes ) ) {
            WP_CLI::log( 'Stockcode terms filter: ' . jb_library_stockcode_filter_label( $stockcode_prefixes ) );
        }

        // Set the directory path
        $wp_uploads_dir = wp_get_upload_dir();
        $directory_path = $wp_uploads_dir['basedir'] . '/';
        if ( isset( $assoc_args['subdirectory-path'] ) && is_string( $assoc_args['subdirectory-path'] ) ) {
            $directory_path .= rtrim( $assoc_args['subdirectory-path'], '/' ) . '/';
        }
        WP_CLI::confirm( "Use directory path: {$directory_path} ?", 'yes' );
        if ( ! is_dir( $directory_path ) ) {
            WP_CLI::error( "The specified directory does not exist: {$directory_path}" );
            return;
        }

        // Get all PDF files in the directory
        $pdf_files = glob( $directory_path . '*.pdf' );
        $total_pdf_files_before_filter = count( $pdf_files );
        $pdf_files = jb_library_filter_pdf_files_by_stockcode_terms( $pdf_files, $stockcode_prefixes );

        if ( ! empty( $pdf_files ) ) {
            $problem_files = 0;
            $unparsed_files = array();
            $unreadable_text = array();
            $missing_sds_info = array();
            $number_of_pdfs = count( $pdf_files );
            if ( ! empty( $stockcode_prefixes ) ) {
                WP_CLI::log( "Stockcode terms filter matched {$number_of_pdfs} of {$total_pdf_files_before_filter} PDF files." );
            }

            $pdf_files_batch = array_slice( $pdf_files, 0, $batch_size, true );

            foreach ( $pdf_files_batch as $file_number => $file ) {

                $scraper = new JB_PDF_Scraper( $file );
                if ( false === $scraper->is_pdf_readable ) {
                    WP_CLI::log( "No readable text found in file {$file}." );
                    WP_CLI::log( "----------------------------------------" );
                    $unreadable_snippet = substr( $scraper->parsed_text, 0, 200 );
                    WP_CLI::log( "Parsed text snippet: {$unreadable_snippet}" );
                    WP_CLI::log( "----------------------------------------" );
                    $unreadable_text[] = $file;
                    $problem_files++;
                    continue;
                }

                WP_CLI::log( "Details for file {$file} ( {$file_number} of {$batch_size} )" );
                $scraped_text = $scraper->scrape_pdf_text();

                // Handle files where the text could not be scraped
                if ( empty( $scraped_text ) ) {
                    WP_CLI::log( "No text scraped from file {$file}." );
                    $problem_files++;
                    $unparsed_files[] = $file;
                    continue;
                }
                $identification_start = $scraper->find_substring_position("identification");
                $hazard_start = $scraper->find_substring_position("hazard");

                // Sometimes, things get out of order, so we need to make sure the positions make sense
                if ( $identification_start > $hazard_start ) {
                    $identification_start = 1;
                }

                // Figure out how long the "Identification" section is
                $text_length = $hazard_start - $identification_start;
                    $does_identification_exist = $identification_start !== false && $hazard_start !== false && $text_length > 0;

                // Log the result
                if ( $does_identification_exist ) {
                    WP_CLI::log( "IDENTIFICATION section found." );
                } else {
                    // Handle files where the text was scraped but did not contain the sections we were looking for
                    if (
                        true === $scraper->is_pdf_readable
                        && -1 === $identification_start
                        && -1 === $hazard_start
                    ) {
                        WP_CLI::confirm( "No IDENTIFICATION section found in file {$file}. One was expected", "yes" );
                        $missing_sds_info[] = $file;
                    } else {
                        // Handle files where text was sraped but could not be read
                        // Throw an error for files where the text was scraped but the sections could not be found
                        WP_CLI::confirm( "IDENTIFICATION section NOT found.
                            Identification position is {$identification_start}.
                            Hazards position is {$hazard_start}.
                            Text length is {$text_length}.
                            Continue to next file?",
                            "yes"
                        );
                    }
                    // Count the number of problem files
                    $problem_files++;
                }
            }
            WP_CLI::log( "----------------------------------------" );

            WP_CLI::log( "Processed {$batch_size} PDFs of {$number_of_pdfs} PDF files found." );
            WP_CLI::log( "Unparsed files: " . count( $unparsed_files ) );
            WP_CLI::log( "Unreadable text files: " . count( $unreadable_text ) );
            WP_CLI::log( "Missing Identification information in files: " . count( $missing_sds_info ) );
            WP_CLI::log( "Total problem files: {$problem_files}" );
            WP_CLI::log( "Total properly parsed files: " . ( $batch_size - $problem_files ) );
            WP_CLI::log( "Percent of files properly parsed: " . ( ( $batch_size - $problem_files ) / $batch_size * 100 ) . "%" );
        }
    }
    WP_CLI::add_command( 'check-pdf-media-detail-for-sds', 'check_pdf_media_sds_content' );

    /**
     * Check how many SDS PDFs contain transport information.
     *
     * Usage:
     *  wp check-pdf-media-transport-for-sds
     *
     * @param array $args
     * @param array $assoc_args
     * - Arguments include:
     *  --subdirectory-path - Subdirectory path for the group of PDFs to be processed
     *  --batch-size - Number of PDF files to process in each batch (default: 100)
     *  --search-term - Term to search for in SDS files (default: transport)
     *  --agencies - Agencies to export. Use "all" for every agency row, or a comma-separated preference list like "dot,generic" for one row per product.
     *  --stockcode-terms - Stockcode prefix terms to process, such as "20" or "20,CR"
     * @return void
     */
    function check_pdf_media_transport_for_sds( array $args, array $assoc_args = []): void {

        // Set the batch size
        if ( isset( $assoc_args['batch-size'] ) && is_numeric( $assoc_args['batch-size'] ) ) {
            $batch_size = intval( $assoc_args['batch-size'] );
        } else {
            $batch_size = 100;
        }
        WP_CLI::confirm( "Batch size set to: {$batch_size}. Continue?", 'yes' );

        $stockcode_prefixes = jb_library_get_stockcode_terms_filter( $assoc_args );
        if ( ! empty( $stockcode_prefixes ) ) {
            WP_CLI::log( 'Stockcode terms filter: ' . jb_library_stockcode_filter_label( $stockcode_prefixes ) );
        }

        // Set whether to reset the transport scan tracking.
        $reset_tracking = isset( $assoc_args['reset-tracking'] );
        if ( $reset_tracking ) {
            WP_CLI::log( 'Resetting transport scan tracking for SDS files.' );
        }

        // Set the search term
        $search_term = 'transport';
        if ( isset( $assoc_args['search-term'] ) && is_string( $assoc_args['search-term'] ) && trim( $assoc_args['search-term'] ) !== '' ) {
            $search_term = trim( $assoc_args['search-term'] );
        }

        // Set agency export mode. "all" keeps every agency row. A preference list
        // like "dot,generic" exports one row per product using the first match.
        $agency_filter_raw = 'all';
        if ( isset( $assoc_args['agencies'] ) && is_string( $assoc_args['agencies'] ) && trim( $assoc_args['agencies'] ) !== '' ) {
            $agency_filter_raw = trim( $assoc_args['agencies'] );
        }

        $agency_filter = array_values(
            array_filter(
                array_map(
                    function ( string $agency ): string {
                        return strtoupper( trim( $agency ) );
                    },
                    explode( ',', $agency_filter_raw )
                )
            )
        );
        $export_all_agencies = in_array( 'ALL', $agency_filter, true );
        if ( empty( $agency_filter ) ) {
            $agency_filter = array( 'ALL' );
            $export_all_agencies = true;
        }
        WP_CLI::log( 'Agency export mode: ' . ( $export_all_agencies ? 'all' : implode( ', ', $agency_filter ) ) );

        // Set the directory path
        $wp_uploads_dir = wp_get_upload_dir();
        $directory_path = $wp_uploads_dir['basedir'] . '/';
        if ( isset( $assoc_args['subdirectory-path'] ) && is_string( $assoc_args['subdirectory-path'] ) ) {
            $directory_path .= rtrim( $assoc_args['subdirectory-path'], '/' ) . '/';
        }
        WP_CLI::confirm( "Use directory path: {$directory_path} ?", 'yes' );
        if ( ! is_dir( $directory_path ) ) {
            WP_CLI::error( "The specified directory does not exist: {$directory_path}" );
            return;
        }

            // Get all PDF files in the directory and normalize paths
            $all_pdf_files = glob( $directory_path . '*.pdf' );
            $total_pdf_files_before_filter = count( $all_pdf_files );
            $all_pdf_files = jb_library_filter_pdf_files_by_stockcode_terms( $all_pdf_files, $stockcode_prefixes );

            if ( empty( $all_pdf_files ) ) {
                WP_CLI::log( "No PDF files found in the specified directory: {$directory_path}" . ( ! empty( $stockcode_prefixes ) ? ' for stockcode terms ' . jb_library_stockcode_filter_label( $stockcode_prefixes ) : '' ) );
                return;
            }
            if ( ! empty( $stockcode_prefixes ) ) {
                WP_CLI::log( 'Stockcode terms filter matched ' . count( $all_pdf_files ) . " of {$total_pdf_files_before_filter} PDF files." );
            }

            // Load list of already-checked files for this transport scan
            $checked_option_key = 'one-time-script-sds-transport-checked-files';
            if ( ! empty( $stockcode_prefixes ) ) {
                $checked_option_key .= '-' . strtolower( implode( '-', $stockcode_prefixes ) );
            }
            $checked_files = get_option( $checked_option_key, array() );
            if ( $reset_tracking ) {
                $checked_files = array();
                delete_option( $checked_option_key );
            }

            if ( ! is_array( $checked_files ) ) {
                $checked_files = array();
            }

            // Normalize both lists for reliable comparisons
            $normalized_all = array_map( 'wp_normalize_path', $all_pdf_files );
            $normalized_checked = array_map( 'wp_normalize_path', $checked_files );

            // Determine remaining files in original order
            $remaining = array();
            foreach ( $normalized_all as $idx => $path ) {
                if ( ! in_array( $path, $normalized_checked, true ) ) {
                    $remaining[] = $path;
                }
            }

            $number_of_pdfs = count( $normalized_all );
            $transport_files = array();
            $transport_rows = array();
            $pdf_files_batch = array_slice( $remaining, 0, $batch_size, true );

            if ( empty( $pdf_files_batch ) ) {
                WP_CLI::log( "No remaining PDFs to check in {$directory_path}." );
                return;
            }

            $processed_in_batch = 0;

            WP_CLI::log( 'Starting transport scan for this batch. Remaining files to process: ' . count( $pdf_files_batch ) );
            foreach ( $pdf_files_batch as $file_number => $file ) {
                WP_CLI::log( "Checking file " . ( $file_number + 1 ) . " of " . count( $pdf_files_batch ) . ": {$file}" );

                if ( ! file_exists( $file ) ) {
                    WP_CLI::warning( "Skipping missing file: {$file}." );
                    $checked_files[] = $file;
                    update_option( $checked_option_key, $checked_files );
                    $processed_in_batch++;
                    continue;
                }

	                $scraper = new JB_PDF_Scraper( $file );
	                $transport_extractor = new JB_PDF_Transport_Extractor( $scraper->cleaned_text );
	                $transport_section = $transport_extractor->get_section();
	                $is_pdf_text_readable = $scraper->is_pdf_readable;
	                $has_transport_section = '' !== trim( $transport_section );
	                $contains_search_term = false;

	                if ( $is_pdf_text_readable && $has_transport_section ) {
	                    $contains_search_term = stripos( $transport_section, $search_term ) !== false;
	                    if ( $contains_search_term ) {
	                        $transport_files[] = $file;
	                    }
	                } elseif ( ! $is_pdf_text_readable ) {
	                    WP_CLI::log( "No readable text found in file {$file}." );
	                } else {
	                    WP_CLI::log( "No readable transport section found in file {$file}." );
	                }

	                $transport_records = ( $is_pdf_text_readable && $has_transport_section ) ? $transport_extractor->get_transport_records() : array();
	                if ( empty( $transport_records ) ) {
	                    $transport_records = array(
	                        array(
	                            'agency'                 => '',
	                            'agency_alias'           => '',
	                            'transport_types'        => '',
	                            'jurisdiction'           => '',
	                            'regulated_material'     => false,
	                            'un_code'                => '',
	                            'shipping_name'          => '',
	                            'hazard_class'           => '',
	                            'packing_group'          => '',
	                            'shipping_class'         => '',
	                            'hazardous_terms'        => '',
	                            'transport_section'      => $transport_section,
	                        ),
	                    );
	                }

	                    $file_name = basename( $file );
	                    $product_id = preg_replace( '/_SDS\.pdf$/i', '', $file_name );
	                    if ( $product_id === $file_name ) {
	                        $product_id = pathinfo( $file_name, PATHINFO_FILENAME );
	                    }

	                    if ( ! $export_all_agencies ) {
	                        $selected_transport_record = null;
	                        foreach ( $agency_filter as $agency ) {
	                            foreach ( $transport_records as $transport_record ) {
	                                if ( strtoupper( $transport_record['agency'] ?? '' ) === $agency ) {
	                                    $selected_transport_record = $transport_record;
	                                    break 2;
	                                }
	                            }
	                        }

	                        if ( null === $selected_transport_record ) {
	                            $selected_transport_record = array(
	                                'agency'                 => '',
	                                'agency_alias'           => '',
	                                'transport_types'        => '',
	                                'jurisdiction'           => '',
	                                'regulated_material'     => false,
	                                'un_code'                => '',
	                                'shipping_name'          => '',
	                                'hazard_class'           => '',
	                                'packing_group'          => '',
	                                'shipping_class'         => '',
	                                'hazardous_terms'        => '',
	                                'transport_section'      => $transport_section,
	                            );
	                        }

	                        $transport_records = array( $selected_transport_record );
	                    }

	                foreach ( $transport_records as $transport_record ) {
	                    $missing_fields = array();
	                    $required_fields = array( 'agency' );
	                    if ( ! empty( $transport_record['regulated_material'] ) ) {
	                        $required_fields = array_merge( $required_fields, array( 'un_code', 'shipping_name', 'hazard_class', 'packing_group' ) );
	                        if ( isset( $transport_record['hazard_class'] ) && preg_match( '/^2(?:\.|$)/', (string) $transport_record['hazard_class'] ) ) {
	                            $required_fields = array_diff( $required_fields, array( 'packing_group' ) );
	                        }
	                    }

	                    foreach ( $required_fields as $field_key ) {
	                        if ( empty( $transport_record[ $field_key ] ) ) {
	                            $missing_fields[] = $field_key;
	                        }
	                    }

	                    $transport_rows[] = array(
	                        'file_path'             => $file,
	                        'product_id'            => $product_id,
	                        'is_pdf_text_readable'  => $is_pdf_text_readable ? 'Yes' : 'No',
	                        'has_transport_section' => $has_transport_section ? 'Yes' : 'No',
	                        'contains_search_term'  => $contains_search_term ? 'Yes' : 'No',
	                        'agency'                => $transport_record['agency'] ?? '',
	                        'agency_alias'          => $transport_record['agency_alias'] ?? '',
	                        'transport_types'       => $transport_record['transport_types'] ?? '',
	                        'jurisdiction'          => $transport_record['jurisdiction'] ?? '',
	                        'regulated_material'    => ! empty( $transport_record['regulated_material'] ) ? 'Yes' : 'No',
	                        'un_code'               => $transport_record['un_code'] ?? '',
	                        'shipping_name'         => $transport_record['shipping_name'] ?? '',
	                        'hazard_class'          => $transport_record['hazard_class'] ?? '',
	                        'packing_group'         => $transport_record['packing_group'] ?? '',
	                        'shipping_class'        => $transport_record['shipping_class'] ?? '',
	                        'hazardous_terms'       => $transport_record['hazardous_terms'] ?? '',
	                        'transport_section'     => $transport_record['transport_section'] ?? $transport_section,
	                        'missing_fields'        => implode( ', ', $missing_fields ),
                    );
                }

                // Mark this file as checked and persist immediately
                $checked_files[] = $file;
                update_option( $checked_option_key, $checked_files );

                $processed_in_batch++;
            }

            // Ensure the logs directory exists before writing the CSV.
            if ( ! is_dir( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' ) ) {
                wp_mkdir_p( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' );
            }

            if ( ! empty( $transport_rows ) ) {
                $agency_file_suffix = $export_all_agencies ? 'all' : strtolower( implode( '-', $agency_filter ) );
                $stockcode_file_suffix = ! empty( $stockcode_prefixes ) ? '-stockcode-' . strtolower( implode( '-', $stockcode_prefixes ) ) : '';
                $csv_file_path = JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/transport-scan-' . $agency_file_suffix . $stockcode_file_suffix . '-' . gmdate( 'Ymd-His', time() ) . '.csv';
                $csv_handle = fopen( $csv_file_path, 'x' );
                if ( $csv_handle ) {
                    WP_CLI\Utils\write_csv(
                        $csv_handle,
                        $transport_rows,
	                        array(
		                            'file_path',
		                            'product_id',
		                            'is_pdf_text_readable',
		                            'has_transport_section',
	                            'contains_search_term',
	                            'agency',
	                            'agency_alias',
	                            'transport_types',
	                            'jurisdiction',
	                            'regulated_material',
		                            'un_code',
		                            'shipping_name',
	                            'hazard_class',
	                            'packing_group',
	                            'shipping_class',
	                            'hazardous_terms',
	                            'transport_section',
	                            'missing_fields',
	                        )
                    );
                    fclose( $csv_handle );
                    WP_CLI::log( "Transport details written to CSV file: {$csv_file_path}" );
                } else {
                    WP_CLI::warning( 'Failed to write transport scan CSV file.' );
                }
            }

            WP_CLI::log( "Processed {$processed_in_batch} PDFs of {$number_of_pdfs} PDF files found." );
            WP_CLI::log( "SDS PDFs containing '{$search_term}': " . count( $transport_files ) );

            if ( ! empty( $transport_files ) ) {
                WP_CLI::log( "Files containing '{$search_term}':" );
                foreach ( $transport_files as $transport_file ) {
                    WP_CLI::log( $transport_file );
                }
            }

            if ( count( $remaining ) > $processed_in_batch ) {
                WP_CLI::log( "Scan stopped after reaching batch size of {$batch_size}. Run again to continue checking remaining files." );
            } else {
                WP_CLI::success( "Transport scan completed for all SDS PDFs in {$directory_path}." );
            }

        WP_CLI::log( "Processed {$processed_in_batch} PDFs of {$number_of_pdfs} PDF files found. SDS PDFs containing '{$search_term}': " . count( $transport_files ) );
    }
    WP_CLI::add_command( 'check-pdf-media-transport-for-sds', 'check_pdf_media_transport_for_sds' );

    /**
     * Clear the transport scan checked-file tracking for SDS files.
     *
     * Usage:
     *  wp pdf-media-transport-clear-checked-files
     *
     * @return void
     */
    function clear_pdf_media_transport_sds_checked_files(): void {
        $option_key = 'one-time-script-sds-transport-checked-files';
        if ( delete_option( $option_key ) ) {
            WP_CLI::log( 'Cleared transport scan tracking for SDS files.' );
            return;
        }

        WP_CLI::log( 'No transport scan tracking option found, or it was already cleared.' );
    }
    WP_CLI::add_command( 'pdf-media-transport-clear-checked-files', 'clear_pdf_media_transport_sds_checked_files' );

    /**
     * Check that the PDF parser is able to read the content of PDF files in a specified directory.
     *
     * Usage:
     *  wp check-pdf-media-detail-for-tds
     *
     * @param array $assoc_args
     * - Arguments include:
     *  --subdirectory-path - Subdirectory path for the group of PDFs to be processed
     *  --batch-size - Number of PDF files to process in each batch (default: 100)
     *  --stockcode-terms - Stockcode prefix terms to process, such as "20" or "20,CR"
     * @return void
     */
    function check_pdf_media_tds_content( array $args, array $assoc_args = []): void {

        // Set the batch size
        if ( $assoc_args['batch-size'] && is_numeric( $assoc_args['batch-size'] ) ) {
            $batch_size = intval( $assoc_args['batch-size'] );
        } else {
            $batch_size = 100;
        }
        WP_CLI::confirm( "Batch size set to: {$batch_size}. Continue?", 'yes' );

        $stockcode_prefixes = jb_library_get_stockcode_terms_filter( $assoc_args );
        if ( ! empty( $stockcode_prefixes ) ) {
            WP_CLI::log( 'Stockcode terms filter: ' . jb_library_stockcode_filter_label( $stockcode_prefixes ) );
        }

        // Set the directory path
        $wp_uploads_dir = wp_get_upload_dir();
        $directory_path = $wp_uploads_dir['basedir'] . '/';
        if ( isset( $assoc_args['subdirectory-path'] ) && is_string( $assoc_args['subdirectory-path'] ) ) {
            $directory_path .= rtrim( $assoc_args['subdirectory-path'], '/' ) . '/';
        }
        WP_CLI::confirm( "Use directory path: {$directory_path} ?", 'yes' );
        if ( ! is_dir( $directory_path ) ) {
            WP_CLI::error( "The specified directory does not exist: {$directory_path}" );
            return;
        }

        // Get all PDF files in the directory
        $pdf_files = glob( $directory_path . '*.pdf' );
        $total_pdf_files_before_filter = count( $pdf_files );
        $pdf_files = jb_library_filter_pdf_files_by_stockcode_terms( $pdf_files, $stockcode_prefixes );

        if ( ! empty( $pdf_files ) ) {
            $problem_files = 0;
            $unparsed_files = array();
            $unreadable_text = array();
            $missing_tds_info = array();
            $number_of_pdfs = count( $pdf_files );
            if ( ! empty( $stockcode_prefixes ) ) {
                WP_CLI::log( "Stockcode terms filter matched {$number_of_pdfs} of {$total_pdf_files_before_filter} PDF files." );
            }

            $pdf_files_batch = array_slice( $pdf_files, 0, $batch_size, true );

            foreach ( $pdf_files_batch as $file_number => $file ) {

                $scraper = new JB_PDF_Scraper( $file );
                if ( false === $scraper->is_pdf_readable ) {
                    WP_CLI::log( "No readable text found in file {$file}." );
                    WP_CLI::log( "----------------------------------------" );
                    $unreadable_snippet = substr( $scraper->parsed_text, 0, 200 );
                    WP_CLI::log( "Parsed text snippet: {$unreadable_snippet}" );
                    WP_CLI::log( "----------------------------------------" );
                    $unreadable_text[] = $file;
                    $problem_files++;
                    continue;
                }

                WP_CLI::log( "Details for file {$file} ( {$file_number} of {$batch_size} )" );

                // Handle files where the text could not be scraped
                if ( empty( $scraper->cleaned_text ) ) {
                    WP_CLI::log( "No text scraped from file {$file}." );
                    $problem_files++;
                    $unparsed_files[] = $file;
                    continue;
                }

                $search_terms = array(
                    'features'      => $scraper->find_substring_position("features"),
                    'description'   => $scraper->find_substring_position("description"),
                    'benefits'      => $scraper->find_substring_position("benefits"),
                    'eigenschaften' => $scraper->find_substring_position("eigenschaften"),
                    'components'    => $scraper->find_substring_position("components"),
                    'information'   => $scraper->find_substring_position("information"),
                );

                if ( -4 === array_sum( $search_terms ) ) {
                    WP_CLI::confirm( "No FEATURES, DESCRIPTION, or BENEFITS sections found in file {$file}. Continue?", "yes" );
                    $problem_files++;
                    $missing_tds_info[] = $file;
                    continue;
                }

                $best_term = array_search( max( $search_terms ), $search_terms );
                WP_CLI::log( "Best term found: {$best_term}" );
            }
            WP_CLI::log( "----------------------------------------" );

            WP_CLI::log( "Processed {$batch_size} PDFs of {$number_of_pdfs} PDF files found." );
            WP_CLI::log( "Unparsed files: " . count( $unparsed_files ) );
            WP_CLI::log( "Unreadable text files: " . count( $unreadable_text ) );
            WP_CLI::log( "Missing Identification information in files: " . count( $missing_tds_info ) );
            WP_CLI::log( "Total problem files: {$problem_files}" );
            WP_CLI::log( "Total properly parsed files: " . ( $batch_size - $problem_files ) );
            WP_CLI::log( "Percent of files properly parsed: " . ( ( $batch_size - $problem_files ) / $batch_size * 100 ) . "%" );
        }
    }
    WP_CLI::add_command( 'check-pdf-media-detail-for-tds', 'check_pdf_media_tds_content' );

    /**
     * Check the content of a single PDF file in the media library.
     *
     * Usage:
     *  wp log-single-pdf-media-detail
     *
     * @param array $assoc_args
     * - Arguments include:
     *  --file-path - Uploads subdirectory path for the single PDF to log
     * @return void
     */
    function log_single_pdf_media_detail( array $args, array $assoc_args = []): void {
        WP_CLI::log( 'Starting single PDF media check' );

        $wp_uploads_dir = wp_get_upload_dir();
        $file_path = $wp_uploads_dir['basedir'] . '/';
        if ( isset( $assoc_args['file-path'] ) && is_string( $assoc_args['file-path'] ) ) {
            $file_path .= rtrim( $assoc_args['file-path'], '/' );
            WP_CLI::confirm( "Use file path: {$file_path} ?", 'yes' );
        } else {
             WP_CLI::error( "You must provide a --file-path argument." );
            return;
        }

        $scraper = new JB_PDF_Scraper( $file_path );
        $scraped_text = $scraper->scrape_pdf_text();
        WP_CLI::log( "Scraped text:" );
        WP_CLI::log( $scraped_text );
    }

    WP_CLI::add_command( 'log-single-pdf-media-detail', 'log_single_pdf_media_detail' );

    /**
     * Check the if all PDFs were uploaded to media attachements or documents
     *
     * Usage:
     *  wp check-pdf-import-status
     *
     * @param array $assoc_args
     * - Arguments include:
     *  --subdirectories - Comma separated subdirectories path for the groups of PDFs to be processed
     *  --post-type - Comma separated post types to check (default: dlp_document,attachment)
     * @return void
     */
    function check_pdf_import_status( array $args, array $assoc_args = []): void {
        WP_CLI::log( '-------------------------------------' );
        WP_CLI::log( 'Starting PDF import check' );
        WP_CLI::log( '-------------------------------------' );

        // Get all of the PDFs from the specified directories
        if ( isset( $assoc_args['subdirectories'] ) && is_string( $assoc_args['subdirectories'] ) ) {
            $subdirectory_paths = array_map( 'trim', explode( ',', $assoc_args['subdirectories'] ) );
        } else {
            $subdirectory_paths = array( 'SDS', 'TDS' );
        }

        WP_CLI::log( 'Checking subdirectories: ' . implode( ', ', $subdirectory_paths ) );
        $wp_uploads_dir = wp_get_upload_dir();
        $all_pdf_files = array();
        $file_number = 0;
        foreach ( $subdirectory_paths as $subdirectory_path ) {
            $directory_path = $wp_uploads_dir['basedir'] . '/' . rtrim( $subdirectory_path, '/' ) . '/';
            if ( is_dir( $directory_path ) ) {
                WP_CLI::log( "Using directory path: {$directory_path}" );
            } else {
                WP_CLI::error( "The specified directory does not exist: {$directory_path}" );
                return;
            }

            // Get all PDF files in the directory
            $pdf_files = array_filter( glob( $directory_path . '*.pdf' ), 'is_file' );
            if ( ! empty( $pdf_files ) ) {
                WP_CLI::log( 'Found ' . count( $pdf_files ) . " PDF files in {$directory_path}." );
                foreach ( $pdf_files as $file_path) {
                    $file_number++;
                    $file_info = pathinfo( $file_path );
                    $file_name = $file_info['filename'] . '.' . $file_info['extension'];
                    $all_pdf_files[ $file_number ] = array(
                        'file_path' => $file_path,
                        'file_name' => $file_name,
                    );
                }
            } else {
                WP_CLI::log( "No PDF files found in the specified directory: {$directory_path}." );
            }
        }

        $pdf_count = count( $all_pdf_files );
        WP_CLI::log( "Total PDF files found: {$pdf_count}" );
        WP_CLI::log( 'All PDFs from directories are loaded.' );
        WP_CLI::log( '-------------------------------------' );

        // Pull all of the posts of the specified types
        // Default to checking both documents and attachments
        // If a --post-type argument is provided, use that instead
        if ( isset( $assoc_args['post-type'] ) && is_string( $assoc_args['post-type'] ) ) {
            $post_types = array_map( 'trim', explode( ',', $assoc_args['post-type'] ) );
        } else {
            $post_types = array( 'dlp_document', 'attachment' );
        }
        WP_CLI::log( 'Checking post types: ' . implode( ', ', $post_types ) );
        WP_CLI::log( '-------------------------------------' );

        global $wpdb;
        $posts = array();

        foreach ( $post_types as $post_type ) {
            $posts[ $post_type ] = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_title, guid
                    FROM $wpdb->posts
                    WHERE post_type = %s
                    AND ( post_title LIKE '%SDS%' OR post_title LIKE '%TDS%' )
                    AND ( post_mime_type = 'application/pdf' OR post_mime_type = '' )
                    ",
                    $post_type
                ),
                ARRAY_A
            );
            WP_CLI::log( 'Found ' . count( $posts[ $post_type ] ) . " posts of type {$post_type}." );

            // Figure out how many posts could be missing
            if ( ! empty( $posts[ $post_type ] ) ) {
                $missing_post_count = $pdf_count - count( $posts[ $post_type ] );
                if ( $missing_post_count > 0 ) {
                    WP_CLI::warning( 'Potentially missing ' . $missing_post_count . " posts of type {$post_type}." );
                } else {
                    WP_CLI::log( "Number of {$post_type} and files match, checking file names against post titles to be sure." );
                }

                $post_pluck_key = $post_type === 'attachment' ? 'guid' : 'post_title';
                $file_pluck_key = $post_type === 'attachment' ? 'file_path' : 'file_name';

                // Format the post names for comparison
                $plucked_posts = wp_list_pluck( $posts[ $post_type ], $post_pluck_key );
                $plucked_files = wp_list_pluck( $all_pdf_files, $file_pluck_key );
                $files_with_missing_posts = array_diff( $plucked_files, $plucked_posts );

                if ( ! empty( $files_with_missing_posts ) ) {
                    WP_CLI::warning( 'Found ' . count( $files_with_missing_posts ) . " files with no corresponding {$post_type} post." );
                    WP_CLI::log( print_r( $files_with_missing_posts, true ) );
                } else {
                    WP_CLI::success( "All files have corresponding {$post_type} posts." );
                }
            }
            WP_CLI::log( '-------------------------------------' );
        }
    }

    WP_CLI::add_command( 'check-pdf-import-status', 'check_pdf_import_status' );

    /**
     * Import Single PDF media files from a specified file path.
     *
     * @param array $assoc_args
     * args:
     *  --file-path - A path to a single file to import
     *  --for-real - Whether to perform the import for real (default: false)
     * @return void
     */
    function import_single_pdf( array $args, array $assoc_args = [] ): void {
        WP_CLI::log( 'Starting PDF media import...' );

        // Determine the file path
        if ( isset( $assoc_args['file-path'] ) && is_string( $assoc_args['file-path'] ) ) {
            $file_path = $assoc_args['file-path'];
            WP_CLI::confirm( "Use file path: {$file_path} ?", 'yes' );
        } else {
             WP_CLI::error( "You must provide a --file-path argument." );
            return;
        }

        // Determine if we are running in dry run mode
        $for_real = isset( $assoc_args['for-real'] ) ?? false;
        if ( $for_real ) {
            WP_CLI::log( 'Running in live mode, FOR REAL. Files will be imported.'  );
        } else {
            WP_CLI::log( ' Running in test mode. No files will be imported.' );
        }

        // Set up the file Importer
        $importer = new JB_Library_File_Importer( $file_path );

        // Check if a post already exists
        global $wpdb;

        $existing_document_post = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title
                FROM $wpdb->posts
                WHERE post_type = 'dlp_document'
                AND post_title = %s
                ",
                $importer->file_name
                ),
            ARRAY_A
        );

        if ( ! empty( $existing_document_post ) ) {
            WP_CLI::confirm( "A document post already exists for file: {$file_path} as post ID {$existing_document_post[0]['ID']}. Continue anyways?", 'yes' );
        }

        // Handle for-real actions
        if ( $for_real ) {

            WP_CLI::log( "Importing {$file_path}" );
            $result = $importer->import_file();
            if ( is_wp_error( $result ) ) {
                WP_CLI::error( "Failed to import file {$file_path}: " . $result->get_error_message() );
            }
            WP_CLI::log( "Successfully imported file: {$file_path} as post ID {$result}" );
        }

        // Handle dry-run actions
        if ( ! $for_real ) {
            // Simulate the import
            WP_CLI::log( "Dry run: Would import file: {$file_path}" );
            WP_CLI::log( "Import Details:
                File Name: {$importer->file_name}
                File Type: {$importer->file_type}
                Category ID: {$importer->category_id}
                Tag Slug: {$importer->tag_slug}
                Author ID: {$importer->author_id}
                Is PDF Text Readable: " . ( $importer->scraper->is_pdf_readable ? 'Yes' : 'No' )
            );
        }

        WP_CLI::success( "PDF media import completed." );
    }

    WP_CLI::add_command( 'import-single-pdf', 'import_single_pdf' );
}
