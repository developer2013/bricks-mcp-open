<?php
/**
 * CSS editability guard — server-side boundary mirror of the MCP-side
 * utils/css-classify.js (Layer 3 of the 2026-06-22 CSS-editability plan).
 *
 * WHY: `_bab_page_assets.css` is injected at `wp_head` priority 9997 — BEFORE
 * element `_cssCustom` (9999) and global CSS (9998) — and has NO Bricks editing
 * UI. Human-editable layout/responsive/visual CSS written there silently
 * overrides what the builder shows, so a human (or a future agent) can no longer
 * find or fix it. This guard catches that at the write boundary, including for
 * NON-MCP callers (e.g. the autonomous agent service) that bypass the MCP layer.
 *
 * The classifier is a heuristic, NOT a real CSS parser. It leans toward
 * 'editable' on uncertainty so the guard warns rather than misses — warning is
 * non-destructive (zero-breakage). The classification logic is kept byte-faithful
 * to utils/css-classify.js; keep the two in sync.
 *
 * @package bricks-api-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guard mode: 'off' | 'warn' | 'block'. Default 'off' — zero-breakage: the fleet
 * is unaffected until a site opts in. Set per site (constant wins over option):
 *   - define( 'BAB_GUARD_EDITABLE_CSS', 'warn' ) in wp-config.php, or
 *   - update_option( 'bab_guard_editable_css', 'warn' ).
 * 'warn'  → write proceeds, response carries a `warnings[]` field.
 * 'block' → 422, the css field is rejected and nothing is written.
 *
 * @return string One of 'off', 'warn', 'block'.
 */
function bricks_api_bridge_editable_css_guard_mode() {
	$mode = defined( 'BAB_GUARD_EDITABLE_CSS' )
		? BAB_GUARD_EDITABLE_CSS
		: get_option( 'bab_guard_editable_css', 'off' );
	$mode = is_string( $mode ) ? strtolower( $mode ) : 'off';
	return in_array( $mode, array( 'off', 'warn', 'block' ), true ) ? $mode : 'off';
}

/**
 * Classify a raw CSS string.
 *
 * @param string $css Raw CSS rules (no <style> wrapper).
 * @return array{kind:string, editable:array, infra:array} kind: empty|infra|editable|mixed
 */
function bricks_api_bridge_classify_css( $css ) {
	if ( ! is_string( $css ) || '' === trim( $css ) ) {
		return array( 'kind' => 'empty', 'editable' => array(), 'infra' => array() );
	}
	$clean    = preg_replace( '#/\*.*?\*/#s', '', $css ); // strip comments
	$editable = array();
	$infra    = array();
	foreach ( bricks_api_bridge_css_top_level_segments( $clean ) as $seg ) {
		$t = bricks_api_bridge_classify_css_segment( $seg );
		if ( 'editable' === $t ) {
			$editable[] = bricks_api_bridge_css_label( $seg );
		} elseif ( 'infra' === $t ) {
			$infra[] = bricks_api_bridge_css_label( $seg );
		}
	}
	if ( $editable && $infra ) {
		$kind = 'mixed';
	} elseif ( $editable ) {
		$kind = 'editable';
	} elseif ( $infra ) {
		$kind = 'infra';
	} else {
		$kind = 'empty';
	}
	return array( 'kind' => $kind, 'editable' => $editable, 'infra' => $infra );
}

/**
 * Split CSS into top-level constructs, respecting nested braces.
 *
 * @param string $css
 * @return string[]
 */
function bricks_api_bridge_css_top_level_segments( $css ) {
	$segs  = array();
	$depth = 0;
	$start = 0;
	$len   = strlen( $css );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $css[ $i ];
		if ( '{' === $c ) {
			$depth++;
		} elseif ( '}' === $c ) {
			$depth--;
			if ( $depth <= 0 ) {
				$depth = 0;
				$s     = trim( substr( $css, $start, $i - $start + 1 ) );
				if ( '' !== $s ) {
					$segs[] = $s;
				}
				$start = $i + 1;
			}
		} elseif ( ';' === $c && 0 === $depth ) {
			$s = trim( substr( $css, $start, $i - $start + 1 ) );
			if ( '' !== $s ) {
				$segs[] = $s;
			}
			$start = $i + 1;
		}
	}
	$tail = trim( substr( $css, $start ) );
	if ( '' !== $tail ) {
		$segs[] = $tail;
	}
	return $segs;
}

/**
 * True when a declaration body contains only CSS custom properties (or nothing).
 *
 * @param string $body declarations between the outermost braces
 * @return bool
 */
function bricks_api_bridge_css_custom_props_only( $body ) {
	$decls = array_filter( array_map( 'trim', explode( ';', $body ) ) );
	if ( empty( $decls ) ) {
		return true;
	}
	foreach ( $decls as $d ) {
		$colon = strpos( $d, ':' );
		if ( false === $colon ) {
			continue;
		}
		$key = trim( substr( $d, 0, $colon ) );
		if ( '' !== $key && 0 !== strpos( $key, '--' ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Short human-readable label for a segment (its prelude / first chars).
 *
 * @param string $seg
 * @return string
 */
function bricks_api_bridge_css_label( $seg ) {
	$s     = trim( preg_replace( '/\s+/', ' ', $seg ) );
	$brace = strpos( $s, '{' );
	$head  = ( false === $brace ) ? $s : trim( substr( $s, 0, $brace ) );
	if ( '' === $head ) {
		$head = $s;
	}
	return substr( $head, 0, 60 );
}

/**
 * @param string $seg a single top-level construct
 * @return string|null 'infra'|'editable', or null (neutral/ignored)
 */
function bricks_api_bridge_classify_css_segment( $seg ) {
	$s = trim( $seg );
	if ( '' === $s ) {
		return null;
	}
	$brace = strpos( $s, '{' );
	if ( false === $brace ) {
		if ( preg_match( '/^@import\b/i', $s ) ) {
			return 'infra';
		}
		if ( preg_match( '/^@charset\b/i', $s ) ) {
			return 'infra';
		}
		return null; // stray top-level declaration — ignore
	}
	$prelude = trim( substr( $s, 0, $brace ) );
	$last    = strrpos( $s, '}' );
	$body    = ( false === $last ) ? '' : substr( $s, $brace + 1, $last - $brace - 1 );

	if ( preg_match( '/^@font-face\b/i', $prelude ) ) {
		return 'infra';
	}
	if ( preg_match( '/^@(-[a-z]+-)?keyframes\b/i', $prelude ) ) {
		return 'infra';
	}
	if ( preg_match( '/^@(media|supports|layer|container)\b/i', $prelude ) ) {
		$inner = bricks_api_bridge_classify_css( $body );
		return ( 'editable' === $inner['kind'] || 'mixed' === $inner['kind'] ) ? 'editable' : 'infra';
	}
	if ( 0 === strpos( $prelude, '@' ) ) {
		return 'editable'; // other at-rules — be conservative
	}
	return bricks_api_bridge_css_custom_props_only( $body ) ? 'infra' : 'editable';
}
