<?php

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use PHPUnit\Framework\TestCase;

abstract class ResizerTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The backend format list is memoized on a static, which outlives the
		// test that filled it.
		Resizer::flushBackendFormats();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function createResizer(): Resizer {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) {
			unset( $filter, $args );
			return $default;
		} );
		return new Resizer();
	}

	protected function createResizerWithSourcePathFlag( bool $enabled ): Resizer {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) use ( $enabled ) {
			unset( $args );
			if ( $filter === 'timber_kit_resizer_source_path_in_cache_key' ) {
				return $enabled;
			}
			return $default;
		} );
		return new Resizer();
	}

	/**
	 * @return mixed
	 */
	protected function callPrivate( object $obj, string $method, array $args = [] ) {
		$ref = new \ReflectionMethod( $obj, $method );
		return $ref->invoke( $obj, ...$args );
	}

	/**
	 * @return mixed
	 */
	protected function getPrivateProperty( object $obj, string $property ) {
		$ref = new \ReflectionProperty( $obj, $property );
		return $ref->getValue( $obj );
	}
}
