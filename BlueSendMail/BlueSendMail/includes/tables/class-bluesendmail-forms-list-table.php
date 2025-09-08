<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class BlueSendMail_Forms_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => __( 'Formulário', 'bluesendmail' ),
			'plural'   => __( 'Formulários', 'bluesendmail' ),
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'name'        => __( 'Nome do Formulário', 'bluesendmail' ),
			'list_name'   => __( 'Lista de Destino', 'bluesendmail' ),
			'shortcode'   => __( 'Shortcode', 'bluesendmail' ),
			'created_at'  => __( 'Data de Criação', 'bluesendmail' ),
		);
	}

	public function prepare_items() {
		global $wpdb;
		$table_forms = $wpdb->prefix . 'bluesendmail_forms';
		$table_lists = $wpdb->prefix . 'bluesendmail_lists';

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset = ( $current_page - 1 ) * $per_page;

		// CORREÇÃO: A consulta SQL agora seleciona f.list_id para garantir que o JOIN funcione corretamente.
		$sql = $wpdb->prepare(
			"SELECT f.form_id, f.name, f.created_at, f.list_id, l.name as list_name
			 FROM {$table_forms} AS f
			 LEFT JOIN {$table_lists} AS l ON f.list_id = l.list_id
			 ORDER BY f.created_at DESC
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		$this->items = $wpdb->get_results( $sql, ARRAY_A );

		$total_items = $wpdb->get_var( "SELECT COUNT(form_id) FROM $table_forms" );

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
		) );
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}
	
	protected function column_name( $item ) {
		$edit_url   = admin_url( 'admin.php?page=bluesendmail-forms&action=edit&form=' . $item['form_id'] );
		$delete_nonce = wp_create_nonce( 'bsm_delete_form_' . $item['form_id'] );
		$delete_url = admin_url( 'admin.php?page=bluesendmail-forms&action=delete&form=' . $item['form_id'] . '&_wpnonce=' . $delete_nonce );
		
		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Editar', 'bluesendmail' ) ),
			'delete' => sprintf( '<a href="%s" style="color:red;" onclick="return confirm(\'Tem a certeza que deseja excluir este formulário?\');">%s</a>', esc_url( $delete_url ), __( 'Excluir', 'bluesendmail' ) ),
		);

		// CORREÇÃO: A variável 'anctions' foi corrigida para '$actions'.
		return sprintf( '<strong><a href="%1$s">%2$s</a></strong> %3$s', esc_url( $edit_url ), esc_html( $item['name'] ), $this->row_actions( $actions ) );
	}
	
	protected function column_shortcode( $item ) {
		$shortcode = '[bluesendmail_form id="' . $item['form_id'] . '"]';
		return '<code>' . esc_html( $shortcode ) . '</code>';
	}

	public function no_items() {
		_e( 'Nenhum formulário encontrado. Que tal criar um?', 'bluesendmail' );
	}
}


