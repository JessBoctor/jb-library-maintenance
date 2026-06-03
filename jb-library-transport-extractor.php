<?php
if ( ! class_exists( 'JB_PDF_Transport_Extractor' ) ) {
    /**
     * Extracts Section 14 transportation details from SDS text.
     *
     * The CSV export is built from get_transport_records(), which returns one
     * row per detected regulatory agency when the SDS separates DOT/IATA/IMDG/etc.
     */
    class JB_PDF_Transport_Extractor {
        private string $cleaned_text = '';
        private string $transport_section = '';

        /**
         * Regulatory agency aliases plus the transport modes each agency covers.
         *
         * These aliases are used to split one SDS transportation section into
         * agency-specific CSV rows.
         *
         * @var array<string,array{aliases:array<int,string>,transport_types:array<int,string>,jurisdiction:string}>
         */
        private static $AGENCY_MAP = array(
            'DOT' => array(
                'aliases' => array(
                    'dot',
                    'us dot',
                    'u.s. dot',
                    'd.o.t.',
                    'department of transportation',
                    'department of transport',
                    'department of transport regulations',
                    'u.s. department of transportation',
                    'usa: department of transportation',
                    'usa department of transport regulations',
                    'land',
                    'ground',
                ),
                'transport_types' => array( 'air', 'road', 'rail', 'water' ),
                'jurisdiction' => 'United States',
            ),
            'TDG' => array(
                'aliases' => array(
                    'tdg',
                    'transport canada',
                    'canada tdg',
                    'tmd',
                ),
                'transport_types' => array( 'air', 'road', 'rail', 'water' ),
                'jurisdiction' => 'Canada',
            ),
            'IATA' => array(
                'aliases' => array(
                    'iata',
                    'iata-dgr',
                    'air transport',
                    'air shipment',
                ),
                'transport_types' => array( 'air' ),
                'jurisdiction' => 'International / airline industry',
            ),
            'ICAO' => array(
                'aliases' => array(
                    'icao',
                    'icao/iata',
                    'icao / iata',
                ),
                'transport_types' => array( 'air' ),
                'jurisdiction' => 'International aviation',
            ),
            'IMDG' => array(
                'aliases' => array(
                    'imdg',
                    'imo',
                    'imo/imdg',
                    'imo / imdg',
                    'sea transport',
                    'marine transport',
                    'vessel',
                    'ocean',
                ),
                'transport_types' => array( 'water', 'sea' ),
                'jurisdiction' => 'International maritime',
            ),
            'ADR' => array(
                'aliases' => array(
                    'adr',
                ),
                'transport_types' => array( 'road' ),
                'jurisdiction' => 'Europe / international road',
            ),
            'RID' => array(
                'aliases' => array(
                    'rid',
                ),
                'transport_types' => array( 'rail' ),
                'jurisdiction' => 'Europe / international rail',
            ),
            'ADN' => array(
                'aliases' => array(
                    'adn',
                ),
                'transport_types' => array( 'inland_waterway' ),
                'jurisdiction' => 'Europe / inland waterways',
            ),
            'NOM' => array(
                'aliases' => array(
                    'nom',
                    'mexico',
                    'mexico classification',
                    'norma oficial mexicana',
                ),
                'transport_types' => array( 'road' ),
                'jurisdiction' => 'Mexico',
            ),
        );

	        /**
	         * Single-word terms used as a fallback shipping-name clue.
	         *
	         * These are matched only inside the transportation section. The matcher
	         * keeps the full word that contains the term, so "gas" can return
	         * "gases" and "methylpentane" can be captured from a longer phrase.
	         *
	         * @var array<int,string>
	         */
	        private static $HAZARDOUS_TERMS = array(
	            'acetone',
	            'adhesive',
	            'aerosol',
	            'alcohol',
	            'aliphatic',
	            'alkane',
	            'amine',
	            'amineepoxy',
	            'aromatic',
	            'aviation',
		            'benzene',
		            'bisphenol',
		            'carbon',
		            'combustible',
	            'compressed',
	            'corrosive',
	            'cumyl',
	            'cycloaliphatic',
	            'diamine',
	            'dibenzoyl',
	            'dioxide',
	            'distillate',
	            'epoxide',
	            'epoxy',
	            'ester',
	            'ethanol',
	            'ether',
	            'extract',
	            'flammable',
	            'fluorocarbon',
	            'gas',
		            'heptane',
	            'hexane',
	            'hydrocarbon',
	            'hydroperoxide',
	            'inert',
	            'isopropanol',
	            'ketone',
	            'kerosene',
	            'limonene',
	            'limited',
	            'liquid',
	            'mercaptan',
	            'methacrylate',
	            'methanol',
	            'methylpentane',
	            'mineral',
	            'monomer',
	            'naphtha',
	            'nitrogen',
	            'organic',
	            'paint',
	            'peroxide',
	            'petroleum',
	            'phthalate',
	            'polyamine',
	            'polyester',
	            'polymer',
	            'quantity',
		            'resin',
	            'solid',
	            'solvent',
	            'solution',
	            'stabilized',
	            'styrene',
		            'toluene',
		            'xylene',
	        );

        public function __construct( string $cleaned_text = '' ) {
            $this->cleaned_text = $cleaned_text;
            $this->transport_section = $this->get_transport_section();
        }

        private function normalize_whitespace( string $text ): string {
            return trim( preg_replace( '/\s+/', ' ', $text ) );
        }

        private function extract_regex_value( string $pattern, string $text = '' ): string {
            if ( preg_match( $pattern, $text, $matches ) ) {
                return trim( $matches[1] );
            }

            return '';
        }

        /**
         * Get the transport section from the cleaned text.
         *
         * @param int $length The length of the section to extract.
         * @return string The transport section.
         */
        public function get_transport_section( int $length = 5000 ): string {
            if ( empty( $this->cleaned_text ) ) {
                return '';
            }

            $transport_pos = false;
            $ocr_gap = '[^A-Za-z0-9]{0,6}';
            $transport_information_pattern = '(?:t|1)' . $ocr_gap . 'r' . $ocr_gap . 'a' . $ocr_gap . 'n' . $ocr_gap . 's' . $ocr_gap . 'p' . $ocr_gap . 'o' . $ocr_gap . '(?:r|i)' . $ocr_gap . 'i?' . $ocr_gap . 't' . $ocr_gap . '(?:a' . $ocr_gap . 't' . $ocr_gap . 'i' . $ocr_gap . 'o' . $ocr_gap . 'n' . $ocr_gap . ')?i' . $ocr_gap . 'n' . $ocr_gap . 'f' . $ocr_gap . 'o' . $ocr_gap . 'r' . $ocr_gap . 'm' . $ocr_gap . 'a' . $ocr_gap . 't' . $ocr_gap . 'i' . $ocr_gap . 'o' . $ocr_gap . 'n?' . $ocr_gap . 's?';
            $start_patterns = array(
                '/\b1\s*4\s*[.:\-]?\s*' . $transport_information_pattern . '\b/i',
                '/\b' . $transport_information_pattern . '\s*1\s*4\b/i',
                '/\b1\s*4\s*[.:\-)]?\s*trans(?:port|portation|portion|poration)\b/i',
                '/\bsection\s*1\s*4\b\s*[:.\-]?\s*(?:' . $transport_information_pattern . ')?/i',
                '/\bs\s*e\s*c\s*t\s*(?:i|l|1)\s*o\s*n\s*1\s*4\s*\)?\s*t\s*r\s*a\s*n\s*s\s*p\s*o\s*r\s*t\s*(?:i|l|1)?\s*n\s*f\s*o\s*r\s*m\s*a\s*t\s*(?:i|l|1)\s*o\s*n\b/i',
                '/\bsecci\s*n\s*1\s*4\b\s*[:.\-]?\s*informaci\s*n\s+del\s+transporte\b/i',
                '/\b1\s*4\s*[.:\-]?\s*informaci\s*n\s+del\s+transporte\b/i',
                '/\bsection\s*(?:x|10)\b\s*(?:shipping|transport(?:ation)?)\b/i',
                '/\bagency\s+UN\s+Number\s+Proper\s+Shipping\s+name\s+Hazard\s+Class\s+Packing\s+Group\b/i',
                '/\bUN\s+CLASS\s*:[\s\S]{0,300}?\bUN\s+NUMBER\s*:/i',
                '/\b(?:DOT|TDG|IMDG|IMO\/IMDG|IATA|ICAO|ADR|RID|ADN|NOM|Mexico)\s+(?:UN\/?ID|UN\/?l|UNfl|UN\s+Number|Proper\s+Shipping\s+Name|Hazard\s+Class|Packing\s+group)\b/i',
                '/\bD\.?\s*O\.?\s*T\.?[,.\s]*(?:Proper\s+Shipping\s+Name|Shipping\s+Name)\b/i',
                '/\b(?:DOT|IATA|IMDG|ICAO|IMO\/IMDG)\s*\(?\s*Proper\s+Shipping\s+Name\s*\)?\b/i',
                '/\bUN[-\s]*Number\b\s*(?:\||[-:])?\s*(?:DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\b/i',
                '/\bProper\s+shipping\s+name\s*[:;].{0,250}\b(?:Hazard\s+cl(?:ass|ags)|Identification\s+number|Packing\s+group)\b/i',
                '/\b' . $transport_information_pattern . '\b/i',
            );
            foreach ( $start_patterns as $pattern ) {
                if ( preg_match( $pattern, $this->cleaned_text, $matches, PREG_OFFSET_CAPTURE ) ) {
                    $transport_pos = $matches[0][1] + strlen( $matches[0][0] );
                    break;
                }
            }

            if ( false === $transport_pos ) {
                return '';
            }

            $lookbehind_start = max( 0, $transport_pos - 1500 );
            $lookbehind = substr( $this->cleaned_text, $lookbehind_start, $transport_pos - $lookbehind_start );
            if (
                preg_match( '/\b(?:D\.?\s*O\.?\s*T\.?[,.\s]*(?:Proper\s+Shipping\s+Name|Shipping\s+Name)|(?:DOT|IATA|IMDG|ICAO|IMO\/IMDG)\s*\(?\s*Proper\s+Shipping\s+Name\s*\)?|UN[-\s]*Number|UN\s+proper\s+shipping\s+name|Transport\s+hazard\s+class(?:\(es\))?)\b/i', $lookbehind, $label_matches, PREG_OFFSET_CAPTURE )
            ) {
                $label_pos = $lookbehind_start + $label_matches[0][1];
                $between_label_and_heading = substr( $this->cleaned_text, $label_pos, $transport_pos - $label_pos );
                if ( ! preg_match( '/\b(?:section\s*15|regulatory information)\b/i', $between_label_and_heading ) ) {
                    $transport_pos = $label_pos;
                }
            }

            $end_pos = false;
            if ( preg_match( '/\b1\s*5\s*[.:\-]?\s*r\s*egulatory\s+i\s*n\s*f\s*o\s*r\s*m\s*a\s*t\s*i\s*o\s*n\b|\bsection\s*1\s*5\b|\bsecci\s*n\s*1\s*5\b|\binformaci\s*n\s+reglamentaria\b|\br\s*egulatory\s+i\s*n\s*f\s*o\s*r\s*m\s*a\s*t\s*i\s*o\s*n\b|\bcalifornia\s+prop(?:osition)?\b/i', $this->cleaned_text, $matches, PREG_OFFSET_CAPTURE, $transport_pos + 1 ) ) {
                $end_pos = $matches[0][1];
            }
            if ( false !== $end_pos ) {
                $length = min( $length, $end_pos - $transport_pos );
            }

            return substr( $this->cleaned_text, $transport_pos, $length );
        }

        /**
         * Create a normalized row shape before agency-specific values are filled.
         */
        private function get_empty_transport_record( string $agency = 'GENERIC' ): array {
            $metadata = $this->get_agency_metadata( $agency );

            return array(
                'agency'                 => $agency,
                'agency_alias'           => '',
	                'transport_types'        => implode( ', ', $metadata['transport_types'] ),
	                'jurisdiction'           => $metadata['jurisdiction'],
	                'regulated_material'     => false,
	                'un_code'                => '',
	                'shipping_name'          => '',
	                'hazard_class'           => '',
	                'packing_group'          => '',
	                'shipping_class'         => $this->get_shipping_class(),
	                'hazardous_terms'        => $this->get_hazardous_terms(),
	                'transport_section'      => '',
	            );
	        }

        /**
         * Agency helpers.
         */
        private function get_agency_metadata( string $agency ): array {
            if ( isset( self::$AGENCY_MAP[ $agency ] ) ) {
                return array(
                    'transport_types' => self::$AGENCY_MAP[ $agency ]['transport_types'],
                    'jurisdiction' => self::$AGENCY_MAP[ $agency ]['jurisdiction'],
                );
            }

            return array(
                'transport_types' => array(),
                'jurisdiction' => '',
            );
        }

        private function alias_matches_line( string $alias, string $line ): bool {
            $alias = preg_quote( $alias, '/' );
            return (bool) preg_match( '/(^|[^a-z0-9])' . $alias . '([^a-z0-9]|$)/i', $line );
        }

        private function get_agency_matches_from_line( string $line ): array {
            $matches = array();

            foreach ( self::$AGENCY_MAP as $agency => $metadata ) {
                foreach ( $metadata['aliases'] as $alias ) {
                    if ( $this->alias_matches_line( $alias, $line ) ) {
                        $position = stripos( $line, $alias );
                        $matches[] = array(
                            'agency' => $agency,
                            'agency_alias' => $alias,
                            'agency_alias_position' => false === $position ? 0 : $position,
                            'transport_types' => implode( ', ', $metadata['transport_types'] ),
                            'jurisdiction' => $metadata['jurisdiction'],
                        );
                    }
                }
            }

            usort(
                $matches,
                function ( array $a, array $b ): int {
                    return $a['agency_alias_position'] <=> $b['agency_alias_position'];
                }
            );

            $matches_by_agency = array();
            foreach ( $matches as $match ) {
                if ( ! isset( $matches_by_agency[ $match['agency'] ] ) ) {
                    $matches_by_agency[ $match['agency'] ] = $match;
                }
            }

            return array_values( $matches_by_agency );
        }

        private function get_agency_match_from_line( string $line ): array {
            $matches = $this->get_agency_matches_from_line( $line );
            return $matches[0] ?? array();
        }

	        /**
	         * Value normalizers.
	         */
	        private function normalize_un_code( string $value ): string {
	            $value = preg_replace_callback(
	                '/\b(UN|ID|NA)\s*[-\/]?\s*([0-9B]{3,4})\b/i',
	                function ( array $matches ): string {
	                    return strtoupper( $matches[1] ) . strtr( strtoupper( $matches[2] ), array( 'B' => '8' ) );
	                },
	                $value
	            );

	            if ( preg_match( '/\b(UN|ID|NA)\s*[-\/]?\s*([0-9]{3,4})\b/i', $value, $matches ) ) {
	                return strtoupper( $matches[1] ) . $matches[2];
	            }

	            if ( preg_match( '/\bUN[IilL]\s*([0-9]{3})\b/i', $value, $matches ) ) {
	                return 'UN1' . $matches[1];
	            }

	            if ( preg_match( '/\b[tTlL][lI]?N\s*([0-9]{3,4})\b/', $value, $matches ) ) {
	                return 'UN' . $matches[1];
	            }

	            return '';
	        }

	        private function normalize_labeled_un_code( string $value ): string {
	            $un_code = $this->normalize_un_code( $value );
	            if ( '' !== $un_code ) {
	                return $un_code;
	            }

	            if ( preg_match( '/^\s*([0-9]{3,4})\b/', $value, $matches ) ) {
	                return 'UN' . $matches[1];
	            }

	            return '';
	        }

	        private function normalize_packing_group_ocr_text( string $value ): string {
	            return strtr( $value, array( 'l' => 'I', 'L' => 'I', 't' => 'I', 'T' => 'I' ) );
	        }

	        private function apply_single_line_shipping_description_to_record( array $record, string $text ): array {
	            if (
	                ! preg_match(
	                    '/\b((?:UN|ID|NA)\s*\d{3,4})\s*,\s*(.+),\s*([A-Z0-9.]+)\s*,\s*(?:PG\s*)?([A-ZILl1]{1,3}|[123])\b/i',
	                    $text,
	                    $matches
	                )
	                && ! preg_match(
	                    '/\b((?:UN|ID|NA)\s*\d{3,4})\s*,\s*(.+?)\s+(\d+(?:\.\d+)?|CBL)\s*,\s*(?:PG\s*)?([A-ZILl1]{1,3}|[123])\b/i',
	                    $text,
	                    $matches
	                )
	            ) {
	                return $record;
	            }

	            if ( empty( $record['un_code'] ) ) {
	                $record['un_code'] = $this->normalize_un_code( $matches[1] );
	            }

	            if ( empty( $record['shipping_name'] ) ) {
	                $record['shipping_name'] = $this->normalize_whitespace( $matches[2] );
	            }

	            if ( empty( $record['hazard_class'] ) ) {
	                $record['hazard_class'] = strtoupper( $matches[3] );
	            }

	            if ( empty( $record['packing_group'] ) ) {
	                $record['packing_group'] = $this->normalize_packing_group( $matches[4] );
	            }

	            return $record;
	        }

	        private function get_shipping_description_from_text( string $text ): string {
	            if ( ! preg_match( '/\bPossible Shipping Description(?:\(s\))?\s*:?\s*(.+)$/i', $text, $matches ) ) {
	                return '';
	            }

	            $description = $this->normalize_whitespace( $matches[1] );
	            $description = preg_replace( '/^(?:not regulated|not restricted)\s+/i', '', $description );
	            $description = preg_replace( '/\b(?:Sea|Air|Land|Ground)\s*-\s+.*$/i', '', $description );
	            $description = trim( $description );

	            if ( ! preg_match( '/\b(?:UN|ID|NA)\s*\d{3,4}\b/i', $description ) ) {
	                return '';
	            }

	            return $description;
	        }

	        private function apply_shipping_description_to_record( array $record, string $text ): array {
	            $description = $this->get_shipping_description_from_text( $text );
	            if ( '' === $description ) {
	                return $record;
	            }

	            if ( empty( $record['un_code'] ) ) {
	                $record['un_code'] = $this->normalize_un_code( $description );
	            }

	            if ( empty( $record['hazard_class'] ) && preg_match( '/\s(\d+(?:\.\d+)?)\s+(?:I{1,3}|1|2|3)\s*$/i', $description, $matches ) ) {
	                $record['hazard_class'] = $matches[1];
	            }

	            if ( empty( $record['packing_group'] ) && preg_match( '/\s(I{1,3}|1|2|3)\s*$/i', $description, $matches ) ) {
	                $record['packing_group'] = $this->normalize_packing_group( $matches[1] );
	            }

	            if ( empty( $record['shipping_name'] ) ) {
	                $shipping_name = preg_replace( '/^\s*(?:UN|ID|NA)\s*\d{3,4}\s+/i', '', $description );
	                $shipping_name = preg_replace( '/\s+\d+(?:\.\d+)?\s+(?:I{1,3}|1|2|3)\s*$/i', '', $shipping_name );
	                $record['shipping_name'] = $this->normalize_whitespace( $shipping_name );
	            }

	            return $record;
	        }

	        private function extract_labeled_value( string $text, string $label_pattern, string $next_label_pattern ): string {
	            if ( ! preg_match( '/\b' . $label_pattern . '\b\s*:?\s*(.+?)(?=\s+(?:' . $next_label_pattern . ')(?:\b|\s|:)|$)/i', $text, $matches ) ) {
	                return '';
	            }

	            return $this->normalize_whitespace( $matches[1] );
	        }

	        /**
	         * Handles inline label/value sequences like:
	         * "UN number UN1090 UN proper shipping name ACETONE Transport hazard class(es) Class 3 Packing group II".
	         */
	        private function apply_inline_labeled_transport_values_to_record( array $record, string $text ): array {
	            $next_label_pattern = implode(
	                '|',
	                array(
	                    'UN\s+proper\s+shipping\s+name',
	                    'proper\s+shipping\s+name',
	                    'shipping\s+name',
	                    'transport\s+hazard\s+class(?:\(es\))?',
	                    'hazard\s+class',
	                    'class(?:\/division)?',
	                    'subsidiary\s+(?:risk|hazard)',
	                    'packing\s+group',
	                    'special\s+precautions',
	                    'environmental\s+hazards?',
	                    'marine\s+pollutant',
	                    'ERG\s+number',
	                )
	            );

	            if ( empty( $record['un_code'] ) ) {
	                $un_code = $this->extract_labeled_value( $text, 'UN[\s-]*(?:number|no\.?|ID|NA\s+number)', $next_label_pattern );
	                if ( '' !== $un_code ) {
	                    $record['un_code'] = $this->normalize_labeled_un_code( $un_code );
	                }
	            }

	            if ( empty( $record['shipping_name'] ) ) {
	                $shipping_name = $this->extract_labeled_value( $text, '(?:UN\s+proper\s+shipping\s+name|proper\s+shipping\s+name|shipping\s+name)', $next_label_pattern );
	                if ( '' !== $shipping_name ) {
	                    $shipping_name = preg_replace( '/\s+Transport\s+hazard\s+class(?:\(es\))?.*$/i', '', $shipping_name );
	                    $shipping_name = preg_replace( '/\s+Packing\s+group.*$/i', '', $shipping_name );
	                    $record['shipping_name'] = $shipping_name;
	                }
	            }

	            if ( empty( $record['hazard_class'] ) || ! preg_match( '/\d/', $record['hazard_class'] ) ) {
	                $hazard_class = $this->extract_labeled_value( $text, '(?:transport\s+hazard\s+class(?:\(es\))?|hazard\s+class|class(?:\/division)?)', $next_label_pattern );
	                if ( ! $this->has_non_regulated_language( $hazard_class ) && preg_match( '/\b(?:Class\s*)?(\d+(?:\.\d+)?)\b/i', $hazard_class, $matches ) ) {
	                    $record['hazard_class'] = $matches[1];
	                }
	            }

	            if ( empty( $record['packing_group'] ) ) {
	                $packing_group = $this->extract_labeled_value( $text, 'packing\s+group', $next_label_pattern );
	                if ( '' !== $packing_group ) {
	                    $record['packing_group'] = $this->normalize_packing_group( $packing_group );
	                }
	            }

	            return $record;
	        }

	        private function apply_repeated_column_transport_values_to_record( array $record, string $text ): array {
	            if ( empty( $record['un_code'] ) && preg_match( '/\b(UN|ID|NA)\s*([0-9]{3,4})\b/i', $text, $matches ) ) {
	                $record['un_code'] = strtoupper( $matches[1] ) . $matches[2];
	            }

	            if (
	                ( empty( $record['shipping_name'] ) || preg_match( '/^(?:flammable|not available\.?)$/i', $record['shipping_name'] ) )
	                && preg_match( '/\bUN\s+proper\s+(.+?)\s+Transport\s+(?:\d+(?:\.\d+)?\s*)+\s+hazard\s+class(?:\(es\))?/i', $text, $matches )
	            ) {
	                $shipping_name_text = $this->normalize_whitespace( $matches[1] );
	                $shipping_name_text = preg_replace( '/\bshipping\s+name\b/i', ' ', $shipping_name_text );
	                $words = preg_split( '/\s+/', str_replace( ',', ' ', $shipping_name_text ) );
	                $shipping_name_parts = array();
	                foreach ( $words as $word ) {
	                    $word = trim( $word );
	                    if ( '' === $word ) {
	                        continue;
	                    }

	                    $key = strtolower( $word );
	                    if ( ! isset( $shipping_name_parts[ $key ] ) ) {
	                        $shipping_name_parts[ $key ] = $word;
	                    }
	                }

	                if ( ! empty( $shipping_name_parts ) ) {
	                    $record['shipping_name'] = implode( ', ', array_values( $shipping_name_parts ) );
	                }
	            }

	            if (
	                ( empty( $record['hazard_class'] ) || ! preg_match( '/\d/', $record['hazard_class'] ) )
	                && preg_match( '/\bTransport\s+((?:\d+(?:\.\d+)?\s*)+)\s+hazard\s+class(?:\(es\))?/i', $text, $matches )
	            ) {
	                if ( preg_match( '/\d+(?:\.\d+)?/', $matches[1], $class_matches ) ) {
	                    $record['hazard_class'] = $class_matches[0];
	                }
	            }

	            return $record;
	        }

	        private function strip_page_metadata_from_value( string $value ): string {
	            $value = preg_replace( '/\s+(?:Date of issue|Date of previous issue|Revision date|Reviewed on|printed|print date|Page:|page\s+\d+\s+of\s+\d+|Safety Data Sheet|Section\s+14\b|Additional information)\b.*$/i', '', $value );
	            return $this->normalize_whitespace( $value );
	        }

	        private function apply_header_sequence_transport_values_to_record( array $record, string $text ): array {
	            if (
	                preg_match(
	                    '/\bUN\s+number\s+Proper\s+shipping\s+name\s+Transport\s+hazard\s+class(?:\(es\))?\s+Packing\s+group\s+((?:UN|ID|NA)\s*\d{3,4})\s+(.+?)(?=\s+(?:Date of issue|Date of previous issue|Revision date|Page:|Section\s+14\b|Additional information|Environmental hazards|Special precautions|$))/i',
	                    $text,
	                    $matches
	                )
	            ) {
	                if ( empty( $record['un_code'] ) ) {
	                    $record['un_code'] = $this->normalize_un_code( $matches[1] );
	                }

	                $shipping_name = $this->strip_page_metadata_from_value( $matches[2] );
	                if ( '' !== $shipping_name && ( empty( $record['shipping_name'] ) || 'transport' === strtolower( $record['shipping_name'] ) ) ) {
	                    $record['shipping_name'] = $shipping_name;
	                }

	                if ( ! empty( $record['packing_group'] ) && ! $this->looks_like_packing_group( $record['packing_group'] ) ) {
	                    $record['packing_group'] = '';
	                }
	            }

	            return $record;
	        }

	        /**
	         * Regulated-material helpers.
	         *
	         * A UN/ID code is treated as regulated first. Explicit "not regulated"
	         * language can set the row false, while limited quantity / consumer
	         * commodity language is treated as a regulated exception.
	         */
	        private function has_non_regulated_language( string $text ): bool {
	            if ( '' === trim( $text ) ) {
	                return false;
	            }

	            $patterns = array(
	                '/\bnot\s+(?:regulated|restricted)\b/i',
	                '/\bnon[-\s]?regulated\b/i',
	                '/\bnone\s+applied\b/i',
	                '/\bnot\s+applicable\b/i',
	                '/\bnot\s+applicable\s*\/\s*not\s+regulated\b/i',
	                '/\bnot\s+classified(?:\s+as\s+hazardous)?(?:\s+for\s+transport)?\b/i',
	                '/\bnot\s+considered\s+as\s+class\s+\d+(?:\.\d+)?\b/i',
	                '/\bnot\s+dangerous\s+to\s+transport\b/i',
	                '/\b(?:no|not)\s+dangerous\s+goods?\b/i',
	                '/\bno\s+dangerous\s+good\s+in\s+sense\s+of\s+(?:these\s+)?transport\s+regulations\b/i',
	            );

	            foreach ( $patterns as $pattern ) {
	                if ( preg_match( $pattern, $text ) ) {
	                    return true;
	                }
	            }

	            return false;
	        }

	        private function has_regulated_exception_language( string $text ): bool {
	            if ( '' === trim( $text ) ) {
	                return false;
	            }

	            return (bool) preg_match( '/\b(?:limited\s+quantity|consumer\s+commodity|orm-d|id\s*8000)\b/i', $text );
	        }

	        private function has_explicit_non_regulated_status( string $text ): bool {
	            if ( '' === trim( $text ) ) {
	                return false;
	            }

	            return (bool) preg_match( '/\b(?:not\s+(?:regulated|restricted)|non[-\s]?regulated|not\s+dangerous\s+to\s+transport)\b/i', $text );
	        }

	        private function looks_like_transport_hazard_class( string $value ): bool {
	            $value = strtoupper( trim( $value ) );
	            if ( '' === $value ) {
	                return false;
	            }

	            if ( 'CBL' === $value ) {
	                return true;
	            }

	            if ( ! preg_match( '/^([1-9])(?:\.(\d))?$/', $value, $matches ) ) {
	                return false;
	            }

	            $class = (int) $matches[1];
	            $division = isset( $matches[2] ) ? (int) $matches[2] : null;
	            if ( 1 > $class || 9 < $class ) {
	                return false;
	            }

	            if ( null === $division ) {
	                return true;
	            }

	            $allowed_divisions = array(
	                1 => array( 1, 2, 3, 4, 5, 6 ),
	                2 => array( 1, 2, 3 ),
	                4 => array( 1, 2, 3 ),
	                5 => array( 1, 2 ),
	                6 => array( 1, 2 ),
	            );

	            return in_array( $division, $allowed_divisions[ $class ] ?? array(), true );
	        }

	        private function has_meaningful_transport_value( string $value ): bool {
	            $value = $this->normalize_whitespace( $value );
	            if ( '' === $value ) {
	                return false;
	            }

	            if ( preg_match( '/^(?:-|n\.?a\.?|none|not applicable|not assigned by regulation|agency|class|transport|transport\s+hazard\s+class(?:\(es\))?|h[ae]\s*z?ard|hazard\s+class|packing\s+group|proper\s+shipping\s+name|14(?:[,.]\d+)?\.?)$/i', $value ) ) {
	                return false;
	            }

	            if ( preg_match( '/^(?:[A-Z]{2,5}[-:\s]+)?(?:shipping\s+name|proper\s+shipping\s+name)\s*:?\s*n\.?a\.?$/i', $value ) ) {
	                return false;
	            }

	            if ( preg_match( '/^(?:DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM|ADR\/RID|ICAO\/IATA)\s*:?,?$/i', $value ) ) {
	                return false;
	            }

	            if ( preg_match( '/^\d+(?:\.\d+)?\s*Class\b/i', $value ) ) {
	                return false;
	            }

	            if ( preg_match( '/^not applicable/i', $value ) && preg_match( '/\b(?:Safety Data Sheet|14\.\d|SDS)\b/i', $value ) ) {
	                return false;
	            }

	            return ! $this->has_non_regulated_language( $value );
	        }

	        private function get_unique_section_un_code( string $text ): string {
	            if (
	                ! preg_match_all(
	                    '/\b(?:UN|ID|NA)\s*[-\/]?\s*\d{3,4}\b|\bUN[IilL]\s*\d{3}\b|\b(?:UN\/ID\s*(?:no\.?|number)?|UN\/NA\s*(?:no\.?|number)?|UN\s*(?:number|no\.?)|Identification\s+No\.?)\s*:?\s*(\d{3,4})\b|\b(?:ADR\s*\/\s*RID,\s*IMDG,\s*IATA|CFR|IMO\/MDG|IATA(?:\s*\(Cargo\))?)\s*:?\s*(\d{4})\b/i',
	                    $text,
	                    $matches
	                )
	            ) {
	                return '';
	            }

	            $codes = array();
	            foreach ( $matches[0] as $index => $match ) {
	                if ( ! empty( $matches[1][ $index ] ) ) {
	                    $match = $matches[1][ $index ];
	                } elseif ( ! empty( $matches[2][ $index ] ) ) {
	                    $match = $matches[2][ $index ];
	                }
	                $code = $this->normalize_labeled_un_code( $match );
	                if ( '' !== $code ) {
	                    $codes[ $code ] = true;
	                }
	            }

	            if ( 1 !== count( $codes ) ) {
	                return '';
	            }

	            return array_key_first( $codes );
	        }

	        private function get_agency_list_un_code( string $agency, string $text ): string {
	            if ( '' === $agency || '' === trim( $text ) ) {
	                return '';
	            }

	            if (
	                preg_match_all(
	                    '/\b((?:(?:DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\s*(?:\/\s*RID)?\s*,?\s*){2,})\s*:?\s*((?:UN|ID|NA)?\s*[-\/]?\s*\d{3,4})\b/i',
	                    $text,
	                    $matches,
	                    PREG_SET_ORDER
	                )
	            ) {
	                foreach ( $matches as $match ) {
	                    $agency_names = array();
	                    if ( preg_match_all( '/\b(DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\b/i', $match[1], $agency_matches ) ) {
	                        $agency_names = array_map( 'strtoupper', $agency_matches[1] );
	                    }

	                    if ( in_array( strtoupper( $agency ), $agency_names, true ) ) {
	                        return $this->normalize_labeled_un_code( $match[2] );
	                    }
	                }
	            }

	            return '';
	        }

	        private function has_regulated_classification_values( array $record ): bool {
	            if ( ! $this->looks_like_transport_hazard_class( $record['hazard_class'] ?? '' ) ) {
	                return false;
	            }

	            if ( $this->has_meaningful_transport_value( $record['shipping_name'] ?? '' ) ) {
	                return true;
	            }

	            $packing_group = $record['packing_group'] ?? '';
	            return (
	                $this->looks_like_packing_group( $packing_group )
	                && ! preg_match( '/\b(?:not applicable|n\/a|none|not assigned by regulation)\b/i', $packing_group )
	            );
	        }

	        private function agency_is_explicitly_not_regulated( array $record, string $text ): bool {
	            $agency = $record['agency'] ?? '';
	            if ( '' === $agency || '' === trim( $text ) ) {
	                return false;
	            }

	            return (bool) preg_match( '/\b' . preg_quote( $agency, '/' ) . '(?:[-\s]*(?:Code|DGR))?\b.{0,80}\b(?:not\s+regulated|not\s+restricted|non[-\s]?regulated)\b/i', $text );
	        }

	        private function clean_transport_record_values( array $record ): array {
	            foreach ( array( 'shipping_name', 'hazard_class', 'packing_group' ) as $field ) {
	                if ( isset( $record[ $field ] ) ) {
	                    $record[ $field ] = $this->normalize_whitespace( (string) $record[ $field ] );
	                }
	            }

	            if (
	                ! empty( $record['shipping_name'] )
	                && ! $this->has_explicit_non_regulated_status( $record['shipping_name'] )
	                && ! $this->has_meaningful_transport_value( $record['shipping_name'] )
	            ) {
	                $record['shipping_name'] = '';
	            }

	            if ( ! empty( $record['shipping_name'] ) && ! preg_match( '/\bnon[-\s]?regulated\s+material\b/i', $record['shipping_name'] ) && preg_match( '/\b(not\s+regulated|not\s+restricted|non[-\s]?regulated)\b/i', $record['shipping_name'], $matches ) ) {
	                $record['shipping_name'] = ucwords( strtolower( str_replace( '-', ' ', $matches[1] ) ) );
	            }

	            if ( empty( $record['un_code'] ) && ( ! empty( $record['regulated_material'] ) || $this->has_regulated_classification_values( $record ) ) ) {
	                $section_text = $record['transport_section'] ?? '';
	                if ( ! $this->agency_is_explicitly_not_regulated( $record, $section_text ) ) {
	                    $record['un_code'] = $this->get_agency_list_un_code( $record['agency'] ?? '', $section_text );
	                    if ( empty( $record['un_code'] ) ) {
	                        $record['un_code'] = $this->get_unique_section_un_code( $section_text );
	                    }
	                }

	                if ( empty( $record['un_code'] ) && ! $this->agency_is_explicitly_not_regulated( $record, $this->transport_section ) ) {
	                    $record['un_code'] = $this->get_agency_list_un_code( $record['agency'] ?? '', $this->transport_section );
	                    if ( empty( $record['un_code'] ) ) {
	                        $record['un_code'] = $this->get_unique_section_un_code( $this->transport_section );
	                    }
	                }
	            }

	            if ( ! empty( $record['hazard_class'] ) && ! $this->looks_like_transport_hazard_class( $record['hazard_class'] ) ) {
	                $record['hazard_class'] = '';
	            }

	            if ( ! empty( $record['packing_group'] ) && ! $this->looks_like_packing_group( $record['packing_group'] ) ) {
	                $record['packing_group'] = '';
	            }

	            return $record;
	        }

	        private function is_noise_transport_record( array $record ): bool {
	            $record = $this->clean_transport_record_values( $record );

	            if ( ! empty( $record['regulated_material'] ) ) {
	                return false;
	            }

	            if ( ! empty( $record['un_code'] ) ) {
	                return false;
	            }

	            if (
	                'GENERIC' === ( $record['agency'] ?? 'GENERIC' )
	                && $this->has_explicit_non_regulated_status( $record['shipping_name'] ?? '' )
	                && empty( $record['un_code'] )
	                && empty( $record['hazard_class'] )
	                && ( empty( $record['packing_group'] ) || ! $this->has_meaningful_transport_value( $record['packing_group'] ) )
	            ) {
	                return true;
	            }

	            if ( $this->has_explicit_non_regulated_status( $record['shipping_name'] ?? '' ) ) {
	                return false;
	            }

	            if ( preg_match( '/^(?:DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\s+(?:not regulated|not restricted|non[-\s]?regulated)/i', $record['transport_section'] ?? '' ) ) {
	                return false;
	            }

	            if ( $this->has_regulated_classification_values( $record ) ) {
	                return false;
	            }

	            return (
	                ! $this->has_meaningful_transport_value( $record['shipping_name'] ?? '' )
	                && ! $this->has_meaningful_transport_value( $record['packing_group'] ?? '' )
	            );
	        }

	        private function get_transport_record_quality_score( array $record ): int {
	            $score = 0;

	            if ( ! empty( $record['regulated_material'] ) ) {
	                $score += 100;
	            }

	            if ( ! empty( $record['un_code'] ) ) {
	                $score += 30;
	            }

	            if ( $this->has_meaningful_transport_value( $record['shipping_name'] ?? '' ) ) {
	                $score += 20;
	            }

	            if ( $this->looks_like_transport_hazard_class( $record['hazard_class'] ?? '' ) ) {
	                $score += 15;
	            }

	            if ( $this->looks_like_packing_group( $record['packing_group'] ?? '' ) ) {
	                $score += 10;
	            }

	            return $score;
	        }

	        private function clean_transport_records( array $records ): array {
	            $clean_records = array();
	            foreach ( $records as $record ) {
	                $record = $this->clean_transport_record_values( $record );
	                if ( $this->is_noise_transport_record( $record ) ) {
	                    continue;
	                }

	                $record['regulated_material'] = $this->is_transport_record_regulated( $record, $this->get_agency_record_context( $record ) );
	                $clean_records[] = $record;
	            }

	            $best_records_by_agency = array();
	            foreach ( $clean_records as $record ) {
	                $agency = $record['agency'] ?? 'GENERIC';
	                if (
	                    ! isset( $best_records_by_agency[ $agency ] )
	                    || $this->get_transport_record_quality_score( $record ) > $this->get_transport_record_quality_score( $best_records_by_agency[ $agency ] )
	                ) {
	                    $best_records_by_agency[ $agency ] = $record;
	                }
	            }

	            return array_values( $best_records_by_agency );
	        }

	        private function is_transport_record_regulated( array $record, string $context = '' ): bool {
	            if ( ! empty( $record['un_code'] ) ) {
	                return true;
	            }

	            if ( $this->agency_is_explicitly_not_regulated( $record, $this->transport_section ) ) {
	                return false;
	            }

	            if ( $this->has_regulated_classification_values( $record ) ) {
	                return true;
	            }

	            $record_text = implode(
	                ' ',
	                array_filter(
	                    array(
	                        $record['shipping_name'] ?? '',
	                        $record['hazard_class'] ?? '',
	                        $record['packing_group'] ?? '',
	                        $record['hazardous_terms'] ?? '',
	                        $context,
	                    )
	                )
	            );

	            if ( $this->has_non_regulated_language( $record_text ) ) {
	                return false;
	            }

	            return $this->has_regulated_exception_language( $record_text );
	        }

	        private function finalize_transport_record( array $record, string $context = '' ): array {
	            $context = $this->normalize_whitespace( $context );
	            if ( '' !== $context ) {
	                $record = $this->apply_header_sequence_transport_values_to_record( $record, $context );
	                $record = $this->apply_repeated_column_transport_values_to_record( $record, $context );
	                $record = $this->apply_inline_labeled_transport_values_to_record( $record, $context );
	                $record = $this->apply_vertical_label_values_to_record( $record, $context );
	                $record = $this->apply_single_line_shipping_description_to_record( $record, $context );
	                $record = $this->apply_shipping_description_to_record( $record, $context );

	                if ( ( empty( $record['hazard_class'] ) || ! preg_match( '/\d/', $record['hazard_class'] ) ) && preg_match( '/\bClass\s*:?\s*(\d+(?:\.\d+)?)/i', $context, $matches ) ) {
	                    $record['hazard_class'] = $matches[1];
	                }

	                if (
	                    preg_match( '/^UN31(?:0[1-9]|1[0-9]|20)$/', $record['un_code'] ?? '' )
	                    && preg_match( '/\borganic peroxide\b/i', $record['shipping_name'] ?? '' )
	                    && '5.2' !== ( $record['hazard_class'] ?? '' )
	                ) {
	                    $record['hazard_class'] = '5.2';
	                }

	                if (
	                    ! empty( $record['hazard_class'] )
	                    && preg_match( '/\bnot\s+considered\s+as\s+class\s+' . preg_quote( (string) $record['hazard_class'], '/' ) . '\b/i', $context )
	                ) {
	                    $record['hazard_class'] = '';
	                }

	                if ( empty( $record['un_code'] ) && $this->has_non_regulated_language( $record['shipping_name'] ?? '' ) ) {
	                    $record['hazard_class'] = '';
	                }

	                if ( ! empty( $record['shipping_name'] ) ) {
	                    $record['shipping_name'] = preg_replace( '/\s+Transport\s+hazard\s+class(?:\(es\))?.*$/i', '', $record['shipping_name'] );
	                    $record['shipping_name'] = preg_replace( '/(\))\s+[A-Z][A-Z0-9\s\-]{8,}$/', '$1', $record['shipping_name'] );
	                    $record['shipping_name'] = preg_replace( '/\s+Packing\s+group.*$/i', '', $record['shipping_name'] );
	                    $record['shipping_name'] = $this->strip_page_metadata_from_value( $record['shipping_name'] );
	                }

	                if ( empty( $record['packing_group'] ) && preg_match( '/\bPacking Group\s+(not applicable|n\/a|I{1,3}|1|2|3)\b/i', $context, $matches ) ) {
	                    $record['packing_group'] = $this->normalize_packing_group( $matches[1] );
	                }

	                if ( empty( $record['packing_group'] ) || ! $this->looks_like_packing_group( $record['packing_group'] ) ) {
	                    $record['packing_group'] = $this->get_unique_section_packing_group( $context );
	                }

	                if (
	                    ( empty( $record['packing_group'] ) || ! $this->looks_like_packing_group( $record['packing_group'] ) )
	                    && preg_match( '/^2(?:\.\d+)?$/', $record['hazard_class'] ?? '' )
	                ) {
	                    $record['packing_group'] = 'Class 2 - Not applicable';
	                }

	                if ( ! empty( $record['packing_group'] ) && ! $this->looks_like_packing_group( $record['packing_group'] ) ) {
	                    $record['packing_group'] = '';
	                }
	            }

	            $record['regulated_material'] = $this->is_transport_record_regulated( $record, $context );
	            $record['transport_section'] = $context;
	            if ( '' !== $context ) {
	                $record['hazardous_terms'] = $this->match_hazardous_terms( $context );
	            }
	            return $record;
	        }

	        /**
	         * Packing group and freight class helpers.
	         *
	         * SDS files sometimes say "shipping group" when they mean packing group,
	         * but freight class values are numeric class values. These helpers
	         * route ambiguous values to the least surprising column.
	         */
	        private function looks_like_packing_group( string $value ): bool {
	            return (bool) preg_match( '/^\s*(?:I{1,3}|1|2|3|class\s+2\s*-\s*not\s+applicable|not\s+applicable|not\s+assigned\s+by\s+regulation|n\/a|none)\b/i', $value );
	        }

	        private function normalize_packing_group( string $value ): string {
	            $value = $this->normalize_whitespace( $value );
	            if ( '' === $value ) {
	                return '';
	            }

	            if ( preg_match( '/\b(?:not\s+applicable|n\/a)\b/i', $value ) ) {
	                return 'Not applicable';
	            }

	            if ( preg_match( '/\bnot\s+assigned\s+by\s+regulation\b/i', $value ) ) {
	                return 'Not assigned by regulation';
	            }

	            if ( preg_match( '/\bnone\b/i', $value ) ) {
	                return 'None';
	            }

	            $value_for_roman_match = $this->normalize_packing_group_ocr_text( $value );
	            if ( preg_match( '/\b(?:M|H)I\b/i', $value_for_roman_match ) ) {
	                return 'III';
	            }

	            if ( preg_match( '/\bI[T7]\b/i', $value_for_roman_match ) ) {
	                return 'II';
	            }

	            if ( preg_match( '/\b(?:PG|Packing\s*Group)?\s*(I{1,3}|1|2|3)\b/i', $value_for_roman_match, $matches ) ) {
	                return strtoupper( $matches[1] );
	            }

	            return $value;
	        }

	        private function get_unique_section_packing_group( string $text ): string {
	            if ( ! preg_match_all( '/\bPACKING\s+GROUP\s*:?\s*PG\s*(I{1,3}|1|2|3)\b/i', $text, $matches ) ) {
	                return '';
	            }

	            $groups = array();
	            foreach ( $matches[1] as $match ) {
	                $group = $this->normalize_packing_group( $match );
	                if ( '' !== $group ) {
	                    $groups[ $group ] = true;
	                }
	            }

	            if ( 1 !== count( $groups ) ) {
	                return '';
	            }

	            return array_key_first( $groups );
	        }

	        private function looks_like_freight_class( string $value ): bool {
	            return (bool) preg_match( '/^\s*(?:50|55|60|65|70|77\.5|85|92\.5|100|110|125|150|175|200|250|300|400|500)\b/i', $value );
	        }

	        private function apply_ambiguous_shipping_group_to_record( array $record, string $value ): array {
	            if ( $this->looks_like_packing_group( $value ) ) {
	                $record['packing_group'] = $this->normalize_packing_group( $value );
	            } elseif ( $this->looks_like_freight_class( $value ) ) {
	                $record['shipping_class'] = $value;
	            }

	            return $record;
	        }

	        private function apply_transport_value_to_record( array $record, string $label, string $value ): array {
	            $label = strtolower( $label );
	            $value = $this->normalize_whitespace( $value );

	            if ( preg_match( '/\b(un|un\/id|un\/na|id)\b/', $label ) ) {
	                $record['un_code'] = $this->normalize_labeled_un_code( $value );
	            } elseif ( preg_match( '/shipping name|proper shipping name|description/', $label ) ) {
	                $record['shipping_name'] = $value;
	            } elseif ( preg_match( '/hazard class|class\/division|transport hazard class/', $label ) ) {
	                if ( preg_match( '/\b(\d+(?:\.\d+)?)\b/', $value, $matches ) ) {
	                    $record['hazard_class'] = $matches[1];
	                }
	            } elseif ( preg_match( '/packing group|pg\b/', $label ) ) {
	                $record['packing_group'] = $this->normalize_packing_group( $value );
	            } elseif ( preg_match( '/\b(?:shipping|ship|shp)\s*group\b/', $label ) ) {
	                $record = $this->apply_ambiguous_shipping_group_to_record( $record, $value );
	            } elseif ( preg_match( '/\b(?:shipping|ship|shp|freight)\s*class\b/', $label ) ) {
	                $record['shipping_class'] = $value;
	            }

	            return $record;
	        }

	        private function apply_vertical_label_values_to_record( array $record, string $text ): array {
	            if (
	                ( empty( $record['packing_group'] ) || ! $this->looks_like_packing_group( $record['packing_group'] ) )
	                && preg_match( '/\bPacking\s+group\b(?:\s+(?:Special Provisions|Description|Emergency Response Guide(?:\s+Number)?|Special Precautions))*\s+(not applicable|n\/a|[IHMlL]{1,3}|[123])\b/i', $text, $matches )
	            ) {
	                $record['packing_group'] = $this->normalize_packing_group( $matches[1] );
	            }

	            return $record;
	        }

	        private function get_shared_transport_record(): array {
	            $record = $this->get_empty_transport_record();
	            $text = $this->transport_section;

	            if ( preg_match( '/\b(?:UN\s+Number|Identification\s+No\.)\s*:?\s*((?:UN|ID|NA)\s*\d{3,4})/i', $text, $matches ) ) {
	                $record['un_code'] = $this->normalize_un_code( $matches[1] );
	            }

	            if ( preg_match( '/\b(?:UN\s+Proper\s+Shipping\s+Name|Proper\s+Shipping\s+Name)\s*:?\s*(.+?)(?=\s+(?:Transport\s+Hazard\s+Class|Hazard\s+Class|Identification\s+No\.|Packing\s+Group|\s+(?:DOT|ADR|RID|IMDG|IMO|ICAO|IATA)\b)|$)/is', $text, $matches ) ) {
	                $record['shipping_name'] = rtrim( $this->normalize_whitespace( $matches[1] ), '. ' );
	            }

	            if ( preg_match( '/\b(?:Transport\s+Hazard\s+Class|Hazard\s+Class)\s*:?\s*(\d+(?:\.\d+)?|CBL)\b/i', $text, $matches ) ) {
	                $record['hazard_class'] = strtoupper( $matches[1] );
	            }

	            if ( preg_match( '/\bPacking\s+Group\s*:?\s*(not applicable|n\/a|I{1,3}|1|2|3)\b/i', $text, $matches ) ) {
	                $record['packing_group'] = $this->normalize_packing_group( $matches[1] );
	            }

	            if (
	                '' === $record['un_code']
	                && '' === $record['shipping_name']
	                && '' === $record['hazard_class']
	                && '' === $record['packing_group']
	            ) {
	                return array();
	            }

	            return $record;
	        }

	        private function apply_shared_transport_values_to_records( array $records ): array {
	            $shared_record = $this->get_shared_transport_record();
	            if ( empty( $shared_record ) ) {
	                return $records;
	            }

	            foreach ( $records as $index => $record ) {
	                $record_context = $this->get_agency_record_context( $record );
	                if ( $this->has_non_regulated_language( $record_context . ' ' . ( $record['transport_section'] ?? '' ) ) ) {
	                    continue;
	                }

	                foreach ( array( 'un_code', 'shipping_name', 'hazard_class', 'packing_group' ) as $field ) {
	                    if ( empty( $record[ $field ] ) && ! empty( $shared_record[ $field ] ) ) {
	                        $record[ $field ] = $shared_record[ $field ];
	                    }
	                }

	                $record['regulated_material'] = $this->is_transport_record_regulated( $record, $this->get_agency_record_context( $record ) );
	                $records[ $index ] = $record;
	            }

	            return $records;
	        }

	        private function strip_un_code_from_shipping_name( string $value ): string {
	            $value = $this->normalize_whitespace( $value );
	            $value = preg_replace( '/^\s*(?:UN|ID|NA)\s*\d{3,4}\s*,?\s*/i', '', $value );
	            return $this->normalize_whitespace( $value );
	        }

        /**
         * Parser 1: compact table rows.
         *
         * Handles lines like "DOT UN1993 Flammable liquids 3 II".
         */
        private function parse_table_transport_records(): array {
            $records = array();
            $lines = preg_split( '/[\r\n]+/', $this->transport_section );

            foreach ( $lines as $line ) {
                $line = $this->normalize_whitespace( $line );
                if ( '' === $line ) {
                    continue;
                }

                if ( preg_match( '/^(DOT|IATA|IMDG)\s+((?:UN|ID)\s*\d{3,4})\s+(.+?)\s+(\d+(?:\.\d+)?)\s+(not applicable|n\/a|[ivx]+|[123])$/i', $line, $matches ) ) {
                    $agency_match = $this->get_agency_match_from_line( $matches[1] );
                    $record = $this->get_empty_transport_record( $agency_match['agency'] ?? strtoupper( $matches[1] ) );
                    $record['agency_alias'] = $agency_match['agency_alias'] ?? strtolower( $matches[1] );
                    $record['transport_types'] = $agency_match['transport_types'] ?? $record['transport_types'];
                    $record['jurisdiction'] = $agency_match['jurisdiction'] ?? $record['jurisdiction'];
                    $record['un_code'] = $this->normalize_un_code( $matches[2] );
	                    $record['shipping_name'] = $this->normalize_whitespace( $matches[3] );
	                    $record['hazard_class'] = $matches[4];
	                    $record['packing_group'] = $this->normalize_packing_group( $matches[5] );
	                    $records[] = $this->finalize_transport_record( $record, $line );
	                }
	            }

            return $records;
        }

        /**
         * Parser 2: repeated agency/status phrases on one line.
         *
         * Handles compact non-regulated lines like
         * "DOT not regulated TDG not regulated IMDG not regulated".
         */
        private function parse_inline_agency_status_records(): array {
            $records = array();

            if ( preg_match_all( '/\b((?:(?:DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\s*(?:,|\/|and)?\s*){2,})\s+(not regulated|not restricted|non[-\s]?regulated(?:\s+material)?|limited quantity|consumer commodity|orm-d|id\s*8000)\b/i', $this->transport_section, $agency_list_matches, PREG_SET_ORDER ) ) {
                foreach ( $agency_list_matches as $agency_list_match ) {
                    if ( ! preg_match_all( '/\b(DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\b/i', $agency_list_match[1], $agency_matches ) ) {
                        continue;
                    }

                    foreach ( array_unique( array_map( 'strtoupper', $agency_matches[1] ) ) as $agency_name ) {
                        $agency_match = $this->get_agency_match_from_line( $agency_name );
                        $record = $this->get_empty_transport_record( $agency_match['agency'] ?? $agency_name );
                        $record['agency_alias'] = $agency_match['agency_alias'] ?? strtolower( $agency_name );
                        $record['transport_types'] = $agency_match['transport_types'] ?? $record['transport_types'];
                        $record['jurisdiction'] = $agency_match['jurisdiction'] ?? $record['jurisdiction'];
                        $record['shipping_name'] = $this->normalize_whitespace( $agency_list_match[2] );
                        $records[] = $this->finalize_transport_record( $record, $agency_name . ' ' . $agency_list_match[2] );
                    }
                }

                if ( ! empty( $records ) ) {
                    return $records;
                }
            }

            if ( ! preg_match_all( '/\b(DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\b\s+(not regulated|not restricted|non[-\s]?regulated(?:\s+material)?|limited quantity|consumer commodity|orm-d|id\s*8000)\b/i', $this->transport_section, $matches, PREG_SET_ORDER ) || count( $matches ) < 2 ) {
                return $records;
            }

            foreach ( $matches as $match ) {
                $agency_match = $this->get_agency_match_from_line( $match[1] );
                $record = $this->get_empty_transport_record( $agency_match['agency'] ?? strtoupper( $match[1] ) );
                $record['agency_alias'] = $agency_match['agency_alias'] ?? strtolower( $match[1] );
                $record['transport_types'] = $agency_match['transport_types'] ?? $record['transport_types'];
                $record['jurisdiction'] = $agency_match['jurisdiction'] ?? $record['jurisdiction'];
                $record['shipping_name'] = $this->normalize_whitespace( $match[2] );
                $records[] = $this->finalize_transport_record( $record, $match[0] );
            }

            return $records;
        }

	        private function get_or_create_agency_record( array &$records_by_agency, string $agency_name ): array {
	            $agency_match = $this->get_agency_match_from_line( $agency_name );
	            $agency = $agency_match['agency'] ?? strtoupper( $agency_name );

	            if ( ! isset( $records_by_agency[ $agency ] ) ) {
	                $records_by_agency[ $agency ] = $this->get_empty_transport_record( $agency );
	                $records_by_agency[ $agency ]['agency_alias'] = $agency_match['agency_alias'] ?? strtolower( $agency_name );
	                $records_by_agency[ $agency ]['transport_types'] = $agency_match['transport_types'] ?? $records_by_agency[ $agency ]['transport_types'];
	                $records_by_agency[ $agency ]['jurisdiction'] = $agency_match['jurisdiction'] ?? $records_by_agency[ $agency ]['jurisdiction'];
	            }

	            return $records_by_agency[ $agency ];
	        }

	        private function save_agency_record( array &$records_by_agency, array $record ): void {
	            if ( ! empty( $record['agency'] ) ) {
	                $records_by_agency[ $record['agency'] ] = $record;
	            }
	        }

	        private function get_agency_names_from_label( string $agency_label ): array {
	            $agency_names = array();
	            $parts = preg_split( '/\s*\/\s*/', strtoupper( $agency_label ) );

	            foreach ( $parts as $part ) {
	                $part = trim( $part );
	                if ( '' !== $part && ! in_array( $part, $agency_names, true ) ) {
	                    $agency_names[] = $part;
	                }
	            }

	            return $agency_names;
	        }

	        private function get_agency_record_context( array $record ): string {
	            return $this->normalize_whitespace(
	                implode(
	                    ' ',
	                    array_filter(
	                        array(
	                            $record['agency'] ?? '',
	                            $record['un_code'] ?? '',
	                            $record['shipping_name'] ?? '',
	                            $record['hazard_class'] ?? '',
	                            $record['packing_group'] ?? '',
	                        )
	                    )
	                )
	            );
	        }

	        /**
	         * Parser 3: label-grouped agency values.
	         *
	         * Handles sections where labels are grouped together and each value
	         * names the agency, such as "UN No. (DOT)..." followed later by
	         * "Proper shipping name (DOT)..." and "DOT pack group...".
	         */
	        private function parse_agency_labeled_value_records(): array {
	            $records_by_agency = array();
	            $text = $this->normalize_whitespace( $this->transport_section );
	            $agency_pattern = 'ADR\/RID|ICAO\/IATA|DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM';
	            $has_numbered_transport_labels = (bool) preg_match( '/\b14\.\d+\.\s*(?:UN number|UN proper shipping name|Transport hazard class|Packing group)\b/i', $text );

	            if ( ! preg_match( '/\b(?:UN[-\/\s]*(?:No\.?|Number|ID)?\s*\((?:' . $agency_pattern . ')\)|Proper shipping name\s*\((?:' . $agency_pattern . ')\)|Transport hazard class(?:\(es\))?\s*\((?:' . $agency_pattern . ')\)|Packing group\s*\((?:' . $agency_pattern . ')\)|(?:' . $agency_pattern . ')\s+(?:hazard class|pack group|packing group))(?=\s|:|$)/i', $text ) ) {
	                return array();
	            }

	            if ( preg_match_all( '/\bUN[-\/\s]*(?:No\.?|Number|ID)?\.?\s*\(\s*(' . $agency_pattern . ')\s*\)\s*:?\s*((?:(?:UN|ID|NA)\s*)?\d{3,4}|Not applicable)/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    foreach ( $this->get_agency_names_from_label( $match[1] ) as $agency_name ) {
	                        $record = $this->get_or_create_agency_record( $records_by_agency, $agency_name );
	                        $record['un_code'] = $this->normalize_labeled_un_code( $match[2] );
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match( '/\bUN\/NA\s+Number\s*:?\s*((?:UN|ID|NA)\s*\d{3,4})/i', $text, $matches ) ) {
	                foreach ( $records_by_agency as $agency => $record ) {
	                    if ( empty( $record['un_code'] ) ) {
	                        $record['un_code'] = $this->normalize_un_code( $matches[1] );
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match_all( '/\bProper shipping name\s*\((' . $agency_pattern . ')\)\s*:?\s*(.+?)(?=\s+Proper shipping name\s*\((?:' . $agency_pattern . ')\)|\s+Transport document description|\s+Transport hazard class|\s+Hazard Classes?\s*\((?:' . $agency_pattern . ')\)|\s+Packing group\s*\((?:' . $agency_pattern . ')\)|\s+(?:Date of issue|Date of previous issue|Revision date|Reviewed on|printed|print date|page\s+\d+\s+of\s+\d+|Safety Data Sheet)\b|\s+14\.\d+\.|\s+(?:' . $agency_pattern . ')\s+(?:hazard class|Class|pack group|packing group)\b|$)/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    foreach ( $this->get_agency_names_from_label( $match[1] ) as $agency_name ) {
	                        $record = $this->get_or_create_agency_record( $records_by_agency, $agency_name );
	                        $record['shipping_name'] = $this->strip_page_metadata_from_value( $match[2] );
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match_all( '/\bTransport document description\s*\((' . $agency_pattern . ')\)\s*:?\s*((?:UN|ID|NA)\s*\d{3,4}\s+.+?)(?=\s+Transport document description\s*\((?:' . $agency_pattern . ')\)|\s+14\.\d+\.|\s+Transport hazard class|$)/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    foreach ( $this->get_agency_names_from_label( $match[1] ) as $agency_name ) {
	                        $record = $this->get_or_create_agency_record( $records_by_agency, $agency_name );
	                        $record = $this->apply_single_line_shipping_description_to_record( $record, $match[2] );
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match_all( '/\b(DOT|IMDG|IATA)\s*(?:[-_~]\s*)?(?:SHIPPING NAME|P\.S\.N\.)\s*[:;\/]?\s*(.+?)(?=\s+(?:DOT|IMDG|IATA)\s*(?:[-_~]\s*)?(?:HAZARD CLASS|CLASS|PACKING GROUP|Marine Pollutant|P\.S\.N\.)\s*[:;\/]?|\s+(?:Date of issue|Date of previous issue|Revision date|Reviewed on|printed|print date|page\s+\d+\s+of\s+\d+|Safety Data Sheet)\b|\s+This product\b|$)/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    $record = $this->get_or_create_agency_record( $records_by_agency, $match[1] );
	                    $record['shipping_name'] = $this->strip_page_metadata_from_value( $match[2] );
	                    $this->save_agency_record( $records_by_agency, $record );
	                }
	            }

	            if ( preg_match_all( '/\b(?:Transport hazard class(?:\(es\))?|Hazard Classes?)\s*\((' . $agency_pattern . ')\)\s*:?\s*(\d+(?:\.\d+)?|Not applicable)/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    foreach ( $this->get_agency_names_from_label( $match[1] ) as $agency_name ) {
	                        $record = $this->get_or_create_agency_record( $records_by_agency, $agency_name );
	                        if ( preg_match( '/\d/', $match[2] ) ) {
	                            $record['hazard_class'] = $match[2];
	                        }
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match_all( '/\b(' . $agency_pattern . ')\s+(?:hazard class|class(?:\/division)?|Class)\s+(\d+(?:\.\d+)?)/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    foreach ( $this->get_agency_names_from_label( $match[1] ) as $agency_name ) {
	                        $record = $this->get_or_create_agency_record( $records_by_agency, $agency_name );
	                        $record['hazard_class'] = $match[2];
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match_all( '/\b(' . $agency_pattern . ')\s*(?:[-_~]\s*)?(?:HAZARD CLASS|CLASS)\s*[:;\/]?\s*(?:Class\s*)?(\d+(?:\.\d+)?|Flammable Liquid)/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    foreach ( $this->get_agency_names_from_label( $match[1] ) as $agency_name ) {
	                        $record = $this->get_or_create_agency_record( $records_by_agency, $agency_name );
	                        $record['hazard_class'] = preg_match( '/flammable liquid/i', $match[2] ) ? '3' : $match[2];
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match_all( '/\bPacking group\s*\((' . $agency_pattern . ')\)\s*:?\s*(not applicable|n\/a|I{1,3}|1|2|3)\b/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    foreach ( $this->get_agency_names_from_label( $match[1] ) as $agency_name ) {
	                        $record = $this->get_or_create_agency_record( $records_by_agency, $agency_name );
	                        $record['packing_group'] = $this->normalize_packing_group( $match[2] );
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match_all( '/\b(' . $agency_pattern . ')\s+(?:pack group|packing group)\s+(not applicable|n\/a|I{1,3}|1|2|3)\b/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    foreach ( $this->get_agency_names_from_label( $match[1] ) as $agency_name ) {
	                        $record = $this->get_or_create_agency_record( $records_by_agency, $agency_name );
	                        $record['packing_group'] = $this->normalize_packing_group( $match[2] );
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match_all( '/\b(' . $agency_pattern . ')\s*(?:[-_]\s*)?PACKING GROUP\s*:?\s*PG\s*(I{1,3}|1|2|3)\b/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    foreach ( $this->get_agency_names_from_label( $match[1] ) as $agency_name ) {
	                        $record = $this->get_or_create_agency_record( $records_by_agency, $agency_name );
	                        $record['packing_group'] = $this->normalize_packing_group( $match[2] );
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( $has_numbered_transport_labels && preg_match( '/\b14\.4\.\s*Packing group\s+(not applicable|n\/a|I{1,3}|1|2|3)\b/i', $text, $matches ) ) {
	                $shared_packing_group = $this->normalize_packing_group( $matches[1] );
	                foreach ( $records_by_agency as $agency => $record ) {
	                    if ( empty( $record['packing_group'] ) ) {
	                        $record['packing_group'] = $shared_packing_group;
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( preg_match( '/\bUN\/NA\s+Number\s*:?\s*((?:UN|ID|NA)\s*\d{3,4})/i', $text, $matches ) ) {
	                foreach ( $records_by_agency as $agency => $record ) {
	                    if ( empty( $record['un_code'] ) ) {
	                        $record['un_code'] = $this->normalize_un_code( $matches[1] );
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            if ( $has_numbered_transport_labels ) {
	                $fallback_shipping_name = '';
	                foreach ( $records_by_agency as $record ) {
	                    if ( ! empty( $record['shipping_name'] ) ) {
	                        $fallback_shipping_name = $record['shipping_name'];
	                        break;
	                    }
	                }

	                if ( '' !== $fallback_shipping_name ) {
	                    foreach ( $records_by_agency as $agency => $record ) {
	                        if ( empty( $record['shipping_name'] ) ) {
	                            $record['shipping_name'] = $fallback_shipping_name;
	                            $this->save_agency_record( $records_by_agency, $record );
	                        }
	                    }
	                }
	            }

	            $records = array();
	            foreach ( $records_by_agency as $record ) {
	                $record_context = $has_numbered_transport_labels ? $this->get_agency_record_context( $record ) : $this->transport_section;
	                $record = $this->finalize_transport_record( $record, $record_context );
	                $record['transport_section'] = $this->normalize_whitespace( $this->transport_section );
	                $records[] = $record;
	            }

	            return $records;
	        }

	        /**
	         * Parser 4: agency labels followed by separate value blocks.
	         *
	         * Some SDS exports print all DOT/IMDG/IATA labels first, then print the
	         * actual values in agency order. This keeps the DOT value block from
	         * being mistaken for an empty DOT agency section.
	         */
	        private function parse_stacked_label_value_records(): array {
	            $text = $this->normalize_whitespace( $this->transport_section );

	            if (
	                ! preg_match( '/\bUSA:\s*Department of Transportation\s*\(DOT\)(?=\s|$)/i', $text )
	                || ! preg_match( '/\bEmergency Response Guide-Code\s*\(ERG\)\s*:\s*(.+)$/i', $text, $matches )
	            ) {
	                return array();
	            }

	            if (
	                ! preg_match_all(
	                    '/\b((?:UN|ID|NA)\s*\d{3,4})\s+((?:UN|ID|NA)\s*\d{3,4}\s*,\s*.+?)\s+(?:Class\s*)?(\d+(?:\.\d+)?|CBL)(?:\s*,\s*Subrisk\s*[-\w]+)?/i',
	                    $matches[1],
	                    $value_matches,
	                    PREG_SET_ORDER
	                )
	            ) {
	                return array();
	            }

	            $agencies = array( 'DOT', 'IMDG', 'IATA' );
	            $records = array();
	            foreach ( $value_matches as $index => $value_match ) {
	                if ( ! isset( $agencies[ $index ] ) ) {
	                    break;
	                }

	                $agency_match = $this->get_agency_match_from_line( $agencies[ $index ] );
	                $record = $this->get_empty_transport_record( $agency_match['agency'] ?? $agencies[ $index ] );
	                $record['agency_alias'] = $agency_match['agency_alias'] ?? strtolower( $agencies[ $index ] );
	                $record['transport_types'] = $agency_match['transport_types'] ?? $record['transport_types'];
	                $record['jurisdiction'] = $agency_match['jurisdiction'] ?? $record['jurisdiction'];
	                $record['un_code'] = $this->normalize_un_code( $value_match[1] );
	                $record['shipping_name'] = $this->strip_un_code_from_shipping_name( $value_match[2] );
	                $record['hazard_class'] = strtoupper( $value_match[3] );
	                $records[] = $this->finalize_transport_record( $record, $this->transport_section );
	            }

	            return $records;
	        }

	        private function make_transport_record_from_values( string $agency_name, string $un_code, string $shipping_name, string $hazard_class, string $packing_group, string $context ): array {
	            $agency_match = $this->get_agency_match_from_line( $agency_name );
	            $record = $this->get_empty_transport_record( $agency_match['agency'] ?? $agency_name );
	            $record['agency_alias'] = $agency_match['agency_alias'] ?? strtolower( $agency_name );
	            $record['transport_types'] = $agency_match['transport_types'] ?? $record['transport_types'];
	            $record['jurisdiction'] = $agency_match['jurisdiction'] ?? $record['jurisdiction'];
	            $record['un_code'] = $this->normalize_labeled_un_code( $un_code );
	            $record['shipping_name'] = $this->normalize_whitespace( $shipping_name );
	            $record['hazard_class'] = strtoupper( $hazard_class );
	            $record['packing_group'] = $this->normalize_packing_group( $packing_group );

	            return $this->finalize_transport_record( $record, $context );
	        }

	        /**
	         * Parser 11: legacy MSDS shipping description lines.
	         *
	         * Older one-page MSDS files often put shipping classification in the
	         * product identity block instead of a modern Section 14. These lines are
	         * compact and usually read "DOT Proper Shipping Name: NAME, 3, UN1234,
	         * PGII" or "DOT Proper Shipping Name: UN1234, NAME, 3, PGII".
	         */
	        private function parse_legacy_shipping_name_records(): array {
	            $text = $this->normalize_whitespace( $this->transport_section );
	            if ( '' === $text ) {
	                return array();
	            }

	            if (
	                ! preg_match_all(
	                    '/\b((?:(?:U\.?S\.?|U\.?S\.?A\.?)\s*)?(?:D\.?\s*O\.?\s*T\.?|DOT)\s+(?:Information|lnformation)|(?:D\.?\s*O\.?\s*T\.?|DOT|IMDG|IMO\/IMDG|IATA|ICAO)\s*\(?\s*(?:Proper\s+Shipping\s+Name|Shipping\s+Name)\s*\)?)\s*[:;]?\s*(.+?)(?=\s+(?:NFPA|SECTION\s+[IVX0-9]|sEcTtoN\s+15|={3,}|MANUFACTURER|CERCLA|Emergency\s+Response|REGULATORY\s+INFORMATION|REGULATORY\s+TNFORMATTON|cAs\b)|$)/i',
	                    $text,
	                    $matches,
	                    PREG_SET_ORDER
	                )
	            ) {
	                return array();
	            }

	            $records = array();
	            foreach ( $matches as $match ) {
	                $agency_match = $this->get_agency_match_from_line( $match[1] );
	                $record = $this->get_empty_transport_record( $agency_match['agency'] ?? 'DOT' );
	                $record['agency_alias'] = $agency_match['agency_alias'] ?? 'dot';
	                $record['transport_types'] = $agency_match['transport_types'] ?? $record['transport_types'];
	                $record['jurisdiction'] = $agency_match['jurisdiction'] ?? $record['jurisdiction'];

	                $description = trim( $match[2] );
	                if ( preg_match( '/\b(?:not\s+regulated|non[-\s]?regulated)\b/i', $description, $not_regulated_match ) ) {
	                    $record['shipping_name'] = ucwords( strtolower( str_replace( '-', ' ', $not_regulated_match[0] ) ) );
	                    $records[] = $this->finalize_transport_record( $record, $match[0] );
	                    continue;
	                }

	                if (
	                    preg_match(
	                        '/\b((?:UN|NA|ID)\s*[-\/]?\s*[0-9B]{3,4})\s*,?\s*(.+?),\s*(\d+(?:\.\d+)?|CBL)\s*,?\s*(?:PG\s*)?([IVXLTtl1]{1,3}|[123])\b/i',
	                        $description,
	                        $value_match
	                    )
	                ) {
	                    $record['un_code'] = $this->normalize_un_code( $value_match[1] );
	                    $record['shipping_name'] = $this->normalize_whitespace( $value_match[2] );
	                    $record['hazard_class'] = strtoupper( $value_match[3] );
	                    $record['packing_group'] = $this->normalize_packing_group( $value_match[4] );
	                    $records[] = $this->finalize_transport_record( $record, $match[0] );
	                    continue;
	                }

	                if (
	                    preg_match(
	                        '/^(.+?),\s*(\d+(?:\.\d+)?|CBL)\s*,?\s*0?\s*((?:UN|NA|ID)\s*[-\/]?\s*[0-9B]{3,4})\s*,?\s*(?:PG\s*)?([IVXLTtl1]{1,3}|[123])\b/i',
	                        $description,
	                        $value_match
	                    )
	                ) {
	                    $record['shipping_name'] = $this->normalize_whitespace( $value_match[1] );
	                    $record['hazard_class'] = strtoupper( $value_match[2] );
	                    $record['un_code'] = $this->normalize_un_code( $value_match[3] );
	                    $record['packing_group'] = $this->normalize_packing_group( $value_match[4] );
	                    $records[] = $this->finalize_transport_record( $record, $match[0] );
	                }
	            }

	            return $records;
	        }

	        /**
	         * Parser 5: stacked regulatory tables.
	         *
	         * Some supplier PDFs print several label blocks first (UNRTDG,
	         * IATA-DGR, IMDG-Code, 49 CFR), then emit the values in the same order.
	         * This pulls the repeated UN/name/class/packing rows back into agencies.
	         */
	        private function parse_stacked_regulatory_table_records(): array {
	            $text = $this->normalize_whitespace( $this->transport_section );

	            if ( ! preg_match( '/\b(?:UNRTDG|IATA-DGR|IMDG-Code|49 CFR)\b/i', $text ) ) {
	                return array();
	            }

	            if (
	                ! preg_match_all(
	                    '/\b((?:UN|ID|NA)\s*\d{3,4})\s+(.+?)\s+(\d+(?:\.\d+)?)\s+(Not assigned by regulation)\b/i',
	                    $text,
	                    $matches,
	                    PREG_SET_ORDER
	                )
	            ) {
	                return array();
	            }

	            $agency_order = array( 'GENERIC', 'IATA', 'IMDG', 'DOT' );
	            $records_by_agency = array();
	            foreach ( $matches as $index => $match ) {
	                if ( ! isset( $agency_order[ $index ] ) ) {
	                    break;
	                }

	                $record = $this->make_transport_record_from_values( $agency_order[ $index ], $match[1], $match[2], $match[3], $match[4], $this->transport_section );
	                $records_by_agency[ $record['agency'] ] = $record;
	            }

	            if (
	                preg_match(
	                    '/\bDomestic regulation\s+49 CFR\b.+?\bUN\/ID\/NA number\s*:?\s*((?:UN|ID|NA)\s*\d{3,4})\s+Proper shipping name\s*:?\s*(.+?)\s+Class\s*:?\s*(\d+(?:\.\d+)?)\s+Packing group\s*:?\s*(Not assigned by regulation|not applicable|n\/a|I{1,3}|1|2|3)\b/i',
	                    $text,
	                    $domestic_match
	                )
	            ) {
	                $record = $this->make_transport_record_from_values( 'DOT', $domestic_match[1], $domestic_match[2], $domestic_match[3], $domestic_match[4], $this->transport_section );
	                $records_by_agency[ $record['agency'] ] = $record;
	            }

	            return array_values( $records_by_agency );
	        }

	        private function get_numbered_transport_block( string $text, string $start, string $end ): string {
	            if ( ! preg_match( '/\b' . $start . '\b(.+?)\b' . $end . '\b/i', $text, $matches ) ) {
	                return '';
	            }

	            return $this->normalize_whitespace( $matches[1] );
	        }

	        private function get_most_common_hazard_class( string $text ): string {
	            if ( ! preg_match_all( '/\b([1-9](?:\.\d+)?)\b/', $text, $matches ) ) {
	                return '';
	            }

	            $counts = array();
	            foreach ( $matches[1] as $value ) {
	                if ( preg_match( '/^14(?:\.\d+)?$/', $value ) ) {
	                    continue;
	                }
	                $counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
	            }

	            if ( empty( $counts ) ) {
	                return '';
	            }

	            arsort( $counts );
	            return array_key_first( $counts );
	        }

	        /**
	         * Parser 6: numbered agency labels followed by value lists.
	         *
	         * Handles OCR output like "UN No. (ADR/RID) ... UN No. (IMDG)"
	         * followed much later by the four UN values.
	         */
	        private function parse_numbered_label_list_records(): array {
	            $text = $this->normalize_whitespace( $this->transport_section );

	            if ( ! preg_match( '/\b14\.1\.\s*UN number\b/i', $text ) || ! preg_match( '/\bUN No\.\s*\(/i', $text ) ) {
	                return array();
	            }

	            $un_block = $this->get_numbered_transport_block( $text, '14\.1\.\s*UN number', '14\.2\.\s*UN proper shipping name' );
	            $shipping_block = $this->get_numbered_transport_block( $text, '14\.2\.\s*UN proper shipping name', '14[,.]\s*3[,.]\s*Transport hazard class' );
	            $hazard_block = $this->get_numbered_transport_block( $text, '14[,.]\s*3[,.]\s*Transport hazard class(?:\(es\))?', '14[,.]\s*6[,.]\s*Special precautions' );

	            if ( '' === $un_block || '' === $shipping_block ) {
	                return array();
	            }

	            if ( preg_match( '/\bUN No\.\s*\([^)]+\)\s*:?\s*(?:(?:UN|ID|NA)\s*)?\d{3,4}\b/i', $un_block ) ) {
	                return array();
	            }

	            if ( ! preg_match_all( '/\bUN No\.\s*\(([^)]+)\)/i', $un_block, $agency_matches ) ) {
	                return array();
	            }

	            if ( ! preg_match_all( '/\b(?:UN\s*)?(\d{3,4})\b/', $un_block, $un_matches ) ) {
	                return array();
	            }

	            $agency_labels = $agency_matches[1];
	            $un_values = array_slice( $un_matches[1], -count( $agency_labels ) );
	            if ( empty( $un_values ) ) {
	                return array();
	            }

	            preg_match_all( '/\bORGANIC PEROXIDE TYPE [A-Z],\s*LIQUID\s*\(.+?\)/i', $shipping_block, $shipping_matches );
	            $shipping_values = $shipping_matches[0] ?? array();
	            $fallback_shipping_name = $shipping_values[0] ?? '';
	            $hazard_class = $this->get_most_common_hazard_class( $hazard_block );
	            if ( '' === $hazard_class && preg_match( '/\bORGANIC PEROXIDE\b/i', $shipping_block ) ) {
	                $hazard_class = '5.2';
	            }

	            $packing_group = '';
	            if ( preg_match( '/\b14[,.]\s*4[,.]\s*Packing group\s+(Not applicable|Not assigned by regulation|n\/a|I{1,3}|1|2|3)\b/i', $text, $packing_match ) ) {
	                $packing_group = $packing_match[1];
	            }

	            $records_by_agency = array();
	            foreach ( $agency_labels as $index => $agency_label ) {
	                $shipping_name = $shipping_values[ $index ] ?? $fallback_shipping_name;
	                foreach ( $this->get_agency_names_from_label( $agency_label ) as $agency_name ) {
	                    $record = $this->make_transport_record_from_values(
	                        $agency_name,
	                        $un_values[ $index ] ?? $un_values[0],
	                        $shipping_name,
	                        $hazard_class,
	                        $packing_group,
	                        $this->transport_section
	                    );
	                    $records_by_agency[ $record['agency'] ] = $record;
	                }
	            }

	            return array_values( $records_by_agency );
	        }

	        /**
	         * Parser 7: multimodal dash-labeled rows.
	         *
	         * Handles OCR like "UN-Number DOT, IMDG, IATA UNI133" plus a later
	         * model-regulation line containing the complete description.
	         */
	        private function parse_multimodal_dash_labeled_records(): array {
	            $text = $this->normalize_whitespace( $this->transport_section );

	            if ( ! preg_match( '/\bUN-Number\b.+\bDOT,\s*IMDG,\s*IATA\b/i', $text ) ) {
	                return array();
	            }

	            if (
	                ! preg_match(
	                    '/\bUN\s+"Model Regulation"\s*:?\s*((?:UN|ID|NA)\s*\d{3,4}|UN[IilL]\d{3})\s+(.+?),\s*(\d+(?:\.\d+)?|CBL),\s*([A-ZIT7l1]{1,3})\b/i',
	                    $text,
	                    $matches
	                )
	            ) {
	                return array();
	            }

	            $agency_names = array( 'DOT', 'IMDG', 'IATA' );
	            $records = array();
	            foreach ( $agency_names as $agency_name ) {
	                $records[] = $this->make_transport_record_from_values(
	                    $agency_name,
	                    $matches[1],
	                    $matches[2],
	                    $matches[3],
	                    $matches[4],
	                    $this->transport_section
	                );
	            }

	            return $records;
	        }

	        /**
	         * Parser 8: agency status lines with shared classification.
	         *
	         * Handles sections where DOT lists the full shipping description and
	         * other agencies only say "Status: Hazardous".
	         */
	        private function parse_hazardous_status_records(): array {
	            $text = $this->normalize_whitespace( $this->transport_section );

	            if ( ! preg_match( '/\b(?:TDG|IMO|IMDG|IATA)\s+Status\s*:\s*Hazardous\b/i', $text ) ) {
	                return array();
	            }

	            $shared_record = $this->get_shared_transport_record();
	            if ( empty( $shared_record ) || empty( $shared_record['un_code'] ) ) {
	                return array();
	            }

	            $records = array();
	            if ( preg_match( '/\bUS\s+DOT\s+Shipping\s+Classification\b/i', $text ) ) {
	                $records[] = $this->make_transport_record_from_values(
	                    'DOT',
	                    $shared_record['un_code'],
	                    $shared_record['shipping_name'],
	                    $shared_record['hazard_class'],
	                    $shared_record['packing_group'],
	                    $this->transport_section
	                );
	            }

	            if ( preg_match_all( '/\b(TDG|IMO|IMDG|IATA)\s+Status\s*:\s*Hazardous\b/i', $text, $matches ) ) {
	                foreach ( array_unique( array_map( 'strtoupper', $matches[1] ) ) as $agency_name ) {
	                    $records[] = $this->make_transport_record_from_values(
	                        $agency_name,
	                        $shared_record['un_code'],
	                        $shared_record['shipping_name'],
	                        $shared_record['hazard_class'],
	                        $shared_record['packing_group'],
	                        $this->transport_section
	                    );
	                }
	            }

	            return $records;
	        }

	        /**
	         * Parser 9: Spanish/Mexico Section 14 labels.
	         *
	         * Handles OCR text such as "N ONU", "Designacion Oficial de
	         * Transporte", "Clase", and "Grupo de embalaje".
	         */
	        private function parse_spanish_transport_records(): array {
	            $text = $this->normalize_whitespace( $this->transport_section );

	            if ( ! preg_match( '/\bN\s*ONU\b|\bDesignaci\s*n\s+Oficial\s+de\s+Transporte\b|\bGrupo\s+de\s+embalaje\b/i', $text ) ) {
	                return array();
	            }

	            $records_by_agency = array();

	            if ( preg_match_all( '/\bN\s*ONU\s*\((DOT|ADR|IMDG|IATA)\)\s*:?\s*((?:UN\s*)?\d{3,4})\b/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    $record = $this->get_or_create_agency_record( $records_by_agency, $match[1] );
	                    $record['un_code'] = $this->normalize_labeled_un_code( $match[2] );
	                    $this->save_agency_record( $records_by_agency, $record );
	                }
	            }

	            if ( preg_match_all( '/\b(DOT)\s+Designaci\s*n\s+Oficial\s+de\s+Transporte\s*:?\s*(.+?)(?=\s+(?:Departamento\s+de\s+Transporte|Etiquetas\s+de\s+peligro|Grupo\s+de\s+embalaje|Informaci\s*n\s+adicional|ADR\b|N\s*ONU\s*\(|Designaci\s*n\s+Oficial\s+de\s+Transporte\s*\(|Clase\s*\(|Air\s+transport\b|$))/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    $record = $this->get_or_create_agency_record( $records_by_agency, $match[1] );
	                    $record['shipping_name'] = $this->strip_page_metadata_from_value( $match[2] );
	                    $this->save_agency_record( $records_by_agency, $record );
	                }
	            }

	            if ( preg_match_all( '/\bDesignaci\s*n\s+Oficial\s+de\s+Transporte\s*\((IMDG|IATA|ADR)\)\s*:?\s*(.+?)(?=\s+(?:Departamento\s+de\s+Transporte|Etiquetas\s+de\s+peligro|Grupo\s+de\s+embalaje|Informaci\s*n\s+adicional|ADR\b|N\s*ONU\s*\(|Designaci\s*n\s+Oficial\s+de\s+Transporte\s*\(|Clase\s*\(|Air\s+transport\b|$))/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    $record = $this->get_or_create_agency_record( $records_by_agency, $match[1] );
	                    $record['shipping_name'] = $this->strip_page_metadata_from_value( $match[2] );
	                    $this->save_agency_record( $records_by_agency, $record );
	                }
	            }

	            if ( preg_match_all( '/\bDepartamento\s+de\s+Transporte\s*\((DOT)\)\s+Clases\s+de\s+Peligro\s*:?\s*(\d+(?:\.\d+)?)/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    $record = $this->get_or_create_agency_record( $records_by_agency, $match[1] );
	                    $record['hazard_class'] = $match[2];
	                    $this->save_agency_record( $records_by_agency, $record );
	                }
	            }

	            if ( preg_match_all( '/\bClase\s*\((DOT|ADR|IMDG|IATA)\)\s*:?\s*(\d+(?:\.\d+)?)/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    $record = $this->get_or_create_agency_record( $records_by_agency, $match[1] );
	                    $record['hazard_class'] = $match[2];
	                    $this->save_agency_record( $records_by_agency, $record );
	                }
	            }

	            if ( preg_match_all( '/\bGrupo\s+de\s+embalaje\s*\((DOT|ADR|IMDG|IATA)\)\s*:?\s*(not applicable|n\/a|I{1,3}|1|2|3)\b/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    $record = $this->get_or_create_agency_record( $records_by_agency, $match[1] );
	                    $record['packing_group'] = $this->normalize_packing_group( $match[2] );
	                    $this->save_agency_record( $records_by_agency, $record );
	                }
	            }

	            if ( preg_match_all( '/\bDescripci\s*n\s+del\s+documento\s+del\s+transporte\s*:?\s*((?:UN\s*)?\d{3,4})\s*,\s*(\d+(?:\.\d+)?)\s*,\s*(I{1,3}|1|2|3)\b/i', $text, $matches, PREG_SET_ORDER ) ) {
	                foreach ( $matches as $match ) {
	                    $record = $this->get_or_create_agency_record( $records_by_agency, 'ADR' );
	                    $record['un_code'] = $this->normalize_labeled_un_code( $match[1] );
	                    $record['hazard_class'] = $match[2];
	                    $record['packing_group'] = $this->normalize_packing_group( $match[3] );
	                    $this->save_agency_record( $records_by_agency, $record );
	                }
	            }

	            $fallback_shipping_name = '';
	            foreach ( $records_by_agency as $record ) {
	                if ( ! empty( $record['shipping_name'] ) ) {
	                    $fallback_shipping_name = $record['shipping_name'];
	                    break;
	                }
	            }

	            if ( '' !== $fallback_shipping_name ) {
	                foreach ( $records_by_agency as $agency => $record ) {
	                    if ( empty( $record['shipping_name'] ) ) {
	                        $record['shipping_name'] = $fallback_shipping_name;
	                        $this->save_agency_record( $records_by_agency, $record );
	                    }
	                }
	            }

	            $records = array();
	            foreach ( $records_by_agency as $record ) {
	                $records[] = $this->finalize_transport_record( $record, $this->get_agency_record_context( $record ) );
	            }

	            return $records;
	        }

	        /**
	         * Parser 10: agency lists followed by shared compact values.
	         *
	         * Handles OCR like "UN-Number: DOT, IMDG, IATA UNI 866" followed by
	         * shipping-name lines for DOT and IMDG/IATA.
	         */
	        private function parse_agency_list_compact_records(): array {
	            $text = $this->normalize_whitespace( $this->transport_section );

	            if (
	                ! preg_match(
	                    '/\bUN-?Number\b\s*(?:[:|]\s*)?((?:(?:DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\s*,?\s*){2,})\s+((?:UN|ID|NA)\s*\d{3,4}|UN[IilL]\s*\d{3})\b/i',
	                    $text,
	                    $un_match
	                )
	            ) {
	                return array();
	            }

	            if ( ! preg_match_all( '/\b(DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\b/i', $un_match[1], $agency_matches ) ) {
	                return array();
	            }

	            $hazard_class = '';
	            if ( preg_match( '/\bTransport\s+hazard\s+class(?:\(es\))?\s+(\d+(?:\.\d+)?)/i', $text, $class_match ) ) {
	                $hazard_class = $class_match[1];
	            }

	            $packing_group = '';
	            if ( preg_match( '/\bPacking\s+group\b\s*-?\s*(?:(?:DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\s*,?\s*){1,}\s+([IVXLl1]{1,3}|[123])\b/i', $text, $packing_match ) ) {
	                $packing_group = $packing_match[1];
	            }

	            $shipping_names = array();
	            if ( preg_match( '/-\s*DOT\s+(.+?)(?=\s+-\s*(?:IMDG|IATA|TDG|ADR|RID|ADN|NOM)\b|\s+-\s*Transport\b|\s+-\s*Packing\b|$)/i', $text, $dot_match ) ) {
	                $shipping_names['DOT'] = $this->strip_page_metadata_from_value( $dot_match[1] );
	            }

	            if ( preg_match( '/-\s*IMDG,\s*IATA\s+(.+?)(?=\s+-\s*Transport\b|\s+-\s*Packing\b|$)/i', $text, $combined_match ) ) {
	                $shipping_names['IMDG'] = $this->strip_page_metadata_from_value( $combined_match[1] );
	                $shipping_names['IATA'] = $this->strip_page_metadata_from_value( $combined_match[1] );
	            }

	            $records = array();
	            foreach ( array_unique( array_map( 'strtoupper', $agency_matches[1] ) ) as $agency_name ) {
	                $records[] = $this->make_transport_record_from_values(
	                    $agency_name,
	                    $un_match[2],
	                    $shipping_names[ $agency_name ] ?? reset( $shipping_names ) ?: '',
	                    $hazard_class,
	                    $packing_group,
	                    $this->transport_section
	                );
	            }

	            return $records;
	        }

	        /**
	         * Parser 11: bordered classification tables.
	         *
	         * Some PDFs emit visual tables as column values first, followed by the
	         * headers. This handles tables with DOT/TDG/Mexico/ADR/IMDG/IATA columns.
	         */
	        private function parse_bordered_classification_table_records(): array {
	            $text = $this->normalize_whitespace( $this->transport_section );

	            if ( ! preg_match( '/\bDOT\s+Classification\b.*\bUN\s+proper\s+shipping\s+name\b.*\bPacking\s+group\b/i', $text ) ) {
	                return array();
	            }

	            $records = array();
	            $pre_header = preg_split( '/\bDOT\s+Classification\b/i', $text, 2 )[0] ?? '';
	            if (
	                preg_match(
	                    '/^\s*(.+?)\s+3\s+(I{1,3})\s+(.+?)\s+3\s+(I{1,3})\s+(.+?)\s+((?:UN|ID|NA)\s*\d{3,4})\s+3\s+(I{1,3})\s+((?:UN|ID|NA)\s*\d{3,4})\s+((?:UN|ID|NA)\s*\d{3,4})\b/i',
	                    $pre_header,
	                    $matches
	                )
	            ) {
	                $header_records = array(
	                    array( 'DOT', $matches[8], $matches[1], '3', $matches[2] ),
	                    array( 'IMDG', $matches[9], $matches[3], '3', $matches[4] ),
	                    array( 'IATA', $matches[6], $matches[5], '3', $matches[7] ),
	                );

	                foreach ( $header_records as $header_record ) {
	                    $agency_match = $this->get_agency_match_from_line( $header_record[0] );
	                    $record = $this->get_empty_transport_record( $agency_match['agency'] ?? $header_record[0] );
	                    $record['agency_alias'] = $agency_match['agency_alias'] ?? strtolower( $header_record[0] );
	                    $record['transport_types'] = $agency_match['transport_types'] ?? $record['transport_types'];
	                    $record['jurisdiction'] = $agency_match['jurisdiction'] ?? $record['jurisdiction'];
	                    $record['un_code'] = $this->normalize_un_code( $header_record[1] );
	                    $record['shipping_name'] = $this->normalize_whitespace( $header_record[2] );
	                    $record['hazard_class'] = $header_record[3];
	                    $record['packing_group'] = $this->normalize_packing_group( $header_record[4] );
	                    $record = $this->finalize_transport_record( $record, $this->get_agency_record_context( $record ) );
	                    $record['transport_section'] = $text;
	                    $records[] = $record;
	                }
	            }

	            if (
	                preg_match_all(
	                    '/\b(TDG|ADR\/RID|Mexico)(?:\s+Classification)?\s+((?:UN|ID|NA)\s*\d{3,4})\s+(.+?)\s+(\d+(?:\.\d+)?)\s+(I{1,3})\s+No\./i',
	                    $text,
	                    $matches,
	                    PREG_SET_ORDER
	                )
	            ) {
	                foreach ( $matches as $match ) {
	                    $agency_name = 'ADR/RID' === $match[1] ? 'ADR' : $match[1];
	                    $agency_name = 'Mexico' === $agency_name ? 'NOM' : $agency_name;
	                    $agency_match = $this->get_agency_match_from_line( $agency_name );
	                    $record = $this->get_empty_transport_record( $agency_match['agency'] ?? $agency_name );
	                    $record['agency_alias'] = $agency_match['agency_alias'] ?? strtolower( $agency_name );
	                    $record['transport_types'] = $agency_match['transport_types'] ?? $record['transport_types'];
	                    $record['jurisdiction'] = $agency_match['jurisdiction'] ?? $record['jurisdiction'];
	                    $record['un_code'] = $this->normalize_un_code( $match[2] );
	                    $record['shipping_name'] = $this->normalize_whitespace( $match[3] );
	                    $record['hazard_class'] = $match[4];
	                    $record['packing_group'] = $this->normalize_packing_group( $match[5] );
	                    $record = $this->finalize_transport_record( $record, $this->get_agency_record_context( $record ) );
	                    $record['transport_section'] = $text;
	                    $records[] = $record;
	                }
	            }

	            if ( empty( $records ) ) {
	                return array();
	            }

	            $records_by_agency = array();
	            foreach ( $records as $record ) {
	                $records_by_agency[ $record['agency'] ] = $record;
	            }

	            return array_values( $records_by_agency );
	        }

	        /**
	         * Parser 6: agency-grouped sections.
	         *
	         * Handles SDS sections where each agency gets its own block or line,
	         * such as DOT, IATA, and IMDG with separate values under each label.
	         */
	        private function parse_grouped_transport_records(): array {
	            $records = array();
	            $current_agency = '';
	            $current_record = array();
	            $current_context = '';
	            $lines = preg_split( '/[\r\n]+/', $this->transport_section );

	            foreach ( $lines as $line ) {
	                $line = $this->normalize_whitespace( $line );
	                if ( '' === $line ) {
                    continue;
                }

	                $agency_match = $this->get_agency_match_from_line( $line );
	                if ( ! empty( $agency_match ) && $agency_match['agency_alias_position'] <= 25 ) {
	                    if ( ! empty( $current_record ) ) {
	                        $records[] = $this->finalize_transport_record( $current_record, $current_context );
	                    }
	                    $current_agency = $agency_match['agency'];
	                    $current_record = $this->get_empty_transport_record( $current_agency );
	                    $current_context = $line;
	                    $current_record['agency_alias'] = $agency_match['agency_alias'];
	                    $current_record['transport_types'] = $agency_match['transport_types'];
	                    $current_record['jurisdiction'] = $agency_match['jurisdiction'];

	                    $line = trim( preg_replace( '/^.*?' . preg_quote( $agency_match['agency_alias'], '/' ) . '\b\s*[:\-\/]?\s*/i', '', $line, 1 ) );
	                    $line = trim( preg_replace( '/^\(?[A-Z]{2,5}\)?\s*/', '', $line, 1 ) );
	                    if ( '' === $line ) {
	                        continue;
	                    }
	                }

	                if ( empty( $current_record ) ) {
	                    continue;
	                }

	                if ( false === strpos( $current_context, $line ) ) {
	                    $current_context .= ' ' . $line;
	                }

                if ( preg_match( '/^(UN\/ID no|UN\/NA NUMBER|UN Number|UN number|UN ID|UN\/ID|UN)\s*:?\s*(.+)$/i', $line, $matches ) ) {
                    $current_record = $this->apply_transport_value_to_record( $current_record, $matches[1], $matches[2] );
                    continue;
                }

                if ( preg_match( '/^(Proper Shipping Name|Shipping Name|Description)\s*:?\s*(.+)$/i', $line, $matches ) ) {
                    $current_record = $this->apply_transport_value_to_record( $current_record, $matches[1], $matches[2] );
                    continue;
                }

                if ( preg_match( '/^(Hazard class|Primary Hazard Class\/Division|Transport Hazard Class(?:\\(es\\))?)\s*:?\s*(.+)$/i', $line, $matches ) ) {
                    $current_record = $this->apply_transport_value_to_record( $current_record, $matches[1], $matches[2] );
                    continue;
                }

	                if ( preg_match( '/^(Packing group|Packing Group|PG)\s*:?\s*(.+)$/i', $line, $matches ) ) {
	                    $current_record = $this->apply_transport_value_to_record( $current_record, $matches[1], $matches[2] );
	                    continue;
	                }

	                if ( preg_match( '/^((?:Shipping|Ship|SHP)\s*Group|(?:Shipping|Ship|SHP|Freight)\s*Class)\s*:?\s*(.+)$/i', $line, $matches ) ) {
	                    $current_record = $this->apply_transport_value_to_record( $current_record, $matches[1], $matches[2] );
	                    continue;
	                }

	            }

	            if ( ! empty( $current_record ) ) {
	                $records[] = $this->finalize_transport_record( $current_record, $current_context );
	            }

	            return $this->apply_shared_transport_values_to_records( $records );
        }

        /**
         * Parser 4: flat label/value sections.
         *
         * Handles SDS sections that do not identify a regulatory agency and only
         * provide one set of labels like UN number, proper shipping name, class,
         * packing group, and shipping class.
         */
        private function parse_flat_transport_record(): array {
            $record = $this->get_empty_transport_record();

            $label_patterns = array(
                'un_code' => '/\b(?:UN Number|UN number|UN\/NA NUMBER|UN\/ID no)\b\s*:?\s*([^\n\r]+)/i',
	                'shipping_name' => '/\b(?:UN Proper Shipping Name|Proper Shipping Name|Shipping Name|Description)\b\s*:?\s*([^\n\r]+)/i',
	                'hazard_class' => '/\b(?:Transport Hazard Class|Hazard class|Primary Hazard Class\/Division)\b\s*:?\s*([^\n\r]+)/i',
	                'packing_group' => '/\b(?:Packing Group|PG)\b\s*:?\s*([^\n\r]+)/i',
	                'shipping_class' => '/\b(?:Shipping|Ship|SHP|Freight)\s*Class\b\s*:?\s*([^\n\r]+)/i',
	                'ambiguous_shipping_group' => '/\b(?:Shipping|Ship|SHP)\s*Group\b\s*:?\s*([^\n\r]+)/i',
	            );

            foreach ( $label_patterns as $field => $pattern ) {
                if ( preg_match( $pattern, $this->transport_section, $matches ) ) {
	                    if ( 'un_code' === $field ) {
	                        $record[ $field ] = $this->normalize_un_code( $matches[1] );
	                    } elseif ( 'ambiguous_shipping_group' === $field ) {
	                        $record = $this->apply_ambiguous_shipping_group_to_record( $record, $this->normalize_whitespace( $matches[1] ) );
	                    } elseif ( 'hazard_class' === $field && preg_match( '/\b(\d+(?:\.\d+)?)\b/', $matches[1], $class_matches ) ) {
	                        $record[ $field ] = $class_matches[1];
	                    } elseif ( 'packing_group' === $field ) {
	                        $record[ $field ] = $this->normalize_packing_group( $matches[1] );
	                    } else {
	                        $record[ $field ] = $this->normalize_whitespace( $matches[1] );
	                    }
                }
            }

	            $record = $this->finalize_transport_record( $record, $this->transport_section );

            if (
                '' === $record['un_code']
	                && '' === $record['shipping_name']
	                && '' === $record['hazard_class']
	                && '' === $record['packing_group']
	                && '' === $record['shipping_class']
	                && '' === $record['hazardous_terms']
	                && ! $this->has_non_regulated_language( $this->transport_section )
	            ) {
                return array();
            }

            return array( $record );
        }

        /**
         * Return the best available transportation rows for CSV export.
         *
         * The parsers run from most structured to least structured:
         * table rows, grouped agency blocks, then one generic flat record.
         */
        public function get_transport_records(): array {
            if ( empty( $this->transport_section ) ) {
                return array();
            }

            $records = $this->parse_table_transport_records();
            $records = $this->clean_transport_records( $records );
            if ( ! empty( $records ) ) {
                return $records;
            }

            $records = $this->parse_inline_agency_status_records();
            $records = $this->clean_transport_records( $records );
            if ( ! empty( $records ) ) {
                return $records;
            }

	            $records = $this->parse_stacked_regulatory_table_records();
	            $records = $this->clean_transport_records( $records );
	            if ( ! empty( $records ) ) {
	                return $records;
	            }

	            $records = $this->parse_numbered_label_list_records();
	            $records = $this->clean_transport_records( $records );
	            if ( ! empty( $records ) ) {
	                return $records;
	            }

	            $records = $this->parse_multimodal_dash_labeled_records();
	            $records = $this->clean_transport_records( $records );
	            if ( ! empty( $records ) ) {
	                return $records;
	            }

	            $records = $this->parse_hazardous_status_records();
	            $records = $this->clean_transport_records( $records );
	            if ( ! empty( $records ) ) {
	                return $records;
	            }

	            $records = $this->parse_spanish_transport_records();
	            $records = $this->clean_transport_records( $records );
	            if ( ! empty( $records ) ) {
	                return $records;
	            }

	            $records = $this->parse_agency_list_compact_records();
	            $records = $this->clean_transport_records( $records );
	            if ( ! empty( $records ) ) {
	                return $records;
	            }

            $records = $this->parse_agency_labeled_value_records();
            $records = $this->clean_transport_records( $records );
            if ( ! empty( $records ) ) {
                return $records;
            }

	            $records = $this->parse_legacy_shipping_name_records();
	            $records = $this->clean_transport_records( $records );
	            if ( ! empty( $records ) ) {
	                return $records;
	            }

	            $records = $this->parse_stacked_label_value_records();
	            $records = $this->clean_transport_records( $records );
	            if ( ! empty( $records ) ) {
	                return $records;
	            }

	            $records = $this->parse_bordered_classification_table_records();
	            $records = $this->clean_transport_records( $records );
	            if ( ! empty( $records ) ) {
	                return $records;
	            }

            $records = $this->parse_grouped_transport_records();
            $records = $this->clean_transport_records( $records );
            if ( ! empty( $records ) ) {
                return $records;
            }

            return $this->clean_transport_records( $this->parse_flat_transport_record() );
        }

	        /**
	         * Match fallback hazard terms and return unique phrases.
	         *
	         * Adjacent matched words are combined, so "inert gases" is returned as
	         * a phrase instead of separate "inert" and "gases" values.
	         */
	        private function match_hazardous_terms( string $text ): string {
	            if ( empty( $text ) ) {
	                return '';
	            }

	            $terms_pattern = implode(
	                '|',
	                array_map(
	                    function ( string $term ): string {
	                        return preg_quote( $term, '/' );
	                    },
	                    self::$HAZARDOUS_TERMS
	                )
	            );
	            $word_pattern = '/(?<![A-Za-z0-9])([A-Za-z0-9-]*(?:' . $terms_pattern . ')[A-Za-z0-9-]*)(?![A-Za-z0-9])/i';
	            if ( ! preg_match_all( $word_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
	                return '';
	            }

	            $matched_terms = array();
	            $current_phrase = '';
	            $last_end = null;
	            foreach ( $matches[1] as $match ) {
	                $word = strtolower( $match[0] );
	                $start = $match[1];
	                $end = $start + strlen( $match[0] );

	                if ( null !== $last_end ) {
	                    $gap = substr( $text, $last_end, $start - $last_end );
	                    if ( preg_match( '/^[\s\/-]+$/', $gap ) ) {
	                        $current_phrase .= ' ' . $word;
	                    } else {
	                        $matched_terms[] = $current_phrase;
	                        $current_phrase = $word;
	                    }
	                } else {
	                    $current_phrase = $word;
	                }

	                $last_end = $end;
	            }

	            if ( '' !== $current_phrase ) {
	                $matched_terms[] = $current_phrase;
	            }

	            $matched_terms = array_values( array_unique( $matched_terms ) );
	            sort( $matched_terms );

	            return implode( ', ', $matched_terms );
	        }

	        public function get_hazardous_terms(): string {
	            return $this->match_hazardous_terms( $this->transport_section );
	        }

	        public function get_shipping_class(): string {
	            $class = $this->extract_regex_value(
	                '/\b(?:shipping|ship|shp|freight)\s*class\b\s*:?\s*([^\n\r,;]+)/i',
	                $this->transport_section
	            );

	            return $class ? strtoupper( $this->normalize_whitespace( $class ) ) : '';
	        }

        public function get_section(): string {
            return $this->transport_section;
        }
    }
}
