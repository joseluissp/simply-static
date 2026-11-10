<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Changes only" exports for the Cloudflare delivery.
 *
 * The core already has an `update` export that re-fetches only the pages flagged as stale
 * and keeps the previous archive; nothing flags them, though. This class does:
 * before an update starts it finds the posts edited since the last deploy and flags them
 * together with the pages that list them (home, categories, author, dates, feeds, sitemaps).
 * Deleted or unpublished posts are removed from the archive.
 *
 * An update is only allowed on top of a known-good full export (the "baseline"). Anything
 * that changes every page (menus, theme, plugins, URL, Hide WP settings...) invalidates it,
 * and the export silently becomes a full one.
 */
class Cloudflare_Changes {

	const BASELINE  = 'ss_cloudflare_baseline';
	const NEED_FULL = 'ss_cloudflare_need_full';
	const STARTED   = 'ss_cloudflare_export_started';

	/** Posts flagged per update; more than this is a redesign, not an edit. */
	const MAX_POSTS = 2000;

	public static function init() {
		add_filter( 'ss_export_type', array( __CLASS__, 'prepare' ) );
		add_action( 'ss_before_static_export', array( __CLASS__, 'remember_start' ) );

		// Things that touch every page: the next export has to be a full one.
		foreach ( array( 'wp_update_nav_menu', 'customize_save_after', 'switch_theme', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'update_option_sidebars_widgets', 'update_option_permalink_structure' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'need_full' ) );
		}
	}

	public static function need_full() {
		if ( Cloudflare::enabled() ) {
			update_option( self::NEED_FULL, time(), false );
		}
	}

	public static function remember_start() {
		update_option( self::STARTED, time(), false );
	}

	/**
	 * Settings that change the content of every page; if they differ from the last full export, so must the next one.
	 *
	 * @return string
	 */
	public static function fingerprint() {
		$s       = Cloudflare::settings();
		$options = Options::instance();
		$parts   = array(
			Cloudflare::destination( 'destination_host', '' ),
			$s['html_handling'],
			$s['not_found_handling'],
			(int) $s['forms'],
			(int) $s['search'],
			(int) $s['hide_wp'],
		);
		foreach ( Hide_WP::OPTIONS as $name ) {
			$parts[] = wp_json_encode( $options->get( $name ) );
		}

		return md5( wp_json_encode( $parts ) );
	}

	/** Whether an update can safely sit on top of the archive from the last deploy. */
	public static function can_update() {
		$base = get_option( self::BASELINE );
		if ( ! is_array( $base ) || empty( $base['archive_name'] ) ) {
			return false;
		}

		$options = Options::instance();
		if ( $options->get( 'archive_name' ) !== $base['archive_name'] || ! is_file( $options->get_archive_dir() . 'index.html' ) ) {
			return false;
		}

		return $base['fingerprint'] === self::fingerprint() && ! get_option( self::NEED_FULL );
	}

	/**
	 * Filter for the export type: keeps "update" when it is safe (flagging what changed), else a full export.
	 *
	 * @param string $type Requested type.
	 *
	 * @return string
	 */
	public static function prepare( $type ) {
		if ( 'update' !== $type || ! Cloudflare::enabled() ) {
			return $type;
		}
		if ( ! self::can_update() ) {
			return 'export';
		}

		$base = get_option( self::BASELINE );
		self::flag_changes( (int) $base['time'] );

		return 'update';
	}

	/** Called after a successful deploy: the archive is now a complete, published site. */
	public static function record_baseline() {
		$options = Options::instance();
		$started = (int) get_option( self::STARTED, time() );

		update_option( self::BASELINE, array(
			'archive_name' => $options->get( 'archive_name' ),
			'fingerprint'  => self::fingerprint(),
			'time'         => $started,
		), false );

		if ( (int) get_option( self::NEED_FULL ) <= $started ) {
			delete_option( self::NEED_FULL );
		}
	}

	/**
	 * Flag every page an edit could have changed, remove the ones that no longer exist.
	 *
	 * @param int $since Unix time of the last deploy's start.
	 *
	 * @return int Number of changed posts.
	 */
	public static function flag_changes( $since ) {
		global $wpdb;

		$types = array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) );
		if ( ! $types ) {
			return 0;
		}

		$since_gmt    = gmdate( 'Y-m-d H:i:s', $since );
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_modified_gmt > %s LIMIT " . self::MAX_POSTS, array_merge( $types, array( $since_gmt ) ) ) );
		// Trashed posts leave no row in wp_posts' "publish" set, but Page rows still point at them.
		$gone = $wpdb->get_col( $wpdb->prepare( "SELECT p.post_id FROM " . Page::table_name() . " p LEFT JOIN {$wpdb->posts} w ON w.ID = p.post_id WHERE p.post_id > 0 AND ( w.ID IS NULL OR ( w.post_status <> 'publish' AND w.post_type <> %s ) )", 'attachment' ) );

		$exact    = array();
		$prefixes = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				$gone[] = $id;
				continue;
			}
			$permalink = get_permalink( $post );
			self::drop_stale( $post->ID, $permalink );
			self::ensure_page( $permalink, $post->ID );
			$exact[] = $permalink;

			foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
				if ( ! $taxonomy->public ) {
					continue;
				}
				foreach ( (array) wp_get_object_terms( $post->ID, $taxonomy->name ) as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$prefixes[] = $link;
					}
				}
			}
			$prefixes[] = get_author_posts_url( $post->post_author );
			$prefixes[] = get_year_link( (int) mysql2date( 'Y', $post->post_date ) );
			if ( get_post_type_archive_link( $post->post_type ) ) {
				$prefixes[] = get_post_type_archive_link( $post->post_type );
			}
		}

		foreach ( array_unique( array_map( 'intval', $gone ) ) as $post_id ) {
			self::remove_post_pages( $post_id );
		}

		if ( $ids || $gone ) {
			// The front page and everything listing posts.
			$home       = home_url( '/' );
			$exact[]    = $home;
			$prefixes[] = $home . 'page/';
			$prefixes[] = $home . 'feed/';
			$prefixes[] = $home . 'comments/';
		}

		$exact    = apply_filters( 'ss_cloudflare_changed_urls', array_unique( array_filter( $exact ) ), $ids );
		$prefixes = array_unique( array_filter( $prefixes ) );
		self::mark_urls( $exact, $prefixes );

		return count( $ids );
	}

	/** Flag pages for re-fetching: exact URLs, URL prefixes (term archives with their paging/feeds) and sitemaps. */
	protected static function mark_urls( array $exact, array $prefixes ) {
		global $wpdb;

		$table = Page::table_name();
		foreach ( $exact as $url ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_checked_at = NULL WHERE url = %s", $url ) );
		}
		foreach ( $prefixes as $prefix ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_checked_at = NULL WHERE url = %s OR url LIKE %s", $prefix, $wpdb->esc_like( $prefix ) . '%' ) );
		}
		if ( $exact || $prefixes ) {
			$wpdb->query( "UPDATE {$table} SET last_checked_at = NULL WHERE url LIKE '%sitemap%'" );
		}
	}

	/** A post published since the last full export has no row yet, and discovery does not run on updates. */
	protected static function ensure_page( $url, $post_id ) {
		$page = Page::query()->find_or_initialize_by( 'url', $url );
		if ( ! $page->id ) {
			$page->post_id     = $post_id;
			$page->found_on_id = 0;
			$page->set_status_message( __( 'Changed content', 'simply-static' ) );
			$page->save();
		}
	}

	/** Slug changed: the old address must not stay online. */
	protected static function drop_stale( $post_id, $permalink ) {
		global $wpdb;

		$table = Page::table_name();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, file_path FROM {$table} WHERE post_id = %d AND url <> %s", $post_id, $permalink ) );
		self::delete_rows( $rows );
	}

	protected static function remove_post_pages( $post_id ) {
		global $wpdb;

		$table = Page::table_name();
		self::delete_rows( $wpdb->get_results( $wpdb->prepare( "SELECT id, file_path FROM {$table} WHERE post_id = %d", $post_id ) ) );
	}

	protected static function delete_rows( $rows ) {
		global $wpdb;

		$dir = Options::instance()->get_archive_dir();
		foreach ( (array) $rows as $row ) {
			$file = $row->file_path ? $dir . ltrim( $row->file_path, '/' ) : '';
			// Only inside the archive, and never the whole site by accident.
			if ( $file && 0 === strpos( realpath( dirname( $file ) ) ?: '', rtrim( $dir, '/' ) ) && is_file( $file ) ) {
				@unlink( $file );
			}
			$wpdb->delete( Page::table_name(), array( 'id' => $row->id ) );
		}
	}
}
