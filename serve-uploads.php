<?php
/*
 * @wordpress-plugin
 * Plugin Name: Serve Uploads
 * Plugin URI:
 * Description: Only allow access to files when logged in
 * Version: 0.1.1
 * Author: Lee
 * Author URI: https://www.dxw.com/
 * Text Domain: serve-uploads
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

add_action( 'init', 'serve_uploads_activate' );

function serve_uploads_activate()
{
	$path = url_strip_query($_SERVER['REQUEST_URI']);
	if (str_contains($path, '/wp-content/uploads/') || str_contains($path, '/wp-content/blogs.dir/')) {
		serve_upload($path);
	}
}

function url_strip_query($path)
{
	$pos = strpos($path, '?');
	if ($pos !== false) {
		$path = substr($path, 0, $pos);
	}
	return $path;
}

function get_attachment_id($path)
{
	global $wpdb;
	$attach_id = 0;

	$query = "SELECT post_id FROM $wpdb->postmeta WHERE meta_key=\"_wp_attached_file\" AND meta_value = \"".$path."\" LIMIT 1";
	$to_check = $wpdb->get_col($query);
	if (sizeof($to_check)>0) {
		$attach_id = $to_check[0];
	} else {
		$query = "SELECT post_id FROM $wpdb->postmeta WHERE meta_key=\"_wp_attachment_metadata\" AND meta_value LIKE (\"%".basename($path)."%\")";
		$to_check = $wpdb->get_col($query);

		foreach($to_check as $check_id) {
			if ($attach_id == 0) {
				$root_file = get_post_meta($check_id, "_wp_attached_file")[0];
				$meta = wp_get_attachment_metadata($check_id, false);
				if (dirname($root_file) == dirname($path)) {
					$meta = wp_get_attachment_metadata($check_id, false);
					foreach($meta["sizes"] as $size) {
						if ($size["file"] == basename($path)) {
							$attach_id = $check_id;
						}
					}
				}
			}
		}
	}
	return $attach_id;
}

function serve_upload($req)
{
	$upload_dir = wp_upload_dir(null,false,false);
	$baseurl = preg_replace('%^https?://[^/]+(/.*)$%', '$1', $upload_dir['baseurl']);
	$basedir = $upload_dir['basedir'];
	$file = preg_replace("[^{$baseurl}]", $basedir, $req);
	$realFilePath = realpath($file);
	$realUploadDir = realpath($basedir);
	if (str_starts_with($realFilePath, $realUploadDir.'/') && is_file($file) && is_readable($file)) {
		$subdir_file = str_replace($basedir.'/', '', $file); # example: 2024/03/fish.jpg
		$attachment_id = get_attachment_id($subdir_file);
		if ($attachment_id > 0) {
			$public = get_post_meta($attachment_id, 'public_access', true);
			if ($public) {
				$cache_control = "public, max-age=600";
				serve_upload_file($file, $cache_control);
			} else {
				if (is_user_logged_in()) {
					$cache_control = "private, max-age=600";
					serve_upload_file($file, $cache_control);
				}
			}
		}
	}
}

function serve_upload_file($file, $cache_control = "public, max-age=600")
{
	$ims_timestamp = gmdate('D, d M Y H:i:s T', filemtime($file));

	if (array_key_exists('HTTP_IF_MODIFIED_SINCE', $_SERVER) && $ims_timestamp === $_SERVER['HTTP_IF_MODIFIED_SINCE']) {
		## we don't set Etag, so `If-None-Match:` doesn't need checking

		http_response_code(304);
		header('Last-Modified: ' . $ims_timestamp);
	} else {
		$mime = wp_check_filetype($file);
		$type = 'application/octet-stream';
		if ($mime['type'] !== false) {
			$type = $mime['type'];
		}
		header('Accept-Ranges: none');
		header('Cache-Control: ' . $cache_control);
		header('Content-Type: ' . $type);
		header('Content-Length: ' . filesize($file));
		header('Last-Modified: ' . $ims_timestamp);

		header('X-Accel-Buffering: no');

		ob_get_flush();
		readfile($file);
	}
	exit;
}


add_filter('attachment_fields_to_edit', 'add_public_access_flag', 10, 2);

function add_public_access_flag($form_fields, $post)
{
	$public_access = (bool) get_post_meta($post->ID, 'public_access', true);
	$input = '<input type="checkbox" id="attachments-'.$post->ID.'-public_access" name="attachments['.$post->ID.'][public_access]" value="1"'. checked($public_access, true, false) .'>';
	$form_fields['public_access'] = [
		'label' => 'Public access',
		'input' => 'html',
		'html' => $input,
		'value' => $public_access,
		'helps' => 'Can the file be downloaded without being logged in?'
	];
	return $form_fields;
}

add_action('edit_attachment', 'save_public_access_flag');

function save_public_access_flag($attachment_id)
{
	if (isset($_REQUEST['attachments'][$attachment_id]['public_access'])) {
		$public_access = $_REQUEST['attachments'][$attachment_id]['public_access'];
		update_post_meta($attachment_id, 'public_access', $public_access);
	} else {
		delete_post_meta($attachment_id, 'public_access');
	}
}
