<?php
/**
 * Templates List Table Class.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BlueSendMail_Templates_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Template', 'bluesendmail' ),
				'plural'   => __( 'Templates', 'bluesendmail' ),
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'name'       => __( 'Nome do Template', 'bluesendmail' ),
			'created_at' => __( 'Data de Criação', 'bluesendmail' ),
		);
	}

	public function get_bulk_actions() {
		return array( 'bulk-delete' => __( 'Excluir', 'bluesendmail' ) );
	}

	public function prepare_items() {
		global $wpdb;
		$table_templates = $wpdb->prefix . 'bluesendmail_templates';

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table_templates} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		$this->items = $wpdb->get_results( $sql, ARRAY_A );

		$total_items = $wpdb->get_var( "SELECT COUNT(template_id) FROM $table_templates" );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	protected function column_default( $item, $column_name ) {
		if ( 'created_at' === $column_name ) {
			return $item[ $column_name ] ? date_i18n( 'd/m/Y H:i', strtotime( $item[ $column_name ] ) ) : '—';
		}
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

    protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="template[]" value="%s" />', $item['template_id'] );
	}

	protected function column_name( $item ) {
		$edit_url = admin_url( 'admin.php?page=bluesendmail-templates&action=edit&template=' . $item['template_id'] );
        $delete_nonce = wp_create_nonce( 'bsm_delete_template_' . $item['template_id'] );
		$delete_url   = admin_url( 'admin.php?page=bluesendmail-templates&action=delete&template=' . $item['template_id'] . '&_wpnonce=' . $delete_nonce );

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Editar', 'bluesendmail' ) ),
			'delete' => sprintf( '<a href="%s" style="color:red;" onclick="return confirm(\'Tem certeza que deseja excluir este template? Esta ação não pode ser desfeita.\');">%s</a>', esc_url( $delete_url ), __( 'Excluir', 'bluesendmail' ) ),
		);
		return sprintf( '<strong><a href="%1$s">%2$s</a></strong> %3$s', esc_url( $edit_url ), esc_html( $item['name'] ), $this->row_actions( $actions ) );
	}
}
