<?php namespace ProcessWire;

/**
 * SelfHostedVideoCaptions
 *
 * Helpers for caption files: reading WebVTT, converting SubRip (.srt) to WebVTT,
 * and the languages offered to editors.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoCaptions {

	/**
	 * Largest caption file accepted, in bytes
	 *
	 */
	const maxBytes = 2097152;

	/**
	 * Languages offered to editors: code => name in that language
	 *
	 * The name is what visitors see in the caption menu of the player.
	 *
	 * @var array
	 *
	 */
	static protected $languages = array(
		'sq' => 'Shqip',
		'ar' => 'العربية',
		'eu' => 'Euskara',
		'bs' => 'Bosanski',
		'bg' => 'Български',
		'ca' => 'Català',
		'zh' => '中文',
		'hr' => 'Hrvatski',
		'cs' => 'Čeština',
		'da' => 'Dansk',
		'nl' => 'Nederlands',
		'en' => 'English',
		'et' => 'Eesti',
		'fi' => 'Suomi',
		'fr' => 'Français',
		'gl' => 'Galego',
		'de' => 'Deutsch',
		'el' => 'Ελληνικά',
		'he' => 'עברית',
		'hi' => 'हिन्दी',
		'hu' => 'Magyar',
		'is' => 'Íslenska',
		'id' => 'Bahasa Indonesia',
		'ga' => 'Gaeilge',
		'it' => 'Italiano',
		'ja' => '日本語',
		'ko' => '한국어',
		'lv' => 'Latviešu',
		'lt' => 'Lietuvių',
		'mk' => 'Македонски',
		'nb' => 'Norsk',
		'fa' => 'فارسی',
		'pl' => 'Polski',
		'pt' => 'Português',
		'ro' => 'Română',
		'ru' => 'Русский',
		'sr' => 'Српски',
		'sk' => 'Slovenčina',
		'sl' => 'Slovenščina',
		'es' => 'Español',
		'sv' => 'Svenska',
		'th' => 'ไทย',
		'tr' => 'Türkçe',
		'uk' => 'Українська',
		'vi' => 'Tiếng Việt',
	);

	/**
	 * Get the languages offered to editors, sorted by name
	 *
	 * @return array code => name in that language
	 *
	 */
	public static function languages() {
		$languages = self::$languages;
		if(class_exists('\Collator')) {
			$collator = new \Collator('root');
			$collator->asort($languages);
		} else {
			asort($languages);
		}
		return $languages;
	}

	/**
	 * Clean a language code (BCP 47), i.e. "DE", "pt_BR" become "de", "pt-br"
	 *
	 * @param string $code
	 * @return string Blank if this is not a language code
	 *
	 */
	public static function cleanLang($code) {
		$code = strtolower(trim(str_replace('_', '-', (string) $code)));
		return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8}){0,3}$/', $code) ? $code : '';
	}

	/**
	 * Get the name of a language as shown to visitors
	 *
	 * @param string $code Clean language code
	 * @return string The name in that language, or the code in capitals when the language is not in the list
	 *
	 */
	public static function label($code) {
		if(isset(self::$languages[$code])) return self::$languages[$code];
		$primary = strtok($code, '-');
		if(isset(self::$languages[$primary])) return self::$languages[$primary] . ' (' . strtoupper(substr($code, strlen($primary) + 1)) . ')';
		return strtoupper($code);
	}

	/**
	 * Turn the content of a caption file into clean WebVTT
	 *
	 * Accepts WebVTT and SubRip (.srt). Text that is not UTF-8 is taken to be Windows-1252,
	 * which is what older subtitle tools write.
	 *
	 * @param string $text Content of the file
	 * @return array Array with keys:
	 *  - `vtt` (string): WebVTT content, blank if the text is neither format.
	 *  - `cues` (int): Number of captions.
	 *  - `end` (float): Time in seconds at which the last caption ends.
	 *  - `converted` (bool): Was the text converted from SubRip?
	 *  - `skipped` (int): Number of captions whose times cannot be read (browsers leave these out).
	 *
	 */
	public static function toVtt($text) {

		$result = array('vtt' => '', 'cues' => 0, 'end' => 0.0, 'converted' => false, 'skipped' => 0);
		$text = (string) $text;

		// byte order mark, encoding, line ends
		if(substr($text, 0, 3) === "\xEF\xBB\xBF") $text = substr($text, 3);
		if(!preg_match('//u', $text)) {
			$converted = false;
			if(function_exists('mb_convert_encoding')) {
				$converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
			} else if(function_exists('iconv')) {
				$converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
			}
			if(!is_string($converted) || !strlen($converted)) return $result;
			$text = $converted;
		}
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		$text = str_replace("\0", '', $text);
		$text = ltrim($text, "\n \t");

		if(!strlen($text)) return $result;

		$isVtt = preg_match('/^WEBVTT(?:[ \t\n]|$)/', $text) === 1;

		if(!$isVtt) {
			// SubRip writes times with a comma: 00:00:01,000 --> 00:00:04,000
			if(!preg_match('/^\d{1,3}:\d{2}:\d{2}[,.]\d{1,3}\s*-->/m', $text)) return $result;
			$text = self::srtToVtt($text);
			$result['converted'] = true;
		}

		$time = '(?:(\d{2,}):)?(\d{2}):(\d{2})\.(\d{3})';

		foreach(explode("\n", $text) as $line) {
			if(strpos($line, '-->') === false) continue;
			if(!preg_match('/^\s*' . $time . '[ \t]+-->[ \t]+' . $time . '(?:[ \t]|$)/', $line, $m)) {
				$result['skipped']++;
				continue;
			}
			$start = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3] + ((int) $m[4]) / 1000;
			$end = ((int) $m[5]) * 3600 + ((int) $m[6]) * 60 + (int) $m[7] + ((int) $m[8]) / 1000;
			if($end <= $start || (int) $m[2] > 59 || (int) $m[3] > 59 || (int) $m[6] > 59 || (int) $m[7] > 59) {
				$result['skipped']++;
				continue;
			}
			$result['cues']++;
			if($end > $result['end']) $result['end'] = $end;
		}

		if(!$result['cues']) return $result;

		$result['vtt'] = rtrim($text, "\n") . "\n";

		return $result;
	}

	/**
	 * Read the cues of a WebVTT text: when each is shown, what it says, who says it
	 *
	 * Text is returned as plain text: the tags of WebVTT (voice, class, italic, bold,
	 * underline, ruby, language, timestamps inside a cue) are taken out, its character
	 * references decoded and its lines joined by spaces.
	 *
	 * @param string $vtt WebVTT text as toVtt() makes it
	 * @return array of [ `start` (float), `end` (float), `text` (string), `voice` (string, blank if not given) ]
	 *
	 */
	public static function cues($vtt) {

		$cues = array();
		$vtt = str_replace(array("\r\n", "\r"), "\n", (string) $vtt);
		$time = '(?:(\d{2,}):)?(\d{2}):(\d{2})\.(\d{3})';

		foreach(preg_split('/\n[ \t]*\n+/', $vtt) as $block) {
			$lines = explode("\n", trim($block, "\n"));
			// an identifier may stand above the time line; header, NOTE, STYLE and REGION blocks have none
			$n = 0;
			while($n < count($lines) && strpos($lines[$n], '-->') === false) $n++;
			if($n >= count($lines) || $n > 1) continue;
			if(!preg_match('/^\s*' . $time . '[ \t]+-->[ \t]+' . $time . '/', $lines[$n], $m)) continue;
			$start = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3] + ((int) $m[4]) / 1000;
			$end = ((int) $m[5]) * 3600 + ((int) $m[6]) * 60 + (int) $m[7] + ((int) $m[8]) / 1000;
			if($end <= $start) continue;

			$raw = implode("\n", array_slice($lines, $n + 1));
			$voice = '';
			if(preg_match('/<v(?:\.[^ \t>]*)?[ \t]+([^>]+)>/', $raw, $v)) $voice = trim($v[1]);
			// tags out, line breaks become spaces
			$text = preg_replace('/<[^>]*>/', '', $raw);
			$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$text = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $text)));
			// directional marks have no meaning in a text read as a whole
			$text = trim(str_replace(array("\xE2\x80\x8E", "\xE2\x80\x8F"), '', $text));
			if(!strlen($text)) continue;

			$cues[] = array('start' => $start, 'end' => $end, 'text' => $text, 'voice' => html_entity_decode($voice, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}

		usort($cues, function($a, $b) { return $a['start'] == $b['start'] ? 0 : ($a['start'] < $b['start'] ? -1 : 1); });

		return $cues;
	}

	/**
	 * Group cues into the paragraphs of a transcript
	 *
	 * A new paragraph begins where another voice speaks, after a pause of two seconds or more,
	 * and at the end of a sentence once a paragraph has run for 20 seconds. The same text
	 * shown twice in a row (as captions that roll up repeat lines) is written once.
	 *
	 * @param array $cues As cues() returns them
	 * @return array of [ `time` (float, start of the first cue), `end` (float), `text` (string), `voice` (string) ]
	 *
	 */
	public static function paragraphs(array $cues) {

		$paragraphs = array();
		$current = null;
		$last = '';

		foreach($cues as $cue) {
			if($cue['text'] === $last) continue;
			$last = $cue['text'];
			$new = $current === null
				|| (strlen($cue['voice']) && $cue['voice'] !== $current['voice'])
				|| $cue['start'] - $current['end'] >= 2.0
				|| ($cue['start'] - $current['time'] >= 20.0 && preg_match('/[.!?\x{2026}]["\x{201D}\x{201C}\x{00BB}\x{00AB}\x{2019})]*$/u', $current['text']));
			if($new) {
				if($current !== null) $paragraphs[] = $current;
				$current = array('time' => $cue['start'], 'end' => $cue['end'], 'text' => $cue['text'], 'voice' => $cue['voice']);
			} else {
				$current['text'] .= ' ' . $cue['text'];
				if($cue['end'] > $current['end']) $current['end'] = $cue['end'];
			}
		}
		if($current !== null) $paragraphs[] = $current;

		return $paragraphs;
	}

	/**
	 * Convert SubRip text to WebVTT
	 *
	 * @param string $text With line ends already normalized
	 * @return string
	 *
	 */
	protected static function srtToVtt($text) {

		$out = array();
		$lines = explode("\n", $text);
		$count = count($lines);

		for($n = 0; $n < $count; $n++) {
			$line = $lines[$n];

			// the running number above a time line is not needed
			if(preg_match('/^\s*\d+\s*$/', $line) && isset($lines[$n + 1]) && strpos($lines[$n + 1], '-->') !== false) continue;

			if(strpos($line, '-->') !== false) {
				// 0:00:01,5 --> 00:00:04,000 becomes 00:00:01.500 --> 00:00:04.000
				$line = preg_replace_callback('/(\d{1,3}):(\d{2}):(\d{2})[,.](\d{1,3})/', function($m) {
					return sprintf('%02d:%02d:%02d.%s', (int) $m[1], (int) $m[2], (int) $m[3], str_pad($m[4], 3, '0'));
				}, $line);
				// position hints of SubRip (X1: Y1: …) mean nothing to browsers
				$line = trim(preg_replace('/\s+[XY]\d:\s*\d+/i', '', $line));
			} else {
				// styling that WebVTT does not know
				$line = preg_replace('!</?font[^>]*>!i', '', $line);
				$line = preg_replace('/\{\\\\[^}]*\}/', '', $line);
			}

			$out[] = $line;
		}

		return "WEBVTT\n\n" . ltrim(implode("\n", $out), "\n");
	}
}
