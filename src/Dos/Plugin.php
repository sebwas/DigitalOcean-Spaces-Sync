<?php

namespace Dos;

final class Plugin {
	private static $instance;

	private $key;

	private $secret;

	private $endpoint;

	private $container;

	private $storagePath;

	private $keepOnlyInStorage;

	private $storageFileDelete;

	private $filter;

	private $uploadUrlPath;

	private $uploadPath;

	/**
	 * @return self
	 */
	public static function getInstance() {
		if (!self::$instance) {
			self::$instance = new this(
				defined('DOS_KEY') ? DOS_KEY : null,
				defined('DOS_SECRET') ? DOS_SECRET : null,
				defined('DOS_CONTAINER') ? DOS_CONTAINER : null,
				defined('DOS_ENDPOINT') ? DOS_ENDPOINT : null,
				defined('DOS_STORAGE_PATH') ? DOS_STORAGE_PATH : null,
				defined('DOS_STORAGE_FILE_ONLY') ? DOS_STORAGE_FILE_ONLY : null,
				defined('DOS_STORAGE_FILE_DELETE') ? DOS_STORAGE_FILE_DELETE : null,
				defined('DOS_FILTER') ? DOS_FILTER : null,
				defined('UPLOAD_URL_PATH') ? UPLOAD_URL_PATH : null,
				defined('UPLOAD_PATH') ? UPLOAD_PATH : null
			);
		}

		return self::$instance;
	}

	public function __construct($key, $secret, $container, $endpoint, $storagePath, $keepOnlyInStorage, $storageFileDelete, $filter, $uploadUrlPath, $uploadPath) {
		$this->key               = empty($key) ? get_option('dos_key') : $key;
		$this->secret            = empty($secret) ? get_option('dos_secret') : $secret;
		$this->endpoint          = empty($endpoint) ? get_option('dos_endpoint') : $endpoint;
		$this->container         = empty($container) ? get_option('dos_container') : $container;
		$this->storagePath       = empty($storagePath) ? get_option('dos_storage_path') : $storagePath;
		$this->keepOnlyInStorage = empty($keepOnlyInStorage) ? get_option('dos_keep_only_in_storage') : $keepOnlyInStorage;
		$this->storageFileDelete = empty($storageFileDelete) ? get_option('dos_storage_file_delete') : $storageFileDelete;
		$this->filter            = empty($filter) ? get_option('dos_filter') : $filter;
		$this->uploadUrlPath     = empty($uploadUrlPath) ? get_option('dos_upload_url_path') : $uploadUrlPath;
		$this->uploadPath        = empty($uploadPath) ? get_option('dos_upload_path') : $uploadPath;
	}

	public function setup (): void {
		$this->registerActions();
	}

	private function registerActions (): void {
		add_action('admin_menu', [$this, 'registerMenu']);
		add_action('admin_init', [$this, 'registerSettings' ]);
		add_action('admin_enqueue_scripts', [$this, 'registerScripts' ]);
		add_action('admin_enqueue_scripts', [$this, 'registerStyles' ]);

		add_action('wp_ajax_dos_test_connection', [$this, 'testConnection' ]);

		add_action('add_attachment', [$this, 'addAttachment' ], 10, 1);
		add_action('delete_attachment', [$this, 'deleteAttachment' ], 10, 1);

		add_filter('wp_update_attachment_metadata', [$this, 'updateAttachmentMetadata'], 20, 1);
		add_filter('wp_unique_filename', [$this, 'uniqueFilename']);
	}

	public function registerScripts (): void {
		wp_enqueue_script('dos-core-js', plugin_dir_url(__FILE__) . '/assets/scripts/core.js', ['jquery'], '1.4.0', true);
	}

	public function registerStyles (): void {
		wp_enqueue_style('dos-flexboxgrid', plugin_dir_url(__FILE__) . '/assets/styles/flexboxgrid.min.css');
		wp_enqueue_style('dos-core-css', plugin_dir_url(__FILE__) . '/assets/styles/core.css');
	}

	public function registerSettings (): void {
		register_setting('dos_settings', 'dos_key');
		register_setting('dos_settings', 'dos_secret');
		register_setting('dos_settings', 'dos_endpoint');
		register_setting('dos_settings', 'dos_container');
		register_setting('dos_settings', 'dos_storagePath');
		register_setting('dos_settings', 'dos_storage_file_only');
		register_setting('dos_settings', 'dos_storage_file_delete');
		register_setting('dos_settings', 'dos_filter');
		register_setting('dos_settings', 'dos_upload_url_path');
		register_setting('dos_settings', 'dos_upload_path');
	}

	public function registerSettingPage (): void {
		include_once __DIR__ . '/page/settings.php';
	}

	public function registerMenu (): void {
		add_options_page(
			'DigitalOcean Spaces Sync',
			'DigitalOcean Spaces Sync',
			'manage_options',
			'setting_page.php',
			[$this, 'registerSettingPage']
		);
	}

	// FILTERS
	public function updateAttachmentMetadata ($metadata) {
		$paths     = [];
		$uploadDir = wp_upload_dir();

		// collect original file path
		if (isset($metadata['file'])) {
			$path = "{$uploadDir['basedir']}/{$metadata['file']}";
			array_push($paths, $path);

			// set basepath for other sizes
			$fileInfo = pathinfo($path);
			$basepath = isset($fileInfo['extension'])
					? str_replace("{$fileInfo['filename']}.{$fileInfo['extension']}", '', $path)
					: $path;
		}

		// collect size files path
		if (isset($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $size) {
				if (isset($size['file'])) {
					$path = "{$basepath}{$size['file']}";
					array_push($paths, $path);
				}
			}
		}

		// process paths
		foreach ($paths as $filepath) {
			$this->uploadFile($filepath, 0, true);
		}

		return $metadata;
	}

	/**
	 * Make sure the to be uploaded file has a unique file name and won't be overwritten.
	 */
	public function uniqueFilename ($filename) {
		$uploadDir = wp_upload_dir();
		$subdir    = $uploadDir['subdir'];

		$filesystem = Filesystem::getInstance($this->key, $this->secret, $this->container, $this->endpoint);

		$number      = 1;
		$newFilename = $filename;
		$fileparts   = pathinfo($filename);

		while ($filesystem->has("{$subdir}/{$newFilename}")) {
			$newFilename = "{$fileparts['filename']}-{$number}.{$fileparts['extension']}";
			$number      = (int) $number + 1;
		}

		return $newFilename;
	}

	public function addAttachment ($postId) {
		if (wp_attachment_is_image($postId) === false || get_post_mime_type($postId) === 'image/svg+xml') {
			$file = get_attached_file($postId);

			$this->uploadFile($file);
		}

		return true;
	}

	public function deleteAttachment ($postId): void {
		$paths     = [];
		$uploadDir = wp_upload_dir();

		if (wp_attachment_is_image($postId) === false) {
			$file = get_attached_file($postId);

			$this->deleteFile($file);
		} else {
			$metadata = wp_get_attachment_metadata($postId);

			// collect original file path
			if (isset($metadata['file'])) {
				$path = "{$uploadDir['basedir']}/{$metadata['file']}";
				array_push($paths, $path);

				// set basepath for other sizes
				$fileInfo = pathinfo($path);
				$basepath = isset($fileInfo['extension'])
						? str_replace("{$fileInfo['filename']}.{$fileInfo['extension']}", '', $path)
						: $path;
			}

			// collect size files path
			if (isset($metadata['sizes'])) {
				foreach ($metadata['sizes'] as $size) {
					if (isset($size['file'])) {
						$path = "{$basepath}{$size['file']}";
						array_push($paths, $path);
					}
				}
			}

			// process paths
			foreach ($paths as $filepath) {
				$this->deleteFile($filepath);
			}
		}
	}

	// METHODS
	public function testConnection (): void {
		try {
			$filesystem = Filesystem::getInstance($this->key, $this->secret, $this->container, $this->endpoint);
			$filesystem->write('test.txt', 'test');
			$filesystem->delete('test.txt');

			$this->showMessage(__('Connection is successfully established. Save the settings.', 'dos'));
			exit();
		} catch (Exception $e) {
			$this->showMessage(__('Connection is not established.', 'dos') . " : {$e->getMessage()}" . ($e->getCode() === 0 ? '' : ' - ' . $e->getCode()), true);
			exit();
		}
	}

	private function showMessage ($message, $errormsg = false): void {
		if ($errormsg) {
			echo '<div id="message" class="error">';
		} else {
			echo '<div id="message" class="updated fade">';
		}

		echo "<p><strong>$message</strong></p></div>";
	}

	private function filePath ($file) {
		$path = mb_strlen($this->uploadPath)
			? str_replace($this->uploadPath, '', $file)
			: str_replace(wp_upload_dir()['basedir'], '', $file);

		return "{$this->storagePath}{$path}";
	}

	public function uploadFile ($file) {
		$filesystem  = Filesystem::getInstance($this->key, $this->secret, $this->container, $this->endpoint);
		$regexString = $this->filter;

		// prepare regex
		if ($regexString === '*' || !mb_strlen($regexString)) {
			$regex = false;
		} else {
			$regex = preg_match($regexString, $file);
		}

		try {
			if (is_readable($file) && !$regex) {
				$filesystem->put($this->filePath($file), file_get_contents($file), [
					'visibility' => 'public',
				]);

				// remove on upload
				if ($this->keepOnlyInStorage) {
					unlink($file);
				}
			}

			return true;
		} catch (Exception $e) {
			return false;
		}
	}

	public function deleteFile ($file) {
		if ($this->storageFileDelete === 1) {
			try {
				$filepath = $this->filePath($file);

				$filesystem = Filesystem::getInstance($this->key, $this->secret, $this->container, $this->endpoint);
				$filesystem->delete($filepath);
			} catch (Exception $e) {
				error_log($e);
			}
		}

		return $file;
	}
}
