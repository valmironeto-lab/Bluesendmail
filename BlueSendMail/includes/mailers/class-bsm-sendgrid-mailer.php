<?php
/**
 * Mailer class for sending emails via the SendGrid API.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_SendGrid_Mailer implements BSM_Mailer_Interface {

	private $options;
	private $last_error = '';

	public function __construct( $options ) {
		$this->options = $options;
	}

	/**
	 * Sends an email using the SendGrid API.
	 *
	 * @param string $to_email The recipient's email address.
	 * @param string $subject  The email subject.
	 * @param string $body     The email body (HTML).
	 * @param array  $headers  Not used by SendGrid API directly, but here for interface compliance.
	 * @return bool True on success, false on failure.
	 */
	public function send( $to_email, $subject, $body, $headers = array() ) {
		$this->last_error = '';
		$api_key = $this->options['sendgrid_api_key'] ?? '';
		if ( empty( $api_key ) ) {
			$this->last_error = __( 'SendGrid API key is missing.', 'bluesendmail' );
			return false;
		}

		$from_name  = $this->options['from_name'] ?? get_bloginfo( 'name' );
		$from_email = $this->options['from_email'] ?? get_bloginfo( 'admin_email' );

		$payload = array(
			'personalizations' => array( array( 'to' => array( array( 'email' => $to_email ) ) ) ),
			'from'             => array( 'email' => $from_email, 'name' => $from_name ),
			'subject'          => $subject,
			'content'          => array( array( 'type' => 'text/html', 'value' => $body ) ),
		);

		$response = wp_remote_post(
			'https://api.sendgrid.com/v3/mail/send',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 202 !== $response_code ) {
			$response_body    = wp_remote_retrieve_body( $response );
			$this->last_error = sprintf( __( 'SendGrid API returned a non-202 status code: %d. Response: %s', 'bluesendmail' ), $response_code, $response_body );
			return false;
		}

		return true;
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
