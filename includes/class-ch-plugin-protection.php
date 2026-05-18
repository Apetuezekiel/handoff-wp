<?php
/**
 * Plugin protection: action-link removal + admin_init request intercept.
 *
 * This class implements the plugin-protection component of the enforcement
 * layer described in brief § 3.4. Two responsibilities:
 *
 *   1. Action-link removal (plugin_action_links filter): strips the 'deactivate'
 *      and 'delete' keys from the $actions array for protected, non-exempt users
 *      when the target plugin is in enforcement.protected_plugins.
 *
 *   2. Admin-init intercept (admin_init action): blocks deactivation and deletion
 *      requests even if the action-link UI was bypassed. Four request shapes:
 *        - action=deactivate          (single)       — plugin= in $_REQUEST
 *        - action=deactivate-selected (bulk)         — checked[] in $_REQUEST
 *        - action=delete-selected     (single)       — checked[] with one entry
 *        - action=delete-selected     (bulk)         — checked[] with many entries
 *      One protected plugin in a bulk checked[] is sufficient to die.
 *
 * FILTER CHOICE — generic plugin_action_links rather than per-plugin
 * plugin_action_links_{$plugin_file}: the generic filter receives every plugin
 * in a single callback, so one registration covers all entries in
 * protected_plugins without requiring a loop of per-plugin registrations or
 * re-registration on config change.
 *
 * RECURSION CONSTRAINT (brief § 3.4): neither callback calls current_user_can()
 * or user_can(). Both use wp_get_current_user() for the user object and
 * CH_Core's recursion-safe helpers for role/capability checks.
 *
 * DIE MESSAGE: die_blocked() duplicates CH_Enforcer::die_blocked(). These two
 * classes share no base class or trait in Phase 1 (premature for two callers).
 * If a third enforcement class needs the same helper, factor it then.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CH_Plugin_Protection
 */
class CH_Plugin_Protection {

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
	 * plugin_action_links fires once per plugin row in the Plugins list table.
	 * admin_init fires on every admin request before the specific page handler.
	 */
	public function register_hooks() {
		add_filter( 'plugin_action_links', array( $this, 'filter_plugin_action_links' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'intercept_plugin_action' ) );
	}

	// -------------------------------------------------------------------------
	// Action-link removal
	// -------------------------------------------------------------------------

	/**
	 * Remove 'deactivate' and 'delete' action links for protected plugins.
	 *
	 * Early-return order (cheapest-first):
	 *   1. Plugin disabled → return untouched.
	 *   2. Plugin not in protected_plugins → return untouched. This cheap array
	 *      lookup is placed before any user/role checks so that the forty-eight
	 *      unprotected plugins in a typical list table incur no user or role I/O.
	 *   3. User not in protected_roles → return untouched.
	 *   4. User is exempt from enforcement → return untouched.
	 *
	 * All other action keys ('activate', 'edit', etc.) are always preserved.
	 *
	 * @param array  $actions     Associative array of action link HTML strings.
	 * @param string $plugin_file Plugin basename (e.g. 'akismet/akismet.php').
	 * @return array Modified $actions.
	 */
	public function filter_plugin_action_links( $actions, $plugin_file ) {
		if ( ! $this->core->get( 'enabled' ) ) {
			return $actions;
		}

		$protected_plugins = $this->get_protected_plugins();

		if ( ! in_array( $plugin_file, $protected_plugins, true ) ) {
			return $actions;
		}

		$user = wp_get_current_user();

		if ( ! $this->core->is_protected_user( $user ) ) {
			return $actions;
		}

		if ( $this->core->is_exempt_from_enforcement( $user ) ) {
			return $actions;
		}

		unset( $actions['deactivate'], $actions['delete'] );

		return $actions;
	}

	// -------------------------------------------------------------------------
	// Admin-init request intercept
	// -------------------------------------------------------------------------

	/**
	 * Block deactivation/deletion requests targeting a protected plugin.
	 *
	 * Handles four request shapes (brief § 3.4):
	 *   - action=deactivate         — single; plugin basename in $_REQUEST['plugin']
	 *   - action=deactivate-selected — bulk; basenames in $_REQUEST['checked']
	 *   - action=delete-selected    — single or bulk; basenames in $_REQUEST['checked']
	 *
	 * For bulk shapes (checked[]), one entry in protected_plugins is sufficient
	 * to die — the entire request is blocked.
	 *
	 * Early-return order (cheapest-first):
	 *   1. Action not in the four shapes → return (regex-free string match).
	 *   2. Plugin disabled → return.
	 *   3. User not in protected_roles → return.
	 *   4. User is exempt → return.
	 *   5. protected_plugins list is empty → return.
	 *   6. Request targets no protected plugin → return.
	 *
	 * MUST NOT call user_can() or current_user_can() — see recursion constraint
	 * in the file docblock and brief § 3.4.
	 */
	public function intercept_plugin_action() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Intercept fires before WP's own nonce check; we only inspect the action name.

		if ( ! in_array( $action, array( 'deactivate', 'deactivate-selected', 'delete-selected' ), true ) ) {
			return;
		}

		if ( ! $this->core->get( 'enabled' ) ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $this->core->is_protected_user( $user ) ) {
			return;
		}

		if ( $this->core->is_exempt_from_enforcement( $user ) ) {
			return;
		}

		$protected_plugins = $this->get_protected_plugins();

		if ( empty( $protected_plugins ) ) {
			return;
		}

		if ( 'deactivate' === $action ) {
			$plugin = isset( $_REQUEST['plugin'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above; plugin basenames contain slashes that sanitize_key would strip.
			if ( in_array( $plugin, $protected_plugins, true ) ) {
				$this->die_blocked();
				return; // Unreachable after wp_die(); explicit for static analysis.
			}
		} else {
			// action=deactivate-selected or action=delete-selected.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Intercept fires before WP's own nonce check; we only inspect plugin basenames against protected_plugins.
			$checked = isset( $_REQUEST['checked'] ) && is_array( $_REQUEST['checked'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['checked'] ) )
				: array();
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			foreach ( $checked as $plugin ) {
				if ( in_array( (string) $plugin, $protected_plugins, true ) ) {
					$this->die_blocked();
					return; // Unreachable after wp_die(); explicit for static analysis.
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Return the enforcement.protected_plugins list from config.
	 *
	 * @return string[]
	 */
	private function get_protected_plugins() {
		$enforcement = $this->core->get( 'enforcement' );
		return isset( $enforcement['protected_plugins'] ) && is_array( $enforcement['protected_plugins'] )
			? $enforcement['protected_plugins']
			: array();
	}

	/**
	 * Kill the page load with a localized blocked-action message.
	 *
	 * Duplicated from CH_Enforcer::die_blocked() — see the "DIE MESSAGE" note in
	 * the file docblock for why this is intentional rather than factored out.
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
