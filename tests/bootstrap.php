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
