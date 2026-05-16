<?php
/**
 * Unit tests for CH_Admin_Bar (admin bar simplification, cosmetic layer).
 *
 * WP_Mock limitation: add_action() is an expectation assertion only — the hook
 * pipeline is never fired. All tests call simplify_admin_bar() directly.
 *
 * Cosmetic-layer independence (Tests 6 and 7): simplify_admin_bar() must NOT
 * call is_exempt_from_enforcement() — that is an enforcement-layer safeguard.
 * Both tests intentionally omit wp_roles/is_multisite mocks. If the method
 * ever called those helpers the mock framework would error on the unexpected
 * function call, turning the architectural constraint into a test failure.
 *
 * WP_Admin_Bar stub: defined in tests/bootstrap.php. It stores nodes as a
 * plain associative array and records every remove_node() call in $removed.
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class AdminBarTest
 */
class AdminBarTest extends TestCase {

	/** @var WP_Admin_Bar|null Saved global restored in tearDown */
	private $saved_admin_bar;

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public function setUp(): void {
		parent::setUp();
		CH_Core::reset_instance();

		global $wp_admin_bar;
		$this->saved_admin_bar = $wp_admin_bar;
	}

	public function tearDown(): void {
		global $wp_admin_bar;
		$wp_admin_bar = $this->saved_admin_bar;

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
	 * Populate the global $wp_admin_bar stub with the given node IDs and return
	 * the stub so tests can inspect $stub->removed after the call.
	 *
	 * @param string[] $node_ids
	 * @return WP_Admin_Bar
	 */
	private function make_admin_bar( array $node_ids ) {
		$nodes = array();
		foreach ( $node_ids as $id ) {
			$nodes[ $id ] = (object) array( 'id' => $id );
		}

		$bar = new WP_Admin_Bar();
		$bar->set_nodes( $nodes );

		global $wp_admin_bar;
		$wp_admin_bar = $bar;

		return $bar;
	}

	/**
	 * Standard enabled config for an active simplification pass.
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
				'admin_bar'       => array(
					'simplify'      => true,
					'allowed_nodes' => array(),
				),
			),
			$overrides
		);
	}

	// =========================================================================
	// Test 1: default keep set — three defaults survive, others removed
	// =========================================================================

	/**
	 * With allowed_nodes empty, the three DEFAULT_KEEP_NODES survive; every
	 * other node is recorded as removed.
	 */
	public function test_default_keep_set_removes_non_default_nodes() {
		$core    = $this->make_core( $this->active_config() );
		$admin   = new CH_Admin_Bar( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$bar = $this->make_admin_bar( array(
			'site-name', 'edit', 'my-account',
			'wp-logo', 'comments', 'new-content', 'updates',
		) );

		$admin->simplify_admin_bar();

		$this->assertNotContains( 'site-name',   $bar->removed, 'site-name must survive' );
		$this->assertNotContains( 'edit',        $bar->removed, 'edit must survive' );
		$this->assertNotContains( 'my-account',  $bar->removed, 'my-account must survive (logout lives inside)' );

		$this->assertContains( 'wp-logo',     $bar->removed );
		$this->assertContains( 'comments',    $bar->removed );
		$this->assertContains( 'new-content', $bar->removed );
		$this->assertContains( 'updates',     $bar->removed );
		$this->assertCount( 4, $bar->removed );
	}

	// =========================================================================
	// Test 2: custom allowed_nodes overrides default keep set
	// =========================================================================

	/**
	 * When allowed_nodes is non-empty it replaces DEFAULT_KEEP_NODES entirely.
	 * Only nodes in allowed_nodes survive; edit and my-account are removed
	 * because they are not in the custom list.
	 */
	public function test_custom_allowed_nodes_overrides_default_keep_set() {
		$core = $this->make_core( $this->active_config( array(
			'admin_bar' => array(
				'simplify'      => true,
				'allowed_nodes' => array( 'site-name' ),
			),
		) ) );
		$admin = new CH_Admin_Bar( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$bar = $this->make_admin_bar( array( 'site-name', 'edit', 'my-account' ) );

		$admin->simplify_admin_bar();

		$this->assertNotContains( 'site-name', $bar->removed, 'site-name must survive (in allowed_nodes)' );
		$this->assertContains( 'edit',       $bar->removed, 'edit must be removed (not in allowed_nodes)' );
		$this->assertContains( 'my-account', $bar->removed, 'my-account must be removed (not in allowed_nodes)' );
		$this->assertCount( 2, $bar->removed );
	}

	// =========================================================================
	// Test 3: simplify=false is a no-op
	// =========================================================================

	/**
	 * When admin_bar.simplify is false the method returns immediately and no
	 * nodes are removed.
	 */
	public function test_simplify_false_is_noop() {
		$core = $this->make_core( $this->active_config( array(
			'admin_bar' => array( 'simplify' => false, 'allowed_nodes' => array() ),
		) ) );
		$admin = new CH_Admin_Bar( $core );

		$bar = $this->make_admin_bar( array( 'wp-logo', 'site-name', 'my-account' ) );

		$admin->simplify_admin_bar();

		$this->assertEmpty( $bar->removed, 'No nodes must be removed when simplify=false' );
	}

	// =========================================================================
	// Test 4: enabled=false is a no-op
	// =========================================================================

	/**
	 * When the plugin is disabled the method returns before any WP call.
	 */
	public function test_disabled_plugin_is_noop() {
		$core  = $this->make_core( array( 'enabled' => false ) );
		$admin = new CH_Admin_Bar( $core );

		$bar = $this->make_admin_bar( array( 'wp-logo', 'site-name', 'my-account' ) );

		$admin->simplify_admin_bar();

		$this->assertEmpty( $bar->removed, 'No nodes must be removed when plugin is disabled' );
	}

	// =========================================================================
	// Test 5: non-protected user — no-op
	// =========================================================================

	/**
	 * A user whose role is not in protected_roles is not subject to
	 * simplification. No nodes are removed.
	 */
	public function test_non_protected_user_is_noop() {
		$core  = $this->make_core( $this->active_config() ); // protected_roles=['subscriber']
		$admin = new CH_Admin_Bar( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'author' ) ), // author not in protected_roles
		) );

		$bar = $this->make_admin_bar( array( 'wp-logo', 'site-name', 'my-account' ) );

		$admin->simplify_admin_bar();

		$this->assertEmpty( $bar->removed, 'No nodes must be removed for a non-protected user' );
	}

	// =========================================================================
	// Test 6: cosmetic independence — admin_roles user still gets bar simplified
	// =========================================================================

	/**
	 * simplify_admin_bar() does NOT call is_exempt_from_enforcement(), so a user
	 * in admin_roles still has the bar simplified when their role is also in
	 * protected_roles.
	 *
	 * This test intentionally omits wp_roles/is_multisite mocks. If the method
	 * called those helpers the mock framework would error on the unexpected call,
	 * proving the architectural constraint via test failure.
	 */
	public function test_admin_roles_user_still_gets_admin_bar_simplified() {
		$core = $this->make_core( $this->active_config( array(
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array( 'editor' ),
		) ) );
		$admin = new CH_Admin_Bar( $core );

		// User holds both 'subscriber' (protected) and 'editor' (admin_role).
		// is_protected_user() checks protected_roles only — subscriber matches.
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber', 'editor' ) ),
		) );

		$bar = $this->make_admin_bar( array( 'wp-logo', 'site-name', 'my-account' ) );

		$admin->simplify_admin_bar();

		$this->assertContains( 'wp-logo', $bar->removed, 'Admin bar must be simplified even for an admin_roles user' );
		$this->assertNotContains( 'site-name',  $bar->removed );
		$this->assertNotContains( 'my-account', $bar->removed );
	}

	// =========================================================================
	// Test 7: cosmetic independence — activate_plugins hard floor still simplified
	// =========================================================================

	/**
	 * simplify_admin_bar() does NOT call is_exempt_from_enforcement(), so a user
	 * whose unfiltered role definition grants activate_plugins (the hard-floor
	 * exemption in the enforcement layer) still has the bar simplified.
	 *
	 * This test intentionally omits the wp_roles mock. If the method called
	 * user_has_cap_unfiltered() → wp_roles() the mock framework would error.
	 */
	public function test_activate_plugins_hard_floor_user_still_gets_admin_bar_simplified() {
		$core = $this->make_core( $this->active_config( array(
			'protected_roles' => array( 'custom_admin' ),
			'admin_roles'     => array(),
		) ) );
		$admin = new CH_Admin_Bar( $core );

		// custom_admin is in protected_roles and would grant activate_plugins in a
		// real WP_Roles definition — but we do NOT mock wp_roles here, proving the
		// hard-floor check (user_has_cap_unfiltered) is never reached.
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'custom_admin' ) ),
		) );

		$bar = $this->make_admin_bar( array( 'wp-logo', 'site-name', 'my-account' ) );

		$admin->simplify_admin_bar();

		$this->assertContains( 'wp-logo', $bar->removed,
			'Admin bar must be simplified even for a user who would hit the activate_plugins hard floor'
		);
		$this->assertNotContains( 'site-name',  $bar->removed );
		$this->assertNotContains( 'my-account', $bar->removed );
	}
}
