<?php
/**
 * Gerencia a renderização da página de Contatos (lista e editor).
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Contacts_Page extends BSM_Admin_Page {

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
        $contacts_table = new BlueSendMail_Contacts_List_Table();
        $this->render_header(
            __( 'Contatos', 'bluesendmail' ),
            array(
                'url'   => admin_url( 'admin.php?page=bluesendmail-contacts&action=new' ),
                'label' => __( 'Adicionar Novo', 'bluesendmail' ),
                'icon'  => 'dashicons-plus',
            )
        );
		?>
		<form method="post"><?php wp_nonce_field( 'bsm_bulk_action_nonce', 'bsm_bulk_nonce_field' ); $contacts_table->prepare_items(); $contacts_table->display(); ?></form>
		<?php
    }

    private function render_add_edit_page() {
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

        $this->render_header($contact ? __( 'Editar Contato', 'bluesendmail' ) : __( 'Adicionar Novo Contato', 'bluesendmail' ));
		?>
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
}
