<?php

declare(strict_types=1);

// Global-namespace stand-in for WPML's resolver of the per-page translation
// editor (sitepress-multilingual-cms, classes/post-edit-screen). It carries
// only get_editor_settings(), the one method WPML 4.9 and WPML 5 both ship:
// uses_native_editor() exists only from WPML 5, and calling it on 4.9 is a
// fatal error. Tests set $settings and read $calls.

if ( ! class_exists( 'WPML_TM_Post_Edit_TM_Editor_Mode' ) ) {
	// phpcs:ignore
	class WPML_TM_Post_Edit_TM_Editor_Mode {

		/** @var array<int, array{0: bool, 1: bool}> post id → [ uses a WPML editor, WPML editor blocked ] */
		public static array $settings = [];

		/** @var array<int, int> post id → number of calls */
		public static array $calls = [];

		public static function get_editor_settings( $deprecated, $post_id ) {
			self::$calls[ $post_id ] = ( self::$calls[ $post_id ] ?? 0 ) + 1;
			[ $use_tm, $blocked ] = self::$settings[ $post_id ] ?? [ true, false ];
			return [ $use_tm, $blocked, '' ];
		}
	}
}
