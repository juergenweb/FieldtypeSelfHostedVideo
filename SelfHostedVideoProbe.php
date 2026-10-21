<?php namespace ProcessWire;

/**
 * SelfHostedVideoProbe
 *
 * Reads basic facts about a video file (duration, dimensions, codec) in plain PHP,
 * without ffmpeg or any PHP extension. Supports MP4/MOV (ISO base media) and WebM/Matroska.
 *
 * It only reads the container headers and never loads the media data, so it stays fast
 * on very large files. When a file cannot be understood, the returned values are simply
 * left at their defaults (0 or blank) rather than throwing.
 *
 * ~~~~~
 * $info = SelfHostedVideoProbe::probe('/path/to/video.mp4');
 * echo $info['duration']; // 12.5
 * ~~~~~
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoProbe {

	/**
	 * Maximum number of boxes/elements to inspect at any one level (guards against corrupt files)
	 *
	 */
	const maxItems = 2000;

	/**
	 * Maximum bytes read into memory for a single header element
	 *
	 */
	const maxRead = 1048576;

	/**
	 * Probe the given file
	 *
	 * @param string $filename Full path to the video file
	 * @return array Associative array with these keys:
	 *  - `format` (string): Container, one of "mp4", "webm", "matroska", or blank if not recognized.
	 *  - `duration` (float): Duration in seconds, or 0 if unknown.
	 *  - `width` (int): Display width in pixels (rotation applied), or 0 if unknown.
	 *  - `height` (int): Display height in pixels (rotation applied), or 0 if unknown.
	 *  - `rotation` (int): Clockwise rotation stored in the file: 0, 90, 180 or 270.
	 *  - `videoCodec` (string): Codec identifier such as "avc1", "hvc1", "av01" or "V_VP9", blank if unknown.
	 *  - `hasVideo` (bool): Does the file have a video track?
	 *  - `hasAudio` (bool): Does the file have an audio track?
	 *  - `faststart` (bool|null): MP4 only: is the index stored before the media data, so that
	 *     playback can start while downloading? Null when not applicable or unknown.
	 *
	 */
	public static function probe($filename) {

		$info = array(
			'format' => '',
			'duration' => 0.0,
			'width' => 0,
			'height' => 0,
			'rotation' => 0,
			'videoCodec' => '',
			'hasVideo' => false,
			'hasAudio' => false,
			'faststart' => null,
		);

		if(!is_string($filename) || !strlen($filename) || !is_file($filename)) return $info;

		$fp = @fopen($filename, 'rb');
		if(!$fp) return $info;

		try {
			$stat = fstat($fp);
			$size = isset($stat['size']) ? (int) $stat['size'] : 0;
			$head = $size >= 12 ? (string) fread($fp, 12) : '';
			if(strlen($head) === 12) {
				$type = substr($head, 4, 4);
				if(substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {
					self::probeMatroska($fp, $size, $info);
				} else if(in_array($type, array('ftyp', 'styp', 'moov', 'mdat', 'free', 'skip', 'wide', 'pnot'), true)) {
					self::probeMp4($fp, $size, $info);
				}
			}
		} catch(\Throwable $e) {
			// unreadable or corrupt file: return whatever was determined so far
		}

		fclose($fp);

		return $info;
	}

	/*** MP4 / MOV ******************************************************************************/

	/**
	 * Probe an ISO base media file (MP4, M4V, MOV)
	 *
	 * @param resource $fp
	 * @param int $size File size
	 * @param array $info
	 *
	 */
	protected static function probeMp4($fp, $size, array &$info) {

		$info['format'] = 'mp4';
		$moov = null;
		$mdatPos = null;
		$pos = 0;
		$n = 0;

		while($pos + 8 <= $size && $n++ < self::maxItems) {
			$box = self::readBox($fp, $pos, $size);
			if(!$box) break;
			if($box['type'] === 'moov' && !$moov) $moov = $box;
			if($box['type'] === 'mdat' && $mdatPos === null) $mdatPos = $pos;
			if($moov && $mdatPos !== null) break;
			$pos = $box['end'];
		}

		if(!$moov) return;

		$info['faststart'] = $mdatPos === null ? true : $moov['start'] < $mdatPos;

		$timescale = 0;
		$duration = 0;
		$fragmentDuration = 0;

		foreach(self::childBoxes($fp, $moov) as $box) {
			if($box['type'] === 'mvhd') {
				$data = self::readAt($fp, $box['data'], 32);
				if(strlen($data) < 20) continue;
				if(ord($data[0]) === 1) {
					if(strlen($data) < 32) continue;
					$timescale = self::uint32($data, 20);
					$duration = self::uint64($data, 24);
				} else {
					$timescale = self::uint32($data, 12);
					$duration = self::uint32($data, 16);
					if($duration === 0xFFFFFFFF) $duration = 0; // means "unknown"
				}
			} else if($box['type'] === 'mvex') {
				// fragmented MP4 keeps the overall duration here
				foreach(self::childBoxes($fp, $box) as $child) {
					if($child['type'] !== 'mehd') continue;
					$data = self::readAt($fp, $child['data'], 12);
					if(strlen($data) >= 12 && ord($data[0]) === 1) {
						$fragmentDuration = self::uint64($data, 4);
					} else if(strlen($data) >= 8) {
						$fragmentDuration = self::uint32($data, 4);
					}
				}
			} else if($box['type'] === 'trak') {
				self::parseMp4Track($fp, $box, $info);
			}
		}

		if(!$duration) $duration = $fragmentDuration;
		if($timescale > 0 && $duration > 0) $info['duration'] = round($duration / $timescale, 3);
	}

	/**
	 * Parse one MP4 track ("trak" box)
	 *
	 * @param resource $fp
	 * @param array $trak
	 * @param array $info
	 *
	 */
	protected static function parseMp4Track($fp, array $trak, array &$info) {

		$width = 0;
		$height = 0;
		$rotation = 0;
		$handler = '';
		$codec = '';

		foreach(self::childBoxes($fp, $trak) as $box) {

			if($box['type'] === 'tkhd') {
				$data = self::readAt($fp, $box['data'], 104);
				if(!strlen($data)) continue;
				// position right after the track duration, which depends on the box version
				$base = ord($data[0]) === 1 ? 36 : 24;
				if(strlen($data) < $base + 60) continue;
				$matrix = $base + 16;
				$a = self::int32($data, $matrix);
				$b = self::int32($data, $matrix + 4);
				$width = (int) round(self::uint32($data, $base + 52) / 65536);
				$height = (int) round(self::uint32($data, $base + 56) / 65536);
				if($a === 0 && $b > 0) {
					$rotation = 90;
				} else if($a < 0 && $b === 0) {
					$rotation = 180;
				} else if($a === 0 && $b < 0) {
					$rotation = 270;
				}

			} else if($box['type'] === 'mdia') {
				foreach(self::childBoxes($fp, $box) as $mdia) {
					if($mdia['type'] === 'hdlr') {
						$data = self::readAt($fp, $mdia['data'], 12);
						if(strlen($data) >= 12) $handler = substr($data, 8, 4);
					} else if($mdia['type'] === 'minf') {
						foreach(self::childBoxes($fp, $mdia) as $minf) {
							if($minf['type'] !== 'stbl') continue;
							foreach(self::childBoxes($fp, $minf) as $stbl) {
								if($stbl['type'] !== 'stsd') continue;
								$data = self::readAt($fp, $stbl['data'], 16);
								if(strlen($data) >= 16) $codec = self::cleanCodec(substr($data, 12, 4));
							}
						}
					}
				}
			}
		}

		if($handler === 'vide' && !$info['hasVideo']) {
			$info['hasVideo'] = true;
			$info['rotation'] = $rotation;
			$info['videoCodec'] = $codec;
			if($rotation === 90 || $rotation === 270) {
				// stored sideways: what viewers see has width and height swapped
				$info['width'] = $height;
				$info['height'] = $width;
			} else {
				$info['width'] = $width;
				$info['height'] = $height;
			}
		} else if($handler === 'soun') {
			$info['hasAudio'] = true;
		}
	}

	/**
	 * Read an MP4 box header at the given position
	 *
	 * @param resource $fp
	 * @param int $pos Position of the box
	 * @param int $limit Position where the containing box (or file) ends
	 * @return array|null Array with 'type', 'start', 'data' (payload position) and 'end', or null if no valid box
	 *
	 */
	protected static function readBox($fp, $pos, $limit) {

		$head = self::readAt($fp, $pos, 16);
		if(strlen($head) < 8) return null;

		$size = self::uint32($head, 0);
		$type = substr($head, 4, 4);
		$headerSize = 8;

		if($size === 1) {
			// 64-bit size follows the type
			if(strlen($head) < 16) return null;
			$size = self::uint64($head, 8);
			$headerSize = 16;
		} else if($size === 0) {
			// box extends to the end of its container
			$size = $limit - $pos;
		}

		if($size < $headerSize) return null;

		$end = $pos + $size;
		if($end > $limit) $end = $limit; // truncated file: read what is there

		return array(
			'type' => $type,
			'start' => $pos,
			'data' => $pos + $headerSize,
			'end' => $end,
		);
	}

	/**
	 * Get the boxes directly inside the given box
	 *
	 * @param resource $fp
	 * @param array $parent
	 * @return array
	 *
	 */
	protected static function childBoxes($fp, array $parent) {
		$boxes = array();
		$pos = $parent['data'];
		$n = 0;
		while($pos + 8 <= $parent['end'] && $n++ < self::maxItems) {
			$box = self::readBox($fp, $pos, $parent['end']);
			if(!$box || $box['end'] <= $pos) break;
			$boxes[] = $box;
			$pos = $box['end'];
		}
		return $boxes;
	}

	/*** WEBM / MATROSKA ************************************************************************/

	/**
	 * Probe a WebM or Matroska file
	 *
	 * @param resource $fp
	 * @param int $size File size
	 * @param array $info
	 *
	 */
	protected static function probeMatroska($fp, $size, array &$info) {

		$info['format'] = 'webm';

		$header = self::readElement($fp, 0, $size);
		if(!$header || $header['id'] !== 0x1A45DFA3) return;

		foreach(self::childElements($fp, $header) as $el) {
			if($el['id'] !== 0x4282) continue; // DocType
			$docType = trim(self::readAt($fp, $el['data'], min(32, $el['end'] - $el['data'])), "\0 ");
			if($docType === 'matroska') $info['format'] = 'matroska';
		}

		$segment = self::readElement($fp, $header['end'], $size);
		if(!$segment || $segment['id'] !== 0x18538067) return;

		$timestampScale = 1000000; // nanoseconds per tick, the format default
		$duration = 0.0;
		$hasInfo = false;
		$hasTracks = false;

		foreach(self::childElements($fp, $segment, 0x1F43B675) as $el) {

			if($el['id'] === 0x1549A966) {
				// Info
				$hasInfo = true;
				foreach(self::childElements($fp, $el) as $child) {
					if($child['id'] === 0x2AD7B1) {
						$value = self::readUint($fp, $child);
						if($value > 0) $timestampScale = $value;
					} else if($child['id'] === 0x4489) {
						$duration = self::readFloat($fp, $child);
					}
				}

			} else if($el['id'] === 0x1654AE6B) {
				// Tracks
				$hasTracks = true;
				foreach(self::childElements($fp, $el) as $track) {
					if($track['id'] === 0xAE) self::parseMatroskaTrack($fp, $track, $info);
				}
			}

			if($hasInfo && $hasTracks) break;
		}

		if($duration > 0) $info['duration'] = round($duration * $timestampScale / 1000000000, 3);
	}

	/**
	 * Parse one Matroska track ("TrackEntry" element)
	 *
	 * @param resource $fp
	 * @param array $track
	 * @param array $info
	 *
	 */
	protected static function parseMatroskaTrack($fp, array $track, array &$info) {

		$type = 0;
		$codec = '';
		$pixelWidth = 0;
		$pixelHeight = 0;
		$displayWidth = 0;
		$displayHeight = 0;
		$displayUnit = 0;

		foreach(self::childElements($fp, $track) as $el) {
			if($el['id'] === 0x83) {
				$type = self::readUint($fp, $el);
			} else if($el['id'] === 0x86) {
				$codec = self::cleanCodec(self::readAt($fp, $el['data'], min(64, $el['end'] - $el['data'])));
			} else if($el['id'] === 0xE0) {
				foreach(self::childElements($fp, $el) as $video) {
					switch($video['id']) {
						case 0xB0: $pixelWidth = self::readUint($fp, $video); break;
						case 0xBA: $pixelHeight = self::readUint($fp, $video); break;
						case 0x54B0: $displayWidth = self::readUint($fp, $video); break;
						case 0x54BA: $displayHeight = self::readUint($fp, $video); break;
						case 0x54B2: $displayUnit = self::readUint($fp, $video); break;
					}
				}
			}
		}

		if($type === 1 && !$info['hasVideo']) {
			$info['hasVideo'] = true;
			$info['videoCodec'] = $codec;
			if($displayUnit === 0 && $displayWidth > 0 && $displayHeight > 0) {
				// display size given in pixels, which is what viewers see
				$info['width'] = $displayWidth;
				$info['height'] = $displayHeight;
			} else {
				$info['width'] = $pixelWidth;
				$info['height'] = $pixelHeight;
			}
		} else if($type === 2) {
			$info['hasAudio'] = true;
		}
	}

	/**
	 * Read an EBML element header at the given position
	 *
	 * @param resource $fp
	 * @param int $pos
	 * @param int $limit Position where the containing element (or file) ends
	 * @return array|null Array with 'id', 'start', 'data' (payload position) and 'end', or null if not valid
	 *
	 */
	protected static function readElement($fp, $pos, $limit) {

		$head = self::readAt($fp, $pos, 12);
		$len = strlen($head);
		if($len < 2) return null;

		// element ID: 1-4 bytes, length given by the position of the first set bit
		$first = ord($head[0]);
		if($first & 0x80) {
			$idLen = 1;
		} else if($first & 0x40) {
			$idLen = 2;
		} else if($first & 0x20) {
			$idLen = 3;
		} else if($first & 0x10) {
			$idLen = 4;
		} else {
			return null;
		}
		if($len < $idLen + 1) return null;

		$id = 0;
		for($i = 0; $i < $idLen; $i++) $id = ($id << 8) | ord($head[$i]);

		// element size: 1-8 bytes, same scheme, marker bit removed
		$first = ord($head[$idLen]);
		$sizeLen = 0;
		for($i = 0; $i < 8; $i++) {
			if($first & (0x80 >> $i)) {
				$sizeLen = $i + 1;
				break;
			}
		}
		if(!$sizeLen || $len < $idLen + $sizeLen) return null;

		$size = $first & (0xFF >> $sizeLen);
		$unknown = $size === (0xFF >> $sizeLen);
		for($i = 1; $i < $sizeLen; $i++) {
			$byte = ord($head[$idLen + $i]);
			if($byte !== 0xFF) $unknown = false;
			$size = ($size << 8) | $byte;
		}

		$data = $pos + $idLen + $sizeLen;
		$end = $unknown ? $limit : $data + $size; // unknown size: runs to the end of its container
		if($end > $limit || $end < $data) $end = $limit;

		return array(
			'id' => $id,
			'start' => $pos,
			'data' => $data,
			'end' => $end,
		);
	}

	/**
	 * Get the elements directly inside the given EBML element
	 *
	 * @param resource $fp
	 * @param array $parent
	 * @param int $stopId Stop when an element with this ID is reached (0=no stop)
	 * @return array
	 *
	 */
	protected static function childElements($fp, array $parent, $stopId = 0) {
		$elements = array();
		$pos = $parent['data'];
		$n = 0;
		while($pos + 2 <= $parent['end'] && $n++ < self::maxItems) {
			$el = self::readElement($fp, $pos, $parent['end']);
			if(!$el || $el['end'] <= $pos) break;
			if($stopId && $el['id'] === $stopId) break;
			$elements[] = $el;
			$pos = $el['end'];
		}
		return $elements;
	}

	/**
	 * Read the unsigned integer value of an EBML element
	 *
	 * @param resource $fp
	 * @param array $el
	 * @return int
	 *
	 */
	protected static function readUint($fp, array $el) {
		$length = $el['end'] - $el['data'];
		if($length < 1 || $length > 8) return 0;
		$data = self::readAt($fp, $el['data'], $length);
		$value = 0;
		for($i = 0; $i < strlen($data); $i++) $value = ($value << 8) | ord($data[$i]);
		return $value < 0 ? 0 : $value;
	}

	/**
	 * Read the float value of an EBML element
	 *
	 * @param resource $fp
	 * @param array $el
	 * @return float
	 *
	 */
	protected static function readFloat($fp, array $el) {
		$length = $el['end'] - $el['data'];
		if($length !== 4 && $length !== 8) return 0.0;
		$data = self::readAt($fp, $el['data'], $length);
		if(strlen($data) !== $length) return 0.0;
		$value = unpack($length === 4 ? 'G' : 'E', $data);
		$value = (float) $value[1];
		return is_finite($value) && $value > 0 ? $value : 0.0;
	}

	/*** SHARED HELPERS *************************************************************************/

	/**
	 * Read bytes at the given position
	 *
	 * @param resource $fp
	 * @param int $pos
	 * @param int $length
	 * @return string
	 *
	 */
	protected static function readAt($fp, $pos, $length) {
		if($length < 1 || $pos < 0) return '';
		if($length > self::maxRead) $length = self::maxRead;
		if(fseek($fp, $pos) !== 0) return '';
		$data = fread($fp, $length);
		return $data === false ? '' : $data;
	}

	/**
	 * Unsigned 32-bit big-endian integer from the given string
	 *
	 * @param string $data
	 * @param int $offset
	 * @return int
	 *
	 */
	protected static function uint32($data, $offset) {
		$value = unpack('N', substr($data, $offset, 4));
		return (int) $value[1];
	}

	/**
	 * Signed 32-bit big-endian integer from the given string
	 *
	 * @param string $data
	 * @param int $offset
	 * @return int
	 *
	 */
	protected static function int32($data, $offset) {
		$value = self::uint32($data, $offset);
		return $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}

	/**
	 * Unsigned 64-bit big-endian integer from the given string
	 *
	 * @param string $data
	 * @param int $offset
	 * @return int
	 *
	 */
	protected static function uint64($data, $offset) {
		$value = unpack('J', substr($data, $offset, 8));
		$value = (int) $value[1];
		return $value < 0 ? 0 : $value;
	}

	/**
	 * Reduce a codec identifier to safe printable characters
	 *
	 * @param string $codec
	 * @return string
	 *
	 */
	protected static function cleanCodec($codec) {
		return trim((string) preg_replace('/[^A-Za-z0-9_.\/-]/', '', (string) $codec));
	}
}
