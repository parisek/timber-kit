<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Db;

use Parisek\TimberKit\Health\Db\CharsetAudit;
use Tests\Unit\Health\HealthTestCase;

class CharsetAuditTest extends HealthTestCase {

	/**
	 * @param list<array{name: string, collation: string, row_format?: string}> $tables
	 * @param list<array{table_name: string, column_name: string, collation: string}> $columns
	 */
	private function audit( array $tables, array $columns = [], string $prefix = 'wp_' ): CharsetAudit {
		return new CharsetAudit( $tables, $columns, $prefix );
	}

	public function test_clean_when_everything_is_uniform_utf8mb4(): void {
		$audit = $this->audit( [
			[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci' ],
			[ 'name' => 'wp_options', 'collation' => 'utf8mb4_unicode_ci' ],
		] );

		$this->assertTrue( $audit->clean() );
		$this->assertSame( [], $audit->offendingTables() );
		$this->assertSame( [], $audit->columnOverrides() );
		$this->assertSame( [], $audit->mixedCollations() );
		$this->assertSame( 'utf8mb4_unicode_ci', $audit->dominantCollation() );
	}

	public function test_non_utf8mb4_table_is_offending_grouped_by_charset(): void {
		$audit = $this->audit( [
			[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci' ],
			[ 'name' => 'wp_aryo_activity_log', 'collation' => 'utf8mb3_general_ci' ],
			[ 'name' => 'wp_legacy', 'collation' => 'latin1_swedish_ci' ],
		] );

		$this->assertFalse( $audit->clean() );
		$this->assertSame(
			[
				'latin1'  => [ 'wp_legacy' ],
				'utf8mb3' => [ 'wp_aryo_activity_log' ],
			],
			$audit->offendingTables()
		);
	}

	public function test_utf8_alias_counts_as_utf8mb3(): void {
		$audit = $this->audit( [
			[ 'name' => 'wp_old', 'collation' => 'utf8_general_ci' ],
		] );

		$this->assertSame( [ 'utf8mb3' => [ 'wp_old' ] ], $audit->offendingTables() );
	}

	public function test_column_collation_deviating_from_table_default_is_reported(): void {
		$audit = $this->audit(
			[ [ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci' ] ],
			[
				[ 'table_name' => 'wp_posts', 'column_name' => 'post_title', 'collation' => 'utf8mb4_unicode_ci' ],
				[ 'table_name' => 'wp_posts', 'column_name' => 'guid', 'collation' => 'utf8mb3_general_ci' ],
			]
		);

		$this->assertFalse( $audit->clean() );
		$this->assertSame(
			[ [ 'table' => 'wp_posts', 'column' => 'guid', 'collation' => 'utf8mb3_general_ci' ] ],
			$audit->columnOverrides()
		);
	}

	public function test_mixed_utf8mb4_collations_are_reported(): void {
		$audit = $this->audit( [
			[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci' ],
			[ 'name' => 'wp_options', 'collation' => 'utf8mb4_unicode_ci' ],
			[ 'name' => 'wp_straggler', 'collation' => 'utf8mb4_unicode_520_ci' ],
		] );

		$this->assertFalse( $audit->clean() );
		$this->assertSame(
			[
				'utf8mb4_unicode_520_ci' => [ 'wp_straggler' ],
				'utf8mb4_unicode_ci'     => [ 'wp_options', 'wp_posts' ],
			],
			$audit->mixedCollations()
		);
	}

	public function test_dominant_collation_is_majority_vote_over_utf8mb4_tables(): void {
		$audit = $this->audit( [
			[ 'name' => 'wp_a', 'collation' => 'utf8mb4_unicode_520_ci' ],
			[ 'name' => 'wp_b', 'collation' => 'utf8mb4_unicode_520_ci' ],
			[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci' ],
			[ 'name' => 'wp_old', 'collation' => 'utf8mb3_general_ci' ],
		] );

		$this->assertSame( 'utf8mb4_unicode_520_ci', $audit->dominantCollation() );
	}

	public function test_dominant_collation_tie_breaks_toward_core_tables(): void {
		$audit = $this->audit( [
			[ 'name' => 'wp_plugin_x', 'collation' => 'utf8mb4_unicode_520_ci' ],
			[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci' ],
		] );

		$this->assertSame( 'utf8mb4_unicode_ci', $audit->dominantCollation() );
	}

	public function test_dominant_collation_null_without_any_utf8mb4_table(): void {
		$audit = $this->audit( [
			[ 'name' => 'wp_old', 'collation' => 'utf8mb3_general_ci' ],
		] );

		$this->assertNull( $audit->dominantCollation() );
	}
}
