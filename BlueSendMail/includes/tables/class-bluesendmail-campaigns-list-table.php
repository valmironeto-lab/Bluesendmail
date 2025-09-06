<?php
/**
 * Campaigns List Table Class.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BlueSendMail_Campaigns_List_Table extends WP_List_Table {
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Campanha', 'bluesendmail' ),
				'plural'   => __( 'Campanhas', 'bluesendmail' ),
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'title'         => __( 'Título', 'bluesendmail' ),
			'status'        => __( 'Status', 'bluesendmail' ),
			'stats'         => __( 'Estatísticas', 'bluesendmail' ),
			'scheduled_for' => __( 'Agendado/Enviado', 'bluesendmail' ),
			'created_at'    => __( 'Data de Criação', 'bluesendmail' ),
		);
	}

	public function prepare_items() {
		global $wpdb;
		$table_campaigns = $wpdb->prefix . 'bluesendmail_campaigns';
		$table_queue     = $wpdb->prefix . 'bluesendmail_queue';
		$table_opens     = $wpdb->prefix . 'bluesendmail_email_opens';
		$table_clicks    = $wpdb->prefix . 'bluesendmail_email_clicks';

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$sql       = "SELECT * FROM {$table_campaigns} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$campaigns = $wpdb->get_results( $wpdb->prepare( $sql, $per_page, $offset ), ARRAY_A );

		$campaign_ids = wp_list_pluck( $campaigns, 'campaign_id' );
		if ( ! empty( $campaign_ids ) ) {
			$ids_placeholder = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );

			$sent_sql    = "SELECT campaign_id, COUNT(queue_id) as total FROM {$table_queue} WHERE campaign_id IN ({$ids_placeholder}) GROUP BY campaign_id";
			$sent_counts = $wpdb->get_results( $wpdb->prepare( $sent_sql, $campaign_ids ), OBJECT_K );

			// Busca o total de aberturas (soma de todas as vezes que o email foi aberto)
			$opens_sql   = "SELECT q.campaign_id, COUNT(o.open_id) as total FROM {$table_opens} o JOIN {$table_queue} q ON o.queue_id = q.queue_id WHERE q.campaign_id IN ({$ids_placeholder}) GROUP BY q.campaign_id";
			$open_counts = $wpdb->get_results( $wpdb->prepare( $opens_sql, $campaign_ids ), OBJECT_K );

			// Busca as aberturas únicas (contagem de contatos distintos que abriram)
			$unique_opens_sql   = "SELECT q.campaign_id, COUNT(DISTINCT q.contact_id) as total FROM {$table_opens} o JOIN {$table_queue} q ON o.queue_id = q.queue_id WHERE q.campaign_id IN ({$ids_placeholder}) GROUP BY q.campaign_id";
			$unique_open_counts = $wpdb->get_results( $wpdb->prepare( $unique_opens_sql, $campaign_ids ), OBJECT_K );

			// Busca o total de cliques
			$clicks_sql   = "SELECT campaign_id, COUNT(click_id) as total FROM {$table_clicks} WHERE campaign_id IN ({$ids_placeholder}) GROUP BY campaign_id";
			$click_counts = $wpdb->get_results( $wpdb->prepare( $clicks_sql, $campaign_ids ), OBJECT_K );

			// Busca os cliques únicos
			$unique_clicks_sql   = "SELECT campaign_id, COUNT(DISTINCT contact_id) as total FROM {$table_clicks} WHERE campaign_id IN ({$ids_placeholder}) GROUP BY campaign_id";
			$unique_click_counts = $wpdb->get_results( $wpdb->prepare( $unique_clicks_sql, $campaign_ids ), OBJECT_K );

			foreach ( $campaigns as $key => $campaign ) {
				$campaigns[ $key ]['sent_count']        = $sent_counts[ $campaign['campaign_id'] ]->total ?? 0;
				$campaigns[ $key ]['open_count']        = $open_counts[ $campaign['campaign_id'] ]->total ?? 0;
				$campaigns[ $key ]['unique_open_count'] = $unique_open_counts[ $campaign['campaign_id'] ]->total ?? 0;
				$campaigns[ $key ]['click_count']       = $click_counts[ $campaign['campaign_id'] ]->total ?? 0;
				$campaigns[ $key ]['unique_click_count'] = $unique_click_counts[ $campaign['campaign_id'] ]->total ?? 0;
			}
		}

		$this->items = $campaigns;

		$total_items = $wpdb->get_var( "SELECT COUNT(campaign_id) FROM $table_campaigns" );

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
		if ( 'scheduled_for' === $column_name ) {
			if ( ! empty( $item['sent_at'] ) ) {
				return get_date_from_gmt( $item['sent_at'], 'd/m/Y H:i' );
			}
			if ( ! empty( $item[ $column_name ] ) ) {
				return get_date_from_gmt( $item[ $column_name ], 'd/m/Y H:i' );
			}
			return '—';
		}
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	protected function column_title( $item ) {
		$edit_url   = admin_url( 'admin.php?page=bluesendmail-campaigns&action=edit&campaign=' . $item['campaign_id'] );
		$report_url = admin_url( 'admin.php?page=bluesendmail-reports&campaign_id=' . $item['campaign_id'] );
		$actions    = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Editar', 'bluesendmail' ) ),
			'report' => sprintf( '<a href="%s">%s</a>', esc_url( $report_url ), __( 'Ver Relatório', 'bluesendmail' ) ),
		);
		return sprintf( '<strong><a href="%1$s">%2$s</a></strong> %3$s', esc_url( $edit_url ), esc_html( $item['title'] ), $this->row_actions( $actions ) );
	}

	protected function column_status( $item ) {
		$status = $item['status'];
		switch ( $status ) {
			case 'sent':
				return '<strong style="color:green;">' . __( 'Enviada', 'bluesendmail' ) . '</strong>';
			case 'scheduled':
				return '<strong style="color:blue;">' . __( 'Agendada', 'bluesendmail' ) . '</strong>';
			case 'queued':
				return '<strong style="color:orange;">' . __( 'Na Fila', 'bluesendmail' ) . '</strong>';
			case 'draft':
			default:
				return '<em>' . __( 'Rascunho', 'bluesendmail' ) . '</em>';
		}
	}

	protected function column_stats( $item ) {
		$sent         = $item['sent_count'] ?? 0;
		$total_opens  = $item['open_count'] ?? 0;
		$unique_opens = $item['unique_open_count'] ?? 0;
		$unique_clicks = $item['unique_click_count'] ?? 0;

		$open_rate  = ( $sent > 0 ) ? round( ( $unique_opens / $sent ) * 100, 2 ) : 0;
		$click_rate = ( $unique_opens > 0 ) ? round( ( $unique_clicks / $unique_opens ) * 100, 2 ) : 0;

		if ( 'draft' === $item['status'] ) {
			return '—';
		}

		return sprintf(
			'Enviados: %d <br> Aberturas Únicas: %d (%s%%) <br> Aberturas Totais: %d <br> Cliques Únicos: %d (%s%% CTOR)',
			$sent,
			$unique_opens,
			number_format_i18n( $open_rate, 2 ),
			$total_opens,
			$unique_clicks,
			number_format_i18n( $click_rate, 2 )
		);
	}
}

