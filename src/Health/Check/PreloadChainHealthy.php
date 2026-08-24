<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: Breeze drives its preload queue through an Action Scheduler
 * loopback. When that loopback cannot reach the site, the queue simply stops
 * advancing — nothing anywhere reports it, so the site just stays cold. This
 * check watches the queue's own progress to surface that silent failure.
 */
final class PreloadChainHealthy implements HealthCheck {

	/** @var int Seconds of no progress after which the chain counts as stalled. */
	private const STALL_AFTER = 60;

	public function id(): string {
		return 'preload_chain_healthy';
	}

	public function label(): string {
		return __( 'Preload chain is moving', 'timber-kit' );
	}

	public function category(): string {
		return 'caching';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		$queue = get_option( 'breeze_preload_queue', array() );
		$queue = is_array( $queue ) ? $queue : array();

		if ( array() === $queue ) {
			return Result::good( __( 'Preload queue is empty.', 'timber-kit' ) );
		}

		$last_warm = (int) get_option( 'breeze_preload_last_warm', 0 );
		$idle      = time() - $last_warm;

		if ( $idle <= self::STALL_AFTER ) {
			return Result::good(
				sprintf(
					/* translators: %d: number of URLs still waiting to be warmed. */
					__( '%d URL(s) left to warm; the chain is moving.', 'timber-kit' ),
					count( $queue )
				)
			);
		}

		return Result::critical(
			sprintf(
				/* translators: 1: number of URLs still waiting to be warmed. 2: seconds since the last warm. */
				__( '%1$d URL(s) still unwarmed; last warm %2$d seconds ago.', 'timber-kit' ),
				count( $queue ),
				$idle
			),
			__( 'The Action Scheduler loopback appears to be stalled. Check that the site can reach its own public URL.', 'timber-kit' )
		);
	}
}
