<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use Parisek\TimberKit\Seo\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Detection is split in two on purpose.
 *
 * Brain\Monkey stubs a WordPress function by DEFINING the real global function,
 * and that definition survives for the rest of the PHPUnit process. Once any
 * test stubs `aioseo`, `function_exists( 'aioseo' )` answers true in every
 * later test too — so the "plugin absent" branch cannot be tested through the
 * symbol checks at all, and the kit's AGENTS.md says to document such guards by
 * inspection instead.
 *
 * So the judgment lives in `detect()`, which takes booleans and is exhaustively
 * tested here, and `active()` is one line of symbol gathering on top. What is
 * untestable is reduced to something a reader can verify by eye.
 */
final class PluginTest extends TestCase {

	/**
	 * @return array<string, array{0: array{yoast: bool, aioseo: bool}, 1: ?string}>
	 */
	public static function symbols(): array {
		return array(
			'neither plugin'  => array( array( 'yoast' => false, 'aioseo' => false ), null ),
			'yoast alone'     => array( array( 'yoast' => true, 'aioseo' => false ), 'yoast' ),
			'aioseo alone'    => array( array( 'yoast' => false, 'aioseo' => true ), 'aioseo' ),
			'both installed'  => array( array( 'yoast' => true, 'aioseo' => true ), 'aioseo' ),
		);
	}

	#[DataProvider( 'symbols' )]
	public function testOnePluginIsChosen( array $present, ?string $expected ): void {
		$this->assertSame( $expected, Plugin::detect( $present ) );
	}

	/**
	 * Two SEO plugins emit two `rel=canonical` tags — a defect, not a mode. The
	 * layer must pick one and pick it the same way every request, so the result
	 * can never depend on plugin load order.
	 */
	public function testTwoPluginsResolveDeterministically(): void {
		$both = array( 'yoast' => true, 'aioseo' => true );

		$this->assertSame( Plugin::detect( $both ), Plugin::detect( $both ) );
		$this->assertSame( 'aioseo', Plugin::detect( $both ) );
	}

	/**
	 * A symbol proves the plugin is loaded. It does not prove the plugin does
	 * the thing being asked about: Yoast ships a switch that turns its XML
	 * sitemap off, and when it is off WordPress core serves /wp-sitemap.xml
	 * again. `WarmupSitemap` learned this the hard way; the lesson moves here
	 * intact rather than being re-derived.
	 *
	 * @return array<string, array{0: ?array<string, mixed>, 1: bool}>
	 */
	public static function yoastSitemapOptions(): array {
		return array(
			'switched on'            => array( array( 'enable_xml_sitemap' => true ), true ),
			'switched off'           => array( array( 'enable_xml_sitemap' => false ), false ),
			'key absent counts as on' => array( array( 'other' => 1 ), true ),
			'unreadable counts as on' => array( null, true ),
		);
	}

	#[DataProvider( 'yoastSitemapOptions' )]
	public function testTheYoastSitemapSwitchIsHonoured( ?array $options, bool $expected ): void {
		$this->assertSame( $expected, Plugin::supportsYoastSitemap( $options ) );
	}
}
