<?php namespace ProcessWire;

/**
 * SelfHostedVideoTimeline
 *
 * Helpers for what sits on the timeline of a video: chapters (titled moments) and overlays
 * (text and links placed on the picture). They bring whatever an editor or a script hands
 * in into the form in which it is stored, and convert times.
 *
 * Stored form of a chapter:  [ 'id' => 'k3x9ab', 'time' => 12.5, 'title' => [ 0 => 'Intro', 1017 => 'Einleitung' ] ]
 * Stored form of an overlay: [ 'id' => 'p0q7zt', 'text' => [ 0 => '…' ], 'url' => [ 0 => '…' ],
 *   'x' => 50.0, 'y' => 20.0, 'ax' => 'c', 'ay' => 't', 'w' => 0, 'when' => 'range', 'start' => 3.0, 'end' => 9.5,
 *   'size' => 'm', 'look' => 'dark' ]
 *
 * Texts are kept per language, indexed by language ID, where 0 is the default language.
 * Positions are percentages of the picture, so that they hold at every size of the player.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoTimeline {

	const maxChapters = 100;
	const maxOverlays = 30;
	const maxTitleLength = 200;
	const maxTextLength = 500;
	const maxUrlLength = 1024;

	/**
	 * Convert a time as people write it to seconds, i.e. "1:23.5" to 83.5
	 *
	 * @param string|int|float $value Seconds, "m:ss", "h:mm:ss", each with optional decimals (point or comma)
	 * @return float|null Null if this is not a time
	 *
	 */
	public static function parseTime($value) {
		if(is_int($value) || is_float($value)) return $value >= 0 ? (float) $value : null;
		$value = str_replace(',', '.', trim((string) $value));
		if(!strlen($value) || !preg_match('/^(?:(\d+):)?(?:(\d+):)?(\d+(?:\.\d+)?)$/', $value, $m)) return null;
		if(strlen($m[1]) && strlen($m[2])) {
			return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (float) $m[3];
		}
		if(strlen($m[1])) return ((int) $m[1]) * 60 + (float) $m[3];
		return (float) $m[3];
	}

	/**
	 * Format seconds as a time, i.e. 83.5 as "1:23.5" and 3725 as "1:02:05"
	 *
	 * @param float $seconds
	 * @return string
	 *
	 */
	public static function formatTime($seconds) {
		$seconds = max(0.0, round((float) $seconds, 1));
		$whole = (int) floor($seconds);
		$tenth = (int) round(($seconds - $whole) * 10);
		if($tenth >= 10) {
			$whole++;
			$tenth = 0;
		}
		$h = intdiv($whole, 3600);
		$m = intdiv($whole % 3600, 60);
		$s = $whole % 60;
		$out = $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
		return $tenth ? "$out.$tenth" : $out;
	}

	/**
	 * Make an identifier for a chapter or overlay
	 *
	 * @return string
	 *
	 */
	public static function newId() {
		return substr(base_convert(bin2hex(random_bytes(5)), 16, 36) . 'aaaaaa', 0, 6);
	}

	/**
	 * Clean an identifier, or make a new one if it is not usable or already taken
	 *
	 * @param mixed $id
	 * @param array $taken Identifiers in use, as keys
	 * @return string
	 *
	 */
	protected static function cleanId($id, array &$taken) {
		$id = is_string($id) && preg_match('/^[a-z0-9]{4,12}$/', $id) ? $id : '';
		while(!strlen($id) || isset($taken[$id])) $id = self::newId();
		$taken[$id] = true;
		return $id;
	}

	/**
	 * Clean texts given per language
	 *
	 * @param mixed $value Array indexed by language ID (0 = default language), or a string for the default language
	 * @param array $langKeys Language IDs that exist
	 * @param Sanitizer $sanitizer
	 * @param string $type "line" (one line of text), "text" (several lines) or "url"
	 * @return array Indexed by language ID, without empty values
	 *
	 */
	public static function cleanTexts($value, array $langKeys, Sanitizer $sanitizer, $type = 'line') {

		if(is_string($value)) $value = array(0 => $value);
		if(!is_array($value)) return array();

		$out = array();

		foreach($langKeys as $key) {
			$key = (int) $key;
			if(!isset($value[$key]) && !isset($value["$key"])) continue;
			$text = isset($value[$key]) ? $value[$key] : $value["$key"];
			if(!is_scalar($text)) continue;
			$text = (string) $text;
			if($type === 'url') {
				$text = self::cleanUrl($text, $sanitizer);
			} else if($type === 'text') {
				$text = $sanitizer->textarea($text, array('maxLength' => self::maxTextLength, 'stripTags' => false));
				$text = trim(preg_replace('/\n{3,}/', "\n\n", str_replace(array("\r\n", "\r"), "\n", $text)));
			} else {
				$text = trim($sanitizer->text($text, array('maxLength' => self::maxTitleLength, 'stripTags' => false)));
			}
			if(strlen($text)) $out[$key] = $text;
		}

		ksort($out);

		return $out;
	}

	/**
	 * Clean the address an overlay links to
	 *
	 * Allowed are addresses on this site ("/contact/", "#form"), web addresses, and mail and phone links.
	 *
	 * @param string $url
	 * @param Sanitizer $sanitizer
	 * @return string Blank if the address is not usable
	 *
	 */
	public static function cleanUrl($url, Sanitizer $sanitizer) {
		$url = trim((string) $url);
		if(!strlen($url) || strlen($url) > self::maxUrlLength) return '';
		if(preg_match('/[\x00-\x20<>"\'`\\\\]/', $url)) return '';
		if($url[0] === '#') return preg_match('/^#[A-Za-z0-9_:.\-]*$/', $url) ? $url : '';
		if(preg_match('!^(mailto|tel):[^/]!i', $url)) return $url;
		if(preg_match('!^[a-z][a-z0-9+.\-]*:!i', $url) && !preg_match('!^https?://!i', $url)) return '';
		if(strpos($url, '//') === 0) return '';
		$clean = $sanitizer->url($url, array('allowRelative' => true, 'allowQuerystring' => true, 'allowSchemes' => array('http', 'https'), 'throw' => false));
		return is_string($clean) ? $clean : '';
	}

	/**
	 * Bring a time into the range of the video
	 *
	 * @param mixed $value
	 * @param float $duration Duration of the video, 0 if not known
	 * @return float|null
	 *
	 */
	protected static function cleanTime($value, $duration) {
		$time = self::parseTime(is_scalar($value) ? $value : '');
		if($time === null) return null;
		$time = round($time, 1);
		if($duration > 0 && $time > $duration) $time = floor($duration * 10) / 10;
		return min($time, 359999.0);
	}

	/**
	 * Bring chapters into the form in which they are stored
	 *
	 * @param array $chapters Each an array with `time`, `title` (per language) and optionally `id`
	 * @param float $duration Duration of the video, 0 if not known
	 * @param array $langKeys Language IDs that exist
	 * @param Sanitizer $sanitizer
	 * @return array Sorted by time. Chapters without a time or title, and second chapters at the same time, are left out.
	 *
	 */
	public static function cleanChapters(array $chapters, $duration, array $langKeys, Sanitizer $sanitizer) {

		$out = array();
		$ids = array();
		$times = array();

		foreach($chapters as $chapter) {
			if(!is_array($chapter) || count($out) >= self::maxChapters) continue;
			$time = self::cleanTime(isset($chapter['time']) ? $chapter['time'] : null, $duration);
			$title = self::cleanTexts(isset($chapter['title']) ? $chapter['title'] : null, $langKeys, $sanitizer, 'line');
			if($time === null || !count($title)) continue;
			$key = number_format($time, 1, '.', '');
			if(isset($times[$key])) continue;
			$times[$key] = true;
			$out[] = array(
				'id' => self::cleanId(isset($chapter['id']) ? $chapter['id'] : '', $ids),
				'time' => $time,
				'title' => $title,
			);
		}

		usort($out, function($a, $b) {
			return $a['time'] <=> $b['time'];
		});

		return $out;
	}

	/**
	 * Bring overlays into the form in which they are stored
	 *
	 * @param array $overlays Each an array with `text` (per language) and optionally `url` (per language), `x`, `y`,
	 *   `ax`, `ay`, `w`, `when`, `start`, `end`, `size`, `look`, `id`
	 * @param float $duration Duration of the video, 0 if not known
	 * @param array $langKeys Language IDs that exist
	 * @param Sanitizer $sanitizer
	 * @return array Overlays without text are left out.
	 *
	 */
	public static function cleanOverlays(array $overlays, $duration, array $langKeys, Sanitizer $sanitizer) {

		$out = array();
		$ids = array();

		foreach($overlays as $overlay) {
			if(!is_array($overlay) || count($out) >= self::maxOverlays) continue;

			$text = self::cleanTexts(isset($overlay['text']) ? $overlay['text'] : null, $langKeys, $sanitizer, 'text');
			if(!count($text)) continue;

			$x = isset($overlay['x']) && is_numeric($overlay['x']) ? (float) $overlay['x'] : 50.0;
			$y = isset($overlay['y']) && is_numeric($overlay['y']) ? (float) $overlay['y'] : 50.0;
			$x = round(max(0.0, min(100.0, $x)), 1);
			$y = round(max(0.0, min(100.0, $y)), 1);

			// where the overlay holds on to its position: near an edge it grows away from that edge
			$ax = isset($overlay['ax']) && in_array($overlay['ax'], array('l', 'c', 'r'), true) ? $overlay['ax'] : self::anchor($x, 'l', 'c', 'r');
			$ay = isset($overlay['ay']) && in_array($overlay['ay'], array('t', 'm', 'b'), true) ? $overlay['ay'] : self::anchor($y, 't', 'm', 'b');

			$w = isset($overlay['w']) && is_numeric($overlay['w']) ? (float) $overlay['w'] : 0.0;
			$w = $w > 0 ? round(max(10.0, min(100.0, $w)), 1) : 0;

			$when = isset($overlay['when']) && in_array($overlay['when'], array('always', 'range', 'end'), true) ? $overlay['when'] : 'always';
			$start = 0.0;
			$end = 0.0;

			if($when === 'range') {
				$start = self::cleanTime(isset($overlay['start']) ? $overlay['start'] : 0, $duration);
				$end = self::cleanTime(isset($overlay['end']) ? $overlay['end'] : 0, $duration);
				if($start === null) $start = 0.0;
				if($end === null || $end <= $start) $end = 0.0; // 0: until the video ends
				if($start <= 0 && $end <= 0) $when = 'always';
			}

			$item = array(
				'id' => self::cleanId(isset($overlay['id']) ? $overlay['id'] : '', $ids),
				'text' => $text,
				'x' => $x,
				'y' => $y,
				'ax' => $ax,
				'ay' => $ay,
				'w' => $w,
				'when' => $when,
				'start' => $when === 'range' ? $start : 0.0,
				'end' => $when === 'range' ? $end : 0.0,
				'size' => isset($overlay['size']) && in_array($overlay['size'], array('s', 'm', 'l'), true) ? $overlay['size'] : 'm',
				'look' => isset($overlay['look']) && in_array($overlay['look'], array('dark', 'light'), true) ? $overlay['look'] : 'dark',
			);

			$url = self::cleanTexts(isset($overlay['url']) ? $overlay['url'] : null, $langKeys, $sanitizer, 'url');
			if(count($url)) $item['url'] = $url;

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Choose the anchor for a position: start, middle or end of the overlay
	 *
	 * @param float $percent
	 * @param string $first
	 * @param string $middle
	 * @param string $last
	 * @return string
	 *
	 */
	public static function anchor($percent, $first, $middle, $last) {
		if($percent < 34) return $first;
		if($percent > 66) return $last;
		return $middle;
	}

	/**
	 * Keep the texts of languages that the current user may not edit
	 *
	 * @param array $items Cleaned chapters or overlays as they came from the editor
	 * @param array $stored The chapters or overlays as they are stored now
	 * @param array $locked IDs of the languages that must stay as they are
	 * @param array $props Names of the properties that hold texts per language
	 * @return array
	 *
	 */
	public static function keepLockedTexts(array $items, array $stored, array $locked, array $props) {

		if(!count($locked)) return $items;

		$byId = array();
		foreach($stored as $item) {
			if(is_array($item) && isset($item['id'])) $byId[$item['id']] = $item;
		}

		foreach($items as $n => $item) {
			$old = isset($byId[$item['id']]) ? $byId[$item['id']] : array();
			foreach($props as $prop) {
				$texts = isset($item[$prop]) && is_array($item[$prop]) ? $item[$prop] : array();
				foreach($locked as $key) {
					unset($texts[$key]);
					if(isset($old[$prop][$key]) && strlen((string) $old[$prop][$key])) $texts[$key] = $old[$prop][$key];
				}
				ksort($texts);
				if(count($texts)) {
					$items[$n][$prop] = $texts;
				} else {
					unset($items[$n][$prop]);
				}
			}
		}

		return $items;
	}
}
