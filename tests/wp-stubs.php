<?php
/**
 * Minimal WordPress stubs, enough to run plugin classes outside WordPress.
 *
 * Post meta lives in $GLOBALS['bab_test_meta'] so a test can seed and inspect it.
 * Only what the controllers under test actually call is stubbed — if a test
 * fails with "undefined function", add it here rather than widening a test.
 *
 * @package Bricks_API_Bridge
 */

$GLOBALS['bab_test_meta'] = array();

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stand-in for WP_Error.
	 */
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code; }
		public function get_error_message() {
			return $this->message; }
		public function get_error_data() {
			return $this->data; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Stand-in for WP_REST_Request.
	 */
	class WP_REST_Request {
		private $params;
		private $body;
		private $headers;

		public function __construct( $params = array(), $body = array(), $headers = array() ) {
			$this->params  = $params;
			$this->body    = $body;
			$this->headers = $headers;
		}
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null; }
		public function get_json_params() {
			return $this->body; }
		public function get_header( $key ) {
			return $this->headers[ $key ] ?? null; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : ''; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags ); }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() {
		return true; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value ); }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return $response; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $default; }
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return (object) array(
			'ID'          => $id,
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_name'   => 'test-page',
			'post_title'  => 'Test page',
		);
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) {
		return 'Test page'; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) {
		return 'https://example.test/test-page'; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) {
		return $GLOBALS['bab_test_meta'][ $id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['bab_test_meta'][ $id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'bricks_api_bridge_purge_post_cache' ) ) {
	function bricks_api_bridge_purge_post_cache( $id ) {}
}

if ( ! class_exists( 'Bricks_API_Bridge_Backup' ) ) {
	/**
	 * Stand-in for the backup manager. Backups are not what these tests check.
	 */
	class Bricks_API_Bridge_Backup {
		public function create_backup( $post_id ) {}
	}
}
if ( ! class_exists( 'Bricks_API_Bridge_Validator' ) ) {
	/**
	 * Permissive validator: these tests exercise patch bookkeeping, not validation.
	 */
	class Bricks_API_Bridge_Validator {
		public function validate( $content, $existing_ids = array() ) {
			return array(
				'valid'    => true,
				'warnings' => array(),
				'info'     => array(),
			);
		}
	}
}
if ( ! class_exists( 'Bricks_API_Bridge_Quirks_Coercion' ) ) {
	/**
	 * Pass-through quirks coercion.
	 */
	class Bricks_API_Bridge_Quirks_Coercion {
		public static function process( $content ) {
			return array(
				'content'    => $content,
				'warnings'   => array(),
				'image_alts' => array(),
			);
		}
		public static function write_image_alts( $alts ) {
			return 0; }
	}
}
