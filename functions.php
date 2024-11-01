<?php
	if ( ! function_exists( 'wc_cart_add_discount' ) ) {
		function wc_cart_add_discount( $order_id, $title, $amount, $tax_class = '' ) {
			$order    = wc_get_order( $order_id );
			$subtotal = $order->get_subtotal();
			$item     = new WC_Order_Item_Fee();

			if ( strpos( $amount, '%' ) !== false ) {
				$percentage = (float) str_replace( array('%', ' '), array('', ''), $amount );
				$percentage = $percentage > 100 ? -100 : -$percentage;
				$discount   = $percentage * $subtotal / 100;
			} else {
				$discount = (float) str_replace( ' ', '', $amount );
				$discount = $discount > $subtotal ? -$subtotal : -$discount;
			}

			$item->set_tax_class( $tax_class );
			$item->set_name( $title );
			$item->set_amount( $discount );
			$item->set_total( $discount );

			if ( '0' !== $item->get_tax_class() && 'taxable' === $item->get_tax_status() && wc_tax_enabled() ) {
				$tax_for   = array(
					'country'   => $order->get_shipping_country(),
					'state'     => $order->get_shipping_state(),
					'postcode'  => $order->get_shipping_postcode(),
					'city'      => $order->get_shipping_city(),
					'tax_class' => $item->get_tax_class(),
				);
				$tax_rates = WC_Tax::find_rates( $tax_for );
				$taxes     = WC_Tax::calc_tax( $item->get_total(), $tax_rates, false );
				print_pr($taxes);

				if ( method_exists( $item, 'get_subtotal' ) ) {
					$subtotal_taxes = WC_Tax::calc_tax( $item->get_subtotal(), $tax_rates, false );
					$item->set_taxes( array( 'total' => $taxes, 'subtotal' => $subtotal_taxes ) );
					$item->set_total_tax( array_sum($taxes) );
				} else {
					$item->set_taxes( array( 'total' => $taxes ) );
					$item->set_total_tax( array_sum($taxes) );
				}
				$has_taxes = true;
			} else {
				$item->set_taxes( false );
				$has_taxes = false;
			}
			$item->save();

			$order->add_item( $item );
			$order->calculate_totals( $has_taxes );
			$order->save();
		}
	}
?>