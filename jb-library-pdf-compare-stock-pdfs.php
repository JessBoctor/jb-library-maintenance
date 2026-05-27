<?php
/**
 * Compare CSV stock IDs against SDS/TDS PDF filenames using WP-CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

if ( ! class_exists( 'JB_Compare_Stock_PDFs_Command' ) ) {
    class JB_Compare_Stock_PDFs_Command {

        private const PLUGIN_BASENAME = 'jb-library-maintenance/jb-library-maintenance.php';

        /**
         * Compare stock IDs in a CSV to PDF filenames in SDS and TDS directories.
         *
         * ## OPTIONS
         *
         * [--csv-path=<path>]
         * : Path to the CSV file. Defaults to the plugin CSV file.
         *
         * [--sds-dir=<path>]
         * : Path to the SDS directory. Defaults to the site upload SDS folder.
         *
         * [--tds-dir=<path>]
         * : Path to the TDS directory. Defaults to the site upload TDS folder.
         *
         * [--column=<name>]
         * : CSV header column name containing stock IDs. Defaults to "Row Labels".
         *
         * [--description-column=<name>]
         * : CSV header column name containing product descriptions. Defaults to none.
         *
         * [--product-site-id=<id>]
         * : Site ID for the product source site when the CSV file is stored or referenced from another network site.
         *
         * [--document-site-id=<id>]
         * : Site ID for the document library site when PDFs are managed on a different network site.
         *
         * [--shared-pdf-dir=<path>]
         * : Single shared directory containing PDF resources for both SDS and TDS files.
         *
         * [--match-parent-stock-id]
         * : After exact child-ID matching, fall back to a unique parent prefix match against PDF filenames.
         *   The parent is the common prefix shared by child variants (e.g. 123456 from 123456-aa).
         *
         * [--report-ungrouped-unmatched]
         * : Compare PDF matching against stock grouping and report stock IDs that are both ungrouped and unmatched.
         *
         * [--group-threshold=<value>]
         * : Similarity threshold for grouping when using --report-ungrouped-unmatched. Defaults to 0.86.
         *
         * [--export-csv]
         * : Export results to CSV files in the plugin directory.
         *
         * [--export-stock-summary-csv]
         * : Export stock IDs with parent groups and matched PDFs to CSV.
         *
         * ## EXAMPLES
         *
         *     wp compare-stock-pdfs
         *     wp compare-stock-pdfs --match-parent-stock-id
         *     wp compare-stock-pdfs --csv-path=wp-content/plugins/jb-library-maintenance/2025ProductData.csv --sds-dir=wp-content/uploads/SDS --tds-dir=wp-content/uploads/TDS
         *     wp compare-stock-pdfs --match-parent-stock-id --export-csv
         *     wp compare-stock-pdfs --match-parent-stock-id --export-stock-summary-csv
         *     wp compare-stock-pdfs --product-site-id=2 --document-site-id=3 --shared-pdf-dir=wp-content/uploads/shared-docs --match-parent-stock-id --export-stock-summary-csv
         *
         * @param array $args
         * @param array $assoc_args
         * @return void
         */
        public function __invoke( $args, $assoc_args ) {
            if ( ! $this->is_plugin_active() ) {
                WP_CLI::error( sprintf( 'The plugin %s is not active. Activate it and try again.', self::PLUGIN_BASENAME ) );
            }

            $csv_path = $assoc_args['csv-path'] ?? JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . '2025ProductData.csv';
            $column_name = $assoc_args['column'] ?? 'Row Labels';
            $description_column = $assoc_args['description-column'] ?? '';
            $sds_dir = $assoc_args['sds-dir'] ?? '';
            $tds_dir = $assoc_args['tds-dir'] ?? '';
            $shared_pdf_dir = $assoc_args['shared-pdf-dir'] ?? '';
            $product_site_id = isset( $assoc_args['product-site-id'] ) ? intval( $assoc_args['product-site-id'] ) : 0;
            $document_site_id = isset( $assoc_args['document-site-id'] ) ? intval( $assoc_args['document-site-id'] ) : 0;
            $match_parent_stock_id = isset( $assoc_args['match-parent-stock-id'] );
            $report_ungrouped_unmatched = isset( $assoc_args['report-ungrouped-unmatched'] );
            $group_threshold = isset( $assoc_args['group-threshold'] ) ? floatval( $assoc_args['group-threshold'] ) : 0.86;
            $export_csv = $assoc_args['export-csv'] ?? false;
            $export_stock_summary_csv = isset( $assoc_args['export-stock-summary-csv'] );

            if ( $report_ungrouped_unmatched && ( $group_threshold < 0 || $group_threshold > 1 ) ) {
                WP_CLI::error( 'Group threshold must be between 0.0 and 1.0.' );
            }

            if ( $product_site_id > 0 && ! is_multisite() ) {
                WP_CLI::error( 'product-site-id requires WordPress multisite.' );
            }
            if ( $document_site_id > 0 && ! is_multisite() ) {
                WP_CLI::error( 'document-site-id requires WordPress multisite.' );
            }
            if ( $product_site_id > 0 && ! get_blog_details( $product_site_id ) ) {
                WP_CLI::error( 'Invalid product site ID: ' . $product_site_id );
            }
            if ( $document_site_id > 0 && ! get_blog_details( $document_site_id ) ) {
                WP_CLI::error( 'Invalid document site ID: ' . $document_site_id );
            }

            if ( $shared_pdf_dir ) {
                if ( ! isset( $assoc_args['sds-dir'] ) ) {
                    $sds_dir = $shared_pdf_dir;
                }
                if ( ! isset( $assoc_args['tds-dir'] ) ) {
                    $tds_dir = $shared_pdf_dir;
                }
            }

            if ( $document_site_id > 0 ) {
                if ( $sds_dir === '' ) {
                    $sds_dir = trailingslashit( $this->get_blog_upload_basedir( $document_site_id ) ) . 'SDS';
                }
                if ( $tds_dir === '' ) {
                    $tds_dir = trailingslashit( $this->get_blog_upload_basedir( $document_site_id ) ) . 'TDS';
                }
            }

            if ( $sds_dir === '' ) {
                $sds_dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'SDS';
            }
            if ( $tds_dir === '' ) {
                $tds_dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'TDS';
            }

            // Only resolve relative paths
            if ( ! isset( $assoc_args['csv-path'] ) ) {
                $csv_path = wp_normalize_path( $csv_path );
            } else {
                $csv_path = $this->resolve_path( $csv_path, $product_site_id );
            }

            if ( ! isset( $assoc_args['sds-dir'] ) ) {
                $sds_dir = wp_normalize_path( $sds_dir );
            } else {
                $sds_dir = $this->resolve_path( $sds_dir, $document_site_id );
            }

            if ( ! isset( $assoc_args['tds-dir'] ) ) {
                $tds_dir = wp_normalize_path( $tds_dir );
            } else {
                $tds_dir = $this->resolve_path( $tds_dir, $document_site_id );
            }

            $csv_stock_data = $this->read_csv_stock_ids( $csv_path, $column_name, $description_column );
            $csv_stock_ids = array_keys( $csv_stock_data );

            if ( $sds_dir !== '' && $sds_dir === $tds_dir ) {
                $sds_stock_ids = $this->scan_pdf_stock_ids( $sds_dir );
                $tds_stock_ids = array();
            } else {
                $sds_stock_ids = $this->scan_pdf_stock_ids( $sds_dir );
                $tds_stock_ids = $this->scan_pdf_stock_ids( $tds_dir );
            }

            $all_pdf_stock_ids = array_unique( array_merge( array_keys( $sds_stock_ids ), array_keys( $tds_stock_ids ) ) );
            sort( $csv_stock_ids, SORT_NATURAL | SORT_FLAG_CASE );
            sort( $all_pdf_stock_ids, SORT_NATURAL | SORT_FLAG_CASE );

            $missing_stock_ids = array_values( array_diff( $csv_stock_ids, $all_pdf_stock_ids ) );
            $orphan_pdfs = array_values( array_diff( $all_pdf_stock_ids, $csv_stock_ids ) );

            // Perform matching for missing stock IDs.
            if ( $match_parent_stock_id ) {
                $fuzzy_matches = $this->find_parent_stock_id_matches( $missing_stock_ids, $all_pdf_stock_ids );
            } else {
                $fuzzy_matches = $this->find_fuzzy_matches( $missing_stock_ids, $all_pdf_stock_ids );
            }

            // Calculate stock ID match statistics
            $exact_matched_stock_ids = array_values( array_diff( $csv_stock_ids, $missing_stock_ids ) );
            $fuzzy_matched_stock_ids = array_keys( $fuzzy_matches );
            $total_matched_stock_ids = array_unique( array_merge( $exact_matched_stock_ids, $fuzzy_matched_stock_ids ) );
            $stock_ids_without_pdf = array_values( array_diff( $csv_stock_ids, $total_matched_stock_ids ) );

            // Count total PDF files
            $total_pdf_files = 0;
            foreach ( $sds_stock_ids as $files ) {
                $total_pdf_files += count( $files );
            }
            foreach ( $tds_stock_ids as $files ) {
                $total_pdf_files += count( $files );
            }

            // Count matched file IDs (total PDF files for matched stock IDs)
            $matched_file_count = 0;
            foreach ( $total_matched_stock_ids as $stock_id ) {
                if ( isset( $sds_stock_ids[ $stock_id ] ) ) {
                    $matched_file_count += count( $sds_stock_ids[ $stock_id ] );
                }
                if ( isset( $tds_stock_ids[ $stock_id ] ) ) {
                    $matched_file_count += count( $tds_stock_ids[ $stock_id ] );
                }
            }

            // Count unmatched PDF files (files for orphan stock IDs)
            $unmatched_file_count = 0;
            foreach ( $orphan_pdfs as $stock_id ) {
                if ( isset( $sds_stock_ids[ $stock_id ] ) ) {
                    $unmatched_file_count += count( $sds_stock_ids[ $stock_id ] );
                }
                if ( isset( $tds_stock_ids[ $stock_id ] ) ) {
                    $unmatched_file_count += count( $tds_stock_ids[ $stock_id ] );
                }
            }

            $total_non_matches = count( $missing_stock_ids ) + count( $orphan_pdfs );

            WP_CLI::line( "CSV stock IDs: " . count( $csv_stock_ids ) );
            WP_CLI::line( "" );
            WP_CLI::line( "STOCK ID MATCH SUMMARY:" );
            WP_CLI::line( "  Exact matched stock IDs: " . count( $exact_matched_stock_ids ) );
            if ( ! empty( $fuzzy_matches ) ) {
                WP_CLI::line( "  Parent/fuzzy matched stock IDs: " . count( $fuzzy_matched_stock_ids ) );
            }
            WP_CLI::line( "  Total matched stock IDs: " . count( $total_matched_stock_ids ) );
            WP_CLI::line( "  Stock IDs without PDFs: " . count( $stock_ids_without_pdf ) );
            WP_CLI::line( "" );
            WP_CLI::line( "CATEGORY SUMMARY:" );
            $category_summary = $this->build_category_parent_summary( $csv_stock_ids );
            foreach ( $category_summary as $category => $data ) {
                WP_CLI::line( "  Category {$category}: {$data['count']} IDs" );
                foreach ( $data['parents'] as $parent_id => $child_count ) {
                    WP_CLI::line( "    - Parent ID {$parent_id}: {$child_count} children" );
                }
            }
            WP_CLI::line( "" );
            WP_CLI::line( "PDF MATCH SUMMARY:" );
            WP_CLI::line( "  Total PDF files (SDS + TDS): " . $total_pdf_files );
            WP_CLI::line( "  PDF files with matching stock IDs: " . $matched_file_count );
            WP_CLI::line( "  PDF files without matching stock IDs: " . $unmatched_file_count );
            WP_CLI::line( "" );
            WP_CLI::line( "NON-MATCHES:" );
            WP_CLI::line( "  Stock IDs with no matching PDF: " . count( $stock_ids_without_pdf ) );
            WP_CLI::line( "  PDF stock IDs not present in CSV: " . count( $orphan_pdfs ) );
            WP_CLI::line( "  Total unmatched items: " . ( count( $stock_ids_without_pdf ) + count( $orphan_pdfs ) ) );
            WP_CLI::line( str_repeat( '-', 60 ) );

            if ( ! empty( $stock_ids_without_pdf ) ) {
                WP_CLI::line( "Stock IDs in CSV with no matching PDF:" );
                foreach ( $stock_ids_without_pdf as $stock_id ) {
                    $description = isset( $csv_stock_data[ $stock_id ] ) ? $csv_stock_data[ $stock_id ] : '';
                    $description_text = $description ? " - {$description}" : '';
                    WP_CLI::line( "  - {$stock_id}{$description_text}" );
                }
                WP_CLI::line( str_repeat( '-', 60 ) );
            }

            if ( ! empty( $fuzzy_matches ) ) {
                WP_CLI::line( "POTENTIAL FUZZY MATCHES (by removing suffix characters):" );
                foreach ( $fuzzy_matches as $match ) {
                    WP_CLI::line( "  - {$match['original']} → {$match['matched']} (removed {$match['chars_removed']} char(s))" );
                }
                WP_CLI::line( str_repeat( '-', 60 ) );
            }

            if ( ! empty( $orphan_pdfs ) ) {
                WP_CLI::line( "PDF stock IDs not present in CSV:" );
                foreach ( $orphan_pdfs as $stock_id ) {
                    $files = array();
                    if ( isset( $sds_stock_ids[ $stock_id ] ) ) {
                        $files = array_merge( $files, $sds_stock_ids[ $stock_id ] );
                    }
                    if ( isset( $tds_stock_ids[ $stock_id ] ) ) {
                        $files = array_merge( $files, $tds_stock_ids[ $stock_id ] );
                    }
                    WP_CLI::line( "  - {$stock_id} (" . implode( ', ', $files ) . ")" );
                }
                WP_CLI::line( str_repeat( '-', 60 ) );
            }

            // Final summary
            $total_stock_ids = count( $csv_stock_ids );
            $matched_stock_id_count = count( $total_matched_stock_ids );
            $missing_stock_id_count = count( $stock_ids_without_pdf );
            $matched_percent = $total_stock_ids > 0 ? round( $matched_stock_id_count / $total_stock_ids * 100, 1 ) : 0;
            $missing_percent = $total_stock_ids > 0 ? round( $missing_stock_id_count / $total_stock_ids * 100, 1 ) : 0;

            WP_CLI::line( "FINAL SUMMARY:" );
            WP_CLI::line( "  Total Stock IDs: {$total_stock_ids}" );
            WP_CLI::line( "  Stock IDs with matching PDFs: {$matched_stock_id_count} ({$matched_percent}%)" );
            WP_CLI::line( "  Stock IDs without matching PDFs: {$missing_stock_id_count} ({$missing_percent}%)" );
            WP_CLI::line( "" );
            WP_CLI::line( "FINAL CATEGORY SUMMARY:" );
            WP_CLI::line( "  Total categories: " . count( $category_summary ) );
            $total_parent_groups = 0;
            foreach ( $category_summary as $category_data ) {
                $total_parent_groups += count( $category_data['parents'] );
            }
            WP_CLI::line( "  Total parent groups: {$total_parent_groups}" );
            foreach ( $category_summary as $category => $data ) {
                WP_CLI::line( "  Category {$category}: {$data['count']} IDs, " . count( $data['parents'] ) . " parent groups" );
                foreach ( $data['parents'] as $parent_id => $child_count ) {
                    WP_CLI::line( "    - Parent ID {$parent_id}: {$child_count} children" );
                }
            }
            WP_CLI::line( str_repeat( '-', 60 ) );

            if ( $report_ungrouped_unmatched ) {
                $groups = $this->build_groups( $csv_stock_ids, $group_threshold );
                $grouped_stock_ids = array();
                foreach ( $groups as $group ) {
                    foreach ( $group as $stock_id ) {
                        $grouped_stock_ids[ $stock_id ] = true;
                    }
                }
                $ungrouped_stock_ids = array_values( array_diff( $csv_stock_ids, array_keys( $grouped_stock_ids ) ) );
                $ungrouped_unmatched_stock_ids = array_values( array_intersect( $ungrouped_stock_ids, $stock_ids_without_pdf ) );

                WP_CLI::line( "UNGROUPED / UNMATCHED SUMMARY:" );
                WP_CLI::line( "  Group threshold: {$group_threshold}" );
                WP_CLI::line( "  Stock IDs in any group: " . count( $grouped_stock_ids ) );
                WP_CLI::line( "  Stock IDs not in any group: " . count( $ungrouped_stock_ids ) );
                WP_CLI::line( "  Stock IDs not in a group AND not matched to a PDF: " . count( $ungrouped_unmatched_stock_ids ) );
                if ( ! empty( $ungrouped_unmatched_stock_ids ) ) {
                    WP_CLI::line( "  Example ungrouped/unmatched stock IDs:" );
                    foreach ( array_slice( $ungrouped_unmatched_stock_ids, 0, 20 ) as $stock_id ) {
                        WP_CLI::line( "    - {$stock_id}" );
                    }
                    if ( count( $ungrouped_unmatched_stock_ids ) > 20 ) {
                        WP_CLI::line( "    ...showing top 20" );
                    }
                }
                WP_CLI::line( str_repeat( '-', 60 ) );
            }

            if ( $export_csv ) {
                $this->export_results_to_csv( $missing_stock_ids, $orphan_pdfs, $sds_stock_ids, $tds_stock_ids, $fuzzy_matches, $csv_stock_data );
            }

            if ( $export_stock_summary_csv ) {
                $this->export_stock_summary_to_csv( $csv_stock_ids, $category_summary, $sds_stock_ids, $tds_stock_ids, $csv_stock_data );
            }

            WP_CLI::success( 'Stock comparison complete.' );
        }

        private function resolve_path( string $path, int $blog_id = 0 ): string {
            $path = trim( str_replace( '\\', '/', $path ) );
            if ( $path === '' ) {
                return $path;
            }

            if ( strpos( $path, ABSPATH ) === 0 || strpos( $path, '/' ) === 0 || preg_match( '/^[A-Za-z]:\//', $path ) ) {
                return wp_normalize_path( $path );
            }

            if ( $blog_id > 0 && is_multisite() ) {
                $upload_dir = $this->get_blog_upload_basedir( $blog_id );
                if ( preg_match( '#^(?:wp-content/uploads|uploads)(/.*)?$#i', $path, $matches ) ) {
                    $relative = isset( $matches[1] ) ? ltrim( $matches[1], '/' ) : '';
                    return wp_normalize_path( $upload_dir . ( $relative ? '/' . $relative : '' ) );
                }
            }

            return wp_normalize_path( ABSPATH . ltrim( $path, '/' ) );
        }

        private function get_blog_upload_basedir( int $blog_id ): string {
            if ( $blog_id <= 0 || ! is_multisite() ) {
                return wp_get_upload_dir()['basedir'];
            }

            $blog = get_blog_details( $blog_id );
            if ( ! $blog ) {
                WP_CLI::error( 'Invalid site ID: ' . $blog_id );
            }

            $current_blog_id = get_current_blog_id();
            switch_to_blog( $blog_id );
            $upload_basedir = wp_get_upload_dir()['basedir'];
            restore_current_blog();

            if ( get_current_blog_id() !== $current_blog_id ) {
                switch_to_blog( $current_blog_id );
            }

            return $upload_basedir;
        }

        private function normalize_stock_id( string $value ): string {
            return strtoupper( trim( preg_replace( '/["\'\s]+/', '', $value ) ) );
        }

        private function is_plugin_active(): bool {
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            return is_plugin_active( self::PLUGIN_BASENAME );
        }

        private function read_csv_stock_ids( string $csv_path, string $column_name, string $description_column = '' ): array {
            if ( ! is_readable( $csv_path ) ) {
                WP_CLI::error( "CSV file not readable: {$csv_path}" );
            }

            $handle = fopen( $csv_path, 'rb' );
            if ( $handle === false ) {
                WP_CLI::error( "Unable to open CSV file: {$csv_path}" );
            }

            $header = fgetcsv( $handle, 0, ',', '"', '\\' );
            if ( $header === false ) {
                fclose( $handle );
                WP_CLI::error( "CSV file is empty or invalid: {$csv_path}" );
            }

            $column_index = array_search( $column_name, $header, true );
            if ( $column_index === false ) {
                fclose( $handle );
                WP_CLI::error( "Column '{$column_name}' not found in CSV header. Available columns: " . implode( ', ', $header ) );
            }

            $description_index = null;
            if ( $description_column !== '' ) {
                $description_index = array_search( $description_column, $header, true );
                if ( $description_index === false ) {
                    fclose( $handle );
                    WP_CLI::error( "Description column '{$description_column}' not found in CSV header. Available columns: " . implode( ', ', $header ) );
                }
            }

            $stock_ids = array();
            while ( ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
                if ( ! isset( $row[ $column_index ] ) ) {
                    continue;
                }
                $stock_id = $this->normalize_stock_id( $row[ $column_index ] );
                if ( $stock_id === '' ) {
                    continue;
                }

                $description = '';
                if ( $description_index !== null && isset( $row[ $description_index ] ) ) {
                    $description = trim( $row[ $description_index ] );
                }

                $stock_ids[ $stock_id ] = $description;
            }
            fclose( $handle );
            return $stock_ids;
        }

        private function extract_stock_id_from_pdf( string $file_name ): string {
            $base_name = pathinfo( $file_name, PATHINFO_FILENAME );
            $base_name = preg_replace( '/(?:[-_\s](?:SDS|TDS))(?:[-_\s].*)?$/i', '', $base_name );
            return $this->normalize_stock_id( $base_name );
        }

        private function scan_pdf_stock_ids( string $directory ): array {
            if ( $directory === '' ) {
                return array();
            }
            if ( ! is_dir( $directory ) ) {
                WP_CLI::warning( "Directory does not exist: {$directory}." );
                return array();
            }

            $stock_ids = array();
            $files = scandir( $directory );
            if ( $files === false ) {
                WP_CLI::warning( "Unable to read directory: {$directory}." );
                return array();
            }

            foreach ( $files as $file_name ) {
                if ( $file_name === '.' || $file_name === '..' ) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $file_name;
                if ( ! is_file( $path ) ) {
                    continue;
                }
                if ( ! preg_match( '/\.pdf$/i', $file_name ) ) {
                    continue;
                }
                $stock_id = $this->extract_stock_id_from_pdf( $file_name );
                if ( $stock_id === '' ) {
                    continue;
                }
                $stock_ids[ $stock_id ][] = $file_name;
            }
            return $stock_ids;
        }

        private function find_fuzzy_matches( array $missing_stock_ids, array $pdf_stock_ids ): array {
            $fuzzy_matches = array();
            $pdf_set = array_flip( $pdf_stock_ids ); // For faster lookups

            // Find the longest stock ID to determine max iterations
            $max_length = 0;
            foreach ( $missing_stock_ids as $stock_id ) {
                $max_length = max( $max_length, strlen( $stock_id ) );
            }

            foreach ( $missing_stock_ids as $stock_id ) {
                $stock_length = strlen( $stock_id );
                // Try removing 1 character at a time, up to the full length of this stock ID
                for ( $chars_to_remove = 1; $chars_to_remove < $stock_length; $chars_to_remove++ ) {
                    $truncated_id = substr( $stock_id, 0, -$chars_to_remove );
                    if ( isset( $pdf_set[ $truncated_id ] ) ) {
                        $fuzzy_matches[ $stock_id ] = array(
                            'original' => $stock_id,
                            'matched' => $truncated_id,
                            'chars_removed' => $chars_to_remove
                        );
                        break; // Stop at first match for this stock ID
                    }
                }
            }

            return $fuzzy_matches;
        }

        private function find_parent_stock_id_matches( array $missing_stock_ids, array $pdf_stock_ids ): array {
            $matches = array();

            foreach ( $missing_stock_ids as $stock_id ) {
                $candidates = array();
                foreach ( $pdf_stock_ids as $pdf_id ) {
                    if ( $pdf_id === $stock_id ) {
                        continue;
                    }
                    if ( strpos( $stock_id, $pdf_id ) === 0 ) {
                        $candidates[] = $pdf_id;
                    }
                }

                if ( count( $candidates ) === 1 ) {
                    $matches[ $stock_id ] = array(
                        'original' => $stock_id,
                        'matched' => $candidates[0],
                        'method' => 'parent_prefix'
                    );
                }
            }

            return $matches;
        }

        private function clean_stock_id( string $stock_id ): string {
            return preg_replace( '/[^A-Z0-9]+/', '', $stock_id );
        }

        private function similarity_score( string $a, string $b ): float {
            if ( $a === $b ) {
                return 1.0;
            }

            $a_clean = $this->clean_stock_id( $a );
            $b_clean = $this->clean_stock_id( $b );
            if ( $a_clean === '' || $b_clean === '' ) {
                return 0.0;
            }

            if ( $a_clean === $b_clean ) {
                return 0.98;
            }

            if ( strpos( $a_clean, $b_clean ) === 0 || strpos( $b_clean, $a_clean ) === 0 ) {
                return 0.92;
            }

            $common_prefix = 0;
            $max_prefix = min( strlen( $a_clean ), strlen( $b_clean ) );
            for ( $i = 0; $i < $max_prefix; $i++ ) {
                if ( $a_clean[ $i ] !== $b_clean[ $i ] ) {
                    break;
                }
                $common_prefix++;
            }
            if ( $max_prefix > 0 && ( $common_prefix / $max_prefix ) >= 0.75 && abs( strlen( $a_clean ) - strlen( $b_clean ) ) <= 4 ) {
                return 0.90;
            }

            similar_text( $a, $b, $percent );
            return $percent / 100;
        }

        private function group_by_similarity( array $ids, float $threshold ): array {
            $count = count( $ids );
            $parent = range( 0, $count - 1 );

            $find = function( $index ) use ( &$parent, &$find ) {
                while ( $parent[ $index ] !== $index ) {
                    $parent[ $index ] = $parent[ $parent[ $index ] ];
                    $index = $parent[ $index ];
                }
                return $index;
            };

            $union = function( $first, $second ) use ( &$parent, $find ) {
                $root_first = $find( $first );
                $root_second = $find( $second );
                if ( $root_first !== $root_second ) {
                    $parent[ $root_second ] = $root_first;
                }
            };

            for ( $i = 0; $i < $count; $i++ ) {
                for ( $j = $i + 1; $j < $count; $j++ ) {
                    if ( $this->similarity_score( $ids[ $i ], $ids[ $j ] ) >= $threshold ) {
                        $union( $i, $j );
                    }
                }
            }

            $groups = array();
            for ( $i = 0; $i < $count; $i++ ) {
                $root = $find( $i );
                if ( ! isset( $groups[ $root ] ) ) {
                    $groups[ $root ] = array();
                }
                $groups[ $root ][] = $ids[ $i ];
            }

            $result = array();
            foreach ( $groups as $group ) {
                if ( count( $group ) > 1 ) {
                    sort( $group, SORT_NATURAL | SORT_FLAG_CASE );
                    $result[] = $group;
                }
            }
            return $result;
        }

        private function build_groups( array $stock_ids, float $threshold ): array {
            $category_groups = array();
            foreach ( $stock_ids as $stock_id ) {
                $category = substr( $stock_id, 0, 2 );
                if ( ! isset( $category_groups[ $category ] ) ) {
                    $category_groups[ $category ] = array();
                }
                $category_groups[ $category ][] = $stock_id;
            }

            $all_groups = array();
            foreach ( $category_groups as $ids_in_category ) {
                if ( count( $ids_in_category ) < 2 ) {
                    continue;
                }

                $stripped_ids = array();
                foreach ( $ids_in_category as $id ) {
                    $stripped = substr( $id, 2 );
                    if ( $stripped !== '' ) {
                        $stripped_ids[ $id ] = $stripped;
                    }
                }

                if ( empty( $stripped_ids ) ) {
                    continue;
                }

                $sub_groups = $this->group_by_similarity( array_values( $stripped_ids ), $threshold );
                foreach ( $sub_groups as $sub_group ) {
                    $original_group = array();
                    foreach ( $sub_group as $stripped_id ) {
                        $original_id = array_search( $stripped_id, $stripped_ids );
                        if ( $original_id !== false ) {
                            $original_group[] = $original_id;
                        }
                    }
                    if ( count( $original_group ) > 1 ) {
                        $all_groups[] = $original_group;
                    }
                }
            }

            usort( $all_groups, function ( $a, $b ) {
                if ( count( $a ) === count( $b ) ) {
                    return strcasecmp( $a[0], $b[0] );
                }
                return count( $b ) - count( $a );
            } );

            return $all_groups;
        }

        private function get_category_prefix( string $stock_id ): string {
            return substr( $stock_id, 0, 2 );
        }

        private function get_longest_common_prefix( array $strings ): string {
            if ( empty( $strings ) ) {
                return '';
            }
            if ( count( $strings ) === 1 ) {
                return $strings[0];
            }

            $prefix = $strings[0];
            for ( $i = 1; $i < count( $strings ); $i++ ) {
                $prefix = $this->get_common_prefix( $prefix, $strings[$i] );
                if ( $prefix === '' ) {
                    break;
                }
            }
            return $prefix;
        }

        private function get_common_prefix( string $a, string $b ): string {
            $min_len = min( strlen( $a ), strlen( $b ) );
            for ( $i = 0; $i < $min_len; $i++ ) {
                if ( $a[$i] !== $b[$i] ) {
                    return substr( $a, 0, $i );
                }
            }
            return substr( $a, 0, $min_len );
        }

        private function get_parent_id( string $stock_id ): string {
            if ( strlen( $stock_id ) <= 2 ) {
                return $stock_id;
            }

            $category = $this->get_category_prefix( $stock_id );
            $rest = substr( $stock_id, 2 );
            $rest = preg_replace( '/[^A-Z0-9_\-]/', '', $rest );

            if ( preg_match( '/^([^_\-]+)/', $rest, $matches ) ) {
                $core = $matches[1];
            } else {
                $core = $rest;
            }

            if ( preg_match( '/^(\d+)[A-Z].*$/', $core, $matches ) ) {
                $core = $matches[1];
            }

            return $category . $core;
        }

        private function build_category_parent_summary( array $stock_ids ): array {
            $summary = array();
            $categories = array();

            foreach ( $stock_ids as $stock_id ) {
                $category = $this->get_category_prefix( $stock_id );
                if ( ! isset( $categories[ $category ] ) ) {
                    $categories[ $category ] = array();
                }
                $categories[ $category ][] = $stock_id;
            }

            foreach ( $categories as $category => $ids ) {
                $suffixes = array();
                $prefix_counts = array();

                foreach ( $ids as $stock_id ) {
                    $suffix = substr( $stock_id, 2 );
                    $suffix = preg_replace( '/[^A-Z0-9_\-]/', '', $suffix );
                    $suffixes[ $stock_id ] = $suffix;

                    $length = strlen( $suffix );
                    for ( $prefix_len = max( 4, $length - 1 ); $prefix_len >= 4; $prefix_len-- ) {
                        if ( $prefix_len > $length ) {
                            continue;
                        }
                        $prefix = substr( $suffix, 0, $prefix_len );
                        if ( $prefix === '' ) {
                            continue;
                        }
                        if ( ! isset( $prefix_counts[ $prefix ] ) ) {
                            $prefix_counts[ $prefix ] = 0;
                        }
                        $prefix_counts[ $prefix ]++;
                    }
                }

                foreach ( $ids as $stock_id ) {
                    $suffix = $suffixes[ $stock_id ];
                    $parent_suffix = '';
                    $suffix_length = strlen( $suffix );

                    for ( $prefix_len = $suffix_length - 1; $prefix_len >= 4; $prefix_len-- ) {
                        if ( $prefix_len > $suffix_length ) {
                            continue;
                        }
                        $prefix = substr( $suffix, 0, $prefix_len );
                        if ( isset( $prefix_counts[ $prefix ] ) && $prefix_counts[ $prefix ] > 1 ) {
                            $parent_suffix = $prefix;
                            break;
                        }
                    }

                    $parent_id = $parent_suffix !== '' ? $category . $parent_suffix : $stock_id;

                    if ( ! isset( $summary[ $category ] ) ) {
                        $summary[ $category ] = array(
                            'count' => 0,
                            'parents' => array(),
                        );
                    }

                    $summary[ $category ]['count']++;
                    if ( ! isset( $summary[ $category ]['parents'][ $parent_id ] ) ) {
                        $summary[ $category ]['parents'][ $parent_id ] = 0;
                    }
                    $summary[ $category ]['parents'][ $parent_id ]++;
                }
            }

            uasort( $summary, function ( $a, $b ) {
                return $b['count'] <=> $a['count'];
            } );

            foreach ( $summary as $category => &$category_data ) {
                arsort( $category_data['parents'] );
            }
            unset( $category_data );

            return $summary;
        }

        private function export_results_to_csv( array $missing_pdfs, array $orphan_pdfs, array $sds_stock_ids, array $tds_stock_ids, array $fuzzy_matches = array(), array $csv_stock_data = array() ): void {
            $logs_dir = JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs' . DIRECTORY_SEPARATOR;
            
            // Ensure logs directory exists
            if ( ! is_dir( $logs_dir ) ) {
                wp_mkdir_p( $logs_dir );
            }
            
            $timestamp = gmdate( 'Y-m-d_H-i-s' );

            // Export missing PDFs
            if ( ! empty( $missing_pdfs ) ) {
                $missing_csv_path = $logs_dir . "missing-pdfs-{$timestamp}.csv";
                $missing_handle = fopen( $missing_csv_path, 'w' );
                if ( $missing_handle !== false ) {
                    $headers = array( 'Stock ID' );
                    if ( ! empty( $csv_stock_data ) ) {
                        $headers[] = 'Description';
                    }
                    fputcsv( $missing_handle, $headers, ',', '"', '\\' );
                    foreach ( $missing_pdfs as $stock_id ) {
                        $row = array( $stock_id );
                        if ( ! empty( $csv_stock_data ) ) {
                            $row[] = isset( $csv_stock_data[ $stock_id ] ) ? $csv_stock_data[ $stock_id ] : '';
                        }
                        fputcsv( $missing_handle, $row, ',', '"', '\\' );
                    }
                    fclose( $missing_handle );
                    WP_CLI::line( "Exported missing PDFs to: {$missing_csv_path}" );
                }
            }

            // Export fuzzy matches
            if ( ! empty( $fuzzy_matches ) ) {
                $fuzzy_csv_path = $logs_dir . "fuzzy-matches-{$timestamp}.csv";
                $fuzzy_handle = fopen( $fuzzy_csv_path, 'w' );
                if ( $fuzzy_handle !== false ) {
                    fputcsv( $fuzzy_handle, array( 'Original Stock ID', 'Matched PDF ID', 'Characters Removed' ), ',', '"', '\\' );
                    foreach ( $fuzzy_matches as $match ) {
                        fputcsv( $fuzzy_handle, array( $match['original'], $match['matched'], $match['chars_removed'] ), ',', '"', '\\' );
                    }
                    fclose( $fuzzy_handle );
                    WP_CLI::line( "Exported fuzzy matches to: {$fuzzy_csv_path}" );
                }
            }

            // Export orphan PDFs
            if ( ! empty( $orphan_pdfs ) ) {
                $orphan_csv_path = $logs_dir . "orphan-pdfs-{$timestamp}.csv";
                $orphan_handle = fopen( $orphan_csv_path, 'w' );
                if ( $orphan_handle !== false ) {
                    fputcsv( $orphan_handle, array( 'Stock ID', 'SDS Files', 'TDS Files' ), ',', '"', '\\' );
                    foreach ( $orphan_pdfs as $stock_id ) {
                        $sds_files = isset( $sds_stock_ids[ $stock_id ] ) ? implode( '; ', $sds_stock_ids[ $stock_id ] ) : '';
                        $tds_files = isset( $tds_stock_ids[ $stock_id ] ) ? implode( '; ', $tds_stock_ids[ $stock_id ] ) : '';
                        fputcsv( $orphan_handle, array( $stock_id, $sds_files, $tds_files ), ',', '"', '\\' );
                    }
                    fclose( $orphan_handle );
                    WP_CLI::line( "Exported orphan PDFs to: {$orphan_csv_path}" );
                }
            }
        }

        private function export_stock_summary_to_csv( array $stock_ids, array $category_summary, array $sds_stock_ids, array $tds_stock_ids, array $csv_stock_data = array() ): void {
            $logs_dir = JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs' . DIRECTORY_SEPARATOR;
            
            // Ensure logs directory exists
            if ( ! is_dir( $logs_dir ) ) {
                wp_mkdir_p( $logs_dir );
            }
            
            $timestamp = gmdate( 'Y-m-d_H-i-s' );
            $summary_csv_path = $logs_dir . "stock-summary-{$timestamp}.csv";
            $summary_handle = fopen( $summary_csv_path, 'w' );
            
            if ( $summary_handle === false ) {
                WP_CLI::warning( "Unable to write stock summary CSV to: {$summary_csv_path}" );
                return;
            }

            // Write headers
            $headers = array( 'Stock ID', 'Parent ID', 'Description', 'SDS PDFs', 'TDS PDFs' );
            fputcsv( $summary_handle, $headers, ',', '"', '\\' );

            // Write data for each stock ID
            foreach ( $stock_ids as $stock_id ) {
                $parent_id = $this->get_parent_id( $stock_id );
                $description = isset( $csv_stock_data[ $stock_id ] ) ? $csv_stock_data[ $stock_id ] : '';
                
                $sds_files = isset( $sds_stock_ids[ $stock_id ] ) ? implode( '; ', $sds_stock_ids[ $stock_id ] ) : '';
                $tds_files = isset( $tds_stock_ids[ $stock_id ] ) ? implode( '; ', $tds_stock_ids[ $stock_id ] ) : '';
                
                fputcsv( $summary_handle, array( $stock_id, $parent_id, $description, $sds_files, $tds_files ), ',', '"', '\\' );
            }
            
            fclose( $summary_handle );
            WP_CLI::line( "Exported stock summary to: {$summary_csv_path}" );
        }

        private function display_log_summary(): void {
            $logs_dir = JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'logs' . DIRECTORY_SEPARATOR;
            
            if ( ! is_dir( $logs_dir ) ) {
                WP_CLI::line( "LOGS: No logs directory found." );
                return;
            }

            $log_files = glob( $logs_dir . '*.csv' );
            if ( empty( $log_files ) ) {
                WP_CLI::line( "LOGS: No log files found." );
                return;
            }

            // Get file info for sorting by modification time
            $file_info = array();
            foreach ( $log_files as $file ) {
                $file_info[] = array(
                    'path' => $file,
                    'name' => basename( $file ),
                    'size' => filesize( $file ),
                    'mtime' => filemtime( $file )
                );
            }

            // Sort by modification time (newest first)
            usort( $file_info, function( $a, $b ) {
                return $b['mtime'] - $a['mtime'];
            } );

            $total_files = count( $file_info );
            $total_size = array_sum( array_column( $file_info, 'size' ) );

            WP_CLI::line( str_repeat( '-', 60 ) );
            WP_CLI::line( "LOGS SUMMARY:" );
            WP_CLI::line( "  Total log files: {$total_files}" );
            WP_CLI::line( "  Total size: " . $this->format_bytes( $total_size ) );
            WP_CLI::line( "  Logs directory: {$logs_dir}" );
            
            // Show most recent files
            $recent_count = min( 5, $total_files );
            WP_CLI::line( "  Most recent {$recent_count} files:" );
            
            for ( $i = 0; $i < $recent_count; $i++ ) {
                $file = $file_info[ $i ];
                $age = time() - $file['mtime'];
                $age_str = $this->format_age( $age );
                WP_CLI::line( "    - {$file['name']} ({$this->format_bytes( $file['size'] )}, {$age_str})" );
            }
        }

        private function format_bytes( int $bytes ): string {
            $units = array( 'B', 'KB', 'MB', 'GB' );
            $i = 0;
            while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
                $bytes /= 1024;
                $i++;
            }
            return round( $bytes, 1 ) . ' ' . $units[ $i ];
        }

        private function format_age( int $seconds ): string {
            if ( $seconds < 60 ) {
                return $seconds . 's ago';
            } elseif ( $seconds < 3600 ) {
                return round( $seconds / 60 ) . 'm ago';
            } elseif ( $seconds < 86400 ) {
                return round( $seconds / 3600 ) . 'h ago';
            } else {
                return round( $seconds / 86400 ) . 'd ago';
            }
        }
    }

    class JB_Group_Stock_Variations_Command {

        /**
         * Find similar stock IDs and group them as variations.
         *
         * Groups stock IDs by category (first two characters), then removes the category prefix
         * and groups the remaining parts by similarity to find parent-child variations.
         *
         * ## OPTIONS
         *
         * [--csv-path=<path>]
         * : Path to the CSV file. Defaults to the plugin CSV file.
         *
         * [--column=<name>]
         * : CSV header column name containing stock IDs. Defaults to "Row Labels".
         *
         * [--threshold=<value>]
         * : Similarity threshold between 0.0 and 1.0. Defaults to 0.86.
         *
         * [--output=<path>]
         * : Path to export grouped variations CSV. Defaults to stock-variation-groups.csv.
         *
         * ## EXAMPLES
         *
         *     wp group-stock-variations
         *     wp group-stock-variations --csv-path=wp-content/plugins/jb-library-maintenance/2025ProductData.csv --threshold=0.88 --output=variation-groups.csv
         *
         * @param array $args
         * @param array $assoc_args
         * @return void
         */
        public function __invoke( $args, $assoc_args ) {
            if ( ! function_exists( 'wp_normalize_path' ) ) {
                require_once ABSPATH . 'wp-includes/functions.php';
            }

            $csv_path = $assoc_args['csv-path'] ?? JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . '2025ProductData.csv';
            $column_name = $assoc_args['column'] ?? 'Row Labels';
            $threshold = isset( $assoc_args['threshold'] ) ? floatval( $assoc_args['threshold'] ) : 0.86;
            $output_path = $assoc_args['output'] ?? 'stock-variation-groups.csv';

            if ( $threshold < 0 || $threshold > 1 ) {
                WP_CLI::error( 'Threshold must be between 0.0 and 1.0.' );
            }

            $csv_path = $this->resolve_path( $csv_path );
            $stock_ids = $this->read_csv_stock_ids( $csv_path, $column_name );

            if ( empty( $stock_ids ) ) {
                WP_CLI::warning( 'No stock IDs found in CSV.' );
                return;
            }

            $groups = $this->build_groups( $stock_ids, $threshold );
            $this->display_summary( $stock_ids, $groups, $threshold );
            $this->export_groups_to_csv( $groups, $output_path );

            WP_CLI::success( 'Stock variation grouping complete.' );
        }

        private function read_csv_stock_ids( string $csv_path, string $column_name ): array {
            if ( ! is_readable( $csv_path ) ) {
                WP_CLI::error( "CSV file not readable: {$csv_path}" );
            }

            $handle = fopen( $csv_path, 'rb' );
            if ( $handle === false ) {
                WP_CLI::error( "Unable to open CSV file: {$csv_path}" );
            }

            $header = fgetcsv( $handle, 0, ',', '"', '\\' );
            if ( $header === false ) {
                fclose( $handle );
                WP_CLI::error( "CSV file is empty or invalid: {$csv_path}" );
            }

            $column_index = array_search( $column_name, $header, true );
            if ( $column_index === false ) {
                fclose( $handle );
                WP_CLI::error( "Column '{$column_name}' not found in CSV header. Available columns: " . implode( ', ', $header ) );
            }

            $stock_ids = array();
            while ( ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
                if ( ! isset( $row[ $column_index ] ) ) {
                    continue;
                }
                $stock_id = $this->normalize_stock_id( $row[ $column_index ] );
                if ( $stock_id === '' ) {
                    continue;
                }
                $stock_ids[ $stock_id ] = true;
            }
            fclose( $handle );

            $stock_ids = array_keys( $stock_ids );
            sort( $stock_ids, SORT_NATURAL | SORT_FLAG_CASE );
            return $stock_ids;
        }

        private function normalize_stock_id( string $value ): string {
            $value = strtoupper( trim( preg_replace( '/["\'\s]+/', '', $value ) ) );
            $value = preg_replace( '/[^A-Z0-9\-]+/', '-', $value );
            $value = preg_replace( '/-+/', '-', $value );
            return trim( $value, '-' );
        }

        private function resolve_path( string $path ): string {
            $path = trim( str_replace( '\\', '/', $path ) );
            if ( $path === '' ) {
                return $path;
            }

            if ( strpos( $path, ABSPATH ) === 0 || strpos( $path, '/' ) === 0 || preg_match( '/^[A-Za-z]:\//', $path ) ) {
                return wp_normalize_path( $path );
            }

            return wp_normalize_path( ABSPATH . ltrim( $path, '/' ) );
        }

        private function clean_stock_id( string $stock_id ): string {
            return preg_replace( '/[^A-Z0-9]+/', '', $stock_id );
        }

        private function similarity_score( string $a, string $b ): float {
            if ( $a === $b ) {
                return 1.0;
            }

            $a_clean = $this->clean_stock_id( $a );
            $b_clean = $this->clean_stock_id( $b );
            if ( $a_clean === '' || $b_clean === '' ) {
                return 0.0;
            }

            if ( $a_clean === $b_clean ) {
                return 0.98;
            }

            if ( strpos( $a_clean, $b_clean ) === 0 || strpos( $b_clean, $a_clean ) === 0 ) {
                return 0.92;
            }

            $common_prefix = 0;
            $max_prefix = min( strlen( $a_clean ), strlen( $b_clean ) );
            for ( $i = 0; $i < $max_prefix; $i++ ) {
                if ( $a_clean[ $i ] !== $b_clean[ $i ] ) {
                    break;
                }
                $common_prefix++;
            }
            if ( $max_prefix > 0 && ( $common_prefix / $max_prefix ) >= 0.75 && abs( strlen( $a_clean ) - strlen( $b_clean ) ) <= 4 ) {
                return 0.90;
            }

            similar_text( $a, $b, $percent );
            return $percent / 100;
        }

        private function build_groups( array $stock_ids, float $threshold ): array {
            // First, group by category (first two characters)
            $category_groups = array();
            foreach ( $stock_ids as $stock_id ) {
                $category = substr( $stock_id, 0, 2 );
                if ( ! isset( $category_groups[ $category ] ) ) {
                    $category_groups[ $category ] = array();
                }
                $category_groups[ $category ][] = $stock_id;
            }

            $all_groups = array();
            foreach ( $category_groups as $category => $ids_in_category ) {
                if ( count( $ids_in_category ) < 2 ) {
                    continue; // Skip categories with only one ID
                }

                // Remove category prefix for similarity matching
                $stripped_ids = array();
                foreach ( $ids_in_category as $id ) {
                    $stripped = substr( $id, 2 );
                    if ( $stripped !== '' ) {
                        $stripped_ids[ $id ] = $stripped;
                    }
                }

                if ( empty( $stripped_ids ) ) {
                    continue;
                }

                // Group by similarity on stripped IDs
                $stripped_list = array_values( $stripped_ids );
                $sub_groups = $this->group_by_similarity( $stripped_list, $threshold );

                // Map back to original IDs and add category prefix to group names
                foreach ( $sub_groups as $sub_group ) {
                    $original_group = array();
                    foreach ( $sub_group as $stripped_id ) {
                        $original_id = array_search( $stripped_id, $stripped_ids );
                        if ( $original_id !== false ) {
                            $original_group[] = $original_id;
                        }
                    }
                    if ( count( $original_group ) > 1 ) {
                        $all_groups[] = $original_group;
                    }
                }
            }

            // Sort groups by size and then alphabetically
            usort( $all_groups, function( $a, $b ) {
                if ( count( $a ) === count( $b ) ) {
                    return strcasecmp( $a[0], $b[0] );
                }
                return count( $b ) - count( $a );
            } );

            return $all_groups;
        }

        private function group_by_similarity( array $ids, float $threshold ): array {
            $count = count( $ids );
            $parent = range( 0, $count - 1 );

            $find = function( $index ) use ( &$parent, &$find ) {
                while ( $parent[ $index ] !== $index ) {
                    $parent[ $index ] = $parent[ $parent[ $index ] ];
                    $index = $parent[ $index ];
                }
                return $index;
            };

            $union = function( $first, $second ) use ( &$parent, $find ) {
                $root_first = $find( $first );
                $root_second = $find( $second );
                if ( $root_first !== $root_second ) {
                    $parent[ $root_second ] = $root_first;
                }
            };

            for ( $i = 0; $i < $count; $i++ ) {
                for ( $j = $i + 1; $j < $count; $j++ ) {
                    if ( $this->similarity_score( $ids[ $i ], $ids[ $j ] ) >= $threshold ) {
                        $union( $i, $j );
                    }
                }
            }

            $groups = array();
            for ( $i = 0; $i < $count; $i++ ) {
                $root = $find( $i );
                if ( ! isset( $groups[ $root ] ) ) {
                    $groups[ $root ] = array();
                }
                $groups[ $root ][] = $ids[ $i ];
            }

            $result = array();
            foreach ( $groups as $group ) {
                if ( count( $group ) > 1 ) {
                    sort( $group, SORT_NATURAL | SORT_FLAG_CASE );
                    $result[] = $group;
                }
            }
            return $result;
        }

        private function display_summary( array $stock_ids, array $groups, float $threshold ): void {
            $total = count( $stock_ids );
            $grouped = 0;
            foreach ( $groups as $group ) {
                $grouped += count( $group );
            }

            WP_CLI::line( "CSV stock IDs: {$total}" );
            WP_CLI::line( "Similarity threshold: {$threshold}" );
            WP_CLI::line( "Groups found: " . count( $groups ) );
            WP_CLI::line( "Stock IDs in groups: {$grouped}" );
            WP_CLI::line( "Stock IDs not grouped: " . ( $total - $grouped ) );
            WP_CLI::line( str_repeat( '-', 60 ) );

            foreach ( $groups as $index => $group ) {
                WP_CLI::line( sprintf( 'Group %d (%d IDs): %s', $index + 1, count( $group ), implode( ', ', $group ) ) );
                if ( $index >= 19 ) {
                    WP_CLI::line( '...showing top 20 groups' );
                    break;
                }
            }
        }

        private function export_groups_to_csv( array $groups, string $output_path ): void {
            $output_path = $this->resolve_path( $output_path );
            $directory = dirname( $output_path );
            if ( ! is_dir( $directory ) ) {
                wp_mkdir_p( $directory );
            }

            $handle = fopen( $output_path, 'w' );
            if ( $handle === false ) {
                WP_CLI::warning( "Unable to write groups CSV to: {$output_path}" );
                return;
            }

            fputcsv( $handle, array( 'Group ID', 'Stock IDs' ) );
            foreach ( $groups as $index => $group ) {
                fputcsv( $handle, array( $index + 1, implode( '; ', $group ) ) );
            }
            fclose( $handle );
            WP_CLI::line( "Exported group results to: {$output_path}" );
        }
    }

    WP_CLI::add_command( 'compare-stock-pdfs', 'JB_Compare_Stock_PDFs_Command' );
    WP_CLI::add_command( 'group-stock-variations', 'JB_Group_Stock_Variations_Command' );
}
