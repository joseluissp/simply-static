<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 301 redirects for pages whose address changed.
 *
 * A static site has no WordPress to redirect old slugs, so the old address would turn into a 404.
 * Whenever a published post changes address (slug, parent...) or leaves "published", its old path
 * is remembered together with the post ID. Every deploy turns that memory into `_redirects` lines
 * pointing at the post's CURRENT address, so chains (A -> B -> C) always end at C.
 *
 * Nothing is redirected when the old path serves something again (the slug was reverted or reused
 * by another page) or when the post is no longer published: a deleted page stays a 404 instead of
 * a redirect to the home page, which search engines treat as a soft 404.
 */
class Cloudflare_Redirects {

	const OPTION = 'ss_cloudflare_moved';

	/** Old addresses remembered (the oldest are forgotten first). */
	const MAX = 1000;

	public static function init() {
		add_action( 'post_updated', array( __CLASS__, 'on_update' ), 10, 3 );
		add_filter( 'ss_cloudflare_redirects', array( __CLASS__, 'add_rules' ) );
	}

	/**
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   Post after the update.
	 * @param \WP_Post $before  Post before the update.
	 */
	public static function on_update( $post_id, $after, $before ) {
		if ( ! Cloudflare::enabled() || 'publish' !== $before->post_status || ! is_post_type_viewable( $before->post_type ) ) {
			return;
		}

		$old = static::path( static::permalink( $before ) );
		$new = 'publish' === $after->post_status ? static::path( static::permalink( $after ) ) : '';

		// '/' is the front page (or a site without pretty permalinks): never a redirect source.
		if ( '' === $old || '/' === $old || $old === $new ) {
			return;
		}

		$moved = (array) get_option( self::OPTION, array() );
		unset( $moved[ $old ] ); // Re-recorded last, so it is the newest.
		$moved[ $old ] = (int) $post_id;

		update_option( self::OPTION, array_slice( $moved, -self::MAX, null, true ), false );
	}

	/**
	 * Filter `ss_cloudflare_redirects`: our rules go after the ones the user wrote.
	 *
	 * @param array $rules List of [ from, to, status ].
	 *
	 * @return array
	 */
	public static function add_rules( $rules ) {
		foreach ( (array) get_option( self::OPTION, array() ) as $from => $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$to = static::path( static::permalink( $post ) );
			// Something lives at the old address again: never hide it behind a redirect.
			if ( '' === $to || $to === $from || url_to_postid( home_url( $from ) ) ) {
				continue;
			}

			$rules[] = array( $from, $to, 301 );
			// The static site answers with and without the trailing slash.
			$rules[] = array( '/' === substr( $from, -1 ) ? rtrim( $from, '/' ) : $from . '/', $to, 301 );
		}

		return $rules;
	}

	/** Address of a post as it is in the object (the "before" post still has its old slug). */
	protected static function permalink( $post ) {
		return get_permalink( $post );
	}

	/** Path of a URL relative to the site root, '' when it has none. */
	protected static function path( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return '' !== $path && '/' === $path[0] ? $path : '';
	}
}
