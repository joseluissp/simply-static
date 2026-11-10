<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes Cloudflare's `_headers` and `_redirects` into the export, so a WordPress site
 * gets sane security/cache headers and keeps its redirects without any manual file.
 *
 * Rules in a `_headers` file ADD UP when several match (same header values get joined),
 * so the cache rules below are deliberately disjoint.
 *
 * @link https://developers.cloudflare.com/workers/static-assets/headers/
 * @link https://developers.cloudflare.com/workers/static-assets/redirects/
 */
class Cloudflare_Edge_Files {

	const MAX_REDIRECTS = 2000;

	const MARKER = '# --- Simply Static ---';

	/**
	 * @param string $dir Archive directory (with trailing slash).
	 *
	 * @return int Number of redirects written.
	 */
	public static function write( $dir ) {
		$settings = Cloudflare::settings();

		self::merge( $dir . '_headers', self::headers_file( $settings['extra_headers'] ) );

		$rules = array_merge( self::parse_rules( $settings['extra_redirects'] ), self::redirection_plugin_rules() );
		$rules = apply_filters( 'ss_cloudflare_redirects', $rules );
		$file  = self::redirects_file( $rules );
		if ( $file ) {
			self::merge( $dir . '_redirects', $file );
		}

		return count( self::parse_rules( $file ) );
	}

	/**
	 * @param string $extra Extra rules typed by the user, appended as-is.
	 *
	 * @return string
	 */
	public static function headers_file( $extra = '' ) {
		$file = <<<'TXT'
/*
  X-Content-Type-Options: nosniff
  Referrer-Policy: strict-origin-when-cross-origin
  X-Frame-Options: SAMEORIGIN
  Permissions-Policy: camera=(), microphone=(), geolocation=()

TXT;

		// Folders may carry other names (Hide WP): the cache rules must follow them.
		$cache = array(
			'/wp-content/uploads/*' => 'public, max-age=31536000, immutable',
			'/wp-content/themes/*'  => 'public, max-age=604800',
			'/wp-content/plugins/*' => 'public, max-age=604800',
			'/wp-includes/*'        => 'public, max-age=604800',
		);
		foreach ( $cache as $path => $control ) {
			$file .= Util::replace_wordpress_path_with_public_path( $path ) . "\n  Cache-Control: " . $control . "\n";
		}

		return trim( $file . "\n" . trim( (string) $extra ) ) . "\n";
	}

	/**
	 * Exact (non-regex) redirects only, which is what `_redirects` supports without placeholders.
	 *
	 * @param array $rules List of [ from, to, status ].
	 *
	 * @return string Empty when there is nothing valid.
	 */
	public static function redirects_file( array $rules ) {
		$lines = array();
		$seen  = array();

		foreach ( $rules as $rule ) {
			list( $from, $to ) = array( trim( (string) $rule[0] ), trim( (string) $rule[1] ) );
			$status            = isset( $rule[2] ) ? (int) $rule[2] : 301;

			$valid = '' !== $from && '/' === $from[0] && ! preg_match( '/[\s?*:#]/', $from )
				&& ( '/' === ( $to[0] ?? '' ) || preg_match( '#^https?://\S+$#', $to ) ) && ! preg_match( '/\s/', $to )
				&& in_array( $status, array( 301, 302, 303, 307, 308 ), true )
				&& $from !== $to && ! isset( $seen[ $from ] );
			if ( ! $valid ) {
				continue;
			}

			$seen[ $from ] = true;
			$lines[]       = "$from $to $status";
		}

		// ponytail: over the limit we cut the tail; per-rule priority only if someone hits 2000 redirects.
		return $lines ? implode( "\n", array_slice( $lines, 0, self::MAX_REDIRECTS ) ) . "\n" : '';
	}

	/**
	 * "from to [status]" per line; blank lines and # comments are ignored.
	 *
	 * @return array List of [ from, to, status ].
	 */
	public static function parse_rules( $text ) {
		$rules = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$parts   = preg_split( '/\s+/', $line );
			$rules[] = array( $parts[0], $parts[1] ?? '', $parts[2] ?? 301 );
		}

		return $rules;
	}

	/** Enabled, plain-URL redirects from the Redirection plugin (the common WordPress choice). */
	protected static function redirection_plugin_rules() {
		global $wpdb;

		$table = $wpdb->prefix . 'redirection_items';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		$rows = $wpdb->get_results( "SELECT url, action_data, action_code FROM {$table} WHERE status = 'enabled' AND regex = 0 AND match_type = 'url' AND action_type = 'url' AND action_code IN (301,302,307,308) LIMIT 5000", ARRAY_A );
		$home = untrailingslashit( home_url() );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			// Absolute links to this very site become paths, so they survive a domain change.
			$to = 0 === strpos( $row['action_data'], $home . '/' ) ? substr( $row['action_data'], strlen( $home ) ) : $row['action_data'];
			// The plugin matches a path with or without query; `_redirects` cannot do queries (skipped by redirects_file).
			$out[] = array( $row['url'], $to, $row['action_code'] );
		}

		return $out;
	}

	/** Append our block after whatever the site already ships; a previous run's block is replaced. */
	public static function merge( $path, $content ) {
		$existing = is_file( $path ) ? (string) file_get_contents( $path ) : '';
		$at       = strpos( $existing, self::MARKER );
		if ( false !== $at ) {
			$existing = substr( $existing, 0, $at );
		}

		file_put_contents( $path, ( trim( $existing ) ? rtrim( $existing ) . "\n\n" : '' ) . self::MARKER . "\n" . $content );
	}
}
