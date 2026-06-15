<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies filter_image_sizes() enforces the $enabled_image_sizes allowlist
 * against the intermediate_image_sizes_advanced filter payload.
 */
class FilterImageSizesTest extends StarterBaseTestCase {

	/** All four WP built-in sizes used as a representative fixture. */
	private function allSizes(): array {
		return [
			'thumbnail'  => [ 'width' => 150, 'height' => 150, 'crop' => true ],
			'medium'     => [ 'width' => 300, 'height' => 300, 'crop' => false ],
			'large'      => [ 'width' => 1024, 'height' => 1024, 'crop' => false ],
			'1536x1536'  => [ 'width' => 1536, 'height' => 1536, 'crop' => false ],
			'2048x2048'  => [ 'width' => 2048, 'height' => 2048, 'crop' => false ],
		];
	}

	public function test_returns_all_sizes_when_allowlist_is_null(): void {
		$base = $this->createStarterBase( [ 'enabled_image_sizes' => null ] );

		$result = $base->filter_image_sizes( $this->allSizes(), [], 1 );

		$this->assertSame( $this->allSizes(), $result );
	}

	public function test_keeps_only_allowlisted_slugs(): void {
		$base = $this->createStarterBase( [
			'enabled_image_sizes' => [ 'thumbnail', 'medium', 'large' ],
		] );

		$result = $base->filter_image_sizes( $this->allSizes(), [], 1 );

		$this->assertArrayHasKey( 'thumbnail', $result );
		$this->assertArrayHasKey( 'medium', $result );
		$this->assertArrayHasKey( 'large', $result );
		$this->assertArrayNotHasKey( '1536x1536', $result );
		$this->assertArrayNotHasKey( '2048x2048', $result );
	}

	public function test_drops_wp53_sizes_by_default_when_allowlist_excludes_them(): void {
		$base = $this->createStarterBase( [
			'enabled_image_sizes' => [ 'thumbnail', 'medium', 'medium_large', 'large' ],
		] );

		$result = $base->filter_image_sizes( $this->allSizes(), [], 1 );

		$this->assertArrayNotHasKey( '1536x1536', $result );
		$this->assertArrayNotHasKey( '2048x2048', $result );
		$this->assertCount( 3, $result ); // thumbnail + medium + large (medium_large not in fixture)
	}

	public function test_empty_allowlist_drops_all_sizes(): void {
		$base = $this->createStarterBase( [ 'enabled_image_sizes' => [] ] );

		$result = $base->filter_image_sizes( $this->allSizes(), [], 1 );

		$this->assertSame( [], $result );
	}

	public function test_allowlist_entry_not_in_registered_sizes_is_silently_ignored(): void {
		$base = $this->createStarterBase( [
			'enabled_image_sizes' => [ 'thumbnail', 'nonexistent-size' ],
		] );

		$result = $base->filter_image_sizes( $this->allSizes(), [], 1 );

		$this->assertArrayHasKey( 'thumbnail', $result );
		$this->assertArrayNotHasKey( 'nonexistent-size', $result );
		$this->assertCount( 1, $result );
	}

	public function test_size_settings_are_preserved_unmodified(): void {
		$base = $this->createStarterBase( [
			'enabled_image_sizes' => [ 'thumbnail' ],
		] );

		$result = $base->filter_image_sizes( $this->allSizes(), [], 1 );

		$this->assertSame(
			[ 'width' => 150, 'height' => 150, 'crop' => true ],
			$result['thumbnail']
		);
	}

	public function test_image_meta_and_attachment_id_are_not_used(): void {
		// The method ignores $image_meta and $attachment_id — passing arbitrary
		// values must not change the outcome.
		$base = $this->createStarterBase( [
			'enabled_image_sizes' => [ 'medium' ],
		] );

		$result1 = $base->filter_image_sizes( $this->allSizes(), [], 0 );
		$result2 = $base->filter_image_sizes( $this->allSizes(), [ 'width' => 5000, 'height' => 3000 ], 99 );

		$this->assertSame( $result1, $result2 );
	}
}
