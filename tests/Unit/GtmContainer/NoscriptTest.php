<?php

declare(strict_types=1);

namespace Tests\Unit\GtmContainer;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\GtmContainer;

/**
 * The `noscript` iframe, which can only ever carry the container ID.
 *
 * That makes it free where the ID is public anyway, and self-defeating
 * where a custom path exists precisely to keep the ID out of requests. The
 * block is therefore emitted by default in the first case and only on
 * request in the second.
 */
class NoscriptTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_default_loader_gets_the_iframe(): void {
		$noscript = GtmContainer::noscript( array( 'id' => 'GTM-N9FNXT1' ) );

		$this->assertStringContainsString( 'https://www.googletagmanager.com/ns.html?id=GTM-N9FNXT1', $noscript );
		$this->assertStringContainsString( '<noscript><iframe', $noscript );
		$this->assertStringContainsString( 'height="0" width="0"', $noscript );
		$this->assertStringContainsString( 'aria-hidden="true"', $noscript );
	}

	public function test_a_custom_domain_serves_the_iframe_too(): void {
		$noscript = GtmContainer::noscript(
			array(
				'id'     => 'GTM-N9FNXT1',
				'domain' => 'windstream.example.com',
			)
		);

		$this->assertStringContainsString( 'https://windstream.example.com/ns.html?id=GTM-N9FNXT1', $noscript );
	}

	/**
	 * The whole point of a custom path is that the ID never appears in a
	 * request. An iframe cannot honour that, so silence is the default.
	 */
	public function test_a_custom_path_suppresses_the_iframe(): void {
		$this->assertSame(
			'',
			GtmContainer::noscript(
				array(
					'id'     => 'GTM-N9FNXT1',
					'domain' => 'windstream.example.com',
					'path'   => '84jp8NTuqpqDvI/',
				)
			)
		);
	}

	public function test_a_custom_path_can_opt_into_the_iframe(): void {
		$noscript = GtmContainer::noscript(
			array(
				'id'       => 'GTM-N9FNXT1',
				'domain'   => 'windstream.example.com',
				'path'     => '84jp8NTuqpqDvI/',
				'noscript' => true,
			)
		);

		$this->assertStringContainsString( 'https://windstream.example.com/ns.html?id=GTM-N9FNXT1', $noscript );
	}

	/**
	 * `ns.html` is served from the host root, not from the loader path —
	 * the opt-in restores the block, it does not invent a URL.
	 */
	public function test_the_opt_in_iframe_does_not_use_the_loader_path(): void {
		$noscript = GtmContainer::noscript(
			array(
				'id'       => 'GTM-N9FNXT1',
				'domain'   => 'windstream.example.com',
				'path'     => '84jp8NTuqpqDvI/',
				'noscript' => true,
			)
		);

		$this->assertStringNotContainsString( '84jp8NTuqpqDvI', $noscript );
	}

	public function test_the_iframe_can_be_switched_off_where_it_would_otherwise_print(): void {
		$this->assertSame(
			'',
			GtmContainer::noscript(
				array(
					'id'       => 'GTM-N9FNXT1',
					'noscript' => false,
				)
			)
		);
	}

	public function test_environment_parameters_reach_the_iframe(): void {
		$noscript = GtmContainer::noscript(
			array(
				'id'          => 'GTM-N9FNXT1',
				'gtm_auth'    => 'abc123',
				'gtm_preview' => 'env-5',
			)
		);

		$this->assertStringContainsString( '&gtm_auth=abc123&gtm_preview=env-5&gtm_cookies_win=x', $noscript );
	}

	public function test_an_unusable_container_emits_nothing(): void {
		$this->assertSame( '', GtmContainer::noscript( array() ) );
		$this->assertSame( '', GtmContainer::noscript( array( 'id' => 'gtm-lowercase' ) ) );
	}

	public function test_a_malformed_domain_falls_back_to_the_google_host(): void {
		$noscript = GtmContainer::noscript(
			array(
				'id'     => 'GTM-N9FNXT1',
				'domain' => "evil.example.com';alert(1);//",
			)
		);

		$this->assertStringContainsString( 'https://www.googletagmanager.com/ns.html', $noscript );
		$this->assertStringNotContainsString( 'alert(1)', $noscript );
	}
}
