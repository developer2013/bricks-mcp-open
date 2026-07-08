<?php
/**
 * Templates controller for Bricks templates.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Templates
 *
 * Handles listing, creating, importing, and deleting Bricks templates
 * (custom post type: bricks_template) via the REST API.
 */
class Bricks_API_Bridge_Templates {

	/**
	 * Validator instance.
	 *
	 * @var Bricks_API_Bridge_Validator
	 */
	private $validator;

	/**
	 * Valid Bricks template types.
	 *
	 * @var string[]
	 */
	const VALID_TEMPLATE_TYPES = array(
		'header',
		'footer',
		'section',
		'content',
		'single',
		'archive',
		'popup',
		'search',
		'error',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->validator = new Bricks_API_Bridge_Validator();
	}

	/**
	 * Map template type to Bricks Database area.
	 *
	 * Bricks stores data in type-specific meta keys:
	 *   header → _bricks_page_header_2
	 *   footer → _bricks_page_footer_2
	 *   *      → _bricks_page_content_2
	 *
	 * @param string $template_type The template type.
	 * @return string The Bricks Database area key.
	 */
	private static function get_area( $template_type ) {
		if ( 'header' === $template_type ) {
			return 'header';
		}
		if ( 'footer' === $template_type ) {
			return 'footer';
		}
		return 'content';
	}

	/**
	 * List Bricks templates.
	 *
	 * Supports query parameters:
	 * - template_type (string): Filter by template type (header, footer, section, etc.).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_templates( $request ) {
		$template_type = sanitize_text_field( $request->get_param( 'template_type' ) );
		$fields        = array_filter( explode( ',', (string) $request->get_param( 'fields' ) ) );

		$args = array(
			'post_type'      => 'bricks_template',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		);

		// Add search term if provided.
		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		// Filter by template type if provided.
		if ( ! empty( $template_type ) ) {
			if ( ! in_array( $template_type, self::VALID_TEMPLATE_TYPES, true ) ) {
				return new WP_Error(
					'bricks_api_bridge_invalid_template_type',
					sprintf(
						/* translators: 1: provided type, 2: list of valid types */
						__( 'Invalid template type "%1$s". Valid types: %2$s', 'bricks-api-bridge' ),
						$template_type,
						implode( ', ', self::VALID_TEMPLATE_TYPES )
					),
					array( 'status' => 400 )
				);
			}

			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_bricks_template_type',
					'value' => $template_type,
				),
			);
		}

		$query     = new WP_Query( $args );
		$templates = array();

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
					$bricks_data   = null;
					$tpl_type      = get_post_meta( $post->ID, '_bricks_template_type', true );
					$tpl_area      = self::get_area( $tpl_type ? $tpl_type : 'content' );

					if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
						$bricks_data = \Bricks\Database::get_data( $post->ID, $tpl_area );
					}
					if ( empty( $bricks_data ) ) {
						$area_keys = array(
							'_bricks_page_' . $tpl_area . '_2',
							'_bricks_page_content_2',
							'_bricks_page_content',
							'_bricks_page_data',
						);
						foreach ( $area_keys as $key ) {
							$meta = get_post_meta( $post->ID, $key, true );
							if ( ! empty( $meta ) ) {
								$bricks_data = $meta;
								break;
							}
						}
					}

					$element_count = is_array( $bricks_data ) ? count( $bricks_data ) : 0;
				}

				$type       = get_post_meta( $post->ID, '_bricks_template_type', true );
				$conditions = get_post_meta( $post->ID, '_bricks_template_conditions', true );

				$template = array(
					'id'            => $post->ID,
					'title'         => get_the_title( $post->ID ),
					'type'          => $type ? $type : 'content',
					'conditions'    => ! empty( $conditions ) ? $conditions : array(),
					'element_count' => $element_count,
					'modified'      => $post->post_modified,
				);

				// Filter to only requested fields (always include 'id').
				if ( ! empty( $fields ) ) {
					$template = array_intersect_key( $template, array_flip( array_merge( array( 'id' ), $fields ) ) );
				}

				$templates[] = $template;
			}
		}

		return rest_ensure_response( $templates );
	}

	/**
	 * Create a new Bricks template.
	 *
	 * Expects JSON body with:
	 * - title (string, required): Template title.
	 * - template_type (string, required): One of the valid template types.
	 * - content (array, required): Bricks element array.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_template( $request ) {
		$body = $request->get_json_params();

		// Validate required fields.
		if ( empty( $body['title'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_title',
				__( 'Template title is required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $body['template_type'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_template_type',
				__( 'Template type is required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $body['template_type'], self::VALID_TEMPLATE_TYPES, true ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_template_type',
				sprintf(
					/* translators: 1: provided type, 2: list of valid types */
					__( 'Invalid template type "%1$s". Valid types: %2$s', 'bricks-api-bridge' ),
					$body['template_type'],
					implode( ', ', self::VALID_TEMPLATE_TYPES )
				),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $body['content'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_content',
				__( 'Template content is required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Auto-fix common issues before validation.
		$fix_result      = Bricks_API_Bridge_Autofix::autofix( $body['content'] );
		$body['content'] = $fix_result['content'];

		// Validate the content.
		$validation = $this->validator->validate( $body['content'] );

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

		// Create the template post.
		$post_id = wp_insert_post(
			array(
				'post_title'  => sanitize_text_field( $body['title'] ),
				'post_type'   => 'bricks_template',
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'bricks_api_bridge_create_failed',
				__( 'Failed to create template post.', 'bricks-api-bridge' ),
				array( 'status' => 500 )
			);
		}

		// Save template metadata first (needed for correct area resolution).
		$tpl_type = sanitize_text_field( $body['template_type'] );
		update_post_meta( $post_id, '_bricks_template_type', $tpl_type );
		update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

		// Save the Bricks data using the correct area for the template type.
		$area = self::get_area( $tpl_type );
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $post_id, $body['content'], $area );
		} else {
			$meta_key = '_bricks_page_' . $area . '_2';
			update_post_meta( $post_id, $meta_key, $body['content'] );
		}

		// Save template conditions if provided (e.g. [{"main":"entireWebsite"}]).
		if ( ! empty( $body['conditions'] ) && is_array( $body['conditions'] ) ) {
			update_post_meta( $post_id, '_bricks_template_conditions', $body['conditions'] );
		}

		$element_count = is_array( $body['content'] ) ? count( $body['content'] ) : 0;

		return rest_ensure_response(
			array(
				'success'       => true,
				'message'       => __( 'Template created successfully.', 'bricks-api-bridge' ),
				'id'            => $post_id,
				'title'         => get_the_title( $post_id ),
				'type'          => $body['template_type'],
				'element_count' => $element_count,
			)
		);
	}

	/**
	 * Import a complete Bricks export JSON as a template.
	 *
	 * Expects JSON body with:
	 * - title (string, optional): Template title. Defaults to 'Imported Template'.
	 * - template_type (string, optional): Defaults to 'section'.
	 * - content (array, required): Bricks element array from export.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_template( $request ) {
		$body = $request->get_json_params();

		if ( ! isset( $body['content'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_content',
				__( 'Import data must include a "content" array.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Auto-fix common issues before validation.
		$fix_result      = Bricks_API_Bridge_Autofix::autofix( $body['content'] );
		$body['content'] = $fix_result['content'];

		// Validate the content.
		$validation = $this->validator->validate( $body['content'] );

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

		$title         = ! empty( $body['title'] ) ? sanitize_text_field( $body['title'] ) : __( 'Imported Template', 'bricks-api-bridge' );
		$template_type = ! empty( $body['template_type'] ) ? sanitize_text_field( $body['template_type'] ) : 'section';

		if ( ! in_array( $template_type, self::VALID_TEMPLATE_TYPES, true ) ) {
			return new WP_Error(
				'bricks_api_bridge_invalid_template_type',
				sprintf(
					/* translators: 1: provided type, 2: list of valid types */
					__( 'Invalid template type "%1$s". Valid types: %2$s', 'bricks-api-bridge' ),
					$template_type,
					implode( ', ', self::VALID_TEMPLATE_TYPES )
				),
				array( 'status' => 400 )
			);
		}

		// Create the template post.
		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'bricks_template',
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'bricks_api_bridge_import_failed',
				__( 'Failed to create template post for import.', 'bricks-api-bridge' ),
				array( 'status' => 500 )
			);
		}

		// Save template metadata first.
		update_post_meta( $post_id, '_bricks_template_type', $template_type );
		update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

		// Save the Bricks data using the correct area for the template type.
		$area = self::get_area( $template_type );
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $post_id, $body['content'], $area );
		} else {
			$meta_key = '_bricks_page_' . $area . '_2';
			update_post_meta( $post_id, $meta_key, $body['content'] );
		}

		$element_count = is_array( $body['content'] ) ? count( $body['content'] ) : 0;

		return rest_ensure_response(
			array(
				'success'       => true,
				'message'       => __( 'Template imported successfully.', 'bricks-api-bridge' ),
				'id'            => $post_id,
				'title'         => $title,
				'type'          => $template_type,
				'element_count' => $element_count,
			)
		);
	}

	/**
	 * Delete or trash a Bricks template.
	 *
	 * Supports the `force` query parameter. When true, permanently deletes the
	 * template. When false (default), moves it to the trash.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_template( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$force   = (bool) $request->get_param( 'force' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_template_not_found',
				__( 'Template not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		if ( 'bricks_template' !== $post->post_type ) {
			return new WP_Error(
				'bricks_api_bridge_not_a_template',
				__( 'The specified post is not a Bricks template.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( $force ) {
			$result = wp_delete_post( $post_id, true );
		} else {
			$result = wp_trash_post( $post_id );
		}

		if ( ! $result ) {
			return new WP_Error(
				'bricks_api_bridge_delete_failed',
				__( 'Failed to delete template.', 'bricks-api-bridge' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => $force
					? __( 'Template permanently deleted.', 'bricks-api-bridge' )
					: __( 'Template moved to trash.', 'bricks-api-bridge' ),
				'id'      => $post_id,
			)
		);
	}

	/**
	 * Clone a template with its Bricks data and metadata.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function clone_template( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new WP_Error(
				'bricks_api_bridge_template_not_found',
				__( 'Template not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$new_id = wp_insert_post(
			array(
				'post_title'  => $post->post_title . ' (Copy)',
				'post_type'   => 'bricks_template',
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return new WP_Error(
				'bricks_api_bridge_clone_failed',
				__( 'Failed to create cloned template.', 'bricks-api-bridge' ),
				array( 'status' => 500 )
			);
		}

		// Copy template type first (needed for area resolution).
		$type = get_post_meta( $post_id, '_bricks_template_type', true );
		if ( ! empty( $type ) ) {
			update_post_meta( $new_id, '_bricks_template_type', $type );
		}
		update_post_meta( $new_id, '_bricks_editor_mode', 'bricks' );

		// Copy Bricks data using the correct area.
		$area = self::get_area( $type ? $type : 'content' );
		$data = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$data = \Bricks\Database::get_data( $post_id, $area );
		}
		if ( empty( $data ) ) {
			$data = get_post_meta( $post_id, '_bricks_page_' . $area . '_2', true );
		}
		if ( empty( $data ) ) {
			$data = get_post_meta( $post_id, '_bricks_page_content_2', true );
		}

		if ( ! empty( $data ) ) {
			if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
				\Bricks\Database::set_data( $new_id, $data, $area );
			} else {
				$meta_key = '_bricks_page_' . $area . '_2';
				update_post_meta( $new_id, $meta_key, $data );
			}
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
				'type'    => $type ? $type : 'content',
				'source'  => $post_id,
			)
		);
	}

	/**
	 * Get a single template with full Bricks data.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_template( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new WP_Error(
				'bricks_api_bridge_template_not_found',
				__( 'Template not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$type       = get_post_meta( $post_id, '_bricks_template_type', true );
		$conditions = get_post_meta( $post_id, '_bricks_template_conditions', true );
		$area       = self::get_area( $type ? $type : 'content' );

		$bricks_data = null;

		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$bricks_data = \Bricks\Database::get_data( $post_id, $area );
		}
		// Fallback: try type-specific key, then generic keys.
		if ( empty( $bricks_data ) ) {
			$meta_keys = array(
				'_bricks_page_' . $area . '_2',
				'_bricks_page_content_2',
				'_bricks_page_content',
				'_bricks_page_data',
			);
			foreach ( $meta_keys as $key ) {
				$meta = get_post_meta( $post_id, $key, true );
				if ( ! empty( $meta ) ) {
					$bricks_data = $meta;
					break;
				}
			}
		}

		return rest_ensure_response(
			array(
				'id'          => $post->ID,
				'title'       => get_the_title( $post->ID ),
				'type'        => $type ? $type : 'content',
				'conditions'  => ! empty( $conditions ) ? $conditions : array(),
				'modified'    => $post->post_modified,
				'bricks_data' => ! empty( $bricks_data ) ? $bricks_data : array(),
			)
		);
	}

	/**
	 * Update a single template's Bricks data.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_template( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new WP_Error(
				'bricks_api_bridge_template_not_found',
				__( 'Template not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$body = $request->get_json_params();

		$content = null;

		// Content update is optional — allows conditions-only or title-only updates.
		if ( isset( $body['bricks_data'] ) ) {
			$content = $body['bricks_data'];

			// Auto-fix before validation. A full save of EXISTING template content defaults
			// to 'preserve' (keep element types + IDs verbatim); override via autofix_mode /
			// bab_autofix_mode option / BAB_AUTOFIX_MODE constant. See issue #13.
			$autofix_mode = Bricks_API_Bridge_Autofix::resolve_mode( $request );
			$fix_result   = Bricks_API_Bridge_Autofix::autofix( $content, $autofix_mode );
			$content      = $fix_result['content'];

			// Validate.
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

			// Save using the correct area for the template type.
			$type_for_area = get_post_meta( $post_id, '_bricks_template_type', true );
			$area          = self::get_area( $type_for_area ? $type_for_area : 'content' );
			if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
				\Bricks\Database::set_data( $post_id, $content, $area );
			} else {
				$meta_key = '_bricks_page_' . $area . '_2';
				update_post_meta( $post_id, $meta_key, $content );
			}
		}

		// Update title if provided.
		if ( ! empty( $body['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => sanitize_text_field( $body['title'] ),
				)
			);
		}

		// Update template type if provided.
		if ( ! empty( $body['template_type'] ) ) {
			$new_type = sanitize_text_field( $body['template_type'] );
			if ( in_array( $new_type, self::VALID_TEMPLATE_TYPES, true ) ) {
				update_post_meta( $post_id, '_bricks_template_type', $new_type );
			}
		}

		// Update template conditions if provided.
		if ( isset( $body['conditions'] ) && is_array( $body['conditions'] ) ) {
			update_post_meta( $post_id, '_bricks_template_conditions', $body['conditions'] );
		}

		$type = get_post_meta( $post_id, '_bricks_template_type', true );

		// Get element count — from updated content or existing data.
		if ( is_array( $content ) ) {
			$element_count = count( $content );
		} else {
			$existing = get_post_meta( $post_id, '_bricks_page_content_2', true );
			$element_count = is_array( $existing ) ? count( $existing ) : 0;
		}

		return rest_ensure_response(
			array(
				'success'       => true,
				'id'            => $post_id,
				'type'          => $type ? $type : 'section',
				'element_count' => $element_count,
			)
		);
	}
}
