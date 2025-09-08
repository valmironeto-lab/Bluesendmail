<?php
/**
 * Interface for Mailer classes.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the contract for any mailer class in BlueSendMail.
 */
interface BSM_Mailer_Interface {

	/**
	 * Sends an email.
	 *
	 * @param string $to_email The recipient's email address.
	 * @param string $subject  The email subject.
	 * @param string $body     The email body (HTML).
	 * @param array  $headers  Optional. Additional headers.
	 * @return bool True on success, false on failure.
	 */
	public function send( $to_email, $subject, $body, $headers = array() );

	/**
	 * Captures and returns the last error message from the mailer.
	 *
	 * @return string The last error message.
	 */
	public function get_last_error();
}
