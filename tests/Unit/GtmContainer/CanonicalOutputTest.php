<?php

declare(strict_types=1);

namespace Tests\Unit\GtmContainer;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\GtmContainer;

/**
 * Whole-output assertions against Google's published snippet.
 *
 * The rest of the suite checks behaviour; this file checks appearance. The
 * page source has to read as if someone pasted the snippet from Google's
 * documentation by hand — no vendor attributes, no generator marks, same
 * line breaks and comments — so that a person diffing it against the
 * documentation finds only the one intended deviation: the id-less URL.
 */
class CanonicalOutputTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_default_loader_is_googles_snippet_verbatim(): void {
		$expected = <<<'HTML'
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-N9FNXT1');</script>
<!-- End Google Tag Manager -->

HTML;

		$this->assertSame( $expected, GtmContainer::snippet( array( 'id' => 'GTM-N9FNXT1' ) ) );
	}

	/**
	 * The one intended deviation: the container is selected by its path, so
	 * the ID leaves the URL and the query string starts rather than
	 * continues. Everything around it stays Google's.
	 */
	public function test_the_id_less_loader_deviates_only_where_it_must(): void {
		$expected = <<<'HTML'
<!-- Google Tag Manager -->
<script>(function(w,d,s,l){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'?l='+l:'';j.async=true;j.src=
'https://windstream.example.com/84jp8NTuqpqDvI/'+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer');</script>
<!-- End Google Tag Manager -->

HTML;

		$this->assertSame(
			$expected,
			GtmContainer::snippet(
				array(
					'id'     => 'GTM-N9FNXT1',
					'domain' => 'windstream.example.com',
					'path'   => '84jp8NTuqpqDvI/',
				)
			)
		);
	}

	public function test_the_noscript_block_is_googles_snippet_verbatim(): void {
		$expected = <<<'HTML'
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-N9FNXT1"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

HTML;

		$this->assertSame( $expected, GtmContainer::noscript( array( 'id' => 'GTM-N9FNXT1' ) ) );
	}

	/**
	 * Vendor attributes a plugin adds for its own hosting concerns
	 * (Cloudflare Rocket Loader, PageSpeed) are not part of the snippet
	 * Google publishes, and their presence is what makes a page look
	 * plugin-generated.
	 */
	public function test_no_vendor_attributes_reach_the_page(): void {
		$output = GtmContainer::snippet( array( 'id' => 'GTM-N9FNXT1' ) )
			. GtmContainer::noscript( array( 'id' => 'GTM-N9FNXT1' ) );

		foreach ( array( 'data-cfasync', 'data-pagespeed', 'gtm4wp', 'timber-kit', 'aria-hidden' ) as $mark ) {
			$this->assertStringNotContainsString( $mark, $output );
		}
	}
}
