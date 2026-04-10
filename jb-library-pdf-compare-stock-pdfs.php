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
         * [--export-csv]
         * : Export results to CSV files in the plugin directory.
         *
         * ## EXAMPLES
         *
         *     wp compare-stock-pdfs
         *     wp compare-stock-pdfs --csv-path=wp-content/plugins/jb-library-maintenance/2025ProductData.csv --sds-dir=wp-content/uploads/SDS --tds-dir=wp-content/uploads/TDS
         *     wp compare-stock-pdfs --export-csv
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
            $sds_dir = $assoc_args['sds-dir'] ?? trailingslashit( wp_get_upload_dir()['basedir'] ) . 'SDS';
            $tds_dir = $assoc_args['tds-dir'] ?? trailingslashit( wp_get_upload_dir()['basedir'] ) . 'TDS';
            $export_csv = $assoc_args['export-csv'] ?? false;

            // Only resolve relative paths
            if ( ! isset( $assoc_args['csv-path'] ) ) {
                $csv_path = wp_normalize_path( $csv_path );
            } else {
                $csv_path = $this->resolve_path( $csv_path );
            }
            if ( ! isset( $assoc_args['sds-dir'] ) ) {
                $sds_dir = wp_normalize_path( $sds_dir );
            } else {
                $sds_dir = $this->resolve_path( $sds_dir );
            }
            if ( ! isset( $assoc_args['tds-dir'] ) ) {
                $tds_dir = wp_normalize_path( $tds_dir );
            } else {
                $tds_dir = $this->resolve_path( $tds_dir );
            }

            $csv_stock_ids = $this->read_csv_stock_ids( $csv_path, $column_name );
            $sds_stock_ids = $this->scan_pdf_stock_ids( $sds_dir );
            $tds_stock_ids = $this->scan_pdf_stock_ids( $tds_dir );

            $all_pdf_stock_ids = array_unique( array_merge( array_keys( $sds_stock_ids ), array_keys( $tds_stock_ids ) ) );
            sort( $csv_stock_ids, SORT_NATURAL | SORT_FLAG_CASE );
            sort( $all_pdf_stock_ids, SORT_NATURAL | SORT_FLAG_CASE );

            $missing_pdfs = array_values( array_diff( $csv_stock_ids, $all_pdf_stock_ids ) );
            $orphan_pdfs = array_values( array_diff( $all_pdf_stock_ids, $csv_stock_ids ) );

            // Perform fuzzy matching by stripping characters from missing stock IDs
            $fuzzy_matches = $this->find_fuzzy_matches( $missing_pdfs, $all_pdf_stock_ids );

            // Calculate match statistics
            $exact_matched_stock_ids = array_diff( $csv_stock_ids, $missing_pdfs );
            $fuzzy_matched_stock_ids = array_keys( $fuzzy_matches );
            $total_matched_stock_ids = array_unique( array_merge( $exact_matched_stock_ids, $fuzzy_matched_stock_ids ) );

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

            $total_non_matches = count( $missing_pdfs ) + count( $orphan_pdfs );

            WP_CLI::line( "CSV stock IDs: " . count( $csv_stock_ids ) );
            WP_CLI::line( "Total PDF files (SDS + TDS): " . $total_pdf_files );
            WP_CLI::line( "" );
            WP_CLI::line( "FILE MATCH SUMMARY:" );
            WP_CLI::line( "  PDF files with matching stock IDs: " . $matched_file_count );
            WP_CLI::line( "  PDF files without matching stock IDs: " . $unmatched_file_count );
            WP_CLI::line( "  Total PDF files: " . $total_pdf_files );
            WP_CLI::line( "" );
            WP_CLI::line( "STOCK ID SUMMARY:" );
            WP_CLI::line( "  Exact matched stock IDs: " . count( $exact_matched_stock_ids ) );
            if ( ! empty( $fuzzy_matches ) ) {
                WP_CLI::line( "  Fuzzy matched stock IDs: " . count( $fuzzy_matched_stock_ids ) );
            }
            WP_CLI::line( "  Total matched stock IDs: " . count( $total_matched_stock_ids ) );
            WP_CLI::line( "" );
            WP_CLI::line( "NON-MATCHES:" );
            WP_CLI::line( "  Missing PDFs for CSV stock IDs: " . count( $missing_pdfs ) );
            WP_CLI::line( "  PDF stock IDs not found in CSV: " . count( $orphan_pdfs ) );
            WP_CLI::line( "  Total unmatched items: " . $total_non_matches );
            WP_CLI::line( str_repeat( '-', 60 ) );

            if ( ! empty( $missing_pdfs ) ) {
                WP_CLI::line( "Stock IDs in CSV with no matching PDF:" );
                foreach ( $missing_pdfs as $stock_id ) {
                    WP_CLI::line( "  - {$stock_id}" );
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
            WP_CLI::line( "FINAL SUMMARY:" );
            WP_CLI::line( "  Total PDF files: {$total_pdf_files}" );
            WP_CLI::line( "  Files with matching stock IDs: {$matched_file_count} (" . round( $matched_file_count / $total_pdf_files * 100, 1 ) . "%)" );
            WP_CLI::line( "  Files without matching stock IDs: {$unmatched_file_count} (" . round( $unmatched_file_count / $total_pdf_files * 100, 1 ) . "%)" );
            WP_CLI::line( str_repeat( '-', 60 ) );

            if ( $export_csv ) {
                $this->export_results_to_csv( $missing_pdfs, $orphan_pdfs, $sds_stock_ids, $tds_stock_ids, $fuzzy_matches );
            }

            WP_CLI::success( 'Stock comparison complete.' );
        }

        private function resolve_path( string $path ): string {
            $path = trim( $path );
            if ( $path === '' ) {
                return $path;
            }
            if ( preg_match( '#^(?:[A-Za-z]:\\|/)#', $path ) ) {
                return wp_normalize_path( $path );
            }
            return wp_normalize_path( ABSPATH . ltrim( str_replace( '\\', '/', $path ), '/' ) );
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
            return array_keys( $stock_ids );
        }

        private function extract_stock_id_from_pdf( string $file_name ): string {
            $base_name = pathinfo( $file_name, PATHINFO_FILENAME );
            $base_name = preg_replace( '/(?:[-_\s](?:SDS|TDS))(?:[-_\s].*)?$/i', '', $base_name );
            return $this->normalize_stock_id( $base_name );
        }

        private function scan_pdf_stock_ids( string $directory ): array {
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

        private function export_results_to_csv( array $missing_pdfs, array $orphan_pdfs, array $sds_stock_ids, array $tds_stock_ids, array $fuzzy_matches = array() ): void {
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
                    fputcsv( $missing_handle, array( 'Stock ID' ), ',', '"', '\\' );
                    foreach ( $missing_pdfs as $stock_id ) {
                        fputcsv( $missing_handle, array( $stock_id ), ',', '"', '\\' );
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

    WP_CLI::add_command( 'compare-stock-pdfs', 'JB_Compare_Stock_PDFs_Command' );
}
