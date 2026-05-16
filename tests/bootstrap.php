<?php
/**
 * PHPUnit bootstrap — loads WP_Mock and minimal WordPress class stubs.
 *
 * This file must NOT load WordPress. It provides only the class shells that
 * CH_Core actually touches (WP_User, WP_Role, WP_Roles) so unit tests can
 * run without a WordPress install.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ---- Output / translation stubs (must be defined BEFORE WP_Mock::bootstrap()) ----
// WP_Mock's function-mocks.php declares these with `string` return types. If
// Patchwork fails to intercept a call (e.g. on PHP 8.5), the stub returns null
// and PHP throws a TypeError. Defining them here as PHP-userspace functions
// before WP_Mock::bootstrap() runs means WP_Mock skips its own stubs
// (function_exists() == true) and our identity versions are used instead.
// Tests that need specific return values can still use WP_Mock::userFunction()
// with Patchwork to override these.

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return (string) $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Stub for wp_kses_post: strips tags not in the allowed set.
	 * Allows basic HTML that developers may put in welcome_message; strips
	 * script/style/iframe and similar. Close enough for unit testing —
	 * real wp_kses_post has an extensive allowlist, but the key behaviour
	 * we need to verify (that <script> is removed) works with strip_tags.
	 */
	function wp_kses_post( $content ) {
		$allowed = '<p><a><strong><em><ul><ol><li><h1><h2><h3><h4><h5><h6><br><div><span><blockquote><code><pre>';
		return strip_tags( (string) $content, $allowed );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Stub for get_bloginfo: identity passthrough.
	 * Tests assert which key was queried, not what it resolves to.
	 */
	function get_bloginfo( $show = '', $filter = '' ) {
		return (string) $show;
	}
}

WP_Mock::bootstrap();

// ---- Minimal WordPress class stubs -----------------------------------------
// Only the properties/methods accessed by CH_Core are stubbed.

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Stub for WP_User — exposes only the properties CH_Core reads.
	 */
	class WP_User {
		/** @var int */
		public $ID = 0;

		/** @var string[] */
		public $roles = array();

		/**
		 * @param int      $id
		 * @param string[] $roles
		 */
		public function __construct( $id = 0, array $roles = array() ) {
			$this->ID    = $id;
			$this->roles = $roles;
		}
	}
}

if ( ! class_exists( 'WP_Role' ) ) {
	/**
	 * Stub for WP_Role — exposes only the capabilities map CH_Core reads.
	 */
	class WP_Role {
		/** @var array<string, bool> */
		public $capabilities = array();

		/**
		 * @param array<string, bool> $capabilities
		 */
		public function __construct( array $capabilities = array() ) {
			$this->capabilities = $capabilities;
		}
	}
}

if ( ! class_exists( 'WP_Roles' ) ) {
	/**
	 * Stub for WP_Roles — exposes only get_role(), which CH_Core calls in
	 * user_has_cap_unfiltered().
	 */
	class WP_Roles {
		/** @var array<string, array<string, bool>> role_name => capability_map */
		private $roles_data = array();

		/**
		 * @param array<string, array<string, bool>> $roles_data
		 */
		public function __construct( array $roles_data = array() ) {
			$this->roles_data = $roles_data;
		}

		/**
		 * @param string $role_name
		 * @return WP_Role|null
		 */
		public function get_role( $role_name ) {
			if ( ! isset( $this->roles_data[ $role_name ] ) ) {
				return null;
			}
			return new WP_Role( $this->roles_data[ $role_name ] );
		}

		/**
		 * Return a map of role slug => display name (slug used as placeholder name).
		 *
		 * @return array<string, string>
		 */
		public function get_names() {
			$names = array();
			foreach ( $this->roles_data as $slug => $caps ) {
				$names[ $slug ] = $slug;
			}
			return $names;
		}
	}
}

if ( ! class_exists( 'WP_Screen' ) ) {
	/**
	 * Stub for WP_Screen — exposes only the 'id' property the screen guard reads.
	 */
	class WP_Screen {
		/** @var string */
		public $id;

		/**
		 * @param string $id Admin screen ID (e.g. 'edit.php', 'index.php').
		 */
		public function __construct( $id ) {
			$this->id = $id;
		}
	}
}

if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	/**
	 * Stub for WP_Admin_Bar — records remove_node() calls for assertions.
	 *
	 * Tests construct this with a flat list of node IDs, assign it to the global
	 * $wp_admin_bar, run simplify_admin_bar(), then inspect the $removed list.
	 */
	class WP_Admin_Bar {
		/** @var array<string, object> node ID => node object */
		private $nodes = array();

		/** @var string[] node IDs passed to remove_node(), in call order */
		public $removed = array();

		/**
		 * @param array<string, object> $nodes
		 */
		public function set_nodes( array $nodes ) {
			$this->nodes = $nodes;
		}

		/** @return array<string, object> */
		public function get_nodes() {
			return $this->nodes;
		}

		/** @param string $id */
		public function remove_node( $id ) {
			$this->removed[] = $id;
			unset( $this->nodes[ $id ] );
		}
	}
}

// ---- Classes under test -----------------------------------------------------
require_once dirname( __DIR__ ) . '/includes/class-ch-core.php';
require_once dirname( __DIR__ ) . '/includes/class-ch-enforcer.php';
require_once dirname( __DIR__ ) . '/includes/class-ch-plugin-protection.php';
require_once dirname( __DIR__ ) . '/includes/class-ch-menu-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-ch-admin-bar.php';
require_once dirname( __DIR__ ) . '/includes/class-ch-notifications.php';
require_once dirname( __DIR__ ) . '/includes/class-ch-admin-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-ch-dashboard.php';
