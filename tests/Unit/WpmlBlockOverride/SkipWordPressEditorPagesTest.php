<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Parisek\TimberKit\WpmlBlockOverride;
use Tests\Unit\WpmlBlockOverrideTestCase;

/**
 * WpmlBlockOverride::skipWordPressEditorPages() — the should_override callback
 * behind $wpml_block_override_skip_wp_editor. A page whose translations are
 * edited in the WordPress editor owns its blocks in every language, so the
 * override must leave its Copy values alone.
 */
class SkipWordPressEditorPagesTest extends WpmlBlockOverrideTestCase {

	private const BLOCK = [ 'blockName' => 'acf/hero', 'attrs' => [ 'data' => [] ] ];

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/Stubs/WpmlTmPostEditTmEditorModeStub.php';
		\WPML_TM_Post_Edit_TM_Editor_Mode::$settings = [];
		\WPML_TM_Post_Edit_TM_Editor_Mode::$calls  = [];
		$GLOBALS['post'] = (object) [ 'ID' => 10, 'post_type' => 'page' ];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['post'] );
		parent::tearDown();
	}

	public function test_vetoes_a_page_that_uses_the_wordpress_editor(): void {
		\WPML_TM_Post_Edit_TM_Editor_Mode::$settings[10] = [ false, false ];

		$this->assertFalse( WpmlBlockOverride::skipWordPressEditorPages( true, self::BLOCK ) );
	}

	public function test_keeps_a_page_that_uses_a_wpml_editor(): void {
		\WPML_TM_Post_Edit_TM_Editor_Mode::$settings[10] = [ true, false ];

		$this->assertTrue( WpmlBlockOverride::skipWordPressEditorPages( true, self::BLOCK ) );
	}

	public function test_vetoes_a_page_whose_wpml_editor_is_blocked(): void {
		// WPML edits a blocked page in the WordPress editor too (uses_native_editor() in WPML 5).
		\WPML_TM_Post_Edit_TM_Editor_Mode::$settings[10] = [ true, true ];

		$this->assertFalse( WpmlBlockOverride::skipWordPressEditorPages( true, self::BLOCK ) );
	}

	public function test_an_earlier_veto_stands_and_the_resolver_is_not_called(): void {
		\WPML_TM_Post_Edit_TM_Editor_Mode::$settings[10] = [ true, false ];

		$this->assertFalse( WpmlBlockOverride::skipWordPressEditorPages( false, self::BLOCK ) );
		$this->assertSame( [], \WPML_TM_Post_Edit_TM_Editor_Mode::$calls );
	}

	public function test_without_a_global_post_the_decision_is_unchanged(): void {
		unset( $GLOBALS['post'] );

		$this->assertTrue( WpmlBlockOverride::skipWordPressEditorPages( true, self::BLOCK ) );
	}

	public function test_resolves_each_page_once_per_request(): void {
		\WPML_TM_Post_Edit_TM_Editor_Mode::$settings[10] = [ false, false ];

		WpmlBlockOverride::skipWordPressEditorPages( true, self::BLOCK );
		WpmlBlockOverride::skipWordPressEditorPages( true, self::BLOCK );
		WpmlBlockOverride::skipWordPressEditorPages( true, self::BLOCK );

		$this->assertSame( [ 10 => 1 ], \WPML_TM_Post_Edit_TM_Editor_Mode::$calls );
	}
}
