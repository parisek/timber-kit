<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * `attachments_sharing_file()` finds the other rows over one file — the WPML
 * translations `rescale-originals` must rewrite together.
 */
class AttachmentsSharingFileTest extends StarterBaseTestCase {

	private function stubWpdb( mixed $col ): object {
		$wpdb = new class( $col ) extends \wpdb {
			public string $postmeta = 'wp_postmeta';

			/** @var list<mixed> */
			public array $prepare_args = [];

			public function __construct( private readonly mixed $col ) {}

			public function prepare( string $query, mixed ...$args ): string {
				$this->prepare_args = $args;
				return $query;
			}

			public function get_col( string $query ): mixed {
				return $this->col;
			}
		};
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	public function test_returns_other_rows_as_integers(): void {
		Functions\when( 'get_post_meta' )->justReturn( '2026/08/photo-scaled.jpg' );
		$wpdb = $this->stubWpdb( [ '318', '402' ] );

		$ids = $this->createStarterBase()->attachments_sharing_file( 232 );

		$this->assertSame( [ 318, 402 ], $ids );
		$this->assertSame( [ 'wp_postmeta', '2026/08/photo-scaled.jpg', 232 ], $wpdb->prepare_args );
	}

	public function test_returns_nothing_without_an_attached_file(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->stubWpdb( [ '318' ] );

		$this->assertSame( [], $this->createStarterBase()->attachments_sharing_file( 232 ) );
	}
}
