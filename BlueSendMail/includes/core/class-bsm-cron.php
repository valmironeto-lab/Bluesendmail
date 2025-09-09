<?php
/**
 * Gerencia todas as funcionalidades de Cron (tarefas agendadas).
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define o número máximo de tentativas de reenvio
define( 'BSM_MAX_RETRY_ATTEMPTS', 3 );

class BSM_Cron {

	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->register_hooks();
	}

	private function register_hooks() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
		add_action( 'bsm_process_sending_queue', array( $this, 'process_sending_queue' ) );
		add_action( 'bsm_check_scheduled_campaigns', array( $this, 'enqueue_scheduled_campaigns' ) );
        add_action( 'bsm_process_automation_queue', array( $this, 'process_automation_queue' ) );

		add_action( 'admin_init', array( $this, 'maybe_trigger_cron' ) );
		add_action( 'update_option_bluesendmail_settings', array( $this, 'reschedule_cron_on_settings_update' ), 10, 2 );
	}

	public function add_cron_interval( $schedules ) {
		$schedules['every_three_minutes'] = array( 'interval' => 180, 'display' => esc_html__( 'A Cada 3 Minutos', 'bluesendmail' ) );
		$schedules['every_five_minutes']  = array( 'interval' => 300, 'display' => esc_html__( 'A Cada 5 Minutos (Recomendado)', 'bluesendmail' ) );
		$schedules['every_ten_minutes']   = array( 'interval' => 600, 'display' => esc_html__( 'A Cada 10 Minutos', 'bluesendmail' ) );
		$schedules['every_fifteen_minutes'] = array( 'interval' => 900, 'display' => esc_html__( 'A Cada 15 Minutos', 'bluesendmail' ) );
		return $schedules;
	}

	public function reschedule_cron_on_settings_update( $old_value, $new_value ) {
		$old_interval = $old_value['cron_interval'] ?? 'every_five_minutes';
		$new_interval = $new_value['cron_interval'] ?? 'every_five_minutes';
		if ( $old_interval !== $new_interval ) {
			wp_clear_scheduled_hook( 'bsm_process_sending_queue' );
			wp_schedule_event( time(), $new_interval, 'bsm_process_sending_queue' );
			wp_clear_scheduled_hook( 'bsm_check_scheduled_campaigns' );
			wp_schedule_event( time(), 'every_five_minutes', 'bsm_check_scheduled_campaigns' );
            wp_clear_scheduled_hook( 'bsm_process_automation_queue' );
            wp_schedule_event( time(), 'every_five_minutes', 'bsm_process_automation_queue' );
		}
	}
    
    /**
     * Processa a fila de automações com registos de depuração detalhados.
     */
    public function process_automation_queue() {
        $this->plugin->log_event('debug', 'automation_cron_runner', 'Iniciando a execução do cron de automação (process_automation_queue).');

        global $wpdb;
        $items_to_process = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bluesendmail_automation_queue WHERE status = 'waiting' AND process_at <= %s LIMIT 20",
            current_time('mysql', 1)
        ));
        
        $this->plugin->log_event('debug', 'automation_cron_runner', sprintf('Consulta SQL executada. Encontrados %d itens na fila de automação para processar.', count($items_to_process)));
        
        if ( empty($items_to_process) ) {
            return;
        }

        foreach($items_to_process as $item){
            $this->plugin->log_event('debug', 'automation_cron_runner', sprintf('Processando item da fila #%d para o contato #%d.', $item->queue_id, $item->contact_id));

            $automation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bluesendmail_automations WHERE automation_id = %d", $item->automation_id));
            
            if(!$automation || 'active' !== $automation->status){
                $wpdb->update("{$wpdb->prefix}bluesendmail_automation_queue", ['status' => 'cancelled'], ['queue_id' => $item->queue_id]);
                $this->plugin->log_event('debug', 'automation_cron_runner', sprintf('Item da fila #%d cancelado. Motivo: Automação #%d não encontrada ou inativa.', $item->queue_id, $item->automation_id));
                continue;
            }

            if('send_campaign' === $automation->action_type){
                $insert_result = $wpdb->insert("{$wpdb->prefix}bluesendmail_queue", array(
                    'campaign_id' => $automation->action_meta,
                    'contact_id' => $item->contact_id,
                    'status' => 'pending',
                    'added_at' => current_time( 'mysql', 1 )
                ));

                if ($insert_result === false) {
                    $this->plugin->log_event('error', 'automation_processor', sprintf("Falha ao inserir na fila de envio principal para o item da fila de automação #%d. Erro DB: %s", $item->queue_id, $wpdb->last_error));
                    continue; // Pula para o próximo item
                }

                $wpdb->update("{$wpdb->prefix}bluesendmail_automation_queue", ['status' => 'completed'], ['queue_id' => $item->queue_id]);
                $this->plugin->log_event('info', 'automation_processor', "Contato #{$item->contact_id} enfileirado para campanha #{$automation->action_meta} pela automação #{$automation->automation_id}.");
            }
        }
        $this->plugin->log_event('debug', 'automation_cron_runner', 'Finalizada a execução do cron de automação.');
    }

	public function process_sending_queue() {
		global $wpdb;
		update_option( 'bsm_last_cron_run', time() );
		$items_to_process = $wpdb->get_results( $wpdb->prepare( "SELECT q.queue_id, q.campaign_id, q.attempts, c.* FROM {$wpdb->prefix}bluesendmail_queue q JOIN {$wpdb->prefix}bluesendmail_contacts c ON q.contact_id = c.contact_id WHERE q.status = 'pending' ORDER BY q.added_at ASC LIMIT %d", 20 ) );
		if ( empty( $items_to_process ) ) return;

		foreach ( $items_to_process as $item ) {
			$this->plugin->mail_error = '';
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id = %d", $item->campaign_id ) );
			if ( ! $campaign ) {
				$wpdb->update( "{$wpdb->prefix}bluesendmail_queue", array( 'status' => 'failed' ), array( 'queue_id' => $item->queue_id ) );
				$this->plugin->log_event( 'error', 'queue_processor', "Campanha ID #{$item->campaign_id} não encontrada para o item da fila #{$item->queue_id}." );
				continue;
			}
			$subject = ! empty( $campaign->subject ) ? $campaign->subject : $campaign->title;
			$content = $campaign->content;
			if ( ! empty( $campaign->preheader ) ) $content = '<span style="display:none !important; visibility:hidden; mso-hide:all; font-size:1px; color:#ffffff; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden;">' . esc_html( $campaign->preheader ) . '</span>' . $content;
			
			if ( $this->plugin->send_email( $item->email, $subject, $content, $item, $item->queue_id ) ) {
				$wpdb->update( "{$wpdb->prefix}bluesendmail_queue", array( 'status' => 'sent' ), array( 'queue_id' => $item->queue_id ) );
			} else {
				$attempts = (int) $item->attempts + 1;
				$new_status = ( $attempts >= BSM_MAX_RETRY_ATTEMPTS ) ? 'failed' : 'pending';

				$wpdb->update(
					"{$wpdb->prefix}bluesendmail_queue",
					array( 'status' => $new_status, 'attempts' => $attempts ),
					array( 'queue_id' => $item->queue_id )
				);

				$this->plugin->log_event( 'error', 'queue_processor', "Falha ao enviar e-mail para {$item->email} (Tentativa {$attempts}/" . BSM_MAX_RETRY_ATTEMPTS . "). Status alterado para: {$new_status}.", $this->plugin->mail_error );
			}
		}

		foreach ( array_unique( wp_list_pluck( $items_to_process, 'campaign_id' ) ) as $campaign_id ) {
			if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE campaign_id = %d AND status = 'pending'", $campaign_id ) ) ) {
                $is_from_automation = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bluesendmail_automations WHERE action_meta = %d AND status = 'active'", $campaign_id));
                if(!$is_from_automation){
				    $wpdb->update( "{$wpdb->prefix}bluesendmail_campaigns", array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', 1 ) ), array( 'campaign_id' => $campaign_id ) );
				    $this->plugin->log_event( 'info', 'campaign', "Campanha #{$campaign_id} concluída e marcada como 'enviada'." );
                }
			}
		}
	}

	public function enqueue_scheduled_campaigns() {
		global $wpdb;
		$campaigns_to_send = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status = 'scheduled' AND scheduled_for <= %s", current_time( 'mysql', 1 ) ) );
		if ( empty( $campaigns_to_send ) ) return;
		foreach ( $campaigns_to_send as $campaign ) {
			$wpdb->update( "{$wpdb->prefix}bluesendmail_campaigns", array( 'status' => 'queued' ), array( 'campaign_id' => $campaign->campaign_id ) );
			$this->plugin->enqueue_campaign_recipients( $campaign->campaign_id );
		}
	}

	public function maybe_trigger_cron() {
		if ( get_transient( 'bsm_cron_check_lock' ) ) return;
		set_transient( 'bsm_cron_check_lock', true, 5 * MINUTE_IN_SECONDS );
		
        $events = [
            'bsm_process_sending_queue' => $this->plugin->options['cron_interval'] ?? 'every_five_minutes',
            'bsm_check_scheduled_campaigns' => 'every_five_minutes',
            'bsm_process_automation_queue' => 'every_five_minutes'
        ];

        foreach($events as $event => $interval){
            if ( ! wp_next_scheduled( $event ) ) {
                wp_schedule_event( time(), $interval, $event );
            }
        }
	}
}

