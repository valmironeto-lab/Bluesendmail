<?php
/**
 * Logs List Table Class.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BlueSendMail_Logs_List_Table extends WP_List_Table {
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Log', 'bluesendmail' ),
				'plural'   => __( 'Logs', 'bluesendmail' ),
				'ajax'     => false,
			)
		);
	}
	public function get_columns() {
		return array(
			'type'       => __( 'Tipo', 'bluesendmail' ),
			'source'     => __( 'Origem', 'bluesendmail' ),
			'message'    => __( 'Mensagem', 'bluesendmail' ),
			'details'    => __( 'Detalhes', 'bluesendmail' ),
			'created_at' => __( 'Data', 'bluesendmail' ),
		);
	}
	public function prepare_items() {
		global $wpdb;
		$table_logs   = $wpdb->prefix . 'bluesendmail_logs';
		$per_page     = 30;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$sql         = "SELECT * FROM {$table_logs} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $per_page, $offset ), ARRAY_A );

		$total_items = $wpdb->get_var( "SELECT COUNT(log_id) FROM $table_logs" );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}
	protected function column_type( $item ) {
		$type  = $item['type'];
		$color = 'black';
		if ( 'error' === $type ) {
			$color = 'red';
		} elseif ( 'success' === $type || 'info' === $type ) {
			$color = 'green';
		}
		return '<strong style="color:' . $color . ';">' . esc_html( ucfirst( $type ) ) . '</strong>';
	}
}
