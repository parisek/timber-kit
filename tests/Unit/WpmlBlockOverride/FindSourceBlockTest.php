<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Tests\Unit\WpmlBlockOverrideTestCase;

/**
 * Covers the source↔translation matching path that the original `attrs.id`
 * strategy left untested. Real ACF blocks carry no `attrs.id`, so matching is
 * by block name + ordinal position.
 */
class FindSourceBlockTest extends WpmlBlockOverrideTestCase {

	private const SRC = 1;

	/** Minimal block array — only the keys findSourceBlock reads. */
	private static function block( string $name, array $data = [] ): array {
		return [ 'blockName' => $name, 'attrs' => [ 'data' => $data ] ];
	}

	public function test_single_block_matches_its_only_source_counterpart(): void {
		$source = [
			self::block( 'acf/hero', [ 'img' => 999 ] ),
		];
		$translation = self::block( 'acf/hero', [ 'img' => 111 ] );

		$matched = self::callPrivate( 'findSourceBlock', [ $translation, $source, self::SRC ] );

		$this->assertSame( 999, $matched['attrs']['data']['img'] );
	}

	public function test_duplicate_named_blocks_match_by_position(): void {
		// Two acf/hero-text blocks; the 2nd translation must pair with the 2nd source,
		// not the 1st. This is the case the attrs.id strategy got wrong in practice.
		$source = [
			self::block( 'acf/hero-text', [ 'k' => 'source-A' ] ),
			self::block( 'acf/other',     [ 'k' => 'noise' ] ),
			self::block( 'acf/hero-text', [ 'k' => 'source-B' ] ),
		];

		$first  = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero-text' ), $source, self::SRC ] );
		$second = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero-text' ), $source, self::SRC ] );

		$this->assertSame( 'source-A', $first['attrs']['data']['k'], 'first occurrence → first source' );
		$this->assertSame( 'source-B', $second['attrs']['data']['k'], 'second occurrence → second source' );
	}

	public function test_independent_counters_per_block_name(): void {
		$source = [
			self::block( 'acf/hero',    [ 'k' => 'hero-0' ] ),
			self::block( 'acf/gallery', [ 'k' => 'gallery-0' ] ),
			self::block( 'acf/hero',    [ 'k' => 'hero-1' ] ),
			self::block( 'acf/gallery', [ 'k' => 'gallery-1' ] ),
		];

		// Interleave the two names; each name has its own ordinal sequence.
		$h0 = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero' ),    $source, self::SRC ] );
		$g0 = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/gallery' ), $source, self::SRC ] );
		$h1 = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero' ),    $source, self::SRC ] );
		$g1 = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/gallery' ), $source, self::SRC ] );

		$this->assertSame( 'hero-0', $h0['attrs']['data']['k'] );
		$this->assertSame( 'gallery-0', $g0['attrs']['data']['k'] );
		$this->assertSame( 'hero-1', $h1['attrs']['data']['k'] );
		$this->assertSame( 'gallery-1', $g1['attrs']['data']['k'] );
	}

	public function test_structural_drift_returns_null_safe_degrade(): void {
		// Source has one acf/hero; translation renders two. The second has no
		// counterpart → null (no-op) rather than a wrong match.
		$source = [ self::block( 'acf/hero', [ 'k' => 'only' ] ) ];

		$first  = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero' ), $source, self::SRC ] );
		$second = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero' ), $source, self::SRC ] );

		$this->assertSame( 'only', $first['attrs']['data']['k'], 'first matches' );
		$this->assertNull( $second, 'overflow occurrence returns null (safe degrade)' );
	}

	public function test_no_matching_name_in_source_returns_null(): void {
		$source = [ self::block( 'acf/other', [ 'k' => 'x' ] ) ];

		$matched = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero' ), $source, self::SRC ] );

		$this->assertNull( $matched );
	}

	public function test_empty_block_name_returns_null(): void {
		$matched = self::callPrivate( 'findSourceBlock', [ [ 'blockName' => '', 'attrs' => [] ], [], self::SRC ] );

		$this->assertNull( $matched );
	}

	public function test_ordinals_are_scoped_per_source_post(): void {
		// Two different source posts rendered in the same pass keep separate counters.
		$sourceA = [ self::block( 'acf/hero', [ 'k' => 'A0' ] ), self::block( 'acf/hero', [ 'k' => 'A1' ] ) ];
		$sourceB = [ self::block( 'acf/hero', [ 'k' => 'B0' ] ) ];

		$a0 = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero' ), $sourceA, 1 ] );
		$b0 = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero' ), $sourceB, 2 ] );

		$this->assertSame( 'A0', $a0['attrs']['data']['k'], 'post 1 starts at ordinal 0' );
		$this->assertSame( 'B0', $b0['attrs']['data']['k'], 'post 2 has its own ordinal 0' );
	}

	public function test_reset_block_ordinals_restarts_positions(): void {
		$source = [ self::block( 'acf/hero', [ 'k' => 'first' ] ), self::block( 'acf/hero', [ 'k' => 'second' ] ) ];

		self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero' ), $source, self::SRC ] ); // consumes ordinal 0
		\Parisek\TimberKit\WpmlBlockOverride::resetBlockOrdinals( '' );

		// After reset, the counter restarts → next call gets ordinal 0 again.
		$afterReset = self::callPrivate( 'findSourceBlock', [ self::block( 'acf/hero' ), $source, self::SRC ] );

		$this->assertSame( 'first', $afterReset['attrs']['data']['k'], 'reset restarts ordinal at 0' );
	}

	public function test_reset_block_ordinals_returns_content_unchanged(): void {
		$content = '<p>unchanged</p>';
		$this->assertSame( $content, \Parisek\TimberKit\WpmlBlockOverride::resetBlockOrdinals( $content ) );
	}
}
