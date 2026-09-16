<?php

declare(strict_types=1);

namespace Tests\Unit\ImageCacheRegenerator;

use Parisek\TimberKit\ImageCacheRegenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reads a derivative path back into the parameters that produced it.
 *
 * Every parameter the resizer wrote into the path comes back out: the two
 * dimensions, the style, the quality when the path carries one, the output
 * format, and the source file relative to the uploads root.
 */
class ParsePathTest extends TestCase {

	private const string CACHE = '/var/www/wp-content/cache/image';
	private const string UPLOADS = '/var/www/wp-content/uploads';

	private function regenerator( int $default_quality = 80 ): ImageCacheRegenerator {
		return new ImageCacheRegenerator( self::CACHE, self::UPLOADS, $default_quality );
	}

	/**
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public static function derivatives(): array {
		return array(
			'width and height with quality' => array(
				'1440x900-center-q80/2025/09/Career.png.avif',
				array( 'width' => 1440, 'height' => 900, 'image_style' => 'center', 'quality' => 80, 'format' => 'avif', 'source' => '2025/09/Career.png' ),
			),
			'height zero' => array(
				'0x1056-center-q80/2025/09/Career.png.avif',
				array( 'width' => 0, 'height' => 1056, 'image_style' => 'center', 'quality' => 80, 'format' => 'avif', 'source' => '2025/09/Career.png' ),
			),
			'style top' => array(
				'600x800-top-q70/2026/01/team.jpg.webp',
				array( 'width' => 600, 'height' => 800, 'image_style' => 'top', 'quality' => 70, 'format' => 'webp', 'source' => '2026/01/team.jpg' ),
			),
			'style with a dash' => array(
				'1200x630-smart-crop-q80/2026/01/hero.jpg.avif',
				array( 'width' => 1200, 'height' => 630, 'image_style' => 'smart-crop', 'quality' => 80, 'format' => 'avif', 'source' => '2026/01/hero.jpg' ),
			),
			'no quality segment falls back to the filter value' => array(
				'900x0-smart-crop/2026/01/hero.jpg.avif',
				array( 'width' => 900, 'height' => 0, 'image_style' => 'smart-crop', 'quality' => 55, 'format' => 'avif', 'source' => '2026/01/hero.jpg' ),
			),
			'nested source path' => array(
				'900x0-center-q80/sites/3/2026/01/a/b/hero.jpg.avif',
				array( 'width' => 900, 'height' => 0, 'image_style' => 'center', 'quality' => 80, 'format' => 'avif', 'source' => 'sites/3/2026/01/a/b/hero.jpg' ),
			),
			'source at the uploads root' => array(
				'900x0-center-q80/hero.jpg.avif',
				array( 'width' => 900, 'height' => 0, 'image_style' => 'center', 'quality' => 80, 'format' => 'avif', 'source' => 'hero.jpg' ),
			),
			'odd characters in the name stay verbatim' => array(
				'900x0-center-q80/2026/01/Ebook 50%25 off #2.png.avif',
				array( 'width' => 900, 'height' => 0, 'image_style' => 'center', 'quality' => 80, 'format' => 'avif', 'source' => '2026/01/Ebook 50%25 off #2.png' ),
			),
			'a dot in the stem is not the source extension' => array(
				'900x0-center-q80/2026/01/photo.v2.jpg.avif',
				array( 'width' => 900, 'height' => 0, 'image_style' => 'center', 'quality' => 80, 'format' => 'avif', 'source' => '2026/01/photo.v2.jpg' ),
			),
			'quality zero is a quality, not an absent one' => array(
				'900x0-center-q0/2026/01/hero.jpg.avif',
				array( 'width' => 900, 'height' => 0, 'image_style' => 'center', 'quality' => 0, 'format' => 'avif', 'source' => '2026/01/hero.jpg' ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $expected
	 */
	#[DataProvider('derivatives')]
	public function testParsesEveryParameterOutOfThePath( string $relative, array $expected ): void {
		$entry = $this->regenerator( 55 )->parse( self::CACHE . '/' . $relative );

		$this->assertNotNull( $entry );
		$this->assertSame( $expected['source'], $entry['source_relative'] );
		$this->assertSame( self::UPLOADS . '/' . $expected['source'], $entry['source'] );
		$this->assertSame( $expected['width'], $entry['variant']['width'] );
		$this->assertSame( $expected['height'], $entry['variant']['height'] );
		$this->assertSame( $expected['image_style'], $entry['variant']['image_style'] );
		$this->assertSame( $expected['quality'], $entry['variant']['quality'] );
		$this->assertSame( $expected['format'], $entry['variant']['format'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unreadable(): array {
		return array(
			// The flat layout names a derivative after the sanitised source
			// stem, so the source extension is gone and no path points back.
			'flat layout carries no source extension' => array( '900x0-center-q80/hero.avif' ),
			'derivative directly in the cache root'   => array( 'hero.jpg.avif' ),
			'size segment is not a size'              => array( 'thumbnails/2026/01/hero.jpg.avif' ),
			'no output extension'                     => array( '900x0-center-q80/2026/01/hero' ),
		);
	}

	#[DataProvider('unreadable')]
	public function testRefusesAPathItCannotReadBack( string $relative ): void {
		$this->assertNull( $this->regenerator()->parse( self::CACHE . '/' . $relative ) );
	}

	public function testRefusesAPathOutsideTheCacheDirectory(): void {
		$this->assertNull( $this->regenerator()->parse( '/var/www/wp-content/uploads/2026/01/hero.jpg' ) );
	}
}
