<?php

namespace Simply_Static\Crawler;

use Simply_Static\Cloudflare_Pages;
use Simply_Static\Util;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds the pages of every TranslatePress language.
 *
 * TranslatePress translates on the fly: its secondary-language pages are not WordPress posts, so no
 * other crawler lists them and Smart Crawl never follows a link to them. Fetching them works like for
 * any visitor; they only have to be announced. They mirror what the default language exports: the
 * pages of the crawlers that are switched on, minus what the user left out.
 *
 * Loaded lazily (see Cloudflare_Pages::add_translatepress_crawler), because the base class is.
 */
class Translatepress_Crawler extends Crawler {

	protected $id = 'translatepress';

	public function __construct() {
		$this->name        = __( 'TranslatePress languages', 'simply-static' );
		$this->description = __( 'Adds the pages of every published TranslatePress language.', 'simply-static' );
	}

	public function dependency_active() : bool {
		return class_exists( 'TRP_Translate_Press' );
	}

	/** Not a user choice: registered only for Cloudflare delivery, and a language without pages is a broken site. */
	public function is_active() {
		return $this->dependency_active();
	}

	public function detect() : array {
		$trp = \TRP_Translate_Press::get_trp_instance();
		if ( ! $trp ) {
			return array();
		}

		$settings  = $trp->get_component( 'settings' )->get_settings();
		$converter = $trp->get_component( 'url_converter' );
		$default   = isset( $settings['default-language'] ) ? $settings['default-language'] : '';
		$languages = array_diff( (array) ( isset( $settings['publish-languages'] ) ? $settings['publish-languages'] : array() ), array( $default ) );
		if ( ! $languages || ! $converter ) {
			return array();
		}

		$base = $this->base_urls();

		$urls = array();
		foreach ( $languages as $code ) {
			foreach ( $base as $url ) {
				$translated = $converter->get_url_for_language( $code, $url, '' );
				if ( is_string( $translated ) && '' !== $translated ) {
					$urls[] = $translated;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/** What the default language exports: the pages of the active crawlers, minus the exclusion list. */
	protected function base_urls() {
		$base = array();
		foreach ( Cloudflare_Pages::groups( true ) as $group ) {
			foreach ( $group['urls'] as $url ) {
				if ( ! Util::is_url_excluded( $url ) ) {
					$base[] = $url;
				}
			}
		}

		return $base;
	}
}
