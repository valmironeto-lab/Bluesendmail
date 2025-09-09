<?php
/**
 * Gerencia a renderização da página de Configurações.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Settings_Page extends BSM_Admin_Page {

    public function __construct( $plugin ) {
        parent::__construct( $plugin );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

	public function render() {
        ?>
		<div class="wrap bsm-wrap">
			<?php $this->render_header( __( 'Configurações', 'bluesendmail' ) ); ?>

			<div class="bsm-grid bsm-grid-cols-3">
				<div class="bsm-col-span-2">
					<div class="bsm-card">
						<form method="post" action="options.php">
							<?php 
								settings_fields( 'bluesendmail_settings_group' );
								do_settings_sections( 'bluesendmail-settings' ); 
								submit_button();
							?>
						</form>
					</div>
					<div class="bsm-card" style="margin-top: 24px;">
						<h2 class="bsm-card-title"><span class="dashicons dashicons-email"></span><?php _e( 'Testar Envio', 'bluesendmail' ); ?></h2>
						<p><?php _e( 'Use esta ferramenta para verificar se as suas configurações de envio estão funcionando corretamente.', 'bluesendmail' ); ?></p>
						<form method="post">
							<table class="form-table">
								<tr valign="top">
									<th scope="row"><label for="bsm_test_email_recipient"><?php _e( 'Enviar para', 'bluesendmail' ); ?></label></th>
									<td><input type="email" id="bsm_test_email_recipient" name="bsm_test_email_recipient" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" required>
										<p class="description"><?php _e( 'O e-mail de teste será enviado para este endereço.', 'bluesendmail' ); ?></p>
									</td>
								</tr>
							</table>
							<?php wp_nonce_field( 'bsm_send_test_email_action', 'bsm_send_test_email_nonce' ); ?>
							<?php submit_button( __( 'Enviar Teste', 'bluesendmail' ), 'secondary', 'bsm_send_test_email' ); ?>
						</form>
					</div>
				</div>
				<div class="bsm-col-span-1">
					<div class="bsm-card">
						<h2 class="bsm-card-title"><span class="dashicons dashicons-dashboard"></span><?php _e( 'Status do Sistema', 'bluesendmail' ); ?></h2>
						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><?php _e( 'Fila de Envio', 'bluesendmail' ); ?></th>
									<td><?php global $wpdb; echo '<strong>' . esc_html( $wpdb->get_var( "SELECT COUNT(queue_id) FROM {$wpdb->prefix}bluesendmail_queue WHERE status = 'pending'" ) ) . '</strong> ' . __( 'e-mails pendentes', 'bluesendmail' ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php _e( 'Próxima Execução', 'bluesendmail' ); ?></th>
									<td><?php $timestamp = wp_next_scheduled( 'bsm_process_sending_queue' ); echo $timestamp ? '<strong>' . get_date_from_gmt( date( 'Y-m-d H:i:s', $timestamp ), 'd/m/Y H:i:s' ) . '</strong>' : '<strong style="color:red;">' . esc_html__( 'Não agendado!', 'bluesendmail' ) . '</strong>'; ?></td>
								</tr>
								<tr>
									<th scope="row"><?php _e( 'Última Execução', 'bluesendmail' ); ?></th>
									<td>
										<?php
										$last_run = get_option( 'bsm_last_cron_run' );
										if ( $last_run ) echo '<strong>' . sprintf( esc_html__( '%s atrás' ), human_time_diff( $last_run ) ) . '</strong>';
										else echo '<strong>' . esc_html__( 'Nunca', 'bluesendmail' ) . '</strong>';
										if ( $last_run && ( time() - $last_run > 30 * MINUTE_IN_SECONDS ) ) echo '<p style="color: #a00; font-size: 12px; margin-top: 5px;">' . esc_html__( 'Atenção: A última execução foi há muito tempo. Verifique a configuração do WP-Cron.', 'bluesendmail' ) . '</p>';
										?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<div class="bsm-card" style="margin-top: 24px;">
						<h2 class="bsm-card-title"><span class="dashicons dashicons-admin-generic"></span><?php _e( 'Confiabilidade', 'bluesendmail' ); ?></h2>
						<p><?php _e( 'Para garantir envios pontuais, recomendamos configurar um "cron job" no seu servidor. Use o comando abaixo:', 'bluesendmail' ); ?></p>
						<pre style="background:#eee; padding:10px; border-radius:4px; font-size: 12px; word-wrap: break-word;"><code>wget -q -O - <?php echo esc_url( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> >/dev/null 2>&1</code></pre>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

    public function register_settings() {
		register_setting( 'bluesendmail_settings_group', 'bluesendmail_settings', array( 'sanitize_callback' => array($this, 'sanitize_settings') ) );
		
        add_settings_section( 'bsm_general_section', __( 'Configurações Gerais de Remetente', 'bluesendmail' ), null, 'bluesendmail-settings' );
		add_settings_field( 'bsm_from_name', __( 'Nome do Remetente', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_general_section', array('type' => 'text', 'id' => 'from_name', 'description' => __( 'O nome que aparecerá no campo "De:" do e-mail.', 'bluesendmail' ) ) );
		add_settings_field( 'bsm_from_email', __( 'E-mail do Remetente', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_general_section', array('type' => 'email', 'id' => 'from_email', 'description' => __( 'O e-mail que aparecerá no campo "De:" do e-mail.', 'bluesendmail' ) ) );
		
        add_settings_section( 'bsm_mailer_section', __( 'Configurações do Disparador', 'bluesendmail' ), null, 'bluesendmail-settings' );
		add_settings_field( 'bsm_mailer_type', __( 'Método de Envio', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array('type' => 'select', 'id' => 'mailer_type', 'description' => __( 'Escolha como os e-mails serão enviados.', 'bluesendmail' ), 'options' => array( 'wp_mail' => __( 'Padrão do WordPress', 'bluesendmail' ), 'smtp' => __( 'SMTP', 'bluesendmail' ), 'sendgrid' => __( 'SendGrid', 'bluesendmail' ) ) ) );
		
        // Campos SMTP
        add_settings_field( 'bsm_smtp_host', __( 'Host SMTP', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'type' => 'text', 'id' => 'smtp_host', 'class' => 'bsm-smtp-option' ) );
		add_settings_field( 'bsm_smtp_port', __( 'Porta SMTP', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'type' => 'number', 'id' => 'smtp_port', 'class' => 'bsm-smtp-option' ) );
		add_settings_field( 'bsm_smtp_encryption', __( 'Encriptação SMTP', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'type' => 'select', 'id' => 'smtp_encryption', 'class' => 'bsm-smtp-option', 'options' => array( 'none' => 'Nenhuma', 'ssl' => 'SSL', 'tls' => 'TLS' ) ) );
		add_settings_field( 'bsm_smtp_user', __( 'Utilizador SMTP', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'type' => 'text', 'id' => 'smtp_user', 'class' => 'bsm-smtp-option' ) );
		add_settings_field( 'bsm_smtp_pass', __( 'Palavra-passe SMTP', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'type' => 'password', 'id' => 'smtp_pass', 'class' => 'bsm-smtp-option' ) );
		
        // Campo SendGrid
        add_settings_field( 'bsm_sendgrid_api_key', __( 'Chave da API do SendGrid', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array( 'type' => 'password', 'id' => 'sendgrid_api_key', 'class' => 'bsm-sendgrid-option', 'description' => sprintf( __( 'Insira a sua chave da API do SendGrid. Pode encontrá-la no seu painel do <a href="%s" target="_blank">SendGrid</a>.', 'bluesendmail' ), 'https://app.sendgrid.com/settings/api_keys' ) ) );
		
        add_settings_field( 'bsm_cron_interval', __( 'Intervalo de Envio', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_mailer_section', array('type' => 'select', 'id' => 'cron_interval', 'description' => __( 'Selecione a frequência com que o sistema irá processar a fila de envio.', 'bluesendmail' ), 'options' => array( 'every_three_minutes' => __( 'A Cada 3 Minutos', 'bluesendmail' ), 'every_five_minutes' => __( 'A Cada 5 Minutos (Recomendado)', 'bluesendmail' ), 'every_ten_minutes' => __( 'A Cada 10 Minutos', 'bluesendmail' ), 'every_fifteen_minutes' => __( 'A Cada 15 Minutos', 'bluesendmail' ) ) ) );
		
        add_settings_section( 'bsm_tracking_section', __( 'Configurações de Rastreamento', 'bluesendmail' ), null, 'bluesendmail-settings' );
		add_settings_field( 'bsm_enable_open_tracking', __( 'Rastreamento de Abertura', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_tracking_section', array( 'type' => 'checkbox', 'id' => 'enable_open_tracking', 'description' => __( 'Ativar o rastreamento de aberturas de e-mail.', 'bluesendmail' ) ) );
		add_settings_field( 'bsm_enable_click_tracking', __( 'Rastreamento de Cliques', 'bluesendmail' ), array( $this, 'render_field' ), 'bluesendmail-settings', 'bsm_tracking_section', array( 'type' => 'checkbox', 'id' => 'enable_click_tracking', 'description' => __( 'Ativar o rastreamento de cliques em links.', 'bluesendmail' ) ) );
	}

    /**
     * Renderiza um campo de formulário genérico.
     */
    public function render_field( $args ) {
        $options = $this->plugin->options;
        $id = $args['id'];
        $type = $args['type'];
        $value = $options[$id] ?? '';

        switch ($type) {
            case 'checkbox':
                echo '<label><input type="checkbox" id="bsm_' . esc_attr($id) . '" name="bluesendmail_settings[' . esc_attr($id) . ']" value="1" ' . checked(1, $value, false) . '>';
		        if ( ! empty( $args['description'] ) ) echo ' ' . esc_html( $args['description'] ) . '</label>';
                break;
            case 'select':
                $defaults = array( 'mailer_type' => 'wp_mail', 'smtp_encryption' => 'tls', 'cron_interval' => 'every_five_minutes' );
                $value = $value ?: ($defaults[$id] ?? '');
                echo '<select id="bsm_' . esc_attr($id) . '" name="bluesendmail_settings[' . esc_attr($id) . ']">';
                foreach ($args['options'] as $option_key => $option_value) {
                    echo '<option value="' . esc_attr($option_key) . '" ' . selected($value, $option_key, false) . '>' . esc_html($option_value) . '</option>';
                }
                echo '</select>';
                break;
            default:
                echo '<input type="' . esc_attr($type) . '" id="bsm_' . esc_attr($id) . '" name="bluesendmail_settings[' . esc_attr($id) . ']" value="' . esc_attr($value) . '" class="regular-text">';
                break;
        }

        if ( ! empty( $args['description'] ) && $type !== 'checkbox' ) {
            echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
        }
    }

    /**
     * Sanitiza as configurações antes de as salvar.
     */
    public function sanitize_settings($input) {
        $sanitized_input = [];
        foreach ($input as $key => $value) {
            switch ($key) {
                case 'from_email':
                    $sanitized_input[$key] = sanitize_email($value);
                    break;
                case 'smtp_port':
                    $sanitized_input[$key] = absint($value);
                    break;
                case 'enable_open_tracking':
                case 'enable_click_tracking':
                    $sanitized_input[$key] = $value ? 1 : 0;
                    break;
                default:
                    $sanitized_input[$key] = sanitize_text_field($value);
                    break;
            }
        }
        return $sanitized_input;
    }
}

