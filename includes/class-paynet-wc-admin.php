<?php
/**
 * Orders list: icon and filter for "paid amount differs" (ТЗ разд. 7.3, 10). Works with HPOS and posts storage.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin helpers.
 */
final class Paynet_WC_Admin {

	const FILTER = 'paynet_wc_mismatch';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		// HPOS.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'filter_dropdown' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_hpos' ) );
		// Posts storage.
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_posts' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown_posts' ) );
		add_filter( 'request', array( __CLASS__, 'filter_posts' ) );
	}

	/**
	 * Add the column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$columns['paynet_wc'] = __( 'Paynet', 'paynet-for-woocommerce' );
		return $columns;
	}

	/**
	 * HPOS column.
	 *
	 * @param string   $column Column.
	 * @param WC_Order $order  Order.
	 */
	public static function column_hpos( $column, $order ) {
		if ( 'paynet_wc' === $column && $order instanceof WC_Order ) {
			self::icon( $order );
		}
	}

	/**
	 * Posts column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function column_posts( $column, $post_id ) {
		if ( 'paynet_wc' === $column ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				self::icon( $order );
			}
		}
	}

	/**
	 * Icon: amount differs.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function icon( WC_Order $order ) {
		$flag = (string) $order->get_meta( Paynet_WC_Payment::META_MISMATCH );
		if ( '' === $flag ) {
			return;
		}
		$text = 'less' === $flag
			? __( 'Paid less than the order amount — check whether there was a discount', 'paynet-for-woocommerce' )
			: __( 'Amount mismatch — check manually', 'paynet-for-woocommerce' );
		echo '<span class="paynet-wc-mismatch dashicons dashicons-warning" style="color:#d63638" title="' . esc_attr( $text ) . '"></span><span class="screen-reader-text">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Selected filter.
	 *
	 * @return bool
	 */
	private static function filter_on() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		return isset( $_GET[ self::FILTER ] ) && '1' === sanitize_text_field( wp_unslash( $_GET[ self::FILTER ] ) );
	}

	/**
	 * Dropdown (HPOS).
	 */
	public static function filter_dropdown() {
		echo '<select name="' . esc_attr( self::FILTER ) . '"><option value="">' . esc_html__( 'Paynet: all', 'paynet-for-woocommerce' ) . '</option><option value="1"' . selected( self::filter_on(), true, false ) . '>' . esc_html__( 'Paid amount differs', 'paynet-for-woocommerce' ) . '</option></select>';
	}

	/**
	 * Dropdown (posts).
	 *
	 * @param string $post_type Post type.
	 */
	public static function filter_dropdown_posts( $post_type ) {
		if ( 'shop_order' === $post_type ) {
			self::filter_dropdown();
		}
	}

	/**
	 * Query (HPOS).
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function filter_hpos( $args ) {
		if ( self::filter_on() ) {
			$args['meta_query']   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'][] = array(
				'key'     => Paynet_WC_Payment::META_MISMATCH,
				'compare' => 'EXISTS',
			);
		}
		return $args;
	}

	/**
	 * Query (posts).
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function filter_posts( $vars ) {
		global $typenow;
		if ( 'shop_order' === $typenow && self::filter_on() ) {
			$vars['meta_query']   = isset( $vars['meta_query'] ) ? (array) $vars['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$vars['meta_query'][] = array(
				'key'     => Paynet_WC_Payment::META_MISMATCH,
				'compare' => 'EXISTS',
			);
		}
		return $vars;
	}
}
