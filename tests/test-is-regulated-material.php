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
    'generic not regulated values are dropped as noise' => array(
        'text' => '14. Transport information UN No.: Not regulated Proper shipping name: Not regulated Hazard Class: Not regulated Packing Group: Not regulated Environmental Hazards: Not applicable 15. Regulatory information',
        'expected' => array(
            'record_count' => 0,
        ),
    ),
    'not applicable section labels are not returned as transport values' => array(
        'text' => '14. Transport information General statements 14.1. UN number or ID number: Transport by road/by rail (ADR/RID) 14.2. UN proper shipping name: 14.3. Transport hazard class(es): 14.4. Packing group: Classification code: Not applicable n.a. Not applicable Not applicable Transport by sea (IMDG-code) 14.2. UN proper shipping name: 14.3. Transport hazard class(es): 14.4. Packing group: Marine Pollutant: Transport by air (IATA) 14.2. UN proper shipping name: 14.3. Transport hazard class(es): 14.4. Packing group: Non-dangerous material according to Transport Regulations. n.a. Not applicable n.a Not applicable 15. Regulatory information',
        'expected' => array(
            'record_count' => 0,
        ),
    ),
    'negative class statement does not create hazard class' => array(
        'text' => '14. Transport information UN Number Daphnia study has been conducted. Based on the study results, the product is not required to be labelled with the dead fish / dead tree symbol (GHS09), and is not considered as Class 9 in accordance with Transport of Dangerous Goods Regulations. This product is not dangerous to transport. UN proper shipping name This product is not dangerous to transport. Transport hazard class(es) This product is not dangerous to transport. Packing group This product is not dangerous to transport. 15. Regulatory information',
        'expected' => array(
            'record_count' => 0,
        ),
    ),
    'page number after non dangerous hazard class is ignored' => array(
        'text' => '14. Transport information General Not regulated. UN Number This product is not dangerous to transport. UN proper shipping name This product is not dangerous to transport. Transport hazard class(es) This product is not dangerous to transport. 7/10 Revision date: 5/1/2015 Packing group This product is not dangerous to transport. Environmental hazards Environmentally Hazardous Substance No. Special precautions for user This product is not dangerous to transport. 15. Regulatory information',
        'expected' => array(
            'record_count' => 0,
        ),
    ),
    'page metadata after agency shipping name is ignored' => array(
        'text' => '14. Transport information UN/NA Number: Not Regulated DOT SHIPPING NAME: Not Regulated DOT HAZARD CLASS: Not Regulated IMDG P.S.N.: Not Regulated GHS GT60P printed: 02/12/18 page 4 of 5 Able Industrial Products SAFETY DATA SHEET IMDG CLASS; Not Regulated IMDG PACKING GROUP: Not Regulated IATA - P.S.N./ Not Regulated IATA - CLASS: Not Regulated IATA PACKING GROUP: Not Regulated This product and its ingredients are NOT considered dangerous goods according to the UN Model Regulations. 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'shipping_name' => 'Not Regulated',
                'hazard_class' => '',
                'regulated_material' => false,
            ),
            array(
                'agency' => 'IMDG',
                'shipping_name' => 'Not Regulated',
                'hazard_class' => '',
                'regulated_material' => false,
            ),
            array(
                'agency' => 'IATA',
                'shipping_name' => 'Not Regulated',
                'hazard_class' => '',
                'regulated_material' => false,
            ),
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
    'inline labeled shipping description is parsed' => array(
        'text' => '14. Transport information DOT UN number UN1090 UN proper shipping name ACETONE Transport hazard class(es) Class 3 Subsidiary risk - Packing group II Special precautions for user Read safety instructions, SDS and emergency procedures before handling. ERG number 127 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1090',
                'shipping_name' => 'ACETONE',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
        ),
    ),
    'hyphenated UN number without prefix is parsed' => array(
        'text' => 'SECTION 14 : Transport information UN-Number 1090 UN proper shipping name Acetone Transport hazard class(es) Class: 3 Flammable liquids Packing group:II Environmental hazard: SECTION 15 :',
        'expected_records' => array(
            array(
                'agency' => 'GENERIC',
                'un_code' => 'UN1090',
                'shipping_name' => 'Acetone',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
        ),
    ),
    'section 14 without transport title can be non regulated' => array(
        'text' => 'SECTION 14 Not regulated per U.S. DOT, IATA or IMO. These transportation classifications are provided as a customer service. SECTION 15: REGULATORY INFORMATION',
        'expected' => array(
            'record_count' => 0,
        ),
    ),
    'agency list non regulated material is split' => array(
        'text' => 'SECTION 14 Transport Information UN-Number: DOT, ADR, ADN, IMDG, IATA Non-Regulated Material UN proper shipping name: DOT, ADR, ADN, IMDG, IATA Non-Regulated Material SECTION 15',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => '',
                'shipping_name' => 'Non-Regulated Material',
                'regulated_material' => false,
            ),
        ),
    ),
    'repeated column aerosol description is parsed' => array(
        'text' => 'Section 14. Transport information DOT TDG Mexico IATA Classification Classification Classification a UN1950 UN1950 UN1950 UN1950 UN proper AEROSOLS AEROSOLS AEROSOLS AEROSOLS, shipping name flammable Transport 2.1 2.1 2.1 2.1 hazard class(es) Environmental hazards No. Section 15',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1950',
                'shipping_name' => 'AEROSOLS, flammable',
                'hazard_class' => '2.1',
                'regulated_material' => true,
            ),
        ),
    ),
    'single line description without class comma is parsed' => array(
        'text' => 'SECTION 14) TRANSPORT INFORMATION U.S. DOT Information UN1993, Flammable Liquids, N.O.S. (Acetone, Methyl Acetate) 3, PG II Emergency Response Guide 128 SECTION 15) REGULATORY INFORMATION',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1993',
                'shipping_name' => 'Flammable Liquids, N.O.S. (Acetone, Methyl Acetate)',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
        ),
    ),
    'agency labeled grouped values are merged' => array(
        'text' => '14. Transport information UN Number UN No. (DOT) UN 3234 UN No. (IMDG) UN 3234 UN proper shipping name Proper shipping name (DOT)Self reactive solid, type C, temperature controlled Proper shipping name (IMDG) Self reactive solid, type C, temperature controlled Transport hazard class(es) DOT hazard class 4.1 DOT hazard label Flammable Solid IMDG Class 4.1 Transport labels Packing group DOT pack group II IMDG packing group II Environmental hazards Environmentally Hazardous Substance 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN3234',
                'shipping_name' => 'Self reactive solid, type C, temperature controlled',
                'hazard_class' => '4.1',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN3234',
                'shipping_name' => 'Self reactive solid, type C, temperature controlled',
                'hazard_class' => '4.1',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
        ),
    ),
    'agency labeled un no with spacing and hazard classes plural is parsed' => array(
        'text' => '14. Transport information In accordance with DOT Not regulated for transport ADR Transport document description : Transport by sea UN-No.( IMDG) : 3082 Proper Shipping Name (IMDG) : Environmently Hazarous substance (AQUATIC ENVIRONMENT), Liquid,N.O.S.(Epoxy Resin) EPOXY LAMINATING RESIN Safety Data Sheet Hazard Classes (IMDG) : 9 Packing group (IMDG) : III - Minor Danger Air transport UN-No.(IATA) : 3082 Proper Shipping Name (IATA) : Environmently Hazarous substance (AQUATIC ENVIRONMENT), Liquid,N.O.S.(Epoxy Resin) Hazard Classes (IATA) : 9 Packing group (IATA) : III - Minor Danger 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN3082',
                'shipping_name' => 'Environmently Hazarous substance (AQUATIC ENVIRONMENT), Liquid,N.O.S.(Epoxy Resin)',
                'hazard_class' => '9',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN3082',
                'shipping_name' => 'Environmently Hazarous substance (AQUATIC ENVIRONMENT), Liquid,N.O.S.(Epoxy Resin)',
                'hazard_class' => '9',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'incomplete header sequence does not pollute packing group' => array(
        'text' => 'Section 14. Transport information DOT /TDG / IMDG/IMO / ICAO/IATA and National regulations. UN number Proper shipping name Transport hazard class(es) Packing group UN1866 Resin Solution FO010-TBP-25 Date of issue: 04/15/2022 Page: 7 of 9 Section 14. Transport information Additional information US regulations require reporting spills Section 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1866',
                'shipping_name' => 'Resin Solution FO010-TBP-25',
                'hazard_class' => '',
                'packing_group' => '',
                'regulated_material' => true,
            ),
        ),
    ),
    'usa department of transport regulations is DOT' => array(
        'text' => 'Section 14. Transport information USA Department of Transport Regulations (DOT) UN proper shipping name: Petroleum distillates, n.o.s. Transport hazard class(es): 3 UN number: UN1268 Packing group, if applicable: III Hazard label: Flammable liquid Section 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1268',
                'shipping_name' => 'Petroleum distillates, n.o.s.',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'dot shipping name with shared un number is regulated' => array(
        'text' => '14. TRANSPORT INFORMATION UN/NA Number: UN1993. DOT SHIPPING NAME: FLAMMABLE LIQUID N.O.S. Silicone Mixture Contains Methanol DOT HAZARD CLASS: Flammable Liquid. IMDG P.S.N.: FLAMMABLE LIQUID N.O.S. (Methanol) IMDG CLASS: Class 3 IMDG PACKING GROUP: PGIII IATA - P.S.N.: FLAMMABLE LIQUID N.O.S. (Methanol) IATA - CLASS: Class 3 IATA PACKING GROUP: PGIII 15. REGULATORY INFORMATION',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1993',
                'shipping_name' => 'FLAMMABLE LIQUID N.O.S. Silicone Mixture Contains Methanol',
                'hazard_class' => '3',
                'regulated_material' => true,
            ),
        ),
    ),
    'stacked agency labels and values are parsed' => array(
        'text' => '14. Transport information USA: Department of Transportation (DOT) Identification number: Proper shipping name: Hazard class or Division: Labels: Special Provisions: Packaging Exceptions: Packaging Non-bulk: Packaging Bulk: Quantity limitations Passenger aircraft / rail: Quantity limitations Cargo only: Vessel stowage Location: Vessel stowage Other: Sea transport (IMDG) UN number: Proper shipping name: Class or division, Subsidary risk: Labels: EmS: Marine pollutant: Air transport (IATA) UN/ID number: Proper shipping name: Class or division, Subsidary risk: Labels: Emergency Response Guide-Code (ERG): UN1950 UN 1950, AEROSOLS 2.1 2.1 N82 306 None None 75 kg 150 kg A 25, 87, 126, 157 UN 1950 UN 1950, AEROSOLS Class 2.1, Subrisk - 2.1 F-D, S-U no UN 1950 UN 1950, AEROSOLS, FLAMMABLE Class 2.1 2.1 10L 203 203 75kg 150kg 10L ERG-Code 10L 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1950',
                'shipping_name' => 'AEROSOLS',
                'hazard_class' => '2.1',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN1950',
                'shipping_name' => 'AEROSOLS',
                'hazard_class' => '2.1',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN1950',
                'shipping_name' => 'AEROSOLS, FLAMMABLE',
                'hazard_class' => '2.1',
                'regulated_material' => true,
            ),
        ),
    ),
    'packing group ocr numerals are normalized' => array(
        'text' => "14. Transport information\nDOT\nUN-No UN2310\nProper Shipping Name PENTANE-2,4-DIONE\nHazard Class 3\nPacking Group Ml\nTDG\nUN-No UN2310\nProper Shipping Name PENTANE-2,4-DIONE\nHazard Class 3\nPacking Group Ill\nIATA\nUN-No UN2310\nProper Shipping Name PENTANE-2,4-DIONE\nHazard Class 3\nPacking Group Il\n15. Regulatory information",
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'TDG',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
        ),
    ),
    'vertical packing group value is parsed' => array(
        'text' => '14. Transport information DOT Regulated UNHD no UN1993 Proper Shipping Name Flammable liquids, n.o.s. Hazard class 3 Packing group Special Provisions Description Emergency Response Guide Number Special Precautions Ill B1, B52 UN1993, Flammable liquids, n.o.s.((Methanol)), 3, Ill 128 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1993',
                'shipping_name' => 'Flammable liquids, n.o.s.',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'class 2 material has no packing group' => array(
        'text' => '14. Transport information DOT UN number UN1950 UN proper shipping name AEROSOLS, flammable Transport hazard class(es) 2.1 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1950',
                'shipping_name' => 'AEROSOLS, flammable',
                'hazard_class' => '2.1',
                'packing_group' => 'Class 2 - Not applicable',
                'regulated_material' => true,
            ),
        ),
    ),
    'hazard class and packing group can indicate regulated when un code is missed' => array(
        'text' => '14. Transport information DOT proper shipping name Adhesives, containing a flammable liquid Transport hazard class(es) DOT Hazard Class: 3 Packing group II 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => '',
                'shipping_name' => 'Adhesives, containing a flammable liquid',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
        ),
    ),
    'label noise with not applicable values is not regulated' => array(
        'text' => '14. Transport information 14.1. UN number not applicable 14.2. UN proper shipping name not applicable 14.3. Transport hazard class(es) not applicable 14.4. Packing group not applicable 15. Regulatory information',
        'expected' => array(
            'record_count' => 0,
        ),
    ),
    'single unique section packing group can fill a missing agency value' => array(
        'text' => '14. TRANSPORT INFORMATION UN/NA Number: UN1993. DOT SHIPPING NAME: FLAMMABLE LIQUID N.O.S. Silicone Mixture Contains Methanol DOT HAZARD CLASS: Flammable Liquid. IMDG P.S.N.: FLAMMABLE LIQUID N.O.S. (Methanol) IMDG CLASS: Class 3 IMDG PACKING GROUP: PGIII IATA P.S.N.: FLAMMABLE LIQUID N.O.S. (Methanol) IATA CLASS: Class 3 IATA PACKING GROUP: PGIII 15. REGULATORY INFORMATION',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1993',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'numbered section agency labels are merged into agency rows' => array(
        'text' => '14. Transport information In accordance with ADR / RID / IMDG / IATA / ADN 14.1. UN number UN-No. (ADR) : UN 1866 UN-No. (IMDG) : UN 1866 UN-No. (IATA) : UN 1866 UN-No. (ADN) : Not applicable UN-No. (RID) : UN 1866 14.2. UN proper shipping name Proper Shipping Name (ADR) : paint Proper Shipping Name (IMDG) : RESIN SOLUTION Proper Shipping Name (IATA) : Resin solution Proper Shipping Name (ADN) : Not applicable Proper Shipping Name (RID) : Not applicable Transport document description (ADR) : UN 1866 paint, 3, II, (D/E) Transport document description (IMDG) : UN 1866 RESIN SOLUTION, 3, II Transport document description (IATA) : UN 1866 Resin solution, 3, II Transport document description (RID) : UN 1866 , 3 14.3. Transport hazard class(es) ADR Transport hazard class(es) (ADR) : 3 IMDG Transport hazard class(es) (IMDG) : 3 IATA Transport hazard class(es) (IATA) : 3 ADN Transport hazard class(es) (ADN) : Not applicable RID Transport hazard class(es) (RID) : 3 14.4. Packing group Packing group (ADR) : II Packing group (IMDG) : II Packing group (IATA) : II Packing group (ADN) : Not applicable Packing group (RID) : Not applicable 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'ADR',
                'un_code' => 'UN1866',
                'shipping_name' => 'paint',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN1866',
                'shipping_name' => 'Resin solution',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'RID',
                'un_code' => 'UN1866',
                'shipping_name' => '',
                'hazard_class' => '3',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
        ),
    ),
    'numbered section datapoints grouped by agency label are expanded' => array(
        'text' => '14. Transport information 14.1. UN number UN No. (ADR/RID) 3105 UN No. (IMDG) 3105 UN No. (ICAO) 3105 UN No. (ADN) 3105 14.2. UN proper shipping name Proper Shipping name (IMDG) ORGANIC PEROXIDE TYPE D, LIQUID Proper Shipping name (ICAO) ORGANIC PEROXIDE TYPE D, LIQUID Proper Shipping name (ADN) ORGANIC PEROXIDE TYPE D, LIQUID 14.3. Transport hazard class(es) ADR/RID class 5.2 ADR/RID label 5.2 IMDG class 5.2 ICAO class/division 5.2 ADN class 5.2 14.4. Packing group Not applicable. 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'ADR',
                'un_code' => 'UN3105',
                'shipping_name' => 'ORGANIC PEROXIDE TYPE D, LIQUID',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'RID',
                'un_code' => 'UN3105',
                'shipping_name' => 'ORGANIC PEROXIDE TYPE D, LIQUID',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN3105',
                'shipping_name' => 'ORGANIC PEROXIDE TYPE D, LIQUID',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'ICAO',
                'un_code' => 'UN3105',
                'shipping_name' => 'ORGANIC PEROXIDE TYPE D, LIQUID',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'ADN',
                'un_code' => 'UN3105',
                'shipping_name' => 'ORGANIC PEROXIDE TYPE D, LIQUID',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
        ),
    ),
    'stacked regulatory table values are parsed before label-only text' => array(
        'text' => 'SECTION 14. TRANSPORT INFORMATION International Regulations UNRTDG UN number Proper shipping name Class Packing group Labels IATA-DGR UN/ID No. Proper shipping name Class Packing group Labels IMDG-Code UN number Proper shipping name Class Packing group Labels UN 3105 ORGANIC PEROXIDE TYPE D, LIQUID (ACETYL ACETONE PEROXIDE) 5.2 Not assigned by regulation 5.2 UN 3105 Organic peroxide type D, liquid (Acetyl acetone peroxide) 5.2 Not assigned by regulation Organic Peroxides, Keep Away From Heat 570 570 UN 3105 ORGANIC PEROXIDE TYPE D, LIQUID (ACETYL ACETONE PEROXIDE) 5.2 Not assigned by regulation 5.2 F-J, S-R no Domestic regulation 49 CFR UN/ID/NA number : UN 3105 Proper shipping name : Organic peroxide type D, liquid (Acetyl Acetone Peroxide, <=42%) Class : 5.2 Packing group : Not assigned by regulation Labels : ORGANIC PEROXIDE ERG Code > 145 Marine pollutant > no Special precautions for user',
        'expected_records' => array(
            array(
                'agency' => 'GENERIC',
                'un_code' => 'UN3105',
                'shipping_name' => 'ORGANIC PEROXIDE TYPE D, LIQUID (ACETYL ACETONE PEROXIDE)',
                'hazard_class' => '5.2',
                'packing_group' => 'Not assigned by regulation',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN3105',
                'shipping_name' => 'Organic peroxide type D, liquid (Acetyl acetone peroxide)',
                'hazard_class' => '5.2',
                'packing_group' => 'Not assigned by regulation',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN3105',
                'shipping_name' => 'ORGANIC PEROXIDE TYPE D, LIQUID (ACETYL ACETONE PEROXIDE)',
                'hazard_class' => '5.2',
                'packing_group' => 'Not assigned by regulation',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'DOT',
                'un_code' => 'UN3105',
                'shipping_name' => 'Organic peroxide type D, liquid (Acetyl Acetone Peroxide, <=42%)',
                'hazard_class' => '5.2',
                'packing_group' => 'Not assigned by regulation',
                'regulated_material' => true,
            ),
        ),
    ),
    'numbered label lists with delayed values are parsed' => array(
        'text' => 'SECTION 14. Transport information 14.1. UN number UN No. (ADR/RID) UN No. (IMDG) UN No. (ICAO) UN No. (ADN) disposal text before values 3105 3105 3105 3105 14.2. UN proper shipping name Proper Shipping name (ADR/RID) Proper Shipping name (IMDG) Proper Shipping name (ICAO) Proper Shipping name (ADN) ORGANIC PEROXIDE TYPE D, LIQUID (Reaction mass of dihydroperoxide) ORGANIC PEROXIDE TYPE D, LIQUID (Reaction mass of dihydroperoxide) ORGANIC PEROXIDE TYPE D, LIQUID (Reaction mass of dihydroperoxide) ORGANIC PEROXIDE TYPE D, LIQUID (Reaction mass of dihydroperoxide) 14,3, Transport hazard class(es) ADR/RID class ADR/RID label IMDG class ICAO class/division Transport labels 6.2 14.4. Packing group Not applicable. 14,5. Environmental hazards 5.2 5.2 5.2 5.2 14.6. Special precautions for user',
        'expected_records' => array(
            array(
                'agency' => 'ADR',
                'un_code' => 'UN3105',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'RID',
                'un_code' => 'UN3105',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN3105',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'ICAO',
                'un_code' => 'UN3105',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'ADN',
                'un_code' => 'UN3105',
                'hazard_class' => '5.2',
                'packing_group' => 'Not applicable',
                'regulated_material' => true,
            ),
        ),
    ),
    'generic transport values fill agency classification rows' => array(
        'text' => "14. Transport information\nUN Number: UN1133.\nUN Proper Shipping Name: Adhesives (DOT), Flammable Liquid.\nTransport Hazard Class: 3.\nPacking Group: II\nDOT Classification: UN1133, Adhesives, Flammable Liquid, Hazard Class 3, Packing Group II, Limited Quantity.\nADR / RID Classification: Class 3 Flammable Liquid.\nICAO/IATA Classification: Class 3 Flammable Liquid.\nIMO/IMDG Classification: Class 3 Flammable Liquid.\nMarine Pollutant: No additional information.\n15. Regulatory information",
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1133',
                'shipping_name' => 'Adhesives (DOT), Flammable Liquid',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'ADR',
                'un_code' => 'UN1133',
                'shipping_name' => 'Adhesives (DOT), Flammable Liquid',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'ICAO',
                'un_code' => 'UN1133',
                'shipping_name' => 'Adhesives (DOT), Flammable Liquid',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN1133',
                'shipping_name' => 'Adhesives (DOT), Flammable Liquid',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
        ),
    ),
    'bordered agency table values are parsed' => array(
        'text' => '14. Transport information Resin solution 3 III RESIN SOLUTION 3 III Resin solution UN1866 3 III UN1866 UN1866 Reportable quantity 3106. DOT Classification IMDG IATA UN number UN proper shipping name Transport hazard class(es) Packing group Additional information Environmental hazards TDG Classification UN1866 RESIN SOLUTION 3 III No. Product classified as per the following sections of the Transportation of Dangerous Goods Regulations: 2.18-2.19 (Class 3). ADR/RID UN1866 RESIN SOLUTION 3 III No. Hazard identification number 30 Mexico Classification UN1866 RESINA, SOLUCIONES DE 3 III No. Special provisions 223 Section 15',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1866',
                'shipping_name' => 'Resin solution',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN1866',
                'shipping_name' => 'Resin solution',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'TDG',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'ADR',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'NOM',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESINA, SOLUCIONES DE',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'multimodal dash labeled ocr values are parsed from model regulation line' => array(
        'text' => '14. Transport information UN-Number DOT, IMDG, IATA UNI133 - UN proper shipping name - DOT, IATA Adhesives -IMDG ADHESIVES : Transport hazard class(es) : Class 3 Flammable liquids - Label 3 IMDG, IATA 3 Flammable liquids 3 : Packing group - DOT, IMDG, IATA IT - Environmental hazards: Not applicable. -UN "Model Regulation": UN 1133 ADHESIVES, 3, IT SECTION 15',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1133',
                'shipping_name' => 'ADHESIVES',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN1133',
                'shipping_name' => 'ADHESIVES',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN1133',
                'shipping_name' => 'ADHESIVES',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
        ),
    ),
    'hazardous agency statuses inherit shared dot classification' => array(
        'text' => '14. Transport information US DOT Shipping Classification Proper Shipping Name: TERPENE HYDROCARBONS, N.O.S. Hazard Class: 3 Identification No.: UN2319 Packing Group: III Label/Placard: exception applies. TDG Status: Hazardous IMO Status: Hazardous IATA Status: Hazardous The listed transportation classification does not address regulatory variations. 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN2319',
                'shipping_name' => 'TERPENE HYDROCARBONS, N.O.S',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'TDG',
                'un_code' => 'UN2319',
                'shipping_name' => 'TERPENE HYDROCARBONS, N.O.S',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN2319',
                'shipping_name' => 'TERPENE HYDROCARBONS, N.O.S',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN2319',
                'shipping_name' => 'TERPENE HYDROCARBONS, N.O.S',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'spanish transport labels are parsed' => array(
        'text' => 'SECCI N 14: Informaci n del transporte In accordance with DOT N ONU (DOT) :UN1866 DOT Designaci n Oficial de Transporte :RESIN SOLUTION Departamento de Transporte (DOT) Clases de Peligro :3 - Clase 3 - Liquido inflamable Grupo de embalaje (DOT) :III - Riesgo pequeno ADR Descripci n del documento del transporte :UN 1866, 3, III, (D/E) Grupo de embalaje (ADR) :III Clase (ADR) :3 - Liquido inflamable N ONU (IMDG) :1866 Designaci n Oficial de Transporte (IMDG) :RESIN SOLUTION Clase (IMDG) :3 - Liquido inflamable Grupo de embalaje (IMDG) :III - substances presenting low danger Air transport N ONU (IATA) :1866 Designaci n Oficial de Transporte (IATA) :RESIN SOLUTION Clase (IATA) :3 - Liquido inflamable Grupo de embalaje (IATA) :III SECCI N 15: Informaci n reglamentaria',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'ADR',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'compact agency list with spaced UNI code is parsed' => array(
        'text' => '14. Transport information UN-Number : DOT, IMDG, IATA UNI 866 - UN proper shipping name As stated below: -DOT Resin solution - IMDG, IATA RESIN SOLUTION - Transport hazard class(es) 3 Flammable liquids - Packing group -DOT - Environmental hazards: - Marine pollutant: 15. Regulatory information',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1866',
                'shipping_name' => 'Resin solution',
                'hazard_class' => '3',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'regulated_material' => true,
            ),
        ),
    ),
    'transport title before section number is found' => array(
        'text' => 'Disposal text TRANSPORT INFORMATION14 Non DOT/RCRA regulated REGULATORY INFORMATION15',
        'expected' => array(
            'record_count' => 0,
        ),
    ),
    'legacy dot proper shipping line is parsed' => array(
        'text' => 'SECTION I PRODUCT IDENTIFICATION PRODUCT DESCRIPTION: MOLD RELEASE SEALER DOT PROPER SHIPPING NAME: RESIN SOLUTION, 3, UN1866, PGIII NFPA CODES: FLAMMABILITY-3 HEALTH-2 SECTION II HAZARDOUS INGREDIENTS',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN1866',
                'shipping_name' => 'RESIN SOLUTION',
                'hazard_class' => '3',
                'packing_group' => 'III',
                'regulated_material' => true,
            ),
        ),
    ),
    'legacy dot proper shipping line with leading code is parsed' => array(
        'text' => 'sEcTloN 14) TRANSPORT TNFORMATTON U.S. DOT lnformation: NA19B7, Denatured Alcohol, 3 PG tt Emergency Response Guide 127 sEcTtoN 15) REGULATORY TNFORMATTON',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'NA1987',
                'shipping_name' => 'Denatured Alcohol',
                'hazard_class' => '3',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
        ),
    ),
    'un number agency block without transport heading is parsed' => array(
        'text' => 'Water hazard class 2 - UN-Number | DOT, IMDG, IATA UN3267 - UN proper shipping name - DOT Corrosive liquid, basic, organic, n.o.s. -IMDG, IATA CORROSIVE LIQUID, BASIC, ORGANIC, N.O.S. - Transport hazard class(es) 8 Corrosive substances 8 8 Corrosive substances 8 - Packing group - DOT, IMDG, IATA II',
        'expected_records' => array(
            array(
                'agency' => 'DOT',
                'un_code' => 'UN3267',
                'shipping_name' => 'Corrosive liquid, basic, organic, n.o.s.',
                'hazard_class' => '8',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IMDG',
                'un_code' => 'UN3267',
                'shipping_name' => 'CORROSIVE LIQUID, BASIC, ORGANIC, N.O.S.',
                'hazard_class' => '8',
                'packing_group' => 'II',
                'regulated_material' => true,
            ),
            array(
                'agency' => 'IATA',
                'un_code' => 'UN3267',
                'shipping_name' => 'CORROSIVE LIQUID, BASIC, ORGANIC, N.O.S.',
                'hazard_class' => '8',
                'packing_group' => 'II',
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
