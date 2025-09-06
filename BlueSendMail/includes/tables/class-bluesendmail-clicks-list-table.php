<?php
/**
 * Clicks List Table Class.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BlueSendMail_Clicks_List_Table extends WP_List_Table {
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Relatório de Clique', 'bluesendmail' ),
				'plural'   => __( 'Relatórios de Cliques', 'bluesendmail' ),
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'email'        => __( 'E-mail do Contato', 'bluesendmail' ),
			'original_url' => __( 'URL Clicada', 'bluesendmail' ),
			'clicked_at'   => __( 'Data do Clique', 'bluesendmail' ),
			'ip_address'   => __( 'Endereço IP', 'bluesendmail' ),
		);
	}

	public function prepare_items() {
		global $wpdb;
		$campaign_id = isset( $_REQUEST['campaign_id'] ) ? absint( $_REQUEST['campaign_id'] ) : 0;

		if ( ! $campaign_id ) {
			$this->items = array();
			return;
		}

		$table_clicks   = $wpdb->prefix . 'bluesendmail_email_clicks';
		$table_contacts = $wpdb->prefix . 'bluesendmail_contacts';

		$per_page     = 30;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$sql = $wpdb->prepare(
			"SELECT c.email, cl.original_url, cl.clicked_at, cl.ip_address
			 FROM {$table_clicks} cl
			 JOIN {$table_contacts} c ON cl.contact_id = c.contact_id
			 WHERE cl.campaign_id = %d
			 ORDER BY cl.clicked_at DESC
			 LIMIT %d OFFSET %d",
			$campaign_id,
			$per_page,
			$offset
		);
		$this->items = $wpdb->get_results( $sql, ARRAY_A );

		$total_items = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(click_id) FROM {$table_clicks} WHERE campaign_id = %d",
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

	protected function column_clicked_at( $item ) {
		return get_date_from_gmt( $item['clicked_at'], 'd/m/Y H:i:s' );
	}

	protected function column_email( $item ) {
		global $wpdb;
		$contact_id = $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM {$wpdb->prefix}bluesendmail_contacts WHERE email = %s", $item['email'] ) );
		if ( $contact_id ) {
			$edit_url = admin_url( 'admin.php?page=bluesendmail-contacts&action=edit&contact=' . $contact_id );
			return sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $item['email'] ) );
		}
		return esc_html( $item['email'] );
	}

	protected function column_original_url( $item ) {
		$url = esc_url( $item['original_url'] );
		$display_url = esc_html( wp_trim_words( $url, 10, '...' ) );
		return '<a href="' . $url . '" target="_blank" title="' . $url . '">' . $display_url . '</a>';
	}
}

