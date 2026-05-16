<?php
/**
 * Unit tests for CH_Core.
 *
 * Covers: config defaults, role resolution (single- and multi-role users),
 * admin/protected precedence, all three lockout safeguards, the recursion-safe
 * capability helper, and inert-defaults behaviour.
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class CoreTest
 */
class CoreTest extends TestCase {

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public function setUp(): void {
		parent::setUp(); // WP_Mock::setUp() called by parent.
		CH_Core::reset_instance();
	}

	public function tearDown(): void {
		CH_Core::reset_instance();
		parent::tearDown(); // WP_Mock::tearDown() called by parent.
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a CH_Core instance with get_option() mocked to return $config.
	 *
	 * @param array $config Saved option value (empty = fresh activation).
	 * @return CH_Core
	 */
	private function make_core( array $config = array() ) {
		WP_Mock::userFunction( 'get_option', array(
			'return' => $config,
		) );
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
	 * @param array<string, array<string, bool>> $roles_data
	 * @return WP_Roles
	 */
	private function make_wp_roles( array $roles_data ) {
		return new WP_Roles( $roles_data );
	}

	// =========================================================================
	// Inert defaults
	// =========================================================================

	/**
	 * A freshly activated plugin (no saved config) must not restrict any user.
	 *
	 * @test
	 */
	public function test_inert_defaults_restrict_no_one() {
		$core = $this->make_core(); // empty saved config.
		$user = $this->make_user( 5, array( 'subscriber' ) );

		$this->assertFalse(
			$core->get( 'enabled' ),
			'enabled must default to false'
		);
		$this->assertFalse(
			$core->is_protected_user( $user ),
			'no user should be protected when enabled=false'
		);
		$this->assertFalse(
			$core->is_admin_user( $user ),
			'no user should be admin when admin_roles is empty'
		);
		$this->assertSame(
			'neither',
			$core->get_user_status( $user ),
			'status must be neither with inert defaults'
		);
	}

	/**
	 * protected_roles and admin_roles must default to empty arrays.
	 *
	 * @test
	 */
	public function test_inert_defaults_role_lists_are_empty() {
		$core = $this->make_core();

		$this->assertSame( array(), $core->get( 'protected_roles' ) );
		$this->assertSame( array(), $core->get( 'admin_roles' ) );
	}

	/**
	 * Saved config keys not present in DEFAULTS are dropped (forward-compat safety).
	 *
	 * @test
	 */
	public function test_unknown_saved_keys_are_dropped() {
		$core = $this->make_core( array(
			'enabled'             => true,
			'unknown_future_key'  => 'should_be_dropped',
		) );

		$this->assertNull( $core->get( 'unknown_future_key' ) );
	}

	/**
	 * A saved enabled=true is honoured while all other defaults remain intact.
	 *
	 * @test
	 */
	public function test_partial_saved_config_merges_with_defaults() {
		$core = $this->make_core( array( 'enabled' => true ) );

		$this->assertTrue( $core->get( 'enabled' ) );
		// Everything else should still carry defaults.
		$this->assertSame( array(), $core->get( 'protected_roles' ) );
		$this->assertFalse( $core->get( 'setup_completed' ) );
	}

	// =========================================================================
	// Nested associative merge (deep_merge recursion branch)
	// =========================================================================

	/**
	 * Partial 'dashboard' save merges against defaults: unset keys come back
	 * as their DEFAULTS values, proving the assoc-recurse branch fires.
	 *
	 * @test
	 */
	public function test_nested_dashboard_merge_preserves_unset_defaults() {
		$core = $this->make_core( array(
			'enabled'   => true,
			'dashboard' => array(
				'enabled' => true, // only this key is saved.
			),
		) );

		$dashboard = $core->get( 'dashboard' );

		$this->assertTrue( $dashboard['enabled'], 'saved value must win' );
		$this->assertSame( '', $dashboard['welcome_message'], 'welcome_message must carry default' );
		$this->assertSame( array(), $dashboard['quick_links'], 'quick_links must carry default' );
		$this->assertSame( true, $dashboard['show_site_status'], 'show_site_status must carry default' );
		// developer_contact is itself a nested assoc — its sub-keys must also carry defaults.
		$this->assertSame(
			array( 'name' => '', 'email' => '', 'url' => '' ),
			$dashboard['developer_contact'],
			'developer_contact must be the full default when not saved'
		);
	}

	/**
	 * Partial 'enforcement' save merges against defaults: unset keys come back
	 * as their DEFAULTS values (parallel case to the dashboard test above).
	 *
	 * @test
	 */
	public function test_nested_enforcement_merge_preserves_unset_defaults() {
		$core = $this->make_core( array(
			'enabled'     => true,
			'enforcement' => array(
				'protected_plugins' => array( 'woocommerce/woocommerce.php' ),
				// blocked_caps and screen_blocklist intentionally omitted.
			),
		) );

		$enforcement = $core->get( 'enforcement' );

		$this->assertSame(
			array( 'woocommerce/woocommerce.php' ),
			$enforcement['protected_plugins'],
			'saved protected_plugins must win'
		);
		// screen_blocklist default is empty array — must survive unset save.
		$this->assertSame( array(), $enforcement['screen_blocklist'], 'screen_blocklist must carry default' );
		// blocked_caps default set must survive unset save.
		$this->assertNotEmpty( $enforcement['blocked_caps'], 'blocked_caps must carry the full default set' );
		$this->assertContains( 'activate_plugins', $enforcement['blocked_caps'] );
	}

	// =========================================================================
	// Blocked-caps default set (design-decision pin)
	// =========================================================================

	/**
	 * The full 11-capability blocked_caps default set must be exactly as specified
	 * in brief § 3.4. This test pins the deliberate design decision so that
	 * accidental edits to DEFAULTS are caught immediately.
	 *
	 * @test
	 */
	public function test_blocked_caps_default_set_is_complete_and_exact() {
		$expected = array(
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
		);

		$defaults    = CH_Core::DEFAULTS;
		$blocked_caps = $defaults['enforcement']['blocked_caps'];

		// Exact match — order matters because the array is used in foreach loops.
		$this->assertSame(
			$expected,
			$blocked_caps,
			'blocked_caps default set must match brief § 3.4 exactly (11 capabilities)'
		);
	}

	// =========================================================================
	// Role resolution — single-role users
	// =========================================================================

	/**
	 * A user whose role is in protected_roles is classified as 'protected'.
	 *
	 * @test
	 */
	public function test_protected_user_single_role() {
		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array(),
		) );
		$user = $this->make_user( 5, array( 'subscriber' ) );

		$this->assertTrue( $core->is_protected_user( $user ) );
		$this->assertSame( 'protected', $core->get_user_status( $user ) );
	}

	/**
	 * A user whose role is in admin_roles is classified as 'admin'.
	 *
	 * @test
	 */
	public function test_admin_user_single_role() {
		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array( 'editor' ),
		) );
		$user = $this->make_user( 5, array( 'editor' ) );

		$this->assertTrue( $core->is_admin_user( $user ) );
		$this->assertSame( 'admin', $core->get_user_status( $user ) );
	}

	/**
	 * A user with a role in neither list is classified as 'neither'.
	 *
	 * @test
	 */
	public function test_neither_user_single_role() {
		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array( 'editor' ),
		) );
		$user = $this->make_user( 5, array( 'author' ) );

		$this->assertFalse( $core->is_protected_user( $user ) );
		$this->assertFalse( $core->is_admin_user( $user ) );
		$this->assertSame( 'neither', $core->get_user_status( $user ) );
	}

	/**
	 * is_protected_user returns false when plugin is disabled, even if roles match.
	 *
	 * @test
	 */
	public function test_protected_user_returns_false_when_disabled() {
		$core = $this->make_core( array(
			'enabled'         => false,
			'protected_roles' => array( 'subscriber' ),
		) );
		$user = $this->make_user( 5, array( 'subscriber' ) );

		$this->assertFalse( $core->is_protected_user( $user ) );
	}

	// =========================================================================
	// Role resolution — multi-role users
	// =========================================================================

	/**
	 * A multi-role user is 'protected' when any of their roles is in protected_roles.
	 *
	 * @test
	 */
	public function test_multi_role_user_protected_when_any_role_matches() {
		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array(),
		) );
		// author is not protected; subscriber is.
		$user = $this->make_user( 5, array( 'author', 'subscriber' ) );

		$this->assertTrue( $core->is_protected_user( $user ) );
		$this->assertSame( 'protected', $core->get_user_status( $user ) );
	}

	/**
	 * A multi-role user is 'admin' when any of their roles is in admin_roles.
	 *
	 * @test
	 */
	public function test_multi_role_user_admin_when_any_role_matches() {
		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array( 'editor' ),
		) );
		// subscriber alone would be protected, but editor makes them admin.
		$user = $this->make_user( 5, array( 'subscriber', 'editor' ) );

		$this->assertTrue( $core->is_admin_user( $user ) );
		$this->assertSame( 'admin', $core->get_user_status( $user ) );
	}

	/**
	 * A user in BOTH protected_roles and admin_roles must be classified as 'admin'.
	 * Admin status takes precedence (brief § 3.4 admin_roles precedence rule).
	 *
	 * @test
	 */
	public function test_both_protected_and_admin_role_yields_admin_status() {
		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'editor' ), // editor is in both lists.
			'admin_roles'     => array( 'editor' ),
		) );
		$user = $this->make_user( 5, array( 'editor' ) );

		$this->assertTrue( $core->is_admin_user( $user ) );
		$this->assertSame( 'admin', $core->get_user_status( $user ) );
	}

	// =========================================================================
	// Lockout safeguards
	// =========================================================================

	/**
	 * User ID 1 is always exempt from enforcement, regardless of roles.
	 *
	 * @test
	 */
	public function test_user_id_1_is_always_exempt() {
		// No WP function mocks needed: the ID=1 check returns before any WP call.
		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array(),
		) );
		$user = $this->make_user( 1, array( 'subscriber' ) );

		$this->assertTrue( $core->is_exempt_from_enforcement( $user ) );
	}

	/**
	 * A user in admin_roles is exempt from enforcement (admin role precedence).
	 *
	 * @test
	 */
	public function test_admin_role_member_is_exempt_from_enforcement() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );

		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array( 'editor' ),
		) );
		$user = $this->make_user( 5, array( 'editor' ) );

		$this->assertTrue( $core->is_exempt_from_enforcement( $user ) );
	}

	/**
	 * A protected-role user who holds activate_plugins in their unfiltered role
	 * definition is exempt from enforcement (hard floor).
	 *
	 * @test
	 */
	public function test_activate_plugins_hard_floor_exempts_from_enforcement() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );

		$wp_roles = $this->make_wp_roles( array(
			'custom_role' => array(
				'read'             => true,
				'activate_plugins' => true,
			),
		) );
		WP_Mock::userFunction( 'wp_roles', array( 'return' => $wp_roles ) );

		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'custom_role' ),
			'admin_roles'     => array(),
		) );
		$user = $this->make_user( 5, array( 'custom_role' ) );

		// The role matches protected_roles...
		$this->assertTrue( $core->is_protected_user( $user ) );
		// ...but the hard floor exempts the user from enforcement.
		$this->assertTrue( $core->is_exempt_from_enforcement( $user ) );
	}

	/**
	 * A protected-role user without activate_plugins is NOT exempt from enforcement.
	 *
	 * @test
	 */
	public function test_protected_user_without_activate_plugins_is_not_exempt() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );

		$wp_roles = $this->make_wp_roles( array(
			'subscriber' => array(
				'read' => true,
				// activate_plugins is deliberately absent.
			),
		) );
		WP_Mock::userFunction( 'wp_roles', array( 'return' => $wp_roles ) );

		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array(),
		) );
		$user = $this->make_user( 5, array( 'subscriber' ) );

		$this->assertFalse( $core->is_exempt_from_enforcement( $user ) );
	}

	/**
	 * Multisite super-admins are exempt from enforcement.
	 *
	 * @test
	 */
	public function test_multisite_super_admin_is_exempt() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => true ) );
		WP_Mock::userFunction( 'is_super_admin', array( 'return' => true ) );

		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array(),
		) );
		$user = $this->make_user( 5, array( 'subscriber' ) );

		$this->assertTrue( $core->is_exempt_from_enforcement( $user ) );
	}

	// =========================================================================
	// Recursion-safe capability helper
	// =========================================================================

	/**
	 * Returns true when the user's role grants the capability.
	 *
	 * @test
	 */
	public function test_user_has_cap_unfiltered_true_for_matching_role() {
		$wp_roles = $this->make_wp_roles( array(
			'administrator' => array(
				'activate_plugins' => true,
				'manage_options'   => true,
			),
		) );
		WP_Mock::userFunction( 'wp_roles', array( 'return' => $wp_roles ) );

		$core = $this->make_core();
		$user = $this->make_user( 2, array( 'administrator' ) );

		$this->assertTrue( $core->user_has_cap_unfiltered( $user, 'activate_plugins' ) );
	}

	/**
	 * Returns false when the user's role does not grant the capability.
	 *
	 * @test
	 */
	public function test_user_has_cap_unfiltered_false_when_cap_absent() {
		$wp_roles = $this->make_wp_roles( array(
			'subscriber' => array(
				'read' => true,
				// activate_plugins deliberately absent.
			),
		) );
		WP_Mock::userFunction( 'wp_roles', array( 'return' => $wp_roles ) );

		$core = $this->make_core();
		$user = $this->make_user( 5, array( 'subscriber' ) );

		$this->assertFalse( $core->user_has_cap_unfiltered( $user, 'activate_plugins' ) );
	}

	/**
	 * Returns false when the user's role does not exist in the roles registry.
	 *
	 * @test
	 */
	public function test_user_has_cap_unfiltered_false_for_nonexistent_role() {
		$wp_roles = $this->make_wp_roles( array() ); // empty registry.
		WP_Mock::userFunction( 'wp_roles', array( 'return' => $wp_roles ) );

		$core = $this->make_core();
		$user = $this->make_user( 5, array( 'nonexistent_role' ) );

		$this->assertFalse( $core->user_has_cap_unfiltered( $user, 'activate_plugins' ) );
	}

	/**
	 * Checks all roles for a multi-role user and returns true if any grants the cap.
	 *
	 * @test
	 */
	public function test_user_has_cap_unfiltered_true_via_secondary_role() {
		$wp_roles = $this->make_wp_roles( array(
			'subscriber' => array( 'read' => true ),
			'editor'     => array(
				'edit_posts'       => true,
				'activate_plugins' => true, // granted on the second role.
			),
		) );
		WP_Mock::userFunction( 'wp_roles', array( 'return' => $wp_roles ) );

		$core = $this->make_core();
		$user = $this->make_user( 5, array( 'subscriber', 'editor' ) );

		$this->assertTrue( $core->user_has_cap_unfiltered( $user, 'activate_plugins' ) );
	}

	/**
	 * The helper must read wp_roles() directly — never user_can() / current_user_can().
	 *
	 * This test verifies the contract by confirming wp_roles() is called and
	 * the result is correct without touching the capability API. If user_can()
	 * or current_user_can() were called, they would not be mocked and WP_Mock
	 * would fail or return null, making the test fail.
	 *
	 * @test
	 */
	public function test_user_has_cap_unfiltered_does_not_use_capability_api() {
		$wp_roles = $this->make_wp_roles( array(
			'administrator' => array( 'activate_plugins' => true ),
		) );
		// Only wp_roles() is mocked. user_can / current_user_can are NOT mocked.
		// Any call to them would cause WP_Mock to throw or return null,
		// breaking the assertion below.
		WP_Mock::userFunction( 'wp_roles', array( 'return' => $wp_roles ) );

		$core = $this->make_core();
		$user = $this->make_user( 2, array( 'administrator' ) );

		// If user_has_cap_unfiltered secretly called user_can(), this would fail.
		$this->assertTrue( $core->user_has_cap_unfiltered( $user, 'activate_plugins' ) );
	}

	// =========================================================================
	// merge_into_current — partial-merge helper
	// =========================================================================

	/**
	 * An empty partial leaves the saved config unchanged (minus unknown keys).
	 *
	 * @test
	 */
	public function test_merge_into_current_empty_partial_returns_saved_config() {
		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
		) );

		$result = $core->merge_into_current( array() );

		$this->assertTrue( $result['enabled'] );
		$this->assertSame( array( 'subscriber' ), $result['protected_roles'] );
	}

	/**
	 * A key in $partial overrides the matching saved value; other saved keys survive.
	 *
	 * @test
	 */
	public function test_merge_into_current_partial_key_overrides_saved() {
		$core = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
		) );

		$result = $core->merge_into_current( array( 'enabled' => false ) );

		$this->assertFalse( $result['enabled'] );
		$this->assertSame( array( 'subscriber' ), $result['protected_roles'] );
	}

	/**
	 * On a fresh install (saved=[]) the result is DEFAULTS overlaid with $partial.
	 *
	 * @test
	 */
	public function test_merge_into_current_on_fresh_install_uses_defaults() {
		$core = $this->make_core( array() ); // fresh install: no saved config.

		$result = $core->merge_into_current( array( 'enabled' => true ) );

		$this->assertTrue( $result['enabled'] );
		$this->assertSame( array(), $result['protected_roles'] );
		$this->assertSame( array(), $result['admin_roles'] );
		$this->assertArrayHasKey( 'enforcement', $result );
		$this->assertArrayHasKey( 'dashboard', $result );
	}

	/**
	 * Keys present in $partial but absent from DEFAULTS are silently dropped.
	 *
	 * @test
	 */
	public function test_merge_into_current_drops_unknown_partial_keys() {
		$core = $this->make_core( array() );

		$result = $core->merge_into_current( array(
			'enabled'        => true,
			'future_feature' => 'should_be_dropped',
		) );

		$this->assertTrue( $result['enabled'] );
		$this->assertArrayNotHasKey( 'future_feature', $result );
	}
}
