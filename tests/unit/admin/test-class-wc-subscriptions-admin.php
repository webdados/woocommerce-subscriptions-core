<?php
/**
 * Class WC_Subscriptions_Admin_Test
 *
 * @package WooCommerce\SubscriptionsCore\Tests
 */
class WC_Subscriptions_Admin_Test extends WP_UnitTestCase {
	/**
	 * Test for `maybe_attach_gettext_callback` and `maybe_unattach_gettext_callback` methods.
	 *
	 * @param bool        $is_admin     Whether the user is an admin or not.
	 * @param string      $screen_id    Screen ID.
	 * @param int|boolean $expected     Expected result.
	 * @return void
	 * @dataProvider provide_test_maybe_attach_and_unattach_gettext_callback
	 */
	public function test_maybe_attach_and_unattach_gettext_callback( $is_admin, $screen_id, $expected ) {
		if ( $is_admin ) {
			$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );
		}

		set_current_screen( $screen_id );

		$admin = new WC_Subscriptions_Admin();

		$admin->maybe_attach_gettext_callback();
		$this->assertSame( $expected, has_filter( 'gettext', [ WC_Subscriptions_Admin::class, 'change_order_item_editable_text' ] ) );

		$admin->maybe_unattach_gettext_callback();
		$this->assertSame( false, has_filter( 'gettext', [ WC_Subscriptions_Admin::class, 'change_order_item_editable_text' ] ) );
	}

	/**
	 * Generic data provider for `test_maybe_attach_gettext_callback` values.
	 *
	 * @return array
	 */
	public function provide_test_maybe_attach_and_unattach_gettext_callback() {
		return array(
			'not an admin'                               => array(
				'is admin'  => false,
				'screen id' => '',
				'expected'  => false,
			),
			'invalid screen'                             => array(
				'is admin'  => true,
				'screen id' => '',
				'expected'  => false,
			),
			'hpos disabled, edit subscriptions page'     => array(
				'is admin'  => true,
				'screen id' => 'shop_subscription',
				'expected'  => 10,
			),
			'hpos disabled, not edit subscriptions page' => array(
				'is admin'  => true,
				'screen id' => '',
				'expected'  => false,
			),
		);
	}

	/**
	 * Test for `change_order_item_editable_text` method.
	 *
	 * @return void
	 * @dataProvider provide_test_change_order_item_editable_text
	 */
	public function test_change_order_item_editable_text( $text, $expected ) {
		$admin = new WC_Subscriptions_Admin();

		$this->assertSame( $expected, $admin->change_order_item_editable_text( $text, $text, 'woocommerce-subscriptions' ) );
	}

	/**
	 * Provider for `test_change_order_item_editable_text` values.
	 *
	 * @return array
	 */
	public function provide_test_change_order_item_editable_text() {
		return array(
			'This order is no longer editable.' => array(
				'text'     => 'This order is no longer editable.',
				'expected' => 'Subscription items can no longer be edited.',
			),
			'To edit this order change the status back to "Pending"' => array(
				'text'     => 'To edit this order change the status back to "Pending"',
				'expected' => 'This subscription is no longer editable because the payment gateway does not allow modification of recurring amounts.',
			),
			'To edit this order change the status back to "Pending payment"' => array(
				'text'     => 'To edit this order change the status back to "Pending payment"',
				'expected' => 'This subscription is no longer editable because the payment gateway does not allow modification of recurring amounts.',
			),
			'Random text.'                      => array(
				'text'     => 'Random text.',
				'expected' => 'Random text.',
			),
		);
	}
}
