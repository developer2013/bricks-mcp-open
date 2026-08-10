<?php
/**
 * Bricks API Bridge — Full-State Backup & Export.
 *
 * Bundles the entire *Bricks layer* of the site — every Bricks page (including
 * drafts and private), templates, global design tokens and the navigation
 * menus — into a single, self-describing JSON artifact. Backups are written to
 * a protected directory inside uploads and are downloadable only through a
 * capability- and nonce-guarded admin handler (never a public URL).
 *
 * What this DOES back up: the design/content layer the MCP can recreate.
 * What it does NOT: media files, the WordPress database, other plugins' data,
 * users or server config. For a true disaster-recovery backup of the whole
 * site, use a host snapshot or a dedicated backup plugin (UpdraftPlus, etc.).
 * The manifest states this explicitly so a restore is never assumed complete.
 *
 * Purely additive: a new file, feature-flagged in the main plugin. It adds no
 * REST routes and changes no existing behavior. A site that never triggers a
 * backup is wholly unaffected.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bricks_API_Bridge_Backup_Export {

	/** Sub-directory (under uploads) where backup files live. */
	const DIR_NAME = 'bricks-mcp-backups';

	/** Backup file format version — bump when the schema changes. */
	const FORMAT = 2;

	/** Filename prefix; the strict pattern below also guards against traversal. */
	const PREFIX = 'bricks-fullstate-';

	/**
	 * Build the complete Bricks-layer state as a PHP array.
	 *
	 * Self-contained: reads post meta and options directly so it never depends
	 * on the REST controllers' request lifecycle.
	 *
	 * @return array
	 */
	public static function build_full_state() {
		$bricks_active = defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Database' );

		$state = array(
			'manifest' => array(
				'format'         => self::FORMAT,
				'plugin_version' => defined( 'BRICKS_API_BRIDGE_VERSION' ) ? BRICKS_API_BRIDGE_VERSION : '?',
				'bricks_version' => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : null,
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
				'site_url'       => get_site_url(),
				'home_url'       => home_url(),
				'timestamp'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'generated_by'   => wp_get_current_user()->user_login,
				// Honest scope statement — a restore from this file is NOT a full
				// site restore. Surfaced in the dashboard too.
				'not_included'   => array(
					'media_files'      => 'Image/PDF binaries in the media library (only references are saved).',
					'database'         => 'No SQL dump — only Bricks options are captured.',
					'other_plugins'    => 'Settings of non-Bricks plugins are not included.',
					'users_and_config' => 'User accounts, roles and wp-config are not included.',
				),
			),
		);

		// ── Pages: ALL statuses, not just published (drafts matter for backups). ──
		$pages = array();
		if ( $bricks_active ) {
			$posts = get_posts(
				array(
					'post_type'      => 'page',
					'posts_per_page' => -1,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'meta_key'       => '_bricks_page_content_2',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			foreach ( $posts as $page ) {
				$thumb_id = get_post_thumbnail_id( $page->ID );
				$pages[]  = array(
					'id'             => $page->ID,
					'title'          => $page->post_title,
					'slug'           => $page->post_name,
					'status'         => $page->post_status,
					'parent'         => (int) $page->post_parent,
					'menu_order'     => (int) $page->menu_order,
					'date'           => $page->post_date_gmt,
					'modified'       => $page->post_modified_gmt,
					'featured_image' => $thumb_id
						? array( 'id' => (int) $thumb_id, 'url' => wp_get_attachment_url( $thumb_id ) )
						: null,
					'elements'       => get_post_meta( $page->ID, '_bricks_page_content_2', true ) ?: array(),
					'header'         => get_post_meta( $page->ID, '_bricks_page_header_2', true ) ?: null,
					'footer'         => get_post_meta( $page->ID, '_bricks_page_footer_2', true ) ?: null,
					'page_settings'  => get_post_meta( $page->ID, '_bricks_page_settings', true ) ?: null,
					'meta'           => self::collect_meta( $page->ID ),
				);
			}
		}
		$state['pages'] = $pages;

		// ── Templates. ──
		$templates = array();
		if ( $bricks_active ) {
			$tmpls = get_posts(
				array(
					'post_type'      => 'bricks_template',
					'posts_per_page' => -1,
					'post_status'    => array( 'publish', 'draft' ),
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $tmpls as $tmpl ) {
				$templates[] = array(
					'id'            => $tmpl->ID,
					'title'         => $tmpl->post_title,
					'status'        => $tmpl->post_status,
					'type'          => get_post_meta( $tmpl->ID, '_bricks_template_type', true ) ?: 'section',
					'conditions'    => get_post_meta( $tmpl->ID, '_bricks_template_conditions', true ) ?: array(),
					'elements'      => get_post_meta( $tmpl->ID, '_bricks_page_content_2', true ) ?: array(),
					/*
					 * Header and footer templates keep their elements in
					 * _bricks_page_header_2 / _bricks_page_footer_2. On those posts
					 * _bricks_page_content_2 is an empty array, so without these three keys
					 * the export captured a header or footer template as a title-and-type
					 * stub with no elements, while still counting it as a template.
					 *
					 * The pages loop above already captures all three, and restore_post()
					 * already consumes them. Only this loop was missing them.
					 *
					 * page_settings is captured here for the same reason: collect_meta()
					 * skips _bricks_page_settings, so a template's documentTitle and
					 * metaDescription were dropped too.
					 */
					'header'        => get_post_meta( $tmpl->ID, '_bricks_page_header_2', true ) ?: null,
					'footer'        => get_post_meta( $tmpl->ID, '_bricks_page_footer_2', true ) ?: null,
					'page_settings' => get_post_meta( $tmpl->ID, '_bricks_page_settings', true ) ?: null,
					'meta'          => self::collect_meta( $tmpl->ID ),
				);
			}
		}
		$state['templates'] = $templates;

		// ── Globals (design tokens). ──
		$state['globals'] = array(
			'theme_styles'    => get_option( 'bricks_theme_styles', array() ),
			'global_classes'  => get_option( 'bricks_global_classes', array() ),
			'color_palette'   => get_option( 'bricks_color_palette', array() ),
			'fonts'           => get_option( 'bricks_custom_fonts', array() ),
			'css_variables'   => get_option( 'bricks_global_variables', array() ),
			'global_css'      => get_option( 'bricks_global_custom_css', '' ),
			'global_settings' => get_option( 'bricks_global_settings', array() ),
		);

		// ── Menus (NEW vs. bulk_export — structure + items + locations). ──
		$state['menus'] = self::collect_menus();

		// ── WordPress core settings (curated allowlist — never secrets). ──
		$state['wp_settings'] = self::collect_wp_settings();

		return $state;
	}

	/**
	 * Collect WordPress core settings from a curated allowlist.
	 *
	 * Deliberately an allowlist, NOT the whole wp_options table: a full dump
	 * would sweep in transients, session tokens and — critically — API keys or
	 * secrets that other plugins store as options, all into a downloadable file.
	 * This captures exactly what the Settings → General/Writing/Reading/
	 * Discussion/Media/Permalinks screens manage. Extend via the
	 * `bab_backup_export_options` filter if you need more.
	 *
	 * @return array { option_key => value }
	 */
	private static function collect_wp_settings() {
		$out = array();
		foreach ( self::allowed_setting_keys() as $key ) {
			$val = get_option( $key, null );
			if ( null !== $val ) {
				$out[ $key ] = $val;
			}
		}
		return $out;
	}

	/**
	 * Curated allowlist of WordPress core option keys — the single source of
	 * truth for BOTH export (collect_wp_settings) and import (import_full_state).
	 * Import writes ONLY keys in this list, so a crafted/poisoned backup file
	 * cannot set arbitrary options (e.g. default_role, an autoload-bloat key); it
	 * can only touch the same core-settings surface the export captures.
	 *
	 * @return string[] Allowlisted option keys.
	 */
	private static function allowed_setting_keys() {
		$keys = array(
			// General.
			'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email',
			'users_can_register', 'default_role', 'timezone_string', 'gmt_offset',
			'date_format', 'time_format', 'start_of_week', 'WPLANG',
			// Writing.
			'default_category', 'default_post_format', 'use_smilies', 'default_email_category',
			// Reading.
			'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page',
			'posts_per_rss', 'rss_use_excerpt', 'blog_public',
			// Discussion.
			'default_comment_status', 'default_ping_status', 'comment_registration',
			'require_name_email', 'comments_notify', 'moderation_notify', 'comment_moderation',
			'comment_max_links', 'comments_per_page', 'thread_comments', 'thread_comments_depth',
			'page_comments', 'close_comments_for_old_posts', 'close_comments_days_old',
			'show_avatars', 'avatar_default', 'avatar_rating',
			// Media.
			'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
			'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
			'uploads_use_yearmonth_folders',
			// Permalinks.
			'permalink_structure', 'category_base', 'tag_base',
			// Active theme + plugins (for restore context, not the code itself).
			'template', 'stylesheet', 'active_plugins',
		);

		/**
		 * Filter the WordPress option keys captured in / restorable from a
		 * full-state backup.
		 *
		 * @param string[] $keys Allowlisted option keys.
		 */
		return (array) apply_filters( 'bab_backup_export_options', $keys );
	}

	/**
	 * Collect the Bricks-/bridge-/SEO-relevant post meta for one post.
	 *
	 * Filtered to known prefixes so we never dump unrelated (and possibly
	 * sensitive) meta from third-party plugins.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function collect_meta( $post_id ) {
		$all   = get_post_meta( $post_id );
		$keep  = array();
		$prefixes = array( '_bab_', '_bricks', '_yoast_wpseo', 'rank_math' );
		// Large Bricks payloads already captured as top-level fields — skip the
		// duplicates here so the backup file stays lean.
		$skip = array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2', '_bricks_page_settings' );
		// Per-page rotating backups and named snapshots are themselves full copies
		// of past element states. Capturing them would nest a backup-of-backups
		// (often larger than the live content) and balloon every full-state file.
		// The current state is already saved as `elements`, so drop the history.
		$skip_prefixes = array( '_bricks_backup_', '_bricks_snapshots' );

		foreach ( (array) $all as $key => $vals ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( $skip_prefixes as $sp ) {
				if ( 0 === strpos( $key, $sp ) ) {
					continue 2;
				}
			}
			foreach ( $prefixes as $p ) {
				if ( 0 === strpos( $key, $p ) ) {
					// get_post_meta() returns each value as a single-element array
					// of serialized strings; unserialize for a clean snapshot.
					$keep[ $key ] = array_map( 'maybe_unserialize', (array) $vals );
					if ( 1 === count( $keep[ $key ] ) ) {
						$keep[ $key ] = $keep[ $key ][0];
					}
					break;
				}
			}
		}
		return $keep;
	}

	/**
	 * Collect all navigation menus with their items and theme-location map.
	 *
	 * @return array
	 */
	private static function collect_menus() {
		$out       = array( 'locations' => array(), 'menus' => array() );
		$locations = get_nav_menu_locations();
		$out['locations'] = is_array( $locations ) ? $locations : array();

		$menus = wp_get_nav_menus();
		foreach ( (array) $menus as $menu ) {
			$items     = wp_get_nav_menu_items( $menu->term_id );
			$item_data = array();
			foreach ( (array) $items as $it ) {
				$item_data[] = array(
					'id'        => (int) $it->ID,
					'title'     => $it->title,
					'url'       => $it->url,
					'parent'    => (int) $it->menu_item_parent,
					'order'     => (int) $it->menu_order,
					'type'      => $it->type,        // post_type | taxonomy | custom.
					'object'    => $it->object,      // page | category | custom.
					'object_id' => (int) $it->object_id,
					'target'    => $it->target,
					'classes'   => $it->classes,
				);
			}
			$out['menus'][] = array(
				'id'    => (int) $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'count' => (int) $menu->count,
				'items' => $item_data,
			);
		}
		return $out;
	}

	// ═══════════════════════════════════════════════════════════
	//  FILE STORAGE  (protected uploads dir + retention)
	// ═══════════════════════════════════════════════════════════

	/**
	 * Absolute path to the protected backup directory, created on demand.
	 *
	 * Hardened on first creation with an .htaccess deny rule and an empty
	 * index.php so the JSON files can never be fetched directly over HTTP.
	 *
	 * @return string|WP_Error Directory path, or error if not writable.
	 */
	public static function backup_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'uploads_unavailable', $uploads['error'] );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'mkdir_failed', 'Could not create the backup directory.' );
			}
		}
		// Always (re)assert the guards — cheap, and self-heals if removed.
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			file_put_contents( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		$idx = $dir . '/index.php';
		if ( ! file_exists( $idx ) ) {
			file_put_contents( $idx, "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Build a full-state backup and write it to disk.
	 *
	 * @return array|WP_Error { file, size, time, counts } or error.
	 */
	public static function create_backup() {
		$dir = self::backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$state = self::build_full_state();
		$json  = wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new WP_Error( 'encode_failed', 'Failed to encode the backup to JSON.' );
		}

		// Random token → unguessable filename, so the file stays private even on
		// servers (e.g. nginx) that ignore the directory's .htaccess deny rule.
		$token = wp_generate_password( 20, false );
		$name  = self::PREFIX . gmdate( 'Ymd-His' ) . '-' . $token . '.json';
		$path  = trailingslashit( $dir ) . $name;

		if ( false === file_put_contents( $path, $json ) ) {
			return new WP_Error( 'write_failed', 'Failed to write the backup file.' );
		}

		self::prune();

		return array(
			'file'   => $name,
			'size'   => filesize( $path ),
			'time'   => filemtime( $path ),
			'counts' => array(
				'pages'     => count( $state['pages'] ),
				'templates' => count( $state['templates'] ),
				'menus'     => count( $state['menus']['menus'] ),
			),
		);
	}

	/**
	 * List existing backup files, newest first.
	 *
	 * @return array[] Each { file, size, time }.
	 */
	public static function list_backups() {
		$dir = self::backup_dir();
		if ( is_wp_error( $dir ) ) {
			return array();
		}
		$out   = array();
		$files = glob( trailingslashit( $dir ) . self::PREFIX . '*.json' );
		foreach ( (array) $files as $path ) {
			$out[] = array(
				'file' => basename( $path ),
				'size' => filesize( $path ),
				'time' => filemtime( $path ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);
		return $out;
	}

	/**
	 * Keep only the most recent N backups; delete the rest.
	 */
	public static function prune() {
		$keep = (int) apply_filters( 'bab_backup_export_keep', 10 );
		if ( $keep < 1 ) {
			$keep = 1;
		}
		$list = self::list_backups();
		if ( count( $list ) <= $keep ) {
			return;
		}
		foreach ( array_slice( $list, $keep ) as $old ) {
			self::delete_backup( $old['file'] );
		}
	}

	/**
	 * Validate a user-supplied filename against the strict backup pattern.
	 *
	 * Defends against path traversal: only the strict
	 * `bricks-fullstate-YYYYMMDD-HHMMSS-<token>.json` basename is ever accepted.
	 *
	 * The token length is a range, not a fixed 6. write_state() generates the name
	 * with wp_generate_password( 20, false ), so a fixed {6} rejected every filename
	 * this class had ever written, and restore_state() could not be reached for any
	 * stored backup. The range keeps shorter legacy names resolvable.
	 *
	 * The traversal guard is unchanged: basename() strips any path, the character
	 * set stays strictly alphanumeric, and the .json suffix is still required.
	 *
	 * @param string $file Candidate filename.
	 * @return string|false Safe basename, or false if invalid.
	 */
	public static function safe_name( $file ) {
		$file = basename( (string) $file );
		if ( preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '\d{8}-\d{6}-[A-Za-z0-9]{6,32}\.json$/', $file ) ) {
			return $file;
		}
		return false;
	}

	/**
	 * Delete one backup file by name.
	 *
	 * @param string $file Filename.
	 * @return bool
	 */
	public static function delete_backup( $file ) {
		$name = self::safe_name( $file );
		if ( ! $name ) {
			return false;
		}
		$dir = self::backup_dir();
		if ( is_wp_error( $dir ) ) {
			return false;
		}
		$path = trailingslashit( $dir ) . $name;
		return file_exists( $path ) ? unlink( $path ) : false;
	}

	/**
	 * Stream a backup file to the browser as a download, then exit.
	 *
	 * Caller MUST have already verified capability + nonce.
	 *
	 * @param string $file Filename.
	 */
	public static function stream_download( $file ) {
		$name = self::safe_name( $file );
		$dir  = self::backup_dir();
		if ( ! $name || is_wp_error( $dir ) ) {
			wp_die( esc_html__( 'Invalid backup file.', 'bricks-api-bridge' ) );
		}
		$path = trailingslashit( $dir ) . $name;
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'bricks-api-bridge' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Read + decode a stored backup file by name.
	 *
	 * @param string $file Filename.
	 * @return array|WP_Error Decoded state, or error.
	 */
	public static function read_backup( $file ) {
		$name = self::safe_name( $file );
		$dir  = self::backup_dir();
		if ( ! $name || is_wp_error( $dir ) ) {
			return new WP_Error( 'invalid_file', 'Invalid backup filename.' );
		}
		$path = trailingslashit( $dir ) . $name;
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'not_found', 'Backup file not found.' );
		}
		$data = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'corrupt', 'Backup file is not valid JSON.' );
		}
		return $data;
	}

	// ═══════════════════════════════════════════════════════════
	//  IMPORT / RESTORE
	// ═══════════════════════════════════════════════════════════

	/**
	 * Restore a full-state backup into this site.
	 *
	 * SAFE BY DESIGN:
	 *  - Takes an automatic safety backup of the current state FIRST, so the
	 *    import itself is reversible.
	 *  - WordPress infrastructure options (siteurl/home/active_plugins/theme)
	 *    are NEVER overwritten unless `force_infra` is explicitly set — this
	 *    stops a cross-site import from breaking the live domain or theme.
	 *  - Each section is opt-in via $opts.
	 *
	 * Pages/templates are matched by ID (intended for same-site restore). Direct
	 * meta writes preserve element IDs exactly as backed up (no regeneration).
	 *
	 * @param array $data Decoded full-state backup.
	 * @param array $opts Section toggles.
	 * @return array|WP_Error Report, or error.
	 */
	public static function import_full_state( $data, $opts = array() ) {
		if ( ! is_array( $data ) || empty( $data['manifest'] ) ) {
			return new WP_Error( 'invalid_backup', 'Not a valid full-state backup (missing manifest).' );
		}

		$opts = wp_parse_args( $opts, array(
			'pages'          => true,
			'templates'      => true,
			'globals'        => true,
			'wp_settings'    => true,
			'create_missing' => true,
			'force_infra'    => false,
		) );

		// Safety net before touching anything.
		$safety = self::create_backup();

		$report = array(
			'safety_backup' => is_wp_error( $safety ) ? null : $safety['file'],
			'pages'         => array( 'updated' => 0, 'created' => 0, 'skipped' => 0 ),
			'templates'     => array( 'updated' => 0, 'created' => 0, 'skipped' => 0 ),
			'globals'       => 0,
			'wp_settings'   => array( 'updated' => 0, 'protected' => array(), 'skipped' => array() ),
			'menus'         => 'not imported (menu restore is a planned follow-up)',
			'warnings'      => array(),
		);

		$src = isset( $data['manifest']['site_url'] ) ? rtrim( $data['manifest']['site_url'], '/' ) : '';
		if ( $src && $src !== rtrim( get_site_url(), '/' ) ) {
			$report['warnings'][] = "Backup is from a different site ($src). Pages/templates are matched by ID — IDs may not line up across sites.";
		}

		if ( ! empty( $opts['pages'] ) && ! empty( $data['pages'] ) ) {
			foreach ( $data['pages'] as $pg ) {
				$report['pages'][ self::restore_post( $pg, 'page', $opts['create_missing'] ) ]++;
			}
		}

		if ( ! empty( $opts['templates'] ) && ! empty( $data['templates'] ) ) {
			foreach ( $data['templates'] as $tp ) {
				$report['templates'][ self::restore_post( $tp, 'bricks_template', $opts['create_missing'] ) ]++;
			}
		}

		if ( ! empty( $opts['globals'] ) && ! empty( $data['globals'] ) ) {
			$map = array(
				'theme_styles'    => 'bricks_theme_styles',
				'global_classes'  => 'bricks_global_classes',
				'color_palette'   => 'bricks_color_palette',
				'fonts'           => 'bricks_custom_fonts',
				'css_variables'   => 'bricks_global_variables',
				'global_css'      => 'bricks_global_custom_css',
				'global_settings' => 'bricks_global_settings',
			);
			foreach ( $map as $k => $opt ) {
				if ( array_key_exists( $k, $data['globals'] ) ) {
					update_option( $opt, $data['globals'][ $k ] );
					$report['globals']++;
				}
			}
		}

		if ( ! empty( $opts['wp_settings'] ) && ! empty( $data['wp_settings'] ) ) {
			// Restore ONLY keys on the same curated allowlist the export uses —
			// otherwise a crafted/poisoned backup could set ARBITRARY options
			// (default_role, autoload bloat, …) via update_option.
			$allowed = self::allowed_setting_keys();
			// Infrastructure keys that would break a (cross-site) install — never
			// touched unless the caller explicitly forces it.
			$infra = array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet' );
			foreach ( $data['wp_settings'] as $k => $v ) {
				if ( ! in_array( $k, $allowed, true ) ) {
					$report['wp_settings']['skipped'][] = $k;
					continue;
				}
				if ( in_array( $k, $infra, true ) && empty( $opts['force_infra'] ) ) {
					$report['wp_settings']['protected'][] = $k;
					continue;
				}
				update_option( $k, $v );
				$report['wp_settings']['updated']++;
			}
		}

		return $report;
	}

	/**
	 * Restore one page/template record from a backup item.
	 *
	 * @param array  $item           Backup item (id, title, elements, meta, …).
	 * @param string $post_type      'page' or 'bricks_template'.
	 * @param bool   $create_missing Create the post if its ID no longer exists.
	 * @return string 'updated' | 'created' | 'skipped'.
	 */
	private static function restore_post( $item, $post_type, $create_missing ) {
		$id     = isset( $item['id'] ) ? (int) $item['id'] : 0;
		$exists = $id && get_post( $id );

		if ( ! $exists ) {
			if ( ! $create_missing ) {
				return 'skipped';
			}
			$new = wp_insert_post( array(
				'post_type'   => $post_type,
				'post_title'  => $item['title'] ?? 'Restored',
				'post_name'   => $item['slug'] ?? '',
				'post_status' => $item['status'] ?? 'draft',
				'post_parent' => isset( $item['parent'] ) ? (int) $item['parent'] : 0,
				'menu_order'  => isset( $item['menu_order'] ) ? (int) $item['menu_order'] : 0,
			), true );
			if ( is_wp_error( $new ) ) {
				return 'skipped';
			}
			$id      = $new;
			$created = true;
		} else {
			$upd = array( 'ID' => $id );
			if ( isset( $item['title'] ) ) {
				$upd['post_title'] = $item['title'];
			}
			if ( isset( $item['status'] ) ) {
				$upd['post_status'] = $item['status'];
			}
			wp_update_post( $upd );
			$created = false;
		}

		// Element data — direct meta writes preserve IDs exactly (no regeneration).
		if ( isset( $item['elements'] ) ) {
			update_post_meta( $id, '_bricks_page_content_2', $item['elements'] );
		}
		if ( ! empty( $item['header'] ) ) {
			update_post_meta( $id, '_bricks_page_header_2', $item['header'] );
		}
		if ( ! empty( $item['footer'] ) ) {
			update_post_meta( $id, '_bricks_page_footer_2', $item['footer'] );
		}
		if ( ! empty( $item['page_settings'] ) ) {
			update_post_meta( $id, '_bricks_page_settings', $item['page_settings'] );
		}

		// Captured meta (SEO, schema, scripts, assets, gsap flag, template type…).
		if ( ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
			foreach ( $item['meta'] as $mk => $mv ) {
				update_post_meta( $id, $mk, $mv );
			}
		}

		if ( 'bricks_template' === $post_type ) {
			if ( isset( $item['type'] ) ) {
				update_post_meta( $id, '_bricks_template_type', $item['type'] );
			}
			if ( isset( $item['conditions'] ) ) {
				update_post_meta( $id, '_bricks_template_conditions', $item['conditions'] );
			}
		}

		if ( ! empty( $item['featured_image']['id'] ) ) {
			set_post_thumbnail( $id, (int) $item['featured_image']['id'] );
		}

		return $created ? 'created' : 'updated';
	}
}
