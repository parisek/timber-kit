<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: wp_head/feeds emit the generator through the the_generator
 * filter — running the real generator markup through it and looking for the
 * actual version string shows what a visitor would really see, without
 * depending on which layer emptied or rewrote it.
 */
final class WpVersionHidden implements HealthCheck {

	public function id(): string {
		return 'wp_version_hidden';
	}

	public function label(): string {
		return __( 'WordPress version is hidden', 'timber-kit' );
	}

	public function category(): string {
		return 'security';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		$emitted = apply_filters( 'the_generator', get_the_generator( 'xhtml' ), 'xhtml' );
		$version = get_bloginfo( 'version' );

		if ( is_string( $emitted ) && '' !== $version && str_contains( $emitted, $version ) ) {
			return Result::recommended(
				__( 'The generator tag discloses the WordPress version. Enable the $remove_wp_generator flag in the project Base class.', 'timber-kit' )
			);
		}

		return Result::good( __( 'The emitted generator output does not contain the WordPress version.', 'timber-kit' ) );
	}
}
