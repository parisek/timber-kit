<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Seo\NotFoundTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `pre_get_document_title` callback behind `$seo_not_found_title`.
 *
 * The load-bearing case is the padded string. `wp_title( ' ' )` on a 404
 * returns core's title behind its separator prefix, `'   Page not found'`, and
 * an SEO plugin that caches the first title it sees hands that string back as
 * the document title. Returning `''` sends core down its own path, which builds
 * `Page not found - <site name>`.
 */
final class NotFoundTitleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function paddedTitles(): array {
		return array(
			'wp_title separator prefix'  => array( '   Page not found' ),
			'translated'                 => array( '   Stránka nebyla nalezena' ),
			'right-hand separator'       => array( 'Page not found   ' ),
			'whitespace only'            => array( '   ' ),
			'tab and newline'            => array( "\t\nPage not found" ),
		);
	}

	#[DataProvider( 'paddedTitles' )]
	public function testAPaddedTitleOnA404FallsBackToCore( string $title ): void {
		Functions\when( 'is_404' )->justReturn( true );

		$this->assertSame( '', NotFoundTitle::filter( $title ) );
	}

	/**
	 * A title somebody wrote on purpose stays, even on a 404: an SEO plugin
	 * that has its own 404 format produces exactly this shape.
	 */
	public function testAWellFormedTitleOnA404IsKept(): void {
		Functions\when( 'is_404' )->justReturn( true );

		$this->assertSame( 'Stránka nenalezena | Example', NotFoundTitle::filter( 'Stránka nenalezena | Example' ) );
	}

	/**
	 * Empty already means "let core build it". Passing it through keeps that.
	 */
	public function testAnEmptyTitleOnA404PassesThrough(): void {
		Functions\when( 'is_404' )->justReturn( true );

		$this->assertSame( '', NotFoundTitle::filter( '' ) );
	}

	/**
	 * Off a 404 the callback changes nothing, padded or not: the scope is the
	 * one request type where the defect was measured.
	 */
	public function testAPaddedTitleOffA404IsLeftAlone(): void {
		Functions\when( 'is_404' )->justReturn( false );

		$this->assertSame( '   Page not found', NotFoundTitle::filter( '   Page not found' ) );
	}

	/**
	 * Somebody else's value of an unexpected type is not this callback's to
	 * judge.
	 */
	public function testANonStringPassesThrough(): void {
		Functions\when( 'is_404' )->justReturn( true );

		$this->assertNull( NotFoundTitle::filter( null ) );
		$this->assertFalse( NotFoundTitle::filter( false ) );
	}

	/**
	 * Registered after every SEO plugin's own callback, or the plugin would
	 * overwrite the repaired value.
	 */
	public function testRegisterHooksTheFilterLast(): void {
		$added = array();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback, int $priority = 10 ) use ( &$added ): bool {
				$added[] = array( $hook, $callback, $priority );

				return true;
			}
		);

		NotFoundTitle::register();

		$this->assertSame(
			array( array( 'pre_get_document_title', array( NotFoundTitle::class, 'filter' ), PHP_INT_MAX ) ),
			$added
		);
	}
}
