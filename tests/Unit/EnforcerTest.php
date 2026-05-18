<?php
/**
 * Unit tests for ZSCH_Enforcer (capability filter + screen guard).
 *
 * Three test groups:
 *   1. Capability filter — blocked_caps are stripped; early-return paths are no-ops.
 *   2. Recursion safety — unit-level proof that filter_user_has_cap never calls
 *      current_user_can(). A true integration test (register filter → fire pipeline)
 *      requires a WordPress test harness and is deferred to Phase 4.
 *   3. Screen guard — wp_die() on blocklisted screens; always-permitted set respected.
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class EnforcerTest
 */
class EnforcerTest extends TestCase {

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public function setUp(): void {
		parent::setUp();
		ZSCH_Core::reset_instance();
	}

	public function tearDown(): void {
		ZSCH_Core::reset_instance();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a ZSCH_Core instance backed by the given saved config.
	 *
	 * @param array $config
	 * @return ZSCH_Core
	 */
	private function make_core( array $config = array() ) {
		WP_Mock::userFunction( 'get_option', array( 'return' => $config ) );
		return ZSCH_Core::get_instance();
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
	 * @param array<string, array<string, bool>> $roles_data
	 * @return WP_Roles
	 */
	private function make_wp_roles( array $roles_data ) {
		return new WP_Roles( $roles_data );
	}

	/**
	 * @param string $screen_id
	 * @return WP_Screen
	 */
	private function make_screen( $screen_id ) {
		return new WP_Screen( $screen_id );
	}

	/**
	 * Config for a standard "plugin enabled, subscriber is protected" scenario.
	 *
	 * @param array $overrides
	 * @return array
	 */
	private function active_config( array $overrides = array() ) {
		return array_merge(
			array(
				'enabled'         => true,
				'protected_roles' => array( 'subscriber' ),
				'admin_roles'     => array(),
				'enforcement'     => array(
					'blocked_caps'      => ZSCH_Core::DEFAULTS['enforcement']['blocked_caps'],
					'screen_blocklist'  => array(),
					'protected_plugins' => array(),
				),
				'dashboard'       => array(
					'enabled'           => false,
					'welcome_message'   => '',
					'quick_links'       => array(),
					'developer_contact' => array( 'name' => 'Dev', 'email' => 'dev@example.com', 'url' => '' ),
					'show_site_status'  => true,
				),
			),
			$overrides
		);
	}

	// =========================================================================
	// Capability filter
	// =========================================================================

	/**
	 * Every capability in the default blocked_caps set is removed from $allcaps
	 * for a protected, non-exempt user.
	 *
	 * @test
	 */
	public function test_cap_filter_denies_all_blocked_caps_for_protected_user() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );

		$core     = $this->make_core( $this->active_config() );
		$enforcer = new ZSCH_Enforcer( $core );
		$user     = $this->make_user( 5, array( 'subscriber' ) );

		// Give the user every blocked cap so we can verify they all get stripped.
		$allcaps = array( 'read' => true );
		foreach ( ZSCH_Core::DEFAULTS['enforcement']['blocked_caps'] as $cap ) {
			$allcaps[ $cap ] = true;
		}

		$result = $enforcer->filter_user_has_cap( $allcaps, array( 'read' ), array( 'read', 5 ), $user );

		foreach ( ZSCH_Core::DEFAULTS['enforcement']['blocked_caps'] as $cap ) {
			$this->assertArrayNotHasKey(
				$cap,
				$result,
				"blocked cap '{$cap}' must be absent from allcaps after filter"
			);
		}
		// Non-blocked caps must survive.
		$this->assertArrayHasKey( 'read', $result );
	}

	/**
	 * The filter is a no-op when the plugin is disabled.
	 *
	 * @test
	 */
	public function test_cap_filter_is_noop_when_plugin_disabled() {
		$core     = $this->make_core( array( 'enabled' => false ) );
		$enforcer = new ZSCH_Enforcer( $core );
		$user     = $this->make_user( 5, array( 'subscriber' ) );
		$allcaps  = array( 'read' => true, 'activate_plugins' => true );

		$result = $enforcer->filter_user_has_cap( $allcaps, array( 'read' ), array( 'read', 5 ), $user );

		$this->assertSame( $allcaps, $result, 'caps must be untouched when disabled' );
	}

	/**
	 * The filter is a no-op for a user whose role is not in protected_roles.
	 *
	 * @test
	 */
	public function test_cap_filter_is_noop_for_non_protected_user() {
		$core     = $this->make_core( $this->active_config() ); // protected_roles=['subscriber']
		$enforcer = new ZSCH_Enforcer( $core );
		$user     = $this->make_user( 5, array( 'author' ) ); // author is not protected.
		$allcaps  = array( 'read' => true, 'activate_plugins' => true );

		$result = $enforcer->filter_user_has_cap( $allcaps, array( 'read' ), array( 'read', 5 ), $user );

		$this->assertSame( $allcaps, $result );
	}

	/**
	 * The filter is a no-op for user ID 1 (always-exempt lockout safeguard).
	 *
	 * @test
	 */
	public function test_cap_filter_is_noop_for_user_id_1() {
		// ID=1 check fires before is_multisite() — no mock needed.
		$core     = $this->make_core( $this->active_config() );
		$enforcer = new ZSCH_Enforcer( $core );
		$user     = $this->make_user( 1, array( 'subscriber' ) );
		$allcaps  = array( 'read' => true, 'activate_plugins' => true );

		$result = $enforcer->filter_user_has_cap( $allcaps, array( 'read' ), array( 'read', 1 ), $user );

		$this->assertArrayHasKey( 'activate_plugins', $result, 'ID 1 must never have caps stripped' );
	}

	/**
	 * The filter is a no-op for a user in admin_roles (admin role precedence).
	 *
	 * @test
	 */
	public function test_cap_filter_is_noop_for_user_in_admin_roles() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );

		$config   = $this->active_config( array( 'admin_roles' => array( 'editor' ) ) );
		$core     = $this->make_core( $config );
		$enforcer = new ZSCH_Enforcer( $core );
		// editor is in protected_roles AND admin_roles — admin takes precedence.
		$user     = $this->make_user( 5, array( 'subscriber', 'editor' ) );
		$allcaps  = array( 'read' => true, 'activate_plugins' => true );

		$result = $enforcer->filter_user_has_cap( $allcaps, array( 'read' ), array( 'read', 5 ), $user );

		$this->assertArrayHasKey( 'activate_plugins', $result );
	}

	/**
	 * The filter is a no-op for a user who holds activate_plugins in their
	 * unfiltered role definition (hard-floor lockout safeguard).
	 *
	 * @test
	 */
	public function test_cap_filter_is_noop_for_activate_plugins_hard_floor() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'custom_role' => array(
					'read'             => true,
					'activate_plugins' => true,
				),
			) ),
		) );

		$config   = $this->active_config( array( 'protected_roles' => array( 'custom_role' ) ) );
		$core     = $this->make_core( $config );
		$enforcer = new ZSCH_Enforcer( $core );
		$user     = $this->make_user( 5, array( 'custom_role' ) );
		$allcaps  = array( 'read' => true, 'activate_plugins' => true );

		$result = $enforcer->filter_user_has_cap( $allcaps, array( 'read' ), array( 'read', 5 ), $user );

		$this->assertArrayHasKey( 'activate_plugins', $result,
			'activate_plugins hard floor must exempt user from enforcement'
		);
	}

	// =========================================================================
	// Recursion safety (unit)
	// =========================================================================

	/**
	 * Unit proof: filter_user_has_cap() never calls current_user_can().
	 *
	 * WP_Mock's hook system is an expectation matcher, not a real WordPress
	 * pipeline — add_filter callbacks are not executed by apply_filters. A true
	 * integration test (register the filter, fire current_user_can(), observe the
	 * re-entrant apply_filters('user_has_cap', ...) pipeline, prove termination)
	 * requires a full WordPress test harness and is deferred to Phase 4.
	 *
	 * This test covers the unit-level constraint: the callback MUST NOT call
	 * current_user_can() or user_can(). In real WordPress those calls re-trigger
	 * apply_filters('user_has_cap', ...) from inside the callback, causing an
	 * infinite loop (brief § 3.4 RECURSION CONSTRAINT).
	 *
	 * @test
	 */
	public function test_cap_filter_never_calls_current_user_can() {
		// WP_Mock's add_filter/apply_filters are expectation matchers, not a real
		// hook pipeline. We prove the constraint by calling the callback directly
		// and asserting current_user_can() was never invoked inside it.
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );

		$core     = $this->make_core( $this->active_config() );
		$enforcer = new ZSCH_Enforcer( $core );

		// If current_user_can() is called inside the callback, this flag flips.
		// WP_Mock uses 'return' => Closure for callback-based returns.
		$current_user_can_called = false;
		WP_Mock::userFunction( 'current_user_can', array(
			'return' => static function () use ( &$current_user_can_called ) {
				$current_user_can_called = true;
				return false;
			},
		) );

		$user    = new WP_User( 5, array( 'subscriber' ) );
		$allcaps = array( 'read' => true, 'install_plugins' => true );

		// Invoke exactly as WordPress does via apply_filters('user_has_cap', ...).
		$result = $enforcer->filter_user_has_cap(
			$allcaps,
			array( 'install_plugins' ),
			array( 'install_plugins', 5 ),
			$user
		);

		$this->assertFalse(
			$current_user_can_called,
			'filter_user_has_cap must not call current_user_can() — causes infinite recursion in WordPress'
		);
		$this->assertArrayNotHasKey( 'install_plugins', $result,
			'blocked cap must be stripped'
		);
		$this->assertArrayHasKey( 'read', $result,
			'non-blocked cap must survive'
		);
	}

	// =========================================================================
	// Screen guard
	// =========================================================================

	/**
	 * A protected, non-exempt user on a blocklisted screen triggers wp_die().
	 *
	 * @test
	 */
	public function test_screen_guard_blocks_protected_user_on_blocklisted_screen() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		// No apply_filters mock needed: WP_Mock's built-in apply_filters passes
		// through the value unchanged when no processor is registered for the hook.
		// WP_Mock uses 'return' => Closure (not 'return_callback') for callbacks.
		$wp_die_called = false;
		WP_Mock::userFunction( 'wp_die', array(
			'return' => static function () use ( &$wp_die_called ) {
				$wp_die_called = true;
			},
		) );

		$config = $this->active_config( array(
			'enforcement' => array(
				'blocked_caps'      => array(),
				'protected_plugins' => array(),
				'screen_blocklist'  => array(
					'subscriber' => array( 'options-general.php' ),
				),
			),
		) );

		$core     = $this->make_core( $config );
		$enforcer = new ZSCH_Enforcer( $core );
		$enforcer->guard_current_screen( $this->make_screen( 'options-general.php' ) );

		$this->assertTrue( $wp_die_called, 'wp_die() must be called for a protected user on a blocklisted screen' );
	}

	/**
	 * index.php must never be blocked even when explicitly listed in the blocklist.
	 *
	 * @test
	 */
	public function test_screen_guard_never_blocks_index_php() {
		$this->assert_screen_never_blocked( 'index.php' );
	}

	/**
	 * profile.php must never be blocked even when explicitly listed in the blocklist.
	 *
	 * @test
	 */
	public function test_screen_guard_never_blocks_profile_php() {
		$this->assert_screen_never_blocked( 'profile.php' );
	}

	/**
	 * The plugin's own settings screen must never be blocked.
	 *
	 * @test
	 */
	public function test_screen_guard_never_blocks_settings_screen() {
		$this->assert_screen_never_blocked( ZSCH_Enforcer::SETTINGS_SCREEN_ID );
	}

	/**
	 * The screen guard is a no-op when the plugin is disabled.
	 *
	 * @test
	 */
	public function test_screen_guard_is_noop_when_plugin_disabled() {
		$core     = $this->make_core( array( 'enabled' => false ) );
		$enforcer = new ZSCH_Enforcer( $core );

		$wp_die_called = false;
		WP_Mock::userFunction( 'wp_die', array(
			'return' => static function () use ( &$wp_die_called ) {
				$wp_die_called = true;
			},
		) );

		// Guard returns at the first check (enabled=false) — no WP calls after that.
		$enforcer->guard_current_screen( $this->make_screen( 'options-general.php' ) );

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called when plugin is disabled' );
	}

	/**
	 * The screen guard is a no-op for a user whose role is not in protected_roles.
	 *
	 * @test
	 */
	public function test_screen_guard_is_noop_for_non_protected_user() {
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'author' ) ), // author not in protected_roles.
		) );

		$core     = $this->make_core( $this->active_config() ); // protected_roles=['subscriber']
		$enforcer = new ZSCH_Enforcer( $core );

		$wp_die_called = false;
		WP_Mock::userFunction( 'wp_die', array(
			'return' => static function () use ( &$wp_die_called ) {
				$wp_die_called = true;
			},
		) );

		$enforcer->guard_current_screen( $this->make_screen( 'options-general.php' ) );

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called for a non-protected user' );
	}

	/**
	 * The screen guard is a no-op for user ID 1 (always-exempt safeguard).
	 *
	 * @test
	 */
	public function test_screen_guard_is_noop_for_user_id_1() {
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 1, array( 'subscriber' ) ),
		) );

		// subscriber IS in protected_roles, and options-general.php IS in the
		// blocklist — only the ID=1 exemption prevents the block.
		$config = $this->active_config( array(
			'enforcement' => array(
				'blocked_caps'      => array(),
				'protected_plugins' => array(),
				'screen_blocklist'  => array(
					'subscriber' => array( 'options-general.php' ),
				),
			),
		) );

		$core     = $this->make_core( $config );
		$enforcer = new ZSCH_Enforcer( $core );

		// ID=1 exemption fires before is_multisite() and wp_roles() — no extra mocks.
		$wp_die_called = false;
		WP_Mock::userFunction( 'wp_die', array(
			'return' => static function () use ( &$wp_die_called ) {
				$wp_die_called = true;
			},
		) );

		$enforcer->guard_current_screen( $this->make_screen( 'options-general.php' ) );

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called for user ID 1 (always-exempt safeguard)' );
	}

	/**
	 * The screen guard is a no-op for a user in admin_roles (admin precedence).
	 *
	 * @test
	 */
	public function test_screen_guard_is_noop_for_user_in_admin_roles() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		// User holds BOTH subscriber (protected) and editor (admin_role) so
		// is_protected_user() returns true and only the admin exemption prevents
		// the block. Giving the user only 'editor' would short-circuit at
		// is_protected_user(), never exercising the admin-role exemption path.
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber', 'editor' ) ),
		) );

		$config = $this->active_config( array(
			'admin_roles' => array( 'editor' ),
			'enforcement' => array(
				'blocked_caps'      => array(),
				'protected_plugins' => array(),
				'screen_blocklist'  => array(
					'subscriber' => array( 'options-general.php' ),
					'editor'     => array( 'options-general.php' ),
				),
			),
		) );

		$core     = $this->make_core( $config );
		$enforcer = new ZSCH_Enforcer( $core );

		$wp_die_called = false;
		WP_Mock::userFunction( 'wp_die', array(
			'return' => static function () use ( &$wp_die_called ) {
				$wp_die_called = true;
			},
		) );

		$enforcer->guard_current_screen( $this->make_screen( 'options-general.php' ) );

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called for a user in admin_roles (admin precedence)' );
	}

	// -------------------------------------------------------------------------
	// Internal helper for always-permitted-set assertions
	// -------------------------------------------------------------------------

	/**
	 * Assert that $screen_id is never blocked even when it appears in the
	 * screen_blocklist for the user's role.
	 *
	 * @param string $screen_id
	 */
	private function assert_screen_never_blocked( $screen_id ) {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		// Deliberately put the always-permitted screen into the blocklist to
		// prove the always-permitted check fires first.
		// No apply_filters mock needed: WP_Mock's built-in apply_filters passes
		// through the value when no processor is registered (i.e. returns the
		// permitted array unchanged), so is_always_permitted() resolves correctly.
		$config = $this->active_config( array(
			'enforcement' => array(
				'blocked_caps'      => array(),
				'protected_plugins' => array(),
				'screen_blocklist'  => array(
					'subscriber' => array( $screen_id ),
				),
			),
		) );

		$core     = $this->make_core( $config );
		$enforcer = new ZSCH_Enforcer( $core );

		// Track wp_die() with a flag so the assertion is meaningful.
		// If is_always_permitted() fails and the blocklist branch runs,
		// die_blocked() calls wp_die() and $wp_die_called becomes true.
		$wp_die_called = false;
		WP_Mock::userFunction( 'wp_die', array(
			'return' => static function () use ( &$wp_die_called ) {
				$wp_die_called = true;
			},
		) );

		$enforcer->guard_current_screen( $this->make_screen( $screen_id ) );

		$this->assertFalse(
			$wp_die_called,
			"wp_die() must not be called for always-permitted screen '{$screen_id}'"
		);
	}
}
