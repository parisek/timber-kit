<?php

declare(strict_types=1);

namespace Tests\Unit\Wpml;

use Parisek\TimberKit\Wpml\BlockOverrideAudit;
use PHPUnit\Framework\TestCase;

class BlockOverrideAuditTest extends TestCase {

	public function test_translation_editor_is_not_wordpress_editor(): void {
		$this->assertFalse( BlockOverrideAudit::usesWordPressEditor( [ true, false, '' ] ) );
	}

	public function test_no_translation_editor_is_wordpress_editor(): void {
		$this->assertTrue( BlockOverrideAudit::usesWordPressEditor( [ false, false, '' ] ) );
	}

	public function test_blocked_translation_editor_is_wordpress_editor(): void {
		$this->assertTrue( BlockOverrideAudit::usesWordPressEditor( [ true, true, 'reason' ] ) );
	}

	public function test_empty_settings_mean_translation_editor(): void {
		$this->assertFalse( BlockOverrideAudit::usesWordPressEditor( [] ) );
	}

	public function test_counts_only_acf_blocks_whose_data_changes(): void {
		$blocks = [
			[ 'blockName' => 'acf/hero', 'attrs' => [ 'data' => [ 'image' => 1 ] ], 'innerBlocks' => [] ],
			[ 'blockName' => 'acf/text', 'attrs' => [ 'data' => [ 'title' => 'x' ] ], 'innerBlocks' => [] ],
			[ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [] ],
		];
		$filter = static function ( array $block ): array {
			if ( 'acf/hero' === $block['blockName'] ) {
				$block['attrs']['data']['image'] = 2;
			}
			return $block;
		};

		$this->assertSame( 1, BlockOverrideAudit::countChangedBlocks( $blocks, $filter ) );
	}

	public function test_never_passes_non_acf_blocks_to_the_filter(): void {
		$seen   = [];
		$filter = static function ( array $block ) use ( &$seen ): array {
			$seen[] = $block['blockName'];
			return $block;
		};
		BlockOverrideAudit::countChangedBlocks(
			[ [ 'blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [] ], [ 'blockName' => null ] ],
			$filter
		);

		$this->assertSame( [], $seen );
	}

	public function test_walks_inner_blocks_in_render_order(): void {
		$blocks = [
			[
				'blockName'   => 'core/group',
				'attrs'       => [],
				'innerBlocks' => [
					[ 'blockName' => 'acf/a', 'attrs' => [ 'data' => [ 'v' => 1 ] ], 'innerBlocks' => [] ],
				],
			],
			[ 'blockName' => 'acf/b', 'attrs' => [ 'data' => [ 'v' => 1 ] ], 'innerBlocks' => [] ],
		];
		$seen   = [];
		$filter = static function ( array $block ) use ( &$seen ): array {
			$seen[]                  = $block['blockName'];
			$block['attrs']['data']['v'] = 2;
			return $block;
		};

		$this->assertSame( 2, BlockOverrideAudit::countChangedBlocks( $blocks, $filter ) );
		$this->assertSame( [ 'acf/a', 'acf/b' ], $seen );
	}

	public function test_summary_names_every_count(): void {
		$line = BlockOverrideAudit::summary( 97, 9, 21, 129 );

		$this->assertStringContainsString( '97 original(s)', $line );
		$this->assertStringContainsString( '9 translated in the WordPress editor', $line );
		$this->assertStringContainsString( '21 published page(s), 129 block(s)', $line );
	}
}
