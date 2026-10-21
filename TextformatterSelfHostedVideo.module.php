<?php namespace ProcessWire;

/**
 * Videos in text
 *
 * Shows a video of the page where its text has a tag such as [[video intro.mp4]].
 * Add this text formatter to a textarea field (Details tab of the field) to switch it on
 * for that field. In the rich text editor (TinyMCE) a button then inserts the tag.
 *
 * The tag names a video by its file name. The video has to be in a video field of the
 * same page, uploaded there or reused from another page. More can follow the name:
 *
 *   [[video intro.mp4]]
 *   [[video intro.mp4 nocaption]]               without title, duration and description below the video
 *   [[video intro.mp4 notitle noduration nodescription]]   without one of them
 *   [[video intro.mp4 caption=title]]           only the title (and the duration), or "caption=description"
 *   [[video intro.mp4 noinfo]]                  without the box with what its custom fields say ("info=open": the box open)
 *   [[video intro.mp4 notranscript]]            without the transcript ("transcript=open": the transcript open)
 *   [[video intro.mp4 align=right width=40]]    beside the text: left, right or center, width in percent
 *   [[video intro.mp4 start=1:23]]              start at a moment
 *   [[video intro.mp4 autoplay muted loop]]     as the options of $video->render()
 *   [[video intro.mp4 nocontrols nochapters nooverlays]]
 *   [[video intro.mp4 captions=de]]             switch the captions of a language on
 *   [[video intro.mp4 class=wide]]              a class name for the element around the video
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class TextformatterSelfHostedVideo extends Textformatter {

	public static function getModuleInfo() {
		return array(
			'title' => __('Videos in text', __FILE__),
			'summary' => __('Shows a video of the page where its text has a tag such as [[video intro.mp4]], and adds a button for it to the rich text editor.', __FILE__),
			'version' => '1.0.0',
			'icon' => 'film',
			'requires' => 'FieldtypeSelfHostedVideo',
		);
	}

	/**
	 * Texts formatted during this request: [ key => formatted text ]
	 *
	 * @var array
	 *
	 */
	protected $formatted = array();

	/**
	 * What a tag looks like: "[[video", the name with its options, "]]"
	 *
	 */
	const tagPattern = '\[\[video(?:\s|&nbsp;|\x{00A0})+((?:(?!\]\]).)+?)\s*\]\]';

	/**
	 * Format the value of a field
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param string $value
	 *
	 */
	public function formatValue(Page $page, Field $field, &$value) {

		if(!is_string($value) || stripos($value, '[[video') === false) return;

		// ProcessWire formats a text every time it is asked for, and many templates ask for the
		// same text more than once: to cut a summary from it, and later to print it. Each time
		// gets the same result here. Otherwise only the first, which may never be printed, would
		// carry what the module adds once per page: stylesheet, script, structured data.
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$language = $this->wire()->user->language;
		$key =
			$page->id . '|' . $field->id . '|' . ($language ? $language->id : 0) . '|' .
			($fieldtype instanceof FieldtypeSelfHostedVideo ? $fieldtype->markup()->generation() : 0) . '|' . md5($value);

		if(!isset($this->formatted[$key])) {
			if(count($this->formatted) > 50) $this->formatted = array();
			$this->formatted[$key] = $this->replaceTags($value, $page);
		}

		$value = $this->formatted[$key];
	}

	/**
	 * Replace the video tags of a text with the videos they name
	 *
	 * ~~~~~
	 * echo $modules->get('TextformatterSelfHostedVideo')->replaceTags($text, $page);
	 * ~~~~~
	 *
	 * @param string $text Text or markup
	 * @param Page $page The page whose videos are meant
	 * @return string
	 *
	 */
	public function replaceTags($text, Page $page) {

		$text = (string) $text;
		if(stripos($text, '[[video') === false) return $text;

		$tag = self::tagPattern;
		$videos = array();

		// A video is a block of its own and cannot stand inside a paragraph. A paragraph with
		// a tag is therefore split: what is before the tag, the video, what is after it.
		$result = preg_replace_callback('~<p\b([^>]*)>((?:(?!</p>).)*?' . $tag . '(?:(?!</p>).)*?)</p>~isu', function($m) use($page, $tag, &$videos) {
			$attrs = $m[1];
			$inner = preg_replace_callback('~' . $tag . '~isu', function($t) use($page, &$videos) {
				$videos[] = $this->renderTag($t[1], $page);
				return "\x01" . (count($videos) - 1) . "\x01";
			}, $m[2]);
			if($inner === null) return $m[0];
			$out = '';
			foreach(preg_split('~\x01(\d+)\x01~', $inner, -1, PREG_SPLIT_DELIM_CAPTURE) as $n => $piece) {
				if($n % 2) {
					$out .= $videos[(int) $piece];
				} else if($this->hasContent($piece)) {
					$out .= "<p$attrs>" . trim($piece) . "</p>";
				}
			}
			return $out;
		}, $text);

		if($result === null) return $text; // not valid UTF-8: left as it is

		// tags anywhere else: in a list item, a table cell, or in a text without paragraphs
		$result2 = preg_replace_callback('~' . $tag . '~isu', function($t) use($page) {
			return $this->renderTag($t[1], $page);
		}, $result);

		return $result2 === null ? $result : $result2;
	}

	/**
	 * Is there something to read or see in this piece of a paragraph?
	 *
	 * @param string $html
	 * @return bool
	 *
	 */
	protected function hasContent($html) {
		if(stripos($html, '<img') !== false) return true;
		$text = str_replace(array('&nbsp;', "\xC2\xA0"), ' ', strip_tags($html));
		return strlen(trim($text)) > 0;
	}

	/**
	 * Render the video one tag asks for
	 *
	 * @param string $spec What stands between "[[video" and "]]"
	 * @param Page $page
	 * @return string Markup of the video. A comment if the page has no such video.
	 *
	 */
	protected function renderTag($spec, Page $page) {

		$tag = $this->parseTag($spec);
		$video = strlen($tag['name']) ? $this->findVideo($page, $tag['name']) : null;
		// the text shows it: the rule that keeps videos of a text out of render() does not apply here
		$markup = $video ? $video->render(array_merge($tag['options'], array('includeall' => true))) : '';

		if(!strlen($markup)) {
			$name = str_replace('--', '-', $this->wire()->sanitizer->entities($tag['name']));
			return "<!-- video “" . $name . "” not found on this page -->";
		}

		$class = 'shv-video-text';
		$style = '';
		if($tag['align']) $class .= ' shv-video-text--' . $tag['align'];
		if($tag['width']) $style = " style='--shv-width:" . $tag['width'] . "%'";

		// with a CSS framework chosen in the module settings, the element gets its class names too
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		if($fieldtype) {
			$attrs = $fieldtype->markup()->part('text', array('class' => $class), $tag['align'] ? array('text-' . $tag['align']) : array(), true);
			$class = $this->wire()->sanitizer->entities($attrs['class']);
		}

		return "<div class='$class'$style>$markup</div>";
	}

	/**
	 * Read what a tag says
	 *
	 * @param string $spec I.e. `intro.mp4 caption=title align=right width=40`
	 * @return array
	 *  - `name` (string): File name of the video.
	 *  - `options` (array): Options for Pagevideo::render().
	 *  - `align` (string): "left", "right", "center" or blank.
	 *  - `width` (int): Percent, 0 if not given.
	 *
	 */
	public function parseTag($spec) {

		// the editor may have put markup or entities into the tag
		$spec = html_entity_decode(strip_tags((string) $spec), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$spec = trim(str_replace("\xC2\xA0", ' ', $spec));
		$tokens = preg_split('/\s+/u', $spec);
		if(!is_array($tokens)) $tokens = array();

		$tag = array(
			'name' => basename(trim((string) array_shift($tokens), "\"' ")),
			'options' => array(),
			'align' => '',
			'width' => 0,
		);

		foreach($tokens as $token) {

			$value = null;
			if(strpos($token, '=') !== false) list($token, $value) = explode('=', $token, 2);
			$key = strtolower($token);
			if($value !== null) $value = trim($value, "\"' ");

			switch($key) {
				case 'caption':
					$tag['options']['caption'] = in_array($value, array('title', 'description'), true) ? $value : true;
					break;
				case 'nocaption':
					$tag['options']['caption'] = false;
					break;
				case 'notitle':
					$tag['options']['title'] = false;
					break;
				case 'noduration':
					$tag['options']['duration'] = false;
					break;
				case 'nodescription':
					$tag['options']['description'] = false;
					break;
				case 'info':
					// the box with what the custom fields of the video say: "info", or "info=open"
					$tag['options']['info'] = true;
					if($value === 'open') $tag['options']['infoOpen'] = true;
					break;
				case 'noinfo':
					$tag['options']['info'] = false;
					break;
				case 'transcript':
					// the text of the captions, in a box that opens: "transcript", or "transcript=open"
					$tag['options']['transcript'] = true;
					if($value === 'open') $tag['options']['transcriptOpen'] = true;
					break;
				case 'notranscript':
					$tag['options']['transcript'] = false;
					break;
				case 'autoplay':
				case 'muted':
				case 'loop':
					$tag['options'][$key] = $value === null || $this->isYes($value);
					break;
				case 'nocontrols':
					$tag['options']['controls'] = false;
					break;
				case 'nochapters':
					$tag['options']['chapters'] = false;
					break;
				case 'nooverlays':
					$tag['options']['overlays'] = false;
					break;
				case 'start':
					if($value !== null && preg_match('/^[0-9:.,]{1,12}$/', $value)) $tag['options']['start'] = $value;
					break;
				case 'captions':
					if($value !== null && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $value)) $tag['options']['captions'] = $value;
					break;
				case 'class':
					if($value !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,60}$/', $value)) $tag['options']['class'] = $value;
					break;
				case 'align':
					if(in_array($value, array('left', 'right', 'center'), true)) $tag['align'] = $value;
					break;
				case 'width':
					$width = (int) $value;
					if($width >= 10 && $width <= 100) $tag['width'] = $width;
					break;
			}
		}

		return $tag;
	}

	/**
	 * Does a value say yes?
	 *
	 * @param string $value
	 * @return bool
	 *
	 */
	protected function isYes($value) {
		return !in_array(strtolower((string) $value), array('0', 'no', 'false', 'off'), true);
	}

	/**
	 * Find the video a tag names
	 *
	 * Looked for in the video fields of the page. For a text inside a repeater item, the item
	 * is looked at first, then the page it belongs to.
	 *
	 * @param Page $page
	 * @param string $name File name
	 * @return Pagevideo|null
	 *
	 */
	public function findVideo(Page $page, $name) {

		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		if(!$fieldtype instanceof FieldtypeSelfHostedVideo) return null;

		$name = strtolower($name);
		$guard = 0;

		while($page && $page->id && $guard++ < 10) {
			foreach($fieldtype->getPageVideos($page) as $video) {
				if(strtolower($video->basename()) === $name) return $video;
				// a reused video goes by the file name of its original
				if($video->isReference() && strtolower((string) $video->get('ref_name')) === $name) return $video;
			}
			$page = method_exists($page, 'getForPage') ? $page->getForPage() : null;
		}

		return null;
	}
}
