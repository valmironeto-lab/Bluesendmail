<?php
/**
 * Gerencia todas as funcionalidades do painel de administração.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Admin {

	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->register_hooks();
	}

	private function register_hooks() {
		add_action( 'admin_menu', array( $this, 'setup_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function setup_admin_menu() {
		$this->plugin->load_list_tables();
		add_menu_page( __( 'BlueSendMail', 'bluesendmail' ), __( 'BlueSendMail', 'bluesendmail' ), 'bsm_view_reports', 'bluesendmail', array( $this, 'render_dashboard_page' ), 'dashicons-email-alt2', 25 );
		add_submenu_page( 'bluesendmail', __( 'Dashboard', 'bluesendmail' ), __( 'Dashboard', 'bluesendmail' ), 'bsm_view_reports', 'bluesendmail', array( $this, 'render_dashboard_page' ) );
		add_submenu_page( 'bluesendmail', __( 'Campanhas', 'bluesendmail' ), __( 'Campanhas', 'bluesendmail' ), 'bsm_manage_campaigns', 'bluesendmail-campaigns', array( $this, 'render_campaigns_page' ) );
		add_submenu_page( 'bluesendmail', __( 'Criar Nova Campanha', 'bluesendmail' ), __( 'Criar Nova', 'bluesendmail' ), 'bsm_manage_campaigns', 'bluesendmail-new-campaign', array( $this, 'render_add_edit_campaign_page' ) );
		add_submenu_page( 'bluesendmail', __( 'Contatos', 'bluesendmail' ), __( 'Contatos', 'bluesendmail' ), 'bsm_manage_contacts', 'bluesendmail-contacts', array( $this, 'render_contacts_page' ) );
		add_submenu_page( 'bluesendmail', __( 'Listas', 'bluesendmail' ), __( 'Listas', 'bluesendmail' ), 'bsm_manage_lists', 'bluesendmail-lists', array( $this, 'render_lists_page' ) );
		add_submenu_page( 'bluesendmail', __( 'Importar', 'bluesendmail' ), __( 'Importar', 'bluesendmail' ), 'bsm_manage_contacts', 'bluesendmail-import', array( $this, 'render_import_page' ) );
		add_submenu_page( 'bluesendmail', __( 'Relatórios', 'bluesendmail' ), __( 'Relatórios', 'bluesendmail' ), 'bsm_view_reports', 'bluesendmail-reports', array( $this, 'render_reports_page' ) );
		add_submenu_page( 'bluesendmail', __( 'Logs do Sistema', 'bluesendmail' ), __( 'Logs do Sistema', 'bluesendmail' ), 'bsm_manage_settings', 'bluesendmail-logs', array( $this, 'render_logs_page' ) );
		add_submenu_page( 'bluesendmail', __( 'Configurações', 'bluesendmail' ), __( 'Configurações', 'bluesendmail' ), 'bsm_manage_settings', 'bluesendmail-settings', array( $this, 'render_settings_page' ) );
	}

	public function handle_actions() {
		add_action( 'admin_post_bsm_import_contacts', array( $this, 'handle_import_contacts' ) );
		if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'bluesendmail' ) !== false ) {
			if ( false === get_transient( 'bsm_scheduled_check_lock' ) ) {
				set_transient( 'bsm_scheduled_check_lock', true, 5 * MINUTE_IN_SECONDS );
				$this->plugin->cron->enqueue_scheduled_campaigns();
			}
		}
		$page = $_GET['page'] ?? '';
		if ( 'bluesendmail-settings' === $page && isset( $_POST['bsm_send_test_email'] ) ) $this->handle_send_test_email();
		if ( ( 'bluesendmail-campaigns' === $page || 'bluesendmail-new-campaign' === $page ) && ( isset( $_POST['bsm_save_draft'] ) || isset( $_POST['bsm_send_campaign'] ) || isset( $_POST['bsm_schedule_campaign'] ) ) ) $this->handle_save_campaign();
		if ( 'bluesendmail-contacts' === $page ) {
			if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['contact'] ) ) $this->handle_delete_contact();
			if ( ( isset( $_POST['action'] ) && 'bulk-delete' === $_POST['action'] ) || ( isset( $_POST['action2'] ) && 'bulk-delete' === $_POST['action2'] ) ) $this->handle_bulk_delete_contacts();
			if ( isset( $_POST['bsm_save_contact'] ) ) $this->handle_save_contact();
		}
		if ( 'bluesendmail-lists' === $page ) {
			if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['list'] ) ) $this->handle_delete_list();
			if ( ( isset( $_POST['action'] ) && 'bulk-delete' === $_POST['action'] ) || ( isset( $_POST['action2'] ) && 'bulk-delete' === $_POST['action2'] ) ) $this->handle_bulk_delete_lists();
			if ( isset( $_POST['bsm_save_list'] ) ) $this->handle_save_list();
		}
	}

	private function get_top_openers( $limit = 5 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT c.email, COUNT(o.open_id) AS open_count
			 FROM {$wpdb->prefix}bluesendmail_email_opens AS o
			 JOIN {$wpdb->prefix}bluesendmail_queue AS q ON o.queue_id = q.queue_id
			 JOIN {$wpdb->prefix}bluesendmail_contacts AS c ON q.contact_id = c.contact_id
			 GROUP BY c.email
			 ORDER BY open_count DESC
			 LIMIT %d",
			$limit
		) );
	}

	private function get_top_clickers( $limit = 5 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT c.email, COUNT(cl.click_id) AS click_count
			 FROM {$wpdb->prefix}bluesendmail_email_clicks AS cl
			 JOIN {$wpdb->prefix}bluesendmail_contacts AS c ON cl.contact_id = c.contact_id
			 GROUP BY c.email
			 ORDER BY click_count DESC
			 LIMIT %d",
			$limit
		) );
	}

	private function get_contacts_growth_data() {
		global $wpdb;
		$results = $wpdb->get_results(
			"SELECT DATE(created_at) AS date, COUNT(contact_id) AS count
			 FROM {$wpdb->prefix}bluesendmail_contacts
			 WHERE created_at >= CURDATE() - INTERVAL 30 DAY
			 GROUP BY DATE(created_at)
			 ORDER BY date ASC"
		);

		$dates = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$date = date( 'Y-m-d', strtotime( "-$i days" ) );
			$dates[ $date ] = 0;
		}

		foreach ( $results as $result ) {
			$dates[ $result->date ] = (int) $result->count;
		}

		return array(
			'labels' => array_keys( $dates ),
			'data'   => array_values( $dates ),
		);
	}

	public function render_dashboard_page() {
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
			<div class="bsm-header">
				<h1><?php _e( 'Dashboard', 'bluesendmail' ); ?></h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-new-campaign' ) ); ?>" class="page-title-action">
					<span class="dashicons dashicons-plus"></span>
					<?php _e( 'Criar Nova Campanha', 'bluesendmail' ); ?>
				</a>
			</div>

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

	public function render_campaigns_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		echo '<div class="wrap bsm-wrap">';
		if ( 'edit' === $action && ! empty( $_GET['campaign'] ) || 'new-campaign' === $action ) {
			$this->render_add_edit_campaign_page();
		} else {
			$this->render_campaigns_list_page();
		}
		echo '</div>';
	}

	public function render_campaigns_list_page() {
		$campaigns_table = new BlueSendMail_Campaigns_List_Table();
		?>
		<div class="bsm-header">
			<h1><?php echo esc_html__( 'Campanhas', 'bluesendmail' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-new-campaign' ) ); ?>" class="page-title-action">
				<span class="dashicons dashicons-plus"></span>
				<?php echo esc_html__( 'Criar Nova', 'bluesendmail' ); ?>
			</a>
		</div>
		<form method="post">
			<?php
			$campaigns_table->prepare_items();
			$campaigns_table->display();
			?>
		</form>
		<?php
	}

	public function render_add_edit_campaign_page() {
		global $wpdb;
		$campaign_id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
		$campaign = null;
		$selected_lists = array();
		if ( $campaign_id ) {
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id = %d", $campaign_id ) );
			if ( $campaign ) $selected_lists = ! empty( $campaign->lists ) ? unserialize( $campaign->lists ) : array();
		}
		?>
		<div class="bsm-header">
			<h1><?php echo $campaign ? esc_html__( 'Editar Campanha', 'bluesendmail' ) : esc_html__( 'Criar Nova Campanha', 'bluesendmail' ); ?></h1>
		</div>
		<form method="post">
			<?php wp_nonce_field( 'bsm_save_campaign_action', 'bsm_save_campaign_nonce' ); ?>
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>">
			
			<div class="bsm-card">
				<table class="form-table">
					<tbody>
						<tr><th scope="row"><label for="bsm-title"><?php _e( 'Título da Campanha', 'bluesendmail' ); ?></label></th><td><input type="text" name="bsm_title" id="bsm-title" class="large-text" value="<?php echo esc_attr( $campaign->title ?? '' ); ?>" required><p class="description"><?php _e( 'Para sua referência interna.', 'bluesendmail' ); ?></p></td></tr>
						<tr><th scope="row"><label for="bsm-subject"><?php _e( 'Assunto do E-mail', 'bluesendmail' ); ?></label></th><td><input type="text" name="bsm_subject" id="bsm-subject" class="large-text" value="<?php echo esc_attr( $campaign->subject ?? '' ); ?>"><p class="description"><?php _e( 'Deixe em branco para usar o título da campanha.', 'bluesendmail' ); ?></p></td></tr>
						<tr><th scope="row"><label for="bsm-preheader"><?php _e( 'Pré-cabeçalho (Preheader)', 'bluesendmail' ); ?></label></th><td><input type="text" name="bsm_preheader" id="bsm-preheader" class="large-text" value="<?php echo esc_attr( $campaign->preheader ?? '' ); ?>"></td></tr>
						<tr>
							<th scope="row"><label for="bsm-content"><?php _e( 'Conteúdo do E-mail', 'bluesendmail' ); ?></label></th>
							<td>
								<div class="bsm-merge-tags-container">
									<h3><?php _e( 'Personalize seu e-mail', 'bluesendmail' ); ?></h3><p><?php _e( 'Clique nas tags abaixo para inseri-las no seu conteúdo ou assunto.', 'bluesendmail' ); ?></p>
									<p class="bsm-tags-group-title"><?php _e( 'Dados do Contato:', 'bluesendmail' ); ?></p><div><span class="bsm-merge-tag" data-tag="{{contact.first_name}}"><?php _e( 'Primeiro Nome', 'bluesendmail' ); ?></span><span class="bsm-merge-tag" data-tag="{{contact.last_name}}"><?php _e( 'Último Nome', 'bluesendmail' ); ?></span><span class="bsm-merge-tag" data-tag="{{contact.email}}"><?php _e( 'E-mail do Contato', 'bluesendmail' ); ?></span></div>
									<p class="bsm-tags-group-title"><?php _e( 'Dados do Site e Links:', 'bluesendmail' ); ?></p><div><span class="bsm-merge-tag" data-tag="{{site.name}}"><?php _e( 'Nome do Site', 'bluesendmail' ); ?></span><span class="bsm-merge-tag" data-tag="{{site.url}}"><?php _e( 'URL do Site', 'bluesendmail' ); ?></span><span class="bsm-merge-tag" data-tag="{{unsubscribe_link}}"><?php _e( 'Link de Desinscrição', 'bluesendmail' ); ?></span></div>
								</div>
								<?php wp_editor( $campaign->content ?? '', 'bsm-content', array( 'textarea_name' => 'bsm_content', 'media_buttons' => true ) ); ?>
								<?php if ( ! empty( $this->plugin->options['enable_open_tracking'] ) || ! empty( $this->plugin->options['enable_click_tracking'] ) ) : ?>
									<p class="description"><?php _e( 'Rastreamento ativado:', 'bluesendmail' ); ?> <?php if ( ! empty( $this->plugin->options['enable_open_tracking'] ) ) echo __( 'Aberturas', 'bluesendmail' ); ?><?php if ( ! empty( $this->plugin->options['enable_open_tracking'] ) && ! empty( $this->plugin->options['enable_click_tracking'] ) ) echo ' & '; ?><?php if ( ! empty( $this->plugin->options['enable_click_tracking'] ) ) echo __( 'Cliques', 'bluesendmail' ); ?>.</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Agendamento', 'bluesendmail' ); ?></th>
							<td>
								<fieldset><label for="bsm-schedule-enabled"><input type="checkbox" name="bsm_schedule_enabled" id="bsm-schedule-enabled" value="1" <?php checked( ! empty( $campaign->scheduled_for ) ); ?>> <?php _e( 'Agendar o envio para uma data futura', 'bluesendmail' ); ?></label></fieldset>
								<div id="bsm-schedule-fields" style="<?php echo empty( $campaign->scheduled_for ) ? 'display: none;' : ''; ?>">
									<p class="bsm-schedule-inputs"><input type="date" name="bsm_schedule_date" value="<?php echo ! empty( $campaign->scheduled_for ) ? get_date_from_gmt( $campaign->scheduled_for, 'Y-m-d' ) : ''; ?>"><input type="time" name="bsm_schedule_time" value="<?php echo ! empty( $campaign->scheduled_for ) ? get_date_from_gmt( $campaign->scheduled_for, 'H:i' ) : ''; ?>"></p>
									<?php $timezone_display = wp_timezone_string() ?: 'UTC' . ( ( $offset = get_option( 'gmt_offset' ) ) >= 0 ? '+' : '' ) . $offset; ?>
									<p class="description"><?php printf( __( 'O envio será realizado no primeiro processamento da fila após esta data/hora. Fuso horário do site: %s.', 'bluesendmail' ), '<code>' . $timezone_display . '</code>' ); ?></p>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bsm-lists-select"><?php _e( 'Destinatários', 'bluesendmail' ); ?></label></th>
							<td>
								<?php
								$all_lists = $wpdb->get_results( "SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC" );
								if ( ! empty( $all_lists ) ) : ?>
								<select name="bsm_lists[]" id="bsm-lists-select" multiple="multiple" style="width: 100%;"><?php foreach ( $all_lists as $list ) : ?><option value="<?php echo esc_attr( $list->list_id ); ?>" <?php selected( in_array( $list->list_id, $selected_lists ) ); ?>><?php echo esc_html( $list->name ); ?></option><?php endforeach; ?></select>
								<p class="description"><?php _e( 'Selecione uma ou mais listas. Se nenhuma lista for selecionada, a campanha será enviada para todos os contatos inscritos.', 'bluesendmail' ); ?></p>
								<?php else : ?><p><?php _e( 'Nenhuma lista de contatos encontrada. Por favor, crie uma primeiro.', 'bluesendmail' ); ?></p><?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="submit" style="padding-top: 20px; background-color: transparent;">
				<?php submit_button( $campaign ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Salvar Rascunho', 'bluesendmail' ), 'secondary bsm-btn bsm-btn-secondary', 'bsm_save_draft', false ); ?>
				<span style="padding-left: 10px;"></span>
				<?php if ( ! $campaign || in_array( $campaign->status, array( 'draft', 'scheduled' ), true ) ) : ?>
					<?php submit_button( __( 'Enviar Agora', 'bluesendmail' ), 'primary bsm-btn bsm-btn-primary', 'bsm_send_campaign', false, array( 'id' => 'bsm-send-now-button', 'onclick' => "return confirm('" . __( 'Tem a certeza que deseja enfileirar esta campanha para envio imediato?', 'bluesendmail' ) . "');" ) ); ?>
					<?php submit_button( __( 'Agendar Envio', 'bluesendmail' ), 'primary bsm-btn bsm-btn-primary', 'bsm_schedule_campaign', false, array( 'id' => 'bsm-schedule-button', 'style' => 'display:none;', 'onclick' => "return confirm('" . __( 'Tem a certeza que deseja agendar esta campanha para o horário selecionado?', 'bluesendmail' ) . "');" ) ); ?>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	public function render_settings_page() {
		?>
		<div class="wrap bsm-wrap">
			<div class="bsm-header">
				<h1><?php _e( 'Configurações', 'bluesendmail' ); ?></h1>
			</div>

			<div class="bsm-grid bsm-grid-cols-3">
				<div class="bsm-col-span-2">
					<div class="bsm-card">
						<form method="post" action="options.php">
							<?php 
								settings_fields( 'bluesendmail_settings_group' );
								do_settings_sections( 'bluesendmail-settings' ); 
								submit_button();
							?>
						</form>
					</div>
					<div class="bsm-card" style="margin-top: 24px;">
						<h2 class="bsm-card-title"><span class="dashicons dashicons-email"></span><?php _e( 'Testar Envio', 'bluesendmail' ); ?></h2>
						<p><?php _e( 'Use esta ferramenta para verificar se as suas configurações de envio estão funcionando corretamente.', 'bluesendmail' ); ?></p>
						<form method="post">
							<table class="form-table">
								<tr valign="top">
									<th scope="row"><label for="bsm_test_email_recipient"><?php _e( 'Enviar para', 'bluesendmail' ); ?></label></th>
									<td><input type="email" id="bsm_test_email_recipient" name="bsm_test_email_recipient" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" required>
										<p class="description"><?php _e( 'O e-mail de teste será enviado para este endereço.', 'bluesendmail' ); ?></p>
									</td>
								</tr>
							</table>
							<?php wp_nonce_field( 'bsm_send_test_email_action', 'bsm_send_test_email_nonce' ); ?>
							<?php submit_button( __( 'Enviar Teste', 'bluesendmail' ), 'secondary', 'bsm_send_test_email' ); ?>
						</form>
					</div>
				</div>
				<div class="bsm-col-span-1">
					<div class="bsm-card">
						<h2 class="bsm-card-title"><span class="dashicons dashicons-dashboard"></span><?php _e( 'Status do Sistema', 'bluesendmail' ); ?></h2>
						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><?php _e( 'Fila de Envio', 'bluesendmail' ); ?></th>
									<td><?php global $wpdb; echo '<strong>' . esc_html( $wpdb->get_var( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE status = 'pending'" ) ) . '</strong> ' . __( 'e-mails pendentes', 'bluesendmail' ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php _e( 'Próxima Execução', 'bluesendmail' ); ?></th>
									<td><?php $timestamp = wp_next_scheduled( 'bsm_process_sending_queue' ); echo $timestamp ? '<strong>' . get_date_from_gmt( date( 'Y-m-d H:i:s', $timestamp ), 'd/m/Y H:i:s' ) . '</strong>' : '<strong style="color:red;">' . esc_html__( 'Não agendado!', 'bluesendmail' ) . '</strong>'; ?></td>
								</tr>
								<tr>
									<th scope="row"><?php _e( 'Última Execução', 'bluesendmail' ); ?></th>
									<td>
										<?php
										$last_run = get_option( 'bsm_last_cron_run' );
										if ( $last_run ) echo '<strong>' . sprintf( esc_html__( '%s atrás' ), human_time_diff( $last_run ) ) . '</strong>';
										else echo '<strong>' . esc_html__( 'Nunca', 'bluesendmail' ) . '</strong>';
										if ( $last_run && ( time() - $last_run > 30 * MINUTE_IN_SECONDS ) ) echo '<p style="color: #a00; font-size: 12px; margin-top: 5px;">' . esc_html__( 'Atenção: A última execução foi há muito tempo. Verifique a configuração do WP-Cron.', 'bluesendmail' ) . '</p>';
										?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<div class="bsm-card" style="margin-top: 24px;">
						<h2 class="bsm-card-title"><span class="dashicons dashicons-admin-generic"></span><?php _e( 'Confiabilidade', 'bluesendmail' ); ?></h2>
						<p><?php _e( 'Para garantir envios pontuais, recomendamos configurar um "cron job" no seu servidor. Use o comando abaixo:', 'bluesendmail' ); ?></p>
						<pre style="background:#eee; padding:10px; border-radius:4px; font-size: 12px; word-wrap: break-word;"><code>wget -q -O - <?php echo esc_url( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> >/dev/null 2>&1</code></pre>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_reports_page() {
		global $wpdb;
		$campaign_id = isset( $_REQUEST['campaign_id'] ) ? absint( $_REQUEST['campaign_id'] ) : 0;
		?>
		<div class="wrap bsm-wrap">
			<div class="bsm-header">
				<h1>
				<?php
				if ( $campaign_id ) {
					echo esc_html__( 'Relatório da Campanha:', 'bluesendmail' ) . ' ' . esc_html( $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id = %d", $campaign_id ) ) );
				} else {
					echo esc_html__( 'Relatórios', 'bluesendmail' );
				}
				?>
				</h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-campaigns' ) ); ?>" class="page-title-action"><?php echo esc_html__( 'Voltar para Campanhas', 'bluesendmail' ); ?></a>
			</div>
			<?php if ( $campaign_id ) $this->render_report_content( $campaign_id ); else $this->render_reports_selection_page(); ?>
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

	private function render_reports_selection_page() {
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

	public function render_logs_page() {
		?>
		<div class="wrap bsm-wrap">
			<div class="bsm-header">
				<h1><?php echo esc_html__( 'Logs do Sistema', 'bluesendmail' ); ?></h1>
			</div>
			<form method="post"><?php $logs_table = new BlueSendMail_Logs_List_Table(); $logs_table->prepare_items(); $logs_table->display(); ?></form>
		</div>
		<?php
	}

	public function register_settings() {
		register_setting( 'bluesendmail_settings_group', 'bluesendmail_settings' );
		add_settings_section( 'bsm_general_section', __( 'Configurações Gerais de Remetente', 'bluesendmail' ), null, 'bluesendmail-settings' );
		add_settings_field( 'bsm_from_name', __( 'Nome do Remetente', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings', 'bsm_general_section', array( 'id' => 'from_name', 'description' => __( 'O nome que aparecerá no campo "De:" do e-mail.', 'bluesendmail' ) ) );
		add_settings_field( 'bsm_from_email', __( 'E-mail do Remetente', 'bluesendmail' ), array( $this, 'render_email_field' ), 'bluesendmail-settings', 'bsm_general_section', array( 'id' => 'from_email', 'description' => __( 'O e-mail que aparecerá no campo "De:" do e-mail.', 'bluesendmail' ) ) );
		add_settings_section( 'bsm_mailer_section', __( 'Configurações do Disparador', 'bluesendmail' ), fn() => print( '<p>' . __( 'Configure o serviço que será usado para enviar os e-mails e a velocidade do envio.', 'bluesendmail' ) . '</p>' ), 'bluesendmail-settings' );
		add_settings_field( 'bsm_mailer_type', __( 'Método de Envio', 'bluesendmail' ), array( $this, 'render_select_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'mailer_type', 'description' => __( 'Escolha como os e-mails serão enviados.', 'bluesendmail' ), 'options' => array( 'wp_mail' => __( 'E-mail Padrão do WordPress (Não recomendado para produção)', 'bluesendmail' ), 'smtp' => __( 'SMTP', 'bluesendmail' ), 'sendgrid' => __( 'SendGrid', 'bluesendmail' ) ) ) );
		add_settings_field( 'bsm_smtp_host', __( 'Host SMTP', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'smtp_host', 'class' => 'bsm-smtp-option' ) );
		add_settings_field( 'bsm_smtp_port', __( 'Porta SMTP', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'smtp_port', 'class' => 'bsm-smtp-option' ) );
		add_settings_field( 'bsm_smtp_encryption', __( 'Encriptação SMTP', 'bluesendmail' ), array( $this, 'render_select_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'smtp_encryption', 'class' => 'bsm-smtp-option', 'options' => array( 'none' => 'Nenhuma', 'ssl' => 'SSL', 'tls' => 'TLS' ) ) );
		add_settings_field( 'bsm_smtp_user', __( 'Utilizador SMTP', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'smtp_user', 'class' => 'bsm-smtp-option' ) );
		add_settings_field( 'bsm_smtp_pass', __( 'Palavra-passe SMTP', 'bluesendmail' ), array( $this, 'render_password_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'smtp_pass', 'class' => 'bsm-smtp-option' ) );
		add_settings_field( 'bsm_sendgrid_api_key', __( 'Chave da API do SendGrid', 'bluesendmail' ), array( $this, 'render_password_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'sendgrid_api_key', 'class' => 'bsm-sendgrid-option', 'description' => sprintf( __( 'Insira a sua chave da API do SendGrid. Pode encontrá-la no seu painel do <a href="%s" target="_blank">SendGrid</a>.', 'bluesendmail' ), 'https://app.sendgrid.com/settings/api_keys' ) ) );
		add_settings_field( 'bsm_cron_interval', __( 'Intervalo de Envio', 'bluesendmail' ), array( $this, 'render_select_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'cron_interval', 'description' => __( 'Selecione a frequência com que o sistema irá processar a fila de envio.', 'bluesendmail' ), 'options' => array( 'every_three_minutes' => __( 'A Cada 3 Minutos', 'bluesendmail' ), 'every_five_minutes' => __( 'A Cada 5 Minutos (Recomendado)', 'bluesendmail' ), 'every_ten_minutes' => __( 'A Cada 10 Minutos', 'bluesendmail' ), 'every_fifteen_minutes' => __( 'A Cada 15 Minutos', 'bluesendmail' ) ) ) );
		add_settings_section( 'bsm_tracking_section', __( 'Configurações de Rastreamento (Tracking)', 'bluesendmail' ), fn() => print( '<p>' . __( 'Ative ou desative o rastreamento de aberturas e cliques.', 'bluesendmail' ) . '</p>' ), 'bluesendmail-settings' );
		add_settings_field( 'bsm_enable_open_tracking', __( 'Rastreamento de Abertura', 'bluesendmail' ), array( $this, 'render_checkbox_field' ), 'bluesendmail-settings', 'bsm_tracking_section', array( 'id' => 'enable_open_tracking', 'description' => __( 'Ativar o rastreamento de aberturas de e-mail através de um pixel de 1x1.', 'bluesendmail' ) ) );
		add_settings_field( 'bsm_enable_click_tracking', __( 'Rastreamento de Cliques', 'bluesendmail' ), array( $this, 'render_checkbox_field' ), 'bluesendmail-settings', 'bsm_tracking_section', array( 'id' => 'enable_click_tracking', 'description' => __( 'Ativar o rastreamento de cliques em links. Isso irá reescrever todos os links nos e-mails.', 'bluesendmail' ) ) );
	}

	public function render_checkbox_field( $args ) {
		$value = $this->plugin->options[ $args['id'] ] ?? 0;
		echo '<label><input type="checkbox" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="1" ' . checked( 1, $value, false ) . '>';
		if ( ! empty( $args['description'] ) ) echo ' ' . esc_html( $args['description'] ) . '</label>';
	}

	public function render_text_field( $args ) {
		$value = $this->plugin->options[ $args['id'] ] ?? '';
		$class = 'regular-text ' . ( $args['class'] ?? '' );
		echo '<input type="text" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="' . esc_attr( $value ) . '" class="' . esc_attr( $class ) . '">';
		if ( ! empty( $args['description'] ) ) echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
	}

	public function render_email_field( $args ) {
		$value = $this->plugin->options[ $args['id'] ] ?? '';
		echo '<input type="email" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="' . esc_attr( $value ) . '" class="regular-text">';
		if ( ! empty( $args['description'] ) ) echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
	}

	public function render_password_field( $args ) {
		$value = $this->plugin->options[ $args['id'] ] ?? '';
		$class = 'regular-text ' . ( $args['class'] ?? '' );
		echo '<input type="password" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="' . esc_attr( $value ) . '" class="' . esc_attr( $class ) . '">';
		if ( ! empty( $args['description'] ) ) echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
	}

	public function render_select_field( $args ) {
		$defaults = array( 'mailer_type' => 'wp_mail', 'smtp_encryption' => 'tls', 'cron_interval' => 'every_five_minutes' );
		$value = $this->plugin->options[ $args['id'] ] ?? ( $defaults[ $args['id'] ] ?? '' );
		$class = $args['class'] ?? '';
		echo '<select id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" class="' . esc_attr($class) . '">';
		foreach ( $args['options'] as $option_key => $option_value ) {
			echo '<option value="' . esc_attr( $option_key ) . '" ' . selected( $value, $option_key, false ) . '>' . esc_html( $option_value ) . '</option>';
		}
		echo '</select>';
		if ( ! empty( $args['description'] ) ) echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'bluesendmail' ) === false ) return;
		wp_enqueue_style( 'bluesendmail-admin-styles', BLUESENDMAIL_PLUGIN_URL . 'assets/css/admin.css', array(), BLUESENDMAIL_VERSION );
		$page = $_GET['page'] ?? '';
		$is_dashboard_page = 'bluesendmail' === $page;
		$is_campaign_editor = 'bluesendmail-new-campaign' === $page || ( 'bluesendmail-campaigns' === $page && ( $_GET['action'] ?? '' ) === 'edit' );
		$is_import_page = 'bluesendmail-import' === $page;
		$is_reports_page = 'bluesendmail-reports' === $page;
		
		if ( $is_campaign_editor || $is_import_page ) {
			wp_enqueue_style( 'select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css' );
			wp_enqueue_script( 'select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array( 'jquery' ), '4.0.13', true );
		}
		if ( $is_reports_page || $is_dashboard_page ) {
			wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.1', true );
		}
		$js_deps = array( 'jquery' );
		if ( $is_campaign_editor ) { $js_deps[] = 'wp-editor'; $js_deps[] = 'select2'; }
		if ( $is_import_page ) $js_deps[] = 'select2';
		if ( $is_reports_page || $is_dashboard_page ) $js_deps[] = 'chartjs';

		wp_enqueue_script( 'bluesendmail-admin-script', BLUESENDMAIL_PLUGIN_URL . 'assets/js/admin.js', $js_deps, BLUESENDMAIL_VERSION, true );
		
		$script_data = array( 'is_dashboard_page' => $is_dashboard_page, 'is_campaign_editor' => $is_campaign_editor, 'is_import_page' => $is_import_page, 'is_reports_page' => $is_reports_page );
		
		if ( $is_dashboard_page ) {
			global $wpdb;
			$script_data['growth_chart_data'] = $this->get_contacts_growth_data();
			$total_sent_emails = $wpdb->get_var( "SELECT COUNT(q.queue_id) FROM {$wpdb->prefix}bluesendmail_queue q JOIN {$wpdb->prefix}bluesendmail_campaigns c ON q.campaign_id = c.campaign_id WHERE c.status = 'sent'" );
			$total_unique_opens = $wpdb->get_var( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id JOIN {$wpdb->prefix}bluesendmail_campaigns c ON q.campaign_id = c.campaign_id WHERE c.status = 'sent'" );
			$total_unique_clicks = $wpdb->get_var( "SELECT COUNT(DISTINCT cl.contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks cl JOIN {$wpdb->prefix}bluesendmail_campaigns c ON cl.campaign_id = c.campaign_id WHERE c.status = 'sent'" );
			$script_data['performance_chart_data'] = array(
				'sent' => (int) $total_sent_emails,
				'opens' => (int) $total_unique_opens,
				'clicks' => (int) $total_unique_clicks,
				'labels' => array(
					'not_opened' => __( 'Não Aberto', 'bluesendmail' ),
					'opened'     => __( 'Abertos (sem clique)', 'bluesendmail' ),
					'clicked'    => __( 'Clicados', 'bluesendmail' ),
				),
			);
		}

		if ( $is_reports_page && ! empty( $_GET['campaign_id'] ) ) {
			global $wpdb;
			$campaign_id   = absint( $_GET['campaign_id'] );
			$sent          = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE campaign_id = %d", $campaign_id ) );
			$unique_opens  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id WHERE q.campaign_id = %d", $campaign_id ) );
			$unique_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks WHERE campaign_id = %d", $campaign_id ) );
			$script_data['chart_data'] = array( 'sent' => (int) $sent, 'opens' => (int) $unique_opens, 'clicks' => (int) $unique_clicks, 'labels' => array( 'not_opened' => __( 'Não Aberto', 'bluesendmail' ), 'opened' => __( 'Abertura Única', 'bluesendmail' ), 'clicked' => __( 'Clique Único (dentro dos que abriram)', 'bluesendmail' ) ) );
		}
		wp_localize_script( 'bluesendmail-admin-script', 'bsm_admin_data', $script_data );
	}

	public function render_import_page() {
		echo '<div class="wrap bsm-wrap">';
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		if ( 2 === $step && isset( $_POST['bsm_import_step1'] ) ) {
			$this->render_import_step2();
		} else {
			$this->render_import_step1();
		}
		echo '</div>';
	}

	private function render_import_step1() {
		global $wpdb;
		$all_lists = $wpdb->get_results( "SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC" );
		?>
		<div class="bsm-header"><h1><?php _e( 'Importar Contatos - Passo 1 de 2', 'bluesendmail' ); ?></h1></div>
		<div class="bsm-card">
			<p><?php _e( 'Selecione um arquivo CSV para enviar. O arquivo deve conter uma linha de cabeçalho com os nomes das colunas (ex: "E-mail", "Nome", "Apelido").', 'bluesendmail' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-import&step=2' ) ); ?>">
				<table class="form-table">
					<tr valign="top"><th scope="row"><?php _e( 'Arquivo CSV', 'bluesendmail' ); ?></th><td><input type="file" name="bsm_import_file" accept=".csv" required /></td></tr>
					<?php if ( ! empty( $all_lists ) ) : ?>
					<tr valign="top"><th scope="row"><?php _e( 'Adicionar à Lista', 'bluesendmail' ); ?></th><td><select name="bsm_import_list_id" required><option value=""><?php _e( 'Selecione uma lista', 'bluesendmail' ); ?></option><?php foreach ( $all_lists as $list ) : ?><option value="<?php echo esc_attr( $list->list_id ); ?>"><?php echo esc_html( $list->name ); ?></option><?php endforeach; ?></select></td></tr>
					<?php else : ?>
					<tr valign="top"><td colspan="2"><p><?php printf( wp_kses_post( __( 'Nenhuma lista encontrada. Por favor, <a href="%s">crie uma lista</a> antes de importar contatos.', 'bluesendmail' ) ), esc_url( admin_url( 'admin.php?page=bluesendmail-lists&action=new' ) ) ); ?></p></td></tr>
					<?php endif; ?>
				</table>
				<?php wp_nonce_field( 'bsm_import_nonce_action_step1', 'bsm_import_nonce_field_step1' ); ?>
				<?php submit_button( __( 'Próximo Passo', 'bluesendmail' ), 'primary', 'bsm_import_step1', true, ( empty( $all_lists ) ? array( 'disabled' => 'disabled' ) : null ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_import_step2() {
		if ( ! isset( $_POST['bsm_import_nonce_field_step1'] ) || ! wp_verify_nonce( $_POST['bsm_import_nonce_field_step1'], 'bsm_import_nonce_action_step1' ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( empty( $_FILES['bsm_import_file']['tmp_name'] ) ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=no-file' ) ); exit; }
		$file_handle = fopen( $_FILES['bsm_import_file']['tmp_name'], 'r' );
		if ( ! $file_handle ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=read-error' ) ); exit; }
		$headers = fgetcsv( $file_handle );
		fclose( $file_handle );
		$upload_dir = wp_upload_dir();
		$new_file_path = $upload_dir['basedir'] . '/bsm-import-' . uniqid() . '.csv';
		move_uploaded_file( $_FILES['bsm_import_file']['tmp_name'], $new_file_path );
		?>
		<div class="bsm-header"><h1><?php _e( 'Importar Contatos - Passo 2 de 2', 'bluesendmail' ); ?></h1></div>
		<div class="bsm-card">
			<p><?php _e( 'Associe as colunas do seu arquivo CSV aos campos de contato do BlueSendMail.', 'bluesendmail' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bsm_import_contacts"><input type="hidden" name="bsm_import_file_path" value="<?php echo esc_attr( $new_file_path ); ?>"><input type="hidden" name="bsm_import_list_id" value="<?php echo esc_attr( $_POST['bsm_import_list_id'] ); ?>">
				<?php wp_nonce_field( 'bsm_import_nonce_action_step2', 'bsm_import_nonce_field_step2' ); ?>
				<table class="form-table">
					<?php foreach ( $headers as $index => $header ) : ?>
					<tr valign="top"><th scope="row"><label for="map-<?php echo $index; ?>"><?php echo esc_html( $header ); ?></label></th><td><select name="column_map[<?php echo $index; ?>]" id="map-<?php echo $index; ?>"><option value=""><?php _e( 'Ignorar esta coluna', 'bluesendmail' ); ?></option><option value="email"><?php _e( 'E-mail (Obrigatório)', 'bluesendmail' ); ?></option><option value="first_name"><?php _e( 'Primeiro Nome', 'bluesendmail' ); ?></option><option value="last_name"><?php _e( 'Último Nome', 'bluesendmail' ); ?></option><option value="company"><?php _e( 'Empresa', 'bluesendmail' ); ?></option><option value="job_title"><?php _e( 'Cargo', 'bluesendmail' ); ?></option><option value="segment"><?php _e( 'Segmento', 'bluesendmail' ); ?></option></select></td></tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Importar Contatos', 'bluesendmail' ), 'primary', 'bsm_import_contacts' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_lists_page() {
		echo '<div class="wrap bsm-wrap">';
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		if ( 'new' === $action || 'edit' === $action ) {
			$this->render_add_edit_list_page();
		} else {
			$this->render_lists_list_page();
		}
		echo '</div>';
	}

	public function render_lists_list_page() {
		$lists_table = new BlueSendMail_Lists_List_Table();
		?>
		<div class="bsm-header">
			<h1><?php echo esc_html__( 'Listas de Contatos', 'bluesendmail' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-lists&action=new' ) ); ?>" class="page-title-action">
				<span class="dashicons dashicons-plus"></span>
				<?php echo esc_html__( 'Adicionar Nova', 'bluesendmail' ); ?>
			</a>
		</div>
		<form method="post"><?php wp_nonce_field( 'bsm_bulk_action_nonce', 'bsm_bulk_nonce_field' ); $lists_table->prepare_items(); $lists_table->display(); ?></form>
		<?php
	}

	public function render_add_edit_list_page() {
		global $wpdb;
		$list_id = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0;
		$list = $list_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_lists WHERE list_id = %d", $list_id ) ) : null;
		?>
		<div class="bsm-header"><h1><?php echo $list ? esc_html__( 'Editar Lista', 'bluesendmail' ) : esc_html__( 'Adicionar Nova Lista', 'bluesendmail' ); ?></h1></div>
		<div class="bsm-card">
			<form method="post">
				<table class="form-table" role="presentation">
					<tbody>
						<tr class="form-field form-required"><th scope="row"><label for="name"><?php _e( 'Nome da Lista', 'bluesendmail' ); ?> <span class="description">(obrigatório)</span></label></th><td><input name="name" type="text" id="name" value="<?php echo esc_attr( $list->name ?? '' ); ?>" class="regular-text" required></td></tr>
						<tr class="form-field"><th scope="row"><label for="description"><?php _e( 'Descrição', 'bluesendmail' ); ?></label></th><td><textarea name="description" id="description" rows="5" cols="50" class="large-text"><?php echo esc_textarea( $list->description ?? '' ); ?></textarea></td></tr>
					</tbody>
				</table>
				<input type="hidden" name="list_id" value="<?php echo esc_attr( $list_id ); ?>" />
				<?php wp_nonce_field( 'bsm_save_list_nonce_action', 'bsm_save_list_nonce_field' ); ?>
				<?php submit_button( $list ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Adicionar Lista', 'bluesendmail' ), 'primary', 'bsm_save_list' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_contacts_page() {
		echo '<div class="wrap bsm-wrap">';
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		if ( 'new' === $action || 'edit' === $action ) {
			$this->render_add_edit_contact_page();
		} else {
			$this->render_contacts_list_page();
		}
		echo '</div>';
	}

	public function render_contacts_list_page() {
		$contacts_table = new BlueSendMail_Contacts_List_Table();
		?>
		<div class="bsm-header">
			<h1><?php echo esc_html__( 'Contatos', 'bluesendmail' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-contacts&action=new' ) ); ?>" class="page-title-action">
				<span class="dashicons dashicons-plus"></span>
				<?php echo esc_html__( 'Adicionar Novo', 'bluesendmail' ); ?>
			</a>
		</div>
		<form method="post"><?php wp_nonce_field( 'bsm_bulk_action_nonce', 'bsm_bulk_nonce_field' ); $contacts_table->prepare_items(); $contacts_table->display(); ?></form>
		<?php
	}

	public function render_add_edit_contact_page() {
		global $wpdb;
		$contact_id = isset( $_GET['contact'] ) ? absint( $_GET['contact'] ) : 0;
		$contact = null;
		$contact_list_ids = array();
		if ( $contact_id ) {
			$contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_contacts WHERE contact_id = %d", $contact_id ) );
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT list_id FROM {$wpdb->prefix}bluesendmail_contact_lists WHERE contact_id = %d", $contact_id ), ARRAY_A );
			if ( $results ) $contact_list_ids = wp_list_pluck( $results, 'list_id' );
		}
		$all_lists = $wpdb->get_results( "SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC" );
		?>
		<div class="bsm-header"><h1><?php echo $contact ? esc_html__( 'Editar Contato', 'bluesendmail' ) : esc_html__( 'Adicionar Novo Contato', 'bluesendmail' ); ?></h1></div>
		<div class="bsm-card">
			<form method="post">
				<table class="form-table" role="presentation">
					<tbody>
						<tr class="form-field form-required"><th scope="row"><label for="email"><?php _e( 'E-mail', 'bluesendmail' ); ?> <span class="description">(obrigatório)</span></label></th><td><input name="email" type="email" id="email" value="<?php echo esc_attr( $contact->email ?? '' ); ?>" class="regular-text" required></td></tr>
						<tr class="form-field"><th scope="row"><label for="first_name"><?php _e( 'Nome', 'bluesendmail' ); ?></label></th><td><input name="first_name" type="text" id="first_name" value="<?php echo esc_attr( $contact->first_name ?? '' ); ?>" class="regular-text"></td></tr>
						<tr class="form-field"><th scope="row"><label for="last_name"><?php _e( 'Sobrenome', 'bluesendmail' ); ?></label></th><td><input name="last_name" type="text" id="last_name" value="<?php echo esc_attr( $contact->last_name ?? '' ); ?>" class="regular-text"></td></tr>
						<tr class="form-field"><th scope="row"><label for="company"><?php _e( 'Empresa', 'bluesendmail' ); ?></label></th><td><input name="company" type="text" id="company" value="<?php echo esc_attr( $contact->company ?? '' ); ?>" class="regular-text"></td></tr>
						<tr class="form-field"><th scope="row"><label for="job_title"><?php _e( 'Cargo', 'bluesendmail' ); ?></label></th><td><input name="job_title" type="text" id="job_title" value="<?php echo esc_attr( $contact->job_title ?? '' ); ?>" class="regular-text"></td></tr>
						<tr class="form-field"><th scope="row"><label for="segment"><?php _e( 'Segmento', 'bluesendmail' ); ?></label></th><td><input name="segment" type="text" id="segment" value="<?php echo esc_attr( $contact->segment ?? '' ); ?>" class="regular-text"></td></tr>
						<tr class="form-field">
							<th scope="row"><label for="status"><?php _e( 'Status', 'bluesendmail' ); ?></label></th>
							<td><select name="status" id="status"><option value="subscribed" <?php selected( $contact->status ?? 'subscribed', 'subscribed' ); ?>><?php _e( 'Inscrito', 'bluesendmail' ); ?></option><option value="unsubscribed" <?php selected( $contact->status ?? '', 'unsubscribed' ); ?>><?php _e( 'Não Inscrito', 'bluesendmail' ); ?></option><option value="pending" <?php selected( $contact->status ?? '', 'pending' ); ?>><?php _e( 'Pendente', 'bluesendmail' ); ?></option></select></td>
						</tr>
						<?php if ( ! empty( $all_lists ) ) : ?>
						<tr class="form-field">
							<th scope="row"><?php _e( 'Listas', 'bluesendmail' ); ?></th>
							<td><fieldset><legend class="screen-reader-text"><span><?php _e( 'Listas', 'bluesendmail' ); ?></span></legend><?php foreach ( $all_lists as $list ) : ?><label for="list-<?php echo esc_attr( $list->list_id ); ?>"><input type="checkbox" name="lists[]" id="list-<?php echo esc_attr( $list->list_id ); ?>" value="<?php echo esc_attr( $list->list_id ); ?>" <?php checked( in_array( $list->list_id, $contact_list_ids, true ) ); ?>> <?php echo esc_html( $list->name ); ?></label><br><?php endforeach; ?></fieldset></td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<input type="hidden" name="contact_id" value="<?php echo esc_attr( $contact_id ); ?>" />
				<?php wp_nonce_field( 'bsm_save_contact_nonce_action', 'bsm_save_contact_nonce_field' ); ?>
				<?php submit_button( $contact ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Adicionar Contato', 'bluesendmail' ), 'primary', 'bsm_save_contact' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_import_contacts() {
		if ( ! isset( $_POST['bsm_import_nonce_field_step2'] ) || ! wp_verify_nonce( $_POST['bsm_import_nonce_field_step2'], 'bsm_import_nonce_action_step2' ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( ! current_user_can( 'bsm_manage_contacts' ) ) wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
		$file_path = sanitize_text_field( $_POST['bsm_import_file_path'] );
		$list_id   = absint( $_POST['bsm_import_list_id'] );
		$map       = (array) $_POST['column_map'];
		if ( ! $list_id ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=no-list' ) ); exit; }
		$email_column_index = array_search( 'email', $map, true );
		if ( false === $email_column_index ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=no-email-map' ) ); exit; }
		global $wpdb;
		$imported_count = 0; $skipped_count = 0; $row_count = 0;
		if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
			while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
				$row_count++; if ( 1 === $row_count ) continue;
				$email = sanitize_email( $data[ $email_column_index ] ?? '' );
				if ( ! is_email( $email ) ) { $skipped_count++; continue; }
				$contact_data = array( 'email' => $email, 'status' => 'subscribed' );
				foreach ( $map as $index => $field ) { if ( ! empty( $field ) && isset( $data[ $index ] ) ) $contact_data[ $field ] = sanitize_text_field( $data[ $index ] ); }
				$existing_contact_id = $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM {$wpdb->prefix}bluesendmail_contacts WHERE email = %s", $email ) );
				if ( $existing_contact_id ) {
					unset( $contact_data['email'] );
					$wpdb->update( "{$wpdb->prefix}bluesendmail_contacts", $contact_data, array( 'contact_id' => $existing_contact_id ) );
					$contact_id = $existing_contact_id;
				} else {
					$wpdb->insert( "{$wpdb->prefix}bluesendmail_contacts", $contact_data );
					$contact_id = $wpdb->insert_id;
				}
				if ( $contact_id ) { $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}bluesendmail_contact_lists (contact_id, list_id) VALUES (%d, %d)", $contact_id, $list_id ) ); $imported_count++; } else { $skipped_count++; }
			}
			fclose( $handle );
		}
		@unlink( $file_path );
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&imported=' . $imported_count . '&skipped=' . $skipped_count ) );
		exit;
	}

	public function handle_bulk_delete_contacts() {
		if ( ! isset( $_POST['bsm_bulk_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_bulk_nonce_field'], 'bsm_bulk_action_nonce' ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( ! current_user_can( 'bsm_manage_contacts' ) ) wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
		$contact_ids = isset( $_POST['contact'] ) ? array_map( 'absint', $_POST['contact'] ) : array();
		if ( empty( $contact_ids ) ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&error=no-items-selected' ) ); exit; }
		global $wpdb;
		$ids_placeholder = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_contacts WHERE contact_id IN ($ids_placeholder)", $contact_ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_contact_lists WHERE contact_id IN ($ids_placeholder)", $contact_ids ) );
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&items-deleted=true' ) );
		exit;
	}

	public function handle_bulk_delete_lists() {
		if ( ! isset( $_POST['bsm_bulk_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_bulk_nonce_field'], 'bsm_bulk_action_nonce' ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( ! current_user_can( 'bsm_manage_lists' ) ) wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
		$list_ids = isset( $_POST['list'] ) ? array_map( 'absint', $_POST['list'] ) : array();
		if ( empty( $list_ids ) ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&error=no-items-selected' ) ); exit; }
		global $wpdb;
		$ids_placeholder = implode( ',', array_fill( 0, count( $list_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_lists WHERE list_id IN ($ids_placeholder)", $list_ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_contact_lists WHERE list_id IN ($ids_placeholder)", $list_ids ) );
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&items-deleted=true' ) );
		exit;
	}

	public function handle_delete_contact() {
		$contact_id = absint( $_GET['contact'] );
		$nonce = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'bsm_delete_contact_' . $contact_id ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( ! current_user_can( 'bsm_manage_contacts' ) ) wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_contacts", array( 'contact_id' => $contact_id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_contact_lists", array( 'contact_id' => $contact_id ), array( '%d' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&contact-deleted=true' ) );
		exit;
	}

	public function handle_save_contact() {
		if ( ! isset( $_POST['bsm_save_contact_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_save_contact_nonce_field'], 'bsm_save_contact_nonce_action' ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( ! current_user_can( 'bsm_manage_contacts' ) ) wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
		global $wpdb;
		$contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		$data = array('email' => sanitize_email( $_POST['email'] ),'first_name' => sanitize_text_field( $_POST['first_name'] ),'last_name' => sanitize_text_field( $_POST['last_name'] ),'company' => sanitize_text_field( $_POST['company'] ),'job_title' => sanitize_text_field( $_POST['job_title'] ),'segment' => sanitize_text_field( $_POST['segment'] ),'status' => sanitize_key( $_POST['status'] ));
		if ( empty( $data['email'] ) ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&action=new&error=empty-email' ) ); exit; };
		if ( $contact_id ) {
			$result = $wpdb->update( "{$wpdb->prefix}bluesendmail_contacts", $data, array( 'contact_id' => $contact_id ) );
			$redirect_slug = 'contact-updated=true';
		} else {
			$result = $wpdb->insert( "{$wpdb->prefix}bluesendmail_contacts", $data );
			if ( $result ) $contact_id = $wpdb->insert_id;
			$redirect_slug = 'contact-added=true';
		}
		if ( false === $result || ! $contact_id ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&action=' . ( $contact_id ? 'edit&contact=' . $contact_id : 'new' ) . '&error=' . ( $contact_id ? 'contact-update-failed' : 'contact-insert-failed' ) ) ); exit; }
		$submitted_lists = isset( $_POST['lists'] ) ? array_map( 'absint', $_POST['lists'] ) : array();
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_contact_lists", array( 'contact_id' => $contact_id ), array( '%d' ) );
		if ( ! empty( $submitted_lists ) ) { foreach ( $submitted_lists as $list_id ) $wpdb->insert( "{$wpdb->prefix}bluesendmail_contact_lists", array( 'contact_id' => $contact_id, 'list_id' => $list_id ), array( '%d', '%d' ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&' . $redirect_slug ) );
		exit;
	}

	public function handle_delete_list() {
		$list_id = absint( $_GET['list'] );
		$nonce = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'bsm_delete_list_' . $list_id ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( ! current_user_can( 'bsm_manage_lists' ) ) wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_lists", array( 'list_id' => $list_id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}bluesendmail_contact_lists", array( 'list_id' => $list_id ), array( '%d' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&list-deleted=true' ) );
		exit;
	}

	public function handle_save_list() {
		if ( ! isset( $_POST['bsm_save_list_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_save_list_nonce_field'], 'bsm_save_list_nonce_action' ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( ! current_user_can( 'bsm_manage_lists' ) ) wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
		global $wpdb;
		$list_id = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
		$data = array( 'name' => sanitize_text_field( $_POST['name'] ), 'description' => sanitize_textarea_field( $_POST['description'] ) );
		if ( empty( $data['name'] ) ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&action=' . ( $list_id ? 'edit&list=' . $list_id : 'new' ) . '&error=empty-name' ) ); exit; }
		if ( $list_id ) {
			$result = $wpdb->update( "{$wpdb->prefix}bluesendmail_lists", $data, array( 'list_id' => $list_id ) );
			$redirect_slug = 'list-updated=true';
		} else {
			$result = $wpdb->insert( "{$wpdb->prefix}bluesendmail_lists", $data );
			$redirect_slug = 'list-added=true';
		}
		if ( false === $result ) wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&action=' . ( $list_id ? 'edit&list=' . $list_id : 'new' ) . '&error=' . ( $list_id ? 'list-update-failed' : 'list-insert-failed' ) ) );
		else wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&' . $redirect_slug ) );
		exit;
	}

	public function handle_send_test_email() {
		if ( ! isset( $_POST['bsm_send_test_email_nonce'] ) || ! wp_verify_nonce( $_POST['bsm_send_test_email_nonce'], 'bsm_send_test_email_action' ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( ! current_user_can( 'bsm_manage_settings' ) ) wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
		$recipient = sanitize_email( $_POST['bsm_test_email_recipient'] );
		if ( ! is_email( $recipient ) ) { set_transient( 'bsm_test_email_result', array( 'success' => false, 'message' => __( 'O endereço de e-mail fornecido é inválido.', 'bluesendmail' ) ), 30 ); wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-settings' ) ); exit; }
		$subject = '[' . get_bloginfo( 'name' ) . '] ' . __( 'E-mail de Teste do BlueSendMail', 'bluesendmail' );
		$body    = '<h1>🎉 ' . __( 'Teste de Envio Bem-sucedido!', 'bluesendmail' ) . '</h1><p>' . __( 'Se você está recebendo este e-mail, suas configurações de envio estão funcionando corretamente.', 'bluesendmail' ) . '</p>';
		
		$mock_contact = (object) array( 'email' => $recipient, 'first_name' => 'Teste', 'last_name' => 'Usuário' );
		$result = $this->plugin->send_email( $recipient, $subject, $body, $mock_contact, 0 );

		if ( $result ) {
			set_transient( 'bsm_test_email_result', array( 'success' => true, 'message' => __( 'E-mail de teste enviado com sucesso!', 'bluesendmail' ) ), 30 );
			$this->plugin->log_event( 'success', 'test_email', "E-mail de teste enviado para {$recipient}." );
		} else {
			$message = __( 'Falha ao enviar o e-mail de teste.', 'bluesendmail' );
			if ( ! empty( $this->plugin->mail_error ) ) $message .= '<br><strong>' . __( 'Erro retornado:', 'bluesendmail' ) . '</strong> <pre>' . esc_html( $this->plugin->mail_error ) . '</pre>';
			set_transient( 'bsm_test_email_result', array( 'success' => false, 'message' => $message ), 30 );
			$this->plugin->log_event( 'error', 'test_email', "Falha ao enviar e-mail de teste para {$recipient}.", $this->plugin->mail_error );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-settings' ) );
		exit;
	}

	public function handle_save_campaign() {
		if ( ! isset( $_POST['bsm_save_campaign_nonce'] ) || ! wp_verify_nonce( $_POST['bsm_save_campaign_nonce'], 'bsm_save_campaign_action' ) ) wp_die( esc_html__( 'Falha na verificação de segurança.', 'bluesendmail' ) );
		if ( ! current_user_can( 'bsm_manage_campaigns' ) ) wp_die( esc_html__( 'Você não tem permissão para realizar esta ação.', 'bluesendmail' ) );
		global $wpdb;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$title = sanitize_text_field( wp_unslash( $_POST['bsm_title'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['bsm_subject'] ?? '' ) );
		$preheader = sanitize_text_field( wp_unslash( $_POST['bsm_preheader'] ?? '' ) );
		$content = wp_kses_post( wp_unslash( $_POST['bsm_content'] ?? '' ) );
		$lists = isset( $_POST['bsm_lists'] ) ? array_map( 'absint', (array) $_POST['bsm_lists'] ) : array();
		$is_send_now = isset( $_POST['bsm_send_campaign'] );
		$is_schedule = isset( $_POST['bsm_schedule_campaign'] );
		$schedule_enabled = isset( $_POST['bsm_schedule_enabled'] ) && '1' === $_POST['bsm_schedule_enabled'];
		$schedule_date = sanitize_text_field( $_POST['bsm_schedule_date'] ?? '' );
		$schedule_time = sanitize_text_field( $_POST['bsm_schedule_time'] ?? '' );
		$scheduled_for = null;
		if ( ( $is_schedule || $schedule_enabled ) && ! empty( $schedule_date ) && ! empty( $schedule_time ) ) {
			$schedule_datetime = new DateTime( "$schedule_date $schedule_time", $this->plugin->bsm_get_timezone() );
			$schedule_datetime->setTimezone( new DateTimeZone( 'UTC' ) );
			$scheduled_for = $schedule_datetime->format( 'Y-m-d H:i:s' );
		}
		$status = 'draft';
		if ( $is_send_now ) $status = 'queued';
		elseif ( $is_schedule && $scheduled_for ) $status = 'scheduled';
		$data = array( 'title' => $title, 'subject' => $subject, 'preheader' => $preheader, 'content' => $content, 'status' => $status, 'lists' => maybe_serialize( $lists ), 'scheduled_for' => $scheduled_for );
		if ( $campaign_id ) {
			$wpdb->update( "{$wpdb->prefix}bluesendmail_campaigns", $data, array( 'campaign_id' => $campaign_id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', 1 );
			$wpdb->insert( "{$wpdb->prefix}bluesendmail_campaigns", $data );
			$campaign_id = $wpdb->insert_id;
		}
		if ( ! $campaign_id ) { set_transient( 'bsm_campaign_error_notice_' . get_current_user_id(), __( 'Falha ao salvar a campanha.', 'bluesendmail' ), 30 ); wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-new-campaign' ) ); exit; }
		$this->plugin->log_event( 'info', 'campaign', "Campanha #{$campaign_id} salva com status '{$status}'." );
		if ( $is_send_now ) {
			$this->plugin->enqueue_campaign_recipients( $campaign_id );
			set_transient( 'bsm_campaign_queued_notice_' . get_current_user_id(), __( 'Campanha enfileirada para envio imediato!', 'bluesendmail' ), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-campaigns' ) );
			exit;
		}
		if ( $is_schedule ) {
			set_transient( 'bsm_campaign_queued_notice_' . get_current_user_id(), __( 'Campanha agendada com sucesso!', 'bluesendmail' ), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-campaigns' ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-campaigns&action=edit&campaign=' . $campaign_id . '&updated=1' ) );
		exit;
	}

	public function show_admin_notices() {
		$test_result = get_transient( 'bsm_test_email_result' );
		if ( $test_result ) { printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $test_result['success'] ? 'success' : 'error', wp_kses_post( $test_result['message'] ) ); delete_transient( 'bsm_test_email_result' ); }
		$user_id = get_current_user_id();
		$success_message = get_transient( 'bsm_campaign_queued_notice_' . $user_id );
		if ( $success_message ) { printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $success_message ) ); delete_transient( 'bsm_campaign_queued_notice_' . $user_id ); }
		$error_message = get_transient( 'bsm_campaign_error_notice_' . $user_id );
		if ( $error_message ) { printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $error_message ) ); delete_transient( 'bsm_campaign_error_notice_' . $user_id ); }
		$notices = array(
			'success' => array('contact-added'=>'Contato adicionado com sucesso!','contact-updated'=>'Contato atualizado com sucesso!','contact-deleted'=>'Contato excluído com sucesso!','list-added'=>'Lista adicionada com sucesso!','list-updated'=>'Lista atualizada com sucesso!','list-deleted'=>'Lista excluída com sucesso!','items-deleted'=>'Os itens selecionados foram excluídos com sucesso.','campaign-saved'=>'Campanha guardada com sucesso.','campaign-sent'=>'Campanha enfileirada para envio com sucesso!'),
			'error' => array('contact-insert-failed'=>'Falha ao adicionar o contato. É possível que o e-mail já exista.','contact-update-failed'=>'Falha ao atualizar o contato.','list-insert-failed'=>'Falha ao adicionar a lista.','list-update-failed'=>'Falha ao atualizar a lista.','empty-name'=>'O nome da lista não pode estar vazio.','no-file'=>'Nenhum arquivo foi selecionado para importação.','no-list'=>'Nenhuma lista foi selecionada para a importação.','no-items-selected'=>'Nenhum item foi selecionado.','no-email-map'=>'É obrigatório associar uma coluna ao campo "E-mail".'),
		);
		foreach ( $notices as $type => $messages ) { foreach ( $messages as $key => $message ) { if ( isset( $_GET[ $key ] ) ) { printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( __( $message, 'bluesendmail' ) ) ); return; } } }
		if ( isset( $_GET['imported'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( 'Importação concluída! %d contatos importados/atualizados e %d linhas ignoradas.', 'bluesendmail' ), absint( $_GET['imported'] ), absint( $_GET['skipped'] ?? 0 ) ) ) );
		}
	}
}

