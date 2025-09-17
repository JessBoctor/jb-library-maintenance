<?php
/**
 * PDF Media Deduplication WP-CLI Command
 *
 * Requires WP-CLI to be installed and activated.
 *
 * Usage:
 *   wp pdf-media-scrape-and-import [--for-real] [--batch-size=<number>] [--skip-confirmations]
 *
 * Examples:
 *   wp pdf-media-scrape-and-import --for-real
 *   wp pdf-media-dedup --skip-confirmations
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
         * Whether to run in test mode (dry run).
         *
         * @var bool
         */
        private $dry_run = false;

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
         * Minimum post ID to start processing from.
         *
         * @var int
         */
        private $start_post_id = 1;

        /**
         * Holds the last post ID returned in the batch.
         *
         * @var int|null
         */
        private $last_post_id = null;

        /**
         * Holds unique post titles to check for duplicates.
         *
         * @var array
         */
        private $unique_post_titles = array();

        /**
         * Holds the posts which have been deleted.
         * This will allow us to log the deleted posts in a CSV file at the end of the batch
         *
         * @var array
         */
        private $duplicate_posts_to_log = array();

        /**
         * Total number of PDF posts detected in the media library.
         *
         * @var int
         */
        private $total_duplicate_posts = 0;

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
            $this->dry_run = isset( $assoc_args['dry-run'] );
            if ( $this->dry_run ) {
                WP_CLI::log( 'Running in dry run mode. No changes will be made.' );
            } else {
                WP_CLI::log( 'Running in live mode. Changes will be applied.' );
            }

            // Determine if we are running in dry run mode
            $this->skip_confirmations = isset( $assoc_args['skip-confirmations'] );
            if ( $this->skip_confirmations ) {
                WP_CLI::log( 'Cofirmations will be skipped.' );
            }

            // Determine the starting post ID from CLI args or saved option
            $this->determine_start_post_id( $assoc_args );

            // Set the batch size if provided
            if ( isset( $assoc_args['batch-size'] ) && is_numeric( $assoc_args['batch-size'] ) ) {
                $this->batch_size = intval( $assoc_args['batch-size'] );
            }
            WP_CLI::log( "Batch size set to: {$this->batch_size}" );

            // Fetch the past unique post titles from options
            $saved_unique_post_titles = get_option( 'one-time-script-pdf-deduplication-unique-post-titles', array() );
            if ( is_array( $saved_unique_post_titles ) ) {
                $this->unique_post_titles = $saved_unique_post_titles;
                WP_CLI::log( 'Loaded unique post records from options.' );
            } else {
                WP_CLI::log( 'No unique post records found in options.' );
            }

            // Being the deduplication process
            $this->deduplicate_pdfs();
        }

        /**
         * Deduplicate PDF media files in the WordPress media library.
         *
         * @param none
         * @return void
         * @when after_wp_load
         */
        public function deduplicate_pdfs(): void {
            WP_CLI::log( 'Starting PDF media deduplication...' );

            // Fetch PDF posts for this batch
            $pdf_posts = $this->get_pdf_posts();
            if ( empty( $pdf_posts ) ) {
                WP_CLI::log( 'No PDF posts found to deduplicate.' );
                return;
            }
            // Log the number of PDF posts found
            $pdf_posts_count = count( $pdf_posts );
            WP_CLI::log( "Found {$pdf_posts_count} PDF posts to process." );
            $this->save_last_post_id_to_options();
            WP_CLI::log( "Last post ID in batch: {$this->last_post_id}" );

            // Loop through the PDF posts and check for duplicates
            foreach ( $pdf_posts as $post ) {
                $post_title = $post->post_title;
                $matching_post_title_id = null;

                if ( $this->dry_run ) {
                    WP_CLI::log( "Checking post ID {$post->ID} with title '{$post_title}' for duplicates." );
                }

                // Check if the post title is already in the unique titles array
                $matching_post_title_id = array_search( $post_title, $this->unique_post_titles, true );
                if ( ! empty( $matching_post_title_id ) ) {
                    $this->handle_duplicate_post( $post, $matching_post_title_id );
                    continue;
                } 

                // Check if the post is a fuzzy duplicate
                // These are post titles that may have a common slug
                // but a unique post title because of -x suffixes which get added upon upload

                // "-1" is a common suffix for duplicates, so we check for it
                if ( str_contains( $post_title, '-1' ) ) {
                    str_replace( '-1', '', $post_title );
                    $matching_post_title_id = array_search( $post_title, $this->unique_post_titles, true );
                    if ( ! empty( $matching_post_title_id ) ) {
                        $this->handle_duplicate_post( $post, $matching_post_title_id );
                        continue;
                    }
                }

                // "-2" is a common suffix for duplicates, so we check for it
                if ( str_contains( $post_title, '-2' ) ) {
                    str_replace( '-2', '', $post_title );
                    $matching_post_title_id = array_search( $post_title, $this->unique_post_titles, true );
                    if ( ! empty( $matching_post_title_id ) ) {
                        $this->handle_duplicate_post( $post, $matching_post_title_id );
                        continue;
                    }
                }

                // "-pdf" is a common suffix for duplicates, so we check for it
                if ( str_contains( $post_title, '-pdf' ) ) {
                    str_replace( '-pdf', '', $post_title );
                    $matching_post_title_id = array_search( $post_title, $this->unique_post_titles, true );
                    if ( ! empty( $matching_post_title_id ) ) {
                        $this->handle_duplicate_post( $post, $matching_post_title_id );
                        continue;
                    }
                }

                // Add the unmodified post title to the unique titles array
                $this->unique_post_titles[$post->ID] = $post->post_title;
            }

            // Save the unique post titles to options
            $this->save_unique_post_titles_to_options();

            // Handle logging the results
            $this->log_results();

            // Your deduplication logic here, using $this->dry_run and $this->start_post_id to control actions.
            WP_CLI::success( "PDF media deduplication completed for post ID #{$this->start_post_id} through #{$this->last_post_id}." );
        }

        /**
         * Determine the starting post ID from CLI args or saved option.
         *
         * @param array $assoc_args
         */
        private function determine_start_post_id( $assoc_args ) {
            if ( isset( $assoc_args['start-post-id'] ) ) {
                $this->start_post_id = intval( $assoc_args['start-post-id'] );
                WP_CLI::log( "Starting from provided post ID: {$this->start_post_id}" );
                return; // If a start post ID is provided, it should always take precedence.
            }

            $saved_start_post_id = get_option( 'one-time-script-pdf-deduplication-start-post-id' );
            if ( $saved_start_post_id ) {
                $this->start_post_id = intval( $saved_start_post_id );
                WP_CLI::log( "Resuming from saved post ID: {$this->start_post_id}" );
                return; // If a saved start post ID exists, use it.
            }

            // If no start post ID is provided or saved, use default of 1
            WP_CLI::log( 'No saved start post ID found or provided. Starting from post ID 1.' );
        }

        /**
         * Fetch PDF posts from the database.
         *
         * @param none
         * @return array Array of post objects representing PDF attachments.
         */
        private function get_pdf_posts(): array {
            global $wpdb;

            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "
                    SELECT * FROM {$wpdb->posts}
                    WHERE post_type = %s
                      AND post_mime_type = %s
                      AND ID > %d
                    ORDER BY ID ASC
                    LIMIT %d
                    ",
                    'attachment',
                    'application/pdf',
                    $this->start_post_id,
                    $this->batch_size
                )
            );

            // Set the last_post_id property to the last post ID in the results, if any
            if ( ! empty( $results ) ) {
                $last_post = end( $results );
                $this->last_post_id = $last_post->ID;
            }

            return $results;
        }

        /**
         * Save the last processed post ID to the wp_options table.
         * This allows the script to resume from the last processed post ID
         *
         * @param none
         * @return void
         */
        private function save_last_post_id_to_options(): void {
            if ( ! is_null( $this->last_post_id ) ) {
                update_option( 'one-time-script-pdf-deduplication-start-post-id', $this->last_post_id );
            }
        }

        /**
         * Save the unique post titles array to the wp_options table.
         * This allows the script to check previously processed titles for duplicates
         *
         * @param none
         * @return void
         */
        private function save_unique_post_titles_to_options(): void {
            if ( ! empty( $this->unique_post_titles ) ) {
                update_option( 'one-time-script-pdf-deduplication-unique-post-titles', $this->unique_post_titles );
            }
        }

        /**
         * Handle a duplicate post when it is found.
         *
         * @param object $post The post object that is a duplicate.
         * @param int|string $matching_post_title_id The IDs of posts with the same title.
         * @return void
         */
        private function handle_duplicate_post( object $duplicate_post, int|string $matching_post_title_id ): void {
            $this->total_duplicate_posts++;
            $original_pdf_url = get_attached_file( $matching_post_title_id );
            $duplicate_post_message =
                "
                    Duplicate PDF found. Original post ID {$matching_post_title_id} with title '{$this->unique_post_titles[$matching_post_title_id]}'
                    ({$original_pdf_url}).
                    Duplicate post ID {$duplicate_post->ID} has title '{$duplicate_post->post_title}' ({$duplicate_post->guid}).
                ";

            if ( $this->dry_run ) {
                WP_CLI::log( "Dry run: " . $duplicate_post_message );
                if ( ! $this->skip_confirmations ) {
                   WP_CLI::confirm( 'Log the duplicate post and PDF file to CSV?', 'yes' );
                }
                $this->gather_duplicate_posts_data( $duplicate_post, $matching_post_title_id );
                return;
            }

            if ( ! $this->dry_run ) {
                // Logic to handle duplicates, e.g., delete or mark as duplicate
                WP_CLI::log( $duplicate_post_message);
                if ( ! $this->skip_confirmations ) {
                    WP_CLI::confirm( 'Do you want to delete the duplicate post and PDF file?', 'yes' );
                }
                $this->gather_duplicate_posts_data( $duplicate_post, $matching_post_title_id );
                wp_delete_attachment( $duplicate_post->ID, true );
                WP_CLI::log( "Deleted duplicate post ID {$duplicate_post->ID}." );
                return;
            }
        }

        /**
         * Gather the duplicate posts data for logging later
         * This will be used to log the deleted posts in a CSV file at the end of the batch
         *
         * @param object $post The post object that is a duplicate.
         * @param int|string $matching_post_title_id The IDs of posts with the same title.
         * @return void
         */
        private function gather_duplicate_posts_data( $duplicate_post, $matching_post_title_id ): void {
            $duplicate_file = get_attached_file( $duplicate_post->ID );
            $duplicate_file_exists = is_file( $duplicate_file );
            $duplicate_file_size = $duplicate_file_exists ? filesize( $duplicate_file ) : 0;


            $this->duplicate_posts_to_log[] = array(
                'original_post_id'          => $matching_post_title_id,
                'original_post_title'       => $this->unique_post_titles[$matching_post_title_id],
                'original_pdf_url'          => get_attached_file( $matching_post_title_id ),
                'duplicate_post_id'         => $duplicate_post->ID,
                'duplicate_post_title'      => $duplicate_post->post_title,
                'duplicate_pdf_url'         => $duplicate_file,
                'duplicate_pdf_file_exists' => $duplicate_file_exists,
                'duplicate_pdf_filesize'    => $duplicate_file_size
            );
        }

        /**
         * Handle logging the results of the deduplication process.
         * @return void
         */
        private function log_results(): void {
            // Log the number of duplicate posts found
            WP_CLI::log( "Total duplicate posts found: {$this->total_duplicate_posts}" );

            // Log the number of duplicate posts recorded or deleted
            if ( $this->dry_run ) {
                WP_CLI::log( 'Total duplicate posts logged: ' . count( $this->duplicate_posts_to_log ) );
            } else {
                WP_CLI::log( 'Total duplicate posts deleted: ' . count( $this->duplicate_posts_to_log )  );
            }

            // Write the duplicate posts to a CSV file
            if (  ! empty( $this->duplicate_posts_to_log ) ) {
                $csv_preffix = $this->dry_run ? 'dry-run-' : 'deleted-';
                $csv_file_path = fopen( JB_DEDUP_PLUGIN_DIR . 'logs/' . $csv_preffix . 'pdf-media-duplicate-posts-' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for duplicate posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $csv_file_path,
                    $this->duplicate_posts_to_log,
                    array(
                        'original_post_id',
                        'original_post_title',
                        'original_pdf_url',
                        'duplicate_post_id',
                        'duplicate_post_title',
                        'duplicate_pdf_url',
                        'duplicate_pdf_file_exists',
                        'duplicate_pdf_filesize',
                    ),
                );

                WP_CLI::log( "Duplicate posts written to CSV file: {$csv_file_path}" );
                fclose( $csv_file_path );
            }

            // Log the number of unique post titles found
            WP_CLI::log( 'Unique PDF posts found: ' . count( $this->unique_post_titles ) );
        }
    }
    WP_CLI::add_command( 'pdf-media-scrape-and-import', 'PDF_Media_Scrape_And_Import_Command' );
}

    /**
     * Clear out fields stored in wp_options related to PDF media deduplication.
     * This is useful for resetting the deduplication process.
     *
     * Usage:
     *  wp pdf-media-dedup-clear-options
     *
     * @param none
     * @return void
     */
    function clear_pdf_media_deduplication_options(): void {
        delete_option( 'one-time-script-pdf-deduplication-start-post-id' );
        delete_option( 'one-time-script-pdf-deduplication-unique-post-titles' );
        WP_CLI::log( 'Cleared PDF media deduplication options.' );
    }
    WP_CLI::add_command( 'pdf-media-dedup-clear-options', 'clear_pdf_media_deduplication_options' );


    /**
     * Clear out CSV Log files stored in jb-deduplication/logs related to PDF media deduplication.
     *
     * Usage:
     *  wp pdf-media-dedup-delete-logs
     *
     * @param none
     * @return void
     */
    function delete_pdf_media_deduplication_log_files(): void {
        WP_CLI::confirm( 'Are you sure you want to delete all PDF media deduplication log files? If you need a CSV record of changes, make sure to download it before continuing.', 'yes' );
        $run_types = array( 'dry-run-', 'deleted-', '' );
        foreach ( $run_types as $run_type ) {
            $log_files = glob( JB_DEDUP_PLUGIN_DIR . 'logs/' . $run_type . 'pdf-media-duplicate-posts-*.csv' );
            if ( ! empty( $log_files ) ) {
                foreach ( $log_files as $file ) {
                    @unlink( $file );
                }
                WP_CLI::log( 'Deleted all PDF media deduplication log CSV files.' );
            } else {
                WP_CLI::log( 'No log CSV files found to delete.' );
            }
        }
    }
    WP_CLI::add_command( 'pdf-media-dedup-delete-logs', 'delete_pdf_media_deduplication_log_files' );

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
        write_log( 'Starting single PDF media check' );

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
