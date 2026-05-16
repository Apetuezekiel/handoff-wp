<?php
/**
 * Core: config loading, role resolution, lockout safeguards.
 *
 * This is the highest-risk component — every feature class depends on it.
 * The lockout safeguards defined here are enforcement-only and non-overridable
 * by configuration (brief § 3.4, Constraint 11.3).
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CH_Core
 */
class CH_Core {

	// ---- Name constants — single source of truth ----------------------------
	// Mirrored as plugin-level defines in client-handoff.php so external code
	// can reference them without coupling to this class.

	const OPTION_CONFIG    = 'client_handoff_config';
	const OPTION_LOG       = 'client_handoff_activity_log';
	const OPTION_CHECKLIST = 'client_handoff_checklist';
	const CRON_PRUNE_LOG   = 'client_handoff_prune_log';
	const CPT_HELP_NOTE    = 'ch_help_note';
	const TEXT_DOMAIN      = 'client-handoff';

	/**
	 * Activation defaults — deliberately inert.
	 *
	 * A freshly activated plugin does nothing until the developer configures it.
	 * enabled=false means no user is ever restricted or redirected.
	 */
	const DEFAULTS = array(
		'enabled'         => false,
		'setup_completed' => false,
		'protected_roles' => array(),
		'admin_roles'     => array(),
		'menu_hiding'     => array(
			'hidden_menus' => array(),
			'preset'       => null,
		),
		'enforcement'     => array(
			'screen_blocklist'  => array(),
			// Default blocked-cap set: everything that lets a user alter the
			// site codebase or the active theme. Runtime-only — never persisted
			// to role definitions. See brief § 3.4.
			'blocked_caps'      => array(
				'install_plugins',
				'activate_plugins',
				'delete_plugins',
				'edit_plugins',
				'update_plugins',
				'install_themes',
				'switch_themes',
				'delete_themes',
				'edit_themes',
				'update_themes',
				'update_core',
			),
			'protected_plugins' => array(),
		),
		'dashboard'       => array(
			'enabled'           => false,
			'welcome_message'   => '',
			'quick_links'       => array(),
			'developer_contact' => array(
				'name'  => '',
				'email' => '',
				'url'   => '',
			),
			'show_site_status'  => true,
		),
		'admin_bar'       => array(
			'simplify'      => false,
			'allowed_nodes' => array(),
		),
		'notifications'   => array(
			'suppress_updates' => false,
			'suppress_nags'    => false,
		),
		'logging'         => array(
			'enabled'        => false,
			'max_entries'    => 100,
			'retention_days' => 30,
		),
	);

	/** @var CH_Core|null */
	private static $instance = null;

	/** @var array Full config, merged against DEFAULTS on load. */
	private $config = array();

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * @return CH_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Destroy the singleton — for unit tests only.
	 *
	 * @internal
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/** Private constructor — use get_instance(). */
	private function __construct() {
		$this->load_config();
	}

	// -------------------------------------------------------------------------
	// Config loading
	// -------------------------------------------------------------------------

	/**
	 * Read the saved option and merge against inert DEFAULTS.
	 */
	private function load_config() {
		$saved = get_option( self::OPTION_CONFIG, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$this->config = self::deep_merge( $saved, self::DEFAULTS );
	}

	/**
	 * Recursively merge $saved into $defaults.
	 *
	 * Rules:
	 * - Non-empty assoc arrays on both sides → recurse (preserves defaults for
	 *   keys missing from $saved, drops unknown keys from $saved for safety).
	 * - Everything else (scalar, sequential list, empty array, type mismatch)
	 *   → use $saved value wholesale. Empty arrays and sequential arrays are
	 *   treated as data containers (lists / dynamic dicts) that must not have
	 *   their inner structure merged against defaults.
	 *
	 * @param array $saved    Values from the stored option.
	 * @param array $defaults Default values to fill in.
	 * @return array
	 */
	private static function deep_merge( array $saved, array $defaults ) {
		$result = $defaults;

		foreach ( $saved as $key => $value ) {
			// Drop unknown keys — prevents arbitrary data injection and makes
			// forward-compatibility explicit (new keys must be added to DEFAULTS).
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			$default = $defaults[ $key ];

			if (
				is_array( $default ) && is_array( $value ) &&
				self::is_assoc( $default ) && self::is_assoc( $value )
			) {
				// Both are non-empty assoc arrays → recurse.
				$result[ $key ] = self::deep_merge( $value, $default );
			} else {
				// Scalar, sequential list, empty array, or type mismatch:
				// treat the saved value as authoritative.
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Returns true only for non-empty associative (string-keyed) arrays.
	 *
	 * Empty arrays are treated as sequential (data containers) so that dynamic
	 * structures like screen_blocklist (keyed by role slug at runtime) are
	 * never merged against their empty defaults — they must be used wholesale.
	 *
	 * @param array $arr
	 * @return bool
	 */
	private static function is_assoc( array $arr ) {
		if ( empty( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Return the full config array.
	 *
	 * @return array
	 */
	public function get_config() {
		return $this->config;
	}

	/**
	 * Return a single top-level config value.
	 *
	 * @param string $key
	 * @return mixed|null
	 */
	public function get( $key ) {
		return isset( $this->config[ $key ] ) ? $this->config[ $key ] : null;
	}

	/**
	 * Persist a new config state and reload.
	 *
	 * NOT a sanitization boundary. This method merges the incoming array against
	 * DEFAULTS and persists the result, but it does not sanitize individual field
	 * values (e.g. it will not strip tags from welcome_message or validate that
	 * role slugs exist). Callers — specifically class-ch-admin-settings.php —
	 * are responsible for sanitizing every field before calling this method.
	 *
	 * @param array $new_config Sanitized config array from the settings form.
	 */
	public function update_config( array $new_config ) {
		$this->config = self::deep_merge( $new_config, self::DEFAULTS );
		update_option( self::OPTION_CONFIG, $this->config, 'yes' );
	}

	// -------------------------------------------------------------------------
	// Role resolution
	// -------------------------------------------------------------------------

	/**
	 * Returns true if $user holds at least one role in protected_roles.
	 *
	 * Returns false when the plugin is disabled (enabled=false) or when
	 * protected_roles is empty — inert by default.
	 *
	 * NOTE: This method gates on the `enabled` flag. A disabled plugin treats
	 * every user as unprotected, so no enforcement or cosmetic restrictions apply.
	 * Compare with is_admin_user(), which intentionally does NOT gate on `enabled`
	 * — see that method's docblock for the rationale.
	 *
	 * @param WP_User $user
	 * @return bool
	 */
	public function is_protected_user( $user ) {
		if ( empty( $this->config['enabled'] ) || empty( $this->config['protected_roles'] ) ) {
			return false;
		}

		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $this->config['protected_roles'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns true if $user holds at least one role in admin_roles.
	 *
	 * INTENTIONAL ASYMMETRY WITH is_protected_user(): this method does NOT gate
	 * on the `enabled` flag. Admin exemption must be independent of plugin state
	 * for two reasons:
	 *   1. is_exempt_from_enforcement() calls is_admin_user() as a lockout
	 *      safeguard. Safeguards must always fire — gating them on `enabled`
	 *      would create a window where re-enabling the plugin momentarily
	 *      bypasses the admin-role precedence rule.
	 *   2. The cosmetic layer (menu hiding) applies to configured roles regardless
	 *      of lockout-safeguard status (brief § 3.4). is_admin_user() is the
	 *      signal for that layer too; tying it to `enabled` would break cosmetic
	 *      hiding for admin users when the plugin is enabled.
	 *
	 * @param WP_User $user
	 * @return bool
	 */
	public function is_admin_user( $user ) {
		if ( empty( $this->config['admin_roles'] ) ) {
			return false;
		}

		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $this->config['admin_roles'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify a user as 'admin', 'protected', or 'neither'.
	 *
	 * Admin status always takes precedence over protected status when a user
	 * holds roles in both lists (brief § 3.4 admin_roles precedence rule).
	 *
	 * @param WP_User $user
	 * @return string 'admin'|'protected'|'neither'
	 */
	public function get_user_status( $user ) {
		if ( $this->is_admin_user( $user ) ) {
			return 'admin';
		}

		if ( $this->is_protected_user( $user ) ) {
			return 'protected';
		}

		return 'neither';
	}

	// -------------------------------------------------------------------------
	// Lockout safeguards — enforcement layer only, structurally non-overridable
	// -------------------------------------------------------------------------

	/**
	 * Returns true if $user must be exempted from ALL enforcement restrictions.
	 *
	 * These checks apply ONLY to the enforcement layer (capability filtering,
	 * screen guard, plugin protection). They do NOT exempt from the cosmetic
	 * layer (menu hiding, admin bar, notification suppression) — brief § 3.4.
	 *
	 * The checks are structural and cannot be disabled by configuration.
	 *
	 * @param WP_User $user
	 * @return bool
	 */
	public function is_exempt_from_enforcement( $user ) {
		// User ID 1 is unconditionally exempt.
		if ( 1 === (int) $user->ID ) {
			return true;
		}

		// Multisite super-admins are unconditionally exempt.
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return true;
		}

		// Admin role precedence: any admin_role match exempts regardless of
		// other role memberships (e.g. a user who is both subscriber and editor
		// where editor is an admin_role must be exempt).
		if ( $this->is_admin_user( $user ) ) {
			return true;
		}

		// Hard floor: never enforce against a user whose unfiltered role
		// definition already grants activate_plugins. Checked via the
		// recursion-safe helper — must not use user_can() here.
		if ( $this->user_has_cap_unfiltered( $user, 'activate_plugins' ) ) {
			return true;
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Recursion-safe capability check
	// -------------------------------------------------------------------------

	/**
	 * Check whether $user holds $capability in their unfiltered role definition.
	 *
	 * MUST NOT call user_can() or current_user_can() — those re-trigger the
	 * user_has_cap filter and cause infinite recursion when this method is called
	 * from inside a user_has_cap callback. Reads $user->roles and wp_roles()
	 * directly (brief § 3.4, Implementation note).
	 *
	 * @param WP_User $user
	 * @param string  $capability
	 * @return bool
	 */
	public function user_has_cap_unfiltered( $user, $capability ) {
		$wp_roles = wp_roles();

		foreach ( $user->roles as $role_name ) {
			$role = $wp_roles->get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			if ( ! empty( $role->capabilities[ $capability ] ) ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Multisite detection
	// -------------------------------------------------------------------------

	/**
	 * Returns true when this plugin is active network-wide on a multisite install.
	 *
	 * Network-wide activation is unsupported at MVP (Constraint 11.11). Feature
	 * classes must call this and gracefully no-op when it returns true.
	 *
	 * @return bool
	 */
	public function is_network_activated() {
		if ( ! is_multisite() ) {
			return false;
		}

		// CH_PLUGIN_FILE is defined in client-handoff.php. Guard against test
		// environments where the main plugin file is not loaded.
		if ( ! defined( 'CH_PLUGIN_FILE' ) ) {
			return false;
		}

		$active_sitewide = get_site_option( 'active_sitewide_plugins', array() );
		$basename        = plugin_basename( CH_PLUGIN_FILE );

		return isset( $active_sitewide[ $basename ] );
	}

	// -------------------------------------------------------------------------
	// Lifecycle hooks
	// -------------------------------------------------------------------------

	/**
	 * Activation hook handler.
	 *
	 * Called by register_activation_hook() in client-handoff.php.
	 * Writes the inert default config (only if no config exists yet) and
	 * registers the activity-log pruning cron.
	 *
	 * @param bool $network_wide True when activated network-wide on multisite.
	 */
	public static function on_activation( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			// Network-wide activation is unsupported. Store a transient so the
			// next admin page load can display an admin notice (the hook fires
			// too early for direct admin_notices output).
			set_transient( 'ch_network_activation_notice', true, MINUTE_IN_SECONDS );
			return;
		}

		// Write default config only on first activation; preserve any existing
		// config (e.g. re-activation after a temporary deactivation).
		if ( false === get_option( self::OPTION_CONFIG ) ) {
			update_option( self::OPTION_CONFIG, self::DEFAULTS, 'yes' );
		}

		// Register cron for bounded activity-log pruning (brief § 3.5).
		if ( ! wp_next_scheduled( self::CRON_PRUNE_LOG ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_PRUNE_LOG );
		}
	}

	/**
	 * Deactivation hook handler.
	 *
	 * Non-destructive: clears the cron hook only. Config and log data are
	 * preserved so re-activation restores the prior configuration.
	 * Data removal happens exclusively in uninstall.php.
	 */
	public static function on_deactivation() {
		wp_clear_scheduled_hook( self::CRON_PRUNE_LOG );
	}
}
