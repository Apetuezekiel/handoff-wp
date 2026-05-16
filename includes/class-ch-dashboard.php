<?php
/**
 * Client operational dashboard.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CH_Dashboard
 *
 * Replaces the default WP dashboard for protected users with a purpose-built
 * widget (brief § 3.1).
 *
 * GATING — three layered checks evaluated in cheapest-first order:
 *
 *   1. enabled=false → no-op. The top-level handoff toggle is off; the
 *      entire plugin is inert, so the dashboard must not be touched.
 *
 *   2. dashboard.enabled=false → no-op. The developer has enabled handoff
 *      but chosen not to replace the dashboard (e.g. they only want
 *      enforcement restrictions). Separating this flag lets the developer
 *      opt the dashboard sub-feature on or off independently.
 *
 *   3. Current user status must be 'protected' — checked via
 *      CH_Core::get_user_status($user), not is_protected_user().
 *      Reason: admin_roles members must keep the standard dashboard for
 *      site management. A user in BOTH protected_roles AND admin_roles
 *      resolves to status 'admin' by precedence and receives the standard
 *      dashboard unchanged.
 *
 * GATING ASYMMETRY WITH THE COSMETIC LAYER
 *
 * The cosmetic layer (CH_Menu_Manager, CH_Admin_Bar, CH_Notifications) does
 * NOT check admin status — it applies to all users in the relevant role maps.
 * CH_Dashboard explicitly does check admin status because dashboard
 * replacement is an experience substitution, not a restriction, and admin
 * users actively need the standard dashboard to manage the site. This
 * asymmetry is intentional; document it here so it is not "fixed" later.
 *
 * PERMITTED-SCREENS FILTER — DEFERRED (Phase 4)
 *
 * The brief expects this class to register on the
 * 'client_handoff_permitted_screens' filter to add quick_link targets to the
 * screen-guard allowlist. Deferred because the current quick_links data model
 * stores URLs, not screen IDs, and URL-to-screen-ID derivation is
 * non-trivial. A developer who adds a quick-link target to screen_blocklist
 * could create a contradiction — acceptable for this sub-pass. Resolve when
 * the Dashboard settings tab lands with a screen-ID-capture flow.
 *
 * SITE STATUS — LAST BACKUP DATE DEFERRED (Phase 4)
 *
 * There is no standard WordPress API for querying the last backup date across
 * the ecosystem of backup plugins (UpdraftPlus, BackWPup, etc. all expose
 * proprietary data). Deferred until a strategy (filter hook or explicit
 * plugin detection) is agreed.
 */
class CH_Dashboard {

	/** @var string Dashboard widget ID. */
	const WIDGET_ID = 'ch_client_handoff_dashboard';

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
	 * Priority 999 ensures this fires after every plugin has registered its
	 * own dashboard widgets, giving us a complete picture of what to remove.
	 */
	public function register_hooks() {
		add_action( 'wp_dashboard_setup', array( $this, 'replace_dashboard' ), 999 );
	}

	// -------------------------------------------------------------------------
	// Dashboard replacement
	// -------------------------------------------------------------------------

	/**
	 * Remove all existing dashboard widgets and register our single custom widget.
	 *
	 * Runs on wp_dashboard_setup at priority 999. Applies the three-layer gate
	 * (plugin enabled → dashboard enabled → user is 'protected') before
	 * touching anything.
	 */
	public function replace_dashboard() {
		// Gate 1 — plugin master switch.
		if ( ! $this->core->get( 'enabled' ) ) {
			return;
		}

		// Gate 2 — dashboard feature toggle.
		$dashboard_config = $this->core->get( 'dashboard' );
		if ( empty( $dashboard_config['enabled'] ) ) {
			return;
		}

		// Gate 3 — user must be 'protected', not 'admin' or 'neither'.
		$user = wp_get_current_user();
		if ( 'protected' !== $this->core->get_user_status( $user ) ) {
			return;
		}

		// Remove all registered dashboard meta boxes.
		global $wp_meta_boxes;
		$contexts   = array( 'normal', 'side', 'column3', 'column4' );
		$priorities = array( 'high', 'core', 'default', 'low' );

		foreach ( $contexts as $context ) {
			if ( ! isset( $wp_meta_boxes['dashboard'][ $context ] ) ) {
				continue;
			}
			foreach ( $priorities as $priority ) {
				if ( ! isset( $wp_meta_boxes['dashboard'][ $context ][ $priority ] ) ) {
					continue;
				}
				foreach ( $wp_meta_boxes['dashboard'][ $context ][ $priority ] as $id => $widget ) {
					remove_meta_box( $id, 'dashboard', $context );
				}
			}
		}

		// Remove the welcome panel (shown above meta boxes on fresh installs).
		remove_action( 'welcome_panel', 'wp_welcome_panel' );

		// Register our single replacement widget.
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Client Dashboard', 'client-handoff' ),
			array( $this, 'render_widget' )
		);
	}

	// -------------------------------------------------------------------------
	// Widget rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the client dashboard widget body.
	 *
	 * Sections rendered in order:
	 *   1. Welcome message (wp_kses_post — basic HTML is intentional).
	 *   2. Quick-action cards (empty array → section skipped).
	 *   3. Developer contact block (all-empty → section skipped).
	 *   4. Site status (gated on show_site_status flag).
	 *   5. Recent activity feed.
	 */
	public function render_widget() {
		$config = $this->core->get( 'dashboard' );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		// 1. Welcome message.
		$welcome = isset( $config['welcome_message'] ) ? $config['welcome_message'] : '';
		if ( '' !== $welcome ) {
			echo '<div class="ch-widget-section ch-welcome-message">';
			echo wp_kses_post( $welcome );
			echo '</div>';
		}

		// 2. Quick-action cards.
		$quick_links = isset( $config['quick_links'] ) && is_array( $config['quick_links'] )
			? $config['quick_links'] : array();
		if ( ! empty( $quick_links ) ) {
			echo '<div class="ch-widget-section">';
			echo '<h3>' . esc_html( __( 'Quick Actions', 'client-handoff' ) ) . '</h3>';
			echo '<div class="ch-quick-links">';
			foreach ( $quick_links as $link ) {
				printf(
					'<a class="ch-quick-link" href="%s"><span class="dashicons %s"></span> %s</a>',
					esc_url( isset( $link['url'] )   ? $link['url']   : '#' ),
					esc_attr( isset( $link['icon'] )  ? $link['icon']  : 'dashicons-admin-generic' ),
					esc_html( isset( $link['label'] ) ? $link['label'] : '' )
				);
			}
			echo '</div>';
			echo '</div>';
		}

		// 3. Developer contact block.
		$contact     = isset( $config['developer_contact'] ) && is_array( $config['developer_contact'] )
			? $config['developer_contact'] : array();
		$has_contact = ! empty( $contact['name'] ) || ! empty( $contact['email'] ) || ! empty( $contact['url'] );
		if ( $has_contact ) {
			echo '<div class="ch-widget-section ch-developer-contact">';
			echo '<h3>' . esc_html( __( 'Need Help?', 'client-handoff' ) ) . '</h3>';
			if ( ! empty( $contact['name'] ) ) {
				echo '<p>' . esc_html( $contact['name'] ) . '</p>';
			}
			if ( ! empty( $contact['email'] ) ) {
				echo '<p><a href="mailto:' . esc_attr( $contact['email'] ) . '">';
				echo esc_html( $contact['email'] ) . '</a></p>';
			}
			if ( ! empty( $contact['url'] ) ) {
				echo '<p><a href="' . esc_url( $contact['url'] ) . '">';
				echo esc_html( $contact['url'] ) . '</a></p>';
			}
			echo '</div>';
		}

		// 4. Site status.
		// Last backup date: deferred — no standard API across backup plugins (Phase 4).
		if ( ! empty( $config['show_site_status'] ) ) {
			$update_data    = wp_get_update_data();
			$plugin_updates = isset( $update_data['counts']['plugins'] )
				? (int) $update_data['counts']['plugins'] : 0;
			$ssl_status     = is_ssl()
				? __( 'Secured (HTTPS)', 'client-handoff' )
				: __( 'Not secured', 'client-handoff' );

			echo '<div class="ch-widget-section ch-site-status">';
			echo '<h3>' . esc_html( __( 'Site Status', 'client-handoff' ) ) . '</h3>';
			echo '<div class="ch-site-status-grid">';
			echo '<div class="ch-status-item">';
			echo '<span class="ch-status-item__label">' . esc_html( __( 'WordPress version:', 'client-handoff' ) ) . '</span>';
			echo '<span class="ch-status-item__value">' . esc_html( get_bloginfo( 'version' ) ) . '</span>';
			echo '</div>';
			echo '<div class="ch-status-item">';
			echo '<span class="ch-status-item__label">' . esc_html( __( 'Security:', 'client-handoff' ) ) . '</span>';
			echo '<span class="ch-status-item__value">' . esc_html( $ssl_status ) . '</span>';
			echo '</div>';
			echo '<div class="ch-status-item">';
			echo '<span class="ch-status-item__label">' . esc_html( __( 'Plugin updates pending:', 'client-handoff' ) ) . '</span>';
			echo '<span class="ch-status-item__value">' . esc_html( $plugin_updates ) . '</span>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		// 5. Recent activity feed.
		$this->render_activity_feed();
	}

	/**
	 * Render a short list of the current user's recent content activity.
	 *
	 * Strategy:
	 *   If WP_POST_REVISIONS is enabled (the default), query the user's last
	 *   5 revisions and show the parent post title for each. This gives a
	 *   meaningful "what did I work on?" view without exposing publish state.
	 *
	 *   If revisions are disabled (WP_POST_REVISIONS=false or 0), or if the
	 *   revision query returns empty, fall back to the user's last 5 modified
	 *   posts of any type.
	 *
	 *   If both queries return empty, render an empty-state message.
	 */
	private function render_activity_feed() {
		$user    = wp_get_current_user();
		$user_id = (int) $user->ID;

		$revisions_enabled = ! (
			defined( 'WP_POST_REVISIONS' ) &&
			( false === WP_POST_REVISIONS || 0 === WP_POST_REVISIONS )
		);

		$activity = array();

		if ( $revisions_enabled ) {
			$activity = get_posts( array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'author'      => $user_id,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'numberposts' => 5,
			) );
		}

		// Fallback: most-recently-modified posts of any type.
		if ( empty( $activity ) ) {
			$activity = get_posts( array(
				'post_type'   => 'any',
				'author'      => $user_id,
				'orderby'     => 'modified',
				'order'       => 'DESC',
				'numberposts' => 5,
			) );
		}

		if ( empty( $activity ) ) {
			echo '<div class="ch-widget-section ch-activity-feed">';
			echo '<h3>' . esc_html( __( 'Recent Activity', 'client-handoff' ) ) . '</h3>';
			echo '<p class="ch-empty-state">' . esc_html( __( 'No recent activity to show.', 'client-handoff' ) ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="ch-widget-section ch-activity-feed">';
		echo '<h3>' . esc_html( __( 'Recent Activity', 'client-handoff' ) ) . '</h3>';
		echo '<ul>';
		foreach ( $activity as $item ) {
			$parent_id = isset( $item->post_parent ) ? (int) $item->post_parent : 0;
			$title     = get_the_title( $parent_id > 0 ? $parent_id : $item->ID );
			echo '<li>' . esc_html( $title ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}
}
