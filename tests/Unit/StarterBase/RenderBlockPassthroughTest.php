<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies the $render_block_passthrough_blocks escape hatch: listed block
 * names (exact, `prefix/*` wildcard, or `*`) bypass the core-block wrapper
 * in render_block() and are returned unchanged.
 *
 * Passthrough must short-circuit before any WP/Timber calls — these tests
 * deliberately mock no WordPress functions beyond the test-case defaults,
 * so reaching get_post_type()/Timber would fatal and fail the test.
 */
class RenderBlockPassthroughTest extends StarterBaseTestCase {

	private function block( ?string $name ): array {
		return [ 'blockName' => $name, 'parent' => null ];
	}

	public function test_exact_match_returns_content_unchanged(): void {
		$base = $this->createStarterBase( [ 'render_block_passthrough_blocks' => [ 'core/quote' ] ] );

		$this->assertSame( '<blockquote>q</blockquote>', $base->render_block( '<blockquote>q</blockquote>', $this->block( 'core/quote' ) ) );
	}

	public function test_prefix_wildcard_matches_namespaced_blocks(): void {
		$base = $this->createStarterBase( [ 'render_block_passthrough_blocks' => [ 'wpforms/*' ] ] );

		$this->assertSame( '<form>f</form>', $base->render_block( '<form>f</form>', $this->block( 'wpforms/form-selector' ) ) );
	}

	public function test_star_matches_every_block(): void {
		$base = $this->createStarterBase( [ 'render_block_passthrough_blocks' => [ '*' ] ] );

		$this->assertSame( '<p>p</p>', $base->render_block( '<p>p</p>', $this->block( 'core/paragraph' ) ) );
	}

	public function test_passthrough_wins_over_forced_contact_form_wrapping(): void {
		$base = $this->createStarterBase( [ 'render_block_passthrough_blocks' => [ 'contact-form-7/contact-form-selector' ] ] );

		$this->assertSame( '<div>cf7</div>', $base->render_block( '<div>cf7</div>', $this->block( 'contact-form-7/contact-form-selector' ) ) );
	}

	public function test_prefix_wildcard_does_not_match_other_namespaces(): void {
		$base = $this->createStarterBase( [ 'render_block_passthrough_blocks' => [ 'wpforms/*' ] ] );

		// acf/foo is a non-core block → falls through to the existing
		// non-core early-out and comes back unchanged for that reason.
		$this->assertSame( 'x', $base->render_block( 'x', $this->block( 'acf/foo' ) ) );
	}

	public function test_default_empty_list_keeps_existing_early_outs(): void {
		$base = $this->createStarterBase();

		// Nested block → unchanged (parent check).
		$this->assertSame( 'n', $base->render_block( 'n', [ 'blockName' => 'core/paragraph', 'parent' => [ 'name' => 'core/group' ] ] ) );
		// Non-core custom block → unchanged (namespace check).
		$this->assertSame( 'c', $base->render_block( 'c', $this->block( 'acf/hero' ) ) );
		// Layout block → unchanged (skip list).
		$this->assertSame( 'g', $base->render_block( 'g', $this->block( 'core/group' ) ) );
	}
}
