<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Parisek\TimberKit\WpmlBlockOverride;
use Tests\Unit\WpmlBlockOverrideTestCase;

class ShouldOverrideTest extends WpmlBlockOverrideTestCase {

	private function acfBlock(): array {
		return array( 'blockName' => 'acf/jumbotron', 'attrs' => array( 'id' => 'b1' ) );
	}

	public function test_true_for_acf_block_in_a_translation_language(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$this->assertTrue( WpmlBlockOverride::shouldOverride( $this->acfBlock(), 'sk', 'en' ) );
	}

	public function test_false_in_admin_context(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$this->assertFalse( WpmlBlockOverride::shouldOverride( $this->acfBlock(), 'sk', 'en' ) );
	}

	public function test_false_for_non_acf_block(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$block = array( 'blockName' => 'core/paragraph', 'attrs' => array( 'id' => 'b1' ) );
		$this->assertFalse( WpmlBlockOverride::shouldOverride( $block, 'sk', 'en' ) );
	}

	public function test_false_when_rendering_the_source_language(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		// current === default → we ARE the source; nothing to override.
		$this->assertFalse( WpmlBlockOverride::shouldOverride( $this->acfBlock(), 'en', 'en' ) );
	}

	public function test_false_when_language_context_is_missing(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$this->assertFalse( WpmlBlockOverride::shouldOverride( $this->acfBlock(), '', 'en' ) );
		$this->assertFalse( WpmlBlockOverride::shouldOverride( $this->acfBlock(), 'sk', '' ) );
	}
}
