<?php
/**
 * Cosmetic layer: runtime admin menu hiding.
 *
 * This class implements the menu-hiding component of the cosmetic layer
 * described in brief § 3.4 Layer 1. It is STRUCTURALLY DIFFERENT from the
 * enforcement layer in one critical respect:
 *
 *   LOCKOUT SAFEGUARDS DO NOT APPLY HERE.
 *
 * The enforcement layer (CH_Enforcer, CH_Plugin_Protection) gates every action
 * on is_exempt_from_enforcement() — a user in admin_roles or holding
 * activate_plugins bypasses all enforcement. The cosmetic layer does NOT.
 * A user in admin_roles still has menus hidden if their role appears in
 * hidden_menus. Menu hiding is non-destructive (it cannot lock anyone out),
 * so it is safe to apply unconditionally to configured roles.
 *
 * Source of truth for who gets menus hidden: enforcement.menu_hiding.hidden_menus,
 * keyed by role slug. NOT protected_roles, NOT admin_roles.
 *
 * SLUG CONVENTION (hidden_menus values):
 *   - Bare slug  e.g. 'plugins.php'                      → remove_menu_page($slug)
 *   - Pipe slug  e.g. 'options-general.php|options-writing.php'
 *                                                         → remove_submenu_page($parent, $child)
 * The pipe is split on the FIRST occurrence; everything after the first pipe
 * is treated as the child slug.
 *
 * PRESET CONSTANTS: PRESET_CONTENT_MANAGER and PRESET_STORE_MANAGER hold the
 * canonical slug lists for the built-in presets. They are read by the future
 * settings UI to pre-fill hidden_menus when the developer applies a preset.
 * The menu manager itself does NOT expand presets at runtime — it reads
 * hidden_menus only. The settings UI is responsible for writing the expanded
 * slugs into hidden_menus before save.
 *
 * GET_ADMIN_MENU_SNAPSHOT: reads the $menu/$submenu globals (not screen IDs)
 * to produce a structured list for the settings UI. The brief's Constraint 11.8
 * (no screen enumeration) forbids enumerating WP_Screen IDs, but $menu/$submenu
 * are the authoritative WordPress menu registry and are explicitly permitted.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CH_Menu_Manager
 */
class CH_Menu_Manager {

	/**
	 * Preset slug list for a "content manager" role.
	 *
	 * Hides all site-configuration menus while leaving content menus visible.
	 * Written to hidden_menus by the settings UI on preset apply — not used here
	 * at runtime.
	 *
	 * @var string[]
	 */
	const PRESET_CONTENT_MANAGER = array(
		'options-general.php',
		'themes.php',
		'plugins.php',
		'tools.php',
		'users.php',
	);

	/**
	 * Preset slug list for a "store manager" role (e.g. WooCommerce shop admin).
	 *
	 * Same as PRESET_CONTENT_MANAGER but keeps Users visible so the manager can
	 * handle customer accounts.
	 *
	 * @var string[]
	 */
	const PRESET_STORE_MANAGER = array(
		'options-general.php',
		'themes.php',
		'plugins.php',
		'tools.php',
	);

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
	 * admin_menu fires at priority 999 — after every plugin and theme has
	 * registered its menu items — so the removal pass sees the full menu tree.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'apply_menu_hiding' ), 999 );
	}

	// -------------------------------------------------------------------------
	// Runtime menu hiding
	// -------------------------------------------------------------------------

	/**
	 * Remove admin menu items for the current user based on hidden_menus config.
	 *
	 * Gating (cheapest-first):
	 *   1. Plugin disabled → return.
	 *   2. hidden_menus map is empty → return (common during initial setup).
	 *   3. Loop the current user's roles; for each role found in hidden_menus,
	 *      remove every slug in that role's list.
	 *
	 * This method does NOT call is_protected_user(), is_exempt_from_enforcement(),
	 * user_can(), or current_user_can(). The only signals used are the enabled
	 * flag, the user's $roles array, and the hidden_menus map. See the file
	 * docblock for why lockout safeguards are intentionally absent.
	 */
	public function apply_menu_hiding() {
		if ( ! $this->core->get( 'enabled' ) ) {
			return;
		}

		$menu_hiding  = $this->core->get( 'menu_hiding' );
		$hidden_menus = isset( $menu_hiding['hidden_menus'] ) && is_array( $menu_hiding['hidden_menus'] )
			? $menu_hiding['hidden_menus']
			: array();

		if ( empty( $hidden_menus ) ) {
			return;
		}

		$user = wp_get_current_user();

		foreach ( $user->roles as $role ) {
			if ( ! isset( $hidden_menus[ $role ] ) || ! is_array( $hidden_menus[ $role ] ) ) {
				continue;
			}

			foreach ( $hidden_menus[ $role ] as $slug ) {
				if ( false !== strpos( $slug, '|' ) ) {
					list( $parent, $child ) = explode( '|', $slug, 2 );
					remove_submenu_page( $parent, $child );
				} else {
					remove_menu_page( $slug );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Menu snapshot for settings UI
	// -------------------------------------------------------------------------

	/**
	 * Return a structured snapshot of the current admin menu for the settings UI.
	 *
	 * Reads the $menu and $submenu WordPress globals. The brief's Constraint 11.8
	 * prohibits enumerating WP_Screen IDs; the $menu/$submenu globals are the
	 * authoritative menu registry and are explicitly permitted as the data source.
	 *
	 * Separator entries (slug is empty or starts with 'separator') are omitted.
	 * HTML in menu labels (e.g. notification count badges) is stripped.
	 *
	 * Return shape:
	 * [
	 *   'top_level' => [['slug' => 'plugins.php', 'label' => 'Plugins'], ...],
	 *   'submenu'   => [
	 *     'options-general.php' => [
	 *       ['slug' => 'options-writing.php', 'label' => 'Writing'],
	 *       ...
	 *     ],
	 *     ...
	 *   ],
	 * ]
	 *
	 * @return array{top_level: array<int, array{slug: string, label: string}>, submenu: array<string, array<int, array{slug: string, label: string}>>}
	 */
	public function get_admin_menu_snapshot() {
		global $menu, $submenu;

		$snapshot = array(
			'top_level' => array(),
			'submenu'   => array(),
		);

		if ( is_array( $menu ) ) {
			foreach ( $menu as $entry ) {
				$slug = isset( $entry[2] ) ? (string) $entry[2] : '';

				if ( '' === $slug || 0 === strpos( $slug, 'separator' ) ) {
					continue;
				}

				$snapshot['top_level'][] = array(
					'slug'  => $slug,
					'label' => strip_tags( isset( $entry[0] ) ? (string) $entry[0] : '' ),
				);
			}
		}

		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent_slug => $items ) {
				if ( ! is_array( $items ) ) {
					continue;
				}

				$children = array();
				foreach ( $items as $item ) {
					$child_slug = isset( $item[2] ) ? (string) $item[2] : '';
					if ( '' === $child_slug ) {
						continue;
					}
					$children[] = array(
						'slug'  => $child_slug,
						'label' => strip_tags( isset( $item[0] ) ? (string) $item[0] : '' ),
					);
				}

				if ( ! empty( $children ) ) {
					$snapshot['submenu'][ (string) $parent_slug ] = $children;
				}
			}
		}

		return $snapshot;
	}
}
