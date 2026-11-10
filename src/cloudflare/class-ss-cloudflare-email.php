<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails the site owner every form submission, set up by the plugin on Cloudflare.
 *
 * Cloudflare only lets a Worker send mail from an address on one of the account's own
 * domains, to an address its owner confirmed once. So the chain is, all automatic except
 * the confirmation click:
 *   domain in the account -> Email Routing on -> owner address registered -> send_email binding.
 * Before the site has its own domain this reports "needs_domain" and the Worker simply
 * keeps storing submissions in D1.
 *
 * @link https://developers.cloudflare.com/email-service/get-started/route-emails/
 */
class Cloudflare_Email {

	const OPTION = 'ss_cloudflare_email';

	/** @return string Recipient: the setting, else the WordPress admin email. */
	public static function recipient( array $settings ) {
		$to = ! empty( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );

		return is_email( $to ) ? $to : '';
	}

	/**
	 * Prepare everything and report where it stands.
	 *
	 * @param Cloudflare_Client $client   API client.
	 * @param array             $settings Cloudflare settings.
	 *
	 * @return array Worker bindings (empty when mail cannot be sent yet).
	 */
	public static function provision( Cloudflare_Client $client, array $settings ) {
		$to = self::recipient( $settings );

		if ( empty( $settings['notify'] ) || ! $to ) {
			return self::finish( 'off', '', '' );
		}
		if ( empty( $settings['domain'] ) ) {
			return self::finish( 'needs_domain', $to, '' );
		}

		$zone = $client->zone_for( $settings['domain'] );
		if ( is_wp_error( $zone ) ) {
			return self::finish( 'needs_zone', $to, '', $zone->get_error_message() );
		}
		if ( 'active' !== $zone['status'] ) {
			return self::finish( 'zone_pending', $to, '', $zone['name'] );
		}

		$on = $client->email_routing_enabled( $zone['id'] );
		if ( ! is_wp_error( $on ) && ! $on ) {
			$on = $client->email_routing_enable( $zone['id'] );
		}
		if ( is_wp_error( $on ) ) {
			// Typically: the domain already receives mail somewhere else (its MX records stay untouched).
			return self::finish( 'error', $to, '', $on->get_error_message() );
		}

		$destination = $client->email_destination( $to );
		if ( is_wp_error( $destination ) ) {
			return self::finish( 'error', $to, '', $destination->get_error_message() );
		}

		$from = 'noreply@' . $zone['name'];

		return self::finish( $destination['verified'] ? 'ready' : 'pending_verification', $to, $from );
	}

	/** Saves the state and returns the bindings the Worker needs. */
	protected static function finish( $state, $to, $from, $detail = '' ) {
		update_option( self::OPTION, array( 'state' => $state, 'to' => $to, 'from' => $from, 'detail' => $detail ), false );

		return self::bindings( $state, $to, $from );
	}

	/**
	 * @return array Bindings; present from the moment the address is registered, so confirming it
	 *               later works without another deploy.
	 */
	public static function bindings( $state, $to, $from ) {
		if ( ! in_array( $state, array( 'ready', 'pending_verification' ), true ) ) {
			return array();
		}

		return array(
			array( 'name' => 'EMAIL', 'type' => 'send_email', 'allowed_destination_addresses' => array( $to ) ),
			array( 'name' => 'MAIL_TO', 'type' => 'plain_text', 'text' => $to ),
			array( 'name' => 'MAIL_FROM', 'type' => 'plain_text', 'text' => $from ),
			array( 'name' => 'MAIL_SITE', 'type' => 'plain_text', 'text' => wp_strip_all_tags( get_bloginfo( 'name' ) ) ),
		);
	}

	/** For the admin screen. */
	public static function status() {
		return wp_parse_args( get_option( self::OPTION, array() ), array( 'state' => 'off', 'to' => '', 'from' => '', 'detail' => '' ) );
	}
}
