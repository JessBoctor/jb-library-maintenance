<?php
/**
 * PDF Media Import WP-CLI Command
 *
 * Requires WP-CLI to be installed and activated.
 *
 * Usage:
 *   wp pdf-media-scrape-and-import [--for-real] [--batch-size=<number>] [--skip-confirmations]
 *
 * Examples:
 *   wp pdf-media-scrape-and-import --for-real
 *   wp pdf-media-scrape-and-import --skip-confirmations
 *
 * Run the above commands from the terminal.
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
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
            if ( empty( $pdf_files ) ) {
                WP_CLI::log( 'No PDF files found in the specified directory.' );
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

                foreach ( $pdf_files as $file_number => $file_path ) {

                     // Check if we've reached the batch size limit
                    if ( $this->total_processed_files === $this->batch_size ) {
                        WP_CLI::log( "Reached batch size limit of {$this->batch_size}. Stopping import." );
                        break;
                    }

                    // Set up the file Importer
                    $importer = new JB_Library_File_Importer( $file_path );

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
                }

                // Log the results
                $this->log_results();
                WP_CLI::success( "PDF media import completed." );

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

            // Write the processed files to a CSV file
            if (  ! empty( $this->processed_files_to_log ) ) {
                // Create the logs directory if it doesn't exist
                if ( ! is_dir( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' ) ) {
                    WP_CLI::log( 'Creating logs directory...' );
                    wp_mkdir_p( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' );
                }
                $csv_preffix = $this->for_real ? 'for-real-' : 'dry-run-';
                $csv_file_path = fopen( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . $csv_preffix . 'pdf-media-import-' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for duplicate posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $csv_file_path,
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

                WP_CLI::log( "Duplicate posts written to CSV file: {$csv_file_path}" );
                fclose( $csv_file_path );
            }

            // Write the skipped files to a CSV file
            if (  ! empty( $this->skipped_files_to_log ) ) {
                // Create the logs directory if it doesn't exist
                if ( ! is_dir( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' ) ) {
                    WP_CLI::log( 'Creating logs directory...' );
                    wp_mkdir_p( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' );
                }
                $csv_preffix = $this->for_real ? 'for-real-' : 'dry-run-';
                $csv_file_path = fopen( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . $csv_preffix . 'pdf-media-skipped' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for duplicate posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $csv_file_path,
                    $this->skipped_files_to_log,
                    array(
                        'file_path',
                        'existing_post_id',
                    ),
                );

                WP_CLI::log( "Duplicate posts written to CSV file: {$csv_file_path}" );
                fclose( $csv_file_path );
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

        if ( ! empty( $pdf_files ) ) {
            $problem_files = 0;
            $unparsed_files = array();
            $unreadable_text = array();
            $missing_sds_info = array();
            $number_of_pdfs = count( $pdf_files );

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
     * Check that the PDF parser is able to read the content of PDF files in a specified directory.
     *
     * Usage:
     *  wp check-pdf-media-detail-for-tds
     *
     * @param array $assoc_args
     * - Arguments include:
     *  --subdirectory-path - Subdirectory path for the group of PDFs to be processed
     *  --batch-size - Number of PDF files to process in each batch (default: 100)
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

        if ( ! empty( $pdf_files ) ) {
            $problem_files = 0;
            $unparsed_files = array();
            $unreadable_text = array();
            $missing_tds_info = array();
            $number_of_pdfs = count( $pdf_files );

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
        WP_CLI::log( 'Starting PDF import check' );

    }

    WP_CLI::add_command( 'check-pdf-import-status', 'check_pdf_import_status' );
}
