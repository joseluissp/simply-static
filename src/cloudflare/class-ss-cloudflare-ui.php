<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the user sees whether the live site is up to date: the admin bar and the editor.
 *
 * - Admin bar: the Pro/Studio upsell entries are removed; with Cloudflare delivery it shows
 *   the publish state and a link straight to the live site.
 * - Editor: the disabled Pro "Push" teaser is replaced by a real button that shows whether
 *   this page is on the live site (Pushed), changed since (Push) or still unsaved, and
 *   publishes the pending changes when clicked.
 *
 * "Up to date" means: published, and not modified after the start of the last export that
 * reached Cloudflare (the baseline recorded by Cloudflare_Changes).
 */
class Cloudflare_Ui {

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 101 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'editor_script' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ------------------------------------------------------------- state */

	/** Base URL of the live site, '' until the first deploy. */
	public static function live_url() {
		$status = get_option( Cloudflare::STATUS, array() );
		if ( ! empty( $status['domain_ok'] ) ) {
			return 'https://' . $status['domain_ok'];
		}

		return ! empty( $status['url'] ) ? untrailingslashit( $status['url'] ) : '';
	}

	/** Unix time the last successful publish started, 0 if none. */
	protected static function last_publish() {
		$base = get_option( Cloudflare_Changes::BASELINE, array() );

		return is_array( $base ) && ! empty( $base['time'] ) ? (int) $base['time'] : 0;
	}

	protected static function is_running() {
		try {
			$job = Plugin::instance()->get_archive_creation_job();

			return method_exists( $job, 'is_running' ) && ( $job->is_running() || ( method_exists( $job, 'is_paused' ) && $job->is_paused() ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whole-site state: off | never | running | pending | synced, plus how many pages changed.
	 *
	 * @return array
	 */
	public static function site_state() {
		$s = Cloudflare::settings();
		if ( ! Cloudflare::enabled() || empty( $s['token'] ) ) {
			return array( 'state' => 'off', 'pending' => 0, 'url' => '' );
		}

		$last    = self::last_publish();
		$url     = self::live_url();
		$queued  = (bool) wp_next_scheduled( Cloudflare::HOOK );
		$pending = 0;

		if ( $last ) {
			$query   = new \WP_Query( array(
				'post_type'      => array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => array( array( 'column' => 'post_modified_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $last ) ) ),
				'no_found_rows'  => false,
			) );
			$pending = (int) $query->found_posts;
		}

		if ( self::is_running() ) {
			$state = 'running';
		} elseif ( ! $last ) {
			$state = 'never';
		} elseif ( $pending || $queued || get_option( Cloudflare_Changes::NEED_FULL ) ) {
			$state = 'pending';
		} else {
			$state = 'synced';
		}

		return array( 'state' => $state, 'pending' => $pending, 'url' => $url );
	}

	/** State of one post: unpublished | the site state, except that an untouched page of a pending site is still synced. */
	public static function post_state( $post ) {
		$site = self::site_state();
		if ( in_array( $site['state'], array( 'off', 'running' ), true ) ) {
			return $site['state'];
		}
		if ( 'publish' !== $post->post_status ) {
			return 'unpublished';
		}
		$last = self::last_publish();
		if ( ! $last ) {
			return 'never';
		}

		return strtotime( $post->post_modified_gmt . ' UTC' ) > $last ? 'pending' : 'synced';
	}

	/* --------------------------------------------------------- admin bar */

	public static function admin_bar( $admin_bar ) {
		// Never show the upsells (this fork has no Pro/Studio).
		$admin_bar->remove_node( 'ss-admin-bar-pro-upsell' );
		$admin_bar->remove_node( 'ss-admin-bar-studio-upsell' );

		$site = self::site_state();
		if ( 'off' === $site['state'] || ! $admin_bar->get_node( 'ss-admin-bar' ) ) {
			return;
		}

		$labels = array(
			'never'   => __( 'Not published yet', 'simply-static' ),
			'running' => __( 'Publishing…', 'simply-static' ),
			'synced'  => __( 'Live site is up to date', 'simply-static' ),
			'pending' => $site['pending']
				/* translators: %d: number of changed pages. */
				? sprintf( _n( '%d change not published yet', '%d changes not published yet', $site['pending'], 'simply-static' ), $site['pending'] )
				: __( 'Changes not published yet', 'simply-static' ),
		);
		$icons  = array( 'never' => '○', 'running' => '↻', 'synced' => '●', 'pending' => '●' );
		$colors = array( 'never' => '#a0a5aa', 'running' => '#46b450', 'synced' => '#46b450', 'pending' => '#ffb900' );

		$admin_bar->add_node( array(
			'id'     => 'ss-admin-bar-cf-state',
			'parent' => 'ss-admin-bar',
			'title'  => '<span style="color:' . esc_attr( $colors[ $site['state'] ] ) . '">' . $icons[ $site['state'] ] . '</span> ' . esc_html( $labels[ $site['state'] ] ),
			'href'   => get_admin_url() . 'admin.php?page=simply-static-generate',
		) );

		if ( $site['url'] ) {
			$admin_bar->add_node( array(
				'id'     => 'ss-admin-bar-cf-visit',
				'parent' => 'ss-admin-bar',
				'title'  => esc_html__( 'Open live site', 'simply-static' ) . ' ↗',
				'href'   => $site['url'],
				'meta'   => array( 'target' => '_blank', 'rel' => 'noopener', 'title' => $site['url'] ),
			) );
		}
	}

	/* ------------------------------------------------------------ editor */

	public static function can_push() {
		return current_user_can( apply_filters( 'ss_user_capability', 'publish_pages', 'generate' ) );
	}

	public static function editor_script( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// The Pro teaser is a disabled button with an upsell tooltip.
		wp_dequeue_script( 'ss-single-push-teaser' );

		$post = get_post();
		if ( ! $post || ! self::can_push() || 'off' === self::site_state()['state'] || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		wp_enqueue_script( 'ss-cloudflare-push', SIMPLY_STATIC_URL . '/assets/ss-cloudflare-push.js', array(), SIMPLY_STATIC_VERSION, true );
		wp_localize_script( 'ss-cloudflare-push', 'ssCloudflarePush', array(
			'api'    => rest_url( 'simplystatic/v1/cloudflare/' ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'postId' => (int) $post->ID,
			'text'   => array(
				'push'        => __( 'Push', 'simply-static' ),
				'pushed'      => __( 'Pushed', 'simply-static' ),
				'pushing'     => __( 'Pushing…', 'simply-static' ),
				'unsaved'     => __( 'Unsaved', 'simply-static' ),
				'unpublished' => __( 'Not published', 'simply-static' ),
				'tipPush'     => __( 'This page changed after it went live. Click to publish the pending changes to Cloudflare.', 'simply-static' ),
				'tipPushed'   => __( 'This page is on the live site.', 'simply-static' ),
				'tipPushing'  => __( 'Publishing to Cloudflare…', 'simply-static' ),
				'tipUnsaved'  => __( 'Save the changes first; only saved content is pushed.', 'simply-static' ),
				'tipUnpub'    => __( 'Publish the page in WordPress first.', 'simply-static' ),
				'tipError'    => __( 'Could not reach the server. Try again.', 'simply-static' ),
			),
		) );
	}

	/* -------------------------------------------------------------- REST */

	public static function routes() {
		$can = array( __CLASS__, 'can_push' );
		register_rest_route( 'simplystatic/v1', '/cloudflare/post-status', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_post_status' ), 'permission_callback' => $can,
		) );
		register_rest_route( 'simplystatic/v1', '/cloudflare/push', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_push' ), 'permission_callback' => $can,
		) );
	}

	public static function rest_post_status( \WP_REST_Request $request ) {
		$post = get_post( (int) $request->get_param( 'post_id' ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'ss_cloudflare_post', __( 'Invalid page.', 'simply-static' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array_merge( self::site_state(), array( 'state' => self::post_state( $post ) ) ) );
	}

	/** Publish what changed now instead of waiting for the debounce. */
	public static function rest_push() {
		if ( 'off' === self::site_state()['state'] ) {
			return new \WP_Error( 'ss_cloudflare_off', __( 'Cloudflare is not set up.', 'simply-static' ), array( 'status' => 400 ) );
		}
		wp_clear_scheduled_hook( Cloudflare::HOOK );
		Cloudflare::autopublish();

		return rest_ensure_response( array( 'ok' => true ) );
	}
}
