<?php
/**
 * Admin settings page: top-level menu, nav-tab framework, settings registration.
 *
 * PAGE STRUCTURE
 *
 * A single top-level menu page is registered at the 'client-handoff' slug.  The
 * page is rendered with a standard nav-tab wrapper.  Six tabs are declared in
 * the TABS constant; only the Roles tab is fully wired in this release — the
 * other five render a "coming soon" placeholder.
 *
 * SETTINGS API FLOW
 *
 * register_settings() ties the 'client_handoff_config' settings group to the
 * 'client_handoff_config' option with this class's sanitize() method as the
 * sanitize_callback.  The Roles tab form submits to options.php with
 * option_page=client_handoff_config.  WordPress calls sanitize(), which:
 *
 *   1. Validates protected_roles and admin_roles against the live wp_roles()
 *      registry (slugs not in the registry are silently dropped).
 *   2. Builds a $partial array containing only the fields on this tab.
 *   3. Calls CH_Core::merge_into_current($partial), which overlays $partial
 *      onto the current saved option — preserving fields from other tabs that
 *      were not submitted in this POST — and returns the full merged config.
 *   4. Returns the merged config; WordPress saves it via update_option().
 *
 * SCREEN ID
 *
 * add_menu_page() with slug 'client-handoff' produces the screen ID
 * 'toplevel_page_client-handoff', which must match
 * CH_Enforcer::SETTINGS_SCREEN_ID so developers are never blocked from this
 * page by the screen guard.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CH_Admin_Settings
 */
class CH_Admin_Settings {

	/** @var string[] Ordered list of nav-tab slugs. */
	const TABS = array( 'roles', 'dashboard', 'restrictions', 'help-notes', 'checklist', 'logging' );

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
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	// -------------------------------------------------------------------------
	// Page and settings registration
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level admin menu page.
	 *
	 * Slug 'client-handoff' → screen ID 'toplevel_page_client-handoff'.
	 */
	public function register_page() {
		add_menu_page(
			__( 'Client Handoff', 'client-handoff' ),
			__( 'Client Handoff', 'client-handoff' ),
			'manage_options',
			'client-handoff',
			array( $this, 'render_page' ),
			'dashicons-businessman',
			80
		);
	}

	/**
	 * Register settings group, section, and fields for the Roles tab.
	 *
	 * register_setting() is unconditional — the option registration is global,
	 * not tab-specific. Section and field registrations are tab-gated so that
	 * each tab only registers its own fields (precedent for future tabs).
	 */
	public function register_settings() {
		register_setting(
			CH_Core::OPTION_CONFIG,
			CH_Core::OPTION_CONFIG,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);

		if ( 'roles' === $this->get_active_tab() ) {
			add_settings_section(
				'ch_roles_section',
				__( 'Role Configuration', 'client-handoff' ),
				null,
				'client-handoff-roles'
			);

			add_settings_field(
				'ch_protected_roles',
				__( 'Protected Roles', 'client-handoff' ),
				array( $this, 'render_protected_roles_field' ),
				'client-handoff-roles',
				'ch_roles_section'
			);

			add_settings_field(
				'ch_admin_roles',
				__( 'Admin Roles', 'client-handoff' ),
				array( $this, 'render_admin_roles_field' ),
				'client-handoff-roles',
				'ch_roles_section'
			);
		}
	}

	// -------------------------------------------------------------------------
	// Tab resolution
	// -------------------------------------------------------------------------

	/**
	 * Return the active tab slug, defaulting to 'roles'.
	 *
	 * sanitize_key() strips anything outside [a-z0-9_-]; the allowlist check
	 * then rejects anything not in TABS.
	 *
	 * @return string
	 */
	public function get_active_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
		return in_array( $tab, self::TABS, true ) ? $tab : 'roles';
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the full settings page (nav-tab wrapper + active-tab content).
	 *
	 * The capability check here is belt-and-braces: add_menu_page() already
	 * enforces 'manage_options' at the menu registration level, but direct URL
	 * access warrants an explicit guard. The return after wp_die() is dead code
	 * in production (wp_die halts) but keeps the method unit-testable.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'client-handoff' ) );
			return;
		}

		$active = $this->get_active_tab();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( __( 'Client Handoff', 'client-handoff' ) ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::TABS as $tab ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=client-handoff&tab=' . $tab ) ); ?>"
					   class="nav-tab<?php echo $active === $tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( ucfirst( str_replace( '-', ' ', $tab ) ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php
				if ( 'roles' === $active ) {
					settings_fields( CH_Core::OPTION_CONFIG );
					do_settings_sections( 'client-handoff-roles' );
					submit_button();
				} else {
					echo '<p>' . esc_html( __( 'This tab is coming soon.', 'client-handoff' ) ) . '</p>';
				}
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render checkboxes for protected_roles.
	 */
	public function render_protected_roles_field() {
		$saved = (array) $this->core->get( 'protected_roles' );
		$roles = wp_roles()->get_names();
		foreach ( $roles as $slug => $name ) {
			printf(
				'<label><input type="checkbox" name="%s[protected_roles][]" value="%s"%s> %s</label><br>',
				esc_attr( CH_Core::OPTION_CONFIG ),
				esc_attr( $slug ),
				in_array( $slug, $saved, true ) ? ' checked' : '',
				esc_html( $name )
			);
		}
	}

	/**
	 * Render checkboxes for admin_roles.
	 */
	public function render_admin_roles_field() {
		$saved = (array) $this->core->get( 'admin_roles' );
		$roles = wp_roles()->get_names();
		foreach ( $roles as $slug => $name ) {
			printf(
				'<label><input type="checkbox" name="%s[admin_roles][]" value="%s"%s> %s</label><br>',
				esc_attr( CH_Core::OPTION_CONFIG ),
				esc_attr( $slug ),
				in_array( $slug, $saved, true ) ? ' checked' : '',
				esc_html( $name )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Sanitize callback
	// -------------------------------------------------------------------------

	/**
	 * Sanitize and merge the Roles-tab form submission.
	 *
	 * Called by the WP Settings API when options.php saves 'client_handoff_config'.
	 * Builds a $partial from the submitted fields, validates role slugs against
	 * the live wp_roles() registry, then calls merge_into_current() to overlay
	 * $partial onto the full saved option (preserving all other tabs' fields).
	 *
	 * @param mixed $input Raw $_POST['client_handoff_config'] value.
	 * @return array Full merged config to be saved.
	 */
	public function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$valid_roles = array_keys( wp_roles()->get_names() );

		$protected_raw = isset( $input['protected_roles'] ) && is_array( $input['protected_roles'] )
			? $input['protected_roles'] : array();
		$admin_raw     = isset( $input['admin_roles'] ) && is_array( $input['admin_roles'] )
			? $input['admin_roles'] : array();

		// 'enabled' is intentionally absent from this partial. The Roles tab form
		// does not render an enabled checkbox; including it here would overlay
		// false onto the saved flag on every Roles save, silently deactivating
		// the plugin. The enabled flag is owned by a future dedicated tab.
		$partial = array(
			'protected_roles' => array_values( array_filter(
				array_map( 'sanitize_key', $protected_raw ),
				static function ( $slug ) use ( $valid_roles ) {
					return in_array( $slug, $valid_roles, true );
				}
			) ),
			'admin_roles'     => array_values( array_filter(
				array_map( 'sanitize_key', $admin_raw ),
				static function ( $slug ) use ( $valid_roles ) {
					return in_array( $slug, $valid_roles, true );
				}
			) ),
		);

		return $this->core->merge_into_current( $partial );
	}
}
