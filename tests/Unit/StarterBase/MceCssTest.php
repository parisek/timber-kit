<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that mce_css() drops the block-editor stylesheet from the classic
 * (TinyMCE) editor and leaves every other stylesheet alone.
 *
 * The regression it guards: `add_editor_style()` feeds `gutenberg-editor.css` to
 * both editors, and in TinyMCE the file contributes only its Tailwind Preflight —
 * which resets `a` to `text-decoration: inherit` and `ol`/`ul` to
 * `list-style: none`. An ACF `wysiwyg` field then renders a link as plain body
 * text and a list with no bullets.
 */
class MceCssTest extends StarterBaseTestCase {

	public function test_drops_the_block_editor_stylesheet(): void {
		$base = $this->createStarterBase();

		$css = 'https://example.test/wp-includes/css/dashicons.css,'
			. 'https://example.test/wp-content/themes/x/static/dist/css/gutenberg-editor.css';

		$this->assertSame(
			'https://example.test/wp-includes/css/dashicons.css',
			$base->mce_css( $css )
		);
	}

	// The version query core appends (`?wp-mce-49110-20250317`) must not make the
	// entry unrecognisable — a strict equality compare would miss every real call.
	public function test_matches_the_stylesheet_carrying_a_cache_busting_query(): void {
		$base = $this->createStarterBase();

		$css = 'https://example.test/static/dist/css/gutenberg-editor.css?wp-mce-49110-20250317';

		$this->assertSame( '', $base->mce_css( $css ) );
	}

	public function test_leaves_other_stylesheets_untouched(): void {
		$base = $this->createStarterBase();

		$css = 'https://example.test/a.css,https://example.test/b.css';

		$this->assertSame( $css, $base->mce_css( $css ) );
	}

	// A theme that opted out of editor styles never registered the file, so the
	// filter has nothing to do and must not rewrite a list it did not create.
	public function test_returns_the_list_unchanged_when_editor_styles_are_disabled(): void {
		$base = $this->createStarterBase( [ 'gutenberg_editor_styles' => false ] );

		$css = 'https://example.test/static/dist/css/gutenberg-editor.css';

		$this->assertSame( $css, $base->mce_css( $css ) );
	}

	public function test_handles_an_empty_list(): void {
		$base = $this->createStarterBase();

		$this->assertSame( '', $base->mce_css( '' ) );
	}
}
