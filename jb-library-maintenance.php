<?php
/**
 * Plugin Name: JB Library Maintenance
 * Description: Extends the Document Library Pro plugin with features for long term maintenance.
 * Version: 1.0.0
 * Author: Jess Boctor
 * Author URI: https://jessboctor.com
 * Text Domain: jb-library-maintenance
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Plugin URI: https://github.com/JessBoctor/jb-library-maintenance
 * License: GPL2
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define( 'JB_LIBRARY_MAINTENANCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

define( 'JB_LIBRARY_STOCKCODE_PREFIX_TERMS',
    array(
        '08' => 'VACUUM BAG SUPPLIES',
        '09' => 'RESIN EMULSIFIERS',
        '10' => 'ACETONE',
        '11' => 'ANVIL SLEEVES',
        '12' => 'ABRASIVES',
        '14' => 'RESPERATORS & MASKS',
        '15' => 'CLOTH / FABRICS',
        '16' => 'BRUSHES / BUFF SPURS',
        '17' => 'TOOLING RUBBER',
        '18' => 'BUFFING PADS',
        '19' => 'CATALYST',
        '20' => 'DURATEC PRODUCTS',
        '21' => 'DISPOSABLE CLOTHING',
        '22' => 'TOOLING BOARD',
        '23' => 'ADHESIVES',
        '24' => 'FILLERS',
        '25' => 'POLYURETHANE FOAM',
        '26' => 'MAT',
        '27' => 'MAT/WOVEN ROVING/ETC',
        '29' => 'MIXING CUPS',
        '30' => 'RESINS',
        '32' => 'RAGS',
        '34' => 'ALUMINUM TRI HYDRATE',
        '35' => 'TAPE',
        '36' => 'ROLLERS',
        '37' => 'WAXES',
        '38' => 'SHOP/MFG SUPPLIES/FLM',
        '39' => 'SOLVENT',
        '40' => 'GEL COATS',
        '44' => 'FLUORO PAINTS',
        '57' => 'FCS FINS SETS',
        '59' => 'FIN BOXES',
        '80' => 'EXPOY PRODUCTS',
        '81' => 'EPOXY PRODUCTS (SYS)',
        '82' => 'CORE MATERIAL',
        'CR' => 'COMPOSITE RESOURCES',
        'XX' => 'EQUIPMENTS',
    )
);

// Include the composer autoloader
require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'vendor/autoload.php';

// Include the main plugin files
require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-library-clean-sweep.php';
require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-library-set-import-options.php';
require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-library-pdf-document-import.php';
require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-library-import-files.php';
require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-library-scrape-pdf-documents.php';
require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-library-pdf-clean-sweep.php';
require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-library-pdf-compare-stock-pdfs.php';
