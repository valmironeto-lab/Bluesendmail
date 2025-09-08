<?php
/**
 * Lists List Table Class.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BlueSendMail_Lists_List_Table extends WP_List_Table {
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Lista', 'bluesendmail' ),
				'plural'   => __( 'Listas', 'bluesendmail' ),
				'ajax'     => false,
			)
		);
	}
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'name'        => __( 'Nome', 'bluesendmail' ),
			'description' => __( 'Descrição', 'bluesendmail' ),
			'subscribers' => __( 'Inscritos', 'bluesendmail' ),
			'created_at'  => __( 'Data de Criação', 'bluesendmail' ),
		);
	}
	public function get_bulk_actions() {
		return array( 'bulk-delete' => __( 'Excluir', 'bluesendmail' ) );
	}
	public function prepare_items() {
		global $wpdb;
		$table_lists         = $wpdb->prefix . 'bluesendmail_lists';
		$table_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';
		$per_page            = 20;
		$current_page        = $this->get_pagenum();
		$orderby             = ( ! empty( $_REQUEST['orderby'] ) ) ? sanitize_key( $_REQUEST['orderby'] ) : 'created_at';
		$order               = ( ! empty( $_REQUEST['order'] ) && in_array( strtoupper( $_REQUEST['order'] ), array( 'ASC', 'DESC' ) ) ) ? strtoupper( $_REQUEST['order'] ) : 'DESC';
		$offset              = ( $current_page - 1 ) * $per_page;
		$sql                 = "SELECT l.list_id, l.name, l.description, l.created_at, COUNT(cl.contact_id) as subscribers 
					FROM {$table_lists} AS l
					LEFT JOIN {$table_contact_lists} AS cl ON l.list_id = cl.list_id
					GROUP BY l.list_id, l.name, l.description, l.created_at
					ORDER BY {$orderby} {$order} 
					LIMIT %d OFFSET %d";
		$this->items         = $wpdb->get_results( $wpdb->prepare( $sql, $per_page, $offset ), ARRAY_A );
		$total_items         = $wpdb->get_var( "SELECT COUNT(list_id) FROM $table_lists" );
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}
	public function get_sortable_columns() {
		return array(
			'name'       => array( 'name', false ),
			'created_at' => array( 'created_at', true ),
		);
	}
	protected function column_default( $item, $column_name ) {
		if ( 'description' === $column_name ) {
			return $item[ $column_name ] ? esc_html( $item[ $column_name ] ) : '—';
		}
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="list[]" value="%s" />', $item['list_id'] );
	}
	protected function column_name( $item ) {
		$edit_url     = admin_url( 'admin.php?page=bluesendmail-lists&action=edit&list=' . $item['list_id'] );
		$delete_nonce = wp_create_nonce( 'bsm_delete_list_' . $item['list_id'] );
		$delete_url   = admin_url( 'admin.php?page=bluesendmail-lists&action=delete&list=' . $item['list_id'] . '&_wpnonce=' . $delete_nonce );
		$actions      = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Editar', 'bluesendmail' ) ),
			'delete' => sprintf( '<a href="%s" style="color:red;" onclick="return confirm(\'Tem certeza que deseja excluir esta lista? Esta ação não pode ser desfeita.\');">%s</a>', esc_url( $delete_url ), __( 'Excluir', 'bluesendmail' ) ),
		);
		return sprintf( '<strong><a href="%1$s">%2$s</a></strong> %3$s', esc_url( $edit_url ), esc_html( $item['name'] ), $this->row_actions( $actions ) );
	}
}
