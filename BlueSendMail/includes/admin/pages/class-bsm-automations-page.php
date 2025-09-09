<?php
/**
 * Gerencia a renderização da página de Automações.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Automations_Page extends BSM_Admin_Page {

	public function render() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'new' === $action || ( 'edit' === $action && ! empty( $_GET['automation'] ) ) ) {
			$this->render_add_edit_page();
		} else {
			$this->render_list_page();
		}
	}

	private function render_list_page() {
		?>
        <div class="wrap bsm-wrap">
            <?php
            $this->render_header(
                __( 'Automações', 'bluesendmail' ),
                array(
                    'url'   => admin_url( 'admin.php?page=bluesendmail-automations&action=new' ),
                    'label' => __( 'Criar Nova Automação', 'bluesendmail' ),
                    'icon'  => 'dashicons-plus',
                )
            );
            ?>
            <form method="post">
                <?php
                $automations_table = new BlueSendMail_Automations_List_Table();
                $automations_table->prepare_items();
                $automations_table->display();
                ?>
            </form>
        </div>
        <?php
	}

	private function render_add_edit_page() {
		global $wpdb;
		$automation_id = isset( $_GET['automation'] ) ? absint( $_GET['automation'] ) : 0;
		$automation = null;
		if ( $automation_id ) {
			$automation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_automations WHERE automation_id = %d", $automation_id ) );
		}

        $lists = $wpdb->get_results("SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC");
        $campaigns = $wpdb->get_results("SELECT campaign_id, title FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status IN ('sent', 'draft') ORDER BY title ASC");

		?>
		<div class="wrap bsm-wrap">
            <?php $this->render_header( $automation ? esc_html__( 'Editar Automação', 'bluesendmail' ) : esc_html__( 'Criar Nova Automação', 'bluesendmail' ) ); ?>
            
            <form method="post">
                <?php wp_nonce_field( 'bsm_save_automation_nonce_action', 'bsm_save_automation_nonce_field' ); ?>
                <input type="hidden" name="automation_id" value="<?php echo esc_attr( $automation_id ); ?>">

                <div class="bsm-card">
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="bsm-name"><?php _e( 'Nome da Automação', 'bluesendmail' ); ?></label></th>
                                <td><input type="text" name="name" id="bsm-name" class="large-text" value="<?php echo esc_attr( $automation->name ?? '' ); ?>" required>
                                <p class="description"><?php _e( 'Para sua referência interna.', 'bluesendmail' ); ?></p></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="bsm-status"><?php _e( 'Status', 'bluesendmail' ); ?></label></th>
                                <td>
                                    <select name="status" id="bsm-status">
                                        <option value="active" <?php selected( $automation->status ?? 'inactive', 'active' ); ?>><?php _e( 'Ativo', 'bluesendmail' ); ?></option>
                                        <option value="inactive" <?php selected( $automation->status ?? 'inactive', 'inactive' ); ?>><?php _e( 'Inativo', 'bluesendmail' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                             <tr>
                                <th scope="row"><label for="bsm-trigger-list"><?php _e( 'Gatilho: Quando o contato for adicionado à lista...', 'bluesendmail' ); ?></label></th>
                                <td>
                                    <select name="trigger_meta" id="bsm-trigger-list" required>
                                        <option value=""><?php _e( 'Selecione uma lista...', 'bluesendmail' ); ?></option>
                                        <?php foreach($lists as $list): ?>
                                            <option value="<?php echo esc_attr($list->list_id); ?>" <?php selected($automation->trigger_meta ?? '', $list->list_id); ?>>
                                                <?php echo esc_html($list->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                             <tr>
                                <th scope="row"><label for="bsm-action-campaign"><?php _e( 'Ação: Enviar a campanha...', 'bluesendmail' ); ?></label></th>
                                <td>
                                    <select name="action_meta" id="bsm-action-campaign" required>
                                        <option value=""><?php _e( 'Selecione uma campanha...', 'bluesendmail' ); ?></option>
                                        <?php foreach($campaigns as $campaign): ?>
                                            <option value="<?php echo esc_attr($campaign->campaign_id); ?>" <?php selected($automation->action_meta ?? '', $campaign->campaign_id); ?>>
                                                <?php echo esc_html($campaign->title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php _e( 'Esta campanha será enviada ao contato assim que ele entrar na lista.', 'bluesendmail' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="submit" style="padding-top: 20px;">
                    <?php submit_button( $automation ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Criar Automação', 'bluesendmail' ), 'primary bsm-btn bsm-btn-primary', 'bsm_save_automation', false ); ?>
                </div>
            </form>
        </div>
		<?php
	}
}
