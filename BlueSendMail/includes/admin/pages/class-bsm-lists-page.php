<?php
/**
 * Gerencia a renderização da página de Listas (lista e editor).
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Lists_Page extends BSM_Admin_Page {

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
        $lists_table = new BlueSendMail_Lists_List_Table();
        $this->render_header(
            __( 'Listas de Contatos', 'bluesendmail' ),
            array(
                'url'   => admin_url( 'admin.php?page=bluesendmail-lists&action=new' ),
                'label' => __( 'Adicionar Nova', 'bluesendmail' ),
                'icon'  => 'dashicons-plus',
            )
        );
		?>
		<form method="post"><?php wp_nonce_field( 'bsm_bulk_action_nonce', 'bsm_bulk_nonce_field' ); $lists_table->prepare_items(); $lists_table->display(); ?></form>
		<?php
    }

    private function render_add_edit_page() {
        global $wpdb;
		$list_id = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0;
		$list = $list_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_lists WHERE list_id = %d", $list_id ) ) : null;

        $this->render_header($list ? __( 'Editar Lista', 'bluesendmail' ) : __( 'Adicionar Nova Lista', 'bluesendmail' ));
		?>
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
}
