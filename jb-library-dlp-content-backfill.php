<?php
/**
 * Backfill Document Library Pro post content from attached PDF files.
 *
 * Usage:
 *   wp dlp-backfill-document-content --dry-run
 *   wp dlp-backfill-document-content --for-real --batch-size=100
 *   wp dlp-backfill-document-content --post-id=12345 --dry-run
 *   wp dlp-backfill-document-content --for-real --use-fallback --start-post-id=30000 --end-post-id=33000
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

use Smalot\PdfParser\Parser;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

if ( ! function_exists( 'jb_library_resolve_dlp_document_pdf_path' ) ) {
    /**
     * Resolve a DLP document post to a local PDF path.
     */
    function jb_library_resolve_dlp_document_pdf_path( int $document_id ): string {
        $candidates = array();
        $source     = (string) get_post_meta( $document_id, '_dlp_attachment_source', true );
        $file_id    = (int) get_post_meta( $document_id, '_dlp_attached_file_id', true );

        if ( '' !== $source ) {
            $uploads_position = strpos( $source, '/wp-content/uploads/' );
            if ( false !== $uploads_position ) {
                $relative_upload_path = substr( $source, $uploads_position + strlen( '/wp-content/uploads/' ) );
                $candidates[]         = WP_CONTENT_DIR . '/uploads/' . ltrim( $relative_upload_path, '/' );
            }

            $candidates[] = $source;
        }

        if ( $file_id > 0 ) {
            $attached_file = get_post_meta( $file_id, '_wp_attached_file', true );
            if ( is_string( $attached_file ) && '' !== $attached_file ) {
                $upload_dir   = wp_get_upload_dir();
                $candidates[] = trailingslashit( $upload_dir['basedir'] ) . ltrim( $attached_file, '/' );
                $candidates[] = WP_CONTENT_DIR . '/uploads/' . ltrim( $attached_file, '/' );
            }
        }

        foreach ( array_unique( array_filter( $candidates ) ) as $candidate ) {
            if ( is_file( $candidate ) ) {
                return $candidate;
            }
        }

        return '';
    }
}

if ( ! function_exists( 'jb_library_clean_pdf_text_for_content' ) ) {
    /**
     * Normalize parser output into text suitable for post_content.
     */
    function jb_library_clean_pdf_text_for_content( string $text ): string {
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        $text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $text );
        $text = preg_replace( "/[ \t]+/", ' ', (string) $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

        return trim( (string) $text );
    }
}

if ( ! function_exists( 'jb_library_pdf_text_looks_usable' ) ) {
    /**
     * Determine whether parsed text is useful enough to write to a document post.
     */
    function jb_library_pdf_text_looks_usable( string $text, int $min_chars = 200 ): bool {
        $length = strlen( trim( $text ) );
        if ( $length < $min_chars ) {
            return false;
        }

        $letters = preg_match_all( '/[A-Za-z]/', $text );
        if ( false === $letters || $letters < 100 ) {
            return false;
        }

        $letter_ratio = $letters / max( $length, 1 );
        if ( $letter_ratio < 0.25 ) {
            return false;
        }

        $clues = array(
            'safety data sheet',
            'material safety data sheet',
            'technical data sheet',
            'product data sheet',
            'identification',
            'hazard',
            'composition',
            'physical',
            'properties',
            'product',
            'manufacturer',
            'handling',
            'storage',
            'application',
            'resin',
            'mixture',
        );

        $lower = strtolower( $text );
        foreach ( $clues as $clue ) {
            if ( false !== strpos( $lower, $clue ) ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'jb_library_get_dlp_backfill_log_path' ) ) {
    /**
     * Get the CSV path used by the backfill command.
     */
    function jb_library_get_dlp_backfill_log_path( array $assoc_args ): string {
        if ( isset( $assoc_args['log-file'] ) && is_string( $assoc_args['log-file'] ) && '' !== trim( $assoc_args['log-file'] ) ) {
            return trim( $assoc_args['log-file'] );
        }

        $log_dir = JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs/';
        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        return $log_dir . 'dlp-document-content-backfill-' . gmdate( 'Ymd-His' ) . '.csv';
    }
}

if ( ! function_exists( 'jb_library_backfill_dlp_document_content' ) ) {
    /**
     * Backfill empty DLP document post content from the related PDF file.
     */
    function jb_library_backfill_dlp_document_content( array $args, array $assoc_args = array() ): void {
        global $wpdb;

        $for_real   = isset( $assoc_args['for-real'] );
        $force      = isset( $assoc_args['force'] );
        $use_fallback = isset( $assoc_args['use-fallback'] );
        $batch_size = isset( $assoc_args['batch-size'] ) && is_numeric( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 100;
        $limit      = isset( $assoc_args['limit'] ) && is_numeric( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
        $post_id    = isset( $assoc_args['post-id'] ) && is_numeric( $assoc_args['post-id'] ) ? absint( $assoc_args['post-id'] ) : 0;
        $start_post_id = isset( $assoc_args['start-post-id'] ) && is_numeric( $assoc_args['start-post-id'] ) ? absint( $assoc_args['start-post-id'] ) : 0;
        $end_post_id = isset( $assoc_args['end-post-id'] ) && is_numeric( $assoc_args['end-post-id'] ) ? absint( $assoc_args['end-post-id'] ) : 0;
        $min_chars  = isset( $assoc_args['min-chars'] ) && is_numeric( $assoc_args['min-chars'] ) ? absint( $assoc_args['min-chars'] ) : 200;
        $log_path   = jb_library_get_dlp_backfill_log_path( $assoc_args );

        if ( $batch_size < 1 ) {
            $batch_size = 100;
        }

        $where = "post_type = 'dlp_document' AND post_status NOT IN ('trash', 'auto-draft')";
        if ( ! $force ) {
            $where .= " AND (post_content IS NULL OR TRIM(post_content) = '')";
        }

        if ( $post_id > 0 ) {
            $where .= $wpdb->prepare( ' AND ID = %d', $post_id );
        } else {
            if ( $start_post_id > 0 ) {
                $where .= $wpdb->prepare( ' AND ID >= %d', $start_post_id );
            }

            if ( $end_post_id > 0 ) {
                $where .= $wpdb->prepare( ' AND ID <= %d', $end_post_id );
            }
        }

        $query = "SELECT ID, post_title, CHAR_LENGTH(TRIM(post_content)) AS content_length
            FROM {$wpdb->posts}
            WHERE {$where}
            ORDER BY ID";

        if ( $limit > 0 ) {
            $query .= ' LIMIT ' . $limit;
        } elseif ( $batch_size > 0 ) {
            $query .= ' LIMIT ' . $batch_size;
        }

        $documents = $wpdb->get_results( $query, ARRAY_A );

        if ( empty( $documents ) ) {
            WP_CLI::success( 'No DLP document posts matched the backfill criteria.' );
            return;
        }

        WP_CLI::log( $for_real ? 'Running DLP content backfill FOR REAL.' : 'Running DLP content backfill as a dry run.' );
        WP_CLI::log( $use_fallback ? 'PDF text fallback is enabled for this run.' : 'Using bundled parser only. Add --use-fallback for local OCR fallback.' );
        WP_CLI::log( sprintf( 'Matched %d document post(s). Log: %s', count( $documents ), $log_path ) );

        $log_handle = fopen( $log_path, 'w' );
        if ( false === $log_handle ) {
            WP_CLI::error( 'Could not open log file for writing: ' . $log_path );
            return;
        }

        fputcsv(
            $log_handle,
            array(
                'document_id',
                'post_title',
                'status',
                'message',
                'content_length_before',
                'extracted_content_length',
                'pdf_path',
            )
        );

        $parser = new Parser();
        $counts = array(
            'updated'           => 0,
            'would_update'      => 0,
            'skipped_unusable'  => 0,
            'skipped_missing'   => 0,
            'parse_error'       => 0,
        );

        foreach ( $documents as $document ) {
            $document_id = (int) $document['ID'];
            $title       = (string) $document['post_title'];
            $before      = (int) $document['content_length'];
            $pdf_path    = jb_library_resolve_dlp_document_pdf_path( $document_id );

            if ( '' === $pdf_path ) {
                $counts['skipped_missing']++;
                fputcsv( $log_handle, array( $document_id, $title, 'skipped_missing_file', 'Could not resolve local PDF path.', $before, 0, '' ) );
                continue;
            }

            try {
                if ( $use_fallback ) {
                    $scraper      = new JB_PDF_Scraper( $pdf_path );
                    $cleaned_text = jb_library_clean_pdf_text_for_content( $scraper->cleaned_text );
                } else {
                    $pdf          = $parser->parseFile( $pdf_path );
                    $raw_text     = method_exists( $pdf, 'getText' ) ? $pdf->getText() : (string) $pdf;
                    $cleaned_text = jb_library_clean_pdf_text_for_content( (string) $raw_text );
                }
            } catch ( Throwable $exception ) {
                $counts['parse_error']++;
                fputcsv( $log_handle, array( $document_id, $title, 'parse_error', $exception->getMessage(), $before, 0, $pdf_path ) );
                continue;
            }

            $extracted_length = strlen( $cleaned_text );
            if ( ! jb_library_pdf_text_looks_usable( $cleaned_text, $min_chars ) ) {
                $counts['skipped_unusable']++;
                fputcsv( $log_handle, array( $document_id, $title, 'skipped_unusable_text', 'Parsed text did not pass usability checks.', $before, $extracted_length, $pdf_path ) );
                continue;
            }

            if ( $for_real ) {
                $updated = wp_update_post(
                    array(
                        'ID'           => $document_id,
                        'post_content' => $cleaned_text,
                    ),
                    true
                );

                if ( is_wp_error( $updated ) ) {
                    $counts['parse_error']++;
                    fputcsv( $log_handle, array( $document_id, $title, 'update_error', $updated->get_error_message(), $before, $extracted_length, $pdf_path ) );
                    continue;
                }

                update_post_meta( $document_id, '_jb_library_content_backfilled_at', current_time( 'mysql', true ) );
                update_post_meta( $document_id, '_jb_library_content_backfilled_from', $pdf_path );
                update_post_meta( $document_id, '_jb_library_content_backfill_length', $extracted_length );

                $counts['updated']++;
                fputcsv( $log_handle, array( $document_id, $title, 'updated', 'Post content backfilled from PDF text.', $before, $extracted_length, $pdf_path ) );
            } else {
                $counts['would_update']++;
                fputcsv( $log_handle, array( $document_id, $title, 'would_update', 'Dry run: post content would be backfilled from PDF text.', $before, $extracted_length, $pdf_path ) );
            }
        }

        fclose( $log_handle );

        WP_CLI::success(
            sprintf(
                'Backfill complete. Updated: %d. Would update: %d. Skipped unusable: %d. Missing files: %d. Errors: %d. Log: %s',
                $counts['updated'],
                $counts['would_update'],
                $counts['skipped_unusable'],
                $counts['skipped_missing'],
                $counts['parse_error'],
                $log_path
            )
        );
    }

    WP_CLI::add_command( 'dlp-backfill-document-content', 'jb_library_backfill_dlp_document_content' );
}
