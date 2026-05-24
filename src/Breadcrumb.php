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
}
