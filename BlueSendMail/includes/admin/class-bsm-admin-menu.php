<?php
/**
 * Gerencia a criação dos menus no painel de administração.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Admin_Menu {

    private $pages;

    public function __construct( $pages ) {
        $this->pages = $pages;
        add_action( 'admin_menu', array( $this, 'setup_admin_menu' ) );
    }

    private function page_exists( $slug ) {
        return isset( $this->pages[ $slug ] ) && is_object( $this->pages[ $slug ] );
    }

    public function setup_admin_menu() {
        // Carrega as dependências das List Tables
        BlueSendMail::get_instance()->load_list_tables();

        if ( ! $this->page_exists( 'dashboard' ) ) {
            return; // Sai se a página principal não existir
        }

        add_menu_page( 'BlueSendMail', 'BlueSendMail', 'bsm_view_reports', 'bluesendmail', array( $this->pages['dashboard'], 'render' ), 'dashicons-email-alt2', 25 );
        add_submenu_page( 'bluesendmail', __( 'Dashboard', 'bluesendmail' ), __( 'Dashboard', 'bluesendmail' ), 'bsm_view_reports', 'bluesendmail', array( $this->pages['dashboard'], 'render' ) );
        
        if ( $this->page_exists( 'campaigns' ) ) {
            add_submenu_page( 'bluesendmail', __( 'Campanhas', 'bluesendmail' ), __( 'Campanhas', 'bluesendmail' ), 'bsm_manage_campaigns', 'bluesendmail-campaigns', array( $this->pages['campaigns'], 'render' ) );
            add_submenu_page( 'bluesendmail', __( 'Criar Nova', 'bluesendmail' ), __( 'Criar Nova', 'bluesendmail' ), 'bsm_manage_campaigns', 'bluesendmail-new-campaign', array( $this->pages['campaigns'], 'render_add_edit_page' ) );
        }

        if ( $this->page_exists( 'automations' ) ) {
             add_submenu_page( 'bluesendmail', __( 'Automações', 'bluesendmail' ), __( 'Automações', 'bluesendmail' ), 'bsm_manage_campaigns', 'bluesendmail-automations', array( $this->pages['automations'], 'render' ) );
        }
        
        if ( $this->page_exists( 'templates' ) ) {
            add_submenu_page( 'bluesendmail', __( 'Templates', 'bluesendmail' ), __( 'Templates', 'bluesendmail' ), 'bsm_manage_campaigns', 'bluesendmail-templates', array( $this->pages['templates'], 'render' ) );
        }

        if ( $this->page_exists( 'contacts' ) ) {
            add_submenu_page( 'bluesendmail', __( 'Contatos', 'bluesendmail' ), __( 'Contatos', 'bluesendmail' ), 'bsm_manage_contacts', 'bluesendmail-contacts', array( $this->pages['contacts'], 'render' ) );
        }

        if ( $this->page_exists( 'lists' ) ) {
            add_submenu_page( 'bluesendmail', __( 'Listas', 'bluesendmail' ), __( 'Listas', 'bluesendmail' ), 'bsm_manage_lists', 'bluesendmail-lists', array( $this->pages['lists'], 'render' ) );
        }

        if ( $this->page_exists( 'forms' ) ) {
            add_submenu_page( 'bluesendmail', __( 'Formulários', 'bluesendmail' ), __( 'Formulários', 'bluesendmail' ), 'bsm_manage_lists', 'bluesendmail-forms', array( $this->pages['forms'], 'render' ) );
        }

        if ( $this->page_exists( 'import' ) ) {
            add_submenu_page( 'bluesendmail', __( 'Importar', 'bluesendmail' ), __( 'Importar', 'bluesendmail' ), 'bsm_manage_contacts', 'bluesendmail-import', array( $this->pages['import'], 'render' ) );
        }

        if ( $this->page_exists( 'reports' ) ) {
            add_submenu_page( 'bluesendmail', __( 'Relatórios', 'bluesendmail' ), __( 'Relatórios', 'bluesendmail' ), 'bsm_view_reports', 'bluesendmail-reports', array( $this->pages['reports'], 'render' ) );
        }

        if ( $this->page_exists( 'logs' ) ) {
            add_submenu_page( 'bluesendmail', __( 'Logs do Sistema', 'bluesendmail' ), __( 'Logs do Sistema', 'bluesendmail' ), 'bsm_manage_settings', 'bluesendmail-logs', array( $this->pages['logs'], 'render' ) );
        }
        
        if ( $this->page_exists( 'settings' ) ) {
            add_submenu_page( 'bluesendmail', __( 'Configurações', 'bluesendmail' ), __( 'Configurações', 'bluesendmail' ), 'bsm_manage_settings', 'bluesendmail-settings', array( $this->pages['settings'], 'render' ) );
        }
    }
}

