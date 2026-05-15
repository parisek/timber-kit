<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

class RegisterTimberKitNamespaceTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$reflection  = new \ReflectionClass( \Parisek\TimberKit\StarterBase::class );
		$this->base  = $reflection->newInstanceWithoutConstructor();
	}

	public function test_initializes_when_namespace_not_pre_registered(): void {
		// An empty map (no theme has touched the key yet) should produce a
		// single-entry array containing the package's own templates path.
		$result = $this->base->register_timber_kit_namespace( [] );

		$this->assertArrayHasKey( 'timber-kit', $result );
		$this->assertCount( 1, $result['timber-kit'] );
		$this->assertStringEndsWith( '/src/templates', $result['timber-kit'][0] );
	}

	public function test_appends_package_path_preserving_theme_paths(): void {
		// Simulate a theme having already registered its own path under the same
		// key at priority 10 (before our priority-20 handler runs). The package
		// path must be APPENDED so Twig finds the theme's templates first.
		$input  = [ 'timber-kit' => [ '/theme/templates' ] ];
		$result = $this->base->register_timber_kit_namespace( $input );

		$this->assertArrayHasKey( 'timber-kit', $result );
		$this->assertCount( 2, $result['timber-kit'] );
		$this->assertSame( '/theme/templates', $result['timber-kit'][0], 'theme path must come first' );
		$this->assertStringEndsWith( '/src/templates', $result['timber-kit'][1], 'package path appended last' );
	}
}
