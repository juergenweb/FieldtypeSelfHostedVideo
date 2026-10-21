<?php namespace ProcessWire;

/**
 * Sends the files of protected videos to those who may see them
 *
 * ProcessWire keeps the files of pages that are not public in folders the web server does not
 * serve (see `$config->pagefileSecure` and the "Access" settings of a template), and sends
 * such a file itself after checking who asks. For a video that is not enough: it is sent as a
 * download of unknown type, the session of the visitor stays locked for as long as the video
 * is being sent (every other page the visitor opens meanwhile has to wait), and nothing is
 * ever taken from the browser's cache.
 *
 * So the files that belong to a video (the video, its thumbnail in all sizes, its captions)
 * are sent here instead: with their type, in the parts the player asks for, with what a
 * browser needs to keep a copy and ask whether it is still good, and with the session set
 * free first. Who may have the file is still decided by ProcessWire, and by
 * FieldtypeSelfHostedVideo::allowFile() for videos that are reused on other pages.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoDelivery extends Wire {

	/**
	 * Media types by file extension
	 *
	 * @var array
	 *
	 */
	static protected $types = array(
		'mp4' => 'video/mp4',
		'm4v' => 'video/mp4',
		'webm' => 'video/webm',
		'mov' => 'video/quicktime',
		'ogv' => 'video/ogg',
		'ogg' => 'video/ogg',
		'vtt' => 'text/vtt; charset=utf-8',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png' => 'image/png',
		'webp' => 'image/webp',
		'gif' => 'image/gif',
		'avif' => 'image/avif',
	);

	/**
	 * Bytes read from the file and handed to the web server at a time
	 *
	 * @var int
	 *
	 */
	protected $chunkSize = 262144;

	/**
	 * Get the media type to send a file as
	 *
	 * @param string $filename
	 * @return string
	 *
	 */
	public function contentType($filename) {
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		return isset(self::$types[$ext]) ? self::$types[$ext] : 'application/octet-stream';
	}

	/**
	 * Get the validator of a file: it changes whenever the file does
	 *
	 * @param string $filename
	 * @return string
	 *
	 */
	public function etag($filename) {
		return '"' . dechex((int) filemtime($filename)) . '-' . dechex((int) filesize($filename)) . '"';
	}

	/**
	 * Work out what to answer to a request for a file
	 *
	 * Nothing is sent here, so that the decision can be tested on its own.
	 *
	 * @param string $filename Full path of an existing file
	 * @param array $request Request values, by default taken from the current request:
	 *  - `method` (string): GET or HEAD.
	 *  - `range` (string): Range header.
	 *  - `ifRange` (string): If-Range header.
	 *  - `ifNoneMatch` (string): If-None-Match header.
	 *  - `ifModifiedSince` (string): If-Modified-Since header.
	 * @return array
	 *  - `status` (int): 200, 206, 304, 405 or 416.
	 *  - `headers` (array): Header name => value.
	 *  - `start` (int): First byte to send.
	 *  - `length` (int): Number of bytes to send, 0 when the answer has no body.
	 *
	 */
	public function plan($filename, array $request = array()) {

		$defaults = array(
			'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
			'range' => isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '',
			'ifRange' => isset($_SERVER['HTTP_IF_RANGE']) ? $_SERVER['HTTP_IF_RANGE'] : '',
			'ifNoneMatch' => isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '',
			'ifModifiedSince' => isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : '',
		);
		$request = array_merge($defaults, $request);
		foreach($request as $key => $value) $request[$key] = trim((string) $value);

		$size = (int) filesize($filename);
		$mtime = (int) filemtime($filename);
		$etag = $this->etag($filename);
		$method = strtoupper($request['method']);

		$headers = array(
			'Content-Type' => $this->contentType($filename),
			'Accept-Ranges' => 'bytes',
			'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
			'ETag' => $etag,
			// The browser of the visitor may keep the file, nobody in between may, and before
			// each use the browser asks again: that is when access is checked.
			'Cache-Control' => 'private, no-cache',
			'X-Content-Type-Options' => 'nosniff',
		);

		$plan = array('status' => 200, 'headers' => $headers, 'start' => 0, 'length' => $size);

		if($method !== 'GET' && $method !== 'HEAD') {
			$plan['status'] = 405;
			$plan['headers'] = array('Allow' => 'GET, HEAD');
			$plan['length'] = 0;
			return $plan;
		}

		// does the browser have this very file already?
		$fresh = false;
		if(strlen($request['ifNoneMatch'])) {
			$fresh = $request['ifNoneMatch'] === '*' || $this->matchesEtag($request['ifNoneMatch'], $etag);
		} else if(strlen($request['ifModifiedSince'])) {
			$since = strtotime($request['ifModifiedSince']);
			$fresh = $since !== false && $since >= $mtime;
		}

		if($fresh) {
			$plan['status'] = 304;
			$plan['length'] = 0;
			unset($plan['headers']['Content-Type'], $plan['headers']['X-Content-Type-Options']);
			return $plan;
		}

		// a part of the file, as players ask for when they load ahead or jump
		$range = $request['range'];
		if(strlen($range) && strlen($request['ifRange'])) {
			// parts only belong together if they come from the same version of the file
			$same = strpos($request['ifRange'], '"') !== false
				? $this->matchesEtag($request['ifRange'], $etag, true)
				: strtotime($request['ifRange']) === $mtime;
			if(!$same) $range = '';
		}

		if(strlen($range)) {
			$part = $this->parseRange($range, $size);
			if($part === false) {
				$plan['status'] = 416;
				$plan['headers']['Content-Range'] = "bytes */$size";
				$plan['length'] = 0;
				unset($plan['headers']['Content-Type']);
				return $plan;
			}
			if(is_array($part)) {
				$plan['status'] = 206;
				$plan['start'] = $part[0];
				$plan['length'] = $part[1] - $part[0] + 1;
				$plan['headers']['Content-Range'] = "bytes $part[0]-$part[1]/$size";
			}
		}

		$plan['headers']['Content-Length'] = (string) $plan['length'];
		if($method === 'HEAD') $plan['length'] = 0;

		return $plan;
	}

	/**
	 * Read a Range header
	 *
	 * @param string $header I.e. "bytes=0-1023", "bytes=4096-" or "bytes=-500"
	 * @param int $size Size of the file
	 * @return array|null|bool First and last byte, null to send the whole file (no range, a kind
	 *   of range not known here, or several ranges at once), false if the range lies outside the file
	 *
	 */
	public function parseRange($header, $size) {

		if(!preg_match('/^\s*bytes\s*=\s*(.+)$/i', (string) $header, $matches)) return null;
		$spec = trim($matches[1]);
		if(strpos($spec, ',') !== false) return null;
		if(!preg_match('/^(\d*)\s*-\s*(\d*)$/', $spec, $matches)) return null;

		list(, $first, $last) = $matches;
		if($first === '' && $last === '') return null;
		if($size < 1) return false;

		if($first === '') {
			// the last so many bytes
			$count = (int) $last;
			if($count < 1) return false;
			return array(max(0, $size - $count), $size - 1);
		}

		$start = (int) $first;
		$end = $last === '' ? $size - 1 : min((int) $last, $size - 1);
		if($start >= $size || $start > $end) return false;

		return array($start, $end);
	}

	/**
	 * Is the validator of the file among those the browser names?
	 *
	 * @param string $header I.e. `"abc", W/"def"`
	 * @param string $etag
	 * @param bool $strong Do validators marked as weak not count?
	 * @return bool
	 *
	 */
	protected function matchesEtag($header, $etag, $strong = false) {
		foreach(explode(',', $header) as $candidate) {
			$candidate = trim($candidate);
			if(stripos($candidate, 'W/') === 0) {
				if($strong) continue;
				$candidate = substr($candidate, 2);
			}
			if($candidate === $etag) return true;
		}
		return false;
	}

	/**
	 * Send a file in answer to the current request
	 *
	 * @param string $filename Full path of the file
	 * @param array $headers More headers to send, as name => value
	 * @return int Number of bytes sent
	 * @throws WireException if the file does not exist
	 *
	 */
	public function send($filename, array $headers = array()) {

		if(!is_file($filename) || !is_readable($filename)) throw new WireException('File does not exist');

		$plan = $this->plan($filename);
		foreach($headers as $name => $value) $plan['headers'][$name] = $value;

		// Set the session free before anything is sent. PHP lets one request at a time use a session,
		// and a video can be on its way for minutes: the visitor could not open another page meanwhile.
		$this->wire()->session->close();

		if(ini_get('zlib.output_compression')) @ini_set('zlib.output_compression', 'Off');
		while(ob_get_level() > 0) {
			if(!@ob_end_clean()) break;
		}

		if(!headers_sent()) {
			http_response_code($plan['status']);
			// what PHP adds to every answer of a request with a session says "never keep this"
			header_remove('Pragma');
			header_remove('Expires');
			foreach($plan['headers'] as $name => $value) header("$name: $value", true);
		}

		if($plan['length'] < 1) return 0;

		$handle = @fopen($filename, 'rb');
		if(!$handle) return 0;

		if($plan['start'] > 0) fseek($handle, $plan['start']);

		$left = $plan['length'];
		$sent = 0;

		while($left > 0 && !feof($handle)) {
			if(function_exists('set_time_limit')) @set_time_limit(300); // counted anew for every piece
			$chunk = fread($handle, min($this->chunkSize, $left));
			if($chunk === false || $chunk === '') break;
			echo $chunk;
			$length = strlen($chunk);
			$left -= $length;
			$sent += $length;
			@flush();
			if(connection_aborted()) break; // the visitor has left or jumped elsewhere in the video
		}

		fclose($handle);

		return $sent;
	}
}
