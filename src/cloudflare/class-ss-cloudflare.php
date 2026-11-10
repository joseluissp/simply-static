<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare Workers delivery method: settings, task registration and admin screen.
 *
 * Self-contained on purpose: the only core change is the require/init line in
 * Plugin, so upstream merges stay conflict-free.
 *
 * Credentials may live in wp-config.php (SIMPLY_STATIC_CF_ACCOUNT_ID,
 * SIMPLY_STATIC_CF_API_TOKEN, SIMPLY_STATIC_CF_WORKER); constants win over the screen.
 */
class Cloudflare {

	const OPTION = 'ss_cloudflare';
	const SLUG   = 'simply-static-cloudflare';

	public static function init() {
		add_filter( 'simplystatic.archive_creation_job.task_list', array( __CLASS__, 'task_list' ), 20, 2 );
		add_filter( 'ss_get_option_delivery_method', array( __CLASS__, 'delivery_method' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'admin_post_ss_cloudflare_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ss_cloudflare_test', array( __CLASS__, 'test' ) );
	}

	/**
	 * @return array account_id, worker_name, token, enabled, html_handling, not_found_handling
	 */
	public static function settings() {
		$settings = wp_parse_args( get_option( self::OPTION, array() ), array(
			'enabled'            => false,
			'account_id'         => '',
			'worker_name'        => '',
			'token'              => '',
			'html_handling'      => 'auto-trailing-slash',
			'not_found_handling' => '404-page',
		) );

		foreach ( array( 'account_id' => 'SIMPLY_STATIC_CF_ACCOUNT_ID', 'token' => 'SIMPLY_STATIC_CF_API_TOKEN', 'worker_name' => 'SIMPLY_STATIC_CF_WORKER' ) as $key => $constant ) {
			if ( defined( $constant ) ) {
				$settings[ $key ] = constant( $constant );
			}
		}

		return $settings;
	}

	/** Make 'cloudflare' the delivery method when enabled; the React screen keeps working untouched. */
	public static function delivery_method( $method ) {
		$settings = get_option( self::OPTION, array() );

		return ! empty( $settings['enabled'] ) ? 'cloudflare' : $method;
	}

	/** Deliver right before wrap-up. */
	public static function task_list( $task_list, $delivery_method ) {
		if ( 'cloudflare' !== $delivery_method ) {
			return $task_list;
		}

		$index = array_search( 'wrapup', $task_list, true );
		if ( false === $index ) {
			$task_list[] = 'deploy_cloudflare';
		} else {
			array_splice( $task_list, $index, 0, 'deploy_cloudflare' );
		}

		return $task_list;
	}

	public static function menu() {
		add_submenu_page( 'simply-static-generate', 'Cloudflare', 'Cloudflare', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function save() {
		check_admin_referer( 'ss_cloudflare' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'simply-static' ) );
		}

		$old   = get_option( self::OPTION, array() );
		$token = isset( $_POST['token'] ) ? trim( wp_unslash( $_POST['token'] ) ) : '';

		update_option( self::OPTION, array(
			'enabled'            => ! empty( $_POST['enabled'] ),
			'account_id'         => sanitize_text_field( wp_unslash( $_POST['account_id'] ?? '' ) ),
			'worker_name'        => sanitize_key( wp_unslash( $_POST['worker_name'] ?? '' ) ),
			// Blank keeps the stored token: it is never printed back into the form.
			'token'              => '' !== $token ? $token : ( $old['token'] ?? '' ),
			'html_handling'      => in_array( $_POST['html_handling'] ?? '', array( 'auto-trailing-slash', 'force-trailing-slash', 'drop-trailing-slash', 'none' ), true ) ? $_POST['html_handling'] : 'auto-trailing-slash',
			'not_found_handling' => in_array( $_POST['not_found_handling'] ?? '', array( '404-page', 'single-page-application', 'none' ), true ) ? $_POST['not_found_handling'] : '404-page',
		), false );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&ss_cf=saved' ) );
		exit;
	}

	public static function test() {
		check_admin_referer( 'ss_cloudflare' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'simply-static' ) );
		}

		$s      = self::settings();
		$result = ( new Cloudflare_Client( $s['account_id'], $s['token'], $s['worker_name'] ) )->verify();

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::SLUG, 'ss_cf' => is_wp_error( $result ) ? 'fail' : 'ok', 'ss_cf_msg' => is_wp_error( $result ) ? $result->get_error_message() : '' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function render() {
		$s      = self::settings();
		$status = isset( $_GET['ss_cf'] ) ? sanitize_key( $_GET['ss_cf'] ) : '';
		$field  = function ( $label, $name, $value, $type = 'text', $hint = '' ) {
			printf(
				'<tr><th><label for="%1$s">%2$s</label></th><td><input class="regular-text" id="%1$s" name="%1$s" type="%3$s" value="%4$s" autocomplete="off"><p class="description">%5$s</p></td></tr>',
				esc_attr( $name ), esc_html( $label ), esc_attr( $type ), esc_attr( $value ), esc_html( $hint )
			);
		};
		$select = function ( $label, $name, $value, array $options ) {
			echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $options as $option ) {
				printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), selected( $value, $option, false ) );
			}
			echo '</select></td></tr>';
		};

		echo '<div class="wrap"><h1>Cloudflare</h1>';
		if ( 'saved' === $status ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'simply-static' ) . '</p></div>';
		} elseif ( 'ok' === $status ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Connection works.', 'simply-static' ) . '</p></div>';
		} elseif ( 'fail' === $status ) {
			echo '<div class="notice notice-error"><p>' . esc_html( isset( $_GET['ss_cf_msg'] ) ? wp_unslash( $_GET['ss_cf_msg'] ) : '' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ss_cloudflare' );
		echo '<input type="hidden" name="action" value="ss_cloudflare_save"><table class="form-table">';
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="enabled" value="1"%s> %s</label></td></tr>',
			esc_html__( 'Delivery', 'simply-static' ), checked( ! empty( $s['enabled'] ), true, false ), esc_html__( 'Deploy exports to Cloudflare Workers', 'simply-static' )
		);
		$field( 'Account ID', 'account_id', $s['account_id'] );
		$field( 'Worker name', 'worker_name', $s['worker_name'], 'text', __( 'Created on first deploy. Lowercase letters, numbers and dashes.', 'simply-static' ) );
		$field( 'API token', 'token', '', 'password', $s['token'] ? __( 'A token is stored. Leave blank to keep it. Needs "Workers Scripts: Edit".', 'simply-static' ) : __( 'Needs "Workers Scripts: Edit".', 'simply-static' ) );
		$select( 'html_handling', 'html_handling', $s['html_handling'], array( 'auto-trailing-slash', 'force-trailing-slash', 'drop-trailing-slash', 'none' ) );
		$select( 'not_found_handling', 'not_found_handling', $s['not_found_handling'], array( '404-page', 'single-page-application', 'none' ) );
		echo '</table><p>';
		submit_button( __( 'Save', 'simply-static' ), 'primary', 'submit', false );
		echo ' <button class="button" type="submit" name="action" value="ss_cloudflare_test">' . esc_html__( 'Test connection', 'simply-static' ) . '</button></p></form></div>';
	}
}
