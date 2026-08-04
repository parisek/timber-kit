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

		public function set_404(): void {
			$this->is_404 = true;
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

// Minimal WP_Hook stub so production code can guard with `instanceof \WP_Hook`
// and use the real add_filter/remove_filter API (cf. the wpdb stub above).
// Only the members Acfml\LoadReferenceGuard touches are modelled: the public
// `$callbacks` map and callback registration keyed the way core keys it —
// `spl_object_hash` for closures, the string itself for named functions.
if ( ! class_exists( 'WP_Hook' ) ) {
	class WP_Hook {
		/** @var array<int, array<string, array{function: callable, accepted_args: int}>> */
		public array $callbacks = [];

		public function add_filter( string $tag, callable $function, int $priority = 10, int $accepted_args = 1 ): void {
			$this->callbacks[ $priority ][ self::unique_id( $function ) ] = [
				'function'      => $function,
				'accepted_args' => $accepted_args,
			];
		}

		public function remove_filter( string $tag, callable $function, int $priority ): bool {
			$id = self::unique_id( $function );

			if ( ! isset( $this->callbacks[ $priority ][ $id ] ) ) {
				return false;
			}

			unset( $this->callbacks[ $priority ][ $id ] );

			return true;
		}

		public function count(): int {
			return array_sum( array_map( 'count', $this->callbacks ) );
		}

		private static function unique_id( callable $function ): string {
			return is_object( $function ) ? spl_object_hash( $function ) : (string) $function;
		}
	}
}
