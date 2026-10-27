<?php

declare(strict_types=1);

// Global-namespace stand-in for WPML's resolver of the per-page translation
// editor (sitepress-multilingual-cms, classes/post-edit-screen). Only the one
// static method the override calls exists. Tests set $native and read $calls.

if ( ! class_exists( 'WPML_TM_Post_Edit_TM_Editor_Mode' ) ) {
	// phpcs:ignore
	class WPML_TM_Post_Edit_TM_Editor_Mode {

		/** @var array<int, bool> post id → uses the WordPress editor */
		public static array $native = [];

		/** @var array<int, int> post id → number of calls */
		public static array $calls = [];

		public static function uses_native_editor( $post_id ) {
			self::$calls[ $post_id ] = ( self::$calls[ $post_id ] ?? 0 ) + 1;
			return self::$native[ $post_id ] ?? false;
		}
	}
}
