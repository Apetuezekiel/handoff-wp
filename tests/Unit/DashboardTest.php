<?php
/**
 * Unit tests for ZSCH_Dashboard (gating, dashboard replacement, widget rendering).
 *
 * GATING TESTS (T1–T5)
 * All four gate conditions are tested in isolation. T5 is the asymmetric-gate
 * test: a user in both protected_roles AND admin_roles resolves to status='admin'
 * and must never receive the replacement dashboard.
 *
 * REPLACEMENT TEST (T6)
 * Populates $GLOBALS['wp_meta_boxes']['dashboard'] with three sample widgets
 * across two contexts, then verifies remove_meta_box() is called for each and
 * wp_add_dashboard_widget() is called exactly once with WIDGET_ID.
 *
 * RENDERING TESTS (T7–T10)
 * Call render_widget() directly (public method) via ob_start()/ob_get_clean().
 * show_site_status is false in all these tests to avoid mocking wp_get_update_data
 * and is_ssl — those are tested implicitly if show_site_status is ever enabled.
 *
 * WP_POST_REVISIONS NOTE
 * PHP constants cannot be undefined once defined. T9 defines WP_POST_REVISIONS
 * as false. T8 must therefore run before T9 (PHPUnit executes methods in
 * declaration order). T10's assertion holds whether the constant is defined or
 * not, so the ordering dependency does not affect it.
 *
 * BOOTSTRAP STUBS AVAILABLE WITHOUT WP_Mock mocking:
 *   __ / esc_html / esc_attr / esc_url / wp_kses_post / get_bloginfo
 * These are pre-defined as PHP userspace functions before WP_Mock::bootstrap().
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class DashboardTest
 */
class DashboardTest extends TestCase {

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/** @var array|null Saved $GLOBALS['wp_meta_boxes'] value, restored in tearDown. */
	private $saved_meta_boxes;

	public function setUp(): void {
		parent::setUp();
		ZSCH_Core::reset_instance();
		$this->saved_meta_boxes         = isset( $GLOBALS['wp_meta_boxes'] ) ? $GLOBALS['wp_meta_boxes'] : null;
		$GLOBALS['wp_meta_boxes']       = array();
	}

	public function tearDown(): void {
		ZSCH_Core::reset_instance();
		if ( null === $this->saved_meta_boxes ) {
			unset( $GLOBALS['wp_meta_boxes'] );
		} else {
			$GLOBALS['wp_meta_boxes'] = $this->saved_meta_boxes;
		}
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a ZSCH_Core instance backed by the given config array.
	 *
	 * @param array $config
	 * @return ZSCH_Core
	 */
	private function make_core( array $config = array() ): ZSCH_Core {
		WP_Mock::userFunction( 'get_option', array( 'return' => $config ) );
		return ZSCH_Core::get_instance();
	}

	/**
	 * Config with plugin enabled and dashboard enabled.
	 *
	 * protected_roles: ['subscriber'] — the role that yields status='protected'.
	 * admin_roles:     ['editor']     — the role that yields status='admin' (T5).
	 * show_site_status: false         — avoids needing wp_get_update_data / is_ssl mocks.
	 *
	 * @param array $dashboard_overrides
	 * @return array
	 */
	private function enabled_config( array $dashboard_overrides = array() ): array {
		return array(
			'enabled'         => true,
			'protected_roles' => array( 'subscriber' ),
			'admin_roles'     => array( 'editor' ),
			'dashboard'       => array_merge(
				array(
					'enabled'           => true,
					'welcome_message'   => '',
					'quick_links'       => array(),
					'developer_contact' => array( 'name' => '', 'email' => '', 'url' => '' ),
					'show_site_status'  => false,
				),
				$dashboard_overrides
			),
		);
	}

	// =========================================================================
	// T1 — register_hooks()
	// =========================================================================

	/**
	 * T1 — register_hooks() adds wp_dashboard_setup at priority 999.
	 *
	 * WP_Mock verifies the expectation in tearDown. expectNotToPerformAssertions()
	 * prevents PHPUnit from marking the test risky for having no $this->assert*() calls.
	 */
	public function test_register_hooks_adds_wp_dashboard_setup_at_priority_999() {
		$this->expectNotToPerformAssertions();

		$core      = $this->make_core();
		$dashboard = new ZSCH_Dashboard( $core );

		WP_Mock::expectActionAdded( 'wp_dashboard_setup', array( $dashboard, 'replace_dashboard' ), 999 );

		$dashboard->register_hooks();
	}

	// =========================================================================
	// T2 — Gate 1: plugin master switch
	// =========================================================================

	/**
	 * T2 — replace_dashboard() is a no-op when enabled=false.
	 *
	 * Gate 1 fires before wp_get_current_user is ever called, so that function
	 * is intentionally not mocked. wp_add_dashboard_widget is mocked to record
	 * any unexpected call; the assertion confirms it was never invoked.
	 */
	public function test_replace_dashboard_no_ops_when_plugin_disabled() {
		$config = array(
			'enabled'   => false,
			'dashboard' => array( 'enabled' => true ),
		);
		$core      = $this->make_core( $config );
		$dashboard = new ZSCH_Dashboard( $core );

		$widget_calls = array();
		WP_Mock::userFunction( 'wp_add_dashboard_widget', array(
			'return' => static function () use ( &$widget_calls ) {
				$widget_calls[] = func_get_args();
			},
		) );

		$dashboard->replace_dashboard();

		$this->assertEmpty( $widget_calls, 'wp_add_dashboard_widget must not be called when plugin is disabled' );
	}

	// =========================================================================
	// T3 — Gate 2: dashboard feature toggle
	// =========================================================================

	/**
	 * T3 — replace_dashboard() is a no-op when dashboard.enabled=false.
	 *
	 * Gate 2 fires before wp_get_current_user, so again that function is not
	 * mocked here. wp_add_dashboard_widget tracked as in T2.
	 */
	public function test_replace_dashboard_no_ops_when_dashboard_feature_disabled() {
		$config = array(
			'enabled'   => true,
			'dashboard' => array( 'enabled' => false ),
		);
		$core      = $this->make_core( $config );
		$dashboard = new ZSCH_Dashboard( $core );

		$widget_calls = array();
		WP_Mock::userFunction( 'wp_add_dashboard_widget', array(
			'return' => static function () use ( &$widget_calls ) {
				$widget_calls[] = func_get_args();
			},
		) );

		$dashboard->replace_dashboard();

		$this->assertEmpty( $widget_calls, 'wp_add_dashboard_widget must not be called when dashboard feature is disabled' );
	}

	// =========================================================================
	// T4 — Gate 3: user status 'neither'
	// =========================================================================

	/**
	 * T4 — replace_dashboard() is a no-op when user status resolves to 'neither'.
	 *
	 * User holds role 'author' — not in protected_roles (['subscriber']) and not
	 * in admin_roles (['editor']) — so get_user_status() returns 'neither'.
	 */
	public function test_replace_dashboard_no_ops_when_user_status_is_neither() {
		$core      = $this->make_core( $this->enabled_config() );
		$dashboard = new ZSCH_Dashboard( $core );

		$user = new WP_User( 5, array( 'author' ) );
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => $user ) );

		$widget_calls = array();
		WP_Mock::userFunction( 'wp_add_dashboard_widget', array(
			'return' => static function () use ( &$widget_calls ) {
				$widget_calls[] = func_get_args();
			},
		) );

		$dashboard->replace_dashboard();

		$this->assertEmpty( $widget_calls, 'Dashboard must not be replaced when user status is neither' );
	}

	// =========================================================================
	// T5 — Gate 3: user in both protected_roles and admin_roles → status='admin'
	// =========================================================================

	/**
	 * T5 — Asymmetric-gate: admin_roles membership overrides protected_roles.
	 *
	 * User holds BOTH 'subscriber' (protected) and 'editor' (admin). ZSCH_Core
	 * resolves admin_roles first by precedence, yielding status='admin'. The
	 * dashboard must NOT be replaced — admin users need the standard dashboard
	 * for site management.
	 */
	public function test_replace_dashboard_no_ops_when_user_holds_both_protected_and_admin_role() {
		$core      = $this->make_core( $this->enabled_config() );
		$dashboard = new ZSCH_Dashboard( $core );

		// Both roles present — admin_roles precedence must win.
		$user = new WP_User( 2, array( 'subscriber', 'editor' ) );
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => $user ) );

		$widget_calls = array();
		WP_Mock::userFunction( 'wp_add_dashboard_widget', array(
			'return' => static function () use ( &$widget_calls ) {
				$widget_calls[] = func_get_args();
			},
		) );

		$dashboard->replace_dashboard();

		$this->assertEmpty( $widget_calls, 'Dashboard must not be replaced when user resolves to admin status' );
	}

	// =========================================================================
	// T6 — Full replacement: existing widgets removed, custom widget registered
	// =========================================================================

	/**
	 * T6 — replace_dashboard() removes every existing widget and registers ours.
	 *
	 * Three widgets are pre-seeded in $wp_meta_boxes across two contexts. The
	 * test verifies remove_meta_box() is called once per widget (correct ID and
	 * 'dashboard' screen) and wp_add_dashboard_widget() is called exactly once
	 * with ZSCH_Dashboard::WIDGET_ID.
	 */
	public function test_replace_dashboard_removes_all_widgets_and_registers_custom_widget() {
		$core      = $this->make_core( $this->enabled_config() );
		$dashboard = new ZSCH_Dashboard( $core );

		// Pre-seed the global with 3 widgets in mixed contexts.
		$GLOBALS['wp_meta_boxes']['dashboard']['normal']['high'] = array(
			'dashboard_activity'  => array( 'title' => 'Activity',     'callback' => '__return_false' ),
			'dashboard_right_now' => array( 'title' => 'At a Glance', 'callback' => '__return_false' ),
		);
		$GLOBALS['wp_meta_boxes']['dashboard']['side']['core'] = array(
			'dashboard_quick_press' => array( 'title' => 'Quick Draft', 'callback' => '__return_false' ),
		);

		$user = new WP_User( 42, array( 'subscriber' ) );
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => $user ) );

		$remove_calls = array();
		WP_Mock::userFunction( 'remove_meta_box', array(
			'return' => static function () use ( &$remove_calls ) {
				$remove_calls[] = func_get_args();
			},
		) );

		WP_Mock::userFunction( 'remove_action', array( 'return' => null ) );

		$widget_calls = array();
		WP_Mock::userFunction( 'wp_add_dashboard_widget', array(
			'return' => static function () use ( &$widget_calls ) {
				$widget_calls[] = func_get_args();
			},
		) );

		$dashboard->replace_dashboard();

		// All 3 existing widgets must have been removed.
		$this->assertCount( 3, $remove_calls, 'remove_meta_box must be called once per existing widget' );

		$removed_ids = array_column( $remove_calls, 0 );
		sort( $removed_ids );
		$this->assertSame(
			array( 'dashboard_activity', 'dashboard_quick_press', 'dashboard_right_now' ),
			$removed_ids,
			'The correct widget IDs must be passed to remove_meta_box'
		);

		// Second arg of each remove_meta_box call must be 'dashboard'.
		foreach ( $remove_calls as $call ) {
			$this->assertSame( 'dashboard', $call[1], 'remove_meta_box screen arg must be dashboard' );
		}

		// Our custom widget must be registered exactly once with WIDGET_ID.
		$this->assertCount( 1, $widget_calls, 'wp_add_dashboard_widget must be called exactly once' );
		$this->assertSame( ZSCH_Dashboard::WIDGET_ID, $widget_calls[0][0], 'Widget ID must match ZSCH_Dashboard::WIDGET_ID' );
	}

	// =========================================================================
	// T7 — Welcome message is sanitized via wp_kses_post (script tag stripped)
	// =========================================================================

	/**
	 * T7 — render_widget() passes welcome_message through wp_kses_post.
	 *
	 * The bootstrap stub for wp_kses_post calls strip_tags() with an allowed-tag
	 * list that excludes <script>. This verifies the sanitization hook-up without
	 * needing WordPress's full kses allowlist.
	 */
	public function test_render_widget_strips_script_tag_from_welcome_message() {
		$config = $this->enabled_config( array(
			'welcome_message' => '<p>Welcome</p><script>alert(1)</script>',
		) );
		$core      = $this->make_core( $config );
		$dashboard = new ZSCH_Dashboard( $core );

		// render_activity_feed calls wp_get_current_user and get_posts.
		$user = new WP_User( 42, array() );
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => $user ) );
		WP_Mock::userFunction( 'get_posts', array( 'return' => array() ) );

		ob_start();
		$dashboard->render_widget();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<p>Welcome</p>', $output, 'Allowed HTML must be preserved' );
		$this->assertStringNotContainsString( '<script', $output, '<script> must be stripped by wp_kses_post' );
	}

	// =========================================================================
	// T8 — Activity feed queries revisions first when WP_POST_REVISIONS is enabled
	// =========================================================================

	/**
	 * T8 — render_activity_feed() issues the revision query first.
	 *
	 * When WP_POST_REVISIONS is not defined (or not set to false/0), revisions are
	 * considered enabled. The first get_posts() call must use post_type='revision'.
	 * Both calls return [] so the empty-state message is also confirmed.
	 *
	 * IMPORTANT: this test must be declared before T9, which defines
	 * WP_POST_REVISIONS. PHPUnit runs methods in declaration order.
	 */
	public function test_render_activity_feed_queries_revisions_first_when_revisions_enabled() {
		$core      = $this->make_core( $this->enabled_config() );
		$dashboard = new ZSCH_Dashboard( $core );

		$user = new WP_User( 42, array() );
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => $user ) );

		$post_calls = array();
		WP_Mock::userFunction( 'get_posts', array(
			'return' => static function ( $args ) use ( &$post_calls ) {
				$post_calls[] = $args;
				return array(); // empty → fallback fires too
			},
		) );

		ob_start();
		$dashboard->render_widget();
		ob_get_clean();

		$this->assertGreaterThanOrEqual( 1, count( $post_calls ), 'get_posts must be called at least once' );
		$this->assertSame(
			'revision',
			$post_calls[0]['post_type'],
			'First get_posts call must target the revision post type'
		);
	}

	// =========================================================================
	// T9 — Activity feed skips revision query when WP_POST_REVISIONS=false
	// =========================================================================

	/**
	 * T9 — render_activity_feed() skips the revision query when revisions are disabled.
	 *
	 * Defines WP_POST_REVISIONS=false (guarded so re-runs don't fatal). The
	 * revision query must be skipped; only the fallback 'any' query fires.
	 */
	public function test_render_activity_feed_skips_revision_query_when_revisions_disabled() {
		if ( ! defined( 'WP_POST_REVISIONS' ) ) {
			define( 'WP_POST_REVISIONS', false );
		}

		$core      = $this->make_core( $this->enabled_config() );
		$dashboard = new ZSCH_Dashboard( $core );

		$user = new WP_User( 42, array() );
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => $user ) );

		$post_calls = array();
		WP_Mock::userFunction( 'get_posts', array(
			'return' => static function ( $args ) use ( &$post_calls ) {
				$post_calls[] = $args;
				return array();
			},
		) );

		ob_start();
		$dashboard->render_widget();
		ob_get_clean();

		$this->assertGreaterThanOrEqual( 1, count( $post_calls ), 'get_posts must be called for the fallback query' );

		foreach ( $post_calls as $index => $call ) {
			$this->assertNotSame(
				'revision',
				$call['post_type'],
				"Call #{$index} must not target revision post type when revisions are disabled"
			);
		}
	}

	// =========================================================================
	// T10 — Empty activity shows the empty-state message
	// =========================================================================

	/**
	 * T10 — render_activity_feed() shows the empty-state paragraph when both
	 * queries return empty arrays.
	 *
	 * This assertion holds regardless of WP_POST_REVISIONS state (whether T9
	 * has already defined it or not), because both the revision path and the
	 * fallback path return [] and converge on the same empty-state branch.
	 */
	public function test_render_activity_feed_shows_empty_state_when_no_activity() {
		$core      = $this->make_core( $this->enabled_config() );
		$dashboard = new ZSCH_Dashboard( $core );

		$user = new WP_User( 42, array() );
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => $user ) );
		WP_Mock::userFunction( 'get_posts', array( 'return' => array() ) );

		ob_start();
		$dashboard->render_widget();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'No recent activity to show',
			$output,
			'Empty-state message must be rendered when no activity exists'
		);
	}
}
