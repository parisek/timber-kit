<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check via anonymous loopback request — the only honest way to see
 * what an unauthenticated visitor gets from /wp/v2/users (an in-process REST
 * dispatch would inherit the admin's logged-in context). Site Health tests
 * run on demand in wp-admin, so the loopback cost is acceptable; core's own
 * tests do the same.
 */
final class RestUsersRestricted implements HealthCheck {

	public function id(): string {
		return 'rest_users_restricted';
	}

	public function label(): string {
		return __( 'REST users endpoint is restricted', 'timber-kit' );
	}

	public function category(): string {
		return 'security';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		$response = wp_remote_get( rest_url( 'wp/v2/users' ), array( 'timeout' => 5 ) );

		if ( is_wp_error( $response ) ) {
			return Result::recommended(
				__( 'Could not verify the REST users endpoint — the loopback request failed. Check loopback connectivity on this host.', 'timber-kit' )
			);
		}

		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return Result::critical(
				__( 'The REST users endpoint (/wp/v2/users) lists users to anonymous visitors — usernames are enumerable. Enable the $restrict_rest_users flag in the project Base class.', 'timber-kit' )
			);
		}

		return Result::good( __( 'The REST users endpoint rejects anonymous requests.', 'timber-kit' ) );
	}
}
