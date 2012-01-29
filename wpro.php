<?php
/**
Plugin Name: Wordpress Read-Only
Plugin URI: http://klandestino.se/
Description: Plugin for binaries (images, media, uploads) on read-only Wordpress installations. Amazon S3 is used as binary backend.
Version: 0.1
Author: Alfred
Author URI: http://klandestino.se/
License: Beerware / Kopimi  
 */

require_once('S3.php');

// Register the settings:
add_action ('admin_init', 'init_wpro_admin');
function init_wpro_admin() {
	register_setting('wpro-settings-group', 'aws-key');
	register_setting('wpro-settings-group', 'aws-secret');
	register_setting('wpro-settings-group', 'aws-bucket');
	register_setting('wpro-settings-group', 'aws-endpoint');
	add_option('aws-key');
	add_option('aws-secret');
	add_option('aws-bucket');
	add_option('aws-endpoint');
}

// Add admin menu:
add_action ('admin_menu', 'add_wpro_admin_menu');
function add_wpro_admin_menu() {
	add_options_page('WPRO Plugin Settings', 'WPRO Settings', 'manage_options', 'wpro', 'generate_wpro_admin_form');
}
function generate_wpro_admin_form () {
	if (!current_user_can ('manage_options'))  {
		wp_die ( __ ('You do not have sufficient permissions to access this page.'));
	}

	?>
		<div class="wrap">
			<div id="icon-plugins" class="icon32"><br /></div>
			<h2>Wordpress Read-Only (WPRO)</h2>
			<form name="wpro-settings-form" action="options.php" method="post">
				<?php settings_fields ('wpro-settings-group'); ?>
				<h3><?php echo __('Common Settings'); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="aws-key">AWS Key</label></th> 
						<td><input name="aws-key" id="aws-key" type="text" value="<?php echo get_option('aws-key'); ?>" class="regular-text code" /></td>
					</tr>
					<tr>
						<th><label for="aws-secret">AWS Secret</label></th> 
						<td><input name="aws-secret" id="aws-secret" type="text" value="<?php echo get_option('aws-secret'); ?>" class="regular-text code" /></td>
					</tr>
					<tr>
						<th><label for="aws-bucket">AWS Bucket</label></th> 
						<td><input name="aws-bucket" id="aws-bucket" type="text" value="<?php echo get_option('aws-bucket'); ?>" class="regular-text code" /></td>
					</tr>
					<tr>
						<th><label for="aws-endpoint">AWS Endpoint</label></th> 
						<td><input name="aws-endpoint" id="aws-endpoint" type="text" value="<?php echo get_option('aws-endpoint'); ?>" class="regular-text code" /></td>
					</tr>
				</table>
				<p class="submit"> 
					<input type="submit" name="submit" class="button-primary" value="<?php echo __('Save Changes'); ?>" /> 
				</p>
			</form>
		</div>
	<?php
}

add_filter('wp_handle_upload', 'wpro_wp_handle_upload');
add_filter('upload_dir', 'wpro_upload_dir');
add_filter('wp_generate_attachment_metadata', 'wpro_wp_generate_attachment_metadata');

function wpro_get_temp_dir() {
	$dir = sys_get_temp_dir();
	if (substr($dir, -1) != '/') $dir = $dir . '/';
	return $dir;
}

function wpro_upload_dir($data) {
	if (isset($GLOBALS['wpro_cache_upload_baseurl'])) {
		$data['basedir'] = $GLOBALS['wpro_cache_upload_baseurl'];
	} else {
		$data['basedir'] = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999);
		while (is_dir($data['basedir'])) $data['basedir'] = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999);
		$GLOBALS['wpro_cache_upload_baseurl'] = $data['basedir'];
	}
	$data['baseurl'] = 'http://' . get_option('aws-bucket');
	$data['path'] = $data['basedir'] . $data['subdir'];
	$data['url'] = $data['baseurl'] . $data['subdir'];
	if (!is_dir($data['path'])) @mkdir($data['path'], 0777, true);

	return $data;
}

function wpro_upload_file_to_s3($file, $fullurl, $mime) {
	if (!preg_match('/^http:\/\/([^\/]+)\/(.*)$/', $fullurl, $regs)) return false;

	$fullurl = wpro_url_normalizer($fullurl);

	$bucket = $regs[1];
	$url = $regs[2];

	if (!file_exists($file)) return false;

	$s3 = new S3(get_option('aws-key'), get_option('aws-secret'), false, get_option('aws-endpoint'));
	$r = $s3->putObject($s3->inputFile($file, false, $mime), $bucket, $url, S3::ACL_PUBLIC_READ);

	return $r;
}
function wpro_file_exists_on_s3($path) {
	$path = wpro_url_normalizer($path);
	$s3 = new S3(get_option('aws-key'), get_option('aws-secret'), false, get_option('aws-endpoint'));
	$r = $s3->getObjectInfo(get_option('aws-bucket'), $path);
	if (is_array($r)) return true;
	return false;
}

function wpro_wp_handle_upload($data) {

	$data['url'] = wpro_url_normalizer($data['url']);

	if (!file_exists($data['file'])) return false;

	$response = wpro_upload_file_to_s3($data['file'], $data['url'], $data['type']);
	if (!$response) return false;

	return $data;
}

function wpro_wp_generate_attachment_metadata($data) {
	if (!is_array($data) || !isset($data['sizes']) || !is_array($data['sizes'])) return $data;

	$upload_dir = wp_upload_dir();
	$filepath = $upload_dir['basedir'] . '/' . preg_replace('/^(.+)\/[^\/]+$/', '\\1', $data['file']);
	foreach ($data['sizes'] as $size => $sizedata) {
		$file = $filepath . '/' . $sizedata['file'];
		$url = $upload_dir['baseurl'] . substr($file, strlen($upload_dir['basedir']));

		$mime = 'application/octet-stream';
		switch(substr($file, -4)) {
			case '.gif':
				$mime = 'image/gif';
				break;
			case '.jpg':
				$mime = 'image/jpeg';
				break;
			case '.png':
				$mime = 'image/png';
				break;
		}

		wpro_upload_file_to_s3($file, $url, $mime);
	}

	return $data;
}

function wpro_url_normalizer($url) {
	if (strpos($url, '%') !== false) return $url;
	$url = explode('/', $url);
	foreach ($url as $key => $val) $url[$key] = urlencode($val);
	return str_replace('%3A', ':', join('/', $url));
}

add_filter('load_image_to_edit_path', 'wpro_load_image_to_edit_path');
function wpro_load_image_to_edit_path($filepath) {
	if (substr($filepath, 0, 7) == 'http://') {

		$ending = '';
		if (preg_match('/\.([^\.\/]+)$/', $filepath, $regs)) $ending = '.' . $regs[1];

		$tmpfile = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999) . $ending;
		while (file_exists($tmpfile)) $tmpfile = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999) . $ending;

		$filepath = wpro_url_normalizer($filepath);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $filepath);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);

		$fh = fopen($tmpfile, 'w');
		fwrite($fh, curl_exec($ch));
		fclose($fh);

		return $tmpfile;

	}
	return $filepath;
}

add_filter('wp_save_image_file', 'wpro_wp_save_image_file', 10, 5);
function wpro_wp_save_image_file($dummy, $filename, $image, $mime_type, $post_id) {

	if (!substr($filename, 0, strlen(wpro_get_temp_dir())) == wpro_get_temp_dir()) return false;
	$filename = substr($filename, strlen(wpro_get_temp_dir()));
	if (!preg_match('/^wpro[0-9]+(\/.+)$/', $filename, $regs)) return false;

	$filename = $regs[1];

	$tmpfile = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999);
	while (file_exists($tmpfile)) $tmpfile = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999);

	switch ( $mime_type ) {
		case 'image/jpeg':
			imagejpeg( $image, $tmpfile, apply_filters( 'jpeg_quality', 90, 'edit_image' ) );
			break;
		case 'image/png':
			imagepng($image, $tmpfile);
			break;
		case 'image/gif':
			imagegif($image, $tmpfile);
			break;
		default:
			return false;
	}

	return wpro_upload_file_to_s3($tmpfile, 'http://' . get_option('aws-bucket') . $filename, $mime_type);
}

// Filter for handling XMLRPC uploads:
add_filter('wp_upload_bits', 'wpro_wp_upload_bits');
function wpro_wp_upload_bits($data) {

	$ending = '';
	if (preg_match('/\.([^\.\/]+)$/', $data['name'], $regs)) $ending = '.' . $regs[1];

	$tmpfile = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999) . $ending;
	while (file_exists($tmpfile)) $tmpfile = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999) . $ending;

	$fh = fopen($tmpfile, 'wb');
	fwrite($fh, $data['bits']);
	fclose($fh);

	$upload = wp_upload_dir();

	return array(
		'file' => $tmpfile,
		'url' => wpro_url_normalizer($upload['url'] . '/' . $data['name']),
		'error' => false
	);
}

// Handle duplicate filenames:
// Wordpress never calls the wp_handle_upload_overrides filter properly, so we do not have any good way of setting a callback for wpro_unique_filename_callback, which would be the most beautiful way of doing this. So, instead we are usting the wpro_handle_upload_prefilter to check for duplicates and rename the files...
add_filter('wp_handle_upload_prefilter', 'wpro_handle_upload_prefilter');
function wpro_handle_upload_prefilter($file) {

	$upload = wp_upload_dir();

	$name = $file['name'];
	$path = trim($upload['subdir'], '/') . '/' . $name;

	$counter = 0;
	while (wpro_file_exists_on_s3($path)) {
		if (preg_match('/\.([^\.\/]+)$/', $file['name'], $regs)) {
			$ending = '.' . $regs[1];
			$preending = substr($file['name'], 0, 0 - strlen($ending));
			$name = $preending . '_' . $counter . $ending;
		} else {
			$name = $file['name'] . '_' . $counter;
		}
		$path = trim($upload['subdir'], '/') . '/' . $name;
		$counter++;
	}

	$file['name'] = $name;

	return $file;
}
