# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- `DevMediaProxy` for development environments with missing local uploads
- automatic activation from `StarterBase` via `TIMBERKIT_MEDIA_ORIGIN`
- domain-only media origins, e.g. `https://example.com`, with automatic reuse of the local uploads path
- Resizer integration through `timber_kit_resizer_missing_source_variants` for remote variant fallback
- Composer scripts for `composer test` and `composer phpstan`

### Changed
- Resizer missing-source handling now delegates to a filter so dev media behavior stays isolated in `DevMediaProxy`

### Fixed
- Media Library JS payload rewriting now covers nested `sizes[*].url` and `icon`
- DevMediaProxy hardens missing-file resolution against traversal-style relative paths
- configured media origins strip URL userinfo before rendering rewritten URLs
- remote Resizer variant probing is bounded per request and configurable via filter

## [1.0.4] - 2026-04-07

### Added
- ACF JSON save/load support for `user_form` location rule — field groups assigned to user forms are saved to `templates/user/`

## [1.0.0] - 2026-03-21

### Added
- `StarterBase` — WordPress/Timber base class with 25 configurable properties and 45+ methods
- `Helpers` — ACF field formatting, menu helpers, image/link/video formatters
- `Resizer` — Image resizing via Spatie/Image with AVIF support
- Security & cleanup: wp_head cleanup, XML-RPC disable, emoji removal, feed/comment disable
- Media processing: filename sanitization, upload resize (replaces clean-image-filenames + imsanity plugins)
- Gutenberg: align-wide, responsive-embeds, editor-styles, disable core patterns
- Editor role enhancements: login redirect, WPML translate, privacy page cap
- REST API users endpoint protection
- 293 unit tests, PHPStan level 5
- DDEV config for standalone development (PHP 8.3, no DB)
