<?php

declare(strict_types=1);

/**
 * StarterBase — Configurable WordPress/Timber base class.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit;

use Timber\Site;
use Timber\Timber;
use Twig\Environment;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\Extension\StringLoaderExtension;
use Twig\Extra\String\StringExtension;
use Symfony\Bridge\Twig\Extension\DumpExtension;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Parisek\Twig\CommonExtension;
use Parisek\Twig\AttributeExtension;
use Parisek\Twig\TypographyExtension;
use Parisek\TimberKit\BlockRenderer;

/**
 * Base class for WordPress themes using Timber/Twig templating.
 *
 * Provides configurable defaults for security hardening, media processing,
 * Gutenberg block management, ACF integration, and Twig extensions.
 * Extend this class and override protected properties in the child constructor
 * before calling parent::__construct().
 */
class StarterBase extends Site {

	/** @var string|false Theme text domain, set from wp_get_theme(). */
	public $theme_name;

	/**
	 * Configurable properties — override in child constructor before calling parent::__construct()
	 */

	/** @var array<string, string> Navigation menus to register (slug => label). */
	protected array $menus = [];

	/** @var array<string, string> Font stylesheets to enqueue (handle-suffix => relative path from static/). */
	protected array $font_stylesheets = [];

	/** @var string[] Font files to preload (relative paths from static/). */
	protected array $preload_fonts = [];

	/** @var string[] Post types included in frontend search results. */
	protected array $search_post_types = [ 'post' ];

	/** @var string[] Post types treated as articles (skip block wrapper in render_block). */
	protected array $article_post_types = [ 'post' ];

	/** @var array{slug: string, title: string} Custom Gutenberg block category definition. */
	protected array $block_category = [ 'slug' => 'custom', 'title' => 'Custom' ];

	/** @var string[] Core Gutenberg blocks allowed in the editor. ACF blocks are always allowed. */
	protected array $allowed_core_blocks = [
		'core/paragraph',
		'core/heading',
		'core/image',
		'core/list',
		'core/list-item',
		'core/code',
		'core/html',
		'core/separator',
		'core/spacer',
		'core/columns',
		'core/column',
		'core/group',
		'core/table',
		'core/shortcode',
		'core/block',
	];

	/** @var string Relative path to favicon SVG from the static/ directory. */
	protected string $favicon_path = 'images/touch/favicon.svg';

	/** @var string Relative path to typography YAML config from the static/ directory. */
	protected string $typography_config = 'typography.yml';

	/** @var string Twig template used to wrap core Gutenberg blocks on non-article pages. */
	protected string $block_wrapper_template = '@component/content/content.twig';

	/** @var string Nav menu location for breadcrumb menu-trail strategy */
	protected string $breadcrumb_menu_name = 'main-menu';

	/** @var array<string, string> Post type → ACF option key for "listing page" injection */
	protected array $breadcrumb_list_page_map = [];

	/** @var array<int, string>|null Post types eligible for menu-trail; null = auto-detect hierarchical */
	protected ?array $breadcrumb_menu_trail_post_types = null;

	/** @var bool Whether to add "Page N" item on paginated views */
	protected bool $breadcrumb_include_pagination = false;

	/** @var array{home: string, '404': string, search: string, pagination: string, author: string} Default English labels */
	protected array $breadcrumb_labels = [
		'home'       => 'Home',
		'404'        => 'Page not found',
		'search'     => 'Search: %s',
		'pagination' => 'Page %d',
		'author'     => 'Author: %s',
	];

	/**
	 * Security & cleanup — override in child constructor to disable
	 */

	/** @var bool Remove unnecessary meta tags from wp_head. */
	protected bool $cleanup_wp_head = true;

	/** @var bool Disable XML-RPC and remove X-Pingback header. */
	protected bool $disable_xmlrpc = true;

	/** @var bool Remove emoji scripts, styles, and filters. */
	protected bool $disable_emojis = true;

	/** @var bool Disable all RSS/RDF/Atom feeds (returns 404). */
	protected bool $disable_feeds = true;

	/** @var bool Remove comment support from posts and pages. */
	protected bool $disable_comments = true;

	/** @var bool Disable frontend search (redirects to 404). */
	protected bool $disable_search = true;

	/** @var bool Remove default dashboard widgets and hide dashboard for non-admins. */
	protected bool $cleanup_dashboard = true;

	/** @var bool Remove updates and comments nodes from the admin toolbar. */
	protected bool $cleanup_admin_bar = true;

	/** @var bool Grant editor role theme_options cap, hide unnecessary admin pages, enable WPML translate. */
	protected bool $editor_role_enhancements = true;

	/** @var string Admin URL path editors are redirected to after login. */
	protected string $editor_login_redirect_url = 'edit.php?post_type=page';

	/** @var bool Prevent the site from sending pingbacks to itself. */
	protected bool $disable_self_pingbacks = true;

	/** @var bool Restrict REST API /wp/v2/users endpoint to authenticated users. */
	protected bool $restrict_rest_users = true;

	/** @var bool Disable WP Application Passwords (REST auth surface that is rarely used in practice). */
	protected bool $disable_application_passwords = true;

	/** @var bool Block ?author=N URL enumeration that leaks usernames via canonical redirect. */
	protected bool $block_author_enumeration = true;

	/** @var bool Define DISALLOW_FILE_EDIT so Theme/Plugin Editor is hidden from the admin. */
	protected bool $disable_file_editing = true;

	/** @var bool Filter the_generator to empty so the WP version disappears from wp_head and feeds. */
	protected bool $remove_wp_generator = true;

	/** @var bool Remove the core author (users) sitemap (/wp-sitemap-users-1.xml), which lists author slugs regardless of ?author= blocking. Default on; set false on sites that intentionally expose author archives for SEO. */
	protected bool $disable_author_sitemap = true;

	/** @var bool Emit baseline security response headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, CSP, Permissions-Policy, X-XSS-Protection, HSTS over TLS). Off by default. */
	protected bool $security_headers = false;

	/** @var array<string,string|null> Override, extend, or (with a null value) drop individual security headers, keyed by header name. Applied on top of the defaults. */
	protected array $security_headers_config = [];

	/**
	 * Media processing — replaces clean-image-filenames + imsanity plugins
	 */

	/** @var bool Sanitize uploaded filenames (remove diacritics, lowercase, normalize). */
	protected bool $clean_image_filenames = true;

	/**
	 * Maximum dimension in pixels for uploaded images, mirroring WordPress core's
	 * `big_image_size_threshold` filter. When an uploaded image's longer edge
	 * exceeds this, core downscales it on upload and serves a `-scaled` derivative.
	 * `0` disables the cap. Default `2560` matches WP core's own default.
	 *
	 * This is the single canonical knob — set it instead of the deprecated
	 * width/height pair below.
	 *
	 * @var int
	 */
	protected int $big_image_size_threshold = 2560;

	/**
	 * @deprecated Use {@see $big_image_size_threshold}. Honoured only when non-null;
	 *             the larger of width/height becomes the (square) threshold.
	 * @var int|null
	 */
	protected ?int $max_upload_width = null;

	/**
	 * @deprecated Use {@see $big_image_size_threshold}.
	 * @var int|null
	 */
	protected ?int $max_upload_height = null;

	/**
	 * Gutenberg enhancements
	 */

	/** @var bool Enable wide/full alignment support in Gutenberg. */
	protected bool $gutenberg_align_wide = true;

	/** @var bool Enable responsive embed wrappers. */
	protected bool $gutenberg_responsive_embeds = true;

	/** @var bool Enable editor styles and enqueue gutenberg-editor.css. */
	protected bool $gutenberg_editor_styles = true;

	/**
	 * ACF options pages. Each entry must define `menu_slug` and `page_title`
	 * (required); optional per-entry keys are `parent_slug`, `capability`,
	 * `icon_url`, and `admin_bar`. An entry with a 'parent_slug' is registered as
	 * a sub-page (acf_add_options_sub_page) under that parent; otherwise it is a
	 * top-level page (acf_add_options_page). `icon_url` is only used for top-level
	 * pages (sub-pages do not take an icon); defaults to `'dashicons-admin-generic'`
	 * when not set. A 'parent_slug' must reference a top-level page in the same
	 * list (top-level pages are always registered first, so order within the list
	 * does not matter). `capability` sets the WordPress capability required to
	 * access that page; defaults to `edit_posts` when not set. `admin_bar` (bool,
	 * default off) adds an admin-bar shortcut to this page; set it on any entry —
	 * including sub-pages — to surface a direct link under the site name. Multiple
	 * entries may carry `admin_bar => true` and each gets its own node. Override
	 * the whole list in a subclass, e.g.:
	 *   $this->options_pages = [
	 *     ['menu_slug'=>'settings','page_title'=>'Theme Settings','admin_bar'=>true],
	 *     ['menu_slug'=>'footer','page_title'=>'Footer','parent_slug'=>'settings'],
	 *     ['menu_slug'=>'dev','page_title'=>'Dev Settings','capability'=>'manage_options'],
	 *   ];
	 *
	 * Set to an empty array (`$this->options_pages = [];`) to register NO options
	 * pages at all — disables the feature entirely (no ACF page, no admin-bar link).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $options_pages = [
		[ 'menu_slug' => 'settings', 'page_title' => 'Theme Settings', 'admin_bar' => true ],
	];

	/**
	 * Enqueue the resizable Gutenberg editor sidebar (admin/js|css/
	 * gutenberg-resizable-sidebar.*). Set false on themes that don't ship those
	 * files, to skip the enqueue (and the asset-version lookups on missing files).
	 */
	protected bool $admin_resizable_sidebar = true;

	/**
	 * Auto-populate $context['breadcrumb'] with a Parisek\TimberKit\Breadcrumb on
	 * every request. Set false when the theme builds breadcrumbs itself (or doesn't
	 * use them) to skip the work. The legacy `! class_exists('\Breadcrumb')` guard
	 * still applies on top of this flag.
	 */
	protected bool $autopopulate_breadcrumb = true;

	/** @var bool Remove core block patterns from the inserter. */
	protected bool $gutenberg_disable_core_patterns = true;

	/**
	 * Render-time Copy-field sync for ACF Gutenberg blocks under WPML/ACFML
	 * ({@see WpmlBlockOverride}). Opt-in (default off): it changes rendered output
	 * — a Copy field changed in the source language is mirrored into every
	 * translation at render time — so projects enable it deliberately. No-ops
	 * unless WPML + ACF Pro are active (verified inside `register()`).
	 *
	 * @var bool
	 */
	protected bool $wpml_block_override = false;

	/**
	 * ACF Datastore ({@see https://www.advancedcustomfields.com/resources/acf-settings-enable_datastore/}).
	 *
	 * Opt-in (default off). Switches how ACF saves field values — through the
	 * REST / Gutenberg `wp.data` flow instead of the legacy metabox AJAX request
	 * — which lets ACF values participate in **revisions** and **autosave**.
	 * Storage is unchanged (still postmeta), Local JSON is untouched, and the
	 * Timber read path (`get_field()`) is identical. The REST save still calls
	 * `acf_save_post()` and fires the same `acf/save_post` action, so
	 * `BlockRenderer::flushPostBlockCache` and `acfml`'s WPML sync keep working.
	 *
	 * Site-wide on/off only — ACF evaluates `acf_is_using_datastore()` in
	 * no-post contexts (`rest_api_init`) where `get_post_type()` is `false`, so
	 * a per-post-type conditional would silently disable the save hooks
	 * everywhere. Requires WP 6.7+ and ACF Pro 6.8.1+ (ACF self-guards both).
	 * Fully reversible. Left off in the library because the feature is still in
	 * ACF's feedback phase and is not yet WPML-certified against the datastore;
	 * projects enable it deliberately (pilot on staging — see
	 * {@link https://github.com/portadesign/wordpress-base/issues/38}).
	 *
	 * @var bool
	 */
	protected bool $acf_datastore = false;

	/**
	 * Performance — replaces the standalone Speculation Rules plugin
	 * (https://wordpress.org/plugins/speculation-rules/)
	 */

	/**
	 * Canonical defaults for `$speculation_rules`. Single source of truth so a
	 * partial override (e.g. `['mode' => 'prefetch']`) merges cleanly without
	 * leaving the unset keys as `null` in the filter payload.
	 *
	 * @var array{mode: 'prefetch'|'prerender', eagerness: 'conservative'|'moderate'|'eager', authentication: 'logged_out'|'any'}
	 */
	private const SPECULATION_RULES_DEFAULTS = [
		'mode'           => 'prerender',
		'eagerness'      => 'moderate',
		'authentication' => 'logged_out',
	];

	/**
	 * Speculation rules configuration override.
	 *
	 * Defaults mirror the Speculation Rules plugin's defaults (faster than WP
	 * core's `prefetch`/`conservative`, with rules emitted only for logged-out
	 * visitors to keep object-cached pages safe). Set to `null` to fall back to
	 * WP core defaults (no override, no auth gate). All keys are optional —
	 * `configure_speculation_rules()` merges the override on top of
	 * `SPECULATION_RULES_DEFAULTS`, so a partial array (e.g.
	 * `['mode' => 'prefetch']`) keeps the other two keys at their default.
	 *
	 * @var array{mode?: 'prefetch'|'prerender', eagerness?: 'conservative'|'moderate'|'eager', authentication?: 'logged_out'|'any'}|null
	 */
	protected ?array $speculation_rules = self::SPECULATION_RULES_DEFAULTS;

	/** @var bool Surface a Site Health warning when the redundant standalone Speculation Rules plugin is also active. */
	protected bool $warn_speculation_rules_plugin_redundant = true;

	/** @var bool Surface a Site Health test + debug info reporting which uploadable image formats the resizer backend can actually decode. */
	protected bool $resizer_format_health = true;

	/**
	 * Slim orchestrator — resolves theme identity, delegates hook registration
	 * to concern-focused private methods, then hands off to Timber\Site.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->theme_name = $this->resolveThemeName();

		$this->registerTimberHooks();
		$this->registerBootstrapHooks();
		$this->registerAssetHooks();
		$this->registerBlockHooks();
		$this->registerAcfHooks();
		$this->registerAdminAndEditorHooks();
		$this->registerMediaHooks();
		$this->registerMiscHooks();
		$this->registerSecurityHardeningHooks();
		$this->registerCommentDisablingHooks();
		$this->registerPerformanceHooks();

		$this->setup_dev_media_proxy();
		$this->setup_wpforms_config_bridge();
		$this->registerCliCommands();

		parent::__construct();
	}

	/**
	 * Resolve the theme's text domain from the active WordPress theme.
	 * Extracted from __construct so the constructor is purely declarative.
	 *
	 * Return type is narrowed to `string` because `php-stubs/wordpress-stubs`
	 * proves `WP_Theme::get('TextDomain')` always returns a string — `TextDomain`
	 * is part of `WP_Theme::HEADERS`, so the underlying `$this->headers` lookup
	 * never short-circuits to `false`. The `$theme_name` property keeps its
	 * `string|false` shape for general safety, but this resolver is specific
	 * to `TextDomain` and benefits from the stricter type.
	 *
	 * @return string
	 */
	private function resolveThemeName(): string {
		$theme = wp_get_theme();
		return $theme->get( 'TextDomain' );
	}

	/**
	 * Register WP-CLI commands (no-op outside a WP-CLI context).
	 *
	 * `timber-kit prune-originals` reclaims disk space from preserved `-scaled`
	 * originals — a deliberate opt-in sweep, never an on-upload hook. See
	 * {@see \Parisek\TimberKit\OriginalImagePruner}.
	 *
	 * @return void
	 */
	private function registerCliCommands(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'timber-kit prune-originals', \Parisek\TimberKit\Cli\PruneOriginalsCommand::class );
	}

	/**
	 * Register Timber/Twig integration hooks — context, template loader,
	 * namespace registration, image URL rewriting, cache location, and the
	 * per-post block cache invalidation handler.
	 *
	 * @return void
	 */
	private function registerTimberHooks(): void {
		add_filter( 'timber/context', array( $this, 'timber_context' ) );
		add_filter( 'timber/twig', array( $this, 'timber_twig' ) );
		add_filter( 'timber/loader/loader', array( $this, 'timber_twig_loader' ) );
		add_filter( 'timber/locations', array( $this, 'register_timber_kit_namespace' ), 20 );
		add_action( 'acf/save_post', array( BlockRenderer::class, 'flushPostBlockCache' ), 20 );
		add_action( 'timber/twig/environment/options', array( $this, 'timber_cache_location' ), 10, 1 );
		add_action( 'timber/image/new_url', array( $this, 'timber_image_new_url' ) );
		add_action( 'timber/image/new_path', array( $this, 'timber_image_new_path' ) );
	}

	/**
	 * Register theme bootstrap hooks — theme_supports on after_setup_theme,
	 * menu registration and post type registration on init/acf/init.
	 *
	 * @return void
	 */
	private function registerBootstrapHooks(): void {
		add_action( 'after_setup_theme', array( $this, 'theme_supports' ) );
		add_action( 'init', array( $this, 'register_menus' ) );
		add_action( 'init', array( $this, 'setup_breadcrumb_labels' ), 1 );
		add_action( 'acf/init', array( $this, 'register_post_types' ) );
	}

	/**
	 * Register asset enqueueing hooks — block assets, font preloading,
	 * admin scripts, editor assets, and the conditional SVG favicon filter.
	 *
	 * @return void
	 */
	private function registerAssetHooks(): void {
		add_action( 'enqueue_block_assets', array( $this, 'assets' ) );
		add_action( 'wp_preload_resources', array( $this, 'preload_resources' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_filter( 'block_editor_settings_all', array( $this, 'inject_font_editor_styles' ), 10, 2 );
		if ( is_file( get_template_directory() . '/static/' . $this->favicon_path ) ) {
			add_filter( 'get_site_icon_url', array( $this, 'get_site_icon_url' ), 10, 3 );
		}
	}

	/**
	 * Register Gutenberg block hooks — block type allowlist, block registration,
	 * render pipeline filters, block categories, and ACF block-related actions.
	 *
	 * @return void
	 */
	private function registerBlockHooks(): void {
		add_filter( 'allowed_block_types_all', array( $this, 'allowed_block_types_all' ), 10, 2 );
		add_action( 'init', array( $this, 'gutenberg_blocks' ) );
		add_action( 'acf/init', array( $this, 'acf_options_page' ) );
		add_action( 'acf/save_post', array( $this, 'clear_cache_on_options_save' ), 20 );
		add_action( 'acf/fields/google_map/api', array( $this, 'acf_google_map_api' ) );
		add_filter( 'render_block_data', array( $this, 'render_block_data' ), 10, 3 );
		add_filter( 'render_block', array( $this, 'render_block' ), 10, 2 );
		add_filter( 'block_categories_all', array( $this, 'block_categories_all' ) );
		if ( $this->wpml_block_override ) {
			// Opt-in render-time Copy-field sync for ACF blocks under WPML.
			// register() self-guards on WPML + ACF Pro, so it no-ops otherwise.
			add_action( 'init', array( WpmlBlockOverride::class, 'register' ) );
		}
	}

	/**
	 * Register ACF JSON sync and field-formatting hooks — load/save paths,
	 * save filename, wysiwyg sanitization, and post-object value fix. Plus the
	 * opt-in `acf/settings/enable_datastore` filter when `$acf_datastore` is on.
	 *
	 * @return void
	 */
	private function registerAcfHooks(): void {
		add_filter( 'acf/settings/load_json', array( $this, 'acf_load_json' ) );
		add_filter( 'acf/json/save_paths', array( $this, 'acf_json_save_paths' ), 10, 2 );
		add_filter( 'acf/json/save_file_name', array( $this, 'acf_json_save_file_name' ), 10, 3 );
		add_filter( 'acf/update_value/type=wysiwyg', array( $this, 'sanitize_acf_editor_value' ), 10, 1 );
		add_filter( 'acf/format_value/type=post_object', array( $this, 'fix_wrong_acf_orders_with_ids' ), 10, 3 );
		if ( $this->acf_datastore ) {
			// Opt-in: route ACF saves through the REST/datastore path so values land
			// in revisions + autosave. Site-wide boolean — see the $acf_datastore
			// docblock for why a per-post-type gate would silently disable it.
			add_filter( 'acf/settings/enable_datastore', '__return_true' );
		}
	}

	/**
	 * Register admin UI and editor hooks — template redirect, page templates,
	 * post list filters, admin bar, admin head, ACF admin footer, TinyMCE,
	 * and the frontend search post-type filter.
	 *
	 * @return void
	 */
	private function registerAdminAndEditorHooks(): void {
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 0 );
		add_filter( 'theme_page_templates', array( $this, 'theme_page_templates' ) );
		add_action( 'restrict_manage_posts', array( $this, 'restrict_manage_posts' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 );
		add_action( 'admin_head', array( $this, 'hide_core_update_notifications' ), 1 );
		add_action( 'acf/input/admin_footer', array( $this, 'acf_input_admin_footer' ) );
		add_filter( 'tiny_mce_before_init', array( $this, 'tiny_mce_before_init' ) );
		add_filter( 'pre_get_posts', array( $this, 'search_post_type_filter' ) );
	}

	/**
	 * Register media-processing hooks — image attributes, JPEG quality,
	 * global styles removal, attachment cleanup, duplicate upload prevention,
	 * and the conditional filename sanitization and upload-resize filters.
	 *
	 * @return void
	 */
	private function registerMediaHooks(): void {
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'wp_get_attachment_image_attributes' ), 10, 2 );
		add_filter( 'jpeg_quality', array( $this, 'jpeg_quality' ) );
		add_filter( 'wp_editor_set_quality', array( $this, 'wp_editor_set_quality' ) );
		add_action( 'init', array( $this, 'remove_global_styles_and_svg_filters' ) );
		add_action( 'delete_attachment', array( $this, 'cleanup_cached_images' ) );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'prevent_duplicate_filename_uploads' ), 10, 1 );
		// Media processing (replaces clean-image-filenames + imsanity plugins)
		if ( $this->clean_image_filenames ) {
			add_filter( 'sanitize_file_name', array( $this, 'clean_uploaded_filename' ), 10, 1 );
		}
		// Image downscaling — drive WordPress core's native big_image_size_threshold
		// instead of resizing on wp_handle_upload (which fought core's own 2560 cap
		// and missed non-media-library upload paths). Registered unconditionally:
		// timber-kit is authoritative over the threshold across the fleet, and the
		// callback returns 0 to disable core scaling entirely. See the callback note.
		add_filter( 'big_image_size_threshold', array( $this, 'big_image_size_threshold' ), 10, 1 );
	}

	/**
	 * Register miscellaneous one-off hooks — disables wptexturize (Alpine.js
	 * compatibility) and CF7 paragraph auto-wrapping.
	 *
	 * @return void
	 */
	private function registerMiscHooks(): void {
		// Disable wptexturize to prevent WordPress from converting quotes in Alpine.js x-data attributes
		// Without this, Alpine.js attributes like x-data="{ open: false }" get converted to curly quotes
		// which breaks JavaScript parsing
		// https://core.trac.wordpress.org/ticket/29882
		add_filter( 'run_wptexturize', '__return_false' );
		// CF7 autop disable
		add_filter( 'wpcf7_autop_or_not', '__return_false' );
	}

	/**
	 * Register security hardening and cleanup hooks — all 14 feature-gated blocks
	 * (wp_head cleanup, XML-RPC, emojis, feeds, search, dashboard, admin bar,
	 * editor role, pingbacks, REST users, application passwords, author enumeration,
	 * file editing, WP generator).
	 *
	 * @return void
	 */
	private function registerSecurityHardeningHooks(): void {
		// Security & cleanup hooks (consolidated from portadesign.php plugin)
		if ( $this->cleanup_wp_head ) {
			add_action( 'init', array( $this, 'cleanup_wp_head' ) );
		}
		if ( $this->disable_xmlrpc ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', array( $this, 'remove_x_pingback_header' ) );
		}
		if ( $this->disable_emojis ) {
			add_action( 'init', array( $this, 'disable_emojis' ) );
		}
		if ( $this->disable_feeds ) {
			add_action( 'init', array( $this, 'disable_feeds' ) );
		}
		if ( $this->disable_search ) {
			add_action( 'parse_query', array( $this, 'disable_search' ) );
		}
		if ( $this->cleanup_dashboard ) {
			add_action( 'wp_dashboard_setup', array( $this, 'cleanup_dashboard_widgets' ), 999 );
			add_action( 'admin_menu', array( $this, 'cleanup_dashboard_menu' ), 99 );
		}
		if ( $this->cleanup_admin_bar ) {
			add_action( 'admin_bar_menu', array( $this, 'cleanup_admin_bar_items' ), 1200 );
		}
		if ( $this->editor_role_enhancements ) {
			add_action( 'admin_menu', array( $this, 'editor_admin_menu' ), 999 );
			add_filter( 'map_meta_cap', array( $this, 'editor_privacy_page_cap' ), 1, 4 );
			add_filter( 'login_redirect', array( $this, 'editor_login_redirect' ), 10, 3 );
			add_filter( 'wpml_user_can_translate', array( $this, 'editor_wpml_translate' ), 10, 2 );
		}
		if ( $this->disable_self_pingbacks ) {
			add_action( 'pre_ping', array( $this, 'disable_self_pingbacks' ) );
		}
		if ( $this->restrict_rest_users ) {
			add_filter( 'rest_authentication_errors', array( $this, 'restrict_rest_users_endpoint' ) );
		}
		if ( $this->disable_application_passwords ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}
		if ( $this->block_author_enumeration ) {
			// Priority 9 runs before redirect_canonical (priority 10), so the username-revealing redirect never fires.
			add_action( 'template_redirect', array( $this, 'block_author_enumeration' ), 9 );
		}
		if ( $this->disable_file_editing && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}
		if ( $this->remove_wp_generator ) {
			// Filtering the_generator suppresses the version string in wp_head AND in every feed generator.
			add_filter( 'the_generator', '__return_empty_string' );
		}
		if ( $this->disable_author_sitemap ) {
			// Drops /wp-sitemap-users-1.xml — the third username-enumeration vector alongside REST + ?author=.
			add_filter( 'wp_sitemaps_add_provider', array( $this, 'disable_author_sitemap_provider' ), 10, 2 );
		}
		if ( $this->security_headers ) {
			// wp_headers (same point as the X-Pingback removal) → filterable array, not raw header() calls.
			add_filter( 'wp_headers', array( $this, 'security_headers' ) );
		}
	}

	/**
	 * Register comment-disabling hooks — isolated from the main security block
	 * because the 30-line disable_comments gate deserves its own concern boundary.
	 *
	 * @return void
	 */
	private function registerCommentDisablingHooks(): void {
		if ( $this->disable_comments ) {
			// Late priority sweeps any post types already registered on `init`.
			add_action( 'init', array( $this, 'disable_comments' ), PHP_INT_MAX );
			// Per-post-type hook catches anything registered after the sweep (later hooks, later priorities).
			add_action( 'registered_post_type', array( $this, 'disable_comments_for_post_type' ) );
			add_filter( 'comments_open', '__return_false', 20 );
			add_filter( 'pings_open', '__return_false', 20 );
			add_filter( 'comments_array', '__return_empty_array' );
			add_action( 'admin_menu', array( $this, 'disable_comments_admin_menu' ), 999 );
			add_action( 'load-edit-comments.php', array( $this, 'disable_comments_admin_redirect' ) );
			add_action( 'load-options-discussion.php', array( $this, 'disable_comments_admin_redirect' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_comments_dequeue_scripts' ) );
			// REST: 404 standard public comment requests on /wp/v2/comments without
			// stripping the route — non-standard comment_type values (note/review/
			// editorial-comment/order_note/…) used by WP 6.9+ editor notes and
			// several plugins must still pass through.
			add_filter( 'rest_pre_dispatch', array( $this, 'disable_comments_rest_pre_dispatch' ), 10, 3 );
			// REST: defense in depth — block insertion of standard public comments
			// even if a plugin re-registers a route. Non-standard types pass through
			// for the same reason as the dispatch filter above.
			add_filter( 'rest_pre_insert_comment', array( $this, 'disable_comments_rest_insertion' ) );
			// XML-RPC: strip comment + pingback methods (no-op when $disable_xmlrpc is true).
			add_filter( 'xmlrpc_methods', array( $this, 'disable_comments_xmlrpc_methods' ) );
			// HTTP: drop X-Pingback header even when XML-RPC remains enabled site-wide.
			add_filter( 'wp_headers', array( $this, 'remove_x_pingback_header' ) );
			// Defaults: force closed for any post type that respects core defaults.
			add_filter( 'pre_option_default_comment_status', fn() => 'closed' );
			add_filter( 'pre_option_default_ping_status', fn() => 'closed' );
			// Frontend: suppress the comments-only RSS link without depending on $disable_feeds.
			add_filter( 'feed_links_show_comments_feed', '__return_false', -1 );
		}
	}

	/**
	 * Register performance hooks — Speculation Rules filter (`wp_speculation_rules_configuration`)
	 * and the redundant-plugin Site Health check. Both are gated by their own feature flags so
	 * a project that genuinely needs to keep the standalone Speculation Rules plugin (or stay on
	 * WP core defaults) can opt out independently.
	 *
	 * @return void
	 */
	private function registerPerformanceHooks(): void {
		if ( null !== $this->speculation_rules ) {
			add_filter( 'wp_speculation_rules_configuration', array( $this, 'configure_speculation_rules' ) );
		}
		if ( $this->warn_speculation_rules_plugin_redundant ) {
			add_filter( 'site_status_tests', array( $this, 'site_health_register_speculation_rules_test' ) );
		}
		if ( $this->resizer_format_health ) {
			add_filter( 'site_status_tests', array( $this, 'site_health_register_resizer_formats_test' ) );
			add_filter( 'debug_information', array( $this, 'site_health_resizer_formats_debug' ) );
		}
	}

	/**
	 * Activate dev media proxy when an upstream origin is configured.
	 *
	 * @return void
	 */
	protected function setup_dev_media_proxy(): void {
		// Resolve the upstream origin from either an explicit constant or an
		// environment variable. The constant wins when both are present so an
		// existing project that defines it keeps its exact behaviour; the env
		// fallback lets a project enable the proxy with a single line in
		// .ddev/.env (tracked, so it propagates to git worktrees) and no PHP.
		$origin = defined( 'TIMBERKIT_MEDIA_ORIGIN' )
			? (string) constant( 'TIMBERKIT_MEDIA_ORIGIN' )
			: (string) getenv( 'TIMBERKIT_MEDIA_ORIGIN', true );

		$origin = trim( $origin );
		if ( '' === $origin ) {
			return;
		}

		DevMediaProxy::register( $origin );
	}

	/**
	 * Activate the WPForms config bridge when WPForms is loaded.
	 *
	 * Only runs for projects that have WPForms active; otherwise the filter
	 * would never fire but registering it is unnecessary overhead.
	 *
	 * @return void
	 */
	protected function setup_wpforms_config_bridge(): void {
		if ( ! defined( 'WPFORMS_VERSION' ) && ! function_exists( 'wpforms' ) ) {
			return;
		}

		WPFormsConfigBridge::register();
	}

	/**
	 * Register navigation menus defined in $this->menus.
	 *
	 * Hooked to `init`.
	 *
	 * @return void
	 */
	public function register_menus() {
		foreach ( $this->menus as $slug => $label ) {
			register_nav_menu( $slug, __( $label, $this->theme_name ) );
		}
	}

	/**
	 * Hook point for projects to populate `$breadcrumb_labels` with translated
	 * strings via `_x()` / `__()` calls.
	 *
	 * Runs on `init` (priority 1) — after WordPress has loaded the theme's
	 * textdomain, so translation functions are safe. Before `Breadcrumb`
	 * dispatcher consumes the labels (`timber/context` filter fires later
	 * during request rendering).
	 *
	 * Calling `_x()` in `Base::__construct()` to populate `$breadcrumb_labels`
	 * triggers WordPress 6.7+'s `_load_textdomain_just_in_time` notice because
	 * the constructor runs before `init`. Override this method instead — the
	 * library calls it at the right time.
	 *
	 * Default implementation is a no-op; the English defaults declared on the
	 * `$breadcrumb_labels` property apply when a project doesn't override.
	 *
	 * Example override in `Base.php`:
	 *
	 *     public function setup_breadcrumb_labels() {
	 *         $this->breadcrumb_labels = array(
	 *             'home'       => _x( 'Home', $this->theme_name, $this->theme_name ),
	 *             '404'        => _x( 'Page not found', $this->theme_name, $this->theme_name ),
	 *             'search'     => _x( 'Search: %s', $this->theme_name, $this->theme_name ),
	 *             'pagination' => _x( 'Page %d', $this->theme_name, $this->theme_name ),
	 *             'author'     => _x( 'Author: %s', $this->theme_name, $this->theme_name ),
	 *         );
	 *     }
	 *
	 * Hooked to `init` (priority 1).
	 *
	 * @return void
	 */
	public function setup_breadcrumb_labels() {
		// No-op by default. Subclasses override to assign translated labels.
	}

	/**
	 * Include PHP files from templates/ directory (excluding gutenberg/) to register post types.
	 *
	 * Hooked to `acf/init`.
	 *
	 * @return void
	 */
	public function register_post_types() {

		$directory = get_template_directory() . '/templates';
		$directory_iterator = new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS );
		$flattened = new \RecursiveIteratorIterator( $directory_iterator );

		$regex_iterator = new \RegexIterator( $flattened, '/\.php$/' );
		foreach ( $regex_iterator as $file ) {
			if ( strpos( $file->getPath(), 'gutenberg' ) === FALSE ) {
				include $file->getPathname();
			}
		}

	}

	/**
	 * Add generic variables to the global Timber context.
	 *
	 * Override in child class for project-specific context (header, footer, etc.).
	 * Hooked to `timber/context`.
	 *
	 * @param array $context The Timber context array.
	 * @return array Modified context with homeUrl, templateUrl, frontPage, langcode, and search_query.
	 */
	public function timber_context( $context ) {

		$context['homeUrl'] = get_home_url();
		$context['templateUrl'] = get_template_directory_uri() . '/static';
		$context['frontPage'] = is_front_page();
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) ) {
			$context['langcode'] = apply_filters( 'wpml_current_language', NULL );
		} else {
			$context['langcode'] = get_bloginfo( 'language' );
		}
		$context['search_query'] = get_search_query();

		// Auto-populate $context['breadcrumb'] — unless the project still ships
		// a global \Breadcrumb class (legacy convention from wordpress-base
		// versions before 1.6.0). class_exists triggers Composer's classmap
		// autoload when the class is registered (cheap; one map lookup), so
		// the guard reliably detects the legacy class even before the project
		// instantiates it.
		//
		// Skipping when legacy class exists avoids double computation — the
		// project's Base::timber_context() overrides $context['breadcrumb']
		// later anyway via its own `new \Breadcrumb()` call.
		if ( $this->autopopulate_breadcrumb && ! class_exists( '\Breadcrumb' ) ) {
			$bc = new Breadcrumb( [
				'menu_name'             => $this->breadcrumb_menu_name,
				'list_page_map'         => $this->breadcrumb_list_page_map,
				'menu_trail_post_types' => $this->breadcrumb_menu_trail_post_types,
				'include_pagination'    => $this->breadcrumb_include_pagination,
				'labels'                => $this->breadcrumb_labels,
			] );
			$context['breadcrumb'] = $bc->get();
		}

		return $context;
	}

	/**
	 * Register theme support features (title-tag, thumbnails, HTML5, editor styles, etc.).
	 *
	 * Hooked to `after_setup_theme`.
	 *
	 * @return void
	 */
	public function theme_supports() {
		// Add default posts and comments RSS feed links to head.
		// add_theme_support( 'automatic-feed-links' );

		/*
		 * Let WordPress manage the document title.
		 * By adding theme support, we declare that this theme does not use a
		 * hard-coded <title> tag in the document head, and expect WordPress to
		 * provide it for us.
		 */
		add_theme_support( 'title-tag' );

		/*
		 * Enable support for Post Thumbnails on posts and pages.
		 *
		 * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
		 */
		add_theme_support( 'post-thumbnails' );

		/*
		 * Switch default core markup for search form, comment form, and comments
		 * to output valid HTML5.
		 */
		add_theme_support(
			'html5',
			array(
				'comment-form',
				'comment-list',
				'gallery',
				'caption',
				'script',
				'style',
				'search-form',
			)
		);

		/*
		 * Enable support for translations files.
		 *
		 * See: https://developer.wordpress.org/reference/functions/load_theme_textdomain/
		 */
		load_theme_textdomain( $this->theme_name, get_template_directory() . '/static/translations' );

		// Gutenberg enhancements
		if ( $this->gutenberg_align_wide ) {
			add_theme_support( 'align-wide' );
		}
		if ( $this->gutenberg_responsive_embeds ) {
			add_theme_support( 'responsive-embeds' );
		}
		if ( $this->gutenberg_editor_styles ) {
			add_theme_support( 'editor-styles' );
			add_editor_style( 'static/dist/css/gutenberg-editor.css' );
			// Font stylesheets are forwarded into the editor canvas via the
			// `block_editor_settings_all` filter (see inject_font_editor_styles()).
			// add_editor_style() inlines CSS into the iframe and strips the
			// originating baseURL, so relative `@font-face src: url()` paths
			// silently fail to load fonts — Gutenberg #41035.
		}
		if ( $this->gutenberg_disable_core_patterns ) {
			remove_theme_support( 'core-block-patterns' );
		}
	}

	/**
	 * Register Twig extensions, filters, and functions (component_*, page_*, template_exists, etc.).
	 *
	 * Hooked to `timber/twig`.
	 *
	 * @param Environment $twig The Twig environment instance.
	 * @return Environment Modified Twig environment with all extensions and functions.
	 */
	public function timber_twig( $twig ) {
		$twig->addExtension( new StringLoaderExtension() );
		$twig->addExtension( new CommonExtension() );
		$twig->addExtension( new AttributeExtension() );
		$typography_settings = get_template_directory() . '/static/' . $this->typography_config;
		$twig->addExtension( new TypographyExtension( $typography_settings ) );
		$twig->addExtension( new StringExtension() );
		$cloner = new VarCloner();
		$twig->addExtension( new DumpExtension( $cloner ) );
		$twig->addFilter( new TwigFilter( 'resizer', [ $this, 'twig_resizer_filter' ] ) );
		$twig->addFunction( new TwigFunction( 'component_*', [ $this, 'twig_component_template' ], [
			'needs_environment' => true,
			'needs_context'     => true,
			'is_safe'           => [ 'html' ],
		] ) );
		$twig->addFunction( new TwigFunction( 'page_*', [ $this, 'twig_page_template' ], [
			'needs_environment' => true,
			'needs_context'     => true,
			'is_safe'           => [ 'html' ],
		] ) );
		$twig->addFunction( new TwigFunction( 'template_exists', [ $this, 'twig_template_exists' ], [
			'needs_environment' => true,
			'needs_context'     => true,
			'is_safe'           => [ 'html' ],
		] ) );
		$twig->addFunction( new TwigFunction( 'merge_resizer', [ $this, 'twig_merge_resizer' ] ) );
		$twig->addFunction( new TwigFunction( 'gtm4wp_the_gtm_tag', [ $this, 'twig_gtm4wp_the_gtm_tag' ] ) );

		// Typography-aware translation helpers (`…t` suffix = "translate +
		// typography"): `_xt`/`__t`/`_nt`/`_nxt` mirror `_x`/`__`/`_n`/`_nx` but
		// pipe the translated string through `|typography`, so long-form copy
		// gets consistent typographic treatment without `|typography` on every
		// callsite. Production (Timber) side of parisek/styleguide#21 — keeps
		// the authoring surface identical preview ↔ live site. `is_safe: html`
		// mirrors the `|typography` filter's own contract.
		$twig->addFunction( new TwigFunction( '_xt', [ $this, 'twig_xt' ], [ 'needs_environment' => true, 'is_safe' => [ 'html' ] ] ) );
		$twig->addFunction( new TwigFunction( '__t', [ $this, 'twig_t' ], [ 'needs_environment' => true, 'is_safe' => [ 'html' ] ] ) );
		$twig->addFunction( new TwigFunction( '_nt', [ $this, 'twig_nt' ], [ 'needs_environment' => true, 'is_safe' => [ 'html' ] ] ) );
		$twig->addFunction( new TwigFunction( '_nxt', [ $this, 'twig_nxt' ], [ 'needs_environment' => true, 'is_safe' => [ 'html' ] ] ) );

		return $twig;
	}

	/**
	 * `_xt` — `_x()` then `|typography`. Backs the `_xt` Twig function.
	 *
	 * @param Environment $twig    Injected via `needs_environment`.
	 * @param string      $text    Source string.
	 * @param string      $context Gettext context.
	 * @param string      $domain  Text domain.
	 */
	public function twig_xt( Environment $twig, string $text, string $context, string $domain = 'default' ): string {
		return $this->apply_typography( $twig, _x( $text, $context, $domain ) );
	}

	/**
	 * `__t` — `__()` then `|typography`. Backs the `__t` Twig function.
	 *
	 * @param Environment $twig   Injected via `needs_environment`.
	 * @param string      $text   Source string.
	 * @param string      $domain Text domain.
	 */
	public function twig_t( Environment $twig, string $text, string $domain = 'default' ): string {
		return $this->apply_typography( $twig, __( $text, $domain ) );
	}

	/**
	 * `_nt` — `_n()` then `|typography`. Backs the `_nt` Twig function.
	 *
	 * @param Environment $twig   Injected via `needs_environment`.
	 * @param string      $single Singular form.
	 * @param string      $plural Plural form.
	 * @param int         $number Count selecting singular/plural.
	 * @param string      $domain Text domain.
	 */
	public function twig_nt( Environment $twig, string $single, string $plural, int $number, string $domain = 'default' ): string {
		return $this->apply_typography( $twig, _n( $single, $plural, $number, $domain ) );
	}

	/**
	 * `_nxt` — `_nx()` then `|typography`. Backs the `_nxt` Twig function.
	 *
	 * @param Environment $twig    Injected via `needs_environment`.
	 * @param string      $single  Singular form.
	 * @param string      $plural  Plural form.
	 * @param int         $number  Count selecting singular/plural.
	 * @param string      $context Gettext context.
	 * @param string      $domain  Text domain.
	 */
	public function twig_nxt( Environment $twig, string $single, string $plural, int $number, string $context, string $domain = 'default' ): string {
		return $this->apply_typography( $twig, _nx( $single, $plural, $number, $context, $domain ) );
	}

	/**
	 * Run a string through the env's `|typography` filter, resolved at call
	 * time so the project's tuned TypographyExtension wins. Falls back to the
	 * raw value if no `typography` filter is registered (defensive — the filter
	 * is registered by `timber_twig()` itself, so this is normally unreachable).
	 */
	private function apply_typography( Environment $twig, string $value ): string {
		$callable = $twig->getFilter( 'typography' )?->getCallable();

		if ( ! is_callable( $callable ) ) {
			return $value;
		}

		return (string) $callable( $value );
	}

	/**
	 * Polymorphic `|resizer` Twig filter dispatcher.
	 *
	 * Picks between the orientation-aware {@see Resizer::resizerAspect()} path
	 * and the historical variadic-tuples {@see Resizer::resizer()} path based on
	 * the shape of the variadic args. See {@see Resizer::isOrientationMap()}.
	 *
	 * Public so it's reachable as a `[$this, 'method']` Twig callable and so
	 * unit tests can exercise the dispatch directly without fishing the closure
	 * out of a Twig environment via reflection.
	 *
	 * @param mixed $image     The image (single dict or array of dicts) passed in by the Twig filter call.
	 * @param mixed ...$variants Either positional tuples (`['w','h','media','style', quality?]`) or a single
	 *                           orientation-keyed map (`{landscape:[...], portrait:[...], square:[...]}`).
	 * @return array
	 */
	public function twig_resizer_filter( $image, ...$variants ): array {
		$resizer = new Resizer();
		if ( Resizer::isOrientationMap( $variants ) ) {
			return $resizer->resizerAspect( $image, $variants[0] );
		}
		return $resizer->resizer( $image, $variants );
	}

	/**
	 * `component_*` Twig function — load a component template from the
	 * `@component/<name>/<name>.twig` convention, with a two-tier fallback:
	 * the `@component/alert/alert.twig` template on first failure, then a
	 * bare `<div>` on second failure.
	 *
	 * @param Environment          $env           Twig environment.
	 * @param array<string,mixed>  $context       Twig render context.
	 * @param string               $template_name Component slug (`_` is normalised to `-`).
	 * @param array<string,mixed>  $content       Optional content data merged into `context.content`.
	 * @return string Rendered HTML.
	 */
	public function twig_component_template( Environment $env, $context, string $template_name, array $content = [] ): string {
		return $this->render_namespaced_twig_template( $env, 'component', 'Component', $context, $template_name, $content );
	}

	/**
	 * `page_*` Twig function — sibling of {@see twig_component_template()} for
	 * the `@page/<name>/<name>.twig` namespace.
	 *
	 * @param Environment          $env           Twig environment.
	 * @param array<string,mixed>  $context       Twig render context.
	 * @param string               $template_name Page slug (`_` is normalised to `-`).
	 * @param array<string,mixed>  $content       Optional content data merged into `context.content`.
	 * @return string Rendered HTML.
	 */
	public function twig_page_template( Environment $env, $context, string $template_name, array $content = [] ): string {
		return $this->render_namespaced_twig_template( $env, 'page', 'Page', $context, $template_name, $content );
	}

	/**
	 * Shared body for {@see twig_component_template()} and {@see twig_page_template()}.
	 * Identical control flow except for the Twig namespace and the error label.
	 *
	 * @param Environment          $env           Twig environment.
	 * @param string               $namespace     Twig namespace (`component`, `page`) — used as `@{namespace}/...`.
	 * @param string               $label         Human-readable label shown in fallback messages (`Component`, `Page`).
	 * @param array<string,mixed>  $context       Twig render context.
	 * @param string               $template_name Slug (`_` is normalised to `-`).
	 * @param array<string,mixed>  $content       Content data merged into `context.content`.
	 * @return string Rendered HTML.
	 */
	private function render_namespaced_twig_template( Environment $env, string $namespace, string $label, $context, string $template_name, array $content ): string {
		try {
			$template_name = str_replace( '_', '-', $template_name );
			$template      = $env->load( '@' . $namespace . '/' . $template_name . '/' . $template_name . '.twig' );
			$context       = array_merge( $context, [ 'content' => $content ] );

			// Use render() so callers can save output to a Twig variable.
			return $template->render( $context );
		} catch ( \Throwable $e ) {
			try {
				$template = $env->load( '@component/alert/alert.twig' );
				$context  = array_merge( $context, [
					'content' => [
						'type'      => 'error',
						'container' => 'container',
						'message'   => $label . ' template <strong>' . $template_name . '.twig</strong> not found',
					],
				] );

				return $template->render( $context );
			} catch ( \Throwable $e ) {
				return '<div>' . $label . ' template <strong>' . $template_name . '.twig</strong> not found</div>';
			}
		}
	}

	/**
	 * `template_exists` Twig function — true when Twig can resolve the template
	 * path against the current loader, false otherwise.
	 *
	 * @param Environment         $env           Twig environment.
	 * @param array<string,mixed> $context       Twig render context (unused — kept for `needs_context`).
	 * @param string              $template_name Template path (e.g. `@component/foo/foo.twig`).
	 */
	public function twig_template_exists( Environment $env, $context, string $template_name ): bool {
		unset( $context );
		try {
			$env->load( $template_name );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * `merge_resizer` Twig function — composes multiple `Resizer`-shaped image
	 * lists into one, taking only media-qualified variants from non-last lists
	 * and the entire last list (including the fallback default image). Used to
	 * stitch a mobile + desktop image picker into a single `<picture>` source set.
	 *
	 * Variadic — each call argument is one `Resizer` output (a list of image
	 * dicts). PHPStan reads the per-argument type from this annotation.
	 *
	 * @param array<int,array<string,mixed>> ...$items One or more resizer outputs.
	 * @return list<array<string,mixed>>
	 */
	public function twig_merge_resizer( ...$items ): array {
		$images = [];

		// Drop empty lists so array_key_last reflects the real last input.
		foreach ( $items as $key => $item ) {
			if ( empty( $item ) ) {
				unset( $items[ $key ] );
			}
		}

		foreach ( $items as $key => $item ) {
			foreach ( $item as $image ) {
				if ( $key !== array_key_last( $items ) ) {
					// Non-last lists contribute ONLY media-qualified variants.
					// `Resizer::processVariant()` always sets a 'media' key, using
					// '' for tuples without a maxWidth — so `isset()` is not enough
					// (it keeps ''). `! empty()` drops both the empty-media fallback
					// and the no-key default_image, leaving the unconditional <img>
					// fallback to the LAST list (e.g. the mobile crop). Without this,
					// a desktop empty-media <source> shadows the mobile image.
					if ( ! empty( $image['media'] ) ) {
						$images[] = $image;
					}
				} else {
					$images[] = $image;
				}
			}
		}

		return $images;
	}

	/**
	 * `gtm4wp_the_gtm_tag` Twig function — calls the global GTM4WP tag printer
	 * when the plugin is loaded; no-op otherwise so themes can call it
	 * unconditionally.
	 */
	public function twig_gtm4wp_the_gtm_tag(): void {
		if ( function_exists( 'gtm4wp_the_gtm_tag' ) ) {
			gtm4wp_the_gtm_tag();
		}
	}

	/**
	 * Register Twig template namespaces (@component, @macro, @page, @icons, @images, @wordpress).
	 *
	 * Hooked to `timber/loader/loader`.
	 *
	 * @param \Twig\Loader\FilesystemLoader $loader The Twig filesystem loader.
	 * @return \Twig\Loader\FilesystemLoader Modified loader with registered paths.
	 */
	public function timber_twig_loader( $loader ) {
		$loader->addPath( get_template_directory() . '/static/templates/component', 'component' );
		$loader->addPath( get_template_directory() . '/static/templates/macro', 'macro' );
		$loader->addPath( get_template_directory() . '/static/templates/page', 'page' );
		$loader->addPath( get_template_directory() . '/static/images/icons', 'icons' );
		$loader->addPath( get_template_directory() . '/static/images', 'images' );
		$loader->addPath( get_template_directory() . '/templates', 'wordpress' );
		return $loader;
	}

	/**
	 * Register the `@timber-kit/` Twig namespace as a fallback pointing at
	 * this package's shipped templates directory.
	 *
	 * Hooked to `timber/locations`.
	 *
	 * Priority 20 (after WP default 10) so any path a downstream theme has
	 * already registered under the same namespace takes precedence — Twig's
	 * filesystem loader searches paths in array order, and we always append
	 * (preserve existing entries) so the package's path acts as the last
	 * fallback. This pattern lets themes override individual templates by
	 * registering their own path regardless of whether they prepend, append,
	 * or replace the entry.
	 *
	 * @param array<string, array<int, string>> $paths Existing namespace map.
	 * @return array<string, array<int, string>>
	 */
	public function register_timber_kit_namespace( array $paths ): array {
		$existing = isset( $paths['timber-kit'] ) && is_array( $paths['timber-kit'] )
			? $paths['timber-kit']
			: [];

		$existing[]          = __DIR__ . '/templates';
		$paths['timber-kit'] = $existing;

		return $paths;
	}

	/**
	 * Set Timber's Twig cache directory to wp-content/cache/timber.
	 *
	 * Hooked to `timber/twig/environment/options`.
	 *
	 * @param array $options Twig environment options.
	 * @return array Modified options with custom cache path.
	 */
	public function timber_cache_location( $options ) {
		$cache_dir = WP_CONTENT_DIR . '/cache/timber';

		if ( ! \is_dir( $cache_dir ) ) {
			\wp_mkdir_p( $cache_dir );
		}

		$options['cache'] = $cache_dir;

		return $options;
	}

	/**
	 * Redirect Timber image URLs to wp-content/cache/image, with WPML compatibility.
	 *
	 * Hooked to `timber/image/new_url`.
	 *
	 * @param string $location The original image URL.
	 * @return string Modified image URL pointing to cache directory.
	 */
	public function timber_image_new_url( $location ) {
		$upload_dir = wp_upload_dir();

		$new_dir = str_replace( $upload_dir['relative'], '/wp-content/cache/image', $upload_dir['basedir'] );
		if ( ! file_exists( $new_dir ) ) {
			wp_mkdir_p( $new_dir );
		}

		$location = str_replace( $upload_dir['relative'], '/wp-content/cache/image', $location );
		// Resolves issues with wrong relative URLs with WPML
		// Without this we cannot generate unique images from non default languages
		// https://github.com/timber/timber/issues/2117
		if ( strpos( $location, '/wp-content/' ) === 0 ) {
			$location = str_replace( '/wp-content', content_url(), $location );
		}

		return $location;
	}

	/**
	 * Redirect Timber image filesystem paths to wp-content/cache/image, with WPML compatibility.
	 *
	 * Hooked to `timber/image/new_path`.
	 *
	 * @param string $location The original image filesystem path.
	 * @return string Modified path pointing to cache directory.
	 */
	public function timber_image_new_path( $location ) {
		$upload_dir = wp_upload_dir();

		// Resolves issues with wrong relative URLs with WPML
		// Without this we cannot generate unique images from non default languages
		// https://github.com/timber/timber/issues/2117
		if ( strpos( $upload_dir['relative'], 'http' ) === 0 ) {
			$upload_dir['relative'] = str_replace( content_url(), '/wp-content', $upload_dir['relative'] );
		}

		$new_dir = str_replace( $upload_dir['relative'], '/wp-content/cache/image', $upload_dir['basedir'] );
		if ( ! file_exists( $new_dir ) ) {
			wp_mkdir_p( $new_dir );
		}

		$location = str_replace( $upload_dir['relative'], '/wp-content/cache/image', $location );

		return $location;
	}

	/**
	 * Enqueue frontend CSS and JS assets, dequeue jQuery, remove WPML block styles.
	 *
	 * Hooked to `enqueue_block_assets`.
	 *
	 * @return void
	 */
	public function assets() {

		foreach ( $this->font_stylesheets as $name => $path ) {
			$full_path = get_template_directory() . '/static/' . $path;
			if ( file_exists( $full_path ) ) {
				wp_enqueue_style( $this->theme_name . '-' . $name, get_template_directory_uri() . '/static/' . $path, [], filemtime( wp_normalize_path( $full_path ) ) );
			}
		}

		if ( ! is_admin() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				wp_enqueue_style( $this->theme_name, get_template_directory_uri() . '/static/dist/css/style.css', [], filemtime( wp_normalize_path( get_template_directory() . '/static/dist/css/style.css' ) ) );
			} else {
				wp_enqueue_style( $this->theme_name, get_template_directory_uri() . '/static/dist/css/style.min.css', [], filemtime( wp_normalize_path( get_template_directory() . '/static/dist/css/style.min.css' ) ) );
			}
			wp_enqueue_script_module( $this->theme_name, get_template_directory_uri() . '/static/dist/js/script.js', [], filemtime( wp_normalize_path( get_template_directory() . '/static/dist/js/script.js' ) ) );

			wp_dequeue_script( 'jquery' );

			// https://wpml.org/forums/topic/how-to-remove-loading-of-blocks-styling/
			// remove wp-content/plugins/sitepress-multilingual-cms/dist/css/blocks/styles.css
			if ( class_exists( 'WPML\BlockEditor\Loader' ) ) {
				wp_deregister_style( \WPML\BlockEditor\Loader::SCRIPT_NAME );
			}
		}

	}

	/**
	 * Add font files from $this->preload_fonts to preload resource hints.
	 *
	 * Hooked to `wp_preload_resources`.
	 *
	 * @param array $preload_resources Existing preload resource entries.
	 * @return array Modified preload resources with font entries appended.
	 */
	public function preload_resources( array $preload_resources ): array {

		foreach ( $this->preload_fonts as $font ) {
			$preload_resources[] = [
				'href' => get_template_directory_uri() . '/static/' . $font,
				'as' => 'font',
				'type' => 'font/woff2',
				'crossorigin' => 'anonymous',
			];
		}

		return $preload_resources;
	}

	/**
	 * Enqueue resizable editor sidebar script and styles on block editor screens.
	 *
	 * Hooked to `admin_enqueue_scripts`.
	 *
	 * @return void
	 */
	public function admin_enqueue_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || ! $screen->is_block_editor() ) {
			return;
		}

		// Based on https://wordpress.org/plugins/resizable-editor-sidebar/ plugin
		// But without advertising and with custom styles
		if ( $this->admin_resizable_sidebar ) {
			wp_enqueue_script( $this->theme_name . '-resizable-editor-sidebar', get_template_directory_uri() . '/admin/js/gutenberg-resizable-sidebar.js', [ 'jquery-ui-resizable' ], filemtime( wp_normalize_path( get_template_directory() . '/admin/js/gutenberg-resizable-sidebar.js' ) ), true );
			wp_enqueue_style( $this->theme_name . '-resizable-editor-sidebar', get_template_directory_uri() . '/admin/css/gutenberg-resizable-sidebar.css', [], filemtime( wp_normalize_path( get_template_directory() . '/admin/css/gutenberg-resizable-sidebar.css' ) ) );
		}
	}

	/**
	 * Enqueue Gutenberg editor CSS and theme JS module in the block editor.
	 *
	 * Hooked to `enqueue_block_editor_assets`.
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		wp_enqueue_style( $this->theme_name . '-gutenberg-editor', get_template_directory_uri() . '/static/dist/css/gutenberg-editor.css', [], filemtime( wp_normalize_path( get_template_directory() . '/static/dist/css/gutenberg-editor.css' ) ) );
		wp_enqueue_script_module( $this->theme_name, get_template_directory_uri() . '/static/dist/js/script.js', [], filemtime( wp_normalize_path( get_template_directory() . '/static/dist/js/script.js' ) ) );
	}

	/**
	 * Forward `$font_stylesheets` into the block editor canvas via the
	 * `block_editor_settings_all` filter.
	 *
	 * Uses `@import url('<absolute>')` rather than the file-inlining path that
	 * `add_editor_style()` takes. The Sage 11 / Roots pattern: the browser
	 * fetches each font CSS from its own origin, so relative `@font-face src:
	 * url("./brand.woff2")` references resolve against the CSS file's URL —
	 * not against the iframe's `blob:` document, which is the failure mode
	 * tracked in https://github.com/WordPress/gutenberg/issues/41035.
	 *
	 * Mode-agnostic: the filter fires for both iframed (modern, all blocks
	 * `apiVersion: 3`, including pure ACF v3 setups) and non-iframed (legacy /
	 * any ACF v2 block present on WP <7.0) canvases.
	 *
	 * @param array<string,mixed>     $editor_settings Editor settings keyed under `styles`/`__experimentalFeatures`/etc.
	 * @param mixed                   $context         Block editor context (unused).
	 * @return array<string,mixed>
	 */
	public function inject_font_editor_styles( array $editor_settings, $context = null ): array {
		if ( ! $this->gutenberg_editor_styles || empty( $this->font_stylesheets ) ) {
			return $editor_settings;
		}

		foreach ( $this->font_stylesheets as $path ) {
			if ( preg_match( '#^https?://#', $path ) ) {
				$url = $path;
			} else {
				$abs_path = get_template_directory() . '/static/' . $path;
				if ( ! file_exists( $abs_path ) ) {
					continue;
				}
				// `ver` (not `v`) so the URL matches what wp_enqueue_style()
				// emits in assets() — in non-iframed editor mode both
				// register the same file and a mismatched query key would
				// cost an extra round-trip per font. add_query_arg() also
				// safely composes with any `?…` already present in $path.
				$url = add_query_arg(
					'ver',
					filemtime( wp_normalize_path( $abs_path ) ),
					get_template_directory_uri() . '/static/' . $path
				);
			}

			// `esc_url_raw()` (not `esc_url()`) — `esc_url()` HTML-entity-encodes
			// `&` to `&amp;`, which CSS does not decode, breaking Google Fonts
			// URLs like `?family=Inter&display=swap`. Then defensively escape
			// the CSS string context (` ' ` and `\`) so a stray quote in the
			// URL cannot break out of the @import statement.
			$safe_url = esc_url_raw( $url );
			$css_url  = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $safe_url );

			$editor_settings['styles'][] = array(
				'css' => "@import url('" . $css_url . "');",
			);
		}

		return $editor_settings;
	}

	/**
	 * Restrict Gutenberg to allowed core blocks plus all ACF blocks.
	 *
	 * Hooked to `allowed_block_types_all`.
	 *
	 * @param bool|string[] $allowed_block_types Default allowed block types.
	 * @param \WP_Block_Editor_Context $block_editor_context The current block editor context.
	 * @return string[] Filtered list of allowed block type names.
	 */
	public function allowed_block_types_all( $allowed_block_types, $block_editor_context ) {

		$allowed_block_types = $this->allowed_core_blocks;

		// Get all registered blocks
		$all_blocks = \WP_Block_Type_Registry::get_instance()->get_all_registered();

		// Allow all ACF blocks (they start with 'acf/')
		foreach ( $all_blocks as $block_name => $block_type ) {
			if ( strpos( $block_name, 'acf/' ) === 0 ) {
				$allowed_block_types[] = $block_name;
			}
		}

		return $allowed_block_types;
	}

	/**
	 * Register Gutenberg blocks from block.json files and include PHP files in block directories.
	 *
	 * Scans templates/gutenberg and static/templates/component for block.json and .php files.
	 * Hooked to `init`.
	 *
	 * @return void
	 */
	public function gutenberg_blocks() {

		$directories = [
			get_template_directory() . '/templates/gutenberg',
			get_template_directory() . '/static/templates/component'
		];

		foreach ( $directories as $directory ) {
			if ( file_exists( $directory ) ) {
				$directory_iterator = new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS );
				$flattened = new \RecursiveIteratorIterator( $directory_iterator );

				// look for block.json files
				$regex_iterator = new \RegexIterator( $flattened, '/block\.json$/' );
				foreach ( $regex_iterator as $file ) {
					register_block_type( dirname( $file->getPathname() ) );
				}
				// look for PHP files
				$regex_iterator = new \RegexIterator( $flattened, '/\.php$/' );
				foreach ( $regex_iterator as $file ) {
					include $file->getPathname();
				}
			}
		}

	}

	/**
	 * Attach parent block info to parsed block data for nested block detection.
	 *
	 * Hooked to `render_block_data`.
	 *
	 * @see https://github.com/WordPress/gutenberg/issues/17358#issuecomment-1698655247
	 *
	 * @param array $parsed_block The parsed block array.
	 * @param array $source_block The raw source block array.
	 * @param \WP_Block|null $parent_block The parent WP_Block instance, or null if top-level.
	 * @return array Modified parsed block with 'parent' key added.
	 */
	public function render_block_data( $parsed_block, $source_block, $parent_block ) {

		$parsed_block['parent'] = null;

		if ( ! empty( $parent_block->parsed_block ) ) {
			$parsed_block['parent'] = array(
				'name' => $parent_block->name,
				'attributes' => $parent_block->attributes,
				'block' => $parent_block->parsed_block,
			);
		}

		return $parsed_block;
	}

	/**
	 * Wrap top-level core Gutenberg blocks with a Twig content template on non-article pages.
	 *
	 * Skips nested blocks, non-core blocks, layout blocks, and article post types.
	 * Hooked to `render_block`.
	 *
	 * @param string $block_content The rendered block HTML.
	 * @param array  $block         The parsed block data including blockName and attributes.
	 * @return string Possibly wrapped block HTML.
	 */
	public function render_block( $block_content, $block ) {

		// check if block has parent
		// assigned in render_block_data()
		if ( ! empty( $block['parent'] ) ) {
			return $block_content;
		}

		// Apply filter only on core gutenberg blocks
		// Custom blocks will get filter via Twig
		if ( strpos( (string) $block['blockName'], 'core/' ) === FALSE && ! in_array( $block['blockName'], [ 'contact-form-7/contact-form-selector' ] ) ) {
			return $block_content;
		}

		// Skip Core columns blocks
		if ( in_array( $block['blockName'], [ 'core/column', 'core/columns', 'core/group', 'core/spacer', 'core/block', 'core/list-item' ] ) ) {
			return $block_content;
		}

		// Check if we need raw output
		$raw = FALSE;
		if ( in_array( $block['blockName'], [ 'core/shortcode', 'contact-form-7/contact-form-selector' ] ) ) {
			$raw = TRUE;
		}

		$post_type = get_post_type();
		if ( in_array( $post_type, $this->article_post_types ) ) {
			return $block_content;
		} else {
			$context = Timber::context();
			$context['content'] = [
				'name' => 'gutenberg-' . str_replace( 'core/', '', $block['blockName'] ),
				'wrapper_classes' => '',
				'container' => 'container',
				'html' => $block_content,
				'raw' => $raw,
			];
			return Timber::compile( $this->block_wrapper_template, $context );
		}
	}

	/**
	 * Add a custom block category to the Gutenberg block inserter.
	 *
	 * Hooked to `block_categories_all`.
	 *
	 * @param array[] $categories Existing block categories.
	 * @return array[] Categories with the custom category appended.
	 */
	public function block_categories_all( $categories ) {
		return array_merge(
			$categories,
			array(
				array(
					'slug' => $this->block_category['slug'],
					'title' => __( $this->block_category['title'], $this->theme_name ),
				),
			)
		);
	}

	/**
	 * Hide WordPress core update notifications from non-administrator users.
	 *
	 * Hooked to `admin_head`.
	 *
	 * @return void
	 */
	public function hide_core_update_notifications() {
		if ( ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
		}
	}

	/**
	 * Output CSS/JS to reduce ACF WYSIWYG editor height and enable Alpine.js attribute compatibility.
	 *
	 * Hooked to `acf/input/admin_footer`.
	 *
	 * @return void
	 */
	public function acf_input_admin_footer() {

		$str = <<<EOF
			<style>
			.acf-editor-wrap iframe {
				min-height: 0;
			}
			</style>
			<script>
			(function($) {
				function sanitizeTinyMceContent(content) {
					if (typeof content !== 'string' || content === '') {
						return content;
					}

					content = content.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
					content = content.replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, '');

					if (typeof document === 'undefined' || !document.createElement) {
						return content;
					}

					var template = document.createElement('template');
					template.innerHTML = content;
					template.content.querySelectorAll('[style]').forEach(function(element) {
						element.removeAttribute('style');
					});

					content = template.innerHTML;

					return content;
				}

				// reduce placeholder textarea height to match tinymce settings (when using delay-setting)
				$('.acf-editor-wrap.delay textarea').css('height', '60px');
				// (filter called before the tinyMCE instance is created)
				acf.add_filter('wysiwyg_tinymce_settings', function(mceInit, id, field) {
				// enable autoresizing of the WYSIWYG editor
				mceInit.wp_autoresize_on = true;
				return mceInit;
				});
				// (action called when a WYSIWYG tinymce element has been initialized)
				acf.add_action('wysiwyg_tinymce_init', function(ed, id, mceInit, field) {
				// reduce tinymce's min-height settings
				ed.settings.autoresize_min_height = 60;
				// reduce iframe's 'height' style to match tinymce settings
				$('.acf-editor-wrap iframe').css('height', '60px');
				['BeforeSetContent', 'PastePreProcess', 'GetContent'].forEach(function(eventName) {
					ed.on(eventName, function(e) {
						if (e && typeof e.content === 'string') {
							e.content = sanitizeTinyMceContent(e.content);
						}
					});
				});
				});
				// Compatibility with Alpine.js and Gutenberg preview.
				// ACF's parseJSX JSON.parses any attribute value starting with `[` or `{`,
				// which crashes the block preview (and blocks saving) for Alpine directives
				// and regex `pattern`s that legitimately start with those chars. Pass those
				// attributes through untouched so ACF skips the JSON.parse.
				// https://discourse.roots.io/t/alpine-js-and-blade-acf-composer/23756/12
				acf.addFilter('acf_blocks_parse_node_attr', (current, node) => {
					var name = node.name;
					return (name.startsWith('x-') || name.startsWith(':') || name.startsWith('@') || name === 'pattern') ? node : current;
				});
			})(jQuery)
			</script>
		EOF;
		print $str;
	}

	/**
	 * Add custom body margin styles to TinyMCE editor content.
	 *
	 * Hooked to `tiny_mce_before_init`.
	 *
	 * @param array $mceInit TinyMCE initialization settings.
	 * @return array Modified settings with custom content_style.
	 */
	public function tiny_mce_before_init( $mceInit ) {
		$styles = 'body.mce-content-body { margin-top:0;margin-bottom:0 }';
		if ( isset( $mceInit['content_style'] ) ) {
			$mceInit['content_style'] .= ' ' . $styles . ' ';
		} else {
			$mceInit['content_style'] = $styles . ' ';
		}

		$mceInit['verify_html'] = true;
		$mceInit['invalid_elements'] = 'script,iframe';
		$mceInit['paste_webkit_styles'] = 'none';

		return $mceInit;
	}

	/**
	 * Sanitize ACF editor values before saving them to the database.
	 *
	 * Hooked to `acf/update_value/type=wysiwyg`.
	 *
	 * @param mixed $value Raw ACF field value.
	 * @return mixed Sanitized value, or empty string when visually empty.
	 */
	public function sanitize_acf_editor_value( $value ) {
		$value = Helpers::sanitizeEditorContent( $value );

		if ( ! is_string( $value ) ) {
			return ( null === $value || false === $value ) ? '' : $value;
		}

		$allowed_html = Helpers::getEditorAllowedHtml();
		$value = wp_kses( $value, $allowed_html );

		if ( Helpers::isEditorContentEmpty( $value, true ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Options page title: the default literal is wrapped in __() so it stays
	 * extractable; a custom title is returned verbatim (consumer owns its i18n).
	 *
	 * @param string $title
	 * @return string
	 */
	/**
	 * Register the ACF "Theme Settings" options page(s).
	 *
	 * Hooked to `acf/init`.
	 *
	 * @return void
	 */
	public function acf_options_page() {
		if ( ! function_exists( 'acf_add_options_page' ) ) {
			return;
		}

		// Register top-level pages first so a sub-page's parent is always present
		// by the time the child is registered, regardless of list order.
		foreach ( $this->options_pages as $page ) {
			if ( ! isset( $page['parent_slug'] ) ) {
				$this->register_options_page( $page );
			}
		}

		if ( function_exists( 'acf_add_options_sub_page' ) ) {
			foreach ( $this->options_pages as $page ) {
				if ( isset( $page['parent_slug'] ) ) {
					$this->register_options_page( $page );
				}
			}
		}
	}

	/**
	 * Register a single ACF options page (top-level) or sub-page (when the entry
	 * carries a parent_slug).
	 *
	 * @param array<string, string> $page An entry from $this->options_pages.
	 * @return void
	 */
	private function register_options_page( array $page ): void {
		// Title used verbatim — translation is the consumer's job (cf. $breadcrumb_labels).
		$args = [
			'page_title'      => $page['page_title'],
			'menu_title'      => $page['page_title'],
			'menu_slug'       => $page['menu_slug'],
			'capability'      => $page['capability'] ?? 'edit_posts',
			'redirect'        => false,
			'show_in_graphql' => false,
		];

		if ( isset( $page['parent_slug'] ) ) {
			$args['parent_slug'] = $page['parent_slug'];
			acf_add_options_sub_page( $args );
		} else {
			$args['icon_url'] = $page['icon_url'] ?? 'dashicons-admin-generic';
			acf_add_options_page( $args );
		}
	}

	/**
	 * Add options-page shortcuts under the site name in the admin toolbar.
	 *
	 * Iterates $options_pages and adds one node for every entry whose `admin_bar`
	 * key is truthy. Works for both top-level and sub-pages (each has its own
	 * `?page=<slug>` URL). Multiple pages may be marked, each gets a unique node
	 * id derived from its menu_slug.
	 *
	 * Hooked to `admin_bar_menu`.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 * @return void
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		foreach ( $this->options_pages as $page ) {
			if ( empty( $page['admin_bar'] ) ) {
				continue;
			}

			$wp_admin_bar->add_node( [
				'parent' => 'site-name',
				'id'     => 'theme-settings-' . $page['menu_slug'],
				'title'  => $page['page_title'],
				'href'   => add_query_arg( 'page', $page['menu_slug'], admin_url( 'admin.php' ) ),
			] );
		}
	}

	/**
	 * Clear Breeze cache when the ACF options page is saved.
	 *
	 * Hooked to `acf/save_post`.
	 *
	 * @param int|string $post_id The post ID or 'options' for the options page.
	 * @return void
	 */
	public function clear_cache_on_options_save( $post_id ) {
		if ( $post_id !== 'options' ) {
			return;
		}

		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
		}
	}

	/**
	 * Provide Google Maps API key from GOOGLE_MAPS_API_KEY constant to ACF.
	 *
	 * Hooked to `acf/fields/google_map/api`.
	 *
	 * @param array $api ACF Google Map API settings.
	 * @return array Modified API settings with key if constant is defined.
	 */
	public function acf_google_map_api( $api ) {
		// Place CONSTANT definition to wp-config.php
		// define('GOOGLE_MAPS_API_KEY', 'XXX');
		if ( defined( 'GOOGLE_MAPS_API_KEY' ) ) {
			$api['key'] = GOOGLE_MAPS_API_KEY;
		}
		return $api;
	}

	/**
	 * Add subdirectories of templates/ and static/templates/component/ to ACF JSON load paths.
	 *
	 * Hooked to `acf/settings/load_json`.
	 *
	 * @param string[] $paths Existing ACF JSON load paths.
	 * @return string[] Modified paths with component directories appended.
	 */
	public function acf_load_json( $paths ) {

		$directories = [
			get_template_directory() . '/templates',
			get_template_directory() . '/static/templates/component'
		];

		foreach ( $directories as $directory ) {
			if ( is_dir( $directory ) ) {
				$iterator = new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS );
				foreach ( $iterator as $fileinfo ) {
					if ( $fileinfo->isDir() ) {
						$paths[] = $fileinfo->getPathname();
					}
				}
			}
		}

		return $paths;
	}

	/**
	 * Route ACF JSON save to the appropriate component/template directory based on location rules.
	 *
	 * Inspects ACF field group location rules (block, post_type, taxonomy, nav_menu_item,
	 * options_page, user_form) or post_type/taxonomy config to determine the save directory.
	 * Hooked to `acf/json/save_paths`.
	 *
	 * @param string[] $paths Default save paths.
	 * @param array    $post  The ACF field group or post type/taxonomy configuration.
	 * @return string[] Modified save paths targeting the correct directory.
	 */
	public function acf_json_save_paths( $paths, $post ) {

		// find gutenberg block name from ACF location rules
		if ( isset( $post['location'] ) && is_array( $post['location'] ) ) {
			foreach ( $post['location'] as $location_group ) {
				foreach ( $location_group as $location_rule ) {
					if (
						isset( $location_rule['param'], $location_rule['value'] ) &&
						$location_rule['param'] === 'block' && ! empty( $location_rule['value'] ) ) {
						$block = str_replace( 'acf/', '', $location_rule['value'] );

						$path = get_template_directory() . '/static/templates/component/' . $block;
						if ( is_dir( $path ) ) {
							$paths = [ $path ];

							break 2;
						}

					} elseif (
						isset( $location_rule['param'], $location_rule['value'] ) &&
						$location_rule['param'] === 'post_type' && ! empty( $location_rule['value'] ) ) {

						$post_type = $location_rule['value'];

						// if post type is 'post', we use 'article' as page directory
						if ( $post_type === 'post' ) {
							$post_type = 'article';
						}

						$path = get_template_directory() . '/templates/' . $post_type;
						if ( is_dir( $path ) ) {
							$paths = [ $path ];

							break 2;
						}

					} elseif (
						isset( $location_rule['param'], $location_rule['value'] ) &&
						$location_rule['param'] === 'taxonomy' && ! empty( $location_rule['value'] ) ) {

						$path = get_template_directory() . '/templates/taxonomy';
						if ( is_dir( $path ) ) {
							$paths = [ $path ];

							break 2;
						}

					} elseif (
						isset( $location_rule['param'], $location_rule['value'] ) &&
						$location_rule['param'] === 'nav_menu_item' && ! empty( $location_rule['value'] ) ) {

						$path = get_template_directory() . '/templates/menu';
						if ( is_dir( $path ) ) {
							$paths = [ $path ];

							break 2;
						}

					} elseif (
						isset( $location_rule['param'], $location_rule['value'] ) &&
						$location_rule['param'] === 'options_page' && ! empty( $location_rule['value'] ) ) {

						$path = get_template_directory() . '/templates/options-page';
						if ( is_dir( $path ) ) {
							$paths = [ $path ];

							break 2;
						}

					} elseif (
						isset( $location_rule['param'], $location_rule['value'] ) &&
						$location_rule['param'] === 'user_form' && ! empty( $location_rule['value'] ) ) {

						$path = get_template_directory() . '/templates/user';
						if ( is_dir( $path ) ) {
							$paths = [ $path ];

							break 2;
						}
					}
				}
			}
		}
		// find content type from ACF post type configuration
		else if ( isset( $post['post_type'] ) && ! empty( $post['post_type'] ) ) {

			$post_type = $post['post_type'];

			// if post type is 'post', we use 'article' as page directory
			if ( $post_type === 'post' ) {
				$post_type = 'article';
			}

			$path = get_template_directory() . '/templates/' . $post_type;
			if ( is_dir( $path ) ) {
				$paths = [ $path ];
			}
		}
		// find taxonomy from ACF taxonomy configuration
		else if ( isset( $post['taxonomy'] ) && ! empty( $post['taxonomy'] ) ) {

			$post_type = $post['object_type'][0] ?? '';

			// if post type is 'post', we use 'article' as page directory
			if ( $post_type === 'post' ) {
				$post_type = 'article';
			}

			$path = get_template_directory() . '/templates/' . $post_type;
			if ( is_dir( $path ) ) {
				$paths = [ $path ];
			}
		}

		return $paths;
	}

	/**
	 * Determine the ACF JSON filename based on location rules or content type.
	 *
	 * Returns 'acf.json' for block, post_type, and user_form locations, or '{type}.json' for
	 * post type/taxonomy configurations. Hooked to `acf/json/save_file_name`.
	 *
	 * @param string $filename  Default filename.
	 * @param array  $post      The ACF field group or configuration.
	 * @param string $load_path The load path being saved to.
	 * @return string Determined JSON filename.
	 */
	public function acf_json_save_file_name( $filename, $post, $load_path ) {

		// find gutenberg block name from ACF location rules
		if ( isset( $post['location'] ) && is_array( $post['location'] ) ) {
			foreach ( $post['location'] as $location_group ) {
				foreach ( $location_group as $location_rule ) {
					if (
						isset( $location_rule['param'], $location_rule['value'] ) &&
						$location_rule['param'] === 'block' && ! empty( $location_rule['value'] ) ) {
						return 'acf.json';
					}
				}
			}
		}

		// find post type from ACF location rules
		if ( isset( $post['location'] ) && is_array( $post['location'] ) ) {
			foreach ( $post['location'] as $location_group ) {
				foreach ( $location_group as $location_rule ) {
					if (
						isset( $location_rule['param'], $location_rule['value'] ) &&
						$location_rule['param'] === 'post_type' && ! empty( $location_rule['value'] ) ) {
						return 'acf.json';
					}
				}
			}
		}

		// find user form from ACF location rules
		if ( isset( $post['location'] ) && is_array( $post['location'] ) ) {
			foreach ( $post['location'] as $location_group ) {
				foreach ( $location_group as $location_rule ) {
					if (
						isset( $location_rule['param'], $location_rule['value'] ) &&
						$location_rule['param'] === 'user_form' && ! empty( $location_rule['value'] ) ) {
						return 'acf.json';
					}
				}
			}
		}

		// find content type from ACF post type configuration
		if ( isset( $post['post_type'] ) && ! empty( $post['post_type'] ) ) {
			// if post type is 'post', we use 'article' as page directory
			if ( $post['post_type'] === 'post' ) {
				$post['post_type'] = 'article';
			}
			return $post['post_type'] . '.json';
		}

		// find taxonomy from ACF taxonomy configuration
		if ( isset( $post['taxonomy'] ) && ! empty( $post['taxonomy'] ) ) {
			return $post['taxonomy'] . '.json';
		}

		return $filename;
	}

	/**
	 * Convert 'page' query var to 'paged' on singular posts and prevent canonical redirect.
	 *
	 * Enables pagination on single post templates. Hooked to `template_redirect`.
	 *
	 * @return void
	 */
	public function template_redirect() {

		global $wp_query;

		if ( is_singular( 'post' ) ) {
			$page = (int) $wp_query->get( 'page' );
			if ( $page > 1 ) {
				// convert 'page' to 'paged'
				$wp_query->set( 'page', 1 );
				$wp_query->set( 'paged', $page );
			}
			// prevent redirect
			remove_action( 'template_redirect', 'redirect_canonical' );
		}
	}

	/**
	 * Define custom page templates in code. Override in child class to add templates.
	 *
	 * Hooked to `theme_page_templates`.
	 *
	 * @param array<string, string> $templates Existing page templates (filename => label).
	 * @return array<string, string> Page templates, unmodified by default.
	 */
	public function theme_page_templates( $templates ) {
		return $templates;
	}

	/**
	 * Add taxonomy dropdown filters on custom post type admin list screens.
	 *
	 * Only applies to non-default post types with taxonomies that have show_admin_column enabled.
	 * Hooked to `restrict_manage_posts`.
	 *
	 * @return void
	 */
	public function restrict_manage_posts() {

		$screen = get_current_screen();

		// Single out WordPress default posts types
		$restricted_post_types = array(
			'post',
			'page',
			'attachment',
			'revision',
			'nav_menu_item',
		);

		if ( 'edit' === $screen->base && ! in_array( $screen->post_type, $restricted_post_types ) ) {
			$taxonomies = get_object_taxonomies( $screen->post_type, 'objects' );

			// Loop through each taxonomy
			foreach ( $taxonomies as $taxonomy ) {
				if ( $taxonomy->show_admin_column ) {
					wp_dropdown_categories(
						array(
							'show_option_all' => $taxonomy->labels->all_items,
							'pad_counts' => true,
							'show_count' => true,
							'hierarchical' => true,
							'name' => $taxonomy->query_var,
							'id' => 'filter-by-' . $taxonomy->query_var,
							'class' => '',
							'value_field' => 'slug',
							'taxonomy' => $taxonomy->query_var,
							'hide_if_empty' => true,
						)
					);
				}
				;
			}
			;
		}
		;
	}

	/**
	 * Append 'img-fluid' CSS class to all attachment images.
	 *
	 * Hooked to `wp_get_attachment_image_attributes`.
	 *
	 * @param array    $attr       Image element attributes.
	 * @param \WP_Post $attachment The attachment post object.
	 * @return array Modified attributes with 'img-fluid' class added.
	 */
	public function wp_get_attachment_image_attributes( $attr, $attachment ) {

		if ( strpos( $attr['class'], 'img-fluid' ) === FALSE ) {
			$attr['class'] .= ' img-fluid';
		}

		return $attr;
	}

	/**
	 * Set JPEG quality to 100% (optimization handled by the Resizer).
	 *
	 * Hooked to `jpeg_quality`.
	 *
	 * @param int $quality Default JPEG quality.
	 * @return int Always 100.
	 */
	public function jpeg_quality( $quality ) {
		return 100;
	}

	/**
	 * Set WP image editor quality to 100% (optimization handled by the Resizer).
	 *
	 * Hooked to `wp_editor_set_quality`.
	 *
	 * @param int $quality Default editor quality.
	 * @return int Always 100.
	 */
	public function wp_editor_set_quality( $quality ) {
		return 100;
	}

	/**
	 * Fix wrong order in ACF post_object fields with WPML by translating post IDs.
	 *
	 * Hooked to `acf/format_value/type=post_object`.
	 *
	 * @see https://www.pixelbar.be/blog/fix-wrong-order-in-acf-gallery-and-relationship-fields-with-wpml/
	 *
	 * @param mixed  $value    The field value (array of post IDs or single value).
	 * @param int    $field_id The ACF field ID.
	 * @param array  $field    The ACF field settings.
	 * @return mixed Translated post IDs array, or original value if WPML is inactive.
	 */
	public function fix_wrong_acf_orders_with_ids( $value, $field_id, $field ) {

		if ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$wpml_value = array();
		foreach ( $value as $key => $v ) {
			$id = apply_filters( 'wpml_object_id', $v, 'post', true );
			if ( is_int( $id ) ) {
				$wpml_value[ $key ] = $id;
			}
		}

		return $wpml_value;
	}

	/**
	 * Restrict frontend search queries to post types defined in $this->search_post_types.
	 *
	 * Hooked to `pre_get_posts`.
	 *
	 * @param \WP_Query $query The current query object.
	 * @return \WP_Query Modified query.
	 */
	public function search_post_type_filter( $query ) {

		if ( $query->is_search && ! is_admin() ) {
			$query->set( 'post_type', $this->search_post_types );
		}

		return $query;
	}

	/**
	 * Remove Full Site Editing global styles enqueued in wp_footer (WP 6.9+).
	 *
	 * Hooked to `init`.
	 *
	 * @return void
	 */
	public function remove_global_styles_and_svg_filters() {
		// Remove Global Styles enqueued by Full Site Editing (WordPress 5.9+)
		// In WP 6.9+ global styles moved from wp_enqueue_scripts to wp_footer
		// SVG filters (wp_global_styles_render_svg_filters) deprecated in WP 6.3 — now handled per-block
		remove_action( 'wp_footer', 'wp_enqueue_global_styles' );
	}

	/**
	 * Delete cached Timber/Resizer images when an attachment is deleted.
	 *
	 * Scans wp-content/cache/image for files matching the attachment basename.
	 * Hooked to `delete_attachment`.
	 *
	 * @param int $attachment_id The ID of the deleted attachment.
	 * @return void
	 */
	public function cleanup_cached_images( $attachment_id ) {
		// Get the file path of the deleted attachment
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path ) {
			return;
		}

		// Extract filename without path
		$filename = basename( $file_path );
		$path_info = pathinfo( $filename );
		$basename = $path_info['filename']; // filename without extension

		// Define cache directory path
		$cache_dir = WP_CONTENT_DIR . '/cache/image';

		if ( ! is_dir( $cache_dir ) || ! is_readable( $cache_dir ) ) {
			return;
		}

		// Scan cache directory for matching files (including nested directories)
		$files_to_delete = [];

		$directory_iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $directory_iterator as $file ) {
			// Skip directories, only process files
			if ( $file->isDir() ) {
				continue;
			}

			$filename = $file->getFilename();

			// Pattern 1: Legacy Timber format - matches files like basename-123x456.jpg (dimensions in filename)
			// Example: image-name-1200x800.jpg, image-name-800x600-crop.webp
			$pattern1 = '/^' . preg_quote( $basename, '/' ) . '-\d+x\d+.*\..+$/';
			// Pattern 2: Custom Resizer format - matches files like basename.avif or basename.webp in subdirectories
			// Example: 1200x800-crop/image-name.avif, 800x600-center/image-name.webp (dimensions in directory name)
			$pattern2 = '/^' . preg_quote( $basename, '/' ) . '\.(avif|webp)$/';
			if ( preg_match( $pattern1, $filename ) || preg_match( $pattern2, $filename ) ) {
				$files_to_delete[] = $file->getPathname();
			}
		}

		if ( ! empty( $files_to_delete ) ) {
			// Initialize the WordPress filesystem
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			global $wp_filesystem;
			\WP_Filesystem();

			// Delete the matched files
			foreach ( $files_to_delete as $file_to_delete ) {
				if ( $wp_filesystem->exists( $file_to_delete ) ) {
					$wp_filesystem->delete( $file_to_delete );
				}
			}
		}
	}

	/**
	 * Prevent uploading images when a file with the same basename but different extension already exists.
	 *
	 * Hooked to `wp_handle_upload_prefilter`.
	 *
	 * @param array $file The upload data array with 'name', 'type', 'tmp_name', 'error', 'size'.
	 * @return array Upload data, possibly with 'error' set if a duplicate basename is found.
	 */
	public function prevent_duplicate_filename_uploads( $file ) {
		// Only check for image files
		$image_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff', 'tif', 'svg' ];

		$filename = $file['name'];
		$file_info = pathinfo( $filename );
		$basename = $file_info['filename'];
		$current_extension = strtolower( $file_info['extension'] ?? '' );

		// Skip check if the uploaded file is not an image
		if ( ! in_array( $current_extension, $image_extensions, true ) ) {
			return $file;
		}

		$upload_dir = wp_upload_dir();
		$upload_path = $upload_dir['path'];

		// Check if upload directory exists and is readable
		if ( ! is_dir( $upload_path ) || ! is_readable( $upload_path ) ) {
			return $file;
		}

		$directory_iterator = new \FilesystemIterator( $upload_path, \FilesystemIterator::SKIP_DOTS );

		foreach ( $directory_iterator as $file_info_obj ) {

			$existing_filename = $file_info_obj->getFilename();
			$existing_file_info = pathinfo( $existing_filename );
			$existing_extension = strtolower( $existing_file_info['extension'] ?? '' );

			// Only check against existing image files
			if ( ! in_array( $existing_extension, $image_extensions, true ) ) {
				continue;
			}

			if ( isset( $existing_file_info['filename'] ) &&
				$existing_file_info['filename'] === $basename &&
				$existing_filename !== $filename ) {

				if ( $existing_extension !== $current_extension ) {
					$file['error'] = sprintf(
						__( 'An image with the name "%1$s" already exists with extension "%2$s". Please rename your file or delete the existing image first.', $this->theme_name ),
						$basename,
						$existing_extension
					);
					break; // Exit early after finding first conflict
				}
			}
		}

		return $file;
	}

	/**
	 * Override site icon URL with the theme's custom favicon.
	 *
	 * Hooked to `get_site_icon_url` only when the favicon file exists on disk
	 * (gating happens in the constructor). When the file is missing the filter
	 * is not registered at all, so WordPress falls back to its default site icon.
	 *
	 * @param string $url     Default site icon URL.
	 * @param int    $size    Requested icon size in pixels.
	 * @param int    $blog_id Blog ID for multisite.
	 * @return string Favicon URL from theme's static directory.
	 */
	public function get_site_icon_url( $url, $size, $blog_id ) {
		return get_template_directory_uri() . '/static/' . $this->favicon_path;
	}

	// =========================================================================
	// Security & Cleanup (consolidated from portadesign.php plugin)
	// =========================================================================

	/**
	 * Remove unnecessary meta tags and links from wp_head (feeds, RSD, generator, etc.).
	 *
	 * Hooked to `init`.
	 *
	 * @return void
	 */
	public function cleanup_wp_head() {
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'index_rel_link' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'parent_post_rel_link', 10 );
		remove_action( 'wp_head', 'start_post_rel_link', 10 );
	}

	/**
	 * Remove X-Pingback HTTP header from responses.
	 *
	 * Hooked to `wp_headers`.
	 *
	 * @param array $headers HTTP response headers.
	 * @return array Headers with X-Pingback removed.
	 */
	public function remove_x_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/**
	 * Remove WordPress emoji scripts, styles, and mail/feed filters.
	 *
	 * Hooked to `init`.
	 *
	 * @return void
	 */
	public function disable_emojis() {
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	/**
	 * Disable all RSS/RDF/Atom feeds by returning 404 and removing feed links.
	 *
	 * Hooked to `init`.
	 *
	 * @return void
	 */
	public function disable_feeds() {
		$disable_feed = function () {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		};

		add_action( 'do_feed', $disable_feed, -1 );
		add_action( 'do_feed_rdf', $disable_feed, -1 );
		add_action( 'do_feed_rss', $disable_feed, -1 );
		add_action( 'do_feed_rss2', $disable_feed, -1 );
		add_action( 'do_feed_atom', $disable_feed, -1 );
		add_action( 'do_feed_rss2_comments', $disable_feed, -1 );
		add_action( 'do_feed_atom_comments', $disable_feed, -1 );
		add_action( 'feed_links_show_posts_feed', '__return_false', -1 );
		add_action( 'feed_links_show_comments_feed', '__return_false', -1 );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
	}

	/**
	 * Remove comment and trackback support from all registered post types and unregister comment widgets.
	 *
	 * Hooked to `init`.
	 *
	 * @return void
	 */
	public function disable_comments() {
		foreach ( get_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
			}
			if ( post_type_supports( $post_type, 'trackbacks' ) ) {
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}

		unregister_widget( 'WP_Widget_Recent_Comments' );
	}

	/**
	 * Remove comment-related admin menu and submenu pages.
	 *
	 * Hooked to `admin_menu`.
	 *
	 * @return void
	 */
	public function disable_comments_admin_menu() {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	/**
	 * Redirect from comment-related admin pages to the dashboard.
	 *
	 * Hooked to `load-edit-comments.php` and `load-options-discussion.php`.
	 *
	 * @return void
	 */
	public function disable_comments_admin_redirect() {
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Dequeue the comment-reply script on the frontend.
	 *
	 * Hooked to `wp_enqueue_scripts`.
	 *
	 * @return void
	 */
	public function disable_comments_dequeue_scripts() {
		wp_dequeue_script( 'comment-reply' );
	}

	/**
	 * Short-circuit `/wp/v2/comments` requests for standard public comment types.
	 *
	 * Hooked to `rest_pre_dispatch`. Returning a `WP_Error` with `status=404`
	 * mimics the previous "route stripped" behavior for the calls this flag
	 * exists to block (anonymous comment reads, authenticated standard
	 * comment writes), without breaking non-standard `comment_type` values:
	 *
	 * - **WordPress 6.9+ editor notes** (`type=note`, stored with
	 *   `_wp_note_status` meta) — block-editor sidebar feature; stripping
	 *   the route silently broke the notes panel.
	 * - **Plugin-specific types** — WooCommerce `order_note`/`review`,
	 *   editorial workflow `editorial-comment`, etc.
	 *
	 * The `disable_comments` flag is about removing the public spam
	 * surface, not locking down every internal use of the comments table.
	 *
	 * @param mixed            $result  Existing dispatch result (null when no other filter has short-circuited).
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Current REST request.
	 * @return mixed
	 */
	public function disable_comments_rest_pre_dispatch( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}
		$route = $request->get_route();
		// Only the collection route is the public spam surface. Single-item
		// routes (`/wp/v2/comments/<id>`, `/wp/v2/comments/<id>/<sub>`) are
		// id-scoped operations — read/update/delete of an already-existing
		// comment by id, often without a `type` query param at all (e.g.
		// WP 6.9 editor deleting a note by id). Filtering by `starts_with`
		// would 404 those unconditionally and break non-standard types we
		// already pass through on the collection route.
		if ( ! is_string( $route ) || $route !== '/wp/v2/comments' ) {
			return $result;
		}
		$type = $request->get_param( 'type' );
		if ( is_string( $type ) && $type !== '' && ! in_array( $type, array( 'comment', 'pingback', 'trackback' ), true ) ) {
			return $result;
		}
		return new \WP_Error(
			'rest_no_route',
			__( 'No route was found matching the URL and request method.', $this->theme_name ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Remove comment and pingback methods from the XML-RPC method list.
	 *
	 * No-op when `$disable_xmlrpc` is true because XML-RPC is rejected before this filter runs.
	 *
	 * Hooked to `xmlrpc_methods`.
	 *
	 * @param array<string, mixed> $methods Registered XML-RPC method handlers.
	 * @return array<string, mixed> Methods with comment and pingback entries removed.
	 */
	public function disable_comments_xmlrpc_methods( array $methods ): array {
		$blocked = array(
			'wp.getCommentCount',
			'wp.getComment',
			'wp.getComments',
			'wp.newComment',
			'wp.editComment',
			'wp.deleteComment',
			'wp.getCommentStatusList',
			'pingback.ping',
			'pingback.extensions.getPingbacks',
		);
		foreach ( $blocked as $method ) {
			unset( $methods[ $method ] );
		}
		return $methods;
	}

	/**
	 * Remove `comments` and `trackbacks` support from a single post type as it registers.
	 *
	 * Hooked to `registered_post_type` so post types registered after the `init` sweep
	 * (or on later actions such as `widgets_init`) are also covered.
	 *
	 * @param string $post_type Post type slug.
	 * @return void
	 */
	public function disable_comments_for_post_type( string $post_type ): void {
		if ( post_type_supports( $post_type, 'comments' ) ) {
			remove_post_type_support( $post_type, 'comments' );
		}
		if ( post_type_supports( $post_type, 'trackbacks' ) ) {
			remove_post_type_support( $post_type, 'trackbacks' );
		}
	}

	/**
	 * Reject REST insertion of standard public comments (`comment`/`pingback`/`trackback`)
	 * with a `403`, even if the `/wp/v2/comments` route gets re-registered.
	 *
	 * Hooked to `rest_pre_insert_comment`. Non-standard `comment_type` values
	 * (WP 6.9+ editor `note`, WooCommerce `order_note`/`review`, editorial
	 * workflow `editorial-comment`, etc.) are passed through untouched —
	 * this flag exists to remove the public spam surface, not lock down
	 * internal uses of the comments table.
	 *
	 * @param mixed $prepared_comment Comment prepared for insertion (array, object, WP_Error, or null).
	 * @return mixed `WP_Error` for blocked standard comments; the original `$prepared_comment` otherwise.
	 */
	public function disable_comments_rest_insertion( $prepared_comment ) {
		// Preserve prior short-circuit results — if another filter already
		// returned a `WP_Error` (anti-spam, custom validation, …) or `null`,
		// don't overwrite it with our generic `rest_comment_closed` and mask
		// the real failure reason.
		if ( null === $prepared_comment || $prepared_comment instanceof \WP_Error ) {
			return $prepared_comment;
		}
		$comment_type = '';
		if ( is_array( $prepared_comment ) && isset( $prepared_comment['comment_type'] ) ) {
			$comment_type = (string) $prepared_comment['comment_type'];
		} elseif ( is_object( $prepared_comment ) && isset( $prepared_comment->comment_type ) ) {
			$comment_type = (string) $prepared_comment->comment_type;
		}
		if ( $comment_type !== '' && ! in_array( $comment_type, array( 'comment', 'pingback', 'trackback' ), true ) ) {
			return $prepared_comment;
		}
		return new \WP_Error(
			'rest_comment_closed',
			__( 'Comments are closed.', $this->theme_name ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Disable frontend search by converting search queries to 404.
	 *
	 * Hooked to `parse_query`.
	 *
	 * @param \WP_Query $query The current query object.
	 * @return void
	 */
	public function disable_search( $query ) {
		if ( ! is_admin() && is_search() ) {
			$query->is_search = false;
			$query->query_vars['s'] = false;
			$query->query['s'] = false;
			$query->is_404 = true;
		}
	}

	/**
	 * Remove default dashboard widgets (activity, plugins, drafts, comments, etc.).
	 *
	 * Hooked to `wp_dashboard_setup`.
	 *
	 * @return void
	 */
	public function cleanup_dashboard_widgets() {
		global $wp_meta_boxes;

		unset( $wp_meta_boxes['dashboard']['normal']['core']['dashboard_activity'] );
		unset( $wp_meta_boxes['dashboard']['normal']['core']['dashboard_incoming_links'] );
		unset( $wp_meta_boxes['dashboard']['normal']['core']['dashboard_right_now'] );
		unset( $wp_meta_boxes['dashboard']['normal']['core']['dashboard_plugins'] );
		unset( $wp_meta_boxes['dashboard']['normal']['core']['dashboard_recent_drafts'] );
		unset( $wp_meta_boxes['dashboard']['normal']['core']['dashboard_recent_comments'] );
		unset( $wp_meta_boxes['dashboard']['side']['core']['dashboard_primary'] );
		unset( $wp_meta_boxes['dashboard']['side']['core']['dashboard_secondary'] );
		unset( $wp_meta_boxes['dashboard']['side']['core']['dashboard_quick_press'] );
		unset( $wp_meta_boxes['dashboard']['side']['high']['loginlockdown_dashboard_widget'] );
	}

	/**
	 * Remove dashboard page from admin menu for non-administrators.
	 *
	 * Hooked to `admin_menu`.
	 *
	 * @return void
	 */
	public function cleanup_dashboard_menu() {
		if ( ! current_user_can( 'administrator' ) ) {
			remove_menu_page( 'index.php' );
		}
	}

	/**
	 * Remove 'updates' and 'comments' nodes from the admin toolbar.
	 *
	 * Hooked to `admin_bar_menu`.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 * @return void
	 */
	public function cleanup_admin_bar_items( $wp_admin_bar ) {
		$wp_admin_bar->remove_node( 'updates' );
		$wp_admin_bar->remove_node( 'comments' );
	}

	/**
	 * Hide unnecessary admin pages for non-admins, grant editor theme_options cap.
	 *
	 * Removes comments, discussion, tools, themes, widgets, and customize pages as appropriate.
	 * Hooked to `admin_menu`.
	 *
	 * @return void
	 */
	public function editor_admin_menu() {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );

		if ( ! current_user_can( 'administrator' ) ) {
			remove_menu_page( 'tools.php' );
			remove_menu_page( 'activity_log_page' );
		}
		if ( current_user_can( 'editor' ) ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				$role_object = get_role( 'editor' );
				$role_object->add_cap( 'edit_theme_options' );
			}
			remove_submenu_page( 'themes.php', 'themes.php' );
			remove_submenu_page( 'themes.php', 'widgets.php' );
			remove_submenu_page( 'themes.php', 'customize.php' );
			global $submenu;
			unset( $submenu['themes.php'][6] );
		}
	}

	/**
	 * Allow editors and administrators to manage privacy page settings.
	 *
	 * Removes the manage_options requirement for manage_privacy_options capability.
	 * Hooked to `map_meta_cap`.
	 *
	 * @see https://wordpress.stackexchange.com/questions/318666/how-to-allow-editor-to-edit-privacy-page-settings-only
	 *
	 * @param string[] $caps    Required primitive capabilities for the requested capability.
	 * @param string   $cap     The capability being checked.
	 * @param int      $user_id The user ID being checked.
	 * @param array    $args    Additional arguments passed to the capability check.
	 * @return string[] Modified required capabilities.
	 */
	public function editor_privacy_page_cap( $caps, $cap, $user_id, $args ) {
		if ( ! is_user_logged_in() ) {
			return $caps;
		}

		$user_meta = get_userdata( $user_id );
		if ( array_intersect( [ 'editor', 'administrator' ], $user_meta->roles ) ) {
			if ( 'manage_privacy_options' === $cap ) {
				$manage_name = is_multisite() ? 'manage_network' : 'manage_options';
				$caps = array_diff( $caps, [ $manage_name ] );
			}
		}
		return $caps;
	}

	/**
	 * Redirect non-administrator users to the configured URL after login.
	 *
	 * Hooked to `login_redirect`.
	 *
	 * @param string   $redirect_to             Default redirect URL.
	 * @param string   $requested_redirect_to   Requested redirect URL from login form.
	 * @param \WP_User|\WP_Error $user          The authenticated user or error.
	 * @return string Redirect URL.
	 */
	public function editor_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof \WP_User && ! in_array( 'administrator', $user->roles, true ) ) {
			return admin_url( $this->editor_login_redirect_url );
		}
		return $redirect_to;
	}

	/**
	 * Allow editors with 'translate' capability to translate content in WPML.
	 *
	 * Hooked to `wpml_user_can_translate`.
	 *
	 * @param bool     $user_can_translate Whether the user can translate.
	 * @param \WP_User $user               The user being checked.
	 * @return bool True if user is an editor with translate cap, otherwise original value.
	 */
	public function editor_wpml_translate( $user_can_translate, $user ) {
		if ( in_array( 'editor', (array) $user->roles, true ) && current_user_can( 'translate' ) ) {
			return true;
		}
		return $user_can_translate;
	}

	/**
	 * Remove links pointing to the site's own URL from pingback list.
	 *
	 * Hooked to `pre_ping`.
	 *
	 * @param string[] $links Pingback URLs (passed by reference).
	 * @return void
	 */
	public function disable_self_pingbacks( &$links ) {
		$home_url = home_url();
		foreach ( $links as $key => $link ) {
			if ( strpos( $link, $home_url ) === 0 ) {
				unset( $links[ $key ] );
			}
		}
	}

	/**
	 * Block unauthenticated access to the REST API /wp/v2/users endpoint.
	 *
	 * Hooked to `rest_authentication_errors`.
	 *
	 * @param \WP_Error|null|true $result Existing authentication result.
	 * @return \WP_Error|null|true WP_Error with 401 status for unauthenticated user requests, otherwise passthrough.
	 */
	public function restrict_rest_users_endpoint( $result ) {
		if ( $result !== null ) {
			return $result;
		}

		$rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
		if ( preg_match( '#^/wp/v2/users#', $rest_route ) && ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_cannot_access',
				__( 'Only authenticated users can access the User endpoint.', $this->theme_name ),
				[ 'status' => 401 ]
			);
		}

		return $result;
	}

	/**
	 * Force a 404 when the request uses `?author=N` numeric enumeration.
	 *
	 * WordPress canonically redirects `/?author=1` to `/author/{username}/`, which
	 * exposes the login slug. Setting 404 on `template_redirect` priority 9 runs
	 * before `redirect_canonical` (priority 10) and short-circuits the disclosure.
	 * Path-based `/author/{slug}/` archives are intentionally untouched so themes
	 * that legitimately surface author pages keep working.
	 *
	 * Hooked to `template_redirect`.
	 *
	 * @return void
	 */
	public function block_author_enumeration() {
		if ( is_admin() ) {
			return;
		}

		$raw_author = isset( $_GET['author'] ) ? wp_unslash( $_GET['author'] ) : null;
		if ( ! is_string( $raw_author ) ) {
			return;
		}
		$author = trim( $raw_author );
		if ( $author === '' || ! ctype_digit( $author ) ) {
			return;
		}

		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Remove the core "users" sitemap provider (`/wp-sitemap-users-1.xml`).
	 *
	 * WordPress 5.5+ exposes author slugs/usernames through that sitemap
	 * regardless of `?author=N` blocking, so it's a third username-enumeration
	 * vector alongside REST (`restrict_rest_users`) and `?author=`
	 * (`block_author_enumeration`). Returning false for the `users` provider
	 * drops it; every other provider (`posts`, `taxonomies`, custom) passes
	 * through untouched.
	 *
	 * Hooked to `wp_sitemaps_add_provider`.
	 *
	 * @param mixed  $provider The sitemap provider (or an already-filtered value).
	 * @param string $name     The provider name (post-types, taxonomies, users).
	 * @return mixed False to drop the users provider, otherwise $provider unchanged.
	 */
	public function disable_author_sitemap_provider( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Emit a baseline set of security response headers.
	 *
	 * Merges a hardened default set over WordPress's outgoing header array, then
	 * applies `$security_headers_config` on top so projects can override, extend,
	 * or — by mapping a header to `null` — drop individual headers. HSTS is added
	 * only when the request is genuinely over TLS (see `request_is_https()`), so
	 * it still fires behind a TLS-terminating proxy — where the canonical
	 * `.htaccess` `env=HTTPS` gate silently fails — and never half-applies on
	 * plain HTTP.
	 *
	 * Hooked to `wp_headers` (the same lifecycle point as the X-Pingback
	 * removal), so the headers ride out with the main front-end response and the
	 * set stays a filterable/testable array rather than raw `header()` calls.
	 *
	 * @param array<string,string> $headers Outgoing headers WordPress will send.
	 * @return array<string,string> Headers with the security set merged in.
	 */
	public function security_headers( $headers ) {
		$defaults = array(
			'X-Frame-Options'         => 'SAMEORIGIN',
			'X-Content-Type-Options'  => 'nosniff',
			'Referrer-Policy'         => 'strict-origin-when-cross-origin',
			'Content-Security-Policy' => 'upgrade-insecure-requests',
			'Permissions-Policy'      => 'geolocation=(), microphone=(), camera=()',
			'X-XSS-Protection'        => '0',
		);

		if ( $this->request_is_https() ) {
			$defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
		}

		$merged = array_merge( $headers, $defaults, $this->security_headers_config );

		// A null in $security_headers_config means "drop this header" — array_merge
		// can only override/extend, so the unset is applied here, after the merge.
		return array_filter( $merged, static fn ( $value ) => null !== $value );
	}

	/**
	 * Whether the current request reached the site over TLS.
	 *
	 * Trusts `is_ssl()` first, then falls back to the `X-Forwarded-Proto` hint a
	 * TLS-terminating reverse proxy sets (Cloudways Nginx+Apache, Cloudflare, …),
	 * where `is_ssl()` reports false because PHP only sees the upstream
	 * plain-HTTP hop. This is the gate that keeps HSTS from silently vanishing
	 * behind such proxies.
	 *
	 * @return bool
	 */
	private function request_is_https(): bool {
		if ( is_ssl() ) {
			return true;
		}

		if ( ! isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			return false;
		}

		// A multi-hop chain ("https, http") lists the client-facing protocol first.
		$forwarded     = (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		$client_proto  = strtolower( trim( explode( ',', $forwarded )[0] ) );

		return 'https' === $client_proto;
	}

	// =========================================================================
	// Performance (replaces the standalone Speculation Rules plugin)
	// =========================================================================

	/**
	 * Filter callback for `wp_speculation_rules_configuration` (WordPress 6.8+).
	 *
	 * Returns the configured mode/eagerness array, or `null` when the configured
	 * authentication gate is `logged_out` and the current request belongs to an
	 * authenticated user — matching the behaviour of the standalone Speculation
	 * Rules plugin. Returning `null` is a load-bearing short-circuit, not a
	 * fall-through to WP core defaults: `wp_get_speculation_rules_configuration()`
	 * exits at `if (null === $config) return null;` *before* the auto→prefetch
	 * coercion runs, so no `<script type="speculationrules">` is emitted for that
	 * request. This is the safe outcome for logged-in sessions where prerender
	 * would otherwise pollute analytics, double-fire GTM events, or interfere
	 * with stateful previews.
	 *
	 * @param array<string, string>|null $config Current filter value as documented by WP core's
	 *                                           `wp_speculation_rules_configuration` filter. Other
	 *                                           filter callbacks may legitimately pass through unrelated
	 *                                           shapes, so the method defensively guards with
	 *                                           `is_array()` before reading keys.
	 * @return array{mode: 'prefetch'|'prerender', eagerness: 'conservative'|'moderate'|'eager'}|null
	 */
	public function configure_speculation_rules( $config ): ?array {
		if ( null === $this->speculation_rules ) {
			return is_array( $config ) ? $config : null;
		}

		$resolved = array_merge( self::SPECULATION_RULES_DEFAULTS, $this->speculation_rules );

		if ( 'logged_out' === $resolved['authentication'] && is_user_logged_in() ) {
			return null;
		}

		return array(
			'mode'      => $resolved['mode'],
			'eagerness' => $resolved['eagerness'],
		);
	}

	/**
	 * Register a Site Health test that warns when the standalone Speculation
	 * Rules plugin is still active alongside the theme's built-in handling.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $tests The current Site Health tests registry.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function site_health_register_speculation_rules_test( $tests ): array {
		if ( ! is_array( $tests ) ) {
			$tests = array( 'direct' => array(), 'async' => array() );
		}
		$tests['direct']['timber_kit_speculation_rules_redundant'] = array(
			'label' => __( 'Speculation Rules plugin redundancy', 'timber-kit' ),
			'test'  => array( $this, 'site_health_test_speculation_rules' ),
		);
		return $tests;
	}

	/**
	 * Site Health test callback. Returns a `good` result when the standalone
	 * plugin is inactive, or a `recommended` result with a link to manage the
	 * plugin when both code paths are running.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_test_speculation_rules(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'speculation-rules/load.php' ) ) {
			return array(
				'label'       => __( 'Speculation Rules is handled by the theme', 'timber-kit' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Performance', 'timber-kit' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'The standalone Speculation Rules plugin is not active. parisek/timber-kit configures equivalent prerender/moderate behaviour for logged-out visitors directly.', 'timber-kit' ) . '</p>',
				'test'        => 'timber_kit_speculation_rules_redundant',
			);
		}

		return array(
			'label'       => __( 'Speculation Rules plugin is redundant', 'timber-kit' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'timber-kit' ),
				'color' => 'orange',
			),
			'description' => '<p>' . esc_html__( 'parisek/timber-kit already configures Speculation Rules. Running the standalone plugin alongside duplicates the wp_speculation_rules_configuration filter and may cause settings to drift between the two sources. Deactivate and delete the plugin to keep a single source of truth.', 'timber-kit' ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'plugins.php?s=speculation-rules' ) ),
				esc_html__( 'Manage plugin', 'timber-kit' )
			),
			'test'        => 'timber_kit_speculation_rules_redundant',
		);
	}

	/**
	 * Register a Site Health test reporting whether the resizer's image backend
	 * can decode every image format WordPress accepts as an upload.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $tests The current Site Health tests registry.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function site_health_register_resizer_formats_test( $tests ): array {
		if ( ! is_array( $tests ) ) {
			$tests = array( 'direct' => array(), 'async' => array() );
		}
		$tests['direct']['timber_kit_resizer_formats'] = array(
			'label' => __( 'Image resizer format support', 'timber-kit' ),
			'test'  => array( $this, 'site_health_test_resizer_formats' ),
		);
		return $tests;
	}

	/**
	 * Site Health test callback. `good` when the resizer backend decodes every
	 * uploadable image format; `recommended` (with the specific gaps + remedy)
	 * when a format WordPress lets editors upload can't be resized — those uploads
	 * silently fall back to the full-size original instead of being optimized.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_test_resizer_formats(): array {
		$gaps = $this->resizer_format_gaps();

		if ( empty( $gaps ) ) {
			return array(
				'label'       => __( 'The image resizer can decode every uploadable format', 'timber-kit' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Performance', 'timber-kit' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'Every image format WordPress accepts as an upload can be decoded by the active image backend, so uploads are cropped and downscaled rather than served at full size.', 'timber-kit' ) . '</p>',
				'test'        => 'timber_kit_resizer_formats',
			);
		}

		$gap_list = implode( ', ', array_map( static fn ( string $mime ): string => esc_html( $mime ), $gaps ) );

		return array(
			'label'       => __( 'Some uploadable image formats cannot be resized', 'timber-kit' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'timber-kit' ),
				'color' => 'orange',
			),
			'description' => '<p>' . sprintf(
				/* translators: %s: comma-separated list of image MIME types. */
				esc_html__( 'WordPress accepts uploads in these formats, but the active image backend (Imagick or GD) cannot decode them: %s. Images uploaded in these formats are served at their original size — not cropped or downscaled. Install or enable the matching Imagick delegate (e.g. libheif for HEIC/HEIF, libavif for AVIF), or restrict editors to formats the backend supports.', 'timber-kit' ),
				$gap_list
			) . '</p>',
			'test'        => 'timber_kit_resizer_formats',
		);
	}

	/**
	 * Add the resizer's input-format capability matrix to the Site Health
	 * "Info" tab (Tools → Site Health → Info), for support / debugging.
	 *
	 * @param array<string, mixed> $info The current debug information registry.
	 * @return array<string, mixed>
	 */
	public function site_health_resizer_formats_debug( $info ): array {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$fields = array();
		foreach ( ( new Resizer() )->supportedInputFormats() as $mime => $decodable ) {
			$fields[ $mime ] = array(
				'label' => $mime,
				'value' => $decodable
					? __( 'decodable', 'timber-kit' )
					: __( 'not decodable', 'timber-kit' ),
				'debug' => $decodable ? 'yes' : 'no',
			);
		}

		$info['timber_kit_resizer'] = array(
			'label'       => __( 'Timber Kit — image resizer', 'timber-kit' ),
			'description' => __( 'Input image formats the resizer backend can decode on this server. Formats reported as "not decodable" are served at their original size.', 'timber-kit' ),
			'fields'      => $fields,
		);
		return $info;
	}

	/**
	 * Image MIME types WordPress accepts as uploads that the resizer *wants* to
	 * process but the active backend cannot decode — the silent full-size-fallback
	 * gaps. Returns an empty array when there are none.
	 *
	 * @return array<int, string>
	 */
	private function resizer_format_gaps(): array {
		$supported = ( new Resizer() )->supportedInputFormats();

		$uploadable = array_filter(
			(array) get_allowed_mime_types(),
			static fn ( string $mime ): bool => str_starts_with( $mime, 'image/' )
		);

		$gaps = array();
		foreach ( $uploadable as $mime ) {
			// Only formats the resizer actually targets (present in the matrix) and
			// that the backend can't decode count as a gap. SVG / ico / sequence
			// types aren't in the matrix — they're out of the resizer's scope, not gaps.
			if ( array_key_exists( $mime, $supported ) && false === $supported[ $mime ] ) {
				$gaps[] = $mime;
			}
		}
		return array_values( array_unique( $gaps ) );
	}

	// =========================================================================
	// Media Processing (replaces clean-image-filenames + imsanity plugins)
	// =========================================================================

	/**
	 * Sanitize uploaded filenames: remove diacritics, lowercase, replace spaces with hyphens.
	 *
	 * Replaces the clean-image-filenames plugin. Hooked to `sanitize_file_name`.
	 *
	 * @param string $filename The original uploaded filename.
	 * @return string Cleaned filename with extension preserved.
	 */
	public function clean_uploaded_filename( $filename ) {
		$info = pathinfo( $filename );
		$name = $info['filename'] ?? '';
		$ext = isset( $info['extension'] ) ? '.' . $info['extension'] : '';

		// Remove diacritics (ě→e, č→c, etc.)
		$name = remove_accents( $name );
		// Lowercase
		$name = strtolower( $name );
		// Replace spaces and underscores with hyphens
		$name = preg_replace( '/[\s_]+/', '-', $name );
		// Remove anything that isn't alphanumeric or hyphens
		$name = preg_replace( '/[^a-z0-9\-]/', '', $name );
		// Collapse multiple hyphens
		$name = preg_replace( '/-+/', '-', $name );
		// Trim hyphens from edges
		$name = trim( $name, '-' );

		return $name . $ext;
	}

	/**
	 * Resolve the upload size cap for WordPress core's `big_image_size_threshold`.
	 *
	 * Reads the canonical {@see $big_image_size_threshold} property, falling back
	 * to the deprecated {@see $max_upload_width} / {@see $max_upload_height} pair
	 * (larger edge wins) for backward compatibility. The incoming `$threshold`
	 * argument is intentionally ignored — see the authoritative note below.
	 *
	 * Returning `0` disables core scaling entirely (no `-scaled` derivative, no
	 * separately-preserved original). This is **authoritative** — registered
	 * unconditionally, so it overrides any other plugin's `big_image_size_threshold`
	 * filter. That is deliberate: timber-kit owns the threshold across the fleet.
	 *
	 * Hooked to `big_image_size_threshold`.
	 *
	 * @param int $threshold Incoming threshold from WP core / earlier filters (ignored — see note).
	 * @return int Effective threshold in pixels; `0` disables scaling.
	 */
	public function big_image_size_threshold( $threshold ) {
		// Deprecated width/height pair wins when EITHER is explicitly set (non-null),
		// including 0 — which preserves the legacy "both 0 disables resizing" contract.
		if ( null !== $this->max_upload_width || null !== $this->max_upload_height ) {
			return max( (int) $this->max_upload_width, (int) $this->max_upload_height );
		}

		return $this->big_image_size_threshold;
	}

	/**
	 * Downscale uploaded images exceeding the configured maximum dimensions.
	 *
	 * @deprecated Image downscaling now drives WordPress core's native
	 *             `big_image_size_threshold` (see {@see big_image_size_threshold()}),
	 *             which avoids fighting core's own 2560 cap and covers every upload
	 *             path. This method is no longer hooked and is kept only for
	 *             backward compatibility with code that called it directly.
	 *
	 * Supports JPEG, PNG, GIF, and WebP. Replaces the imsanity plugin.
	 *
	 * @param array $upload Upload data with 'file', 'url', and 'type' keys.
	 * @return array Unmodified upload data (image is resized in place).
	 */
	public function resize_uploaded_image( $upload ) {
		if ( ! isset( $upload['file'] ) || ! isset( $upload['type'] ) ) {
			return $upload;
		}

		$allowed_types = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
		if ( ! in_array( $upload['type'], $allowed_types, true ) ) {
			return $upload;
		}

		$image_size = getimagesize( $upload['file'] );
		if ( ! $image_size ) {
			return $upload;
		}

		list( $width, $height ) = $image_size;

		$max_width  = (int) $this->max_upload_width;
		$max_height = (int) $this->max_upload_height;
		if ( $max_width <= 0 && $max_height <= 0 ) {
			return $upload;
		}

		if ( $width <= $max_width && $height <= $max_height ) {
			return $upload;
		}

		$editor = wp_get_image_editor( $upload['file'] );
		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		$resized = $editor->resize( $max_width, $max_height );
		if ( ! is_wp_error( $resized ) ) {
			$editor->save( $upload['file'] );
		}

		return $upload;
	}
}
