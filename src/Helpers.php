<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

use Timber\Term;
use Timber\Timber;
use Timber\ImageHelper;

class Helpers {

	public static function formatImage( $image, $post_id = null, $field = null ) {

		$data = [];

		// if we have multivalue field eg. gallery
		if ( is_countable( $image ) && ! Helpers::isAssoc( $image ) ) {
			$items = [];
			foreach ( $image as $item ) {
				$data = Helpers::formatImage( $item );
				if ( $data ) {
					$items[] = $data;
				}
			}
			return $items;
		}

		if ( is_object( $image ) ) {
			// fixed weird bug when image/svg+xml is sometimes width 1px / height 1px
			// https://core.trac.wordpress.org/ticket/26256
			$width = ( ! empty( $image->width ) && $image->width > 1 ) ? $image->width : null;
			$height = ( ! empty( $image->height ) && $image->height > 1 ) ? $image->height : null;
			$data[] = [
				'id' => $image->ID,
				'src' => $image->src,
				'type' => $image->post_mime_type,
				'width' => $width,
				'height' => $height,
				'alt' => $image->alt,
				'caption' => $image->caption,
				'description' => $image->description,
			];
		} elseif ( is_array( $image ) ) {
			// fixed weird bug when image/svg+xml is sometimes width 1px / height 1px
			// https://core.trac.wordpress.org/ticket/26256
			$width = ( ! empty( $image['width'] ) && $image['width'] > 1 ) ? $image['width'] : null;
			$height = ( ! empty( $image['height'] ) && $image['height'] > 1 ) ? $image['height'] : null;
			$data[] = [
				'id' => isset( $image['ID'] ) ? $image['ID'] : null,
				'src' => $image['url'],
				'type' => $image['mime_type'],
				'width' => $width,
				'height' => $height,
				'alt' => $image['alt'],
				'caption' => $image['caption'],
				'description' => $image['description'],
			];
		} elseif ( is_numeric( $image ) ) {
			$image = acf_get_attachment( $image );
			if ( $image ) {
				$data[] = [
					'id' => isset( $image['ID'] ) ? $image['ID'] : null,
					'src' => $image['url'],
					'type' => $image['mime_type'],
					'width' => $image['width'],
					'height' => $image['height'],
					'alt' => $image['alt'],
					'caption' => $image['caption'],
					'description' => $image['description'],
				];
			}
		} elseif ( filter_var( $image, FILTER_VALIDATE_URL ) ) {
			$image = attachment_url_to_postid( $image );
			$image = acf_get_attachment( $image );
			if ( $image ) {
				$data[] = [
					'id' => isset( $image['ID'] ) ? $image['ID'] : null,
					'src' => $image['url'],
					'type' => $image['mime_type'],
					'width' => $image['width'],
					'height' => $image['height'],
					'alt' => $image['alt'],
					'caption' => $image['caption'],
					'description' => $image['description'],
				];
			}
		}

		return $data;
	}

	public static function formatFile( $file, $post_id = null, $field = null ) {
		$attachment = null;

		if ( is_object( $file ) ) {
			$attachment = [
				'ID' => $file->ID ?? null,
				'url' => $file->src ?? '',
				'mime_type' => $file->post_mime_type ?? '',
				'subtype' => $file->subtype ?? '',
				'filename' => $file->filename ?? '',
				'filesize' => $file->filesize ?? '',
				'alt' => $file->alt ?? '',
				'caption' => $file->caption ?? '',
				'description' => $file->description ?? '',
			];
		} elseif ( is_array( $file ) ) {
			$attachment = $file;
		} elseif ( is_numeric( $file ) ) {
			$attachment = acf_get_attachment( $file );
		} elseif ( is_string( $file ) && filter_var( $file, FILTER_VALIDATE_URL ) ) {
			$id = attachment_url_to_postid( $file );
			if ( $id ) {
				$attachment = acf_get_attachment( $id );
			}
		}

		if ( empty( $attachment ) || ! is_array( $attachment ) ) {
			return '';
		}

		$raw_size = $attachment['filesize'] ?? '';
		$filesize_formatted = is_numeric( $raw_size ) ? size_format( $raw_size ) : '';

		$preview = [];
		if ( ( $attachment['mime_type'] ?? '' ) === 'application/pdf' && ! empty( $attachment['ID'] ) ) {
			$image_src_data = wp_get_attachment_image_src( (int) $attachment['ID'], 'full', false );
			if ( $image_src_data ) {
				list( $img_src, $img_width, $img_height ) = $image_src_data;
				$preview = Helpers::formatImage( [
					'id' => null, // this image is not tracked in WP media library just as file metadata value
					'url' => $img_src,
					'mime_type' => 'image/jpeg',
					'width' => $img_width,
					'height' => $img_height,
					'alt' => $attachment['alt'] ?? '',
					'caption' => $attachment['caption'] ?? '',
					'description' => $attachment['description'] ?? '',
				] );
			}
		}

		return [
			'id' => $attachment['ID'] ?? null,
			'src' => $attachment['url'] ?? '',
			'type' => $attachment['mime_type'] ?? '',
			'subtype' => $attachment['subtype'] ?? '',
			'filename' => $attachment['filename'] ?? '',
			'filesize' => $filesize_formatted,
			'alt' => $attachment['alt'] ?? '',
			'caption' => $attachment['caption'] ?? '',
			'description' => $attachment['description'] ?? '',
			'preview' => $preview, // empty array if not a PDF or no preview
		];
	}

	public static function formatVideo( $file, $post_id = null, $field = null ) {
		// use formatImage for simplicity as video has similar structure
		$video = self::formatImage( $file, $post_id, $field );
		// disable nested array
		$video = is_countable( $video ) ? reset( $video ) : null;
		return $video;
	}

	public static function formatTerms( $terms ) {

		$items = [];

		if ( is_countable( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof Term ) {
					$link = ( strpos( $term->link(), '?taxonomy=' ) === FALSE ) ? $term->link() : '';
					// we need this approach to respest sorting of nested taxonomy terms
					// like when using plugin https://cs.wordpress.org/plugins/taxonomy-terms-order/
					$children = [];
					if ( $term->children ) {
						$children = Timber::get_terms( [
							'taxonomy' => $term->taxonomy,
							'child_of' => $term->ID
						] );
					}
					$items[] = [
						'id' => $term->ID,
						'title' => $term->title,
						'url' => $link,
						'children' => Helpers::formatTerms( $children ),
					];
				}
			}
		}

		return $items;
	}

	/**
	 * Resize image into multiple variants
	 * @deprecated This function is deprecated, use ImageHelper::resize directly, kept for backward compatibility only
	 */
	public static function resizeImage( $image, $variants ) {

		$theme = wp_get_theme();
		$theme_name = $theme->get( 'TextDomain' );

		$images = [];

		if ( is_countable( $image ) ) {
			$image = end( $image );
		}

		// if empty src something not working correctly return empty array
		if ( ! isset( $image['src'] ) || empty( $image['src'] ) ) {
			return $images;
		}

		$default_image = [
			'src' => $image['src'],
			'type' => isset( $image['type'] ) ? $image['type'] : '',
			'width' => isset( $image['width'] ) ? $image['width'] : '',
			'height' => isset( $image['height'] ) ? $image['height'] : '',
			'alt' => isset( $image['alt'] ) ? $image['alt'] : '',
			'caption' => isset( $image['caption'] ) ? $image['caption'] : '',
			'description' => isset( $image['description'] ) ? $image['description'] : '',
		];

		// if SVG return original image without processing
		if ( isset( $image['type'] ) && $image['type'] === 'image/svg+xml' ) {
			$images[] = $default_image;
			return $images;
		}

		foreach ( $variants as $key => $variant ) {
			$variants[ $key ] = [
				'width' => ( isset( $variant[0] ) && ! empty( $variant[0] ) ) ? intval( $variant[0] ) : 0,
				'height' => ( isset( $variant[1] ) && ! empty( $variant[1] ) ) ? intval( $variant[1] ) : 0,
				'media' => ( isset( $variant[2] ) && ! empty( $variant[2] ) ) ? intval( $variant[2] ) : 0,
				'crop' => ( isset( $variant[3] ) && ! empty( $variant[3] ) ) ? $variant[3] : 'crop',
			];
		}

		// sort array by media value
		usort( $variants, function ( $a, $b ) {
			return $b['media'] - $a['media'];
		} );

		foreach ( $variants as $variant ) {

			if ( ! in_array( $variant['crop'], [ 'center', 'top', 'bottom', 'left', 'right' ] ) ) {
				$variant['crop'] = 'center';
			}

			$resize_src_url = ImageHelper::resize( $default_image['src'], $variant['width'], $variant['height'], $variant['crop'] );
			if ( ! empty( $resize_src_url ) ) {
				// we need this approach as Timber does not support generate webp images from already resized images
				// https://github.com/timber/timber/issues/1978
				$upload_dir = wp_upload_dir();
				// Resolves issues with wrong relative URLs with WPML
				// Without this we cannot generate unique images from non default languages
				// https://github.com/timber/timber/issues/2117
				if ( strpos( $upload_dir['relative'], 'http' ) === 0 ) {
					$upload_dir['relative'] = str_replace( content_url(), '/wp-content', $upload_dir['relative'] );
				}
				// Check if image is in WordPress uploads folder
				// If not we could use images in theme folder
				if ( strpos( $default_image['src'], $upload_dir['relative'] ) === FALSE && strpos( $default_image['src'], $theme_name ) !== FALSE ) {
					$resize_src_path = get_template_directory() . str_replace( get_template_directory_uri(), '', $resize_src_url );
				} else {
					$location = str_replace( $upload_dir['relative'], '/wp-content/cache/image', $upload_dir['basedir'] );
					$resize_src_path = $location . '/' . basename( $resize_src_url );
				}

				if ( file_exists( $resize_src_path ) && $default_image['type'] !== 'image/webp' ) {
					$webp_src = ImageHelper::img_to_webp( $resize_src_path, 100 );
					if ( ! empty( $webp_src ) ) {
						$images[] = [
							'src' => $webp_src,
							'type' => 'image/webp',
							'width' => $variant['width'],
							'height' => $variant['height'],
							'media' => ( ! empty( $variant['media'] ) ) ? '(min-width: ' . $variant['media'] . 'px)' : '',
						];
					}
				}

				$images[] = [
					'src' => $resize_src_url,
					'type' => $default_image['type'],
					'width' => $variant['width'],
					'height' => $variant['height'],
					'media' => ( ! empty( $variant['media'] ) ) ? '(min-width: ' . $variant['media'] . 'px)' : '',
				];
			}
		}

		// add last as fallback image
		$images[] = $default_image;

		return $images;
	}

	public static function isAssoc( array $array ) {
		$keys = array_keys( $array );
		return array_keys( $keys ) !== $keys;
	}

	/**
	 * Normalize pagination from Timber to Bootstrap
	 */
	public static function pagination( object $pagination ) {
		$content = [];

		if ( isset( $pagination->current ) ) {
			$content['current'] = (int) $pagination->current;
		}
		if ( isset( $pagination->total ) ) {
			$content['total'] = (int) $pagination->total;
		}

		if ( isset( $pagination->pages ) && count( $pagination->pages ) ) {
			foreach ( $pagination->pages as $page ) {
				$content['pages'][] = [
					'url' => ( isset( $page['link'] ) ) ? $page['link'] : home_url( $_SERVER['REQUEST_URI'] ),
					'title' => $page['title'],
					'current' => $page['current'],
				];
			}
			$first = reset( $content['pages'] );
			$content['first'] = [
				'url' => $first['url'],
				'title' => 'First',
				'disabled' => ( $first['title'] != $pagination->current ) ? false : true,
			];
			$last = end( $content['pages'] );
			$content['last'] = [
				'url' => $last['url'],
				'title' => 'Last',
				'disabled' => ( $last['title'] != $pagination->current ) ? false : true,
			];
		}

		if ( isset( $pagination->next ) ) {
			$content['next'] = [
				'url' => ( isset( $pagination->next['link'] ) ) ? $pagination->next['link'] : '',
				'title' => 'Next',
				'disabled' => ( isset( $pagination->next['link'] ) ) ? false : true,
			];
		} else {
			$content['next'] = [
				'url' => '',
				'title' => 'Next',
				'disabled' => true,
			];
		}

		if ( isset( $pagination->prev ) ) {
			$content['previous'] = [
				'url' => ( isset( $pagination->prev['link'] ) ) ? $pagination->prev['link'] : '',
				'title' => 'Previous',
				'disabled' => ( isset( $pagination->prev['link'] ) ) ? false : true,
			];
		} else {
			$content['previous'] = [
				'url' => '',
				'title' => 'Previous',
				'disabled' => true,
			];
		}

		return $content;
	}

	/**
	 * Format ACF fields
	 */
	public static function formatFields( $post = null, $is_preview = false ) {

		$post_id = null;

		if ( is_object( $post ) && ! empty( $post->ID ) ) {
			$post_id = $post->ID;
		} elseif ( is_object( $post ) && ! empty( $post->term_id ) ) {
			$post_id = $post->term_id;
		} elseif ( is_numeric( $post ) ) {
			$post_id = $post;
		} elseif ( is_string( $post ) ) { // like page options values
			$post_id = $post;
		} else {
			// this will get also queried object id for gutenberg block
			// format like block_f85ccf81c4271662c50f0d92f2da2d1
			$post_id = get_queried_object_id();
		}

		$fields = get_field_objects( $post_id );

		// if we are inside gutenberg block we need to get real $post_id for formatters to work properly
		if ( str_starts_with( (string) $post_id, 'block_' ) ) {
			global $post;

			if ( isset( $post ) && isset( $post->ID ) ) {
				$post_id = $post->ID;
			}
		}

		$content = [];
		if ( ! empty( $fields ) ) {
			foreach ( $fields as $key => $field ) {
				$value = self::fieldFormatter( $field, $post_id, $is_preview );
				if ( ! empty( $value ) ) {
					$content[ $key ] = $value;
				}
			}
		}

		return $content;
	}

	public static function fieldFormatter( $field, $post_id = null, $is_preview = false ) {

		// we need to allow post_id null when we are using it during preview block without saving
		if ( empty( $field ) ) {
			return FALSE;
		}

		if ( ! isset( $field['type'] ) || ! isset( $field['value'] ) ) {
			return $field;
		}

		if ( $field['type'] === 'link' ) {

			$field['value'] = self::formatLink( $field['value'], $post_id, $field );

		} elseif ( in_array( $field['type'], [ 'wywiwyg', 'textarea' ] ) ) {

			// we need to check wysiwyg fields for <br data-mce-bogus="1"> to properly check if empty
			if ( is_string( $field ) && empty( trim( preg_replace( '/\s\s+/', ' ', strip_tags( $field['value'] ) ) ) ) ) {
				$field['value'] = '';
			}

			$field['value'] = do_shortcode( $field['value'] );

		} elseif ( $field['type'] === 'image' ) {

			$data = self::formatImage( $field['value'], $post_id, $field );
			if ( $data ) {
				$field['value'] = $data;
			}

		} elseif ( $field['type'] === 'gallery' ) {

			if ( is_countable( $field['value'] ) ) {
				foreach ( $field['value'] as &$item ) {
					if ( $item['type'] === 'image' ) {
						$data = self::formatImage( $item, $post_id, $field );
						if ( $data ) {
							$item = $data;
						}
					} else if ( $item['type'] === 'application' ) {
						$data = self::formatFile( $item, $post_id, $field );
						if ( $data ) {
							$item = $data;
						}
					} else if ( $item['type'] === 'video' ) {
						$data = self::formatVideo( $item, $post_id, $field );
						if ( $data ) {
							$item = $data;
						}
					}
				}
			}

		} elseif ( $field['type'] === 'file' ) {

			$data = self::formatFile( $field['value'], $post_id, $field );
			if ( $data ) {
				$field['value'] = $data;
			}

		} elseif ( $field['type'] === 'post_object' ) {

			if ( $field['value'] instanceof \WP_Post ) {
				if ( $field['value']->post_type === 'wpcf7_contact_form' ) {
					// during preview we need to return only shortcode as preview is not working
					if ( $is_preview ) {
						$field['value'] = '[contact-form-7 id="' . $field['value']->ID . '" title=""]';
					} else {
						$field['value'] = do_shortcode( '[contact-form-7 id="' . $field['value']->ID . '" title=""]' );
					}
				} elseif ( $field['value']->post_type === 'wpforms' ) {
					if ( $is_preview ) {
						// during preview we need to return only shortcode as preview is not working
						$field['value'] = '[wpforms id="' . $field['value']->ID . '"]';
					} else {
						$field['value'] = do_shortcode( '[wpforms id="' . $field['value']->ID . '"]' );
					}
				}
			}

		} elseif ( $field['type'] === 'oembed' ) {

			// parse iframe src only
			$field['value'] = preg_match( '/src="(.+?)"/', $field['value'], $matches ) ? $matches[1] : '';

		} elseif ( in_array( $field['type'], [ 'repeater', 'group' ] ) ) {

			// create array with sub_fields by name
			$sub_fields = [];
			if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				foreach ( $field['sub_fields'] as $sub_field ) {
					$sub_fields[ $sub_field['name'] ] = $sub_field;
				}
			}

			// we need to combine sub field configuration to sub field value
			if ( is_countable( $field['value'] ) ) {
				foreach ( $field['value'] as $key => &$value ) {
					// group field could be associative array
					if ( is_countable( $field['value'] ) && ! Helpers::isAssoc( $field['value'] ) ) {
						foreach ( $value as $sub_key => &$sub_value ) {
							if ( isset( $sub_fields[ $sub_key ] ) ) {
								$sub_fields[ $sub_key ]['value'] = $sub_value;
								$sub_value = self::fieldFormatter( $sub_fields[ $sub_key ], $post_id, $is_preview );
							}
						}
					} else {
						if ( isset( $sub_fields[ $key ] ) ) {
							$sub_fields[ $key ]['value'] = $value;
							$value = self::fieldFormatter( $sub_fields[ $key ], $post_id, $is_preview );
						}
					}
				}
			}

		} elseif ( in_array( $field['type'], [ 'flexible_content' ] ) ) {

			// create array with layouts and sub_fields by name
			$layouts = [];
			if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					$sub_fields = [];
					if ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						foreach ( $layout['sub_fields'] as $sub_field ) {
							$sub_fields[ $sub_field['name'] ] = $sub_field;
						}
					}
					$layouts[ $layout['name'] ] = $sub_fields;
				}
			}

			// we need to combine layout field configuration to layout field value
			if ( is_countable( $field['value'] ) ) {
				foreach ( $field['value'] as $key => &$value ) {
					// group field could be associative array
					if ( is_countable( $value ) ) {
						foreach ( $value as $layout_key => &$layout_value ) {
							if ( isset( $layouts[ $value['acf_fc_layout'] ][ $layout_key ] ) ) {
								$layouts[ $value['acf_fc_layout'] ][ $layout_key ]['value'] = $layout_value;
								$layout_value = self::fieldFormatter( $layouts[ $value['acf_fc_layout'] ][ $layout_key ], $post_id, $is_preview );
							}
						}
					} else {
						if ( isset( $layouts[ $key ] ) ) {
							$layouts[ $key ]['value'] = $value;
							$value = self::fieldFormatter( $layouts[ $key ], $post_id, $is_preview );
						}
					}
				}
			}

		}

		// allow to alter formatter for specific field type
		$field = apply_filters( 'field_formatter_' . $field['type'], $field, $post_id );

		return $field['value'];
	}

	/**
	 * Translate ACF link
	 */
	public static function formatLink( $value, $post_id, $field ) {

		if ( ! is_array( $value ) ) {
			return $value;
		}

		// copy target to attributes field
		if ( isset( $value['target'] ) ) {
			if ( ! empty( $value['target'] ) ) {
				$value['attributes']['target'] = $value['target'];
			} else {
				unset( $value['target'] );
			}
		}

		// allow certain HTML tags for the link title
		if ( isset( $value['title'] ) ) {
			// decode HTML entities first as string is already encoded
			$value['title'] = html_entity_decode( $value['title'] );
			// allow only certain tags
			$value['title'] = wp_kses( $value['title'], [
				'strong' => [],
				'b' => [],
				'i' => [],
				'em' => [],
				'br' => [],
			] );
		}

		// apply only for internal links
		if ( isset( $value['attributes']['target'] ) && $value['attributes']['target'] === '_blank' ) {
			return $value;
		}

		// apply only if WPML is enabled and on links which are set to translatable
		if ( ! isset( $field['wpml_cf_preferences'] ) || $field['wpml_cf_preferences'] !== 2 ) {
			return $value;
		}

		if ( isset( $value['url'] ) ) {

			$parsed_url = parse_url( $value['url'] );
			$post_id = url_to_postid( $value['url'] );
			// if we are in non default language we could get 0 for valid URLs
			// then we need to extract only slug from URL
			if ( $post_id === 0 ) {
				$url = self::extract_slug_from_url( $value['url'] );
				$post_id = url_to_postid( $url );
			}

			if ( $post_id > 0 ) {

				$post_type = get_post_type( $post_id );
				$translated_url = apply_filters( 'wpml_object_id', $post_id, $post_type );
				$translated_url = get_permalink( $translated_url );

				// Add query if it's there
				if ( isset( $parsed_url['query'] ) ) {
					$translated_url .= '?' . $parsed_url['query'];
				}

				// Add fragment if it's there
				if ( isset( $parsed_url['fragment'] ) ) {
					$translated_url .= '#' . $parsed_url['fragment'];
				}

				// replace with translated url
				$value['url'] = $translated_url;
			}
		}

		return $value;
	}

	public static function formatMenu( $menu_or_name, $parent_item = null ) {

		// If a menu name (string) was passed, fetch the menu object once.
		$menu = is_string( $menu_or_name ) ? Timber::get_menu( $menu_or_name ) : $menu_or_name;

		// Decide which items to process: root items or a parent's children.
		$source_items = [];
		if ( $parent_item === null ) {
			if ( isset( $menu->items ) ) {
				$source_items = $menu->items;
			}
		} else {
			if ( isset( $parent_item->children ) ) {
				$source_items = $parent_item->children;
			}
		}

		$items = [];
		foreach ( $source_items as $item ) {

			$attributes = [];
			$attributes['target'] = $item->target ?: null;
			if ( isset( $item->classes ) && is_array( $item->classes ) ) {
				// remove from array WordPress defaults classes
				$item->classes = array_filter( $item->classes, function ( $class ) {
					return strpos( $class, 'menu-item' ) === FALSE
						&& strpos( $class, 'current_page' ) === FALSE
						&& strpos( $class, 'page_item' ) === FALSE
						&& strpos( $class, 'page-item' ) === FALSE;
				} );
				$attributes['class'] = implode( ' ', $item->classes );
			}

			// we cannot directly translate description, so we register it here manually
			$description = $item->description;
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) && ! empty( $description ) ) {
				$default_language = apply_filters( 'wpml_default_language', null );
				icl_register_string( $menu->name . ' menu', 'Menu Item Description ' . $item->ID, $description, false, $default_language );
				$description = icl_t( $menu->name . ' menu', 'Menu Item Description ' . $item->ID, $description );
			}

			$acf_fields = (array) Helpers::formatFields( $item );

			$items[] = [
				'id' => $item->ID,
				'title' => $item->name,
				'url' => $item->url,
				'description' => $description,
				'attributes' => $attributes,
				'in_active_trail' => $item->current_item_ancestor,
				'is_active' => $item->current,
				'below' => self::formatMenu( $menu, $item ),
			] + $acf_fields;
		}

		return $items;
	}

	public static function formatLanguageSwitcher() {

		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return [];
		}

		global $sitepress;

		$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => FALSE ] );

		$items = [];
		if ( ! empty( $languages ) && is_countable( $languages ) ) {
			foreach ( $languages as $language ) {
				$url = esc_url( $language['url'] );
				if ( isset( $language['missing'] ) && $language['missing'] ) {
					$url = '';
				}
				$home_url = esc_url( $sitepress->language_url( $language['language_code'] ) );
				$items[] = [
					'id' => esc_html( $language['language_code'] ),
					'title' => esc_html( $language['native_name'] ),
					'url' => $url,
					'home_url' => $home_url,
					'is_active' => (bool) $language['active'],
				];
			}
		}

		return $items;
	}

	/**
	 * This function extracts the slug from a URL
	 * useful for url_to_postid() when using WPML with prefixes
	 */
	public static function extract_slug_from_url( $url ) {

		// Remove domain from URL to get just the path
		$parsed_url = parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';

		// Remove trailing slash for consistency
		$path = rtrim( $path, '/' );

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) ) {
			// Get all active language codes
			$active_languages = apply_filters( 'wpml_active_languages', null );
			$active_languages = array_keys( $active_languages );

			// Remove language prefix if present
			foreach ( $active_languages as $lang ) {
				$prefix = '/' . $lang;
				if ( strpos( $path, $prefix . '/' ) === 0 ) {
					$path = substr( $path, strlen( $prefix ) );
					break;
				} elseif ( $path === $prefix ) {
					$path = '';
					break;
				}
			}
		}

		// Ensure path starts with a single slash
		if ( $path !== '' && $path[0] !== '/' ) {
			$path = '/' . $path;
		}

		return $path;
	}
}
