<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search for the static site, with no server and no extra service.
 *
 * On every deploy the plugin writes a compact JSON index of the site's content
 * (`/ss-search/index.json`) and a results page (`/buscar/`) that reuses the site's own
 * layout; a small script (search.js) filters the index in the browser. The theme's search
 * forms are pointed at that page, so visitors keep using the box they already know.
 * Static files only: they cost nothing on Cloudflare and need no Worker run.
 */
class Cloudflare_Search {

	const DIR = 'ss-search';

	/** Characters of body text kept per post; enough to find a post, small enough to ship. */
	const TEXT_LENGTH = 4000;

	const MAX_POSTS = 5000;

	public static function init() {
		add_filter( 'ss_dom_before_save', array( __CLASS__, 'point_forms' ), 25 );
	}

	public static function enabled() {
		$s = Cloudflare::settings();

		return Cloudflare::enabled() && ! empty( $s['search'] );
	}

	/** Slug of the results page; never a slug the site already uses. */
	public static function page_path() {
		$slug = 0 === strpos( get_locale(), 'es' ) ? 'buscar' : 'search';

		return get_page_by_path( $slug ) ? $slug . '-site' : $slug;
	}

	/** Theme search boxes (GET forms with a field named `s`) go to the results page. */
	public static function point_forms( $dom ) {
		if ( ! $dom instanceof \DOMDocument || ! self::enabled() ) {
			return $dom;
		}

		$path = '/' . self::page_path() . '/';
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'form' ) ) as $form ) {
			$method = strtolower( $form->getAttribute( 'method' ) );
			if ( '' !== $method && 'get' !== $method ) {
				continue;
			}
			foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
				if ( 's' === $input->getAttribute( 'name' ) ) {
					$form->setAttribute( 'action', $path );
					break;
				}
			}
		}

		return $dom;
	}

	/**
	 * Write index, script and results page into the export.
	 *
	 * @param string $dir Archive directory (with trailing slash).
	 *
	 * @return int Posts indexed.
	 */
	public static function write( $dir ) {
		$rows = self::index( $dir );

		wp_mkdir_p( $dir . self::DIR );
		file_put_contents( $dir . self::DIR . '/index.json', wp_json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		copy( __DIR__ . '/search.js', $dir . self::DIR . '/search.js' );

		$page = self::results_page( $dir );
		if ( $page ) {
			wp_mkdir_p( $dir . self::page_path() );
			file_put_contents( $dir . self::page_path() . '/index.html', $page );
		}

		return count( $rows );
	}

	/**
	 * @param string $dir Archive directory: only posts that really exist in the export are listed.
	 *
	 * @return array Rows of [ title, path, text, type ].
	 */
	public static function index( $dir = '' ) {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) );
		$ids   = get_posts( array(
			'post_type'        => $types,
			'post_status'      => 'publish',
			'has_password'     => false,
			'posts_per_page'   => self::MAX_POSTS,
			'fields'           => 'ids',
			'orderby'          => 'date',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		) );

		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$rows      = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			// ponytail: raw post_content, so page-builder layouts (Elementor...) are searched by title only. Render the_content if needed.
			$text = $post->post_content ? $post->post_content : $post->post_excerpt;
			$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $text ) ) ) );
			$path = (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH );
			if ( $home_path && 0 === strpos( $path, $home_path ) ) {
				$path = substr( $path, strlen( $home_path ) );
			}

			$path = $path ? $path : '/';
			// Only pages that exist in the export (drops builder templates and the like), and only the real front page at "/".
			if ( '/' === $path ? (int) get_option( 'page_on_front' ) !== (int) $id : ( $dir && ! is_file( $dir . trim( $path, '/' ) . '/index.html' ) && ! is_file( $dir . trim( $path, '/' ) ) ) ) {
				continue;
			}

			$rows[] = array(
				html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				$path,
				mb_substr( $text, 0, self::TEXT_LENGTH ),
				$post->post_type,
			);
		}

		return $rows;
	}

	/**
	 * The results page: the home page's layout with its main area replaced by the search box.
	 *
	 * @param string $dir Archive directory.
	 *
	 * @return string HTML, '' when there is no home page to borrow from.
	 */
	protected static function results_page( $dir ) {
		$home = is_file( $dir . 'index.html' ) ? (string) file_get_contents( $dir . 'index.html' ) : '';
		if ( '' === $home ) {
			return '';
		}

		$dom = new \DOMDocument();
		@$dom->loadHTML( '<?xml encoding="utf-8"?>' . $home );
		$xpath = new \DOMXPath( $dom );

		$title = esc_html__( 'Search', 'simply-static' );
		$ui    = $dom->createDocumentFragment();
		$ui->appendXML( self::ui_markup() );

		$main = $xpath->query( '//main | //*[@role="main"] | //*[@id="main"] | //*[@id="content"] | //*[contains(concat(" ",normalize-space(@class)," ")," site-main ")]' )->item( 0 );
		if ( $main ) {
			while ( $main->firstChild ) {
				$main->removeChild( $main->firstChild );
			}
			$main->appendChild( $ui );
		} elseif ( ( $body = $dom->getElementsByTagName( 'body' )->item( 0 ) ) ) {
			$body->insertBefore( $ui, $body->firstChild );
		} else {
			return '';
		}

		foreach ( iterator_to_array( $xpath->query( '//link[@rel="canonical"] | //meta[@property="og:url"] | //meta[@name="robots"] | //script[@type="application/ld+json"]' ) ) as $node ) {
			$node->parentNode->removeChild( $node );
		}
		$head = $dom->getElementsByTagName( 'head' )->item( 0 );
		if ( $head ) {
			$robots = $dom->createElement( 'meta' );
			$robots->setAttribute( 'name', 'robots' );
			$robots->setAttribute( 'content', 'noindex' );
			$head->appendChild( $robots );
			foreach ( $head->getElementsByTagName( 'title' ) as $node ) {
				$node->nodeValue = $title . ' - ' . wp_strip_all_tags( get_bloginfo( 'name' ) );
			}
		}

		return $dom->saveHTML();
	}

	protected static function ui_markup() {
		$attr = function ( $text ) {
			return esc_attr( $text );
		};

		return '<div id="ss-search" data-index="/' . self::DIR . '/index.json"'
			. ' data-none="' . $attr( __( 'No results found.', 'simply-static' ) ) . '"'
			. ' data-loading="' . $attr( __( 'Searching…', 'simply-static' ) ) . '"'
			. ' data-error="' . $attr( __( 'Search is not available right now.', 'simply-static' ) ) . '">'
			. '<form role="search" method="get" action="">'
			. '<input type="search" name="s" placeholder="' . $attr( __( 'Search…', 'simply-static' ) ) . '" aria-label="' . $attr( __( 'Search', 'simply-static' ) ) . '"/> '
			. '<button type="submit">' . esc_html__( 'Search', 'simply-static' ) . '</button>'
			. '</form>'
			. '<div class="ss-search-results" aria-live="polite"></div>'
			. '<script src="/' . self::DIR . '/search.js" defer="defer"></script>'
			. '</div>';
	}
}
