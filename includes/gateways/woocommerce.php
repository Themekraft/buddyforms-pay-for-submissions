<?php

/**
 * Process the entry after the form is submitted
 *
 * @param $args
 */
function buddyforms_pay_for_submissions_after_submit_end( $args ) {
	if ( ! empty( $args ) && ! empty( $args['form_slug'] ) ) {
		$form_slug         = $args['form_slug'];
		$is_trigger_status = buddyforms_pay_for_submissions_is_trigger_status( $form_slug );		
		$post_id 		   = false;

		if ( isset( $args['post_id'] ) && ! empty( $args['post_id'] )  ) {
			$post_id = $args['post_id'];
			
		} elseif ( isset( $_POST['post_id'] ) && ! empty( $_POST['post_id'] ) ) {
			$post_id = $_POST['post_id'];
		}

		$is_paid = buddyforms_pay_for_submission_is_paid( $post_id );

		if ( buddyforms_pay_for_submissions_is_enabled( $form_slug ) && $is_trigger_status && ! $is_paid ) {
			$options    = buddyforms_get_form_by_slug( $form_slug );
			$product_id = ! empty( $options['pay_for_submissions_woo_product'] ) ? $options['pay_for_submissions_woo_product'] : false;
			$product = wc_get_product( (int) $product_id );
			if( isset( $options['pay_for_submissions_is_variable_product'] ) && $product->is_type( 'variable' ) ){
				$product_id = apply_filters( 'buddyforms_pay_for_submissions_variation_id', $options['pay_for_submissions_is_variable_product'], $form_slug, $product );
			}
			if ( ! empty( $product_id ) ) {
				try {
					WC()->cart->add_to_cart( $product_id );
				} catch ( Exception $e ) {
					BuddyFormsPayForSubmissions::error_log( $e->getMessage() );
				}

			}
		}
	}
}

add_action( 'buddyforms_after_submission_end', 'buddyforms_pay_for_submissions_after_submit_end', 10, 1 );

/**
 * Redirect the form ajax submission to the checkout or cart page of woocommerce
 *
 * @param $args
 *
 * @return mixed
 */
function buddyforms_pay_for_submissions_ajax_process_edit_post_json_response( $args ) {
	if ( ! empty( $args ) ) {
		$form_slug         = $args['form_slug'];
		$is_trigger_status = buddyforms_pay_for_submissions_is_trigger_status( $form_slug );
		$post_id 		   = false;

		if ( isset( $args['post_id'] ) && ! empty( $args['post_id'] )  ) {
			$post_id = $args['post_id'];
			
		} elseif ( isset( $_POST['post_id'] ) && ! empty( $_POST['post_id'] ) ) {
			$post_id = $_POST['post_id'];
		}
		
		$is_paid = buddyforms_pay_for_submission_is_paid( $post_id );
		
		if ( buddyforms_pay_for_submissions_is_enabled( $form_slug ) && $is_trigger_status && ! $is_paid ) {
			$options            = buddyforms_get_form_by_slug( $form_slug );
			$is_direct_checkout = ! empty( $options['pay_for_submissions_woo_direct_checkout'] );
			if ( $is_direct_checkout ) {
				$args['form_notice'] = buddyforms_after_save_post_redirect( wc_get_checkout_url() );
			} else {
				$args['form_notice'] = buddyforms_after_save_post_redirect( wc_get_cart_url() );
			}
		}
	}

	return $args;
}

add_filter( 'buddyforms_ajax_process_edit_post_json_response', 'buddyforms_pay_for_submissions_ajax_process_edit_post_json_response', 10, 1 );

/**
 * Change the status of the entry when is payment is complete
 *
 * @param $order_id
 * @param $from
 * @param $to
 * @param $order
 */
function buddyforms_pay_for_submissions_on_process_complete( $order_id, $from, $to, $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order ) {
		return;
	}
	$items = $order->get_items();
	/** @var object $item */
	foreach ( $items as $key => $item ) {
		/** @var WC_Product $product */
		$target_post = $item->get_meta( 'bf-pay-for-submission' );
		if ( ! empty( $target_post ) ) {
			if ( stripos( $to, 'completed' ) !== false ) {
				$form_slug = buddyforms_get_form_slug_by_post_id( $target_post );
				if ( empty( $form_slug ) ) {
					return;
				}
				global $buddyforms;
				if ( empty( $buddyforms ) ) {
					return;
				}
				if ( empty( $buddyforms[ $form_slug ] ) ) {
					return;
				}
				buddyforms_switch_to_form_blog( $form_slug );
				$form_options_status = ! empty( $buddyforms[ $form_slug ]['status'] ) ? $buddyforms[ $form_slug ]['status'] : 'publish';
				wp_update_post( array(
					'ID'          => $target_post,
					'post_status' => $form_options_status,
					'meta_input' => array(
						'bf_pay_for_submission_is_paid' => 'paid',
					)
				) );

				if ( buddyforms_is_multisite() ) {
					restore_current_blog();
				}
			}
		}
	}
}

add_action( 'woocommerce_order_status_changed', 'buddyforms_pay_for_submissions_on_process_complete', 10, 4 );

/**
 * When added to cart, save any forms data
 *
 * @param mixed $cart_item_meta
 * @param mixed $product_id
 *
 * @return mixed
 */
function buddyforms_pay_for_submissions_add_cart_item_data( $cart_item_meta, $product_id ) {
	if ( ! empty( $_POST['form_slug'] ) && ! empty( $_POST['post_id'] ) ) {
		$cart_item_meta['bf-pay-for-submission'] = array(
			'post_id'   => intval( $_POST['post_id'] ),
			'form_slug' => buddyforms_sanitize_slug( $_POST['form_slug'] ),
		);
	}

	return $cart_item_meta;
}

add_filter( 'woocommerce_add_cart_item_data', 'buddyforms_pay_for_submissions_add_cart_item_data', 10, 2 );
/**
 * Add field data to cart item
 *
 * @modifiers GFireM
 *
 * @param mixed $cart_item
 * @param mixed $values
 *
 * @return mixed
 */
function buddyforms_pay_for_submissions_get_cart_item_from_session( $cart_item, $values ) {
	if ( ! empty( $values['bf-pay-for-submission'] ) ) {
		$cart_item['bf-pay-for-submission'] = $values['bf-pay-for-submission'];
	}

	return $cart_item;
}

add_filter( 'woocommerce_get_cart_item_from_session', 'buddyforms_pay_for_submissions_get_cart_item_from_session', 10, 2 );
/**
 * Get item data
 *
 * @param $item_data
 * @param $cart_item
 *
 * @return array
 */
function buddyforms_pay_for_submissions_get_item_data( $item_data, $cart_item ) {

	$item_data = buddyforms_pay_for_submissions_add_data_as_meta( $item_data, $cart_item, true );

	return $item_data;
}


add_filter( 'woocommerce_get_item_data', 'buddyforms_pay_for_submissions_get_item_data', 10, 2 );

/**
 * After ordering, add the data to the order line item
 *
 * @param mixed $item_id
 * @param $cart_item
 * @param $order_id
 *
 * @throws Exception
 */
function buddyforms_pay_for_submissions_add_order_item_meta( $item_id, $cart_item, $order_id ) {
	if ( ! isset( $cart_item['bf-pay-for-submission'] ) ) {
		if ( isset( $cart_item->legacy_values['bf-pay-for-submission'] ) ) {
			$cart_item['bf-pay-for-submission'] = $cart_item->legacy_values['bf-pay-for-submission'];
		} else {
			return;
		}


	}
	$item_data = buddyforms_pay_for_submissions_add_data_as_meta( array(), $cart_item );

	if ( empty ( $item_data ) ) {
		return;
	}

	foreach ( $item_data as $key => $value ) {
		wc_add_order_item_meta( $item_id, strip_tags( $value['key'] ), strip_tags( $value['value'] ) );
	}
}

add_action( 'woocommerce_new_order_item', 'buddyforms_pay_for_submissions_add_order_item_meta', 10, 3 );


/**
 * Process the data to create the stream into the cart and the order
 *
 * @param $item_data
 * @param $cart_item
 * @param bool $output
 *
 * @return array
 */
function buddyforms_pay_for_submissions_add_data_as_meta( $item_data, $cart_item, $output = false ) {
	if ( ! empty( $cart_item['bf-pay-for-submission'] ) && ! empty( $cart_item['bf-pay-for-submission']['post_id'] ) ) {
		if ( $output ) {
			$post_title  = get_the_title( $cart_item['bf-pay-for-submission']['post_id'] );
			$item_data[] = array(
				'key'   => '<strong>' . __( "Entry", 'buddyforms-pay-for-submissions' ) . '</strong>',
				'value' => $post_title
			);
		} else {
			$item_data[] = array(
				'key'   => 'bf-pay-for-submission',
				'value' => $cart_item['bf-pay-for-submission']['post_id']
			);
		}

	}

	return $item_data;
}

/**
 * Process the item meta to show in the order in the front
 *
 * @param $output
 * @param WC_Order_Item_Meta $itemMeta
 *
 * @return mixed
 */
function buddyforms_pay_for_submissions_on_order_items_meta_display( $output, $itemMeta ) {
	$meta_list = array();
	foreach ( $itemMeta->get_formatted() as $meta ) {
		if ( $meta['key'] == 'bf-pay-for-submission' ) {
			$post_title  = get_the_title( $meta['value'] );
			$meta_list[] = '
						<dt class="variation-' . sanitize_html_class( sanitize_text_field( $meta['key'] ) ) . '">' . __( "Entry", 'buddyforms-pay-for-submissions' ) . ':</dt>
						<dd class="variation-' . sanitize_html_class( sanitize_text_field( $meta['key'] ) ) . '">' . wp_kses_post( wpautop( $post_title ) ) . '</dd>
					';
		} else {
			$meta_list[] = '
						<dt class="variation-' . sanitize_html_class( sanitize_text_field( $meta['key'] ) ) . '">' . wp_kses_post( $meta['label'] ) . ':</dt>
						<dd class="variation-' . sanitize_html_class( sanitize_text_field( $meta['key'] ) ) . '">' . wp_kses_post( wpautop( make_clickable( $meta['value'] ) ) ) . '</dd>
					';
		}
	}
	$output = '<dl class="variation">' . implode( '', $meta_list ) . '</dl>';

	return $output;
}

add_filter( 'woocommerce_order_items_meta_display', 'buddyforms_pay_for_submissions_on_order_items_meta_display', 10, 2 ); //Process the item meta to show in the order in the front

/**
 * Process the item meta to show in the thank you page
 *
 * @param String $html
 * @param WC_Order_Item_Product $item
 * @param array $args
 *
 * @return mixed
 */
function buddyforms_pay_for_submissions_on_display_items_meta( $html, $item, $args ) {
	$strings = array();
	foreach ( $item->get_formatted_meta_data() as $meta_id => $meta ) {
		if ( $meta->key == 'bf-pay-for-submission' ) {
			$post_title = get_the_title( $meta->value );
			$strings[]  = '<strong class="wc-item-meta-label">' . __( "Entry", 'buddyforms-pay-for-submissions' ) . ':</strong> ' . $post_title;
		} else {
			$value     = $args['autop'] ? wp_kses_post( wpautop( make_clickable( $meta->display_value ) ) ) : wp_kses_post( make_clickable( $meta->display_value ) );
			$strings[] = '<strong class="wc-item-meta-label">' . wp_kses_post( $meta->display_key ) . ':</strong> ' . $value;
		}
	}

	if ( $strings ) {
		$html = $args['before'] . implode( $args['separator'], $strings ) . $args['after'];
	}

	return $html;
}

add_filter( 'woocommerce_display_item_meta', 'buddyforms_pay_for_submissions_on_display_items_meta', 10, 3 ); //Process the item meta to show in the order in the front
/**
 * Hide the custom meta to avoid show it as code
 *
 * @param $hidden_metas
 *
 * @return array
 */
function buddyforms_pay_for_submissions_hidden_order_itemmeta( $hidden_metas ) {
	return array_merge( $hidden_metas, array( 'bf-pay-for-submission' ) );
}

add_filter( 'woocommerce_hidden_order_itemmeta', 'buddyforms_pay_for_submissions_hidden_order_itemmeta', 10, 1 ); //Hide the custom meta to avoid show it as code

/**
 * Add the custom meta to the line item in the backend
 *
 * @param $item_id
 * @param WC_Order_Item_Product $item
 * @param WC_Product $_product
 */
function buddyforms_pay_for_submissions_add_after_oder_item_meta( $item_id, $item, $_product ) {
	if ( ! empty( $item['bf-pay-for-submission'] ) ) {
		$post_title = get_the_title( $item['bf-pay-for-submission'] );
		$post_url   = get_permalink( $item['bf-pay-for-submission'] );
		echo '<table cellspacing="0" class="display_meta">';
		echo sprintf( "<tr><th>%s:</th><td><a href='%s'>%s</a></td></tr>", __( "Entry", 'buddyforms-pay-for-submissions' ), esc_url( $post_url ), $post_title );
		echo '</table>';
	}
}

add_action( 'woocommerce_after_order_itemmeta', 'buddyforms_pay_for_submissions_add_after_oder_item_meta', 10, 3 ); //Add the custom meta to the line item in the backend
