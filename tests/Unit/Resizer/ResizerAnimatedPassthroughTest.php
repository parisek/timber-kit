<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * Integration coverage for the opt-in animated-source passthrough in resizer():
 * when `timber_kit_resizer_skip_animated` is enabled, an animated source returns
 * only the original image (no resized variants), stopping before the re-encode
 * loop that would flatten it. When the flag is off (the default) the property is
 * false and the source flows into the normal resize pipeline.
 */
class ResizerAnimatedPassthroughTest extends ResizerTestCase {

	/** @var string[] */
	private array $tmp_files = [];

	protected function tearDown(): void {
		foreach ( $this->tmp_files as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		$this->tmp_files = [];
		parent::tearDown();
	}

	/**
	 * Build a Resizer with the skip-animated feature forced on via the filter,
	 * leaving every other filter at its default.
	 */
	private function resizerSkippingAnimated(): Resizer {
		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $default, ...$args ) {
				unset( $args );
				return 'timber_kit_resizer_skip_animated' === $filter ? true : $default;
			}
		);
		return new Resizer();
	}

	/** Minimal two-frame GIF89a (no Global Color Table). */
	private function animatedGifBytes(): string {
		$header = 'GIF89a' . "\x01\x00\x01\x00" . "\x00" . "\x00" . "\x00";
		$frame = "\x2C" . "\x00\x00\x00\x00\x01\x00\x01\x00" . "\x00" . "\x02" . "\x00";
		return $header . $frame . $frame . "\x3B";
	}

	public function test_constructor_reads_skip_animated_filter(): void {
		// Default (no override) → off; the resize pipeline keeps its behaviour.
		$this->assertFalse( $this->getPrivateProperty( $this->createResizer(), 'skip_animated' ) );
		// Filter on → captured at construction.
		$this->assertTrue( $this->getPrivateProperty( $this->resizerSkippingAnimated(), 'skip_animated' ) );
	}

	public function test_animated_source_returns_original_only_when_enabled(): void {
		$resizer = $this->resizerSkippingAnimated();

		// Backend must be able to decode GIF for the source to reach the
		// animated check (it's gated behind canDecode()).
		if ( ! $resizer->canDecode( 'image/gif' ) ) {
			$this->markTestSkipped( 'GIF decoding unavailable on this backend.' );
		}

		$dir = sys_get_temp_dir();
		$name = 'tk_passthru_' . uniqid() . '.gif';
		$path = $dir . '/' . $name;
		file_put_contents( $path, $this->animatedGifBytes() );
		$this->tmp_files[] = $path;

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/gif', 'ext' => 'gif' ] );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => $dir,
			'baseurl' => 'https://example.com/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( static fn( $n ) => $n );

		$image = [
			'src'    => 'https://example.com/uploads/' . $name,
			'width'  => 1,
			'height' => 1,
			'alt'    => 'Animated',
		];

		$result = $resizer->resizer( $image, [ [ '800', '600', '768', 'crop' ] ] );

		// Passthrough: exactly the original, no generated variants.
		$this->assertCount( 1, $result );
		$this->assertSame( $image['src'], $result[0]['src'] );
		$this->assertSame( 'Animated', $result[0]['alt'] );
	}
}
