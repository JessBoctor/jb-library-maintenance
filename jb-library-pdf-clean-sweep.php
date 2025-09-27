<?php
/**
 * WP-CLI Command to clean out the Document Library Pro (DLP) custom post type
 *
 * Requires WP-CLI to be installed and activated.
 *
 * Usage:
 *   wp pdf-media-delete [--dry-run] [--start-post-id=<id>] [--batch-size=<size>]
 *
 * Examples:
 *   wp pdf-media-delete --dry-run
 *   wp pdf-media-delete --start-post-id=500
 *   wp pdf-media-delete --dry-run --start-post-id=1000
 *   wp pdf-media-delete --dry-run --start-post-id=1000 --batch-size=50
 *
 * Run the above commands from the terminal.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

if ( ! class_exists( 'PDF_Media_Deletion_Commandq' ) ) {
    class PDF_Media_Deletion_Command {

        /**
         * Allow skipping confirmations.
         *
         * @var bool
         */
        private $skip_confirmations = false;

        /**
         * Whether to run in for real.
         *
         * @var bool
         */
        private $for_real = false;

        /**
         * Number of posts to process per batch.
         *
         * @var int
         */
        private $batch_size = 100;

        /**
         * Minimum post ID to start processing from.
         *
         * @var int|null
         */
        private $start_post_id = null;

        /**
         * Holds the last post ID returned in the batch.
         *
         * @var int|null
         */
        private $last_post_id = null;

        /**
         * Total number of PDF Media posts deleted.
         *
         * @var int
         */
        private $total_deleted_posts = 0;

        /**
         * Array of post IDs which were deleted.
         *
         * @var array
         */
        private $stash_deleted_pdf_media_posts = array();

        /**
         * Delete PDF Media Posts and associated files.
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
                WP_CLI::confirm( 'Running in live mode. Documents and PDFs will be deleted. Continue?' );
            } else {
                WP_CLI::log( 'Running in dry run mode. No changes will be made.' );
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

            // Begin the deletion process
            $this->delete_pdf_docs();
        }

        /**
         * Delete PDF Media post and related PDFs.
         *
         * @param none
         * @return void
         */
        public function delete_pdf_docs(): void {
            WP_CLI::log( 'Starting PDF Media deletion...' );

            // Fetch PDF Media posts for this batch
            $pdf_media_posts = $this->get_pdf_media_posts();
            if ( empty( $pdf_media_posts ) ) {
                WP_CLI::log( 'No PDF Media posts found to delete.' );
                return;
            }
            // Log the number of PDF Media posts found
            $pdf_media_posts_count = count( $pdf_media_posts );
            WP_CLI::log( "Found {$pdf_media_posts_count} PDF Media posts to process." );
            $this->save_last_post_id_to_options();
            WP_CLI::log( "Last post ID in batch: {$this->last_post_id}" );

            // Loop through the pdf_doc posts and check for duplicates
            foreach ( $pdf_media_posts as $post ) {

                // Find and delete any attached PDF file
                $this->stash_deleted_pdf_media_posts[$post->ID]['pdf_post_id'] = $post->ID ?? '';
                $this->stash_deleted_pdf_media_posts[$post->ID]['pdf_post_title'] = $post->post_title ?? '';
                $this->stash_deleted_pdf_media_posts[$post->ID]['pdf_post_date'] = $post->post_date ?? '';
                $this->stash_deleted_pdf_media_posts[$post->ID]['pdf_file_path'] = $post->guid ?? '';

                // Once we have handled the PDF file, we need to delete the post
                if ( $this->for_real ) {
                    WP_CLI::log( "Deleting PDF Media post ID #{$post->ID} with title '{$post->post_title}'." );
                    $post_deleted = wp_delete_attachment( $post->ID, true );
                    $this->stash_deleted_pdf_media_posts[$post->ID]['deleted']  =  ( $post_deleted instanceof WP_POST ) ? true : false;
                    $this->total_deleted_posts++;
                    $this->stash_deleted_pdf_media_posts[$post->ID]['deletion_date'] = gmdate( "Y-m-d H:i:s", time() );
                } else {
                    WP_CLI::log( "Dry run: Would delete PDF Media post ID #{$post->ID} with title '{$post->post_title}'." );
                    $this->stash_deleted_pdf_media_posts[$post->ID]['deleted'] = false;
                    $this->stash_deleted_pdf_media_posts[$post->ID]['deletion_date'] = '';
                }

            }

            // Clean up the terms for the deleted posts
            $this->clean_up_media_terms();

            // Save the deleted post information to a CSV file
            $this->log_deleted_post_results();

            // All done!
            WP_CLI::success( "PDF Media deletion completed for post ID #{$this->start_post_id} through #{$this->last_post_id}." );
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

            $saved_start_post_id = get_option( 'pdf-media-deletion-start-post-id' );
            if ( $saved_start_post_id ) {
                $this->start_post_id = intval( $saved_start_post_id );
                WP_CLI::log( "Resuming from saved post ID: {$this->start_post_id}" );
                return; // If a saved start post ID exists, use it.
            }

            // If no start post ID is provided or saved, get the most recent DLP_Document post ID
            // We need the highest post ID, since we are processing posts in descending order
            if ( null === $this->start_post_id ) {
                global $wpdb;
                $this->start_post_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "
                        SELECT MAX(ID) FROM {$wpdb->posts}
                        WHERE post_type = %s
                            AND ( post_name LIKE %s OR post_name LIKE %s )
                            AND post_mime_type = %s
                        ",
                        'attachment',
                        '%SDS%',
                        '%TDS%',
                        'application/pdf',
                    )
                );
                WP_CLI::log( "Starting from highest PDF Media post ID: {$this->start_post_id}" );
                return;
            }

            // If no start post ID is provided or saved, get the most recent DLP_Document post ID
            // We need the highest post ID, since we are processing posts in descending order
            if ( null === $this->start_post_id ) {
                WP_CLI::error( "A start post ID was not found for the PDF Media post type. Quitting deletion run." );
            }
        }

        /**
         * Fetch PDF Media posts from the database.
         *
         * @param none
         * @return array Array of post objects representing PDF Medias.
         */
        private function get_pdf_media_posts(): array {
            global $wpdb;

            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "
                    SELECT * FROM {$wpdb->posts}
                    WHERE post_type = %s
                      AND ( post_name LIKE %s OR post_name LIKE %s )
                      AND post_mime_type = %s
                      AND ID < %d
                    ORDER BY ID DESC
                    LIMIT %d
                    ",
                    'attachment',
                    '%SDS%',
                    '%TDS%',
                    'application/pdf',
                    $this->start_post_id,
                    $this->batch_size
                )
            );

            // Set the last_post_id property to the first post ID in the results
            // The posts are ordered by ID DESC, so the first post is the highest post ID
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
                update_option( 'pdf-media-deletion-start-post-id', $this->last_post_id );
            }
        }

        /**
         * Clean up terms associated with deleted PDF Media posts.
         * This removes any terms that are no longer associated with any posts.
         * @param none
         * @return void
         */
        private function clean_up_media_terms(): void {

            // Update the term counts for all posts - This includes posts outside of PDF Media
            global $wpdb;
            $wpdb->query(
                "
                UPDATE {$wpdb->term_taxonomy} tt
                SET count = (SELECT count(p.ID)
                FROM {$wpdb->term_relationships} tr
                LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                WHERE tr.term_taxonomy_id = tt.term_taxonomy_id)
                "
            );
            WP_CLI::log( 'Updated term counts for all taxonomies.' );
        }

        /**
         * Handle logging duplicate posts results.
         * @return void
         */
        private function log_deleted_post_results(): void {
            if ( $this->for_real ) {
                // Log the number of deleted posts
                WP_CLI::log( "Total Document posts deleted: {$this->total_deleted_posts}" );
            }

            // Write the duplicate posts to a CSV file
            if (  ! empty( $this->stash_deleted_pdf_media_posts ) ) {
                // Create the logs directory if it doesn't exist
                if ( ! is_dir( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' ) ) {
                    WP_CLI::log( 'Creating logs directory...' );
                    wp_mkdir_p( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' );
                }
                $csv_prefix = $this->for_real ? 'for-real-' : 'dry-run-';
                $csv_file_path = fopen( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . $csv_prefix . 'deleted-pdf-media-posts-' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for deleted pdf media posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $csv_file_path,
                    $this->stash_deleted_pdf_media_posts,
                    array(
                        'pdf_post_id',
                        'pdf_post_title',
                        'pdf_post_date',
                        'pdf_file_path',
                        'deleted',
                        'deletion_date',
                    ),
                );

                WP_CLI::log( "Deleted document posts and PDFs written to CSV file: {$csv_file_path}" );
                fclose( $csv_file_path );
            }
        }
    }
    WP_CLI::add_command( 'pdf-media-delete', 'PDF_Media_Deletion_Command' );
}

if ( class_exists( 'PDF_Media_Deletion_Command' ) ) {

    /**
     * Clear out fields stored in wp_options related to PDF Media deleted.
     * This is useful for resetting the deletion process.
     *
     * Usage:
     *  wp pdf-media-delete-clear-options
     *
     * @param none
     * @return void
     */
    function clear_pdf_media_deletion_options(): void {
        delete_option( 'pdf-media-deletion-start-post-id' );
        WP_CLI::log( 'Cleared PDF Media deletion options.' );
    }
    WP_CLI::add_command( 'pdf-media-delete-clear-options', 'clear_pdf_media_deletion_options' );

    /**
     * Clear out CSV Log files stored in jb-library-maintenance/logs related to PDF Media deletion.
     *
     * Usage:
     *  wp pdf-media-delete-delete-logs
     *
     * @param none
     * @return void
     */
    function clear_pdf_media_deletion_log_files(): void {
        WP_CLI::confirm( 'Are you sure you want to delete all PDF Media deletion log files? If you need a CSV record of changes, make sure to download it before continuing.', 'yes' );
        $run_types = array( 'dry-run-', 'for-real-', '' );
        foreach( $run_types as $run_type ) {
            $log_files = glob( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . $run_type . 'deleted-pdf-media-posts-*.csv' );
            if ( ! empty( $log_files ) ) {
                foreach ( $log_files as $file ) {
                    @unlink( $file );
                }
                WP_CLI::log( 'Cleared out all PDF Media deletion log CSV files.' );
            } else {
                WP_CLI::log( 'No log CSV files found to delete.' );
            }
        }
    }
    WP_CLI::add_command( 'pdf-media-delete-clear-logs', 'clear_pdf_media_deletion_log_files' );
}
