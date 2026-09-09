<?php
/**
 * Optimistic locking is per content area (issue #21).
 *
 * The check used to compare every If-Match against the CONTENT area, so a
 * header write was gated on the page body: it 409'd when the body changed and
 * missed concurrent header edits entirely. Fixing only the write side would
 * have regressed, because a read exposed no header hash to send — these tests
 * pin both halves.
 *
 * @package Bricks_API_Bridge
 */

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../plugin/includes/class-autofix.php';
require __DIR__ . '/../plugin/includes/class-pages-controller.php';

$bab_pass = 0;
$bab_fail = 0;

/**
 * Assert strict equality and print a line either way.
 *
 * @param string $label Check name.
 * @param mixed  $got   Actual.
 * @param mixed  $want  Expected.
 */
function bab_check( $label, $got, $want ) {
	global $bab_pass, $bab_fail;
	$ok = $got === $want;
	if ( $ok ) {
		++$bab_pass;
	} else {
		++$bab_fail;
	}
	printf( "  [%s] %-54s got=%s want=%s\n", $ok ? 'ok' : 'FAIL', $label, wp_json_encode( $got ), wp_json_encode( $want ) );
}

/** Seed a page with DIFFERENT content and header data, so their hashes differ. */
function bab_seed() {
	$GLOBALS['bab_test_meta'][7] = array(
		'_bricks_page_content_2' => array(
			array( 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => array(), 'settings' => array( 'text' => 'body' ) ),
		),
		'_bricks_page_header_2'  => array(
			array( 'id' => 'hhh222', 'name' => 'section', 'parent' => 0, 'children' => array(), 'settings' => array( 'text' => 'header' ) ),
		),
	);
}

$ctl = new Bricks_API_Bridge_Pages();
bab_seed();

$content_hash = md5( wp_json_encode( $GLOBALS['bab_test_meta'][7]['_bricks_page_content_2'] ) );
$header_hash  = md5( wp_json_encode( $GLOBALS['bab_test_meta'][7]['_bricks_page_header_2'] ) );

echo "\n1. a read exposes a hash per area\n";
$read = $ctl->get_page( new WP_REST_Request( array( 'id' => 7 ) ) );
bab_check( 'content_hash still present (unchanged contract)', $read['content_hash'] ?? null, $content_hash );
bab_check( 'content_hashes.content matches content_hash', $read['content_hashes']['content'] ?? null, $content_hash );
bab_check( 'content_hashes.header is the header hash', $read['content_hashes']['header'] ?? null, $header_hash );
bab_check( 'header hash differs from content hash', ( $read['content_hashes']['header'] ?? null ) !== $content_hash, true );
// Defaulted to array() so a build without content_hashes reports a readable
// failure instead of a TypeError. array_key_exists, not ?? — the null coalesce
// cannot tell an absent key from a null value, and null is what this asserts.
$hashes = is_array( $read['content_hashes'] ?? null ) ? $read['content_hashes'] : array();
bab_check( 'content_hashes.footer key exists', array_key_exists( 'footer', $hashes ), true );
bab_check(
	'content_hashes.footer is null (page has no footer)',
	array_key_exists( 'footer', $hashes ) ? $hashes['footer'] : 'ABSENT',
	null
);

echo "\n2. a header patch is gated on the HEADER hash\n";
$response = $ctl->patch_page(
	new WP_REST_Request(
		array( 'id' => 7 ),
		array( 'content_area' => 'header', 'update' => array( array( 'id' => 'hhh222', 'settings' => array( 'text' => 'edited' ) ) ) ),
		array( 'If-Match' => $header_hash )
	)
);
bab_check( 'correct header hash passes', is_wp_error( $response ), false );
bab_check( 'header actually written', $GLOBALS['bab_test_meta'][7]['_bricks_page_header_2'][0]['settings']['text'], 'edited' );

echo "\n3. the CONTENT hash no longer passes a header write\n";
bab_seed();
$response = $ctl->patch_page(
	new WP_REST_Request(
		array( 'id' => 7 ),
		array( 'content_area' => 'header', 'update' => array( array( 'id' => 'hhh222', 'settings' => array( 'text' => 'edited' ) ) ) ),
		array( 'If-Match' => $content_hash )
	)
);
bab_check( 'rejected', is_wp_error( $response ), true );
bab_check( 'status 409', is_wp_error( $response ) ? $response->get_error_data()['status'] : null, 409 );
bab_check( 'names the area', is_wp_error( $response ) ? $response->get_error_data()['content_area'] : null, 'header' );
bab_check(
	'message names content_hashes.header as the way out',
	is_wp_error( $response ) ? (bool) strpos( $response->get_error_message(), 'content_hashes.header' ) : false,
	true
);
bab_check( 'header NOT written', $GLOBALS['bab_test_meta'][7]['_bricks_page_header_2'][0]['settings']['text'], 'header' );

echo "\n4. a body change no longer 409s a header write\n";
bab_seed();
$GLOBALS['bab_test_meta'][7]['_bricks_page_content_2'][0]['settings']['text'] = 'body changed by someone else';
$response = $ctl->patch_page(
	new WP_REST_Request(
		array( 'id' => 7 ),
		array( 'content_area' => 'header', 'update' => array( array( 'id' => 'hhh222', 'settings' => array( 'text' => 'edited' ) ) ) ),
		array( 'If-Match' => $header_hash )
	)
);
bab_check( 'header write unaffected by body edit', is_wp_error( $response ), false );

echo "\n5. content writes are unchanged\n";
bab_seed();
$response = $ctl->patch_page(
	new WP_REST_Request(
		array( 'id' => 7 ),
		array( 'update' => array( array( 'id' => 'aaa111', 'settings' => array( 'text' => 'edited' ) ) ) ),
		array( 'If-Match' => $content_hash )
	)
);
bab_check( 'correct content hash still passes', is_wp_error( $response ), false );
bab_seed();
$response = $ctl->patch_page(
	new WP_REST_Request(
		array( 'id' => 7 ),
		array( 'update' => array( array( 'id' => 'aaa111', 'settings' => array( 'text' => 'edited' ) ) ) ),
		array( 'If-Match' => 'stale0000000000000000000000000000' )
	)
);
bab_check( 'stale content hash still 409s', is_wp_error( $response ) ? $response->get_error_data()['status'] : null, 409 );

printf( "\n%d passed, %d failed\n", $bab_pass, $bab_fail );
exit( $bab_fail > 0 ? 1 : 0 );
