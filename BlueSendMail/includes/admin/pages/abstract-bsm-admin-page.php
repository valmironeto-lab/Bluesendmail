<?php
/**
 * Classe abstrata para páginas de administração.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BSM_Admin_Page {

    protected $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Renderiza o conteúdo principal da página.
     */
    abstract public function render();

    /**
     * Renderiza o cabeçalho padrão para as páginas do plugin.
     *
     * @param string $title O título da página.
     * @param array $button {
     * Opcional. Define um botão de ação no cabeçalho.
     *
     * @type string $url   A URL do botão.
     * @type string $label O texto do botão.
     * @type string $icon  A classe do Dashicon.
     * }
     */
    protected function render_header( $title, $button = array() ) {
        ?>
        <div class="bsm-header">
            <h1><?php echo esc_html( $title ); ?></h1>
            <?php if ( ! empty( $button ) ) : ?>
                <a href="<?php echo esc_url( $button['url'] ); ?>" class="page-title-action">
                    <?php if ( ! empty( $button['icon'] ) ) : ?>
                        <span class="dashicons <?php echo esc_attr( $button['icon'] ); ?>"></span>
                    <?php endif; ?>
                    <?php echo esc_html( $button['label'] ); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }
}
