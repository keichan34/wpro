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

new WordpressReadOnly;

/* * * * * * * * * * * * * * * * * * * * * * *
  GENERIC FUNCTION FOR NORMALIZING URLS:
* * * * * * * * * * * * * * * * * * * * * * */

class WordpressReadOnlyGeneric {

	function url_normalizer($url) {
		if (strpos($url, '%') !== false) return $url;
		$url = explode('/', $url);
		foreach ($url as $key => $val) $url[$key] = urlencode($val);
		return str_replace('%3A', ':', join('/', $url));
	}

}

/* * * * * * * * * * * * * * * * * * * * * * *
  BACKENDS:
* * * * * * * * * * * * * * * * * * * * * * */

class WordpressReadOnlyBackend extends WordpressReadOnlyGeneric {

	function upload($localpath, $url, $mimetype) {
		return false;
	}

	function file_exists($url) {
		return false;
	}

}

class WordpressReadOnlyS3 extends WordpressReadOnlyBackend {

	public $s3;

	public $key;
	public $secret;
	public $bucket;
	public $endpoint;

	function __construct() {
		$this->key = get_option('aws-key');
		$this->secret = get_option('aws-secret');
		$this->bucket = get_option('aws-bucket');
		$this->endpoint = get_option('aws-endpoint');

		$this->s3 = new S3(get_option('aws-key'), get_option('aws-secret'), false, get_option('aws-endpoint'));
	}

	function upload($file, $fullurl, $mime) {
		if (!preg_match('/^http:\/\/([^\/]+)\/(.*)$/', $fullurl, $regs)) return false;

		$fullurl = $this->url_normalizer($fullurl);

		$url = $regs[2];

		if (!file_exists($file)) return false;

		$r = $this->s3->putObject($this->s3->inputFile($file, false, $mime), $this->bucket, $url, S3::ACL_PUBLIC_READ);

		$debug = array(
			$file, $fullurl, $url, $mime, $r
		);
		$fh = fopen('/tmp/log', 'a');
		fwrite($fh, print_r($debug, true));
		fclose($fh);

		return $r;
	}

	function file_exists($path) {
		$path = $this->url_normalizer($path);
		$r = $this->s3->getObjectInfo($this->bucket, $path);
		if (is_array($r)) return true;
		return false;
	}

}

/* * * * * * * * * * * * * * * * * * * * * * *
  THE MAIN PLUGIN CLASS:
* * * * * * * * * * * * * * * * * * * * * * */

class WordpressReadOnly extends WordpressReadOnlyGeneric {

	public $backend = null;
	public $tempdir = '/tmp';

	function __construct() {
		add_action('admin_init', array($this, 'admin_init')); // Register the settings.
		add_action('admin_menu', array($this, 'admin_menu')); // Will add the settings menu.
		add_filter('wp_handle_upload', array($this, 'handle_upload')); // The very filter that takes care of uploads.
		add_filter('upload_dir', array($this, 'upload_dir')); // Sets the paths and urls for uploads.
		add_filter('wp_generate_attachment_metadata', array($this, 'generate_attachment_metadata')); // We use this filter to store resized versions of the images.
		add_filter('load_image_to_edit_path', array($this, 'load_image_to_edit_path')); // This filter downloads the image to our local temporary directory, prior to editing the image.
		add_filter('wp_save_image_file', array($this, 'save_image_file'), 10, 5); // Store image file.
		add_filter('wp_upload_bits', array($this, 'upload_bits')); // On XMLRPC uploads, files arrives as strings, which we are handling in this filter.
		add_filter('wp_handle_upload_prefilter', array($this, 'handle_upload_prefilter')); // This is where we check for filename dupes (and change them to avoid overwrites).

		$this->backend = new WordpressReadOnlyS3(); // This is the backend (i.e. S3 specific functions are in this class.)

		$this->tempdir = sys_get_temp_dir();
		if (substr($this->tempdir, -1) != '/') $this->tempdir = $this->tempdir . '/';

	}

	/* * * * * * * * * * * * * * * * * * * * * * *
	  REGISTER THE SETTINGS:
	* * * * * * * * * * * * * * * * * * * * * * */

	function admin_init() {
		register_setting('wpro-settings-group', 'aws-key');
		register_setting('wpro-settings-group', 'aws-secret');
		register_setting('wpro-settings-group', 'aws-bucket');
		register_setting('wpro-settings-group', 'aws-endpoint');
		add_option('aws-key');
		add_option('aws-secret');
		add_option('aws-bucket');
		add_option('aws-endpoint');
	}


	/* * * * * * * * * * * * * * * * * * * * * * *
	  ADMIN MENU:
	* * * * * * * * * * * * * * * * * * * * * * */

	function admin_menu() {
		add_options_page('WPRO Plugin Settings', 'WPRO Settings', 'manage_options', 'wpro', array($this, 'admin_form'));
	}
	function admin_form() {
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
							<th><label for="upload-destination">Upload Storage</th>
							<td><input name="upload-destination" id="upload-destination" type="radio" value="s3" checked="checked"/> Amazon AWS S3</td>
						</tr>
					</table>
					<h3><?php echo __('Amazon AWS S3 Settings'); ?></h3>
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
							<td>
								<select name="aws-endpoint" id="aws-endpoint">
									<?php
										$aws_regions = array(
											's3.amazonaws.com' => 'US East Region (Standard)',
											's3-us-west-2.amazonaws.com' => 'US West (Oregon) Region',
											's3-us-west-1.amazonaws.com' => 'US West (Northern California) Region',
											's3-eu-west-1.amazonaws.com' => 'EU (Ireland) Region',
											's3-ap-southeast-1.amazonaws.com' => 'Asia Pacific (Singapore) Region',
											's3-ap-northeast-1.amazonaws.com' => 'Asia Pacific (Tokyo) Region',
											's3-sa-east-1.amazonaws.com' => 'South America (Sao Paulo) Region'
										);
										// Endpoints comes from http://docs.amazonwebservices.com/general/latest/gr/rande.html

										foreach ($aws_regions as $endpoint => $endpoint_name) {
											echo ('<option value="' . $endpoint . '"');
											if ($endpoint == get_option('aws-endpoint')) {
												echo(' selected="selected"');
											}
											echo ('>' . $endpoint_name . '</option>');
										}
									?>
								</select> 
							</td>
						</tr>
					</table>
					<p class="submit"> 
						<input type="submit" name="submit" class="button-primary" value="<?php echo __('Save Changes'); ?>" /> 
					</p>
				</form>
			</div>
		<?php
	}

	/* * * * * * * * * * * * * * * * * * * * * * *
	  TAKING CARE OF UPLOADS:
	* * * * * * * * * * * * * * * * * * * * * * */

	function handle_upload($data) {

		$data['url'] = $this->url_normalizer($data['url']);

		if (!file_exists($data['file'])) return false;

		$response = $this->backend->upload($data['file'], $data['url'], $data['type']);
		if (!$response) return false;

		return $data;
	}

	public $upload_basedir = ''; // Variable for caching in the upload_dir()-method
	function upload_dir($data) {
		if ($this->upload_basedir == '') {
			$this->upload_basedir = $this->tempdir . 'wpro' . time() . rand(0, 999999);
			while (is_dir($this->upload_basedir)) $this->upload_basedir = $this->tempdir . 'wpro' . time() . rand(0, 999999);
		}
		$data['basedir'] = $this->upload_basedir;
		$data['baseurl'] = 'http://' . get_option('aws-bucket');
		$data['path'] = $this->upload_basedir . $data['subdir'];
		$data['url'] = $data['baseurl'] . $data['subdir'];
		if (!is_dir($data['path'])) @mkdir($data['path'], 0777, true);

		return $data;
	}

	function generate_attachment_metadata($data) {
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

			$this->backend->upload($file, $url, $mime);
		}

		return $data;
	}

	function load_image_to_edit_path($filepath) {
		if (substr($filepath, 0, 7) == 'http://') {

			$ending = '';
			if (preg_match('/\.([^\.\/]+)$/', $filepath, $regs)) $ending = '.' . $regs[1];

			$tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999) . $ending;
			while (file_exists($tmpfile)) $tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999) . $ending;

			$filepath = $this->url_normalizer($filepath);

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

	function save_image_file($dummy, $filename, $image, $mime_type, $post_id) {

		if (substr($filename, 0, strlen($this->tempdir)) != $this->tempdir) return false;
		$filename = substr($filename, strlen($this->tempdir));
		if (!preg_match('/^wpro[0-9]+(\/.+)$/', $filename, $regs)) return false;

		$filename = $regs[1];

		$tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999);
		while (file_exists($tmpfile)) $tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999);

		switch ($mime_type) {
			case 'image/jpeg':
				imagejpeg($image, $tmpfile, apply_filters('jpeg_quality', 90, 'edit_image'));
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

		return $this->backend->upload($tmpfile, 'http://' . get_option('aws-bucket') . $filename, $mime_type);
	}

	function upload_bits($data) {

		$ending = '';
		if (preg_match('/\.([^\.\/]+)$/', $data['name'], $regs)) $ending = '.' . $regs[1];

		$tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999) . $ending;
		while (file_exists($tmpfile)) $tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999) . $ending;

		$fh = fopen($tmpfile, 'wb');
		fwrite($fh, $data['bits']);
		fclose($fh);

		$upload = wp_upload_dir();

		return array(
			'file' => $tmpfile,
			'url' => $this->url_normalizer($upload['url'] . '/' . $data['name']),
			'error' => false
		);
	}

	// Handle duplicate filenames:
	// Wordpress never calls the wp_handle_upload_overrides filter properly, so we do not have any good way of setting a callback for wp_unique_filename_callback, which would be the most beautiful way of doing this. So, instead we are usting the wp_handle_upload_prefilter to check for duplicates and rename the files...
	function handle_upload_prefilter($file) {

		$upload = wp_upload_dir();

		$name = $file['name'];
		$path = trim($upload['subdir'], '/') . '/' . $name;

		$counter = 0;
		while ($this->backend->file_exists($path)) {
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

}
