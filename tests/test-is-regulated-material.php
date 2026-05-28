<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../jb-library-transport-extractor.php';

$cases = array(
    'UN code is regulated' => array(
        'text' => "14. Transport information UN number ADR/RID, IMDG, IATA-DGR: UN 1950 UN proper shipping name ADR/RID, IMDG: IATA-DGR: UN 1950, AEROSOLS UN 1950, AEROSOLS, FLAMMABLE Transport hazard class(es) ADR/RID: IMDG: IATA-DGR: Class 2, Code: 5F Class 2.1, Subrisk - Class 2.1 Packing group ADR/RID, IMDG, IATA-DGR: not applicable printed by Airtech Advanced Materials Group with Qualisys SUMDAT SAFETY DATA SHEET according to 29 CFR 1910.1200 and ANSI standard Z400.1-2010 Airtac 2C Material number 1191 2/10/2023 Revision date: 1.0 Version: 0.0 Replaces version: Language: en-US Date of first version: 2/10/2023 Page: 9 of 11 Environmental hazards Marine pollutant: no Transport in bulk according to Annex II of MARPOL 73/78 and the IBC Code No data available USA: Department of Transportation (DOT)",
        'expected' => array(
            'record_count' => 1,
            'un_code' => 'UN1950',
            'regulated_material' => true,
        ),
    ),
    'explicit not regulated is not regulated' => array(
        'text' => '14. Transport information DOT not regulated TDG not regulated IMDG not regulated IATA not regulated 15. Regulatory information',
        'expected' => array(
            'record_count' => 4,
            'un_code' => '',
            'regulated_material' => false,
        ),
    ),
    'not restricted is not regulated' => array(
        'text' => '14. Transport information USA: Department of Transportation (DOT) Proper shipping name: Not restricted 15. Regulatory information',
        'expected' => array(
            'record_count' => 1,
            'agency' => 'DOT',
            'un_code' => '',
            'regulated_material' => false,
        ),
    ),
    'limited quantity is regulated exception' => array(
        'text' => '14. Transport information DOT Limited Quantity 15. Regulatory information',
        'expected' => array(
            'record_count' => 1,
            'agency' => 'DOT',
            'un_code' => '',
            'regulated_material' => true,
        ),
    ),
    'consumer commodity is regulated exception' => array(
        'text' => '14. Transport information IATA Proper Shipping Name: Consumer Commodity 15. Regulatory information',
        'expected' => array(
            'record_count' => 1,
            'agency' => 'IATA',
            'un_code' => '',
            'shipping_name' => 'Consumer Commodity',
            'regulated_material' => true,
        ),
    ),
    'grouped agencies keep agency-specific transport section' => array(
        'text' => "14. Transport information\nDOT\nUN Number: UN1993\nProper Shipping Name: Flammable liquids\nHazard class: 3\nPacking Group: II\nIATA\nNot regulated\n15. Regulatory information",
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1993',
                'regulated_material' => true,
                'transport_section' => 'DOT UN Number: UN1993 Proper Shipping Name: Flammable liquids Hazard class: 3 Packing Group: II',
            ),
            array(
                'agency' => 'IATA',
                'un_code' => '',
                'regulated_material' => false,
                'transport_section' => 'IATA Not regulated',
            ),
        ),
    ),
    'agency paragraph shipping description is parsed' => array(
        'text' => '14. Transport information DOT (USA) Class 9, Packing Group III when material is shipped in quantities in one package at or above the Reportable Quantity and when no other hazard class applies; otherwise, not regulated. Reportable Quantity: 4.5 kg (dibutyl phthalate) Marine pollutant.: dibutyl phthalate Possible Shipping Description(s): not regulated UN 3082 Environmentally hazardous substances, liquid, n.o.s. (dibutyl phthalate) 9 III Sea - IMDG (International Maritime Dangerous Goods) Marine pollutant.: (dibutyl phthalate) Possible Shipping Description(s): UN 3082 ENVIRONMENTALLY HAZARDOUS SUBSTANCE, LIQUID, N.O.S. (dibutyl phthalate) 9 III 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN3082',
                'shipping_name' => 'Environmentally hazardous substances, liquid, n.o.s. (dibutyl phthalate)',
                'hazard_class' => '9',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'single line shipping description is parsed' => array(
        'text' => '14. Transport information DOT (Department of Transportation) : UN3082, Environmentally hazardous substances, liquid, n.o.s., (BIS(2-ETHYLHEXYL) PHTHALATE), 9, lll 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN3082',
                'shipping_name' => 'Environmentally hazardous substances, liquid, n.o.s., (BIS(2-ETHYLHEXYL) PHTHALATE)',
                'hazard_class' => '9',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'single line NA combustible liquid description is parsed' => array(
        'text' => '14. Transport information DOT (Department of Transportation): NA1993, Combustible liquid, n.o.s., (PROPANOL, 1(OR 2)-(2-METHOXYMETHYLETHOXY) ), CBL, III 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'NA1993',
                'shipping_name' => 'Combustible liquid, n.o.s., (PROPANOL, 1(OR 2)-(2-METHOXYMETHYLETHOXY) )',
                'hazard_class' => 'CBL',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
);

$errors = array();
foreach ( $cases as $label => $case ) {
    $extractor = new JB_PDF_Transport_Extractor( $case['text'] );
    $records = $extractor->get_transport_records();
    $actual = array( 'record_count' => count( $records ) );

    if ( isset( $case['expected_records'] ) ) {
        foreach ( $case['expected_records'] as $index => $expected_record ) {
            $record = $records[ $index ] ?? array();
            foreach ( $expected_record as $key => $value ) {
                if ( ( $record[ $key ] ?? null ) !== $value ) {
                    $errors[] = sprintf(
                        '%s record %d: Expected %s to be %s, got %s',
                        $label,
                        $index,
                        $key,
                        var_export( $value, true ),
                        var_export( $record[ $key ] ?? null, true )
                    );
                }
            }
        }

        continue;
    }

    foreach ( $records as $record ) {
        foreach ( $case['expected'] as $key => $value ) {
            if ( 'record_count' === $key ) {
                continue;
            }

            if ( ! array_key_exists( $key, $actual ) && array_key_exists( $key, $record ) ) {
                $actual[ $key ] = $record[ $key ];
            }
        }
    }

    foreach ( $case['expected'] as $key => $value ) {
        if ( $actual[ $key ] !== $value ) {
            $errors[] = sprintf(
                '%s: Expected %s to be %s, got %s',
                $label,
                $key,
                var_export( $value, true ),
                var_export( $actual[ $key ], true )
            );
        }
    }
}

if (!empty($errors)) {
    echo "TEST FAILED:\n";
    foreach ($errors as $error) {
        echo "- $error\n";
    }
    exit(1);
}

echo "TEST PASSED\n";
exit(0);
