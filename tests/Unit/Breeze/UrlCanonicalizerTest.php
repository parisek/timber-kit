<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\UrlCanonicalizer;

/**
 * Covers `canonicalize()` — the single URL shape every signal is keyed on.
 *
 * No Brain\Monkey: this is a pure function and must stay one, so the
 * scoring pipeline built on top of it can be property-tested under the
 * `tests/Property/` isolation convention.
 */
class UrlCanonicalizerTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function canonicalCases(): array {
		return array(
			'lowercases scheme and host' => array(
				'HTTPS://Example.TEST/Page/',
				'https://example.test/Page/',
			),
			'drops the fragment' => array(
				'https://example.test/page/#section',
				'https://example.test/page/',
			),
			'drops the default https port' => array(
				'https://example.test:443/page/',
				'https://example.test/page/',
			),
			'drops the default http port' => array(
				'http://example.test:80/page/',
				'http://example.test/page/',
			),
			'keeps a non-default port' => array(
				'https://example.test:8443/page/',
				'https://example.test:8443/page/',
			),
			'adds the trailing slash' => array(
				'https://example.test/page',
				'https://example.test/page/',
			),
			'leaves a file-looking path alone' => array(
				'https://example.test/feed.xml',
				'https://example.test/feed.xml',
			),
			'root gets a slash' => array(
				'https://example.test',
				'https://example.test/',
			),
			'preserves the query verbatim' => array(
				'https://example.test/?lang=sk&b=1',
				'https://example.test/?lang=sk&b=1',
			),
			'garbage passes through untouched' => array(
				'not a url',
				'not a url',
			),
		);
	}

	#[DataProvider('canonicalCases')]
	public function test_canonicalizes( string $input, string $expected ): void {
		$this->assertSame( $expected, UrlCanonicalizer::canonicalize( $input ) );
	}

	public function test_is_idempotent(): void {
		$once  = UrlCanonicalizer::canonicalize( 'HTTPS://Example.TEST:443/a#x' );
		$twice = UrlCanonicalizer::canonicalize( $once );

		$this->assertSame( $once, $twice );
	}
}
