<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\Check\ResizerOutputFormatWritable;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\ImageFormatProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Health\HealthTestCase;

/**
 * Records the format it was handed and returns a fixed verdict, so the
 * judgement is asserted without a live image backend.
 */
final class FakeImageFormatProbe implements ImageFormatProbe {

	public ?string $seen = null;
	public int $calls     = 0;

	public function __construct( private readonly string $verdict = ImageFormatProbe::VERDICT_OK ) {
	}

	public function probe( string $format ): string {
		++$this->calls;
		$this->seen = $format;

		return $this->verdict;
	}
}

class ResizerOutputFormatWritableTest extends HealthTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
	}

	public function test_identity(): void {
		$check = new ResizerOutputFormatWritable( new FakeImageFormatProbe() );

		$this->assertSame( 'resizer_output_format_writable', $check->id() );
		$this->assertSame( 'performance', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	/**
	 * A delegate-free format short-circuits: no encode is burned to prove PNG
	 * works.
	 */
	public function test_delegate_free_format_passes_without_probing(): void {
		Filters\expectApplied( 'timber_kit_resizer_target_format' )->once()->andReturn( 'png' );

		$probe = new FakeImageFormatProbe();

		$this->assertSame( 'good', ( new ResizerOutputFormatWritable( $probe ) )->run()->status() );
		$this->assertSame( 0, $probe->calls );
	}

	/**
	 * A filter can return any casing; the short-circuit must still recognise
	 * the format.
	 */
	public function test_uppercase_format_from_the_filter_still_short_circuits(): void {
		Filters\expectApplied( 'timber_kit_resizer_target_format' )->once()->andReturn( 'PNG' );

		$probe = new FakeImageFormatProbe();

		$this->assertSame( 'good', ( new ResizerOutputFormatWritable( $probe ) )->run()->status() );
		$this->assertSame( 0, $probe->calls );
	}

	/**
	 * The check reads the filter, not the constant — a project on WebP must be
	 * told about WebP.
	 */
	public function test_probe_receives_the_filtered_format(): void {
		Filters\expectApplied( 'timber_kit_resizer_target_format' )->once()->andReturn( 'webp' );

		$probe = new FakeImageFormatProbe();
		( new ResizerOutputFormatWritable( $probe ) )->run();

		$this->assertSame( 'webp', $probe->seen );
	}

	public function test_default_format_is_avif_when_no_filter_changes_it(): void {
		// Brain\Monkey's apply_filters passes the default through untouched.
		$probe = new FakeImageFormatProbe();
		( new ResizerOutputFormatWritable( $probe ) )->run();

		$this->assertSame( 'avif', $probe->seen );
	}

	#[DataProvider( 'failing_verdicts' )]
	public function test_every_failure_verdict_is_critical( string $verdict ): void {
		$result = ( new ResizerOutputFormatWritable( new FakeImageFormatProbe( $verdict ) ) )->run();

		$this->assertSame( 'critical', $result->status() );
		$this->assertNotSame( '', $result->summary() );
		$this->assertNotSame( '', $result->actions(), 'A critical row without a remediation hint sends nobody anywhere.' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function failing_verdicts(): array {
		return array(
			'no backend'       => array( ImageFormatProbe::VERDICT_NO_BACKEND ),
			'missing delegate' => array( ImageFormatProbe::VERDICT_MISSING_DELEGATE ),
			'write failed'     => array( ImageFormatProbe::VERDICT_WRITE_FAILED ),
			'alpha lost'       => array( ImageFormatProbe::VERDICT_ALPHA_LOST ),
		);
	}

	/**
	 * Each verdict must produce its own wording — collapsing "no delegate" and
	 * "drops alpha" into one message sends an admin to the wrong fix.
	 */
	public function test_failure_summaries_are_distinct(): void {
		$summaries = array();
		foreach ( self::failing_verdicts() as $row ) {
			$summaries[] = ( new ResizerOutputFormatWritable( new FakeImageFormatProbe( $row[0] ) ) )->run()->summary();
		}

		$this->assertCount( count( $summaries ), array_unique( $summaries ) );
	}

	public function test_ok_verdict_is_good(): void {
		$result = ( new ResizerOutputFormatWritable( new FakeImageFormatProbe( ImageFormatProbe::VERDICT_OK ) ) )->run();

		$this->assertSame( 'good', $result->status() );
	}

	/**
	 * Fail loud, not silent: a verdict nobody wrote a branch for must never
	 * read as a pass.
	 */
	public function test_unknown_verdict_does_not_pass(): void {
		$result = ( new ResizerOutputFormatWritable( new FakeImageFormatProbe( 'something_new' ) ) )->run();

		$this->assertNotSame( 'good', $result->status() );
	}

	/**
	 * The default constructor must stay usable — StarterBase registers the
	 * check with no arguments.
	 */
	public function test_constructs_without_an_injected_probe(): void {
		$this->assertSame(
			'resizer_output_format_writable',
			( new ResizerOutputFormatWritable() )->id()
		);
	}
}
