<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/tmp/wp-content' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Minimal wpdb stub so production code can guard with `instanceof \wpdb`
// (mirrors the WP_Post / WP_Term stub approach below).
if ( ! class_exists( 'wpdb' ) ) {
	#[\AllowDynamicProperties]
	class wpdb {
	}
}

// Lightweight `wp_strip_all_tags` stub so production code that calls it
// directly (e.g. Helpers::readTime) works without per-test Brain Monkey
// mocks that leak across test classes via Patchwork.
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = (string) $string;
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $string );
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
	}
}

// Minimal WP_Error stub for unit tests
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public array $error_data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code = $code;
			$this->message = $message;
			$this->error_data = [ $code => $data ];
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

// Minimal WP_Query stub so production `instanceof WP_Query` checks pass in tests.
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public bool $is_404 = false;
		public bool $is_search = false;
		public bool $is_archive = false;
		public bool $is_home = false;
		public bool $is_post_type_archive = false;
		public bool $is_feed = false;
		public array $query_vars = [];
		public array $query = [];
		private bool $is_main_query_result = true;

		/**
		 * Mirrors WP_Query::set(): writes into query_vars only — the same
		 * surface core's own `set()` exposes.
		 */
		public function set( string $key, mixed $value ): void {
			$this->query_vars[ $key ] = $value;
		}

		/**
		 * Mirrors WP_Query::init_query_flags(): resets every conditional this
		 * stub carries, including `is_feed` — core resets it here too. It's
		 * `set_404()` that saves and restores `is_feed` around the call, not
		 * this method that spares it.
		 */
		public function init_query_flags(): void {
			$this->is_search            = false;
			$this->is_archive           = false;
			$this->is_home              = false;
			$this->is_post_type_archive = false;
			$this->is_feed              = false;
			$this->is_404               = false;
		}

		/**
		 * Mirrors WP_Query::set_404(): saves `is_feed`, resets the
		 * conditionals via init_query_flags(), restores `is_feed`, sets
		 * is_404, and fires the `set_404` action — the three things the
		 * hand-written flag flip in disable_search() used to skip.
		 */
		public function set_404(): void {
			$is_feed = $this->is_feed;
			$this->init_query_flags();
			$this->is_feed = $is_feed;
			$this->is_404  = true;
			do_action_ref_array( 'set_404', array( $this ) );
		}

		public function is_main_query(): bool {
			return $this->is_main_query_result;
		}

		/**
		 * Test-only helper: WP core derives `is_main_query()` from a global
		 * comparison; this stub has no globals, so tests set it directly.
		 */
		public function set_is_main_query( bool $is_main_query ): void {
			$this->is_main_query_result = $is_main_query;
		}
	}
}

// Minimal WP_Post stub for tests that need an instance to satisfy `instanceof WP_Post`.
// Constructor accepts array or object, mirroring WordPress core's permissive signature.
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_content = '';
		public string $post_type = 'post';

		public function __construct( array|object $props = [] ) {
			foreach ( (array) $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// Minimal WP_Term stub for tests that need an instance to satisfy `instanceof WP_Term`.
// `#[\AllowDynamicProperties]` mirrors WordPress core, which annotates `WP_Term`
// the same way so plugins can stash arbitrary metadata on term objects.
if ( ! class_exists( 'WP_Term' ) ) {
	#[\AllowDynamicProperties]
	class WP_Term {
		public int $term_id = 0;
		public string $taxonomy = '';
		public string $name = '';

		public function __construct( array|object $props = [] ) {
			foreach ( (array) $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// Minimal Breeze_Cache_Preloader stub: the Breeze plugin class the tail tick
// dispatches URLs through. Not a dependency of this package, so tests supply
// a no-op stand-in the same way the WPML stub covers TranslationManagement.
if ( ! class_exists( 'Breeze_Cache_Preloader' ) ) {
	class Breeze_Cache_Preloader {
		public static function preload_url( string $url ): void {
		}
	}
}

// Minimal WP_User stub for tests that need an instance to satisfy `instanceof WP_User`.
// `#[\AllowDynamicProperties]` mirrors WordPress core, which annotates `WP_User`
// the same way (dynamic props are hydrated from the `$data` user row).
if ( ! class_exists( 'WP_User' ) ) {
	#[\AllowDynamicProperties]
	class WP_User {
		public int $ID = 0;

		public function __construct( array|object $props = [] ) {
			foreach ( (array) $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// Minimal WP_CLI stub recording every call instead of touching a real
// terminal. `error()` throws, mirroring real WP_CLI: it halts the command
// and (outside tests) exits non-zero, so code calling it must never expect
// control to return -- exactly the ordering bug MigrateImageCacheCommand's
// success/error fix depends on.
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @var list<string> */
		public static array $logs = [];

		/** @var list<string> */
		public static array $warnings = [];

		/** @var list<string> */
		public static array $successes = [];

		/** @var list<string> */
		public static array $errors = [];

		public static function reset(): void {
			self::$logs      = [];
			self::$warnings  = [];
			self::$successes = [];
			self::$errors    = [];
		}

		public static function log( $message ): void {
			self::$logs[] = (string) $message;
		}

		public static function warning( $message ): void {
			self::$warnings[] = (string) $message;
		}

		public static function success( $message ): void {
			self::$successes[] = (string) $message;
		}

		public static function error( $message ): void {
			self::$errors[] = (string) $message;
			throw new \RuntimeException( (string) $message );
		}
	}
}
