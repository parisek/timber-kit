<?php

declare(strict_types=1);

namespace Tests\Unit\SvgDimensions;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\SvgDimensions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies SVG intrinsic dimensions are read from the markup with a `viewBox`
 * fallback, and that the backfill never overwrites a value another plugin
 * already stored.
 *
 * Every expectation in the markup provider is a shape observed in this fleet's
 * own uploads, not a synthetic case: a `viewBox`-only export (current Figma and
 * Illustrator default), unit-suffixed attributes, and the attribute pair older
 * exporters still emit.
 */
class SvgDimensionsTest extends TestCase {

	/** @var string[] */
	private array $temp_files = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private function tempSvg( string $markup ): string {
		$file = tempnam( sys_get_temp_dir(), 'tk-svg-' ) . '.svg';
		file_put_contents( $file, $markup );
		$this->temp_files[] = $file;
		return $file;
	}

	// --- fromMarkup -----------------------------------------------------------

	/**
	 * @return array<string, array{0: string, 1: array{width: int, height: int}|null}>
	 */
	public static function markupProvider(): array {
		return [
			'width/height attributes' => [
				'<svg xmlns="http://www.w3.org/2000/svg" width="60" height="26"></svg>',
				[ 'width' => 60, 'height' => 26 ],
			],
			'viewBox only -- the case svg-support misses' => [
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>',
				[ 'width' => 92, 'height' => 106 ],
			],
			'attributes win over viewBox when both are present' => [
				'<svg xmlns="http://www.w3.org/2000/svg" width="60" height="26" viewBox="0 0 120 52"></svg>',
				[ 'width' => 60, 'height' => 26 ],
			],
			'px units are stripped' => [
				'<svg xmlns="http://www.w3.org/2000/svg" width="60px" height="26px"></svg>',
				[ 'width' => 60, 'height' => 26 ],
			],
			'percentage attributes are not dimensions, fall through to viewBox' => [
				'<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 92 106"></svg>',
				[ 'width' => 92, 'height' => 106 ],
			],
			'fractional viewBox rounds to whole pixels' => [
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 91.5 105.4"></svg>',
				[ 'width' => 92, 'height' => 105 ],
			],
			'viewBox with comma separators' => [
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0,0,92,106"></svg>',
				[ 'width' => 92, 'height' => 106 ],
			],
			'negative min-x offset does not corrupt the size' => [
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="-10 -20 92 106"></svg>',
				[ 'width' => 92, 'height' => 106 ],
			],
			'leading XML declaration and doctype' => [
				'<?xml version="1.0"?><!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>',
				[ 'width' => 92, 'height' => 106 ],
			],
			'zero-sized viewBox is not a dimension' => [
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 0"></svg>',
				null,
			],
			'no dimensional information at all' => [
				'<svg xmlns="http://www.w3.org/2000/svg"></svg>',
				null,
			],
			'malformed XML' => [ '<svg width="60"', null ],
			'empty string' => [ '', null ],
			'not an SVG at all' => [ '<html><body>nope</body></html>', null ],
		];
	}

	#[DataProvider('markupProvider')]
	public function test_fromMarkup_reads_the_intrinsic_size( string $markup, ?array $expected ): void {
		$this->assertSame( $expected, SvgDimensions::fromMarkup( $markup ) );
	}

	// --- root identification --------------------------------------------------

	/**
	 * Every case below was a wrong answer from the first implementation, which
	 * searched for the first lexical `<svg` instead of walking the prolog. A
	 * wrong number here is worse than none: the sweep stores it, and the next
	 * run without --force reads it back as authoritative.
	 *
	 * @return array<string, array{0: string, 1: array{width: int, height: int}|null}>
	 */
	public static function rootProvider(): array {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>';

		return [
			'processing instruction quoting an svg' => [
				'<?probe fake="<svg viewBox=\'0 0 1 2\'>"?>' . $svg,
				[ 'width' => 92, 'height' => 106 ],
			],
			'entity declaration quoting an svg' => [
				'<!DOCTYPE svg [<!ENTITY fake "<svg viewBox=\'0 0 3 4\'>">]>' . $svg,
				[ 'width' => 92, 'height' => 106 ],
			],
			'comment token inside a processing instruction' => [
				'<?probe <!-- marker ?>' . $svg,
				[ 'width' => 92, 'height' => 106 ],
			],
			'comment token inside an entity declaration' => [
				'<!DOCTYPE svg [<!ENTITY fake "<!--">]>' . $svg,
				[ 'width' => 92, 'height' => 106 ],
			],
			'xml declaration then doctype then root' => [
				'<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "svg11.dtd">' . $svg,
				[ 'width' => 92, 'height' => 106 ],
			],
			'cdata inside the root cannot be reached' => [
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106">'
					. '<![CDATA[<svg viewBox="0 0 5 6">]]></svg>',
				[ 'width' => 92, 'height' => 106 ],
			],
			'prefixed root bound to the svg namespace' => [
				'<svg:svg xmlns:svg="http://www.w3.org/2000/svg" viewBox="0 0 92 106"/>',
				[ 'width' => 92, 'height' => 106 ],
			],
			'prefixed root in a foreign namespace is not an svg' => [
				'<x:svg xmlns:x="http://example.com/not-svg" viewBox="0 0 92 106"/>',
				null,
			],
			'a different root element' => [
				'<html><body><svg viewBox="0 0 92 106"></svg></body></html>',
				null,
			],
			'character data before the root' => [ 'oops' . $svg, null ],
			'unterminated comment' => [ '<!-- never closed ' . $svg, null ],
			'unterminated processing instruction' => [ '<?never closed ' . $svg, null ],
			'unterminated internal subset' => [ '<!DOCTYPE svg [<!ENTITY a "b">' . $svg, null ],
			'start tag that never closes' => [ '<svg viewBox="0 0 92 106"', null ],
		];
	}

	#[DataProvider('rootProvider')]
	public function test_fromMarkup_identifies_the_real_root( string $markup, ?array $expected ): void {
		$this->assertSame( $expected, SvgDimensions::fromMarkup( $markup ) );
	}

	public function test_fromMarkup_reads_utf16_with_a_bom(): void {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			$this->markTestSkipped( 'mbstring is required to decode UTF-16.' );
		}

		$utf8   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>';
		$utf16  = mb_convert_encoding( $utf8, 'UTF-16LE', 'UTF-8' );
		$markup = "\xFF\xFE" . $utf16;

		$this->assertSame( [ 'width' => 92, 'height' => 106 ], SvgDimensions::fromMarkup( $markup ) );
	}

	public function test_fromMarkup_reads_utf8_with_a_bom(): void {
		$markup = "\xEF\xBB\xBF" . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"/>';

		$this->assertSame( [ 'width' => 92, 'height' => 106 ], SvgDimensions::fromMarkup( $markup ) );
	}

	// --- lengths --------------------------------------------------------------

	/**
	 * @return array<string, array{0: string, 1: array{width: int, height: int}|null}>
	 */
	public static function lengthProvider(): array {
		return [
			// 72pt and 25.4mm are both exactly 96px, so the pair is square --
			// reading the viewBox instead would report 1:2.
			'physical units convert to pixels' => [
				'<svg width="72pt" height="25.4mm" viewBox="0 0 10 20"></svg>',
				[ 'width' => 96, 'height' => 96 ],
			],
			'inches and picas' => [
				'<svg width="2in" height="6pc"></svg>',
				[ 'width' => 192, 'height' => 96 ],
			],
			'centimetres' => [
				'<svg width="2.54cm" height="5.08cm"></svg>',
				[ 'width' => 96, 'height' => 192 ],
			],
			'quarter-millimetres' => [
				'<svg width="101.6q" height="203.2q"></svg>',
				[ 'width' => 96, 'height' => 192 ],
			],
			'scientific notation' => [
				'<svg width="1e3" height="2.5e2"></svg>',
				[ 'width' => 1000, 'height' => 250 ],
			],
			'leading plus sign' => [
				'<svg width="+92" height="+106"></svg>',
				[ 'width' => 92, 'height' => 106 ],
			],
			'uppercase unit' => [
				'<svg width="72PT" height="72PT"></svg>',
				[ 'width' => 96, 'height' => 96 ],
			],
			'negative length is not a size' => [ '<svg width="-92" height="-106"></svg>', null ],
			'em is context-dependent, not intrinsic' => [
				'<svg width="10em" height="20em" viewBox="0 0 92 106"></svg>',
				[ 'width' => 92, 'height' => 106 ],
			],
			'absurd value is refused rather than stored' => [
				'<svg width="1e30" height="1e30"></svg>',
				null,
			],
		];
	}

	#[DataProvider('lengthProvider')]
	public function test_fromMarkup_converts_absolute_lengths( string $markup, ?array $expected ): void {
		$this->assertSame( $expected, SvgDimensions::fromMarkup( $markup ) );
	}

	public function test_fromMarkup_combines_one_explicit_axis_with_the_viewbox_ratio(): void {
		// The author stated 60. Reporting the viewBox's 120x52 would claim a size
		// the file does not; 60x26 keeps both the stated axis and the ratio.
		$this->assertSame(
			[ 'width' => 60, 'height' => 26 ],
			SvgDimensions::fromMarkup( '<svg width="60" viewBox="0 0 120 52"></svg>' )
		);
	}

	public function test_fromMarkup_combines_an_explicit_height_with_the_viewbox_ratio(): void {
		$this->assertSame(
			[ 'width' => 60, 'height' => 26 ],
			SvgDimensions::fromMarkup( '<svg height="26" viewBox="0 0 120 52"></svg>' )
		);
	}

	public function test_fromMarkup_refuses_a_one_pixel_result(): void {
		// Indistinguishable from the bogus 1px core reports for SVG (#26256),
		// which Helpers discards on sight -- storing it writes a number the rest
		// of the stack refuses to use.
		$this->assertNull( SvgDimensions::fromMarkup( '<svg viewBox="0 0 1 1"></svg>' ) );
	}

	public function test_fromMarkup_ignores_a_nested_svg_element(): void {
		// The outer element defines the intrinsic size; an inner <svg> is content.
		$markup = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106">'
			. '<svg viewBox="0 0 10 10"></svg></svg>';

		$this->assertSame( [ 'width' => 92, 'height' => 106 ], SvgDimensions::fromMarkup( $markup ) );
	}

	public function test_fromMarkup_does_not_resolve_external_entities(): void {
		// An SVG is attacker-supplied content on any site with open uploads.
		$markup = '<?xml version="1.0"?>'
			. '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"><desc>&xxe;</desc></svg>';

		// The earlier version of this test accepted null OR the dimensions, which
		// made it unable to fail. The contract is exact: the root is read, the
		// entity is never expanded, and the DOCTYPE never reaches the parser.
		$this->assertSame( [ 'width' => 92, 'height' => 106 ], SvgDimensions::fromMarkup( $markup ) );
	}

	public function test_fromMarkup_does_not_read_a_dimension_out_of_an_entity(): void {
		// A file could declare `<!ENTITY w "92">` and use `width="&w;"`. Resolving
		// it is the XXE vector; refusing is the documented answer.
		$markup = '<!DOCTYPE svg [<!ENTITY w "92"><!ENTITY h "106">]>'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="&w;" height="&h;"></svg>';

		$this->assertNull( SvgDimensions::fromMarkup( $markup ) );
	}

	// --- fromFile -------------------------------------------------------------

	public function test_fromFile_reads_a_real_file(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		$this->assertSame( [ 'width' => 92, 'height' => 106 ], SvgDimensions::fromFile( $file ) );
	}

	public function test_fromFile_returns_null_for_a_missing_file(): void {
		$this->assertNull( SvgDimensions::fromFile( '/nonexistent/nope.svg' ) );
	}

	public function test_fromFile_reads_a_root_whose_child_has_an_oversized_attribute(): void {
		// Regression, measured on three real uploads (21.7 MB and 11.9 MB): they
		// resolved to null while their root carried width="573" height="445" all
		// along. The cause is not document size -- it is a single embedded
		// <image xlink:href="data:image/png;base64,..."> whose value exceeds
		// libxml's 10 MB AttValue limit, so the whole parse fails with
		// "AttValue length too long" and takes the root's own attributes with it.
		// Reading only the root element never reaches the offending child.
		$data = str_repeat( 'A', 10_500_000 );
		$file = $this->tempSvg(
			'<svg xmlns="http://www.w3.org/2000/svg" width="573" height="445" viewBox="0 0 573 445">'
			. '<image href="data:image/png;base64,' . $data . '"/></svg>'
		);

		$this->assertSame( [ 'width' => 573, 'height' => 445 ], SvgDimensions::fromFile( $file ) );
	}

	public function test_fromMarkup_handles_a_greater_than_sign_inside_an_attribute(): void {
		// Naive scanning for the first '>' would truncate the root element here.
		$markup = '<svg xmlns="http://www.w3.org/2000/svg" data-note="a > b" viewBox="0 0 92 106"></svg>';

		$this->assertSame( [ 'width' => 92, 'height' => 106 ], SvgDimensions::fromMarkup( $markup ) );
	}

	public function test_fromMarkup_tolerates_a_doctype_declared_entity_in_the_root(): void {
		// Adobe Illustrator's SVG export declares entities in the DOCTYPE and uses
		// them in the root's namespace attributes. Reading the root in isolation
		// leaves those references undefined, which failed the parse on four real
		// uploads. They are neutralised to text rather than resolved -- resolving
		// an entity from a DOCTYPE is the XXE vector this deliberately avoids, and
		// a namespace URI is never read here anyway.
		$markup = '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "svg11.dtd" ['
			. '<!ENTITY ns_extend "http://ns.adobe.com/Extensibility/1.0/">]>'
			. '<svg version="1.1" xmlns:x="&ns_extend;" xmlns="http://www.w3.org/2000/svg"'
			. ' viewBox="0 0 5435.8 1604"></svg>';

		$this->assertSame( [ 'width' => 5436, 'height' => 1604 ], SvgDimensions::fromMarkup( $markup ) );
	}

	public function test_fromMarkup_tolerates_a_bare_ampersand_in_an_attribute(): void {
		$markup = '<svg xmlns="http://www.w3.org/2000/svg" data-title="Tom & Jerry" viewBox="0 0 92 106"></svg>';

		$this->assertSame( [ 'width' => 92, 'height' => 106 ], SvgDimensions::fromMarkup( $markup ) );
	}

	public function test_fromMarkup_handles_a_self_closing_root(): void {
		$this->assertSame(
			[ 'width' => 92, 'height' => 106 ],
			SvgDimensions::fromMarkup( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"/>' )
		);
	}

	public function test_fromMarkup_ignores_a_comment_mentioning_svg(): void {
		$markup = '<!-- <svg viewBox="0 0 1 1"> generated by something -->'
			. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>';

		$this->assertSame( [ 'width' => 92, 'height' => 106 ], SvgDimensions::fromMarkup( $markup ) );
	}

	// --- resolveSvg (the read path) -------------------------------------------

	/**
	 * The read path runs for every image a template asks for, so "it only touches
	 * SVG" has to be provable rather than argued. `get_attached_file` is the gate
	 * to the filesystem: if it is never called, nothing was read.
	 *
	 * @return array<string, array{0: string|null, 1: int|null, 2: int|null}>
	 */
	public static function noFilesystemProvider(): array {
		return [
			'a raster image missing both axes' => [ 'image/jpeg', null, null ],
			'a raster image with both axes'    => [ 'image/png', 800, 600 ],
			'an SVG that already has both'     => [ 'image/svg+xml', 60, 26 ],
			'a PDF'                            => [ 'application/pdf', null, null ],
			'no mime type at all'              => [ null, null, null ],
		];
	}

	#[DataProvider('noFilesystemProvider')]
	public function test_resolveSvg_touches_no_file( ?string $mime, ?int $width, ?int $height ): void {
		Functions\expect( 'get_attached_file' )->never();

		$result = SvgDimensions::resolveSvg( 4242, $mime, $width, $height );

		// And it hands back exactly what it was given.
		$this->assertSame( [ 'width' => $width, 'height' => $height ], $result );
	}

	public function test_resolveSvg_touches_no_file_without_an_id(): void {
		Functions\expect( 'get_attached_file' )->never();

		$this->assertSame(
			[ 'width' => null, 'height' => null ],
			SvgDimensions::resolveSvg( null, 'image/svg+xml', null, null )
		);
	}

	public function test_resolveSvg_fills_a_missing_axis_for_an_svg(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_attached_file' )->justReturn( $file );

		$this->assertSame(
			[ 'width' => 92, 'height' => 106 ],
			SvgDimensions::resolveSvg( 4301, 'image/svg+xml', null, null )
		);
	}

	public function test_resolveSvg_keeps_a_known_axis(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_attached_file' )->justReturn( $file );

		$this->assertSame(
			[ 'width' => 60, 'height' => 106 ],
			SvgDimensions::resolveSvg( 4302, 'image/svg+xml', 60, null )
		);
	}

	public function test_resolveSvg_opens_a_repeated_file_once(): void {
		// A logo marquee renders the same attachment many times; without the memo
		// that is one file open per <img> rather than per file.
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		$opens = 0;
		Functions\when( 'get_attached_file' )->alias(
			function () use ( $file, &$opens ) {
				++$opens;
				return $file;
			}
		);

		for ( $i = 0; $i < 30; $i++ ) {
			SvgDimensions::resolveSvg( 4303, 'image/svg+xml', null, null );
		}

		$this->assertSame( 1, $opens );
	}

	public function test_resolveSvg_remembers_a_refusal_too(): void {
		// An unreadable file must not be retried on every image either.
		$opens = 0;
		Functions\when( 'get_attached_file' )->alias(
			function () use ( &$opens ) {
				++$opens;
				return '/nonexistent/nope.svg';
			}
		);

		for ( $i = 0; $i < 10; $i++ ) {
			$result = SvgDimensions::resolveSvg( 4304, 'image/svg+xml', null, null );
		}

		$this->assertSame( 1, $opens );
		$this->assertSame( [ 'width' => null, 'height' => null ], $result );
	}

	// --- backfill -------------------------------------------------------------

	public function test_backfill_fills_missing_dimensions(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'file' => 'a.svg' ] );

		$written = null;
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function ( $id, $meta ) use ( &$written ) {
				$written = $meta;
				return true;
			}
		);

		$result = ( new SvgDimensions() )->backfill( 7 );

		$this->assertSame( 'derived', $result['status'] );
		$this->assertSame( 92, $result['width'] );
		$this->assertSame( 106, $result['height'] );
		$this->assertSame( 92, $written['width'] );
		$this->assertSame( 106, $written['height'] );
		// The rest of the metadata survives untouched.
		$this->assertSame( 'a.svg', $written['file'] );
	}

	public function test_backfill_keeps_a_stored_width_while_filling_the_missing_height(): void {
		// The guarantee in this class, the README and the changelog is that
		// another plugin's answer stands. Treating the pair atomically broke it:
		// a missing height caused a valid width to be overwritten too.
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'file' => 'a.svg', 'width' => 60 ] );

		$written = null;
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function ( $id, $meta ) use ( &$written ) {
				$written = $meta;
				return true;
			}
		);

		$result = ( new SvgDimensions() )->backfill( 7 );

		$this->assertSame( 'derived', $result['status'] );
		$this->assertSame( 60, $result['width'] );
		$this->assertSame( 106, $result['height'] );
		$this->assertSame( 60, $written['width'] );
		$this->assertSame( 106, $written['height'] );
	}

	public function test_backfill_keeps_a_stored_height_while_filling_the_missing_width(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'height' => 26, 'width' => 0 ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );

		$result = ( new SvgDimensions() )->backfill( 7 );

		$this->assertSame( 92, $result['width'] );
		$this->assertSame( 26, $result['height'] );
	}

	public function test_backfill_reads_unfiltered_metadata(): void {
		// wp_get_attachment_metadata() runs the stored array through filters and
		// this method writes back what it read, so a plugin that rewrites paths
		// on read would have its projection persisted.
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );

		$unfiltered = null;
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			function ( $id, $flag = false ) use ( &$unfiltered ) {
				$unfiltered = $flag;
				return [];
			}
		);

		( new SvgDimensions() )->backfill( 7 );

		$this->assertTrue( $unfiltered );
	}

	public function test_backfill_reports_unchanged_rather_than_failed(): void {
		// wp_update_attachment_metadata() returns false when nothing changed, not
		// only on failure, so --force on an already-correct file used to report
		// a failure that had not happened.
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'width' => 92, 'height' => 106 ] );
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$this->assertSame( 'unchanged', ( new SvgDimensions() )->backfill( 7, true )['status'] );
	}

	public function test_backfill_confirms_a_false_return_against_the_database(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( false );

		$calls = 0;
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			function () use ( &$calls ) {
				++$calls;
				// Second read is the confirmation: the write did land.
				return $calls > 1 ? [ 'width' => 92, 'height' => 106 ] : [];
			}
		);

		$this->assertSame( 'derived', ( new SvgDimensions() )->backfill( 7 )['status'] );
	}

	public function test_backfill_force_actually_writes(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'width' => 60, 'height' => 26 ] );

		$written = null;
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function ( $id, $meta ) use ( &$written ) {
				$written = $meta;
				return true;
			}
		);

		$this->assertSame( 'derived', ( new SvgDimensions() )->backfill( 7, true )['status'] );
		$this->assertSame( [ 'width' => 92, 'height' => 106 ], $written );
	}

	public function test_filter_keeps_a_stored_width_while_filling_the_missing_height(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );

		$metadata = ( new SvgDimensions() )->filterGeneratedMetadata(
			[ 'width' => 60, 'height' => 0, 'sizes' => [ 'thumbnail' => [] ] ],
			7
		);

		$this->assertSame( 60, $metadata['width'] );
		$this->assertSame( 106, $metadata['height'] );
		$this->assertSame( [ 'thumbnail' => [] ], $metadata['sizes'] );
	}

	public function test_backfill_leaves_dimensions_another_plugin_already_stored(): void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'width' => 60, 'height' => 26 ] );
		Functions\when( 'get_attached_file' )->justReturn( '/should/not/be/read.svg' );
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$result = ( new SvgDimensions() )->backfill( 7 );

		$this->assertSame( 'already_sized', $result['status'] );
	}

	public function test_backfill_treats_a_stored_zero_as_missing(): void {
		// svg-support writes intval('') === 0 for a viewBox-only export, so a
		// zero is its failure, not a plugin's considered answer.
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'width' => 0, 'height' => 0 ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );

		$this->assertSame( 'derived', ( new SvgDimensions() )->backfill( 7 )['status'] );
	}

	public function test_backfill_force_overwrites_an_existing_value(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'width' => 1, 'height' => 1 ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );

		$this->assertSame( 'derived', ( new SvgDimensions() )->backfill( 7, true )['status'] );
	}

	public function test_backfill_dry_run_writes_nothing(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$result = ( new SvgDimensions() )->backfill( 7, false, true );

		$this->assertSame( 'would_derive', $result['status'] );
		$this->assertSame( 92, $result['width'] );
	}

	public function test_backfill_skips_a_non_svg_attachment(): void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$this->assertSame( 'not_svg', ( new SvgDimensions() )->backfill( 7 )['status'] );
	}

	public function test_backfill_reports_an_unreadable_file(): void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( '/nonexistent/nope.svg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$this->assertSame( 'unreadable', ( new SvgDimensions() )->backfill( 7 )['status'] );
	}

	public function test_backfill_creates_metadata_when_the_attachment_has_none(): void {
		// Core stores `false` for an SVG it could not measure at all.
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( false );

		$written = null;
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function ( $id, $meta ) use ( &$written ) {
				$written = $meta;
				return true;
			}
		);

		$this->assertSame( 'derived', ( new SvgDimensions() )->backfill( 7 )['status'] );
		$this->assertSame( [ 'width' => 92, 'height' => 106 ], $written );
	}

	// --- the upload filter ----------------------------------------------------

	public function test_filter_fills_metadata_for_a_viewBox_only_upload(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 92 106"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );

		$metadata = ( new SvgDimensions() )->filterGeneratedMetadata( [ 'file' => 'a.svg' ], 7 );

		$this->assertSame( 92, $metadata['width'] );
		$this->assertSame( 106, $metadata['height'] );
		$this->assertSame( 'a.svg', $metadata['file'] );
	}

	public function test_filter_never_overwrites_what_another_plugin_wrote(): void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( '/should/not/be/read.svg' );

		$input    = [ 'width' => 60, 'height' => 26, 'file' => 'a.svg' ];
		$metadata = ( new SvgDimensions() )->filterGeneratedMetadata( $input, 7 );

		$this->assertSame( $input, $metadata );
	}

	public function test_filter_passes_a_non_svg_through_untouched(): void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );

		$input = [ 'width' => 800, 'height' => 600 ];

		$this->assertSame( $input, ( new SvgDimensions() )->filterGeneratedMetadata( $input, 7 ) );
	}

	public function test_filter_returns_its_input_unchanged_when_nothing_can_be_derived(): void {
		$file = $this->tempSvg( '<svg xmlns="http://www.w3.org/2000/svg"></svg>' );

		Functions\when( 'get_post_mime_type' )->justReturn( 'image/svg+xml' );
		Functions\when( 'get_attached_file' )->justReturn( $file );

		$input = [ 'file' => 'a.svg' ];

		$this->assertSame( $input, ( new SvgDimensions() )->filterGeneratedMetadata( $input, 7 ) );
	}
}
