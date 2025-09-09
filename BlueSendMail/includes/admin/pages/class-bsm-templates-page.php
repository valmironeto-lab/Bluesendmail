<?php
/**
 * Gerencia a renderização da página de Templates.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Templates_Page extends BSM_Admin_Page {

	public function render() {
		echo '<div class="wrap bsm-wrap">';
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'new' === $action || 'edit' === $action ) {
			$this->render_add_edit_page();
		} else {
			$this->render_list_page();
		}
		echo '</div>';
	}

	private function render_list_page() {
		$templates_table = new BlueSendMail_Templates_List_Table();
		?>
        <div class="bsm-header">
            <h1><?php echo esc_html__( 'Templates', 'bluesendmail' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-templates&action=new' ) ); ?>" class="page-title-action">
                <span class="dashicons dashicons-plus"></span>
                <?php echo esc_html__( 'Criar Novo Template', 'bluesendmail' ); ?>
            </a>
        </div>
        <form method="post">
            <?php
            wp_nonce_field( 'bsm_bulk_action_nonce', 'bsm_bulk_nonce_field' );
            $templates_table->prepare_items();
            $templates_table->display();
            ?>
        </form>
        <?php
	}

	private function render_add_edit_page() {
		global $wpdb;
		$template_id = isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0;
		$template    = null;

		if ( $template_id ) {
			$template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_templates WHERE template_id = %d", $template_id ) );
		}

		$page_title = $template ? __( 'Editar Template', 'bluesendmail' ) : __( 'Adicionar Novo Template', 'bluesendmail' );
		?>
        <div class="bsm-header">
            <h1><?php echo esc_html( $page_title ); ?></h1>
        </div>
        <div class="bsm-card">
            <form method="post">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr class="form-field form-required">
                            <th scope="row"><label for="name"><?php _e( 'Nome do Template', 'bluesendmail' ); ?> <span class="description">(obrigatório)</span></label></th>
                            <td><input name="name" type="text" id="name" value="<?php echo esc_attr( $template->name ?? '' ); ?>" class="regular-text" required></td>
                        </tr>
                        <tr class="form-field">
                            <th scope="row"><label for="bsm-content"><?php _e( 'Conteúdo', 'bluesendmail' ); ?></label></th>
                            <td><?php wp_editor( $template->content ?? '', 'content', array( 'textarea_name' => 'content', 'media_buttons' => true, 'editor_height' => 400 ) ); ?></td>
                        </tr>
                    </tbody>
                </table>
                <input type="hidden" name="template_id" value="<?php echo esc_attr( $template_id ); ?>" />
                <?php wp_nonce_field( 'bsm_save_template_nonce_action', 'bsm_save_template_nonce_field' ); ?>
                <?php submit_button( $template ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Adicionar Template', 'bluesendmail' ), 'primary', 'bsm_save_template' ); ?>
            </form>
        </div>
        <?php
	}
}

