<?php namespace ProcessWire\SelfHostedVideoTests;

/**
 * Builds tiny video files byte by byte, so that the tests need no sample files:
 * an MP4 with an index (moov) and media data (mdat), and a WebM with the header of its tracks.
 * They hold no playable pictures, only what the module reads.
 */
class MediaFiles {

	// MP4 / MOV

	public static function box($type, $payload = '') {
		return pack('N', 8 + strlen($payload)) . $type . $payload;
	}

	public static function matrix($rotation = 0) {
		$one = 0x00010000;
		$w = 0x40000000;
		switch($rotation) {
			case 90: $m = array(0, $one, 0, -$one, 0, 0, 0, 0, $w); break;
			case 180: $m = array(-$one, 0, 0, 0, -$one, 0, 0, 0, $w); break;
			case 270: $m = array(0, -$one, 0, $one, 0, 0, 0, 0, $w); break;
			default: $m = array($one, 0, 0, 0, $one, 0, 0, 0, $w);
		}
		return pack('N9', ...array_map(function($v) { return $v & 0xFFFFFFFF; }, $m));
	}

	/**
	 * @param string $kind "vide" or "soun"
	 * @param array $o `width`, `height`, `rotation`, `codec`, `chunkOffset` (value in the chunk offset table, or null for no table)
	 */
	public static function track($kind, array $o = array()) {
		$o += array('width' => 0, 'height' => 0, 'rotation' => 0, 'codec' => 'avc1', 'chunkOffset' => null);
		$tkhd = self::box('tkhd', pack('N6', 3, 0, 0, 1, 0, 0) . str_repeat("\0", 8) . pack('n4', 0, 0, 0, 0) . self::matrix($o['rotation']) . pack('N2', $o['width'] << 16, $o['height'] << 16));
		$hdlr = self::box('hdlr', pack('N2', 0, 0) . $kind . str_repeat("\0", 13));
		$stsd = self::box('stsd', pack('N2', 0, 1) . self::box($o['codec'], str_repeat("\0", 8)));
		$stbl = $stsd;
		if($o['chunkOffset'] !== null) $stbl .= self::box('stco', pack('N3', 0, 1, $o['chunkOffset']));
		$minf = self::box('minf', self::box('stbl', $stbl));
		return self::box('trak', $tkhd . self::box('mdia', $hdlr . $minf));
	}

	/**
	 * @param array $tracks Tracks as track() builds them
	 * @param float $seconds
	 */
	public static function moov(array $tracks, $seconds = 5.0, $timescale = 1000) {
		$mvhd = self::box('mvhd', pack('N5', 0, 0, 0, $timescale, (int) round($seconds * $timescale)) . str_repeat("\0", 80));
		return self::box('moov', $mvhd . implode('', $tracks));
	}

	/**
	 * An MP4 with one video track, whose chunk offset points at the first byte of $chunk inside the media data.
	 *
	 * @param array $o `faststart` (index before the media data), `seconds`, `width`, `height`, `rotation`, `codec`, `audio` (bool), `chunk`
	 * @return array [ `bytes`, `chunk` (what the offset must point at) ]
	 */
	public static function mp4(array $o = array()) {
		$o += array('faststart' => false, 'seconds' => 5.0, 'width' => 640, 'height' => 360, 'rotation' => 0, 'codec' => 'avc1', 'audio' => false, 'chunk' => 'CHUNK-ONE-DATA');
		$ftyp = self::box('ftyp', 'isom' . pack('N', 512) . 'isomiso2mp41');
		$mdat = self::box('mdat', $o['chunk']);
		$build = function($offset) use($o) {
			$tracks = array(self::track('vide', array('width' => $o['width'], 'height' => $o['height'], 'rotation' => $o['rotation'], 'codec' => $o['codec'], 'chunkOffset' => $offset)));
			if($o['audio']) $tracks[] = self::track('soun', array('codec' => 'mp4a'));
			return self::moov($tracks, $o['seconds']);
		};
		$length = strlen($build(0));
		if($o['faststart']) {
			$offset = strlen($ftyp) + $length + 8; // behind the header of the media data
			$bytes = $ftyp . $build($offset) . $mdat;
		} else {
			$offset = strlen($ftyp) + 8;
			$bytes = $ftyp . $mdat . $build($offset);
		}
		return array('bytes' => $bytes, 'chunk' => $o['chunk']);
	}

	/**
	 * The value in the first chunk offset table (stco) of a file
	 */
	public static function chunkOffset($bytes) {
		$at = strpos($bytes, 'stco');
		if($at === false) return null;
		return unpack('N', substr($bytes, $at + 4 + 8, 4))[1];
	}

	// WebM / Matroska

	public static function ebml($idHex, $payload) {
		$len = strlen($payload);
		$size = $len < 127 ? chr(0x80 | $len) : pack('N', 0x10000000 | $len);
		return hex2bin($idHex) . $size . $payload;
	}

	public static function uint($n) {
		$bytes = ltrim(pack('J', $n), "\0");
		return strlen($bytes) ? $bytes : "\0";
	}

	/**
	 * @param array $o `docType`, `seconds`, `width`, `height`, `displayWidth`, `displayHeight`, `codec`, `audio` (bool), `scale` (ns per tick)
	 */
	public static function webm(array $o = array()) {
		$o += array('docType' => 'webm', 'seconds' => 12.5, 'width' => 1280, 'height' => 720, 'displayWidth' => 0, 'displayHeight' => 0, 'codec' => 'V_VP9', 'audio' => false, 'scale' => 1000000);
		$header = self::ebml('1A45DFA3', self::ebml('4282', $o['docType']));
		$info = self::ebml('1549A966', self::ebml('2AD7B1', self::uint($o['scale'])) . self::ebml('4489', pack('E', $o['seconds'] * 1000000000 / $o['scale'])));
		$video = self::ebml('B0', self::uint($o['width'])) . self::ebml('BA', self::uint($o['height']));
		if($o['displayWidth']) $video .= self::ebml('54B0', self::uint($o['displayWidth'])) . self::ebml('54BA', self::uint($o['displayHeight']));
		$entries = self::ebml('AE', self::ebml('83', self::uint(1)) . self::ebml('86', $o['codec']) . self::ebml('E0', $video));
		if($o['audio']) $entries .= self::ebml('AE', self::ebml('83', self::uint(2)) . self::ebml('86', 'A_OPUS'));
		$tracks = self::ebml('1654AE6B', $entries);
		$cluster = self::ebml('1F43B675', "\0\0");
		return $header . self::ebml('18538067', $info . $tracks . $cluster);
	}

	/**
	 * A small PNG image (a picture to use as the thumbnail of a video)
	 *
	 * @return string The bytes
	 */
	public static function png($size = 4) {
		$chunk = function($type, $data) { return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data)); };
		$row = "\0" . str_repeat("\x20\x40\x80", $size);
		return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat($row, $size))) . $chunk('IEND', '');
	}
}
