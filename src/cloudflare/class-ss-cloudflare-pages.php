<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which pages the Cloudflare export contains.
 *
 * Smart Crawl finds pages only through crawlers, so everything WordPress can list is exported
 * (the theme's portfolio and service posts, taxonomies, authors...). This lists those pages in
 * the admin; the ones the user unticks go into `ss_cloudflare[excluded_urls]` as paths and are
 * handed to the core's own "Exclude URLs" setting, so they are skipped the same way.
 *
 * It also registers the TranslatePress crawler (see class-ss-cloudflare-translatepress-crawler.php).
 */
class Cloudflare_Pages {

	/** Crawlers that list pages; the others find assets. */
	const PAGE_CRAWLERS = array(
		'Simply_Static\Crawler\Home_Crawler',
		'Simply_Static\Crawler\Post_Type_Crawler',
		'Simply_Static\Crawler\Taxonomy_Crawler',
		'Simply_Static\Crawler\Archive_Crawler',
		'Simply_Static\Crawler\Author_Crawler',
	);

	public static function init() {
		add_filter( 'simply_static_crawlers', array( __CLASS__, 'add_translatepress_crawler' ) );
		add_filter( 'ss_get_option_urls_to_exclude', array( __CLASS__, 'exclude' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Filter `simply_static_crawlers`: the base class is loaded by now, so the subclass can be. */
	public static function add_translatepress_crawler( $crawlers ) {
		if ( Cloudflare::enabled() && class_exists( 'TRP_Translate_Press' ) ) {
			require_once __DIR__ . '/class-ss-cloudflare-translatepress-crawler.php';
			$crawlers[] = new \Simply_Static\Crawler\Translatepress_Crawler();
		}

		return $crawlers;
	}

	/** @return object[] The crawlers that list pages. */
	protected static function page_crawlers() {
		// The core loads its crawlers on demand (discovery task, admin REST); so do we.
		require_once SIMPLY_STATIC_PATH . 'src/crawler/class-ss-crawlers.php';

		$found = array();
		// In PAGE_CRAWLERS order: the home page comes first and keeps the pages other crawlers repeat.
		foreach ( self::PAGE_CRAWLERS as $class ) {
			foreach ( Crawlers::instance()->get_crawlers() as $crawler ) {
				if ( is_a( $crawler, $class ) ) {
					$found[] = $crawler;
				}
			}
		}

		return $found;
	}

	/**
	 * Page URLs per crawler, each URL once (the first crawler that finds it keeps it).
	 *
	 * @param bool $only_active Only the crawlers the user left switched on (what an export really visits).
	 *
	 * @return array List of [ id, label, urls ].
	 */
	public static function groups( $only_active = false ) {
		$groups = array();
		$seen   = array();

		foreach ( static::page_crawlers() as $crawler ) {
			if ( $only_active && ! $crawler->is_active() ) {
				continue;
			}

			$urls = array();
			foreach ( (array) $crawler->detect() as $url ) {
				if ( is_string( $url ) && '' !== trim( $url ) && ! isset( $seen[ $url ] ) ) {
					$seen[ $url ] = true;
					$urls[]       = $url;
				}
			}

			$info     = $crawler->js_object();
			$groups[] = array( 'id' => $info['id'], 'label' => $info['name'], 'urls' => $urls );
		}

		return $groups;
	}

	/** Path of a URL relative to the site root ('' when it is not on this site). */
	public static function path( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return '' !== $path && '/' === $path[0] ? $path : '';
	}

	/**
	 * Paths worth storing: absolute, no query, never the home page (an export without it is no site).
	 *
	 * @param array $paths Whatever the browser sent.
	 *
	 * @return string[]
	 */
	public static function clean_paths( array $paths ) {
		$clean = array();
		foreach ( $paths as $path ) {
			$path = is_string( $path ) ? self::path( sanitize_text_field( $path ) ) : '';
			if ( '' !== $path && '/' !== $path && false === strpos( $path, '?' ) ) {
				$clean[ $path ] = true;
			}
		}

		return array_keys( $clean );
	}

	/**
	 * One regex per path, in the format the core's exclusion list understands. It matches the page
	 * itself (with or without trailing slash) but not what lives below it.
	 *
	 * @param string[] $paths Paths relative to the site root.
	 *
	 * @return string[]
	 */
	public static function patterns( array $paths ) {
		$home  = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$lines = array();
		foreach ( self::clean_paths( $paths ) as $path ) {
			$lines[] = '/^https?:\/\/[^\/]+' . preg_quote( $home . rtrim( $path, '/' ), '/' ) . '\/?(?:[?#].*)?$/i';
		}

		return $lines;
	}

	/** Filter `ss_get_option_urls_to_exclude`: what the user wrote plus what they unticked. */
	public static function exclude( $value ) {
		if ( ! Cloudflare::enabled() ) {
			return $value;
		}
		$settings = Cloudflare::settings();
		$lines    = self::patterns( (array) $settings['excluded_urls'] );
		if ( ! $lines ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $lines as $line ) {
				$value[] = array( 'url' => $line );
			}

			return $value;
		}

		return trim( trim( (string) $value ) . "\n" . implode( "\n", $lines ) );
	}

	public static function routes() {
		register_rest_route( 'simplystatic/v1', '/cloudflare/pages', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_pages' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	/** The pages WordPress can list, with the ones the user left out marked. */
	public static function rest_pages() {
		$excluded = array_flip( self::clean_paths( (array) Cloudflare::settings()['excluded_urls'] ) );
		$groups   = array();

		foreach ( self::groups() as $group ) {
			$items = array();
			foreach ( $group['urls'] as $url ) {
				$path = self::path( $url );
				if ( '' !== $path ) {
					$items[] = array( 'path' => $path, 'excluded' => isset( $excluded[ $path ] ), 'locked' => '/' === $path );
				}
			}
			if ( $items ) {
				$groups[] = array( 'id' => $group['id'], 'label' => $group['label'], 'items' => $items );
			}
		}

		return rest_ensure_response( array( 'groups' => $groups ) );
	}
}
