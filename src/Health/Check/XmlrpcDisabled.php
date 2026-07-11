<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: verifies the effective xmlrpc_enabled filter value, so it
 * holds regardless of whether the kit flag, a WAF, or another plugin did
 * the disabling.
 */
final class XmlrpcDisabled implements HealthCheck {

	public function id(): string {
		return 'xmlrpc_disabled';
	}

	public function label(): string {
		return __( 'XML-RPC is disabled', 'timber-kit' );
	}

	public function category(): string {
		return 'security';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		if ( false === apply_filters( 'xmlrpc_enabled', true ) ) {
			return Result::good( __( 'XML-RPC authenticated methods are disabled.', 'timber-kit' ) );
		}

		return Result::recommended(
			__( 'XML-RPC is enabled. Porta recommends disabling it unless a service depends on it — enable the $disable_xmlrpc flag in the project Base class.', 'timber-kit' )
		);
	}
}
