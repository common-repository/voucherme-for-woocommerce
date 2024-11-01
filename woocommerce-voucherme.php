<?php
/*
Plugin Name: VoucherMe for WooCommerce
Description: Enables VoucherMe for WooCommerce.
Version:     1.6.4
Author:      VoucherMe
Author URI:  https://www.voucherme.ie/
Text Domain: wc-voucherme
Domain Path: /languages
*/

defined( 'ABSPATH' ) or die;

define( 'WC_VOUCHERME_FILE', __FILE__ );
define( 'WC_VOUCHERME_NONCE_NAME', 'wcawr_nonce' );
define( 'WC_VOUCHERME_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_VOUCHERME_VER', '1.6.4' );

if ( ! class_exists( 'WC_VoucherMe' ) ) {
	class WC_VoucherMe {
		public static function get_instance() {
			if ( self::$instance == null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private static $instance = null;

		private function __clone() { }

		private function __wakeup() { }

		private function __construct() {
			$this->api_url = 'https://voucherme.ie/api/';
			$this->voucherme = null;

			// Properties
			$this->can_invoke = null;
			$this->attempts_table_name = 'wc_voucherme_attemtps';
			$this->bans_table_name = 'wc_voucherme_bans';
			$this->max_attempts = 10;
			$this->attempts_before_notice = 5;
			$this->max_attempts_time = 60 * 60 * 24; // Seconds
			$this->ban_time = 60 * 60 * 24; // Seconds
			$this->user_id = null;
			$this->nonce_fn = basename( __FILE__ );
			$this->nonce_name = 'wc_voucherme_nonce';

			// WP Actions
			register_activation_hook( __FILE__, array( $this, 'install' ) );
			add_action( 'init', array( $this, 'init' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'wc_ajax_voucherme_process', array( $this, 'process' ) );
			add_action( 'wc_ajax_nopriv_voucherme_process', array( $this, 'process' ) );
			add_action( 'wc_ajax_voucherme_remove', array( $this, 'remove' ) );
			add_action( 'wc_ajax_nopriv_voucherme_remove', array( $this, 'remove' ) );
			add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_cart_fee' ) );

			// WC Actions
			add_action( 'woocommerce_settings_tabs_settings_voucherme', array( $this, 'add_settings_tab' ) );
			add_action( 'woocommerce_update_options_settings_voucherme', array( $this, 'update_settings' ) );
			add_action( 'woocommerce_review_order_before_payment', array( $this, 'place_voucher_section' ) );
			add_action( 'woocommerce_checkout_process', array( $this, 'should_place_order' ) );
			add_action( 'woocommerce_thankyou', array( $this, 'maybe_redeem_voucher' ) );

			// Filters
			add_filter( 'woocommerce_settings_tabs_array', array( $this, 'define_settings_tab' ), 50 );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
		}

		public function init() {
			load_plugin_textdomain( 'wc-voucherme', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}

		public function install() {
			global $wpdb;
			$attemps_table_name = $wpdb->prefix . $this->attempts_table_name;
			$banned_table_name = $wpdb->prefix . $this->banned_table_name;
			$charset_collate = $wpdb->get_charset_collate();

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			$sql = "CREATE TABLE $attemps_table_name (
				id bigint(11) NOT NULL AUTO_INCREMENT,
				session_id varchar(50),
				time datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id)
			) $charset_collate;";
			
			dbDelta( $sql );

			$sql = "CREATE TABLE $banned_table_name (
				id bigint(11) NOT NULL AUTO_INCREMENT,
				session_id varchar(50),
				time datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id)
			) $charset_collate;";
			
			dbDelta( $sql );
		}

		public static function define_settings_tab( $settings_tabs ) {
			$settings_tabs['settings_voucherme'] = __( 'VoucherMe', 'wc-voucherme' );
			return $settings_tabs;
		}

		public function add_settings_tab( $settings_tabs ) {
			woocommerce_admin_fields( $this->get_settings() );
		}

		public function update_settings() {
			 woocommerce_update_options( $this->get_settings() );
		}

		public function enqueue_assets() {
			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

			wp_enqueue_style( 'wc-voucherme', plugins_url( 'css/style.css', __FILE__ ), array(), WC_VOUCHERME_VER, 'all' );
			wp_enqueue_style( 'jquery-modal', plugins_url( 'css/jquery.modal.min.css', __FILE__ ), array(), WC_VOUCHERME_VER, 'all' );
			wp_enqueue_script( 'jquery-modal', plugins_url( 'js/jquery.modal.min.js', __FILE__ ), array( 'jquery' ), WC_VOUCHERME_VER, true );
			wp_enqueue_script( 'wc-voucherme', plugins_url( 'js/script.js', __FILE__ ), array( 'jquery-modal' ), WC_VOUCHERME_VER, true );
			wp_localize_script( 'wc-voucherme', 'WCVoucherMeData', array(
				'i18n' => array(
					'nsError' => __( 'Network / Server error', 'wc-voucherme' ),
					'pleaseWait' => __( 'Checking Voucher Code...', 'wc-voucherme' )
				)
			) );
		}

		public function place_voucher_section() {
			if ( ! $this->can_invoke() ) return;
			require( __DIR__ . '/voucher-form.php' );
		}

		public function process() {
			if ( is_user_logged_in() ) {
				if ( empty( $_POST[$this->nonce_name] ) || ! wp_verify_nonce( $_POST[$this->nonce_name], $this->nonce_bn ) ) wp_send_json_error( __( 'Invalid request', 'wc-voucherme' ) );
			}

			if ( $this->is_banned() ) {
				wp_send_json_error( __( 'Sorry, please contact us regarding your Voucher.', 'wc-voucherme' ) );
			}

			$voucher = isset( $_POST['wc_voucherme_voucher'] ) ? sanitize_text_field( $_POST['wc_voucherme_voucher'] ) : '';
			if ( $voucher == '' ) wp_send_json_error( __( 'Empty voucher', 'wc-voucherme' ) );

			$balance = $this->get_connector()->get_balance( $voucher );
			if ( is_wp_error( $balance ) ) {
				$message = $this->add_attempt( $balance->get_error_message() );
				wp_send_json_error( $message );
			}

			if ( $balance <= 0 ) wp_send_json_error( 'Voucher has empty balance!', 'wc-voucherme' );

			//$this->clear_attempts();

			WC()->session->set( 'voucherme_code' , $voucher );
			WC()->session->set( 'voucherme_balance' , $balance );

			wp_send_json_success();
		}

		public function add_cart_fee() {
			$balance = ( float ) WC()->session->get( 'voucherme_balance' );
			$voucher = WC()->session->get( 'voucherme_code' );

			if ( $balance > 0 && $voucher != '' ) {
				$cart_total = WC()->cart->cart_contents_total + WC()->cart->shipping_total;
				$discount = min( $balance, $cart_total );
				WC()->cart->add_fee( "VoucherMe ($voucher)", -$discount, false, '' );
				WC()->session->set( 'voucherme_discount' , $discount );
			}
		}

		public function remove() {
			if ( is_user_logged_in() ) {
				if ( empty($_POST[$this->nonce_name]) || ! wp_verify_nonce( $_POST[$this->nonce_name], $this->nonce_bn ) ) wp_send_json_error( __( 'Invalid request', 'wc-voucherme' ) );
			}
			WC()->session->__unset( 'voucherme_balance' );
			WC()->session->__unset( 'voucherme_code' );
			WC()->session->__unset( 'voucherme_discount' );

			wp_send_json_success();
		}

		public function should_place_order() {
			$balance = ( float ) WC()->session->get( 'voucherme_balance' );
			if ( $balance <= 0 ) return;

			$voucher = WC()->session->get( 'voucherme_code' );
			if ( $voucher == '' ) return;

			$rt_balance = $this->get_connector()->get_balance( $voucher );

			if ( is_wp_error( $rt_balance ) ) wc_add_notice( $balance->get_error_message(), 'error' );

			if ( $balance != $rt_balance ) wc_add_notice( __( 'Voucher error!', 'wc-voucherme' ), 'error' );
		}

		public function maybe_redeem_voucher( $order_id ) {
			$balance = ( float ) WC()->session->get( 'voucherme_balance' );
			if ( $balance <= 0 ) return;

			$voucher = WC()->session->get( 'voucherme_code' );
			if ( $voucher == '' ) return;

			$discount = ( float ) WC()->session->get( 'voucherme_discount' );
			if ( $discount <= 0 ) return;

			$this->get_connector()->redeem( $voucher, $discount );

			WC()->session->__unset( 'voucherme_balance' );
			WC()->session->__unset( 'voucherme_code' );
			WC()->session->__unset( 'voucherme_discount' );
		}

		public function get_settings() {
			return array(
				array(
					'name' => __( 'VoucherMe Settings', 'wc-voucherme' ),
					'type' => 'title',
					'desc' => __( 'The following options are used to configure VoucherMe', 'wc-voucherme' ),
					'id' => 'voucherme'
				),
				array(
					'name'     => __( 'Enabled', 'wc-voucherme' ),
					'desc_tip' => __( 'Whether VoucherMe should be enabled on checkout', 'wc-voucherme' ),
					'id'       => 'voucherme_enabled',
					'type'     => 'checkbox',
					'css'      => 'min-width:300px;',
					'desc'     => __( 'Enable VoucherMe', 'wc-voucherme' ),
				),
				array(
					'name'     => __( 'API Key', 'text-domain' ),
					'desc_tip' => __( 'API Key for VoucherMe', 'wc-voucherme' ),
					'id'       => 'voucherme_api_key',
					'type'     => 'text',
					'desc'     => __( 'VoucherMe API Key', 'wc-voucherme' ),
				),
				array( 'type' => 'sectionend', 'id' => 'voucherme' )
			);
		}

		public function can_invoke() {
			if ( is_null( $this->can_invoke ) ) $this->can_invoke = get_option( 'voucherme_enabled' ) == 'yes' && strlen( get_option( 'voucherme_api_key' ) );
			return $this->can_invoke;
		}

		public function get_connector() {
			if ( is_null( $this->voucherme ) ){
				require_once( __DIR__ . '/class/class.voucherme.php' );
				$this->voucherme = new VoucherMe( $this->api_url, get_option( 'voucherme_api_key' ) );
			}
			return $this->voucherme;
		}

		public function get_attempts() {
			global $wpdb;
			$table_name = $wpdb->prefix . $this->attempts_table_name;
			$user_id = $this->get_user_id();
			return ( int ) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE session_id = %s AND TIMESTAMPDIFF(SECOND, time, NOW()) <= %d", $user_id, $this->max_attempts_time ) );
		}

		public function add_attempt( $message ) {
			global $wpdb;
			$table_name = $wpdb->prefix . $this->attempts_table_name;
			$user_id = $this->get_user_id();
			$wpdb->query( $wpdb->prepare( "INSERT INTO $table_name (session_id) VALUES(%s)", $user_id ) );

			$attempts = $this->get_attempts();
			if ( $attempts >= $this->max_attempts ) {
				$this->ban();
				return __( 'Sorry, please contact us regarding your Voucher.', 'wc-voucherme' );
			}
			if ( $attempts >= $this->attempts_before_notice ) $message .= ' ' . sprintf( __( 'Please note: only %d attempts remain.', 'wc-voucherme' ), $this->max_attempts - $attempts );
			return $message;
		}

		public function clear_attempts() {
			global $wpdb;
			$table_name = $wpdb->prefix . $this->attempts_table_name;
			$user_id = $this->get_user_id();
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE session_id =%s", $user_id ) );
		}

		public function ban() {
			global $wpdb;
			$table_name = $wpdb->prefix . $this->bans_table_name;
			$user_id = $this->get_user_id();
			$wpdb->query( $wpdb->prepare( "INSERT INTO $table_name (session_id) VALUES(%s)", $user_id ) );
		}

		public function is_banned() {
			global $wpdb;
			$table_name = $wpdb->prefix . $this->bans_table_name;
			$user_id = $this->get_user_id();
			return ! ! ( int ) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE session_id = %s AND TIMESTAMPDIFF(SECOND, time, NOW()) <= %d", $user_id, $this->ban_time ) );
		}

		public function add_settings_link( $links ) {
			$url = admin_url( 'admin.php?page=wc-settings&tab=settings_voucherme' );
			$settings_link = "<a href='$url'>" . __( 'Settings' ) . '</a>';

			array_push( $links, $settings_link );
			return $links;
		}

		public function get_user_id() {
			if ( is_null( $this->user_id ) ) $this->user_id = md5( $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT'] );
			return $this->user_id;
		}
	}
}
WC_VoucherMe::get_instance();