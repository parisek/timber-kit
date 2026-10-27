<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Parisek\TimberKit\WpmlBlockOverride;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Unit\WpmlBlockOverrideTestCase;

/**
 * Without WPML's editor resolver (Translation Management not loaded) the
 * callback cannot tell which editor a page uses, so it keeps the override.
 * A separate process, because the sibling test defines the resolver class.
 */
class SkipWordPressEditorPagesWithoutWpmlTmTest extends WpmlBlockOverrideTestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_keeps_the_override_when_the_resolver_is_missing(): void {
		$this->assertFalse( class_exists( 'WPML_TM_Post_Edit_TM_Editor_Mode', false ) );
		$GLOBALS['post'] = (object) [ 'ID' => 10, 'post_type' => 'page' ];

		$this->assertTrue(
			WpmlBlockOverride::skipWordPressEditorPages( true, [ 'blockName' => 'acf/hero', 'attrs' => [] ] )
		);
	}
}
