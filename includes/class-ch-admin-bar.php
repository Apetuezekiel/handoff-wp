<?php
/**
 * Admin bar simplification: runtime cosmetic layer.
 *
 * This class implements the admin-bar-simplification component of the cosmetic
 * layer described in brief § 3.4. It fires after WordPress has fully populated
 * the admin bar and removes every node not in the keep set.
 *
 * GATING ASYMMETRY WITH CH_Menu_Manager
 *
 * CH_Menu_Manager uses its own per-role hidden_menus map and does NOT gate on
 * protected_roles — any role whose slug appears as a key in hidden_menus gets
 * menus hidden regardless of protected_roles.
 *
 * CH_Admin_Bar has no per-role mapping. Simplification is a single global
 * on/off (admin_bar.simplify) applied to all protected users. Because there is
 * no per-role key to drive removal, the gate MUST consult protected_roles to
 * determine whether the current user is a candidate for simplification.
 *
 * In both cases the cosmetic layer does NOT call is_exempt_from_enforcement().
 * Protected users receive the cosmetic treatment regardless of lockout-safeguard
 * status (user ID 1, admin_roles, activate_plugins hard floor).
 *
 * HOOK: wp_before_admin_bar_render fires after WP_Admin_Bar::initialize() and
 * all 'add_action wp_before_admin_bar_render' callbacks have run, meaning the
 * bar is fully populated. Nodes are walked via get_nodes() and removed via
 * remove_node() on the global $wp_admin_bar.
 *
 * KEEP SET:
 *   DEFAULT_KEEP_NODES — always kept when admin_bar.allowed_nodes is empty.
 *   'my-account' is kept because the logout link lives inside it as a sub-node.
 *   Surgically removing only the logout child while keeping other my-account
 *   sub-nodes is a post-MVP concern.
 *
 *   If admin_bar.allowed_nodes is non-empty it takes precedence over the
 *   default, allowing developers to customise the keep set without code changes.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CH_Admin_Bar
 */
class CH_Admin_Bar {

	/**
	 * Default set of node IDs that survive simplification.
	 *
	 * Written to allowed_nodes by the settings UI when a developer chooses the
	 * default preset — not expanded at runtime (this constant is the runtime
	 * fallback when allowed_nodes is empty).
	 *
	 * @var string[]
	 */
	const DEFAULT_KEEP_NODES = array( 'site-name', 'edit', 'my-account' );

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
	 * wp_before_admin_bar_render fires after the bar is fully populated, so the
	 * removal pass sees every node added by WordPress, themes, and plugins.
	 */
	public function register_hooks() {
		add_action( 'wp_before_admin_bar_render', array( $this, 'simplify_admin_bar' ) );
	}

	// -------------------------------------------------------------------------
	// Runtime simplification
	// -------------------------------------------------------------------------

	/**
	 * Remove admin bar nodes that are not in the keep set.
	 *
	 * Gating (cheapest-first):
	 *   1. Plugin disabled → return.
	 *   2. admin_bar.simplify is false → return.
	 *   3. Current user not in protected_roles → return.
	 *
	 * MUST NOT call is_exempt_from_enforcement(), user_can(), or
	 * current_user_can(). Cosmetic layer applies unconditionally to protected
	 * users regardless of lockout-safeguard status.
	 *
	 * Keep set: admin_bar.allowed_nodes if non-empty, else DEFAULT_KEEP_NODES.
	 */
	public function simplify_admin_bar() {
		if ( ! $this->core->get( 'enabled' ) ) {
			return;
		}

		$admin_bar_config = $this->core->get( 'admin_bar' );
		$simplify         = isset( $admin_bar_config['simplify'] ) ? (bool) $admin_bar_config['simplify'] : false;

		if ( ! $simplify ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $this->core->is_protected_user( $user ) ) {
			return;
		}

		// Cosmetic layer: no is_exempt_from_enforcement() call — see docblock.

		$allowed = isset( $admin_bar_config['allowed_nodes'] ) && is_array( $admin_bar_config['allowed_nodes'] )
			? $admin_bar_config['allowed_nodes']
			: array();

		$keep = ! empty( $allowed ) ? $allowed : self::DEFAULT_KEEP_NODES;

		global $wp_admin_bar;

		foreach ( $wp_admin_bar->get_nodes() as $id => $node ) {
			if ( ! in_array( (string) $id, $keep, true ) ) {
				$wp_admin_bar->remove_node( $id );
			}
		}
	}
}
