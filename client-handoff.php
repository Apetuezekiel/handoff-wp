<?php
/**
 * Plugin Name:       Client Handoff
 * Plugin URI:        https://github.com/zicstack/client-handoff
 * Description:       Structured developer-to-client handoff: guided setup flow, client operational dashboard, contextual help notes, developer checklist, and enforced role-based restrictions.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ezekiel / Zicstack
 * Author URI:        https://zicstack.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       client-handoff
 * Domain Path:       /languages
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Manual includes — no Composer, no autoloader.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ch-core.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ch-enforcer.php';

// ---- Plugin constants -------------------------------------------------------
// Defined after the include so they can mirror CH_Core class constants,
// making CH_Core the single source of truth for option/hook names.

define( 'CH_VERSION',          '0.1.0' );
define( 'CH_PLUGIN_FILE',      __FILE__ );
define( 'CH_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'CH_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'CH_OPTION_CONFIG',    CH_Core::OPTION_CONFIG );
define( 'CH_OPTION_LOG',       CH_Core::OPTION_LOG );
define( 'CH_OPTION_CHECKLIST', CH_Core::OPTION_CHECKLIST );
define( 'CH_CRON_PRUNE_LOG',   CH_Core::CRON_PRUNE_LOG );
define( 'CH_CPT_HELP_NOTE',    CH_Core::CPT_HELP_NOTE );
define( 'CH_TEXT_DOMAIN',      CH_Core::TEXT_DOMAIN );

// ---- Lifecycle hooks --------------------------------------------------------
register_activation_hook( __FILE__, array( 'CH_Core', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'CH_Core', 'on_deactivation' ) );

// ---- Feature hooks ----------------------------------------------------------
// Enforcement hooks registered on plugins_loaded so WordPress is fully
// bootstrapped before we read roles and user data.
add_action( 'plugins_loaded', static function () {
	$core     = CH_Core::get_instance();
	$enforcer = new CH_Enforcer( $core );
	$enforcer->register_hooks();
} );
