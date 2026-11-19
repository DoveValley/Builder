<?php
/**
 * wp eval-file wp_builder_create_page.php <slug> <title> <contentFile> <metaDescription>
 * Staged to the box and run once per page by wpb_create_site() — avoids ever
 * passing generated HTML (full of quotes/attributes) through a shell command.
 */
$slug = $args[0];
$title = $args[1];
$contentFile = $args[2];
$metaDescription = $args[3] ?? '';
$seoTitle = $args[4] ?? '';

$content = file_get_contents( $contentFile );
if ( $content === false ) {
	echo "READ_FAILED\n";
	exit( 1 );
}

$existing = get_page_by_path( $slug );
if ( $existing ) {
	wp_delete_post( $existing->ID, true );
}

$postId = wp_insert_post( [
	'post_title'   => $title,
	'post_name'    => $slug,
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_content' => $content,
], true );

if ( is_wp_error( $postId ) ) {
	echo 'ERROR:' . $postId->get_error_message() . "\n";
	exit( 1 );
}

if ( $metaDescription !== '' ) {
	update_post_meta( $postId, '_wpb_meta_description', $metaDescription );
}
if ( $seoTitle !== '' ) {
	update_post_meta( $postId, '_wpb_seo_title', $seoTitle );
}

echo $postId . "\n";
