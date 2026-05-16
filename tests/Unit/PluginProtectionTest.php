<?php
/**
 * Unit tests for CH_Plugin_Protection (action-link removal + admin_init intercept).
 *
 * Two test groups:
 *   1. Action-link filter — calls filter_plugin_action_links() directly with an
 *      $actions array and a plugin basename. Unit-level; does not fire the real
 *      plugin_action_links filter pipeline. Integration coverage (register_hooks()
 *      → apply_filters('plugin_action_links', ...)) is deferred to Phase 4.
 *
 *   2. Admin-init intercept — populates $_REQUEST and calls intercept_plugin_action()
 *      directly. Unit-level; does not fire the real admin_init action pipeline.
 *      Integration coverage is deferred to Phase 4.
 *
 * No-op assertions use $assertFalse($wp_die_called) with a tracked wp_die() mock.
 * Die-path assertions use assertTrue($wp_die_called). Neither group uses assertTrue(true).
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class PluginProtectionTest
 */
class PluginProtectionTest extends TestCase {

	/** @var array Saved $_REQUEST state restored in tearDown. */
	private $saved_request;

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public function setUp(): void {
		parent::setUp();
		CH_Core::reset_instance();
		$this->saved_request = $_REQUEST;
	}

	public function tearDown(): void {
		$_REQUEST = $this->saved_request;
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
	 * @param array<string, array<string, bool>> $roles_data
	 * @return WP_Roles
	 */
	private function make_wp_roles( array $roles_data ) {
		return new WP_Roles( $roles_data );
	}

	/**
	 * Config for a standard "plugin enabled, subscriber protected, one protected
	 * plugin" scenario.
	 *
	 * @param array $overrides Top-level key overrides (shallow merge).
	 * @return array
	 */
	private function active_config( array $overrides = array() ) {
		return array_merge(
			array(
				'enabled'         => true,
				'protected_roles' => array( 'subscriber' ),
				'admin_roles'     => array(),
				'enforcement'     => array(
					'blocked_caps'      => array(),
					'screen_blocklist'  => array(),
					'protected_plugins' => array( 'protected/protected.php' ),
				),
				'dashboard'       => array(
					'enabled'           => false,
					'welcome_message'   => '',
					'quick_links'       => array(),
					'developer_contact' => array(
						'name'  => 'Dev',
						'email' => 'dev@example.com',
						'url'   => '',
					),
					'show_site_status'  => true,
				),
			),
			$overrides
		);
	}

	/**
	 * Standard set of action links that covers all keys the filter might touch.
	 *
	 * @return array<string, string>
	 */
	private function full_actions() {
		return array(
			'activate'   => '<a>Activate</a>',
			'deactivate' => '<a>Deactivate</a>',
			'delete'     => '<a>Delete</a>',
			'edit'       => '<a>Edit</a>',
		);
	}

	/**
	 * Register a tracked wp_die() mock. The $called flag is set to true if
	 * wp_die() fires. Use assertFalse($called) for no-op paths, assertTrue for
	 * die paths. WP_Mock uses 'return' => Closure for callback-based returns.
	 *
	 * @param bool $called Reference variable to track invocation.
	 */
	private function mock_wp_die( &$called ) {
		WP_Mock::userFunction( 'wp_die', array(
			'return' => static function () use ( &$called ) {
				$called = true;
			},
		) );
	}

	// =========================================================================
	// Action-link filter
	// =========================================================================

	/**
	 * 'deactivate' and 'delete' are removed for a protected user on a protected
	 * plugin. All other keys ('activate', 'edit') are preserved untouched.
	 *
	 * @test
	 */
	public function test_action_links_removes_deactivate_and_delete_for_protected_plugin() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );

		$result = $protection->filter_plugin_action_links(
			$this->full_actions(),
			'protected/protected.php'
		);

		$this->assertArrayNotHasKey( 'deactivate', $result, "'deactivate' must be stripped" );
		$this->assertArrayNotHasKey( 'delete', $result, "'delete' must be stripped" );
		$this->assertArrayHasKey( 'activate', $result, "'activate' must be preserved" );
		$this->assertArrayHasKey( 'edit', $result, "'edit' must be preserved" );
	}

	/**
	 * $actions is returned unchanged for a non-protected plugin.
	 *
	 * The plugin-in-list check fires before wp_get_current_user() — no user or
	 * role mocks are needed. If those mocks were required, it would mean the
	 * per-plugin early return is not working.
	 *
	 * @test
	 */
	public function test_action_links_unchanged_for_non_protected_plugin() {
		// No wp_get_current_user / is_multisite / wp_roles mocks: the filter
		// returns at the protected_plugins check before touching the user.
		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$actions    = $this->full_actions();

		$result = $protection->filter_plugin_action_links( $actions, 'other/other.php' );

		$this->assertSame( $actions, $result, '$actions must be untouched for a non-protected plugin' );
	}

	/**
	 * $actions is returned unchanged for a non-protected user.
	 *
	 * @test
	 */
	public function test_action_links_unchanged_for_non_protected_user() {
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'author' ) ), // author not in protected_roles.
		) );

		$core       = $this->make_core( $this->active_config() ); // protected_roles=['subscriber']
		$protection = new CH_Plugin_Protection( $core );
		$actions    = $this->full_actions();

		$result = $protection->filter_plugin_action_links( $actions, 'protected/protected.php' );

		$this->assertSame( $actions, $result, '$actions must be untouched for a non-protected user' );
	}

	/**
	 * $actions is returned unchanged when the plugin is disabled.
	 *
	 * @test
	 */
	public function test_action_links_unchanged_when_plugin_disabled() {
		// disabled check is the very first → no other mocks needed.
		$core       = $this->make_core( array( 'enabled' => false ) );
		$protection = new CH_Plugin_Protection( $core );
		$actions    = $this->full_actions();

		$result = $protection->filter_plugin_action_links( $actions, 'protected/protected.php' );

		$this->assertSame( $actions, $result, '$actions must be untouched when disabled' );
	}

	/**
	 * $actions is returned unchanged for user ID 1 (always-exempt lockout).
	 *
	 * @test
	 */
	public function test_action_links_unchanged_for_exempt_user_id_1() {
		// ID=1 exemption fires before is_multisite() — no extra mocks needed.
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 1, array( 'subscriber' ) ),
		) );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$actions    = $this->full_actions();

		$result = $protection->filter_plugin_action_links( $actions, 'protected/protected.php' );

		$this->assertSame( $actions, $result, '$actions must be untouched for user ID 1' );
	}

	/**
	 * $actions is returned unchanged for a user in admin_roles (admin precedence).
	 *
	 * The user holds both subscriber (protected) and editor (admin_role) roles so
	 * is_protected_user() returns true and only the admin exemption prevents stripping.
	 *
	 * @test
	 */
	public function test_action_links_unchanged_for_exempt_user_in_admin_roles() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber', 'editor' ) ),
		) );

		$config     = $this->active_config( array( 'admin_roles' => array( 'editor' ) ) );
		$core       = $this->make_core( $config );
		$protection = new CH_Plugin_Protection( $core );
		$actions    = $this->full_actions();

		$result = $protection->filter_plugin_action_links( $actions, 'protected/protected.php' );

		$this->assertSame( $actions, $result, '$actions must be untouched for an admin_role user' );
	}

	/**
	 * $actions is returned unchanged for a user with activate_plugins in their
	 * unfiltered role definition (hard-floor lockout safeguard).
	 *
	 * @test
	 */
	public function test_action_links_unchanged_for_exempt_user_with_activate_plugins() {
		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'custom_admin' => array(
					'read'             => true,
					'activate_plugins' => true,
				),
			) ),
		) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'custom_admin' ) ),
		) );

		$config = $this->active_config( array(
			'protected_roles' => array( 'custom_admin' ),
		) );
		$core       = $this->make_core( $config );
		$protection = new CH_Plugin_Protection( $core );
		$actions    = $this->full_actions();

		$result = $protection->filter_plugin_action_links( $actions, 'protected/protected.php' );

		$this->assertSame( $actions, $result, '$actions must be untouched for activate_plugins hard-floor' );
	}

	// =========================================================================
	// Admin-init request intercept
	// =========================================================================

	/**
	 * action=deactivate with a protected plugin basename in $_REQUEST['plugin']
	 * triggers wp_die() for a protected user.
	 *
	 * @test
	 */
	public function test_intercept_deactivate_single_protected_plugin_triggers_die() {
		$_REQUEST['action'] = 'deactivate';
		$_REQUEST['plugin'] = 'protected/protected.php';

		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertTrue( $wp_die_called, 'wp_die() must be called when deactivating a protected plugin' );
	}

	/**
	 * action=deactivate-selected with a protected plugin in $_REQUEST['checked']
	 * triggers wp_die().
	 *
	 * @test
	 */
	public function test_intercept_deactivate_selected_protected_plugin_triggers_die() {
		$_REQUEST['action']  = 'deactivate-selected';
		$_REQUEST['checked'] = array( 'protected/protected.php' );

		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertTrue( $wp_die_called, 'wp_die() must be called for deactivate-selected on a protected plugin' );
	}

	/**
	 * action=delete-selected with a single protected plugin in checked[] triggers
	 * wp_die().
	 *
	 * @test
	 */
	public function test_intercept_delete_selected_single_protected_plugin_triggers_die() {
		$_REQUEST['action']  = 'delete-selected';
		$_REQUEST['checked'] = array( 'protected/protected.php' );

		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertTrue( $wp_die_called, 'wp_die() must be called for delete-selected on a protected plugin' );
	}

	/**
	 * action=delete-selected with a bulk checked[] where ONE entry is protected
	 * triggers wp_die() — the entire bulk request is blocked.
	 *
	 * @test
	 */
	public function test_intercept_delete_selected_bulk_one_protected_triggers_die() {
		$_REQUEST['action']  = 'delete-selected';
		$_REQUEST['checked'] = array( 'other/other.php', 'protected/protected.php', 'another/another.php' );

		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertTrue( $wp_die_called, 'wp_die() must be called when any checked[] entry is a protected plugin' );
	}

	/**
	 * action=delete-selected with no protected plugins in checked[] is a no-op.
	 *
	 * @test
	 */
	public function test_intercept_delete_selected_no_protected_plugin_is_noop() {
		$_REQUEST['action']  = 'delete-selected';
		$_REQUEST['checked'] = array( 'other/other.php', 'another/another.php' );

		WP_Mock::userFunction( 'is_multisite', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_roles', array(
			'return' => $this->make_wp_roles( array(
				'subscriber' => array( 'read' => true ),
			) ),
		) );
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'subscriber' ) ),
		) );

		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called when no checked[] entry is protected' );
	}

	/**
	 * A non-matching action (e.g. 'activate') short-circuits immediately — no WP
	 * calls at all, so no mocks beyond wp_die tracking are required.
	 *
	 * @test
	 */
	public function test_intercept_non_matching_action_is_noop() {
		$_REQUEST['action'] = 'activate';
		$_REQUEST['plugin'] = 'protected/protected.php';

		// Cheapest early-return: no user, no core calls after the action check.
		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called for a non-matching action' );
	}

	/**
	 * No 'action' key in $_REQUEST is a no-op.
	 *
	 * @test
	 */
	public function test_intercept_no_action_param_is_noop() {
		unset( $_REQUEST['action'] );

		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called when no action param is present' );
	}

	/**
	 * A non-protected user targeting a protected plugin is a no-op.
	 *
	 * @test
	 */
	public function test_intercept_non_protected_user_is_noop() {
		$_REQUEST['action']  = 'deactivate-selected';
		$_REQUEST['checked'] = array( 'protected/protected.php' );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 5, array( 'author' ) ), // author not in protected_roles.
		) );

		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( $this->active_config() ); // protected_roles=['subscriber']
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called for a non-protected user' );
	}

	/**
	 * User ID 1 (always-exempt lockout) targeting a protected plugin is a no-op.
	 *
	 * @test
	 */
	public function test_intercept_exempt_user_id_1_is_noop() {
		$_REQUEST['action']  = 'deactivate-selected';
		$_REQUEST['checked'] = array( 'protected/protected.php' );

		// ID=1 exemption fires before is_multisite() — no extra mocks needed.
		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => $this->make_user( 1, array( 'subscriber' ) ),
		) );

		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( $this->active_config() );
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called for user ID 1 (always-exempt safeguard)' );
	}

	/**
	 * Plugin disabled — all intercept paths are skipped.
	 *
	 * @test
	 */
	public function test_intercept_disabled_plugin_is_noop() {
		$_REQUEST['action']  = 'deactivate-selected';
		$_REQUEST['checked'] = array( 'protected/protected.php' );

		// enabled check fires before wp_get_current_user() — no user mock needed.
		$wp_die_called = false;
		$this->mock_wp_die( $wp_die_called );

		$core       = $this->make_core( array( 'enabled' => false ) );
		$protection = new CH_Plugin_Protection( $core );
		$protection->intercept_plugin_action();

		$this->assertFalse( $wp_die_called, 'wp_die() must not be called when plugin is disabled' );
	}
}
