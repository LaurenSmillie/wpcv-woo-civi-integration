<?php
/**
 * Urchin Tracking Module class.
 *
 * Handles integration of Urchin Tracking Module when CiviCAmpaing is enabled.
 *
 * @package WPCV_Woo_Civi
 * @since 3.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Urchin Tracking Module class.
 *
 * @since 2.0
 */
class WPCV_Woo_Civi_UTM {

	/**
	 * Class constructor.
	 *
	 * @since 3.0
	 */
	public function __construct() {

		// Init when this plugin is fully loaded.
		add_action( 'wpcv_woo_civi/loaded', [ $this, 'initialise' ] );

	}

	/**
	 * Initialise this object.
	 *
	 * @since 3.0
	 */
	public function initialise() {

		// Bail if the CiviCampaign component is not active.
		if ( ! WPCV_WCI()->helper->is_component_enabled( 'CiviCampaign' ) ) {
			return;
		}

		$this->register_hooks();
		$this->utm_check();

	}

	/**
	 * Register hooks.
	 *
	 * @since 3.0
	 */
	public function register_hooks() {

		// Flush cookies when a Contribution has been created.
		add_action( 'wpcv_woo_civi/order/created', [ $this, 'utm_cookies_delete' ] );

		// Save UTM Campaign cookie content to the Order post meta.
		add_action( 'wpcv_woo_civi/order/processed', [ $this, 'utm_to_order' ], 20 );

		// Save UTM Campaign cookie content to the Order post meta.
		add_filter( 'wpcv_woo_civi/order/source/generate', [ $this, 'utm_filter_source' ] );

	}

	/**
	 * Check if UTM parameters are passed in URL (front-end only).
	 *
	 * @since 2.2
	 */
	private function utm_check() {

		if ( is_admin() ) {
			return;
		}

		if ( isset( $_GET['utm_campaign'] ) || isset( $_GET['utm_source'] ) || isset( $_GET['utm_medium'] ) ) {
			$this->utm_cookies_save();
		}

	}

	/**
	 * Save UTM parameters to cookies.
	 *
	 * @since 2.2
	 */
	private function utm_cookies_save() {

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		/**
		 * Filter the cookie expiry time.
		 *
		 * @since 2.2
		 *
		 * @param int The duration of the cookie. Default 0.
		 */
		$expire = apply_filters( 'wpcv_woo_civi/utm_cookie/expire', 0 );
		$secure = ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) );

		$campaign_name = filter_input( INPUT_GET, 'utm_campaign' );
		if ( ! empty( $campaign_name ) ) {
			$campaign_cookie = 'woocommerce_civicrm_utm_campaign_' . COOKIEHASH;
			$campaign = WPCV_WCI()->campaign->get_campaign_by_name( esc_attr( $campaign_name ) );
			if ( ! empty( $campaign['id'] ) && is_numeric( $campaign['id'] ) ) {
				setcookie( $campaign_cookie, $campaign['id'], $expire, COOKIEPATH, COOKIE_DOMAIN, $secure );
			} else {
				// Remove cookie if Campaign is invalid.
				setcookie( $campaign_cookie, ' ', time() - YEAR_IN_SECONDS );
			}
		}

		$source = filter_input( INPUT_GET, 'utm_source' );
		if ( false !== $source ) {
			setcookie( 'woocommerce_civicrm_utm_source_' . COOKIEHASH, esc_attr( $source ), $expire, COOKIEPATH, COOKIE_DOMAIN, $secure );
		}

		$medium = filter_input( INPUT_GET, 'utm_medium' );
		if ( false !== $medium ) {
			setcookie( 'woocommerce_civicrm_utm_medium_' . COOKIEHASH, esc_attr( $medium ), $expire, COOKIEPATH, COOKIE_DOMAIN, $secure );
		}

		// Success.
		return true;

	}

	/**
	 * Delete UTM cookies.
	 *
	 * @since 2.2
	 */
	public function utm_cookies_delete() {

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Remove any existing cookies.
		$past = time() - YEAR_IN_SECONDS;
		setcookie( 'woocommerce_civicrm_utm_campaign_' . COOKIEHASH, ' ', $past, COOKIEPATH, COOKIE_DOMAIN );
		setcookie( 'woocommerce_civicrm_utm_source_' . COOKIEHASH, ' ', $past, COOKIEPATH, COOKIE_DOMAIN );
		setcookie( 'woocommerce_civicrm_utm_medium_' . COOKIEHASH, ' ', $past, COOKIEPATH, COOKIE_DOMAIN );

	}

	/**
	 * Saves UTM Campaign cookie content to the Order post meta.
	 *
	 * @since 2.2
	 *
	 * @param int $order_id The Order ID.
	 */
	public function utm_to_order( $order_id ) {

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$cookie = wp_unslash( $_COOKIE );
		$campaign_cookie = 'woocommerce_civicrm_utm_campaign_' . COOKIEHASH;

		// Set the UTM Campaign ID if present, otherwise set default CiviCRM Campaign ID.
		if ( ! empty( $cookie[ $campaign_cookie ] ) ) {
			WPCV_WCI()->campaign->set_order_meta( $order_id, esc_attr( $cookie[ $campaign_cookie ] ) );
			setcookie( $campaign_cookie, ' ', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
		} else {
			$campaign_id = get_option( 'woocommerce_civicrm_campaign_id' );
			WPCV_WCI()->campaign->set_order_meta( $order_id, $campaign_id );
		}

	}

	/**
	 * Filters the Contribution Source.
	 *
	 * @since 3.0
	 *
	 * @param str $source The existing Contribution Source string.
	 * @return str $source The modified Contribution Source string.
	 */
	public function utm_filter_source( $source ) {

		$cookie = wp_unslash( $_COOKIE );
		$source_cookie = 'woocommerce_civicrm_utm_source_' . COOKIEHASH;
		$medium_cookie = 'woocommerce_civicrm_utm_medium_' . COOKIEHASH;

		// Bail early if there's no data.
		if ( empty( $cookie[ $source_cookie ] ) && empty( $cookie[ $medium_cookie ] ) ) {
			return $source;
		}

		// Build new Source string.
		$tmp = [];
		if ( ! empty( $cookie[ $source_cookie ] ) ) {
			$tmp[] = esc_attr( $cookie[ $source_cookie ] );
		}
		if ( ! empty( $cookie[ $medium_cookie ] ) ) {
			$tmp[] = esc_attr( $cookie[ $medium_cookie ] );
		}
		$source = implode( ' / ', $tmp );

		return $source;

	}

}
