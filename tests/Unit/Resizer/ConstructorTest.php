<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

class ConstructorTest extends ResizerTestCase {

	public function test_default_values(): void {
		$resizer = $this->createResizer();

		$this->assertSame( 'avif', $this->getPrivateProperty( $resizer, 'target_format' ) );
		$this->assertSame( 100, $this->getPrivateProperty( $resizer, 'target_quality' ) );
		$this->assertStringContainsString( '/cache/image', $this->getPrivateProperty( $resizer, 'image_cache_dir' ) );
		$this->assertFalse( $this->getPrivateProperty( $resizer, 'force_regenerate' ) );
	}

	public function test_custom_target_format(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default ) {
			if ( $filter === 'portadesign_resizer_target_format' ) {
				return 'webp';
			}
			return $default;
		} );

		$resizer = new Resizer();

		$this->assertSame( 'webp', $this->getPrivateProperty( $resizer, 'target_format' ) );
	}

	public function test_custom_quality(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default ) {
			if ( $filter === 'portadesign_resizer_target_quality' ) {
				return 85;
			}
			return $default;
		} );

		$resizer = new Resizer();

		$this->assertSame( 85, $this->getPrivateProperty( $resizer, 'target_quality' ) );
	}

	public function test_custom_cache_dir(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default ) {
			if ( $filter === 'portadesign_resizer_image_cache_dir' ) {
				return '/custom/cache/path';
			}
			return $default;
		} );

		$resizer = new Resizer();

		$this->assertSame( '/custom/cache/path', $this->getPrivateProperty( $resizer, 'image_cache_dir' ) );
	}

	public function test_force_regenerate(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default ) {
			if ( $filter === 'portadesign_resizer_force_regenerate' ) {
				return true;
			}
			return $default;
		} );

		$resizer = new Resizer();

		$this->assertTrue( $this->getPrivateProperty( $resizer, 'force_regenerate' ) );
	}

	public function test_quality_filters_affect_normalization(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default ) {
			if ( $filter === 'portadesign_resizer_target_quality' ) {
				return 75;
			}
			return $default;
		} );

		$resizer = new Resizer();

		// Variant without explicit quality should inherit the filtered value
		$result = $this->callPrivate( $resizer, 'normalizeVariants', [
			[ [ '800', '600', '768', 'crop' ] ],
		] );

		$this->assertSame( 75, $result[0]['quality'] );
	}
}
