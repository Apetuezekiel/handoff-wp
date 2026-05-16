<?php
/**
 * Unit tests for CH_Notifications (nag/notice suppression, cosmetic layer).
 *
 * WP_Mock limitation: add_action() is an expectation assertion only — the hook
 * pipeline is never fired. All tests call suppress_notifications() directly.
 *
 * Tracking pattern: remove_all_actions and remove_action are replaced by
 * closures that record their argument tuples into reference arrays. Assertions
 * inspect those arrays directly — no 'times' => 0 or assertTrue(true).
 *
 * Cosmetic-layer independence (Test 7): suppress_notifications() must NOT call
 * is_exempt_from_enforcement(). The test intentionally omits wp_roles and
 * is_multisite mocks — if the method calls those, WP_Mock errors on the
 * unexpected function call, turning the architectural constraint into a test
 * failure. This is the active enforcement pattern from MenuManagerTest 7/8
 * and AdminBarTest 6/7.
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class NotificationsTest
 */
class NotificationsTest extends TestCase {

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public function setUp(): void {
		parent::setUp();
		CH_Core::reset_instance();
	}

	public function tearDown(): void {
		CH_Core::reset_instance();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a CH_Core instance backed by the given saved config.
	 *
	 * @param array $config
	 * @return CH_Core
	 */
	private function make_core( array $config = array() ) {
		WP_Mock::userFunction( 'get_option', array( 'return' => $config ) );
		return CH_Core::get_instance();
	}

	/**
	 * @param int      $id
	 * @param string[] $roles
	 * @return WP_User
	 */
	private function make_user( $id, array $roles ) {
		return new WP_User( $id, $roles );
	}

	/**
	 * Register tracked mocks for remove_all_actions and remove_action.
	 *
	 * @param array $all_calls  Reference — each entry is the hook string passed to remove_all_actions.
	 * @param array $single_calls Reference — each entry is [hook, callback, priority].
	 */
	private function mock_remove_functions( array &$all_calls, array &$single_calls ) {
		WP_Mock::userFunction( 'remove_all_actions', array(
			'return' => static function ( $hook ) use ( &$all_calls ) {
				$all_calls[] = $hook;
			},
		) );
		WP_Mock::userFunction( 'remove_action', array(
			'return' => static function ( $hook, $callback, $priority ) use ( &$single_calls ) {
				$single_calls[] = array( $hook, $callback, $priority );
			},
		) );
	}

	/**
	 * Standard enabled config for a suppression pass.
	 *
	 * @param array $overrides Top-level config keys to override.
	 * @return array
	 */
	private function active_config( array $overrides = array() ) {
		return array_merge(
			array(
				'enabled'         => true,
				'protected_roles' => array( 'subscriber' ),
				'admin_roles'     => array(),
				'notifications'   => array(
					'suppress_nags'    => false,
					'suppress_updates' => false,
				),
			),
			$overrides
		);
	}

	// =========================================================================
	// Test 1: suppress_nags=true — remove_all_actions on both notice hooks
	// =========================================================================

	/**
	 * When suppress_nags is true, remove_all_actions is called for both
	 * 'admin_notices' and 'all_admin_notices'. The narrower remove_action calls
	 * are skipped because suppress_nags subsumes suppress_updates.
	 */
	public function test_suppress_nags_calls_remove_all_actions_on_both_hooks() {
		$core  = $this->make_core( $this->active_config( array(
			'notifications' => array( 'suppress_nags' => true, 'suppress_updates' => false ),
		) ) );
		$notif = new CH_Notifications( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$all_calls    = array();
		$single_calls = array();
		$this->mock_remove_functions( $all_calls, $single_calls );

		$notif->suppress_notifications();

		$this->assertContains( 'admin_notices',     $all_calls, 'remove_all_actions must target admin_notices' );
		$this->assertContains( 'all_admin_notices',  $all_calls, 'remove_all_actions must target all_admin_notices' );
		$this->assertCount( 2, $all_calls );
		$this->assertEmpty( $single_calls, 'remove_action must not be called when suppress_nags is true (subsumed)' );
	}

	// =========================================================================
	// Test 2: suppress_updates=true, suppress_nags=false — targeted removals
	// =========================================================================

	/**
	 * When only suppress_updates is true, remove_action is called for update_nag
	 * (priority 3) and maintenance_nag (priority 10). remove_all_actions is not called.
	 */
	public function test_suppress_updates_calls_remove_action_for_update_and_maintenance_nag() {
		$core  = $this->make_core( $this->active_config( array(
			'notifications' => array( 'suppress_nags' => false, 'suppress_updates' => true ),
		) ) );
		$notif = new CH_Notifications( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$all_calls    = array();
		$single_calls = array();
		$this->mock_remove_functions( $all_calls, $single_calls );

		$notif->suppress_notifications();

		$this->assertEmpty( $all_calls, 'remove_all_actions must not be called for suppress_updates-only' );
		$this->assertContains(
			array( 'admin_notices', 'update_nag', 3 ),
			$single_calls,
			"remove_action must target update_nag at priority 3"
		);
		$this->assertContains(
			array( 'admin_notices', 'maintenance_nag', 10 ),
			$single_calls,
			"remove_action must target maintenance_nag at priority 10"
		);
		$this->assertCount( 2, $single_calls );
	}

	// =========================================================================
	// Test 3: both flags true — behaves like suppress_nags only
	// =========================================================================

	/**
	 * When both flags are true, suppress_nags subsumes suppress_updates.
	 * remove_all_actions is called twice; remove_action is not called.
	 */
	public function test_both_flags_true_behaves_like_suppress_nags_only() {
		$core  = $this->make_core( $this->active_config( array(
			'notifications' => array( 'suppress_nags' => true, 'suppress_updates' => true ),
		) ) );
		$notif = new CH_Notifications( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$all_calls    = array();
		$single_calls = array();
		$this->mock_remove_functions( $all_calls, $single_calls );

		$notif->suppress_notifications();

		$this->assertContains( 'admin_notices',     $all_calls );
		$this->assertContains( 'all_admin_notices',  $all_calls );
		$this->assertCount( 2, $all_calls );
		$this->assertEmpty( $single_calls, 'remove_action must not be called when suppress_nags subsumes suppress_updates' );
	}

	// =========================================================================
	// Test 4: enabled=false — no-op
	// =========================================================================

	/**
	 * When the plugin is disabled the method returns before any WP call.
	 */
	public function test_disabled_plugin_is_noop() {
		$core  = $this->make_core( array( 'enabled' => false ) );
		$notif = new CH_Notifications( $core );

		$all_calls    = array();
		$single_calls = array();
		$this->mock_remove_functions( $all_calls, $single_calls );

		$notif->suppress_notifications();

		$this->assertEmpty( $all_calls,    'remove_all_actions must not be called when disabled' );
		$this->assertEmpty( $single_calls, 'remove_action must not be called when disabled' );
	}

	// =========================================================================
	// Test 5: both flags false — cheap early-return
	// =========================================================================

	/**
	 * When both suppress flags are false the method returns before fetching the
	 * user — no wp_get_current_user mock needed.
	 */
	public function test_both_flags_false_is_noop() {
		$core  = $this->make_core( $this->active_config() ); // both flags default to false
		$notif = new CH_Notifications( $core );

		$all_calls    = array();
		$single_calls = array();
		$this->mock_remove_functions( $all_calls, $single_calls );

		$notif->suppress_notifications();

		$this->assertEmpty( $all_calls,    'remove_all_actions must not be called when both flags are false' );
		$this->assertEmpty( $single_calls, 'remove_action must not be called when both flags are false' );
	}

	// =========================================================================
	// Test 6: non-protected user — no-op
	// =========================================================================

	/**
	 * A user whose role is not in protected_roles is not subject to suppression.
	 */
	public function test_non_protected_user_is_noop() {
		$core  = $this->make_core( $this->active_config( array(
			'notifications' => array( 'suppress_nags' => true, 'suppress_updates' => false ),
		) ) );
		$notif = new CH_Notifications( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'author' ) ), // author not in protected_roles
		) );

		$all_calls    = array();
		$single_calls = array();
		$this->mock_remove_functions( $all_calls, $single_calls );

		$notif->suppress_notifications();

		$this->assertEmpty( $all_calls,    'remove_all_actions must not be called for a non-protected user' );
		$this->assertEmpty( $single_calls, 'remove_action must not be called for a non-protected user' );
	}

	// =========================================================================
	// Test 7: cosmetic independence — admin_roles user still gets suppression
	// =========================================================================

	/**
	 * suppress_notifications() does NOT call is_exempt_from_enforcement(), so a
	 * user in admin_roles still receives suppression when their role is also in
	 * protected_roles.
	 *
	 * This test intentionally omits wp_roles and is_multisite mocks. If the
	 * method called those helpers the mock framework would error on the
	 * unexpected function call, turning the architectural constraint into a
	 * test failure.
	 */
	public function test_admin_roles_user_still_gets_suppression() {
		$core  = $this->make_core( $this->active_config( array(
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array( 'editor' ),
			'notifications'   => array( 'suppress_nags' => true, 'suppress_updates' => false ),
		) ) );
		$notif = new CH_Notifications( $core );

		// User holds both 'subscriber' (protected) and 'editor' (admin_role).
		// is_protected_user() sees 'subscriber' in protected_roles → true.
		// The method must not reach is_exempt_from_enforcement().
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber', 'editor' ) ),
		) );

		$all_calls    = array();
		$single_calls = array();
		$this->mock_remove_functions( $all_calls, $single_calls );

		$notif->suppress_notifications();

		$this->assertContains( 'admin_notices',    $all_calls,
			'Suppression must apply even for a user whose role is in admin_roles' );
		$this->assertContains( 'all_admin_notices', $all_calls );
		$this->assertEmpty( $single_calls );
	}
}
