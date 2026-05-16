# Client Handoff

A WordPress plugin for structured developer-to-client handoff. Locks down the admin for non-technical clients without locking out the developer — enforced role-based restrictions, cosmetic simplification, and a clean operational dashboard.

## What it does

**Enforcement layer** — hard restrictions that survive UI bypass attempts:

- **Capability filter** (`user_has_cap`): strips install/activate/delete/edit/update capabilities for configured roles at the WordPress filter level.
- **Screen guard** (`current_screen`): blocks access to prohibited admin screens and calls `wp_die()` with a developer-contact message.
- **Plugin protection** (`plugin_action_links` + `admin_init`): removes deactivate/delete action links and intercepts direct HTTP requests — bulk and single.

**Cosmetic layer** — non-destructive simplification applied unconditionally to configured roles:

- **Menu hiding** (`admin_menu` priority 999): removes top-level and submenu items per role using a slug map. Pipe-separated slugs (`parent|child`) target submenus.
- **Admin bar simplification** (`wp_before_admin_bar_render`): strips all nodes except a configurable keep set (default: `site-name`, `edit`, `my-account`).

**Lockout safeguards** (enforcement layer only, non-overridable):

- User ID 1 is always exempt.
- Multisite super-admins are always exempt.
- Users in `admin_roles` are always exempt.
- Users whose unfiltered role definition includes `activate_plugins` are always exempt.

The cosmetic layer deliberately does **not** apply these safeguards — menu hiding and admin bar simplification are non-destructive and apply to all configured roles.

## Requirements

- PHP 7.4+
- WordPress 6.0+

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.
3. Configure via the plugin settings page (coming in a future release).

The plugin is **inert by default** — `enabled: false` means no user is ever restricted until you configure it.

## Configuration

Configuration is stored in the `client_handoff_config` WordPress option as a single merged array. The shape:

```php
[
  'enabled'         => false,           // master switch
  'protected_roles' => [],              // roles subject to enforcement
  'admin_roles'     => [],              // roles always exempt from enforcement

  'enforcement' => [
    'blocked_caps'      => [ /* default cap list */ ],
    'screen_blocklist'  => [],          // keyed by role slug
    'protected_plugins' => [],          // plugin basenames
  ],

  'menu_hiding' => [
    'hidden_menus' => [],               // keyed by role slug, values are slug arrays
    'preset'       => null,
  ],

  'admin_bar' => [
    'simplify'      => false,
    'allowed_nodes' => [],              // overrides DEFAULT_KEEP_NODES when non-empty
  ],

  'dashboard' => [
    'enabled'           => false,
    'welcome_message'   => '',
    'quick_links'       => [],
    'developer_contact' => [ 'name' => '', 'email' => '', 'url' => '' ],
    'show_site_status'  => true,
  ],
]
```

### Menu hiding slug convention

| Slug format | Behaviour |
|---|---|
| `plugins.php` | `remove_menu_page('plugins.php')` |
| `options-general.php\|options-writing.php` | `remove_submenu_page('options-general.php', 'options-writing.php')` |

### Built-in presets

`CH_Menu_Manager` ships two preset slug lists for the settings UI:

- **`PRESET_CONTENT_MANAGER`** — hides Settings, Appearance, Plugins, Tools, Users.
- **`PRESET_STORE_MANAGER`** — same minus Users (manager needs customer accounts).

## Architecture

```
client-handoff.php          Plugin entry — requires, constants, lifecycle hooks
│
├── includes/
│   ├── class-ch-core.php               Singleton: config, role resolution, safeguards
│   ├── class-ch-enforcer.php           Enforcement: cap filter + screen guard
│   ├── class-ch-plugin-protection.php  Enforcement: action-link removal + intercept
│   ├── class-ch-menu-manager.php       Cosmetic: admin menu hiding
│   └── class-ch-admin-bar.php          Cosmetic: admin bar simplification
│
└── tests/
    ├── bootstrap.php                   WP_Mock bootstrap + WordPress class stubs
    └── Unit/
        ├── CoreTest.php                24 tests
        ├── EnforcerTest.php            15 tests
        ├── PluginProtectionTest.php    17 tests
        ├── MenuManagerTest.php         9 tests
        └── AdminBarTest.php            7 tests
```

### Key design constraints

- **No `current_user_can()` / `user_can()` inside `user_has_cap` callbacks** — those calls re-trigger `apply_filters('user_has_cap', ...)` from inside the callback, causing infinite recursion. All role/capability checks use `CH_Core::user_has_cap_unfiltered()` which reads `wp_roles()` directly.
- **No Composer autoloader at runtime** — `require_once` only. Composer is dev-only (WP_Mock, PHPUnit).
- **Enforcement vs cosmetic gating** — enforcement checks `is_exempt_from_enforcement()`; cosmetic layer does not.

## Development

Install dev dependencies:

```bash
composer install
```

Run the test suite:

```bash
./vendor/bin/phpunit
```

With testdox output:

```bash
./vendor/bin/phpunit --testdox
```

**72 tests, 141 assertions.**

Tests use [WP_Mock](https://github.com/10up/wp_mock) and run without a WordPress install. The bootstrap pre-defines identity stubs for translation and escaping functions before `WP_Mock::bootstrap()` to prevent PHP 8.5 `TypeError` from typed stubs.

## Licence

GPL v2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
