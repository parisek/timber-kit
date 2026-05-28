<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

final class Fixtures {

	public static function load( string $name ): array {
		$path = __DIR__ . '/' . $name . '.json';
		if ( ! file_exists( $path ) ) {
			throw new \RuntimeException( "Missing fixture: $path" );
		}
		$contents = file_get_contents( $path );
		if ( $contents === false ) {
			throw new \RuntimeException( "Cannot read fixture: $path" );
		}
		return json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	}
}
