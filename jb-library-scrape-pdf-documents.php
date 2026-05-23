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
         * Normalize whitespace for transport extraction values.
         *
         * @param string $text
         * @return string
         */
        private function normalize_whitespace( string $text ): string {
            return trim( preg_replace( '/\s+/', ' ', $text ) );
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
         * Extract a value from the cleaned text using an ordered list of labels.
         *
         * @param array $labels
         * @param int   $max_length
         * @return string
         */
        private function extract_label_value( array $labels, int $max_length = 160 ): string {
            $this->ensure_clean_text();
            $text = $this->cleaned_text;

            foreach ( $labels as $label ) {
                $pattern = '/\b' . preg_quote( $label, '/' ) . '\b\s*[:\-]?\s*(.+?)(?=\r?\n|$)/is';
                if ( preg_match( $pattern, $text, $matches ) ) {
                    return $this->normalize_whitespace( substr( $matches[1], 0, $max_length ) );
                }
            }

            return '';
        }

        /**
         * Extract the first regex capture group from the cleaned text.
         *
         * @param string $pattern
         * @return string
         */
        private function extract_regex_value( string $pattern ): string {
            $this->ensure_clean_text();

            if ( preg_match( $pattern, $this->cleaned_text, $matches ) ) {
                return trim( $matches[1] );
            }

            return '';
        }

        /**
         * Get the UN number for this SDS.
         *
         * @return string
         */
        public function get_un_code(): string {
            $un_number = $this->extract_regex_value( '/\bUN[ \-]*([0-9]{4})\b/i' );
            return $un_number ? 'UN' . $un_number : '';
        }

        /**
         * Get the hazardous description / proper shipping name for this SDS.
         *
         * @return string
         */
        public function get_hazardous_description(): string {
            return $this->extract_label_value(
                array(
                    'proper shipping name',
                    'hazardous description',
                    'hazard description',
                    'description of hazard',
                    'shipping name',
                    'technical name',
                ),
                200
            );
        }

        /**
         * Get the hazardous class number from this SDS.
         *
         * @return string
         */
        public function get_hazardous_class_number(): string {
            return $this->extract_regex_value(
                '/\b(?:hazard\s*(?:class|group)|hazardous\s*group|hazardous\s*class)\b[^0-9A-Za-z]*(\d+(?:\.\d+)?)/i'
            );
        }

        /**
         * Get the packing group for this SDS.
         *
         * @return string
         */
        public function get_packing_group(): string {
            return strtoupper( $this->extract_regex_value(
                '/\b(?:packing\s*group|pg\b)\b[^A-Z0-9]*(I{1,3}|II|III|1|2|3)\b/i'
            ) );
        }

        /**
         * Get the shipping class for this SDS.
         *
         * @return string
         */
        public function get_shipping_class(): string {
            return strtoupper( $this->extract_regex_value(
                '/\b(?:shipping\s*group|shipping\s*class|ship\s*group|ship\s*class|shp\s*group|shp\s*class)\b[^A-Z0-9]*(I{1,3}|II|III|1|2|3)\b/i'
            ) );
        }

        /**
         * Get the NMFC code for this SDS.
         *
         * @return string
         */
        public function get_nmfc_code(): string {
            return $this->extract_regex_value('/\bNMFC\b[^0-9]*(\d{4,6})\b/i');
        }

        /**
         * Get the transport section text for this SDS.
         *
         * @param int $max_length
         * @return string
         */
        public function get_transport_section( int $max_length = 1200 ): string {
            $this->ensure_clean_text();
            $text = $this->cleaned_text;

            $start_labels = array(
                'transport information',
                'transport details',
                'transportation information',
                'transportation details',
                'transportation',
                'transport',
            );

            $start_position = false;
            foreach ( $start_labels as $label ) {
                $pattern = '/\b' . preg_quote( $label, '/' ) . '\b/i';
                if ( preg_match( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
                    $start_position = $matches[0][1];
                    break;
                }
            }

            if ( false === $start_position ) {
                return '';
            }

            $end_pattern = '/\n\s*(?:section\s*\d+\b|regulatory information|regulatory|exposure controls|handling|storage|stability|ecological|disposal|hazard|identification|composition|first aid|fire fighting|accidental release|physical and chemical properties|other information|other hazards)\b/i';
            $end_position = false;

            if ( preg_match( $end_pattern, $text, $end_matches, PREG_OFFSET_CAPTURE, $start_position + 1 ) ) {
                $end_position = $end_matches[0][1];
            }

            $section_text = $end_position !== false
                ? substr( $text, $start_position, min( $max_length, $end_position - $start_position ) )
                : substr( $text, $start_position, $max_length );

            return $this->normalize_whitespace( $section_text );
        }

        /**
         * Return the transport-related SDS details.
         *
         * @return array
         */
        public function get_sds_transport_details(): array {
            if ( false === $this->is_pdf_readable ) {
                return array(
                    'un_code'                  => '',
                    'hazardous_description'    => '',
                    'hazardous_class_number'   => '',
                    'packing_group'            => '',
                    'shipping_class'           => '',
                    'nmfc_code'                => '',
                    'transport_section'        => '',
                );
            }

            return array(
                'un_code'                => $this->get_un_code(),
                'hazardous_description'  => $this->get_hazardous_description(),
                'hazardous_class_number' => $this->get_hazardous_class_number(),
                'packing_group'          => $this->get_packing_group(),
                'shipping_class'         => $this->get_shipping_class(),
                'nmfc_code'              => $this->get_nmfc_code(),
                'transport_section'      => $this->get_transport_section(),
            );
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