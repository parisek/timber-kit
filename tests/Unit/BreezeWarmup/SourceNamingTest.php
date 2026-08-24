<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmup\SourceNaming;

/**
 * Covers post-type and language derivation.
 *
 * Both are deliberately forgiving: an unrecognised shape resolves to an
 * empty type (weight 0) or the default language, never to a guess and never
 * to an exception. A sitemap is untrusted input.
 */
class SourceNamingTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function postTypeCases(): array {
		return array(
			'core shape'             => array( 'https://example.test/wp-sitemap-posts-post-1.xml', 'post' ),
			'core custom type'       => array( 'https://example.test/wp-sitemap-posts-realizace-2.xml', 'realizace' ),
			'core taxonomy is not a post type' => array( 'https://example.test/wp-sitemap-taxonomies-category-1.xml', '' ),
			'aioseo shape'           => array( 'https://example.test/post-sitemap.xml', 'post' ),
			'aioseo custom type'     => array( 'https://example.test/realizace-sitemap.xml', 'realizace' ),
			'aioseo gzipped'         => array( 'https://example.test/post-sitemap.xml.gz', 'post' ),
			'root sitemap'           => array( 'https://example.test/sitemap.xml', '' ),
			'core root'              => array( 'https://example.test/wp-sitemap.xml', '' ),
			'unknown shape'          => array( 'https://example.test/whatever.xml', '' ),
			'empty'                  => array( '', '' ),
			'aioseo author index is not a post type'             => array( 'https://example.test/author-sitemap.xml', '' ),
			'aioseo date index is not a post type'                => array( 'https://example.test/date-sitemap.xml', '' ),
			'aioseo product_attributes index is not a post type' => array( 'https://example.test/product_attributes-sitemap.xml', '' ),
		);
	}

	#[DataProvider('postTypeCases')]
	public function test_derives_post_type( string $sitemapUrl, string $expected ): void {
		$this->assertSame( $expected, SourceNaming::derivePostType( $sitemapUrl ) );
	}

	public function test_language_comes_from_the_sub_sitemap_when_it_names_one(): void {
		$this->assertSame(
			'sk',
			SourceNaming::deriveLanguage(
				'https://example.test/nieco/',
				'https://example.test/sk/post-sitemap.xml',
				array( 'cs', 'sk' ),
				'cs'
			)
		);
	}

	public function test_language_comes_from_the_first_path_segment(): void {
		$this->assertSame(
			'sk',
			SourceNaming::deriveLanguage(
				'https://example.test/sk/nieco/',
				'https://example.test/sitemap.xml',
				array( 'cs', 'sk' ),
				'cs'
			)
		);
	}

	public function test_language_comes_from_the_lang_query_parameter(): void {
		$this->assertSame(
			'sk',
			SourceNaming::deriveLanguage(
				'https://example.test/?lang=sk',
				'https://example.test/sitemap.xml',
				array( 'cs', 'sk' ),
				'cs'
			)
		);
	}

	public function test_language_falls_back_to_the_default(): void {
		$this->assertSame(
			'cs',
			SourceNaming::deriveLanguage(
				'https://example.test/neco/',
				'https://example.test/sitemap.xml',
				array( 'cs', 'sk' ),
				'cs'
			)
		);
	}

	public function test_language_default_is_lowercased(): void {
		$this->assertSame(
			'cs',
			SourceNaming::deriveLanguage(
				'https://example.test/neco/',
				'https://example.test/sitemap.xml',
				array( 'cs', 'sk' ),
				'CS'
			)
		);
	}

	public function test_a_path_segment_that_is_not_an_active_code_is_not_a_language(): void {
		// "blog" looks like a prefix but is not a registered language, so it
		// must not be mistaken for one.
		$this->assertSame(
			'cs',
			SourceNaming::deriveLanguage(
				'https://example.test/blog/neco/',
				'https://example.test/sitemap.xml',
				array( 'cs', 'sk' ),
				'cs'
			)
		);
	}
}
