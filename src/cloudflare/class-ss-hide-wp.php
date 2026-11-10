<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hide WP: the settings the Hide WP screen already stores, applied.
 *
 * The core can already rename wp-content/wp-includes (and friends) in URLs and files from the
 * `wp_*_directory` options. This class adds the rest: it removes the markers WordPress leaves
 * in the HTML, and, with Cloudflare as the delivery method, turns the whole thing on by
 * default (one switch: `hide_wp`). Values the user typed on the Hide WP screen always win.
 */
class Hide_WP {

	/** Public names used when nothing else was chosen. */
	const DIRECTORIES = array(
		'wp_content_directory'  => array( 'wp-content', 'assets' ),
		'wp_includes_directory' => array( 'wp-includes', 'lib' ),
		'wp_uploads_directory'  => array( 'uploads', 'media' ),
		'wp_plugins_directory'  => array( 'plugins', 'vendor' ),
		'wp_themes_directory'   => array( 'themes', 'theme' ),
	);

	const TOGGLES = array( 'hide_comments', 'hide_version', 'hide_generator', 'hide_prefetch', 'hide_rsd', 'hide_emotes', 'disable_xmlrpc', 'disable_embed', 'disable_wlw_manifest' );

	/** Every core option this feature reads (used to know when a full export is needed). */
	const OPTIONS = array(
		'wp_content_directory', 'wp_includes_directory', 'wp_uploads_directory', 'wp_plugins_directory', 'wp_themes_directory',
		'theme_style_name', 'hide_comments', 'hide_version', 'hide_generator', 'hide_prefetch', 'hide_rsd', 'hide_emotes',
		'disable_xmlrpc', 'disable_embed', 'disable_wlw_manifest', 'custom_replacements',
	);

	public static function init() {
		foreach ( self::DIRECTORIES as $name => $pair ) {
			add_filter( 'ss_get_option_' . $name, function ( $value ) use ( $name, $pair ) {
				// Untouched (still the WordPress name): use ours. Anything typed by the user stays.
				return self::default_on() && ( '' === $value || $pair[0] === $value ) ? $pair[1] : $value;
			} );
		}
		foreach ( self::TOGGLES as $name ) {
			add_filter( 'ss_get_option_' . $name, function ( $value ) {
				return self::default_on() ? true : $value;
			} );
		}

		add_filter( 'ss_dom_before_save', array( __CLASS__, 'clean_dom' ), 15, 2 );
		add_filter( 'simply_static_content_before_save', array( __CLASS__, 'custom_replacements' ), 20 );
	}

	/** Hide WP applies by default when Cloudflare delivers the site; the setting turns it off. */
	protected static function default_on() {
		if ( ! Cloudflare::enabled() ) {
			return false;
		}
		$stored = get_option( Cloudflare::OPTION, array() );

		return ! is_array( $stored ) || ! array_key_exists( 'hide_wp', $stored ) || ! empty( $stored['hide_wp'] );
	}

	/**
	 * @param \DOMDocument|string $dom DOM being exported.
	 *
	 * @return \DOMDocument|string
	 */
	public static function clean_dom( $dom ) {
		if ( ! $dom instanceof \DOMDocument ) {
			return $dom;
		}

		$options = Options::instance();
		$xpath   = new \DOMXPath( $dom );
		$remove  = array();

		if ( $options->get( 'hide_generator' ) ) {
			$remove[] = '//meta[@name="generator"]';
			$remove[] = '//link[@rel="https://api.w.org/"]';
			$remove[] = '//link[@rel="shortlink"]';
		}
		if ( $options->get( 'hide_prefetch' ) ) {
			$remove[] = '//link[@rel="dns-prefetch"]';
		}
		if ( $options->get( 'hide_rsd' ) ) {
			$remove[] = '//link[@rel="EditURI"]';
		}
		if ( $options->get( 'disable_wlw_manifest' ) ) {
			$remove[] = '//link[@rel="wlwmanifest"]';
		}
		if ( $options->get( 'disable_xmlrpc' ) ) {
			$remove[] = '//link[@rel="pingback"]';
		}
		if ( $options->get( 'disable_embed' ) ) {
			$remove[] = '//link[@type="application/json+oembed" or @type="text/xml+oembed"]';
			$remove[] = '//script[contains(@src,"wp-embed")]';
		}
		if ( $options->get( 'hide_emotes' ) ) {
			$remove[] = '//script[contains(@src,"wp-emoji")]';
			$remove[] = '//script[@id="wp-emoji-settings"]';
			$remove[] = '//script[contains(.,"_wpemojiSettings")]';
			$remove[] = '//style[@id="wp-emoji-styles-inline-css"]';
		}
		if ( $options->get( 'hide_comments' ) ) {
			// Keep IE conditionals and our own markers.
			$remove[] = '//comment()[not(starts-with(normalize-space(.),"[if")) and not(starts-with(normalize-space(.),"<![endif")) and not(starts-with(normalize-space(.),"ss-"))]';
		}

		foreach ( $remove as $query ) {
			foreach ( iterator_to_array( $xpath->query( $query ) ) as $node ) {
				$node->parentNode->removeChild( $node );
			}
		}

		if ( $options->get( 'hide_comments' ) ) {
			// Inline styles carry `/*# sourceURL=/wp-includes/... */` notes that give WordPress away.
			foreach ( $xpath->query( '//style' ) as $style ) {
				if ( $style->firstChild instanceof \DOMText ) {
					$style->firstChild->data = preg_replace( '#/\*\#\s*sourceURL=[^*]*\*/#', '', $style->firstChild->data );
				}
			}
		}

		if ( $options->get( 'hide_version' ) ) {
			foreach ( $xpath->query( '//link[@href] | //script[@src]' ) as $node ) {
				$attr = 'link' === $node->nodeName ? 'href' : 'src';
				$node->setAttribute( $attr, self::strip_version( $node->getAttribute( $attr ) ) );
			}
		}

		return $dom;
	}

	/** Drop `ver=` from a URL; other parameters stay. */
	public static function strip_version( $url ) {
		if ( false === strpos( $url, 'ver=' ) ) {
			return $url;
		}
		$fragment = '';
		if ( false !== ( $pos = strpos( $url, '#' ) ) ) {
			$fragment = substr( $url, $pos );
			$url      = substr( $url, 0, $pos );
		}
		$url = preg_replace( '/([?&])ver=[^&]*(&|$)/', '$1', $url );

		return rtrim( preg_replace( '/&$/', '', $url ), '?&' ) . $fragment;
	}

	/** The Hide WP screen's search/replace pairs, applied to every HTML, CSS and JS file. */
	public static function custom_replacements( $content ) {
		$pairs = Options::instance()->get( 'custom_replacements' );
		if ( ! is_string( $content ) || ! is_array( $pairs ) ) {
			return $content;
		}
		foreach ( $pairs as $pair ) {
			if ( ! empty( $pair['search'] ) ) {
				$content = str_replace( $pair['search'], isset( $pair['replace'] ) ? $pair['replace'] : '', $content );
			}
		}

		return $content;
	}
}
