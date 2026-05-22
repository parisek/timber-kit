<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class ThemeSupportsTest extends StarterBaseTestCase {

	public function test_theme_supports_forwards_font_stylesheets_to_editor_styles(): void {
		$editorStyles = [];

		Functions\when( 'add_theme_support' )->justReturn( true );
		Functions\when( 'remove_theme_support' )->justReturn( true );
		Functions\when( 'load_theme_textdomain' )->justReturn( true );
		Functions\when( 'add_editor_style' )->alias( function ( $path ) use ( &$editorStyles ) {
			$editorStyles[] = $path;
		} );

		$base = $this->createStarterBase(
			[
				'theme_name' => 'test-theme',
				'font_stylesheets' => [
					'brand' => 'fonts/brand.css',
					'display' => 'fonts/display.css',
				],
			]
		);

		$base->theme_supports();

		$this->assertSame(
			[
				'static/dist/css/gutenberg-editor.css',
				'static/fonts/brand.css',
				'static/fonts/display.css',
			],
			$editorStyles
		);
	}
}

