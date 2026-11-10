<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes WordPress forms work on the static site.
 *
 * Every `<form method="post">` pointing at this site is rewritten to post to `/_ss/form/<id>`,
 * which the Worker (worker.js) validates (honeypot, Turnstile) and stores in D1. The D1
 * database and the Turnstile widget are created through the API on first deploy.
 */
class Cloudflare_Forms {

	const OPTION = 'ss_cloudflare_forms';

	const TABLE_SQL = 'CREATE TABLE IF NOT EXISTS ss_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, form_id TEXT NOT NULL, page TEXT, data TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)';

	public static function init() {
		add_filter( 'ss_dom_before_save', array( __CLASS__, 'rewrite_forms' ), 20, 2 );
	}

	public static function enabled() {
		$s = Cloudflare::settings();

		return ! empty( $s['enabled'] ) && ! empty( $s['forms'] );
	}

	/**
	 * @param \DOMDocument|string $dom DOM being exported.
	 * @param string              $url Page URL.
	 *
	 * @return \DOMDocument|string
	 */
	public static function rewrite_forms( $dom, $url ) {
		if ( ! $dom instanceof \DOMDocument || ! self::enabled() ) {
			return $dom;
		}

		$sitekey = (string) ( get_option( self::OPTION, array() )['sitekey'] ?? '' );
		$forms   = $dom->getElementsByTagName( 'form' );
		$changed = false;

		// Iterate over a copy: we add nodes to the live list.
		foreach ( iterator_to_array( $forms ) as $form ) {
			if ( 'post' !== strtolower( $form->getAttribute( 'method' ) ) || ! self::is_local_action( $form->getAttribute( 'action' ) ) ) {
				continue;
			}
			// Logins, searches and comments need PHP: leave them alone rather than break them silently.
			if ( self::needs_php( $form ) ) {
				continue;
			}

			$id = substr( md5( wp_parse_url( $url, PHP_URL_PATH ) . '|' . $form->getAttribute( 'id' ) . '|' . $form->getAttribute( 'class' ) . '|' . self::field_names( $form ) ), 0, 12 );
			$form->setAttribute( 'action', '/_ss/form/' . $id );
			$form->setAttribute( 'data-ss-form', $id );

			self::add_hidden( $dom, $form );
			if ( $sitekey ) {
				$widget = $dom->createElement( 'div' );
				$widget->setAttribute( 'class', 'cf-turnstile' );
				$widget->setAttribute( 'data-sitekey', $sitekey );
				$form->appendChild( $widget );
			}
			$changed = true;
		}

		if ( $changed ) {
			self::add_script( $dom, (bool) $sitekey );
		}

		return $dom;
	}

	/** Action empty (same page) or on this site; third-party forms (Mailchimp...) are untouched. */
	protected static function is_local_action( $action ) {
		if ( '' === $action ) {
			return true;
		}
		$host = wp_parse_url( $action, PHP_URL_HOST );

		return ! $host || strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	protected static function needs_php( \DOMElement $form ) {
		$action = $form->getAttribute( 'action' );
		if ( preg_match( '#wp-login|wp-comments-post|wp-admin|xmlrpc|/\?s=#', $action ) ) {
			return true;
		}
		foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
			if ( in_array( $input->getAttribute( 'name' ), array( 'pwd', 'log', 'comment_post_ID', 'add-to-cart', 'woocommerce-login-nonce' ), true ) || 'password' === $input->getAttribute( 'type' ) ) {
				return true;
			}
		}

		return false;
	}

	protected static function field_names( \DOMElement $form ) {
		$names = array();
		foreach ( array( 'input', 'textarea', 'select' ) as $tag ) {
			foreach ( $form->getElementsByTagName( $tag ) as $field ) {
				$names[] = $field->getAttribute( 'name' );
			}
		}

		return implode( ',', $names );
	}

	/** Honeypot: hidden off-screen so humans never fill it. */
	protected static function add_hidden( \DOMDocument $dom, \DOMElement $form ) {
		$wrap = $dom->createElement( 'div' );
		$wrap->setAttribute( 'style', 'position:absolute;left:-9999px;height:0;overflow:hidden' );
		$wrap->setAttribute( 'aria-hidden', 'true' );
		$input = $dom->createElement( 'input' );
		$input->setAttribute( 'type', 'text' );
		$input->setAttribute( 'name', '_ss_hp' );
		$input->setAttribute( 'tabindex', '-1' );
		$input->setAttribute( 'autocomplete', 'off' );
		$wrap->appendChild( $input );
		$form->appendChild( $wrap );
	}

	/** One tiny script per page: submits via fetch, shows the result next to the form. */
	protected static function add_script( \DOMDocument $dom, $turnstile ) {
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return;
		}

		$js = <<<'JS'
document.addEventListener('submit',function(e){var f=e.target;if(!f.matches||!f.matches('form[data-ss-form]'))return;e.preventDefault();var b=f.querySelector('[type=submit],button:not([type])'),m=f.querySelector('.ss-form-msg');if(!m){m=document.createElement('p');m.className='ss-form-msg';m.setAttribute('role','status');f.appendChild(m);}if(b)b.disabled=true;fetch(f.action,{method:'POST',body:new FormData(f),headers:{Accept:'application/json'}}).then(function(r){return r.json();}).then(function(j){if(j.ok){f.reset();m.textContent=f.getAttribute('data-ss-ok')||'Thank you! Your message has been sent.';}else{m.textContent=f.getAttribute('data-ss-error')||'Something went wrong. Please try again.';if(window.turnstile)turnstile.reset();}}).catch(function(){m.textContent=f.getAttribute('data-ss-error')||'Something went wrong. Please try again.';}).then(function(){if(b)b.disabled=false;});});
JS;

		$script = $dom->createElement( 'script' );
		$script->appendChild( $dom->createTextNode( $js ) );
		$body->appendChild( $script );

		if ( $turnstile ) {
			$ts = $dom->createElement( 'script' );
			$ts->setAttribute( 'src', 'https://challenges.cloudflare.com/turnstile/v0/api.js' );
			$ts->setAttribute( 'async', '' );
			$ts->setAttribute( 'defer', '' );
			$body->appendChild( $ts );
		}
	}

	/**
	 * Create the D1 database + table and the Turnstile widget (once), then hand back the
	 * Worker bindings for them. Existing resources are reused; failures degrade, never abort the deploy.
	 *
	 * @param Cloudflare_Client $client   API client.
	 * @param array             $settings Cloudflare settings.
	 * @param array             $hosts    Public hostnames the site will use (Turnstile domain list).
	 *
	 * @return array|\WP_Error Bindings, plus warnings under the 'warnings' key of the option.
	 */
	public static function provision( Cloudflare_Client $client, array $settings, array $hosts ) {
		$state = wp_parse_args( get_option( self::OPTION, array() ), array( 'd1' => '', 'sitekey' => '', 'secret' => '', 'domains' => array(), 'warnings' => array() ) );
		$state['warnings'] = array();

		if ( ! $state['d1'] ) {
			$id = $client->ensure_d1( $settings['worker_name'] . '-forms' );
			if ( is_wp_error( $id ) ) {
				return $id; // Without storage there is no point in rewriting forms.
			}
			$state['d1'] = $id;
		}
		$table = $client->d1_query( $state['d1'], self::TABLE_SQL );
		if ( is_wp_error( $table ) ) {
			return $table;
		}

		// Turnstile is optional: the honeypot still works without it. A new hostname needs a new domain list.
		$domains = array_values( array_unique( array_filter( array_merge( (array) $state['domains'], $hosts ) ) ) );
		if ( ! $state['sitekey'] || $domains !== $state['domains'] ) {
			$widget = $client->create_turnstile( $settings['worker_name'], $domains );
			if ( is_wp_error( $widget ) ) {
				$state['warnings'][] = 'Turnstile: ' . $widget->get_error_message();
			} else {
				$state['sitekey'] = $widget['sitekey'];
				$state['secret']  = $widget['secret'];
				$state['domains'] = $domains;
			}
		}

		update_option( self::OPTION, $state, false );

		$bindings = array( array( 'name' => 'DB', 'type' => 'd1', 'id' => $state['d1'] ) );
		if ( $state['secret'] ) {
			$bindings[] = array( 'name' => 'TURNSTILE_SECRET', 'type' => 'secret_text', 'text' => $state['secret'] );
		}
		if ( ! empty( $settings['webhook_url'] ) ) {
			$bindings[] = array( 'name' => 'WEBHOOK_URL', 'type' => 'plain_text', 'text' => $settings['webhook_url'] );
		}

		return $bindings;
	}

	/** Recent submissions for the admin screen. */
	public static function recent( Cloudflare_Client $client, $limit = 50 ) {
		$state = get_option( self::OPTION, array() );
		if ( empty( $state['d1'] ) ) {
			return array();
		}

		$result = $client->d1_query( $state['d1'], 'SELECT id, form_id, page, data, created_at FROM ss_submissions ORDER BY id DESC LIMIT ' . (int) $limit );

		return is_wp_error( $result ) ? $result : (array) ( $result['result'][0]['results'] ?? array() );
	}
}
