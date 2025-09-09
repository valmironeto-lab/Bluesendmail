<?php
/**
 * Gerencia a renderização da página do Dashboard.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Dashboard_Page extends BSM_Admin_Page {

    public function render() {
        global $wpdb;

		// --- Coleta de Dados ---
		$total_contacts = $wpdb->get_var( "SELECT COUNT(contact_id) FROM {$wpdb->prefix}bluesendmail_contacts WHERE status = 'subscribed'" );
		$total_campaigns_sent = $wpdb->get_var( "SELECT COUNT(campaign_id) FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status = 'sent'" );

		$stats_query = "
			SELECT
				COUNT(DISTINCT q.queue_id) as total_sent,
				(SELECT COUNT(DISTINCT o.queue_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue iq ON o.queue_id = iq.queue_id JOIN {$wpdb->prefix}bluesendmail_campaigns ic ON iq.campaign_id = ic.campaign_id WHERE ic.status = 'sent') as total_opens,
				(SELECT COUNT(DISTINCT cl.queue_id) FROM {$wpdb->prefix}bluesendmail_email_clicks cl JOIN {$wpdb->prefix}bluesendmail_campaigns ic ON cl.campaign_id = ic.campaign_id WHERE ic.status = 'sent') as total_clicks
			FROM {$wpdb->prefix}bluesendmail_queue q
			JOIN {$wpdb->prefix}bluesendmail_campaigns c ON q.campaign_id = c.campaign_id
			WHERE c.status = 'sent'
		";
		$stats = $wpdb->get_row($stats_query);

		$avg_open_rate = ( $stats && $stats->total_sent > 0 ) ? ( $stats->total_opens / $stats->total_sent ) * 100 : 0;
		$avg_click_rate = ( $stats && $stats->total_opens > 0 ) ? ( $stats->total_clicks / $stats->total_opens ) * 100 : 0;

		$last_campaign = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1" );
		?>
		<div class="wrap bsm-wrap">
			<?php
            $this->render_header(
                __( 'Dashboard', 'bluesendmail' ),
                array(
                    'url'   => admin_url( 'admin.php?page=bluesendmail-new-campaign' ),
                    'label' => __( 'Criar Nova Campanha', 'bluesendmail' ),
                    'icon'  => 'dashicons-plus',
                )
            );
            ?>

			<!-- KPIs -->
			<div class="bsm-grid bsm-grid-cols-4" style="margin-bottom: 24px;">
				<div class="bsm-card bsm-kpi-card">
					<div class="kpi-label"><span class="dashicons dashicons-admin-users"></span> <?php _e( 'Total de Contatos', 'bluesendmail' ); ?></div>
					<div class="kpi-value"><?php echo number_format_i18n( $total_contacts ); ?></div>
				</div>
				<div class="bsm-card bsm-kpi-card">
					<div class="kpi-label"><span class="dashicons dashicons-email-alt"></span> <?php _e( 'Campanhas Enviadas', 'bluesendmail' ); ?></div>
					<div class="kpi-value"><?php echo number_format_i18n( $total_campaigns_sent ); ?></div>
				</div>
				<div class="bsm-card bsm-kpi-card">
					<div class="kpi-label"><span class="dashicons dashicons-visibility"></span> <?php _e( 'Taxa de Abertura Média', 'bluesendmail' ); ?></div>
					<div class="kpi-value"><?php echo number_format_i18n( $avg_open_rate, 1 ); ?>%</div>
				</div>
				<div class="bsm-card bsm-kpi-card">
					<div class="kpi-label"><span class="dashicons dashicons-external"></span> <?php _e( 'Taxa de Clique Média', 'bluesendmail' ); ?></div>
					<div class="kpi-value"><?php echo number_format_i18n( $avg_click_rate, 1 ); ?>%</div>
				</div>
			</div>

			<!-- Gráficos -->
			<div class="bsm-grid bsm-grid-cols-2">
				<div class="bsm-card">
					<h2 class="bsm-card-title"><span class="dashicons dashicons-chart-line"></span><?php _e( 'Crescimento de Contatos (Últimos 30 dias)', 'bluesendmail' ); ?></h2>
					<div class="bsm-chart-container">
						<canvas id="bsm-growth-chart"></canvas>
					</div>
				</div>
				<div class="bsm-card">
					<h2 class="bsm-card-title"><span class="dashicons dashicons-chart-pie"></span><?php _e( 'Performance Geral', 'bluesendmail' ); ?></h2>
					<div class="bsm-chart-container">
						<canvas id="bsm-performance-chart"></canvas>
					</div>
				</div>
			</div>

			<!-- Última Campanha -->
			<?php if ( $last_campaign ) : ?>
			<div class="bsm-card" style="margin-top: 24px;">
				<h2 class="bsm-card-title"><span class="dashicons dashicons-campaign"></span><?php _e( 'Última Campanha Enviada', 'bluesendmail' ); ?></h2>
				<?php
					$sent = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE campaign_id = %d", $last_campaign->campaign_id ) );
					$unique_opens = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id WHERE q.campaign_id = %d", $last_campaign->campaign_id ) );
					$unique_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks WHERE campaign_id = %d", $last_campaign->campaign_id ) );
					$open_rate    = ( $sent > 0 ) ? ( $unique_opens / $sent ) * 100 : 0;
					$click_rate   = ( $unique_opens > 0 ) ? ( $unique_clicks / $unique_opens ) * 100 : 0;
				?>
				<div style="display: flex; justify-content: space-between; align-items: center;">
					<div>
						<h3 style="font-size: 18px; margin: 0 0 5px;"><?php echo esc_html( $last_campaign->title ); ?></h3>
						<p style="margin: 0; color: #6B7280;"><?php printf( __( 'Enviada em %s', 'bluesendmail' ), get_date_from_gmt( $last_campaign->sent_at, 'd/m/Y H:i' ) ); ?></p>
						<p style="margin-top: 10px;">
							<?php printf( __( '<strong>%d</strong> enviados, <strong>%d</strong> aberturas (%s%%), <strong>%d</strong> cliques (%s%% CTOR)', 'bluesendmail' ), $sent, $unique_opens, number_format_i18n( $open_rate, 2 ), $unique_clicks, number_format_i18n( $click_rate, 2 ) ); ?>
						</p>
					</div>
					<div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-reports&campaign_id=' . $last_campaign->campaign_id ) ); ?>" class="bsm-btn bsm-btn-secondary">
							<span class="dashicons dashicons-chart-bar"></span>
							<?php _e( 'Ver Relatório Completo', 'bluesendmail' ); ?>
						</a>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
    }
}

