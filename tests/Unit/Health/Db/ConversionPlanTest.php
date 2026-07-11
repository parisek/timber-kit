<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Db;

use Parisek\TimberKit\Health\Db\CharsetAudit;
use Parisek\TimberKit\Health\Db\ConversionPlan;
use Tests\Unit\Health\HealthTestCase;

class ConversionPlanTest extends HealthTestCase {

	private function audit(): CharsetAudit {
		return new CharsetAudit(
			[
				[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci', 'row_format' => 'DYNAMIC' ],
				[ 'name' => 'wp_aryo_activity_log', 'collation' => 'utf8mb3_general_ci', 'row_format' => 'COMPACT' ],
				[ 'name' => 'wp_legacy', 'collation' => 'latin1_swedish_ci', 'row_format' => 'DYNAMIC' ],
				[ 'name' => 'wp_straggler', 'collation' => 'utf8mb4_unicode_520_ci', 'row_format' => 'DYNAMIC' ],
			],
			[],
			'wp_'
		);
	}

	/**
	 * @param list<array{table_name: string, column_name: string, max_len: int|null, sub_part: int|null}> $indexed
	 */
	private function plan( array $indexed = [] ): ConversionPlan {
		return new ConversionPlan( $this->audit(), $indexed );
	}

	public function test_entries_cover_offending_and_mixed_collation_tables(): void {
		$entries = $this->plan()->entries();

		$this->assertSame(
			[ 'wp_aryo_activity_log', 'wp_legacy', 'wp_straggler' ],
			array_column( $entries, 'table' )
		);
		$this->assertSame( 'utf8mb3_general_ci', $entries[0]['from'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $entries[0]['to'] );
	}

	public function test_explicit_target_collation_overrides_dominant(): void {
		$plan = new ConversionPlan( $this->audit(), [], 'utf8mb4_czech_ci' );

		$this->assertSame( 'utf8mb4_czech_ci', $plan->entries()[0]['to'] );
	}

	public function test_warning_for_compact_row_format_with_long_indexed_varchar(): void {
		$entries = $this->plan( [
			[ 'table_name' => 'wp_aryo_activity_log', 'column_name' => 'hist_ip', 'max_len' => 255, 'sub_part' => null ],
		] )->entries();

		$by_table = array_column( $entries, 'warning', 'table' );

		$this->assertNotSame( '', $by_table['wp_aryo_activity_log'] );
		$this->assertSame( '', $by_table['wp_legacy'] );
	}

	public function test_no_warning_when_index_has_prefix_or_short_column(): void {
		$entries = $this->plan( [
			[ 'table_name' => 'wp_aryo_activity_log', 'column_name' => 'a', 'max_len' => 255, 'sub_part' => 100 ],
			[ 'table_name' => 'wp_aryo_activity_log', 'column_name' => 'b', 'max_len' => 100, 'sub_part' => null ],
		] )->entries();

		$by_table = array_column( $entries, 'warning', 'table' );

		$this->assertSame( '', $by_table['wp_aryo_activity_log'] );
	}

	public function test_select_filters_entries_and_rejects_unknown_tables(): void {
		$plan = $this->plan();

		$selected = $plan->select( [ 'wp_legacy' ] );
		$this->assertSame( [ 'wp_legacy' ], array_column( $selected->entries(), 'table' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'wp_nonexistent' );
		$plan->select( [ 'wp_legacy', 'wp_nonexistent' ] );
	}

	public function test_statements_emit_convert_to_dominant_collation(): void {
		$statements = $this->plan()->select( [ 'wp_aryo_activity_log' ] )->statements();

		$this->assertSame(
			[ 'ALTER TABLE `wp_aryo_activity_log` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' ],
			$statements
		);
	}

	public function test_has_warnings_reflects_selected_entries_only(): void {
		$plan = $this->plan( [
			[ 'table_name' => 'wp_aryo_activity_log', 'column_name' => 'hist_ip', 'max_len' => 255, 'sub_part' => null ],
		] );

		$this->assertTrue( $plan->hasWarnings() );
		$this->assertFalse( $plan->select( [ 'wp_legacy' ] )->hasWarnings() );
	}
}
