<?php
/**
 * PHP half of the autofix parity check.
 *
 * Prints the fixed settings of tests/fixtures/autofix-element.json as canonical
 * JSON, to be compared byte-for-byte with tests/autofix-parity.mjs.
 *
 * @package Bricks_API_Bridge
 */

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../plugin/includes/class-autofix.php';

/**
 * Sort array keys recursively. Key order is an artefact of each language's
 * object handling, not behaviour.
 *
 * @param mixed $value Value to sort.
 * @return mixed
 */
function bab_test_sort_deep( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$out = array();
	foreach ( $value as $k => $v ) {
		$out[ $k ] = bab_test_sort_deep( $v );
	}
	if ( ! array_is_list( $out ) ) {
		ksort( $out );
	}
	return $out;
}

$fixture = file_get_contents( __DIR__ . '/fixtures/autofix-element.json' );

$out = array();
foreach ( array( 'preserve', 'normalize' ) as $mode ) {
	$result   = Bricks_API_Bridge_Autofix::autofix( json_decode( $fixture, true ), $mode );
	$settings = array();
	foreach ( $result['content'] as $el ) {
		$settings[ $el['id'] ] = bab_test_sort_deep( $el['settings'] );
	}
	ksort( $settings );
	$out[ $mode ] = $settings;
}

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
