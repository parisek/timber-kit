# timber-kit

WordPress/Timber starter kit — configurable base class, ACF helpers, image resizer.

## Installation

```bash
composer require parisek/timber-kit
```

## What's Included

### StarterBase

Extends `Timber\Site` with 25 configurable properties. Handles theme setup, Twig extensions, security hardening, Gutenberg blocks, media processing, and admin cleanup — all opt-in via boolean flags.

### Helpers

Static methods for formatting ACF data into clean arrays for Twig templates:

- `formatImage()`, `formatFile()`, `formatVideo()` — media formatting
- `formatFields()`, `fieldFormatter()` — ACF field processing
- `formatLink()` — link/button formatting
- `formatMenu()` — navigation menus
- `formatTerms()` — taxonomy terms
- `formatLanguageSwitcher()` — WPML language switcher
- `resizeImage()` — responsive image variants
- `pagination()` — pagination formatting

### Resizer

Image resizing via [Spatie/Image](https://github.com/spatie/image). AVIF output, responsive variants with breakpoints, crop positions, and cache management. Used as a Twig filter.

## Usage

Create a `Base` class in your theme that extends `StarterBase`:

```php
<?php

use Parisek\TimberKit\StarterBase;
use Parisek\TimberKit\Helpers;

class Base extends StarterBase {

    public function __construct() {
        $this->menus = [
            'main-menu' => 'Main Menu',
            'footer-menu' => 'Footer Menu',
        ];
        $this->font_stylesheets = [
            'poppins' => 'fonts/poppins/stylesheet.css',
        ];
        $this->disable_search = false;

        parent::__construct();
    }
}
```

## Configuration

Override these properties in your child constructor before calling `parent::__construct()`:

### Theme

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$menus` | array | `[]` | Registered navigation menus |
| `$font_stylesheets` | array | `[]` | CSS files to enqueue |
| `$preload_fonts` | array | `[]` | Font files to preload |
| `$search_post_types` | array | `['post']` | Post types for search |
| `$article_post_types` | array | `['post']` | Post types treated as articles |
| `$block_category` | array | `['slug' => 'custom', 'title' => 'Custom']` | Custom block category |
| `$favicon_path` | string | `'images/touch/favicon.svg'` | Favicon path |

### Security & Cleanup

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$cleanup_wp_head` | bool | `true` | Remove unnecessary wp_head output |
| `$disable_xmlrpc` | bool | `true` | Disable XML-RPC |
| `$disable_emojis` | bool | `true` | Remove emoji scripts/styles |
| `$disable_feeds` | bool | `true` | Disable RSS feeds |
| `$disable_comments` | bool | `true` | Disable comments |
| `$disable_search` | bool | `true` | Disable search |
| `$cleanup_dashboard` | bool | `true` | Remove dashboard widgets |
| `$cleanup_admin_bar` | bool | `true` | Clean up admin bar |
| `$editor_role_enhancements` | bool | `true` | Enhanced editor role caps |
| `$disable_self_pingbacks` | bool | `true` | Disable self-pingbacks |
| `$restrict_rest_users` | bool | `true` | Protect REST API users endpoint |

### Media Processing

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$clean_image_filenames` | bool | `true` | Sanitize uploaded filenames |
| `$max_upload_width` | int | `2560` | Max upload image width (px) |
| `$max_upload_height` | int | `2560` | Max upload image height (px) |

### Gutenberg

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `$gutenberg_align_wide` | bool | `true` | Enable wide/full alignment |
| `$gutenberg_responsive_embeds` | bool | `true` | Responsive video embeds |
| `$gutenberg_editor_styles` | bool | `true` | Load editor stylesheet |
| `$gutenberg_disable_core_patterns` | bool | `true` | Remove core block patterns |

## Testing

```bash
ddev start
ddev exec "vendor/bin/phpunit"
ddev exec "vendor/bin/phpstan analyse"
```

## License

[GPL-3.0-or-later](LICENSE)
