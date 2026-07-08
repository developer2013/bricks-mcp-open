<?php
/**
 * Bricks data validator.
 *
 * @package Bricks_API_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bricks_API_Bridge_Validator
 *
 * Validates Bricks Builder element arrays before write operations.
 * Returns errors (blocking), warnings (non-blocking), and info (hints).
 */
class Bricks_API_Bridge_Validator {

	/**
	 * Collected validation errors (block save).
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Collected validation warnings (save proceeds, shown to user).
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Collected info hints (best-practice suggestions).
	 *
	 * @var string[]
	 */
	private $info = array();

	/**
	 * IDs of existing page elements (for partial validation in patch/append).
	 *
	 * @var string[]
	 */
	private $existing_ids = array();

	/**
	 * Validate a complete Bricks content array.
	 *
	 * @param mixed    $content      The content to validate.
	 * @param string[] $existing_ids Optional IDs of existing page elements. When provided,
	 *                               parent/child references to these IDs are accepted, and
	 *                               new elements with duplicate IDs are rejected.
	 * @return array{valid: bool, errors: string[], warnings: string[], info: string[]}
	 */
	public function validate( $content, $existing_ids = array() ) {
		$this->errors       = array();
		$this->warnings     = array();
		$this->info         = array();
		$this->existing_ids = $existing_ids;

		$this->validate_content( $content );

		if ( is_array( $content ) && empty( $this->errors ) ) {
			// Build ID counts for duplicate check.
			$id_counts = array();
			foreach ( $content as $element ) {
				if ( is_array( $element ) && isset( $element['id'] ) ) {
					$id = $element['id'];
					$id_counts[ $id ] = isset( $id_counts[ $id ] ) ? $id_counts[ $id ] + 1 : 1;
				}
			}

			// Error: Duplicate IDs within new content.
			foreach ( $id_counts as $id => $count ) {
				if ( $count > 1 ) {
					$this->errors[] = sprintf(
						'Duplicate element ID "%s" found %d times.',
						$id,
						$count
					);
				}
			}

			// Error: New element IDs colliding with existing page IDs.
			if ( ! empty( $this->existing_ids ) ) {
				$existing_map = array_flip( $this->existing_ids );
				foreach ( $id_counts as $id => $count ) {
					if ( isset( $existing_map[ $id ] ) ) {
						$this->errors[] = sprintf(
							'Element ID "%s" already exists on the page.',
							$id
						);
					}
				}
			}

			foreach ( $content as $index => $element ) {
				$this->validate_element( $element, $index );
			}

			$this->check_parent_child_integrity( $content );

			// Smart checks (warnings + info).
			$this->check_block_with_flex( $content );
			$this->check_grid_in_settings( $content );
			$this->check_backslash_in_css( $content );
			$this->check_transform_in_css( $content );
			$this->check_heading_hierarchy( $content );
			$this->check_row_without_wrap( $content );
			$this->check_fixed_width_no_maxwidth( $content );
			$this->check_large_font_no_responsive( $content );
			$this->check_large_padding_no_responsive( $content );
			$this->check_image_no_object_fit( $content );
			$this->check_position_no_zindex( $content );
			$this->check_empty_container( $content );
		}

		return array(
			'valid'    => empty( $this->errors ),
			'errors'   => $this->errors,
			'warnings' => $this->warnings,
			'info'     => $this->info,
		);
	}

	/**
	 * Check whether a Bricks element ID is valid.
	 *
	 * Valid IDs are exactly 6 lowercase alphanumeric characters. No digit is required:
	 * Bricks accepts all-letter IDs (e.g. "wmhqxu") and cloned/legacy sites routinely
	 * have them. Requiring a digit forced destructive ID regeneration on save (issue #13).
	 *
	 * @param string $id The element ID to check.
	 * @return bool
	 */
	public function is_valid_bricks_id( $id ) {
		if ( ! is_string( $id ) ) {
			return false;
		}

		if ( strlen( $id ) !== 6 ) {
			return false;
		}

		if ( ! ctype_alnum( $id ) ) {
			return false;
		}

		// IDs must be lowercase (consistent with JS validator).
		if ( $id !== strtolower( $id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate that content is an array of elements.
	 *
	 * @param mixed $content The content to check.
	 * @return bool
	 */
	public function validate_content( $content ) {
		if ( ! is_array( $content ) ) {
			$this->errors[] = __( 'Content must be an array of elements.', 'bricks-api-bridge' );
			return false;
		}

		if ( empty( $content ) ) {
			$this->errors[] = __( 'Content array is empty.', 'bricks-api-bridge' );
			return false;
		}

		// Verify it is a sequential (non-associative) array.
		if ( array_keys( $content ) !== range( 0, count( $content ) - 1 ) ) {
			$this->errors[] = __( 'Content must be a sequential array, not an associative array.', 'bricks-api-bridge' );
			return false;
		}

		return true;
	}

	/**
	 * Validate a single Bricks element.
	 *
	 * Each element must have id, name, settings, and children keys.
	 *
	 * @param mixed $element The element to validate.
	 * @param int   $index   The element index in the content array.
	 * @return bool
	 */
	public function validate_element( $element, $index = 0 ) {
		if ( ! is_array( $element ) ) {
			$this->errors[] = sprintf(
				/* translators: %d: element index */
				__( 'Element at index %d is not an array.', 'bricks-api-bridge' ),
				$index
			);
			return false;
		}

		$required_keys = array( 'id', 'name', 'settings' );
		$valid         = true;

		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $element ) ) {
				$this->errors[] = sprintf(
					/* translators: 1: missing key, 2: element index */
					__( 'Element at index %2$d is missing required key "%1$s".', 'bricks-api-bridge' ),
					$key,
					$index
				);
				$valid = false;
			}
		}

		if ( $valid && ! $this->is_valid_bricks_id( $element['id'] ) ) {
			$this->errors[] = sprintf(
				/* translators: 1: element id, 2: element index */
				__( 'Element at index %2$d has invalid Bricks ID "%1$s". IDs must be 6 lowercase alphanumeric characters.', 'bricks-api-bridge' ),
				isset( $element['id'] ) ? $element['id'] : '',
				$index
			);
			$valid = false;
		}

		if ( $valid && ! is_string( $element['name'] ) ) {
			$this->errors[] = sprintf(
				/* translators: %d: element index */
				__( 'Element at index %d has a non-string "name" value.', 'bricks-api-bridge' ),
				$index
			);
			$valid = false;
		}

		if ( $valid && ! is_array( $element['settings'] ) ) {
			$this->errors[] = sprintf(
				/* translators: %d: element index */
				__( 'Element at index %d has non-array "settings".', 'bricks-api-bridge' ),
				$index
			);
			$valid = false;
		}

		if ( $valid && is_array( $element['settings'] ) ) {
			$px_errors = $this->check_no_px_values( $element['settings'] );
			foreach ( $px_errors as $px_error ) {
				$this->errors[] = sprintf(
					/* translators: 1: element id, 2: px error details */
					__( 'Element "%1$s": %2$s', 'bricks-api-bridge' ),
					$element['id'],
					$px_error
				);
			}
		}

		// Error: Missing children array.
		if ( $valid && ! array_key_exists( 'children', $element ) ) {
			$this->errors[] = sprintf(
				'Element "%s" at index %d: Missing "children" array. Every element must have children: [] (empty for leaf nodes).',
				$element['id'],
				$index
			);
		}

		// Error: Root section must have parent: 0 (integer).
		if ( $valid && 'section' === $element['name'] ) {
			$has_parent = array_key_exists( 'parent', $element );
			$parent     = $has_parent ? $element['parent'] : null;
			if ( ! $has_parent || null === $parent || '0' === $parent || '' === $parent ) {
				if ( 0 !== $parent ) {
					$this->errors[] = sprintf(
						'Element "%s" at index %d: Root section has parent=%s instead of 0 (integer).',
						$element['id'],
						$index,
						wp_json_encode( $parent )
					);
				}
			}
		}

		return $valid;
	}

	/**
	 * Recursively check that no bare "123px" string values exist in settings.
	 *
	 * Bricks Builder settings should not contain raw px values like "123px".
	 *
	 * @param mixed  $settings The settings value to check.
	 * @param string $path     The current key path for error reporting.
	 * @return string[] Array of error messages.
	 */
	public function check_no_px_values( $settings, $path = '' ) {
		$errors = array();

		if ( is_array( $settings ) ) {
			foreach ( $settings as $key => $value ) {
				$current_path = $path ? $path . '.' . $key : $key;
				$sub_errors   = $this->check_no_px_values( $value, $current_path );
				$errors       = array_merge( $errors, $sub_errors );
			}
		} elseif ( is_string( $settings ) && preg_match( '/^\d+px$/', $settings ) ) {
			$errors[] = sprintf(
				/* translators: 1: settings path, 2: the bare px value */
				__( 'Bare px value "%2$s" found at "%1$s". Use numeric values or proper units.', 'bricks-api-bridge' ),
				$path,
				$settings
			);
		}

		return $errors;
	}

	/**
	 * Deprecated no-op. `div` is a valid Bricks layout element (unstyled div), distinct
	 * from `block` — it must NOT be rejected (issue #13, Bug 1). Retained as a no-op so any
	 * external caller keeps working; always reports valid.
	 *
	 * @param array $elements The content element array.
	 * @return bool Always true.
	 */
	public function check_no_div_elements( $elements ) {
		return true;
	}

	/**
	 * Validate parent-child integrity of the content array.
	 *
	 * @param array $content The content element array.
	 * @return bool True if parent-child integrity is valid.
	 */
	public function check_parent_child_integrity( $content ) {
		if ( ! is_array( $content ) ) {
			return true;
		}

		$valid = true;

		// Build a map of all element IDs (new + existing).
		$id_map = array();
		foreach ( $content as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) ) {
				$id_map[ $element['id'] ] = true;
			}
		}
		foreach ( $this->existing_ids as $eid ) {
			$id_map[ $eid ] = true;
		}

		foreach ( $content as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$element_id = isset( $element['id'] ) ? $element['id'] : 'unknown';

			// Check parent reference.
			if ( ! empty( $element['parent'] ) && ! isset( $id_map[ $element['parent'] ] ) ) {
				$this->errors[] = sprintf(
					/* translators: 1: element id, 2: parent id */
					__( 'Element "%1$s" references non-existent parent "%2$s".', 'bricks-api-bridge' ),
					$element_id,
					$element['parent']
				);
				$valid = false;
			}

			// Check children references.
			if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
				foreach ( $element['children'] as $child_id ) {
					if ( ! isset( $id_map[ $child_id ] ) ) {
						$this->errors[] = sprintf(
							/* translators: 1: element id, 2: child id */
							__( 'Element "%1$s" references non-existent child "%2$s".', 'bricks-api-bridge' ),
							$element_id,
							$child_id
						);
						$valid = false;
					}
				}
			}
		}

		return $valid;
	}

	// ──────────────────────────────────────────────────
	// Smart checks — Warnings
	// ──────────────────────────────────────────────────

	/**
	 * Warning: "block" elements with flex properties should be "container".
	 *
	 * @param array $content The content element array.
	 */
	private function check_block_with_flex( $content ) {
		$flex_props = array( '_direction', '_alignItems', '_justifyContent' );

		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['name'] ) || 'block' !== $element['name'] ) {
				continue;
			}
			if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				continue;
			}
			foreach ( $flex_props as $prop ) {
				if ( isset( $element['settings'][ $prop ] ) ) {
					$this->warnings[] = sprintf(
						'Element "%s": "block" with flex property "%s". Use "container" instead — block has no native flex support.',
						isset( $element['id'] ) ? $element['id'] : 'unknown',
						$prop
					);
					break; // One warning per element is enough.
				}
			}
		}
	}

	/**
	 * Warning: _display: "grid" in settings triggers the brx-grid bug.
	 *
	 * @param array $content The content element array.
	 */
	private function check_grid_in_settings( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['settings']['_display'] ) ) {
				continue;
			}
			if ( 'grid' === $element['settings']['_display'] ) {
				$this->warnings[] = sprintf(
					'Element "%s": _display: "grid" in settings triggers the brx-grid bug. Use _cssCustom for CSS Grid instead.',
					isset( $element['id'] ) ? $element['id'] : 'unknown'
				);
			}
		}
	}

	/**
	 * Warning: Backslash in _cssCustom will be stripped by WordPress.
	 *
	 * @param array $content The content element array.
	 */
	private function check_backslash_in_css( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['settings']['_cssCustom'] ) ) {
				continue;
			}
			$css = $element['settings']['_cssCustom'];
			if ( is_string( $css ) && false !== strpos( $css, '\\' ) ) {
				$this->warnings[] = sprintf(
					'Element "%s": Backslash in _cssCustom. WordPress strips backslashes via wp_unslash() — use the actual Unicode character instead.',
					isset( $element['id'] ) ? $element['id'] : 'unknown'
				);
			}
		}
	}

	/**
	 * Info: CSS transform in _cssCustom may conflict with GSAP animations.
	 *
	 * @param array $content The content element array.
	 */
	private function check_transform_in_css( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['settings']['_cssCustom'] ) ) {
				continue;
			}
			$css = $element['settings']['_cssCustom'];
			if ( is_string( $css ) && preg_match( '/\btransform\s*:/', $css ) ) {
				$this->info[] = sprintf(
					'Element "%s": CSS transform in _cssCustom may conflict with GSAP. Consider individual properties (rotate, scale, translate) instead.',
					isset( $element['id'] ) ? $element['id'] : 'unknown'
				);
			}
		}
	}

	/**
	 * Warning: Heading hierarchy gaps (e.g. H1 → H4 without H2/H3).
	 *
	 * @param array $content The content element array.
	 */
	private function check_heading_hierarchy( $content ) {
		$levels = array();

		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['name'] ) || 'heading' !== $element['name'] ) {
				continue;
			}
			$tag = isset( $element['settings']['tag'] ) ? $element['settings']['tag'] : 'h2';
			if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
				$levels[] = (int) $m[1];
			}
		}

		if ( empty( $levels ) ) {
			return;
		}

		$unique = array_unique( $levels );
		sort( $unique );

		for ( $i = 0; $i < count( $unique ) - 1; $i++ ) {
			if ( $unique[ $i + 1 ] - $unique[ $i ] > 1 ) {
				$this->warnings[] = sprintf(
					'Heading hierarchy gap: H%d jumps to H%d (missing H%d).',
					$unique[ $i ],
					$unique[ $i + 1 ],
					$unique[ $i ] + 1
				);
			}
		}
	}

	/**
	 * Warning: Container with direction: row and many children but no flex-wrap.
	 *
	 * @param array $content The content element array.
	 */
	private function check_row_without_wrap( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['name'] ) || 'container' !== $element['name'] ) {
				continue;
			}
			if ( ! isset( $element['settings']['_direction'] ) || 'row' !== $element['settings']['_direction'] ) {
				continue;
			}
			if ( ! isset( $element['children'] ) || ! is_array( $element['children'] ) || count( $element['children'] ) <= 4 ) {
				continue;
			}

			$has_wrap = false;
			if ( isset( $element['settings']['_flexWrap'] ) ) {
				$has_wrap = true;
			}
			if ( ! $has_wrap && isset( $element['settings']['_cssCustom'] ) && is_string( $element['settings']['_cssCustom'] ) ) {
				if ( false !== strpos( $element['settings']['_cssCustom'], 'flex-wrap' ) ) {
					$has_wrap = true;
				}
			}

			if ( ! $has_wrap ) {
				$this->warnings[] = sprintf(
					'Element "%s": Container with direction: row and %d children but no flex-wrap. This will likely overflow on mobile.',
					isset( $element['id'] ) ? $element['id'] : 'unknown',
					count( $element['children'] )
				);
			}
		}
	}

	/**
	 * Warning: Fixed width in px without max-width in _cssCustom.
	 *
	 * @param array $content The content element array.
	 */
	private function check_fixed_width_no_maxwidth( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['settings']['_cssCustom'] ) ) {
				continue;
			}
			$css = $element['settings']['_cssCustom'];
			if ( ! is_string( $css ) ) {
				continue;
			}
			if ( preg_match( '/\bwidth\s*:\s*\d+px/', $css ) && false === strpos( $css, 'max-width' ) ) {
				$this->warnings[] = sprintf(
					'Element "%s": Fixed width in px without max-width. This may cause overflow on smaller screens.',
					isset( $element['id'] ) ? $element['id'] : 'unknown'
				);
			}
		}
	}

	// ──────────────────────────────────────────────────
	// Smart checks — Info
	// ──────────────────────────────────────────────────

	/**
	 * Info: Large font-size without responsive overrides.
	 *
	 * @param array $content The content element array.
	 */
	private function check_large_font_no_responsive( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				continue;
			}
			$settings = $element['settings'];
			if ( ! isset( $settings['_typography']['font-size'] ) ) {
				continue;
			}
			$size = (int) $settings['_typography']['font-size'];
			if ( $size <= 48 ) {
				continue;
			}
			if ( ! $this->has_responsive_keys( $settings ) ) {
				$this->info[] = sprintf(
					'Element "%s": Font size %dpx without responsive overrides. Consider adding tablet/mobile sizes.',
					isset( $element['id'] ) ? $element['id'] : 'unknown',
					$size
				);
			}
		}
	}

	/**
	 * Info: Large padding without responsive overrides.
	 *
	 * @param array $content The content element array.
	 */
	private function check_large_padding_no_responsive( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				continue;
			}
			$settings    = $element['settings'];
			$large_value = false;

			if ( isset( $settings['_padding'] ) && is_array( $settings['_padding'] ) ) {
				foreach ( $settings['_padding'] as $val ) {
					if ( is_numeric( $val ) && (int) $val > 60 ) {
						$large_value = true;
						break;
					}
				}
			}

			if ( ! $large_value ) {
				continue;
			}
			if ( ! $this->has_responsive_keys( $settings ) ) {
				$this->info[] = sprintf(
					'Element "%s": Padding > 60px without responsive overrides. Consider reducing for smaller screens.',
					isset( $element['id'] ) ? $element['id'] : 'unknown'
				);
			}
		}
	}

	/**
	 * Info: Image element without _objectFit.
	 *
	 * @param array $content The content element array.
	 */
	private function check_image_no_object_fit( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['name'] ) || 'image' !== $element['name'] ) {
				continue;
			}
			if ( ! isset( $element['settings']['_objectFit'] ) ) {
				$this->info[] = sprintf(
					'Element "%s": Image without _objectFit. Consider setting "cover" or "contain" to prevent distortion.',
					isset( $element['id'] ) ? $element['id'] : 'unknown'
				);
			}
		}
	}

	/**
	 * Info: position: fixed/absolute without z-index.
	 *
	 * @param array $content The content element array.
	 */
	private function check_position_no_zindex( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['settings']['_cssCustom'] ) ) {
				continue;
			}
			$css = $element['settings']['_cssCustom'];
			if ( ! is_string( $css ) ) {
				continue;
			}
			if ( preg_match( '/position\s*:\s*(fixed|absolute)/', $css ) && false === strpos( $css, 'z-index' ) ) {
				$this->info[] = sprintf(
					'Element "%s": position: fixed/absolute in _cssCustom without z-index. Elements may overlap unexpectedly.',
					isset( $element['id'] ) ? $element['id'] : 'unknown'
				);
			}
		}
	}

	/**
	 * Info: Empty container will not be rendered by Bricks.
	 *
	 * @param array $content The content element array.
	 */
	private function check_empty_container( $content ) {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['name'] ) || 'container' !== $element['name'] ) {
				continue;
			}
			if ( ! isset( $element['children'] ) || ! is_array( $element['children'] ) || empty( $element['children'] ) ) {
				$this->info[] = sprintf(
					'Element "%s": Empty container. Bricks does not render containers without children.',
					isset( $element['id'] ) ? $element['id'] : 'unknown'
				);
			}
		}
	}

	// ──────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────

	/**
	 * Check whether an element's settings contain any responsive breakpoint keys.
	 *
	 * @param array $settings The element settings.
	 * @return bool
	 */
	private function has_responsive_keys( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && (
				false !== strpos( $key, ':tablet_portrait' ) ||
				false !== strpos( $key, ':mobile_landscape' ) ||
				false !== strpos( $key, ':mobile_portrait' )
			) ) {
				return true;
			}
		}
		return false;
	}
}
