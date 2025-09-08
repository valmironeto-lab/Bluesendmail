<?php
/**
 * Opens Report List Table Class.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BlueSendMail_Reports_List_Table extends WP_List_Table {
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Relatório de Abertura', 'bluesendmail' ),
				'plural'   => __( 'Relatórios de Abertura', 'bluesendmail' ),
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'email'      => __( 'E-mail do Contato', 'bluesendmail' ),
			'opened_at'  => __( 'Data da Abertura', 'bluesendmail' ),
			'ip_address' => __( 'Endereço IP', 'bluesendmail' ),
			'user_agent' => __( 'Dispositivo/Navegador', 'bluesendmail' ),
		);
	}

	public function prepare_items() {
		global $wpdb;
		$campaign_id = isset( $_REQUEST['campaign_id'] ) ? absint( $_REQUEST['campaign_id'] ) : 0;

		if ( ! $campaign_id ) {
			$this->items = array();
			return;
		}

		$table_opens    = $wpdb->prefix . 'bluesendmail_email_opens';
		$table_queue    = $wpdb->prefix . 'bluesendmail_queue';
		$table_contacts = $wpdb->prefix . 'bluesendmail_contacts';

		$per_page     = 30;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// OTIMIZAÇÃO: Adicionado c.contact_id para evitar uma consulta por linha.
		$sql         = $wpdb->prepare(
			"SELECT c.email, c.contact_id, o.opened_at, o.ip_address, o.user_agent
             FROM {$table_opens} o
             JOIN {$table_queue} q ON o.queue_id = q.queue_id
             JOIN {$table_contacts} c ON q.contact_id = c.contact_id
             WHERE q.campaign_id = %d
             ORDER BY o.opened_at DESC
             LIMIT %d OFFSET %d",
			$campaign_id,
			$per_page,
			$offset
		);
		$this->items = $wpdb->get_results( $sql, ARRAY_A );

		$total_items = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(o.open_id) FROM {$table_opens} o JOIN {$table_queue} q ON o.queue_id = q.queue_id WHERE q.campaign_id = %d",
				$campaign_id
			)
		);

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

	protected function column_email( $item ) {
		// OTIMIZAÇÃO: A consulta extra foi removida. O contact_id agora vem da consulta principal.
		$contact_id = $item['contact_id'];
		if ( $contact_id ) {
			$edit_url = admin_url( 'admin.php?page=bluesendmail-contacts&action=edit&contact=' . $contact_id );
			return sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $item['email'] ) );
		}
		return esc_html( $item['email'] );
	}

	protected function column_opened_at( $item ) {
		return get_date_from_gmt( $item['opened_at'], 'd/m/Y H:i:s' );
	}
}
