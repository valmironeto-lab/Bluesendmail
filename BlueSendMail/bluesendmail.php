<?php
/**
 * Plugin Name:       BlueSendMail
 * Plugin URI:        https://blueagenciadigital.com.br/bluesendmail
 * Description:       Uma plataforma de e-mail marketing e automação nativa do WordPress para gerenciar contatos, criar campanhas e garantir alta entregabilidade.
 * Version:           1.9.0
 * Author:            Blue Mkt Digital
 * Author URI:        https://blueagenciadigital.com.br/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bluesendmail
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; 
}

define( 'BLUESENDMAIL_VERSION', '1.9.0' );
define( 'BLUESENDMAIL_PLUGIN_FILE', __FILE__ );
define( 'BLUESENDMAIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLUESENDMAIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

final class BlueSendMail {

	private static $_instance = null;
	private $options = array();
	public $mail_error = '';
	private $current_queue_id_for_tracking = 0;

	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		$this->load_options();
		$this->load_list_tables();
		$this->register_hooks();
	}
	
	private function load_options() {
		$this->options = get_option( 'bluesendmail_settings', array() );
	}

	private function load_list_tables() {
		$tables_path = BLUESENDMAIL_PLUGIN_DIR . 'includes/tables/';
		require_once $tables_path . 'class-bluesendmail-campaigns-list-table.php';
		require_once $tables_path . 'class-bluesendmail-contacts-list-table.php';
		require_once $tables_path . 'class-bluesendmail-lists-list-table.php';
		require_once $tables_path . 'class-bluesendmail-logs-list-table.php';
		require_once $tables_path . 'class-bluesendmail-reports-list-table.php';
		require_once $tables_path . 'class-bluesendmail-clicks-list-table.php';
		require_once $tables_path . 'class-bluesendmail-forms-list-table.php';
	}

	private function register_hooks() {
		register_activation_hook( BLUESENDMAIL_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( BLUESENDMAIL_PLUGIN_FILE, array( $this, 'deactivate' ) );
		
		add_action( 'init', array( $this, 'handle_public_actions' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'setup_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'handle_actions' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_init', array( $this, 'maybe_trigger_cron' ) );
			add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}

		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
		add_action( 'bsm_process_sending_queue', array( $this, 'process_sending_queue' ) );
		add_action( 'bsm_check_scheduled_campaigns', array( $this, 'enqueue_scheduled_campaigns' ) );
		add_action( 'update_option_bluesendmail_settings', array( $this, 'reschedule_cron_on_settings_update' ), 10, 2 );
		add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
		add_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );
	}

	public function activate() {
		$this->check_database_setup();
		flush_rewrite_rules();
		
		if ( ! wp_next_scheduled( 'bsm_process_sending_queue' ) ) {
			$interval = $this->options['cron_interval'] ?? 'every_five_minutes';
			wp_schedule_event( time(), $interval, 'bsm_process_sending_queue' );
		}
		if ( ! wp_next_scheduled( 'bsm_check_scheduled_campaigns' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'bsm_check_scheduled_campaigns' );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook( 'bsm_process_sending_queue' );
		wp_clear_scheduled_hook( 'bsm_check_scheduled_campaigns' );
		flush_rewrite_rules();
	}

    public function add_cron_interval( $schedules ) {
        $schedules['every_three_minutes'] = array( 'interval' => 180, 'display' => esc_html__( 'A Cada 3 Minutos', 'bluesendmail' ) );
		$schedules['every_five_minutes'] = array( 'interval' => 300, 'display' => esc_html__( 'A Cada 5 Minutos', 'bluesendmail' ) );
		$schedules['every_ten_minutes'] = array( 'interval' => 600, 'display' => esc_html__( 'A Cada 10 Minutos', 'bluesendmail' ) );
		$schedules['every_fifteen_minutes'] = array( 'interval' => 900, 'display' => esc_html__( 'A Cada 15 Minutos', 'bluesendmail' ) );
        return $schedules;
    }

	private function check_database_setup() {
        $current_db_version = get_option( 'bluesendmail_db_version', '0.0.0' );
        if ( version_compare( $current_db_version, BLUESENDMAIL_VERSION, '<' ) ) {
            $this->create_database_tables();
            update_option( 'bluesendmail_db_version', BLUESENDMAIL_VERSION );
        }
    }

	private function create_database_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$table_name_campaigns = $wpdb->prefix . 'bluesendmail_campaigns';
		$sql_campaigns = "CREATE TABLE $table_name_campaigns (
			campaign_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL,
			subject varchar(255) DEFAULT NULL,
			preheader varchar(255) DEFAULT NULL,
			content longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			lists text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			scheduled_for datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			PRIMARY KEY  (campaign_id)
		) $charset_collate;";
		dbDelta( $sql_campaigns );

		// MODIFICADO: Adicionada a coluna 'confirmation_token'
		$table_name_contacts = $wpdb->prefix . 'bluesendmail_contacts';
		$sql_contacts = "CREATE TABLE $table_name_contacts (
			contact_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			email varchar(255) NOT NULL,
			first_name varchar(255) DEFAULT NULL,
			last_name varchar(255) DEFAULT NULL,
			company varchar(255) DEFAULT NULL,
			job_title varchar(255) DEFAULT NULL,
			segment varchar(255) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'subscribed',
			confirmation_token varchar(64) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (contact_id),
			UNIQUE KEY email (email),
			KEY confirmation_token (confirmation_token)
		) $charset_collate;";
		dbDelta( $sql_contacts );

		$table_name_lists = $wpdb->prefix . 'bluesendmail_lists';
		$sql_lists = "CREATE TABLE $table_name_lists (
			list_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (list_id)
		) $charset_collate;";
		dbDelta( $sql_lists );

		$table_name_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';
		$sql_contact_lists = "CREATE TABLE $table_name_contact_lists (
			contact_id bigint(20) UNSIGNED NOT NULL,
			list_id bigint(20) UNSIGNED NOT NULL,
			PRIMARY KEY  (contact_id, list_id),
			KEY list_id (list_id)
		) $charset_collate;";
		dbDelta( $sql_contact_lists );

		$table_name_queue = $wpdb->prefix . 'bluesendmail_queue';
		$sql_queue = "CREATE TABLE $table_name_queue (
			queue_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) UNSIGNED NOT NULL,
			contact_id bigint(20) UNSIGNED NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			added_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (queue_id),
			KEY campaign_id (campaign_id),
			KEY contact_id (contact_id)
		) $charset_collate;";
		dbDelta( $sql_queue );

		$table_name_logs = $wpdb->prefix . 'bluesendmail_logs';
		$sql_logs = "CREATE TABLE $table_name_logs (
			log_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			source varchar(50) NOT NULL,
			message text NOT NULL,
			details longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (log_id),
			KEY type (type),
			KEY source (source)
		) $charset_collate;";
		dbDelta( $sql_logs );

		$table_name_opens = $wpdb->prefix . 'bluesendmail_email_opens';
		$sql_opens = "CREATE TABLE $table_name_opens (
			open_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id bigint(20) UNSIGNED NOT NULL,
			opened_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_address varchar(100) DEFAULT NULL,
			user_agent varchar(255) DEFAULT NULL,
			PRIMARY KEY  (open_id),
			KEY queue_id (queue_id)
		) $charset_collate;";
		dbDelta( $sql_opens );

		$table_name_clicks = $wpdb->prefix . 'bluesendmail_email_clicks';
		$sql_clicks = "CREATE TABLE $table_name_clicks (
			click_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id bigint(20) UNSIGNED NOT NULL,
			campaign_id bigint(20) UNSIGNED NOT NULL,
			contact_id bigint(20) UNSIGNED NOT NULL,
			original_url text NOT NULL,
			clicked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_address varchar(100) DEFAULT NULL,
			user_agent varchar(255) DEFAULT NULL,
			PRIMARY KEY  (click_id),
			KEY queue_id (queue_id),
			KEY campaign_id (campaign_id)
		) $charset_collate;";
		dbDelta( $sql_clicks );

		// NOVA TABELA: Formulários
		$table_name_forms = $wpdb->prefix . 'bluesendmail_forms';
		$sql_forms = "CREATE TABLE $table_name_forms (
			form_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			title varchar(255) DEFAULT NULL,
			description text DEFAULT NULL,
			fields text DEFAULT NULL,
			submit_button_text varchar(255) DEFAULT NULL,
			success_message text DEFAULT NULL,
			list_id bigint(20) UNSIGNED NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (form_id),
			KEY list_id (list_id)
		) $charset_collate;";
		dbDelta( $sql_forms );
	}
	
	public function setup_admin_menu() {
		add_menu_page(
			__( 'BlueSendMail', 'bluesendmail' ),
			__( 'BlueSendMail', 'bluesendmail' ),
			'manage_options',
			'bluesendmail',
			array( $this, 'render_dashboard_page' ),
			'dashicons-email-alt2',
			25
		);

		add_submenu_page(
			'bluesendmail',
			__( 'Dashboard', 'bluesendmail' ),
			__( 'Dashboard', 'bluesendmail' ),
			'manage_options',
			'bluesendmail',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'bluesendmail',
			__( 'Campanhas', 'bluesendmail' ),
			__( 'Campanhas', 'bluesendmail' ),
			'manage_options',
			'bluesendmail-campaigns',
			array( $this, 'render_campaigns_page' )
		);
		add_submenu_page(
			'bluesendmail',
			__( 'Criar Nova Campanha', 'bluesendmail' ),
			__( 'Criar Nova', 'bluesendmail' ),
			'manage_options',
			'bluesendmail-new-campaign',
			array( $this, 'render_add_edit_campaign_page' )
		);
		
		add_submenu_page(
			'bluesendmail',
			__( 'Contatos', 'bluesendmail' ),
			__( 'Contatos', 'bluesendmail' ),
			'manage_options',
			'bluesendmail-contacts',
			array( $this, 'render_contacts_page' )
		);
		
		add_submenu_page(
			'bluesendmail',
			__( 'Listas', 'bluesendmail' ),
			__( 'Listas', 'bluesendmail' ),
			'manage_options',
			'bluesendmail-lists',
			array( $this, 'render_lists_page' )
		);

		add_submenu_page(
			'bluesendmail',
			__( 'Formulários', 'bluesendmail' ),
			__( 'Formulários', 'bluesendmail' ),
			'manage_options',
			'bluesendmail-forms',
			array( $this, 'render_forms_page' )
		);
		
		add_submenu_page(
			'bluesendmail',
			__( 'Importar', 'bluesendmail' ),
			__( 'Importar', 'bluesendmail' ),
			'manage_options',
			'bluesendmail-import',
			array( $this, 'render_import_page' )
		);
		
		add_submenu_page(
			'bluesendmail',
			__( 'Relatórios', 'bluesendmail' ),
			__( 'Relatórios', 'bluesendmail' ),
			'manage_options',
			'bluesendmail-reports',
			array( $this, 'render_reports_page' )
		);
		add_submenu_page(
			'bluesendmail',
			__( 'Logs do Sistema', 'bluesendmail' ),
			__( 'Logs do Sistema', 'bluesendmail' ),
			'manage_options',
			'bluesendmail-logs',
			array( $this, 'render_logs_page' )
		);
		add_submenu_page(
			'bluesendmail',
			__( 'Configurações', 'bluesendmail' ),
			__( 'Configurações', 'bluesendmail' ),
			'manage_options',
			'bluesendmail-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function render_dashboard_page() {
		global $wpdb;
	
		$total_subscribers = $wpdb->get_var( "SELECT COUNT(contact_id) FROM {$wpdb->prefix}bluesendmail_contacts WHERE status = 'subscribed'" );
		$sent_campaigns    = $wpdb->get_var( "SELECT COUNT(campaign_id) FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status = 'sent'" );
	
		$total_sent_emails = $wpdb->get_var( "SELECT COUNT(q.queue_id) FROM {$wpdb->prefix}bluesendmail_queue q JOIN {$wpdb->prefix}bluesendmail_campaigns c ON q.campaign_id = c.campaign_id WHERE c.status = 'sent'" );
		
		$total_unique_opens = $wpdb->get_var( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id JOIN {$wpdb->prefix}bluesendmail_campaigns c ON q.campaign_id = c.campaign_id WHERE c.status = 'sent'" );
	
		$total_unique_clicks = $wpdb->get_var( "SELECT COUNT(DISTINCT cl.contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks cl JOIN {$wpdb->prefix}bluesendmail_campaigns c ON cl.campaign_id = c.campaign_id WHERE c.status = 'sent'" );
	
		$avg_open_rate  = ( $total_sent_emails > 0 ) ? ( $total_unique_opens / $total_sent_emails ) * 100 : 0;
		$avg_click_rate = ( $total_unique_opens > 0 ) ? ( $total_unique_clicks / $total_unique_opens ) * 100 : 0;
	
		$last_campaign = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1" );
		?>
		<div class="wrap bsm-dashboard-wrap">
			<h1><?php _e('Dashboard do BlueSendMail', 'bluesendmail'); ?></h1>
			<p><?php _e('Bem-vindo! Aqui está um resumo rápido da sua atividade de e-mail marketing.', 'bluesendmail'); ?></p>
	
			<div class="bsm-dashboard-grid">
				<div class="bsm-card">
					<h3 class="bsm-card-title"><?php _e('Total de Contatos Inscritos', 'bluesendmail'); ?></h3>
					<div class="bsm-stat-number"><?php echo number_format_i18n($total_subscribers); ?></div>
				</div>
				<div class="bsm-card">
					<h3 class="bsm-card-title"><?php _e('Campanhas Enviadas', 'bluesendmail'); ?></h3>
					<div class="bsm-stat-number"><?php echo number_format_i18n($sent_campaigns); ?></div>
				</div>
				<div class="bsm-card">
					<h3 class="bsm-card-title"><?php _e('Taxa Média de Abertura', 'bluesendmail'); ?></h3>
					<div class="bsm-stat-number"><?php echo number_format_i18n($avg_open_rate, 2); ?>%</div>
				</div>
				<div class="bsm-card">
					<h3 class="bsm-card-title"><?php _e('Taxa Média de Cliques (CTOR)', 'bluesendmail'); ?></h3>
					<div class="bsm-stat-number"><?php echo number_format_i18n($avg_click_rate, 2); ?>%</div>
				</div>
			</div>
	
			<div class="bsm-dashboard-grid">
				<div class="bsm-card bsm-card-full">
					<h3 class="bsm-card-title"><?php _e('Ações Rápidas', 'bluesendmail'); ?></h3>
					<div class="bsm-quick-actions">
						<a href="<?php echo esc_url(admin_url('admin.php?page=bluesendmail-new-campaign')); ?>" class="button button-primary"><?php _e('Criar Nova Campanha', 'bluesendmail'); ?></a>
						<a href="<?php echo esc_url(admin_url('admin.php?page=bluesendmail-contacts&action=new')); ?>" class="button button-secondary"><?php _e('Adicionar Contato', 'bluesendmail'); ?></a>
						<a href="<?php echo esc_url(admin_url('admin.php?page=bluesendmail-forms&action=new')); ?>" class="button button-secondary"><?php _e('Criar Formulário', 'bluesendmail'); ?></a>
					</div>
				</div>
			</div>
			
			<div class="bsm-dashboard-grid">
				<div class="bsm-card bsm-card-full">
					<h3 class="bsm-card-title"><?php _e('Última Campanha Enviada', 'bluesendmail'); ?></h3>
					<?php if ($last_campaign): 
						$sent          = $wpdb->get_var($wpdb->prepare("SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE campaign_id = %d", $last_campaign->campaign_id));
						$unique_opens  = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id WHERE q.campaign_id = %d", $last_campaign->campaign_id));
						$total_opens   = $wpdb->get_var($wpdb->prepare("SELECT COUNT(o.open_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id WHERE q.campaign_id = %d", $last_campaign->campaign_id));
						$unique_clicks = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks WHERE campaign_id = %d", $last_campaign->campaign_id));
						
						$open_rate   = ($sent > 0) ? ($unique_opens / $sent) * 100 : 0;
						$click_rate  = ($unique_opens > 0) ? ($unique_clicks / $unique_opens) * 100 : 0;
					?>
						<h4><a href="<?php echo esc_url(admin_url('admin.php?page=bluesendmail-reports&campaign_id=' . $last_campaign->campaign_id)); ?>"><?php echo esc_html($last_campaign->title); ?></a></h4>
						<p><strong><?php _e('Enviada em:', 'bluesendmail'); ?></strong> <?php echo get_date_from_gmt($last_campaign->sent_at, 'd/m/Y H:i'); ?></p>
						<p><strong><?php _e('Estatísticas:', 'bluesendmail'); ?></strong> 
							<?php printf(__('%d enviados, %d aberturas únicas (%s%%), %d cliques (%s%% CTOR)', 'bluesendmail'), $sent, $unique_opens, number_format_i18n($open_rate, 2), $unique_clicks, number_format_i18n($click_rate, 2)); ?>
						</p>
					<?php else: ?>
						<p><?php _e('Nenhuma campanha foi enviada ainda.', 'bluesendmail'); ?></p>
					<?php endif; ?>
				</div>
			</div>
	
		</div>
		<?php
	}

	public function render_campaigns_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'edit' === $action && ! empty( $_GET['campaign'] ) ) {
			$this->render_add_edit_campaign_page();
		} else {
			$this->render_campaigns_list_page();
		}
	}

	public function render_campaigns_list_page() {
		$campaigns_table = new BlueSendMail_Campaigns_List_Table();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Campanhas', 'bluesendmail' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-new-campaign' ) ); ?>" class="page-title-action"><?php echo esc_html__( 'Criar Nova', 'bluesendmail' ); ?></a>
			<hr class="wp-header-end">
			<form method="post">
				<?php
				$campaigns_table->prepare_items();
				$campaigns_table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function render_add_edit_campaign_page() {
		global $wpdb;
		$campaign_id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
		
		$campaign = null;
		$selected_lists = array();
		$title = '';
		$subject = '';
		$preheader = '';
		$content = '';

		if ( $campaign_id ) {
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id = %d", $campaign_id ) );
			if ( $campaign ) {
				$title = $campaign->title;
				$subject = $campaign->subject;
				$preheader = $campaign->preheader;
				$content = $campaign->content;
				$selected_lists = ! empty($campaign->lists) ? unserialize($campaign->lists) : array();
			}
		}

		$page_title = $campaign ? __( 'Editar Campanha', 'bluesendmail' ) : __( 'Criar Nova Campanha', 'bluesendmail' );
		$submit_label = $campaign ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Salvar Rascunho', 'bluesendmail' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $page_title ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'bsm_save_campaign_action', 'bsm_save_campaign_nonce' ); ?>
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>">
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="bsm-title"><?php _e( 'Título da Campanha', 'bluesendmail' ); ?></label></th>
							<td><input type="text" name="bsm_title" id="bsm-title" class="large-text" value="<?php echo esc_attr( $title ); ?>" required>
							<p class="description"><?php _e( 'Para sua referência interna.', 'bluesendmail' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="bsm-subject"><?php _e( 'Assunto do E-mail', 'bluesendmail' ); ?></label></th>
							<td><input type="text" name="bsm_subject" id="bsm-subject" class="large-text" value="<?php echo esc_attr( $subject ); ?>">
							<p class="description"><?php _e( 'Deixe em branco para usar o título da campanha.', 'bluesendmail' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="bsm-preheader"><?php _e( 'Pré-cabeçalho (Preheader)', 'bluesendmail' ); ?></label></th>
							<td><input type="text" name="bsm_preheader" id="bsm-preheader" class="large-text" value="<?php echo esc_attr( $preheader ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="bsm-content"><?php _e( 'Conteúdo do E-mail', 'bluesendmail' ); ?></label></th>
							<td>
								<div class="bsm-merge-tags-container">
									<h3><?php _e('Personalize seu e-mail', 'bluesendmail'); ?></h3>
									<p><?php _e('Clique nas tags abaixo para inseri-las no seu conteúdo ou assunto.', 'bluesendmail'); ?></p>
									
									<p class="bsm-tags-group-title"><?php _e('Dados do Contato:', 'bluesendmail'); ?></p>
									<div>
										<span class="bsm-merge-tag" data-tag="{{contact.first_name}}"><?php _e('Primeiro Nome', 'bluesendmail'); ?></span>
										<span class="bsm-merge-tag" data-tag="{{contact.last_name}}"><?php _e('Último Nome', 'bluesendmail'); ?></span>
										<span class="bsm-merge-tag" data-tag="{{contact.email}}"><?php _e('E-mail do Contato', 'bluesendmail'); ?></span>
									</div>

									<p class="bsm-tags-group-title"><?php _e('Dados do Site e Links:', 'bluesendmail'); ?></p>
									<div>
										<span class="bsm-merge-tag" data-tag="{{site.name}}"><?php _e('Nome do Site', 'bluesendmail'); ?></span>
										<span class="bsm-merge-tag" data-tag="{{site.url}}"><?php _e('URL do Site', 'bluesendmail'); ?></span>
										<span class="bsm-merge-tag" data-tag="{{unsubscribe_link}}"><?php _e('Link de Desinscrição', 'bluesendmail'); ?></span>
									</div>
								</div>
								<?php wp_editor( $content, 'bsm-content', array( 'textarea_name' => 'bsm_content', 'media_buttons' => true ) ); ?>
								<?php if ( ! empty( $this->options['enable_open_tracking'] ) || ! empty( $this->options['enable_click_tracking'] ) ) : ?>
									<p class="description">
										<?php _e( 'Rastreamento ativado:', 'bluesendmail' ); ?>
										<?php if ( ! empty( $this->options['enable_open_tracking'] ) ) echo __( 'Aberturas', 'bluesendmail' ); ?>
										<?php if ( ! empty( $this->options['enable_open_tracking'] ) && ! empty( $this->options['enable_click_tracking'] ) ) echo ' & '; ?>
										<?php if ( ! empty( $this->options['enable_click_tracking'] ) ) echo __( 'Cliques', 'bluesendmail' ); ?>.
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Agendamento', 'bluesendmail' ); ?></th>
							<td>
								<fieldset>
									<label for="bsm-schedule-enabled">
										<input type="checkbox" name="bsm_schedule_enabled" id="bsm-schedule-enabled" value="1" <?php checked( ! empty( $campaign->scheduled_for ) ); ?>>
										<?php _e( 'Agendar o envio para uma data futura', 'bluesendmail' ); ?>
									</label>
								</fieldset>
								<div id="bsm-schedule-fields" style="<?php echo empty( $campaign->scheduled_for ) ? 'display: none;' : ''; ?>">
									<p class="bsm-schedule-inputs">
										<input type="date" name="bsm_schedule_date" value="<?php echo ! empty( $campaign->scheduled_for ) ? get_date_from_gmt( $campaign->scheduled_for, 'Y-m-d' ) : ''; ?>">
										<input type="time" name="bsm_schedule_time" value="<?php echo ! empty( $campaign->scheduled_for ) ? get_date_from_gmt( $campaign->scheduled_for, 'H:i' ) : ''; ?>">
									</p>
									<?php
										$timezone_display = wp_timezone_string();
										if ( empty( $timezone_display ) ) {
											$offset  = get_option('gmt_offset');
											$timezone_display = 'UTC' . ($offset >= 0 ? '+' : '') . $offset;
										}
									?>
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
								<select name="bsm_lists[]" id="bsm-lists-select" multiple="multiple" style="width: 100%;">
									<?php foreach ( $all_lists as $list ) : 
										$selected = in_array( $list->list_id, $selected_lists ) ? 'selected' : '';
									?>
										<option value="<?php echo esc_attr( $list->list_id ); ?>" <?php echo $selected; ?>><?php echo esc_html( $list->name ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php _e( 'Selecione uma ou mais listas. Se nenhuma lista for selecionada, a campanha será enviada para todos os contatos inscritos.', 'bluesendmail' ); ?></p>
								<?php else : ?>
									<p><?php _e( 'Nenhuma lista de contatos encontrada. Por favor, crie uma primeiro.', 'bluesendmail' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
				
				<div class="submit" style="padding-top: 10px;">
					<?php submit_button( $submit_label, 'secondary', 'bsm_save_draft', false ); ?>
					<span style="padding-left: 10px;"></span>
					<?php if( ! $campaign || in_array($campaign->status, ['draft', 'scheduled']) ): ?>
						<?php submit_button( __( 'Enviar Agora', 'bluesendmail' ), 'primary', 'bsm_send_campaign', false, array('id' => 'bsm-send-now-button', 'onclick' => "return confirm('" . __( 'Tem a certeza que deseja enfileirar esta campanha para envio imediato?', 'bluesendmail' ) . "');") ); ?>
						<?php submit_button( __( 'Agendar Envio', 'bluesendmail' ), 'primary', 'bsm_schedule_campaign', false, array('id' => 'bsm-schedule-button', 'style' => 'display:none;', 'onclick' => "return confirm('" . __( 'Tem a certeza que deseja agendar esta campanha para o horário selecionado?', 'bluesendmail' ) . "');") ); ?>
					<?php endif; ?>
				</div>

			</form>
		</div>
		<?php
	}

	public function render_contacts_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'new' === $action || 'edit' === $action ) {
			$this->render_add_edit_contact_page();
		} else {
			$this->render_contacts_list_page();
		}
	}

	public function render_contacts_list_page() {
		$contacts_table = new BlueSendMail_Contacts_List_Table();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Contatos', 'bluesendmail' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-contacts&action=new' ) ); ?>" class="page-title-action"><?php echo esc_html__( 'Adicionar Novo', 'bluesendmail' ); ?></a>
			<hr class="wp-header-end">

			<form method="post">
				<?php
				$contacts_table->prepare_items();
				$contacts_table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function render_add_edit_contact_page() {
		global $wpdb;
		$table_contacts = $wpdb->prefix . 'bluesendmail_contacts';
		$table_lists = $wpdb->prefix . 'bluesendmail_lists';
		$table_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';

		$contact_id = isset( $_GET['contact'] ) ? absint( $_GET['contact'] ) : 0;
		$contact    = null;
		$contact_list_ids = array();

		if ( $contact_id ) {
			$contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_contacts WHERE contact_id = %d", $contact_id ) );
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT list_id FROM $table_contact_lists WHERE contact_id = %d", $contact_id ), ARRAY_A );
			if ( $results ) {
				$contact_list_ids = wp_list_pluck( $results, 'list_id' );
			}
		}

		$all_lists = $wpdb->get_results( "SELECT list_id, name FROM $table_lists ORDER BY name ASC" );

		$page_title = $contact ? __( 'Editar Contato', 'bluesendmail' ) : __( 'Adicionar Novo Contato', 'bluesendmail' );
		$button_label = $contact ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Adicionar Contato', 'bluesendmail' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $page_title ); ?></h1>
			<form method="post">
				<table class="form-table" role="presentation">
					<tbody>
						<tr class="form-field form-required">
							<th scope="row"><label for="email"><?php _e( 'E-mail', 'bluesendmail' ); ?> <span class="description">(obrigatório)</span></label></th>
							<td><input name="email" type="email" id="email" value="<?php echo esc_attr( $contact->email ?? '' ); ?>" class="regular-text" required></td>
						</tr>
						<tr class="form-field">
							<th scope="row"><label for="first_name"><?php _e( 'Nome', 'bluesendmail' ); ?></label></th>
							<td><input name="first_name" type="text" id="first_name" value="<?php echo esc_attr( $contact->first_name ?? '' ); ?>" class="regular-text"></td>
						</tr>
						<tr class="form-field">
							<th scope="row"><label for="last_name"><?php _e( 'Sobrenome', 'bluesendmail' ); ?></label></th>
							<td><input name="last_name" type="text" id="last_name" value="<?php echo esc_attr( $contact->last_name ?? '' ); ?>" class="regular-text"></td>
						</tr>
						<tr class="form-field">
							<th scope="row"><label for="company"><?php _e( 'Empresa', 'bluesendmail' ); ?></label></th>
							<td><input name="company" type="text" id="company" value="<?php echo esc_attr( $contact->company ?? '' ); ?>" class="regular-text"></td>
						</tr>
						<tr class="form-field">
							<th scope="row"><label for="job_title"><?php _e( 'Cargo', 'bluesendmail' ); ?></label></th>
							<td><input name="job_title" type="text" id="job_title" value="<?php echo esc_attr( $contact->job_title ?? '' ); ?>" class="regular-text"></td>
						</tr>
						<tr class="form-field">
							<th scope="row"><label for="segment"><?php _e( 'Segmento', 'bluesendmail' ); ?></label></th>
							<td><input name="segment" type="text" id="segment" value="<?php echo esc_attr( $contact->segment ?? '' ); ?>" class="regular-text"></td>
						</tr>
						 <tr class="form-field">
							<th scope="row"><label for="status"><?php _e( 'Status', 'bluesendmail' ); ?></label></th>
							<td>
								<select name="status" id="status">
									<option value="subscribed" <?php selected( $contact->status ?? 'subscribed', 'subscribed' ); ?>><?php _e( 'Inscrito', 'bluesendmail' ); ?></option>
									<option value="unsubscribed" <?php selected( $contact->status ?? '', 'unsubscribed' ); ?>><?php _e( 'Não Inscrito', 'bluesendmail' ); ?></option>
									<option value="pending" <?php selected( $contact->status ?? '', 'pending' ); ?>><?php _e( 'Pendente', 'bluesendmail' ); ?></option>
								</select>
							</td>
						</tr>
						<?php if ( ! empty( $all_lists ) ) : ?>
						<tr class="form-field">
							<th scope="row"><?php _e( 'Listas', 'bluesendmail' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><span><?php _e( 'Listas', 'bluesendmail' ); ?></span></legend>
									<?php foreach ( $all_lists as $list ) : ?>
										<label for="list-<?php echo esc_attr( $list->list_id ); ?>">
											<input type="checkbox" name="lists[]" id="list-<?php echo esc_attr( $list->list_id ); ?>" value="<?php echo esc_attr( $list->list_id ); ?>" <?php checked( in_array( $list->list_id, $contact_list_ids ) ); ?>>
											<?php echo esc_html( $list->name ); ?>
										</label><br>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
				
				<input type="hidden" name="contact_id" value="<?php echo esc_attr( $contact_id ); ?>" />
				<?php wp_nonce_field( 'bsm_save_contact_nonce_action', 'bsm_save_contact_nonce_field' ); ?>
				<?php submit_button( $button_label, 'primary', 'bsm_save_contact' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_lists_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'new' === $action || 'edit' === $action ) {
			$this->render_add_edit_list_page();
		} else {
			$this->render_lists_list_page();
		}
	}
	
	public function render_lists_list_page() {
		$lists_table = new BlueSendMail_Lists_List_Table();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Listas de Contatos', 'bluesendmail' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-lists&action=new' ) ); ?>" class="page-title-action"><?php echo esc_html__( 'Adicionar Nova', 'bluesendmail' ); ?></a>
			<hr class="wp-header-end">

			<form method="post">
				<?php
				$lists_table->prepare_items();
				$lists_table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function render_add_edit_list_page() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'bluesendmail_lists';
		$list_id = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0;
		$list    = null;

		if ( $list_id ) {
			$list = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE list_id = %d", $list_id ) );
		}

		$page_title = $list ? __( 'Editar Lista', 'bluesendmail' ) : __( 'Adicionar Nova Lista', 'bluesendmail' );
		$button_label = $list ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Adicionar Lista', 'bluesendmail' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $page_title ); ?></h1>
			<form method="post">
				<table class="form-table" role="presentation">
					<tbody>
						<tr class="form-field form-required">
							<th scope="row"><label for="name"><?php _e( 'Nome da Lista', 'bluesendmail' ); ?> <span class="description">(obrigatório)</span></label></th>
							<td><input name="name" type="text" id="name" value="<?php echo esc_attr( $list->name ?? '' ); ?>" class="regular-text" required></td>
						</tr>
						<tr class="form-field">
							<th scope="row"><label for="description"><?php _e( 'Descrição', 'bluesendmail' ); ?></label></th>
							<td><textarea name="description" id="description" rows="5" cols="50" class="large-text"><?php echo esc_textarea( $list->description ?? '' ); ?></textarea></td>
						</tr>
					</tbody>
				</table>
				
				<input type="hidden" name="list_id" value="<?php echo esc_attr( $list_id ); ?>" />
				<?php wp_nonce_field( 'bsm_save_list_nonce_action', 'bsm_save_list_nonce_field' ); ?>
				<?php submit_button( $button_label, 'primary', 'bsm_save_list' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_forms_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'new' === $action || 'edit' === $action ) {
			$this->render_add_edit_form_page();
		} else {
			$this->render_forms_list_page();
		}
	}
	
	public function render_forms_list_page() {
		$forms_table = new BlueSendMail_Forms_List_Table();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Formulários de Inscrição', 'bluesendmail' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-forms&action=new' ) ); ?>" class="page-title-action"><?php echo esc_html__( 'Adicionar Novo', 'bluesendmail' ); ?></a>
			<hr class="wp-header-end">

			<form method="post">
				<?php
				$forms_table->prepare_items();
				$forms_table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function render_add_edit_form_page() {
		global $wpdb;
		$form_id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		$form    = null;

		if ( $form_id ) {
			$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_forms WHERE form_id = %d", $form_id ) );
		}
		$enabled_fields = ! empty( $form->fields ) ? json_decode( $form->fields, true ) : array();

		$page_title = $form ? __( 'Editar Formulário', 'bluesendmail' ) : __( 'Criar Novo Formulário', 'bluesendmail' );
		
		// Define todos os campos de contato disponíveis para seleção
		$available_fields = array(
			'first_name' => __( 'Primeiro Nome', 'bluesendmail' ),
			'last_name'  => __( 'Sobrenome', 'bluesendmail' ),
			'company'    => __( 'Empresa', 'bluesendmail' ),
			'job_title'  => __( 'Cargo', 'bluesendmail' ),
			'segment'    => __( 'Segmento', 'bluesendmail' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $page_title ); ?></h1>
			<form method="post">
				<input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
				<?php wp_nonce_field( 'bsm_save_form_action', 'bsm_save_form_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="bsm_form_name"><?php _e( 'Nome Interno', 'bluesendmail' ); ?></label></th>
						<td><input type="text" name="bsm_form_name" id="bsm_form_name" class="regular-text" value="<?php echo esc_attr( $form->name ?? '' ); ?>" required>
						<p class="description"><?php _e( 'Para sua referência. Não será exibido publicamente.', 'bluesendmail' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="bsm_form_title"><?php _e( 'Título do Formulário', 'bluesendmail' ); ?></label></th>
						<td><input type="text" name="bsm_form_title" id="bsm_form_title" class="regular-text" value="<?php echo esc_attr( $form->title ?? '' ); ?>">
						<p class="description"><?php _e( 'Exibido no topo do formulário (opcional).', 'bluesendmail' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="bsm_form_description"><?php _e( 'Descrição', 'bluesendmail' ); ?></label></th>
						<td><textarea name="bsm_form_description" id="bsm_form_description" class="regular-text"><?php echo esc_textarea( $form->description ?? '' ); ?></textarea>
						<p class="description"><?php _e( 'Exibido abaixo do título (opcional).', 'bluesendmail' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Campos do Formulário', 'bluesendmail' ); ?></th>
						<td>
							<fieldset>
								<p class="description"><?php _e( 'O campo de E-mail é sempre obrigatório. Selecione os campos adicionais que deseja incluir no formulário.', 'bluesendmail' ); ?></p>
								<?php foreach ( $available_fields as $field_key => $field_label ) : ?>
									<label>
										<input type="checkbox" name="bsm_form_fields[<?php echo esc_attr( $field_key ); ?>]" value="1" <?php checked( ! empty( $enabled_fields[ $field_key ] ) ); ?>>
										<?php echo esc_html( $field_label ); ?>
									</label><br>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bsm_submit_button_text"><?php _e( 'Texto do Botão', 'bluesendmail' ); ?></label></th>
						<td><input type="text" name="bsm_submit_button_text" id="bsm_submit_button_text" class="regular-text" value="<?php echo esc_attr( $form->submit_button_text ?? __( 'Inscrever-se', 'bluesendmail' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bsm_success_message"><?php _e( 'Mensagem de Sucesso', 'bluesendmail' ); ?></label></th>
						<td><textarea name="bsm_success_message" id="bsm_success_message" class="regular-text"><?php echo esc_textarea( $form->success_message ?? __( 'Obrigado pela sua inscrição!', 'bluesendmail' ) ); ?></textarea>
						<p class="description"><?php _e( 'Exibida após o envio bem-sucedido do formulário.', 'bluesendmail' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="bsm_list_id"><?php _e( 'Adicionar à Lista', 'bluesendmail' ); ?></label></th>
						<td>
							<?php
							global $wpdb;
							$lists = $wpdb->get_results( "SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC" );
							if ( ! empty( $lists ) ) {
								echo '<select name="bsm_list_id" id="bsm_list_id" required>';
								echo '<option value="">' . esc_html__( 'Selecione uma lista', 'bluesendmail' ) . '</option>';
								foreach ( $lists as $list ) {
									printf( '<option value="%d" %s>%s</option>', esc_attr( $list->list_id ), selected( $list->list_id, $form->list_id ?? 0, false ), esc_html( $list->name ) );
								}
								echo '</select>';
							} else {
								echo '<p>' . __( 'Nenhuma lista encontrada. Por favor, crie uma primeiro.', 'bluesendmail' ) . '</p>';
							}
							?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Salvar Formulário', 'bluesendmail' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function render_import_page() {
		global $wpdb;
		$csv_headers = (array) get_transient( 'bsm_import_headers_' . get_current_user_id() );
		$file_path   = get_transient( 'bsm_import_filepath_' . get_current_user_id() );
		$list_id     = get_transient( 'bsm_import_list_id_' . get_current_user_id() );
	
		$step = ( ! empty( $csv_headers ) && ! empty( $file_path ) && ! empty( $list_id ) ) ? 2 : 1;
		?>
		<div class="wrap">
			<h1><?php _e( 'Importar Contatos', 'bluesendmail' ); ?></h1>
	
			<?php if ( 1 === $step ) : ?>
				<h2><?php _e( 'Passo 1 de 2: Enviar Arquivo CSV', 'bluesendmail' ); ?></h2>
				<p><?php _e( 'Envie um arquivo CSV para importar contatos. O arquivo deve conter uma linha de cabeçalho com os nomes das colunas (ex: email, nome).', 'bluesendmail' ); ?></p>
				
				<form method="post" enctype="multipart/form-data">
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><?php _e( 'Arquivo CSV', 'bluesendmail' ); ?></th>
							<td><input type="file" name="bsm_import_file" accept=".csv" required /></td>
						</tr>
						<?php
						$all_lists = $wpdb->get_results( "SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC" );
						if ( ! empty( $all_lists ) ) : ?>
						<tr valign="top">
							<th scope="row"><?php _e( 'Adicionar à Lista', 'bluesendmail' ); ?></th>
							<td>
								<select name="bsm_import_list_id" required>
									<option value=""><?php _e( 'Selecione uma lista', 'bluesendmail' ); ?></option>
									<?php foreach ( $all_lists as $list ) : ?>
										<option value="<?php echo esc_attr( $list->list_id ); ?>"><?php echo esc_html( $list->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<?php else : ?>
						<tr valign="top">
							<td colspan="2">
								<p><?php _e( 'Nenhuma lista encontrada. Por favor, <a href="admin.php?page=bluesendmail-lists&action=new">crie uma lista</a> antes de importar contatos.', 'bluesendmail' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>
					</table>
					<?php wp_nonce_field( 'bsm_import_step1_nonce', 'bsm_import_step1_nonce_field' ); ?>
					<?php submit_button( __( 'Próximo Passo', 'bluesendmail' ), 'primary', 'bsm_import_step1', true, ( empty( $all_lists ) ? array( 'disabled' => 'disabled' ) : null ) ); ?>
				</form>
	
			<?php else : // Step 2 ?>
				<h2><?php _e( 'Passo 2 de 2: Mapear Colunas', 'bluesendmail' ); ?></h2>
				<p><?php _e( 'Associe as colunas do seu arquivo CSV aos campos de contato do BlueSendMail. O campo de e-mail é obrigatório.', 'bluesendmail' ); ?></p>
	
				<form method="post">
					<table class="form-table">
						<?php
						$db_fields = array(
							'email'      => __( 'E-mail (Obrigatório)', 'bluesendmail' ),
							'first_name' => __( 'Primeiro Nome', 'bluesendmail' ),
							'last_name'  => __( 'Sobrenome', 'bluesendmail' ),
							'company'    => __( 'Empresa', 'bluesendmail' ),
							'job_title'  => __( 'Cargo', 'bluesendmail' ),
						);
						?>
						<?php foreach ( $db_fields as $db_key => $db_label ) : ?>
						<tr valign="top">
							<th scope="row"><label for="map_<?php echo esc_attr( $db_key ); ?>"><?php echo esc_html( $db_label ); ?></label></th>
							<td>
								<select name="bsm_column_map[<?php echo esc_attr( $db_key ); ?>]" id="map_<?php echo esc_attr( $db_key ); ?>" <?php echo ( 'email' === $db_key ) ? 'required' : ''; ?>>
									<option value=""><?php _e( 'Não importar', 'bluesendmail' ); ?></option>
									<?php foreach ( $csv_headers as $index => $header ) : ?>
										<option value="<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $header ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<?php endforeach; ?>
					</table>
					<?php wp_nonce_field( 'bsm_import_step2_nonce', 'bsm_import_step2_nonce_field' ); ?>
					<?php submit_button( __( 'Iniciar Importação', 'bluesendmail' ), 'primary', 'bsm_import_step2' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_reports_page() {
		global $wpdb;
		$campaign_id = isset( $_REQUEST['campaign_id'] ) ? absint( $_REQUEST['campaign_id'] ) : 0;
	
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
			<?php
			if ( $campaign_id ) {
				$campaign_title = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id = %d", $campaign_id ) );
				echo esc_html__( 'Relatório da Campanha:', 'bluesendmail' ) . ' ' . esc_html( $campaign_title );
			} else {
				echo esc_html__( 'Relatórios', 'bluesendmail' );
			}
			?>
			</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-campaigns' ) ); ?>" class="page-title-action"><?php echo esc_html__( 'Voltar para Campanhas', 'bluesendmail' ); ?></a>
			<hr class="wp-header-end">
	
			<?php if ( $campaign_id ) : ?>
				<?php $this->render_report_content( $campaign_id ); ?>
			<?php else : ?>
				<?php $this->render_reports_selection_page(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_report_content( $campaign_id ) {
		?>
		<div id="bsm-reports-summary" style="margin-top: 20px;">
			<div class="bsm-dashboard-grid">
				<div class="bsm-card">
					<div class="bsm-chart-container" style="max-width: 450px; margin: auto;">
						<canvas id="bsm-report-chart"></canvas>
					</div>
				</div>
			</div>
		</div>
	
		<div class="bsm-report-tabs">
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
			echo '<p>' . esc_html__( 'Nenhuma campanha foi enviada ainda. Assim que enviar uma, poderá ver os relatórios aqui.', 'bluesendmail' ) . '</p>';
			return;
		}
		?>
		<div class="bsm-card" style="margin-top:20px;">
			<h3 class="bsm-card-title"><?php esc_html_e( 'Selecione uma Campanha', 'bluesendmail' ); ?></h3>
			<p><?php esc_html_e( 'Escolha uma campanha abaixo para visualizar o seu relatório detalhado.', 'bluesendmail' ); ?></p>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Título da Campanha', 'bluesendmail' ); ?></th>
						<th><?php esc_html_e( 'Data de Envio', 'bluesendmail' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sent_campaigns as $campaign ) : ?>
						<tr>
							<td>
								<strong>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-reports&campaign_id=' . $campaign->campaign_id ) ); ?>">
										<?php echo esc_html( $campaign->title ); ?>
									</a>
								</strong>
							</td>
							<td><?php echo esc_html( get_date_from_gmt( $campaign->sent_at, 'd/m/Y H:i' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function render_logs_page() {
		$logs_table = new BlueSendMail_Logs_List_Table();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Logs do Sistema', 'bluesendmail' ); ?></h1>
			<hr class="wp-header-end">
			<form method="post">
				<?php
				$logs_table->prepare_items();
				$logs_table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		?>
		<div class="wrap">
			<h1><?php _e( 'Configurações do BlueSendMail', 'bluesendmail' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=bluesendmail-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Geral', 'bluesendmail' ); ?></a>
				<a href="?page=bluesendmail-settings&tab=sending" class="nav-tab <?php echo $active_tab == 'sending' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Envio', 'bluesendmail' ); ?></a>
				<a href="?page=bluesendmail-settings&tab=tracking" class="nav-tab <?php echo $active_tab == 'tracking' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Rastreamento', 'bluesendmail' ); ?></a>
				<a href="?page=bluesendmail-settings&tab=subscription" class="nav-tab <?php echo $active_tab == 'subscription' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Inscrição', 'bluesendmail' ); ?></a>
			</h2>
	
			<form method="post" action="options.php">
				<?php
				settings_fields( 'bluesendmail_settings_group' );
				
				if ( 'sending' === $active_tab ) {
					do_settings_sections( 'bluesendmail-settings-sending' );
				} elseif ( 'tracking' === $active_tab ) {
					do_settings_sections( 'bluesendmail-settings-tracking' );
				} elseif ( 'subscription' === $active_tab ) {
					do_settings_sections( 'bluesendmail-settings-subscription' );
				} else {
					do_settings_sections( 'bluesendmail-settings-general' );
				}
				
				submit_button();
				?>
			</form>
	
			<?php if ( 'sending' === $active_tab ) : ?>
			<hr>
			<h2><?php _e( 'Testar Envio', 'bluesendmail' ); ?></h2>
			<p><?php _e( 'Use esta ferramenta para verificar se as suas configurações de envio estão funcionando corretamente.', 'bluesendmail' ); ?></p>
			<form method="post">
				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label for="bsm_test_email_recipient"><?php _e( 'Enviar para', 'bluesendmail' ); ?></label>
						</th>
						<td>
							<input type="email" id="bsm_test_email_recipient" name="bsm_test_email_recipient" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" required>
							<p class="description"><?php _e( 'O e-mail de teste será enviado para este endereço.', 'bluesendmail' ); ?></p>
						</td>
					</tr>
				</table>
				<?php wp_nonce_field( 'bsm_send_test_email_action', 'bsm_send_test_email_nonce' ); ?>
				<?php submit_button( __( 'Enviar Teste', 'bluesendmail' ), 'secondary', 'bsm_send_test_email' ); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function register_settings() {
		register_setting( 'bluesendmail_settings_group', 'bluesendmail_settings' );
	
		// General Tab
		add_settings_section( 'bsm_general_section', __( 'Configurações Gerais de Remetente', 'bluesendmail' ), null, 'bluesendmail-settings-general' );
		add_settings_field( 'bsm_from_name', __( 'Nome do Remetente', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings-general', 'bsm_general_section', array( 'id' => 'from_name', 'description' => __( 'O nome que aparecerá no campo "De:" do e-mail.', 'bluesendmail' ) ) );
		add_settings_field( 'bsm_from_email', __( 'E-mail do Remetente', 'bluesendmail' ), array( $this, 'render_email_field' ), 'bluesendmail-settings-general', 'bsm_general_section', array( 'id' => 'from_email', 'description' => __( 'O e-mail que aparecerá no campo "De:" do e-mail.', 'bluesendmail' ) ) );
	
		// Sending Tab
		add_settings_section( 'bsm_mailer_section', __( 'Configurações do Disparador', 'bluesendmail' ), null, 'bluesendmail-settings-sending' );
		add_settings_field( 'bsm_mailer_type', __( 'Método de Envio', 'bluesendmail' ), array( $this, 'render_select_field' ), 'bluesendmail-settings-sending', 'bsm_mailer_section', array( 'id' => 'mailer_type', 'options' => array( 'wp_mail' => __( 'E-mail Padrão do WordPress', 'bluesendmail' ), 'smtp' => __( 'SMTP', 'bluesendmail' ), 'sendgrid' => __( 'SendGrid', 'bluesendmail' ) ) ) );
		add_settings_field( 'bsm_smtp_host', __( 'Host SMTP', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings-sending', 'bsm_mailer_section', array( 'id' => 'smtp_host' ) );
		add_settings_field( 'bsm_smtp_port', __( 'Porta SMTP', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings-sending', 'bsm_mailer_section', array( 'id' => 'smtp_port' ) );
		add_settings_field( 'bsm_smtp_encryption', __( 'Encriptação SMTP', 'bluesendmail' ), array( $this, 'render_select_field' ), 'bluesendmail-settings-sending', 'bsm_mailer_section', array( 'id' => 'smtp_encryption', 'options' => array( 'none' => 'Nenhuma', 'ssl' => 'SSL', 'tls' => 'TLS' ) ) );
		add_settings_field( 'bsm_smtp_user', __( 'Utilizador SMTP', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings-sending', 'bsm_mailer_section', array( 'id' => 'smtp_user' ) );
		add_settings_field( 'bsm_smtp_pass', __( 'Palavra-passe SMTP', 'bluesendmail' ), array( $this, 'render_password_field' ), 'bluesendmail-settings-sending', 'bsm_mailer_section', array( 'id' => 'smtp_pass' ) );
		add_settings_field( 'bsm_sendgrid_api_key', __( 'Chave da API do SendGrid', 'bluesendmail' ), array( $this, 'render_password_field' ), 'bluesendmail-settings-sending', 'bsm_mailer_section', array( 'id' => 'sendgrid_api_key' ) );
	
		add_settings_section( 'bsm_cron_section', __( 'Velocidade de Envio', 'bluesendmail' ), null, 'bluesendmail-settings-sending' );
		add_settings_field( 'bsm_cron_interval', __( 'Intervalo de Envio', 'bluesendmail' ), array( $this, 'render_select_field' ), 'bluesendmail-settings-sending', 'bsm_cron_section', array( 'id' => 'cron_interval', 'description' => __( 'Selecione a frequência com que o sistema irá processar a fila de envio.', 'bluesendmail' ), 'options' => wp_get_schedules() ) );

		// Tracking Tab
		add_settings_section( 'bsm_tracking_section', __( 'Configurações de Rastreamento', 'bluesendmail' ), null, 'bluesendmail-settings-tracking' );
		add_settings_field( 'bsm_enable_open_tracking', __( 'Rastreamento de Abertura', 'bluesendmail' ), array( $this, 'render_checkbox_field' ), 'bluesendmail-settings-tracking', 'bsm_tracking_section', array( 'id' => 'enable_open_tracking', 'description' => __( 'Ativar o rastreamento de aberturas de e-mail.', 'bluesendmail' ) ) );
		add_settings_field( 'bsm_enable_click_tracking', __( 'Rastreamento de Cliques', 'bluesendmail' ), array( $this, 'render_checkbox_field' ), 'bluesendmail-settings-tracking', 'bsm_tracking_section', array( 'id' => 'enable_click_tracking', 'description' => __( 'Ativar o rastreamento de cliques em links.', 'bluesendmail' ) ) );
	
		// Subscription Tab
		add_settings_section( 'bsm_subscription_section', __( 'Configurações de Inscrição', 'bluesendmail' ), null, 'bluesendmail-settings-subscription' );
		add_settings_field( 'bsm_enable_double_opt_in', __( 'Double Opt-in', 'bluesendmail' ), array( $this, 'render_checkbox_field' ), 'bluesendmail-settings-subscription', 'bsm_subscription_section', array( 'id' => 'enable_double_opt_in', 'description' => __( 'Ativar a confirmação de inscrição por e-mail.', 'bluesendmail' ) ) );
		add_settings_field( 'bsm_confirmation_subject', __( 'Assunto do E-mail de Confirmação', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings-subscription', 'bsm_subscription_section', array( 'id' => 'confirmation_subject', 'placeholder' => __( 'Confirme a sua inscrição', 'bluesendmail' ) ) );
		add_settings_field( 'bsm_confirmation_content', __( 'Conteúdo do E-mail de Confirmação', 'bluesendmail' ), array( $this, 'render_editor_field' ), 'bluesendmail-settings-subscription', 'bsm_subscription_section', array( 'id' => 'confirmation_content', 'description' => __( 'Crie o conteúdo do e-mail. Use a tag <code>{{confirmation_link}}</code> para inserir o link de confirmação obrigatório.', 'bluesendmail' ) ) );
		add_settings_field( 'bsm_thank_you_page', __( 'Página de Agradecimento', 'bluesendmail' ), array( $this, 'render_page_dropdown_field' ), 'bluesendmail-settings-subscription', 'bsm_subscription_section', array( 'id' => 'thank_you_page', 'description' => __( 'Selecione a página para a qual o usuário será redirecionado após confirmar o e-mail.', 'bluesendmail' ) ) );
	}

	public function render_checkbox_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : 0;
		echo '<label><input type="checkbox" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="1" ' . checked( 1, $value, false ) . '>';
		if( ! empty( $args['description'] ) ) {
			echo ' ' . esc_html( $args['description'] ) . '</label>';
		}
	}

	public function render_text_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : '';
		$placeholder = $args['placeholder'] ?? '';
		echo '<input type="text" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="' . esc_attr( $placeholder ) . '">';
		if( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}
	
	public function render_email_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : '';
		echo '<input type="email" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="' . esc_attr( $value ) . '" class="regular-text">';
		if( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	public function render_password_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : '';
		echo '<input type="password" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="' . esc_attr( $value ) . '" class="regular-text">';
		if( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	public function render_select_field( $args ) {
		$default = 'every_five_minutes';
		if ($args['id'] === 'mailer_type') { $default = 'wp_mail'; }
		if ($args['id'] === 'smtp_encryption') { $default = 'tls'; }
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : $default;
		
		echo '<select id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']">';
		foreach( $args['options'] as $option_key => $option_value ) {
			$label = is_array( $option_value ) ? $option_value['display'] : $option_value;
			echo '<option value="' . esc_attr( $option_key ) . '" ' . selected( $value, $option_key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	public function render_editor_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : '';
		wp_editor( $value, 'bsm_' . esc_attr( $args['id'] ), array( 'textarea_name' => 'bluesendmail_settings[' . esc_attr( $args['id'] ) . ']' ) );
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}
	
	public function render_page_dropdown_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : 0;
		wp_dropdown_pages( array(
			'name'             => 'bluesendmail_settings[' . esc_attr( $args['id'] ) . ']',
			'selected'         => $value,
			'show_option_none' => __( '&mdash; Selecione uma página &mdash;', 'bluesendmail' ),
		) );
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		$is_plugin_page = ( $screen && strpos( $screen->id, 'bluesendmail' ) !== false );
	
		if ( ! $is_plugin_page ) {
			return;
		}
	
		wp_enqueue_style( 'bluesendmail-admin-styles', BLUESENDMAIL_PLUGIN_URL . 'assets/css/admin.css', array(), BLUESENDMAIL_VERSION );
	
		$is_campaign_editor = isset( $_GET['page'] ) && ( $_GET['page'] === 'bluesendmail-new-campaign' || ( $_GET['page'] === 'bluesendmail-campaigns' && ( $_GET['action'] ?? '' ) === 'edit' ) );
		$is_reports_page = isset( $_GET['page'] ) && 'bluesendmail-reports' === $_GET['page'];
	
		if ($is_campaign_editor) {
			wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
			wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true);
		}
		if ( $is_reports_page ) {
			wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.1', true );
		}
	
		$js_dependencies = array('jquery');
		if ($is_campaign_editor) {
			$js_dependencies[] = 'wp-editor';
			$js_dependencies[] = 'select2';
		}
		if ( $is_reports_page ) {
			$js_dependencies[] = 'chartjs';
		}
	
		wp_enqueue_script( 'bluesendmail-admin-script', BLUESENDMAIL_PLUGIN_URL . 'assets/js/admin.js', $js_dependencies, BLUESENDMAIL_VERSION, true );
	
		$script_data = array(
			'is_campaign_editor' => (bool) $is_campaign_editor,
			'is_reports_page'    => (bool) $is_reports_page,
		);
	
		if ( $is_reports_page && ! empty( $_GET['campaign_id'] ) ) {
			global $wpdb;
			$campaign_id   = absint( $_GET['campaign_id'] );
			$sent          = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE campaign_id = %d", $campaign_id ) );
			$unique_opens  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id WHERE q.campaign_id = %d", $campaign_id ) );
			$unique_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks WHERE campaign_id = %d", $campaign_id ) );

			$script_data['chart_data'] = array(
				'sent'   => (int) $sent,
				'opens'  => (int) $unique_opens,
				'clicks' => (int) $unique_clicks,
				'labels' => array(
					'not_opened' => __( 'Não Aberto', 'bluesendmail' ),
					'opened'     => __( 'Abertura Única', 'bluesendmail' ),
					'clicked'    => __( 'Clique Único (dentro dos que abriram)', 'bluesendmail' ),
				),
			);
		}
	
		wp_localize_script( 'bluesendmail-admin-script', 'bsm_admin_data', $script_data );
	}

	public function enqueue_frontend_assets() {
		wp_enqueue_style( 'bluesendmail-frontend-styles', BLUESENDMAIL_PLUGIN_URL . 'assets/css/frontend.css', array(), BLUESENDMAIL_VERSION );
	}
	
	public function handle_actions() {
		$page = $_GET['page'] ?? '';
	
		if ( 'bluesendmail-settings' === $page && isset( $_POST['bsm_send_test_email'] ) ) {
			$this->handle_send_test_email();
		}
		
		if ( ('bluesendmail-campaigns' === $page || 'bluesendmail-new-campaign' === $page) && (isset($_POST['bsm_save_draft']) || isset($_POST['bsm_send_campaign']) || isset($_POST['bsm_schedule_campaign'])) ) {
			$this->handle_save_campaign();
		}
	
		if ( 'bluesendmail-contacts' === $page ) {
			if ( 'delete' === ( $_GET['action'] ?? '' ) && isset( $_GET['contact'] ) ) {
				$this->handle_delete_contact();
			}
			if ( isset( $_POST['bsm_save_contact'] ) ) {
				$this->handle_save_contact();
			}
		}
	
		if ( 'bluesendmail-lists' === $page ) {
			if ( 'delete' === ( $_GET['action'] ?? '' ) && isset( $_GET['list'] ) ) {
				$this->handle_delete_list();
			}
			if ( isset( $_POST['bsm_save_list'] ) ) {
				$this->handle_save_list();
			}
		}
	
		if ( 'bluesendmail-import' === $page ) {
			if ( isset( $_POST['bsm_import_step1'] ) ) {
				$this->handle_import_step1();
			}
			if ( isset( $_POST['bsm_import_step2'] ) ) {
				$this->handle_import_step2();
			}
		}
	
		if ( 'bluesendmail-forms' === $page ) {
			if ( isset( $_POST['submit'] ) ) {
				$this->handle_save_form();
			}
			if ( 'delete' === ( $_GET['action'] ?? '' ) && isset( $_GET['form'] ) ) {
				$this->handle_delete_form();
			}
		}
	}
	
	private function handle_import_step1() {
		if ( ! isset( $_POST['bsm_import_step1_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_import_step1_nonce_field'], 'bsm_import_step1_nonce' ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}
	
		if ( empty( $_FILES['bsm_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=no-file' ) );
			exit;
		}
	
		$list_id = absint( $_POST['bsm_import_list_id'] );
		if ( ! $list_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=no-list' ) );
			exit;
		}
	
		$upload_dir = wp_upload_dir();
		$file_path = $upload_dir['basedir'] . '/bsm-import-' . get_current_user_id() . '.csv';
		if ( ! move_uploaded_file( $_FILES['bsm_import_file']['tmp_name'], $file_path ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=upload-failed' ) );
			exit;
		}
	
		if ( ( $handle = fopen( $file_path, "r" ) ) !== false ) {
			$headers = fgetcsv( $handle, 1000, "," );
			fclose( $handle );
	
			set_transient( 'bsm_import_headers_' . get_current_user_id(), $headers, HOUR_IN_SECONDS );
			set_transient( 'bsm_import_filepath_' . get_current_user_id(), $file_path, HOUR_IN_SECONDS );
			set_transient( 'bsm_import_list_id_' . get_current_user_id(), $list_id, HOUR_IN_SECONDS );
		}
	
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import' ) );
		exit;
	}
	
	private function handle_import_step2() {
		if ( ! isset( $_POST['bsm_import_step2_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_import_step2_nonce_field'], 'bsm_import_step2_nonce' ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}
	
		$user_id = get_current_user_id();
		$file_path = get_transient( 'bsm_import_filepath_' . $user_id );
		$list_id   = get_transient( 'bsm_import_list_id_' . $user_id );
		$map       = $_POST['bsm_column_map'];
	
		if ( ! $file_path || ! $list_id || ! file_exists( $file_path ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=file-expired' ) );
			exit;
		}
	
		global $wpdb;
		$table_contacts = $wpdb->prefix . 'bluesendmail_contacts';
		$table_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';
		
		$imported_count = 0;
		$skipped_count = 0;
		$row_count = 0;
	
		if ( ( $handle = fopen( $file_path, "r" ) ) !== false ) {
			while ( ( $data = fgetcsv( $handle, 1000, "," ) ) !== false ) {
				if ( $row_count++ == 0 ) continue; // Skip header
	
				$email = sanitize_email( $data[ $map['email'] ] ?? '' );
				if ( ! is_email( $email ) ) {
					$skipped_count++;
					continue;
				}
	
				$contact_data = array(
					'email'      => $email,
					'first_name' => isset($map['first_name']) ? sanitize_text_field( $data[ $map['first_name'] ] ?? '' ) : '',
					'last_name'  => isset($map['last_name']) ? sanitize_text_field( $data[ $map['last_name'] ] ?? '' ) : '',
					'company'    => isset($map['company']) ? sanitize_text_field( $data[ $map['company'] ] ?? '' ) : '',
					'job_title'  => isset($map['job_title']) ? sanitize_text_field( $data[ $map['job_title'] ] ?? '' ) : '',
					'status'     => 'subscribed'
				);
	
				$existing_contact_id = $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM $table_contacts WHERE email = %s", $email ) );
				
				if ( $existing_contact_id ) {
					$wpdb->update( $table_contacts, $contact_data, array( 'contact_id' => $existing_contact_id ) );
					$contact_id = $existing_contact_id;
				} else {
					$wpdb->insert( $table_contacts, $contact_data );
					$contact_id = $wpdb->insert_id;
				}
	
				if( $contact_id ) {
					$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table_contact_lists (contact_id, list_id) VALUES (%d, %d)", $contact_id, $list_id ) );
					$imported_count++;
				} else {
					$skipped_count++;
				}
			}
			fclose( $handle );
		}
	
		// Cleanup
		unlink( $file_path );
		delete_transient( 'bsm_import_headers_' . $user_id );
		delete_transient( 'bsm_import_filepath_' . $user_id );
		delete_transient( 'bsm_import_list_id_' . $user_id );
		
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&imported=' . $imported_count . '&skipped=' . $skipped_count ) );
		exit;
	}

	private function handle_save_form() {
		if ( ! isset( $_POST['bsm_save_form_nonce'] ) || ! wp_verify_nonce( $_POST['bsm_save_form_nonce'], 'bsm_save_form_action' ) ) {
			wp_die( 'Falha na verificação de segurança.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para esta ação.' );
		}

		global $wpdb;
		$table_forms = $wpdb->prefix . 'bluesendmail_forms';
		$form_id     = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		$data = array(
			'name'               => sanitize_text_field( $_POST['bsm_form_name'] ),
			'title'              => sanitize_text_field( $_POST['bsm_form_title'] ),
			'description'        => sanitize_textarea_field( $_POST['bsm_form_description'] ),
			'fields'             => json_encode( $_POST['bsm_form_fields'] ?? array() ),
			'submit_button_text' => sanitize_text_field( $_POST['bsm_submit_button_text'] ),
			'success_message'    => sanitize_textarea_field( $_POST['bsm_success_message'] ),
			'list_id'            => absint( $_POST['bsm_list_id'] ),
		);

		if ( $form_id ) {
			$wpdb->update( $table_forms, $data, array( 'form_id' => $form_id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', 1 );
			$wpdb->insert( $table_forms, $data );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-forms&form-saved=true' ) );
		exit;
	}

	private function handle_delete_form() {
		$form_id = absint( $_GET['form'] );
		$nonce   = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'bsm_delete_form_' . $form_id ) ) {
			wp_die( 'Falha na verificação de segurança.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para esta ação.' );
		}
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'bluesendmail_forms', array( 'form_id' => $form_id ) );
		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-forms&form-deleted=true' ) );
		exit;
	}

	private function handle_bulk_delete_forms() {
		// Implementar se necessário
	}

	public function handle_public_actions() {
		if ( isset( $_POST['bsm_form_submission_nonce'] ) ) {
			$this->handle_form_submission();
		}
		
		if ( isset( $_GET['bsm_action'] ) ) {
			switch ( $_GET['bsm_action'] ) {
				case 'unsubscribe':
					$this->handle_unsubscribe_request();
					break;
				case 'track_open':
					$this->handle_tracking_pixel();
					break;
				case 'track_click':
					$this->handle_click_tracking();
					break;
				case 'confirm':
					$this->handle_confirmation_request();
					break;
			}
		}
	}
	
	private function handle_confirmation_request() {
		$token = isset( $_GET['token'] ) ? sanitize_key( $_GET['token'] ) : '';
		if ( empty( $token ) ) {
			wp_die( 'Token de confirmação inválido ou ausente.', 'Erro' );
		}
		
		global $wpdb;
		$table_contacts = $wpdb->prefix . 'bluesendmail_contacts';
		$contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_contacts WHERE confirmation_token = %s", $token ) );
		
		if ( ! $contact ) {
			wp_die( 'Este link de confirmação não é válido ou já foi utilizado.', 'Erro' );
		}
		
		$wpdb->update(
			$table_contacts,
			array( 'status' => 'subscribed', 'confirmation_token' => null ),
			array( 'contact_id' => $contact->contact_id )
		);
		
		$thank_you_page_id = $this->options['thank_you_page'] ?? 0;
		if ( $thank_you_page_id && get_post_status( $thank_you_page_id ) === 'publish' ) {
			wp_safe_redirect( get_permalink( $thank_you_page_id ) );
		} else {
			wp_die( 'Obrigado! A sua inscrição foi confirmada com sucesso.', 'Inscrição Confirmada' );
		}
		exit;
	}

	public function register_shortcode() {
        add_shortcode( 'bluesendmail_form', array( $this, 'render_shortcode_form' ) );
    }

	public function render_shortcode_form( $atts ) {
        global $wpdb;
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'bluesendmail_form' );
        $form_id = absint( $atts['id'] );
        if ( ! $form_id ) return '';

        $form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_forms WHERE form_id = %d", $form_id ) );
        if ( ! $form ) return '';

        ob_start();
		
		// Exibir mensagens de sucesso/erro
		if ( isset( $_GET['bsm_form_status'] ) && absint( $_GET['bsm_form_id'] ) === $form_id ) {
			if ( 'success' === $_GET['bsm_form_status'] ) {
				$message = ! empty( $this->options['enable_double_opt_in'] ) ? __( 'Obrigado! Enviamos um e-mail de confirmação para si.', 'bluesendmail' ) : $form->success_message;
				echo '<div class="bsm-form-message success">' . esc_html( $message ) . '</div>';
			} else {
				$error_message = isset( $_GET['bsm_error'] ) && 'email_exists' === $_GET['bsm_error'] 
					? __( 'Este e-mail já está inscrito ou pendente de confirmação.', 'bluesendmail' ) 
					: __( 'Ocorreu um erro. Por favor, tente novamente.', 'bluesendmail' );
				echo '<div class="bsm-form-message error">' . esc_html( $error_message ) . '</div>';
			}
		}

        $enabled_fields = json_decode( $form->fields, true );
		$available_fields = array(
			'first_name' => __( 'Primeiro Nome', 'bluesendmail' ),
			'last_name'  => __( 'Sobrenome', 'bluesendmail' ),
			'company'    => __( 'Empresa', 'bluesendmail' ),
			'job_title'  => __( 'Cargo', 'bluesendmail' ),
			'segment'    => __( 'Segmento', 'bluesendmail' ),
		);
        ?>
        <div class="bsm-form-container">
            <?php if ( ! empty( $form->title ) ) : ?>
                <h3><?php echo esc_html( $form->title ); ?></h3>
            <?php endif; ?>
            <?php if ( ! empty( $form->description ) ) : ?>
                <p><?php echo esc_html( $form->description ); ?></p>
            <?php endif; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'bsm_form_submission_action_' . $form_id, 'bsm_form_submission_nonce' ); ?>
                <input type="hidden" name="bsm_form_id" value="<?php echo esc_attr( $form_id ); ?>">

                <div class="bsm-form-field">
                    <label for="bsm_email"><?php _e( 'E-mail', 'bluesendmail' ); ?> <span style="color:red">*</span></label>
                    <input type="email" name="bsm_email" id="bsm_email" required>
                </div>

				<?php foreach ( $available_fields as $field_key => $field_label ) : ?>
					<?php if ( ! empty( $enabled_fields[ $field_key ] ) ) : ?>
					<div class="bsm-form-field">
						<label for="bsm_<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $field_label ); ?></label>
						<input type="text" name="bsm_<?php echo esc_attr( $field_key ); ?>" id="bsm_<?php echo esc_attr( $field_key ); ?>">
					</div>
					<?php endif; ?>
				<?php endforeach; ?>

                <div class="bsm-form-submit">
                    <input type="submit" value="<?php echo esc_attr( $form->submit_button_text ); ?>">
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
	
	private function handle_form_submission() {
		$form_id = isset( $_POST['bsm_form_id'] ) ? absint( $_POST['bsm_form_id'] ) : 0;
		if ( ! $form_id || ! isset( $_POST['bsm_form_submission_nonce'] ) || ! wp_verify_nonce( $_POST['bsm_form_submission_nonce'], 'bsm_form_submission_action_' . $form_id ) ) {
			return;
		}

		$email = sanitize_email( $_POST['bsm_email'] );
		if ( ! is_email( $email ) ) return; 

		global $wpdb;
		$table_contacts = $wpdb->prefix . 'bluesendmail_contacts';
		$table_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';
		$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_forms WHERE form_id = %d", $form_id ) );
		$list_id = $form->list_id;

		$redirect_url = remove_query_arg( array('bsm_form_status', 'bsm_form_id', 'bsm_error'), wp_get_referer() );
		$redirect_url = add_query_arg( 'bsm_form_id', $form_id, $redirect_url );
		
		$existing_contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_contacts WHERE email = %s", $email ) );
		if ( $existing_contact ) {
			if ( 'subscribed' === $existing_contact->status ) {
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table_contact_lists (contact_id, list_id) VALUES (%d, %d)", $existing_contact->contact_id, $list_id ) );
				wp_safe_redirect( add_query_arg( 'bsm_form_status', 'success', $redirect_url ) );
			} else { 
				wp_safe_redirect( add_query_arg( array( 'bsm_form_status' => 'error', 'bsm_error' => 'email_exists' ), $redirect_url ) );
			}
			exit;
		}

		$data = array(
			'email'      => $email,
			'first_name' => isset( $_POST['bsm_first_name'] ) ? sanitize_text_field( $_POST['bsm_first_name'] ) : '',
			'last_name'  => isset( $_POST['bsm_last_name'] ) ? sanitize_text_field( $_POST['bsm_last_name'] ) : '',
			'company'    => isset( $_POST['bsm_company'] ) ? sanitize_text_field( $_POST['bsm_company'] ) : '',
			'job_title'  => isset( $_POST['bsm_job_title'] ) ? sanitize_text_field( $_POST['bsm_job_title'] ) : '',
			'segment'    => isset( $_POST['bsm_segment'] ) ? sanitize_text_field( $_POST['bsm_segment'] ) : '',
		);

		$double_opt_in_enabled = ! empty( $this->options['enable_double_opt_in'] );

		if ( $double_opt_in_enabled ) {
			$data['status'] = 'pending';
			$data['confirmation_token'] = wp_generate_password( 32, false );
		} else {
			$data['status'] = 'subscribed';
		}
		
		$wpdb->insert( $table_contacts, $data );
		$contact_id = $wpdb->insert_id;

		if ( $contact_id ) {
			$wpdb->insert( $table_contact_lists, array( 'contact_id' => $contact_id, 'list_id' => $list_id ) );
			if ( $double_opt_in_enabled ) {
				$this->send_confirmation_email( $email, $data['confirmation_token'] );
			}
		}

		wp_safe_redirect( add_query_arg( 'bsm_form_status', 'success', $redirect_url ) );
		exit;
	}

	private function send_confirmation_email( $email, $token ) {
		$subject = $this->options['confirmation_subject'] ?? '';
		if( empty($subject) ) $subject = __( 'Confirme a sua inscrição', 'bluesendmail' );

		$content = $this->options['confirmation_content'] ?? '';
		$confirmation_url = add_query_arg( array( 'bsm_action' => 'confirm', 'token' => $token ), home_url() );
		
		if ( empty( $content ) || strpos( $content, '{{confirmation_link}}' ) === false ) {
			$content = sprintf(
				__( 'Olá,<br><br>Para completar a sua inscrição, por favor, clique no link abaixo:<br><br><a href="%s">%s</a><br><br>Obrigado!', 'bluesendmail' ),
				esc_url( $confirmation_url ),
				esc_url( $confirmation_url )
			);
		} else {
			$content = str_replace( '{{confirmation_link}}', esc_url( $confirmation_url ), $content );
		}

		$this->send_via_wp_mail( $email, $subject, $content );
	}

	private function send_via_wp_mail( $to_email, $subject, $body ) {
		$from_name = $this->options['from_name'] ?? get_bloginfo( 'name' );
		$from_email = $this->options['from_email'] ?? get_bloginfo( 'admin_email' );
		
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>"
		);
	
		return wp_mail( $to_email, $subject, $body, $headers );
	}
    // ... O resto do seu arquivo, como as funções de envio, tracking, etc.
	// ... Re-incluir aqui todo o resto do seu arquivo bluesendmail.php ...
	// ... A partir da função 'reschedule_cron_on_settings_update' até o final do arquivo.
	public function reschedule_cron_on_settings_update( $old_value, $new_value ) {
		// ...
	}
	public function process_sending_queue() {
		// ...
	}
	private function send_email( $to_email, $subject, $body, $contact, $queue_id ) {
		// ...
	}
	private function _replace_links_callback( $matches ) {
		// ...
	}
	public function configure_smtp( $phpmailer ) {
		// ...
	}
	private function send_via_sendgrid( $to_email, $subject, $body ) {
		// ...
	}
	public function handle_save_campaign() {
		// ...
	}
	public function capture_mail_error( $wp_error ) {
		// ...
	}
	public function show_admin_notices() {
		// ...
	}
	private function handle_tracking_pixel() {
		// ...
	}
	private function handle_click_tracking() {
		// ...
	}
	private function handle_unsubscribe_request() {
		// ...
	}
	public function maybe_trigger_cron() {
		// ...
	}
	private function log_event( $type, $source, $message, $details = '' ) {
		// ...
	}
	public function enqueue_scheduled_campaigns() {
		// ...
	}
	private function enqueue_campaign_recipients( $campaign_id ) {
		// ...
	}
	private function bsm_get_timezone() {
		// ...
	}
}

function bluesendmail_init() {
    BlueSendMail::get_instance();
}
add_action( 'plugins_loaded', 'bluesendmail_init' );


