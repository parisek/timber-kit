<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Updates;

class UpdateDiscovery {

	/**
	 * Discover update files from the active stylesheet directory and the
	 * `timberkit_update_paths` filter.
	 */
	public function discover(): DiscoveryResult {
		$theme_dir = get_stylesheet_directory();
		$patterns  = $this->defaultPatterns( (string) $theme_dir );

		$patterns = apply_filters( 'timberkit_update_paths', $patterns );
		if ( ! is_array( $patterns ) ) {
			$patterns = [];
		}

		return $this->discoverPatterns(
			array_values( array_filter( array_map( 'strval', $patterns ) ) ),
			(string) $theme_dir
		);
	}

	public function discoverInTheme( string $theme_dir ): DiscoveryResult {
		return $this->discoverPatterns( $this->defaultPatterns( $theme_dir ), $theme_dir );
	}

	/**
	 * @param list<string> $patterns
	 */
	public function discoverPatterns( array $patterns, ?string $theme_dir = null ): DiscoveryResult {
		$files = [];
		foreach ( $patterns as $pattern ) {
			$matches = glob( $pattern );
			if ( false === $matches ) {
				continue;
			}
			foreach ( $matches as $path ) {
				if ( is_file( $path ) ) {
					$files[] = $path;
				}
			}
		}

		sort( $files, SORT_STRING );

		$updates = [];
		$errors  = [];
		$seen    = [];

		foreach ( $files as $path ) {
			$meta = $this->parsePath( $path, $theme_dir );
			if ( null === $meta ) {
				continue;
			}

			$id = $meta['component'] . ':' . $meta['number_padded'];
			if ( isset( $seen[ $id ] ) ) {
				$errors[] = sprintf( 'Duplicate update id %s: %s and %s', $id, $seen[ $id ], $path );
				continue;
			}
			$seen[ $id ] = $path;

			try {
				$returned = require $path;
			} catch ( \Throwable $throwable ) {
				$errors[] = sprintf( 'Malformed update file %s: %s', $path, $throwable->getMessage() );
				continue;
			}
			if (
				! is_array( $returned )
				|| ! isset( $returned['description'], $returned['run'] )
				|| ! is_string( $returned['description'] )
				|| ! is_callable( $returned['run'] )
			) {
				$errors[] = sprintf( 'Malformed update file %s: expected description string and run callable.', $path );
				continue;
			}

			$updates[] = [
				'id'          => $id,
				'component'   => $meta['component'],
				'number'      => $meta['number'],
				'description' => $returned['description'],
				'path'        => $path,
				'run'         => $returned['run'],
			];
		}

		usort(
			$updates,
			static fn ( array $a, array $b ): int => [ $a['component'], $a['number'] ] <=> [ $b['component'], $b['number'] ]
		);

		return new DiscoveryResult( $updates, $errors );
	}

	/**
	 * @return list<string>
	 */
	private function defaultPatterns( string $theme_dir ): array {
		$theme_dir = rtrim( $theme_dir, '/' );

		return [
			$theme_dir . '/updates/*.php',
			$theme_dir . '/templates/component/*/updates/*.php',
			$theme_dir . '/static/templates/component/*/updates/*.php',
		];
	}

	/**
	 * @return array{component: string, number: int, number_padded: string}|null
	 */
	private function parsePath( string $path, ?string $theme_dir ): ?array {
		$filename = basename( $path );
		if ( 1 !== preg_match( '/^(\d{4})-[a-z0-9][a-z0-9-]*\.php$/', $filename, $matches ) ) {
			return null;
		}

		$updates_dir = dirname( $path );
		$parent      = dirname( $updates_dir );
		$component   = basename( $parent );

		if ( null !== $theme_dir && rtrim( $parent, '/' ) === rtrim( $theme_dir, '/' ) ) {
			$component = 'theme';
		}

		return [
			'component'     => $component,
			'number'        => (int) $matches[1],
			'number_padded' => $matches[1],
		];
	}
}
