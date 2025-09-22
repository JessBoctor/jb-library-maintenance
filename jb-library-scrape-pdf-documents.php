<?php
/**
 * When dealing with thousands of files, we need a way to scrape the PDF text programmatically
 * 
 * The goal here is to create a CLI Command that will pull the file name 
 */

use Smalot\PdfParser\Parser;

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
            // This initial check will up the remaining properties
            $this->check_if_pdf_text_is_readable();
        }

        /**
         * Scrape text content from a PDF file.
         *
         * @return array|null Extracted text or null on failure.
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
                // Handle error or log it
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
                $this->parsed_text = $pdf->getText();
                return $this->parsed_text;
            } catch ( Exception $e ) {
                // Handle error or log it
                return null;
            }
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

            // We aren't sure which type of characters may be causing the issue
            // So we will try multiple preg_replace based on unicode types
            // UTF-8
            $cleaned_text = preg_replace( '/[^\x0A\x20-\x7E]/',' ', $text );

            // 8 bit extended ASCII
            if ( empty( $cleaned_text ) ) {
                $cleaned_text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);
            }

            // 7 bit ASCII
            if ( empty( $cleaned_text ) ) {
                $cleaned_text = preg_replace('/[\x00-\x1F\x7F-\xFF]/', ' ', $text );
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
            return ( $position !== false ) ? $position : -1;
        }

        /**
         * Check that the text is readable
         * @return bool True if readable, false otherwise
         */
        public function check_if_pdf_text_is_readable(): bool {
            //The first time we check, the method may not have been run
            if ( empty( $this->cleaned_text ) ) {
                $this->clean_text();
            }

            //The second time we check, if the cleaned text is still empty, we know it's not readable
            if ( empty( $this->cleaned_text ) ) {
                $this->is_pdf_readable = false;
                return $this->is_pdf_readable;
            }

            // Use the most common words in the English language as a heuristic
            $common_words = array( 'the', 'be', 'to', 'of', 'and' );
            $found_words = 0;
            foreach ( $common_words as $word ) {
                if ( $this->find_substring_position( $word ) !== -1 ) {
                    $found_words++;
                }
            }

            // If we found at least 2 common words, we consider the text readable
            $this->is_pdf_readable = ( $found_words >= 2 ) ? true : false;
            return $this->is_pdf_readable;
        }
    }
}