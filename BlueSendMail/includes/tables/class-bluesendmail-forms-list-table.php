<?php
/**
 * Gerencia a renderização da página de Formulários.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Forms_Page extends BSM_Admin_Page {

	public function render() {
		echo '<div class="wrap bsm-wrap">';
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		// A lógica para adicionar/editar formulários seria colocada aqui.
		// Por enquanto, vamos focar-nos na listagem.
		if ( 'new' === $action || 'edit' === $action ) {
			// Futuramente: $this->render_add_edit_form_page();
			$this->render_header( __( 'Adicionar Novo Formulário', 'bluesendmail' ) );
			echo '<div class="bsm-card"><p>A funcionalidade completa para adicionar e editar formulários será implementada aqui.</p></div>';
		} else {
			$this->render_forms_list_page();
		}
		echo '</div>';
	}

	/**
	 * Renderiza a página com a lista de formulários.
	 */
	private function render_forms_list_page() {
		// Assegura que a classe WP_List_Table está carregada
		if ( ! class_exists( 'BlueSendMail_Forms_List_Table' ) ) {
			require_once BLUESENDMAIL_PLUGIN_DIR . 'includes/tables/class-bluesendmail-forms-list-table.php';
		}
		$forms_table = new BlueSendMail_Forms_List_Table();
		?>
		<div class="bsm-header">
			<h1><?php echo esc_html__( 'Formulários', 'bluesendmail' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bluesendmail-forms&action=new' ) ); ?>" class="page-title-action">
				<span class="dashicons dashicons-plus"></span>
				<?php echo esc_html__( 'Adicionar Novo', 'bluesendmail' ); ?>
			</a>
		</div>
		<form method="post">
			<?php
			wp_nonce_field( 'bsm_bulk_action_nonce', 'bsm_bulk_nonce_field' );
			$forms_table->prepare_items();
			$forms_table->display();
			?>
		</form>
		<?php
	}
}

