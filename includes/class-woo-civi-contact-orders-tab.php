<?php
/**
 * Orders Contact Tab class.
 *
 * Handles the WooCommerce Orders tab on CiviCRM Contact screens.
 *
 * @package WPCV_Woo_Civi
 * @since 2.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Orders Contact Tab class.
 *
 * @since 2.0
 */
class WPCV_Woo_Civi_Contact_Orders_Tab {

	/**
	 * Class constructor.
	 *
	 * @since 2.0
	 */
	public function __construct() {

		// Init when the Contact class is fully loaded.
		add_action( 'wpcv_woo_civi/contact/loaded', [ $this, 'initialise' ] );

	}

	/**
	 * Initialise this object.
	 *
	 * @since 3.0
	 */
	public function initialise() {
		$this->register_hooks();
	}

	/**
	 * Check if WooCommerce is activated on another site.
	 *
	 * This relies on the setting in WPCV_Woo_Civi_Settings_Network to work.
	 * This method plus "fix_site()" and "unfix_site()" should probably be moved
	 * to that class for better encapsulation.
	 *
	 * Furthermore, it's entirely possible for there to be multiple WooCommerce
	 * instances on a Multisite network, so data may need to be gathered for all
	 * of them.
	 *
	 * @since 2.2
	 */
	private function is_remote_wc() {

		if ( false === WPCV_WCI()->is_network_activated() ) {
			return false;
		}

		$option = WPCV_WCI()->settings_network->settings_key;
		$options = get_site_option( $option );
		if ( ! $options ) {
			return false;
		}

		$wc_site_id = (int) $options['wc_blog_id'];
		if ( $wc_site_id === get_current_blog_id() ) {
			return false;
		}

		return $wc_site_id;

	}

	/**
	 * Switch to main WooCommerce site on Multisite installation.
	 *
	 * @since 2.2
	 */
	private function fix_site() {

		$wc_site_id = $this->is_remote_wc();
		if ( false === $wc_site_id ) {
			return;
		}

		switch_to_blog( $wc_site_id );

	}

	/**
	 * Switch back to current site on Multisite installation.
	 *
	 * @since 2.2
	 */
	private function unfix_site() {

		if ( ! is_multisite() ) {
			return;
		}

		restore_current_blog();

	}

	/**
	 * Register hooks.
	 *
	 * @since 2.0
	 */
	public function register_hooks() {

		// Register custom PHP directory.
		add_action( 'civicrm_config', [ $this, 'register_custom_php_directory' ], 10, 1 );
		// Register custom template directory.
		add_action( 'civicrm_config', [ $this, 'register_custom_template_directory' ], 10, 1 );
		// Register menu callback.
		add_filter( 'civicrm_xmlMenu', [ $this, 'register_callback' ], 10, 1 );
		// Add CiviCRM settings tab.
		add_filter( 'civicrm_tabset', [ $this, 'add_orders_contact_tab' ], 10, 3 );

	}

	/**
	 * Register PHP directory.
	 *
	 * @since 2.0
	 *
	 * @param object $config The CiviCRM config object.
	 */
	public function register_custom_php_directory( &$config ) {

		$this->fix_site();
		$custom_path = WPCV_WOO_CIVI_PATH . 'assets/civicrm/custom_php';
		$include_path = $custom_path . PATH_SEPARATOR . get_include_path();
		// phpcs:ignore
		set_include_path( $include_path );
		$this->unfix_site();

	}

	/**
	 * Register template directory.
	 *
	 * @since 2.0
	 *
	 * @param object $config The CiviCRM config object.
	 */
	public function register_custom_template_directory( &$config ) {

		$this->fix_site();
		$custom_path = WPCV_WOO_CIVI_PATH . 'assets/civicrm/custom_tpl';
		$template = CRM_Core_Smarty::singleton()->addTemplateDir( $custom_path );
		$include_template_path = $custom_path . PATH_SEPARATOR . get_include_path();
		// phpcs:ignore
		set_include_path( $include_template_path );
		$this->unfix_site();

	}

	/**
	 * Register XML file.
	 *
	 * @since 2.0
	 *
	 * @param array $files The array for files used to build the menu.
	 */
	public function register_callback( &$files ) {

		$this->fix_site();
		$files[] = WPCV_WOO_CIVI_PATH . 'assets/civicrm/xml/menu.xml';
		$this->unfix_site();

	}

	/**
	 * Add Purchases tab to Contact Summary Screen.
	 *
	 * @since 2.0
	 *
	 * @uses 'woocommerce_settings_tabs_array' filter.
	 *
	 * @param string $tabset_name The name of the screen or visual element.
	 * @param array $tabs The array of tabs.
	 * @param string|array $context Extra data about the screen.
	 */
	public function add_orders_contact_tab( $tabset_name, &$tabs, $context ) {

		// Bail if not on Contact Summary Screen.
		if ( 'civicrm/contact/view' !== $tabset_name ) {
			return;
		}

		$contact_id = $context['contact_id'];

		// Bail if Contact has no Orders and "Hide Order" is enabled.
		$order_count = $this->count_orders( $contact_id );
		$option = get_option( 'woocommerce_civicrm_hide_orders_tab_for_non_customers', false );
		$hide_orders_tab = WPCV_WCI()->helper->check_yes_no_value( $option );
		if ( $hide_orders_tab && ! $order_count ) {
			return;
		}

		$url = CRM_Utils_System::url( 'civicrm/contact/view/purchases', "reset=1&cid=$contact_id&no_redirect=1" );

		$tabs[] = [
			'id' => 'woocommerce-orders',
			'url' => $url,
			'title' => __( 'Woo Orders', 'wpcv-woo-civi-integration' ),
			'count' => $order_count,
			'weight' => 99,
		];

	}

	/**
	 * Get Customer raw Orders.
	 *
	 * @since 2.2
	 *
	 * @param int $contact_id The Contact ID.
	 * @return array $customer_orders The array of raw Order data.
	 */
	private function _get_orders( $contact_id ) {

		$this->fix_site();
		$uid = abs( CRM_Core_BAO_UFMatch::getUFId( $contact_id ) );
		if ( ! $uid ) {
			try {

				$params = [
					'contact_id' => $contact_id,
					'return' => [ 'email' ],
				];

				$contact = civicrm_api3( 'Contact', 'getsingle', $params );

			} catch ( CiviCRM_API3_Exception $e ) {

				// Write to CiviCRM log.
				CRM_Core_Error::debug_log_message( __( 'Unable to find Contact', 'wpcv-woo-civi-integration' ) );
				CRM_Core_Error::debug_log_message( $e->getMessage() );

				// Write details to PHP log.
				$e = new \Exception();
				$trace = $e->getTraceAsString();
				error_log( print_r( [
					'method' => __METHOD__,
					'params' => $params,
					'backtrace' => $trace,
				], true ) );

				$this->unfix_site();
				return [];
			}
		}

		$order_statuses = [
			'wc-pending'    => _x( 'Pending payment', 'Order status', 'woocommerce' ),
			'wc-processing' => _x( 'Processing', 'Order status', 'woocommerce' ),
			'wc-on-hold'    => _x( 'On hold', 'Order status', 'woocommerce' ),
			'wc-completed'  => _x( 'Completed', 'Order status', 'woocommerce' ),
			'wc-cancelled'  => _x( 'Cancelled', 'Order status', 'woocommerce' ),
			'wc-refunded'   => _x( 'Refunded', 'Order status', 'woocommerce' ),
			'wc-failed'     => _x( 'Failed', 'Order status', 'woocommerce' ),
		];

		/**
		 * Filter the list of Order statuses.
		 *
		 * @see https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-order-functions.html
		 *
		 * @since 2.2
		 *
		 * @param array $order_statuses The default list of Order statuses.
		 */
		$order_statuses = apply_filters( 'wc_order_statuses', $order_statuses );

		if ( ! $uid && empty( $contact['email'] ) ) {
			$this->unfix_site();
			return [];
		}

		$order_query = [
			'numberposts' => -1,
			'meta_key'    => $uid ? '_customer_user' : '_billing_email', // phpcs:ignore
			'meta_value'  => $uid ? $uid : $contact['email'], // phpcs:ignore
			'post_type'   => 'shop_order',
			'post_status' => array_keys( $order_statuses ),
		];

		/**
		 * Filter the Order query.
		 *
		 * @see https://woocommerce.github.io/code-reference/files/woocommerce-templates-myaccount-my-orders.html
		 *
		 * @since 2.2
		 *
		 * @param array $order_query The default Order query.
		 */
		$order_query = apply_filters( 'woocommerce_my_account_my_orders_query', $order_query );

		$customer_orders = get_posts( $order_query );

		$this->unfix_site();

		return $customer_orders;

	}

	/**
	 * Get count of Orders for a given Customer.
	 *
	 * @since 2.2
	 *
	 * @param int $contact_id The Contact ID.
	 * @return int $orders_count The number of Orders.
	 */
	public function count_orders( $contact_id ) {
		return count( $this->_get_orders( $contact_id ) );
	}

	/**
	 * Get Customer orders.
	 *
	 * @since 2.1
	 *
	 * @param int $contact_id The Contact ID.
	 * @return array|bool $orders The array of Orders, or false on failure.
	 */
	public function get_orders( $contact_id ) {

		$customer_orders = $this->_get_orders( $contact_id );
		$orders = [];
		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		// FIXME: For now, get partial data.
		// TODO: Fetch real data.

		// If WooCommerce is in another blog, fetch the Order remotely.
		if ( $this->is_remote_wc() ) {
			$this->fix_site();

			foreach ( $customer_orders as $customer_order ) {

				$order_id = $customer_order->ID;
				$order = $customer_order;

				$orders[ $order_id ]['order_number'] = $order_id;
				$orders[ $order_id ]['order_date'] = date_i18n( $date_format, strtotime( $order->post_date ) );
				//$orders[ $order_id ]['order_date'] = $order->get_date_created()->date_i18n($date_format);
				$orders[ $order_id ]['order_billing_name'] = get_post_meta( $order_id, '_billing_first_name', true ) . ' ' . get_post_meta( $order_id, '_billing_last_name', true );
				$orders[ $order_id ]['order_shipping_name'] = get_post_meta( $order_id, '_shipping_first_name', true ) . ' ' . get_post_meta( $order_id, '_shipping_last_name', true );
				$orders[ $order_id ]['item_count'] = '--';
				$orders[ $order_id ]['order_total'] = get_post_meta( $order_id, '_order_total', true );
				$orders[ $order_id ]['order_status'] = $order->post_status;
				$orders[ $order_id ]['order_link'] = admin_url( 'post.php?action=edit&post=' . $order_id );

			}

			$this->unfix_site();
			return $orders;
		}

		// Else continue the main way.
		foreach ( $customer_orders as $customer_order ) {

			$order_id = $customer_order->ID;
			$order = new WC_Order( $customer_order );
			$item_count = $order->get_item_count();
			$total = $order->get_total();

			$orders[ $order_id ]['order_number'] = $order->get_order_number();
			$orders[ $order_id ]['order_date'] = date_i18n( $date_format, strtotime( $order->get_date_created() ) );
			//$orders[ $order_id ]['order_date'] = $order->get_date_created()->date_i18n( $date_format );
			$orders[ $order_id ]['order_billing_name'] = $order->get_formatted_billing_full_name();
			$orders[ $order_id ]['order_shipping_name'] = $order->get_formatted_shipping_full_name();
			$orders[ $order_id ]['item_count'] = $item_count;
			$orders[ $order_id ]['order_total'] = $total;
			$orders[ $order_id ]['order_status'] = $order->get_status();
			$orders[ $order_id ]['order_link'] = admin_url( 'post.php?action=edit&post=' . $order->get_order_number() );

		}

		if ( ! empty( $orders ) ) {
			return $orders;
		}

		return false;

	}

}
