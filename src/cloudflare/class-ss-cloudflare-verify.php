<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks at the live site right after a deploy, and judges it.
 *
 * It requests the home page, a few of the pages and a few of the assets that were just uploaded
 * and fails only on proof the published version is broken: a server error, a page served as a
 * download (octet-stream), or an empty page. Anything ambiguous (network errors, 404/403 that can
 * come from routing or DNS not being ready) is only a warning, because rolling back a good deploy
 * is worse than keeping a doubtful one.
 */
class Cloudflare_Verify {

	/** Pages and assets (each) that are requested besides the home page. */
	const SAMPLE = 2;

	/** Tries per URL: the new version needs a moment to reach the edge. */
	const ATTEMPTS = 3;

	/** Seconds between tries and the most the whole check may take. */
	const PAUSE  = 2;
	const BUDGET = 40;

	/** Cloudflare Workers Free allows this many files per version (Paid: 100,000). */
	const FREE_FILE_LIMIT = 20000;

	/** `_headers` accepts this many rules. */
	const HEADER_RULES_LIMIT = 100;

	/** Kill switch, no UI: `add_filter( 'ss_cloudflare_verify_deploy', '__return_false' )`. */
	public static function enabled() {
		return (bool) apply_filters( 'ss_cloudflare_verify_deploy', true );
	}

	/**
	 * What to request: the home page plus a few of the files this deploy uploaded.
	 *
	 * @param array $uploaded Map of relative path => mime type of the uploaded files.
	 *
	 * @return array List of [ path, expected type, method ].
	 */
	public static function targets( array $uploaded ) {
		$targets = array( array( '/', 'text/html', 'GET' ) );
		$pages   = 0;
		$assets  = 0;

		foreach ( $uploaded as $path => $type ) {
			$path = (string) $path;
			if ( 'index.html' === basename( $path ) ) {
				$dir = dirname( $path );
				if ( '.' !== $dir && $pages < self::SAMPLE ) {
					$targets[] = array( '/' . self::encode( $dir ) . '/', 'text/html', 'GET' );
					$pages ++;
				}
			} elseif ( 'text/html' !== $type && $assets < self::SAMPLE ) {
				$targets[] = array( '/' . self::encode( $path ), (string) $type, 'HEAD' );
				$assets ++;
			}
		}

		return $targets;
	}

	protected static function encode( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/**
	 * Judge one response.
	 *
	 * @param array|\WP_Error $response [ code, type, bytes ] or the transport error.
	 * @param string          $expected Expected content type.
	 * @param string          $method   GET (the body counts) or HEAD.
	 *
	 * @return array [ 'ok' | 'warn' | 'fail', message ]
	 */
	public static function judge( $response, $expected, $method ) {
		if ( is_wp_error( $response ) ) {
			return array( 'warn', $response->get_error_message() );
		}

		$code = (int) $response['code'];
		$type = strtolower( trim( explode( ';', (string) $response['type'] )[0] ) );

		if ( $code >= 500 ) {
			return array( 'fail', "HTTP $code" );
		}
		if ( 200 !== $code ) {
			return array( 'warn', "HTTP $code" );
		}
		if ( 'application/octet-stream' === $type && 'application/octet-stream' !== $expected ) {
			return array( 'fail', 'served as a download (application/octet-stream)' );
		}
		if ( 'text/html' === $expected && 0 !== strpos( $type, 'text/html' ) ) {
			return array( 'fail', 'served as ' . ( $type ?: 'an unknown type' ) . ' instead of a web page' );
		}
		if ( 'GET' === $method && 'text/html' === $expected && ! $response['bytes'] ) {
			return array( 'fail', 'empty page' );
		}

		return array( 'ok', '' );
	}

	/**
	 * Request every target and sum it up.
	 *
	 * @param string        $base     Live site address.
	 * @param array         $targets  From targets().
	 * @param callable|null $fetch    function( $url, $method ) => [ code, type, bytes ]|\WP_Error (tests).
	 * @param int           $attempts Tries per URL.
	 * @param int           $pause    Seconds between tries.
	 *
	 * @return array [ 'state' => ok|warn|fail, 'problems' => [ "url: why" ] ]
	 */
	public static function check( $base, array $targets, $fetch = null, $attempts = self::ATTEMPTS, $pause = self::PAUSE ) {
		$fetch    = $fetch ?: array( __CLASS__, 'fetch' );
		$deadline = time() + self::BUDGET;
		$state    = 'ok';
		$problems = array();

		foreach ( $targets as $i => $target ) {
			list( $path, $expected, $method ) = $target;
			$url                              = rtrim( $base, '/' ) . $path;

			for ( $try = 1; $try <= $attempts; $try ++ ) {
				list( $verdict, $why ) = self::judge( call_user_func( $fetch, $url, $method ), $expected, $method );
				if ( 'ok' === $verdict || $try === $attempts || time() >= $deadline ) {
					break;
				}
				if ( $pause ) {
					sleep( $pause );
				}
			}

			if ( 'ok' !== $verdict ) {
				$problems[] = $path . ': ' . $why;
				$state      = 'fail' === $verdict || 'fail' === $state ? 'fail' : 'warn';
			}
			// Home unreachable (DNS, workers.dev still warming up): the rest would only repeat it.
			if ( 0 === $i && 'warn' === $verdict ) {
				break;
			}
		}

		return array( 'state' => $state, 'problems' => $problems );
	}

	/** One real request; the cache-busting query is ignored by static assets. */
	public static function fetch( $url, $method ) {
		$args     = array(
			'timeout'     => 8,
			'redirection' => 3,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
			'user-agent'  => 'Simply Static deploy check',
		);
		$url      = add_query_arg( 'ss-verify', time(), $url );
		$response = 'HEAD' === $method ? wp_remote_head( $url, $args ) : wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $response ),
			'type'  => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'bytes' => strlen( (string) wp_remote_retrieve_body( $response ) ),
		);
	}

	/**
	 * Limits Cloudflare enforces that we can see coming. Only warnings: the plan is unknown,
	 * and Cloudflare gives the final answer.
	 *
	 * @param int    $files   Files in the site.
	 * @param string $headers Contents of `_headers`.
	 *
	 * @return array List of messages.
	 */
	public static function preflight( $files, $headers ) {
		$warnings = array();
		if ( $files > self::FREE_FILE_LIMIT ) {
			$warnings[] = sprintf( 'The site has %d files; the Workers Free plan accepts %d per version.', $files, self::FREE_FILE_LIMIT );
		}

		$rules = preg_match_all( '/^\S.*$/m', preg_replace( '/^#.*$/m', '', (string) $headers ) );
		if ( $rules > self::HEADER_RULES_LIMIT ) {
			$warnings[] = sprintf( '_headers has %d rules; Cloudflare reads %d.', $rules, self::HEADER_RULES_LIMIT );
		}

		return $warnings;
	}
}
