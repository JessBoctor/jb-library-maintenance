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

require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-library-clean-sweep.php';
require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-library-set-import-options.php';
//require_once JB_LIBRARY_MAINTENANCE_PLUGIN_DIR . 'jb-dlp-document-deduplication.php';