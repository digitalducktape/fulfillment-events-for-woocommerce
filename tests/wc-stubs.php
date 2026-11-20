<?php
/**
 * Minimal WordPress/WooCommerce stubs for the unit suite. Extended by later
 * tasks as more classes need them.
 */

if ( ! defined( 'FE_TESTING' ) ) {
	exit;
}

if ( ! function_exists( 'get_option' ) ) {
	/** In-memory options store for tests. */
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['fe_test_options'] ) ? $GLOBALS['fe_test_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['fe_test_options'][ $name ] = $value;
		return true;
	}
}
$GLOBALS['fe_test_options'] = array();

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	/** Returns the value unchanged unless a test set $GLOBALS['fe_test_filters'][ $hook ]. */
	function apply_filters( $hook, $value, ...$args ) {
		if ( isset( $GLOBALS['fe_test_filters'][ $hook ] ) ) {
			return call_user_func_array( $GLOBALS['fe_test_filters'][ $hook ], array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	/** Records fired actions in $GLOBALS['fe_test_actions']. */
	function do_action( $hook, ...$args ) {
		$GLOBALS['fe_test_actions'][] = array( $hook, $args );
	}
}
$GLOBALS['fe_test_filters'] = array();
$GLOBALS['fe_test_actions'] = array();

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) {
		return isset( $GLOBALS['fe_test_orders'][ $id ] ) ? $GLOBALS['fe_test_orders'][ $id ] : false;
	}
}
$GLOBALS['fe_test_orders'] = array();
$GLOBALS['fe_test_next_id'] = 100;

if ( ! class_exists( 'WC_Order_Item' ) ) {
	/**
	 * Fake order item: get_x()/set_x() over a props array, plus meta.
	 */
	class WC_Order_Item {
		public $props = array();
		public $meta  = array();
		public $id    = 0;
		public function __call( $name, $args ) {
			$prop = substr( $name, 4 );
			if ( 0 === strpos( $name, 'set_' ) ) {
				$this->props[ $prop ] = $args[0];
				return null;
			}
			return isset( $this->props[ $prop ] ) ? $this->props[ $prop ] : null;
		}
		public function get_id() {
			return $this->id;
		}
		public function get_taxes() {
			return isset( $this->props['taxes'] ) ? $this->props['taxes'] : array( 'total' => array(), 'subtotal' => array() );
		}
		public function get_meta( $key, $single = true ) {
			foreach ( $this->meta as $m ) {
				if ( $m->key === $key ) {
					return $m->value;
				}
			}
			return '';
		}
		public function get_meta_data() {
			return $this->meta;
		}
		public function add_meta_data( $key, $value, $unique = false ) {
			$this->meta[] = (object) array( 'key' => $key, 'value' => $value );
		}
		public function update_meta_data( $key, $value ) {
			$this->delete_meta_data( $key );
			$this->add_meta_data( $key, $value );
		}
		public function delete_meta_data( $key ) {
			$this->meta = array_values(
				array_filter(
					$this->meta,
					function ( $m ) use ( $key ) {
						return $m->key !== $key;
					}
				)
			);
		}
		public function save() {
			if ( ! $this->id ) {
				$this->id = $GLOBALS['fe_test_next_id']++;
			}
			return $this->id;
		}
	}
	class WC_Order_Item_Product extends WC_Order_Item {
		public $type = 'line_item';
	}
	class WC_Order_Item_Shipping extends WC_Order_Item {
		public $type = 'shipping';
	}
	class WC_Order_Item_Fee extends WC_Order_Item {
		public $type = 'fee';
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	/** Fake data store recording the stock-reduced flag on the order. */
	class FeFakeOrderDataStore {
		public function set_stock_reduced( $order, $set ) {
			$order = is_object( $order ) ? $order : wc_get_order( $order );
			$order->stock_reduced = (bool) $set;
			$order->log[]         = 'stock_reduced:' . ( $set ? 'yes' : 'no' );
		}
		public function set_recorded_sales( $order, $set ) {
			$order = is_object( $order ) ? $order : wc_get_order( $order );
			$order->recorded_sales = (bool) $set;
			$order->log[]          = 'recorded_sales:' . ( $set ? 'yes' : 'no' );
		}
		public function get_stock_reduced( $order ) {
			$order = is_object( $order ) ? $order : wc_get_order( $order );
			return $order->stock_reduced;
		}
	}

	/**
	 * Fake order: generic props, items, meta, notes, status changes.
	 */
	class WC_Order {
		public $id            = 0;
		public $props         = array( 'status' => 'pending', 'total' => 0 );
		public $meta          = array();
		public $items         = array();
		public $notes         = array();
		public $log           = array();
		public $stock_reduced  = false;
		public $recorded_sales = false;
		public $saves          = 0;
		public $deleted        = false;
		public $fail_status    = false;
		public function __call( $name, $args ) {
			$prop = substr( $name, 4 );
			if ( 0 === strpos( $name, 'set_' ) ) {
				$this->props[ $prop ] = $args[0];
				return null;
			}
			return isset( $this->props[ $prop ] ) ? $this->props[ $prop ] : '';
		}
		public function set_props( $props ) {
			foreach ( $props as $k => $v ) {
				$this->props[ $k ] = $v;
			}
		}
		public function get_id() {
			return $this->id;
		}
		public function get_order_number() {
			return (string) $this->id;
		}
		public function get_address( $type ) {
			return isset( $this->props[ $type ] ) ? $this->props[ $type ] : array();
		}
		public function get_coupon_codes() {
			return isset( $this->props['coupon_codes'] ) ? $this->props['coupon_codes'] : array();
		}
		public function get_data_store() {
			return new FeFakeOrderDataStore();
		}
		public function get_meta( $key, $single = true ) {
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}
		public function delete_meta_data( $key ) {
			unset( $this->meta[ $key ] );
		}
		public function add_item( $item ) {
			$item->save();
			$this->items[ $item->get_id() ] = $item;
		}
		public function get_items( $type = 'line_item' ) {
			$out = array();
			foreach ( $this->items as $id => $item ) {
				if ( $item->type === $type ) {
					$out[ $id ] = $item;
				}
			}
			return $out;
		}
		public function add_order_note( $note ) {
			$this->notes[] = $note;
		}
		public function update_taxes() {
		}
		public function calculate_totals( $and_taxes = true ) {
			$total = 0.0;
			foreach ( $this->items as $item ) {
				$total += (float) $item->get_total();
				$taxes  = $item->get_taxes();
				$total += array_sum( isset( $taxes['total'] ) ? $taxes['total'] : array() );
			}
			$this->props['total'] = round( $total, 2 );
			return $this->props['total'];
		}
		public function update_status( $status, $note = '' ) {
			if ( $this->fail_status ) {
				return false;
			}
			$this->props['status'] = $status;
			$this->notes[]         = $note;
			$this->log[]           = 'status:' . $status;
			$this->save();
			do_action( 'woocommerce_order_status_changed', $this->id, '', $status, $this );
			return true;
		}
		public function delete( $force = false ) {
			$this->deleted = true;
			unset( $GLOBALS['fe_test_orders'][ $this->id ] );
			return true;
		}
		public function save() {
			if ( ! $this->id ) {
				$this->id = $GLOBALS['fe_test_next_id']++;
				$GLOBALS['fe_test_orders'][ $this->id ] = $this;
			}
			$this->saves++;
			return $this->id;
		}
	}
}

if ( ! class_exists( 'FeFakeWpdb' ) ) {
	/**
	 * Fake $wpdb covering the split lock: INSERT IGNORE / SELECT / delete on options.
	 */
	class FeFakeWpdb {
		public $options = 'wp_options';
		public $rows    = array();
		public $writes  = 0;
		public function prepare( $sql, ...$args ) {
			return array( $sql, $args );
		}
		public function query( $prepared ) {
			list( $sql, $args ) = $prepared;
			if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
				$this->writes++;
				if ( isset( $this->rows[ $args[0] ] ) ) {
					return 0;
				}
				$this->rows[ $args[0] ] = $args[1];
				return 1;
			}
			return false;
		}
		public function get_var( $prepared ) {
			list( $sql, $args ) = $prepared;
			return isset( $this->rows[ $args[0] ] ) ? $this->rows[ $args[0] ] : null;
		}
		public function delete( $table, $where ) {
			$this->writes++;
			$name = $where['option_name'];
			if ( ! isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			if ( isset( $where['option_value'] ) && (string) $this->rows[ $name ] !== (string) $where['option_value'] ) {
				return 0;
			}
			unset( $this->rows[ $name ] );
			return 1;
		}
	}
}
$GLOBALS['wpdb'] = new FeFakeWpdb();

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
