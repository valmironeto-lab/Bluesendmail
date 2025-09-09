<?php
/**
 * Gerencia a renderização da página de Relatórios.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Reports_Page extends BSM_Admin_Page {

    public function render() {
        global $wpdb;
		$campaign_id = isset( $_REQUEST['campaign_id'] ) ? absint( $_REQUEST['campaign_id'] ) : 0;
		?>
		<div class="wrap bsm-wrap">
			<?php
            $header_title = $campaign_id
                ? __( 'Relatório da Campanha:', 'bluesendmail' ) . ' ' . esc_html( $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id = %d", $campaign_id ) ) )
                : __( 'Relatórios', 'bluesendmail' );

            $this->render_header(
                $header_title,
                array(
                    'url'   => admin_url( 'admin.php?page=bluesendmail-campaigns' ),
                    'label' => __( 'Voltar para Campanhas', 'bluesendmail' )
                )
            );

            if ( $campaign_id ) {
                $this->render_report_content( $campaign_id );
            } else {
                $this->render_selection_page();
            }
            ?>
		</div>
		<?php
    }

    private function render_report_content( $campaign_id ) {
		?>
		<div id="bsm-reports-summary" style="margin-top: 20px;"><div class="bsm-grid bsm-grid-cols-1"><div class="bsm-card"><div class="bsm-chart-container" style="max-width: 450px; margin: auto;"><canvas id="bsm-report-chart"></canvas></div></div></div></div>
		<div class="bsm-report-tabs" style="margin-top: 24px;">
			<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'opens' ) ) ); ?>" class="nav-tab <?php echo ( ! isset( $_GET['view'] ) || 'opens' === $_GET['view'] ) ? 'nav-tab-active' : ''; ?>"><?php _e( 'Aberturas', 'bluesendmail' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'clicks' ) ) ); ?>" class="nav-tab <?php echo ( isset( $_GET['view'] ) && 'clicks' === $_GET['view'] ) ? 'nav-tab-active' : ''; ?>"><?php _e( 'Cliques', 'bluesendmail' ); ?></a>
		</div>
		<?php
		$view = $_GET['view'] ?? 'opens';
		if ( 'clicks' === $view ) {
			$clicks_table = new BlueSendMail_Clicks_List_Table();
			$clicks_table->prepare_items();
			$clicks_table->display();
		} else {
			$opens_table = new BlueSendMail_Reports_List_Table();
			$opens_table->prepare_items();
			$opens_table->display();
		}
	}

	private function render_selection_page() {
		global $wpdb;
		$sent_campaigns = $wpdb->get_results( "SELECT campaign_id, title, sent_at FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status = 'sent' ORDER BY sent_at DESC" );
		if ( empty( $sent_campaigns ) ) {
			echo '<div class="bsm-card"><p>' . esc_html__( 'Nenhuma campanha foi enviada ainda. Assim que enviar uma, poderá ver os relatórios aqui.', 'bluesendmail' ) . '</p></div>';
			return;
		}
		?>
		<div class="bsm-card">
			<h2 class="bsm-card-title"><?php esc_html_e( 'Selecione uma Campanha', 'bluesendmail' ); ?></h2><p><?php esc_html_e( 'Escolha uma campanha abaixo para visualizar o seu relatório detalhado.', 'bluesendmail' ); ?></p>
			<table class="wp-list-table widefat striped">
				<thead><tr><th><?php esc_html_e( 'Título da Campanha', 'bluesendmail' ); ?></th><th><?php esc_html_e( 'Data de Envio', 'bluesendmail' ); ?></th></tr></thead>
				<tbody><?php foreach ( $sent_campaigns as $campaign ) : ?><tr><td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-reports&campaign_id=' . $campaign->campaign_id ) ); ?>"><?php echo esc_html( $campaign->title ); ?></a></strong></td><td><?php echo esc_html( get_date_from_gmt( $campaign->sent_at, 'd/m/Y H:i' ) ); ?></td></tr><?php endforeach; ?></tbody>
			</table>
		</div>
		<?php
	}
}

