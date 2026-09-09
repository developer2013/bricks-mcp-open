<?php
/**
 * Runs the real patch_page() handler against WordPress stubs.
 *
 * The scenarios all turn on one thing: a patch whose IDs don't exist in the
 * targeted content area. That used to report success against zero matches and
 * save anyway — writing an empty array into _bricks_page_header_2 and giving a
 * page an empty per-page header override it never had. See issue #18.
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
 * @param string $label Human-readable check name.
 * @param mixed  $got   Actual value.
 * @param mixed  $want  Expected value.
 */
function bab_check( $label, $got, $want ) {
	global $bab_pass, $bab_fail;
	$ok = $got === $want;
	if ( $ok ) {
		++$bab_pass;
	} else {
		++$bab_fail;
	}
	printf(
		"  [%s] %-52s got=%s want=%s\n",
		$ok ? 'ok' : 'FAIL',
		$label,
		wp_json_encode( $got ),
		wp_json_encode( $want )
	);
}

/**
 * Reset the fixture: a page with its own content but NO per-page header, which
 * is the normal case — the header comes from a Bricks template post.
 */
function bab_seed() {
	$GLOBALS['bab_test_meta'][7] = array(
		'_bricks_page_content_2' => array(
			array(
				'id'       => 'aaa111',
				'name'     => 'section',
				'parent'   => 0,
				'children' => array( 'bbb222' ),
				'settings' => array(),
			),
			array(
				'id'       => 'bbb222',
				'name'     => 'heading',
				'parent'   => 'aaa111',
				'children' => array(),
				'settings' => array( 'text' => 'before' ),
			),
		),
	);
}

$ctl = new Bricks_API_Bridge_Pages();
bab_seed();

echo "\n1. update on 'header', page inherits its header (total miss)\n";
$before   = $GLOBALS['bab_test_meta'][7];
$response = $ctl->patch_page(
	new WP_REST_Request(
		array( 'id' => 7 ),
		array(
			'content_area' => 'header',
			'update'       => array( array( 'id' => 'zzz999', 'settings' => array( 'text' => 'new' ) ) ),
		)
	)
);
bab_check( 'is WP_Error', is_wp_error( $response ), true );
bab_check( 'HTTP status', is_wp_error( $response ) ? $response->get_error_data()['status'] : null, 422 );
bab_check( 'unmatched_ids', is_wp_error( $response ) ? $response->get_error_data()['unmatched_ids'] : null, array( 'zzz999' ) );
bab_check( 'no empty header written', isset( $GLOBALS['bab_test_meta'][7]['_bricks_page_header_2'] ), false );
bab_check( 'content untouched', $GLOBALS['bab_test_meta'][7] === $before, true );

echo "\n2. update with one existing and one foreign id\n";
$response = $ctl->patch_page(
	new WP_REST_Request(
		array( 'id' => 7 ),
		array(
			'update' => array(
				array( 'id' => 'bbb222', 'settings' => array( 'text' => 'after' ) ),
				array( 'id' => 'zzz999', 'settings' => array( 'text' => 'nowhere' ) ),
			),
		)
	)
);
bab_check( 'no error', is_wp_error( $response ), false );
bab_check( 'updated (matched)', $response['updated'] ?? null, 1 );
bab_check( 'updated_requested', $response['updated_requested'] ?? null, 2 );
bab_check( 'unmatched_ids', $response['unmatched_ids'] ?? null, array( 'zzz999' ) );
bab_check( 'warning raised', ! empty( $response['warnings'] ), true );
bab_check( 'value actually written', $GLOBALS['bab_test_meta'][7]['_bricks_page_content_2'][1]['settings']['text'], 'after' );

echo "\n3. add present, every update misses\n";
$response = $ctl->patch_page(
	new WP_REST_Request(
		array( 'id' => 7 ),
		array(
			'add'    => array( array( 'id' => 'ccc333', 'name' => 'text-basic', 'parent' => 'aaa111', 'children' => array(), 'settings' => array() ) ),
			'update' => array( array( 'id' => 'zzz999', 'settings' => array() ) ),
		)
	)
);
bab_check( 'no error (add did real work)', is_wp_error( $response ), false );
bab_check( 'added', $response['added'] ?? null, 1 );
bab_check( 'updated', $response['updated'] ?? null, 0 );
bab_check( 'unmatched_ids reported', $response['unmatched_ids'] ?? null, array( 'zzz999' ) );

echo "\n4. remove a parent that has a child\n";
$response = $ctl->patch_page( new WP_REST_Request( array( 'id' => 7 ), array( 'remove' => array( 'aaa111' ) ) ) );
bab_check( 'removed = 1, not 3 (children are ours)', $response['removed'] ?? null, 1 );
bab_check( 'removed_requested', $response['removed_requested'] ?? null, 1 );
bab_check( 'children removed too', count( $GLOBALS['bab_test_meta'][7]['_bricks_page_content_2'] ), 0 );

echo "\n5. remove an id that does not exist\n";
bab_seed();
$response = $ctl->patch_page( new WP_REST_Request( array( 'id' => 7 ), array( 'remove' => array( 'qqq888' ) ) ) );
bab_check( 'is WP_Error', is_wp_error( $response ), true );
bab_check( 'HTTP status', is_wp_error( $response ) ? $response->get_error_data()['status'] : null, 422 );
bab_check( 'content untouched', count( $GLOBALS['bab_test_meta'][7]['_bricks_page_content_2'] ), 2 );

printf( "\n%d passed, %d failed\n", $bab_pass, $bab_fail );
exit( $bab_fail > 0 ? 1 : 0 );
