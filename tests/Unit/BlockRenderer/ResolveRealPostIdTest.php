<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Parisek\TimberKit\BlockRenderer;
use PHPUnit\Framework\TestCase;

class ResolveRealPostIdTest extends TestCase {

	private static function callPrivate( string $method, array $args ): mixed {
		$reflection = new \ReflectionClass( BlockRenderer::class );
		$m          = $reflection->getMethod( $method );
		return $m->invokeArgs( null, $args );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['post'] );
		parent::tearDown();
	}

	public function test_callback_post_id_takes_priority_when_numeric(): void {
		$result = self::callPrivate( 'resolveRealPostId', [ 99, 5 ] );
		$this->assertSame( 99, $result );
	}

	public function test_falls_back_to_acf_resolved_id_when_callback_is_zero(): void {
		$result = self::callPrivate( 'resolveRealPostId', [ 0, 42 ] );
		$this->assertSame( 42, $result );
	}

	public function test_falls_back_to_acf_resolved_id_when_callback_is_non_numeric(): void {
		$result = self::callPrivate( 'resolveRealPostId', [ 'block_xyz', 17 ] );
		$this->assertSame( 17, $result );
	}

	public function test_falls_back_to_global_post_when_acf_returns_block_opaque_id(): void {
		$GLOBALS['post'] = (object) [ 'ID' => 55 ];

		$result = self::callPrivate( 'resolveRealPostId', [ 0, 'block_abc123' ] );

		$this->assertSame( 55, $result );
	}

	public function test_keeps_block_opaque_id_when_global_post_unavailable(): void {
		unset( $GLOBALS['post'] );

		$result = self::callPrivate( 'resolveRealPostId', [ 0, 'block_abc123' ] );

		// No global $post available — stays as the opaque string.
		$this->assertSame( 'block_abc123', $result );
	}
}
