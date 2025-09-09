<?php
/**
 * Gerencia a renderização da página de Importação de Contatos.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Import_Page extends BSM_Admin_Page {

    public function render() {
        echo '<div class="wrap bsm-wrap">';
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		if ( 2 === $step && isset( $_POST['bsm_import_step1'] ) ) {
			$this->render_step2();
		} else {
			$this->render_step1();
		}
		echo '</div>';
    }

    private function render_step1() {
        global $wpdb;
		$all_lists = $wpdb->get_results( "SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC" );
        $this->render_header(__( 'Importar Contatos - Passo 1 de 2', 'bluesendmail' ));
		?>
		<div class="bsm-card">
			<p><?php _e( 'Selecione um arquivo CSV para enviar. O arquivo deve conter uma linha de cabeçalho com os nomes das colunas (ex: "E-mail", "Nome", "Apelido").', 'bluesendmail' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-import&step=2' ) ); ?>">
				<table class="form-table">
					<tr valign="top"><th scope="row"><?php _e( 'Arquivo CSV', 'bluesendmail' ); ?></th><td><input type="file" name="bsm_import_file" accept=".csv" required /></td></tr>
					<?php if ( ! empty( $all_lists ) ) : ?>
					<tr valign="top"><th scope="row"><?php _e( 'Adicionar à Lista', 'bluesendmail' ); ?></th><td><select name="bsm_import_list_id" required><option value=""><?php _e( 'Selecione uma lista', 'bluesendmail' ); ?></option><?php foreach ( $all_lists as $list ) : ?><option value="<?php echo esc_attr( $list->list_id ); ?>"><?php echo esc_html( $list->name ); ?></option><?php endforeach; ?></select></td></tr>
					<?php else : ?>
					<tr valign="top"><td colspan="2"><p><?php printf( wp_kses_post( __( 'Nenhuma lista encontrada. Por favor, <a href="%s">crie uma lista</a> antes de importar contatos.', 'bluesendmail' ) ), esc_url( admin_url( 'admin.php?page=bluesendmail-lists&action=new' ) ) ); ?></p></td></tr>
					<?php endif; ?>
				</table>
				<?php wp_nonce_field( 'bsm_import_nonce_action_step1', 'bsm_import_nonce_field_step1' ); ?>
				<?php submit_button( __( 'Próximo Passo', 'bluesendmail' ), 'primary', 'bsm_import_step1', true, ( empty( $all_lists ) ? array( 'disabled' => 'disabled' ) : null ) ); ?>
			</form>
		</div>
		<?php
    }

    private function render_step2() {
        if ( ! isset( $_POST['bsm_import_nonce_field_step1'] ) || ! wp_verify_nonce( $_POST['bsm_import_nonce_field_step1'], 'bsm_import_nonce_action_step1' ) ) wp_die( esc_html__( 'A verificação de segurança falhou.', 'bluesendmail' ) );
		if ( empty( $_FILES['bsm_import_file']['tmp_name'] ) ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=no-file' ) ); exit; }
		$file_handle = fopen( $_FILES['bsm_import_file']['tmp_name'], 'r' );
		if ( ! $file_handle ) { wp_safe_redirect( admin_url( 'admin.php?page=bluesendmail-import&error=read-error' ) ); exit; }
		$headers = fgetcsv( $file_handle );
		fclose( $file_handle );
		$upload_dir = wp_upload_dir();
		$new_file_path = $upload_dir['basedir'] . '/bsm-import-' . uniqid() . '.csv';
		move_uploaded_file( $_FILES['bsm_import_file']['tmp_name'], $new_file_path );

        $this->render_header(__( 'Importar Contatos - Passo 2 de 2', 'bluesendmail' ));
		?>
		<div class="bsm-card">
			<p><?php _e( 'Associe as colunas do seu arquivo CSV aos campos de contato do BlueSendMail.', 'bluesendmail' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bsm_import_contacts"><input type="hidden" name="bsm_import_file_path" value="<?php echo esc_attr( $new_file_path ); ?>"><input type="hidden" name="bsm_import_list_id" value="<?php echo esc_attr( $_POST['bsm_import_list_id'] ); ?>">
				<?php wp_nonce_field( 'bsm_import_nonce_action_step2', 'bsm_import_nonce_field_step2' ); ?>
				<table class="form-table">
					<?php foreach ( $headers as $index => $header ) : ?>
					<tr valign="top"><th scope="row"><label for="map-<?php echo $index; ?>"><?php echo esc_html( $header ); ?></label></th><td><select name="column_map[<?php echo $index; ?>]" id="map-<?php echo $index; ?>"><option value=""><?php _e( 'Ignorar esta coluna', 'bluesendmail' ); ?></option><option value="email"><?php _e( 'E-mail (Obrigatório)', 'bluesendmail' ); ?></option><option value="first_name"><?php _e( 'Primeiro Nome', 'bluesendmail' ); ?></option><option value="last_name"><?php _e( 'Último Nome', 'bluesendmail' ); ?></option><option value="company"><?php _e( 'Empresa', 'bluesendmail' ); ?></option><option value="job_title"><?php _e( 'Cargo', 'bluesendmail' ); ?></option><option value="segment"><?php _e( 'Segmento', 'bluesendmail' ); ?></option></select></td></tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Importar Contatos', 'bluesendmail' ), 'primary', 'bsm_import_contacts' ); ?>
			</form>
		</div>
		<?php
    }
}
