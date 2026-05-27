<?php
/**
 * When dealing with thousands of files, we need a way to scrape the PDF text programmatically
 *
 * The goal here is to create a CLI Command that will pull the file name
 */

use Smalot\PdfParser\Parser;

if ( ! defined( 'JB_LIBRARY_ENABLE_PDF_TEXT_FALLBACK' ) ) {
    define( 'JB_LIBRARY_ENABLE_PDF_TEXT_FALLBACK', false );
}

$local_fallback_parser = __DIR__ . '/jb-library-pdf-fallback-parser.local.php';
if ( defined( 'WP_CLI' ) && WP_CLI && JB_LIBRARY_ENABLE_PDF_TEXT_FALLBACK && file_exists( $local_fallback_parser ) ) {
    require_once $local_fallback_parser;
}

if ( ! class_exists( 'JB_PDF_Scraper' ) ) {
    class JB_PDF_Scraper {

        /**
         * The file to be processed
         * @var string
         */
        public string $file_path = '';

        /**
         * The extracted text content from the PDF
         * @var string
         */
        public string $parsed_text = '';

        /**
         * The cleaned text content from the PDF
         * @var string
         */
        public string $cleaned_text = '';

        /**
         * Indicates if the text is readable
         * @var bool
         */
        public bool $is_pdf_readable = false;

        /**
         * Constructor to initialize the file path
         * @param string $file_path The path to the PDF file
         */
        public function __construct( string $file_path = '' ) {
            $this->file_path = $file_path;
            $this->load_parsed_text_from_cache();

            // This initial check will set up the remaining properties.
            $this->check_if_pdf_text_is_readable();
        }

        /**
         * Try to load parsed text from a companion JSON file produced by the external scraper.
         *
         * @return void
         */
        private function load_parsed_text_from_cache(): void {
            if ( empty( $this->file_path ) ) {
                return;
            }

            $cache_file = $this->file_path . '.parsed.json';
            if ( ! file_exists( $cache_file ) ) {
                return;
            }

            $contents = file_get_contents( $cache_file );
            if ( false === $contents ) {
                return;
            }

            $data = json_decode( $contents, true );
            if ( is_array( $data ) && isset( $data['text'] ) && null !== $data['text'] ) {
                $this->parsed_text = (string) $data['text'];
            }
        }

        /**
         * Scrape PDF metadata/details.
         *
         * @return array|null Extracted details or null on failure.
         */
        public function scrape_pdf_details() {
            if ( ! file_exists( $this->file_path ) ) {
                return null;
            }

            $parser = new Parser();
            try {
                $pdf = $parser->parseFile( $this->file_path );
                return $pdf->getDetails();
            } catch ( Exception $e ) {
                return null;
            }
        }

        /**
         * Scrape text content from a PDF file.
         *
         * @return string|null Extracted text or null on failure.
         */
        public function scrape_pdf_text() {
            if ( ! file_exists( $this->file_path ) ) {
                return null;
            }

            $parser = new Parser();
            try {
                $pdf = $parser->parseFile( $this->file_path );
                $text = method_exists( $pdf, 'getText' ) ? $pdf->getText() : (string) $pdf;
                if ( is_string( $text ) && trim( $text ) !== '' ) {
                    $this->parsed_text = $text;
                    return $this->parsed_text;
                }
            } catch ( Exception $e ) {
                // Fall through to the optional fallback below.
            }

            $fallback_text = $this->run_pdf_text_fallback();
            if ( is_string( $fallback_text ) && trim( $fallback_text ) !== '' ) {
                $this->parsed_text = $fallback_text;
                return $this->parsed_text;
            }

            return null;
        }

        /**
         * Run an optional fallback parser for the current PDF.
         *
         * @return string|null Extracted text or null on failure.
         */
        private function run_pdf_text_fallback(): ?string {
            if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
                return null;
            }

            if ( ! JB_LIBRARY_ENABLE_PDF_TEXT_FALLBACK ) {
                return null;
            }

            if ( ! function_exists( 'apply_filters' ) ) {
                return null;
            }

            $fallback_text = apply_filters( 'jb_library_pdf_scraper_fallback_text', null, $this->file_path, $this );
            return is_string( $fallback_text ) ? $fallback_text : null;
        }

        /**
         * Clean up text by removing unwanted characters.
         *
         * @return string The cleaned text.
         */
        public function clean_text(): string {
            if ( empty( $this->parsed_text ) ) {
                $this->scrape_pdf_text();
            }

            $text = $this->parsed_text;

            if ( empty( $text ) ) {
                return '';
            }

            // We aren't sure which type of characters may be causing the issue, so try multiple patterns.
            $cleaned_text = preg_replace( '/[^\x0A\x20-\x7E]/', ' ', $text );

            if ( empty( $cleaned_text ) ) {
                $cleaned_text = preg_replace( '/[\x00-\x1F\x7F]/', ' ', $text );
            }

            if ( empty( $cleaned_text ) ) {
                $cleaned_text = preg_replace( '/[\x00-\x1F\x7F-\xFF]/', ' ', $text );
            }

            $this->cleaned_text = $cleaned_text;

            return $this->cleaned_text;
        }

        /**
         * Find the position of a substring in the cleaned text.
         *
         * @param string $substring The substring to search for.
         * @return int The position of the substring or -1 if not found.
         */
        public function find_substring_position( string $substring ): int {
            if ( empty( $this->cleaned_text ) ) {
                $this->clean_text();
            }

            $lower_text = strtolower( $this->cleaned_text );
            $lower_substring = strtolower( $substring );
            $position = strpos( $lower_text, $lower_substring );
            return ( false !== $position ) ? $position : -1;
        }

        /**
         * Ensure the cleaned text is available for extraction.
         *
         * @return void
         */
        private function ensure_clean_text(): void {
            if ( empty( $this->cleaned_text ) ) {
                $this->clean_text();
            }
        }

        /**
         * Check that the text is readable.
         *
         * @return bool True if readable, false otherwise.
         */
        public function check_if_pdf_text_is_readable(): bool {
            if ( empty( $this->cleaned_text ) ) {
                $this->clean_text();
            }

            if ( empty( $this->cleaned_text ) ) {
                $this->is_pdf_readable = false;
                return $this->is_pdf_readable;
            }

            $common_words = array( 'the', 'be', 'to', 'of', 'and' );
            $found_words = 0;
            foreach ( $common_words as $word ) {
                if ( $this->find_substring_position( $word ) !== -1 ) {
                    $found_words++;
                }
            }

            $this->is_pdf_readable = ( $found_words >= 2 );
            return $this->is_pdf_readable;
        }
    }
}
