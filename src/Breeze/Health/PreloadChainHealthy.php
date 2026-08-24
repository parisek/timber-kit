<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Breeze\Health;

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

		// An unreadable queue is not evidence of a stalled chain — coerce to
		// empty rather than invent a problem this check cannot substantiate.
		$queue = is_array( $queue ) ? $queue : array();

		if ( array() === $queue ) {
			return Result::good( __( 'Preload queue is empty.', 'timber-kit' ) );
		}

		$last_warm = (int) get_option( 'breeze_preload_last_warm', 0 );

		// A missing/zero timestamp is "never warmed", not "warmed 1.79 billion
		// seconds ago" — an elapsed-time figure computed against epoch zero is
		// nonsense an admin cannot act on, so it gets its own wording instead.
		if ( 0 === $last_warm ) {
			return Result::critical(
				sprintf(
					/* translators: %d: number of URLs waiting to be warmed. */
					__( '%d URL(s) are queued, but the preload chain has never run.', 'timber-kit' ),
					count( $queue )
				),
				__( 'The Action Scheduler loopback never completed a warm. Check that the site can reach its own public URL.', 'timber-kit' )
			);
		}

		$idle = time() - $last_warm;

		// A negative $idle means $last_warm is in the future — clock skew, not
		// a stalled chain. Reading that as "just warmed" is deliberate: skew
		// is not evidence of a fault, and this check must not alarm an
		// administrator over something it cannot substantiate.
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
