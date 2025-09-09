<?php
/**
 * Mailer class for sending emails via wp_mail.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_WPMail_Mailer implements BSM_Mailer_Interface {

	private $last_error = '';

	public function __construct() {
		// Hook into wp_mail_failed to capture errors.
		add_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );
	}

	/**
	 * Sends an email using the standard wp_mail() function.
	 *
	 * @param string $to_email The recipient's email address.
	 * @param string $subject  The email subject.
	 * @param string $body     The email body (HTML).
	 * @param array  $headers  Additional headers for the email.
	 * @return bool True on success, false on failure.
	 */
	public function send( $to_email, $subject, $body, $headers = array() ) {
		$this->last_error = ''; // Reset error before sending.
		return wp_mail( $to_email, $subject, $body, $headers );
	}

	/**
	 * Captures the WP_Error object on a failed wp_mail call.
	 *
	 * @param WP_Error $wp_error The error object.
	 */
	public function capture_mail_error( $wp_error ) {
		if ( is_wp_error( $wp_error ) ) {
			$this->last_error = $wp_error->get_error_message();
		}
	}

	/**
	 * Returns the last captured error message.
	 *
	 * @return string The last error message.
	 */
	public function get_last_error() {
		return $this->last_error;
	}
}
