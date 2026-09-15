<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use Parisek\TimberKit\Cli\RescaleOriginalsCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rows over one file are rewritten together, so the command skips the rest of
 * a group once one row settled it. A row that settled nothing must not skip
 * its siblings: their metadata can differ (WPML syncs the file, not the
 * metadata), so the next row may still be viable.
 */
class RescaleOriginalsCommandDedupeTest extends TestCase {

	/** @return array<string, array{string, bool}> */
	public static function statuses(): array {
		return [
			'restored'      => [ 'restored', true ],
			'rescaled'      => [ 'rescaled', true ],
			'would_restore' => [ 'would_restore', true ],
			'would_rescale' => [ 'would_rescale', true ],
			'unchanged'     => [ 'unchanged', true ],
			'no_original'   => [ 'no_original', false ],
			'not_scaled'    => [ 'not_scaled', false ],
			'missing'       => [ 'missing', false ],
			'failed'        => [ 'failed', false ],
		];
	}

	#[DataProvider( 'statuses' )]
	public function test_only_a_settled_row_settles_its_file( string $status, bool $settles ): void {
		$this->assertSame( $settles, RescaleOriginalsCommand::settlesFile( $status ) );
	}
}
