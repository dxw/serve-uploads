<?php
/*
 * @wordpress-plugin
 * Plugin Name: Serve Uploads
 * Plugin URI: 
 * Description: Serve uploaded files via the PHP process
 * Version: 0.1
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
		serve_uploads($path);
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

function serve_uploads($req, $cache_control = "public, max-age=600")
{
	$upload_dir = wp_upload_dir();
	$baseurl = preg_replace('%^https?://[^/]+(/.*)$%', '$1', $upload_dir['baseurl']);
	$basedir = $upload_dir['basedir'];
	$file = preg_replace("[^{$baseurl}]", $basedir, $req);
	$realFilePath = realpath($file);
	$realUploadDir = realpath($basedir);
	if (is_file($file) && is_readable($file) && str_starts_with($realFilePath, $realUploadDir.'/')) {
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
			header('Cache-Control: '. $cache_control);
			header('Content-Type: ' . $type);
			header('Content-Length: ' . filesize($file));
			header('Last-Modified: ' . $ims_timestamp);

			header('X-Accel-Buffering: no');

			ob_get_flush();
			readfile($file);
		}
		exit;
	}
}
