<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Acfml;

/**
 * Reconciliation plan for WPML's `custom_fields_translation` dictionary.
 *
 * WPML packs custom fields into translation jobs by exact meta-key lookup
 * against that dictionary, but ACFML materialises entries only event-driven
 * (`acf/updated_field`, `acf/update_value`) — meta written programmatically
 * (importers, WPML duplication, direct writes) never gets entries and is
 * silently excluded from jobs. This class computes the deterministic patch:
 * for each meta key with an ACF `_<key>` field-key companion, the exact key
 * is registered with the field definition's `wpml_cf_preferences`.
 *
 * Pure accumulation logic — the field resolver (normally `acf_get_field()`)
 * is injected, and the current dictionary is passed into {@see patch()}, so
 * the class needs no WordPress at all.
 */
class PreferenceSyncPlan {

	public const PREF_IGNORE    = 0;
	public const PREF_COPY      = 1;
	public const PREF_TRANSLATE = 2;
	public const PREF_COPY_ONCE = 3;

	/**
	 * Desired preference per exact meta key, keys in first-seen order.
	 *
	 * @var array<string, int>
	 */
	private array $registrations = [];

	/**
	 * Keys observed with more than one distinct preference: key => prefs seen.
	 *
	 * @var array<string, list<int>>
	 */
	private array $conflicts = [];

	/**
	 * Keys whose field definition is missing or lacks `wpml_cf_preferences`.
	 *
	 * @var list<string>
	 */
	private array $unresolvable = [];

	/**
	 * Memoised resolver results per field key (null = unresolvable).
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $resolved = [];

	/**
	 * @param \Closure(string): (array<string, mixed>|null) $resolveField Field definition by ACF field key.
	 */
	public function __construct( private \Closure $resolveField ) {
	}

	/**
	 * Accumulate one object's meta (as returned by `get_post_meta( $id )`).
	 *
	 * @param array<string, array<int, mixed>> $meta Meta key => list of values.
	 * @return void
	 */
	public function collect( array $meta ): void {
		foreach ( $meta as $key => $values ) {
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}

			$field_key = isset( $meta[ '_' . $key ][0] ) ? (string) $meta[ '_' . $key ][0] : '';
			if ( ! str_starts_with( $field_key, 'field_' ) ) {
				continue;
			}

			if ( ! array_key_exists( $field_key, $this->resolved ) ) {
				$this->resolved[ $field_key ] = ( $this->resolveField )( $field_key );
			}
			$field = $this->resolved[ $field_key ];

			if ( null === $field || ! isset( $field['wpml_cf_preferences'] ) ) {
				if ( ! in_array( $key, $this->unresolvable, true ) ) {
					$this->unresolvable[] = $key;
				}
				continue;
			}

			$this->register( $key, (int) $field['wpml_cf_preferences'] );
		}
	}

	/**
	 * Entries to write, given the current dictionary — empty when in sync.
	 *
	 * Value keys are included when missing or holding a different preference;
	 * `_<key>` companions only when absent (an existing companion entry, e.g.
	 * Copy-once from ACFML's localization mode, is never overwritten).
	 * Conflicted keys are excluded entirely — never guessed.
	 *
	 * @param array<string, int|string> $current Current `custom_fields_translation` map.
	 * @return array<string, int> Meta key => preference to write.
	 */
	public function patch( array $current ): array {
		$patch = [];

		foreach ( $this->registrations as $key => $pref ) {
			if ( isset( $this->conflicts[ $key ] ) ) {
				continue;
			}

			if ( ! isset( $current[ $key ] ) || (int) $current[ $key ] !== $pref ) {
				$patch[ $key ] = $pref;
			}

			$companion = '_' . $key;
			if ( ! isset( $current[ $companion ] ) ) {
				$patch[ $companion ] = self::PREF_COPY;
			}
		}

		return $patch;
	}

	/**
	 * @return array{
	 *     registered_by_preference: array<int, int>,
	 *     conflicts: array<string, list<int>>,
	 *     unresolvable: list<string>,
	 * }
	 */
	public function summary(): array {
		$by_preference = [];
		foreach ( $this->registrations as $key => $pref ) {
			if ( isset( $this->conflicts[ $key ] ) ) {
				continue;
			}
			$by_preference[ $pref ] = ( $by_preference[ $pref ] ?? 0 ) + 1;
		}

		return [
			'registered_by_preference' => $by_preference,
			'conflicts'                => $this->conflicts,
			'unresolvable'             => $this->unresolvable,
		];
	}

	private function register( string $key, int $pref ): void {
		if ( isset( $this->conflicts[ $key ] ) ) {
			if ( ! in_array( $pref, $this->conflicts[ $key ], true ) ) {
				$this->conflicts[ $key ][] = $pref;
			}
			return;
		}

		if ( isset( $this->registrations[ $key ] ) && $this->registrations[ $key ] !== $pref ) {
			$this->conflicts[ $key ] = [ $this->registrations[ $key ], $pref ];
			return;
		}

		$this->registrations[ $key ] = $pref;
	}
}
