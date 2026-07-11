<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;
use Parisek\TimberKit\Health\SiteHealthAdapter;

class SiteHealthAdapterTest extends HealthTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '__' )->returnArg();
	}

	private function fakeCheck( string $id, Result $result ): HealthCheck {
		return new class( $id, $result ) implements HealthCheck {
			public function __construct(
				private readonly string $id,
				private readonly Result $result,
			) {
			}
			public function id(): string {
				return $this->id;
			}
			public function label(): string {
				return 'Label ' . $this->id;
			}
			public function category(): string {
				return 'security';
			}
			public function method(): string {
				return self::METHOD_EFFECT;
			}
			public function run(): Result {
				return $this->result;
			}
		};
	}

	public function test_map_tests_adds_direct_entries_prefixed_with_timber_kit_health(): void {
		$tests = [ 'direct' => [ 'existing' => [ 'label' => 'x' ] ], 'async' => [] ];

		$mapped = SiteHealthAdapter::mapTests(
			$tests,
			[ 'foo' => $this->fakeCheck( 'foo', Result::good( 'ok' ) ) ]
		);

		$this->assertArrayHasKey( 'existing', $mapped['direct'] );
		$this->assertArrayHasKey( 'timber_kit_health_foo', $mapped['direct'] );
		$this->assertSame( 'Label foo', $mapped['direct']['timber_kit_health_foo']['label'] );
		$this->assertIsCallable( $mapped['direct']['timber_kit_health_foo']['test'] );
	}

	public function test_map_tests_normalizes_non_array_input(): void {
		$mapped = SiteHealthAdapter::mapTests( null, [] );

		$this->assertSame( [ 'direct' => [], 'async' => [] ], $mapped );
	}

	public function test_map_tests_skips_non_check_entries(): void {
		$mapped = SiteHealthAdapter::mapTests(
			[ 'direct' => [], 'async' => [] ],
			[ 'bogus' => 'not a check', 'real' => $this->fakeCheck( 'real', Result::good( 'ok' ) ) ]
		);

		$this->assertArrayNotHasKey( 'timber_kit_health_bogus', $mapped['direct'] );
		$this->assertArrayHasKey( 'timber_kit_health_real', $mapped['direct'] );
	}

	public function test_map_tests_first_check_wins_on_duplicate_id(): void {
		$mapped = SiteHealthAdapter::mapTests(
			[ 'direct' => [], 'async' => [] ],
			[
				'a' => $this->fakeCheck( 'same', Result::good( 'first' ) ),
				'b' => $this->fakeCheck( 'same', Result::critical( 'second' ) ),
			]
		);

		$outcome = $mapped['direct']['timber_kit_health_same']['test']();

		$this->assertSame( '<p>first</p>', $outcome['description'] );
	}

	public function test_actions_html_is_passed_through_wp_kses_post(): void {
		Functions\when( 'wp_kses_post' )->alias( fn ( string $html ): string => str_replace( '<script>bad</script>', '', $html ) );

		$check = $this->fakeCheck( 'foo', Result::critical( 'Bad.', '<script>bad</script><a href="https://example.com">Docs</a>' ) );

		$result = SiteHealthAdapter::toSiteHealthResult( $check );

		$this->assertSame( '<a href="https://example.com">Docs</a>', $result['actions'] );
	}

	public function test_to_site_health_result_maps_result_to_wp_shape(): void {
		$check = $this->fakeCheck( 'foo', Result::critical( 'Bad news.', '<a href="https://example.com">Docs</a>' ) );

		$result = SiteHealthAdapter::toSiteHealthResult( $check );

		$this->assertSame( 'Label foo', $result['label'] );
		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( [ 'label' => 'Security', 'color' => 'blue' ], $result['badge'] );
		$this->assertSame( '<p>Bad news.</p>', $result['description'] );
		$this->assertSame( '<a href="https://example.com">Docs</a>', $result['actions'] );
		$this->assertSame( 'timber_kit_health_foo', $result['test'] );
	}

	public function test_registered_test_closure_returns_the_run_result(): void {
		$mapped = SiteHealthAdapter::mapTests(
			[ 'direct' => [], 'async' => [] ],
			[ 'foo' => $this->fakeCheck( 'foo', Result::good( 'All fine.' ) ) ]
		);

		$outcome = $mapped['direct']['timber_kit_health_foo']['test']();

		$this->assertSame( 'good', $outcome['status'] );
		$this->assertSame( '<p>All fine.</p>', $outcome['description'] );
	}
}
