<?php
/**
 * Notification suppression: runtime cosmetic layer.
 *
 * This class implements the nag/notice-suppression component of the cosmetic
 * layer described in brief § 3.6. It fires on admin_init — before the
 * admin_notices action — and removes the appropriate hooks depending on the
 * two independent config flags.
 *
 * TWO FLAGS, SUBSUMPTION RELATIONSHIP
 *
 *   suppress_nags    (broader)  — removes ALL callbacks on admin_notices and
 *                                 all_admin_notices via remove_all_actions().
 *   suppress_updates (narrower) — removes only update_nag (priority 3) and
 *                                 maintenance_nag (priority 10) from
 *                                 admin_notices via remove_action().
 *
 * When suppress_nags is true it subsumes suppress_updates: update nags are
 * registered as admin_notices callbacks, so remove_all_actions('admin_notices')
 * already catches them. Running the narrower remove_action calls afterward
 * would be harmless but redundant — this class skips them explicitly.
 *
 * GATING ASYMMETRY WITH ZSCH_Menu_Manager
 *
 * ZSCH_Menu_Manager uses a per-role hidden_menus map and does NOT consult
 * protected_roles — any role keyed in hidden_menus gets menus hidden.
 * ZSCH_Notifications has no per-role mapping: suppress_nags and
 * suppress_updates are global booleans. The role gate therefore uses
 * protected_roles, matching ZSCH_Admin_Bar rather than ZSCH_Menu_Manager.
 *
 * Like all cosmetic-layer classes, this class does NOT call
 * is_exempt_from_enforcement(). Protected users receive suppression
 * regardless of admin_roles status or activate_plugins hard floor.
 *
 * PRIORITIES
 *
 * WordPress core registers update_nag at priority 3 and maintenance_nag at
 * priority 10 on admin_notices. remove_action() requires the exact priority
 * used at registration; mismatched priorities silently fail.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ZSCH_Notifications
 */
class ZSCH_Notifications {

	/** @var ZSCH_Core */
	private $core;

	/**
	 * @param ZSCH_Core $core
	 */
	public function __construct( $core ) {
		$this->core = $core;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * admin_init fires before admin_notices, giving us a clean window to remove
	 * callbacks before they execute.
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'suppress_notifications' ) );
	}

	// -------------------------------------------------------------------------
	// Runtime suppression
	// -------------------------------------------------------------------------

	/**
	 * Remove admin notice callbacks based on the two suppression flags.
	 *
	 * Gating (cheapest-first):
	 *   1. Plugin disabled → return.
	 *   2. Both flags false → return (nothing to suppress).
	 *   3. Current user not in protected_roles → return.
	 *   4. Apply suppression.
	 *
	 * Suppression:
	 *   suppress_nags=true  → remove_all_actions on both notice hooks.
	 *                         Skips the narrower update removals (subsumed).
	 *   suppress_nags=false, suppress_updates=true
	 *                       → remove_action for update_nag (priority 3) and
	 *                         maintenance_nag (priority 10).
	 *
	 * MUST NOT call is_exempt_from_enforcement(), user_can(), or
	 * current_user_can(). Cosmetic layer applies unconditionally to protected
	 * users regardless of lockout-safeguard status.
	 */
	public function suppress_notifications() {
		if ( ! $this->core->get( 'enabled' ) ) {
			return;
		}

		$notifications  = $this->core->get( 'notifications' );
		$suppress_nags    = ! empty( $notifications['suppress_nags'] );
		$suppress_updates = ! empty( $notifications['suppress_updates'] );

		if ( ! $suppress_nags && ! $suppress_updates ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $this->core->is_protected_user( $user ) ) {
			return;
		}

		// Cosmetic layer: no is_exempt_from_enforcement() call — see docblock.

		if ( $suppress_nags ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			// suppress_updates is subsumed — skip the narrower removals.
			return;
		}

		// suppress_updates only.
		remove_action( 'admin_notices', 'update_nag', 3 );
		remove_action( 'admin_notices', 'maintenance_nag', 10 );
	}
}
