<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health;

/**
 * Answers one question about the live server: can it write this image format,
 * and does the written file still carry an alpha channel?
 *
 * Separate from the check that consumes it for two reasons. The check is
 * `final` like every check in the registry, so an injected collaborator is the
 * only testable seam. And the question is not Site-Health-specific — a CLI
 * fleet sweep wants the same verdict without wp-admin, the same way
 * SiteHealthAdapter keeps the registry framework-agnostic.
 */
interface ImageFormatProbe {

	/** The backend writes the format and transparency survives the round trip. */
	public const VERDICT_OK = 'ok';

	/** No usable image backend at all — neither Imagick nor GD. */
	public const VERDICT_NO_BACKEND = 'no_backend';

	/** The backend does not know the format; the delegate is absent from the build. */
	public const VERDICT_MISSING_DELEGATE = 'missing_delegate';

	/** The backend lists the format but refuses to encode it — decoder only. */
	public const VERDICT_WRITE_FAILED = 'write_failed';

	/** The backend encodes the format but flattens transparency. */
	public const VERDICT_ALPHA_LOST = 'alpha_lost';

	/**
	 * @param string $format Lower-case output format, e.g. `avif`.
	 * @return self::VERDICT_*
	 */
	public function probe( string $format ): string;
}
