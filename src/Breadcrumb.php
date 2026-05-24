<?php
declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Builds breadcrumb data for the current WordPress query state.
 *
 * Returns an array of typed items `[{type, url, title, …extras}]`, with
 * `type` discriminating which strategy produced the item ('home', 'item',
 * '404', 'search', 'date_year', 'date_month', 'date_day', 'author',
 * 'pagination'). After `hydrate()`, every item has a populated `title`
 * suitable for direct Twig consumption.
 *
 * Configure via constructor:
 * ```
 * $bc = new Breadcrumb([
 *     'menu_name'             => 'main-menu',
 *     'list_page_map'         => ['post' => 'article_list'],
 *     'menu_trail_post_types' => null,
 *     'include_pagination'    => false,
 *     'labels'                => [
 *         'home'       => _x('Home', 'theme', 'theme'),
 *         '404'        => _x('Page not found', 'theme', 'theme'),
 *         'search'     => _x('Search: %s', 'theme', 'theme'),
 *         'pagination' => _x('Page %d', 'theme', 'theme'),
 *         'author'     => _x('Author: %s', 'theme', 'theme'),
 *     ],
 * ]);
 * $items = $bc->get();
 * ```
 *
 * Translation strategy: this class is text-domain agnostic. The `labels`
 * array carries pre-translated strings supplied by the calling project.
 * Defaults are English raw strings — graceful degradation when a project
 * forgets to configure labels.
 */
class Breadcrumb {

	/** @var string Nav menu location for menu-trail strategy */
	protected string $menu_name = 'main-menu';

	/** @var array<string, string> Post type → ACF option key for "listing page" injection */
	protected array $list_page_map = [];

	/** @var array<int, string>|null Post types eligible for menu-trail; null = auto-detect hierarchical */
	protected ?array $menu_trail_post_types = null;

	/** @var bool Whether to add "Page N" item on paginated views */
	protected bool $include_pagination = false;

	/** @var array{'home': string, '404': string, 'search': string, 'pagination': string, 'author': string} Label dict; pre-translated by the caller */
	protected array $labels = [
		'home'       => 'Home',
		'404'        => 'Page not found',
		'search'     => 'Search: %s',
		'pagination' => 'Page %d',
		'author'     => 'Author: %s',
	];

	/**
	 * @param array<string, mixed> $config Optional config overriding default property values.
	 */
	public function __construct( array $config = [] ) {
		foreach ( $config as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}

	/**
	 * Build the breadcrumb item array for the current WordPress query state.
	 *
	 * @return array<int, array<string, mixed>> Typed items with hydrated titles.
	 */
	public function get(): array {
		return [];
	}

	/**
	 * Build the home breadcrumb item. Always the first item in the trail.
	 *
	 * @return array{type: string, url: string}
	 */
	protected function build_home_item(): array {
		return [
			'type' => 'home',
			'url'  => home_url( '/' ),
		];
	}

	/**
	 * Build breadcrumb items for a 404 page.
	 *
	 * @return array<int, array{type: string}>
	 */
	protected function build_for_404(): array {
		return [ [ 'type' => '404' ] ];
	}

	/**
	 * Build breadcrumb items for a search results page.
	 *
	 * @return array<int, array{type: string, query: string, url: string}>
	 */
	protected function build_for_search(): array {
		return [
			[
				'type'  => 'search',
				'query' => get_search_query(),
				'url'   => get_search_link(),
			],
		];
	}

	/**
	 * Build breadcrumb items for an author archive page.
	 *
	 * @return array<int, array{type: string, display_name: string, url: string}>
	 */
	protected function build_for_author_archive(): array {
		$author = get_queried_object();
		if ( ! $author || ! isset( $author->display_name, $author->ID ) ) {
			return [];
		}
		return [
			[
				'type'         => 'author',
				'display_name' => (string) $author->display_name,
				'url'          => get_author_posts_url( (int) $author->ID ),
			],
		];
	}

	/**
	 * Build breadcrumb items for a date archive (year / month / day).
	 *
	 * Year linked when month follows; month linked when day follows; day is
	 * always the leaf (no url). Each item carries the date components needed
	 * for hydrate() to format the title.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_for_date_archive(): array {
		$year  = (int) get_query_var( 'year' );
		$month = (int) get_query_var( 'monthnum' );
		$day   = (int) get_query_var( 'day' );

		if ( $year <= 0 ) {
			return [];
		}

		$items = [];

		if ( $month > 0 ) {
			$items[] = [
				'type' => 'date_year',
				'year' => $year,
				'url'  => get_year_link( $year ),
			];

			if ( $day > 0 ) {
				$items[] = [
					'type'  => 'date_month',
					'year'  => $year,
					'month' => $month,
					'url'   => get_month_link( $year, $month ),
				];
				$items[] = [
					'type'  => 'date_day',
					'year'  => $year,
					'month' => $month,
					'day'   => $day,
				];
			} else {
				$items[] = [
					'type'  => 'date_month',
					'year'  => $year,
					'month' => $month,
					'url'   => null,
				];
			}
		} else {
			$items[] = [
				'type' => 'date_year',
				'year' => $year,
				'url'  => null,
			];
		}

		return $items;
	}

	/**
	 * Build breadcrumb items for a post type archive page.
	 *
	 * @return array<int, array{type: string, title: string, url: string}>
	 */
	protected function build_for_post_type_archive(): array {
		$post_type = get_query_var( 'post_type' );
		if ( is_array( $post_type ) ) {
			$post_type = reset( $post_type );
		}
		if ( ! $post_type || ! is_string( $post_type ) ) {
			return [];
		}

		$cpt = get_post_type_object( $post_type );
		if ( ! $cpt || ! isset( $cpt->labels->archives ) ) {
			return [];
		}

		$url = get_post_type_archive_link( $post_type );
		if ( ! is_string( $url ) ) {
			return [];
		}

		return [
			[
				'type'  => 'item',
				'title' => (string) $cpt->labels->archives,
				'url'   => $url,
			],
		];
	}

	/**
	 * Build breadcrumb items for a taxonomy term archive page.
	 *
	 * For hierarchical taxonomies, prepends ancestor terms (root to leaf).
	 * `get_term_link()` returning `WP_Error` is guarded — item still added
	 * with `url => null` (the leaf term is the current page anyway).
	 *
	 * @return array<int, array{type: string, title: string, url: string|null}>
	 */
	protected function build_for_taxonomy(): array {
		$term = get_queried_object();
		if ( ! $term || ! isset( $term->term_id, $term->name, $term->taxonomy ) ) {
			return [];
		}

		$items = [];

		if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
			$ancestor_ids = get_ancestors( (int) $term->term_id, $term->taxonomy, 'taxonomy' );
			$ancestor_ids = array_reverse( $ancestor_ids );
			foreach ( $ancestor_ids as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, $term->taxonomy );
				if ( ! $ancestor || is_wp_error( $ancestor ) ) {
					continue;
				}
				$ancestor_url = get_term_link( $ancestor );
				$items[] = [
					'type'  => 'item',
					'title' => (string) $ancestor->name,
					'url'   => is_string( $ancestor_url ) ? $ancestor_url : null,
				];
			}
		}

		$term_url = get_term_link( $term );
		$items[] = [
			'type'  => 'item',
			'title' => (string) $term->name,
			'url'   => is_string( $term_url ) ? $term_url : null,
		];

		return $items;
	}

	/**
	 * Find a menu item in an array of items by field-value match.
	 *
	 * Normalizes numeric values to int before strict comparison.
	 * `wp_get_nav_menu_items()` returns `object_id` and `menu_item_parent`
	 * as strings (post_meta is always stringified) while `ID` is int
	 * (from wp_posts) and `get_queried_object_id()` also returns int.
	 * Without this normalization `'42' === 42` is false and the menu
	 * lookup silently fails — breaking breadcrumbs for normal nav menus.
	 *
	 * @param string             $field     Property name to compare.
	 * @param mixed              $object_id Needle value.
	 * @param array<int, object> $items     Haystack of menu-item objects.
	 * @return object|false Matching item, or false if not found.
	 */
	protected function get_menu_item( string $field, mixed $object_id, array $items ): object|false {
		$needle = is_numeric( $object_id ) ? (int) $object_id : $object_id;
		foreach ( $items as $item ) {
			$haystack = is_numeric( $item->$field ) ? (int) $item->$field : $item->$field;
			if ( $haystack === $needle ) {
				return $item;
			}
		}
		return false;
	}
}
