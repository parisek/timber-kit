<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Config check: DISALLOW_FILE_EDIT has no probeable runtime effect short of
 * loading the theme editor, so the constant itself is the source of truth.
 */
final class FileEditingDisabled implements HealthCheck {

	public function id(): string {
		return 'file_editing_disabled';
	}

	public function label(): string {
		return __( 'Plugin/theme file editing is disabled', 'timber-kit' );
	}

	public function category(): string {
		return 'security';
	}

	public function method(): string {
		return self::METHOD_CONFIG;
	}

	public function run(): Result {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return Result::good( __( 'DISALLOW_FILE_EDIT is set — the wp-admin code editors are off.', 'timber-kit' ) );
		}

		return Result::recommended(
			__( 'DISALLOW_FILE_EDIT is not set; wp-admin can edit PHP files. Enable the $disable_file_editing flag in the project Base class or define the constant in wp-config.php.', 'timber-kit' )
		);
	}
}
