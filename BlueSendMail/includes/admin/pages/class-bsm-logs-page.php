<?php
/**
 * Gerencia a renderização da página de Logs do Sistema.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Logs_Page extends BSM_Admin_Page {

    public function render() {
        ?>
		<div class="wrap bsm-wrap">
			<?php $this->render_header(__( 'Logs do Sistema', 'bluesendmail' )); ?>
			<form method="post">
                <?php
                $logs_table = new BlueSendMail_Logs_List_Table();
                $logs_table->prepare_items();
                $logs_table->display();
                ?>
            </form>
		</div>
		<?php
    }
}


