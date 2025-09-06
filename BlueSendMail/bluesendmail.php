<?php
/**
 * Plugin Name:       BlueSendMail
 * Plugin URI:        https://blueagenciadigital.com.br/bluesendmail
 * Description:       Uma plataforma de e-mail marketing e automação nativa do WordPress para gerenciar contatos, criar campanhas e garantir alta entregabilidade.
 * Version:           1.8.0
 * Author:            Blue Mkt Digital
 * Author URI:        https://blueagenciadigital.com.br/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bluesendmail
 * Domain Path:       /languages
 */

// Se este arquivo for chamado diretamente, aborte.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Acesso direto negado.
}

// Define as constantes do plugin de forma segura.
define( 'BLUESENDMAIL_VERSION', '1.8.0' );
define( 'BLUESENDMAIL_PLUGIN_FILE', __FILE__ );
define( 'BLUESENDMAIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLUESENDMAIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * A classe principal do plugin BlueSendMail.
 */
final class BlueSendMail {

	private static $_instance = null;
	private $options          = array();
	public $mail_error        = '';
	private $current_queue_id_for_tracking = 0;

	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		$this->load_options();
		$this->register_hooks();
	}

	private function load_options() {
		$this->options = get_option( 'bluesendmail_settings', array() );
	}

	private function register_hooks() {
		register_activation_hook( BLUESENDMAIL_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( BLUESENDMAIL_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'handle_public_actions' ) );

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

	private function load_list_tables() {
		$path = BLUESENDMAIL_PLUGIN_DIR . 'includes/tables/';
		require_once $path . 'class-bluesendmail-campaigns-list-table.php';
		require_once $path . 'class-bluesendmail-contacts-list-table.php';
		require_once $path . 'class-bluesendmail-lists-list-table.php';
		require_once $path . 'class-bluesendmail-logs-list-table.php';
		require_once $path . 'class-bluesendmail-reports-list-table.php';
		require_once $path . 'class-bluesendmail-clicks-list-table.php';
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
		$schedules['every_three_minutes'] = array(
			'interval' => 180,
			'display'  => esc_html__( 'A Cada 3 Minutos', 'bluesendmail' ),
		);
		$schedules['every_five_minutes']  = array(
			'interval' => 300,
			'display'  => esc_html__( 'A Cada 5 Minutos', 'bluesendmail' ),
		);
		$schedules['every_ten_minutes']   = array(
			'interval' => 600,
			'display'  => esc_html__( 'A Cada 10 Minutos', 'bluesendmail' ),
		);
		$schedules['every_fifteen_minutes'] = array(
			'interval' => 900,
			'display'  => esc_html__( 'A Cada 15 Minutos', 'bluesendmail' ),
		);
		return $schedules;
	}

	public function reschedule_cron_on_settings_update( $old_value, $new_value ) {
		$old_interval = $old_value['cron_interval'] ?? 'every_five_minutes';
		$new_interval = $new_value['cron_interval'] ?? 'every_five_minutes';

		if ( $old_interval !== $new_interval ) {
			wp_clear_scheduled_hook( 'bsm_process_sending_queue' );
			wp_schedule_event( time(), $new_interval, 'bsm_process_sending_queue' );
			wp_clear_scheduled_hook( 'bsm_check_scheduled_campaigns' );
			wp_schedule_event( time(), $new_interval, 'bsm_check_scheduled_campaigns' );
		}
	}

	public function process_sending_queue() {
		global $wpdb;
		$table_queue     = $wpdb->prefix . 'bluesendmail_queue';
		$table_contacts  = $wpdb->prefix . 'bluesendmail_contacts';
		$table_campaigns = $wpdb->prefix . 'bluesendmail_campaigns';

		update_option( 'bsm_last_cron_run', time() );

		$items_to_process = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.queue_id, q.campaign_id, c.* FROM {$table_queue} q
			 JOIN {$table_contacts} c ON q.contact_id = c.contact_id
			 WHERE q.status = 'pending' 
			 ORDER BY q.added_at ASC 
			 LIMIT %d",
				20
			)
		);

		if ( empty( $items_to_process ) ) {
			return;
		}

		foreach ( $items_to_process as $item ) {
			$this->mail_error = '';
			$campaign         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_campaigns} WHERE campaign_id = %d", $item->campaign_id ) );

			if ( ! $campaign ) {
				$wpdb->update( $table_queue, array( 'status' => 'failed' ), array( 'queue_id' => $item->queue_id ) );
				$this->log_event( 'error', 'queue_processor', "Campanha ID #{$item->campaign_id} não encontrada para o item da fila #{$item->queue_id}." );
				continue;
			}

			$subject   = ! empty( $campaign->subject ) ? $campaign->subject : $campaign->title;
			$content   = $campaign->content;
			$preheader = $campaign->preheader;

			if ( ! empty( $preheader ) ) {
				$preheader_html = '<span style="display:none !important; visibility:hidden; mso-hide:all; font-size:1px; color:#ffffff; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden;">' . esc_html( $preheader ) . '</span>';
				$content        = $preheader_html . $content;
			}

			$sent = $this->send_email( $item->email, $subject, $content, $item, $item->queue_id );

			if ( $sent ) {
				$wpdb->update( $table_queue, array( 'status' => 'sent' ), array( 'queue_id' => $item->queue_id ) );
			} else {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table_queue} SET status = 'failed', attempts = attempts + 1 WHERE queue_id = %d", $item->queue_id ) );
				$this->log_event(
					'error',
					'queue_processor',
					"Falha ao enviar e-mail para {$item->email} (Campanha ID: {$item->campaign_id})",
					$this->mail_error
				);
			}
		}

		$processed_campaign_ids = array_unique( wp_list_pluck( $items_to_process, 'campaign_id' ) );

		foreach ( $processed_campaign_ids as $campaign_id ) {
			$remaining_in_queue = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(queue_id) FROM {$table_queue} WHERE campaign_id = %d AND status = 'pending'",
					$campaign_id
				)
			);

			if ( 0 === (int) $remaining_in_queue ) {
				$wpdb->update(
					$table_campaigns,
					array(
						'status'  => 'sent',
						'sent_at' => current_time( 'mysql', 1 ),
					),
					array( 'campaign_id' => $campaign_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				$this->log_event( 'info', 'campaign', "Campanha #{$campaign_id} concluída e marcada como 'enviada'." );
			}
		}
	}

	private function send_email( $to_email, $subject, $body, $contact, $queue_id ) {
		$mailer_type = $this->options['mailer_type'] ?? 'wp_mail';

		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();

		$subject = str_replace( '{{site.name}}', $site_name, $subject );
		$body    = str_replace( '{{site.name}}', $site_name, $body );
		$body    = str_replace( '{{site.url}}', esc_url( $site_url ), $body );

		$subject = str_replace( '{{contact.first_name}}', $contact->first_name, $subject );
		$subject = str_replace( '{{contact.last_name}}', $contact->last_name, $subject );
		$subject = str_replace( '{{contact.email}}', $contact->email, $subject );
		$body    = str_replace( '{{contact.first_name}}', $contact->first_name, $body );
		$body    = str_replace( '{{contact.last_name}}', $contact->last_name, $body );
		$body    = str_replace( '{{contact.email}}', $contact->email, $body );

		$token           = hash( 'sha256', $contact->email . AUTH_KEY );
		$unsubscribe_url = add_query_arg(
			array(
				'bsm_action' => 'unsubscribe',
				'email'      => rawurlencode( $contact->email ),
				'token'      => $token,
			),
			home_url()
		);
		$body            = str_replace( '{{unsubscribe_link}}', esc_url( $unsubscribe_url ), $body );

		if ( ! empty( $this->options['enable_click_tracking'] ) ) {
			$this->current_queue_id_for_tracking = $queue_id;
			$body = preg_replace_callback( '/<a\s+(?:[^>]*?\s+)?href=(["\'])(.*?)\1/', array( $this, '_replace_links_callback' ), $body );
		}

		if ( ! empty( $this->options['enable_open_tracking'] ) ) {
			$tracking_token = hash( 'sha256', $queue_id . NONCE_KEY );
			$tracking_url   = add_query_arg(
				array(
					'bsm_action' => 'track_open',
					'queue_id'   => $queue_id,
					'token'      => $tracking_token,
				),
				home_url()
			);
			$tracking_pixel = '<img src="' . esc_url( $tracking_url ) . '" width="1" height="1" style="display:none;" alt="">';
			$body          .= $tracking_pixel;
		}

		if ( 'sendgrid' === $mailer_type ) {
			return $this->send_via_sendgrid( $to_email, $subject, $body );
		} else {
			return $this->send_via_wp_mail( $to_email, $subject, $body );
		}
	}

	private function _replace_links_callback( $matches ) {
		$original_url = $matches[2];

		if ( strpos( $original_url, '#' ) === 0 || strpos( $original_url, 'mailto:' ) === 0 || strpos( $original_url, 'bsm_action=unsubscribe' ) !== false ) {
			return $matches[0];
		}

		$queue_id    = $this->current_queue_id_for_tracking;
		$encoded_url = rtrim( strtr( base64_encode( $original_url ), '+/', '-_' ), '=' );
		$token       = hash( 'sha256', $queue_id . $original_url . NONCE_KEY );

		$tracking_url = add_query_arg(
			array(
				'bsm_action' => 'track_click',
				'qid'        => $queue_id,
				'url'        => $encoded_url,
				'token'      => $token,
			),
			home_url()
		);

		return str_replace( $original_url, esc_url( $tracking_url ), $matches[0] );
	}

	private function send_via_wp_mail( $to_email, $subject, $body ) {
		$from_name  = $this->options['from_name'] ?? get_bloginfo( 'name' );
		$from_email = $this->options['from_email'] ?? get_bloginfo( 'admin_email' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		);

		return wp_mail( $to_email, $subject, $body, $headers );
	}

	public function configure_smtp( $phpmailer ) {
		if ( 'smtp' !== ( $this->options['mailer_type'] ?? 'wp_mail' ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = $this->options['smtp_host'] ?? '';
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Port       = $this->options['smtp_port'] ?? 587;
		$phpmailer->Username   = $this->options['smtp_user'] ?? '';
		$phpmailer->Password   = $this->options['smtp_pass'] ?? '';
		$phpmailer->SMTPSecure = $this->options['smtp_encryption'] ?? 'tls';

		$phpmailer->From     = $this->options['from_email'] ?? get_bloginfo( 'admin_email' );
		$phpmailer->FromName = $this->options['from_name'] ?? get_bloginfo( 'name' );
	}

	private function send_via_sendgrid( $to_email, $subject, $body ) {
		$api_key = $this->options['sendgrid_api_key'] ?? '';
		if ( empty( $api_key ) ) {
			return false;
		}

		$from_name  = $this->options['from_name'] ?? get_bloginfo( 'name' );
		$from_email = $this->options['from_email'] ?? get_bloginfo( 'admin_email' );

		$api_url = 'https://api.sendgrid.com/v3/mail/send';

		$payload = array(
			'personalizations' => array(
				array( 'to' => array( array( 'email' => $to_email ) ) ),
			),
			'from'             => array(
				'email' => $from_email,
				'name'  => $from_name,
			),
			'subject'          => $subject,
			'content'          => array( array( 'type' => 'text/html', 'value' => $body ) ),
		);

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( $payload ),
			'timeout' => 15,
		);

		$response = wp_remote_post( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log_event(
				'error',
				'sendgrid_api',
				"Falha na chamada da API SendGrid para {$to_email}",
				$response->get_error_message()
			);
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		return ( 202 === $response_code );
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
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name_campaigns = $wpdb->prefix . 'bluesendmail_campaigns';
		$sql_campaigns        = "CREATE TABLE $table_name_campaigns (
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

		$table_name_contacts = $wpdb->prefix . 'bluesendmail_contacts';
		$sql_contacts        = "CREATE TABLE $table_name_contacts (
			contact_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			email varchar(255) NOT NULL,
			first_name varchar(255) DEFAULT NULL,
			last_name varchar(255) DEFAULT NULL,
			company varchar(255) DEFAULT NULL,
			job_title varchar(255) DEFAULT NULL,
			segment varchar(255) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'subscribed',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (contact_id),
			UNIQUE KEY email (email)
		) $charset_collate;";
		dbDelta( $sql_contacts );

		$table_name_lists = $wpdb->prefix . 'bluesendmail_lists';
		$sql_lists        = "CREATE TABLE $table_name_lists (
			list_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (list_id)
		) $charset_collate;";
		dbDelta( $sql_lists );

		$table_name_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';
		$sql_contact_lists        = "CREATE TABLE $table_name_contact_lists (
			contact_id bigint(20) UNSIGNED NOT NULL,
			list_id bigint(20) UNSIGNED NOT NULL,
			PRIMARY KEY  (contact_id, list_id),
			KEY list_id (list_id)
		) $charset_collate;";
		dbDelta( $sql_contact_lists );

		$table_name_queue = $wpdb->prefix . 'bluesendmail_queue';
		$sql_queue        = "CREATE TABLE $table_name_queue (
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
		$sql_logs        = "CREATE TABLE $table_name_logs (
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

		// MODIFICADO: A estrutura da tabela de aberturas foi alterada.
		// A chave 'UNIQUE' em 'queue_id' foi removida para permitir o registro de múltiplas aberturas.
		// Uma chave 'KEY' normal foi adicionada para otimizar as consultas.
		$table_name_opens = $wpdb->prefix . 'bluesendmail_email_opens';
		$sql_opens        = "CREATE TABLE $table_name_opens (
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
		$sql_clicks        = "CREATE TABLE $table_name_clicks (
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
	}

	public function setup_admin_menu() {
		// Carrega todas as classes de tabela necessárias para as páginas de administração.
		$this->load_list_tables();

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

		// Busca aberturas únicas para o cálculo da taxa.
		$total_unique_opens = $wpdb->get_var( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id JOIN {$wpdb->prefix}bluesendmail_campaigns c ON q.campaign_id = c.campaign_id WHERE c.status = 'sent'" );

		// Busca cliques únicos para o cálculo da taxa (CTOR)
		$total_unique_clicks = $wpdb->get_var( "SELECT COUNT(DISTINCT cl.contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks cl JOIN {$wpdb->prefix}bluesendmail_campaigns c ON cl.campaign_id = c.campaign_id WHERE c.status = 'sent'" );

		// A taxa de abertura média é mais precisa quando baseada em aberturas únicas.
		$avg_open_rate  = ( $total_sent_emails > 0 ) ? ( $total_unique_opens / $total_sent_emails ) * 100 : 0;
		$avg_click_rate = ( $total_unique_opens > 0 ) ? ( $total_unique_clicks / $total_unique_opens ) * 100 : 0;

		$last_campaign = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1" );
		?>
		<div class="wrap bsm-dashboard-wrap">
			<h1><?php _e( 'Dashboard do BlueSendMail', 'bluesendmail' ); ?></h1>
			<p><?php _e( 'Bem-vindo! Aqui está um resumo rápido da sua atividade de e-mail marketing.', 'bluesendmail' ); ?></p>

			<div class="bsm-dashboard-grid">
				<div class="bsm-card">
					<h3 class="bsm-card-title"><?php _e( 'Total de Contatos Inscritos', 'bluesendmail' ); ?></h3>
					<div class="bsm-stat-number"><?php echo number_format_i18n( $total_subscribers ); ?></div>
				</div>
				<div class="bsm-card">
					<h3 class="bsm-card-title"><?php _e( 'Campanhas Enviadas', 'bluesendmail' ); ?></h3>
					<div class="bsm-stat-number"><?php echo number_format_i18n( $sent_campaigns ); ?></div>
				</div>
				<div class="bsm-card">
					<h3 class="bsm-card-title"><?php _e( 'Taxa Média de Abertura', 'bluesendmail' ); ?></h3>
					<div class="bsm-stat-number"><?php echo number_format_i18n( $avg_open_rate, 2 ); ?>%</div>
				</div>
				<div class="bsm-card">
					<h3 class="bsm-card-title"><?php _e( 'Taxa Média de Cliques (CTOR)', 'bluesendmail' ); ?></h3>
					<div class="bsm-stat-number"><?php echo number_format_i18n( $avg_click_rate, 2 ); ?>%</div>
				</div>
			</div>

			<div class="bsm-dashboard-grid">
				<div class="bsm-card bsm-card-full">
					<h3 class="bsm-card-title"><?php _e( 'Ações Rápidas', 'bluesendmail' ); ?></h3>
					<div class="bsm-quick-actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-new-campaign' ) ); ?>" class="button button-primary"><?php _e( 'Criar Nova Campanha', 'bluesendmail' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-contacts&action=new' ) ); ?>" class="button button-secondary"><?php _e( 'Adicionar Contato', 'bluesendmail' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-campaigns' ) ); ?>" class="button button-secondary"><?php _e( 'Ver Todas as Campanhas', 'bluesendmail' ); ?></a>
					</div>
				</div>
			</div>

			<div class="bsm-dashboard-grid">
				<div class="bsm-card bsm-card-full">
					<h3 class="bsm-card-title"><?php _e( 'Última Campanha Enviada', 'bluesendmail' ); ?></h3>
					<?php
					if ( $last_campaign ) :
						$sent         = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE campaign_id = %d", $last_campaign->campaign_id ) );
						$unique_opens = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id WHERE q.campaign_id = %d", $last_campaign->campaign_id ) );
						$unique_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks WHERE campaign_id = %d", $last_campaign->campaign_id ) );
						$open_rate    = ( $sent > 0 ) ? ( $unique_opens / $sent ) * 100 : 0;
						$click_rate   = ( $unique_opens > 0 ) ? ( $unique_clicks / $unique_opens ) * 100 : 0;
						?>
						<h4><a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-reports&campaign_id=' . $last_campaign->campaign_id ) ); ?>"><?php echo esc_html( $last_campaign->title ); ?></a></h4>
						<p><strong><?php _e( 'Enviada em:', 'bluesendmail' ); ?></strong> <?php echo get_date_from_gmt( $last_campaign->sent_at, 'd/m/Y H:i' ); ?></p>
						<p><strong><?php _e( 'Estatísticas:', 'bluesendmail' ); ?></strong> 
							<?php printf( __( '%d enviados, %d aberturas (%s%%), %d cliques (%s%% CTOR)', 'bluesendmail' ), $sent, $unique_opens, number_format_i18n( $open_rate, 2 ), $unique_clicks, number_format_i18n( $click_rate, 2 ) ); ?>
						</p>
					<?php else : ?>
						<p><?php _e( 'Nenhuma campanha foi enviada ainda.', 'bluesendmail' ); ?></p>
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

		$campaign       = null;
		$selected_lists = array();
		$title          = '';
		$subject        = '';
		$preheader      = '';
		$content        = '';

		if ( $campaign_id ) {
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id = %d", $campaign_id ) );
			if ( $campaign ) {
				$title          = $campaign->title;
				$subject        = $campaign->subject;
				$preheader      = $campaign->preheader;
				$content        = $campaign->content;
				$selected_lists = ! empty( $campaign->lists ) ? unserialize( $campaign->lists ) : array();
			}
		}

		$page_title   = $campaign ? __( 'Editar Campanha', 'bluesendmail' ) : __( 'Criar Nova Campanha', 'bluesendmail' );
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
									<h3><?php _e( 'Personalize seu e-mail', 'bluesendmail' ); ?></h3>
									<p><?php _e( 'Clique nas tags abaixo para inseri-las no seu conteúdo ou assunto.', 'bluesendmail' ); ?></p>

									<p class="bsm-tags-group-title"><?php _e( 'Dados do Contato:', 'bluesendmail' ); ?></p>
									<div>
										<span class="bsm-merge-tag" data-tag="{{contact.first_name}}"><?php _e( 'Primeiro Nome', 'bluesendmail' ); ?></span>
										<span class="bsm-merge-tag" data-tag="{{contact.last_name}}"><?php _e( 'Último Nome', 'bluesendmail' ); ?></span>
										<span class="bsm-merge-tag" data-tag="{{contact.email}}"><?php _e( 'E-mail do Contato', 'bluesendmail' ); ?></span>
									</div>

									<p class="bsm-tags-group-title"><?php _e( 'Dados do Site e Links:', 'bluesendmail' ); ?></p>
									<div>
										<span class="bsm-merge-tag" data-tag="{{site.name}}"><?php _e( 'Nome do Site', 'bluesendmail' ); ?></span>
										<span class="bsm-merge-tag" data-tag="{{site.url}}"><?php _e( 'URL do Site', 'bluesendmail' ); ?></span>
										<span class="bsm-merge-tag" data-tag="{{unsubscribe_link}}"><?php _e( 'Link de Desinscrição', 'bluesendmail' ); ?></span>
									</div>
								</div>
								<?php wp_editor( $content, 'bsm-content', array( 'textarea_name' => 'bsm_content', 'media_buttons' => true ) ); ?>
								<?php if ( ! empty( $this->options['enable_open_tracking'] ) || ! empty( $this->options['enable_click_tracking'] ) ) : ?>
									<p class="description">
										<?php _e( 'Rastreamento ativado:', 'bluesendmail' ); ?>
										<?php if ( ! empty( $this->options['enable_open_tracking'] ) ) {
											echo __( 'Aberturas', 'bluesendmail' );}
										?>
										<?php
										if ( ! empty( $this->options['enable_open_tracking'] ) && ! empty( $this->options['enable_click_tracking'] ) ) {
											echo ' & ';}
										?>
										<?php if ( ! empty( $this->options['enable_click_tracking'] ) ) {
											echo __( 'Cliques', 'bluesendmail' );}
										?>.
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
										$offset           = get_option( 'gmt_offset' );
										$timezone_display = 'UTC' . ( $offset >= 0 ? '+' : '' ) . $offset;
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
								if ( ! empty( $all_lists ) ) :
									?>
								<select name="bsm_lists[]" id="bsm-lists-select" multiple="multiple" style="width: 100%;">
									<?php
									foreach ( $all_lists as $list ) :
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
					<?php if ( ! $campaign || in_array( $campaign->status, array( 'draft', 'scheduled' ), true ) ) : ?>
						<?php submit_button( __( 'Enviar Agora', 'bluesendmail' ), 'primary', 'bsm_send_campaign', false, array( 'id' => 'bsm-send-now-button', 'onclick' => "return confirm('" . __( 'Tem a certeza que deseja enfileirar esta campanha para envio imediato?', 'bluesendmail' ) . "');" ) ); ?>
						<?php submit_button( __( 'Agendar Envio', 'bluesendmail' ), 'primary', 'bsm_schedule_campaign', false, array( 'id' => 'bsm-schedule-button', 'style' => 'display:none;', 'onclick' => "return confirm('" . __( 'Tem a certeza que deseja agendar esta campanha para o horário selecionado?', 'bluesendmail' ) . "');" ) ); ?>
					<?php endif; ?>
				</div>

			</form>
		</div>
		<?php
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php _e( 'Configurações do BlueSendMail', 'bluesendmail' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'bluesendmail_settings_group' );
				do_settings_sections( 'bluesendmail-settings' );
				submit_button();
				?>
			</form>

			<hr>
			
			<div class="bsm-card">
				<h2 class="bsm-card-title"><?php _e( 'Confiabilidade de Envio', 'bluesendmail' ); ?></h2>
				<p><?php _e( 'Para garantir que seus e-mails agendados e em fila sejam enviados de forma pontual, recomendamos fortemente a configuração de um "cron job" no seu servidor. Isso é mais confiável do que o sistema de cron interno do WordPress (WP-Cron), que depende das visitas ao seu site para funcionar.', 'bluesendmail' ); ?></p>
				<p><strong><?php _e( 'Instruções:', 'bluesendmail' ); ?></strong></p>
				<ol>
					<li><?php _e( 'Acesse o painel de controle da sua hospedagem (cPanel, Plesk, etc.) e procure pela opção "Cron Jobs" ou "Tarefas Agendadas".', 'bluesendmail' ); ?></li>
					<li><?php _e( 'Crie uma nova tarefa para executar a cada 5 ou 15 minutos (dependendo do seu plano de hospedagem).', 'bluesendmail' ); ?></li>
					<li><?php _e( 'No campo de comando, insira a seguinte linha:', 'bluesendmail' ); ?>
						<pre style="background:#eee; padding:10px; border-radius:4px;"><code>wget -q -O - <?php echo esc_url( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> >/dev/null 2>&1</code></pre>
					</li>
					<li><?php printf( __( 'Após configurar o cron job no servidor, adicione a seguinte linha ao seu arquivo %s para desativar o WP-Cron padrão:', 'bluesendmail' ), '<strong>wp-config.php</strong>' ); ?>
						<pre style="background:#eee; padding:10px; border-radius:4px;"><code>define('DISABLE_WP_CRON', true);</code></pre>
					</li>
				</ol>
			</div>
			
			<hr>

			<div id="bsm-system-status">
				<h2><?php _e( 'Status do Sistema', 'bluesendmail' ); ?></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><?php _e( 'Itens na Fila de Envio', 'bluesendmail' ); ?></th>
							<td>
								<?php
								global $wpdb;
								$count = $wpdb->get_var( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE status = 'pending'" );
								echo '<strong>' . esc_html( $count ) . '</strong>';
								?>
								<p class="description"><?php _e( 'Número de e-mails aguardando na fila para serem enviados.', 'bluesendmail' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Próxima Execução da Fila', 'bluesendmail' ); ?></th>
							<td>
								<?php
								$timestamp = wp_next_scheduled( 'bsm_process_sending_queue' );
								if ( $timestamp ) {
									$gmt_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
									$local_time = $timestamp + $gmt_offset;
									echo '<strong>' . date_i18n( 'd/m/Y H:i:s', $local_time ) . '</strong>';
								} else {
									echo '<strong style="color:red;">' . esc_html__( 'Não agendado!', 'bluesendmail' ) . '</strong>';
								}
								?>
								<p class="description"><?php _e( 'A próxima vez que o WordPress irá tentar processar a fila de envio.', 'bluesendmail' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e( 'Última Execução Realizada', 'bluesendmail' ); ?></th>
							<td>
								<?php
								$last_run = get_option( 'bsm_last_cron_run' );
								if ( $last_run ) {
									$gmt_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
									$local_time = $last_run + $gmt_offset;
									echo '<strong>' . date_i18n( 'd/m/Y H:i:s', $local_time ) . '</strong> (' . sprintf( esc_html__( '%s atrás' ), human_time_diff( $last_run ) ) . ')';
								} else {
									echo '<strong>' . esc_html__( 'Nunca executado', 'bluesendmail' ) . '</strong>';
								}

								if ( $last_run && ( time() - $last_run > 30 * MINUTE_IN_SECONDS ) ) {
									echo '<p style="color: #a00;"><strong>' . esc_html__( 'Atenção:', 'bluesendmail' ) . '</strong> ' . esc_html__( 'A última execução do agendador foi há muito tempo. Isso pode indicar que o WP-Cron não está a funcionar corretamente no seu servidor. Para garantir a máxima fiabilidade no envio, recomendamos configurar um cron job no seu painel de alojamento.', 'bluesendmail' ) . '</p>';
								}
								?>
								<p class="description"><?php _e( 'A data da última vez que a fila foi processada.', 'bluesendmail' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

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
		// O conteúdo do gráfico e das abas de relatório que já existia.
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
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Logs do Sistema', 'bluesendmail' ); ?></h1>
			<hr class="wp-header-end">
			<form method="post">
				<?php
				$logs_table = new BlueSendMail_Logs_List_Table();
				$logs_table->prepare_items();
				$logs_table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function register_settings() {
		register_setting( 'bluesendmail_settings_group', 'bluesendmail_settings' );

		add_settings_section(
			'bsm_general_section',
			__( 'Configurações Gerais de Remetente', 'bluesendmail' ),
			null,
			'bluesendmail-settings'
		);

		add_settings_field(
			'bsm_from_name',
			__( 'Nome do Remetente', 'bluesendmail' ),
			array( $this, 'render_text_field' ),
			'bluesendmail-settings',
			'bsm_general_section',
			array(
				'id'          => 'from_name',
				'description' => __( 'O nome que aparecerá no campo "De:" do e-mail.', 'bluesendmail' ),
			)
		);

		add_settings_field(
			'bsm_from_email',
			__( 'E-mail do Remetente', 'bluesendmail' ),
			array( $this, 'render_email_field' ),
			'bluesendmail-settings',
			'bsm_general_section',
			array(
				'id'          => 'from_email',
				'description' => __( 'O e-mail que aparecerá no campo "De:" do e-mail.', 'bluesendmail' ),
			)
		);

		add_settings_section(
			'bsm_mailer_section',
			__( 'Configurações do Disparador', 'bluesendmail' ),
			function() {
				echo '<p>' . __( 'Configure o serviço que será usado para enviar os e-mails e a velocidade do envio.', 'bluesendmail' ) . '</p>';
			},
			'bluesendmail-settings'
		);

		add_settings_field(
			'bsm_mailer_type',
			__( 'Método de Envio', 'bluesendmail' ),
			array( $this, 'render_select_field' ),
			'bluesendmail-settings',
			'bsm_mailer_section',
			array(
				'id'          => 'mailer_type',
				'description' => __( 'Escolha como os e-mails serão enviados.', 'bluesendmail' ),
				'options'     => array(
					'wp_mail'  => __( 'E-mail Padrão do WordPress (Não recomendado para produção)', 'bluesendmail' ),
					'smtp'     => __( 'SMTP', 'bluesendmail' ),
					'sendgrid' => __( 'SendGrid', 'bluesendmail' ),
				),
			)
		);

		add_settings_field( 'bsm_smtp_host', __( 'Host SMTP', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'smtp_host', 'class' => 'bsm-smtp-option' ) );
		add_settings_field( 'bsm_smtp_port', __( 'Porta SMTP', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'smtp_port', 'class' => 'bsm-smtp-option' ) );
		add_settings_field(
			'bsm_smtp_encryption',
			__( 'Encriptação SMTP', 'bluesendmail' ),
			array( $this, 'render_select_field' ),
			'bluesendmail-settings',
			'bsm_mailer_section',
			array(
				'id'      => 'smtp_encryption',
				'class'   => 'bsm-smtp-option',
				'options' => array(
					'none' => 'Nenhuma',
					'ssl'  => 'SSL',
					'tls'  => 'TLS',
				),
			)
		);
		add_settings_field( 'bsm_smtp_user', __( 'Utilizador SMTP', 'bluesendmail' ), array( $this, 'render_text_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'smtp_user', 'class' => 'bsm-smtp-option' ) );
		add_settings_field( 'bsm_smtp_pass', __( 'Palavra-passe SMTP', 'bluesendmail' ), array( $this, 'render_password_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'id' => 'smtp_pass', 'class' => 'bsm-smtp-option' ) );

		add_settings_field(
			'bsm_sendgrid_api_key',
			__( 'Chave da API do SendGrid', 'bluesendmail' ),
			array( $this, 'render_password_field' ),
			'bluesendmail-settings',
			'bsm_mailer_section',
			array(
				'id'          => 'sendgrid_api_key',
				'class'       => 'bsm-sendgrid-option',
				'description' => sprintf( __( 'Insira a sua chave da API do SendGrid. Pode encontrá-la no seu painel do <a href="%s" target="_blank">SendGrid</a>.', 'bluesendmail' ), 'https://app.sendgrid.com/settings/api_keys' ),
			)
		);

		add_settings_field(
			'bsm_cron_interval',
			__( 'Intervalo de Envio', 'bluesendmail' ),
			array( $this, 'render_select_field' ),
			'bluesendmail-settings',
			'bsm_mailer_section',
			array(
				'id'          => 'cron_interval',
				'description' => __( 'Selecione a frequência com que o sistema irá processar a fila de envio.', 'bluesendmail' ),
				'options'     => array(
					'every_three_minutes' => __( 'A Cada 3 Minutos', 'bluesendmail' ),
					'every_five_minutes'  => __( 'A Cada 5 Minutos (Recomendado)', 'bluesendmail' ),
					'every_ten_minutes'   => __( 'A Cada 10 Minutos', 'bluesendmail' ),
					'every_fifteen_minutes' => __( 'A Cada 15 Minutos', 'bluesendmail' ),
				),
			)
		);

		add_settings_section(
			'bsm_tracking_section',
			__( 'Configurações de Rastreamento (Tracking)', 'bluesendmail' ),
			function() {
				echo '<p>' . __( 'Ative ou desative o rastreamento de aberturas e cliques.', 'bluesendmail' ) . '</p>';
			},
			'bluesendmail-settings'
		);

		add_settings_field(
			'bsm_enable_open_tracking',
			__( 'Rastreamento de Abertura', 'bluesendmail' ),
			array( $this, 'render_checkbox_field' ),
			'bluesendmail-settings',
			'bsm_tracking_section',
			array(
				'id'          => 'enable_open_tracking',
				'description' => __( 'Ativar o rastreamento de aberturas de e-mail através de um pixel de 1x1.', 'bluesendmail' ),
			)
		);

		add_settings_field(
			'bsm_enable_click_tracking',
			__( 'Rastreamento de Cliques', 'bluesendmail' ),
			array( $this, 'render_checkbox_field' ),
			'bluesendmail-settings',
			'bsm_tracking_section',
			array(
				'id'          => 'enable_click_tracking',
				'description' => __( 'Ativar o rastreamento de cliques em links. Isso irá reescrever todos os links nos e-mails.', 'bluesendmail' ),
			)
		);
	}

	public function render_checkbox_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : 0;
		echo '<label><input type="checkbox" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="1" ' . checked( 1, $value, false ) . '>';
		if ( ! empty( $args['description'] ) ) {
			echo ' ' . esc_html( $args['description'] ) . '</label>';
		}
	}

	public function render_text_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : '';
		echo '<input type="text" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="' . esc_attr( $value ) . '" class="regular-text">';
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . ( $args['description'] ) . '</p>';
		}
	}

	public function render_email_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : '';
		echo '<input type="email" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="' . esc_attr( $value ) . '" class="regular-text">';
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . ( $args['description'] ) . '</p>';
		}
	}

	public function render_password_field( $args ) {
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : '';
		echo '<input type="password" id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']" value="' . esc_attr( $value ) . '" class="regular-text">';
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . ( $args['description'] ) . '</p>';
		}
	}

	public function render_select_field( $args ) {
		$default = 'every_five_minutes';
		if ( 'mailer_type' === $args['id'] ) {
			$default = 'wp_mail';
		}
		if ( 'smtp_encryption' === $args['id'] ) {
			$default = 'tls';
		}
		$value = isset( $this->options[ $args['id'] ] ) ? $this->options[ $args['id'] ] : $default;

		echo '<select id="bsm_' . esc_attr( $args['id'] ) . '" name="bluesendmail_settings[' . esc_attr( $args['id'] ) . ']">';
		foreach ( $args['options'] as $option_key => $option_value ) {
			echo '<option value="' . esc_attr( $option_key ) . '" ' . selected( $value, $option_key, false ) . '>' . esc_html( $option_value ) . '</option>';
		}
		echo '</select>';
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . ( $args['description'] ) . '</p>';
		}
	}

	public function enqueue_admin_assets( $hook ) {
		$screen         = get_current_screen();
		$is_plugin_page = ( $screen && strpos( $screen->id, 'bluesendmail' ) !== false );

		if ( ! $is_plugin_page ) {
			return;
		}

		wp_enqueue_style(
			'bluesendmail-admin-styles',
			BLUESENDMAIL_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			BLUESENDMAIL_VERSION
		);

		$is_campaign_editor = isset( $_GET['page'] ) && (
			'bluesendmail-new-campaign' === $_GET['page'] ||
			( 'bluesendmail-campaigns' === $_GET['page'] && ( $_GET['action'] ?? '' ) === 'edit' )
		);

		$is_import_page = isset( $_GET['page'] ) && 'bluesendmail-import' === $_GET['page'];

		if ( $is_campaign_editor || $is_import_page ) {
			wp_enqueue_style( 'select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css' );
			wp_enqueue_script( 'select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array( 'jquery' ), '4.0.13', true );
		}

		$is_reports_page = isset( $_GET['page'] ) && 'bluesendmail-reports' === $_GET['page'];
		if ( $is_reports_page ) {
			wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.1', true );
		}

		$js_dependencies = array( 'jquery' );
		if ( $is_campaign_editor ) {
			$js_dependencies[] = 'wp-editor';
			$js_dependencies[] = 'select2';
		}

		if ( $is_import_page ) {
			$js_dependencies[] = 'select2';
		}

		if ( $is_reports_page ) {
			$js_dependencies[] = 'chartjs';
		}

		wp_enqueue_script(
			'bluesendmail-admin-script',
			BLUESENDMAIL_PLUGIN_URL . 'assets/js/admin.js',
			$js_dependencies,
			BLUESENDMAIL_VERSION,
			true
		);

		$script_data = array(
			'is_campaign_editor' => (bool) $is_campaign_editor,
			'is_import_page'     => (bool) $is_import_page,
			'is_reports_page'    => (bool) $is_reports_page,
		);

		if ( $is_reports_page && ! empty( $_GET['campaign_id'] ) ) {
			global $wpdb;
			$campaign_id   = absint( $_GET['campaign_id'] );
			$sent          = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE campaign_id = %d", $campaign_id ) );
			$unique_opens  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT q.contact_id) FROM {$wpdb->prefix}bluesendmail_email_opens o JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id WHERE q.campaign_id = %d", $campaign_id ) );
			$unique_clicks = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT contact_id) FROM {$wpdb->prefix}bluesendmail_email_clicks WHERE campaign_id = %d", $campaign_id ) );

			// Garante que os valores são numéricos antes de passar para o JS
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

	public function render_import_page() {
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;

		if ( 2 === $step && isset( $_POST['bsm_import_step1'] ) ) {
			$this->render_import_step2();
		} else {
			$this->render_import_step1();
		}
	}

	private function render_import_step1() {
		global $wpdb;
		$table_lists = $wpdb->prefix . 'bluesendmail_lists';
		$all_lists   = $wpdb->get_results( "SELECT list_id, name FROM $table_lists ORDER BY name ASC" );
		?>
		<div class="wrap">
			<h1><?php _e( 'Importar Contatos - Passo 1 de 2', 'bluesendmail' ); ?></h1>
			<p><?php _e( 'Selecione um arquivo CSV para enviar. O arquivo deve conter uma linha de cabeçalho com os nomes das colunas (ex: "E-mail", "Nome", "Apelido").', 'bluesendmail' ); ?></p>
			
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-import&step=2' ) ); ?>">
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php _e( 'Arquivo CSV', 'bluesendmail' ); ?></th>
						<td><input type="file" name="bsm_import_file" accept=".csv" required /></td>
					</tr>
					<?php if ( ! empty( $all_lists ) ) : ?>
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
				<?php wp_nonce_field( 'bsm_import_nonce_action_step1', 'bsm_import_nonce_field_step1' ); ?>
				<?php submit_button( __( 'Próximo Passo', 'bluesendmail' ), 'primary', 'bsm_import_step1', true, ( empty( $all_lists ) ? array( 'disabled' => 'disabled' ) : null ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_import_step2() {
		if ( ! isset( $_POST['bsm_import_nonce_field_step1'] ) || ! wp_verify_nonce( $_POST['bsm_import_nonce_field_step1'], 'bsm_import_nonce_action_step1' ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}

		if ( empty( $_FILES['bsm_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=no-file' ) );
			exit;
		}

		$file_path = $_FILES['bsm_import_file']['tmp_name'];
		$file_handle = fopen( $file_path, 'r' );
		if ( ! $file_handle ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=read-error' ) );
			exit;
		}

		$headers = fgetcsv( $file_handle );
		fclose( $file_handle );

		$upload_dir = wp_upload_dir();
		$new_file_path = $upload_dir['basedir'] . '/bsm-import-' . uniqid() . '.csv';
		move_uploaded_file( $file_path, $new_file_path );

		?>
		<div class="wrap">
			<h1><?php _e( 'Importar Contatos - Passo 2 de 2', 'bluesendmail' ); ?></h1>
			<p><?php _e( 'Associe as colunas do seu arquivo CSV aos campos de contato do BlueSendMail.', 'bluesendmail' ); ?></p>
			
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bsm_import_contacts">
				<input type="hidden" name="bsm_import_file_path" value="<?php echo esc_attr( $new_file_path ); ?>">
				<input type="hidden" name="bsm_import_list_id" value="<?php echo esc_attr( $_POST['bsm_import_list_id'] ); ?>">
				<?php wp_nonce_field( 'bsm_import_nonce_action_step2', 'bsm_import_nonce_field_step2' ); ?>

				<table class="form-table">
					<?php foreach ( $headers as $index => $header ) : ?>
					<tr valign="top">
						<th scope="row"><label for="map-<?php echo $index; ?>"><?php echo esc_html( $header ); ?></label></th>
						<td>
							<select name="column_map[<?php echo $index; ?>]" id="map-<?php echo $index; ?>">
								<option value=""><?php _e( 'Ignorar esta coluna', 'bluesendmail' ); ?></option>
								<option value="email"><?php _e( 'E-mail (Obrigatório)', 'bluesendmail' ); ?></option>
								<option value="first_name"><?php _e( 'Primeiro Nome', 'bluesendmail' ); ?></option>
								<option value="last_name"><?php _e( 'Último Nome', 'bluesendmail' ); ?></option>
								<option value="company"><?php _e( 'Empresa', 'bluesendmail' ); ?></option>
								<option value="job_title"><?php _e( 'Cargo', 'bluesendmail' ); ?></option>
								<option value="segment"><?php _e( 'Segmento', 'bluesendmail' ); ?></option>
							</select>
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Importar Contatos', 'bluesendmail' ), 'primary', 'bsm_import_contacts' ); ?>
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
				<?php wp_nonce_field( 'bsm_bulk_action_nonce', 'bsm_bulk_nonce_field' ); ?>
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
		$list_id    = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0;
		$list       = null;

		if ( $list_id ) {
			$list = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE list_id = %d", $list_id ) );
		}

		$page_title   = $list ? __( 'Editar Lista', 'bluesendmail' ) : __( 'Adicionar Nova Lista', 'bluesendmail' );
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
				<?php wp_nonce_field( 'bsm_bulk_action_nonce', 'bsm_bulk_nonce_field' ); ?>
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
		$table_contacts      = $wpdb->prefix . 'bluesendmail_contacts';
		$table_lists         = $wpdb->prefix . 'bluesendmail_lists';
		$table_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';

		$contact_id       = isset( $_GET['contact'] ) ? absint( $_GET['contact'] ) : 0;
		$contact          = null;
		$contact_list_ids = array();

		if ( $contact_id ) {
			$contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_contacts WHERE contact_id = %d", $contact_id ) );
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT list_id FROM $table_contact_lists WHERE contact_id = %d", $contact_id ), ARRAY_A );
			if ( $results ) {
				$contact_list_ids = wp_list_pluck( $results, 'list_id' );
			}
		}

		$all_lists = $wpdb->get_results( "SELECT list_id, name FROM $table_lists ORDER BY name ASC" );

		$page_title   = $contact ? __( 'Editar Contato', 'bluesendmail' ) : __( 'Adicionar Novo Contato', 'bluesendmail' );
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
											<input type="checkbox" name="lists[]" id="list-<?php echo esc_attr( $list->list_id ); ?>" value="<?php echo esc_attr( $list->list_id ); ?>" <?php checked( in_array( $list->list_id, $contact_list_ids, true ) ); ?>>
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

	public function handle_actions() {
		add_action( 'admin_post_bsm_import_contacts', array( $this, 'handle_import_contacts' ) );

		if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'bluesendmail' ) !== false ) {
			$this->check_database_setup();

			if ( false === get_transient( 'bsm_scheduled_check_lock' ) ) {
				set_transient( 'bsm_scheduled_check_lock', true, 5 * MINUTE_IN_SECONDS );
				$this->enqueue_scheduled_campaigns();
			}
		}

		$page = $_GET['page'] ?? '';

		if ( 'bluesendmail-settings' === $page && isset( $_POST['bsm_send_test_email'] ) ) {
			$this->handle_send_test_email();
		}

		if ( ( 'bluesendmail-campaigns' === $page || 'bluesendmail-new-campaign' === $page ) && ( isset( $_POST['bsm_save_draft'] ) || isset( $_POST['bsm_send_campaign'] ) || isset( $_POST['bsm_schedule_campaign'] ) ) ) {
			$this->handle_save_campaign();
		}

		if ( 'bluesendmail-contacts' === $page ) {
			if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['contact'] ) ) {
				$this->handle_delete_contact();
			}
			if ( ( isset( $_POST['action'] ) && 'bulk-delete' === $_POST['action'] ) || ( isset( $_POST['action2'] ) && 'bulk-delete' === $_POST['action2'] ) ) {
				$this->handle_bulk_delete_contacts();
			}
			if ( isset( $_POST['bsm_save_contact'] ) ) {
				$this->handle_save_contact();
			}
		}

		if ( 'bluesendmail-lists' === $page ) {
			if ( isset( $_GET['action'] ) ) {
				if ( 'delete' === $_GET['action'] && isset( $_GET['list'] ) ) {
					$this->handle_delete_list();
				}
			}
			if ( ( isset( $_POST['action'] ) && 'bulk-delete' === $_POST['action'] ) || ( isset( $_POST['action2'] ) && 'bulk-delete' === $_POST['action2'] ) ) {
				$this->handle_bulk_delete_lists();
			}
			if ( isset( $_POST['bsm_save_list'] ) ) {
				$this->handle_save_list();
			}
		}
	}

	public function handle_import_contacts() {
		if ( ! isset( $_POST['bsm_import_nonce_field_step2'] ) || ! wp_verify_nonce( $_POST['bsm_import_nonce_field_step2'], 'bsm_import_nonce_action_step2' ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}

		$file_path = sanitize_text_field( $_POST['bsm_import_file_path'] );
		$list_id   = absint( $_POST['bsm_import_list_id'] );
		$map       = (array) $_POST['column_map'];
		
		if ( ! $list_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=no-list' ) );
			exit;
		}

		$email_column_index = array_search( 'email', $map, true );
		if ( false === $email_column_index ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=no-email-map' ) );
			exit;
		}

		global $wpdb;
		$table_contacts      = $wpdb->prefix . 'bluesendmail_contacts';
		$table_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';

		$imported_count = 0;
		$skipped_count  = 0;
		$row_count      = 0;

		if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
			while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
				$row_count++;
				if ( 1 === $row_count ) {
					continue; // Pula o cabeçalho
				}

				$email = sanitize_email( $data[ $email_column_index ] ?? '' );
				if ( ! is_email( $email ) ) {
					$skipped_count++;
					continue;
				}

				$contact_data = array( 'email' => $email, 'status' => 'subscribed' );

				foreach ( $map as $index => $field ) {
					if ( ! empty( $field ) && isset( $data[ $index ] ) ) {
						$contact_data[ $field ] = sanitize_text_field( $data[ $index ] );
					}
				}

				$existing_contact_id = $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM $table_contacts WHERE email = %s", $email ) );

				if ( $existing_contact_id ) {
					unset( $contact_data['email'] );
					$wpdb->update( $table_contacts, $contact_data, array( 'contact_id' => $existing_contact_id ) );
					$contact_id = $existing_contact_id;
				} else {
					$wpdb->insert( $table_contacts, $contact_data );
					$contact_id = $wpdb->insert_id;
				}

				if ( $contact_id ) {
					$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table_contact_lists (contact_id, list_id) VALUES (%d, %d)", $contact_id, $list_id ) );
					$imported_count++;
				} else {
					$skipped_count++;
				}
			}
			fclose( $handle );
		}
		
		unlink( $file_path ); // Apaga o arquivo temporário

		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&imported=' . $imported_count . '&skipped=' . $skipped_count ) );
		exit;
	}


	private function handle_bulk_delete_contacts() {
		if ( ! isset( $_POST['bsm_bulk_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_bulk_nonce_field'], 'bsm_bulk_action_nonce' ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}

		$contact_ids = isset( $_POST['contact'] ) ? array_map( 'absint', $_POST['contact'] ) : array();

		if ( empty( $contact_ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&error=no-items-selected' ) );
			exit;
		}

		global $wpdb;
		$ids_placeholder = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_contacts WHERE contact_id IN ($ids_placeholder)", $contact_ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_contact_lists WHERE contact_id IN ($ids_placeholder)", $contact_ids ) );

		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&items-deleted=true' ) );
		exit;
	}

	private function handle_bulk_delete_lists() {
		if ( ! isset( $_POST['bsm_bulk_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_bulk_nonce_field'], 'bsm_bulk_action_nonce' ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}

		$list_ids = isset( $_POST['list'] ) ? array_map( 'absint', $_POST['list'] ) : array();

		if ( empty( $list_ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&error=no-items-selected' ) );
			exit;
		}

		global $wpdb;
		$ids_placeholder = implode( ',', array_fill( 0, count( $list_ids ), '%d' ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_lists WHERE list_id IN ($ids_placeholder)", $list_ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bluesendmail_contact_lists WHERE list_id IN ($ids_placeholder)", $list_ids ) );

		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&items-deleted=true' ) );
		exit;
	}


	private function handle_delete_contact() {
		$contact_id = absint( $_GET['contact'] );
		$nonce      = sanitize_text_field( $_GET['_wpnonce'] ?? '' );

		if ( ! wp_verify_nonce( $nonce, 'bsm_delete_contact_' . $contact_id ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'bluesendmail_contacts', array( 'contact_id' => $contact_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'bluesendmail_contact_lists', array( 'contact_id' => $contact_id ), array( '%d' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&contact-deleted=true' ) );
		exit;
	}

	private function handle_save_contact() {
		if ( ! isset( $_POST['bsm_save_contact_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_save_contact_nonce_field'], 'bsm_save_contact_nonce_action' ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}

		global $wpdb;
		$table_contacts      = $wpdb->prefix . 'bluesendmail_contacts';
		$table_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';
		$contact_id          = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;

		$data = array(
			'email'      => sanitize_email( $_POST['email'] ),
			'first_name' => sanitize_text_field( $_POST['first_name'] ),
			'last_name'  => sanitize_text_field( $_POST['last_name'] ),
			'company'    => sanitize_text_field( $_POST['company'] ),
			'job_title'  => sanitize_text_field( $_POST['job_title'] ),
			'segment'    => sanitize_text_field( $_POST['segment'] ),
			'status'     => sanitize_key( $_POST['status'] ),
		);

		if ( empty( $data['email'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&action=new&error=empty-email' ) );
			exit;
		};

		if ( $contact_id ) {
			$result        = $wpdb->update( $table_contacts, $data, array( 'contact_id' => $contact_id ) );
			$redirect_slug = 'contact-updated=true';
		} else {
			$result = $wpdb->insert( $table_contacts, $data );
			if ( $result ) {
				$contact_id = $wpdb->insert_id;
			}
			$redirect_slug = 'contact-added=true';
		}

		if ( false === $result || ! $contact_id ) {
			$error_code = $contact_id ? 'contact-update-failed' : 'contact-insert-failed';
			$action_url = $contact_id ? 'edit&contact=' . $contact_id : 'new';
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&action=' . $action_url . '&error=' . $error_code ) );
			exit;
		}

		$submitted_lists = isset( $_POST['lists'] ) ? array_map( 'absint', $_POST['lists'] ) : array();

		$wpdb->delete( $table_contact_lists, array( 'contact_id' => $contact_id ), array( '%d' ) );

		if ( ! empty( $submitted_lists ) ) {
			foreach ( $submitted_lists as $list_id ) {
				$wpdb->insert(
					$table_contact_lists,
					array(
						'contact_id' => $contact_id,
						'list_id'    => $list_id,
					),
					array( '%d', '%d' )
				);
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-contacts&' . $redirect_slug ) );
		exit;
	}

	private function handle_delete_list() {
		$list_id = absint( $_GET['list'] );
		$nonce   = sanitize_text_field( $_GET['_wpnonce'] ?? '' );

		if ( ! wp_verify_nonce( $nonce, 'bsm_delete_list_' . $list_id ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'bluesendmail_lists', array( 'list_id' => $list_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'bluesendmail_contact_lists', array( 'list_id' => $list_id ), array( '%d' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&list-deleted=true' ) );
		exit;
	}

	private function handle_save_list() {
		if ( ! isset( $_POST['bsm_save_list_nonce_field'] ) || ! wp_verify_nonce( $_POST['bsm_save_list_nonce_field'], 'bsm_save_list_nonce_action' ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'bluesendmail_lists';
		$list_id    = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;

		$data = array(
			'name'        => sanitize_text_field( $_POST['name'] ),
			'description' => sanitize_textarea_field( $_POST['description'] ),
		);

		if ( empty( $data['name'] ) ) {
			$action_url = $list_id ? 'edit&list=' . $list_id : 'new';
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&action=' . $action_url . '&error=empty-name' ) );
			exit;
		}

		if ( $list_id ) {
			$result        = $wpdb->update( $table_name, $data, array( 'list_id' => $list_id ) );
			$redirect_slug = 'list-updated=true';
		} else {
			$result        = $wpdb->insert( $table_name, $data );
			$redirect_slug = 'list-added=true';
		}

		if ( false === $result ) {
			$error_code = $list_id ? 'list-update-failed' : 'list-insert-failed';
			$action     = $list_id ? 'edit&list=' . $list_id : 'new';
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&action=' . $action . '&error=' . $error_code ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-lists&' . $redirect_slug ) );
		}
		exit;
	}

	private function handle_send_test_email() {
		if ( ! isset( $_POST['bsm_send_test_email_nonce'] ) || ! wp_verify_nonce( $_POST['bsm_send_test_email_nonce'], 'bsm_send_test_email_action' ) ) {
			wp_die( 'A verificação de segurança falhou.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}

		$recipient = sanitize_email( $_POST['bsm_test_email_recipient'] );
		if ( ! is_email( $recipient ) ) {
			set_transient( 'bsm_test_email_result', array( 'success' => false, 'message' => __( 'O endereço de e-mail fornecido é inválido.', 'bluesendmail' ) ), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-settings' ) );
			exit;
		}

		$subject = '[' . get_bloginfo( 'name' ) . '] ' . __( 'E-mail de Teste do BlueSendMail', 'bluesendmail' );
		$body    = '<h1>🎉 ' . __( 'Teste de Envio Bem-sucedido!', 'bluesendmail' ) . '</h1>';
		$body   .= '<p>' . __( 'Se você está recebendo este e-mail, suas configurações de envio estão funcionando corretamente.', 'bluesendmail' ) . '</p>';

		$this->mail_error = '';

		$result = $this->send_via_wp_mail( $recipient, $subject, $body );

		$message = '';
		if ( $result ) {
			$message = __( 'E-mail de teste enviado com sucesso!', 'bluesendmail' );
			set_transient( 'bsm_test_email_result', array( 'success' => true, 'message' => $message ), 30 );
			$this->log_event( 'success', 'test_email', "E-mail de teste enviado para {$recipient}." );
		} else {
			$message = __( 'Falha ao enviar o e-mail de teste.', 'bluesendmail' );
			if ( ! empty( $this->mail_error ) ) {
				$message .= '<br><strong>' . __( 'Erro retornado:', 'bluesendmail' ) . '</strong> <pre>' . esc_html( $this->mail_error ) . '</pre>';
			}
			set_transient( 'bsm_test_email_result', array( 'success' => false, 'message' => $message ), 30 );
			$this->log_event( 'error', 'test_email', "Falha ao enviar e-mail de teste para {$recipient}.", $this->mail_error );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-settings' ) );
		exit;
	}


	public function handle_save_campaign() {
		if ( ! isset( $_POST['bsm_save_campaign_nonce'] ) || ! wp_verify_nonce( $_POST['bsm_save_campaign_nonce'], 'bsm_save_campaign_action' ) ) {
			wp_die( 'Falha na verificação de segurança.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Você não tem permissão para realizar esta ação.' );
		}

		global $wpdb;

		$table_campaigns = $wpdb->prefix . 'bluesendmail_campaigns';

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		$title     = isset( $_POST['bsm_title'] ) ? sanitize_text_field( wp_unslash( $_POST['bsm_title'] ) ) : '';
		$subject   = isset( $_POST['bsm_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['bsm_subject'] ) ) : '';
		$preheader = isset( $_POST['bsm_preheader'] ) ? sanitize_text_field( wp_unslash( $_POST['bsm_preheader'] ) ) : '';
		$content   = isset( $_POST['bsm_content'] ) ? wp_kses_post( wp_unslash( $_POST['bsm_content'] ) ) : '';
		$lists     = isset( $_POST['bsm_lists'] ) ? array_map( 'absint', (array) $_POST['bsm_lists'] ) : array();

		$is_send_now = isset( $_POST['bsm_send_campaign'] );
		$is_schedule = isset( $_POST['bsm_schedule_campaign'] );

		$schedule_enabled = isset( $_POST['bsm_schedule_enabled'] ) && '1' === $_POST['bsm_schedule_enabled'];
		$schedule_date    = sanitize_text_field( $_POST['bsm_schedule_date'] ?? '' );
		$schedule_time    = sanitize_text_field( $_POST['bsm_schedule_time'] ?? '' );
		$scheduled_for    = null;
		$status           = 'draft';

		if ( $is_schedule && $schedule_enabled && ! empty( $schedule_date ) && ! empty( $schedule_time ) ) {
			$status                = 'scheduled';
			$schedule_datetime_str = $schedule_date . ' ' . $schedule_time;
			$site_timezone         = $this->bsm_get_timezone();
			$schedule_datetime     = new DateTime( $schedule_datetime_str, $site_timezone );
			$schedule_datetime->setTimezone( new DateTimeZone( 'UTC' ) );
			$scheduled_for = $schedule_datetime->format( 'Y-m-d H:i:s' );
		} elseif ( $is_send_now ) {
			$status = 'queued';
		} else {
			$status = 'draft';
			if ( $schedule_enabled && ! empty( $schedule_date ) && ! empty( $schedule_time ) ) {
				$schedule_datetime_str = $schedule_date . ' ' . $schedule_time;
				$site_timezone         = $this->bsm_get_timezone();
				$schedule_datetime     = new DateTime( $schedule_datetime_str, $site_timezone );
				$schedule_datetime->setTimezone( new DateTimeZone( 'UTC' ) );
				$scheduled_for = $schedule_datetime->format( 'Y-m-d H:i:s' );
			}
		}

		$data   = array(
			'title'         => $title,
			'subject'       => $subject,
			'preheader'     => $preheader,
			'content'       => $content,
			'status'        => $status,
			'lists'         => maybe_serialize( $lists ),
			'scheduled_for' => $scheduled_for,
		);
		$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $campaign_id ) {
			$wpdb->update( $table_campaigns, $data, array( 'campaign_id' => $campaign_id ), $format, array( '%d' ) );
		} else {
			$data['created_at'] = current_time( 'mysql', 1 );
			$format[]           = '%s';
			$wpdb->insert( $table_campaigns, $data, $format );
			$campaign_id = $wpdb->insert_id;
		}

		if ( ! $campaign_id ) {
			set_transient( 'bsm_campaign_error_notice_' . get_current_user_id(), __( 'Falha ao salvar a campanha.', 'bluesendmail' ), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-new-campaign' ) );
			exit;
		}

		$this->log_event( 'info', 'campaign', "Campanha #{$campaign_id} salva com status '{$status}'." );

		if ( $is_send_now ) {
			$this->enqueue_campaign_recipients( $campaign_id );
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

	public function capture_mail_error( $wp_error ) {
		if ( is_wp_error( $wp_error ) ) {
			$this->mail_error = $wp_error->get_error_message();
		}
	}

	public function show_admin_notices() {
		$user_id = get_current_user_id();

		$test_result = get_transient( 'bsm_test_email_result' );
		if ( $test_result ) {
			$notice_class = $test_result['success'] ? 'notice-success' : 'notice-error';
			?>
			<div class="notice <?php echo $notice_class; ?> is-dismissible">
				<p><?php echo wp_kses_post( $test_result['message'] ); ?></p>
			</div>
			<?php
			delete_transient( 'bsm_test_email_result' );
		}

		$success_message = get_transient( 'bsm_campaign_queued_notice_' . $user_id );
		if ( $success_message ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html( $success_message ); ?></p>
			</div>
			<?php
			delete_transient( 'bsm_campaign_queued_notice_' . $user_id );
		}

		$error_message = get_transient( 'bsm_campaign_error_notice_' . $user_id );
		if ( $error_message ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $error_message ); ?></p>
			</div>
			<?php
			delete_transient( 'bsm_campaign_error_notice_' . $user_id );
		}

		$success_notices = array(
			'contact-added'   => __( 'Contato adicionado com sucesso!', 'bluesendmail' ),
			'contact-updated' => __( 'Contato atualizado com sucesso!', 'bluesendmail' ),
			'contact-deleted' => __( 'Contato excluído com sucesso!', 'bluesendmail' ),
			'list-added'      => __( 'Lista adicionada com sucesso!', 'bluesendmail' ),
			'list-updated'    => __( 'Lista atualizada com sucesso!', 'bluesendmail' ),
			'list-deleted'    => __( 'Lista excluída com sucesso!', 'bluesendmail' ),
			'items-deleted'   => __( 'Os itens selecionados foram excluídos com sucesso.', 'bluesendmail' ),
			'campaign-saved'  => __( 'Campanha guardada com sucesso.', 'bluesendmail' ),
			'campaign-sent'   => __( 'Campanha enfileirada para envio com sucesso!', 'bluesendmail' ),
		);

		foreach ( $success_notices as $key => $message ) {
			if ( isset( $_GET[ $key ] ) ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
				<?php
				return;
			}
		}

		if ( isset( $_GET['imported'] ) ) {
			$imported = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : 0;
			$skipped  = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php printf( esc_html__( 'Importação concluída! %d contatos importados/atualizados e %d linhas ignoradas.', 'bluesendmail' ), $imported, $skipped ); ?></p>
			</div>
			<?php
		}


		if ( isset( $_GET['error'] ) ) {
			$error_code     = sanitize_key( $_GET['error'] );
			$error_messages = array(
				'contact-insert-failed' => __( 'Falha ao adicionar o contato. É possível que o e-mail já exista.', 'bluesendmail' ),
				'contact-update-failed' => __( 'Falha ao atualizar o contato.', 'bluesendmail' ),
				'list-insert-failed'    => __( 'Falha ao adicionar a lista.', 'bluesendmail' ),
				'list-update-failed'    => __( 'Falha ao atualizar a lista.', 'bluesendmail' ),
				'empty-name'            => __( 'O nome da lista não pode estar vazio.', 'bluesendmail' ),
				'no-file'               => __( 'Nenhum arquivo foi selecionado para importação.', 'bluesendmail' ),
				'no-list'               => __( 'Nenhuma lista foi selecionada para a importação.', 'bluesendmail' ),
				'no-items-selected'     => __( 'Nenhum item foi selecionado.', 'bluesendmail' ),
				'no-email-map'          => __( 'É obrigatório associar uma coluna ao campo "E-mail".', 'bluesendmail' ),
			);

			$message = $error_messages[ $error_code ] ?? __( 'Ocorreu um erro. Verifique os dados e tente novamente.', 'bluesendmail' );

			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo ( $message ); ?></p>
			</div>
			<?php
		}
	}

	public function handle_public_actions() {
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
			}
		}
	}

	private function handle_click_tracking() {
		if ( ! isset( $_GET['qid'], $_GET['url'], $_GET['token'] ) ) {
			return;
		}

		$queue_id     = absint( $_GET['qid'] );
		$encoded_url  = sanitize_text_field( $_GET['url'] );
		$token        = sanitize_text_field( $_GET['token'] );
		$original_url = base64_decode( strtr( $encoded_url, '-_', '+/' ) );

		$expected_token = hash( 'sha256', $queue_id . $original_url . NONCE_KEY );

		if ( ! hash_equals( $expected_token, $token ) ) {
			wp_die( 'Link inválido ou expirado.', 'Erro de Segurança', 403 );
		}

		global $wpdb;
		$table_clicks = $wpdb->prefix . 'bluesendmail_email_clicks';
		$table_queue  = $wpdb->prefix . 'bluesendmail_queue';

		$queue_item = $wpdb->get_row( $wpdb->prepare( "SELECT contact_id, campaign_id FROM {$table_queue} WHERE queue_id = %d", $queue_id ) );

		if ( $queue_item ) {
			$wpdb->insert(
				$table_clicks,
				array(
					'queue_id'     => $queue_id,
					'campaign_id'  => $queue_item->campaign_id,
					'contact_id'   => $queue_item->contact_id,
					'original_url' => $original_url,
					'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
					'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		}

		wp_redirect( esc_url_raw( $original_url ) );
		exit;
	}

	private function handle_tracking_pixel() {
		if ( ! isset( $_GET['queue_id'] ) || ! isset( $_GET['token'] ) ) {
			return;
		}

		$queue_id = absint( $_GET['queue_id'] );
		$token    = sanitize_text_field( $_GET['token'] );

		$expected_token = hash( 'sha256', $queue_id . NONCE_KEY );
		if ( ! hash_equals( $expected_token, $token ) ) {
			return;
		}

		global $wpdb;
		$table_opens = $wpdb->prefix . 'bluesendmail_email_opens';

		// MODIFICADO: De 'INSERT IGNORE' para 'wpdb->insert'.
		// Isso garante que cada evento de abertura seja registrado, permitindo a contagem de aberturas totais.
		$wpdb->insert(
			$table_opens,
			array(
				'queue_id'   => $queue_id,
				'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
				'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
			),
			array( '%d', '%s', '%s' )
		);

		header( 'Content-Type: image/gif' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		exit;
	}

	private function handle_unsubscribe_request() {
		if ( ! isset( $_GET['email'] ) || ! isset( $_GET['token'] ) ) {
			wp_die( 'Link inválido. Faltam parâmetros.', 'Erro', 400 );
		}

		$email = sanitize_email( rawurldecode( $_GET['email'] ) );
		$token = sanitize_text_field( $_GET['token'] );

		if ( ! is_email( $email ) ) {
			wp_die( 'Formato de e-mail inválido.', 'Erro', 400 );
		}

		$expected_token = hash( 'sha256', $email . AUTH_KEY );
		if ( ! hash_equals( $expected_token, $token ) ) {
			wp_die( 'A verificação de segurança falhou. O link pode ter sido alterado ou é inválido.', 'Erro de Segurança', 403 );
		}

		global $wpdb;
		$table_contacts = $wpdb->prefix . 'bluesendmail_contacts';

		$result = $wpdb->update(
			$table_contacts,
			array( 'status' => 'unsubscribed' ),
			array( 'email' => $email ),
			array( '%s' ),
			array( '%s' )
		);

		if ( false !== $result ) {
			wp_die(
				'Seu e-mail foi removido da nossa lista com sucesso. Você não receberá mais comunicações.',
				'Descadastramento Concluído',
				array( 'response' => 200 )
			);
		} else {
			wp_die( 'Ocorreu um erro ao tentar processar seu pedido. Por favor, tente novamente mais tarde ou contate o administrador do site.', 'Erro no Banco de Dados', 500 );
		}
	}

	public function maybe_trigger_cron() {
		if ( get_transient( 'bsm_cron_check_lock' ) ) {
			return;
		}
		set_transient( 'bsm_cron_check_lock', true, 5 * MINUTE_IN_SECONDS );

		$next_scheduled = wp_next_scheduled( 'bsm_process_sending_queue' );

		if ( ! $next_scheduled || $next_scheduled <= time() ) {
			wp_clear_scheduled_hook( 'bsm_process_sending_queue' );
			$interval = $this->options['cron_interval'] ?? 'every_five_minutes';
			wp_schedule_event( time(), $interval, 'bsm_process_sending_queue' );

			$cron_url = site_url( 'wp-cron.php?doing_wp_cron=' . time() );
			wp_remote_post(
				$cron_url,
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				)
			);
		}
	}

	private function log_event( $type, $source, $message, $details = '' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'bluesendmail_logs';

		$wpdb->insert(
			$table_name,
			array(
				'type'    => $type,
				'source'  => $source,
				'message' => $message,
				'details' => is_string( $details ) ? $details : serialize( $details ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	public function enqueue_scheduled_campaigns() {
		global $wpdb;
		$table_campaigns = $wpdb->prefix . 'bluesendmail_campaigns';

		$now_utc = current_time( 'mysql', 1 );

		$campaigns_to_send = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_campaigns} WHERE status = 'scheduled' AND scheduled_for <= %s",
				$now_utc
			)
		);

		if ( empty( $campaigns_to_send ) ) {
			return;
		}

		foreach ( $campaigns_to_send as $campaign ) {
			$wpdb->update(
				$table_campaigns,
				array( 'status' => 'queued' ),
				array( 'campaign_id' => $campaign->campaign_id ),
				array( '%s' ),
				array( '%d' )
			);

			$this->enqueue_campaign_recipients( $campaign->campaign_id );
		}
	}

	private function enqueue_campaign_recipients( $campaign_id ) {
		global $wpdb;
		$table_campaigns     = $wpdb->prefix . 'bluesendmail_campaigns';
		$table_contacts      = $wpdb->prefix . 'bluesendmail_contacts';
		$table_contact_lists = $wpdb->prefix . 'bluesendmail_contact_lists';
		$table_queue         = $wpdb->prefix . 'bluesendmail_queue';

		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_campaigns} WHERE campaign_id = %d", $campaign_id ) );

		if ( ! $campaign ) {
			return;
		}

		$lists = ! empty( $campaign->lists ) ? unserialize( $campaign->lists ) : array();

		if ( ! empty( $lists ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $lists ), '%d' ) );
			$sql          = $wpdb->prepare(
				"SELECT DISTINCT c.contact_id
				 FROM {$table_contacts} c
				 INNER JOIN {$table_contact_lists} cl ON cl.contact_id = c.contact_id
				 WHERE cl.list_id IN ({$placeholders}) AND c.status = %s",
				array_merge( $lists, array( 'subscribed' ) )
			);
		} else {
			$sql = "SELECT contact_id FROM {$table_contacts} WHERE status = 'subscribed'";
		}
		$contact_ids = $wpdb->get_col( $sql );

		if ( ! empty( $contact_ids ) ) {
			$queued = 0;
			foreach ( $contact_ids as $cid ) {
				$wpdb->insert(
					$table_queue,
					array(
						'campaign_id' => $campaign_id,
						'contact_id'  => (int) $cid,
						'status'      => 'pending',
						'attempts'    => 0,
						'added_at'    => current_time( 'mysql', 1 ),
					),
					array( '%d', '%d', '%s', '%d', '%s' )
				);
				$queued++;
			}
			$this->log_event( 'info', 'scheduler', "Campanha #{$campaign_id} enfileirada para {$queued} destinatários." );
		} else {
			$this->log_event( 'warning', 'scheduler', "Campanha #{$campaign_id} não encontrou destinatários." );
		}
	}

	private function bsm_get_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		$timezone_string = get_option( 'timezone_string' );
		if ( $timezone_string ) {
			return new DateTimeZone( $timezone_string );
		}
		$offset  = (float) get_option( 'gmt_offset' );
		$hours   = (int) $offset;
		$minutes = ( $offset - floor( $offset ) ) * 60;
		$zonestr = sprintf( '%+03d:%02d', $hours, $minutes );
		return new DateTimeZone( $zonestr );
	}

} // FIM DA CLASSE BlueSendMail

function bluesendmail_init() {
	BlueSendMail::get_instance();
}
add_action( 'plugins_loaded', 'bluesendmail_init' );



