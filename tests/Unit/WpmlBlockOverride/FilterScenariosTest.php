<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Parisek\TimberKit\WpmlBlockOverride;
use Tests\Unit\WpmlBlockOverrideTestCase;

/**
 * End-to-end coverage of WpmlBlockOverride::filter() composing all its parts on
 * a realistic multi-block document. The per-method unit tests (FindSourceBlock,
 * ApplyCopyFields, …) prove each piece in isolation; this proves they wire up
 * correctly through the public entry point: ordinals advancing across a whole
 * document, the structural-integrity gate firing per block name, innerBlocks
 * flattening feeding the pairing, and nested-key reconstruction end to end.
 *
 * WordPress is mocked via Brain Monkey: post_content is stored as JSON and
 * parse_blocks() is aliased to json_decode(), so no Gutenberg/WPML runtime is
 * needed. wpml_object_id distinguishes the source-pairing call (return_original
 * = false → maps the translation id to the source post) from the per-value remap
 * call (return_original = true → passthrough, so source values assert exactly).
 *
 * ── Reproducing this against a LIVE WPML + ACF Pro site (wp eval-file) ──────
 * The same scenarios run end to end on a real site; the non-obvious bits:
 *   1. wp eval-file runs through eval() → NO `declare(strict_types)`, NO
 *      `namespace`, NO `use`; reference the class by its fully-qualified name.
 *   2. Make a translation pair: do_action('wpml_set_element_language_details',
 *      [...'trid'=>false, language=<default>]) on the source, read the trid via
 *      apply_filters('wpml_element_trid', null, $src, 'post_post'), then the same
 *      action with that trid + the secondary language on the translation. Detect
 *      languages with the wpml_default_language / wpml_active_languages filters;
 *      switch with do_action('wpml_switch_language', <secondary>).
 *   3. Drive the feature by calling WpmlBlockOverride::filter($b, $b) directly —
 *      NOT apply_filters('render_block_data', …), which also fires core's 3-arg
 *      wp_add_parent_layout_to_parsed_block — and recurse into innerBlocks to
 *      mirror how core renders nested blocks.
 *   4. Declare Copy fields without ACFML: register a synthetic block
 *      (acf_register_block_type works mid-request) and return the copy-field defs
 *      through the timber_kit/wpml_block_override/copy_fields filter. Call
 *      WpmlBlockOverride::invalidateCopyFieldsCache() before (so the synthetic
 *      block is picked up) and after (so the live site's transient isn't left
 *      polluted), since the index is cached in a transient when WP_DEBUG is off.
 *   5. Reset the per-request statics between documents (sourceBlocksMemo,
 *      blockOrdinals, copyFieldsIndex) — same three this test resets in setUp().
 */
class FilterScenariosTest extends WpmlBlockOverrideTestCase {

	private const TRANS = 10;   // translation post id (the one being rendered)
	private const SRC   = 20;   // source-language post id

	/** @var array<int, string> post_content (JSON) keyed by post id */
	private array $content = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'parse_blocks' )->alias(
			static fn( string $c ): array => $c === '' ? [] : (array) json_decode( $c, true )
		);

		// Read $this->content live (not captured by value) — render() populates it
		// per scenario after setUp() has run.
		$self = $this;
		Functions\when( 'get_post' )->alias(
			static fn( $id ) => isset( $self->content[ $id ] ) ? (object) [ 'ID' => $id, 'post_content' => $self->content[ $id ] ] : null
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value = null, mixed ...$args ) {
				if ( $tag === 'wpml_current_language' ) return 'en';
				if ( $tag === 'wpml_default_language' ) return 'cs';
				if ( $tag === 'wpml_object_id' ) {
					// args: [ element_type, return_original, lang ]
					$return_original = $args[1] ?? null;
					if ( $return_original === false ) {
						// Source-language pairing: map the translation post to its source.
						return $value === self::TRANS ? self::SRC : $value;
					}
					return $value; // per-value remap → passthrough so asserts are exact
				}
				return $value; // package filters (should_override, copy_fields) → default
			}
		);
	}

	// ── helpers ──────────────────────────────────────────────────────────

	/** @param array<string, mixed> $data */
	private static function block( string $acf_name, array $data = [], array $inner = [] ): array {
		return [ 'blockName' => $acf_name, 'attrs' => [ 'data' => $data ], 'innerBlocks' => $inner ];
	}

	private static function wrap( array $inner ): array {
		return [ 'blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => $inner ];
	}

	/** Seed the copy-fields index so getCopyFields() resolves without ACF. */
	private static function seedCopyFields( array $index ): void {
		$r = new \ReflectionProperty( WpmlBlockOverride::class, 'copyFieldsIndex' );
		$r->setAccessible( true );
		$r->setValue( null, $index );
	}

	/**
	 * Run a translation document through filter() exactly as core would: set it
	 * as the global post, then apply filter() to every block in document order,
	 * recursing into innerBlocks.
	 *
	 * @param array<int, array<string, mixed>> $source_blocks
	 * @param array<int, array<string, mixed>> $trans_blocks
	 * @return array<int, array<string, mixed>>
	 */
	private function render( array $source_blocks, array $trans_blocks ): array {
		$this->content[ self::SRC ]   = (string) json_encode( $source_blocks );
		$this->content[ self::TRANS ] = (string) json_encode( $trans_blocks );
		$GLOBALS['post'] = (object) [ 'ID' => self::TRANS, 'post_type' => 'post' ];

		return array_map( [ $this, 'applyTree' ], $trans_blocks );
	}

	/** @param array<string, mixed> $b @return array<string, mixed> */
	public function applyTree( array $b ): array {
		if ( ! empty( $b['blockName'] ) ) {
			$b = WpmlBlockOverride::filter( $b, $b );
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			$b['innerBlocks'] = array_map( [ $this, 'applyTree' ], $b['innerBlocks'] );
		}
		return $b;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function all( array $blocks, string $acf_name ): array {
		$res = [];
		foreach ( $blocks as $b ) {
			if ( ( $b['blockName'] ?? '' ) === $acf_name ) $res[] = $b;
			if ( ! empty( $b['innerBlocks'] ) ) $res = array_merge( $res, self::all( $b['innerBlocks'], $acf_name ) );
		}
		return $res;
	}

	private static function topLevelCopy( string $field = 'img', string $type = 'image' ): void {
		self::seedCopyFields( [ 'hero' => [ [ 'field' => [ 'name' => $field, 'type' => $type ], 'path' => [] ] ] ] );
	}

	// ── scenarios ──────────────────────────────────────────────────────────

	public function test_multiple_identical_blocks_pair_by_position(): void {
		self::topLevelCopy();
		$src   = [ self::block( 'acf/hero', [ 'img' => 901 ] ), self::block( 'acf/hero', [ 'img' => 902 ] ), self::block( 'acf/hero', [ 'img' => 903 ] ) ];
		$trans = [ self::block( 'acf/hero', [ 'img' => 111 ] ), self::block( 'acf/hero', [ 'img' => 112 ] ), self::block( 'acf/hero', [ 'img' => 113 ] ) ];

		$out = self::all( $this->render( $src, $trans ), 'acf/hero' );

		$this->assertSame( 901, $out[0]['attrs']['data']['img'] );
		$this->assertSame( 902, $out[1]['attrs']['data']['img'] );
		$this->assertSame( 903, $out[2]['attrs']['data']['img'] );
	}

	public function test_interleaved_block_names_keep_independent_ordinals(): void {
		self::seedCopyFields( [
			'hero'    => [ [ 'field' => [ 'name' => 'img', 'type' => 'image' ], 'path' => [] ] ],
			'gallery' => [ [ 'field' => [ 'name' => 'img', 'type' => 'image' ], 'path' => [] ] ],
		] );
		$mk    = static fn( int $h, int $g ): array => [ self::block( 'acf/hero', [ 'img' => $h ] ), self::block( 'acf/gallery', [ 'img' => $g ] ) ];
		$src   = array_merge( $mk( 910, 911 ), $mk( 920, 921 ) );
		$trans = array_merge( $mk( 110, 111 ), $mk( 120, 121 ) );

		$out  = $this->render( $src, $trans );
		$hero = self::all( $out, 'acf/hero' );
		$gal  = self::all( $out, 'acf/gallery' );

		$this->assertSame( 910, $hero[0]['attrs']['data']['img'] );
		$this->assertSame( 920, $hero[1]['attrs']['data']['img'] );
		$this->assertSame( 911, $gal[0]['attrs']['data']['img'] );
		$this->assertSame( 921, $gal[1]['attrs']['data']['img'] );
	}

	public function test_nested_repeater_keys_overridden_through_filter(): void {
		self::seedCopyFields( [ 'hero' => [ [ 'field' => [ 'name' => 'rimg', 'type' => 'image' ], 'path' => [ [ 'name' => 'rows', 'type' => 'repeater' ] ] ] ] ] );
		$src   = [ self::block( 'acf/hero', [ 'rows' => 2, 'rows_0_rimg' => 901, 'rows_1_rimg' => 902 ] ) ];
		$trans = [ self::block( 'acf/hero', [ 'rows' => 2, 'rows_0_rimg' => 111, 'rows_1_rimg' => 112 ] ) ];

		$b = self::all( $this->render( $src, $trans ), 'acf/hero' )[0];

		$this->assertSame( 901, $b['attrs']['data']['rows_0_rimg'] );
		$this->assertSame( 902, $b['attrs']['data']['rows_1_rimg'] );
	}

	public function test_deep_repeater_in_repeater_through_filter(): void {
		self::seedCopyFields( [ 'hero' => [ [ 'field' => [ 'name' => 'dval', 'type' => 'text' ], 'path' => [ [ 'name' => 'outer', 'type' => 'repeater' ], [ 'name' => 'inner', 'type' => 'repeater' ] ] ] ] ] );
		$src   = [ self::block( 'acf/hero', [ 'outer' => 2, 'outer_0_inner' => 1, 'outer_0_inner_0_dval' => 'S00', 'outer_1_inner' => 1, 'outer_1_inner_0_dval' => 'S10' ] ) ];
		$trans = [ self::block( 'acf/hero', [ 'outer' => 2, 'outer_0_inner' => 1, 'outer_0_inner_0_dval' => 'X00', 'outer_1_inner' => 1, 'outer_1_inner_0_dval' => 'X10' ] ) ];

		$b = self::all( $this->render( $src, $trans ), 'acf/hero' )[0];

		$this->assertSame( 'S00', $b['attrs']['data']['outer_0_inner_0_dval'] );
		$this->assertSame( 'S10', $b['attrs']['data']['outer_1_inner_0_dval'] );
	}

	public function test_acf_block_inside_core_wrapper_is_flattened_and_overridden(): void {
		self::topLevelCopy();
		$src   = [ self::wrap( [ self::block( 'acf/hero', [ 'img' => 905 ] ) ] ) ];
		$trans = [ self::wrap( [ self::block( 'acf/hero', [ 'img' => 111 ] ) ] ) ];

		$b = self::all( $this->render( $src, $trans ), 'acf/hero' )[0];

		$this->assertSame( 905, $b['attrs']['data']['img'] );
	}

	public function test_structural_gate_is_per_block_name(): void {
		// Source: 1 hero + 1 gallery. Translation: 2 heroes (drift) + 1 gallery.
		// hero counts differ (1 vs 2) → heroes skipped; gallery matches → overridden.
		self::seedCopyFields( [
			'hero'    => [ [ 'field' => [ 'name' => 'img', 'type' => 'image' ], 'path' => [] ] ],
			'gallery' => [ [ 'field' => [ 'name' => 'img', 'type' => 'image' ], 'path' => [] ] ],
		] );
		$src   = [ self::block( 'acf/hero', [ 'img' => 901 ] ), self::block( 'acf/gallery', [ 'img' => 940 ] ) ];
		$trans = [ self::block( 'acf/hero', [ 'img' => 111 ] ), self::block( 'acf/hero', [ 'img' => 112 ] ), self::block( 'acf/gallery', [ 'img' => 222 ] ) ];

		$out  = $this->render( $src, $trans );
		$hero = self::all( $out, 'acf/hero' );
		$gal  = self::all( $out, 'acf/gallery' );

		$this->assertSame( 111, $hero[0]['attrs']['data']['img'], 'drifted block name must stay stale' );
		$this->assertSame( 112, $hero[1]['attrs']['data']['img'], 'drifted block name must stay stale' );
		$this->assertSame( 940, $gal[0]['attrs']['data']['img'], 'matched block name still overridden' );
	}
}
