<?php
/**
 * Unit tests for ZSCH_Menu_Manager.
 *
 * WP_Mock limitation: add_action() calls are expectation assertions, not a real
 * hook pipeline. All tests drive apply_menu_hiding() and get_admin_menu_snapshot()
 * directly without relying on hook dispatch.
 *
 * Cosmetic-layer independence: apply_menu_hiding() intentionally does NOT call
 * is_exempt_from_enforcement(), is_protected_user(), or any lockout helper.
 * Tests 7 and 8 prove this by omitting wp_roles/is_multisite mocks entirely —
 * if the method tried to call those helpers the mock framework would error on
 * the unexpected function call.
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class MenuManagerTest
 */
class MenuManagerTest extends TestCase {

	/** @var array|null Saved $menu global */
	private $saved_menu;

	/** @var array|null Saved $submenu global */
	private $saved_submenu;

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public function setUp(): void {
		parent::setUp();
		ZSCH_Core::reset_instance();

		global $menu, $submenu;
		$this->saved_menu    = $menu;
		$this->saved_submenu = $submenu;
	}

	public function tearDown(): void {
		global $menu, $submenu;
		$menu    = $this->saved_menu;
		$submenu = $this->saved_submenu;

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
	 * Track calls to remove_menu_page and remove_submenu_page.
	 *
	 * @param array $top_calls Reference — collects slugs passed to remove_menu_page.
	 * @param array $sub_calls Reference — collects [parent, child] pairs from remove_submenu_page.
	 */
	private function mock_remove_functions( array &$top_calls, array &$sub_calls ) {
		WP_Mock::userFunction( 'remove_menu_page', array(
			'return' => static function ( $slug ) use ( &$top_calls ) {
				$top_calls[] = $slug;
			},
		) );
		WP_Mock::userFunction( 'remove_submenu_page', array(
			'return' => static function ( $parent, $child ) use ( &$sub_calls ) {
				$sub_calls[] = array( $parent, $child );
			},
		) );
	}

	// -------------------------------------------------------------------------
	// Test 1: top-level menu item hidden
	// -------------------------------------------------------------------------

	/**
	 * A bare slug in hidden_menus calls remove_menu_page, not remove_submenu_page.
	 */
	public function test_top_level_slug_calls_remove_menu_page() {
		$core    = $this->make_core( array(
			'enabled'     => true,
			'menu_hiding' => array( 'hidden_menus' => array( 'subscriber' => array( 'plugins.php' ) ) ),
		) );
		$manager = new ZSCH_Menu_Manager( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => new WP_User( 1, array( 'subscriber' ) ),
		) );

		$top_calls = array();
		$sub_calls = array();
		$this->mock_remove_functions( $top_calls, $sub_calls );

		$manager->apply_menu_hiding();

		$this->assertSame( array( 'plugins.php' ), $top_calls, 'remove_menu_page must be called with the bare slug' );
		$this->assertEmpty( $sub_calls, 'remove_submenu_page must not be called for a bare slug' );
	}

	// -------------------------------------------------------------------------
	// Test 2: submenu item hidden via pipe slug
	// -------------------------------------------------------------------------

	/**
	 * A pipe-separated slug calls remove_submenu_page with the correct parent/child.
	 */
	public function test_pipe_slug_calls_remove_submenu_page() {
		$core    = $this->make_core( array(
			'enabled'     => true,
			'menu_hiding' => array( 'hidden_menus' => array( 'subscriber' => array( 'options-general.php|options-writing.php' ) ) ),
		) );
		$manager = new ZSCH_Menu_Manager( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => new WP_User( 2, array( 'subscriber' ) ),
		) );

		$top_calls = array();
		$sub_calls = array();
		$this->mock_remove_functions( $top_calls, $sub_calls );

		$manager->apply_menu_hiding();

		$this->assertEmpty( $top_calls, 'remove_menu_page must not be called for a pipe slug' );
		$this->assertSame(
			array( array( 'options-general.php', 'options-writing.php' ) ),
			$sub_calls,
			'remove_submenu_page must receive correct parent and child slugs'
		);
	}

	// -------------------------------------------------------------------------
	// Test 3: mixed slugs in one role's list
	// -------------------------------------------------------------------------

	/**
	 * A role list with both bare and pipe slugs calls both remove functions once each.
	 */
	public function test_mixed_slugs_call_both_remove_functions() {
		$core    = $this->make_core( array(
			'enabled'     => true,
			'menu_hiding' => array( 'hidden_menus' => array(
				'editor' => array(
					'plugins.php',
					'options-general.php|options-writing.php',
				),
			) ),
		) );
		$manager = new ZSCH_Menu_Manager( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => new WP_User( 3, array( 'editor' ) ),
		) );

		$top_calls = array();
		$sub_calls = array();
		$this->mock_remove_functions( $top_calls, $sub_calls );

		$manager->apply_menu_hiding();

		$this->assertSame( array( 'plugins.php' ), $top_calls );
		$this->assertSame(
			array( array( 'options-general.php', 'options-writing.php' ) ),
			$sub_calls
		);
	}

	// -------------------------------------------------------------------------
	// Test 4: multi-role user gets slugs from all matching roles applied
	// -------------------------------------------------------------------------

	/**
	 * When the user holds two roles and both appear in hidden_menus, all slugs
	 * from both role lists are removed.
	 */
	public function test_multi_role_user_applies_all_matching_roles() {
		$core    = $this->make_core( array(
			'enabled'     => true,
			'menu_hiding' => array( 'hidden_menus' => array(
				'subscriber' => array( 'plugins.php' ),
				'author'     => array( 'themes.php' ),
			) ),
		) );
		$manager = new ZSCH_Menu_Manager( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => new WP_User( 4, array( 'subscriber', 'author' ) ),
		) );

		$top_calls = array();
		$sub_calls = array();
		$this->mock_remove_functions( $top_calls, $sub_calls );

		$manager->apply_menu_hiding();

		$this->assertContains( 'plugins.php', $top_calls );
		$this->assertContains( 'themes.php', $top_calls );
		$this->assertCount( 2, $top_calls );
		$this->assertEmpty( $sub_calls );
	}

	// -------------------------------------------------------------------------
	// Test 5: plugin disabled — neither remove function called
	// -------------------------------------------------------------------------

	/**
	 * When the plugin is disabled the method returns before any remove call.
	 */
	public function test_disabled_plugin_skips_all_removal() {
		$core    = $this->make_core( array( 'enabled' => false ) );
		$manager = new ZSCH_Menu_Manager( $core );

		$top_calls = array();
		$sub_calls = array();
		$this->mock_remove_functions( $top_calls, $sub_calls );

		$manager->apply_menu_hiding();

		$this->assertEmpty( $top_calls, 'remove_menu_page must not be called when plugin is disabled' );
		$this->assertEmpty( $sub_calls, 'remove_submenu_page must not be called when plugin is disabled' );
	}

	// -------------------------------------------------------------------------
	// Test 6: no matching role — neither remove function called
	// -------------------------------------------------------------------------

	/**
	 * When the user's role does not appear in hidden_menus, nothing is removed.
	 */
	public function test_user_role_not_in_hidden_menus_skips_removal() {
		$core    = $this->make_core( array(
			'enabled'     => true,
			'menu_hiding' => array( 'hidden_menus' => array( 'subscriber' => array( 'plugins.php' ) ) ),
		) );
		$manager = new ZSCH_Menu_Manager( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => new WP_User( 5, array( 'author' ) ),
		) );

		$top_calls = array();
		$sub_calls = array();
		$this->mock_remove_functions( $top_calls, $sub_calls );

		$manager->apply_menu_hiding();

		$this->assertEmpty( $top_calls, 'No menu removal when role not in hidden_menus' );
		$this->assertEmpty( $sub_calls );
	}

	// -------------------------------------------------------------------------
	// Test 7: cosmetic independence — admin_roles user still gets menus hidden
	// -------------------------------------------------------------------------

	/**
	 * apply_menu_hiding() does NOT call is_exempt_from_enforcement(), so a user
	 * whose role appears in admin_roles still has menus removed if that role is
	 * also in hidden_menus. This test intentionally omits wp_roles/is_multisite
	 * mocks — if the method called those helpers the mock framework would error.
	 */
	public function test_admin_roles_user_still_gets_menus_hidden() {
		$core    = $this->make_core( array(
			'enabled'     => true,
			'admin_roles' => array( 'administrator' ),
			'menu_hiding' => array( 'hidden_menus' => array( 'administrator' => array( 'plugins.php' ) ) ),
		) );
		$manager = new ZSCH_Menu_Manager( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => new WP_User( 1, array( 'administrator' ) ),
		) );

		$top_calls = array();
		$sub_calls = array();
		$this->mock_remove_functions( $top_calls, $sub_calls );

		$manager->apply_menu_hiding();

		$this->assertSame(
			array( 'plugins.php' ),
			$top_calls,
			'Menu hiding applies even when user role appears in admin_roles'
		);
	}

	// -------------------------------------------------------------------------
	// Test 8: cosmetic independence — non-protected role still gets menus hidden
	// -------------------------------------------------------------------------

	/**
	 * apply_menu_hiding() does NOT consult protected_roles. A user whose role is
	 * absent from protected_roles still has menus hidden if the role appears in
	 * hidden_menus.
	 */
	public function test_non_protected_role_still_gets_menus_hidden() {
		$core    = $this->make_core( array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'menu_hiding'     => array( 'hidden_menus' => array( 'shop_manager' => array( 'plugins.php' ) ) ),
		) );
		$manager = new ZSCH_Menu_Manager( $core );

		WP_Mock::userFunction( 'wp_get_current_user', array(
			'return' => new WP_User( 6, array( 'shop_manager' ) ),
		) );

		$top_calls = array();
		$sub_calls = array();
		$this->mock_remove_functions( $top_calls, $sub_calls );

		$manager->apply_menu_hiding();

		$this->assertSame(
			array( 'plugins.php' ),
			$top_calls,
			'Menu hiding applies even when user role is absent from protected_roles'
		);
	}

	// -------------------------------------------------------------------------
	// Test 9: get_admin_menu_snapshot returns structured snapshot
	// -------------------------------------------------------------------------

	/**
	 * get_admin_menu_snapshot() reads $menu/$submenu globals and returns a
	 * structured array with top_level and submenu keys. Separators and empty
	 * slugs are excluded. HTML in labels is stripped.
	 */
	public function test_get_admin_menu_snapshot_returns_structured_data() {
		$core    = $this->make_core( array( 'enabled' => true ) );
		$manager = new ZSCH_Menu_Manager( $core );

		global $menu, $submenu;

		$menu = array(
			array( 'Dashboard', 'read', 'index.php', '', '' ),
			array( '', '', 'separator1', '', '' ),
			array( 'Plugins <span class="badge">2</span>', 'activate_plugins', 'plugins.php', '', '' ),
		);

		$submenu = array(
			'options-general.php' => array(
				array( 'General', 'manage_options', 'options-general.php' ),
				array( 'Writing', 'manage_options', 'options-writing.php' ),
			),
		);

		$snapshot = $manager->get_admin_menu_snapshot();

		$this->assertCount( 2, $snapshot['top_level'] );

		$this->assertSame( 'index.php', $snapshot['top_level'][0]['slug'] );
		$this->assertSame( 'Dashboard', $snapshot['top_level'][0]['label'] );

		$this->assertSame( 'plugins.php', $snapshot['top_level'][1]['slug'] );
		$this->assertSame( 'Plugins 2', $snapshot['top_level'][1]['label'], 'HTML tags must be stripped from labels' );

		$this->assertArrayHasKey( 'options-general.php', $snapshot['submenu'] );
		$children = $snapshot['submenu']['options-general.php'];
		$this->assertCount( 2, $children );
		$this->assertSame( 'options-general.php', $children[0]['slug'] );
		$this->assertSame( 'General', $children[0]['label'] );
		$this->assertSame( 'options-writing.php', $children[1]['slug'] );
		$this->assertSame( 'Writing', $children[1]['label'] );
	}
}
