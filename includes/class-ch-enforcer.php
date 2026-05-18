<?php
/**
 * Enforcement layer: capability filter + screen guard.
 *
 * This class implements the two enforcement components described in brief § 3.4:
 *
 *   1. Capability filter (user_has_cap) — the PRIMARY authority boundary.
 *      Strips codebase/theme-altering capabilities from protected users at
 *      runtime. Never persists changes to role definitions in the database.
 *
 *   2. Screen guard (current_screen) — defense-in-depth / navigation UX.
 *      Prevents protected users from landing on explicitly blocklisted screens.
 *      This is NOT the security layer — the capability filter is. The screen
 *      guard does not protect POST endpoints (options.php, admin-post.php,
 *      admin-ajax.php); the capability filter handles those.
 *
 * Plugin protection (plugin_action_links + admin_init intercept) lives in the
 * separate class-ch-plugin-protection.php file, not here.
 *
 * RECURSION CONSTRAINT (brief § 3.4): The user_has_cap callback MUST NOT call
 * user_can() or current_user_can() — those re-trigger this filter and cause
 * infinite recursion. All user/capability checks use CH_Core's recursion-safe
 * helpers, which read wp_roles() directly.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CH_Enforcer
 */
class CH_Enforcer {

	/**
	 * Screen ID of the plugin's own settings page.
	 *
	 * WordPress generates this ID from a top-level menu page registered with
	 * the slug 'client-handoff' (add_menu_page). Must match what
	 * class-ch-admin-settings.php registers. The settings page is always
	 * permitted — it must never be blockable.
	 */
	const SETTINGS_SCREEN_ID = 'toplevel_page_client-handoff';

	/**
	 * Core set of always-permitted screen IDs.
	 *
	 * These screens are NEVER blocked by the screen guard, even if they appear
	 * in a role's screen_blocklist (brief § 3.4 always-permitted set). Extended
	 * at runtime via the 'client_handoff_permitted_screens' filter, which the
	 * dashboard class uses to add quick_link screen IDs when it is built.
	 *
	 * @var string[]
	 */
	private static $core_permitted = array(
		'index.php',   // Dashboard — always reachable.
		'profile.php', // User profile — always reachable.
	);

	/** @var CH_Core */
	private $core;

	/**
	 * @param CH_Core $core
	 */
	public function __construct( $core ) {
		$this->core = $core;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * user_has_cap fires on every page (front-end + admin) whenever any
	 * capability is checked. current_screen fires only in the admin.
	 * Both hooks are registered unconditionally; the callbacks perform their
	 * own enabled/protected/exempt checks and return cheaply when not needed.
	 */
	public function register_hooks() {
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
		add_action( 'current_screen', array( $this, 'guard_current_screen' ) );
	}

	// -------------------------------------------------------------------------
	// Capability filter
	// -------------------------------------------------------------------------

	/**
	 * Strip blocked capabilities for protected, non-exempt users.
	 *
	 * Early-return paths (in cheapest-first order):
	 *   - Plugin disabled → return untouched.
	 *   - User not in protected_roles → return untouched.
	 *   - User is exempt from enforcement → return untouched.
	 *
	 * MUST NOT call user_can() or current_user_can() at any point — see the
	 * recursion constraint in the file docblock and brief § 3.4.
	 *
	 * @param array   $allcaps All capabilities currently granted to the user.
	 * @param array   $caps    Primitive capabilities required for the operation.
	 * @param array   $args    [0] requested cap, [1] user ID, [2...] extra args.
	 * @param WP_User $user    The user being checked.
	 * @return array Modified $allcaps (caps stripped for protected, non-exempt users).
	 */
	public function filter_user_has_cap( array $allcaps, array $caps, array $args, $user ) {
		// Cheapest check first: skip entirely if the plugin is disabled.
		if ( ! $this->core->get( 'enabled' ) ) {
			return $allcaps;
		}

		// Skip users who are not in any protected_role.
		if ( ! $this->core->is_protected_user( $user ) ) {
			return $allcaps;
		}

		// Lockout safeguards: admin roles, activate_plugins hard floor, ID 1,
		// and multisite super-admins are all exempt from enforcement.
		// These checks use CH_Core's recursion-safe helpers — no user_can() calls.
		if ( $this->core->is_exempt_from_enforcement( $user ) ) {
			return $allcaps;
		}

		// Strip each blocked capability from the runtime caps array.
		// This never modifies the database — it is a per-request filter only.
		$enforcement  = $this->core->get( 'enforcement' );
		$blocked_caps = isset( $enforcement['blocked_caps'] ) && is_array( $enforcement['blocked_caps'] )
			? $enforcement['blocked_caps']
			: array();

		foreach ( $blocked_caps as $cap ) {
			unset( $allcaps[ $cap ] );
		}

		return $allcaps;
	}

	// -------------------------------------------------------------------------
	// Screen guard
	// -------------------------------------------------------------------------

	/**
	 * Block a protected, non-exempt user from accessing a blocklisted screen.
	 *
	 * This is navigation UX / defense-in-depth, NOT the authority boundary.
	 * It prevents clients from landing on confusing admin screens. POST endpoints
	 * (options.php, admin-post.php, admin-ajax.php) do not fire current_screen,
	 * so this method never runs for them — the capability filter handles those.
	 *
	 * The always-permitted set (index.php, profile.php, settings page, and any
	 * IDs added via the 'client_handoff_permitted_screens' filter) is never
	 * blocked even if explicitly listed in a role's screen_blocklist.
	 *
	 * @param WP_Screen $screen Current screen object.
	 */
	public function guard_current_screen( $screen ) {
		if ( ! $this->core->get( 'enabled' ) ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $this->core->is_protected_user( $user ) ) {
			return;
		}

		// Exempt users pass through — same lockout safeguards as the cap filter.
		if ( $this->core->is_exempt_from_enforcement( $user ) ) {
			return;
		}

		$screen_id = $screen->id;

		// Always-permitted screens are never blocked, regardless of blocklist content.
		if ( $this->is_always_permitted( $screen_id ) ) {
			return;
		}

		// Check each of the user's roles against the per-role blocklist.
		$enforcement      = $this->core->get( 'enforcement' );
		$screen_blocklist = isset( $enforcement['screen_blocklist'] ) && is_array( $enforcement['screen_blocklist'] )
			? $enforcement['screen_blocklist']
			: array();

		foreach ( $user->roles as $role ) {
			if ( isset( $screen_blocklist[ $role ] ) &&
				 in_array( $screen_id, $screen_blocklist[ $role ], true ) ) {
				$this->die_blocked();
				return; // Unreachable after wp_die(); explicit for static analysis.
			}
		}
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Returns true if $screen_id is in the always-permitted set.
	 *
	 * The always-permitted set consists of:
	 *   - The core set defined in $core_permitted (index.php, profile.php).
	 *   - The plugin's own settings page (SETTINGS_SCREEN_ID constant).
	 *   - Any IDs added via the 'client_handoff_permitted_screens' filter.
	 *     The dashboard class uses this filter to register quick_link screen IDs.
	 *
	 * @param string $screen_id
	 * @return bool
	 */
	private function is_always_permitted( $screen_id ) {
		$permitted   = self::$core_permitted;
		$permitted[] = self::SETTINGS_SCREEN_ID;

		/**
		 * Filters the set of always-permitted screen IDs.
		 *
		 * Use this filter to add screen IDs that must never be blocked by the
		 * screen guard, even if present in a role's screen_blocklist. The
		 * dashboard class adds quick_link screen IDs here when it is built.
		 *
		 * @param string[] $permitted Screen IDs that are always permitted.
		 */
		$permitted = apply_filters( 'client_handoff_permitted_screens', $permitted );

		return in_array( $screen_id, (array) $permitted, true );
	}

	/**
	 * Kill the page load with a localized blocked-screen message.
	 *
	 * Includes the developer's contact info from config so clients know who
	 * to reach when they hit a restricted screen (brief § 3.4).
	 */
	private function die_blocked() {
		$dashboard = $this->core->get( 'dashboard' );
		$contact   = isset( $dashboard['developer_contact'] ) ? $dashboard['developer_contact'] : array();
		$name      = isset( $contact['name'] )  ? $contact['name']  : '';
		$email     = isset( $contact['email'] ) ? $contact['email'] : '';
		$url       = isset( $contact['url'] )   ? $contact['url']   : '';

		$message = '<p>' . esc_html( __( 'You do not have access to this page.', 'zicstack-client-handoff' ) ) . '</p>';

		if ( $name || $email || $url ) {
			$message .= '<p>' . esc_html( __( 'For assistance, contact:', 'zicstack-client-handoff' ) ) . ' ';

			if ( $name && $email ) {
				$message .= esc_html( $name ) . ' &mdash; <a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
			} elseif ( $name ) {
				$message .= esc_html( $name );
			} elseif ( $email ) {
				$message .= '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
			}

			if ( $url ) {
				$message .= ' &mdash; <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
			}

			$message .= '</p>';
		}

		wp_die(
			wp_kses_post( $message ),
			esc_html( __( 'Access Restricted', 'zicstack-client-handoff' ) ),
			array( 'response' => 403 )
		);
	}
}
