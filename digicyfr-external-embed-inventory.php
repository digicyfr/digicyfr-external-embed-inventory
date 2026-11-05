<?php
/**
 * Plugin URI: https://github.com/digicyfr/digicyfr-external-embed-inventory
 * Plugin Name: Digicyfr External Embed Inventory
 * Description: A private, local inventory of external references found in saved post and page content.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Digicyfr
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: digicyfr-external-embed-inventory
 * Domain Path: /languages
 * Update URI: https://digicyfr.com/plugins/digicyfr-external-embed-inventory/
 *
 * @package Digicyfr_External_Embed_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DEEI_VERSION', '0.1.0' );
define( 'DEEI_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEEI_URL', plugin_dir_url( __FILE__ ) );

require_once DEEI_PATH . 'includes/class-deei-url.php';
require_once DEEI_PATH . 'includes/class-deei-scanner.php';
require_once DEEI_PATH . 'includes/class-deei-storage.php';
require_once DEEI_PATH . 'includes/class-deei-run.php';
require_once DEEI_PATH . 'includes/class-deei-admin.php';

register_activation_hook( __FILE__, array( 'DEEI_Storage', 'install' ) );
if ( is_admin() ) {
	DEEI_Admin::boot();
}
