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
         * Constructor to initialize the file path
         * @param string $file_path The path to the PDF file
         */
        public function __construct( string $file_path = '' ) {
            $this->file_path = $file_path;
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
            $text = '';
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
            $text = $this->parsed_text;
             // Clean up the text by removing excessive whitespace and newlines
            $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text ); 
            $text = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $text );
            $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text );
            // Optionally, you can add more cleaning rules here
            $this->cleaned_text = $text;

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
            $position = strpos( $this->cleaned_text, $substring );
            return ( $position !== false ) ? $position : -1;
        }
    }
}