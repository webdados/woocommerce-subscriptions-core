<?php
/**
 * Subscriptions Renewal Order Class
 *
 * Provides an API for creating and handling renewal orders.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Order
 * @category	Class
 * @author		Brent Shepherd
 * @since 		1.2
 */
class WC_Subscriptions_Renewal_Order {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0
	 */
	public static function init() {

		// Trigger special hook when payment is completed on renewal orders
		add_action( 'woocommerce_payment_complete', __CLASS__ . '::trigger_renewal_payment_complete', 10 );

		// When a renewal order's status changes, check if a corresponding subscription's status should be changed by marking it as paid (we can't use the 'woocommerce_payment_complete' here because it's not triggered by all payment gateways)
		add_filter( 'woocommerce_order_status_changed', __CLASS__ . '::maybe_record_subscription_payment', 10, 3 );

		add_filter( 'wcs_renewal_order_created', __CLASS__ . '::add_order_note', 10, 2 );
	}

	/* Helper functions */

	/**
	 * Trigger a special hook for payments on a completed renewal order.
	 *
	 * @since 1.5.4
	 */
	public static function trigger_renewal_payment_complete( $order_id ) {
		if ( wcs_order_contains_renewal( $order_id ) ) {
			do_action( 'woocommerce_renewal_order_payment_complete', $order_id );
		}
	}

	/**
	 * Check if a given renewal order was created to replace a failed renewal order.
	 *
	 * @since 1.5.12
	 * @param int ID of the renewal order you want to check against
	 * @return mixed If the renewal order did replace a failed order, the ID of the fail order, else false
	 */
	public static function get_failed_order_replaced_by( $renewal_order_id ) {
		global $wpdb;

		$failed_order_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_failed_order_replaced_by' AND meta_value = %s", $renewal_order_id ) );

		return ( null === $failed_order_id ) ? false : $failed_order_id;
	}

	/**
	 * Whenever a renewal order's status is changed, check if a corresponding subscription's status should be changed
	 *
	 * This function is hooked to 'woocommerce_order_status_changed', rather than 'woocommerce_payment_complete', to ensure
	 * subscriptions are updated even if payment is processed by a manual payment gateways (which would never trigger the
	 * 'woocommerce_payment_complete' hook) or by some other means that circumvents that hook.
	 *
	 * @since 2.0
	 */
	public static function maybe_record_subscription_payment( $order_id, $orders_old_status, $orders_new_status ) {

		if ( ! wcs_order_contains_renewal( $order_id ) ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );

		foreach ( $subscriptions as $subscription ) {

			// Do we need to activate a subscription?
			if ( in_array( $orders_new_status, array( 'processing', 'completed' ) ) && ! $subscription->has_status( 'active' ) ) {

				if ( in_array( $orders_old_status, array( 'pending', 'on-hold', 'failed' ) ) ) {
					$subscription->payment_complete();
				}

				if ( 'failed' === $orders_old_status ) {
					do_action( 'woocommerce_subscriptions_paid_for_failed_renewal_order', wc_get_order( $order_id ), $subscription );
				}
			} elseif ( 'failed' == $orders_new_status ) {

				$subscription->payment_failed();

			}
		}
	}

	/**
	 * Add order note to subscription to record the renewal order
	 *
	 * @param WC_Order|int $renewal_order
	 * @param WC_Subscription|int $subscription
	 * @since 2.0
	 */
	public static function add_order_note( $renewal_order, $subscription ) {
		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		if ( ! is_object( $renewal_order ) ) {
			$renewal_order = wc_get_order( $renewal_order );
		}

		if ( is_a( $renewal_order, 'WC_Order' ) && wcs_is_subscription( $subscription ) ) {
			$subscription->add_order_note( sprintf( __( 'Order %s created to record renewal.', 'woocommerce-subscriptions' ), sprintf( '<a href="%s">%s%s</a> ', esc_url( wcs_get_edit_post_link( $renewal_order->id ) ), _x( '#', 'hash before order number', 'woocommerce-subscriptions' ), $renewal_order->get_order_number() ) ) );
		}

		return $renewal_order;
	}

	/* Deprecated functions */

	/**
	 * Hooks to the renewal order created action to determine if the order should be emailed to the customer.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function maybe_send_customer_renewal_order_email( $order ) {
		_deprecated_function( __METHOD__, '1.4' );
		if ( 'yes' == get_option( WC_Subscriptions_Admin::$option_prefix . '_email_renewal_order' ) ) {
			self::send_customer_renewal_order_email( $order );
		}
	}

	/**
	 * Processing Order
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function send_customer_renewal_order_email( $order ) {
		_deprecated_function( __METHOD__, '1.4' );

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$mailer = WC()->mailer();
		$mails  = $mailer->get_emails();

		$mails['WCS_Email_Customer_Renewal_Invoice']->trigger( $order->id );
	}

	/**
	 * Change the email subject of the new order email to specify the order is a subscription renewal order
	 *
	 * @param string $subject The default WooCommerce email subject
	 * @param WC_Order $order The WC_Order object which the email relates to
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function email_subject_new_renewal_order( $subject, $order ) {
		_deprecated_function( __METHOD__, '1.4' );

		if ( wcs_order_contains_renewal( $order ) ) {
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$subject  = apply_filters(
				'woocommerce_subscriptions_email_subject_new_renewal_order',
				sprintf( __( '[%s] New Subscription Renewal Order (%s)', 'woocommerce-subscriptions' ), $blogname, $order->get_order_number() ),
				$order
			);
		}

		return $subject;
	}

	/**
	 * Change the email subject of the processing order email to specify the order is a subscription renewal order
	 *
	 * @param string $subject The default WooCommerce email subject
	 * @param WC_Order $order The WC_Order object which the email relates to
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function email_subject_customer_procesing_renewal_order( $subject, $order ) {
		_deprecated_function( __METHOD__, '1.4' );

		if ( wcs_order_contains_renewal( $order ) ) {
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$subject  = apply_filters(
				'woocommerce_subscriptions_email_subject_customer_procesing_renewal_order',
				sprintf( __( '[%s] Subscription Renewal Order', 'woocommerce-subscriptions' ), $blogname ),
				$order
			);
		}

		return $subject;
	}

	/**
	 * Change the email subject of the completed order email to specify the order is a subscription renewal order
	 *
	 * @param string $subject The default WooCommerce email subject
	 * @param WC_Order $order The WC_Order object which the email relates to
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function email_subject_customer_completed_renewal_order( $subject, $order ) {
		_deprecated_function( __METHOD__, '1.4' );

		if ( wcs_order_contains_renewal( $order ) ) {
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$subject  = apply_filters(
				'woocommerce_subscriptions_email_subject_customer_completed_renewal_order',
				sprintf( __( '[%s] Subscription Renewal Order', 'woocommerce-subscriptions' ), $blogname ),
				$order
			);
		}

		return $subject;
	}

	/**
	 * Generate an order to record an automatic subscription payment.
	 *
	 * This function is hooked to the 'process_subscription_payment' which is fired when a payment gateway calls
	 * the @see WC_Subscriptions_Manager::process_subscription_payment() function. Because manual payments will
	 * also call this function, the function only generates a renewal order if the @see WC_Order::payment_complete()
	 * will be called for the renewal order.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function generate_paid_renewal_order( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_create_renewal_order( WC_Subscription $subscription )' );
		$subscription  = wcs_get_subscription_from_key( $subscription_key );
		$renewal_order = wcs_create_renewal_order( $subscription );
		$renewal_order->payment_complete();
		return $renewal_order->id;
	}

	/**
	 * Generate an order to record a subscription payment failure.
	 *
	 * This function is hooked to the 'processed_subscription_payment_failure' hook called when a payment
	 * gateway calls the @see WC_Subscriptions_Manager::process_subscription_payment_failure()
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function generate_failed_payment_renewal_order( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_create_renewal_order( WC_Subscription $subscription )' );
		$renewal_order = wcs_create_renewal_order( wcs_get_subscription_from_key( $subscription_key ) );
		$renewal_order->update_status( 'failed' );
		return $renewal_order->id;
	}

	/**
	 * Generate an order to record a subscription payment.
	 *
	 * This function is hooked to the scheduled subscription payment hook to create a pending
	 * order for each scheduled subscription payment.
	 *
	 * When a payment gateway calls the @see WC_Subscriptions_Manager::process_subscription_payment()
	 * @see WC_Order::payment_complete() will be called for the renewal order.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 */
	public static function maybe_generate_manual_renewal_order( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::maybe_create_manual_renewal_order( WC_Subscription $subscription )' );
		self::maybe_create_manual_renewal_order( wcs_get_subscription_from_key( $subscription_key ) )->id;
	}

	/**
	 * Get the ID of the parent order for a subscription renewal order.
	 *
	 * Deprecated because a subscription's details are now stored in a WC_Subscription object, not the
	 * parent order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_parent_order_id( $renewal_order ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscriptions_for_renewal_order()' );

		$parent_order = self::get_parent_order( $renewal_order );

		return ( null === $parent_order ) ? null : $parent_order->id;
	}

	/**
	 * Get the parent order for a subscription renewal order.
	 *
	 * Deprecated because a subscription's details are now stored in a WC_Subscription object, not the
	 * parent order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 * @deprecated 2.0 self::get_parent_subscription() is the better function to use now as a renewal order
	 */
	public static function get_parent_order( $renewal_order ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscriptions_for_renewal_order()' );

		if ( ! is_object( $renewal_order ) ) {
			$renewal_order = new WC_Order( $renewal_order );
		}

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order );
		$subscription  = array_pop( $subscriptions );

		if ( false === $subscription->order ) { // There is no original order
			$parent_order = null;
		} else {
			$parent_order = $subscription->order;
		}

		return apply_filters( 'woocommerce_subscriptions_parent_order', $parent_order, $renewal_order );
	}

	/**
	 * Returns the number of renewals for a given parent order
	 *
	 * @param int $order_id The ID of a WC_Order object.
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_renewal_order_count( $order_id ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_related_orders()' );

		$subscriptions_for_order = wcs_get_subscriptions_for_order( $order_id );

		if ( ! empty( $subscriptions_for_order ) ) {

			$subscription = array_pop( $subscriptions_for_order );
			$all_orders   = $subscription->get_related_orders();

			$renewal_order_count = count( $all_orders );

			// Don't include the initial order (if any)
			if ( false !== $subscription->order ) {
				$renewal_order_count -= 1;
			}
		} else {
			$renewal_order_count = 0;
		}

		return apply_filters( 'woocommerce_subscriptions_renewal_order_count', $renewal_order_count, $order_id );
	}

	/**
	 * Returns a URL including required parameters for an authenticated user to renew a subscription
	 *
	 * Deprecated because the use of a $subscription_key is deprecated.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_users_renewal_link( $subscription_key, $role = 'parent' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_users_resubscribe_link( $subscription )' );
		return wcs_get_users_resubscribe_link( wcs_get_subscription_from_key( $subscription_key ) );
	}

	/**
	 * Returns a URL including required parameters for an authenticated user to renew a subscription by product ID.
	 *
	 * Deprecated because the use of a $subscription_key is deprecated.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_users_renewal_link_for_product( $product_id ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_users_resubscribe_link_for_product( $subscription )' );
		return wcs_get_users_resubscribe_link_for_product( $product_id );
	}

	/**
	 * Check if a given subscription can be renewed.
	 *
	 * Deprecated because the use of a $subscription_key is deprecated.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function can_subscription_be_renewed( $subscription_key, $user_id = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_can_user_resubscribe_to( $subscription, $user_id )' );
		return wcs_can_user_resubscribe_to( wcs_get_subscription_from_key( $subscription_key ), $user_id );
	}

	/**
	 * Checks if the current request is by a user to renew their subscription, and if it is
	 * set up a subscription renewal via the cart for the product/variation that is being renewed.
	 *
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function maybe_create_renewal_order_for_user() {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::maybe_setup_resubscribe_via_cart()' );
	}

	/**
	 * When restoring the cart from the session, if the cart item contains addons, but is also
	 * a subscription renewal, do not adjust the price because the original order's price will
	 * be used, and this includes the addons amounts.
	 *
	 * @since 1.5.5
	 * @deprecated 2.0
	 */
	public static function product_addons_adjust_price( $adjust_price, $cart_item ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::product_addons_adjust_price()' );
	}

	/**
	 * Created a new order for renewing a subscription product based on the details of a previous order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the a new order should be created.
	 * @param string $product_id The ID of the subscription product in the order which needs to be added to the new order.
	 * @param array $args (optional) An array of name => value flags:
	 *         'new_order_role' string A flag to indicate whether the new order should become the master order for the subscription. Accepts either 'parent' or 'child'. Defaults to 'parent' - replace the existing order.
	 *         'checkout_renewal' bool Indicates if invoked from an interactive cart/checkout session and certain order items are not set, like taxes, shipping as they need to be set in teh calling function, like @see WC_Subscriptions_Checkout::filter_woocommerce_create_order(). Default false.
	 *         'failed_order_id' int For checkout_renewal true, indicates order id being replaced
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function generate_renewal_order( $original_order, $product_id, $args = array() ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_create_renewal_order() or wcs_create_resubscribe_order()' );

		if ( ! wcs_order_contains_subscription( $original_order ) ) {
			return false;
		}

		$args = wp_parse_args( $args, array(
			'new_order_role'   => 'parent',
			'checkout_renewal' => false,
			)
		);

		$subscriptions = wcs_get_subscriptions_for_order( $original_order );
		$subscription  = array_shift( $subscriptions );

		if ( 'parent' == $args['new_order_role'] ) {
			$new_order = wcs_create_resubscribe_order( $subscription );
		} else {
			$new_order = wcs_create_renewal_order( $subscription );
		}

		return $new_order->id;
	}

	/**
	 * If a product is being marked as not purchasable because it is limited and the customer has a subscription,
	 * but the current request is to resubscribe to the subscription, then mark it as purchasable.
	 *
	 * @since 1.5
	 * @deprecated 2.0
	 */
	public static function is_purchasable( $is_purchasable, $product ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::is_purchasable()' );
		return $is_purchasable;
	}

	/**
	 * Check if a given order is a subscription renewal order and optionally, if it is a renewal order of a certain role.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @param array $args (optional) An array of name => value flags:
	 *         'order_role' string (optional) A specific role to check the order against. Either 'parent' or 'child'.
	 *         'via_checkout' Indicates whether to check if the renewal order was via the cart/checkout process.
	 * @since 1.2
	 */
	public static function is_renewal( $order, $args = array() ) {

		$args = wp_parse_args( $args, array(
			'order_role'   => '',
			'via_checkout' => false,
			)
		);

		$is_resubscribe_order = wcs_order_contains_resubscribe( $order );
		$is_renewal_order     = wcs_order_contains_renewal( $order );

		if ( empty( $args['new_order_role'] ) ) {
			_deprecated_function( __METHOD__, '2.0', 'wcs_order_contains_resubscribe( $order ) and wcs_order_contains_renewal( $order )' );
			return ( $is_resubscribe_order || $is_renewal_order );
		} elseif ( 'parent' == $args['new_order_role'] ) {
			_deprecated_function( __METHOD__, '2.0', 'wcs_order_contains_resubscribe( $order )' );
			return $is_resubscribe_order;
		} else {
			_deprecated_function( __METHOD__, '2.0', 'wcs_order_contains_renewal( $order )' );
			return $is_renewal_order;
		}
	}

	/**
	 * Returns the renewal orders for a given parent order
	 *
	 * @param int $order_id The ID of a WC_Order object.
	 * @param string $output (optional) How you'd like the result. Can be 'ID' for IDs only or 'WC_Order' for order objects.
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function get_renewal_orders( $order_id, $output = 'ID' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_related_orders()' );

		$subscriptions = wcs_get_subscriptions_for_order( $order_id );
		$subscription  = array_shift( $subscriptions );

		if ( 'WC_Order' == $output ) {

			$renewal_orders = $subscription->get_related_orders( 'all', 'renewal' );

		} else {

			$renewal_orders = $subscription->get_related_orders( 'ids', 'renewal' );

		}

		return apply_filters( 'woocommerce_subscriptions_renewal_orders', $renewal_orders, $order_id );
	}

	/**
	 * Flag payment of manual renewal orders.
	 *
	 * This is particularly important to ensure renewals of limited subscriptions can be completed.
	 *
	 * @since 1.5.5
	 * @deprecated 2.0
	 */
	public static function get_checkout_payment_url( $pay_url, $order ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::get_checkout_payment_url() or WCS_Cart_Resubscribe::get_checkout_payment_url()' );
		return $pay_url;
	}

	/**
	 * Process a renewal payment when a customer has completed the payment for a renewal payment which previously failed.
	 *
	 * @since 1.3
	 * @deprecated 2.0
	 */
	public static function maybe_process_failed_renewal_order_payment( $order_id ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::maybe_change_subscription_status( $order_id, $orders_old_status, $orders_new_status )' );
	}

	/**
	 * If the payment for a renewal order has previously failed and is then paid, then the
	 * @see WC_Subscriptions_Manager::process_subscription_payments_on_order() function would
	 * never be called. This function makes sure it is called.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function process_failed_renewal_order_payment( $order_id ) {
		_deprecated_function( __METHOD__, '2.0' );
		if ( wcs_order_contains_renewal( $order_id ) ) {

			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
			$subscription  = array_pop( $subscriptions );

			if ( $subscription->is_manual() ) {
				add_action( 'woocommerce_payment_complete', __CLASS__ . '::process_subscription_payment_on_child_order', 10, 1 );
			}
		}
	}

	/**
	 * Records manual payment of a renewal order against a subscription.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function maybe_record_renewal_order_payment( $order_id ) {
		_deprecated_function( __METHOD__, '2.0' );
		if ( wcs_order_contains_renewal( $order_id ) ) {

			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
			$subscription  = array_pop( $subscriptions );

			if ( $subscription->is_manual() ) {
				self::process_subscription_payment_on_child_order( $order_id );
			}
		}
	}

	/**
	 * Records manual payment of a renewal order against a subscription.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function maybe_record_renewal_order_payment_failure( $order_id ) {
		_deprecated_function( __METHOD__, '2.0' );
		if ( wcs_order_contains_renewal( $order_id ) ) {

			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
			$subscription  = array_pop( $subscriptions );

			if ( $subscription->is_manual() ) {
				self::process_subscription_payment_on_child_order( $order_id, 'failed' );
			}
		}
	}

	/**
	 * If the payment for a renewal order has previously failed and is then paid, we need to make sure the
	 * subscription payment function is called.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 * @deprecated 2.0
	 */
	public static function process_subscription_payment_on_child_order( $order_id, $payment_status = 'completed' ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( wcs_order_contains_renewal( $order_id ) ) {

			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );

			foreach ( $subscriptions as $subscription ) {

				if ( 'failed' == $payment_status ) {

					$subscription->payment_failed();

				} else {

					$subscription->payment_complete();

					$subscription->update_status( 'active' );
				}
			}
		}
	}

	/**
	 * Adds a renewal orders section to the Related Orders meta box displayed on subscription orders.
	 *
	 * @deprecated 2.0
	 * @since 1.2
	 */
	public static function renewal_orders_meta_box_section( $order, $post ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Trigger a hook when a subscription suspended due to a failed renewal payment is reactivated
	 *
	 * @since 1.3
	 */
	public static function trigger_processed_failed_renewal_order_payment_hook( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::maybe_record_subscription_payment( $order_id, $orders_old_status, $orders_new_status )' );
	}
}
WC_Subscriptions_Renewal_Order::init();