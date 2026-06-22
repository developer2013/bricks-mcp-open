<?php
/**
 * Plugin Name: Bricks API Bridge
 * Description: REST API endpoints for Bricks Builder page data
 * Version: 1.2.3
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: Bricks API Bridge
 * Text Domain: bricks-api-bridge
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BRICKS_API_BRIDGE_VERSION', '1.2.3' );
define( 'BRICKS_API_BRIDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRICKS_API_BRIDGE_PLUGIN_FILE', __FILE__ );

/**
 * Capability gate for raw "code surface" writes (sign-code, page assets).
 *
 * These endpoints either sign Bricks code elements — a signature is bound to
 * the site salts and lets a code/PHP/query element execute — or store raw
 * JS/CSS that renders verbatim for every visitor. Both are administrator-level
 * concerns, not ordinary content edits. With the hardening flag on (default)
 * they require `manage_options`; setting the option to `false` restores the
 * legacy `edit_posts` baseline for an instant, code-free rollback.
 *
 * @return bool Whether the current user may write raw code surfaces.
 */
function bricks_api_bridge_can_write_code_surface() {
	$harden = (bool) get_option( 'bab_harden_code_routes', true );
	return current_user_can( $harden ? 'manage_options' : 'edit_posts' );
}

/**
 * Fix Apache CGI/FastCGI stripping the Authorization header.
 *
 * Many shared hosting environments run PHP via CGI or FastCGI, which strips
 * the HTTP Authorization header before PHP can read it. This recovers it
 * from alternative server variables and parses Basic auth into PHP_AUTH_USER
 * and PHP_AUTH_PW so WordPress Application Passwords can authenticate.
 *
 * Runs immediately during plugin load — before any hooks call wp_get_current_user().
 */
if ( empty( $_SERVER['PHP_AUTH_USER'] ) ) {
	$_bab_auth = '';
	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$_bab_auth = $_SERVER['HTTP_AUTHORIZATION'];
	} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$_bab_auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	} elseif ( ! empty( $_SERVER['HTTP_X_WP_AUTH'] ) ) {
		$_bab_auth = $_SERVER['HTTP_X_WP_AUTH'];
	} elseif ( function_exists( 'getallheaders' ) ) {
		foreach ( getallheaders() as $_bab_name => $_bab_value ) {
			if ( strtolower( $_bab_name ) === 'authorization' ) {
				$_bab_auth = $_bab_value;
				break;
			}
		}
	}

	if ( $_bab_auth ) {
		$_SERVER['HTTP_AUTHORIZATION'] = $_bab_auth;

		if ( 0 === stripos( $_bab_auth, 'Basic ' ) ) {
			$_bab_decoded = base64_decode( substr( $_bab_auth, 6 ) );
			if ( $_bab_decoded && false !== strpos( $_bab_decoded, ':' ) ) {
				list( $_bab_user, $_bab_pass ) = explode( ':', $_bab_decoded, 2 );
				$_SERVER['PHP_AUTH_USER'] = $_bab_user;
				$_SERVER['PHP_AUTH_PW']   = $_bab_pass;
			}
		}
	}
	unset( $_bab_auth, $_bab_decoded, $_bab_user, $_bab_pass, $_bab_name, $_bab_value );
}

/**
 * Ensure Application Passwords are available for REST API auth.
 *
 * Security plugins or hosting configs often disable Application Passwords.
 * We re-enable them so our API bridge can authenticate via Basic Auth
 * using the Application Password the user created in WP Admin.
 *
 * Opt out (respect a site's deliberate decision to keep App Passwords off)
 * by defining BAB_FORCE_APP_PASSWORDS as false, or via the
 * `bab_force_app_passwords` filter. Defaults to true (re-enable).
 */
if ( apply_filters( 'bab_force_app_passwords', defined( 'BAB_FORCE_APP_PASSWORDS' ) ? (bool) BAB_FORCE_APP_PASSWORDS : true ) ) {
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
}

/**
 * Allow font file uploads (woff2, woff, ttf, otf).
 * WordPress blocks these by default.
 */
add_filter( 'upload_mimes', 'bab_allow_font_mimes' );
function bab_allow_font_mimes( $mimes ) {
	$mimes['woff2'] = 'font/woff2';
	$mimes['woff']  = 'font/woff';
	$mimes['ttf']   = 'font/ttf';
	$mimes['otf']   = 'font/otf';
	return $mimes;
}

/**
 * Fix font MIME type detection.
 * PHP's finfo_file / getimagesize cannot detect font files correctly,
 * so WordPress rejects them even after upload_mimes whitelisting.
 */
add_filter( 'wp_check_filetype_and_ext', 'bab_fix_font_filetype', 10, 5 );
function bab_fix_font_filetype( $data, $file, $filename, $mimes, $real_mime ) {
	$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$font_mimes = array(
		'woff2' => 'font/woff2',
		'woff'  => 'font/woff',
		'ttf'   => 'font/ttf',
		'otf'   => 'font/otf',
	);
	if ( isset( $font_mimes[ $ext ] ) ) {
		$data['ext']             = $ext;
		$data['type']            = $font_mimes[ $ext ];
		$data['proper_filename'] = $filename;
	}
	return $data;
}

// Auth-diag endpoint removed (was public, leaked user info). Use connection_test instead.

/**
 * Resolve the real client IP for rate-limiting / lockout keys.
 *
 * Spoofing-resistant: proxy-forwarded headers (CF-Connecting-IP, X-Real-IP,
 * X-Forwarded-For) are honored ONLY when the request actually arrives from a
 * trusted proxy — otherwise a client could forge them to rotate the throttle
 * key and bypass the login lockout / REST rate limit. With no trusted proxies
 * configured the function returns REMOTE_ADDR (the real TCP peer) — the secure
 * default.
 *
 * Configure proxies when the site sits behind Cloudflare / a load balancer, as
 * an array of IPs or IPv4 CIDRs (any one source):
 *   - define( 'BAB_TRUSTED_PROXIES', array( '173.245.48.0/20', ... ) )
 *   - update_option( 'bab_trusted_proxies', array( ... ) )
 *   - add_filter( 'bab_trusted_proxies', ... )
 * Set it to '*' to trust forwarded headers from any source (legacy behavior /
 * instant rollback).
 *
 * @return string
 */
function bricks_api_bridge_get_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

	$trusted = apply_filters(
		'bab_trusted_proxies',
		defined( 'BAB_TRUSTED_PROXIES' ) ? BAB_TRUSTED_PROXIES : get_option( 'bab_trusted_proxies', array() )
	);

	$trust_all = ( '*' === $trusted || true === $trusted );
	if ( $trust_all || ( is_array( $trusted ) && ! empty( $trusted ) && bricks_api_bridge_ip_in_ranges( $remote, $trusted ) ) ) {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$candidate = trim( strtok( $_SERVER[ $header ], ',' ) );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}
	}

	return $remote;
}

/**
 * Test whether an IP falls within a list of exact IPs / IPv4 CIDR ranges.
 *
 * @param string $ip     The IP to test.
 * @param mixed  $ranges Array of IPs (exact match) or IPv4 CIDRs ("10.0.0.0/8").
 * @return bool
 */
function bricks_api_bridge_ip_in_ranges( $ip, $ranges ) {
	foreach ( (array) $ranges as $range ) {
		$range = trim( (string) $range );
		if ( '' === $range ) {
			continue;
		}
		if ( false === strpos( $range, '/' ) ) {
			if ( $ip === $range ) {
				return true;
			}
			continue;
		}
		list( $subnet, $bits ) = explode( '/', $range, 2 );
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		if ( false === $ip_long || false === $subnet_long ) {
			continue; // Not IPv4 — skip (IPv6 CIDR unsupported; use exact match).
		}
		$mask = -1 << ( 32 - (int) $bits );
		if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Fallback: authenticate REST API requests via Basic Auth + wp_authenticate()
 * if Application Passwords still don't work (e.g. user uses regular WP password).
 * Only active for our own REST namespace.
 *
 * @param int|false $user_id The current user ID or false.
 * @return int|false
 */
function bricks_api_bridge_basic_auth( $user_id ) {
	if ( $user_id ) {
		return $user_id;
	}

	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return $user_id;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	if ( false === strpos( $request_uri, '/bricks-bridge/' ) ) {
		return $user_id;
	}

	$php_user = isset( $_SERVER['PHP_AUTH_USER'] ) ? $_SERVER['PHP_AUTH_USER'] : '';
	$php_pass = isset( $_SERVER['PHP_AUTH_PW'] ) ? $_SERVER['PHP_AUTH_PW'] : '';

	if ( ! $php_user || ! $php_pass ) {
		return $user_id;
	}

	// Rate limit check for REST Basic Auth. Use the same spoofing-resistant IP
	// source as the rest_rate_limit reader so the throttle key matches on both
	// sides (previously this wrote REMOTE_ADDR while the reader used a
	// header-derived IP — the keys never matched on proxied sites).
	$ip          = bricks_api_bridge_get_client_ip();
	$rl_key      = 'bab_rest_attempts_' . md5( $ip );
	$rl_attempts = (int) get_transient( $rl_key );
	if ( $rl_attempts >= 5 ) {
		return $user_id; // Silently reject — REST rate limit will return 429.
	}

	// Try regular WordPress authentication as fallback.
	$user = wp_authenticate( $php_user, $php_pass );
	if ( ! is_wp_error( $user ) ) {
		delete_transient( $rl_key );
		return $user->ID;
	}

	// Record failed REST auth attempt.
	set_transient( $rl_key, $rl_attempts + 1, 900 );

	return $user_id;
}

add_filter( 'determine_current_user', 'bricks_api_bridge_basic_auth', 30 );

/**
 * Load plugin include files.
 *
 * @return void
 */
function bricks_api_bridge_load_includes() {
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-validator.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-autofix.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-quirks-coercion.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-backup-manager.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-pages-controller.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-templates-controller.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-presets-controller.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-global-classes-controller.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-element-search.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-responsive-inference.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-rest-controller.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-seo-controller.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-site-controller.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-dynamic-tags.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-design-tokens.php';
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/css-guard.php';

	// Security hardening — user enumeration, rate limiting, version hiding.
	require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-security-hardening.php';
	$security = new Bricks_API_Bridge_Security_Hardening();
	$security->init();

	// Security audit (read-only posture report). Feature-flagged: flipping the
	// option to false unregisters its routes for an instant, code-free rollback.
	if ( get_option( 'bab_security_audit_enabled', true ) ) {
		require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-security-audit.php';
		$security_audit = new Bricks_API_Bridge_Security_Audit();
		$security_audit->init();
	}

	// Admin "Connect" interface — guided setup (status, Application Password,
	// client-config generator, connection test). Admin-only; registers a menu
	// and adds no REST routes. Feature-flagged for an instant, code-free rollback.
	if ( is_admin() && get_option( 'bab_admin_ui_enabled', true ) ) {
		// Full-state backup/export — loaded before the admin UI so its Backup
		// section can use it. Admin-only, no REST routes, additive.
		// Feature-flagged for an instant, code-free rollback.
		if ( get_option( 'bab_backup_export_enabled', true ) ) {
			require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-backup-export.php';
		}
		require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-admin-ui.php';
		Bricks_API_Bridge_Admin_UI::init();
	}
}

add_action( 'plugins_loaded', 'bricks_api_bridge_load_includes' );

/**
 * Polylang clone: replicate _bab_page_assets from source to target.
 *
 * Polylang's "+ Translate" button copies the page row plus bricks_data, but
 * leaves _bab_page_assets unset on the new translation. The clone then
 * renders without the source page's per-page CSS / JS (custom typography,
 * GSAP intros). Hook into pll_save_post — fires when Polylang creates or
 * links a translation — and clone the assets meta verbatim. Element IDs
 * are preserved across translations, so selectors in the CSS / JS still
 * match.
 */
add_action( 'pll_save_post', 'bricks_api_bridge_polylang_clone_page_assets', 10, 3 );
function bricks_api_bridge_polylang_clone_page_assets( $post_id, $post, $translations ) {
	if ( ! function_exists( 'pll_get_post' ) || empty( $translations ) || ! is_array( $translations ) ) {
		return;
	}
	$existing = get_post_meta( $post_id, '_bab_page_assets', true );
	if ( ! empty( $existing ) ) {
		return;
	}
	foreach ( $translations as $lang => $sibling_id ) {
		$sibling_id = (int) $sibling_id;
		if ( $sibling_id <= 0 || $sibling_id === $post_id ) {
			continue;
		}
		$assets = get_post_meta( $sibling_id, '_bab_page_assets', true );
		if ( ! empty( $assets ) ) {
			update_post_meta( $post_id, '_bab_page_assets', $assets );
			return;
		}
	}
}

/**
 * Register dynamic data tags for CSS variables.
 *
 * @return void
 */
function bricks_api_bridge_register_dynamic_tags() {
	if ( class_exists( 'Bricks_API_Bridge_Dynamic_Tags' ) ) {
		$dynamic_tags = new Bricks_API_Bridge_Dynamic_Tags();
		$dynamic_tags->register();
	}
}

add_action( 'init', 'bricks_api_bridge_register_dynamic_tags' );

/**
 * Register REST API routes.
 *
 * @return void
 */
function bricks_api_bridge_register_routes() {
	$controller = new Bricks_API_Bridge_REST_Controller();
	$controller->register_routes();

	// Design Tokens endpoints.
	$tokens = new Bricks_API_Bridge_Design_Tokens();
	$tokens->register_routes();
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_routes' );

/**
 * Record server-side telemetry on every API response.
 * Hooks into rest_post_dispatch to capture timing and status.
 */
add_filter( 'rest_post_dispatch', 'bricks_api_bridge_record_telemetry', 10, 3 );
function bricks_api_bridge_record_telemetry( $response, $server, $request ) {
	$route = $request->get_route();
	if ( false === strpos( $route, '/bricks-bridge/' ) ) {
		return $response;
	}

	// Extract endpoint name (strip namespace + ID params).
	$endpoint = preg_replace( '#^/bricks-bridge/v1#', '', $route );
	$endpoint = preg_replace( '#/\d+#', '/{id}', $endpoint );

	$status    = $response->get_status();
	$is_error  = $status >= 400;
	$method    = $request->get_method();
	$key       = $method . ' ' . $endpoint;

	// Update telemetry (lightweight — single option read/write).
	$telemetry = get_option( 'bab_telemetry', array() );
	if ( ! isset( $telemetry[ $key ] ) ) {
		$telemetry[ $key ] = array( 'calls' => 0, 'errors' => 0, 'last_call' => '' );
	}
	$telemetry[ $key ]['calls']++;
	if ( $is_error ) {
		$telemetry[ $key ]['errors']++;
	}
	$telemetry[ $key ]['last_call'] = gmdate( 'c' );
	$telemetry[ $key ]['error_rate'] = $telemetry[ $key ]['calls'] > 0
		? round( $telemetry[ $key ]['errors'] / $telemetry[ $key ]['calls'], 4 )
		: 0;

	update_option( 'bab_telemetry', $telemetry, false );

	return $response;
}

/**
 * Rate limit REST API requests to our namespace.
 *
 * Limits each user to 200 requests per 60-second window.
 * Adds X-RateLimit-Limit, X-RateLimit-Remaining, and X-RateLimit-Reset headers.
 *
 * @param mixed           $result  Response to replace the requested response with.
 * @param WP_REST_Server  $server  REST server instance.
 * @param WP_REST_Request $request The request.
 * @return mixed|WP_Error
 */
function bricks_api_bridge_rate_limit( $result, $server, $request ) {
	$route = $request->get_route();
	if ( false === strpos( $route, '/bricks-bridge/' ) ) {
		return $result;
	}

	$user_id = get_current_user_id();
	$key     = 'bab_rate_' . $user_id;
	$limit   = 200;
	$window  = 60;

	// Use wp_cache (Redis/Memcached if available) to avoid DB queries per request.
	// Falls back to in-memory object cache (per-request) on hosts without persistent cache.
	$current = wp_cache_get( $key, 'bab_rate' );
	if ( false === $current ) {
		wp_cache_set( $key, 1, 'bab_rate', $window );
		$current = 1;
	} else {
		$current = (int) $current + 1;
		wp_cache_set( $key, $current, 'bab_rate', $window );
	}

	// Add rate limit headers via filter.
	add_filter( 'rest_post_dispatch', function ( $response ) use ( $limit, $current, $window ) {
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'X-RateLimit-Limit', $limit );
			$response->header( 'X-RateLimit-Remaining', max( 0, $limit - $current ) );
			$response->header( 'X-RateLimit-Reset', $window );
		}
		return $response;
	} );

	if ( $current > $limit ) {
		return new WP_Error(
			'bricks_api_bridge_rate_limited',
			__( 'Rate limit exceeded. Please wait before making more requests.', 'bricks-api-bridge' ),
			array(
				'status' => 429,
				'limit'  => $limit,
				'window' => $window,
			)
		);
	}

	return $result;
}

add_filter( 'rest_pre_dispatch', 'bricks_api_bridge_rate_limit', 10, 3 );

/**
 * Purge post-level caches after API updates.
 *
 * Regenerates Bricks CSS and clears common caching plugin caches.
 * Called after update_page, build_page, patch_page, append_elements,
 * and restore_backup.
 *
 * @param int $post_id The post ID to purge caches for.
 * @return void
 */
function bricks_api_bridge_purge_post_cache( $post_id ) {
	// Bricks CSS regeneration.
	if ( class_exists( '\Bricks\Assets' ) && method_exists( '\Bricks\Assets', 'generate_css_file' ) ) {
		\Bricks\Assets::generate_css_file( $post_id );
	}

	// WP Super Cache.
	if ( function_exists( 'wp_cache_post_change' ) ) {
		wp_cache_post_change( $post_id );
	}

	// LiteSpeed Cache.
	do_action( 'litespeed_purge_post', $post_id );

	// WP Rocket.
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}

	// Generic hook for other integrations.
	do_action( 'bricks_api_bridge_after_update', $post_id );
}

/**
 * Rotate global data backups (keeps last 3).
 *
 * Stores backups as transients with a week-long TTL.
 * Rotates: slot 2->3, 1->2, new->1.
 *
 * @param string $type The backup type (e.g. 'theme_styles', 'color_palette').
 * @param mixed  $data The current data to backup.
 * @return void
 */
function bricks_api_bridge_rotate_global_backup( $type, $data ) {
	$prefix = 'bab_backup_' . $type . '_';

	// Rotate: 2→3 FIRST, then 1→2 (prevents overwrite-before-read bug).
	$old_slot2 = get_transient( $prefix . '2' );
	if ( false !== $old_slot2 ) {
		set_transient( $prefix . '3', $old_slot2, WEEK_IN_SECONDS );
	}

	$old_slot1 = get_transient( $prefix . '1' );
	if ( false !== $old_slot1 ) {
		set_transient( $prefix . '2', $old_slot1, WEEK_IN_SECONDS );
	}

	// Write new backup to slot 1.
	set_transient( $prefix . '1', array(
		'data'      => $data,
		'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
	), WEEK_IN_SECONDS );
}

/**
 * Debug endpoint — shows what auth headers PHP actually receives.
 * Remove after successful setup.
 */
// Debug endpoint removed — was only needed during initial setup.

/**
 * Utility: Set arbitrary post_meta for Bricks pages (admin-only).
 */
function bricks_api_bridge_register_set_meta() {
	register_rest_route( 'bricks-bridge/v1', '/set-meta/(?P<id>\d+)', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( $request ) {
			$post_id = (int) $request['id'];
			if ( ! get_post( $post_id ) ) {
				return new WP_REST_Response( array( 'error' => 'Post not found' ), 404 );
			}
			$body = $request->get_json_params();
			if ( empty( $body ) || ! is_array( $body ) ) {
				return new WP_REST_Response( array( 'error' => 'JSON body required with key/value pairs' ), 400 );
			}
			$allowed_prefixes = array( '_bricks_' );
			$results = array();
			foreach ( $body as $key => $value ) {
				$allowed = false;
				foreach ( $allowed_prefixes as $prefix ) {
					if ( 0 === strpos( $key, $prefix ) ) {
						$allowed = true;
						break;
					}
				}
				if ( ! $allowed ) {
					$results[ $key ] = 'skipped (only _bricks_ prefixed keys allowed)';
					continue;
				}
				update_post_meta( $post_id, $key, $value );
				$results[ $key ] = 'set';
			}
			return new WP_REST_Response( array( 'post_id' => $post_id, 'results' => $results ), 200 );
		},
	));
}
add_action( 'rest_api_init', 'bricks_api_bridge_register_set_meta' );

// Debug-meta endpoint removed — leaked all post meta to edit_posts users.

/**
 * Output compiled custom CSS for Bricks elements on the frontend.
 *
 * Bricks may not replace %root% with the actual element selector for
 * data written via the REST API. This hook reads _cssCustom from each
 * element and outputs a <style> block with proper #brxe-{id} selectors.
 */
function bricks_api_bridge_compile_custom_css() {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$post_id = get_the_ID();

	// Collect all element arrays to compile.
	$all_data = array();

	// 1. Current page content.
	if ( $post_id ) {
		$page_data = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$page_data = \Bricks\Database::get_data( $post_id, 'content' );
		}
		if ( empty( $page_data ) ) {
			$meta_keys = array( '_bricks_page_content_2', '_bricks_page_content', '_bricks_page_data' );
			foreach ( $meta_keys as $key ) {
				$meta = get_post_meta( $post_id, $key, true );
				if ( ! empty( $meta ) ) {
					$page_data = $meta;
					break;
				}
			}
		}
		if ( ! empty( $page_data ) && is_array( $page_data ) ) {
			$all_data[] = $page_data;
		}
	}

	// 2. Active templates — ALL types, single query with transient cache.
	// Previously 9 separate get_posts() calls (one per template type).
	$tpl_ids = get_transient( 'bab_compiled_css_tpl_ids' );
	if ( false === $tpl_ids ) {
		$tpl_ids = get_posts( array(
			'post_type'      => 'bricks_template',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => '_bricks_template_type',
					'value'   => array( 'header', 'footer', 'section', 'content', 'single', 'archive', 'popup', 'search', 'error' ),
					'compare' => 'IN',
				),
			),
			'fields'         => 'ids',
		) );
		set_transient( 'bab_compiled_css_tpl_ids', $tpl_ids, 300 );
	}

	foreach ( $tpl_ids as $tpl_id ) {
		$tpl_type = get_post_meta( $tpl_id, '_bricks_template_type', true );
		if ( in_array( $tpl_type, array( 'header', 'footer' ), true ) ) {
			$area     = $tpl_type;
			$meta_key = '_bricks_page_' . $area . '_2';
		} else {
			$area     = 'content';
			$meta_key = '_bricks_page_content_2';
		}

		$tpl_data = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$tpl_data = \Bricks\Database::get_data( $tpl_id, $area );
		}
		if ( empty( $tpl_data ) ) {
			$tpl_data = get_post_meta( $tpl_id, $meta_key, true );
		}
		if ( ! empty( $tpl_data ) && is_array( $tpl_data ) ) {
			$all_data[] = $tpl_data;
		}
	}

	if ( empty( $all_data ) ) {
		return;
	}

	$css_output = '';

	foreach ( $all_data as $elements ) {
		foreach ( $elements as $element ) {
			if ( empty( $element['settings']['_cssCustom'] ) || empty( $element['id'] ) ) {
				continue;
			}

			$custom_css = $element['settings']['_cssCustom'];
			$selector   = '#brxe-' . $element['id'];

			// Replace %root% with the actual element selector.
			$compiled = str_replace( '%root%', $selector, $custom_css );
			$css_output .= $compiled . "\n";
		}
	}

	// NOTE: PhotoSwipe CSS overrides REMOVED (2026-03-29).
	// They broke native image-gallery lightbox navigation (opacity:1 on .pswp__bg hid images,
	// overflow:hidden on .pswp__container clipped slides during transitions).
	// Bricks 2.3+ handles PhotoSwipe positioning natively via photoswipe.min.css.

	if ( ! empty( $css_output ) ) {
		// Strip any non-CSS content (e.g. script tags) for safety.
		$css_output = wp_strip_all_tags( $css_output );
		echo '<style id="bricks-api-bridge-custom-css">' . "\n" . $css_output . '</style>' . "\n";
	}
}

add_action( 'wp_head', 'bricks_api_bridge_compile_custom_css', 9999 );

// Invalidate CSS compiler template cache when templates are saved.
add_action( 'save_post_bricks_template', function () {
	delete_transient( 'bab_compiled_css_tpl_ids' );
}, 10 );

/**
 * Fix image element lightbox links created via REST API.
 *
 * Learning: Bricks image elements with link.type='lightbox' or link.url set via API
 * render as <a href=""> (empty href). This hook injects JS that finds these broken
 * anchors and sets their href to the image's full-size URL, then initializes PhotoSwipe.
 *
 * @since 3.1.0
 */
function bricks_api_bridge_fix_image_lightbox() {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	// Check if page has image elements with link settings.
	$page_data = null;
	$meta_keys = array( '_bricks_page_content_2', '_bricks_page_content', '_bricks_page_data' );
	foreach ( $meta_keys as $key ) {
		$meta = get_post_meta( $post_id, $key, true );
		if ( ! empty( $meta ) ) {
			$page_data = $meta;
			break;
		}
	}

	if ( empty( $page_data ) || ! is_array( $page_data ) ) {
		return;
	}

	$has_lightbox_images = false;
	foreach ( $page_data as $element ) {
		if ( empty( $element['name'] ) || 'image' !== $element['name'] ) {
			continue;
		}
		$settings = isset( $element['settings'] ) ? $element['settings'] : array();
		$link     = isset( $settings['link'] ) ? $settings['link'] : array();
		if ( ! empty( $link ) && ( isset( $link['type'] ) || isset( $link['url'] ) || isset( $link['lightbox'] ) ) ) {
			$has_lightbox_images = true;
			break;
		}
	}

	if ( ! $has_lightbox_images ) {
		return;
	}

	// Enqueue PhotoSwipe (Bricks may not auto-enqueue without proper link rendering).
	if ( defined( 'BRICKS_URL_ASSETS' ) ) {
		wp_enqueue_style( 'bricks-photoswipe', BRICKS_URL_ASSETS . 'css/libs/photoswipe.min.css', array(), '5.4.4' );
		wp_enqueue_script( 'bricks-photoswipe-umd', BRICKS_URL_ASSETS . 'js/libs/photoswipe.umd.min.js', array(), '5.4.4', true );
		wp_enqueue_script( 'bricks-photoswipe-lightbox', BRICKS_URL_ASSETS . 'js/libs/photoswipe-lightbox.umd.min.js', array(), '5.4.4', true );
	}

	// Inject JS to fix empty hrefs and init PhotoSwipe.
	?>
	<script id="bab-lightbox-fix">
	window.addEventListener('load', function() {
		if (typeof PhotoSwipeLightbox === 'undefined' || typeof PhotoSwipe5 === 'undefined') return;
		var imgs = document.querySelectorAll('a.brxe-image[href=""], a.brxe-image:not([href])');
		if (!imgs.length) return;
		var sizeRe = new RegExp('-\\d+x\\d+\\.');
		imgs.forEach(function(a) {
			var img = a.querySelector('img');
			if (!img) return;
			var full = (img.src || '').replace(sizeRe, '.');
			a.href = full;
			a.setAttribute('data-pswp-width', img.naturalWidth || 1600);
			a.setAttribute('data-pswp-height', img.naturalHeight || 1200);
			a.setAttribute('data-cropped', 'true');
		});
		// Find gallery containers (parent of lightbox images).
		var galleries = new Set();
		imgs.forEach(function(a) { if (a.parentElement) galleries.add(a.parentElement); });
		galleries.forEach(function(gal) {
			gal.setAttribute('data-bab-lightbox', 'true');
			var lb = new PhotoSwipeLightbox({
				gallery: '[data-bab-lightbox]',
				children: 'a.brxe-image',
				pswpModule: PhotoSwipe5
			});
			lb.init();
		});
	});
	</script>
	<?php
}

add_action( 'wp_footer', 'bricks_api_bridge_fix_image_lightbox', 97 );

/**
 * Register per-page scripts REST API endpoints.
 *
 * Allows storing and retrieving custom JavaScript for individual pages.
 * Scripts are stored in post meta '_bab_footer_scripts' and output
 * via wp_footer on the frontend.
 */
function bricks_api_bridge_register_scripts_routes() {
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/scripts', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'bricks_api_bridge_get_page_scripts',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		array(
			'methods'             => 'PUT',
			'callback'            => 'bricks_api_bridge_update_page_scripts',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		),
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_scripts_routes' );

/**
 * Get stored scripts for a page.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_page_scripts( $request ) {
	$id      = (int) $request['id'];
	$scripts = get_post_meta( $id, '_bab_footer_scripts', true );

	return new WP_REST_Response( array(
		'page_id' => $id,
		'scripts' => $scripts ? $scripts : '',
	), 200 );
}

/**
 * Store scripts for a page.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_update_page_scripts( $request ) {
	$id   = (int) $request['id'];
	$body = $request->get_json_params();

	if ( ! get_post( $id ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_not_found',
			'message' => 'Page not found.',
		), 404 );
	}

	$scripts = isset( $body['scripts'] ) ? $body['scripts'] : '';
	update_post_meta( $id, '_bab_footer_scripts', $scripts );

	return new WP_REST_Response( array(
		'success' => true,
		'page_id' => $id,
		'length'  => strlen( $scripts ),
	), 200 );
}

/**
 * Output per-page scripts in the footer.
 *
 * Reads '_bab_footer_scripts' from post meta and outputs it
 * before the closing </body> tag on the frontend.
 */
function bricks_api_bridge_page_scripts_footer() {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$scripts = get_post_meta( $post_id, '_bab_footer_scripts', true );
	if ( ! empty( $scripts ) ) {
		echo "\n<!-- BAB Per-Page Scripts -->\n" . $scripts . "\n";
	}
}

add_action( 'wp_footer', 'bricks_api_bridge_page_scripts_footer', 99 );

// =========================================================================
// Layer 1.2: Global CSS Output Hook
// =========================================================================

/**
 * Output Bricks global custom CSS in wp_head.
 *
 * Ensures bricks_global_custom_css option is rendered in <head> for
 * CSS-only migrations (header-hiding, body resets, shared tokens).
 * Uses priority 9998 to load before our element CSS at 9999.
 */
function bricks_api_bridge_global_css_head() {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$css = get_option( 'bricks_global_custom_css', '' );
	if ( ! empty( $css ) ) {
		// Avoid duplicate output if Bricks already rendered this.
		if ( did_action( 'bricks_global_css_rendered' ) ) {
			return;
		}
		echo '<style id="bab-global-css">' . "\n" . wp_strip_all_tags( $css ) . "\n</style>\n";
	}
}

add_action( 'wp_head', 'bricks_api_bridge_global_css_head', 9998 );

// =========================================================================
// Layer 2.1: GSAP Enqueue System
// =========================================================================

/**
 * Enqueue GSAP + ScrollTrigger when page has _bab_needs_gsap flag.
 *
 * Replaces per-page CDN <script> tags with proper wp_enqueue_script.
 * Benefits: dedup across pages, browser caching, no duplicate loads.
 * Scripts output in wp_footer at priority ~20, before our JS at 98/99.
 */
function bricks_api_bridge_enqueue_gsap() {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	// Check both: explicit flag AND structured assets js_deps.
	$needs_gsap = get_post_meta( $post_id, '_bab_needs_gsap', true );
	$assets     = get_post_meta( $post_id, '_bab_page_assets', true );
	$js_deps    = ( is_array( $assets ) && ! empty( $assets['js_deps'] ) )
		? (array) $assets['js_deps']
		: array();

	if ( $needs_gsap || in_array( 'gsap', $js_deps, true ) ) {
		wp_enqueue_script(
			'gsap-core',
			'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js',
			array(),
			'3.12.5',
			true
		);
		wp_enqueue_script(
			'gsap-scrolltrigger',
			'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js',
			array( 'gsap-core' ),
			'3.12.5',
			true
		);
	}

	if ( in_array( 'lenis', $js_deps, true ) ) {
		wp_enqueue_script(
			'lenis',
			'https://unpkg.com/lenis@1/dist/lenis.min.js',
			array(),
			'1.0.0',
			true
		);
	}
}

add_action( 'wp_enqueue_scripts', 'bricks_api_bridge_enqueue_gsap' );

// =========================================================================
// Layer 2.2: Structured Per-Page Assets
// =========================================================================

/**
 * Output structured per-page CSS in wp_head.
 *
 * Reads '_bab_page_assets' JSON and outputs the 'css' field in <head>.
 * Fixes FOUC/CLS: CSS variables, font-smoothing, prep styles load early.
 * Priority 9997 = before element CSS (9999) and global CSS (9998).
 */
function bricks_api_bridge_page_assets_head() {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$assets = get_post_meta( $post_id, '_bab_page_assets', true );
	if ( empty( $assets ) || ! is_array( $assets ) ) {
		return;
	}

	if ( ! empty( $assets['css'] ) ) {
		echo '<style id="bab-page-css-' . intval( $post_id ) . '">' . "\n"
			. wp_strip_all_tags( $assets['css'] ) . "\n</style>\n";
	}

	// Above-the-fold critical bundle — inlined, render-blocking by design.
	// Keep under ~14 KB (extractor enforces budget).
	if ( ! empty( $assets['css_critical'] ) ) {
		echo '<style id="bab-page-css-critical-' . intval( $post_id ) . '">' . "\n"
			. wp_strip_all_tags( $assets['css_critical'] ) . "\n</style>\n";
	}

	// Deferred bundle — injected after window.load so it doesn't block first paint.
	// wp_json_encode handles escaping of the CSS string safely for a JS context.
	if ( ! empty( $assets['css_deferred'] ) ) {
		$deferred_json = wp_json_encode( (string) $assets['css_deferred'] );
		echo '<script id="bab-page-css-deferred-' . intval( $post_id ) . '">' . "\n"
			. '(function(){var css=' . $deferred_json . ';'
			. 'function inject(){var s=document.createElement("style");'
			. 's.id="bab-page-css-deferred-style-' . intval( $post_id ) . '";'
			. 's.textContent=css;document.head.appendChild(s);}'
			. 'if(document.readyState==="complete"){inject();}'
			. 'else{window.addEventListener("load",inject);}})();'
			. "\n</script>\n";
	}
}

add_action( 'wp_head', 'bricks_api_bridge_page_assets_head', 9997 );

/**
 * Output structured per-page JS in wp_footer.
 *
 * Outputs the 'js' field wrapped in <script>, and 'raw_footer' as-is.
 * Priority 98 = after enqueued GSAP (~20) but before legacy scripts (99).
 */
function bricks_api_bridge_page_assets_footer() {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$assets = get_post_meta( $post_id, '_bab_page_assets', true );
	if ( empty( $assets ) || ! is_array( $assets ) ) {
		return;
	}

	if ( ! empty( $assets['js'] ) ) {
		echo "\n<!-- BAB Structured JS -->\n<script>\n" . $assets['js'] . "\n</script>\n";
	}

	if ( ! empty( $assets['raw_footer'] ) ) {
		echo "\n<!-- BAB Raw Footer -->\n" . $assets['raw_footer'] . "\n";
	}
}

add_action( 'wp_footer', 'bricks_api_bridge_page_assets_footer', 98 );

// =========================================================================
// REST API: Structured Assets + GSAP Flag Endpoints
// =========================================================================

/**
 * Register structured page assets REST API endpoints.
 *
 * GET/PUT /pages/{id}/assets — read/write structured { css, js_deps, js, raw_footer }
 * PUT /pages/{id}/gsap-flag  — toggle GSAP enqueue flag
 */
function bricks_api_bridge_register_assets_routes() {
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/assets', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'bricks_api_bridge_get_page_assets',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		array(
			'methods'             => 'PUT',
			'callback'            => 'bricks_api_bridge_update_page_assets',
			'permission_callback' => function () {
				return bricks_api_bridge_can_write_code_surface();
			},
		),
	));

	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/gsap-flag', array(
		array(
			'methods'             => 'PUT',
			'callback'            => 'bricks_api_bridge_set_gsap_flag',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_assets_routes' );

/**
 * Get structured assets for a page.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_page_assets( $request ) {
	$id     = (int) $request['id'];
	$assets = get_post_meta( $id, '_bab_page_assets', true );

	return new WP_REST_Response( array(
		'page_id'    => $id,
		'assets'     => $assets ? $assets : null,
		'needs_gsap' => (bool) get_post_meta( $id, '_bab_needs_gsap', true ),
	), 200 );
}

/**
 * Store structured assets for a page.
 *
 * Accepts JSON with keys: css, js_deps, js, raw_footer.
 * Auto-sets _bab_needs_gsap flag when js_deps includes 'gsap'.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_update_page_assets( $request ) {
	$id   = (int) $request['id'];
	$body = $request->get_json_params();

	if ( ! get_post( $id ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_not_found',
			'message' => 'Page not found.',
		), 404 );
	}

	// CSS editability guard. Only the generic `css` field is checked;
	// css_critical/css_deferred are explicitly-named infra and always pass. Mode
	// 'off' (default) is a no-op; 'warn' surfaces a response warning; 'block'
	// rejects the write (422) so editable CSS never lands in _bab_page_assets.
	$bab_css_guard    = bricks_api_bridge_editable_css_guard_mode();
	$bab_css_warnings = array();
	if ( 'off' !== $bab_css_guard && isset( $body['css'] ) ) {
		$bab_css_class = bricks_api_bridge_classify_css( $body['css'] );
		if ( 'editable' === $bab_css_class['kind'] || 'mixed' === $bab_css_class['kind'] ) {
			$bab_css_rules = implode( ', ', array_slice( $bab_css_class['editable'], 0, 8 ) );
			if ( 'block' === $bab_css_guard ) {
				return new WP_REST_Response( array(
					'code'           => 'bab_editable_css_blocked',
					'message'        => 'Editable CSS rejected (BAB_GUARD_EDITABLE_CSS=block). The css field lands in plugin-private _bab_page_assets (wp_head 9997, before element CSS) and is not editable in the Bricks builder. Put layout/responsive/visual CSS in a native channel — element _cssCustom, page customCss via /page-settings, or global CSS — and reserve this field for infra (@font-face / :root vars / @keyframes / critical).',
					'editable_rules' => array_slice( $bab_css_class['editable'], 0, 8 ),
				), 422 );
			}
			$bab_css_warnings[] = 'Editable CSS stored in plugin-private _bab_page_assets (wp_head 9997, before element CSS) — invisible/uneditable in the Bricks builder. Move to _cssCustom / page customCss / global CSS. Editable rules: ' . $bab_css_rules;
		}
	}

	$assets = array();

	if ( isset( $body['css'] ) ) {
		$assets['css'] = $body['css'];
	}
	if ( isset( $body['js_deps'] ) && is_array( $body['js_deps'] ) ) {
		$assets['js_deps'] = array_values( array_map( 'sanitize_text_field', $body['js_deps'] ) );
	}
	if ( isset( $body['js'] ) ) {
		$assets['js'] = $body['js'];
	}
	if ( isset( $body['raw_footer'] ) ) {
		$assets['raw_footer'] = $body['raw_footer'];
	}
	// Critical-CSS fields: css_critical inlined in <head>, css_deferred injected post-load.
	if ( isset( $body['css_critical'] ) ) {
		$assets['css_critical'] = $body['css_critical'];
	}
	if ( isset( $body['css_deferred'] ) ) {
		$assets['css_deferred'] = $body['css_deferred'];
	}

	update_post_meta( $id, '_bab_page_assets', $assets );

	// Auto-set GSAP flag from js_deps.
	if ( ! empty( $assets['js_deps'] ) && in_array( 'gsap', $assets['js_deps'], true ) ) {
		update_post_meta( $id, '_bab_needs_gsap', '1' );
	}

	$bab_assets_response = array(
		'success' => true,
		'page_id' => $id,
		'assets'  => $assets,
	);
	if ( ! empty( $bab_css_warnings ) ) {
		$bab_assets_response['warnings'] = $bab_css_warnings;
	}
	return new WP_REST_Response( $bab_assets_response, 200 );
}

/**
 * Toggle the GSAP enqueue flag for a page.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_set_gsap_flag( $request ) {
	$id   = (int) $request['id'];
	$body = $request->get_json_params();

	if ( ! get_post( $id ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_not_found',
			'message' => 'Page not found.',
		), 404 );
	}

	$enabled = ! empty( $body['enabled'] );

	if ( $enabled ) {
		update_post_meta( $id, '_bab_needs_gsap', '1' );
	} else {
		delete_post_meta( $id, '_bab_needs_gsap' );
	}

	return new WP_REST_Response( array(
		'success' => true,
		'page_id' => $id,
		'gsap'    => $enabled,
	), 200 );
}

/**
 * Register SEO & Open Graph REST API endpoints.
 *
 * Allows storing and retrieving SEO meta data for individual pages.
 * Data is stored in post meta with '_bab_seo_' prefix and output
 * via wp_head on the frontend.
 */
function bricks_api_bridge_register_seo_routes() {
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/seo', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'bricks_api_bridge_get_page_seo',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		array(
			'methods'             => 'PUT',
			'callback'            => 'bricks_api_bridge_update_page_seo',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
	));

	// Schema/JSON-LD endpoint.
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/schema', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'bricks_api_bridge_get_page_schema',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		array(
			'methods'             => 'PUT',
			'callback'            => 'bricks_api_bridge_update_page_schema',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
	));

	// SEO Audit endpoint (bulk scan all pages).
	register_rest_route( 'bricks-bridge/v1', '/seo/audit', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'bricks_api_bridge_seo_audit',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
	));

	// SEO Analyze endpoint (deep single-page analysis).
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/seo-analyze', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'bricks_api_bridge_seo_analyze',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_seo_routes' );

// Register advanced SEO controller routes.
add_action( 'rest_api_init', function () {
	if ( class_exists( 'Bricks_API_Bridge_SEO_Controller' ) ) {
		$seo = new Bricks_API_Bridge_SEO_Controller();
		$seo->register_routes();
	}
});

// Register site controller routes (settings, page creation, cache, menus, stats).
add_action( 'rest_api_init', function () {
	if ( class_exists( 'Bricks_API_Bridge_Site_Controller' ) ) {
		$site = new Bricks_API_Bridge_Site_Controller();
		$site->register_routes();
	}
});

// Handle redirects on frontend.
add_action( 'template_redirect', array( 'Bricks_API_Bridge_SEO_Controller', 'handle_redirects' ), 1 );

/**
 * Get stored SEO data for a page.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_page_seo( $request ) {
	$id = (int) $request['id'];

	if ( ! get_post( $id ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_not_found',
			'message' => 'Page not found.',
		), 404 );
	}

	return new WP_REST_Response( array(
		'page_id'             => $id,
		'seo_title'           => get_post_meta( $id, '_bab_seo_title', true ) ?: '',
		'description'         => get_post_meta( $id, '_bab_seo_description', true ) ?: '',
		'og_image'            => get_post_meta( $id, '_bab_seo_og_image', true ) ?: '',
		'keywords'            => get_post_meta( $id, '_bab_seo_keywords', true ) ?: '',
		'og_type'             => get_post_meta( $id, '_bab_seo_og_type', true ) ?: '',
		'canonical'           => get_post_meta( $id, '_bab_seo_canonical', true ) ?: '',
		'noindex'             => (bool) get_post_meta( $id, '_bab_seo_noindex', true ),
		'nofollow'            => (bool) get_post_meta( $id, '_bab_seo_nofollow', true ),
		'focus_keyword'       => get_post_meta( $id, '_bab_seo_focus_keyword', true ) ?: '',
		'og_title'            => get_post_meta( $id, '_bab_seo_og_title', true ) ?: '',
		'twitter_title'       => get_post_meta( $id, '_bab_seo_twitter_title', true ) ?: '',
		'twitter_description' => get_post_meta( $id, '_bab_seo_twitter_description', true ) ?: '',
		'twitter_image'       => get_post_meta( $id, '_bab_seo_twitter_image', true ) ?: '',
	), 200 );
}

/**
 * Store SEO data for a page. Partial update — only sent fields are updated.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_update_page_seo( $request ) {
	$id   = (int) $request['id'];
	$body = $request->get_json_params();

	if ( ! get_post( $id ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_not_found',
			'message' => 'Page not found.',
		), 404 );
	}

	$fields = array(
		'seo_title'           => '_bab_seo_title',
		'description'         => '_bab_seo_description',
		'og_image'            => '_bab_seo_og_image',
		'keywords'            => '_bab_seo_keywords',
		'og_type'             => '_bab_seo_og_type',
		'canonical'           => '_bab_seo_canonical',
		'focus_keyword'       => '_bab_seo_focus_keyword',
		'og_title'            => '_bab_seo_og_title',
		'twitter_title'       => '_bab_seo_twitter_title',
		'twitter_description' => '_bab_seo_twitter_description',
		'twitter_image'       => '_bab_seo_twitter_image',
	);

	// Boolean fields (stored as '1' or '').
	$bool_fields = array(
		'noindex'  => '_bab_seo_noindex',
		'nofollow' => '_bab_seo_nofollow',
	);

	$updated = array();
	foreach ( $fields as $param => $meta_key ) {
		if ( isset( $body[ $param ] ) ) {
			update_post_meta( $id, $meta_key, sanitize_text_field( $body[ $param ] ) );
			$updated[] = $param;
		}
	}
	foreach ( $bool_fields as $param => $meta_key ) {
		if ( isset( $body[ $param ] ) ) {
			update_post_meta( $id, $meta_key, $body[ $param ] ? '1' : '' );
			$updated[] = $param;
		}
	}

	if ( empty( $updated ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_no_data',
			'message' => 'No SEO fields provided. Accepted: seo_title, description, og_image, keywords, og_type, canonical, noindex, nofollow, focus_keyword, og_title, twitter_title, twitter_description, twitter_image',
		), 400 );
	}

	return new WP_REST_Response( array(
		'success' => true,
		'page_id' => $id,
		'updated' => $updated,
	), 200 );
}

/**
 * Output SEO meta tags and Open Graph data in <head>.
 *
 * Only outputs tags when SEO data has been set via the API.
 * Does not conflict with SEO plugins — no output if no data exists.
 */
function bricks_api_bridge_seo_head() {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$seo_title           = get_post_meta( $post_id, '_bab_seo_title', true );
	$description         = get_post_meta( $post_id, '_bab_seo_description', true );
	$og_image            = get_post_meta( $post_id, '_bab_seo_og_image', true );
	$keywords            = get_post_meta( $post_id, '_bab_seo_keywords', true );
	$og_type             = get_post_meta( $post_id, '_bab_seo_og_type', true );
	$canonical           = get_post_meta( $post_id, '_bab_seo_canonical', true );
	$noindex             = get_post_meta( $post_id, '_bab_seo_noindex', true );
	$nofollow            = get_post_meta( $post_id, '_bab_seo_nofollow', true );
	$og_title_override   = get_post_meta( $post_id, '_bab_seo_og_title', true );
	$twitter_title       = get_post_meta( $post_id, '_bab_seo_twitter_title', true );
	$twitter_description = get_post_meta( $post_id, '_bab_seo_twitter_description', true );
	$twitter_image       = get_post_meta( $post_id, '_bab_seo_twitter_image', true );

	// Guard: no output if no SEO data set.
	$has_data = $seo_title || $description || $og_image || $keywords || $og_type
		|| $canonical || $noindex || $nofollow || $og_title_override
		|| $twitter_title || $twitter_description || $twitter_image;
	if ( ! $has_data ) {
		return;
	}

	$title     = $seo_title ? esc_attr( $seo_title ) : esc_attr( get_the_title( $post_id ) );
	$desc      = esc_attr( $description );
	$image     = esc_url( $og_image );
	$type      = $og_type ? esc_attr( $og_type ) : 'website';
	$url       = esc_url( get_permalink( $post_id ) );
	$site_name = esc_attr( get_bloginfo( 'name' ) );

	echo "\n<!-- BAB SEO Meta Tags -->\n";

	if ( $description ) {
		echo '<meta name="description" content="' . $desc . '" />' . "\n";
	}
	if ( $keywords ) {
		echo '<meta name="keywords" content="' . esc_attr( $keywords ) . '" />' . "\n";
	}

	// Canonical URL.
	if ( $canonical ) {
		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
	}

	// Robots meta (noindex/nofollow).
	$robots_parts = array();
	if ( $noindex ) {
		$robots_parts[] = 'noindex';
	}
	if ( $nofollow ) {
		$robots_parts[] = 'nofollow';
	}
	if ( ! empty( $robots_parts ) ) {
		echo '<meta name="robots" content="' . esc_attr( implode( ', ', $robots_parts ) ) . '" />' . "\n";
	}

	// Open Graph — use og_title override if set, otherwise fall back to seo_title.
	$og_title = $og_title_override ? esc_attr( $og_title_override ) : $title;
	echo '<meta property="og:title" content="' . $og_title . '" />' . "\n";
	if ( $description ) {
		echo '<meta property="og:description" content="' . $desc . '" />' . "\n";
	}
	if ( $og_image ) {
		echo '<meta property="og:image" content="' . $image . '" />' . "\n";
	}
	echo '<meta property="og:url" content="' . $url . '" />' . "\n";
	echo '<meta property="og:type" content="' . $type . '" />' . "\n";
	echo '<meta property="og:site_name" content="' . $site_name . '" />' . "\n";

	// Twitter Card — use separate overrides if set.
	echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
	$tw_title = $twitter_title ? esc_attr( $twitter_title ) : $title;
	echo '<meta name="twitter:title" content="' . $tw_title . '" />' . "\n";
	$tw_desc = $twitter_description ? esc_attr( $twitter_description ) : $desc;
	if ( $tw_desc ) {
		echo '<meta name="twitter:description" content="' . $tw_desc . '" />' . "\n";
	}
	$tw_image = $twitter_image ? esc_url( $twitter_image ) : $image;
	if ( $tw_image ) {
		echo '<meta name="twitter:image" content="' . $tw_image . '" />' . "\n";
	}
	echo "<!-- /BAB SEO Meta Tags -->\n";

	// JSON-LD Schema output.
	$schema_json = get_post_meta( $post_id, '_bab_schema_json', true );
	if ( $schema_json ) {
		$schema = json_decode( $schema_json, true );
		if ( is_array( $schema ) ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
		}
	}
}

add_action( 'wp_head', 'bricks_api_bridge_seo_head', 1 );

/**
 * Override the document <title> tag when SEO title is set.
 *
 * @param array $title_parts The document title parts.
 * @return array
 */
function bricks_api_bridge_seo_document_title( $title_parts ) {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $title_parts;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return $title_parts;
	}

	$seo_title = get_post_meta( $post_id, '_bab_seo_title', true );
	if ( $seo_title ) {
		$title_parts['title'] = $seo_title;
	}

	return $title_parts;
}

add_filter( 'document_title_parts', 'bricks_api_bridge_seo_document_title' );

/**
 * Get JSON-LD schema data for a page.
 */
function bricks_api_bridge_get_page_schema( $request ) {
	$id = (int) $request['id'];

	if ( ! get_post( $id ) ) {
		return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Page not found.' ), 404 );
	}

	$schema_json = get_post_meta( $id, '_bab_schema_json', true );
	$schemas     = array();

	if ( $schema_json ) {
		$decoded = json_decode( $schema_json, true );
		if ( is_array( $decoded ) ) {
			// Support single schema or array of schemas.
			if ( isset( $decoded['@type'] ) ) {
				$schemas = array( $decoded );
			} else {
				$schemas = array_values( $decoded );
			}
		}
	}

	return new WP_REST_Response( array(
		'page_id' => $id,
		'schemas' => $schemas,
		'count'   => count( $schemas ),
	), 200 );
}

/**
 * Store JSON-LD schema data for a page.
 *
 * Accepts a single schema object or an array of schemas.
 * Automatically adds @context if missing.
 */
function bricks_api_bridge_update_page_schema( $request ) {
	$id   = (int) $request['id'];
	$body = $request->get_json_params();

	if ( ! get_post( $id ) ) {
		return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Page not found.' ), 404 );
	}

	$schema = isset( $body['schema'] ) ? $body['schema'] : null;
	if ( empty( $schema ) ) {
		return new WP_REST_Response( array(
			'code'    => 'invalid_data',
			'message' => 'schema object or array is required. Example: {"schema": {"@type": "LocalBusiness", "name": "..."}}',
		), 400 );
	}

	// Normalize: ensure we always store an array of schemas.
	if ( isset( $schema['@type'] ) ) {
		$schemas = array( $schema );
	} else {
		$schemas = array_values( $schema );
	}

	// Add @context if missing.
	foreach ( $schemas as &$s ) {
		if ( is_array( $s ) && ! isset( $s['@context'] ) ) {
			$s['@context'] = 'https://schema.org';
		}
	}
	unset( $s );

	// Validate each schema has @type.
	foreach ( $schemas as $s ) {
		if ( ! is_array( $s ) || empty( $s['@type'] ) ) {
			return new WP_REST_Response( array(
				'code'    => 'invalid_schema',
				'message' => 'Each schema must have an @type property.',
			), 400 );
		}
	}

	// Store as single object if only one, array if multiple.
	$to_store = count( $schemas ) === 1 ? $schemas[0] : $schemas;
	update_post_meta( $id, '_bab_schema_json', wp_json_encode( $to_store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

	return new WP_REST_Response( array(
		'success' => true,
		'page_id' => $id,
		'schemas' => $schemas,
		'count'   => count( $schemas ),
	), 200 );
}

/**
 * SEO Audit — bulk scan all published pages.
 *
 * Scores each page 0-100 based on: title length, description length,
 * OG tags, headings (H1), image alts, canonical, focus keyword.
 */
function bricks_api_bridge_seo_audit( $request ) {
	$pages = get_posts( array(
		'post_type'      => array( 'page', 'post' ),
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'fields'         => 'ids',
	));

	$results = array();
	$total_score = 0;

	foreach ( $pages as $post_id ) {
		$score    = 0;
		$max      = 0;
		$issues   = array();
		$warnings = array();

		$title       = get_post_meta( $post_id, '_bab_seo_title', true );
		$description = get_post_meta( $post_id, '_bab_seo_description', true );
		$og_image    = get_post_meta( $post_id, '_bab_seo_og_image', true );
		$canonical   = get_post_meta( $post_id, '_bab_seo_canonical', true );
		$focus_kw    = get_post_meta( $post_id, '_bab_seo_focus_keyword', true );
		$noindex     = get_post_meta( $post_id, '_bab_seo_noindex', true );
		$schema_json = get_post_meta( $post_id, '_bab_schema_json', true );

		// Title (20 pts): exists + length 30-60.
		$max += 20;
		if ( $title ) {
			$len = mb_strlen( $title );
			if ( $len >= 30 && $len <= 60 ) {
				$score += 20;
			} elseif ( $len > 0 ) {
				$score += 10;
				$warnings[] = "Title length: {$len} chars (ideal: 30-60)";
			}
		} else {
			$issues[] = 'Missing SEO title';
		}

		// Description (20 pts): exists + length 120-160.
		$max += 20;
		if ( $description ) {
			$len = mb_strlen( $description );
			if ( $len >= 120 && $len <= 160 ) {
				$score += 20;
			} elseif ( $len > 0 ) {
				$score += 10;
				$warnings[] = "Description length: {$len} chars (ideal: 120-160)";
			}
		} else {
			$issues[] = 'Missing meta description';
		}

		// OG Image (15 pts).
		$max += 15;
		if ( $og_image ) {
			$score += 15;
		} else {
			$issues[] = 'Missing OG image';
		}

		// Canonical (10 pts).
		$max += 10;
		if ( $canonical ) {
			$score += 10;
		} else {
			$warnings[] = 'No canonical URL set';
		}

		// Focus Keyword (10 pts).
		$max += 10;
		if ( $focus_kw ) {
			$score += 10;
		} else {
			$warnings[] = 'No focus keyword set';
		}

		// Schema/JSON-LD (10 pts).
		$max += 10;
		if ( $schema_json ) {
			$score += 10;
		} else {
			$warnings[] = 'No structured data (JSON-LD)';
		}

		// H1 heading check (15 pts): parse Bricks content for heading elements.
		$max    += 15;
		$content = get_post_meta( $post_id, '_bricks_page_content_2', true );
		$h1_count     = 0;
		$img_no_alt   = 0;
		$img_total    = 0;
		if ( is_array( $content ) ) {
			foreach ( $content as $el ) {
				if ( isset( $el['name'] ) && $el['name'] === 'heading' ) {
					$tag = isset( $el['settings']['tag'] ) ? $el['settings']['tag'] : 'h2';
					if ( $tag === 'h1' ) {
						$h1_count++;
					}
				}
				if ( isset( $el['name'] ) && $el['name'] === 'image' ) {
					$img_total++;
					$alt = isset( $el['settings']['_attributes'] ) ? $el['settings']['_attributes'] : '';
					// Check altText (Bricks native), alt (legacy), _attributes, and media library fallback.
					$has_alt = ! empty( $el['settings']['altText'] ) || ! empty( $el['settings']['alt'] ) || ! empty( $alt ) || ( ! empty( $el['settings']['image']['id'] ) && get_post_meta( $el['settings']['image']['id'], '_wp_attachment_image_alt', true ) !== '' );
					if ( ! $has_alt ) {
						$img_no_alt++;
					}
				}
			}
		}
		if ( $h1_count === 1 ) {
			$score += 15;
		} elseif ( $h1_count === 0 ) {
			$issues[] = 'No H1 heading found';
		} else {
			$score += 5;
			$warnings[] = "Multiple H1 headings ({$h1_count})";
		}

		// Percentage score.
		$pct = $max > 0 ? round( ( $score / $max ) * 100 ) : 0;
		$total_score += $pct;

		$entry = array(
			'page_id'       => $post_id,
			'title'         => get_the_title( $post_id ),
			'slug'          => get_post_field( 'post_name', $post_id ),
			'score'         => $pct,
			'noindex'       => (bool) $noindex,
		);
		if ( ! empty( $issues ) ) {
			$entry['issues'] = $issues;
		}
		if ( ! empty( $warnings ) ) {
			$entry['warnings'] = $warnings;
		}
		if ( $img_no_alt > 0 ) {
			$entry['images_missing_alt'] = $img_no_alt;
			$entry['images_total']       = $img_total;
		}

		$results[] = $entry;
	}

	// Sort by score ascending (worst first).
	usort( $results, function ( $a, $b ) {
		return $a['score'] - $b['score'];
	});

	$avg = count( $results ) > 0 ? round( $total_score / count( $results ) ) : 0;

	return new WP_REST_Response( array(
		'success'       => true,
		'pages_scanned' => count( $results ),
		'average_score' => $avg,
		'pages'         => $results,
	), 200 );
}

/**
 * Deep SEO analysis for a single page.
 *
 * Checks: title/description quality, keyword density, heading hierarchy,
 * image alt texts, link count, content freshness, word count.
 */
function bricks_api_bridge_seo_analyze( $request ) {
	$id = (int) $request['id'];

	$post = get_post( $id );
	if ( ! $post ) {
		return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Page not found.' ), 404 );
	}

	$title       = get_post_meta( $id, '_bab_seo_title', true );
	$description = get_post_meta( $id, '_bab_seo_description', true );
	$og_image    = get_post_meta( $id, '_bab_seo_og_image', true );
	$keywords    = get_post_meta( $id, '_bab_seo_keywords', true );
	$og_type     = get_post_meta( $id, '_bab_seo_og_type', true );
	$canonical   = get_post_meta( $id, '_bab_seo_canonical', true );
	$noindex     = get_post_meta( $id, '_bab_seo_noindex', true );
	$nofollow    = get_post_meta( $id, '_bab_seo_nofollow', true );
	$focus_kw    = get_post_meta( $id, '_bab_seo_focus_keyword', true );
	$schema_json = get_post_meta( $id, '_bab_schema_json', true );

	$content = get_post_meta( $id, '_bricks_page_content_2', true );
	if ( ! is_array( $content ) ) {
		$content = array();
	}

	// Collect all text content from Bricks elements.
	$all_text     = '';
	$headings     = array();
	$images       = array();
	$links        = array();
	$h1_count     = 0;

	foreach ( $content as $el ) {
		$name = isset( $el['name'] ) ? $el['name'] : '';
		$settings = isset( $el['settings'] ) ? $el['settings'] : array();

		// Headings.
		if ( $name === 'heading' ) {
			$tag  = isset( $settings['tag'] ) ? $settings['tag'] : 'h2';
			$text = isset( $settings['text'] ) ? wp_strip_all_tags( $settings['text'] ) : '';
			$headings[] = array( 'tag' => $tag, 'text' => $text, 'id' => $el['id'] );
			if ( $tag === 'h1' ) {
				$h1_count++;
			}
			$all_text .= ' ' . $text;
		}

		// Text elements.
		if ( in_array( $name, array( 'text-basic', 'text', 'rich-text' ), true ) ) {
			$text = isset( $settings['text'] ) ? wp_strip_all_tags( $settings['text'] ) : '';
			$all_text .= ' ' . $text;

			// Extract links from content.
			if ( isset( $settings['text'] ) ) {
				preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $settings['text'], $matches );
				if ( ! empty( $matches[1] ) ) {
					$links = array_merge( $links, $matches[1] );
				}
			}
		}

		// Images.
		if ( $name === 'image' ) {
			$img = array(
				'id'      => $el['id'],
				'has_alt' => ! empty( $settings['altText'] ) || ! empty( $settings['alt'] ) || ( ! empty( $settings['image']['id'] ) && get_post_meta( $settings['image']['id'], '_wp_attachment_image_alt', true ) !== '' ),
			);
			if ( isset( $settings['image']['url'] ) ) {
				$img['url'] = $settings['image']['url'];
			}
			$images[] = $img;
		}
	}

	// Word count.
	$all_text   = trim( preg_replace( '/\s+/', ' ', $all_text ) );
	$word_count = $all_text ? count( preg_split( '/\s+/', $all_text ) ) : 0;

	// Keyword density (if focus keyword is set).
	$keyword_analysis = null;
	if ( $focus_kw && $all_text ) {
		$kw_lower   = mb_strtolower( $focus_kw );
		$text_lower = mb_strtolower( $all_text );
		$kw_count   = mb_substr_count( $text_lower, $kw_lower );
		$density    = $word_count > 0 ? round( ( $kw_count / $word_count ) * 100, 2 ) : 0;
		$in_title   = $title ? ( mb_stripos( $title, $focus_kw ) !== false ) : false;
		$in_desc    = $description ? ( mb_stripos( $description, $focus_kw ) !== false ) : false;
		$in_h1      = false;
		foreach ( $headings as $h ) {
			if ( $h['tag'] === 'h1' && mb_stripos( $h['text'], $focus_kw ) !== false ) {
				$in_h1 = true;
				break;
			}
		}
		$keyword_analysis = array(
			'keyword'          => $focus_kw,
			'occurrences'      => $kw_count,
			'density_percent'  => $density,
			'in_title'         => $in_title,
			'in_description'   => $in_desc,
			'in_h1'            => $in_h1,
			'recommendation'   => $density < 0.5 ? 'Keyword density too low (aim for 1-2%)' : ( $density > 3 ? 'Keyword density too high (risk of keyword stuffing)' : 'Good keyword density' ),
		);
	}

	// Heading hierarchy check.
	$heading_issues = array();
	if ( $h1_count === 0 ) {
		$heading_issues[] = 'No H1 found — every page should have exactly one H1';
	} elseif ( $h1_count > 1 ) {
		$heading_issues[] = "Multiple H1 headings found ({$h1_count}) — use only one per page";
	}

	// Check hierarchy (no skipping levels).
	$prev_level = 0;
	foreach ( $headings as $h ) {
		$level = (int) substr( $h['tag'], 1 );
		if ( $prev_level > 0 && $level > $prev_level + 1 ) {
			$heading_issues[] = "Heading jump: H{$prev_level} → H{$level} (skipped H" . ( $prev_level + 1 ) . ") at element {$h['id']}";
		}
		$prev_level = $level;
	}

	// Image alt check.
	$img_without_alt = array_filter( $images, function ( $img ) {
		return ! $img['has_alt'];
	});

	// Title analysis.
	$title_analysis = null;
	if ( $title ) {
		$len = mb_strlen( $title );
		$title_analysis = array(
			'length'         => $len,
			'status'         => ( $len >= 30 && $len <= 60 ) ? 'good' : ( $len < 30 ? 'too_short' : 'too_long' ),
			'recommendation' => ( $len >= 30 && $len <= 60 ) ? 'Title length is ideal' : "Title is {$len} chars (ideal: 30-60)",
		);
	}

	// Description analysis.
	$desc_analysis = null;
	if ( $description ) {
		$len = mb_strlen( $description );
		$desc_analysis = array(
			'length'         => $len,
			'status'         => ( $len >= 120 && $len <= 160 ) ? 'good' : ( $len < 120 ? 'too_short' : 'too_long' ),
			'recommendation' => ( $len >= 120 && $len <= 160 ) ? 'Description length is ideal' : "Description is {$len} chars (ideal: 120-160)",
		);
	}

	// Content freshness.
	$modified = $post->post_modified;

	// Build score.
	$score    = 0;
	$max      = 100;
	$checks   = array();

	// Title (15 pts).
	if ( $title_analysis && $title_analysis['status'] === 'good' ) {
		$score += 15;
		$checks[] = array( 'check' => 'SEO Title', 'status' => 'pass', 'points' => 15 );
	} elseif ( $title ) {
		$score += 7;
		$checks[] = array( 'check' => 'SEO Title', 'status' => 'warn', 'points' => 7, 'note' => $title_analysis['recommendation'] );
	} else {
		$checks[] = array( 'check' => 'SEO Title', 'status' => 'fail', 'points' => 0, 'note' => 'Missing' );
	}

	// Description (15 pts).
	if ( $desc_analysis && $desc_analysis['status'] === 'good' ) {
		$score += 15;
		$checks[] = array( 'check' => 'Meta Description', 'status' => 'pass', 'points' => 15 );
	} elseif ( $description ) {
		$score += 7;
		$checks[] = array( 'check' => 'Meta Description', 'status' => 'warn', 'points' => 7, 'note' => $desc_analysis['recommendation'] );
	} else {
		$checks[] = array( 'check' => 'Meta Description', 'status' => 'fail', 'points' => 0, 'note' => 'Missing' );
	}

	// H1 (15 pts).
	if ( $h1_count === 1 ) {
		$score += 15;
		$checks[] = array( 'check' => 'H1 Heading', 'status' => 'pass', 'points' => 15 );
	} elseif ( $h1_count > 1 ) {
		$score += 5;
		$checks[] = array( 'check' => 'H1 Heading', 'status' => 'warn', 'points' => 5, 'note' => "Multiple H1s ({$h1_count})" );
	} else {
		$checks[] = array( 'check' => 'H1 Heading', 'status' => 'fail', 'points' => 0, 'note' => 'No H1 found' );
	}

	// Heading hierarchy (10 pts).
	if ( empty( $heading_issues ) && count( $headings ) > 0 ) {
		$score += 10;
		$checks[] = array( 'check' => 'Heading Hierarchy', 'status' => 'pass', 'points' => 10 );
	} elseif ( ! empty( $heading_issues ) ) {
		$score += 3;
		$checks[] = array( 'check' => 'Heading Hierarchy', 'status' => 'warn', 'points' => 3, 'note' => implode( '; ', $heading_issues ) );
	}

	// OG Image (10 pts).
	if ( $og_image ) {
		$score += 10;
		$checks[] = array( 'check' => 'OG Image', 'status' => 'pass', 'points' => 10 );
	} else {
		$checks[] = array( 'check' => 'OG Image', 'status' => 'fail', 'points' => 0, 'note' => 'Missing' );
	}

	// Image ALTs (10 pts).
	if ( count( $images ) === 0 ) {
		$score += 10;
		$checks[] = array( 'check' => 'Image ALT Texts', 'status' => 'pass', 'points' => 10, 'note' => 'No images' );
	} elseif ( count( $img_without_alt ) === 0 ) {
		$score += 10;
		$checks[] = array( 'check' => 'Image ALT Texts', 'status' => 'pass', 'points' => 10 );
	} else {
		$ratio = count( $img_without_alt ) / count( $images );
		$pts   = round( 10 * ( 1 - $ratio ) );
		$score += $pts;
		$checks[] = array( 'check' => 'Image ALT Texts', 'status' => 'warn', 'points' => $pts, 'note' => count( $img_without_alt ) . ' of ' . count( $images ) . ' images missing alt' );
	}

	// Canonical (5 pts).
	if ( $canonical ) {
		$score += 5;
		$checks[] = array( 'check' => 'Canonical URL', 'status' => 'pass', 'points' => 5 );
	} else {
		$checks[] = array( 'check' => 'Canonical URL', 'status' => 'info', 'points' => 0, 'note' => 'Not set (uses default)' );
	}

	// Focus Keyword (10 pts).
	if ( $keyword_analysis ) {
		if ( $keyword_analysis['density_percent'] >= 0.5 && $keyword_analysis['density_percent'] <= 3 ) {
			$score += 10;
			$checks[] = array( 'check' => 'Focus Keyword', 'status' => 'pass', 'points' => 10 );
		} else {
			$score += 5;
			$checks[] = array( 'check' => 'Focus Keyword', 'status' => 'warn', 'points' => 5, 'note' => $keyword_analysis['recommendation'] );
		}
	} else {
		$checks[] = array( 'check' => 'Focus Keyword', 'status' => 'info', 'points' => 0, 'note' => 'No focus keyword set' );
	}

	// Schema (5 pts).
	if ( $schema_json ) {
		$score += 5;
		$checks[] = array( 'check' => 'Structured Data', 'status' => 'pass', 'points' => 5 );
	} else {
		$checks[] = array( 'check' => 'Structured Data', 'status' => 'info', 'points' => 0, 'note' => 'No JSON-LD schema' );
	}

	// Word count (5 pts).
	if ( $word_count >= 300 ) {
		$score += 5;
		$checks[] = array( 'check' => 'Content Length', 'status' => 'pass', 'points' => 5, 'note' => "{$word_count} words" );
	} elseif ( $word_count >= 100 ) {
		$score += 2;
		$checks[] = array( 'check' => 'Content Length', 'status' => 'warn', 'points' => 2, 'note' => "{$word_count} words (aim for 300+)" );
	} else {
		$checks[] = array( 'check' => 'Content Length', 'status' => 'info', 'points' => 0, 'note' => "{$word_count} words (thin content)" );
	}

	return new WP_REST_Response( array(
		'success'           => true,
		'page_id'           => $id,
		'title'             => get_the_title( $id ),
		'url'               => get_permalink( $id ),
		'score'             => $score,
		'checks'            => $checks,
		'seo_meta'          => array(
			'seo_title'   => $title ?: '',
			'description' => $description ?: '',
			'og_image'    => $og_image ?: '',
			'keywords'    => $keywords ?: '',
			'canonical'   => $canonical ?: '',
			'noindex'     => (bool) $noindex,
			'nofollow'    => (bool) $nofollow,
		),
		'headings'          => $headings,
		'heading_issues'    => $heading_issues,
		'images_total'      => count( $images ),
		'images_missing_alt'=> count( $img_without_alt ),
		'word_count'        => $word_count,
		'keyword_analysis'  => $keyword_analysis,
		'title_analysis'    => $title_analysis,
		'description_analysis' => $desc_analysis,
		'content_freshness' => array(
			'last_modified' => $modified,
			'days_ago'      => floor( ( time() - strtotime( $modified ) ) / 86400 ),
		),
		'has_schema'        => ! empty( $schema_json ),
		'link_count'        => count( $links ),
	), 200 );
}

/**
 * Register Theme Styles REST API endpoints.
 *
 * Provides read/write access to Bricks Builder global Theme Styles
 * stored in wp_options as 'bricks_theme_styles'.
 */
function bricks_api_bridge_register_theme_styles_routes() {
	// GET/PUT all theme styles.
	register_rest_route( 'bricks-bridge/v1', '/theme-styles', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'bricks_api_bridge_get_theme_styles',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
		array(
			'methods'             => 'PUT',
			'callback'            => 'bricks_api_bridge_update_theme_styles',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		),
	));

	// GET single theme style by name.
	register_rest_route( 'bricks-bridge/v1', '/theme-styles/(?P<name>[a-zA-Z0-9_-]+)', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'bricks_api_bridge_get_single_theme_style',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_theme_styles_routes' );

/**
 * Get all Bricks Theme Styles.
 *
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_theme_styles() {
	$cached = get_transient( 'bab_theme_styles' );
	if ( false !== $cached ) {
		return new WP_REST_Response( array(
			'theme_styles' => $cached,
			'count'        => count( $cached ),
		), 200 );
	}

	$styles = get_option( 'bricks_theme_styles', array() );

	if ( ! is_array( $styles ) ) {
		$styles = array();
	}

	set_transient( 'bab_theme_styles', $styles, 300 );

	return new WP_REST_Response( array(
		'theme_styles' => $styles,
		'count'        => count( $styles ),
	), 200 );
}

/**
 * Update Bricks Theme Styles.
 *
 * Supports merge mode (default): only sent styles are overwritten,
 * existing styles are preserved. Set merge=false to replace all.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_update_theme_styles( $request ) {
	$body = $request->get_json_params();

	$merge = isset( $body['merge'] ) ? (bool) $body['merge'] : true;

	if ( ! isset( $body['theme_styles'] ) || ! is_array( $body['theme_styles'] ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_invalid_data',
			'message' => 'theme_styles must be an object.',
		), 400 );
	}

	if ( empty( $body['theme_styles'] ) && $merge ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_invalid_data',
			'message' => 'theme_styles must not be empty in merge mode.',
		), 400 );
	}

	// Backup current state before modifying.
	$current_styles = get_option( 'bricks_theme_styles', array() );
	bricks_api_bridge_rotate_global_backup( 'theme_styles', $current_styles );

	// Delete transient AFTER validation passes (not before).
	delete_transient( 'bab_theme_styles' );
	$new_styles = $body['theme_styles'];

	if ( $merge ) {
		$existing = get_option( 'bricks_theme_styles', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		// Deep-merge: overwrite matching keys, keep the rest.
		foreach ( $new_styles as $name => $settings ) {
			if ( isset( $existing[ $name ] ) && is_array( $existing[ $name ] ) && is_array( $settings ) ) {
				$existing[ $name ] = array_replace_recursive( $existing[ $name ], $settings );
			} else {
				$existing[ $name ] = $settings;
			}
		}
		$final = $existing;
	} else {
		$final = $new_styles;
	}

	update_option( 'bricks_theme_styles', $final );

	return new WP_REST_Response( array(
		'success'      => true,
		'merge'        => $merge,
		'count'        => count( $final ),
		'theme_styles' => $final,
	), 200 );
}

/**
 * Get a single Bricks Theme Style by name.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_single_theme_style( $request ) {
	$name   = $request['name'];
	$styles = get_option( 'bricks_theme_styles', array() );

	if ( ! is_array( $styles ) || ! isset( $styles[ $name ] ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_not_found',
			'message' => sprintf( 'Theme style "%s" not found.', $name ),
		), 404 );
	}

	return new WP_REST_Response( array(
		'name'   => $name,
		'style'  => $styles[ $name ],
	), 200 );
}

/**
 * Register Color Palette, Custom Fonts, and Global CSS REST endpoints.
 */
function bricks_api_bridge_register_design_routes() {
	$permission = function () {
		return current_user_can( 'edit_posts' );
	};

	// Color Palette.
	register_rest_route( 'bricks-bridge/v1', '/color-palette', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'bricks_api_bridge_get_color_palette',
			'permission_callback' => $permission,
		),
		array(
			'methods'             => 'PUT',
			'callback'            => 'bricks_api_bridge_update_color_palette',
			'permission_callback' => $permission,
		),
	));

	// Custom Fonts.
	register_rest_route( 'bricks-bridge/v1', '/fonts', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'bricks_api_bridge_get_fonts',
			'permission_callback' => $permission,
		),
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'bricks_api_bridge_update_fonts',
			'permission_callback' => $permission,
		),
	));

	// Custom Font Registration (upload + @font-face).
	register_rest_route( 'bricks-bridge/v1', '/fonts/register-custom', array(
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'bricks_api_bridge_register_custom_font',
			'permission_callback' => $permission,
		),
	));

	// CSS Variables.
	register_rest_route( 'bricks-bridge/v1', '/css-variables', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'bricks_api_bridge_get_css_variables',
			'permission_callback' => $permission,
		),
		array(
			'methods'             => 'PUT',
			'callback'            => 'bricks_api_bridge_update_css_variables',
			'permission_callback' => $permission,
		),
	));

	// Global CSS.
	register_rest_route( 'bricks-bridge/v1', '/global-css', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'bricks_api_bridge_get_global_css',
			'permission_callback' => $permission,
		),
		array(
			'methods'             => 'PUT',
			'callback'            => 'bricks_api_bridge_update_global_css',
			'permission_callback' => $permission,
		),
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_design_routes' );

/**
 * Register Breakpoints REST API endpoint.
 *
 * Returns the active Bricks breakpoints (default + custom) with their
 * pixel values and setting suffixes. Read-only.
 */
function bricks_api_bridge_register_breakpoints_route() {
	register_rest_route( 'bricks-bridge/v1', '/breakpoints', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'bricks_api_bridge_get_breakpoints',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_breakpoints_route' );

/**
 * Get active Bricks breakpoints.
 *
 * Uses the Bricks Breakpoints API if available, otherwise returns
 * the known defaults from bricks_global_settings.
 *
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_breakpoints() {
	// Try Bricks native Breakpoints class first (most reliable).
	if ( class_exists( '\Bricks\Breakpoints' ) ) {
		$breakpoints = \Bricks\Breakpoints::$breakpoints ?? array();

		if ( ! empty( $breakpoints ) ) {
			$result = array();
			foreach ( $breakpoints as $bp ) {
				$result[] = array(
					'key'    => $bp['key'] ?? '',
					'label'  => $bp['label'] ?? '',
					'width'  => isset( $bp['width'] ) ? (int) $bp['width'] : null,
					'base'   => ! empty( $bp['base'] ),
				);
			}

			return new WP_REST_Response( array(
				'breakpoints'      => $result,
				'count'            => count( $result ),
				'source'           => 'bricks_api',
				'has_custom'       => count( $result ) > 4,
			), 200 );
		}
	}

	// Fallback: read from global settings option.
	$settings    = get_option( 'bricks_global_settings', array() );
	$custom_bps  = isset( $settings['customBreakpoints'] ) ? $settings['customBreakpoints'] : array();

	// Bricks default breakpoints.
	$defaults = array(
		array( 'key' => 'desktop',          'label' => 'Desktop',          'width' => null, 'base' => true ),
		array( 'key' => 'tablet_portrait',  'label' => 'Tablet Portrait',  'width' => 992,  'base' => false ),
		array( 'key' => 'mobile_landscape', 'label' => 'Mobile Landscape', 'width' => 768,  'base' => false ),
		array( 'key' => 'mobile_portrait',  'label' => 'Mobile Portrait',  'width' => 478,  'base' => false ),
	);

	// Merge any custom breakpoints.
	$all = $defaults;
	if ( is_array( $custom_bps ) && ! empty( $custom_bps ) ) {
		foreach ( $custom_bps as $cbp ) {
			$all[] = array(
				'key'    => sanitize_key( $cbp['key'] ?? '' ),
				'label'  => sanitize_text_field( $cbp['label'] ?? '' ),
				'width'  => isset( $cbp['width'] ) ? (int) $cbp['width'] : null,
				'base'   => false,
			);
		}
		// Sort by width descending (desktop first, then largest to smallest).
		usort( $all, function ( $a, $b ) {
			$wa = $a['width'] ?? PHP_INT_MAX;
			$wb = $b['width'] ?? PHP_INT_MAX;
			return $wb - $wa;
		});
	}

	return new WP_REST_Response( array(
		'breakpoints'      => $all,
		'count'            => count( $all ),
		'source'           => ! empty( $custom_bps ) ? 'global_settings' : 'defaults',
		'has_custom'       => ! empty( $custom_bps ),
	), 200 );
}

/**
 * Register Responsive Inference REST API endpoint.
 *
 * POST /pages/{id}/responsify — Apply responsive inference to a page's elements.
 * Can also accept a content array in the body (for pre-push processing).
 */
function bricks_api_bridge_register_responsify_route() {
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/responsify', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			$post_id = (int) $request['id'];
			$body    = $request->get_json_params();

			// Accept content from body OR read from page.
			if ( ! empty( $body['content'] ) && is_array( $body['content'] ) ) {
				$content = $body['content'];
				$source  = 'body';
			} else {
				$post = get_post( $post_id );
				if ( ! $post ) {
					return new WP_REST_Response( array(
						'code'    => 'bricks_api_bridge_not_found',
						'message' => 'Page not found.',
					), 404 );
				}
				$content = null;
				if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
					$content = \Bricks\Database::get_data( $post_id, 'content' );
				}
				if ( empty( $content ) ) {
					$meta_keys = array( '_bricks_page_content_2', '_bricks_page_content' );
					foreach ( $meta_keys as $key ) {
						$meta = get_post_meta( $post_id, $key, true );
						if ( ! empty( $meta ) ) {
							$content = $meta;
							break;
						}
					}
				}
				if ( empty( $content ) || ! is_array( $content ) ) {
					return new WP_REST_Response( array(
						'code'    => 'bricks_api_bridge_no_content',
						'message' => 'No Bricks content found for this page.',
					), 400 );
				}
				$source = 'page';
			}

			$result = Bricks_API_Bridge_Responsive_Inference::infer( $content );

			// Optionally write back to the page.
			$write_back = ! empty( $body['write_back'] );
			if ( $write_back && $result['changed'] && 'page' === $source ) {
				update_post_meta( $post_id, '_bricks_page_content_2', $result['content'] );
				bricks_api_bridge_purge_post_cache( $post_id );
			}

			return new WP_REST_Response( array(
				'success'       => true,
				'page_id'       => $post_id,
				'source'        => $source,
				'changed'       => $result['changed'],
				'changes_count' => count( $result['log'] ),
				'log'           => $result['log'],
				'content'       => $result['content'],
				'written_back'  => $write_back && $result['changed'],
			), 200 );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_responsify_route' );

/**
 * Register Page Diff REST API endpoint.
 *
 * GET /pages/{id}/diff?compare_to={snapshot_id|page_id} — Diff two element trees.
 */
function bricks_api_bridge_register_diff_route() {
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/diff', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			$post_id    = (int) $request['id'];
			$compare_to = $request->get_param( 'compare_to' );

			if ( empty( $compare_to ) ) {
				return new WP_REST_Response( array(
					'code'    => 'bricks_api_bridge_missing_param',
					'message' => 'compare_to parameter is required (snapshot ID or page ID).',
				), 400 );
			}

			// Get current page content.
			$content_a = null;
			if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
				$content_a = \Bricks\Database::get_data( $post_id, 'content' );
			}
			if ( empty( $content_a ) ) {
				$content_a = get_post_meta( $post_id, '_bricks_page_content_2', true );
			}
			if ( empty( $content_a ) || ! is_array( $content_a ) ) {
				return new WP_REST_Response( array(
					'code'    => 'bricks_api_bridge_no_content',
					'message' => 'No Bricks content found for page ' . $post_id,
				), 400 );
			}

			// Get comparison content — try snapshot first, then page ID.
			$content_b   = null;
			$compare_src = '';

			// Try as snapshot.
			$snapshots = get_post_meta( $post_id, '_bab_snapshots', true );
			if ( is_array( $snapshots ) && isset( $snapshots[ $compare_to ] ) ) {
				$content_b   = $snapshots[ $compare_to ]['content'];
				$compare_src = 'snapshot:' . $compare_to;
			}

			// Try as backup slot.
			if ( null === $content_b && is_numeric( $compare_to ) && (int) $compare_to <= 5 ) {
				$backup = get_transient( 'bab_backup_page_' . $post_id . '_' . $compare_to );
				if ( ! empty( $backup ) && isset( $backup['data'] ) ) {
					$content_b   = $backup['data'];
					$compare_src = 'backup_slot:' . $compare_to;
				}
			}

			// Try as another page ID.
			if ( null === $content_b && is_numeric( $compare_to ) ) {
				$other_id = (int) $compare_to;
				if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
					$content_b = \Bricks\Database::get_data( $other_id, 'content' );
				}
				if ( empty( $content_b ) ) {
					$content_b = get_post_meta( $other_id, '_bricks_page_content_2', true );
				}
				if ( ! empty( $content_b ) ) {
					$compare_src = 'page:' . $other_id;
				}
			}

			if ( empty( $content_b ) || ! is_array( $content_b ) ) {
				return new WP_REST_Response( array(
					'code'    => 'bricks_api_bridge_compare_not_found',
					'message' => 'Could not find comparison data for: ' . $compare_to,
				), 404 );
			}

			// Build diff server-side (simple PHP implementation).
			$map_a = array();
			foreach ( $content_a as $el ) {
				if ( isset( $el['id'] ) ) {
					$map_a[ $el['id'] ] = $el;
				}
			}
			$map_b = array();
			foreach ( $content_b as $el ) {
				if ( isset( $el['id'] ) ) {
					$map_b[ $el['id'] ] = $el;
				}
			}

			$added   = array();
			$removed = array();
			$modified = array();

			// Find added (in A but not B) and modified.
			foreach ( $map_a as $id => $el_a ) {
				if ( ! isset( $map_b[ $id ] ) ) {
					$added[] = array( 'id' => $id, 'name' => $el_a['name'] ?? '?' );
				} else {
					$el_b = $map_b[ $id ];
					// Quick diff: compare serialized settings.
					if ( wp_json_encode( $el_a['settings'] ?? array() ) !== wp_json_encode( $el_b['settings'] ?? array() ) ) {
						$modified[] = array( 'id' => $id, 'name' => $el_a['name'] ?? '?' );
					}
				}
			}
			// Find removed (in B but not A).
			foreach ( $map_b as $id => $el_b ) {
				if ( ! isset( $map_a[ $id ] ) ) {
					$removed[] = array( 'id' => $id, 'name' => $el_b['name'] ?? '?' );
				}
			}

			return new WP_REST_Response( array(
				'page_id'      => $post_id,
				'compare_to'   => $compare_to,
				'compare_src'  => $compare_src,
				'summary'      => array(
					'elements_current'  => count( $content_a ),
					'elements_compare'  => count( $content_b ),
					'added'             => count( $added ),
					'removed'           => count( $removed ),
					'modified'          => count( $modified ),
					'unchanged'         => count( $map_a ) - count( $added ) - count( $modified ),
				),
				'added'        => $added,
				'removed'      => $removed,
				'modified'     => $modified,
			), 200 );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_diff_route' );

/**
 * Register Page Export REST API endpoint.
 *
 * GET /pages/{id}/export — Export a complete page as portable JSON.
 */
function bricks_api_bridge_register_export_route() {
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/export', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			$post_id = (int) $request['id'];
			$post    = get_post( $post_id );

			if ( ! $post ) {
				return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Page not found.' ), 404 );
			}

			// Get Bricks content.
			$content = null;
			if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
				$content = \Bricks\Database::get_data( $post_id, 'content' );
			}
			if ( empty( $content ) ) {
				$content = get_post_meta( $post_id, '_bricks_page_content_2', true );
			}

			// Get scripts.
			$scripts = get_post_meta( $post_id, '_bab_footer_scripts', true );

			// Get SEO.
			$seo = array(
				'seo_title'           => get_post_meta( $post_id, '_bab_seo_title', true ) ?: '',
				'description'         => get_post_meta( $post_id, '_bab_seo_description', true ) ?: '',
				'og_image'            => get_post_meta( $post_id, '_bab_seo_og_image', true ) ?: '',
				'keywords'            => get_post_meta( $post_id, '_bab_seo_keywords', true ) ?: '',
				'og_type'             => get_post_meta( $post_id, '_bab_seo_og_type', true ) ?: '',
				'canonical'           => get_post_meta( $post_id, '_bab_seo_canonical', true ) ?: '',
				'noindex'             => (bool) get_post_meta( $post_id, '_bab_seo_noindex', true ),
				'nofollow'            => (bool) get_post_meta( $post_id, '_bab_seo_nofollow', true ),
				'focus_keyword'       => get_post_meta( $post_id, '_bab_seo_focus_keyword', true ) ?: '',
				'og_title'            => get_post_meta( $post_id, '_bab_seo_og_title', true ) ?: '',
				'twitter_title'       => get_post_meta( $post_id, '_bab_seo_twitter_title', true ) ?: '',
				'twitter_description' => get_post_meta( $post_id, '_bab_seo_twitter_description', true ) ?: '',
				'twitter_image'       => get_post_meta( $post_id, '_bab_seo_twitter_image', true ) ?: '',
			);

			// Get Schema/JSON-LD.
			$schema_raw = get_post_meta( $post_id, '_bab_schema_json', true );
			$schema = $schema_raw ? json_decode( $schema_raw, true ) : null;

			// Find used global classes.
			$used_classes = array();
			if ( is_array( $content ) ) {
				foreach ( $content as $el ) {
					if ( ! empty( $el['settings']['_cssGlobalClasses'] ) ) {
						$used_classes = array_merge( $used_classes, $el['settings']['_cssGlobalClasses'] );
					}
				}
				$used_classes = array_unique( $used_classes );
			}

			// Get class definitions for used classes.
			$all_classes      = get_option( 'bricks_global_classes', array() );
			$class_defs       = array();
			$all_classes_map  = array();
			if ( is_array( $all_classes ) ) {
				foreach ( $all_classes as $cls ) {
					if ( isset( $cls['id'] ) ) {
						$all_classes_map[ $cls['id'] ] = $cls;
					}
				}
			}
			foreach ( $used_classes as $cls_id ) {
				if ( isset( $all_classes_map[ $cls_id ] ) ) {
					$class_defs[] = $all_classes_map[ $cls_id ];
				}
			}

			// Get CSS variables.
			$css_vars = array();
			$var_keys = array( 'bricks_global_variables', 'bricks_css_variables' );
			foreach ( $var_keys as $key ) {
				$val = get_option( $key, null );
				if ( ! empty( $val ) ) {
					$css_vars = $val;
					break;
				}
			}

			return new WP_REST_Response( array(
				'success' => true,
				'export'  => array(
					'version'        => '1.0',
					'exported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'page_id'        => $post_id,
					'title'          => $post->post_title,
					'slug'           => $post->post_name,
					'elements'       => $content ?: array(),
					'scripts'        => $scripts ?: '',
					'seo'            => $seo,
					'schema'         => $schema,
					'global_classes'  => $class_defs,
					'css_variables'   => $css_vars,
				),
			), 200 );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_export_route' );

/**
 * Register Page Import REST API endpoint.
 *
 * POST /pages/{id}/import — Import a page from exported JSON.
 */
function bricks_api_bridge_register_import_route() {
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/import', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			$post_id = (int) $request['id'];
			$body    = $request->get_json_params();

			if ( ! get_post( $post_id ) ) {
				return new WP_REST_Response( array( 'code' => 'not_found', 'message' => 'Page not found.' ), 404 );
			}

			$export = isset( $body['export_data'] ) ? $body['export_data'] : null;
			if ( empty( $export ) || ! isset( $export['elements'] ) ) {
				return new WP_REST_Response( array(
					'code'    => 'invalid_data',
					'message' => 'export_data with elements is required.',
				), 400 );
			}

			$skip_classes = ! empty( $body['skip_classes'] );
			$skip_scripts = ! empty( $body['skip_scripts'] );
			$skip_seo     = ! empty( $body['skip_seo'] );

			// Backup before import.
			if ( class_exists( 'Bricks_API_Bridge_Backup' ) ) {
				$backup = new Bricks_API_Bridge_Backup();
				$backup->create_backup( $post_id );
			}

			// Autofix elements.
			$autofix_applied = false;
			if ( class_exists( 'Bricks_API_Bridge_Autofix' ) ) {
				$fix_result = Bricks_API_Bridge_Autofix::autofix( $export['elements'] );
				if ( $fix_result['fixed'] ) {
					$export['elements'] = $fix_result['content'];
					$autofix_applied    = true;
				}
			}

			// Import elements.
			update_post_meta( $post_id, '_bricks_page_content_2', $export['elements'] );

			// Import scripts.
			$scripts_imported = false;
			if ( ! $skip_scripts && ! empty( $export['scripts'] ) ) {
				update_post_meta( $post_id, '_bab_footer_scripts', $export['scripts'] );
				$scripts_imported = true;
			}

			// Import SEO.
			$seo_imported = false;
			if ( ! $skip_seo && ! empty( $export['seo'] ) ) {
				$seo_map = array(
					'seo_title'           => '_bab_seo_title',
					'description'         => '_bab_seo_description',
					'og_image'            => '_bab_seo_og_image',
					'keywords'            => '_bab_seo_keywords',
					'og_type'             => '_bab_seo_og_type',
					'canonical'           => '_bab_seo_canonical',
					'focus_keyword'       => '_bab_seo_focus_keyword',
					'og_title'            => '_bab_seo_og_title',
					'twitter_title'       => '_bab_seo_twitter_title',
					'twitter_description' => '_bab_seo_twitter_description',
					'twitter_image'       => '_bab_seo_twitter_image',
				);
				$seo_bool_map = array(
					'noindex'  => '_bab_seo_noindex',
					'nofollow' => '_bab_seo_nofollow',
				);
				foreach ( $seo_map as $field => $meta_key ) {
					if ( ! empty( $export['seo'][ $field ] ) ) {
						update_post_meta( $post_id, $meta_key, sanitize_text_field( $export['seo'][ $field ] ) );
						$seo_imported = true;
					}
				}
				foreach ( $seo_bool_map as $field => $meta_key ) {
					if ( isset( $export['seo'][ $field ] ) && $export['seo'][ $field ] ) {
						update_post_meta( $post_id, $meta_key, '1' );
						$seo_imported = true;
					}
				}
			}

			// Import Schema/JSON-LD.
			$schema_imported = false;
			if ( ! $skip_seo && ! empty( $export['schema'] ) ) {
				update_post_meta( $post_id, '_bab_schema_json', wp_json_encode( $export['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				$schema_imported = true;
			}

			// Import global classes.
			$classes_imported = 0;
			if ( ! $skip_classes && ! empty( $export['global_classes'] ) ) {
				$existing = get_option( 'bricks_global_classes', array() );
				$map      = array();
				foreach ( $existing as $cls ) {
					if ( isset( $cls['id'] ) ) {
						$map[ $cls['id'] ] = true;
					}
				}
				foreach ( $export['global_classes'] as $cls ) {
					if ( ! isset( $map[ $cls['id'] ] ) ) {
						$existing[] = $cls;
						$classes_imported++;
					}
				}
				if ( $classes_imported > 0 ) {
					update_option( 'bricks_global_classes', $existing );
				}
			}

			bricks_api_bridge_purge_post_cache( $post_id );

			return new WP_REST_Response( array(
				'success'          => true,
				'page_id'          => $post_id,
				'elements_count'   => count( $export['elements'] ),
				'scripts_imported' => $scripts_imported,
				'seo_imported'     => $seo_imported,
				'schema_imported'  => $schema_imported,
				'classes_imported' => $classes_imported,
				'autofix_applied'  => $autofix_applied,
			), 200 );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_import_route' );

/**
 * Register Design System Audit REST API endpoint.
 *
 * GET /design-system/audit — Scan all pages for unused classes, orphaned vars, etc.
 */
function bricks_api_bridge_register_audit_route() {
	register_rest_route( 'bricks-bridge/v1', '/design-system/audit', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			// Collect all used classes, fonts, and CSS vars across all pages.
			$used_classes = array();
			$used_fonts   = array();
			$used_vars    = array();
			$no_responsive = array();
			$pages_scanned = 0;

			$pages = get_posts( array(
				'post_type'      => array( 'page', 'post' ),
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			) );

			// Also scan templates.
			$templates = get_posts( array(
				'post_type'      => 'bricks_template',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			) );

			$all_ids = array_merge( $pages, $templates );

			foreach ( $all_ids as $pid ) {
				$content = null;
				if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
					$content = \Bricks\Database::get_data( $pid, 'content' );
				}
				if ( empty( $content ) ) {
					$content = get_post_meta( $pid, '_bricks_page_content_2', true );
				}
				if ( empty( $content ) || ! is_array( $content ) ) {
					continue;
				}

				$pages_scanned++;

				foreach ( $content as $el ) {
					$settings = isset( $el['settings'] ) ? $el['settings'] : array();

					// Classes.
					if ( ! empty( $settings['_cssGlobalClasses'] ) ) {
						$used_classes = array_merge( $used_classes, $settings['_cssGlobalClasses'] );
					}

					// Fonts from typography.
					if ( ! empty( $settings['_typography']['font-family'] ) ) {
						$used_fonts[] = $settings['_typography']['font-family'];
					}

					// CSS variables referenced in _cssCustom.
					if ( ! empty( $settings['_cssCustom'] ) ) {
						if ( preg_match_all( '/var\(\s*--([\w-]+)\s*\)/', $settings['_cssCustom'], $matches ) ) {
							$used_vars = array_merge( $used_vars, $matches[1] );
						}
					}

					// Check for large values without responsive overrides.
					$font_size = isset( $settings['_typography']['font-size'] ) ? (int) $settings['_typography']['font-size'] : 0;
					if ( $font_size >= 32 ) {
						$has_responsive = isset( $settings[':tablet_portrait_typography'] ) || isset( $settings[':mobile_portrait_typography'] );
						if ( ! $has_responsive ) {
							$no_responsive[] = array(
								'id'        => $el['id'] ?? '?',
								'name'      => $el['name'] ?? '?',
								'page_id'   => $pid,
								'font_size' => $font_size,
							);
						}
					}
				}
			}

			$used_classes = array_unique( $used_classes );
			$used_fonts   = array_unique( $used_fonts );
			$used_vars    = array_unique( $used_vars );

			// Get all defined global classes.
			$all_classes = get_option( 'bricks_global_classes', array() );
			$all_class_ids = array();
			if ( is_array( $all_classes ) ) {
				foreach ( $all_classes as $cls ) {
					if ( isset( $cls['id'] ) ) {
						$all_class_ids[] = $cls['id'];
					}
				}
			}
			$unused_classes = array_diff( $all_class_ids, $used_classes );

			// Get all defined CSS variables.
			$all_vars     = array();
			$var_keys     = array( 'bricks_global_variables', 'bricks_css_variables' );
			$var_data     = array();
			foreach ( $var_keys as $key ) {
				$val = get_option( $key, null );
				if ( ! empty( $val ) && is_array( $val ) ) {
					$var_data = $val;
					break;
				}
			}
			if ( is_array( $var_data ) ) {
				foreach ( $var_data as $var ) {
					if ( isset( $var['id'] ) ) {
						$all_vars[] = ltrim( $var['id'], '-' );
					} elseif ( isset( $var['name'] ) ) {
						$all_vars[] = ltrim( $var['name'], '-' );
					}
				}
			}
			$orphaned_vars = array_diff( $all_vars, $used_vars );

			// Get registered fonts.
			$registered_fonts = get_option( 'bricks_custom_fonts', array() );
			$font_names       = array();
			if ( is_array( $registered_fonts ) ) {
				foreach ( $registered_fonts as $font ) {
					if ( isset( $font['font_family'] ) ) {
						$font_names[] = $font['font_family'];
					}
				}
			}
			$unused_fonts = array_diff( $font_names, $used_fonts );

			// Auto-delete unused classes if requested.
			$fix_unused = $request->get_param( 'fix_unused' );
			if ( $fix_unused && ! empty( $unused_classes ) ) {
				$cleaned = array_filter( $all_classes, function ( $cls ) use ( $unused_classes ) {
					return ! in_array( $cls['id'] ?? '', $unused_classes, true );
				} );
				update_option( 'bricks_global_classes', array_values( $cleaned ) );
			}

			return new WP_REST_Response( array(
				'success' => true,
				'audit'   => array(
					'pages_scanned'   => $pages_scanned,
					'total_classes'   => count( $all_class_ids ),
					'used_classes'    => count( $used_classes ),
					'unused_classes'  => array_values( $unused_classes ),
					'total_css_vars'  => count( $all_vars ),
					'orphaned_vars'   => array_values( $orphaned_vars ),
					'total_fonts'     => count( $font_names ),
					'unused_fonts'    => array_values( $unused_fonts ),
					'no_responsive'   => array_slice( $no_responsive, 0, 50 ),
				),
			), 200 );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_audit_route' );

/**
 * Plugin toggle via Bridge (avoids %2F in URL path which many hosts block).
 */
function bricks_api_bridge_register_plugin_toggle_route() {
	register_rest_route( 'bricks-bridge/v1', '/plugins/toggle', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => function () {
			return current_user_can( 'activate_plugins' );
		},
		'callback'            => function ( $request ) {
			$body   = $request->get_json_params();
			$slug   = isset( $body['plugin_slug'] ) ? sanitize_text_field( $body['plugin_slug'] ) : '';
			$status = isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : '';

			if ( empty( $slug ) || ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
				return new WP_REST_Response( array(
					'code'    => 'invalid_params',
					'message' => 'plugin_slug and status (active|inactive) are required.',
				), 400 );
			}

			// Validate plugin file exists.
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $slug ) ) {
				return new WP_REST_Response( array(
					'code'    => 'plugin_not_found',
					'message' => "Plugin file not found: {$slug}",
				), 404 );
			}

			if ( 'active' === $status ) {
				$result = activate_plugin( $slug );
				if ( is_wp_error( $result ) ) {
					return new WP_REST_Response( array(
						'code'    => 'activation_failed',
						'message' => $result->get_error_message(),
					), 500 );
				}
			} else {
				deactivate_plugins( $slug );
			}

			// Get plugin data for response.
			$all_plugins = get_plugins();
			$plugin_data = isset( $all_plugins[ $slug ] ) ? $all_plugins[ $slug ] : array();
			$is_active   = is_plugin_active( $slug );

			return new WP_REST_Response( array(
				'name'    => isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : $slug,
				'version' => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : 'unknown',
				'status'  => $is_active ? 'active' : 'inactive',
			), 200 );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_plugin_toggle_route' );

/**
 * Register Accessibility Report REST API endpoint.
 *
 * GET /pages/{id}/accessibility-report — Analyze page content for a11y issues.
 */
function bricks_api_bridge_register_accessibility_route() {
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/accessibility-report', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			$post_id = (int) $request['id'];
			$post    = get_post( $post_id );

			if ( ! $post ) {
				return new WP_REST_Response( array(
					'code'    => 'not_found',
					'message' => 'Page not found.',
				), 404 );
			}

			// Get Bricks content.
			$content = null;
			if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
				$content = \Bricks\Database::get_data( $post_id, 'content' );
			}
			if ( empty( $content ) ) {
				$content = get_post_meta( $post_id, '_bricks_page_content_2', true );
			}
			if ( empty( $content ) || ! is_array( $content ) ) {
				return new WP_REST_Response( array(
					'code'    => 'no_content',
					'message' => 'No Bricks content found for this page.',
				), 400 );
			}

			$report = Bricks_API_Bridge_Accessibility::audit( $content, $post_id );

			return new WP_REST_Response( array(
				'success' => true,
				'report'  => $report,
			), 200 );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_accessibility_route' );

/**
 * Register Quality Score REST API endpoint.
 *
 * PUT /pages/{id}/quality-score — Persist design quality score + profile as page meta.
 */
function bricks_api_bridge_register_quality_score_route() {
	register_rest_route( 'bricks-bridge/v1', '/pages/(?P<id>\d+)/quality-score', array(
		'methods'             => 'PUT',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			$post_id = (int) $request['id'];

			if ( ! get_post( $post_id ) ) {
				return new WP_REST_Response( array(
					'code'    => 'not_found',
					'message' => 'Page not found.',
				), 404 );
			}

			$body = $request->get_json_params();

			// Store DQS (Design Quality Score).
			if ( isset( $body['dqs'] ) ) {
				update_post_meta( $post_id, '_bab_dqs', (int) $body['dqs'] );
			}

			// Store VSS (Visual Sophistication Score).
			if ( isset( $body['vss'] ) && null !== $body['vss'] ) {
				update_post_meta( $post_id, '_bab_vss', (int) $body['vss'] );
			}

			// Store quality grade.
			if ( ! empty( $body['grade'] ) ) {
				update_post_meta( $post_id, '_bab_quality_grade', sanitize_text_field( $body['grade'] ) );
			}

			// Store design profile.
			if ( ! empty( $body['design_profile'] ) ) {
				update_post_meta( $post_id, '_bab_design_profile', sanitize_text_field( $body['design_profile'] ) );
			}

			// Store custom profile data (JSON).
			if ( ! empty( $body['design_profile_data'] ) ) {
				update_post_meta( $post_id, '_bab_design_profile_data', wp_json_encode( $body['design_profile_data'] ) );
			}

			// Timestamp.
			update_post_meta( $post_id, '_bab_quality_updated', gmdate( 'Y-m-d\TH:i:s\Z' ) );

			return new WP_REST_Response( array(
				'success'  => true,
				'page_id'  => $post_id,
				'dqs'      => isset( $body['dqs'] ) ? (int) $body['dqs'] : null,
				'vss'      => isset( $body['vss'] ) ? (int) $body['vss'] : null,
				'grade'    => isset( $body['grade'] ) ? $body['grade'] : null,
				'profile'  => isset( $body['design_profile'] ) ? $body['design_profile'] : null,
			), 200 );
		},
	));
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_quality_score_route' );

/**
 * Get Bricks color palette.
 *
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_color_palette() {
	$cached = get_transient( 'bab_color_palette' );
	if ( false !== $cached ) {
		return new WP_REST_Response( array( 'color_palette' => $cached ), 200 );
	}

	$palette = get_option( 'bricks_color_palette', array() );
	set_transient( 'bab_color_palette', $palette, 300 );

	return new WP_REST_Response( array( 'color_palette' => $palette ), 200 );
}

/**
 * Update Bricks color palette.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_update_color_palette( $request ) {
	$body = $request->get_json_params();

	if ( ! isset( $body['color_palette'] ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_invalid_data',
			'message' => 'color_palette is required.',
		), 400 );
	}

	// Backup current state before modifying.
	$current_palette = get_option( 'bricks_color_palette', array() );
	bricks_api_bridge_rotate_global_backup( 'color_palette', $current_palette );

	delete_transient( 'bab_color_palette' );
	update_option( 'bricks_color_palette', $body['color_palette'] );

	return new WP_REST_Response( array(
		'success'       => true,
		'color_palette' => $body['color_palette'],
	), 200 );
}

/**
 * Get Bricks CSS variables (Global Variables from Style Manager).
 *
 * Tries multiple option keys for discovery since Bricks version naming varies.
 *
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_css_variables() {
	$cached = get_transient( 'bab_css_variables' );
	if ( false !== $cached && is_array( $cached ) ) {
		return new WP_REST_Response( array(
			'css_variables' => $cached['variables'],
			'count'         => is_array( $cached['variables'] ) ? count( $cached['variables'] ) : 0,
			'option_key'    => $cached['key'],
		), 200 );
	}

	$option_keys = array( 'bricks_global_variables', 'bricks_css_variables', 'bricks_variables' );
	$variables   = array();
	$found_key   = '';

	foreach ( $option_keys as $key ) {
		$val = get_option( $key, null );
		if ( ! empty( $val ) ) {
			// Legacy data may be stored as a JSON string; normalize to an array so
			// clients (and the Bricks builder) never receive a stringified blob.
			if ( is_string( $val ) ) {
				$decoded = json_decode( $val, true );
				if ( is_array( $decoded ) ) {
					$val = $decoded;
				}
			}
			$variables = $val;
			$found_key = $key;
			break;
		}
	}

	set_transient( 'bab_css_variables', array( 'variables' => $variables, 'key' => $found_key ), 300 );

	return new WP_REST_Response( array(
		'css_variables' => $variables,
		'count'         => is_array( $variables ) ? count( $variables ) : 0,
		'option_key'    => $found_key,
	), 200 );
}

/**
 * Update Bricks CSS variables.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_update_css_variables( $request ) {
	$body = $request->get_json_params();

	if ( ! isset( $body['css_variables'] ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_invalid_data',
			'message' => 'css_variables is required.',
		), 400 );
	}

	// Use the same key Bricks uses, discover it first.
	$option_keys = array( 'bricks_global_variables', 'bricks_css_variables', 'bricks_variables' );
	$target_key  = 'bricks_global_variables'; // default fallback.

	foreach ( $option_keys as $key ) {
		$val = get_option( $key, null );
		if ( ! empty( $val ) ) {
			$target_key = $key;
			break;
		}
	}

	$value = $body['css_variables'];
	// Defensive: Bricks expects this option to be an array. If a client sends a JSON
	// string, decode it so we never persist a stringified blob (which makes the Bricks
	// Variables Manager iterate the string character-by-character and break the builder).
	if ( is_string( $value ) ) {
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			$value = $decoded;
		}
	}

	delete_transient( 'bab_css_variables' );
	update_option( $target_key, $value );

	return new WP_REST_Response( array(
		'success'       => true,
		'css_variables' => $value,
		'count'         => is_array( $value ) ? count( $value ) : 0,
		'option_key'    => $target_key,
	), 200 );
}

/**
 * Get Bricks custom fonts.
 *
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_fonts() {
	$cached = get_transient( 'bab_fonts' );
	if ( false !== $cached ) {
		return new WP_REST_Response( array( 'fonts' => $cached ), 200 );
	}

	$fonts = get_option( 'bricks_custom_fonts', array() );
	set_transient( 'bab_fonts', $fonts, 300 );

	return new WP_REST_Response( array( 'fonts' => $fonts ), 200 );
}

/**
 * Update Bricks custom fonts.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_update_fonts( $request ) {
	$body = $request->get_json_params();

	if ( ! isset( $body['fonts'] ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_invalid_data',
			'message' => 'fonts is required.',
		), 400 );
	}

	delete_transient( 'bab_fonts' );
	update_option( 'bricks_custom_fonts', $body['fonts'] );

	return new WP_REST_Response( array(
		'success' => true,
		'fonts'   => $body['fonts'],
	), 200 );
}

/**
 * Register a custom font with file attachments and auto-generate @font-face CSS.
 *
 * Expects JSON body:
 * {
 *   "font_family": "My Custom Font",
 *   "files": [
 *     { "attachment_id": 1234, "weight": "400", "style": "normal" },
 *     { "attachment_id": 1235, "weight": "700", "style": "normal" }
 *   ],
 *   "display": "swap"
 * }
 *
 * Registers the font in bricks_custom_fonts and appends @font-face rules
 * to bricks_global_custom_css so they load on every page.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_register_custom_font( $request ) {
	$body = $request->get_json_params();

	if ( empty( $body['font_family'] ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_invalid_data',
			'message' => 'font_family is required.',
		), 400 );
	}

	if ( empty( $body['files'] ) || ! is_array( $body['files'] ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_invalid_data',
			'message' => 'files array is required (each with attachment_id, weight, style).',
		), 400 );
	}

	$font_family = sanitize_text_field( $body['font_family'] );
	$display     = sanitize_text_field( $body['display'] ?? 'swap' );
	$variants    = array();
	$font_faces  = array();
	$file_urls   = array();

	foreach ( $body['files'] as $file ) {
		$attachment_id = absint( $file['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			continue;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			continue;
		}

		$weight = sanitize_text_field( $file['weight'] ?? '400' );
		$style  = sanitize_text_field( $file['style'] ?? 'normal' );

		// Detect format from extension.
		$ext    = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );
		$format_map = array(
			'woff2' => 'woff2',
			'woff'  => 'woff',
			'ttf'   => 'truetype',
			'otf'   => 'opentype',
		);
		$format = $format_map[ $ext ] ?? 'woff2';

		$variants[]  = $weight;
		$file_urls[] = array(
			'url'    => $url,
			'weight' => $weight,
			'style'  => $style,
		);

		$font_faces[] = sprintf(
			"@font-face {\n  font-family: '%s';\n  src: url('%s') format('%s');\n  font-weight: %s;\n  font-style: %s;\n  font-display: %s;\n}",
			$font_family,
			$url,
			$format,
			$weight,
			$style,
			$display
		);
	}

	if ( empty( $font_faces ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_invalid_data',
			'message' => 'No valid font files found. Check attachment_id values.',
		), 400 );
	}

	// 1. Register font in bricks_custom_fonts (read-modify-write).
	$fonts = get_option( 'bricks_custom_fonts', array() );
	if ( ! is_array( $fonts ) ) {
		$fonts = array();
	}

	$font_entry = array(
		'font_family' => $font_family,
		'type'        => 'custom',
		'variants'    => array_unique( $variants ),
		'files'       => $file_urls,
	);

	// Replace if same name exists, otherwise append.
	$found = false;
	foreach ( $fonts as $idx => $existing ) {
		$existing_name = $existing['font_family'] ?? $existing['name'] ?? '';
		if ( $existing_name === $font_family ) {
			$fonts[ $idx ] = $font_entry;
			$found         = true;
			break;
		}
	}
	if ( ! $found ) {
		$fonts[] = $font_entry;
	}

	delete_transient( 'bab_fonts' );
	update_option( 'bricks_custom_fonts', $fonts );

	// 2. Append @font-face CSS to global CSS.
	$font_face_css = "\n/* Custom Font: {$font_family} — auto-generated */\n" . implode( "\n", $font_faces );
	$global_css    = get_option( 'bricks_global_custom_css', '' );

	// Remove any previous @font-face block for this font family.
	$pattern    = '/\/\* Custom Font: ' . preg_quote( $font_family, '/' ) . ' — auto-generated \*\/.*?(?=\/\* Custom Font:|$)/s';
	$global_css = preg_replace( $pattern, '', $global_css );

	$global_css .= $font_face_css;

	delete_transient( 'bab_global_css' );
	update_option( 'bricks_global_custom_css', $global_css );

	return new WP_REST_Response( array(
		'success'      => true,
		'font_family'  => $font_family,
		'variants'     => array_unique( $variants ),
		'font_face_css' => $font_face_css,
		'files_count'  => count( $file_urls ),
		'total_fonts'  => count( $fonts ),
	), 200 );
}

/**
 * Get Bricks global custom CSS.
 *
 * @return WP_REST_Response
 */
function bricks_api_bridge_get_global_css() {
	$cached = get_transient( 'bab_global_css' );
	if ( false !== $cached ) {
		return new WP_REST_Response( array( 'global_css' => $cached ), 200 );
	}

	$css = get_option( 'bricks_global_custom_css', '' );
	set_transient( 'bab_global_css', $css, 300 );

	return new WP_REST_Response( array( 'global_css' => $css ), 200 );
}

/**
 * Update Bricks global custom CSS.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function bricks_api_bridge_update_global_css( $request ) {
	$body = $request->get_json_params();

	if ( ! isset( $body['global_css'] ) ) {
		return new WP_REST_Response( array(
			'code'    => 'bricks_api_bridge_invalid_data',
			'message' => 'global_css is required.',
		), 400 );
	}

	delete_transient( 'bab_global_css' );
	update_option( 'bricks_global_custom_css', $body['global_css'] );

	return new WP_REST_Response( array(
		'success'    => true,
		'global_css' => $body['global_css'],
	), 200 );
}

/**
 * Register Full-State Backup REST routes.
 *
 * Exposes the same backup engine the admin Backup section uses, so an AI
 * assistant can snapshot the Bricks layer before risky operations. Admin-only
 * (manage_options). Additive and feature-flagged — flip the option to false to
 * unregister instantly.
 *
 *   POST   /backup/full         → create a stored backup, return metadata
 *   GET    /backup/full         → list stored backups
 *   DELETE /backup/full?file=…  → delete one stored backup
 *   GET    /backup/state        → return the live full-state JSON (no file written)
 */
function bricks_api_bridge_register_backup_routes() {
	if ( ! get_option( 'bab_backup_export_enabled', true ) ) {
		return;
	}
	if ( ! class_exists( 'Bricks_API_Bridge_Backup_Export' ) ) {
		require_once BRICKS_API_BRIDGE_PLUGIN_DIR . 'includes/class-backup-export.php';
	}

	$can_manage = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( 'bricks-bridge/v1', '/backup/full', array(
		array(
			'methods'             => 'POST',
			'permission_callback' => $can_manage,
			'callback'            => function () {
				$result = Bricks_API_Bridge_Backup_Export::create_backup();
				if ( is_wp_error( $result ) ) {
					return new WP_REST_Response( array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					), 500 );
				}
				return new WP_REST_Response( array( 'success' => true, 'backup' => $result ), 200 );
			},
		),
		array(
			'methods'             => 'GET',
			'permission_callback' => $can_manage,
			'callback'            => function () {
				return new WP_REST_Response( array(
					'success' => true,
					'backups' => Bricks_API_Bridge_Backup_Export::list_backups(),
				), 200 );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $can_manage,
			'callback'            => function ( $request ) {
				$file = (string) $request->get_param( 'file' );
				$ok   = Bricks_API_Bridge_Backup_Export::delete_backup( $file );
				return new WP_REST_Response( array(
					'success' => $ok,
					'message' => $ok ? 'Backup deleted.' : 'Backup not found or invalid filename.',
				), $ok ? 200 : 404 );
			},
		),
	) );

	register_rest_route( 'bricks-bridge/v1', '/backup/state', array(
		'methods'             => 'GET',
		'permission_callback' => $can_manage,
		'callback'            => function () {
			return new WP_REST_Response( Bricks_API_Bridge_Backup_Export::build_full_state(), 200 );
		},
	) );

	// Import / restore. Body: { file?: stored-filename, backup?: {full state},
	// options?: {pages,templates,globals,wp_settings,create_missing,force_infra} }.
	// Takes an automatic safety backup first; infra options are protected.
	register_rest_route( 'bricks-bridge/v1', '/backup/import', array(
		'methods'             => 'POST',
		'permission_callback' => $can_manage,
		'callback'            => function ( $request ) {
			$body = $request->get_json_params();
			$opts = isset( $body['options'] ) && is_array( $body['options'] ) ? $body['options'] : array();

			if ( ! empty( $body['file'] ) ) {
				$data = Bricks_API_Bridge_Backup_Export::read_backup( (string) $body['file'] );
			} elseif ( ! empty( $body['backup'] ) && is_array( $body['backup'] ) ) {
				$data = $body['backup'];
			} else {
				return new WP_REST_Response( array(
					'code'    => 'no_input',
					'message' => 'Provide either "file" (a stored backup filename) or "backup" (a full-state object).',
				), 400 );
			}

			if ( is_wp_error( $data ) ) {
				return new WP_REST_Response( array(
					'code'    => $data->get_error_code(),
					'message' => $data->get_error_message(),
				), 400 );
			}

			$report = Bricks_API_Bridge_Backup_Export::import_full_state( $data, $opts );
			if ( is_wp_error( $report ) ) {
				return new WP_REST_Response( array(
					'code'    => $report->get_error_code(),
					'message' => $report->get_error_message(),
				), 400 );
			}
			return new WP_REST_Response( array( 'success' => true, 'report' => $report ), 200 );
		},
	) );
}

add_action( 'rest_api_init', 'bricks_api_bridge_register_backup_routes' );
