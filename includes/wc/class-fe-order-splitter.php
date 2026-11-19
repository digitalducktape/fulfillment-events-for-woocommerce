<?php
/**
 * Splits a paid multi-date order into one child order per fulfillment group.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Plans and performs the split of a paid order.
 */
class Fe_Order_Splitter {

	/**
	 * Parent statuses the split runs from.
	 *
	 * @var string[]
	 */
	const SPLIT_FROM = array( 'processing', 'on-hold' );

	/**
	 * Seconds after which a split lock is considered stale.
	 *
	 * @var int
	 */
	const LOCK_TTL = 600;

	/**
	 * Registers the split triggers.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_split' ), 20 );
		add_action( 'woocommerce_payment_complete_order_status_' . Fe_Split_Status::STATUS, array( __CLASS__, 'on_split_parent_paid' ), 10, 2 );
	}

	/**
	 * Status-change trigger: splits on the way into processing or on-hold.
	 *
	 * @param int           $order_id Order id.
	 * @param string        $from     Old status.
	 * @param string        $to       New status.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		if ( ! in_array( $to, self::SPLIT_FROM, true ) ) {
			return;
		}
		// Cheap early exit for the common case; maybe_split() re-reads the order.
		if ( is_object( $order ) && 'yes' !== $order->get_meta( Fe_Meta::WILL_SPLIT ) ) {
			return;
		}
		self::maybe_split( $order_id );
	}

	/**
	 * Pure split plan. One child per shipping group, in shipping-line order.
	 * Line items whose group has no shipping line (virtual items: 'none')
	 * ride on the first child. Fees, and their taxes per rate, are prorated
	 * by each child's item subtotal.
	 *
	 * @param array $line_items     List of {id, group, subtotal_cents, total_cents, tax_cents}.
	 * @param array $shipping_lines List of {id, group, ...}.
	 * @param array $fees           List of {id, total_cents, taxes?: array<rate_id,int>}.
	 * @return array List of {group, item_ids, shipping_ids, fee_allocations, fee_tax_allocations}.
	 *               Empty when fewer than two groups would result.
	 */
	public static function plan( array $line_items, array $shipping_lines, array $fees ) {
		$children = array();
		foreach ( $shipping_lines as $line ) {
			$group = isset( $line['group'] ) ? (string) $line['group'] : '';
			if ( '' === $group ) {
				continue;
			}
			if ( ! isset( $children[ $group ] ) ) {
				$children[ $group ] = array(
					'group'               => $group,
					'item_ids'            => array(),
					'shipping_ids'        => array(),
					'fee_allocations'     => array(),
					'fee_tax_allocations' => array(),
				);
			}
			$children[ $group ]['shipping_ids'][] = $line['id'];
		}
		if ( count( $children ) < 2 ) {
			return array();
		}

		$first_group = key( $children );
		// Untagged shipping lines stay with the first child so no charge is lost.
		foreach ( $shipping_lines as $line ) {
			if ( ! isset( $line['group'] ) || '' === (string) $line['group'] ) {
				$children[ $first_group ]['shipping_ids'][] = $line['id'];
			}
		}

		$weights = array();
		foreach ( $children as $group => $child ) {
			$weights[ $group ] = 0;
		}
		foreach ( $line_items as $item ) {
			$group = isset( $item['group'] ) ? (string) $item['group'] : '';
			if ( ! isset( $children[ $group ] ) ) {
				$group = $first_group;
			}
			$children[ $group ]['item_ids'][] = $item['id'];
			$weights[ $group ]               += isset( $item['subtotal_cents'] ) ? (int) $item['subtotal_cents'] : 0;
		}

		foreach ( $fees as $fee ) {
			$fee_id = $fee['id'];
			$shares = Fe_Split_Math::prorate( isset( $fee['total_cents'] ) ? (int) $fee['total_cents'] : 0, $weights );
			$taxes  = array();
			foreach ( isset( $fee['taxes'] ) ? (array) $fee['taxes'] : array() as $rate_id => $tax_cents ) {
				foreach ( Fe_Split_Math::prorate( (int) $tax_cents, $weights ) as $group => $part ) {
					$taxes[ $group ][ $rate_id ] = $part;
				}
			}
			foreach ( $children as $group => $child ) {
				$tax_parts = isset( $taxes[ $group ] ) ? $taxes[ $group ] : array();
				if ( 0 === $shares[ $group ] && 0 === array_sum( $tax_parts ) ) {
					continue;
				}
				$children[ $group ]['fee_allocations'][ $fee_id ]     = $shares[ $group ];
				$children[ $group ]['fee_tax_allocations'][ $fee_id ] = $tax_parts;
			}
		}

		return array_values( $children );
	}

	/**
	 * Splits the order when it was marked at checkout and not split yet.
	 * Serialised per order by a database lock, so a webhook and the checkout
	 * return can't both split it.
	 *
	 * @param int $order_id Order id.
	 */
	public static function maybe_split( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! self::acquire_lock( $order_id ) ) {
			return;
		}
		try {
			self::split_locked( $order_id );
		} finally {
			self::release_lock( $order_id );
		}
	}

	/**
	 * Option name of the per-order split lock.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function lock_name( $order_id ) {
		return 'fe_split_lock_' . (int) $order_id;
	}

	/**
	 * Takes the per-order lock. add_option() is not atomic (it upserts with
	 * ON DUPLICATE KEY UPDATE, so two racing requests both "succeed"); an
	 * INSERT IGNORE on the unique option_name is. A lock older than
	 * LOCK_TTL seconds is treated as stale, removed and retried once.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	private static function acquire_lock( $order_id ) {
		global $wpdb;
		$name   = self::lock_name( $order_id );
		$insert = function () use ( $wpdb, $name ) {
			return 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
		};
		if ( $insert() ) {
			return true;
		}
		$since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null !== $since && time() - (int) $since > self::LOCK_TTL ) {
			// Compare-and-delete, so a lock another request just renewed survives.
			$wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => $since,
				)
			);
			return $insert();
		}
		return false;
	}

	/**
	 * Releases the per-order lock.
	 *
	 * @param int $order_id Order id.
	 */
	private static function release_lock( $order_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::lock_name( $order_id ) ) );
	}

	/**
	 * The split itself, run while holding the lock. Phase A builds every child
	 * as a pending order with no side effects and rolls back on failure;
	 * phase B hands stock and sales over and moves the statuses.
	 *
	 * @param int $order_id Order id.
	 */
	private static function split_locked( $order_id ) {
		// Fresh read after taking the lock.
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( Fe_Meta::WILL_SPLIT ) || $order->get_meta( Fe_Meta::SPLIT_DONE ) ) {
			return;
		}
		$parent_status = (string) $order->get_status();
		if ( ! in_array( $parent_status, self::SPLIT_FROM, true ) ) {
			return;
		}
		if ( ! apply_filters( 'fe_should_split_order', true, $order ) ) {
			return;
		}

		$items    = $order->get_items( 'line_item' );
		$shipping = $order->get_items( 'shipping' );
		$fees     = $order->get_items( 'fee' );
		$plan     = self::plan( self::item_rows( $items ), self::item_rows( $shipping ), self::fee_rows( $fees ) );
		if ( empty( $plan ) ) {
			return;
		}

		// Mark first, so a repeated trigger can't split twice.
		$order->update_meta_data( Fe_Meta::SPLIT_DONE, 'yes' );
		$order->save();

		// Phase A: pending children only. No status change, emails, stock or sales.
		$children = array();
		try {
			foreach ( $plan as $spec ) {
				self::create_child( $order, $spec, $items, $shipping, $fees, $children );
			}
			self::fix_drift( $order, $children );
		} catch ( Throwable $e ) {
			self::roll_back( $order, $children, $e );
			return;
		}

		// Phase B: hand stock and sales over to the children, then move statuses.
		$done = array();
		try {
			foreach ( $children as $child ) {
				// Flags first, so wc_maybe_reduce_stock_levels() and
				// wc_update_total_sales_counts() skip the child. Passing the
				// object keeps this instance in step on both order stores.
				$store = $child->get_data_store();
				$store->set_stock_reduced( $child, true );
				$store->set_recorded_sales( $child, true );
				/* translators: %s: parent order number. */
				$note = sprintf( __( 'Created from split order #%s.', 'fulfillment-events-for-woocommerce' ), $order->get_order_number() );
				if ( false === $child->update_status( $parent_status, $note ) ) {
					throw new RuntimeException( sprintf( 'could not move order #%s to %s', $child->get_order_number(), $parent_status ) );
				}
				$done[] = $child;
			}

			// The children now own the reduced stock and recorded sales; the
			// parent must not restore stock or decrease sales.
			foreach ( $items as $item ) {
				if ( '' !== (string) $item->get_meta( '_reduced_stock', true ) ) {
					$item->delete_meta_data( '_reduced_stock' );
					$item->save();
				}
			}
			$order->get_data_store()->set_recorded_sales( $order, false );

			$ids     = array();
			$numbers = array();
			foreach ( $children as $child ) {
				$ids[]     = (int) $child->get_id();
				$numbers[] = '#' . $child->get_order_number();
			}
			$order->update_meta_data( Fe_Meta::CHILDREN, implode( ',', $ids ) );
			/* translators: %s: comma-separated child order numbers. */
			$order->add_order_note( sprintf( __( 'Split into %s.', 'fulfillment-events-for-woocommerce' ), implode( ', ', $numbers ) ) );
			if ( false === $order->update_status( Fe_Split_Status::STATUS ) ) {
				throw new RuntimeException( 'could not move the parent to Split' );
			}
		} catch ( Throwable $e ) {
			self::report_partial( $order, $children, $done, $e );
			return;
		}

		do_action( 'fe_order_split', $order, $children );
	}

	/**
	 * When payment completes on a parent that was split while on-hold,
	 * WooCommerce only fires woocommerce_payment_complete_order_status_fe-split.
	 * Moves the on-hold children to processing and records the payment date.
	 *
	 * @param int    $order_id       Parent order id.
	 * @param string $transaction_id Transaction id, if any.
	 */
	public static function on_split_parent_paid( $order_id, $transaction_id = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$changed = false;
		if ( ! $order->get_date_paid() ) {
			$order->set_date_paid( time() );
			$changed = true;
		}
		if ( '' !== (string) $transaction_id && '' === (string) $order->get_transaction_id() ) {
			$order->set_transaction_id( (string) $transaction_id );
			$changed = true;
		}
		if ( $changed ) {
			$order->save();
		}
		$ids = array_filter( array_map( 'intval', explode( ',', (string) $order->get_meta( Fe_Meta::CHILDREN ) ) ) );
		foreach ( $ids as $id ) {
			$child = wc_get_order( $id );
			if ( $child && 'on-hold' === $child->get_status() ) {
				/* translators: %s: parent order number. */
				$child->update_status( 'processing', sprintf( __( 'Payment completed on #%s.', 'fulfillment-events-for-woocommerce' ), $order->get_order_number() ) );
			}
		}
	}

	/**
	 * Plain rows for plan() from line or shipping items.
	 *
	 * @param WC_Order_Item[] $items Items keyed by id.
	 * @return array
	 */
	private static function item_rows( $items ) {
		$rows = array();
		foreach ( $items as $id => $item ) {
			$subtotal = is_callable( array( $item, 'get_subtotal' ) ) ? $item->get_subtotal() : $item->get_total();
			$rows[]   = array(
				'id'             => (int) $id,
				'group'          => (string) $item->get_meta( Fe_Meta::GROUP, true ),
				'subtotal_cents' => Fe_Split_Math::to_cents( $subtotal ),
				'total_cents'    => Fe_Split_Math::to_cents( $item->get_total() ),
				'tax_cents'      => Fe_Split_Math::to_cents( $item->get_total_tax() ),
			);
		}
		return $rows;
	}

	/**
	 * Plain rows for plan() from fee items.
	 *
	 * @param WC_Order_Item_Fee[] $fees Fees keyed by id.
	 * @return array
	 */
	private static function fee_rows( $fees ) {
		$rows = array();
		foreach ( $fees as $id => $fee ) {
			$taxes = $fee->get_taxes();
			$cents = array();
			foreach ( isset( $taxes['total'] ) ? (array) $taxes['total'] : array() as $rate_id => $tax ) {
				$cents[ $rate_id ] = Fe_Split_Math::to_cents( $tax );
			}
			$rows[] = array(
				'id'          => (int) $id,
				'total_cents' => Fe_Split_Math::to_cents( $fee->get_total() ),
				'taxes'       => $cents,
			);
		}
		return $rows;
	}

	/**
	 * Order props copied from the parent onto a child.
	 *
	 * @param WC_Order $parent Parent order.
	 * @return array Prop name => value.
	 */
	private static function child_props( $parent ) {
		$props = array(
			'customer_id'          => $parent->get_customer_id(),
			'payment_method'       => $parent->get_payment_method(),
			'payment_method_title' => $parent->get_payment_method_title(),
			'transaction_id'       => $parent->get_transaction_id(),
			'customer_note'        => $parent->get_customer_note(),
			'currency'             => $parent->get_currency(),
			'prices_include_tax'   => $parent->get_prices_include_tax(),
			'customer_ip_address'  => $parent->get_customer_ip_address(),
			'customer_user_agent'  => $parent->get_customer_user_agent(),
			'created_via'          => 'fe-split',
		);
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( (array) $parent->get_address( $type ) as $key => $value ) {
				$props[ $type . '_' . $key ] = $value;
			}
		}
		return $props;
	}

	/**
	 * Copies every meta entry of one item onto another, `_reduced_stock` included.
	 *
	 * @param WC_Order_Item $from Source item.
	 * @param WC_Order_Item $to   Target item.
	 */
	private static function copy_item_meta( $from, $to ) {
		foreach ( $from->get_meta_data() as $meta ) {
			$to->add_meta_data( $meta->key, $meta->value, false );
		}
	}

	/**
	 * Creates one pending child order for a plan entry.
	 *
	 * @param WC_Order $parent   Parent order.
	 * @param array    $spec     Plan entry.
	 * @param array    $items    Parent line items keyed by id.
	 * @param array    $shipping Parent shipping items keyed by id.
	 * @param array    $fees     Parent fee items keyed by id.
	 * @param array    $created  Children created so far; the new child is appended
	 *                           as soon as it exists, so a rollback can delete it.
	 * @return WC_Order
	 * @throws RuntimeException When the child can't be saved.
	 */
	private static function create_child( $parent, array $spec, $items, $shipping, $fees, array &$created ) {
		$props = apply_filters( 'fe_child_order_args', self::child_props( $parent ), $spec['group'], $parent );

		$child = new WC_Order();
		$child->set_props( $props );
		$child->set_status( 'pending' );
		$child->save();
		if ( ! $child->get_id() ) {
			throw new RuntimeException( 'could not create a split order' );
		}
		$created[] = $child;

		foreach ( $spec['item_ids'] as $id ) {
			$src  = $items[ $id ];
			$item = new WC_Order_Item_Product();
			$item->set_name( $src->get_name() );
			$item->set_product_id( $src->get_product_id() );
			$item->set_variation_id( $src->get_variation_id() );
			$item->set_quantity( $src->get_quantity() );
			$item->set_tax_class( $src->get_tax_class() );
			$item->set_subtotal( $src->get_subtotal() );
			$item->set_total( $src->get_total() );
			$item->set_taxes( $src->get_taxes() );
			$item->set_subtotal_tax( $src->get_subtotal_tax() );
			$item->set_total_tax( $src->get_total_tax() );
			self::copy_item_meta( $src, $item );
			$child->add_item( $item );
		}

		foreach ( $spec['shipping_ids'] as $id ) {
			$src  = $shipping[ $id ];
			$item = new WC_Order_Item_Shipping();
			$item->set_method_title( $src->get_method_title() );
			$item->set_method_id( $src->get_method_id() );
			$item->set_instance_id( $src->get_instance_id() );
			$item->set_total( $src->get_total() );
			$item->set_taxes( $src->get_taxes() );
			self::copy_item_meta( $src, $item );
			$child->add_item( $item );
		}

		foreach ( $spec['fee_allocations'] as $id => $cents ) {
			$src       = $fees[ $id ];
			$tax_parts = isset( $spec['fee_tax_allocations'][ $id ] ) ? $spec['fee_tax_allocations'][ $id ] : array();
			$taxes     = array();
			foreach ( $tax_parts as $rate_id => $tax_cents ) {
				$taxes[ $rate_id ] = $tax_cents / 100;
			}
			$item = new WC_Order_Item_Fee();
			$item->set_name( $src->get_name() );
			$item->set_tax_class( $src->get_tax_class() );
			$item->set_tax_status( $src->get_tax_status() );
			$item->set_amount( $cents / 100 );
			$item->set_total( $cents / 100 );
			$item->set_taxes( array( 'total' => $taxes ) );
			self::copy_item_meta( $src, $item );
			$child->add_item( $item );
		}

		$fulfillment = null;
		if ( 0 === strpos( $spec['group'], 'event:' ) && ! empty( $spec['shipping_ids'] ) ) {
			$src       = $shipping[ $spec['shipping_ids'][0] ];
			$rate_meta = array();
			foreach ( Fe_Order_Stamper::RATE_KEYS as $key ) {
				$value = $src->get_meta( 'fe_' . $key, true );
				if ( '' !== $value && null !== $value ) {
					$rate_meta[ 'fe_' . $key ] = $value;
				}
			}
			if ( ! empty( $rate_meta['fe_date'] ) ) {
				$fulfillment              = Fe_Order_Stamper::fulfillment_from_rate_meta( $rate_meta );
				$fulfillment['parent_id'] = (int) $parent->get_id();
			}
		}
		$meta = $fulfillment ? Fe_Meta::to_meta( $fulfillment ) : array( Fe_Meta::PARENT_ID => (string) (int) $parent->get_id() );
		foreach ( $meta as $key => $value ) {
			$child->update_meta_data( $key, $value );
		}

		$coupons = $parent->get_coupon_codes();
		if ( ! empty( $coupons ) ) {
			/* translators: %s: comma-separated coupon codes. */
			$child->add_order_note( sprintf( __( 'Coupons used on the original order: %s. Line totals already include the discount.', 'fulfillment-events-for-woocommerce' ), implode( ', ', $coupons ) ) );
		}

		// Tax items are summed from the copied item taxes; no tax is recalculated.
		$child->update_taxes();
		$child->calculate_totals( false );
		$child->save();
		if ( method_exists( $child, 'get_last_save_error' ) && null !== $child->get_last_save_error() ) {
			throw new RuntimeException( 'could not save split order #' . (int) $child->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $child;
	}

	/**
	 * Moves any cent drift onto the first child so the children sum exactly
	 * to the parent total.
	 *
	 * @param WC_Order   $parent   Parent order.
	 * @param WC_Order[] $children Children.
	 */
	private static function fix_drift( $parent, array $children ) {
		if ( empty( $children ) ) {
			return;
		}
		$sum = 0;
		foreach ( $children as $child ) {
			$sum += Fe_Split_Math::to_cents( $child->get_total() );
		}
		$delta = Fe_Split_Math::to_cents( $parent->get_total() ) - $sum;
		if ( 0 === $delta ) {
			return;
		}
		$first = $children[0];
		$first->set_total( ( Fe_Split_Math::to_cents( $first->get_total() ) + $delta ) / 100 );
		/* translators: %s: rounding adjustment amount. */
		$first->add_order_note( sprintf( __( 'Total adjusted by %s so the split orders add up to the amount charged.', 'fulfillment-events-for-woocommerce' ), number_format( $delta / 100, 2, '.', '' ) ) );
		$first->save();
	}

	/**
	 * Phase A failed: deletes the pending children and clears the split mark,
	 * so the parent stays a valid single order.
	 *
	 * @param WC_Order   $parent   Parent order.
	 * @param WC_Order[] $children Children created before the failure.
	 * @param Throwable  $e        Error.
	 */
	private static function roll_back( $parent, array $children, $e ) {
		foreach ( $children as $child ) {
			try {
				$child->delete( true );
			} catch ( Throwable $ignored ) {
				self::log( sprintf( 'Could not delete pending split order #%d: %s', $child->get_id(), $ignored->getMessage() ) );
			}
		}
		$parent->delete_meta_data( Fe_Meta::SPLIT_DONE );
		$parent->save();
		self::log( sprintf( 'Split of order #%d failed and was rolled back: %s', $parent->get_id(), $e->getMessage() ) );
		/* translators: %s: error message. */
		$parent->add_order_note( sprintf( __( 'Splitting this order failed and nothing was changed: %s. It stays one order.', 'fulfillment-events-for-woocommerce' ), $e->getMessage() ) );
		do_action( 'fe_order_split_failed', $parent, array(), $e );
	}

	/**
	 * Phase B failed partway. Nothing is undone automatically (some children
	 * may already hold stock and sales); staff must review.
	 *
	 * @param WC_Order   $parent   Parent order.
	 * @param WC_Order[] $children All children.
	 * @param WC_Order[] $done     Children whose status was moved.
	 * @param Throwable  $e        Error.
	 */
	private static function report_partial( $parent, array $children, array $done, $e ) {
		$all   = array();
		$moved = array();
		foreach ( $children as $child ) {
			$all[] = '#' . $child->get_order_number();
		}
		foreach ( $done as $child ) {
			$moved[] = '#' . $child->get_order_number();
		}
		self::log( sprintf( 'Split of order #%d NEEDS MANUAL REVIEW: %s', $parent->get_id(), $e->getMessage() ) );
		$parent->add_order_note(
			sprintf(
				/* translators: 1: error message, 2: all child order numbers, 3: child order numbers already moved, or a dash. */
				__( 'Split needs manual review: %1$s. Split orders: %2$s. Already moved out of pending: %3$s. Check stock, sales and statuses on this order and those.', 'fulfillment-events-for-woocommerce' ),
				$e->getMessage(),
				implode( ', ', $all ),
				empty( $moved ) ? '-' : implode( ', ', $moved )
			)
		);
		do_action( 'fe_order_split_failed', $parent, $children, $e );
	}

	/**
	 * Writes to the WooCommerce log when available.
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'fulfillment-events' ) );
		}
	}
}
