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
         * Holds unique post titles to check for duplicates.
         *
         * @var array
         */
        private $unique_post_titles = array();

        /**
         * Holds the duplicate posts which have been deleted.
         * This will allow us to log the deleted posts in a CSV file at the end of the batch
         *
         * @var array
         */
        private $stash_of_duplicate_dlp_doc_posts = array();

        /**
         * Total number of duplicate DLP Document posts detected.
         *
         * @var int
         */
        private $total_duplicate_posts = 0;

        /**
         * Holds the posts which have been deleted because the PDF file is missing.
         * This will allow us to log the deleted posts in a CSV file at the end of the batch
         *
         * @var array
         */
        private $stash_of_missing_pdf_posts = array();

        /**
         * Total number of DLP Document posts with a missing PDF file.
         *
         * @var int
         */
        private $total_missing_pdf_posts = 0;

        /**
         * Search for duplicate DLP Document files.
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
            $saved_unique_post_titles = get_option( 'one-time-script-dlp-deduplication-unique-post-titles', array() );
            if ( is_array( $saved_unique_post_titles ) ) {
                $this->unique_post_titles = $saved_unique_post_titles;
                WP_CLI::log( 'Loaded unique post records from options.' );
            } else {
                WP_CLI::log( 'No unique post records found in options.' );
            }

            // Being the deduplication process
            $this->deduplicate_dlp_docs();
        }

        /**
         * Deduplicate DLP Document files in the WordPress media library.
         *
         * @param none
         * @return void
         * @when after_wp_load
         */
        public function deduplicate_dlp_docs(): void {
            WP_CLI::log( 'Starting DLP Document deduplication...' );

            // Fetch DLP Document posts for this batch
            $dlp_doc_posts = $this->get_dlp_doc_posts();
            if ( empty( $dlp_doc_posts ) ) {
                WP_CLI::log( 'No DLP Document posts found to deduplicate.' );
                return;
            }
            // Log the number of DLP Document posts found
            $dlp_doc_posts_count = count( $dlp_doc_posts );
            WP_CLI::log( "Found {$dlp_doc_posts_count} DLP Document posts to process." );
            $this->save_last_post_id_to_options();
            WP_CLI::log( "Last post ID in batch: {$this->last_post_id}" );

            // Loop through the dlp_doc posts and check for duplicates
            foreach ( $dlp_doc_posts as $post ) {
                $post_title = $post->post_title;
                $matching_post_title_id = null;

                WP_CLI::log( "Checking post ID {$post->ID} with title '{$post_title}' for duplicates." );

                // Confirm if the post has a valid PDF file attached to it
                $attached_pdf_meta = $this->determine_if_pdf_exists( $post );
                if ( empty( $attached_pdf_meta ) ) {
                    continue;
                }

                // Check if the post title is already in the unique titles array
                $matching_post_title_id = array_search( $post_title, $this->unique_post_titles, true );
                if ( ! empty( $matching_post_title_id ) ) {
                    $this->handle_duplicate_post( $post, $attached_pdf_meta, $matching_post_title_id );
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
                        $this->handle_duplicate_post( $post, $attached_pdf_meta, $matching_post_title_id );
                        continue;
                    }
                }

                // "-2" is a common suffix for duplicates, so we check for it
                if ( str_contains( $post_title, '-2' ) ) {
                    str_replace( '-2', '', $post_title );
                    $matching_post_title_id = array_search( $post_title, $this->unique_post_titles, true );
                    if ( ! empty( $matching_post_title_id ) ) {
                        $this->handle_duplicate_post( $post, $attached_pdf_meta, $matching_post_title_id );
                        continue;
                    }
                }

                // "-pdf" is a common suffix for duplicates, so we check for it
                if ( str_contains( $post_title, '-pdf' ) ) {
                    str_replace( '-pdf', '', $post_title );
                    $matching_post_title_id = array_search( $post_title, $this->unique_post_titles, true );
                    if ( ! empty( $matching_post_title_id ) ) {
                        $this->handle_duplicate_post( $post, $attached_pdf_meta, $matching_post_title_id );
                        continue;
                    }
                }

                // If we reach here, the post is unique and valid
                // Add the unmodified post title to the unique titles array
                $this->unique_post_titles[$post->ID] = $post->post_title;
            }

            // Save the unique post titles to options
            $this->save_unique_post_titles_to_options();

            // Handle logging the results
            $this->log_duplicate_post_results();
            $this->log_missing_pdf_results();

            // Log the number of unique post titles found
            WP_CLI::log( 'Unique DLP Document posts found: ' . count( $this->unique_post_titles ) );

            // Your deduplication logic here, using $this->dry_run and $this->start_post_id to control actions.
            WP_CLI::success( "DLP Document deduplication completed for post ID #{$this->start_post_id} through #{$this->last_post_id}." );
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

            $saved_start_post_id = get_option( 'one-time-script-dlp-deduplication-start-post-id' );
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
                WP_CLI::error( "A start post ID was not found for the DLP Document post type. Quitting deduplication run." );
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
                update_option( 'one-time-script-dlp-deduplication-start-post-id', $this->last_post_id );
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
                update_option( 'one-time-script-dlp-deduplication-unique-post-titles', $this->unique_post_titles );
            }
        }

        /**
         * Handle a duplicate post when it is found.
         *
         * @param object $post The post object that is a duplicate.
         * @param int|string $matching_post_title_id The IDs of posts with the same title.
         * @return void
         */
        private function handle_duplicate_post( object $duplicate_post, array $attached_pdf_meta, int|string $matching_post_title_id ): void {
            $this->total_duplicate_posts++;
            // Determine if the PDF is attached via a post or URL
            $matching_post_pdf_link_type = get_post_meta( $matching_post_title_id, '_dlp_document_link_type', true ) ?? null;

            // Set the meta key based on the link type
            $matching_post_pdf_meta_key = '';
            if ( 'url' === $matching_post_pdf_link_type ) {
                $matching_post_pdf_meta_key = '_dlp_direct_link_url';
            }

            if ( 'file' === $matching_post_pdf_link_type ) {
                $matching_post_pdf_meta_key = '_dlp_attached_file_id';
            }

            $matching_attached_pdf = '';
            if ( ! empty ( $matching_post_pdf_meta_key ) ) {
                $matching_attached_pdf = get_post_meta( $matching_post_title_id, $matching_post_pdf_meta_key, true );
            }
            $duplicate_attached_pdf = $attached_pdf_meta['pdf_file'];

            $duplicate_post_message =
                "
                    Duplicate DLP Document found. Original post ID {$matching_post_title_id} with title '{$this->unique_post_titles[$matching_post_title_id]}'
                    ({$matching_attached_pdf}).
                    Duplicate post ID {$duplicate_post->ID} has title '{$duplicate_post->post_title}' ({$duplicate_attached_pdf}).
                ";

            if ( $this->dry_run ) {
                WP_CLI::log( "Dry run: " . $duplicate_post_message );
                if ( ! $this->skip_confirmations ) {
                    WP_CLI::confirm( 'Do you want to log the duplicate DLP Document post to CSV?', 'yes' );
                }
                $this->gather_duplicate_posts_data(
                    $duplicate_post,
                    $attached_pdf_meta,
                    $matching_post_title_id,
                    $matching_post_pdf_link_type,
                    $matching_attached_pdf
                );
                return;
            }

            if ( ! $this->dry_run ) {
                // Logic to handle duplicates, e.g., delete or mark as duplicate
                WP_CLI::log( $duplicate_post_message);
                if ( ! $this->skip_confirmations ) {
                    WP_CLI::confirm( 'Do you want to delete the duplicate DLP Document post?', 'yes' );
                }
                $is_duplicate_logged = $this->gather_duplicate_posts_data(
                    $duplicate_post,
                    $attached_pdf_meta,
                    $matching_post_title_id,
                    $matching_post_pdf_link_type,
                    $matching_attached_pdf
                );

                if ( $is_duplicate_logged ) {
                    $dlp_doc_taxonomies = get_object_taxonomies( 'dlp_document' );
                    wp_delete_object_term_relationships( $duplicate_post->ID, $dlp_doc_taxonomies );
                    wp_delete_post( $duplicate_post->ID, true );
                }
                WP_CLI::log( "Deleted duplicate post ID {$duplicate_post->ID}." );
                return;
            }
        }

        /**
         * Get the PDF file path or post ID for the DLP Document post.
         * If the PDF file is missing, handle it accordingly.
         *
         * @param object $post The post object that is a DLP Document.
         * @return array True if the PDF file exists, false otherwise.
         */
        private function determine_if_pdf_exists( object $dlp_document_post ): array {
            // We assume the DLP Document post has a PDF file attached to it
            $attached_pdf_meta = [];

            // Confirm that PDF file is attached by checking the post meta
            $pdf_link_type = get_post_meta( $dlp_document_post->ID, '_dlp_document_link_type', true ) ?? null;

            switch ( $pdf_link_type ) {
                case 'url':
                    $pdf_file_path = get_post_meta( $dlp_document_post->ID, '_dlp_direct_link_url', true ) ?? null;
                    // If the postmeta does not exist, the PDF file is missing
                    if ( null === $pdf_file_path ) {
                        $this->handle_missing_pdf_file( $dlp_document_post, $pdf_link_type, null );
                    }

                    // If the postmeta exists, check that the file exists
                    if ( ($pdf_file_path && ! file_exists( $pdf_file_path ) ) || null === $pdf_file_path ) {
                        $this->handle_missing_pdf_file( $dlp_document_post, $pdf_link_type, $pdf_file_path );
                    }

                    $attached_pdf_meta['link_type'] = $pdf_link_type;
                    $attached_pdf_meta['pdf_file'] = $pdf_file_path;
                    break;
                case 'file':
                    $pdf_post_id = get_post_meta( $dlp_document_post->ID, '_dlp_attached_file_id', true ) ?? null;
                    // If the postmeta does not exist, we assume the PDF file is missing
                    if ( null === $pdf_post_id ) {
                        $this->handle_missing_pdf_file( $dlp_document_post, $pdf_link_type, null );
                    }

                    // If the postmeta contains a document post ID, check that the document post exists
                    if ( ( $pdf_post_id && ! get_post_status( $pdf_post_id ) ) || null === $pdf_post_id ) {
                        $this->handle_missing_pdf_file( $dlp_document_post, $pdf_link_type, $pdf_post_id );
                    }

                    $attached_pdf_meta['link_type'] = $pdf_link_type;
                    $attached_pdf_meta['pdf_file'] = $pdf_post_id;
                    break;
                default:
                    // If the DLP Document post is neither a direct link nor a media library attachment, it should be deleted
                    $this->handle_missing_pdf_file( $dlp_document_post, $pdf_link_type, null );
                    break;
            }

            return $attached_pdf_meta;
        }

        /**
         * Handle a DLP Document post with a missing PDF file.
         * Allows us to clean out posts with invalid PDF file URLs attached to them.
         *
         * @param object $post The post object that is a duplicate.
         * @param int|string $missing_pdf_url The URL of the PDF file which is missing.
         * @return void
         */
        private function handle_missing_pdf_file(
                object $dlp_document_post,
                null|string $pdf_link_type = null,
                null|string $missing_pdf_id_or_url = null
        ): void {
            $this->total_missing_pdf_posts++;
            $missing_pdf_message =
                "
                    The PDF attached to DLP Document post ID {$dlp_document_post->ID} with title '{$dlp_document_post->post_title}' does not exist.
                ";

            if ( 'url' === $pdf_link_type ) {
                $missing_pdf_message .= " The url of the missing PDF file is {$missing_pdf_id_or_url}).";
            }

            if ( 'file' === $pdf_link_type ) {
                $missing_pdf_message .= " The ID of the missing PDF post is {$missing_pdf_id_or_url}).";
            }


            if ( $this->dry_run ) {
                WP_CLI::log( "Dry run: " . $missing_pdf_message );
                if ( ! $this->skip_confirmations ) {
                    WP_CLI::confirm( 'Log the DLP Document post and missing PDF file to CSV?', 'yes' );
                }
                $this->gather_missing_pdf_posts_data( $dlp_document_post, $pdf_link_type, $missing_pdf_id_or_url );
                return;
            }

            if ( ! $this->dry_run ) {
                // Logic to handle duplicates, e.g., delete or mark as duplicate
                WP_CLI::log( $missing_pdf_message);
                if ( ! $this->skip_confirmations ) {
                    WP_CLI::confirm( 'Do you want to delete the DLP Document post since the PDF is missing?', 'yes' );
                }
                $this->gather_missing_pdf_posts_data( $dlp_document_post, $pdf_link_type, $missing_pdf_id_or_url );
                wp_delete_post( $dlp_document_post->ID, true );
                WP_CLI::log( "Deleted duplicate post ID {$dlp_document_post->ID}." );
                return;
            }
        }

        /**
         * Gather the duplicate posts data for logging later
         * This will be used to log the deleted posts in a CSV file at the end of the batch
         *
         * @param object $post The post object that is a duplicate.
         * @param int|string $matching_post_title_id The IDs of posts with the same title.
         * @return bool True if the data was gathered successfully, false otherwise.
         */
        private function gather_duplicate_posts_data(
            object $duplicate_post,
            array $attached_pdf_meta,
            int|string $matching_post_title_id,
            string $matching_post_pdf_link_type,
            string $matching_attached_pdf
        ): bool {
            if ( empty( $matching_attached_pdf ) ) {
                return false; // If the matching attached PDF is empty, we cannot log the duplicate post
            }

            $this->stash_of_duplicate_dlp_doc_posts[] = array(
                'original_post_id'           => $matching_post_title_id,
                'original_post_title'        => $this->unique_post_titles[$matching_post_title_id],
                'original_post_link_type'    => $matching_post_pdf_link_type,
                'original_dlp_doc_pdf'       => $matching_attached_pdf,
                'duplicate_post_id'          => $duplicate_post->ID,
                'duplicate_post_title'       => $duplicate_post->post_title,
                'duplicate_post_link_type'   => $attached_pdf_meta['link_type'],
                'duplicate_dlp_doc_pdf'      => $attached_pdf_meta['pdf_file'],
            );
            return true;
        }

        /**
         * Gather the duplicate posts data for logging later
         * This will be used to log the deleted posts in a CSV file at the end of the batch
         *
         * @param object $post The post object that is a duplicate.
         * @param int|string $matching_post_title_id The IDs of posts with the same title.
         * @return void
         */
        private function gather_missing_pdf_posts_data(
            object $dlp_doc_post,
            null|string $pdf_link_type = null,
            null|string $missing_pdf_id_or_url
        ): void {
            $this->stash_of_missing_pdf_posts[] = array(
                'dlp_document_post_id'      => $dlp_doc_post->ID,
                'dlp_document_post_title'   => $dlp_doc_post->post_title,
                'pdf_link_type'           => $pdf_link_type,
                'missing_pdf_id_or_url'   => $missing_pdf_id_or_url,
            );
        }

        /**
         * Handle logging duplicate posts results.
         * @return void
         */
        private function log_duplicate_post_results(): void {
            // Log the number of duplicate posts found
            WP_CLI::log( "Total duplicate posts found: {$this->total_duplicate_posts}" );

            // Log the number of duplicate posts recorded or deleted
            if ( $this->dry_run ) {
                WP_CLI::log( 'Total duplicate posts logged: ' . count( $this->stash_of_duplicate_dlp_doc_posts ) );
            } else {
                WP_CLI::log( 'Total duplicate posts deleted: ' . count( $this->stash_of_duplicate_dlp_doc_posts )  );
            }

            // Write the duplicate posts to a CSV file
            if (  ! empty( $this->stash_of_duplicate_dlp_doc_posts ) ) {
                $csv_prefix = $this->dry_run ? 'dry-run-' : 'deleted-';
                $csv_file_path = fopen( JB_DEDUP_PLUGIN_DIR . 'logs/' . $csv_prefix . 'duplicate-dlp-doc-posts-' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for duplicate posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $csv_file_path,
                    $this->stash_of_duplicate_dlp_doc_posts,
                    array(
                        'original_post_id',
                        'original_post_title',
                        'original_post_link_type',
                        'original_dlp_doc_pdf',
                        'duplicate_post_id',
                        'duplicate_post_title',
                        'duplicate_post_link_type',
                        'duplicate_dlp_doc_pdf',
                    ),
                );

                WP_CLI::log( "Duplicate posts written to CSV file: {$csv_file_path}" );
                fclose( $csv_file_path );
            }
        }

        /**
         * Handle logging missing PDF results.
         * @return void
         */
        private function log_missing_pdf_results(): void {
            // Log the number of duplicate posts found
            WP_CLI::log( "Total posts with missing PDF file found: {$this->total_missing_pdf_posts}" );

            // Log the number of duplicate posts recorded or deleted
            if ( $this->dry_run ) {
                WP_CLI::log( 'Total posts with missing PDF file logged: ' . count( $this->stash_of_missing_pdf_posts ) );
            } else {
                WP_CLI::log( 'Total posts with missing PDF file deleted: ' . count( $this->stash_of_missing_pdf_posts )  );
            }

            // Write the duplicate posts to a CSV file
            if (  ! empty( $this->stash_of_missing_pdf_posts ) ) {
                $csv_prefix = $this->dry_run ? 'dry-run-' : 'deleted-';
                $csv_file_path = fopen( JB_DEDUP_PLUGIN_DIR . 'logs/' . $csv_prefix . 'dlp-doc-posts-missing-pdf-' . gmdate( "Ymd-His", time() ) . '.csv', 'x' );
                if ( ! $csv_file_path ) {
                    WP_CLI::error( 'Failed to create CSV file for missing PDF posts.' );
                    return;
                }

                // Write the header and data to the CSV file
                WP_CLI\Utils\write_csv(
                    $csv_file_path,
                    $this->stash_of_missing_pdf_posts,
                    array(
                        'dlp_document_post_id',
                        'dlp_document_post_title',
                        'pdf_link_type',
                        'missing_pdf_id_or_url',
                    ),
                );

                WP_CLI::log( "Missing PDF posts written to CSV file: {$csv_file_path}" );
                fclose( $csv_file_path );
            }
        }
    }
    WP_CLI::add_command( 'dlp-document-delete', 'DLP_Document_Deduplication_Command' );
}

if ( class_exists( 'DLP_Document_Deduplication_Command' ) ) {

    /**
     * Clear out fields stored in wp_options related to DLP Document deduplication.
     * This is useful for resetting the deduplication process.
     *
     * Usage:
     *  wp dlp-document-delete-clear-options
     *
     * @param none
     * @return void
     */
    function clear_dlp_document_deduplication_options(): void {
        delete_option( 'one-time-script-dlp-deduplication-start-post-id' );
        delete_option( 'one-time-script-dlp-deduplication-unique-post-titles' );
        WP_CLI::log( 'Cleared DLP Document deduplication options.' );
    }
    WP_CLI::add_command( 'dlp-document-delete-clear-options', 'clear_dlp_document_deduplication_options' );

    /**
     * Clear out CSV Log files stored in jb-deduplication/logs related to DLP Document deduplication.
     *
     * Usage:
     *  wp dlp-document-delete-delete-logs
     *
     * @param none
     * @return void
     */
    function delete_dlp_document_deduplication_log_files(): void {
        WP_CLI::confirm( 'Are you sure you want to delete all DLP Document deduplication log files? If you need a CSV record of changes, make sure to download it before continuing.', 'yes' );
        $run_types = array( 'dry-run-', 'deleted-', '' );
        foreach( $run_types as $run_type ) {
            $log_files = glob( JB_DEDUP_PLUGIN_DIR . 'logs/' . $run_type . 'duplicate-dlp-doc-posts-*.csv' );
            if ( ! empty( $log_files ) ) {
                foreach ( $log_files as $file ) {
                    @unlink( $file );
                }
                WP_CLI::log( 'Deleted all DLP Document deduplication log CSV files.' );
            } else {
                WP_CLI::log( 'No log CSV files found to delete.' );
            }
        }
    }
    WP_CLI::add_command( 'dlp-document-delete-delete-logs', 'delete_dlp_document_deduplication_log_files' );

    /**
     * Clear out CSV Log files stored in jb-deduplication/logs related to DLP Document missing PDFs.
     *
     * Usage:
     *  wp dlp-document-delete-delete-logs
     *
     * @param none
     * @return void
     */
    function delete_dlp_document_missing_pdf_log_files(): void {
        WP_CLI::confirm( 'Are you sure you want to delete all DLP Document missing PDF log files? If you need a CSV record of changes, make sure to download it before continuing.', 'yes' );
        $run_types = array( 'dry-run-', 'deleted-', '' );
        foreach( $run_types as $run_type ) {
            $log_files = glob( JB_DEDUP_PLUGIN_DIR . 'logs/' . $run_type . 'dlp-doc-posts-missing-pdf-*.csv' );
            if ( ! empty( $log_files ) ) {
                foreach ( $log_files as $file ) {
                    @unlink( $file );
                }
                WP_CLI::log( 'Deleted all DLP Document missing PDF log CSV files.' );
            } else {
                WP_CLI::log( 'No log CSV files found to delete.' );
            }
        }
    }
    WP_CLI::add_command( 'dlp-document-missing-pdf-delete-logs', 'delete_dlp_document_missing_pdf_log_files' );
}
