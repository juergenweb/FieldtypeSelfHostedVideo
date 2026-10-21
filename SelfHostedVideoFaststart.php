<?php namespace ProcessWire;

/**
 * SelfHostedVideoFaststart
 *
 * Prepares an MP4 or MOV file for streaming without changing picture or sound: the index of
 * the file (the box "moov") is moved in front of the media data (the box "mdat"), so that a
 * browser can start to play while the rest is still loading. This is what "fast start" or
 * "web optimized" does in video tools, and what qt-faststart does. It needs nothing but PHP.
 *
 * The media data is copied as it is. Where the index points into it (the chunk offsets of
 * every track, boxes "stco" and "co64"), the positions are moved by the size of the index.
 * Where a moved position no longer fits into 32 bits, the track's "stco" becomes a "co64".
 *
 * Left alone: files that are already prepared, fragmented files (their index is not where it
 * matters), compressed indexes ("cmov"), and files that cannot be read as MP4.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoFaststart {

	/**
	 * Largest index that is moved, in bytes (it is held in memory)
	 *
	 */
	const maxIndexBytes = 134217728;

	/**
	 * Boxes that contain other boxes on the way to the chunk offsets
	 *
	 */
	static protected $containers = array('moov', 'trak', 'mdia', 'minf', 'stbl');

	/**
	 * Does the file need preparing?
	 *
	 * @param string $filename
	 * @return bool True if it is an MP4 whose index comes after its media data
	 *
	 */
	public static function needed($filename) {
		$layout = self::layout($filename);
		return is_array($layout) && $layout['needed'];
	}

	/**
	 * Prepare a file for streaming, in place
	 *
	 * @param string $filename
	 * @param string $error Set to the reason when the file could not be prepared
	 * @return bool True if the file was changed, false if it was not (already prepared, or not possible: see $error)
	 *
	 */
	public static function fix($filename, &$error = '') {

		$error = '';
		$layout = self::layout($filename);
		if(!is_array($layout)) {
			$error = __('not an MP4 file that can be read');
			return false;
		}
		if(!$layout['needed']) {
			if($layout['reason']) $error = $layout['reason'];
			return false;
		}

		$size = $layout['size'];
		$mdat = $layout['mdat'];
		$moov = $layout['moov'];
		$oldLength = $moov['end'] - $moov['start'];

		if($oldLength > self::maxIndexBytes) {
			$error = __('the index of the file is too large');
			return false;
		}

		$dir = dirname($filename);
		$free = @disk_free_space($dir);
		if($free !== false && $free < $size + 1048576) {
			$error = __('not enough disk space');
			return false;
		}

		$in = @fopen($filename, 'rb');
		if(!$in) {
			$error = __('the file cannot be read');
			return false;
		}

		fseek($in, $moov['start']);
		$bytes = self::readAll($in, $oldLength);
		if(strlen($bytes) !== $oldLength) {
			fclose($in);
			$error = __('the index of the file cannot be read');
			return false;
		}

		$tree = self::parse($bytes, 0, strlen($bytes));
		if(!is_array($tree) || count($tree) !== 1 || $tree[0]['type'] !== 'moov') {
			fclose($in);
			$error = __('the index of the file cannot be read');
			return false;
		}
		if(self::contains($tree, 'cmov')) {
			fclose($in);
			$error = __('the index of the file is compressed');
			return false;
		}

		// where a byte of the old file lands in the new one, given the new length of the index
		$shift = function($offset, $newLength) use($mdat, $moov, $oldLength) {
			if($offset >= $mdat['start'] && $offset < $moov['start']) return $offset + $newLength;
			if($offset >= $moov['end']) return $offset + $newLength - $oldLength;
			return $offset;
		};

		// 64-bit chunk offsets where a moved one would no longer fit
		$length = strlen(self::build($tree, null, false));
		$upgrade = false;
		foreach(self::offsets($tree) as $offset) {
			if($shift($offset, $length) > 0xFFFFFFFF) {
				$upgrade = true;
				break;
			}
		}
		if($upgrade) $length = strlen(self::build($tree, null, true));

		$index = self::build($tree, function($offset) use($shift, $length) { return $shift($offset, $length); }, $upgrade);
		if(strlen($index) !== $length) {
			fclose($in);
			$error = __('the index could not be rebuilt');
			return false;
		}

		// the new file next to the old one, then put in its place
		$tmp = $filename . '.faststart-' . getmypid() . '.tmp';
		$out = @fopen($tmp, 'wb');
		if(!$out) {
			fclose($in);
			$error = __('no file can be written next to the video');
			return false;
		}
		if(function_exists('set_time_limit')) @set_time_limit(max(120, (int) ($size / 20000000)));

		$ok =
			self::copy($in, $out, 0, $mdat['start']) &&
			fwrite($out, $index) === strlen($index) &&
			self::copy($in, $out, $mdat['start'], $moov['start'] - $mdat['start']) &&
			self::copy($in, $out, $moov['end'], $size - $moov['end']);

		fclose($in);
		$ok = fclose($out) && $ok;
		clearstatcache(true, $tmp);

		if(!$ok || filesize($tmp) !== $size - $oldLength + $length) {
			@unlink($tmp);
			$error = __('the file could not be written');
			return false;
		}

		$check = self::layout($tmp);
		if(!is_array($check) || $check['needed'] || $check['reason']) {
			@unlink($tmp);
			$error = __('the new file did not turn out as expected');
			return false;
		}

		$perms = @fileperms($filename);
		if(!@rename($tmp, $filename)) {
			@unlink($tmp);
			$error = __('the file could not be replaced');
			return false;
		}
		if($perms !== false) @chmod($filename, $perms & 0777);
		clearstatcache(true, $filename);

		return true;
	}

	/**
	 * Where the boxes of a file are: the first media data and the index
	 *
	 * @param string $filename
	 * @return array|null [ `size`, `mdat`, `moov` (each [ start, end ] or null), `needed` (bool), `reason` (string) ], null if not an MP4
	 *
	 */
	protected static function layout($filename) {

		if(!is_file($filename) || !is_readable($filename)) return null;
		$size = filesize($filename);
		$fp = @fopen($filename, 'rb');
		if(!$fp || $size < 16) {
			if($fp) fclose($fp);
			return null;
		}

		$result = array('size' => $size, 'mdat' => null, 'moov' => null, 'needed' => false, 'reason' => '');
		$pos = 0;
		$first = true;
		$n = 0;

		while($pos + 8 <= $size && $n++ < 10000) {
			fseek($fp, $pos);
			$head = fread($fp, 16);
			if(strlen($head) < 8) break;
			$length = self::uint32($head, 0);
			$type = substr($head, 4, 4);
			if($length === 1) {
				if(strlen($head) < 16) break;
				$length = self::uint64($head, 8);
			} else if($length === 0) {
				$length = $size - $pos;
			}
			if($first && $type !== 'ftyp') {
				fclose($fp);
				return null;
			}
			$first = false;
			if($length < 8 || $pos + $length > $size) {
				// a box that does not fit: a damaged or unfinished file is left as it is
				fclose($fp);
				$result['reason'] = __('the file is not complete');
				return $result;
			}
			if($type === 'moof') $result['reason'] = __('the file is fragmented');
			if($type === 'mdat' && !$result['mdat']) $result['mdat'] = array('start' => $pos, 'end' => $pos + $length);
			if($type === 'moov') {
				if($result['moov']) {
					fclose($fp);
					$result['reason'] = __('the file has more than one index');
					return $result;
				}
				$result['moov'] = array('start' => $pos, 'end' => $pos + $length);
			}
			$pos += $length;
		}
		fclose($fp);

		if(!$result['moov'] || !$result['mdat']) {
			if(!$result['reason']) $result['reason'] = __('the file has no index or no media data');
			return $result;
		}
		if(!$result['reason'] && $result['moov']['start'] > $result['mdat']['start']) $result['needed'] = true;

		return $result;
	}

	/**
	 * Read boxes from a string
	 *
	 * @param string $bytes
	 * @param int $from
	 * @param int $to
	 * @return array|null of [ `type`, `head` (header length), `start`, `end`, `children` (array|null) ], null if the boxes do not fit
	 *
	 */
	protected static function parse($bytes, $from, $to) {
		$boxes = array();
		$pos = $from;
		while($pos < $to) {
			if($to - $pos < 8) return null;
			$length = self::uint32($bytes, $pos);
			$type = substr($bytes, $pos + 4, 4);
			$head = 8;
			if($length === 1) {
				if($to - $pos < 16) return null;
				$length = self::uint64($bytes, $pos + 8);
				$head = 16;
			} else if($length === 0) {
				$length = $to - $pos;
			}
			if($length < $head || $pos + $length > $to) return null;
			$box = array('type' => $type, 'head' => $head, 'start' => $pos, 'end' => $pos + $length, 'children' => null);
			if(in_array($type, self::$containers, true)) {
				$box['children'] = self::parse($bytes, $pos + $head, $pos + $length);
				if($box['children'] === null) return null;
			}
			$box['bytes'] = $bytes;
			$boxes[] = $box;
			$pos += $length;
		}
		return $boxes;
	}

	/**
	 * Is there a box of this type among the boxes, at any depth that was read?
	 *
	 */
	protected static function contains(array $boxes, $type) {
		foreach($boxes as $box) {
			if($box['type'] === $type) return true;
			if(is_array($box['children']) && self::contains($box['children'], $type)) return true;
		}
		return false;
	}

	/**
	 * All chunk offsets in the boxes
	 *
	 * @param array $boxes
	 * @return array of int
	 *
	 */
	protected static function offsets(array $boxes) {
		$all = array();
		foreach($boxes as $box) {
			if(is_array($box['children'])) {
				$all = array_merge($all, self::offsets($box['children']));
			} else if($box['type'] === 'stco' || $box['type'] === 'co64') {
				foreach(self::entries($box) as $offset) $all[] = $offset;
			}
		}
		return $all;
	}

	/**
	 * The entries of a chunk offset box
	 *
	 * @param array $box
	 * @return array of int
	 *
	 */
	protected static function entries(array $box) {
		$bytes = $box['bytes'];
		$data = $box['start'] + $box['head'];
		if($box['end'] - $data < 8) return array();
		$count = self::uint32($bytes, $data + 4);
		$width = $box['type'] === 'co64' ? 8 : 4;
		$count = min($count, (int) floor(($box['end'] - $data - 8) / $width));
		$out = array();
		for($i = 0; $i < $count; $i++) {
			$p = $data + 8 + $i * $width;
			$out[] = $width === 8 ? self::uint64($bytes, $p) : self::uint32($bytes, $p);
		}
		return $out;
	}

	/**
	 * Write the boxes again, with moved chunk offsets
	 *
	 * @param array $boxes
	 * @param callable|null $move Gives the new position of an offset, or null to keep them
	 * @param bool $upgrade Write every "stco" as "co64"?
	 * @return string
	 *
	 */
	protected static function build(array $boxes, $move, $upgrade) {
		$out = '';
		foreach($boxes as $box) {
			$bytes = $box['bytes'];
			if(is_array($box['children'])) {
				$body = self::build($box['children'], $move, $upgrade);
				$out .= self::header($box['type'], strlen($body)) . $body;
			} else if($box['type'] === 'stco' || $box['type'] === 'co64') {
				$data = $box['start'] + $box['head'];
				$versionFlags = substr($bytes, $data, 4);
				$entries = self::entries($box);
				$wide = $box['type'] === 'co64' || $upgrade;
				$body = $versionFlags . pack('N', count($entries));
				foreach($entries as $offset) {
					if($move) $offset = $move($offset);
					$body .= $wide ? self::pack64($offset) : pack('N', $offset);
				}
				$out .= self::header($wide ? 'co64' : 'stco', strlen($body)) . $body;
			} else {
				$out .= substr($bytes, $box['start'], $box['end'] - $box['start']);
			}
		}
		return $out;
	}

	protected static function header($type, $bodyLength) {
		$length = $bodyLength + 8;
		if($length > 0xFFFFFFFF) return pack('N', 1) . $type . self::pack64($bodyLength + 16);
		return pack('N', $length) . $type;
	}

	protected static function copy($in, $out, $offset, $length) {
		if($length <= 0) return true;
		if(fseek($in, $offset) !== 0) return false;
		$copied = stream_copy_to_stream($in, $out, $length);
		return $copied === $length;
	}

	protected static function readAll($fp, $length) {
		$out = '';
		while(strlen($out) < $length && !feof($fp)) {
			$part = fread($fp, min(8388608, $length - strlen($out)));
			if($part === false || $part === '') break;
			$out .= $part;
		}
		return $out;
	}

	protected static function uint32($bytes, $offset) {
		$v = unpack('N', substr($bytes, $offset, 4));
		return $v ? (int) $v[1] : 0;
	}

	protected static function uint64($bytes, $offset) {
		$v = unpack('N2', substr($bytes, $offset, 8));
		return $v ? (int) ($v[1] * 4294967296 + $v[2]) : 0;
	}

	protected static function pack64($value) {
		$value = (int) $value;
		return pack('N2', intdiv($value, 4294967296), $value % 4294967296);
	}
}
