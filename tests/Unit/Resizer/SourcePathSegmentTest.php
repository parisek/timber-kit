<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Tests\Unit\ResizerTestCase;

class SourcePathSegmentTest extends ResizerTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Mirrors WordPress's own sanitize_file_name(): keep A-Za-z0-9._-, strip
		// the rest. Defined here (not in ResizerTestCase) because this is the
		// only suite whose expectations depend on the exact sanitation shape.
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
		} );
	}

	public static function segments(): array {
		return [
			'year/month upload' => [
				'https://x.test/wp-content/uploads/2026/08/hero.webp',
				'https://x.test/wp-content/uploads',
				'2026/08',
			],
			'no yearmonth folders' => [
				'https://x.test/wp-content/uploads/hero.webp',
				'https://x.test/wp-content/uploads',
				'',
			],
			'trailing slash on baseurl' => [
				'https://x.test/wp-content/uploads/2026/08/hero.webp',
				'https://x.test/wp-content/uploads/',
				'2026/08',
			],
			'source outside uploads' => [
				'https://x.test/wp-content/themes/t/img/hero.webp',
				'https://x.test/wp-content/uploads',
				'',
			],
			'deeper custom structure' => [
				'https://x.test/wp-content/uploads/sites/3/2026/08/hero.webp',
				'https://x.test/wp-content/uploads',
				'sites/3/2026/08',
			],
			'traversal is refused' => [
				'https://x.test/wp-content/uploads/../../evil/hero.webp',
				'https://x.test/wp-content/uploads',
				'',
			],
			'encoded traversal is refused' => [
				'https://x.test/wp-content/uploads/%2e%2e/evil/hero.webp',
				'https://x.test/wp-content/uploads',
				'',
			],
			'query string is ignored' => [
				'https://x.test/wp-content/uploads/2026/08/hero.webp?v=2',
				'https://x.test/wp-content/uploads',
				'2026/08',
			],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'segments' )]
	public function test_source_path_segment( string $src, string $baseurl, string $expected ): void {
		$resizer = $this->createResizer();

		$this->assertSame(
			$expected,
			$this->callPrivate( $resizer, 'sourcePathSegment', [ $src, $baseurl ] )
		);
	}
}
