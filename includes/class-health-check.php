<?php
/**
 * Site health check used after every plugin write operation.
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * After a plugin update/activation, verify the front end and admin still answer
 * correctly. Used to decide whether to roll back automatically.
 */
class HealthCheck {

	/**
	 * Bad markers that show up in a WordPress WSOD ("critical error") page.
	 *
	 * @return string[]
	 */
	private static function bad_markers() {
		// Specific WordPress fatal-error strings only, so a normal post that merely
		// mentions "critical error" does not trigger a false rollback.
		return array(
			'There has been a critical error on this website',
			'<b>Fatal error</b>:',
			'PHP Fatal error:',
		);
	}

	/**
	 * Check one URL: must return HTTP 200 or 302 and a body without fatal markers.
	 *
	 * @param string $url  URL to check.
	 * @param int    $code Optional error code for WP_Error results.
	 * @return true|\WP_Error
	 */
	private static function check_url( $url, $code ) {
		// Cache buster: a page cache would otherwise serve the old, healthy HTML.
		$url      = add_query_arg( 'craynai_health', wp_generate_password( 12, false ), $url );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'body'       => null,
				'redirection' => 3,
				'sslverify'  => false,
				'cookies'    => array(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( $code, sprintf( 'Health check request to %s failed: %s', $url, $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status && 302 !== $status ) {
			return new \WP_Error( $code, sprintf( 'Health check request to %s returned HTTP %d (expected 200 or 302).', $url, $status ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		foreach ( self::bad_markers() as $marker ) {
			if ( false !== stripos( $body, $marker ) ) {
				return new \WP_Error( $code, sprintf( 'Health check request to %s found a fatal marker (" %s ") in the response body.', $url, $marker ) );
			}
		}

		return true;
	}

	/**
	 * Full health check: front page + wp-admin login must respond correctly.
	 *
	 * @return true|\WP_Error
	 */
	public static function check() {
		$frontend = self::check_url( home_url( '/' ), 'craynai_health_frontend' );
		if ( is_wp_error( $frontend ) ) {
			return $frontend;
		}

		$admin = self::check_url( admin_url(), 'craynai_health_admin' );
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}

		return true;
	}
}
