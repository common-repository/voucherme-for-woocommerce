<?php defined( 'ABSPATH' ) or die; ?>
<?php $has_voucher = ( float ) WC()->session->get( 'voucherme_balance' ) > 0; ?>
<div class="voucherme-cont <?php esc_attr_e( $has_voucher ? 'has-voucher' : '' ); ?>">
	<?php if ( is_user_logged_in() ) wp_nonce_field( $this->nonce_bn, $this->nonce_name ); ?>
	<div class="voucherme-r">
		<p><a href="#" class="button btn voucherme-remove"><?php _e( 'Remove voucher', 'wc-voucherme' ); ?></a></p>
	</div>
	<div class="voucherme-a">
		<p><?php echo sprintf( __( 'Have a Gift Voucher? %sClick here to enter your code%s', 'wc-voucherme' ), '<a class="voucherme-link" href="#">', '</a>' ); ?></p>
		<p class="voucherme-input" style="display:none"><input type="text" name="wc_voucherme_voucher" maxlength="5"><a class="button btn voucherme-process" href="#"><?php _e( 'Apply', 'wc-voucherme' ); ?></a></p>
	</div>
</div>