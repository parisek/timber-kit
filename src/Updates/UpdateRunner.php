<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Updates;

class UpdateRunner {

	/** @var callable(): DiscoveryResult */
	private $discover;

	/** @var callable(string): void|null */
	private $logger;

	/**
	 * @param (callable(): DiscoveryResult)|null $discover
	 * @param (callable(string): void)|null      $logger
	 */
	public function __construct(
		?callable $discover = null,
		private readonly AppliedUpdatesRegistry $registry = new UpdateRegistry(),
		?callable $logger = null
	) {
		$this->discover = $discover ?? static fn (): DiscoveryResult => ( new UpdateDiscovery() )->discover();
		$this->logger   = $logger;
	}

	/**
	 * @return array{
	 *     executed: list<array{id: string, duration_ms: int, summary: mixed}>,
	 *     skipped: list<array{id: string, reason: string}>,
	 *     failed: list<array{id: string, message: string, duration_ms: int}>,
	 *     discovery_errors: list<string>
	 * }
	 */
	public function run( bool $dryRun = false, ?string $component = null, ?string $only = null ): array {
		$discovery = ( $this->discover )();
		$result    = [
			'executed'         => [],
			'skipped'          => [],
			'failed'           => [],
			'discovery_errors' => $discovery->errors,
		];

		if ( [] !== $discovery->errors ) {
			return $result;
		}

		$updates = $discovery->updates;
		usort(
			$updates,
			static fn ( array $a, array $b ): int => [ $a['component'], $a['number'] ] <=> [ $b['component'], $b['number'] ]
		);

		foreach ( $updates as $update ) {
			$skip_reason = $this->skipReason( $update, $component, $only );
			if ( null === $skip_reason && $this->registry->isApplied( $update['id'] ) ) {
				$skip_reason = 'applied';
			}

			if ( null !== $skip_reason ) {
				$result['skipped'][] = [ 'id' => $update['id'], 'reason' => $skip_reason ];
				continue;
			}

			$start = hrtime( true );
			try {
				$summary = $this->callUpdate( $update['run'], new UpdateContext( $dryRun ) );
			} catch ( \Throwable $throwable ) {
				$duration_ms = (int) round( ( hrtime( true ) - $start ) / 1_000_000 );
				$message     = sprintf( '%s failed: %s', $update['id'], $throwable->getMessage() );
				$this->log( $message );
				$result['failed'][] = [ 'id' => $update['id'], 'message' => $throwable->getMessage(), 'duration_ms' => $duration_ms ];
				break;
			}

			$duration_ms = (int) round( ( hrtime( true ) - $start ) / 1_000_000 );
			if ( ! $dryRun ) {
				$this->registry->markApplied( $update['id'], $duration_ms );
			}

			$result['executed'][] = [
				'id'          => $update['id'],
				'duration_ms' => $duration_ms,
				'summary'     => $summary,
			];
		}

		return $result;
	}

	/**
	 * @param array{id: string, component: string} $update
	 */
	private function skipReason( array $update, ?string $component, ?string $only ): ?string {
		if ( null !== $component && $update['component'] !== $component ) {
			return 'component';
		}

		if ( null !== $only && $update['id'] !== $only ) {
			return 'only';
		}

		return null;
	}

	private function callUpdate( callable $run, UpdateContext $context ): mixed {
		$reflection = \Closure::fromCallable( $run );
		$callable   = new \ReflectionFunction( $reflection );

		return 0 === $callable->getNumberOfParameters() ? $run() : $run( $context );
	}

	private function log( string $message ): void {
		if ( null !== $this->logger ) {
			( $this->logger )( $message );
			return;
		}

		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::warning( $message );
			return;
		}

		error_log( $message );
	}
}
