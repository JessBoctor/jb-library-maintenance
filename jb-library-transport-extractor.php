<?php
if ( ! class_exists( 'JB_PDF_Transport_Extractor' ) ) {
    class JB_PDF_Transport_Extractor {
        private string $cleaned_text = '';
        private string $transport_section = '';

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
	         * Single-word terms to find useful hazard descriptors in the transport section.
	         *
	         * @var array
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
	            );
	        }

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

        private function get_agency_match_from_line( string $line ): array {
            foreach ( self::$AGENCY_MAP as $agency => $metadata ) {
                foreach ( $metadata['aliases'] as $alias ) {
                    if ( $this->alias_matches_line( $alias, $line ) ) {
                        $position = stripos( $line, $alias );
                        return array(
                            'agency' => $agency,
                            'agency_alias' => $alias,
                            'agency_alias_position' => false === $position ? 0 : $position,
                            'transport_types' => implode( ', ', $metadata['transport_types'] ),
                            'jurisdiction' => $metadata['jurisdiction'],
                        );
                    }
                }
            }

            return array();
        }

	        private function normalize_un_code( string $value ): string {
	            if ( preg_match( '/\b(UN|ID)\s*([0-9]{3,4})\b/i', $value, $matches ) ) {
	                return strtoupper( $matches[1] ) . $matches[2];
	            }

	            return '';
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
	            $record['regulated_material'] = $this->is_transport_record_regulated( $record, $context );
	            return $record;
	        }

	        private function looks_like_packing_group( string $value ): bool {
	            return (bool) preg_match( '/^\s*(?:I{1,3}|1|2|3|not\s+applicable|n\/a|none)\b/i', $value );
	        }

	        private function looks_like_freight_class( string $value ): bool {
	            return (bool) preg_match( '/^\s*(?:50|55|60|65|70|77\.5|85|92\.5|100|110|125|150|175|200|250|300|400|500)\b/i', $value );
	        }

	        private function apply_ambiguous_shipping_group_to_record( array $record, string $value ): array {
	            if ( $this->looks_like_packing_group( $value ) ) {
	                $record['packing_group'] = $value;
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
	                $record['packing_group'] = $value;
	            } elseif ( preg_match( '/\b(?:shipping|ship|shp)\s*group\b/', $label ) ) {
	                $record = $this->apply_ambiguous_shipping_group_to_record( $record, $value );
	            } elseif ( preg_match( '/\b(?:shipping|ship|shp|freight)\s*class\b/', $label ) ) {
	                $record['shipping_class'] = $value;
	            } elseif ( preg_match( '/\bnmfc\b/', $label ) ) {
	                $record['nmfc_code'] = $this->normalize_nmfc_code( $value );
	            }

	            return $record;
	        }

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
	                    $record['packing_group'] = ucfirst( strtolower( $matches[5] ) );
	                    $records[] = $this->finalize_transport_record( $record, $line );
	                }
	            }

            return $records;
        }

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

        private function parse_flat_transport_record(): array {
            $record = $this->get_empty_transport_record();

            $label_patterns = array(
                'un_code' => '/\b(?:UN Number|UN number|UN\/NA NUMBER|UN\/ID no)\b\s*:?\s*([^\n\r]+)/i',
	                'shipping_name' => '/\b(?:UN Proper Shipping Name|Proper Shipping Name|Shipping Name|Description)\b\s*:?\s*([^\n\r]+)/i',
	                'hazard_class' => '/\b(?:Transport Hazard Class|Hazard class|Primary Hazard Class\/Division)\b\s*:?\s*([^\n\r]+)/i',
	                'packing_group' => '/\bPacking Group\b\s*:?\s*([^\n\r]+)/i',
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

        public function get_transport_records(): array {
            if ( empty( $this->transport_section ) ) {
                return array();
            }

            $records = $this->parse_table_transport_records();
            if ( ! empty( $records ) ) {
                return $records;
            }

            $records = $this->parse_grouped_transport_records();
            if ( ! empty( $records ) ) {
                return $records;
            }

            return $this->parse_flat_transport_record();
        }

        private function parse_transport_matrix(): array {
            $section = $this->transport_section;
            $result = array( 'by_standard' => array() );

            if ( empty( $section ) ) {
                return $result;
            }

            $lines = preg_split('/[\r\n;]+/', $section);
            foreach ( $lines as $line ) {
                $line = trim( preg_replace('/\s+/', ' ', $line) );
                if ( $line === '' ) {
                    continue;
                }

                if ( preg_match_all('/\b(UN(?: proper shipping name)?|UN number|Packing group|Packing group ADR|Packing group ADR\/RID)\b[^\r\n]*?((?:ADR\/RID|ADR|RID|IMDG|IATA-DGR)(?:[\/.,\s]*(?:ADR\/RID|ADR|RID|IMDG|IATA-DGR))*)\s*[:\-]\s*([^\n\r]+)/i', $line, $lm, PREG_SET_ORDER) ) {
                    foreach ( $lm as $mline ) {
                        $standards_raw = $mline[2];
                        $value_raw = trim( $mline[3] );

                        $parts_label = preg_split('/\b(?:ADR\/RID|ADR|RID|IMDG|IATA-DGR)\b\s*[:\-]?/i', $value_raw);
                        if ( is_array( $parts_label ) && count( $parts_label ) > 0 ) {
                            $value_raw = trim( $parts_label[0] );
                        }

                        $stds = preg_split('/[\/ ,]+/', $standards_raw);
                        $stds = array_map( function( $s ) { return strtoupper( trim( $s ) ); }, $stds );

                        $un = '';
                        if ( preg_match('/UN\s*(\d{3,4})/i', $value_raw, $u) ) {
                            $un = 'UN' . $u[1];
                        }

                        $shipping = '';
                        if ( $un !== '' ) {
                            $shipping = preg_replace('/^.*?UN\s*\d{3,4}\s*,?\s*/i', '', $value_raw);
                        } else {
                            $shipping = preg_replace('/\b(Transport\b|Transport hazard class|Packing group|IMDG|IATA-DGR)\b.*/i', '', $value_raw);
                        }
                        $shipping = trim( preg_replace('/\s+/', ' ', $shipping) );

                        $pg = '';
                        if ( preg_match('/Packing group[^:]*[:\s]*([^,\n\r]+)/i', $value_raw, $pgm) ) {
                            $pg = strtoupper( trim( $pgm[1] ) );
                        } elseif ( preg_match('/\b(not applicable|n\/a)\b/i', $value_raw, $pgm2) ) {
                            $pg = strtoupper( $pgm2[1] );
                        }

                        $tclass = '';
                        if ( preg_match('/\bClass\s*(\d+(?:\.\d+)?)/i', $value_raw, $cm) ) {
                            $tclass = strtoupper( trim( $cm[1] ) );
                        }

                        foreach ( $stds as $std ) {
                            if ( $std === '' ) {
                                continue;
                            }
                            if ( ! isset( $result['by_standard'][ $std ] ) ) {
                                $result['by_standard'][ $std ] = array(
                                    'un' => $un,
                                    'shipping_name' => $shipping,
                                    'packing_group' => $pg,
                                    'transport_class' => $tclass,
                                );
                            } else {
                                $existing = $result['by_standard'][ $std ];
                                $result['by_standard'][ $std ] = array(
                                    'un' => $existing['un'] ?: $un,
                                    'shipping_name' => $existing['shipping_name'] ?: $shipping,
                                    'packing_group' => $existing['packing_group'] ?: $pg,
                                    'transport_class' => $existing['transport_class'] ?: $tclass,
                                );
                            }
                        }
                    }
                    continue;
                }

                if ( preg_match_all('/((?:ADR\/RID|ADR|RID|IMDG|IATA-DGR)(?:[\/.,\s]*(?:ADR\/RID|ADR|RID|IMDG|IATA-DGR))*)\s*[:\-]\s*(.+)/i', $line, $m, PREG_SET_ORDER) ) {
                    foreach ( $m as $match ) {
                        $standards_raw = $match[1];
                        $value_raw = trim( $match[2] );

                        $parts = preg_split('/\b(?:ADR\/RID|ADR|RID|IMDG|IATA-DGR)\b\s*[:\-]?/i', $value_raw);
                        if ( is_array( $parts ) && count( $parts ) > 0 ) {
                            $value_raw = trim( $parts[0] );
                        }

                        $stds = preg_split('/[\/ ,]+/', $standards_raw);
                        $stds = array_map( function( $s ) { return strtoupper( trim( $s ) ); }, $stds );

                        $un = '';
                        if ( preg_match('/UN\s*(\d{3,4})/i', $value_raw, $u) ) {
                            $un = 'UN' . $u[1];
                        }

                        $shipping = '';
                        if ( $un !== '' ) {
                            $shipping = preg_replace('/^.*?UN\s*\d{3,4}\s*,?\s*/i', '', $value_raw);
                        } else {
                            $shipping = preg_replace('/\b(Transport\b|Transport hazard class|Packing group|IMDG|IATA-DGR)\b.*/i', '', $value_raw);
                        }
                        $shipping = trim( preg_replace('/\s+/', ' ', $shipping) );

                        $pg = '';
                        if ( preg_match('/Packing group[^:]*[:\s]*([^,\n\r]+)/i', $value_raw, $pgm) ) {
                            $pg = strtoupper( trim( $pgm[1] ) );
                        } elseif ( preg_match('/\b(not applicable|n\/a)\b/i', $value_raw, $pgm2) ) {
                            $pg = strtoupper( $pgm2[1] );
                        }

                        $tclass = '';
                        if ( preg_match('/\bClass\s*(\d+(?:\.\d+)?)/i', $value_raw, $cm) ) {
                            $tclass = strtoupper( trim( $cm[1] ) );
                        }

                        foreach ( $stds as $std ) {
                            if ( $std === '' ) {
                                continue;
                            }
                            if ( ! isset( $result['by_standard'][ $std ] ) ) {
                                $result['by_standard'][ $std ] = array(
                                    'un' => $un,
                                    'shipping_name' => $shipping,
                                    'packing_group' => $pg,
                                    'transport_class' => $tclass,
                                );
                            } else {
                                $existing = $result['by_standard'][ $std ];
                                $result['by_standard'][ $std ] = array(
                                    'un' => $existing['un'] ?: $un,
                                    'shipping_name' => $existing['shipping_name'] ?: $shipping,
                                    'packing_group' => $existing['packing_group'] ?: $pg,
                                    'transport_class' => $existing['transport_class'] ?: $tclass,
                                );
                            }
                        }
                    }
                    continue;
                }

                if ( preg_match('/UN\s*(\d{3,4})\s*,?\s*(.+)/i', $line, $mm) ) {
                    $unval = 'UN' . $mm[1];
                    $ship = trim( $mm[2] );
                    if ( ! isset( $result['by_standard']['GENERIC'] ) ) {
                        $result['by_standard']['GENERIC'] = array(
                            'un' => $unval,
                            'shipping_name' => $ship,
                            'packing_group' => '',
                            'transport_class' => '',
                        );
                    }
                }
            }

            if ( preg_match_all('/UN\s*(\d{3,4})\s*,\s*([^\n\r]+)/i', $section, $un_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) ) {
                foreach ( $un_matches as $um ) {
                    $un_num = $um[1][0];
                    $ship_text = trim( $um[2][0] );
                    $ship_text = preg_replace('/UN\s*\d{3,4}\s*,?\s*/i', '', $ship_text);
                    $ship_text = preg_replace('/\b(?:ADR\/RID|ADR|RID|IMDG|IATA-DGR|Transport hazard class|Packing group|UN proper shipping name|UN)\b.*/i', '', $ship_text);
                    $ship_text = trim( $ship_text );

                    $pos = $um[0][1];
                    $look_back = substr( $section, max(0, $pos - 200), 200 );
                    if ( preg_match_all('/(ADR\/RID|IATA-DGR|IMDG|ADR|RID)/i', $look_back, $stds_found, PREG_OFFSET_CAPTURE) ) {
                        $last = end( $stds_found[0] );
                        $std_label = strtoupper( $last[0] );
                    } else {
                        $std_label = 'GENERIC';
                    }

                    if ( $ship_text === '' ) {
                        continue;
                    }

                    if ( ! isset( $result['by_standard'][ $std_label ] ) ) {
                        $result['by_standard'][ $std_label ] = array(
                            'un' => 'UN' . $un_num,
                            'shipping_name' => $ship_text,
                            'packing_group' => '',
                            'transport_class' => '',
                        );
                    } else {
                        if ( empty( $result['by_standard'][ $std_label ]['shipping_name'] ) ) {
                            $result['by_standard'][ $std_label ]['shipping_name'] = $ship_text;
                        }
                        if ( empty( $result['by_standard'][ $std_label ]['un'] ) ) {
                            $result['by_standard'][ $std_label ]['un'] = 'UN' . $un_num;
                        }
                    }
                }
            }

            return $result;
        }

        private function parse_shipping_name_matrix(): array {
            $section = $this->transport_section;
            if ( empty( $section ) ) {
                return array();
            }

            if ( ! preg_match('/\bUN proper shipping name\b(.+?)(?=\bTransport hazard class|Packing group|$)/is', $section, $match) ) {
                return array();
            }

            $block = trim( preg_replace('/\r\n?/', "\n", $match[1]) );
            $label_pattern = '/((?:ADR\/RID|ADR|RID|IMDG|IATA-DGR)(?:\s*,\s*(?:ADR\/RID|ADR|RID|IMDG|IATA-DGR))*)\s*:\s*/i';
            $names = array();
            $labels = array();

            if ( preg_match_all( $label_pattern, $block, $label_matches, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $label_matches[1] as $label ) {
                    $labels[] = strtoupper( trim( $label[0] ) );
                }
                $last_match = end( $label_matches[0] );
                $content_start = $last_match[1] + strlen( $last_match[0] );
                $content = trim( substr( $block, $content_start ) );
            } else {
                $content = $block;
            }

            if ( preg_match_all('/\bUN\s*(\d{3,4})\s*,\s*([^\n\r]+?)(?=(?:\bUN\s*\d{3,4}\b|$))/i', $content, $un_matches, PREG_SET_ORDER) ) {
                foreach ( $un_matches as $un_match ) {
                    $names[] = 'UN' . $un_match[1] . ', ' . $this->normalize_whitespace( $un_match[2] );
                }
            }

            if ( empty( $labels ) && ! empty( $names ) ) {
                return array( 'GENERIC' => implode( '; ', $names ) );
            }

            $result = array();
            $count = min( count( $labels ), count( $names ) );
            for ( $index = 0; $index < $count; $index++ ) {
                $result[ $labels[ $index ] ] = $names[ $index ];
            }

            return $result;
        }

        public function get_sds_transport_details(): array {
            return array(
                'regulated_material'     => $this->is_regulated_material(),
                'un_code'                => $this->get_un_code(),
                'shipping_name'          => $this->get_shipping_name(),
                'hazardous_class_number' => $this->get_hazardous_class_number(),
                'packing_group'          => $this->get_packing_group(),
                'shipping_class'         => $this->get_shipping_class(),
                'nmfc_code'              => $this->get_nmfc_code(),
                'transport_section'      => $this->get_section(),
            );
        }


        public function get_shipping_name(): string {
            $records = $this->get_transport_records();
            foreach ( $records as $record ) {
                if ( ! empty( $record['shipping_name'] ) ) {
                    return $record['shipping_name'];
                }
            }

            return '';
        }

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

        public function get_un_code(): string {
            
            $matrix = $this->parse_transport_matrix();
            if ( ! empty( $matrix['by_standard'] ) ) {
                if ( isset( $matrix['by_standard']['GENERIC'] ) && ! empty( $matrix['by_standard']['GENERIC']['un'] ) ) {
                    return $matrix['by_standard']['GENERIC']['un'];
                }

                $counts = array();
                foreach ( $matrix['by_standard'] as $data ) {
                    if ( ! empty( $data['un'] ) ) {
                        $counts[ $data['un'] ] = ( $counts[ $data['un'] ] ?? 0 ) + 1;
                    }
                }
                if ( ! empty( $counts ) ) {
                    arsort( $counts );
                    return array_key_first( $counts );
                }
            }

            $un_number = $this->extract_regex_value( '/\bUN(?: number)?[ \-]*([0-9]{3,4})\b/i', $this->transport_section );
            if ( ! $un_number ) {
                $un_number = $this->extract_regex_value( '/\bUN\s*([0-9]{3,4})\b/i', $this->transport_section );
            }
            return $un_number ? 'UN' . $un_number : '';
        }

	        public function is_regulated_material(): bool {
	            if ( empty( $this->transport_section ) ) {
	                return false;
	            }

	            $records = $this->get_transport_records();
	            foreach ( $records as $record ) {
	                if ( ! empty( $record['regulated_material'] ) ) {
	                    return true;
	                }
	            }

	            if ( '' !== $this->get_un_code() ) {
	                return true;
	            }

	            if ( $this->has_non_regulated_language( $this->transport_section ) ) {
	                return false;
	            }

	            return $this->has_regulated_exception_language( $this->transport_section );
	        }

        public function get_hazardous_class_number(): string {
            $matrix = $this->parse_transport_matrix();
            if ( ! empty( $matrix['by_standard'] ) ) {
                $order = array( 'IATA-DGR', 'ADR/RID', 'ADR', 'IMDG', 'RID' );
                foreach ( $order as $o ) {
                    if ( isset( $matrix['by_standard'][ $o ] ) && ! empty( $matrix['by_standard'][ $o ]['transport_class'] ) ) {
                        return $matrix['by_standard'][ $o ]['transport_class'];
                    }
                }
                foreach ( $matrix['by_standard'] as $data ) {
                    if ( ! empty( $data['transport_class'] ) ) {
                        return $data['transport_class'];
                    }
                }
            }

            $result = $this->extract_regex_value(
                '/\b(?:Transport hazard class\(es\)|Transport hazard class|hazard\s*(?:class|group)|hazardous\s*group|hazardous\s*class)\b[^0-9A-Za-z]*(\d+(?:\.\d+)?)/i',
                $this->transport_section
            );

            if ( empty( $result ) ) {
                $result = $this->extract_regex_value('/\bClass\s*(\d+(?:\.\d+)?)(?:\b|,|\s)/i', $this->transport_section );
            }

            return $result;
        }

        public function get_packing_group(): string {
            $matrix = $this->parse_transport_matrix();
            if ( ! empty( $matrix['by_standard'] ) ) {
                foreach ( $matrix['by_standard'] as $std => $data ) {
                    if ( ! empty( $data['packing_group'] ) && preg_match('/not applicable|n\/a/i', $data['packing_group'] ) ) {
                        return 'Not applicable';
                    }
                }
                foreach ( $matrix['by_standard'] as $data ) {
                    if ( ! empty( $data['packing_group'] ) ) {
                        $pg = $data['packing_group'];
                        if ( preg_match('/I{1,3}|II|III/i', $pg, $m) ) {
                            return strtoupper( $m[0] );
                        }
                        if ( preg_match('/\b(1|2|3)\b/', $pg, $m2) ) {
                            return $m2[1];
                        }
                        return ucfirst( strtolower( $pg ) );
                    }
                }
            }

            $group = $this->extract_regex_value('/\b(?:packing\s*group|pg\b)\b.*?[:\s]*([^,\n\r]+)/i', $this->transport_section );
            if ( $group ) {
                if ( preg_match('/\b(?:ADR\/RID|ADR|RID|IMDG|IATA-DGR)\b/i', $group) ) {
                    $pos = stripos( $this->transport_section, 'Packing group' );
                    if ( $pos !== false ) {
                        $snippet = substr( $this->transport_section, $pos, 600 );
                        if ( preg_match('/:\s*([^\n\r]+)/', $snippet, $mcol) ) {
                            $after = trim( $mcol[1] );
                        } elseif ( preg_match('/Packing group\s*[\r\n]+([^\n\r]+)/i', $snippet, $mcol2) ) {
                            $after = trim( $mcol2[1] );
                        } else {
                            $after = '';
                        }

                        if ( $after !== '' ) {
                            if ( preg_match('/\b(not applicable|n\/a)\b/i', $after, $m2) ) {
                                return 'Not applicable';
                            }
                            if ( preg_match('/\b(I{1,3}|II|III|1|2|3)\b/i', $after, $m3) ) {
                                return strtoupper( $m3[1] );
                            }
                            return ucfirst( strtolower( preg_replace('/[\.,].*/','', $after) ) );
                        }
                    }
                }

                if ( preg_match('/not applicable|n\/a/i', $group) ) {
                    return 'Not applicable';
                }
                return strtoupper( $group );
            }

            return '';
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
