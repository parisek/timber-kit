# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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
