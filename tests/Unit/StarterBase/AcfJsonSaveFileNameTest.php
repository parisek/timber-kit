<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

class AcfJsonSaveFileNameTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_returns_acf_json_for_block_location(): void {
		$post = [
			'location' => [
				[
					[ 'param' => 'block', 'value' => 'acf/hero' ],
				],
			],
		];

		$result = $this->base->acf_json_save_file_name( 'group_123.json', $post, '' );

		$this->assertSame( 'acf.json', $result );
	}

	public function test_returns_acf_json_for_post_type_location(): void {
		$post = [
			'location' => [
				[
					[ 'param' => 'post_type', 'value' => 'page' ],
				],
			],
		];

		$result = $this->base->acf_json_save_file_name( 'group_123.json', $post, '' );

		$this->assertSame( 'acf.json', $result );
	}

	public function test_returns_post_type_json_for_acf_post_type(): void {
		$post = [ 'post_type' => 'product' ];

		$result = $this->base->acf_json_save_file_name( 'group_123.json', $post, '' );

		$this->assertSame( 'product.json', $result );
	}

	public function test_maps_post_to_article_for_acf_post_type(): void {
		$post = [ 'post_type' => 'post' ];

		$result = $this->base->acf_json_save_file_name( 'group_123.json', $post, '' );

		$this->assertSame( 'article.json', $result );
	}

	public function test_returns_taxonomy_json_for_acf_taxonomy(): void {
		$post = [ 'taxonomy' => 'category' ];

		$result = $this->base->acf_json_save_file_name( 'group_123.json', $post, '' );

		$this->assertSame( 'category.json', $result );
	}

	public function test_returns_original_filename_as_fallback(): void {
		$post = [];

		$result = $this->base->acf_json_save_file_name( 'group_abc123.json', $post, '' );

		$this->assertSame( 'group_abc123.json', $result );
	}
}
