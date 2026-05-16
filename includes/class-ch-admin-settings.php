<?php
/**
 * Admin settings page: top-level menu, nav-tab framework, settings registration.
 *
 * PAGE STRUCTURE
 *
 * A single top-level menu page is registered at the 'client-handoff' slug. The
 * page is rendered with a standard nav-tab wrapper. Six tabs are declared in
 * the TABS constant; Roles and Restrictions are fully wired — the other four
 * render a "coming soon" placeholder.
 *
 * SETTINGS API FLOW
 *
 * register_settings() ties the 'client_handoff_config' settings group to the
 * 'client_handoff_config' option with this class's sanitize() method as the
 * sanitize_callback. Forms submit to options.php. WordPress calls sanitize(),
 * which dispatches to per-tab helpers:
 *
 *   sanitize_roles()        → protected_roles, admin_roles
 *   sanitize_restrictions() → enforcement.blocked_caps, enforcement.protected_plugins
 *
 * Each helper returns a partial array. sanitize() merges all partials and
 * calls merge_into_current(), which overlays them onto the full saved option —
 * preserving fields from tabs not represented in the current POST.
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
	 * Register settings group, section, and fields.
	 *
	 * register_setting() is unconditional — the option registration is global,
	 * not tab-specific. Section and field registrations are tab-gated so each
	 * tab only registers its own fields.
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

		if ( 'restrictions' === $this->get_active_tab() ) {
			add_settings_section(
				'ch_restrictions_section',
				__( 'Restrictions', 'client-handoff' ),
				null,
				'client-handoff-restrictions'
			);

			add_settings_field(
				'ch_blocked_caps',
				__( 'Blocked Capabilities', 'client-handoff' ),
				array( $this, 'render_blocked_caps_field' ),
				'client-handoff-restrictions',
				'ch_restrictions_section'
			);

			add_settings_field(
				'ch_protected_plugins',
				__( 'Protected Plugins', 'client-handoff' ),
				array( $this, 'render_protected_plugins_field' ),
				'client-handoff-restrictions',
				'ch_restrictions_section'
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
				} elseif ( 'restrictions' === $active ) {
					settings_fields( CH_Core::OPTION_CONFIG );
					do_settings_sections( 'client-handoff-restrictions' );
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

	/**
	 * Render checkboxes for enforcement.blocked_caps.
	 *
	 * Only the eleven capabilities in CH_Core::DEFAULTS['enforcement']['blocked_caps']
	 * are offered. Developers may untick any default; they cannot add custom caps
	 * via this UI (post-MVP).
	 */
	public function render_blocked_caps_field() {
		$enforcement = $this->core->get( 'enforcement' );
		$saved       = isset( $enforcement['blocked_caps'] ) ? (array) $enforcement['blocked_caps'] : array();
		$defaults    = CH_Core::DEFAULTS['enforcement']['blocked_caps'];

		foreach ( $defaults as $cap ) {
			printf(
				'<label><input type="checkbox" name="%s[enforcement][blocked_caps][]" value="%s"%s> <code>%s</code></label><br>',
				esc_attr( CH_Core::OPTION_CONFIG ),
				esc_attr( $cap ),
				in_array( $cap, $saved, true ) ? ' checked' : '',
				esc_html( $cap )
			);
		}
	}

	/**
	 * Render checkboxes for enforcement.protected_plugins.
	 *
	 * Iterates the live get_plugins() result; basenames not installed are
	 * never shown (and would be dropped by sanitize_restrictions anyway).
	 */
	public function render_protected_plugins_field() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$enforcement = $this->core->get( 'enforcement' );
		$saved       = isset( $enforcement['protected_plugins'] )
			? (array) $enforcement['protected_plugins'] : array();
		$plugins     = get_plugins();

		if ( empty( $plugins ) ) {
			echo '<p>' . esc_html( __( 'No plugins installed.', 'client-handoff' ) ) . '</p>';
			return;
		}

		foreach ( $plugins as $basename => $data ) {
			$name = isset( $data['Name'] ) ? $data['Name'] : $basename;
			printf(
				'<label><input type="checkbox" name="%s[enforcement][protected_plugins][]" value="%s"%s> %s</label><br>',
				esc_attr( CH_Core::OPTION_CONFIG ),
				esc_attr( $basename ),
				in_array( $basename, $saved, true ) ? ' checked' : '',
				esc_html( $name )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Sanitize callback + per-tab helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize and merge any tab's form submission.
	 *
	 * Detects which tab's fields are present in $input and dispatches to the
	 * appropriate per-tab helper. Each helper returns a partial that is merged
	 * into the running $partial before merge_into_current() is called.
	 *
	 * This dispatch approach means adding a new tab requires only a new private
	 * sanitize_X() method and a detection clause here — not a growing monolith.
	 *
	 * @param mixed $input Raw $_POST['client_handoff_config'] value.
	 * @return array Full merged config to be saved.
	 */
	public function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$partial = array();

		// Roles tab fields.
		if ( isset( $input['protected_roles'] ) || isset( $input['admin_roles'] ) ) {
			$partial = array_merge( $partial, $this->sanitize_roles( $input ) );
		}

		// Restrictions tab fields.
		if ( isset( $input['enforcement'] ) && is_array( $input['enforcement'] ) ) {
			$partial['enforcement'] = $this->sanitize_restrictions( $input['enforcement'] );
		}

		return $this->core->merge_into_current( $partial );
	}

	/**
	 * Sanitize Roles-tab fields: protected_roles and admin_roles.
	 *
	 * Returns ['protected_roles' => [...], 'admin_roles' => [...]].
	 * Role slugs not present in the live wp_roles() registry are dropped.
	 *
	 * 'enabled' is intentionally absent — the Roles form has no enabled
	 * checkbox; including it would silently deactivate the plugin on every save.
	 *
	 * @param array $input Raw form input.
	 * @return array Partial with protected_roles and admin_roles.
	 */
	private function sanitize_roles( array $input ): array {
		$valid_roles   = array_keys( wp_roles()->get_names() );
		$protected_raw = isset( $input['protected_roles'] ) && is_array( $input['protected_roles'] )
			? $input['protected_roles'] : array();
		$admin_raw     = isset( $input['admin_roles'] ) && is_array( $input['admin_roles'] )
			? $input['admin_roles'] : array();

		return array(
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
	}

	/**
	 * Sanitize Restrictions-tab fields: blocked_caps and protected_plugins.
	 *
	 * Returns ['blocked_caps' => [...], 'protected_plugins' => [...]] WITHOUT
	 * the outer 'enforcement' wrapper — sanitize() wraps it.
	 *
	 * BLOCKED CAPS VALIDATION
	 * Source of truth: CH_Core::DEFAULTS['enforcement']['blocked_caps'] (the
	 * eleven core capabilities). Any submitted cap not in that list is dropped.
	 * Developers can untick defaults; they cannot add caps via this UI (post-MVP).
	 * This prevents arbitrary capability names being written to the DB via a
	 * crafted POST.
	 *
	 * PROTECTED PLUGINS VALIDATION
	 * Source of truth: get_plugins() at runtime. Basenames absent from the live
	 * result are silently dropped (guards against stale/crafted POSTs naming
	 * plugins that are no longer installed).
	 * get_plugins() is not autoloaded — a require_once guard is applied before
	 * the call. sanitize_text_field() is used (not sanitize_key()) because
	 * basenames contain slashes and dots that sanitize_key() would strip.
	 *
	 * @param array $enforcement_input Raw enforcement sub-array from form input.
	 * @return array Partial enforcement subarray (no 'enforcement' wrapper).
	 */
	private function sanitize_restrictions( array $enforcement_input ): array {
		// ---- blocked_caps -------------------------------------------------------
		$allowed_caps = CH_Core::DEFAULTS['enforcement']['blocked_caps'];
		$caps_raw     = isset( $enforcement_input['blocked_caps'] ) && is_array( $enforcement_input['blocked_caps'] )
			? $enforcement_input['blocked_caps'] : array();

		$blocked_caps = array_values( array_filter(
			array_map( 'sanitize_key', $caps_raw ),
			static function ( $cap ) use ( $allowed_caps ) {
				return in_array( $cap, $allowed_caps, true );
			}
		) );

		// ---- protected_plugins --------------------------------------------------
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_basenames  = array_keys( get_plugins() );
		$plugins_raw          = isset( $enforcement_input['protected_plugins'] ) && is_array( $enforcement_input['protected_plugins'] )
			? $enforcement_input['protected_plugins'] : array();

		$protected_plugins = array_values( array_filter(
			array_map( 'sanitize_text_field', $plugins_raw ),
			static function ( $basename ) use ( $installed_basenames ) {
				return in_array( $basename, $installed_basenames, true );
			}
		) );

		return array(
			'blocked_caps'      => $blocked_caps,
			'protected_plugins' => $protected_plugins,
		);
	}
}
