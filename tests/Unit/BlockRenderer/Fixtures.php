<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

/**
 * Shared test fixtures + helpers for BlockRenderer tests.
 */
final class Fixtures {

	/**
	 * Standard ACF block attributes shape (matches what WP passes to render callbacks).
	 *
	 * @param array<string, mixed> $overrides Keys merged on top of the default shape.
	 * @return array<string, mixed>
	 */
	public static function attributes( array $overrides = [] ): array {
		return array_merge(
			[
				'name'      => 'acf/article-featured',
				'data'      => [ 'title' => 'Example title' ],
				'anchor'    => '',
				'className' => '',
			],
			$overrides
		);
	}

	/**
	 * Reset the BlockRenderer's in-request preview memo between tests so the
	 * static property doesn't leak state across cases.
	 */
	public static function resetPreviewMemo(): void {
		if ( ! class_exists( \Parisek\TimberKit\BlockRenderer::class ) ) {
			return;
		}

		$ref = new \ReflectionClass( \Parisek\TimberKit\BlockRenderer::class );
		if ( $ref->hasProperty( 'preview_memo' ) ) {
			$prop = $ref->getProperty( 'preview_memo' );
			$prop->setValue( null, [] );
		}
	}
}
