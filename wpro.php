<?php
/**
Plugin Name: Wordpress Read-Only
Plugin URI: http://klandestino.se/
Description: Plugin for binaries (images, media, uploads) on read-only Wordpress installations. Amazon S3 is used as binary backend.
Version: 0.1
Author: Alfred
Author URI: http://klandestino.se/
License: This code (except for the S3.php file) is (un)licensed under the kopimi (copyme) non-license; http://www.kopimi.com. In other words you are free to copy it, taunt it, share it, fork it or whatever. :) 
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

function wpro_upload_file_to_s3($file, $url) {
	if (!preg_match('/^http:\/\/([^\/]+)\/(.*)$/', $url, $regs)) return false;

	$bucket = $regs[1];
	$url = $regs[2];

	$s3 = new S3(get_option('aws-key'), get_option('aws-secret'), false, get_option('aws-endpoint'));

	return $s3->putObject($s3->inputFile($file, false), $bucket, $url, S3::ACL_PUBLIC_READ);
}

function wpro_wp_handle_upload($data) {
	$response = wpro_upload_file_to_s3($data['file'], $data['url']);
	if ($response !== true) {
		return false;
	}
	return $data;
}

function wpro_wp_generate_attachment_metadata($data) {
	$upload_dir = wp_upload_dir();
	$filepath = $upload_dir['basedir'] . '/' . preg_replace('/^(.+)\/[^\/]+$/', '\\1', $data['file']);
	foreach ($data['sizes'] as $size => $sizedata) {
		$file = $filepath . '/' . $sizedata['file'];
		$url = $upload_dir['baseurl'] . substr($file, strlen($upload_dir['basedir']));
		wpro_upload_file_to_s3($file, $url);
	}

	return $data;
}

add_filter('load_image_to_edit_path', 'wpro_load_image_to_edit_path');
function wpro_load_image_to_edit_path($filepath) {
	if (substr($filepath, 0, 7) == 'http://') {

		$ending = '';
		if (preg_match('/\.([^\.\/]+)$/', $filepath, $regs)) $ending = '.' . $regs[1];

		$tmpfile = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999) . $ending;
		while (file_exists($tmpfile)) $tmpfile = wpro_get_temp_dir() . 'wpro' . time() . rand(0, 999999) . $ending;

		// Kombinationen cURL och S3 hanterar inte nationella tecken i URL:er, därför:
		$filepath = explode('/', $filepath);
		foreach ($filepath as $key => $val) {
			$filepath[$key] = urlencode($val);
		}
		$filepath = str_replace('%3A', ':', join('/', $filepath));

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
