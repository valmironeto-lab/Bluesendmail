<?php
/**
 * Gerencia a renderização da página de Campanhas.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Campaigns_Page extends BSM_Admin_Page {

    public function render() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

        if ( 'edit' === $action && ! empty( $_GET['campaign'] ) || 'new-campaign' === ( $_GET['page'] ?? '' ) ) {
            $this->render_add_edit_page();
        } else {
            $this->render_list_page();
        }
    }

    public function render_list_page() {
        ?>
        <div class="wrap bsm-wrap">
            <?php
            $this->render_header(
                __( 'Campanhas', 'bluesendmail' ),
                array(
                    'url'   => admin_url( 'admin.php?page=bluesendmail-new-campaign' ),
                    'label' => __( 'Criar Nova', 'bluesendmail' ),
                    'icon'  => 'dashicons-plus',
                )
            );
            ?>
            <form method="post">
                <?php
                $campaigns_table = new BlueSendMail_Campaigns_List_Table();
                $campaigns_table->prepare_items();
                $campaigns_table->display();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_add_edit_page() {
        global $wpdb;
		$campaign_id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
		$campaign = null;
		$selected_lists = array();
		if ( $campaign_id ) {
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_campaigns WHERE campaign_id = %d", $campaign_id ) );
			if ( $campaign ) $selected_lists = ! empty( $campaign->lists ) ? unserialize( $campaign->lists ) : array();
		}

        $templates = $wpdb->get_results( "SELECT template_id, name FROM {$wpdb->prefix}bluesendmail_templates ORDER BY name ASC" );

		?>
		<div class="wrap bsm-wrap">
            <?php $this->render_header( $campaign ? esc_html__( 'Editar Campanha', 'bluesendmail' ) : esc_html__( 'Criar Nova Campanha', 'bluesendmail' ) ); ?>
            
            <form method="post" id="bsm-campaign-form">
                <?php wp_nonce_field( 'bsm_save_campaign_action', 'bsm_save_campaign_nonce' ); ?>
                <input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>">

                <!-- Seletor de Templates -->
                <?php if ( ! $campaign_id && ! empty( $templates ) ) : ?>
                <div class="bsm-card" id="bsm-template-selector-section">
                    <h2 class="bsm-card-title"><span class="dashicons dashicons-layout"></span><?php _e( 'Comece com um Template', 'bluesendmail' ); ?></h2>
                    <div class="bsm-template-selector">
                        <div class="bsm-template-card active" data-template-id="0">
                            <div class="bsm-template-card-icon"><span class="dashicons dashicons-edit-large"></span></div>
                            <div class="bsm-template-card-name"><?php _e( 'Campanha em Branco', 'bluesendmail' ); ?></div>
                        </div>
                        <?php foreach ( $templates as $template ) : ?>
                            <div class="bsm-template-card" data-template-id="<?php echo esc_attr( $template->template_id ); ?>">
                                <div class="bsm-template-card-icon"><span class="dashicons dashicons-admin-page"></span></div>
                                <div class="bsm-template-card-name"><?php echo esc_html( $template->name ); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="bsm-card" style="margin-top: 24px;">
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
        </div>
		<?php
    }
}

