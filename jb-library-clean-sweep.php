<?php
/**
 * WP-CLI Command to clean out the Document Library Pro (DLP) custom post type
 *
 * Requires WP-CLI to be installed and activated.
 *
 * Usage:
 *   wp dlp-document-delete [--dry-run] [--start-post-id=<id>] [--batch-size=<size>]
 *
 * Examples:
 *   wp dlp-document-delete --dry-run
 *   wp dlp-document-delete --start-post-id=500
 *   wp dlp-document-delete --dry-run --start-post-id=1000
 *   wp dlp-document-delete --dry-run --start-post-id=1000 --batch-size=50
 *
 * Run the above commands from the terminal.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

if ( ! class_exists( 'DLP_Document_Deletion_Command' ) ) {
    class DLP_Document_Deletion_Command {

        /**
         * Allow skipping confirmations.
         *
         * @var bool
         */
        private $skip_confirmations = false;

        /**
         * Whether to run in test mode (dry run).
         *
         * @var bool
         */
        private $dry_run = false;

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
         * Total number of DLP Document posts deleted.
         *
         * @var int
         */
        private $total_deleted_posts = 0;

        /**
         * Array of post IDs which were deleted.
         *
         * @var array
         */
        private $stash_deleted_dlp_doc_posts = array();

        /**
         * Delete DLP Document Posts and associated files.
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

            // Begin the deletion process
            $this->delete_dlp_docs();
        }

        /**
         * Delete DLP Document post and related PDFs.
         *
         * @param none
         * @return void
         */
        public function delete_dlp_docs(): void {
            WP_CLI::log( 'Starting DLP Document deletion...' );

            // Fetch DLP Document posts for this batch
            $dlp_doc_posts = $this->get_dlp_doc_posts();
            if ( empty( $dlp_doc_posts ) ) {
                WP_CLI::log( 'No DLP Document posts found to delete.' );
                return;
            }
            // Log the number of DLP Document posts found
            $dlp_doc_posts_count = count( $dlp_doc_posts );
            WP_CLI::log( "Found {$dlp_doc_posts_count} DLP Document posts to process." );
            $this->save_last_post_id_to_options();
            WP_CLI::log( "Last post ID in batch: {$this->last_post_id}" );

            // Loop through the dlp_doc posts and check for duplicates
            foreach ( $dlp_doc_posts as $post ) {
                // Find and delete any attached PDF file
                $attached_pdf_meta = $this->delete_pdf( $post->ID );
                $this->stash_deleted_dlp_doc_posts[$post->ID]['pdf_link_type'] = $attached_pdf_meta['pdf_link_type'] ?? '';
                $this->stash_deleted_dlp_doc_posts[$post->ID]['pdf_post_id']   = $attached_pdf_meta['pdf_post_id'] ?? '';
                $this->stash_deleted_dlp_doc_posts[$post->ID]['pdf_file_path'] = $attached_pdf_meta['pdf_file_path'] ?? '';
                $this->stash_deleted_dlp_doc_posts[$post->ID]['file_deleted']  = $attached_pdf_meta['file_deleted'] ?? '';

                // Log the post information and attached PDF file
                $this->stash_deleted_dlp_doc_posts[$post->ID]['dlp_documetn_post_id'] = $post->ID;
                $this->stash_deleted_dlp_doc_posts[$post->ID]['dlp_documetn_post_title'] = $post->post_title;
                $this->stash_deleted_dlp_doc_posts[$post->ID]['dlp_document_post_date'] = $post->post_date;

                // Once we have handled the PDF file, we need to delete the post
                if ( ! $this->dry_run ) {
                    WP_CLI::log( "Deleting DLP Document post ID #{$post->ID} with title '{$post->post_title}'." );
                    wp_delete_post( $post->ID, true );
                    $this->total_deleted_posts++;
                    $this->stash_deleted_dlp_doc_posts[$post->ID]['deleted'] = true;
                    $this->stash_deleted_dlp_doc_posts[$post->ID]['deletion_date'] = gmdate( "Y-m-d H:i:s", time() );
                } else {
                    WP_CLI::log( "Dry run: Would delete DLP Document post ID #{$post->ID} with title '{$post->post_title}'." );
                    $this->stash_deleted_dlp_doc_posts[$post->ID]['deleted'] = false;
                    $this->stash_deleted_dlp_doc_posts[$post->ID]['deletion_date'] = '';
                }

            }

            // Save the deleted post information to a CSV file
            $this->log_deleted_post_results();

            // All done!
            WP_CLI::success( "DLP Document deletion completed for post ID #{$this->start_post_id} through #{$this->last_post_id}." );
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

            $saved_start_post_id = get_option( 'dlp-document-deletion-start-post-id' );
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
                        ",
                        'dlp_document'
                    )
                );
                WP_CLI::log( "Starting from highest DLP Document post ID: {$this->start_post_id}" );
                return;
            }

            // If no start post ID is provided or saved, get the most recent DLP_Document post ID
            // We need the highest post ID, since we are processing posts in descending order
            if ( null === $this->start_post_id ) {
                WP_CLI::error( "A start post ID was not found for the DLP Document post type. Quitting deletion run." );
            }
        }

        /**
         * Fetch DLP Document posts from the database.
         *
         * @param none
         * @return array Array of post objects representing DLP Documents.
         */
        private function get_dlp_doc_posts(): array {
            global $wpdb;

            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "
                    SELECT * FROM {$wpdb->posts}
                    WHERE post_type = %s
                      AND ID < %d
                    ORDER BY ID DESC
                    LIMIT %d
                    ",
                    'dlp_document',
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
                update_option( 'dlp-document-deletion-start-post-id', $this->last_post_id );
            }
        }

        /**
         * Get the PDF file path or post ID for the DLP Document post.
         * If the PDF file is missing, handle it accordingly.
         *
         * @param string $post_id The post ID for the DLP Document.
         * @return array Array containing the PDF metadata or an empty array.
         */
        private function delete_pdf( string $dlp_document_post_id ): array {
            // We assume the DLP Document post has a PDF file attached to it
            $attached_pdf_meta = [];

            // Confirm that PDF file is attached by checking the post meta
            $pdf_link_type = get_post_meta( $dlp_document_post_id, '_dlp_document_link_type', true ) ?? null;

            switch ( $pdf_link_type ) {
                case 'url':
                    $pdf_file_path = get_post_meta( $dlp_document_post_id, '_dlp_direct_link_url', true ) ?? null;
                    // If the postmeta does not exist, the PDF file is missing so we return an empty array
                    if ( $pdf_file_path && file_exists( $pdf_file_path ) ) {
                        $attached_pdf_meta['pdf_link_type'] = $pdf_link_type;
                        $attached_pdf_meta['pdf_file_path'] = $pdf_file_path;
                        $attached_pdf_meta['pdf_post_id'] = attachment_url_to_postid( $pdf_file_path );

                        if ( ! $this->dry_run ) {
                            // If the PDF file exists, delete it
                            if ( wp_delete_post( $attached_pdf_meta['pdf_post_id'], true ) ) {
                                $attached_pdf_meta['file_deleted'] = true;
                            } else {
                                $attached_pdf_meta['file_deleted'] = unlink( $attached_pdf_meta['pdf_file_path'] );
                            }
                            WP_CLI::log( "Deleted PDF file at {$pdf_file_path}." );
                        } else {
                            WP_CLI::log( "Dry run: Would delete PDF file at {$pdf_file_path}." );
                            $attached_pdf_meta['file_deleted'] = false;
                        }
                    }
                    break;
                case 'file':
                    $pdf_post_id = get_post_meta( $dlp_document_post_id, '_dlp_attached_file_id', true ) ?? null;
                    // If the postmeta does not exist, we assume the PDF file is missing so we return an empty array
                    // If the postmeta contains a pdf post ID, check that the pdf post exists
                    if ( ( $pdf_post_id && ! get_post_status( $pdf_post_id ) ) || null === $pdf_post_id ) {
                        $attached_pdf_meta['pdf_link_type'] = $pdf_link_type;
                        $attached_pdf_meta['pdf_file_path'] = get_attached_file( $pdf_post_id );
                        $attached_pdf_meta['pdf_post_id'] = $pdf_post_id;

                        $pdf_file_path = $attached_pdf_meta['pdf_file_path'];
                        if ( ! $this->dry_run ) {
                            // Delete the file
                            if ( wp_delete_post( $pdf_post_id, true ) ) {
                                $attached_pdf_meta['file_deleted'] = true;
                            } else {
                                $attached_pdf_meta['file_deleted'] = unlink( $attached_pdf_meta['pdf_file_path'] );
                            }
                            // Log the deletion
                            if ( ! $attached_pdf_meta['file_deleted'] ) {
                                WP_CLI::warning( "Failed to delete PDF file at {$pdf_file_path}." );
                            } else {
                                WP_CLI::log( "Deleted PDF file at {$pdf_file_path}." );
                            }
                        } else {
                            WP_CLI::log( "Dry run: Would delete PDF file at {$pdf_file_path}." );
                            $attached_pdf_meta['file_deleted'] = false;
                        }
                    }
                    break;
                default:
                    // If the link type is not recognized, we assume the PDF file is missing so we return an empty array
                    break;
            }
            // To-do: this is only returning an empty array. Not sure why.
            return $attached_pdf_meta;
        }

        /**
         * Handle logging duplicate posts results.
         * @return void
         */
        private function log_deleted_post_results(): void {
            // Log the number of duplicate posts found
            WP_CLI::log( "Total duplicate posts found: {$this->total_deleted_posts}" );

            // Write the duplicate posts to a CSV file
            if (  ! empty( $this->stash_deleted_dlp_doc_posts ) ) {
                $csv_prefix = $this->dry_run ? 'dry-run-' : 'for-real-';
                $csv_file_path = fopen( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . $csv_prefix . 'deleted-dlp-doc-posts-' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for duplicate posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $csv_file_path,
                    $this->stash_deleted_dlp_doc_posts,
                    array(
                        'dlp_document_post_id',
                        'dlp_document_post_title',
                        'dlp_document_post_date',
                        'deleted',
                        'deletion_date',
                        'pdf_link_type',
                        'pdf_file_path',
                        'pdf_post_id',
                        'pdf_file_deleted',
                    ),
                );

                WP_CLI::log( "Duplicate posts written to CSV file: {$csv_file_path}" );
                fclose( $csv_file_path );
            }
        }
    }
    WP_CLI::add_command( 'dlp-document-delete', 'DLP_Document_Deletion_Command' );
}

if ( class_exists( 'DLP_Document_Deletion_Command' ) ) {

    /**
     * Clear out fields stored in wp_options related to DLP Document deleted.
     * This is useful for resetting the deletion process.
     *
     * Usage:
     *  wp dlp-document-delete-clear-options
     *
     * @param none
     * @return void
     */
    function clear_dlp_document_deletion_options(): void {
        delete_option( 'dlp-document-deletion-start-post-id' );
        WP_CLI::log( 'Cleared DLP Document deletion options.' );
    }
    WP_CLI::add_command( 'dlp-document-delete-clear-options', 'clear_dlp_document_deletion_options' );

    /**
     * Clear out CSV Log files stored in jb-library-maintenance/logs related to DLP Document deletion.
     *
     * Usage:
     *  wp dlp-document-delete-delete-logs
     *
     * @param none
     * @return void
     */
    function clear_dlp_document_deletion_log_files(): void {
        WP_CLI::confirm( 'Are you sure you want to delete all DLP Document deletion log files? If you need a CSV record of changes, make sure to download it before continuing.', 'yes' );
        $run_types = array( 'dry-run-', 'for-real-', '' );
        foreach( $run_types as $run_type ) {
            $log_files = glob( JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/' . $run_type . 'deleted-dlp-doc-posts-*.csv' );
            if ( ! empty( $log_files ) ) {
                foreach ( $log_files as $file ) {
                    @unlink( $file );
                }
                WP_CLI::log( 'Cleared out all DLP Document deletion log CSV files.' );
            } else {
                WP_CLI::log( 'No log CSV files found to delete.' );
            }
        }
    }
    WP_CLI::add_command( 'dlp-document-delete-clear-logs', 'clear_dlp_document_deletion_log_files' );
}
