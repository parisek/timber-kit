<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Tests\Unit\WpmlBlockOverrideTestCase;

class GetCopyFieldsIndexTest extends WpmlBlockOverrideTestCase {

	/**
	 * Synthetic block types returned by acf_get_block_types() in these tests.
	 */
	private function syntheticBlockTypes(): array {
		return [
			[ 'name' => 'acf/hero' ],
		];
	}

	/**
	 * Synthetic field groups returned by acf_get_field_groups() in these tests.
	 */
	private function syntheticFieldGroups(): array {
		return [
			[ 'key' => 'group_hero', 'title' => 'Hero Fields' ],
		];
	}

	/**
	 * Synthetic fields returned by acf_get_fields() in these tests.
	 * One Copy field (image), one Translate field (title).
	 */
	private function syntheticFields(): array {
		return [
			[ 'name' => 'bg_image', 'type' => 'image', 'wpml_cf_preferences' => 1 ],
			[ 'name' => 'heading', 'type' => 'text', 'wpml_cf_preferences' => 2 ],
		];
	}

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value, mixed ...$rest ) => $value
		);
	}

	/**
	 * These tests assert the persistent-transient path, which production skips
	 * under WP_DEBUG. Guard the assumption so a future WP_DEBUG=true profile can't
	 * silently run them against the bypass branch.
	 */
	private function skipIfWpDebug(): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->markTestSkipped( 'Covers the WP_DEBUG=false transient path; bypassed under WP_DEBUG=true.' );
		}
	}

	public function test_index_is_memoized_per_request(): void {
		// Arrange: cold cache — transient miss, ACF functions return synthetic data.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'acf_get_block_types' )->justReturn( $this->syntheticBlockTypes() );
		Functions\when( 'acf_get_field_groups' )->justReturn( $this->syntheticFieldGroups() );
		Functions\when( 'acf_get_fields' )->justReturn( $this->syntheticFields() );

		// Confirm memo starts null (setUp already reset it).
		$ref = new \ReflectionProperty( \Parisek\TimberKit\WpmlBlockOverride::class, 'copyFieldsIndex' );
		$this->assertNull( $ref->getValue(), 'memo starts null' );

		// First call — should hit ACF functions and populate memo.
		$first = self::callPrivate( 'getCopyFieldsIndex', [] );

		$this->assertIsArray( $ref->getValue(), 'memo populated after first call' );

		// Second call — should return the same memoized array without re-hitting ACF.
		$second = self::callPrivate( 'getCopyFieldsIndex', [] );

		$this->assertSame( $first, $second, 'second call returns memoized index unchanged' );

		// Verify index shape: 'hero' key with one copy field (bg_image).
		$this->assertArrayHasKey( 'hero', $first );
		$this->assertCount( 1, $first['hero'], 'only the Copy field (bg_image) indexed' );
		$this->assertSame( 'bg_image', $first['hero'][0]['field']['name'] );
	}

	public function test_warm_transient_is_returned_without_rebuilding(): void {
		$this->skipIfWpDebug();
		// Warm cache (WP_DEBUG off): a cached index short-circuits the ACF walk.
		$cached = [ 'hero' => [ [ 'field' => [ 'name' => 'bg_image', 'type' => 'image' ], 'path' => [] ] ] ];
		Functions\when( 'get_transient' )->justReturn( $cached );
		Functions\expect( 'acf_get_block_types' )->never();
		Functions\expect( 'set_transient' )->never();

		$result = self::callPrivate( 'getCopyFieldsIndex', [] );

		$this->assertSame( $cached, $result, 'cached transient is returned verbatim, ACF not re-walked' );
	}

	public function test_cold_cache_writes_the_built_index_to_the_transient(): void {
		$this->skipIfWpDebug();
		// Cold cache (WP_DEBUG off): miss → walk ACF → persist the built index.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'acf_get_block_types' )->justReturn( $this->syntheticBlockTypes() );
		Functions\when( 'acf_get_field_groups' )->justReturn( $this->syntheticFieldGroups() );
		Functions\when( 'acf_get_fields' )->justReturn( $this->syntheticFields() );

		$captured = null;
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			static function ( $key, $value, $ttl ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		$result = self::callPrivate( 'getCopyFieldsIndex', [] );

		$this->assertSame( $result, $captured, 'the built index is what gets persisted' );
		$this->assertArrayHasKey( 'hero', $captured );
	}

	/**
	 * Under WP_DEBUG=true the production code bypasses get_transient/set_transient
	 * and builds the index fresh from ACF each request (per-request memo still applies).
	 *
	 * The bootstrap defines WP_DEBUG=false (a PHP constant that cannot be redefined).
	 * Testing the WP_DEBUG=true branch within the standard PHPUnit harness therefore
	 * requires either a dedicated phpunit.xml configuration profile or a full
	 * integration test environment where WordPress itself defines WP_DEBUG=true.
	 *
	 * This test is skipped unless the runtime was started with WP_DEBUG=true.
	 * The conditional branch in getCopyFieldsIndex() is nonetheless readable in
	 * source and verifiable by inspection:
	 *   $skip_transient = \defined( 'WP_DEBUG' ) && WP_DEBUG;
	 *   if ( ! $skip_transient ) { get_transient(); ... set_transient(); }
	 */
	public function test_wp_debug_bypasses_persistent_transient(): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			$this->markTestSkipped(
				'WP_DEBUG bypass behavior requires WP_DEBUG=true at runtime. ' .
				'Run the integration test suite (ddev phpunit) or add a dedicated ' .
				'phpunit-debug.xml profile that defines WP_DEBUG=true in <php><const>.'
			);
		}

		// This branch is reached only when the harness was started with WP_DEBUG=true.
		// ACF stubs for the cold-build path.
		Functions\when( 'acf_get_block_types' )->justReturn( $this->syntheticBlockTypes() );
		Functions\when( 'acf_get_field_groups' )->justReturn( $this->syntheticFieldGroups() );
		Functions\when( 'acf_get_fields' )->justReturn( $this->syntheticFields() );

		// Expect: get_transient and set_transient are NEVER called under WP_DEBUG.
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		// Run the index builder.
		$result = self::callPrivate( 'getCopyFieldsIndex', [] );

		// Per-request memo SHOULD still be populated (avoids re-walk within one request).
		$ref = new \ReflectionProperty( \Parisek\TimberKit\WpmlBlockOverride::class, 'copyFieldsIndex' );
		$this->assertIsArray( $ref->getValue(), 'per-request memo still populates under WP_DEBUG' );
		$this->assertIsArray( $result );
	}
}
