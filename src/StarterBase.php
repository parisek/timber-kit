<?php

declare(strict_types=1);

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

class StarterBase extends Site {

	public $theme_name;

	/**
	 * Configurable properties — override in child constructor before calling parent::__construct()
	 */
	protected array $menus = [];
	protected array $font_stylesheets = [];
	protected array $preload_fonts = [];
	protected array $search_post_types = [ 'post' ];
	protected array $article_post_types = [ 'post' ];
	protected array $block_category = [ 'slug' => 'custom', 'title' => 'Custom' ];
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
	protected string $favicon_path = 'images/touch/favicon.svg';
	protected string $typography_config = 'typography.yml';
	protected string $block_wrapper_template = '@component/content/content.twig';

	/**
	 * Security & cleanup — override in child constructor to disable
	 */
	protected bool $cleanup_wp_head = true;
	protected bool $disable_xmlrpc = true;
	protected bool $disable_emojis = true;
	protected bool $disable_feeds = true;
	protected bool $disable_comments = true;
	protected bool $disable_search = true;
	protected bool $cleanup_dashboard = true;
	protected bool $cleanup_admin_bar = true;
	protected bool $editor_role_enhancements = true;
	protected string $editor_login_redirect_url = 'edit.php?post_type=page';
	protected bool $disable_self_pingbacks = true;
	protected bool $restrict_rest_users = true;

	/**
	 * Media processing — replaces clean-image-filenames + imsanity plugins
	 */
	protected bool $clean_image_filenames = true;
	protected int $max_upload_width = 2560;
	protected int $max_upload_height = 2560;

	/**
	 * Gutenberg enhancements
	 */
	protected bool $gutenberg_align_wide = true;
	protected bool $gutenberg_responsive_embeds = true;
	protected bool $gutenberg_editor_styles = true;
	protected bool $gutenberg_disable_core_patterns = true;

	public function __construct() {
		add_action( 'after_setup_theme', array( $this, 'theme_supports' ) );
		add_filter( 'timber/context', array( $this, 'timber_context' ) );
		add_filter( 'timber/twig', array( $this, 'timber_twig' ) );
		add_filter( 'timber/loader/loader', array( $this, 'timber_twig_loader' ) );
		add_action( 'timber/twig/environment/options', array( $this, 'timber_cache_location' ), 10, 1 );
		add_action( 'timber/image/new_url', array( $this, 'timber_image_new_url' ) );
		add_action( 'timber/image/new_path', array( $this, 'timber_image_new_path' ) );
		add_action( 'init', array( $this, 'register_menus' ) );
		add_action( 'acf/init', array( $this, 'register_post_types' ) );
		add_action( 'enqueue_block_assets', array( $this, 'assets' ) );
		add_action( 'wp_preload_resources', array( $this, 'preload_resources' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_filter( 'allowed_block_types_all', array( $this, 'allowed_block_types_all' ), 10, 2 );
		add_action( 'init', array( $this, 'gutenberg_blocks' ) );
		add_action( 'acf/init', array( $this, 'acf_options_page' ) );
		add_action( 'acf/save_post', array( $this, 'clear_cache_on_options_save' ), 20 );
		add_action( 'acf/fields/google_map/api', array( $this, 'acf_google_map_api' ) );
		add_filter( 'acf/settings/load_json', array( $this, 'acf_load_json' ) );
		add_filter( 'acf/json/save_paths', array( $this, 'acf_json_save_paths' ), 10, 2 );
		add_filter( 'acf/json/save_file_name', array( $this, 'acf_json_save_file_name' ), 10, 3 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 0 );
		add_filter( 'theme_page_templates', array( $this, 'theme_page_templates' ) );
		add_action( 'restrict_manage_posts', array( $this, 'restrict_manage_posts' ) );
		add_filter( 'render_block_data', array( $this, 'render_block_data' ), 10, 3 );
		add_filter( 'render_block', array( $this, 'render_block' ), 10, 2 );
		add_filter( 'block_categories_all', array( $this, 'block_categories_all' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 );
		add_action( 'admin_head', array( $this, 'hide_core_update_notifications' ), 1 );
		add_action( 'acf/input/admin_footer', array( $this, 'acf_input_admin_footer' ) );
		add_filter( 'tiny_mce_before_init', array( $this, 'tiny_mce_before_init' ) );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'wp_get_attachment_image_attributes' ), 10, 2 );
		add_filter( 'jpeg_quality', array( $this, 'jpeg_quality' ) );
		add_filter( 'wp_editor_set_quality', array( $this, 'wp_editor_set_quality' ) );
		add_filter( 'acf/format_value/type=post_object', array( $this, 'fix_wrong_acf_orders_with_ids' ), 10, 3 );
		add_filter( 'pre_get_posts', array( $this, 'search_post_type_filter' ) );
		add_action( 'init', array( $this, 'remove_global_styles_and_svg_filters' ) );
		add_action( 'delete_attachment', array( $this, 'cleanup_cached_images' ) );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'prevent_duplicate_filename_uploads' ), 10, 1 );
		add_filter( 'get_site_icon_url', array( $this, 'get_site_icon_url' ), 10, 3 );
		// Disable wptexturize to prevent WordPress from converting quotes in Alpine.js x-data attributes
		// Without this, Alpine.js attributes like x-data="{ open: false }" get converted to curly quotes
		// which breaks JavaScript parsing
		// https://core.trac.wordpress.org/ticket/29882
		add_filter( 'run_wptexturize', '__return_false' );

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
		if ( $this->disable_comments ) {
			add_action( 'init', array( $this, 'disable_comments' ), 100 );
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

		// Media processing (replaces clean-image-filenames + imsanity plugins)
		if ( $this->clean_image_filenames ) {
			add_filter( 'sanitize_file_name', array( $this, 'clean_uploaded_filename' ), 10, 1 );
		}
		if ( $this->max_upload_width > 0 || $this->max_upload_height > 0 ) {
			add_filter( 'wp_handle_upload', array( $this, 'resize_uploaded_image' ), 10, 1 );
		}

		// CF7 autop disable
		add_filter( 'wpcf7_autop_or_not', '__return_false' );

		$theme = wp_get_theme();
		$this->theme_name = $theme->get( 'TextDomain' );

		parent::__construct();
	}

	/**
	 * Register Menu.
	 */
	public function register_menus() {
		foreach ( $this->menus as $slug => $label ) {
			register_nav_menu( $slug, __( $label, $this->theme_name ) );
		}
	}

	/**
	 * Register Post Type.
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
	 * Add generic variables to global context.
	 * Override in child class for project-specific context (header, footer, etc.)
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
		$context['ccnstL'] = get_privacy_policy_url();
		$context['search_query'] = get_search_query();

		return $context;
	}

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
		}
		if ( $this->gutenberg_disable_core_patterns ) {
			remove_theme_support( 'core-block-patterns' );
		}
	}

	/**
	 * Register Twig Functions.
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
		$twig->addFilter( new TwigFilter( 'resizer', function ( $image, ...$variants ) {
			$resizer = new Resizer();
			return $resizer->resizer( $image, $variants );
		} ) );
		$twig->addFunction( new TwigFunction( 'component_*', function ( Environment $env, $context, $template_name, $content = [] ) {
			try {
				$template_name = str_replace( '_', '-', $template_name );
				$template = $env->load( '@component/' . $template_name . '/' . $template_name . '.twig' );
				$context = array_merge( $context, [ 'content' => $content ] );

				// we use render to allow save output to twig variable
				return $template->render( $context );
			} catch (\Throwable $e) {
				try {
					$template = $env->load( '@component/alert/alert.twig' );
					$content = [
						'type' => 'error',
						'container' => 'container',
						'message' => 'Component template <strong>' . $template_name . '.twig</strong> not found',
					];
					$context = array_merge( $context, [ 'content' => $content ] );

					return $template->render( $context );
				} catch (\Throwable $e) {
					return '<div>Component template <strong>' . $template_name . '.twig</strong> not found</div>';
				}
			}
		}, [
			'needs_environment' => true,
			'needs_context' => true,
			'is_safe' => [ 'html' ]
		] ) );
		$twig->addFunction( new TwigFunction( 'page_*', function ( Environment $env, $context, $template_name, $content = [] ) {
			try {
				$template_name = str_replace( '_', '-', $template_name );
				$template = $env->load( '@page/' . $template_name . '/' . $template_name . '.twig' );
				$context = array_merge( $context, [ 'content' => $content ] );

				// we use render to allow save output to twig variable
				return $template->render( $context );
			} catch (\Throwable $e) {
				try {
					$template = $env->load( '@component/alert/alert.twig' );
					$content = [
						'type' => 'error',
						'container' => 'container',
						'message' => 'Page template <strong>' . $template_name . '.twig</strong> not found',
					];
					$context = array_merge( $context, [ 'content' => $content ] );

					return $template->render( $context );
				} catch (\Throwable $e) {
					return '<div>Page template <strong>' . $template_name . '.twig</strong> not found</div>';
				}
			}
		}, [
			'needs_environment' => true,
			'needs_context' => true,
			'is_safe' => [ 'html' ]
		] ) );
		$twig->addFunction( new TwigFunction( 'template_exists', function ( Environment $env, $context, $template_name ) {
			try {
				$env->load( $template_name );
				return TRUE;
			} catch (\Throwable $e) {
				return FALSE;
			}
		}, [
			'needs_environment' => true,
			'needs_context' => true,
			'is_safe' => [ 'html' ]
		] ) );
		$twig->addFunction( new TwigFunction( 'merge_resizer', function ( ...$items ) {

			$images = [];

			// fix if mobile image is empty
			foreach ( $items as $key => $item ) {
				if ( empty( $item ) ) {
					unset( $items[ $key ] );
				}
			}

			foreach ( $items as $key => $item ) {
				foreach ( $item as $image ) {
					if ( $key !== array_key_last( $items ) ) {
						if ( isset( $image['media'] ) ) {
							$images[] = $image;
						}
					} else {
						$images[] = $image;
					}
				}
			}

			return $images;
		} ) );
		$twig->addFunction( new TwigFunction( 'gtm4wp_the_gtm_tag', function () {
			if ( function_exists( 'gtm4wp_the_gtm_tag' ) ) {
				gtm4wp_the_gtm_tag();
			}
		} ) );

		return $twig;
	}

	/**
	 * Register Twig Namespace.
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
	 * Change Timber's cache folder.
	 */
	public function timber_cache_location( $options ) {
		$options['cache'] = WP_CONTENT_DIR . '/cache/timber';

		return $options;
	}

	/**
	 * Change Timber's image url.
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
	 * Change Timber's image path.
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
	 * Enqueue scripts and styles.
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
	 * Preload resources in head
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
	 * Enqueue scripts and styles for admin.
	 */
	public function admin_enqueue_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || ! $screen->is_block_editor() ) {
			return;
		}

		// Based on https://wordpress.org/plugins/resizable-editor-sidebar/ plugin
		// But without advertising and with custom styles
		wp_enqueue_script( $this->theme_name . '-resizable-editor-sidebar', get_template_directory_uri() . '/admin/js/gutenberg-resizable-sidebar.js', [ 'jquery-ui-resizable' ], filemtime( wp_normalize_path( get_template_directory() . '/admin/js/gutenberg-resizable-sidebar.js' ) ), true );
		wp_enqueue_style( $this->theme_name . '-resizable-editor-sidebar', get_template_directory_uri() . '/admin/css/gutenberg-resizable-sidebar.css', [], filemtime( wp_normalize_path( get_template_directory() . '/admin/css/gutenberg-resizable-sidebar.css' ) ) );
	}

	/**
	 * Enqueue scripts and styles for block editor.
	 */
	public function enqueue_block_editor_assets() {
		wp_enqueue_style( $this->theme_name . '-gutenberg-editor', get_template_directory_uri() . '/static/dist/css/gutenberg-editor.css', [], filemtime( wp_normalize_path( get_template_directory() . '/static/dist/css/gutenberg-editor.css' ) ) );
		wp_enqueue_script_module( $this->theme_name, get_template_directory_uri() . '/static/dist/js/script.js', [], filemtime( wp_normalize_path( get_template_directory() . '/static/dist/js/script.js' ) ) );
	}

	/**
	 * Allow only specific blocks in Gutenberg editor
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
	 * Load Dynamicaly Gutenberg Blocks with modern block.json
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
	 * Identify if gutenberg parent block
	 * from https://github.com/WordPress/gutenberg/issues/17358#issuecomment-1698655247
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
	 * Add custom wrapper to all core Gutenberg blocks
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
	 * Custom categories for Gutenberg Blocks
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
	 * Hide WordPress core update notifications from all users except administrators
	 * From https://www.cssigniter.com/hide-the-wordpress-update-notifications-from-all-users-except-administrators/
	 */
	public function hide_core_update_notifications() {
		if ( ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
		}
	}

	/**
	 * ACF Wysiwyg set height to lower value then default
	 * From https://gist.github.com/courtneymyers/eb51f918181746181871f7ae516b428b
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
				});
				// Compatibility with Alpine.js and Gutenberg preview
				// https://discourse.roots.io/t/alpine-js-and-blade-acf-composer/23756/12
				acf.addFilter('acf_blocks_parse_node_attr', (current, node) => node.name.startsWith('x-') ? node : current);
			})(jQuery)
			</script>
		EOF;
		print $str;
	}

	/**
	 * ACF Wysiwyg set height to lower value then default
	 * From https://gist.github.com/courtneymyers/eb51f918181746181871f7ae516b428b
	 */
	public function tiny_mce_before_init( $mceInit ) {
		$styles = 'body.mce-content-body { margin-top:0;margin-bottom:0 }';
		if ( isset( $mceInit['content_style'] ) ) {
			$mceInit['content_style'] .= ' ' . $styles . ' ';
		} else {
			$mceInit['content_style'] = $styles . ' ';
		}
		return $mceInit;
	}

	/**
	 * Create custom admin pages
	 */
	public function acf_options_page() {
		if ( function_exists( 'acf_add_options_page' ) ) {

			acf_add_options_page( [
				'page_title' => __( 'Theme Settings', $this->theme_name ),
				'menu_title' => __( 'Theme Settings', $this->theme_name ),
				'menu_slug' => 'settings',
				'capability' => 'edit_posts',
				'icon_url' => 'dashicons-admin-generic',
				'redirect' => false,
				'graphql_field_name' => 'settings',
				'show_in_graphql' => false
			] );
		}
	}


	/**
	 * Add Settings link to admin top bar
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		$wp_admin_bar->add_node( [
			'parent' => 'site-name',
			'id' => 'theme-settings',
			'title' => __( 'Theme Settings', $this->theme_name ),
			'href' => admin_url( 'admin.php?page=settings' ),
		] );
	}

	/**
	 * Clear Breeze cache when ACF options page is saved
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
	 * Google Maps API key
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
	 * ACF load JSON files from component directories
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
	 * ACF save JSON files to component directories
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
	 * ACF save JSON file name
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
	 * Template redirect
	 * Allow paging on custom post types
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
	 * Define custom page templates in code
	 */
	public function theme_page_templates( $templates ) {
		return $templates;
	}

	/**
	 * Allow to filter by custom taxonomies in administration
	 * https://wordpress.stackexchange.com/a/387502
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
	 * Add default CSS classes to image
	 */
	public function wp_get_attachment_image_attributes( $attr, $attachment ) {

		if ( strpos( $attr['class'], 'img-fluid' ) === FALSE ) {
			$attr['class'] .= ' img-fluid';
		}

		return $attr;
	}

	/**
	 * Set maximum quality, use resizer for optimization.
	 */
	public function jpeg_quality( $quality ) {
		return 100;
	}

	/**
	 * Set maximum quality, use resizer for optimization.
	 */
	public function wp_editor_set_quality( $quality ) {
		return 100;
	}

	/**
	 * Fix wrong order in ACF gallery, relationship, post_object fields with WPML
	 * from https://www.pixelbar.be/blog/fix-wrong-order-in-acf-gallery-and-relationship-fields-with-wpml/
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

	public function search_post_type_filter( $query ) {

		if ( $query->is_search && ! is_admin() ) {
			$query->set( 'post_type', $this->search_post_types );
		}

		return $query;
	}

	public function remove_global_styles_and_svg_filters() {
		// Remove Global Styles enqueued by Full Site Editing (WordPress 5.9+)
		// In WP 6.9+ global styles moved from wp_enqueue_scripts to wp_footer
		// SVG filters (wp_global_styles_render_svg_filters) deprecated in WP 6.3 — now handled per-block
		remove_action( 'wp_footer', 'wp_enqueue_global_styles' );
	}

	/**
	 * Clean up Timber generated images when attachment is deleted
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
	 * Prevent uploading images with duplicate filenames but different extensions like sample.jpg and sample.png
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
	 * Redirect site icon URL to custom favicon in theme directory
	 */
	public function get_site_icon_url( $url, $size, $blog_id ) {
		return get_template_directory_uri() . '/static/' . $this->favicon_path;
	}

	// =========================================================================
	// Security & Cleanup (consolidated from portadesign.php plugin)
	// =========================================================================

	/**
	 * Remove unnecessary meta tags from wp_head
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
	 * Remove X-Pingback HTTP header
	 */
	public function remove_x_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/**
	 * Disable WordPress emoji scripts, styles and filters
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
	 * Disable all RSS/RDF/Atom feeds — return 404
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
	 * Disable comment support from posts and pages
	 */
	public function disable_comments() {
		remove_post_type_support( 'post', 'comments' );
		remove_post_type_support( 'page', 'comments' );
	}

	/**
	 * Disable frontend search — redirect to 404
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
	 * Remove dashboard widgets
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
	 * Remove dashboard page for non-administrators
	 */
	public function cleanup_dashboard_menu() {
		if ( ! current_user_can( 'administrator' ) ) {
			remove_menu_page( 'index.php' );
		}
	}

	/**
	 * Remove updates and comments nodes from admin toolbar
	 */
	public function cleanup_admin_bar_items( $wp_admin_bar ) {
		$wp_admin_bar->remove_node( 'updates' );
		$wp_admin_bar->remove_node( 'comments' );
	}

	/**
	 * Editor role: hide unnecessary admin pages, grant theme_options cap,
	 * hide themes/widgets/customize pages
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
	 * Allow editor/administrator to edit privacy page settings
	 * @see https://wordpress.stackexchange.com/questions/318666/how-to-allow-editor-to-edit-privacy-page-settings-only
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
	 * Redirect non-admin users to pages list after login
	 */
	public function editor_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof \WP_User && ! in_array( 'administrator', $user->roles, true ) ) {
			return admin_url( $this->editor_login_redirect_url );
		}
		return $redirect_to;
	}

	/**
	 * Allow editor to translate with WPML
	 */
	public function editor_wpml_translate( $user_can_translate, $user ) {
		if ( in_array( 'editor', (array) $user->roles, true ) && current_user_can( 'translate' ) ) {
			return true;
		}
		return $user_can_translate;
	}

	/**
	 * Disable self-pingbacks
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
	 * Restrict REST API /wp/v2/users endpoint to authenticated users only
	 */
	public function restrict_rest_users_endpoint( $result ) {
		if ( $result !== null ) {
			return $result;
		}

		$rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
		if ( preg_match( '#^/wp/v2/users#', $rest_route ) && ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_cannot_access',
				__( 'Only authenticated users can access the User endpoint.', 'starter_theme' ),
				[ 'status' => 401 ]
			);
		}

		return $result;
	}

	// =========================================================================
	// Media Processing (replaces clean-image-filenames + imsanity plugins)
	// =========================================================================

	/**
	 * Clean uploaded filenames — remove diacritics, lowercase, normalize
	 * Replaces clean-image-filenames plugin
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
	 * Resize uploaded images if they exceed max dimensions
	 * Replaces imsanity plugin
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

		if ( $width <= $this->max_upload_width && $height <= $this->max_upload_height ) {
			return $upload;
		}

		$editor = wp_get_image_editor( $upload['file'] );
		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		$editor->resize( $this->max_upload_width, $this->max_upload_height );
		$editor->save( $upload['file'] );

		return $upload;
	}
}
