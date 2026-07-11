<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: wp_head/feeds emit the generator through the the_generator
 * filter — pushing a sentinel through it shows what would really be printed,
 * without depending on which layer emptied it.
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
		$emitted = apply_filters( 'the_generator', 'generator-sentinel', 'xhtml' );

		if ( '' === $emitted ) {
			return Result::good( __( 'The generator meta tag is empty — the WordPress version is not disclosed in markup or feeds.', 'timber-kit' ) );
		}

		return Result::recommended(
			__( 'The generator tag discloses the WordPress version. Enable the $remove_wp_generator flag in the project Base class.', 'timber-kit' )
		);
	}
}
