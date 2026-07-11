<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\Check\Utf8mb4Tables;
use Parisek\TimberKit\Health\HealthCheck;
use Tests\Unit\Health\HealthTestCase;

class Utf8mb4TablesTest extends HealthTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * @param list<array<string, string>> $tables
	 * @param list<array<string, string>> $columns
	 */
	private function stubWpdb( array $tables, array $columns = [] ): void {
		$GLOBALS['wpdb'] = new class( $tables, $columns ) extends \wpdb {
			public string $prefix = 'wp_';

			public string $last_error = '';

			/** Simulates a query failure: real wpdb sets last_error DURING the query. */
			public string $error_on_query = '';

			public function __construct(
				private readonly array $tables,
				private readonly array $columns,
			) {
			}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, mixed ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( '' !== $this->error_on_query ) {
					$this->last_error = $this->error_on_query;
					return [];
				}
				return str_contains( $query, 'information_schema.COLUMNS' ) ? $this->columns : $this->tables;
			}
		};
	}

	public function test_identity(): void {
		$check = new Utf8mb4Tables();

		$this->assertSame( 'utf8mb4_tables', $check->id() );
		$this->assertSame( 'database', $check->category() );
		$this->assertSame( HealthCheck::METHOD_CONFIG, $check->method() );
	}

	public function test_good_when_all_tables_uniform_utf8mb4(): void {
		$this->stubWpdb( [
			[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci', 'row_format' => 'DYNAMIC' ],
			[ 'name' => 'wp_options', 'collation' => 'utf8mb4_unicode_ci', 'row_format' => 'DYNAMIC' ],
		] );

		$this->assertSame( 'good', ( new Utf8mb4Tables() )->run()->status() );
	}

	public function test_recommended_when_a_table_is_not_utf8mb4(): void {
		$this->stubWpdb( [
			[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci', 'row_format' => 'DYNAMIC' ],
			[ 'name' => 'wp_aryo_activity_log', 'collation' => 'utf8mb3_general_ci', 'row_format' => 'COMPACT' ],
		] );

		$result = ( new Utf8mb4Tables() )->run();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'wp_aryo_activity_log', $result->summary() );
		$this->assertStringContainsString( 'utf8mb4_unicode_ci', $result->summary() );
	}

	public function test_recommended_when_collations_are_mixed(): void {
		$this->stubWpdb( [
			[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci', 'row_format' => 'DYNAMIC' ],
			[ 'name' => 'wp_straggler', 'collation' => 'utf8mb4_unicode_520_ci', 'row_format' => 'DYNAMIC' ],
		] );

		$this->assertSame( 'recommended', ( new Utf8mb4Tables() )->run()->status() );
	}

	public function test_recommended_when_wpdb_unavailable(): void {
		unset( $GLOBALS['wpdb'] );

		$this->assertSame( 'recommended', ( new Utf8mb4Tables() )->run()->status() );
	}

	public function test_recommended_when_query_errors_instead_of_false_good(): void {
		$this->stubWpdb( [
			[ 'name' => 'wp_posts', 'collation' => 'utf8mb4_unicode_ci', 'row_format' => 'DYNAMIC' ],
		] );
		$GLOBALS['wpdb']->error_on_query = 'SELECT command denied';

		$result = ( new Utf8mb4Tables() )->run();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'inspect', $result->summary() );
	}

	public function test_recommended_when_no_tables_visible(): void {
		$this->stubWpdb( [] );

		$result = ( new Utf8mb4Tables() )->run();

		$this->assertSame( 'recommended', $result->status() );
	}
}
