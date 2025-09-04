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
    }
}