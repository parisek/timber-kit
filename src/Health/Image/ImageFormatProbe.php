<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Image;

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
	 * The backend encoded the format but could not read its own output back,
	 * so transparency could not be verified.
	 *
	 * Encoding and decoding are separate capabilities in both Imagick and GD.
	 * A build that writes a format it cannot read is unusual but real, and it
	 * is NOT an encode failure: the resizer still produces a valid file. Saying
	 * "write failed" here would raise a false alarm about a working server,
	 * which is worse than saying nothing.
	 */
	public const VERDICT_UNVERIFIED = 'unverified';

	/**
	 * @param string $format Lower-case output format, e.g. `avif`.
	 * @return self::VERDICT_*
	 */
	public function probe( string $format ): string;

	/**
	 * Whether any image backend exists at all.
	 *
	 * Asked separately from probe() because "no backend" outranks the format
	 * question: on such a host nothing resizes, whatever the target format is,
	 * and a format that needs no delegate would otherwise report a clean bill
	 * of health for a backend that does not exist.
	 */
	public function hasBackend(): bool;
}
