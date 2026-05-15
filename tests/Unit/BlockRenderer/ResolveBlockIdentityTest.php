<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Parisek\TimberKit\BlockRenderer;
use PHPUnit\Framework\TestCase;

class ResolveBlockIdentityTest extends TestCase {

	private static function callPrivate( string $method, array $args ): mixed {
		$reflection = new \ReflectionClass( BlockRenderer::class );
		$m          = $reflection->getMethod( $method );
		return $m->invokeArgs( null, $args );
	}

	public function test_returns_block_name_slug_and_filter_base_from_attributes(): void {
		[ $block_name, $slug, $filter_base ] = self::callPrivate(
			'resolveBlockIdentity',
			[ [ 'name' => 'acf/article-featured' ] ]
		);

		$this->assertSame( 'acf/article-featured', $block_name );
		$this->assertSame( 'article-featured', $slug );
		$this->assertSame( 'block_article_featured', $filter_base );
	}

	public function test_falls_back_to_unknown_when_name_missing(): void {
		[ $block_name, $slug, $filter_base ] = self::callPrivate(
			'resolveBlockIdentity',
			[ [] ]
		);

		$this->assertSame( 'unknown', $block_name );
		$this->assertSame( 'unknown', $slug );
		$this->assertSame( 'block_unknown', $filter_base );
	}

	public function test_strips_acf_prefix_and_converts_dashes_to_underscores(): void {
		[ $block_name, $slug, $filter_base ] = self::callPrivate(
			'resolveBlockIdentity',
			[ [ 'name' => 'acf/my-complex-block-name' ] ]
		);

		$this->assertSame( 'acf/my-complex-block-name', $block_name );
		$this->assertSame( 'my-complex-block-name', $slug );
		$this->assertSame( 'block_my_complex_block_name', $filter_base );
	}

	public function test_falls_back_to_unknown_when_name_is_not_string(): void {
		[ $block_name, $slug, $filter_base ] = self::callPrivate(
			'resolveBlockIdentity',
			[ [ 'name' => 123 ] ]
		);

		$this->assertSame( 'unknown', $block_name );
		$this->assertSame( 'unknown', $slug );
		$this->assertSame( 'block_unknown', $filter_base );
	}
}
