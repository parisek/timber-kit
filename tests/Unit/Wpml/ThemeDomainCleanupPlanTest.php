<?php

declare(strict_types=1);

namespace Tests\Unit\Wpml;

use Parisek\TimberKit\Wpml\ThemeDomainCleanupPlan;
use PHPUnit\Framework\TestCase;

class ThemeDomainCleanupPlanTest extends TestCase {

	public function test_has_work_when_any_row_count_is_nonzero(): void {
		$plan = new ThemeDomainCleanupPlan( 'fellows', 102, 69, 27, [] );

		$this->assertTrue( $plan->hasWork() );
	}

	public function test_has_work_when_only_compiled_files_remain(): void {
		$plan = new ThemeDomainCleanupPlan( 'fellows', 0, 0, 0, [ '/wp-content/languages/wpml/fellows-cs_CZ.mo' ] );

		$this->assertTrue( $plan->hasWork() );
	}

	public function test_no_work_when_everything_is_zero_and_no_files(): void {
		$plan = new ThemeDomainCleanupPlan( 'fellows', 0, 0, 0, [] );

		$this->assertFalse( $plan->hasWork() );
	}

	public function test_report_lines_include_domain_and_every_count(): void {
		$plan  = new ThemeDomainCleanupPlan( 'fellows', 102, 69, 27, [
			'/wp-content/languages/wpml/fellows-cs_CZ.mo',
			'/wp-content/languages/wpml/fellows-en_US.mo',
		] );
		$lines = $plan->reportLines();

		$this->assertSame( 'Text domain: fellows', $lines[0] );
		$this->assertStringContainsString( '102', implode( "\n", $lines ) );
		$this->assertStringContainsString( '69', implode( "\n", $lines ) );
		$this->assertStringContainsString( '27', implode( "\n", $lines ) );
		$this->assertStringContainsString( 'compiled WPML files: 2', implode( "\n", $lines ) );
	}

	public function test_accessors_return_constructor_values(): void {
		$files = [ '/wp-content/languages/wpml/fellows-cs_CZ.mo' ];
		$plan  = new ThemeDomainCleanupPlan( 'fellows', 102, 69, 27, $files );

		$this->assertSame( 'fellows', $plan->domain() );
		$this->assertSame( 102, $plan->stringCount() );
		$this->assertSame( 69, $plan->stringTranslationCount() );
		$this->assertSame( 27, $plan->stringPositionCount() );
		$this->assertSame( $files, $plan->compiledFiles() );
	}
}
