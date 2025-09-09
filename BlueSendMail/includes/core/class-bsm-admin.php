<?php
/**
 * Gerencia a inicialização de todas as funcionalidades do painel de administração.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Admin {

	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->load_dependencies();
		$this->instantiate_classes();
	}

	/**
	 * Carrega todos os arquivos necessários para a área administrativa.
	 */
	private function load_dependencies() {
		$admin_path = BLUESENDMAIL_PLUGIN_DIR . 'includes/admin/';

		// Carrega os gerenciadores
		require_once $admin_path . 'class-bsm-admin-menu.php';
		require_once $admin_path . 'class-bsm-admin-assets.php';
		require_once $admin_path . 'class-bsm-admin-handler.php';
		
		// Carrega a classe de página abstrata
		require_once $admin_path . 'pages/abstract-bsm-admin-page.php';

		// Carrega todas as classes de páginas que existirem
		$pages_to_load = [
			'dashboard', 'campaigns', 'contacts', 'lists', 'import',
			'reports', 'settings', 'logs', 'templates', 'forms', 'automations'
		];

		foreach ( $pages_to_load as $page ) {
			$file = $admin_path . "pages/class-bsm-{$page}-page.php";
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Instancia as classes da área administrativa de forma segura.
	 */
	private function instantiate_classes() {
		$pages = [];
		$page_classes = [
			'dashboard'   => 'BSM_Dashboard_Page',
			'campaigns'   => 'BSM_Campaigns_Page',
			'contacts'    => 'BSM_Contacts_Page',
			'lists'       => 'BSM_Lists_Page',
			'forms'       => 'BSM_Forms_Page',
			'import'      => 'BSM_Import_Page',
			'reports'     => 'BSM_Reports_Page',
			'settings'    => 'BSM_Settings_Page',
			'logs'        => 'BSM_Logs_Page',
			'templates'   => 'BSM_Templates_Page',
            'automations' => 'BSM_Automations_Page',
		];

		// Itera e instancia apenas as classes que realmente existem
		foreach ( $page_classes as $slug => $class_name ) {
			if ( class_exists( $class_name ) ) {
				$pages[ $slug ] = new $class_name( $this->plugin );
			}
		}

		new BSM_Admin_Menu( $pages );
		new BSM_Admin_Assets( $this->plugin );
		new BSM_Admin_Handler( $this->plugin );

        // Adiciona um gancho para notificações
        add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
	}

    /**
     * Exibe notificações administrativas.
     */
    public function show_admin_notices() {
        $notice = get_transient( 'bsm_admin_notice' );
        if ( $notice ) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $notice['type'] ),
                wp_kses_post( $notice['message'] )
            );
            delete_transient( 'bsm_admin_notice' );
        }
    }
}

