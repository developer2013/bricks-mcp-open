<?php
/**
 * Pages controller for Bricks page data.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Pages
 *
 * Handles listing, reading, and updating Bricks page data via the REST API.
 */
class Bricks_API_Bridge_Pages {

	/**
	 * Backup manager instance.
	 *
	 * @var Bricks_API_Bridge_Backup
	 */
	private $backup_manager;

	/**
	 * Validator instance.
	 *
	 * @var Bricks_API_Bridge_Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->backup_manager = new Bricks_API_Bridge_Backup();
		$this->validator      = new Bricks_API_Bridge_Validator();
	}

	/**
	 * List pages and posts that have Bricks page data.
	 *
	 * Supports query parameters:
	 * - post_type (string): Post type to query. Default: 'page'.
	 * - status (string): Post status to filter by. Default: 'publish'.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_pages( $request ) {
		$post_type = sanitize_text_field( $request->get_param( 'post_type' ) );
		$status    = sanitize_text_field( $request->get_param( 'status' ) );
		$fields    = array_filter( explode( ',', (string) $request->get_param( 'fields' ) ) );

		if ( empty( $post_type ) ) {
			$post_type = 'page';
		}

		if ( empty( $status ) ) {
			$status = 'publish';
		}

		// Validate post type exists.
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_post_type',
				sprintf(
					/* translators: %s: post type name */
					__( 'Invalid post type: %s', 'bricks-api-bridge' ),
					$post_type
				),
				array( 'status' => 400 )
			);
		}

		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 || $per_page > 500 ) {
			$per_page = 100;
		}
		$paged = max( 1, (int) $request->get_param( 'page' ) );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $paged,
		);

		// Add search term if provided.
		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		// Try Bricks Database class first, with fallback meta keys.
		$meta_keys = array( '_bricks_page_content_2', '_bricks_page_content', '_bricks_page_data' );
		$use_bricks_db = class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' );

		// If not using Bricks Database, add meta_query to only return posts with Bricks data.
		if ( ! $use_bricks_db ) {
			$meta_sub_queries = array( 'relation' => 'OR' );
			foreach ( $meta_keys as $key ) {
				$meta_sub_queries[] = array(
					'key'     => $key,
					'compare' => 'EXISTS',
				);
			}
			$args['meta_query'] = $meta_sub_queries; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new WP_Query( $args );
		$pages = array();

		// Prime the meta cache in a single query to avoid N+1 get_post_meta() calls.
		if ( ! empty( $query->posts ) ) {
			$post_ids = wp_list_pluck( $query->posts, 'ID' );
			update_meta_cache( 'post', $post_ids );
		}

		$need_element_count = empty( $fields ) || in_array( 'element_count', $fields, true );

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$element_count = 0;

				if ( $need_element_count ) {
					$bricks_data = null;

					// Method 1: Use Bricks' own Database class.
					if ( $use_bricks_db ) {
						$bricks_data = \Bricks\Database::get_data( $post->ID, 'content' );
					}

					// Method 2: Try meta keys directly.
					if ( empty( $bricks_data ) ) {
						foreach ( $meta_keys as $key ) {
							$meta = get_post_meta( $post->ID, $key, true );
							if ( ! empty( $meta ) ) {
								$bricks_data = $meta;
								break;
							}
						}
					}

					// Skip posts without any Bricks data.
					if ( empty( $bricks_data ) ) {
						continue;
					}

					$element_count = is_array( $bricks_data ) ? count( $bricks_data ) : 0;
				}

				$page = array(
					'id'            => $post->ID,
					'title'         => get_the_title( $post->ID ),
					'slug'          => $post->post_name,
					'status'        => $post->post_status,
					'type'          => $post->post_type,
					'element_count' => $element_count,
					'modified'      => $post->post_modified,
					'url'           => get_permalink( $post->ID ),
				);

				// Filter to only requested fields (always include 'id').
				if ( ! empty( $fields ) ) {
					$page = array_intersect_key( $page, array_flip( array_merge( array( 'id' ), $fields ) ) );
				}

				$pages[] = $page;
			}
		}

		return rest_ensure_response( $pages );
	}

	/**
	 * Get a single page with its full Bricks data.
	 *
	 * Returns the page metadata along with the complete Bricks element array,
	 * plus header and footer data if they exist.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_page( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$bricks_data   = null;
		$meta_key_used = '';
		$meta_keys     = array( '_bricks_page_content_2', '_bricks_page_content', '_bricks_page_data' );

		// Method 1: Bricks Database class.
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$bricks_data   = \Bricks\Database::get_data( $post_id, 'content' );
			$meta_key_used = 'Bricks\\Database::get_data';
		}

		// Method 2: Try meta keys directly.
		if ( empty( $bricks_data ) ) {
			foreach ( $meta_keys as $key ) {
				$meta = get_post_meta( $post_id, $key, true );
				if ( ! empty( $meta ) ) {
					$bricks_data   = $meta;
					$meta_key_used = $key;
					break;
				}
			}
		}

		if ( empty( $bricks_data ) ) {
			return new WP_Error(
				'bricks_api_bridge_no_bricks_data',
				__( 'No Bricks page data found for this post.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		// Compute content hash for optimistic locking.
		$content_hash = md5( wp_json_encode( $bricks_data ) );

		$response = array(
			'id'           => $post->ID,
			'title'        => get_the_title( $post->ID ),
			'slug'         => $post->post_name,
			'status'       => $post->post_status,
			'type'         => $post->post_type,
			'url'          => get_permalink( $post->ID ),
			'meta_key'     => $meta_key_used,
			'content_hash' => $content_hash,
			'bricks_data'  => $bricks_data,
		);

		// Include header data if it exists.
		$header_data = get_post_meta( $post_id, '_bricks_page_header_2', true );
		if ( empty( $header_data ) ) {
			$header_data = get_post_meta( $post_id, '_bricks_page_header_data', true );
		}
		if ( ! empty( $header_data ) ) {
			$response['bricks_header_data'] = $header_data;
		}

		// Include footer data if it exists.
		$footer_data = get_post_meta( $post_id, '_bricks_page_footer_2', true );
		if ( empty( $footer_data ) ) {
			$footer_data = get_post_meta( $post_id, '_bricks_page_footer_data', true );
		}
		if ( ! empty( $footer_data ) ) {
			$response['bricks_footer_data'] = $footer_data;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Update a page's Bricks data.
	 *
	 * 1. Creates a backup of the current data.
	 * 2. Validates the incoming content.
	 * 3. Updates the _bricks_page_data post meta.
	 * 4. Regenerates the Bricks CSS file.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Object-level authorization gate for page-mutating handlers.
	 *
	 * The route permission_callback only checks the generic `edit_posts`
	 * capability, so without this an Author/Editor — or a stolen low-privilege
	 * Application Password — could overwrite ANY page by ID, including pages
	 * they don't own. Administrators (manage_options) always pass, so the MCP's
	 * own admin-token flow is unaffected. Callers invoke this AFTER the
	 * existence (404) check so missing pages still return 404 for everyone.
	 *
	 * @param int $post_id The target post ID (already confirmed to exist).
	 * @return WP_Error|null WP_Error(403) when not allowed, null otherwise.
	 */
	private function require_can_edit( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'bricks_api_bridge_forbidden',
				__( 'You do not have permission to edit this page.', 'bricks-api-bridge' ),
				array( 'status' => 403 )
			);
		}
		return null;
	}

	public function update_page( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$denied = $this->require_can_edit( $post_id );
		if ( $denied ) {
			return $denied;
		}

		$body = $request->get_json_params();

		// Determine which content area to write. Default: content.
		$content_area = sanitize_text_field( $body['content_area'] ?? 'content' );
		$valid_areas  = array( 'content', 'header', 'footer' );
		if ( ! in_array( $content_area, $valid_areas, true ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_area',
				__( 'content_area must be one of: content, header, footer.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $body['bricks_data'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_data',
				__( 'Request body must include "bricks_data" key.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Optimistic locking: check If-Match header against current content hash.
		$if_match = $request->get_header( 'If-Match' );
		if ( ! empty( $if_match ) ) {
			$conflict = $this->check_content_hash( $post_id, $if_match );
			if ( is_wp_error( $conflict ) ) {
				return $conflict;
			}
		}

		$content       = $body['bricks_data'];
		$pre_validated = (bool) $request->get_param( 'pre_validated' );

		// Auto-fix common issues before validation. A full save of EXISTING page content
		// defaults to 'preserve' (keep element types + IDs verbatim); pass autofix_mode or
		// set the bab_autofix_mode option/BAB_AUTOFIX_MODE constant to override. See issue #13.
		$autofix_mode = Bricks_API_Bridge_Autofix::resolve_mode( $request );
		$fix_result   = Bricks_API_Bridge_Autofix::autofix( $content, $autofix_mode );
		$content      = $fix_result['content'];

		// Quirks coercion: silent fixes (e.g. link.postId int→string) plus
		// detection warnings for things we can't safely auto-rewrite.
		$quirks_result = Bricks_API_Bridge_Quirks_Coercion::process( $content );

		// Validate the incoming content unless pre-validated.
		if ( ! $pre_validated ) {
			$validation = $this->validator->validate( $content );

			if ( ! $validation['valid'] ) {
				return new WP_Error(
					'bricks_api_bridge_validation_failed',
					__( 'Bricks data validation failed.', 'bricks-api-bridge' ),
					array(
						'status' => 400,
						'errors' => $validation['errors'],
					)
				);
			}
		}

		// Create a backup of the current data before overwriting.
		$backup_result = $this->backup_manager->create_backup( $post_id );

		if ( is_wp_error( $backup_result ) ) {
			// If no data exists to backup (new page), that is acceptable. Proceed.
			$error_code = $backup_result->get_error_code();
			if ( 'bricks_api_bridge_no_data' !== $error_code ) {
				return $backup_result;
			}
		}

		// Determine which meta key to write to based on content_area.
		$area_meta_map = array(
			'content' => '_bricks_page_content_2',
			'header'  => '_bricks_page_header_2',
			'footer'  => '_bricks_page_footer_2',
		);
		$write_key = $area_meta_map[ $content_area ];

		if ( 'content' === $content_area ) {
			$meta_keys = array( '_bricks_page_content_2', '_bricks_page_content', '_bricks_page_data' );
			foreach ( $meta_keys as $key ) {
				$existing = get_post_meta( $post_id, $key, true );
				if ( ! empty( $existing ) ) {
					$write_key = $key;
					break;
				}
			}
		}

		// Ensure Bricks editor mode is active for this page.
		update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

		// Ensure Bricks recognises this page as having Bricks content.
		if ( ! get_post_meta( $post_id, '_bricks_template_type', true ) ) {
			$post_type = get_post_type( $post_id );
			$tpl_type  = ( 'bricks_template' === $post_type ) ? '' : 'content';
			if ( $tpl_type ) {
				update_post_meta( $post_id, '_bricks_template_type', $tpl_type );
			}
		}

		// Update using Bricks Database class if available, otherwise direct meta.
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $post_id, $content, $content_area );
		} else {
			update_post_meta( $post_id, $write_key, $content );
		}

		// Read-after-write verification.
		$expected_count = is_array( $content ) ? count( $content ) : 0;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$saved = \Bricks\Database::get_data( $post_id, $content_area );
		} else {
			$saved = get_post_meta( $post_id, $write_key, true );
		}
		$saved_count = is_array( $saved ) ? count( $saved ) : 0;
		if ( $saved_count !== $expected_count ) {
			return new WP_Error(
				'bricks_api_bridge_write_truncated',
				sprintf(
					/* translators: 1: expected element count, 2: actual saved count */
					__( 'Write verification failed: sent %1$d elements but server has %2$d. Likely cause: PHP post_max_size, WP postmeta size limit, or set_data filter dropped elements. Restore from snapshot if data is wrong.', 'bricks-api-bridge' ),
					$expected_count,
					$saved_count
				),
				array(
					'status'    => 500,
					'expected'  => $expected_count,
					'saved'     => $saved_count,
				)
			);
		}

		// Purge caches (CSS regeneration + cache plugins).
		$regenerate_css = $request->get_param( 'regenerate_css' );
		if ( false !== $regenerate_css ) {
			bricks_api_bridge_purge_post_cache( $post_id );
		}

		// Auto-learn from the saved data.
		if ( class_exists( 'Bricks_API_Bridge_Presets' ) ) {
			Bricks_API_Bridge_Presets::auto_learn_from_page( $post_id, $content );
		}

		// Quirks coercion: write collected image alts to media library after
		// the bricks save (so a failing alt-write can't undo the page save).
		$alts_written = 0;
		if ( ! empty( $quirks_result['image_alts'] ) ) {
			$alts_written = Bricks_API_Bridge_Quirks_Coercion::write_image_alts( $quirks_result['image_alts'] );
		}

		$element_count = is_array( $content ) ? count( $content ) : 0;

		$response = array(
			'success'       => true,
			'message'       => __( 'Page data updated successfully.', 'bricks-api-bridge' ),
			'post_id'       => $post_id,
			'element_count' => $element_count,
		);
		if ( ! empty( $quirks_result['warnings'] ) ) {
			$response['quirks_warnings'] = $quirks_result['warnings'];
		}
		if ( $alts_written > 0 ) {
			$response['image_alts_synced'] = $alts_written;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Apply delta updates to a page's Bricks data.
	 *
	 * Supports add, update, and remove operations on individual elements
	 * without replacing the entire page content.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function patch_page( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$denied = $this->require_can_edit( $post_id );
		if ( $denied ) {
			return $denied;
		}

		$body = $request->get_json_params();

		// Determine content area for patch.
		$content_area = sanitize_text_field( $body['content_area'] ?? 'content' );
		$valid_areas  = array( 'content', 'header', 'footer' );
		if ( ! in_array( $content_area, $valid_areas, true ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_area',
				__( 'content_area must be one of: content, header, footer.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Optimistic locking: check If-Match header against current content hash.
		$if_match = $request->get_header( 'If-Match' );
		if ( ! empty( $if_match ) ) {
			$conflict = $this->check_content_hash( $post_id, $if_match );
			if ( is_wp_error( $conflict ) ) {
				return $conflict;
			}
		}

		$add    = isset( $body['add'] ) ? $body['add'] : array();
		$update = isset( $body['update'] ) ? $body['update'] : array();
		$remove = isset( $body['remove'] ) ? $body['remove'] : array();

		// Load current data BEFORE validation so we know existing IDs.
		$current = $this->load_page_data( $post_id, $content_area );

		// Extract existing element IDs for context-aware validation.
		$existing_ids = array();
		foreach ( $current as $el ) {
			if ( is_array( $el ) && isset( $el['id'] ) ) {
				$existing_ids[] = $el['id'];
			}
		}

		// Validate and autofix new elements with existing IDs context.
		$fix_log         = array();
		$warnings        = array();
		$info_hints      = array();
		$quirks_warnings = array();
		$pending_alts    = array();

		// Quirks coercion on add[] — silent fixes (postId int→string) plus
		// warnings (var() backgrounds, container _html). Image alts collected
		// here are written to the media library after save.
		if ( ! empty( $add ) ) {
			$qr              = Bricks_API_Bridge_Quirks_Coercion::process( $add );
			$quirks_warnings = array_merge( $quirks_warnings, $qr['warnings'] );
			$pending_alts   += $qr['image_alts'];
		}
		// Same for update[] settings — a partial update can also carry image
		// alt or var() backgrounds. We process the upd entries directly; the
		// helper tolerates element-shaped fragments that lack a parent etc.
		if ( ! empty( $update ) ) {
			$qr              = Bricks_API_Bridge_Quirks_Coercion::process( $update );
			$quirks_warnings = array_merge( $quirks_warnings, $qr['warnings'] );
			$pending_alts   += $qr['image_alts'];
		}

		if ( ! empty( $add ) ) {
			$fix_result = Bricks_API_Bridge_Autofix::autofix( $add );
			$add        = $fix_result['content'];
			$fix_log    = isset( $fix_result['log'] ) ? $fix_result['log'] : array();

			$validation = $this->validator->validate( $add, $existing_ids );
			if ( ! $validation['valid'] ) {
				return new WP_Error(
					'bricks_api_bridge_validation_failed',
					__( 'Patch add-elements validation failed.', 'bricks-api-bridge' ),
					array(
						'status' => 400,
						'errors' => $validation['errors'],
					)
				);
			}
			$warnings   = isset( $validation['warnings'] ) ? $validation['warnings'] : array();
			$info_hints = isset( $validation['info'] ) ? $validation['info'] : array();
		}

		// Create backup BEFORE modifying data.
		$this->backup_manager->create_backup( $post_id );

		// Remove elements (including children).
		if ( ! empty( $remove ) ) {
			$to_remove = array_flip( $remove );
			do {
				$found_more = false;
				foreach ( $current as $el ) {
					if ( isset( $el['parent'] ) && isset( $to_remove[ $el['parent'] ] ) && ! isset( $to_remove[ $el['id'] ] ) ) {
						$to_remove[ $el['id'] ] = true;
						$found_more = true;
					}
				}
			} while ( $found_more );

			$current = array_values(
				array_filter(
					$current,
					function ( $el ) use ( $to_remove ) {
						return ! isset( $to_remove[ $el['id'] ] );
					}
				)
			);
		}

		// Update elements. Default mode is "merge" (deep-merge settings, preserves
		// keys not in the patch). Pass `mode: "replace"` per-update to overwrite
		// settings entirely — useful when you need to drop stale keys.
		foreach ( $update as $upd ) {
			if ( ! isset( $upd['id'] ) || ! is_array( $upd ) ) {
				continue;
			}
			$upd_mode = isset( $upd['mode'] ) && 'replace' === $upd['mode'] ? 'replace' : 'merge';
			foreach ( $current as &$el ) {
				if ( $el['id'] === $upd['id'] ) {
					if ( isset( $upd['settings'] ) && is_array( $upd['settings'] ) ) {
						if ( 'replace' === $upd_mode ) {
							$el['settings'] = $upd['settings'];
						} else {
							$el['settings'] = array_replace_recursive(
								is_array( $el['settings'] ) ? $el['settings'] : array(),
								$upd['settings']
							);
						}
					}
					foreach ( $upd as $key => $val ) {
						if ( 'id' !== $key && 'settings' !== $key && 'mode' !== $key && null !== $val ) {
							$el[ $key ] = $val;
						}
					}
					break;
				}
			}
			unset( $el );
		}

		// Add new elements. Optional `_position` per element controls insertion
		// point; default is end-of-array (backward compatible). Supported syntax:
		//   "start"          → prepend
		//   "end"            → append (default)
		//   "before:<id>"    → directly before the named element
		//   "after:<id>"     → directly after the named element
		// `_position` is stripped before the element is stored — it's a directive,
		// not data. Unknown/unparseable positions silently fall back to "end".
		foreach ( $add as $new_el ) {
			$position = isset( $new_el['_position'] ) ? (string) $new_el['_position'] : 'end';
			unset( $new_el['_position'] );

			$inserted = false;
			if ( 'start' === $position ) {
				array_unshift( $current, $new_el );
				$inserted = true;
			} elseif ( 0 === strpos( $position, 'before:' ) || 0 === strpos( $position, 'after:' ) ) {
				$is_after = 0 === strpos( $position, 'after:' );
				$ref_id   = substr( $position, $is_after ? 6 : 7 );
				foreach ( $current as $idx => $existing ) {
					if ( isset( $existing['id'] ) && $existing['id'] === $ref_id ) {
						$insert_at = $is_after ? $idx + 1 : $idx;
						array_splice( $current, $insert_at, 0, array( $new_el ) );
						$inserted = true;
						break;
					}
				}
			}
			if ( ! $inserted ) {
				$current[] = $new_el; // "end" or unknown ref — fall back to append.
			}

			// If the new element references an existing parent, add it to parent's children.
			if ( ! empty( $new_el['parent'] ) && ! empty( $new_el['id'] ) && in_array( $new_el['parent'], $existing_ids, true ) ) {
				foreach ( $current as &$existing_el ) {
					if ( $existing_el['id'] === $new_el['parent'] ) {
						if ( ! isset( $existing_el['children'] ) ) {
							$existing_el['children'] = array();
						}
						if ( ! in_array( $new_el['id'], $existing_el['children'], true ) ) {
							$existing_el['children'][] = $new_el['id'];
						}
						break;
					}
				}
				unset( $existing_el );
			}
		}

		// Save to the correct content area.
		$patch_meta_map = array(
			'content' => '_bricks_page_content_2',
			'header'  => '_bricks_page_header_2',
			'footer'  => '_bricks_page_footer_2',
		);
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $post_id, $current, $content_area );
		} else {
			update_post_meta( $post_id, $patch_meta_map[ $content_area ], $current );
		}

		// Purge caches (CSS regeneration + cache plugins).
		$regenerate_css = $request->get_param( 'regenerate_css' );
		if ( false !== $regenerate_css ) {
			bricks_api_bridge_purge_post_cache( $post_id );
		}

		// Quirks: write image alts to media library after the bricks save.
		$alts_written = 0;
		if ( ! empty( $pending_alts ) ) {
			$alts_written = Bricks_API_Bridge_Quirks_Coercion::write_image_alts( $pending_alts );
		}

		$response = array(
			'success'       => true,
			'element_count' => count( $current ),
			'added'         => count( $add ),
			'updated'       => count( $update ),
			'removed'       => count( $remove ),
		);

		if ( ! empty( $fix_log ) ) {
			$response['autofix_log'] = $fix_log;
		}
		if ( ! empty( $warnings ) ) {
			$response['warnings'] = $warnings;
		}
		if ( ! empty( $info_hints ) ) {
			$response['info'] = $info_hints;
		}
		if ( ! empty( $quirks_warnings ) ) {
			$response['quirks_warnings'] = $quirks_warnings;
		}
		if ( $alts_written > 0 ) {
			$response['image_alts_synced'] = $alts_written;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Append elements to a page's Bricks data.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function append_elements( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$denied = $this->require_can_edit( $post_id );
		if ( $denied ) {
			return $denied;
		}

		$body        = $request->get_json_params();
		$elements    = isset( $body['elements'] ) ? $body['elements'] : array();
		$position    = isset( $body['position'] ) ? $body['position'] : 'end';
		$parent_id   = isset( $body['parent_id'] ) ? sanitize_text_field( $body['parent_id'] ) : '';
		$skip_backup = isset( $body['skip_backup'] ) ? (bool) $body['skip_backup'] : false;

		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_elements',
				__( 'elements must be a non-empty array.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Auto-fix common issues before validation.
		$fix_result = Bricks_API_Bridge_Autofix::autofix( $elements );
		$elements   = $fix_result['content'];

		// Load current data BEFORE validation so we know existing IDs.
		$current = $this->load_page_data( $post_id );

		// Extract existing element IDs for context-aware validation.
		$existing_ids = array();
		foreach ( $current as $el ) {
			if ( is_array( $el ) && isset( $el['id'] ) ) {
				$existing_ids[] = $el['id'];
			}
		}

		// Assign parent_id to elements that have no parent (or parent: 0).
		if ( ! empty( $parent_id ) ) {
			foreach ( $elements as &$el ) {
				if ( is_array( $el ) && ( empty( $el['parent'] ) || 0 === $el['parent'] ) ) {
					$el['parent'] = $parent_id;
				}
			}
			unset( $el );
		}

		// Validate new elements with existing IDs context.
		$validation = $this->validator->validate( $elements, $existing_ids );
		if ( ! $validation['valid'] ) {
			return new WP_Error(
				'bricks_api_bridge_validation_failed',
				__( 'Element validation failed.', 'bricks-api-bridge' ),
				array(
					'status' => 400,
					'errors' => $validation['errors'],
				)
			);
		}

		// Insert at position.
		if ( 'start' === $position ) {
			$current = array_merge( $elements, $current );
		} elseif ( 0 === strpos( $position, 'after:' ) ) {
			$after_id = substr( $position, 6 );
			$insert_index = null;
			foreach ( $current as $i => $el ) {
				if ( isset( $el['id'] ) && $el['id'] === $after_id ) {
					$insert_index = $i + 1;
					break;
				}
			}
			if ( null !== $insert_index ) {
				array_splice( $current, $insert_index, 0, $elements );
			} else {
				$current = array_merge( $current, $elements );
			}
		} else {
			$current = array_merge( $current, $elements );
		}

		// Update parent children arrays for elements referencing existing parents.
		foreach ( $elements as $new_el ) {
			if ( ! empty( $new_el['parent'] ) && ! empty( $new_el['id'] ) && in_array( $new_el['parent'], $existing_ids, true ) ) {
				foreach ( $current as &$existing_el ) {
					if ( $existing_el['id'] === $new_el['parent'] ) {
						if ( ! isset( $existing_el['children'] ) ) {
							$existing_el['children'] = array();
						}
						if ( ! in_array( $new_el['id'], $existing_el['children'], true ) ) {
							$existing_el['children'][] = $new_el['id'];
						}
						break;
					}
				}
				unset( $existing_el );
			}
		}

		// Backup.
		if ( ! $skip_backup ) {
			$this->backup_manager->create_backup( $post_id );
		}

		// Save.
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $post_id, $current, 'content' );
		} else {
			update_post_meta( $post_id, '_bricks_page_content_2', $current );
		}

		// Purge caches (CSS regeneration + cache plugins).
		bricks_api_bridge_purge_post_cache( $post_id );

		return rest_ensure_response(
			array(
				'success'       => true,
				'element_count' => count( $current ),
				'added'         => count( $elements ),
			)
		);
	}

	/**
	 * Clone a page with its Bricks data and scripts.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function clone_page( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$denied = $this->require_can_edit( $post_id );
		if ( $denied ) {
			return $denied;
		}

		$new_id = wp_insert_post(
			array(
				'post_title'  => $post->post_title . ' (Copy)',
				'post_type'   => $post->post_type,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return new WP_Error(
				'bricks_api_bridge_clone_failed',
				__( 'Failed to create cloned page.', 'bricks-api-bridge' ),
				array( 'status' => 500 )
			);
		}

		// Copy Bricks data.
		$data = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$data = \Bricks\Database::get_data( $post_id, 'content' );
		}
		if ( empty( $data ) ) {
			$data = get_post_meta( $post_id, '_bricks_page_content_2', true );
		}

		if ( ! empty( $data ) ) {
			if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
				\Bricks\Database::set_data( $new_id, $data, 'content' );
			} else {
				update_post_meta( $new_id, '_bricks_page_content_2', $data );
			}
			update_post_meta( $new_id, '_bricks_editor_mode', 'bricks' );
		}

		// Copy per-page scripts.
		$scripts = get_post_meta( $post_id, '_bab_footer_scripts', true );
		if ( ! empty( $scripts ) ) {
			update_post_meta( $new_id, '_bab_footer_scripts', $scripts );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $new_id,
				'title'   => get_the_title( $new_id ),
				'source'  => $post_id,
			)
		);
	}

	/**
	 * Load Bricks page data for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array The Bricks element array (empty array if no data).
	 */
	private function load_page_data( $post_id, $area = 'content' ) {
		$area_meta_map = array(
			'content' => '_bricks_page_content_2',
			'header'  => '_bricks_page_header_2',
			'footer'  => '_bricks_page_footer_2',
		);
		$data = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$data = \Bricks\Database::get_data( $post_id, $area );
		}
		if ( empty( $data ) ) {
			$meta_key = $area_meta_map[ $area ] ?? '_bricks_page_content_2';
			$data = get_post_meta( $post_id, $meta_key, true );
		}
		return ! empty( $data ) && is_array( $data ) ? $data : array();
	}

	/**
	 * Check the If-Match content hash against the current page data.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $if_match The expected content hash from the client.
	 * @return true|WP_Error True if hash matches (or no data exists), WP_Error on mismatch.
	 */
	private function check_content_hash( $post_id, $if_match ) {
		$current_data = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$current_data = \Bricks\Database::get_data( $post_id, 'content' );
		}
		if ( empty( $current_data ) ) {
			$current_data = get_post_meta( $post_id, '_bricks_page_content_2', true );
		}

		// No existing data — nothing to conflict with.
		if ( empty( $current_data ) ) {
			return true;
		}

		$current_hash = md5( wp_json_encode( $current_data ) );
		if ( $current_hash !== $if_match ) {
			return new WP_Error(
				'bricks_api_bridge_conflict',
				__( 'Content has been modified since you last read it. Fetch the page again to get the latest content_hash.', 'bricks-api-bridge' ),
				array(
					'status'       => 409,
					'current_hash' => $current_hash,
				)
			);
		}

		return true;
	}

	/**
	 * Build a page from multiple sections/presets in one request.
	 *
	 * Each section in the 'sections' array can be either:
	 * - {preset: "name", variables: {...}} to expand a preset
	 * - {elements: [...]} to use raw elements
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function build_page( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$denied = $this->require_can_edit( $post_id );
		if ( $denied ) {
			return $denied;
		}

		$body     = $request->get_json_params();
		$sections = isset( $body['sections'] ) ? $body['sections'] : array();

		if ( empty( $sections ) || ! is_array( $sections ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_sections',
				__( 'sections must be a non-empty array.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		$all_elements = array();
		$presets_obj  = new Bricks_API_Bridge_Presets();

		foreach ( $sections as $i => $section ) {
			if ( ! empty( $section['preset'] ) ) {
				// Expand preset via internal request.
				$internal = new WP_REST_Request( 'POST', '/bricks-bridge/v1/presets/instantiate' );
				$internal->set_body(
					wp_json_encode(
						array(
							'name'      => $section['preset'],
							'variables' => isset( $section['variables'] ) ? $section['variables'] : ( isset( $section['vars'] ) ? $section['vars'] : array() ),
						)
					)
				);
				$internal->set_header( 'Content-Type', 'application/json' );

				$result = rest_do_request( $internal );
				$data   = $result->get_data();

				if ( $result->get_status() !== 200 || empty( $data['elements'] ) ) {
					return new WP_Error(
						'bricks_api_bridge_preset_expansion_failed',
						sprintf(
							/* translators: %d: section index */
							__( 'Failed to expand preset at section index %d.', 'bricks-api-bridge' ),
							$i
						),
						array( 'status' => 400 )
					);
				}

				$all_elements = array_merge( $all_elements, $data['elements'] );
			} elseif ( ! empty( $section['elements'] ) && is_array( $section['elements'] ) ) {
				$all_elements = array_merge( $all_elements, $section['elements'] );
			}
		}

		if ( empty( $all_elements ) ) {
			return new WP_Error(
				'bricks_api_bridge_empty_build',
				__( 'No elements generated from sections.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Auto-fix common issues before validation.
		$fix_result   = Bricks_API_Bridge_Autofix::autofix( $all_elements );
		$all_elements = $fix_result['content'];

		// Validate once.
		$validation = $this->validator->validate( $all_elements );
		if ( ! $validation['valid'] ) {
			return new WP_Error(
				'bricks_api_bridge_validation_failed',
				__( 'Build validation failed.', 'bricks-api-bridge' ),
				array(
					'status' => 400,
					'errors' => $validation['errors'],
				)
			);
		}

		// Auto-apply design system classes (default: on, disable with auto_classes=false).
		$auto_classes = isset( $body['auto_classes'] ) ? (bool) $body['auto_classes'] : true;
		if ( $auto_classes ) {
			$all_elements = Bricks_API_Bridge_Global_Classes::apply_classes_to_elements( $all_elements );
		}

		// Backup.
		$this->backup_manager->create_backup( $post_id );

		// Ensure Bricks editor mode.
		update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

		// Write once.
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $post_id, $all_elements, 'content' );
		} else {
			update_post_meta( $post_id, '_bricks_page_content_2', $all_elements );
		}

		// Purge caches (CSS regeneration + cache plugins).
		bricks_api_bridge_purge_post_cache( $post_id );

		// Auto-learn from built page.
		if ( class_exists( 'Bricks_API_Bridge_Presets' ) ) {
			Bricks_API_Bridge_Presets::auto_learn_from_page( $post_id, $all_elements );
		}

		return rest_ensure_response(
			array(
				'success'       => true,
				'element_count' => count( $all_elements ),
				'sections'      => count( $sections ),
			)
		);
	}

	/**
	 * Generate Bricks code signatures for elements on a page.
	 *
	 * Bricks requires code signatures for `code` and `svg` elements
	 * (and elements with `queryEditor`) before they render on the frontend.
	 * API-pushed elements lack these signatures. This endpoint calls
	 * Bricks' Admin::process_elements_for_signature() to generate them.
	 *
	 * Note: `form` elements do NOT need code signatures — they render
	 * via the `fields` array in settings, not via code execution.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sign_code( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_not_found',
				__( 'Page not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$denied = $this->require_can_edit( $post_id );
		if ( $denied ) {
			return $denied;
		}

		// Read current Bricks data.
		$elements = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$elements = \Bricks\Database::get_data( $post_id, 'content' );
		}
		if ( empty( $elements ) ) {
			$elements = get_post_meta( $post_id, '_bricks_page_content_2', true );
		}
		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return new WP_Error(
				'bricks_api_bridge_no_data',
				__( 'No Bricks data found on this page.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$signed_count = 0;

		// Primary: Bricks Admin::process_elements_for_signature (Bricks 1.9.6+).
		// Signs `code`, `svg` elements and elements with `queryEditor` setting.
		if ( class_exists( '\Bricks\Admin' ) && method_exists( '\Bricks\Admin', 'process_elements_for_signature' ) ) {
			if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'code_execution_enabled' ) ) {
				if ( ! \Bricks\Helpers::code_execution_enabled() ) {
					return new WP_Error( 'bricks_code_disabled', 'Code execution is disabled in Bricks settings.', array( 'status' => 403 ) );
				}
			}

			$elements = \Bricks\Admin::process_elements_for_signature( $elements );

			foreach ( $elements as $el ) {
				if ( ! empty( $el['settings']['signature'] ) || ! empty( $el['settings']['query']['signature'] ) ) {
					$signed_count++;
				}
			}

			if ( $signed_count > 0 ) {
				if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
					\Bricks\Database::set_data( $post_id, $elements, 'content' );
				} else {
					update_post_meta( $post_id, '_bricks_page_content_2', $elements );
				}
				bricks_api_bridge_purge_post_cache( $post_id );
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'signed'  => $signed_count,
					'total'   => count( $elements ),
					'method'  => 'admin_process',
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => false,
				'signed'  => 0,
				'method'  => 'none',
				'note'    => 'Bricks Admin class not available. Open page in Bricks Builder and save to sign code elements.',
			)
		);
	}
}
