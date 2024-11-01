<?php
class VoucherMe {
	function __construct( $api_url, $api_key ) {
		$this->api_url = $api_url;
		$this->api_key = $api_key;
	}

	function get_balance( $voucher ) {
		$resobj = $this->fetch( 'search', array(
			'code' => $voucher
		) );

		if ( is_wp_error( $resobj ) ) return $resobj;
		if ( property_exists( $resobj, 'error' ) ) return new WP_Error( 'error', $resobj->error );
		if ( ! property_exists( $resobj, 'current_balance' ) ) return new WP_Error( 'error', __( 'Invalid API response', 'wc-voucherme' ) );

		return floatval( $resobj->current_balance );
	}

	function redeem( $voucher, $amount ) {
		$resobj = $this->fetch( 'redeem', array(
			'code' => $voucher,
			'amount' => $amount
		) );
	}

	function fetch( $action, $body_obj ) {
		if ( $action != 'token' ) {
			$token = $this->get_token();
			if ( is_wp_error( $token ) ) return $token;
		}

		$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
		if ( isset( $token ) ) $headers['Authorization'] = 'Bearer ' . $token;

		$response = wp_remote_post( $this->api_url . $action, array(
			'headers' => $headers,
			'body' => json_encode( $body_obj )
		) );

		$resobj = @json_decode( $response['body'] );
		if ( ! $resobj ) return new WP_Error( 'error', __( 'Invalid API response', 'wc-voucherme' ) );

		return $resobj;
	}

	function get_token() {
		$resobj = $this->fetch( 'token', array(
			'key' => $this->api_key
		) );

		if ( is_wp_error( $resobj ) ) return $resobj;

		if ( ! property_exists( $resobj, 'token' ) ) return new WP_Error( 'error', __( 'Invalid response or API key', 'wc-voucherme' ) );

		return $resobj->token;
	}
}