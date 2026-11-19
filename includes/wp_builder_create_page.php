<?php
/**
 * wp eval-file wp_builder_create_page.php <slug> <title> <contentFile> <metaDescription> <seoTitle> <parentId>
 * Staged to the box and run once per page by wpb_create_site()/wpb_update_site()
 * — avoids ever passing generated HTML (full of quotes/attributes) through a
 * shell command.
 *
 * Insert-or-update BY SLUG, never delete-then-insert: a page that already
 * exists keeps its post ID. Deleting and re-creating looked harmless the
 * first time this was written, but it silently orphans anything that
 * references that ID — WordPress's own show_on_front/page_on_front safety
 * net resets itself to "posts" the moment its designated front-page post
 * disappears, even for an instant. The real bug this caused (PlusCities'
 * homepage going dark after a routine content edit) is the reason this
 * function updates in place instead.
 *
 * <parentId> is optional — pass a real post ID to nest this page under it
 * (e.g. a niche page under the Industries page, giving a real
 * /industries/med-spas/ URL instead of a flat one). Lookup for an existing
 * child has to match on parent too, since two different parents could
 * reuse the same slug.
 */
$slug = $args[0];
$title = $args[1];
$contentFile = $args[2];
$metaDescription = $args[3] ?? '';
$seoTitle = $args[4] ?? '';
$parentId = isset( $args[5] ) && ctype_digit( (string) $args[5] ) ? (int) $args[5] : 0;

$content = file_get_contents( $contentFile );
if ( $content === false ) {
	echo "READ_FAILED\n";
	exit( 1 );
}

if ( $parentId > 0 ) {
	$children = get_posts( [
		'name'           => $slug,
		'post_type'      => 'page',
		'post_parent'    => $parentId,
		'posts_per_page' => 1,
		'post_status'    => 'any',
	] );
	$existing = $children[0] ?? null;
} else {
	$existing = get_page_by_path( $slug );
}
if ( $existing ) {
	$postId = wp_update_post( [
		'ID'           => $existing->ID,
		'post_title'   => $title,
		'post_content' => $content,
	], true );
} else {
	$postId = wp_insert_post( [
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => $content,
		'post_parent'  => $parentId,
	], true );
}

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
