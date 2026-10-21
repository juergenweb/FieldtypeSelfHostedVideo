<?php namespace ProcessWire;

/**
 * SelfHostedVideoUpload
 *
 * The core upload class, fed with a file that already lies on the server: a video that
 * the browser sent in parts. Everything the core does with an uploaded file (checking the
 * file extension, cleaning the file name, finding a free name, replacing in overwrite mode)
 * then applies to it unchanged.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoUpload extends WireUpload {

	/**
	 * Full path of the file that takes the place of the request body
	 *
	 * @var string
	 *
	 */
	protected $localFile = '';

	/**
	 * Set the file that takes the place of the request body
	 *
	 * @param string $filename Full path. The file is moved to its destination.
	 * @return $this
	 *
	 */
	public function setLocalFile($filename) {
		$this->localFile = (string) $filename;
		return $this;
	}

	/**
	 * Describe the upload the way PHP describes an uploaded file
	 *
	 * @return array|bool
	 *
	 */
	protected function getPhpFilesAjax() {

		if(!strlen($this->localFile)) return parent::getPhpFilesAjax();
		if(empty($_SERVER['HTTP_X_FILENAME'])) return false;

		clearstatcache(true, $this->localFile);
		$size = is_file($this->localFile) ? (int) filesize($this->localFile) : 0;

		return array(
			'name' => rawurldecode($_SERVER['HTTP_X_FILENAME']),
			'tmp_name' => $this->localFile,
			'size' => $size,
			'error' => $size ? UPLOAD_ERR_OK : UPLOAD_ERR_NO_FILE,
			'ajax' => true,
		);
	}

	/**
	 * Set the largest file size accepted
	 *
	 * The limit the file input derives from the server's upload settings does not apply
	 * to a file sent in parts. The video input checks its own limit while receiving them.
	 *
	 * @param int $bytes
	 * @return $this
	 *
	 */
	public function setMaxFileSize($bytes) {
		if(strlen($this->localFile)) return $this;
		return parent::setMaxFileSize($bytes);
	}
}
