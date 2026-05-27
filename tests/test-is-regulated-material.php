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
);

$errors = array();
foreach ( $cases as $label => $case ) {
    $extractor = new JB_PDF_Transport_Extractor( $case['text'] );
    $records = $extractor->get_transport_records();
    $actual = array( 'record_count' => count( $records ) );

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
