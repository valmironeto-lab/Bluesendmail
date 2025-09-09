<?php
/**
 * Gerencia a lógica principal das automações.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Automations {

    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        $this->register_hooks();
    }

    /**
     * Registra os ganchos (listeners) para os gatilhos de automação.
     */
    private function register_hooks() {
        add_action( 'bsm_contact_added_to_list', array( $this, 'handle_contact_added_to_list' ), 10, 2 );
    }

    /**
     * Função executada quando o gatilho 'bsm_contact_added_to_list' é acionado.
     */
    public function handle_contact_added_to_list( $contact_id, $list_id ) {
        global $wpdb;

        $automations = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bluesendmail_automations 
             WHERE status = 'active' 
             AND trigger_type = 'contact_added_to_list' 
             AND trigger_meta = %d",
            $list_id
        ) );

        if ( empty( $automations ) ) {
            return;
        }

        foreach ( $automations as $automation ) {
            $wpdb->insert(
                "{$wpdb->prefix}bluesendmail_automation_queue",
                array(
                    'automation_id' => $automation->automation_id,
                    'contact_id'    => $contact_id,
                    'status'        => 'waiting',
                    'process_at'    => current_time( 'mysql', 1 ),
                    'created_at'    => current_time( 'mysql', 1 ),
                )
            );

            $this->plugin->log_event(
                'info',
                'automation_trigger',
                sprintf(
                    'Contato #%d adicionado à fila da automação #%d (%s).',
                    $contact_id,
                    $automation->automation_id,
                    $automation->name
                )
            );
        }
    }
}

