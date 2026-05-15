<?php

declare(strict_types=1);

namespace Tests\Unit\BlockRenderer;

use Parisek\TimberKit\BlockRenderer;
use PHPUnit\Framework\TestCase;

class IsInserterPreviewTest extends TestCase {

	public function test_returns_true_when_preview_and_empty_fields_and_has_data(): void {
		$this->assertTrue(
			BlockRenderer::isInserterPreview(
				true,
				[],
				[ 'data' => [ 'title' => 'Example' ] ]
			)
		);
	}

	public function test_returns_false_when_not_preview(): void {
		$this->assertFalse(
			BlockRenderer::isInserterPreview(
				false,
				[],
				[ 'data' => [ 'title' => 'Example' ] ]
			)
		);
	}

	public function test_returns_false_when_fields_non_empty(): void {
		$this->assertFalse(
			BlockRenderer::isInserterPreview(
				true,
				[ 'title' => 'Real saved value' ],
				[ 'data' => [ 'title' => 'Example' ] ]
			)
		);
	}

	public function test_returns_false_when_attributes_data_missing(): void {
		$this->assertFalse(
			BlockRenderer::isInserterPreview( true, [], [] )
		);
	}

	public function test_returns_false_when_attributes_data_not_array(): void {
		$this->assertFalse(
			BlockRenderer::isInserterPreview( true, [], [ 'data' => 'not-an-array' ] )
		);
	}
}
