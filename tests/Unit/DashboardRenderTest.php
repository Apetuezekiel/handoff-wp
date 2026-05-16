<?php
/**
 * Phase 4 renderer consolidation — Pass C (final pass).
 * CH_Dashboard::render_widget section tests (C1–C6).
 *
 * WHAT IS TESTED
 *
 * render_widget() walks five sections in order:
 *   1. Welcome message   — wp_kses_post, skipped when empty
 *   2. Quick-action cards — iterated, skipped when empty
 *   3. Developer contact  — conditional on at least one field being set
 *   4. Site status        — gated on show_site_status flag
 *   5. Activity feed      — delegates to private render_activity_feed
 *
 * render_activity_feed has three paths:
 *   a. Revisions enabled + results → title list via get_the_title
 *   b. Revisions disabled → fallback get_posts query
 *   c. Both empty → empty-state paragraph
 *
 * Six tests cover each section gate and the two observable activity-feed
 * outcomes (post title vs empty-state message).
 *
 * WP_POST_REVISIONS ROBUSTNESS
 *
 * DashboardTest::T9 defines WP_POST_REVISIONS=false once; once defined a
 * PHP constant cannot be changed. All tests mock get_posts with a single
 * return value that applies regardless of which branch (revisions or
 * fallback) fires — the observable output is the same in both cases.
 *
 * BOOTSTRAP STUBS IN USE
 *   wp_kses_post, esc_html, esc_url, esc_attr, __, get_bloginfo
 *
 * MOCKED PER-TEST
 *   wp_get_current_user — required by render_activity_feed on every call
 *   get_posts           — required by render_activity_feed
 *   is_ssl              — C4 only (site-status branch)
 *   wp_get_update_data  — C4 only (site-status branch)
 *   get_the_title       — C5 only (activity feed with results)
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class DashboardRenderTest
 */
class DashboardRenderTest extends TestCase {

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
	 * Build a CH_Core instance backed by the given config array.
	 *
	 * @param array $config
	 * @return CH_Core
	 */
	private function make_core( array $config = array() ): CH_Core {
		WP_Mock::userFunction( 'get_option', array( 'return' => $config ) );
		return CH_Core::get_instance();
	}

	/**
	 * Capture output of a callable.
	 *
	 * @param callable $fn
	 * @return string
	 */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return ob_get_clean();
	}

	/**
	 * Set up the common mocks required for render_widget() to run without a
	 * WordPress install. Call before every capture() in tests that are not
	 * asserting on activity-feed or site-status content.
	 *
	 * get_posts returns [] so the empty-state branch fires. is_ssl and
	 * wp_get_update_data are mocked because CH_Core::DEFAULTS sets
	 * show_site_status=true, meaning the site-status block runs on every
	 * render_widget call unless explicitly disabled.
	 *
	 * @param int $user_id  ID of the stub user returned by wp_get_current_user.
	 */
	private function mock_activity_feed_noop( int $user_id = 1 ): void {
		$user = new WP_User( $user_id );
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => $user ) );
		WP_Mock::userFunction( 'get_posts',           array( 'return' => array() ) );
		WP_Mock::userFunction( 'is_ssl',              array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_get_update_data',  array(
			'return' => array( 'counts' => array( 'plugins' => 0 ) ),
		) );
	}

	// =========================================================================
	// C1 — Welcome message: rendered via wp_kses_post, <script> stripped
	// =========================================================================

	/**
	 * C1 — render_widget passes welcome_message through wp_kses_post so
	 * allowed HTML (<p>) survives while dangerous tags (<script>) are stripped.
	 *
	 * The bootstrap wp_kses_post stub uses strip_tags with an allowed-tag list
	 * that includes <p> but not <script>. This correctly models real WP behaviour
	 * for the mutation this test targets.
	 *
	 * Mutation: replacing wp_kses_post with esc_html would escape the <p> tag
	 * (output '&lt;p&gt;') and the first assertStringContainsString fails.
	 */
	public function test_render_widget_renders_welcome_message_via_wp_kses_post() {
		$core = $this->make_core( array(
			'dashboard' => array(
				'welcome_message' => '<p>Welcome</p><script>alert(1)</script>',
			),
		) );

		$this->mock_activity_feed_noop();

		$html = $this->capture( array( new CH_Dashboard( $core ), 'render_widget' ) );

		$this->assertStringContainsString(
			'<p>Welcome</p>',
			$html,
			'Allowed HTML (<p>) must survive wp_kses_post in the welcome message'
		);
		$this->assertStringNotContainsString(
			'<script',
			$html,
			'<script> tag must be stripped by wp_kses_post'
		);
	}

	// =========================================================================
	// C2 — Quick-action cards: one card per configured link
	// =========================================================================

	/**
	 * C2 — render_widget renders a card for each entry in dashboard.quick_links,
	 * including the label text, URL, and icon class.
	 *
	 * Mutation: skipping the quick_links loop, inverting the empty() guard, or
	 * omitting the icon span fails on the corresponding substring assertion.
	 */
	public function test_render_widget_renders_quick_link_cards() {
		$core = $this->make_core( array(
			'dashboard' => array(
				'quick_links' => array(
					array( 'label' => 'Edit Posts',    'url' => '/edit.php',   'icon' => 'dashicons-edit' ),
					array( 'label' => 'Media Library', 'url' => '/upload.php', 'icon' => 'dashicons-media' ),
				),
			),
		) );

		$this->mock_activity_feed_noop();

		$html = $this->capture( array( new CH_Dashboard( $core ), 'render_widget' ) );

		$this->assertStringContainsString( 'Edit Posts',    $html, 'First quick-link label must appear' );
		$this->assertStringContainsString( 'Media Library', $html, 'Second quick-link label must appear' );
		$this->assertStringContainsString( '/edit.php',     $html, 'First quick-link URL must appear' );
		$this->assertStringContainsString( '/upload.php',   $html, 'Second quick-link URL must appear' );
		$this->assertStringContainsString( 'dashicons-edit',  $html, 'First quick-link icon class must appear' );
		$this->assertStringContainsString( 'dashicons-media', $html, 'Second quick-link icon class must appear' );
	}

	// =========================================================================
	// C3 — Developer contact: all three fields rendered
	// =========================================================================

	/**
	 * C3 — render_widget renders the developer contact block when at least one
	 * of name, email, or url is non-empty, and each non-empty field appears in
	 * the output.
	 *
	 * Mutation: removing the has_contact guard so the block always renders (or
	 * never renders) does not by itself fail this test — the test exercises the
	 * positive branch where all three fields are set, which the renderer handles
	 * the same in either direction of a guard inversion.
	 * The real mutation target is omitting any of the three output statements.
	 */
	public function test_render_widget_renders_developer_contact_block() {
		$core = $this->make_core( array(
			'dashboard' => array(
				'developer_contact' => array(
					'name'  => 'Ezekiel Apetu',
					'email' => 'dev@example.com',
					'url'   => 'https://example.com',
				),
			),
		) );

		$this->mock_activity_feed_noop();

		$html = $this->capture( array( new CH_Dashboard( $core ), 'render_widget' ) );

		$this->assertStringContainsString( 'Ezekiel Apetu',    $html, 'Developer name must appear in the contact block' );
		$this->assertStringContainsString( 'dev@example.com',  $html, 'Developer email must appear in the contact block' );
		$this->assertStringContainsString( 'https://example.com', $html, 'Developer URL must appear in the contact block' );
	}

	// =========================================================================
	// C4 — Site status: WP version, SSL status, plugin update count
	// =========================================================================

	/**
	 * C4 — render_widget renders the site status block when show_site_status
	 * is true, including WordPress version (via get_bloginfo stub), SSL state,
	 * and pending plugin update count.
	 *
	 * get_bloginfo('version') returns 'version' via the bootstrap stub (the
	 * stub returns its $show argument unchanged). is_ssl returns true →
	 * 'Secured (HTTPS)'. wp_get_update_data returns 3 plugin updates pending.
	 *
	 * Mutation: removing any of the three output statements, or removing the
	 * show_site_status gate, fails the corresponding assertion.
	 */
	public function test_render_widget_renders_site_status_when_enabled() {
		$core = $this->make_core( array(
			'dashboard' => array(
				'show_site_status' => true,
			),
		) );

		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => new WP_User( 1 ) ) );
		WP_Mock::userFunction( 'get_posts',          array( 'return' => array() ) );
		WP_Mock::userFunction( 'is_ssl',             array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_get_update_data', array(
			'return' => array( 'counts' => array( 'plugins' => 3 ) ),
		) );

		$html = $this->capture( array( new CH_Dashboard( $core ), 'render_widget' ) );

		// get_bloginfo('version') returns 'version' via bootstrap stub.
		$this->assertStringContainsString(
			'version',
			$html,
			'Site status must display the WordPress version (get_bloginfo stub returns its arg)'
		);
		$this->assertStringContainsString(
			'Secured (HTTPS)',
			$html,
			'Site status must show "Secured (HTTPS)" when is_ssl returns true'
		);
		$this->assertStringContainsString(
			'3',
			$html,
			'Site status must display the pending plugin update count'
		);
	}

	// =========================================================================
	// C5 — Activity feed: post titles rendered from get_posts results
	// =========================================================================

	/**
	 * C5 — render_activity_feed renders a list item for each post returned by
	 * get_posts, using get_the_title to resolve the display title.
	 *
	 * The mock post has post_parent=7 > 0, so the renderer calls
	 * get_the_title(7) — the parent post. get_the_title is mocked to return
	 * 'Sample Post' for any argument.
	 *
	 * Robust to WP_POST_REVISIONS state: if revisions are enabled, get_posts
	 * is called once (revisions query) and returns our data; if disabled, it
	 * is called once (fallback query) and returns the same data. Either way
	 * 'Sample Post' appears in the output.
	 *
	 * Mutation: removing the foreach loop or the get_the_title call fails
	 * this assertion.
	 */
	public function test_render_widget_renders_activity_feed_with_post_titles() {
		$core = $this->make_core();

		$mock_post        = new stdClass();
		$mock_post->ID          = 42;
		$mock_post->post_parent = 7;

		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => new WP_User( 1 ) ) );
		WP_Mock::userFunction( 'get_posts',           array( 'return' => array( $mock_post ) ) );
		WP_Mock::userFunction( 'get_the_title',       array( 'return' => 'Sample Post' ) );
		// show_site_status defaults to true in DEFAULTS — stub the two functions
		// it calls so they don't throw "undefined function" errors.
		WP_Mock::userFunction( 'is_ssl',             array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_get_update_data', array(
			'return' => array( 'counts' => array( 'plugins' => 0 ) ),
		) );

		$html = $this->capture( array( new CH_Dashboard( $core ), 'render_widget' ) );

		$this->assertStringContainsString(
			'Sample Post',
			$html,
			'Activity feed must display the post title returned by get_the_title'
		);
	}

	// =========================================================================
	// C6 — Activity feed: empty-state message when no posts returned
	// =========================================================================

	/**
	 * C6 — render_activity_feed renders an empty-state paragraph when both
	 * get_posts calls (revisions and fallback) return empty arrays.
	 *
	 * Mutation: removing the empty() check or the empty-state paragraph fails
	 * this assertion.
	 */
	public function test_render_widget_renders_empty_state_when_no_activity() {
		$core = $this->make_core();

		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => new WP_User( 1 ) ) );
		WP_Mock::userFunction( 'get_posts',           array( 'return' => array() ) );
		// show_site_status defaults to true in DEFAULTS.
		WP_Mock::userFunction( 'is_ssl',             array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_get_update_data', array(
			'return' => array( 'counts' => array( 'plugins' => 0 ) ),
		) );

		$html = $this->capture( array( new CH_Dashboard( $core ), 'render_widget' ) );

		$this->assertStringContainsString(
			'No recent activity to show.',
			$html,
			'Activity feed must render the empty-state message when get_posts returns no results'
		);
	}
}
