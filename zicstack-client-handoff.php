<?php
/**
 * Plugin Name:       Zicstack Client Handoff
 * Plugin URI:        https://github.com/Apetuezekiel/handoff-wp
 * Description:       Structured developer-to-client handoff: guided setup flow, client operational dashboard, contextual help notes, developer checklist, and enforced role-based restrictions.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ezekiel / Zicstack
 * Author URI:        https://zicstack.co
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zicstack-client-handoff
 * Domain Path:       /languages
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Manual includes — no Composer, no autoloader.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-core.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-enforcer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-plugin-protection.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-menu-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-admin-bar.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-notifications.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-setup-flow.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-import-export.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zsch-dashboard.php';

// ---- Plugin constants -------------------------------------------------------
// Defined after the include so they can mirror ZSCH_Core class constants,
// making ZSCH_Core the single source of truth for option/hook names.

define( 'ZSCH_VERSION',          '0.1.0' );
define( 'ZSCH_PLUGIN_FILE',      __FILE__ );
define( 'ZSCH_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'ZSCH_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'ZSCH_OPTION_CONFIG',    ZSCH_Core::OPTION_CONFIG );
define( 'ZSCH_OPTION_LOG',       ZSCH_Core::OPTION_LOG );
define( 'ZSCH_OPTION_CHECKLIST', ZSCH_Core::OPTION_CHECKLIST );
define( 'ZSCH_CRON_PRUNE_LOG',   ZSCH_Core::CRON_PRUNE_LOG );
define( 'ZSCH_CPT_HELP_NOTE',    ZSCH_Core::CPT_HELP_NOTE );
define( 'ZSCH_TEXT_DOMAIN',      ZSCH_Core::TEXT_DOMAIN );

// ---- Lifecycle hooks --------------------------------------------------------
register_activation_hook( __FILE__, array( 'ZSCH_Core', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'ZSCH_Core', 'on_deactivation' ) );

// ---- Feature hooks ----------------------------------------------------------
// Enforcement hooks registered on plugins_loaded so WordPress is fully
// bootstrapped before we read roles and user data. user_has_cap can fire
// from plugins_loaded onward; current_screen fires later in the admin.
add_action( 'plugins_loaded', static function () {
	$core          = ZSCH_Core::get_instance();
	$enforcer      = new ZSCH_Enforcer( $core );
	$protection    = new ZSCH_Plugin_Protection( $core );
	$menu_manager  = new ZSCH_Menu_Manager( $core );
	$admin_bar     = new ZSCH_Admin_Bar( $core );
	$notifications = new ZSCH_Notifications( $core );
	$setup_flow    = new ZSCH_Setup_Flow( $core );
	$settings      = new ZSCH_Admin_Settings( $core, $setup_flow );
	$dashboard      = new ZSCH_Dashboard( $core );
	$import_export  = new ZSCH_Import_Export( $core, $settings );
	$enforcer->register_hooks();
	$protection->register_hooks();
	$menu_manager->register_hooks();
	$admin_bar->register_hooks();
	$notifications->register_hooks();
	$settings->register_hooks();
	$dashboard->register_hooks();
	$import_export->register_hooks();
} );
