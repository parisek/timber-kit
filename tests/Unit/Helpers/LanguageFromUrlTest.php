<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Helpers;

/**
 * The language a URL names, read from the URL rather than from the request.
 *
 * The jobs that need this run from cron and WP-CLI, which have no language of
 * their own. Asking the ambient context there always answers "the site
 * default", which is how a translated URL silently becomes its default-language
 * sibling.
 */
final class LanguageFromUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, array<string, string>> $languages
	 */
	private function wpml( array $languages, string $default = 'en' ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null, ...$rest ) use ( $languages, $default ) {
				return match ( $hook ) {
					'wpml_active_languages' => $languages,
					'wpml_default_language' => $default,
					default                 => $value,
				};
			}
		);
	}

	/** @var array<string, array<string, string>> Directory negotiation, minimal shape. */
	private const DIRECTORY = array( 'en' => array(), 'cs' => array(), 'it' => array() );

	/**
	 * Directory negotiation as WPML actually reports it: every language carries
	 * a `url`, and they all share one host. The earlier fixture omitted `url`
	 * entirely, which is why it could not catch the defect below.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const DIRECTORY_REAL = array(
		'cs' => array( 'url' => 'https://example.test/cs/' ),
		'en' => array( 'url' => 'https://example.test/' ),
		'it' => array( 'url' => 'https://example.test/it/' ),
	);

	public function test_reads_the_path_prefix(): void {
		$this->wpml( self::DIRECTORY );

		$this->assertSame( 'it', Helpers::languageFromUrl( 'https://example.test/it/glossario-di-termini/' ) );
		$this->assertSame( 'cs', Helpers::languageFromUrl( 'https://example.test/cs/cenik/' ) );
	}

	public function test_a_bare_language_prefix_still_reads(): void {
		// `/it` and `/it/` are the Italian home page, not an unprefixed URL.
		$this->wpml( self::DIRECTORY );

		$this->assertSame( 'it', Helpers::languageFromUrl( 'https://example.test/it/' ) );
		$this->assertSame( 'it', Helpers::languageFromUrl( 'https://example.test/it' ) );
	}

	public function test_an_unprefixed_url_is_the_default_language(): void {
		// Under directory negotiation that is what no prefix means — not
		// "unknown", which would leave the caller to guess.
		$this->wpml( self::DIRECTORY );

		$this->assertSame( 'en', Helpers::languageFromUrl( 'https://example.test/pricing/' ) );
	}

	public function test_a_path_that_merely_starts_with_a_code_is_not_a_prefix(): void {
		// `/italy-guide/` begins with "it" and is not Italian.
		$this->wpml( self::DIRECTORY );

		$this->assertSame( 'en', Helpers::languageFromUrl( 'https://example.test/italy-guide/' ) );
	}

	public function test_a_relative_path_reads_the_same_as_an_absolute_one(): void {
		$this->wpml( self::DIRECTORY );

		$this->assertSame( 'cs', Helpers::languageFromUrl( '/cs/cenik/' ) );
	}

	public function test_a_host_shared_by_every_language_decides_nothing(): void {
		// The defect this pins. Under directory negotiation WPML still reports
		// a `url` per language and they all share one host, so a plain host
		// match succeeds for whichever language comes first in the array --
		// here `cs` -- and every URL resolves to it. Measured on a live
		// five-language site: an Italian URL came back Czech.
		$this->wpml( self::DIRECTORY_REAL );

		$this->assertSame( 'it', Helpers::languageFromUrl( 'https://example.test/it/glossario/' ) );
		$this->assertSame( 'cs', Helpers::languageFromUrl( 'https://example.test/cs/cenik/' ) );
		$this->assertSame( 'en', Helpers::languageFromUrl( 'https://example.test/pricing/' ) );
	}

	public function test_domain_negotiation_reads_the_host(): void {
		// Under per-language domains there is no prefix to read, and the path
		// would otherwise fall through to the default for every language.
		$this->wpml(
			array(
				'en' => array( 'url' => 'https://example.com/' ),
				'de' => array( 'url' => 'https://example.de/' ),
			)
		);

		$this->assertSame( 'de', Helpers::languageFromUrl( 'https://example.de/preise/' ) );
		$this->assertSame( 'en', Helpers::languageFromUrl( 'https://example.com/pricing/' ) );
	}

	public function test_the_host_wins_over_a_path_that_looks_like_a_prefix(): void {
		$this->wpml(
			array(
				'en' => array( 'url' => 'https://example.com/' ),
				'de' => array( 'url' => 'https://example.de/' ),
			)
		);

		$this->assertSame( 'de', Helpers::languageFromUrl( 'https://example.de/en/whatever/' ) );
	}

	public function test_no_wpml_answers_empty(): void {
		// Empty means "no opinion", and every caller treats it as "change
		// nothing" rather than as a language.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value = null, ...$rest ) => 'wpml_active_languages' === $hook ? null : $value
		);

		$this->assertSame( '', Helpers::languageFromUrl( 'https://example.test/it/x/' ) );
	}
}
