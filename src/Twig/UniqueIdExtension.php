<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig function `uniqueId()`: an HTML id that is unique within one render.
 *
 * Components use it where a loop index would repeat across instances
 * (accordion, dialog, faq, footer). It started in `parisek/twig-common`,
 * which the kit no longer requires.
 *
 * The id starts with a letter, because an HTML id that starts with a digit is
 * awkward to target in CSS selectors.
 */
final class UniqueIdExtension extends AbstractExtension {

	/** @var array<string, true> Ids handed out so far, keyed for O(1) lookup. */
	private array $issued = array();

	/**
	 * @return list<TwigFunction>
	 */
	public function getFunctions(): array {
		return array(
			new TwigFunction( 'uniqueId', array( $this, 'getUniqueId' ) ),
		);
	}

	/**
	 * @return string One letter followed by six hex digits.
	 */
	public function getUniqueId(): string {
		do {
			$id = chr( random_int( 97, 122 ) ) . bin2hex( random_bytes( 3 ) );
		} while ( isset( $this->issued[ $id ] ) );

		$this->issued[ $id ] = true;

		return $id;
	}
}
