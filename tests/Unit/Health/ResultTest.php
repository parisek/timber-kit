<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use Parisek\TimberKit\Health\Result;

class ResultTest extends HealthTestCase {

	public function test_good_result_carries_status_and_summary(): void {
		$result = Result::good( 'All fine.' );

		$this->assertSame( Result::GOOD, $result->status() );
		$this->assertSame( 'good', $result->status() );
		$this->assertSame( 'All fine.', $result->summary() );
		$this->assertSame( '', $result->actions() );
	}

	public function test_recommended_result_carries_optional_actions(): void {
		$result = Result::recommended( 'Could be better.', '<a href="https://example.com">Fix it in code</a>' );

		$this->assertSame( 'recommended', $result->status() );
		$this->assertSame( 'Could be better.', $result->summary() );
		$this->assertSame( '<a href="https://example.com">Fix it in code</a>', $result->actions() );
	}

	public function test_critical_result_defaults_to_empty_actions(): void {
		$result = Result::critical( 'Broken.' );

		$this->assertSame( 'critical', $result->status() );
		$this->assertSame( '', $result->actions() );
	}
}
