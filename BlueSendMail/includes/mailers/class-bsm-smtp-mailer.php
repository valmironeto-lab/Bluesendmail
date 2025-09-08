<?php
/**
 * Mailer class for sending emails via SMTP.
 * It primarily configures PHPMailer and lets wp_mail handle the sending.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_SMTP_Mailer extends BSM_WPMail_Mailer {

	private $options;

	public function __construct( $options ) {
		parent::__construct();
		$this->options = $options;
		add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
	}

	/**
	 * Configures the PHPMailer instance for SMTP.
	 *
	 * @param PHPMailer $phpmailer The PHPMailer instance.
	 */
	public function configure_smtp( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host       = $this->options['smtp_host'] ?? '';
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Port       = $this->options['smtp_port'] ?? 587;
		$phpmailer->Username   = $this->options['smtp_user'] ?? '';
		$phpmailer->Password   = $this->options['smtp_pass'] ?? '';
		$phpmailer->SMTPSecure = $this->options['smtp_encryption'] ?? 'tls';
		$phpmailer->From       = $this->options['from_email'] ?? get_bloginfo( 'admin_email' );
		$phpmailer->FromName   = $this->options['from_name'] ?? get_bloginfo( 'name' );
	}
}
