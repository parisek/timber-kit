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

		// Either it parses without expanding the entity, or it refuses. Never
		// the file's contents.
		$result = SvgDimensions::fromMarkup( $markup );
		$this->assertContains( $result, [ null, [ 'width' => 92, 'height' => 106 ] ] );
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
