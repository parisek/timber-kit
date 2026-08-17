<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;

/**
 * Config check: the theme configures its own GTM container while the GTM4WP
 * plugin is still set to print one.
 *
 * Both would load, every visit would be counted twice, and a doubling reads
 * as growth rather than as a fault — so nothing on the site complains. The
 * check exists because that is the one state a developer cannot see.
 *
 * It is deliberately a check and not a render-time guard. Reading another
 * plugin's stored settings is a guess about a schema this kit does not own;
 * a wrong guess here shows a wrong line in Site Health, while the same wrong
 * guess in the loader would silently stop measurement. Diagnose loudly,
 * never suppress.
 */
final class GtmContainerNotDuplicated implements HealthCheck {

	/** Plugin's placement value for "do not print the container". */
	private const PLACEMENT_OFF = 3;

	/** @var bool Whether the theme configures a container of its own. */
	private bool $kit_configured;

	public function __construct( bool $kit_configured ) {
		$this->kit_configured = $kit_configured;
	}

	public function id(): string {
		return 'gtm_container_not_duplicated';
	}

	public function label(): string {
		return __( 'Google Tag Manager loads exactly once', 'timber-kit' );
	}

	public function category(): string {
		return 'timber-kit';
	}

	public function method(): string {
		return self::METHOD_CONFIG;
	}

	public function run(): Result {
		if ( ! $this->kit_configured ) {
			return Result::good( __( 'The theme configures no container of its own, so GTM4WP is the only possible source.', 'timber-kit' ) );
		}

		if ( ! defined( 'GTM4WP_VERSION' ) ) {
			return Result::good( __( 'The theme configures the container and GTM4WP is not active.', 'timber-kit' ) );
		}

		if ( ! $this->gtm4wp_prints_a_container() ) {
			return Result::good( __( 'The theme configures the container and GTM4WP is set not to print one.', 'timber-kit' ) );
		}

		return Result::critical(
			__( 'Google Tag Manager loads twice: the theme configures a container and GTM4WP is also printing one. Every visit, event and conversion is counted twice, which looks like growth rather than a fault.', 'timber-kit' ),
			__( 'Set the plugin to Container code placement: OFF, or deactivate it if nothing on this site uses its data layer.', 'timber-kit' )
		);
	}

	/**
	 * Whether GTM4WP would print a container on the frontend.
	 *
	 * The plugin numbers its placements footer=0, body-open=1,
	 * body-open-auto=2, off=3 — "off" is the highest value, not the falsy
	 * one, and an absent setting means footer, the plugin's own default. Its
	 * container ID can also come from `GTM4WP_HARDCODED_GTM_ID` rather than
	 * from the stored option, which is how a site keeps the ID out of its
	 * database.
	 *
	 * @return bool
	 */
	private function gtm4wp_prints_a_container(): bool {
		$options = get_option( 'gtm4wp-options' );
		$options = is_array( $options ) ? $options : array();

		$id = defined( 'GTM4WP_HARDCODED_GTM_ID' )
			? (string) constant( 'GTM4WP_HARDCODED_GTM_ID' )
			: (string) ( $options['gtm-code'] ?? '' );

		if ( '' === trim( $id ) ) {
			return false;
		}

		$off = defined( 'GTM4WP_PLACEMENT_OFF' )
			? (int) constant( 'GTM4WP_PLACEMENT_OFF' )
			: self::PLACEMENT_OFF;

		$placement = array_key_exists( 'gtm-code-placement', $options )
			? (int) $options['gtm-code-placement']
			: 0;

		return $placement !== $off;
	}
}
