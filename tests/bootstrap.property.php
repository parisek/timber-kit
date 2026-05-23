<?php

declare(strict_types=1);

// Load shared stubs (WP_Error, WP_Query, WP_Post, WP_Term, wp_strip_all_tags, etc.).
// This file is NOT loaded through Patchwork's preprocessor, so we must define
// apply_filters here — AFTER the shared bootstrap — rather than in bootstrap.php,
// where Patchwork would complain it was defined before it had a chance to
// instrument calls to the function.
require_once __DIR__ . '/bootstrap.php';

// Plain pass-through stub for `apply_filters` so Property tests (which
// don't set up Brain\Monkey) can instantiate Resizer without a fatal.
// Brain\Monkey's Patchwork-based interception intercepts call sites that
// are preprocessed; since Property tests never call Brain\Monkey setup,
// Patchwork is never activated and this plain stub is sufficient.
// The Unit suite uses bootstrap.php (without this stub) so there is no
// DefinedTooEarly conflict for that suite.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}
