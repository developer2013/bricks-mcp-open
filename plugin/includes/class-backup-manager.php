<?php
/**
 * Backup manager for Bricks page data.
 *
 * Uses 5-slot FIFO rotation: newest in slot 1, oldest rolls off slot 5.
 * Each slot stores {data: [...], timestamp: "..."} as serialized array.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Backup
 *
 * Handles creating, retrieving, listing, and restoring backups of Bricks page data.
 */
class Bricks_API_Bridge_Backup {

	/**
	 * Maximum number of backup slots.
	 *
	 * @var int
	 */
	const MAX_SLOTS = 5;

	/**
	 * Meta key prefix for backup slots.
	 *
	 * @var string
	 */
	const META_PREFIX = '_bricks_backup_';

	/**
	 * Get the meta key for a specific backup slot.
	 *
	 * @param int $slot Slot number (1-5).
	 * @return string
	 */
	private function slot_key( $slot ) {
		return self::META_PREFIX . (int) $slot;
	}

	/**
	 * Resolve which Bricks content area a post's elements live in.
	 *
	 * Header and footer templates store their elements in the header/footer area,
	 * not in content. Everything else, templates included, uses content.
	 *
	 * Mirrors Templates_Controller::get_area(), which is private static there.
	 *
	 * @param int $post_id The post ID.
	 * @return string One of 'header', 'footer', 'content'.
	 */
	private function resolve_area( $post_id ) {
		if ( 'bricks_template' !== get_post_type( $post_id ) ) {
			return 'content';
		}

		$type = get_post_meta( $post_id, '_bricks_template_type', true );

		if ( 'header' === $type || 'footer' === $type ) {
			return $type;
		}

		return 'content';
	}

	/**
	 * Meta key holding a post's Bricks elements, by content area.
	 *
	 * @param int $post_id The post ID.
	 * @return string The postmeta key.
	 */
	private function area_meta_key( $post_id ) {
		return '_bricks_page_' . $this->resolve_area( $post_id ) . '_2';
	}

	/**
	 * Create a backup of the current Bricks page data.
	 *
	 * Rotates existing backups: 1->2, 2->3, 3->4, 4->5, new->1.
	 * Oldest backup in slot 5 is discarded.
	 *
	 * @param int $post_id The post ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function create_backup( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		// Read from the correct Bricks meta key for this post's content area.
		$area = $this->resolve_area( $post_id );

		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$current_data = \Bricks\Database::get_data( $post_id, $area );
		} else {
			$current_data = get_post_meta( $post_id, $this->area_meta_key( $post_id ), true );
			if ( empty( $current_data ) && 'content' === $area ) {
				$current_data = get_post_meta( $post_id, '_bricks_page_content', true );
			}
		}

		if ( empty( $current_data ) ) {
			return new WP_Error(
				'bricks_api_bridge_no_data',
				__( 'No Bricks page data found to backup.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		// Rotate slots: shift 1->2, 2->3, 3->4, 4->5 (slot 5 is discarded).
		// Batch-read all slots in one call (avoids N separate get_post_meta queries).
		$all_meta = get_post_meta( $post_id );
		$slots    = array();
		for ( $i = 1; $i < self::MAX_SLOTS; $i++ ) {
			$key = $this->slot_key( $i );
			$slots[ $i ] = isset( $all_meta[ $key ][0] ) ? maybe_unserialize( $all_meta[ $key ][0] ) : null;
		}
		// Write rotated slots (highest to lowest).
		for ( $i = self::MAX_SLOTS; $i > 1; $i-- ) {
			if ( ! empty( $slots[ $i - 1 ] ) ) {
				update_post_meta( $post_id, $this->slot_key( $i ), $slots[ $i - 1 ] );
			} else {
				delete_post_meta( $post_id, $this->slot_key( $i ) );
			}
		}

		// Write new backup to slot 1.
		$backup = array(
			'data'      => $current_data,
			'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		update_post_meta( $post_id, $this->slot_key( 1 ), $backup );

		return true;
	}

	/**
	 * Get the backup data and timestamp for a post from a specific slot.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The backup data or error.
	 */
	public function get_backup( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$slot    = (int) $request->get_param( 'slot' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		if ( $slot < 1 || $slot > self::MAX_SLOTS ) {
			$slot = 1;
		}

		$backup = get_post_meta( $post_id, $this->slot_key( $slot ), true );

		if ( empty( $backup ) || ! isset( $backup['data'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_no_backup',
				__( 'No backup found in this slot.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'post_id'       => $post_id,
				'title'         => get_the_title( $post_id ),
				'slot'          => $slot,
				'backup_data'   => $backup['data'],
				'timestamp'     => $backup['timestamp'],
				'element_count' => is_array( $backup['data'] ) ? count( $backup['data'] ) : 0,
			)
		);
	}

	/**
	 * List all backup slots for a post with timestamps and element counts.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_backups( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$backups = array();

		for ( $i = 1; $i <= self::MAX_SLOTS; $i++ ) {
			$backup = get_post_meta( $post_id, $this->slot_key( $i ), true );
			if ( ! empty( $backup ) && isset( $backup['data'] ) ) {
				$backups[] = array(
					'slot'          => $i,
					'timestamp'     => isset( $backup['timestamp'] ) ? $backup['timestamp'] : '',
					'element_count' => is_array( $backup['data'] ) ? count( $backup['data'] ) : 0,
				);
			}
		}

		return rest_ensure_response(
			array(
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'backups' => $backups,
				'count'   => count( $backups ),
			)
		);
	}

	/**
	 * Restore a backup from a specific slot to _bricks_page_data and regenerate CSS.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error Success response or error.
	 */
	public function restore_backup( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$slot    = (int) $request->get_param( 'slot' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		if ( $slot < 1 || $slot > self::MAX_SLOTS ) {
			$slot = 1;
		}

		$backup = get_post_meta( $post_id, $this->slot_key( $slot ), true );

		if ( empty( $backup ) || ! isset( $backup['data'] ) ) {
			return new WP_Error(
				'bricks_api_bridge_no_backup',
				__( 'No backup found in this slot.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$backup_data = $backup['data'];

		// Restore to the correct Bricks meta key for this post's content area.
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $post_id, $backup_data, $this->resolve_area( $post_id ) );
		} else {
			update_post_meta( $post_id, $this->area_meta_key( $post_id ), $backup_data );
		}

		// Purge caches (uses the shared function if available).
		if ( function_exists( 'bricks_api_bridge_purge_post_cache' ) ) {
			bricks_api_bridge_purge_post_cache( $post_id );
		} elseif ( class_exists( '\Bricks\Assets' ) && method_exists( '\Bricks\Assets', 'generate_css_file' ) ) {
			\Bricks\Assets::generate_css_file( $post_id );
		}

		$element_count = is_array( $backup_data ) ? count( $backup_data ) : 0;

		return rest_ensure_response(
			array(
				'success'       => true,
				'message'       => __( 'Backup restored successfully.', 'bricks-api-bridge' ),
				'post_id'       => $post_id,
				'slot'          => $slot,
				'element_count' => $element_count,
			)
		);
	}

	// ──────────────────────────────────────────────────
	// Named Snapshots
	// ──────────────────────────────────────────────────

	/**
	 * Meta key for named snapshots.
	 *
	 * @var string
	 */
	const SNAPSHOTS_KEY = '_bricks_snapshots';

	/**
	 * Create a named snapshot of the current Bricks page data.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_snapshot( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$body        = $request->get_json_params();
		$name        = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
		$description = isset( $body['description'] ) ? sanitize_text_field( $body['description'] ) : '';

		if ( empty( $name ) ) {
			return new WP_Error(
				'bricks_api_bridge_missing_name',
				__( 'Snapshot name is required.', 'bricks-api-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Read current Bricks data from this post's content area.
		$current_data = null;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_data' ) ) {
			$current_data = \Bricks\Database::get_data( $post_id, $this->resolve_area( $post_id ) );
		}
		if ( empty( $current_data ) ) {
			$current_data = get_post_meta( $post_id, $this->area_meta_key( $post_id ), true );
		}

		if ( empty( $current_data ) ) {
			return new WP_Error(
				'bricks_api_bridge_no_data',
				__( 'No Bricks page data found to snapshot.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$snapshots = get_post_meta( $post_id, self::SNAPSHOTS_KEY, true );
		if ( ! is_array( $snapshots ) ) {
			$snapshots = array();
		}

		$snapshot = array(
			'id'            => 'snap_' . uniqid(),
			'name'          => $name,
			'description'   => $description,
			'data'          => $current_data,
			'element_count' => is_array( $current_data ) ? count( $current_data ) : 0,
			'timestamp'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		$snapshots[] = $snapshot;

		// Auto-snapshot retention: keep at most AUTO_SNAPSHOT_MAX entries with the
		// `auto_pre_` prefix, drop the oldest. Manual snapshots (any other prefix)
		// are never auto-pruned — user owns those.
		// Filterable so sites with high-frequency edits can raise the cap.
		$auto_max = (int) apply_filters( 'bricks_api_bridge_auto_snapshot_max', 15 );
		if ( $auto_max > 0 && 0 === strpos( $name, 'auto_pre_' ) ) {
			$auto_indices = array();
			foreach ( $snapshots as $idx => $s ) {
				if ( isset( $s['name'] ) && 0 === strpos( $s['name'], 'auto_pre_' ) ) {
					$auto_indices[] = $idx;
				}
			}
			$excess = count( $auto_indices ) - $auto_max;
			if ( $excess > 0 ) {
				$drop = array_slice( $auto_indices, 0, $excess ); // oldest first (chronological order preserved by append)
				foreach ( $drop as $idx ) {
					unset( $snapshots[ $idx ] );
				}
				$snapshots = array_values( $snapshots );
			}
		}

		update_post_meta( $post_id, self::SNAPSHOTS_KEY, $snapshots );

		return rest_ensure_response(
			array(
				'success'       => true,
				'snapshot_id'   => $snapshot['id'],
				'name'          => $snapshot['name'],
				'element_count' => $snapshot['element_count'],
				'timestamp'     => $snapshot['timestamp'],
			)
		);
	}

	/**
	 * List all named snapshots for a page (without data).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_snapshots( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$snapshots = get_post_meta( $post_id, self::SNAPSHOTS_KEY, true );
		if ( ! is_array( $snapshots ) ) {
			$snapshots = array();
		}

		// Return metadata only (no data arrays).
		$list = array_map(
			function ( $snap ) {
				return array(
					'id'            => $snap['id'],
					'name'          => $snap['name'],
					'description'   => isset( $snap['description'] ) ? $snap['description'] : '',
					'element_count' => $snap['element_count'],
					'timestamp'     => $snap['timestamp'],
				);
			},
			$snapshots
		);

		return rest_ensure_response(
			array(
				'post_id'   => $post_id,
				'title'     => get_the_title( $post_id ),
				'snapshots' => $list,
				'count'     => count( $list ),
			)
		);
	}

	/**
	 * Restore a named snapshot.
	 *
	 * Creates an auto-backup before restoring.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_snapshot( $request ) {
		$post_id     = (int) $request->get_param( 'id' );
		$snapshot_id = sanitize_text_field( $request->get_param( 'snapshot_id' ) );
		$post        = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$snapshots = get_post_meta( $post_id, self::SNAPSHOTS_KEY, true );
		if ( ! is_array( $snapshots ) ) {
			$snapshots = array();
		}

		// Find snapshot by ID or name.
		$found = null;
		foreach ( $snapshots as $snap ) {
			if ( $snap['id'] === $snapshot_id || $snap['name'] === $snapshot_id ) {
				$found = $snap;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error(
				'bricks_api_bridge_snapshot_not_found',
				__( 'Snapshot not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		// Create auto-backup of current state before restoring.
		$this->create_backup( $post_id );

		// Restore into this post's content area.
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'set_data' ) ) {
			\Bricks\Database::set_data( $post_id, $found['data'], $this->resolve_area( $post_id ) );
		} else {
			update_post_meta( $post_id, $this->area_meta_key( $post_id ), $found['data'] );
		}

		if ( function_exists( 'bricks_api_bridge_purge_post_cache' ) ) {
			bricks_api_bridge_purge_post_cache( $post_id );
		}

		return rest_ensure_response(
			array(
				'success'       => true,
				'message'       => sprintf(
					/* translators: %s: snapshot name */
					__( 'Snapshot "%s" restored successfully.', 'bricks-api-bridge' ),
					$found['name']
				),
				'snapshot_id'   => $found['id'],
				'name'          => $found['name'],
				'element_count' => $found['element_count'],
			)
		);
	}

	/**
	 * Delete a named snapshot.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_snapshot( $request ) {
		$post_id     = (int) $request->get_param( 'id' );
		$snapshot_id = sanitize_text_field( $request->get_param( 'snapshot_id' ) );
		$post        = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'bricks_api_bridge_post_not_found',
				__( 'Post not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		$snapshots = get_post_meta( $post_id, self::SNAPSHOTS_KEY, true );
		if ( ! is_array( $snapshots ) ) {
			$snapshots = array();
		}

		$found_index = null;
		$found_name  = '';
		foreach ( $snapshots as $i => $snap ) {
			if ( $snap['id'] === $snapshot_id || $snap['name'] === $snapshot_id ) {
				$found_index = $i;
				$found_name  = $snap['name'];
				break;
			}
		}

		if ( null === $found_index ) {
			return new WP_Error(
				'bricks_api_bridge_snapshot_not_found',
				__( 'Snapshot not found.', 'bricks-api-bridge' ),
				array( 'status' => 404 )
			);
		}

		array_splice( $snapshots, $found_index, 1 );
		update_post_meta( $post_id, self::SNAPSHOTS_KEY, $snapshots );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: snapshot name */
					__( 'Snapshot "%s" deleted.', 'bricks-api-bridge' ),
					$found_name
				),
			)
		);
	}
}
