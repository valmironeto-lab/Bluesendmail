<?php
/**
 * Plugin Name:       BlueSendMail
 * Plugin URI:        https://blueagenciadigital.com.br/bluesendmail
 * Description:       Uma plataforma de e-mail marketing e automação nativa do WordPress para gerenciar contatos, criar campanhas e garantir alta entregabilidade.
 * Version:           1.9.1
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

define( 'BLUESENDMAIL_VERSION', '1.9.1' );
define( 'BLUESENDMAIL_PLUGIN_FILE', __FILE__ );
define( 'BLUESENDMAIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLUESENDMAIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// --- Lógica de Ativação e Desativação ---

/**
 * Função executada na ativação do plugin.
 * Cria tabelas, adiciona permissões e agenda eventos.
 */
function bluesendmail_activate() {
	require_once BLUESENDMAIL_PLUGIN_DIR . 'includes/core/class-bsm-db.php';
	// Cria as tabelas do banco de dados.
	BSM_DB::create_database_tables();

	// Adiciona as permissões personalizadas para o administrador.
	$administrator = get_role( 'administrator' );
	if ( $administrator ) {
		$caps = array(
			'bsm_manage_campaigns',
			'bsm_manage_contacts',
			'bsm_manage_lists',
			'bsm_view_reports',
			'bsm_manage_settings',
		);
		foreach ( $caps as $cap ) {
			$administrator->add_cap( $cap );
		}
	}

	// Garante que o cron de envio esteja agendado.
	if ( ! wp_next_scheduled( 'bsm_process_sending_queue' ) ) {
		$options = get_option( 'bluesendmail_settings', array() );
		wp_schedule_event( time(), $options['cron_interval'] ?? 'every_five_minutes', 'bsm_process_sending_queue' );
	}

	// Garante que o cron de verificação de campanhas agendadas esteja ativo.
	if ( ! wp_next_scheduled( 'bsm_check_scheduled_campaigns' ) ) {
		wp_schedule_event( time(), 'every_five_minutes', 'bsm_check_scheduled_campaigns' );
	}

	flush_rewrite_rules();
}

/**
 * Função executada na desativação do plugin.
 * Limpa os agendamentos de cron.
 */
function bluesendmail_deactivate() {
	wp_clear_scheduled_hook( 'bsm_process_sending_queue' );
	wp_clear_scheduled_hook( 'bsm_check_scheduled_campaigns' );
	flush_rewrite_rules();
}

register_activation_hook( BLUESENDMAIL_PLUGIN_FILE, 'bluesendmail_activate' );
register_deactivation_hook( BLUESENDMAIL_PLUGIN_FILE, 'bluesendmail_deactivate' );

// --- Carregamento dos Arquivos do Plugin ---

require_once BLUESENDMAIL_PLUGIN_DIR . 'includes/core/class-bsm-db.php';
require_once BLUESENDMAIL_PLUGIN_DIR . 'includes/core/class-bsm-cron.php';
require_once BLUESENDMAIL_PLUGIN_DIR . 'includes/core/class-bsm-admin.php';

require_once BLUESENDMAIL_PLUGIN_DIR . 'includes/interfaces/interface-bsm-mailer.php';
require_once BLUESENDMAIL_PLUGIN_DIR . 'includes/mailers/class-bsm-wp-mail-mailer.php';
require_once BLUESENDMAIL_PLUGIN_DIR . 'includes/mailers/class-bsm-smtp-mailer.php';
require_once BLUESENDMAIL_PLUGIN_DIR . 'includes/mailers/class-bsm-sendgrid-mailer.php';

// --- Classe Principal do Plugin ---

final class BlueSendMail {

	private static $_instance = null;
	public $options           = array();
	public $mail_error        = '';
	private $current_queue_id_for_tracking = 0;
	private $mailer;

	public $db;
	public $cron;
	public $admin;

	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		$this->load_options();
		$this->instantiate_classes();
		$this->register_hooks();
	}

	private function load_options() {
		$this->options = get_option( 'bluesendmail_settings', array() );
	}

	private function instantiate_classes() {
		$this->db   = new BSM_DB();
		$this->cron = new BSM_Cron( $this );
		if ( is_admin() ) {
			$this->admin = new BSM_Admin( $this );
		}
	}

	private function register_hooks() {
		add_action( 'init', array( $this, 'handle_public_actions' ) );
	}

	public function get_mailer() {
		if ( ! is_null( $this->mailer ) ) {
			return $this->mailer;
		}
		$mailer_type = $this->options['mailer_type'] ?? 'wp_mail';
		switch ( $mailer_type ) {
			case 'smtp':
				$this->mailer = new BSM_SMTP_Mailer( $this->options );
				break;
			case 'sendgrid':
				$this->mailer = new BSM_SendGrid_Mailer( $this->options );
				break;
			case 'wp_mail':
			default:
				$this->mailer = new BSM_WPMail_Mailer();
				break;
		}
		return $this->mailer;
	}

	public function send_email( $to_email, $subject, $body, $contact, $queue_id ) {
		$mailer    = $this->get_mailer();
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();

		$subject = str_replace( array('{{site.name}}', '{{contact.first_name}}', '{{contact.last_name}}', '{{contact.email}}'), array($site_name, $contact->first_name, $contact->last_name, $contact->email), $subject );
		$body    = str_replace( array('{{site.name}}', '{{site.url}}', '{{contact.first_name}}', '{{contact.last_name}}', '{{contact.email}}'), array($site_name, esc_url($site_url), $contact->first_name, $contact->last_name, $contact->email), $body );
		
		$token = hash( 'sha256', $contact->email . AUTH_KEY );
		$unsubscribe_url = add_query_arg( array( 'bsm_action' => 'unsubscribe', 'email' => rawurlencode( $contact->email ), 'token' => $token ), home_url() );
		$body = str_replace( '{{unsubscribe_link}}', esc_url( $unsubscribe_url ), $body );

		if ( ! empty( $this->options['enable_click_tracking'] ) && $queue_id > 0 ) {
			$this->current_queue_id_for_tracking = $queue_id;
			$body = preg_replace_callback( '/<a\s+(?:[^>]*?\s+)?href=(["\'])(.*?)\1/', array( $this, '_replace_links_callback' ), $body );
		}

		if ( ! empty( $this->options['enable_open_tracking'] ) && $queue_id > 0 ) {
			$tracking_token = hash( 'sha256', $queue_id . NONCE_KEY );
			$tracking_url   = add_query_arg( array( 'bsm_action' => 'track_open', 'queue_id' => $queue_id, 'token' => $tracking_token ), home_url() );
			$tracking_pixel = '<img src="' . esc_url( $tracking_url ) . '" width="1" height="1" style="display:none;" alt="">';
			$body .= $tracking_pixel;
		}

		$from_name  = $this->options['from_name'] ?? get_bloginfo( 'name' );
		$from_email = $this->options['from_email'] ?? get_bloginfo( 'admin_email' );
		$headers    = array( 'Content-Type: text/html; charset=UTF-8', "From: {$from_name} <{$from_email}>" );

		$result = $mailer->send( $to_email, $subject, $body, $headers );

		if ( ! $result ) {
			$this->mail_error = $mailer->get_last_error();
		}

		return $result;
	}

	private function _replace_links_callback( $matches ) {
		$original_url = $matches[2];
		if ( strpos( $original_url, '#' ) === 0 || strpos( $original_url, 'mailto:' ) === 0 || strpos( $original_url, 'bsm_action=unsubscribe' ) !== false ) {
			return $matches[0];
		}
		$queue_id = $this->current_queue_id_for_tracking;
		$encoded_url = rtrim( strtr( base64_encode( $original_url ), '+/', '-_' ), '=' );
		$token = hash( 'sha256', $queue_id . $original_url . NONCE_KEY );
		$tracking_url = add_query_arg( array( 'bsm_action' => 'track_click', 'qid' => $queue_id, 'url' => $encoded_url, 'token' => $token ), home_url() );
		return str_replace( $original_url, esc_url( $tracking_url ), $matches[0] );
	}

	public function handle_public_actions() {
		if ( isset( $_GET['bsm_action'] ) ) {
			switch ( $_GET['bsm_action'] ) {
				case 'unsubscribe': $this->handle_unsubscribe_request(); break;
				case 'track_open': $this->handle_tracking_pixel(); break;
				case 'track_click': $this->handle_click_tracking(); break;
			}
		}
	}

	private function handle_click_tracking() {
		if ( ! isset( $_GET['qid'], $_GET['url'], $_GET['token'] ) ) return;
		$queue_id = absint( $_GET['qid'] );
		$original_url = base64_decode( strtr( sanitize_text_field( $_GET['url'] ), '-_', '+/' ) );
		if ( ! filter_var($original_url, FILTER_VALIDATE_URL) ) return;
		if ( ! hash_equals( hash( 'sha256', $queue_id . $original_url . NONCE_KEY ), sanitize_text_field( $_GET['token'] ) ) ) wp_die( esc_html__( 'Link inválido ou expirado.', 'bluesendmail' ), esc_html__( 'Erro de Segurança', 'bluesendmail' ), 403 );
		global $wpdb;
		$queue_item = $wpdb->get_row( $wpdb->prepare( "SELECT contact_id, campaign_id FROM {$wpdb->prefix}bluesendmail_queue WHERE queue_id = %d", $queue_id ) );
		if ( $queue_item ) $wpdb->insert( "{$wpdb->prefix}bluesendmail_email_clicks", array( 'queue_id' => $queue_id, 'campaign_id' => $queue_item->campaign_id, 'contact_id' => $queue_item->contact_id, 'original_url' => $original_url, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '', 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		wp_redirect( esc_url_raw( $original_url ) );
		exit;
	}

	private function handle_tracking_pixel() {
		if ( ! isset( $_GET['queue_id'], $_GET['token'] ) ) return;
		$queue_id = absint( $_GET['queue_id'] );
		if ( ! hash_equals( hash( 'sha256', $queue_id . NONCE_KEY ), sanitize_text_field( $_GET['token'] ) ) ) return;
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}bluesendmail_email_opens", array( 'queue_id' => $queue_id, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '', 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		header( 'Content-Type: image/gif' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		exit;
	}

	private function handle_unsubscribe_request() {
		if ( ! isset( $_GET['email'], $_GET['token'] ) ) wp_die( esc_html__( 'Link inválido. Faltam parâmetros.', 'bluesendmail' ), esc_html__( 'Erro', 'bluesendmail' ), 400 );
		$email = sanitize_email( rawurldecode( $_GET['email'] ) );
		if ( ! is_email( $email ) ) wp_die( esc_html__( 'Formato de e-mail inválido.', 'bluesendmail' ), esc_html__( 'Erro', 'bluesendmail' ), 400 );
		if ( ! hash_equals( hash( 'sha256', $email . AUTH_KEY ), sanitize_text_field( $_GET['token'] ) ) ) wp_die( esc_html__( 'A verificação de segurança falhou. O link pode ter sido alterado ou é inválido.', 'bluesendmail' ), esc_html__( 'Erro de Segurança', 'bluesendmail' ), 403 );
		global $wpdb;
		if ( false !== $wpdb->update( "{$wpdb->prefix}bluesendmail_contacts", array( 'status' => 'unsubscribed' ), array( 'email' => $email ) ) ) {
			wp_die( esc_html__( 'Seu e-mail foi removido da nossa lista com sucesso. Você não receberá mais comunicações.', 'bluesendmail' ), esc_html__( 'Descadastramento Concluído', 'bluesendmail' ), array( 'response' => 200 ) );
		} else {
			wp_die( esc_html__( 'Ocorreu um erro ao tentar processar seu pedido. Por favor, tente novamente mais tarde ou contate o administrador do site.', 'bluesendmail' ), esc_html__( 'Erro no Banco de Dados', 'bluesendmail' ), 500 );
		}
	}

	public function log_event( $type, $source, $message, $details = '' ) {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}bluesendmail_logs", array( 'type' => $type, 'source' => $source, 'message' => $message, 'details' => is_string( $details ) ? $details : serialize( $details ) ) );
	}

	public function bsm_get_timezone() {
		if ( function_exists( 'wp_timezone' ) ) return wp_timezone();
		$timezone_string = get_option( 'timezone_string' );
		if ( $timezone_string ) return new DateTimeZone( $timezone_string );
		$offset = (float) get_option( 'gmt_offset' );
		return new DateTimeZone( sprintf( '%+03d:%02d', (int) $offset, ( $offset - floor( $offset ) ) * 60 ) );
	}

	public function enqueue_campaign_recipients( $campaign_id ) {
		global $wpdb;
		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id = %d", $campaign_id ) );
		if ( ! $campaign ) return;
		$lists = ! empty( $campaign->lists ) ? unserialize( $campaign->lists ) : array();
		if ( ! empty( $lists ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $lists ), '%d' ) );
			$sql = $wpdb->prepare( "SELECT DISTINCT c.contact_id FROM {$wpdb->prefix}bluesendmail_contacts c JOIN {$wpdb->prefix}bluesendmail_contact_lists cl ON c.contact_id = cl.contact_id WHERE cl.list_id IN ($placeholders) AND c.status = %s", array_merge( $lists, array( 'subscribed' ) ) );
		} else {
			$sql = "SELECT contact_id FROM {$wpdb->prefix}bluesendmail_contacts WHERE status = 'subscribed'";
		}
		$contact_ids = $wpdb->get_col( $sql );
		if ( ! empty( $contact_ids ) ) {
			$queued = 0;
			foreach ( $contact_ids as $cid ) {
				$wpdb->insert( "{$wpdb->prefix}bluesendmail_queue", array( 'campaign_id' => $campaign_id, 'contact_id' => (int) $cid, 'status' => 'pending', 'attempts' => 0, 'added_at' => current_time( 'mysql', 1 ) ) );
				$queued++;
			}
			$this->log_event( 'info', 'scheduler', "Campanha #{$campaign_id} enfileirada para {$queued} destinatários." );
		} else {
			$this->log_event( 'warning', 'scheduler', "Campanha #{$campaign_id} não encontrou destinatários." );
		}
	}

	public function load_list_tables() {
		$path = BLUESENDMAIL_PLUGIN_DIR . 'includes/tables/';
		require_once $path . 'class-bluesendmail-campaigns-list-table.php';
		require_once $path . 'class-bluesendmail-contacts-list-table.php';
		require_once $path . 'class-bluesendmail-lists-list-table.php';
		require_once $path . 'class-bluesendmail-logs-list-table.php';
		require_once $path . 'class-bluesendmail-reports-list-table.php';
		require_once $path . 'class-bluesendmail-clicks-list-table.php';
	}
}

/**
 * Função de inicialização do plugin.
 */
function bluesendmail_init() {
	BlueSendMail::get_instance();
}
add_action( 'plugins_loaded', 'bluesendmail_init' );

