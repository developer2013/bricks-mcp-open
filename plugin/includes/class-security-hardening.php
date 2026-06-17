<?php
/**
 * Security Hardening — closes common WordPress attack vectors.
 *
 * 1. Disable user enumeration (REST API + author archives)
 * 2. Rate limit login + REST API authentication
 * 3. Hide version strings (WordPress, Bricks, plugins)
 * 4. Disable XML-RPC
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bricks_API_Bridge_Security_Hardening {

	/**
	 * Maximum failed login attempts before lockout.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Lockout duration in seconds (15 minutes).
	 *
	 * @var int
	 */
	const LOCKOUT_DURATION = 900;

	/**
	 * Initialize all security hooks.
	 *
	 * @return void
	 */
	public function init() {
		// 1. User enumeration protection.
		add_filter( 'rest_endpoints', array( $this, 'disable_user_endpoints' ) );
		add_action( 'template_redirect', array( $this, 'block_author_archives' ) );
		add_filter( 'rest_prepare_user', array( $this, 'restrict_user_data' ), 10, 3 );

		// 2. Rate limiting on login.
		add_filter( 'authenticate', array( $this, 'check_rate_limit' ), 30, 3 );
		add_action( 'wp_login_failed', array( $this, 'record_failed_attempt' ) );
		add_action( 'wp_login', array( $this, 'clear_failed_attempts' ), 10, 2 );

		// 3. Rate limiting on REST API authentication.
		add_filter( 'rest_authentication_errors', array( $this, 'rest_rate_limit' ) );

		// 4. Hide version strings.
		add_filter( 'the_generator', '__return_empty_string' );
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'style_loader_src', array( $this, 'strip_version_query' ), 9999 );
		add_filter( 'script_loader_src', array( $this, 'strip_version_query' ), 9999 );

		// 5. Disable XML-RPC.
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'wp_headers', array( $this, 'remove_x_pingback_header' ) );

		// 6. Security headers.
		add_action( 'send_headers', array( $this, 'send_security_headers' ) );
	}

	// =========================================================================
	// 1. USER ENUMERATION PROTECTION
	// =========================================================================

	/**
	 * Remove /wp/v2/users endpoints from the REST API.
	 *
	 * @param array $endpoints Registered REST endpoints.
	 * @return array
	 */
	public function disable_user_endpoints( $endpoints ) {
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		return $endpoints;
	}

	/**
	 * Redirect author archive pages to homepage.
	 *
	 * @return void
	 */
	public function block_author_archives() {
		if ( is_author() ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}

	/**
	 * Strip sensitive fields from any remaining user REST responses.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WP_User          $user     The user object.
	 * @param WP_REST_Request  $request  The request object.
	 * @return WP_REST_Response
	 */
	public function restrict_user_data( $response, $user, $request ) {
		if ( current_user_can( 'list_users' ) ) {
			return $response;
		}
		$data = $response->get_data();
		unset( $data['slug'], $data['registered_date'], $data['capabilities'], $data['extra_capabilities'] );
		$response->set_data( $data );
		return $response;
	}

	// =========================================================================
	// 2. LOGIN RATE LIMITING
	// =========================================================================

	/**
	 * Get the client IP address, respecting proxies.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		// Delegate to the shared, spoofing-resistant resolver (honors proxy
		// headers only from configured trusted proxies — bab_trusted_proxies).
		if ( function_exists( 'bricks_api_bridge_get_client_ip' ) ) {
			return bricks_api_bridge_get_client_ip();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
	}

	/**
	 * Get the transient key for rate limiting.
	 *
	 * @return string
	 */
	private function get_rate_limit_key() {
		$ip = $this->get_client_ip();
		return 'bab_login_attempts_' . md5( $ip );
	}

	/**
	 * Check if the IP is currently locked out before authentication.
	 *
	 * @param null|WP_User|WP_Error $user     The authenticated user or null.
	 * @param string                $username The username.
	 * @param string                $password The password.
	 * @return null|WP_User|WP_Error
	 */
	public function check_rate_limit( $user, $username, $password ) {
		if ( empty( $username ) ) {
			return $user;
		}

		$key      = $this->get_rate_limit_key();
		$attempts = (int) get_transient( $key );

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$remaining = $this->get_lockout_remaining( $key );
			return new WP_Error(
				'bab_rate_limited',
				sprintf(
					/* translators: %d = minutes remaining until lockout expires */
					__( 'Too many failed login attempts. Please try again in %d minutes.', 'bricks-api-bridge' ),
					$remaining
				)
			);
		}

		return $user;
	}

	/**
	 * Record a failed login attempt.
	 *
	 * @param string $username The username that failed.
	 * @return void
	 */
	public function record_failed_attempt( $username ) {
		$key      = $this->get_rate_limit_key();
		$attempts = (int) get_transient( $key );
		set_transient( $key, $attempts + 1, self::LOCKOUT_DURATION );
	}

	/**
	 * Clear failed attempts on successful login.
	 *
	 * @param string  $user_login The username.
	 * @param WP_User $user       The user object.
	 * @return void
	 */
	public function clear_failed_attempts( $user_login, $user ) {
		$key = $this->get_rate_limit_key();
		delete_transient( $key );
	}

	/**
	 * Get remaining lockout time in minutes.
	 *
	 * @param string $key Transient key.
	 * @return int
	 */
	private function get_lockout_remaining( $key ) {
		$timeout = get_option( '_transient_timeout_' . $key );
		if ( ! $timeout ) {
			return (int) ceil( self::LOCKOUT_DURATION / 60 );
		}
		$remaining = (int) $timeout - time();
		return max( 1, (int) ceil( $remaining / 60 ) );
	}

	// =========================================================================
	// 3. REST API RATE LIMITING
	// =========================================================================

	/**
	 * Rate limit REST API authentication attempts.
	 *
	 * @param WP_Error|null|true $errors Authentication errors.
	 * @return WP_Error|null|true
	 */
	public function rest_rate_limit( $errors ) {
		// Don't interfere if already authenticated or another error exists.
		if ( is_wp_error( $errors ) || is_user_logged_in() ) {
			return $errors;
		}

		// Only rate-limit if Basic Auth credentials are being sent.
		if ( empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			return $errors;
		}

		$ip  = $this->get_client_ip();
		$key = 'bab_rest_attempts_' . md5( $ip );
		$attempts = (int) get_transient( $key );

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return new WP_Error(
				'bab_rest_rate_limited',
				__( 'Too many failed authentication attempts. Please try again later.', 'bricks-api-bridge' ),
				array( 'status' => 429 )
			);
		}

		return $errors;
	}

	// =========================================================================
	// 4. VERSION HIDING
	// =========================================================================

	/**
	 * Strip version query strings from enqueued assets.
	 *
	 * @param string $src The asset URL.
	 * @return string
	 */
	public function strip_version_query( $src ) {
		if ( is_admin() ) {
			return $src;
		}
		if ( strpos( $src, '?ver=' ) !== false ) {
			$src = remove_query_arg( 'ver', $src );
		}
		// Also strip Bricks-specific ?v= parameters.
		if ( strpos( $src, '?v=' ) !== false || strpos( $src, '&v=' ) !== false ) {
			$src = remove_query_arg( 'v', $src );
		}
		return $src;
	}

	// =========================================================================
	// 5. XML-RPC + PINGBACK
	// =========================================================================

	/**
	 * Remove X-Pingback header from responses.
	 *
	 * @param array $headers HTTP headers.
	 * @return array
	 */
	public function remove_x_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	// =========================================================================
	// 6. SECURITY HEADERS
	// =========================================================================

	/**
	 * Send security-related HTTP headers.
	 *
	 * @return void
	 */
	public function send_security_headers() {
		if ( is_admin() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
	}
}
