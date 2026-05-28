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
                    'u.s. department of transportation',
                    'usa: department of transportation',
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
        public function get_transport_section( int $length = 2000 ): string {
            if ( empty( $this->cleaned_text ) ) {
                return '';
            }

            $transport_pos = false;
            $start_patterns = array(
                '/\btransport information\b/i',
                '/\btransportation information\b/i',
                '/\b14\.\s*transport(?:ation)? information\b/i',
            );
            foreach ( $start_patterns as $pattern ) {
                if ( preg_match( $pattern, $this->cleaned_text, $matches, PREG_OFFSET_CAPTURE ) ) {
                    $transport_pos = $matches[0][1];
                    break;
                }
            }

            if ( false === $transport_pos ) {
                return '';
            }

            $end_pos = false;
            if ( preg_match( '/\b15\.\s*regulatory information\b|\bregulatory information\b/i', $this->cleaned_text, $matches, PREG_OFFSET_CAPTURE, $transport_pos + 1 ) ) {
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
	                'nmfc_code'              => $this->get_nmfc_code(),
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
	            if ( preg_match( '/\b(UN|ID|NA)\s*([0-9]{3,4})\b/i', $value, $matches ) ) {
	                return strtoupper( $matches[1] ) . $matches[2];
	            }

	            return '';
	        }

	        private function normalize_packing_group_ocr_text( string $value ): string {
	            return strtr( $value, array( 'l' => 'I', 'L' => 'I' ) );
	        }

	        private function apply_single_line_shipping_description_to_record( array $record, string $text ): array {
	            if (
	                ! preg_match(
	                    '/\b((?:UN|ID|NA)\s*\d{3,4})\s*,\s*(.+),\s*([A-Z0-9.]+)\s*,\s*(?:PG\s*)?([A-ZILl1]{1,3}|[123])\b/i',
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

	        private function normalize_nmfc_code( string $value ): string {
	            if ( preg_match( '/\bNMFC\b[^A-Z0-9]*([0-9]{4,6}[A-Z0-9-]*)\b/i', $value, $matches ) ) {
	                return strtoupper( $matches[1] );
	            }

	            if ( preg_match( '/\b([0-9]{4,6}[A-Z0-9-]*)\b/i', $value, $matches ) ) {
	                return strtoupper( $matches[1] );
	            }

	            return '';
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
	                '/\bnot\s+applicable\s*\/\s*not\s+regulated\b/i',
	                '/\bnot\s+classified(?:\s+as\s+hazardous)?(?:\s+for\s+transport)?\b/i',
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

	        private function is_transport_record_regulated( array $record, string $context = '' ): bool {
	            if ( ! empty( $record['un_code'] ) ) {
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
	                $record = $this->apply_single_line_shipping_description_to_record( $record, $context );
	                $record = $this->apply_shipping_description_to_record( $record, $context );

	                if ( empty( $record['hazard_class'] ) && preg_match( '/\bClass\s+(\d+(?:\.\d+)?)/i', $context, $matches ) ) {
	                    $record['hazard_class'] = $matches[1];
	                }

	                if ( empty( $record['packing_group'] ) && preg_match( '/\bPacking Group\s+(not applicable|n\/a|I{1,3}|1|2|3)\b/i', $context, $matches ) ) {
	                    $record['packing_group'] = $this->normalize_packing_group( $matches[1] );
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
	         * but freight class values are numeric NMFC-style classes. These helpers
	         * route ambiguous values to the least surprising column.
	         */
	        private function looks_like_packing_group( string $value ): bool {
	            return (bool) preg_match( '/^\s*(?:I{1,3}|1|2|3|not\s+applicable|n\/a|none)\b/i', $value );
	        }

	        private function normalize_packing_group( string $value ): string {
	            $value = $this->normalize_whitespace( $value );
	            if ( '' === $value ) {
	                return '';
	            }

	            if ( preg_match( '/\b(?:not\s+applicable|n\/a)\b/i', $value ) ) {
	                return 'Not applicable';
	            }

	            if ( preg_match( '/\bnone\b/i', $value ) ) {
	                return 'None';
	            }

	            $value_for_roman_match = $this->normalize_packing_group_ocr_text( $value );
	            if ( preg_match( '/\b(?:PG|Packing\s*Group)?\s*(I{1,3}|1|2|3)\b/i', $value_for_roman_match, $matches ) ) {
	                return strtoupper( $matches[1] );
	            }

	            return $value;
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
	                $record['un_code'] = $this->normalize_un_code( $value );
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
	            } elseif ( preg_match( '/\bnmfc\b/', $label ) ) {
	                $record['nmfc_code'] = $this->normalize_nmfc_code( $value );
	            }

	            return $record;
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

            if ( ! preg_match_all( '/\b(DOT|TDG|IMDG|IATA|ICAO|ADR|RID|ADN|NOM)\b\s+(not regulated|not restricted|non[-\s]?regulated|limited quantity|consumer commodity|orm-d|id\s*8000)\b/i', $this->transport_section, $matches, PREG_SET_ORDER ) || count( $matches ) < 2 ) {
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

	        /**
	         * Parser 3: agency-grouped sections.
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

	                if ( preg_match( '/^(NMFC(?:\s*(?:Number|No\.?|Code|#))?)\s*:?\s*(.+)$/i', $line, $matches ) ) {
	                    $current_record = $this->apply_transport_value_to_record( $current_record, $matches[1], $matches[2] );
	                    continue;
	                }
	            }

	            if ( ! empty( $current_record ) ) {
	                $records[] = $this->finalize_transport_record( $current_record, $current_context );
	            }

            return $records;
        }

        /**
         * Parser 4: flat label/value sections.
         *
         * Handles SDS sections that do not identify a regulatory agency and only
         * provide one set of labels like UN number, proper shipping name, class,
         * packing group, shipping class, and NMFC.
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
	                'nmfc_code' => '/\bNMFC(?:\s*(?:Number|No\.?|Code|#))?\b\s*:?\s*([^\n\r]+)/i',
	            );

            foreach ( $label_patterns as $field => $pattern ) {
                if ( preg_match( $pattern, $this->transport_section, $matches ) ) {
	                    if ( 'un_code' === $field ) {
	                        $record[ $field ] = $this->normalize_un_code( $matches[1] );
	                    } elseif ( 'nmfc_code' === $field ) {
	                        $record[ $field ] = $this->normalize_nmfc_code( $matches[1] );
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
	                && '' === $record['nmfc_code']
	                && '' === $record['hazardous_terms']
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
            if ( ! empty( $records ) ) {
                return $records;
            }

            $records = $this->parse_inline_agency_status_records();
            if ( ! empty( $records ) ) {
                return $records;
            }

            $records = $this->parse_grouped_transport_records();
            if ( ! empty( $records ) ) {
                return $records;
            }

            return $this->parse_flat_transport_record();
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

	        public function get_nmfc_code(): string {
	            return $this->normalize_nmfc_code( $this->transport_section );
	        }

        public function get_section(): string {
            return $this->transport_section;
        }
    }
}
