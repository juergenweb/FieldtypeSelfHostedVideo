<?php namespace ProcessWire;

/**
 * Pagevideo
 *
 * Represents a single video attached to a page through a FieldtypeSelfHostedVideo field.
 * It is a Pagefile, so everything you know from file fields (url, filename, description,
 * tags, filesize, …) works here too. On top of that it knows about the video itself.
 *
 * ~~~~~
 * $video = $page->videos->first();
 * echo $video->url;         // /site/assets/files/1234/intro.mp4
 * echo $video->title;       // Title in the current language
 * echo $video->duration;    // 93.4 (seconds)
 * echo $video->durationStr; // 1:33
 * echo $video->width . 'x' . $video->height; // 1920x1080
 * if($video->hasTag('tutorial')) { … }
 * ~~~~~
 *
 * A Pagevideo is either an uploaded video (an "original") or a reference to a video that
 * lives on another page. A reference has no file of its own: its url, title, description,
 * tags and metadata all come from the original and are edited there.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 * @property float $duration Duration of the video in seconds (0 if not known).
 * @property-read string $durationStr Duration as a readable string, i.e. "1:33" or "1:02:10".
 * @property int $width Width of the video in pixels, as viewers see it (0 if not known).
 * @property int $height Height of the video in pixels, as viewers see it (0 if not known).
 * @property-read float $ratio Width divided by height, i.e. 1.78 for 16:9 (0 if not known).
 * @property string $title Title of the video in the current language.
 * @property-read Pageimage|null $thumb Thumbnail (poster image) of the video, resizable like any image. Null if there is none.
 * @property string $thumbAlt Alternative text for the thumbnail in the current language.
 * @property-read array $captions Caption tracks, indexed by language code. See captions().
 * @property-read bool $hasCaptions Does the video have captions in any language?
 * @property bool $noSpeech Has the editor marked the video as having no speech, so that it needs no captions?
 * @property-read array $checks What an editor should know about the video. See checks().
 * @property array $chapters Chapters of the video in the current language. See chapters().
 * @property array $overlays Overlays (text and links placed on the picture) in the current language. See overlays().
 * @property-read bool $isReference Is this a reference to a video on another page?
 * @property-read bool $isBroken Is this a reference whose original no longer exists?
 * @property-read Pagevideo|null $original The original video when this is a reference, otherwise null.
 * @property-read int $ref_pages_id ID of the page holding the original (0 when not a reference). #pw-internal
 * @property-read string $ref_name File name of the original (blank when not a reference). #pw-internal
 * @property Pagevideos $pagefiles The Pagevideos array that contains this video.
 *
 * @method string render(array $options = array()) Render the video for the website.
 * @method array structuredData() Structured data of the video (schema.org VideoObject).
 * @method string metaTags(array $options = array()) Open Graph tags of the video, for the head of the page.
 * @method array transcript($lang = '') The text of its captions as the paragraphs of a transcript. See transcript().
 * @method array checks() What is missing for accessibility and what about the file will trouble visitors.
 *
 */
class Pagevideo extends Pagefile {

	/**
	 * Properties that a reference takes from its original, so they cannot be set on a reference
	 *
	 * @var array
	 *
	 */
	static protected $sharedProperties = array(
		'description' => 1,
		'tags' => 1,
		'title' => 1,
		'thumbAlt' => 1,
		'noSpeech' => 1,
		'endAction' => 1,
		'endLink' => 1,
		'endText' => 1,
		'chapters' => 1,
		'overlays' => 1,
		'duration' => 1,
		'width' => 1,
		'height' => 1,
	);

	/**
	 * Cached thumbnail image and the key it was built for
	 *
	 * @var array
	 *
	 */
	protected $thumbCache = array('key' => '', 'image' => null);

	/**
	 * What the file told about itself when it was last looked at during this request
	 *
	 * @var array|null
	 *
	 */
	protected $mediaCache = null;

	/**
	 * Was this file copied into place during this request, so that its metadata is not yet known?
	 *
	 * @var bool
	 *
	 */
	protected $needsProbe = false;

	/**
	 * Has the file already been probed during this request?
	 *
	 * @var bool
	 *
	 */
	protected $probed = false;

	/**
	 * Construct
	 *
	 * @param Pagefiles $pagefiles
	 * @param string $filename
	 *
	 */
	public function __construct(Pagefiles $pagefiles, $filename) {
		parent::__construct($pagefiles, $filename);
		foreach(array('duration' => 0.0, 'width' => 0, 'height' => 0, 'ref_pages_id' => 0, 'ref_name' => '') as $key => $value) {
			if(!isset($this->data[$key])) $this->data[$key] = $value;
		}
	}

	/**
	 * Clone
	 *
	 * #pw-internal
	 *
	 */
	public function __clone() {
		$this->needsProbe = false;
		$this->thumbCache = array('key' => '', 'image' => null);
		$this->mediaCache = null;
		parent::__clone();
	}

	/**
	 * Install the file (copy it into the page’s files directory)
	 *
	 * #pw-internal
	 *
	 * @param string $filename
	 *
	 */
	protected function ___install($filename) {
		parent::___install($filename);
		$this->needsProbe = true;
		$this->probed = false;
	}

	/**
	 * Populate video properties from a database row, without tracking changes
	 *
	 * #pw-internal
	 *
	 * @param array $a
	 *
	 */
	public function wakeupVideo(array $a) {
		if(isset($a['duration'])) $this->data['duration'] = (float) $a['duration'];
		if(isset($a['width'])) $this->data['width'] = (int) $a['width'];
		if(isset($a['height'])) $this->data['height'] = (int) $a['height'];
		if(isset($a['ref_pages_id'])) $this->data['ref_pages_id'] = (int) $a['ref_pages_id'];
		if(isset($a['ref_name'])) $this->data['ref_name'] = (string) $a['ref_name'];
	}

	/*** GET AND SET ****************************************************************************/

	/**
	 * Set a property
	 *
	 * #pw-internal
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return Pagevideo|WireData
	 *
	 */
	public function set($key, $value) {

		if($this->isReference()) {
			// values shared with the original are edited on the original, not here
			$sharedKey = strpos($key, 'description') === 0 ? 'description' : $key;
			if(isset(self::$sharedProperties[$sharedKey])) return $this;
		}

		switch($key) {
			case 'duration':
				$value = round((float) $value, 3);
				return $this->setVideoProperty($key, $value > 0 ? $value : 0.0);
			case 'width':
			case 'height':
				$value = (int) $value;
				return $this->setVideoProperty($key, $value > 0 ? $value : 0);
			case 'ref_pages_id':
				return $this->setVideoProperty($key, (int) $value);
			case 'ref_name':
				return $this->setVideoProperty($key, (string) $value);
			case 'title':
				$this->title(null, $value === null ? '' : $value);
				return $this;
			case 'thumbAlt':
				$this->thumbAlt(null, $value === null ? '' : $value);
				return $this;
			case 'endText':
				$this->endText(null, $value === null ? '' : $value);
				return $this;
			case 'endAction':
				$this->endAction((string) $value);
				return $this;
			case 'endLink':
				$this->endLink((string) $value);
				return $this;
			case 'noSpeech':
				$this->noSpeech((bool) $value);
				return $this;
			case 'chapters':
				$this->setChapters(is_array($value) ? $value : array());
				return $this;
			case 'overlays':
				$this->setOverlays(is_array($value) ? $value : array());
				return $this;
		}

		return parent::set($key, $value);
	}

	/**
	 * Set one of the native video properties and track the change
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return $this
	 *
	 */
	protected function setVideoProperty($key, $value) {
		$old = isset($this->data[$key]) ? $this->data[$key] : null;
		if($old !== $value) $this->trackChange($key, $old, $value);
		$this->data[$key] = $value;
		return $this;
	}

	/**
	 * Get a property
	 *
	 * #pw-internal
	 *
	 * @param string $key
	 * @return mixed
	 *
	 */
	public function get($key) {
		switch($key) {
			case 'duration':
			case 'durationStr':
			case 'width':
			case 'height':
			case 'ratio':
			case 'title':
			case 'isReference':
			case 'isBroken':
			case 'isPortrait':
			case 'original':
			case 'thumb':
			case 'thumbAlt':
			case 'hasThumb':
			case 'captions':
			case 'hasCaptions':
			case 'noSpeech':
			case 'endAction':
			case 'endLink':
			case 'endText':
			case 'checks':
			case 'chapters':
			case 'overlays':
				return $this->$key();
			case 'ref_pages_id':
				return isset($this->data[$key]) ? (int) $this->data[$key] : 0;
			case 'ref_name':
				return isset($this->data[$key]) ? (string) $this->data[$key] : '';
		}
		return parent::get($key);
	}

	/**
	 * Hook called when a property changes
	 *
	 * #pw-internal
	 *
	 * @param string $what
	 * @param mixed $old
	 * @param mixed $new
	 *
	 */
	public function ___changed($what, $old = null, $new = null) {
		if(in_array($what, array('duration', 'width', 'height', 'ref_pages_id', 'ref_name'), true)) {
			// technical properties: the field needs saving, but the “modified” date stays as it is
			$this->pagefiles->trackChange('item');
		}
		parent::___changed($what, $old, $new);
	}

	/*** VIDEO METADATA *************************************************************************/

	/**
	 * Duration of the video in seconds
	 *
	 * @return float Seconds with up to 3 decimals, or 0 if not known
	 *
	 */
	public function duration() {
		return (float) $this->getVideoProperty('duration');
	}

	/**
	 * Duration as a readable string, i.e. "0:07", "1:33" or "1:02:10"
	 *
	 * @return string Blank string if the duration is not known
	 *
	 */
	public function durationStr() {
		$seconds = (int) round($this->duration());
		if($seconds < 1) return $this->duration() > 0 ? '0:01' : '';
		$hours = (int) floor($seconds / 3600);
		$minutes = (int) floor(($seconds % 3600) / 60);
		$seconds = $seconds % 60;
		if($hours) return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
		return sprintf('%d:%02d', $minutes, $seconds);
	}

	/**
	 * Width of the video in pixels, as viewers see it
	 *
	 * @return int 0 if not known
	 *
	 */
	public function width() {
		return (int) $this->getVideoProperty('width');
	}

	/**
	 * Height of the video in pixels, as viewers see it
	 *
	 * @return int 0 if not known
	 *
	 */
	public function height() {
		return (int) $this->getVideoProperty('height');
	}

	/**
	 * Aspect ratio: width divided by height
	 *
	 * @return float i.e. 1.78 for a 16:9 video, 0.56 for a 9:16 video, or 0 if not known
	 *
	 */
	public function ratio() {
		$width = $this->width();
		$height = $this->height();
		return $width && $height ? round($width / $height, 2) : 0.0;
	}

	/**
	 * Is this a portrait (vertical) video?
	 *
	 * @return bool False when landscape, square, or when the dimensions are not known
	 *
	 */
	public function isPortrait() {
		return $this->height() > $this->width();
	}

	/**
	 * Get one of the native video properties, from the original when this is a reference
	 *
	 * @param string $key
	 * @return int|float
	 *
	 */
	protected function getVideoProperty($key) {
		if($this->isReference()) {
			$original = $this->original();
			return $original ? $original->getVideoProperty($key) : 0;
		}
		if(empty($this->data[$key]) && $this->needsProbe) $this->probe();
		return isset($this->data[$key]) ? $this->data[$key] : 0;
	}

	/**
	 * Read duration and dimensions from the video file and populate them
	 *
	 * This works without ffmpeg for MP4, MOV and WebM files. It runs automatically for
	 * newly added videos, so there is usually no need to call it. Values that are already
	 * known are kept, unless you ask for them to be replaced.
	 *
	 * #pw-group-manipulation
	 *
	 * @param bool $replace Replace values that are already populated? (default=false)
	 * @return array|bool Info array from SelfHostedVideoProbe::probe(), or false if the file was not probed
	 *
	 */
	public function probe($replace = false) {

		if($this->isReference()) return false;
		if($this->probed && !$replace) return false;

		$this->probed = true;
		$this->needsProbe = false;

		$filename = $this->filename();
		if(!strlen($filename) || !is_file($filename)) return false;

		$info = SelfHostedVideoProbe::probe($filename);

		foreach(array('duration', 'width', 'height') as $key) {
			if(empty($info[$key])) continue;
			if($replace || empty($this->data[$key])) $this->set($key, $info[$key]);
		}

		$this->storeMediaInfo($info);

		return $info;
	}

	/**
	 * Replace the video file with another one, keeping title, description, tags and all other data
	 *
	 * Follow up with a save of the page (or `$video->save()`).
	 *
	 * #pw-internal
	 *
	 * @param string $filename File to replace the current one with
	 * @param bool $move Move the given file rather than copy it? (default=true)
	 * @return bool
	 *
	 */
	public function replaceFile($filename, $move = true) {
		if($this->isReference()) return false;
		if(!parent::replaceFile($filename, $move)) return false;
		$fieldtype = $this->videoFieldtype();
		if($fieldtype && (int) $fieldtype->get('faststart')) $this->faststart();
		// metadata belongs to the old file: forget it and read it from the new one
		foreach(array('duration' => 0.0, 'width' => 0, 'height' => 0) as $key => $value) {
			$this->set($key, $value);
		}
		$this->mediaCache = null;
		$this->filedata(false, 'unplayable');
		$this->probe(true);
		// a frame taken from the old file no longer fits: have it taken again (a custom image stays)
		$info = $this->thumbInfo();
		if($info['file'] && !$info['custom']) {
			$info['stale'] = 1;
			$this->filedata('thumb', $info);
		}
		return true;
	}

	/**
	 * Prepare the video file for streaming: move its index in front of the media data
	 *
	 * An MP4 or MOV file whose index (the box "moov") comes after the media data has to load
	 * completely before a browser can start to play it. This moves the index to the front, in
	 * place, without changing picture or sound. Files that are already prepared, WebM files and
	 * fragmented MP4 files are left as they are. A reused video is prepared through its original.
	 *
	 * With the module setting "Prepare videos for streaming" (on by default) this happens by
	 * itself for uploaded and replaced videos.
	 *
	 * ~~~~~
	 * if($video->faststart()) $page->save($video->field->name);
	 * ~~~~~
	 *
	 * #pw-group-manipulation
	 *
	 * @param string $error Set to the reason when the file could not be prepared
	 * @return bool True if the file was changed
	 *
	 */
	public function faststart(&$error = '') {
		$error = '';
		if($this->isReference()) {
			$original = $this->original();
			return $original ? $original->faststart($error) : false;
		}
		$filename = $this->filename();
		if(!strlen($filename) || !is_file($filename)) return false;
		if(!SelfHostedVideoFaststart::fix($filename, $error)) return false;
		// what the file tells about itself is read again; duration and size of the picture do not change
		$this->mediaCache = null;
		$this->storeMediaInfo();
		$this->wire()->log->save('selfhostedvideo', sprintf('Prepared for streaming: %s', $this->basename()));
		return true;
	}

	/*** TITLE **********************************************************************************/

	/**
	 * Get or set the title of the video (with multi-language support)
	 *
	 * Works like `Pagefile::description()`. Without language support installed, simply
	 * use the `$video->title` property.
	 *
	 * ~~~~~
	 * echo $video->title;                    // title in the current language
	 * $video->title = 'Welcome';             // set title in the current language
	 * $video->title('de', 'Willkommen');     // set German title
	 * echo $video->title('de');              // get German title
	 * $titles = $video->title(true);         // all titles, indexed by language name
	 * $video->title([ 'default' => 'Welcome', 'de' => 'Willkommen' ]); // set several at once
	 * ~~~~~
	 *
	 * #pw-group-common
	 * #pw-group-manipulation
	 *
	 * @param null|bool|string|int|Language|array $language
	 *  - Omit (or null) to get the title in the current language, falling back to the default language.
	 *  - Language name, ID or object to get the title in that language (blank if it has none).
	 *  - Boolean true to get the titles in all languages as an array indexed by language name.
	 *  - Array indexed by language name or ID to set several titles at once.
	 * @param null|string $value Title to set. Omit when getting.
	 * @return string|array
	 *
	 */
	public function title($language = null, $value = null) {
		return $this->langText('title', $language, $value);
	}

	/**
	 * Get or set a short multi-language text that is stored with the video
	 *
	 * This is the common implementation behind title() and other translatable texts.
	 * Values are entity encoded on output when the page’s output formatting is on.
	 *
	 * #pw-internal
	 *
	 * @param string $name Name of the text, i.e. "title"
	 * @param null|bool|string|int|Language|array $language See title() method
	 * @param null|string $value
	 * @return string|array
	 *
	 */
	public function langText($name, $language = null, $value = null) {

		$languages = $this->wire()->languages;

		// SET
		if(is_array($language) || $value !== null) {
			if($this->isReference()) return is_array($language) ? '' : (string) $value;
			$values = $this->getLangTextValues($name);
			if(is_array($language)) {
				foreach($language as $key => $text) {
					$values[$this->langTextKey($key)] = (string) $text;
				}
			} else {
				$values[$this->langTextKey($language === null ? '' : $language, true)] = (string) $value;
			}
			$this->setLangTextValues($name, $values);
			return is_array($language) ? $this->langText($name) : (string) $value;
		}

		// GET
		$source = $this->isReference() ? $this->original() : $this;
		$values = $source ? $source->getLangTextValues($name) : array();
		$default = isset($values[0]) ? $values[0] : '';

		if($language === true) {
			// all languages, indexed by language name
			$all = array('default' => $default);
			if($languages) {
				foreach($languages as $lang) {
					/** @var Language $lang */
					if($lang->isDefault()) continue;
					$all[$lang->name] = isset($values[$lang->id]) ? $values[$lang->id] : '';
				}
			}
			foreach($all as $key => $text) $all[$key] = $this->formatText($text);
			return $all;
		}

		if($language === null) {
			// current language, falling back to default
			$key = $this->langTextKey('', true);
			$text = isset($values[$key]) && strlen($values[$key]) ? $values[$key] : $default;
		} else {
			// specific language, no fallback
			$key = $this->langTextKey($language);
			$text = isset($values[$key]) ? $values[$key] : '';
		}

		return $this->formatText($text);
	}

	/**
	 * Get the stored values of a multi-language text
	 *
	 * #pw-internal
	 *
	 * @param string $name
	 * @return array Indexed by language ID, where 0 is the default language
	 *
	 */
	public function getLangTextValues($name) {
		$stored = $this->filedata($name);
		if(is_array($stored)) {
			$values = array();
			foreach($stored as $key => $text) {
				if(is_scalar($text)) $values[(int) $key] = (string) $text;
			}
			return $values;
		}
		return is_string($stored) && strlen($stored) ? array(0 => $stored) : array();
	}

	/**
	 * Store the values of a multi-language text
	 *
	 * @param string $name
	 * @param array $values Indexed by language ID, where 0 is the default language
	 *
	 */
	protected function setLangTextValues($name, array $values) {
		$sanitizer = $this->wire()->sanitizer;
		$stored = array();
		foreach($values as $key => $text) {
			$text = $sanitizer->text($text, array('maxLength' => 1024, 'stripTags' => false));
			if(strlen($text)) $stored[(int) $key] = $text;
		}
		if(!count($stored)) {
			$this->filedata(false, $name);
		} else if(count($stored) === 1 && isset($stored[0])) {
			// only a default language value: store as plain string
			$this->filedata($name, $stored[0]);
		} else {
			ksort($stored);
			$this->filedata($name, $stored);
		}
		$this->thumbCache['key'] = '';
	}

	/**
	 * Convert a language name, ID or object to the key used for storing multi-language text
	 *
	 * @param string|int|Language $language Blank string for the current user’s language
	 * @param bool $useCurrent Use the current user’s language when $language is blank?
	 * @return int Language ID, or 0 for the default language
	 *
	 */
	protected function langTextKey($language, $useCurrent = false) {
		$languages = $this->wire()->languages;
		if(!$languages) return 0;
		$field = $this->field;
		if($field && $field->get('noLang')) return 0;
		if($language === '' || $language === null) {
			if(!$useCurrent) return 0;
			$language = $this->wire()->user->language;
		} else if(!is_object($language)) {
			if($language === 'default' || $language === 0 || $language === '0') return 0;
			$language = $languages->get(ctype_digit("$language") ? (int) $language : (string) $language);
		}
		if(!$language instanceof Page || !$language->id) return 0;
		/** @var Language $language */
		return $language->isDefault() ? 0 : (int) $language->id;
	}

	/**
	 * Apply the field’s text formatters (entity encoding by default) when output formatting is on
	 *
	 * #pw-internal
	 *
	 * @param string $text
	 * @return string
	 *
	 */
	public function formatText($text) {
		$text = (string) $text;
		if(!strlen($text) || !$this->formatted()) return $text;
		$field = $this->field;
		$page = $this->page;
		$textformatters = $field ? $field->get('textformatters') : null;
		if(!is_array($textformatters)) {
			$textformatters = !$field || $field->get('entityEncode') ? array('TextformatterEntities') : array();
		}
		$modules = $this->wire()->modules;
		foreach($textformatters as $name) {
			/** @var Textformatter $textformatter */
			$textformatter = $modules->get($name);
			if(!$textformatter) continue;
			if($page && $field) {
				$textformatter->formatValue($page, $field, $text);
			} else {
				$textformatter->format($text);
			}
		}
		return $text;
	}

	/*** THUMBNAIL ******************************************************************************/

	/**
	 * Get the thumbnail (poster image) of the video
	 *
	 * The thumbnail is a regular Pageimage stored at the size it was captured or uploaded in,
	 * so all image methods work on it. Its description is the alternative text, falling back
	 * to the video title.
	 *
	 * ~~~~~
	 * if($video->thumb) {
	 *   $poster = $video->thumb->size(640, 360);
	 *   echo "<img src='$poster->url' alt='$poster->description'>";
	 * }
	 * ~~~~~
	 *
	 * #pw-group-thumbnail
	 *
	 * @return Pageimage|null Null if the video has no thumbnail
	 *
	 */
	public function thumb() {

		$source = $this->isReference() ? $this->original() : $this;
		if(!$source) return null;

		$info = $source->thumbInfo();
		if(!$info['file']) return null;

		$filename = $source->pagefiles->path() . $info['file'];
		if(!is_file($filename)) return null;

		$key = $filename . '|' . filemtime($filename) . '|' . ((int) $this->formatted()) . '|' . $source->get('modified');
		if($this->thumbCache['key'] === $key && $this->thumbCache['image']) return $this->thumbCache['image'];

		// the image lives in an array of its own, so that working with it never marks the video field as changed
		/** @var Pageimages $pageimages */
		$pageimages = $this->wire(new Pageimages($source->page));
		$pageimages->setTrackChanges(false);
		/** @var Pageimage $image */
		$image = $this->wire(new Pageimage($pageimages, $filename));
		$image->setTrackChanges(false);

		$languages = $this->wire()->languages;
		if($languages) {
			foreach($languages as $language) {
				/** @var Language $language */
				$alt = $this->thumbAlt($language);
				if(!strlen($alt)) $alt = $this->title($language);
				$image->description($language, $alt);
			}
		} else {
			$alt = $this->thumbAlt();
			$image->description = strlen($alt) ? $alt : $this->title();
		}

		$this->thumbCache = array('key' => $key, 'image' => $image);

		return $image;
	}

	/**
	 * Does the video have a thumbnail?
	 *
	 * #pw-group-thumbnail
	 *
	 * @return bool
	 *
	 */
	public function hasThumb() {
		return $this->thumb() !== null;
	}

	/**
	 * Get or set the alternative text of the thumbnail (with multi-language support)
	 *
	 * Works like title(). When no alternative text is entered, the thumbnail image
	 * uses the video title as its description.
	 *
	 * #pw-group-thumbnail
	 *
	 * @param null|bool|string|int|Language|array $language See title() method
	 * @param null|string $value Text to set. Omit when getting.
	 * @return string|array
	 *
	 */
	public function thumbAlt($language = null, $value = null) {
		return $this->langText('thumbAlt', $language, $value);
	}

	/**
	 * Get information about the stored thumbnail
	 *
	 * #pw-internal
	 *
	 * @return array Array with these keys:
	 *  - `file` (string): File name of the thumbnail, blank if there is none.
	 *  - `time` (float): Position in the video (seconds) the frame was taken from.
	 *  - `custom` (int): 1 if the editor uploaded an image, 0 if it is a frame from the video.
	 *  - `stale` (int): 1 if the frame should be taken again, because the video file was replaced.
	 *
	 */
	public function thumbInfo() {
		$source = $this->isReference() ? $this->original() : $this;
		$stored = $source ? $source->filedata('thumb') : null;
		$info = array('file' => '', 'time' => 0.0, 'custom' => 0, 'stale' => 0);
		if(!is_array($stored) || empty($stored['file'])) return $info;
		$file = basename((string) $stored['file']);
		// a thumbnail is named after its video. Details copied over from another video
		// (as happens when a file replaces another in a single file field) do not count.
		if(strpos($file, $source->thumbBasenamePrefix()) !== 0) return $info;
		$info['file'] = $file;
		if(isset($stored['time'])) $info['time'] = (float) $stored['time'];
		$info['custom'] = empty($stored['custom']) ? 0 : 1;
		$info['stale'] = empty($stored['stale']) ? 0 : 1;
		return $info;
	}

	/**
	 * Set the thumbnail from an image file
	 *
	 * The image is copied (or moved) into the page’s files and replaces any existing thumbnail.
	 * The new thumbnail has a new file name, so that browsers do not go on showing the old one.
	 * JPEG and PNG images are accepted, and WebP where the server can resize it.
	 * Follow up with a save of the page (or `$video->save()`).
	 *
	 * ~~~~~
	 * $video->setThumb('/path/to/poster.jpg', [ 'custom' => true ]);
	 * $page->save('videos');
	 * ~~~~~
	 *
	 * #pw-group-thumbnail
	 * #pw-group-manipulation
	 *
	 * @param string $filename Full path to the image file
	 * @param array $options
	 *  - `time` (float): Position in the video (seconds) the frame was taken from (default=0).
	 *  - `custom` (bool): Is this an image chosen by the editor, rather than a frame from the video? (default=false)
	 *  - `move` (bool): Move the given file rather than copy it? (default=false)
	 * @return Pageimage|bool The thumbnail on success, false if the file is not an accepted image
	 *
	 */
	public function setThumb($filename, array $options = array()) {

		if($this->isReference()) return false;

		$defaults = array('time' => 0, 'custom' => false, 'move' => false);
		$options = array_merge($defaults, $options);
		$ext = self::thumbImageType($filename);

		if(!$ext) return false;

		$files = $this->wire()->files;

		// Every new thumbnail gets a file name of its own: "intro-mp4-thumb.jpg", then "intro-mp4-thumb-v2.jpg"
		// and so on. A new picture under the address of the old one would not be seen by anybody whose
		// browser still has the old one, and the same goes for every size made from it.
		$revision = max((int) $this->filedata('thumbRev'), $this->filedata('thumb') !== null ? 1 : 0) + 1;

		$this->removeThumb();
		$this->filedata('thumbRev', $revision);

		$basename = $this->thumbBasename($ext);
		$target = $this->pagefiles->path() . $basename;

		if($options['move'] && is_writable($filename)) {
			$result = $files->rename($filename, $target);
		} else {
			$result = $files->copy($filename, $target);
		}

		if(!$result || !is_file($target)) return false;

		$files->chmod($target);

		$this->filedata('thumb', array(
			'file' => $basename,
			'time' => self::cleanSeconds($options['time']),
			'custom' => $options['custom'] ? 1 : 0,
		));

		$this->thumbCache['key'] = '';
		$thumb = $this->thumb();

		return $thumb ? $thumb : false;
	}

	/**
	 * Remove the thumbnail and all its size variations
	 *
	 * #pw-group-thumbnail
	 * #pw-group-manipulation
	 *
	 * @return bool True if there was a thumbnail to remove
	 *
	 */
	public function removeThumb() {
		if($this->isReference()) return false;
		$thumb = $this->thumb();
		if($thumb) $thumb->unlink();
		$had = $this->filedata('thumb') !== null;
		if($had) $this->filedata(false, 'thumb');
		$this->thumbCache = array('key' => '', 'image' => null);
		return $had;
	}

	/**
	 * Get the file extension to store the given image under, or blank if it is not an accepted image
	 *
	 * #pw-internal
	 *
	 * @param string $filename
	 * @return string One of "jpg", "png", "webp" or blank string
	 *
	 */
	public static function thumbImageType($filename) {
		if(!is_string($filename) || !is_file($filename)) return '';
		$info = @getimagesize($filename);
		if(!is_array($info) || $info[0] < 1 || $info[1] < 1) return '';
		if($info[2] === IMAGETYPE_JPEG) return 'jpg';
		if($info[2] === IMAGETYPE_PNG) return 'png';
		if(defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) return 'webp';
		return '';
	}

	/**
	 * Get what the file name of this video’s thumbnail starts with
	 *
	 * @return string
	 *
	 */
	protected function thumbBasenamePrefix() {
		// cleaned the same way the full file name is, so that both always agree
		$name = $this->pagefiles->cleanBasename($this->basename(false) . '-' . $this->ext() . '-thumb.x', false, false);
		return substr($name, 0, -2);
	}

	/**
	 * Get the file name for this video’s thumbnail
	 *
	 * The first thumbnail of "intro.mp4" is "intro-mp4-thumb.jpg", the next one "intro-mp4-thumb-v2.jpg"
	 * and so on, see setThumb(). Should a file of that name exist that is not this video’s thumbnail,
	 * a number is added.
	 *
	 * @param string $ext Image extension
	 * @return string
	 *
	 */
	protected function thumbBasename($ext) {
		$base = $this->thumbBasenamePrefix();
		$revision = (int) $this->filedata('thumbRev');
		if($revision > 1) $base .= "-v$revision";
		$path = $this->pagefiles->path();
		$info = $this->thumbInfo();
		$n = 1;
		do {
			$basename = $this->pagefiles->cleanBasename($base . ($n > 1 ? "-$n" : '') . ".$ext", false, false);
			$n++;
		} while(is_file($path . $basename) && $basename !== $info['file'] && $n < 100);
		return $basename;
	}

	/*** TAGS ***********************************************************************************/

	/**
	 * Get labels for this video’s tags, for display to visitors
	 *
	 * Labels for predefined tags are entered in the field settings and can be translated.
	 * A tag without a label is returned as its own label.
	 *
	 * ~~~~~
	 * foreach($video->tagLabels() as $tag => $label) {
	 *   echo "<a href='/videos/$tag/'>$label</a>";
	 * }
	 * ~~~~~
	 *
	 * #pw-group-tags
	 *
	 * @param string|int|Language|null $language Language to get labels for, or omit for the current language
	 * @return array Labels indexed by tag
	 *
	 */
	public function tagLabels($language = null) {
		$source = $this->isReference() ? $this->original() : $this;
		$labels = array();
		if(!$source) return $labels;
		$field = $source->field;
		$fieldtype = $field ? $field->type : null;
		$fieldLabels = $fieldtype instanceof FieldtypeSelfHostedVideo ? $fieldtype->getTagLabels($field, $language) : array();
		$tags = $source->tags(true);
		if(!is_array($tags)) return $labels;
		foreach($tags as $key => $tag) {
			$labels[$tag] = $this->formatText(isset($fieldLabels[$key]) ? $fieldLabels[$key] : $tag);
		}
		return $labels;
	}

	/*** CAPTIONS *******************************************************************************/

	/**
	 * Get the caption tracks of the video
	 *
	 * Captions are WebVTT files, one per language, stored next to the video and named after it.
	 * render() adds them to the player.
	 *
	 * ~~~~~
	 * foreach($video->captions() as $lang => $track) {
	 *   echo "<a href='$track->url'>$track->label</a>"; // i.e. Deutsch
	 * }
	 * ~~~~~
	 *
	 * #pw-group-captions
	 *
	 * @return array of WireData indexed by language code, each with:
	 *  - `lang` (string): Language code, i.e. "de".
	 *  - `label` (string): Name shown in the caption menu of players, i.e. "Deutsch". Not entity encoded.
	 *  - `kind` (string): "captions" (speech and sounds, for people who cannot hear) or "subtitles" (a translation).
	 *  - `file` (string): File name, i.e. "intro-mp4-captions-de.vtt".
	 *  - `filename` (string): Full disk path.
	 *  - `url` (string): URL of the file.
	 *  - `URL` (string): URL that changes whenever the file does.
	 *  - `cues` (int): Number of captions in the file.
	 *  - `end` (float): Time in seconds at which the last caption ends.
	 *
	 */
	public function captions() {

		$source = $this->isReference() ? $this->original() : $this;
		$tracks = array();
		if(!$source) return $tracks;

		$stored = $source->filedata('captions');
		if(!is_array($stored)) return $tracks;

		$path = $source->pagefiles->path();
		$url = $source->pagefiles->url();
		$prefix = $source->captionsBasename('');

		foreach($stored as $lang => $data) {
			$lang = SelfHostedVideoCaptions::cleanLang($lang);
			$file = is_array($data) && isset($data['file']) ? basename((string) $data['file']) : '';
			// a captions file is named after its video. Details copied over from another video do not count.
			if(!$lang || !strlen($file) || strpos($file, $prefix) !== 0 || !is_file($path . $file)) continue;
			/** @var WireData $track */
			$track = $this->wire(new WireData());
			$track->setArray(array(
				'lang' => $lang,
				'label' => isset($data['label']) && strlen((string) $data['label']) ? (string) $data['label'] : SelfHostedVideoCaptions::label($lang),
				'kind' => isset($data['kind']) && $data['kind'] === 'subtitles' ? 'subtitles' : 'captions',
				'file' => $file,
				'filename' => $path . $file,
				'url' => $url . $file,
				'URL' => $url . $file . '?nc=' . (int) @filemtime($path . $file),
				'cues' => isset($data['cues']) ? (int) $data['cues'] : 0,
				'end' => isset($data['end']) ? (float) $data['end'] : 0.0,
			));
			$tracks[$lang] = $track;
		}

		return $tracks;
	}

	/**
	 * Does the video have captions, in any language or in the given one?
	 *
	 * #pw-group-captions
	 *
	 * @param string $lang Optional language code
	 * @return bool
	 *
	 */
	public function hasCaptions($lang = '') {
		$tracks = $this->captions();
		if(!strlen((string) $lang)) return count($tracks) > 0;
		return isset($tracks[SelfHostedVideoCaptions::cleanLang($lang)]);
	}

	/**
	 * Set the captions for a language from a WebVTT or SubRip (.srt) file
	 *
	 * The file is read, converted to WebVTT where needed, and stored next to the video.
	 * Captions the video already has in that language are replaced. Follow up with a save
	 * of the page (or `$video->save()`).
	 *
	 * ~~~~~
	 * $video->setCaptions('de', '/path/to/intro.de.vtt');
	 * $video->setCaptions('en', '/path/to/intro.en.srt', [ 'kind' => 'subtitles' ]);
	 * $page->save('videos');
	 * ~~~~~
	 *
	 * #pw-group-captions
	 *
	 * @param string $lang Language code, i.e. "de" or "pt-BR"
	 * @param string $filename Full path of a .vtt or .srt file
	 * @param array $options
	 *  - `label` (string): Name shown in the caption menu of players. (default=name of the language in that language)
	 *  - `kind` (string): "captions" or "subtitles". (default="captions")
	 * @return WireData The track, as in captions(), with `converted` (was it SubRip?) and `skipped`
	 *   (captions with unreadable times) added
	 * @throws WireException When the language code or the file is not usable
	 *
	 */
	public function setCaptions($lang, $filename, array $options = array()) {

		if($this->isReference()) throw new WireException($this->_('Captions of a reused video are edited on the page it comes from.'));

		$code = SelfHostedVideoCaptions::cleanLang($lang);
		if(!$code) throw new WireException($this->_('Please choose the language of the captions.'));

		if(!is_file($filename) || !is_readable($filename)) throw new WireException($this->_('The captions file could not be read.'));
		if(filesize($filename) > SelfHostedVideoCaptions::maxBytes) throw new WireException($this->_('The captions file is too large.'));

		$result = SelfHostedVideoCaptions::toVtt((string) file_get_contents($filename));

		if(!strlen($result['vtt'])) {
			throw new WireException($this->_('This is not a captions file. Captions come as WebVTT (.vtt) or SubRip (.srt) files with at least one caption.'));
		}

		$files = $this->wire()->files;
		$sanitizer = $this->wire()->sanitizer;
		$stored = $this->filedata('captions');
		if(!is_array($stored)) $stored = array();

		$basename = $this->captionsBasename($code);
		$target = $this->pagefiles->path() . $basename;

		// a file left from an earlier name of the video
		if(isset($stored[$code]['file']) && $stored[$code]['file'] !== $basename) {
			$old = $this->pagefiles->path() . basename((string) $stored[$code]['file']);
			if(is_file($old) && strpos(basename($old), $this->captionsBasename('')) === 0) $files->unlink($old);
		}

		if(file_put_contents($target, $result['vtt'], LOCK_EX) === false) {
			throw new WireException($this->_('The captions file could not be stored.'));
		}
		$files->chmod($target);

		$label = isset($options['label']) ? trim($sanitizer->text((string) $options['label'], array('maxLength' => 60, 'stripTags' => true))) : '';
		$data = array(
			'file' => $basename,
			'kind' => isset($options['kind']) && $options['kind'] === 'subtitles' ? 'subtitles' : 'captions',
			'cues' => $result['cues'],
			'end' => round($result['end'], 3),
		);
		if(strlen($label) && $label !== SelfHostedVideoCaptions::label($code)) $data['label'] = $label;

		$stored[$code] = $data;
		ksort($stored);
		$this->filedata('captions', $stored);

		$tracks = $this->captions();
		$track = $tracks[$code];
		$track->set('converted', $result['converted']);
		$track->set('skipped', $result['skipped']);

		return $track;
	}

	/**
	 * Remove the captions of a language, or all captions
	 *
	 * #pw-group-captions
	 *
	 * @param string $lang Language code, or omit to remove the captions in all languages
	 * @return int Number of caption tracks removed
	 *
	 */
	public function removeCaptions($lang = '') {

		if($this->isReference()) return 0;

		$stored = $this->filedata('captions');
		if(!is_array($stored) || !count($stored)) return 0;

		$code = strlen((string) $lang) ? SelfHostedVideoCaptions::cleanLang($lang) : '';
		$files = $this->wire()->files;
		$path = $this->pagefiles->path();
		$prefix = $this->captionsBasename('');
		$n = 0;

		foreach($stored as $key => $data) {
			if(strlen((string) $lang) && $key !== $code) continue;
			$file = is_array($data) && isset($data['file']) ? basename((string) $data['file']) : '';
			if(strlen($file) && strpos($file, $prefix) === 0 && is_file($path . $file)) $files->unlink($path . $file);
			unset($stored[$key]);
			$n++;
		}

		if($n) {
			if(count($stored)) {
				$this->filedata('captions', $stored);
			} else {
				$this->filedata(false, 'captions');
			}
		}

		return $n;
	}

	/**
	 * Get the file name for the captions of a language
	 *
	 * #pw-internal
	 *
	 * @param string $lang Clean language code, or blank to get the part all caption files of this video start with
	 * @return string i.e. "intro-mp4-captions-de.vtt"
	 *
	 */
	public function captionsBasename($lang) {
		$name = $this->pagefiles->cleanBasename($this->basename(false) . '-' . $this->ext() . '-captions-' . (strlen($lang) ? $lang : 'x') . '.vtt', false, false);
		return strlen($lang) ? $name : substr($name, 0, -5);
	}

	/**
	 * Get or set whether the video has no speech (or other sound that matters), so that it needs no captions
	 *
	 * This is what an editor states about the video. It only affects checks().
	 *
	 * #pw-group-captions
	 *
	 * @param bool|null $value Omit to get, true or false to set
	 * @return bool
	 *
	 */
	public function noSpeech($value = null) {
		if($value !== null && !$this->isReference()) {
			if($value) {
				$this->filedata('noSpeech', 1);
			} else if($this->filedata('noSpeech') !== null) {
				$this->filedata(false, 'noSpeech');
			}
			return (bool) $value;
		}
		$source = $this->isReference() ? $this->original() : $this;
		return $source ? (bool) $source->filedata('noSpeech') : false;
	}

	/**
	 * A position in a video in seconds, as it may be stored: a finite number from 0 to 10 days
	 *
	 * #pw-internal
	 *
	 * @param mixed $value
	 * @return float
	 *
	 */
	public static function cleanSeconds($value) {
		$value = is_numeric($value) ? (float) $value : 0.0;
		if(!is_finite($value) || $value < 0) return 0.0;
		return round(min($value, 864000.0), 3);
	}

	/**
	 * Replace numbers that cannot be stored (infinite, not a number) in data of a video
	 *
	 * The data of a video is stored as JSON, which has no such numbers: one of them would lose all of it.
	 *
	 * #pw-internal
	 *
	 * @param mixed $data
	 * @return mixed
	 *
	 */
	public static function storableData($data) {
		if(is_float($data)) return is_finite($data) ? $data : 0.0;
		if(!is_array($data)) return $data;
		foreach($data as $key => $value) {
			if(is_float($value) || is_array($value)) $data[$key] = self::storableData($value);
		}
		return $data;
	}

	/*** EMBEDDING ON OTHER WEBSITES ***********************************************************/

	/**
	 * Get or set what the player offers when the video has ended
	 *
	 * - "" nothing: the last picture stays
	 * - "replay" a button to watch it again
	 * - "next" the next video of the same field (if the page shows it), and to watch again
	 * - "link" a button to an address (see endLink() and endText()), and to watch again
	 *
	 * ~~~~~
	 * $video->endAction('link');
	 * $video->endLink('/contact/');
	 * $video->endText = 'Ask us'; // or $video->endText($language, 'Fragen Sie uns')
	 * $page->save('videos');
	 * ~~~~~
	 *
	 * #pw-group-common
	 *
	 * @param string|null $value Omit to get
	 * @return string
	 *
	 */
	public function endAction($value = null) {
		$actions = array('replay', 'next', 'link');
		if($value !== null && !$this->isReference()) {
			$value = in_array($value, $actions, true) ? $value : '';
			if($value === '') {
				if($this->filedata('endAction') !== null) $this->filedata(false, 'endAction');
			} else {
				$this->filedata('endAction', $value);
			}
			return $value;
		}
		$source = $this->isReference() ? $this->original() : $this;
		$value = $source ? $source->filedata('endAction') : null;
		return in_array($value, $actions, true) ? $value : '';
	}

	/**
	 * Get or set the address of the button shown when the video has ended (end action "link")
	 *
	 * An address of this site (`/contact/`), a full address (`https://…`), an email address
	 * (`mailto:…`) or a phone number (`tel:…`). Anything else is not kept.
	 *
	 * #pw-group-common
	 *
	 * @param string|null $value Omit to get
	 * @return string
	 *
	 */
	public function endLink($value = null) {
		if($value !== null && !$this->isReference()) {
			$value = self::cleanLink($value);
			if($value === '') {
				if($this->filedata('endLink') !== null) $this->filedata(false, 'endLink');
			} else {
				$this->filedata('endLink', $value);
			}
			return $value;
		}
		$source = $this->isReference() ? $this->original() : $this;
		return $source ? self::cleanLink($source->filedata('endLink')) : '';
	}

	/**
	 * A link address as it may be kept: of this site, http(s), mailto or tel
	 *
	 * #pw-internal
	 *
	 * @param mixed $value
	 * @return string Blank if it is none of them
	 *
	 */
	public static function cleanLink($value) {
		$value = trim(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string) $value));
		if($value === '' || strlen($value) > 2048) return '';
		if(preg_match('!^(https?://|mailto:|tel:)!i', $value)) return str_replace(' ', '%20', $value);
		if(preg_match('!^[/#?]!', $value) && strpos($value, '//') !== 0) return str_replace(' ', '%20', $value);
		return '';
	}

	/**
	 * Get or set the text of the button shown when the video has ended (end action "link")
	 *
	 * Works as title() does, with a text per language.
	 *
	 * #pw-group-common
	 *
	 * @param null|Language|string|int|bool|array $language
	 * @param string|null $value
	 * @return string|array
	 *
	 */
	public function endText($language = null, $value = null) {
		return $this->langText('endText', $language, $value);
	}

	/**
	 * Get or set whether other websites may embed this video, where it differs from the site setting
	 *
	 * ~~~~~
	 * $video->embed('block'); // only this website may show it
	 * $video->embed('');      // as set for the site
	 * $page->save('videos');
	 * ~~~~~
	 *
	 * #pw-group-common
	 *
	 * @param string|null $value Omit to get; "allow", "block" or "" (as set for the site) to set
	 * @return string "allow", "block" or "" (as set for the site)
	 *
	 */
	public function embed($value = null) {
		if($value !== null && !$this->isReference()) {
			$value = in_array($value, array('allow', 'block'), true) ? $value : '';
			if($value === '') {
				if($this->filedata('embed') !== null) $this->filedata(false, 'embed');
			} else {
				$this->filedata('embed', $value);
			}
			return $value;
		}
		$source = $this->isReference() ? $this->original() : $this;
		$value = $source ? $source->filedata('embed') : null;
		return in_array($value, array('allow', 'block'), true) ? $value : '';
	}

	/**
	 * Is embedding of this video on other websites blocked, after its own setting and that of the site?
	 *
	 * #pw-group-common
	 *
	 * @return bool
	 *
	 */
	public function embedBlocked() {
		$source = $this->isReference() ? $this->original() : $this;
		if(!$source) return false;
		$fieldtype = $source->field ? $source->field->type : $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		return $fieldtype instanceof FieldtypeSelfHostedVideo ? $fieldtype->embedRules()->isBlocked($source) : false;
	}

	/**
	 * Get patterns (regular expressions) that match the names of all files of this video
	 *
	 * The video file, its thumbnail in every size and every version, and its captions.
	 *
	 * #pw-internal
	 *
	 * @return array
	 *
	 */
	public function filePatterns() {
		$captions = $this->pagefiles->cleanBasename($this->basename(false) . '-' . $this->ext() . '-captions-x.vtt', false, false);
		return array(
			preg_quote($this->basename(), '#'),
			preg_quote($this->thumbBasenamePrefix(), '#') . '[^/]*',
			preg_quote(substr($captions, 0, -5), '#') . '[^/]*',
		);
	}

	/*** CHAPTERS AND OVERLAYS ******************************************************************/

	/**
	 * Get the chapters of the video
	 *
	 * A chapter is a titled moment of the video. Titles are kept per language.
	 *
	 * ~~~~~
	 * foreach($video->chapters() as $chapter) {
	 *   echo "<li>$chapter->timeStr $chapter->title</li>"; // 1:23 Setting up
	 * }
	 * ~~~~~
	 *
	 * #pw-group-timeline
	 *
	 * @param string|int|Language|null $language Language for the titles, or omit for the current language.
	 *   A chapter without a title in that language has the one of the default language.
	 * @return array of WireData, sorted by time, each with:
	 *  - `id` (string): Identifier of the chapter.
	 *  - `time` (float): Where the chapter starts, in seconds.
	 *  - `timeStr` (string): The same as a time, i.e. "1:23".
	 *  - `end` (float): Where it ends: the start of the next chapter, or the end of the video (0 if that is not known).
	 *  - `title` (string): Title, entity encoded when the page's output formatting is on.
	 *
	 */
	public function chapters($language = null) {

		$source = $this->isReference() ? $this->original() : $this;
		$out = array();
		if(!$source) return $out;

		$stored = $source->filedata('chapters');
		if(!is_array($stored)) return $out;

		$key = $this->langTextKey($language === null ? '' : $language, $language === null);
		$duration = (float) $source->duration();
		$stored = array_values($stored);

		foreach($stored as $n => $data) {
			if(!is_array($data) || !isset($data['time'])) continue;
			$next = isset($stored[$n + 1]['time']) ? (float) $stored[$n + 1]['time'] : $duration;
			/** @var WireData $chapter */
			$chapter = $this->wire(new WireData());
			$chapter->setArray(array(
				'id' => isset($data['id']) ? (string) $data['id'] : '',
				'time' => (float) $data['time'],
				'timeStr' => SelfHostedVideoTimeline::formatTime($data['time']),
				'end' => $next > (float) $data['time'] ? $next : 0.0,
				'title' => $this->formatText(self::pickLangText(isset($data['title']) ? $data['title'] : '', $key)),
			));
			$out[] = $chapter;
		}

		return $out;
	}

	/**
	 * Set the chapters of the video, replacing those it has
	 *
	 * Follow up with a save of the page (or `$video->save()`).
	 *
	 * ~~~~~
	 * $video->setChapters([
	 *   [ 'time' => 0, 'title' => 'Welcome' ],
	 *   [ 'time' => '1:23', 'title' => [ 'default' => 'Setting up', 'de' => 'Einrichten' ] ],
	 * ]);
	 * $page->save('videos');
	 * ~~~~~
	 *
	 * #pw-group-timeline
	 *
	 * @param array $chapters Each an array with `time` (seconds, or a time such as "1:23") and `title`
	 *   (a string for the current language, or an array indexed by language name or ID).
	 *   Chapters without a title, and second chapters at the same time, are left out.
	 * @return array The chapters as stored
	 *
	 */
	public function setChapters(array $chapters) {
		if($this->isReference()) return array();
		foreach($chapters as $n => $chapter) {
			if(is_array($chapter) && isset($chapter['title'])) $chapters[$n]['title'] = $this->langTextInput($chapter['title']);
		}
		$clean = SelfHostedVideoTimeline::cleanChapters($chapters, (float) $this->duration(), $this->langKeys(), $this->wire()->sanitizer);
		$this->storeTimeline('chapters', $clean);
		return $clean;
	}

	/**
	 * Get the overlays of the video: text and links placed on the picture
	 *
	 * ~~~~~
	 * foreach($video->overlays() as $overlay) {
	 *   echo "$overlay->text at $overlay->x% / $overlay->y%";
	 * }
	 * ~~~~~
	 *
	 * #pw-group-timeline
	 *
	 * @param string|int|Language|null $language Language for text and link, or omit for the current language.
	 *   What is missing in that language is taken from the default language.
	 * @return array of WireData, each with:
	 *  - `id` (string): Identifier of the overlay.
	 *  - `text` (string): Text, entity encoded when the page's output formatting is on. May have line breaks.
	 *  - `url` (string): Address the overlay links to, blank if it is no link.
	 *  - `x`, `y` (float): Position in percent of the picture, from the left and from the top.
	 *  - `anchorX` (string): Which part of the overlay sits at `x`: "left", "center" or "right".
	 *  - `anchorY` (string): Which part of the overlay sits at `y`: "top", "middle" or "bottom".
	 *  - `width` (float): Width in percent of the picture, 0 for as wide as the text.
	 *  - `when` (string): "always", "range" (from `start` to `end`) or "end" (once the video has ended).
	 *  - `start`, `end` (float): Seconds. An `end` of 0 means until the video ends.
	 *  - `size` (string): Size of the text: "s", "m" or "l".
	 *  - `look` (string): "dark" (light text on a dark box) or "light" (dark text on a light box).
	 *
	 */
	public function overlays($language = null) {

		$source = $this->isReference() ? $this->original() : $this;
		$out = array();
		if(!$source) return $out;

		$stored = $source->filedata('overlays');
		if(!is_array($stored)) return $out;

		$key = $this->langTextKey($language === null ? '' : $language, $language === null);
		$anchorsX = array('l' => 'left', 'c' => 'center', 'r' => 'right');
		$anchorsY = array('t' => 'top', 'm' => 'middle', 'b' => 'bottom');

		foreach($stored as $data) {
			if(!is_array($data) || !isset($data['text'])) continue;
			$ax = isset($data['ax']) && isset($anchorsX[$data['ax']]) ? $data['ax'] : 'c';
			$ay = isset($data['ay']) && isset($anchorsY[$data['ay']]) ? $data['ay'] : 'm';
			$url = self::pickLangText(isset($data['url']) ? $data['url'] : '', $key);
			/** @var WireData $overlay */
			$overlay = $this->wire(new WireData());
			$overlay->setArray(array(
				'id' => isset($data['id']) ? (string) $data['id'] : '',
				'text' => $this->formatText(self::pickLangText($data['text'], $key)),
				'url' => $this->formatted() ? $this->wire()->sanitizer->entities($url) : $url,
				'x' => isset($data['x']) ? (float) $data['x'] : 50.0,
				'y' => isset($data['y']) ? (float) $data['y'] : 50.0,
				'anchorX' => $anchorsX[$ax],
				'anchorY' => $anchorsY[$ay],
				'width' => isset($data['w']) ? (float) $data['w'] : 0.0,
				'when' => isset($data['when']) ? (string) $data['when'] : 'always',
				'start' => isset($data['start']) ? (float) $data['start'] : 0.0,
				'end' => isset($data['end']) ? (float) $data['end'] : 0.0,
				'size' => isset($data['size']) ? (string) $data['size'] : 'm',
				'look' => isset($data['look']) ? (string) $data['look'] : 'dark',
			));
			$out[] = $overlay;
		}

		return $out;
	}

	/**
	 * Set the overlays of the video, replacing those it has
	 *
	 * Follow up with a save of the page (or `$video->save()`).
	 *
	 * ~~~~~
	 * $video->setOverlays([
	 *   [ 'text' => 'New: workshop dates', 'url' => '/workshops/', 'x' => 95, 'y' => 8 ],
	 *   [ 'text' => [ 'default' => 'Watch again?', 'de' => 'Noch einmal ansehen?' ], 'when' => 'end' ],
	 *   [ 'text' => 'Mind the gap', 'when' => 'range', 'start' => '0:12', 'end' => '0:20', 'x' => 50, 'y' => 85, 'look' => 'light' ],
	 * ]);
	 * $page->save('videos');
	 * ~~~~~
	 *
	 * #pw-group-timeline
	 *
	 * @param array $overlays Each an array with `text` and optionally `url` (a string for the current language, or an
	 *   array indexed by language name or ID), `x`, `y` (percent, default 50), `width` (percent, 0 for as wide as the text),
	 *   `when` ("always", "range" or "end"), `start`, `end` (seconds or times), `size` ("s", "m", "l"), `look` ("dark", "light").
	 *   Overlays without text are left out.
	 * @return array The overlays as stored
	 *
	 */
	public function setOverlays(array $overlays) {
		if($this->isReference()) return array();
		$anchorsX = array('left' => 'l', 'center' => 'c', 'right' => 'r');
		$anchorsY = array('top' => 't', 'middle' => 'm', 'bottom' => 'b');
		foreach($overlays as $n => $overlay) {
			if(!is_array($overlay)) continue;
			foreach(array('text', 'url') as $prop) {
				if(isset($overlay[$prop])) $overlays[$n][$prop] = $this->langTextInput($overlay[$prop]);
			}
			if(isset($overlay['width']) && !isset($overlay['w'])) $overlays[$n]['w'] = $overlay['width'];
			if(isset($overlay['anchorX'], $anchorsX[$overlay['anchorX']])) $overlays[$n]['ax'] = $anchorsX[$overlay['anchorX']];
			if(isset($overlay['anchorY'], $anchorsY[$overlay['anchorY']])) $overlays[$n]['ay'] = $anchorsY[$overlay['anchorY']];
		}
		$clean = SelfHostedVideoTimeline::cleanOverlays($overlays, (float) $this->duration(), $this->langKeys(), $this->wire()->sanitizer);
		$this->storeTimeline('overlays', $clean);
		return $clean;
	}

	/**
	 * Get chapters and overlays as they are stored, with their texts in all languages
	 *
	 * #pw-internal
	 *
	 * @return array Array with keys `chapters` and `overlays`
	 *
	 */
	public function timelineData() {
		$source = $this->isReference() ? $this->original() : $this;
		$chapters = $source ? $source->filedata('chapters') : null;
		$overlays = $source ? $source->filedata('overlays') : null;
		return array(
			'chapters' => is_array($chapters) ? array_values($chapters) : array(),
			'overlays' => is_array($overlays) ? array_values($overlays) : array(),
		);
	}

	/**
	 * Store chapters or overlays that are already in their stored form
	 *
	 * #pw-internal
	 *
	 * @param string $name Either "chapters" or "overlays"
	 * @param array $items
	 * @return bool True if anything changed
	 *
	 */
	public function storeTimeline($name, array $items) {
		if($this->isReference() || ($name !== 'chapters' && $name !== 'overlays')) return false;
		$stored = $this->filedata($name);
		if(!count($items)) {
			if($stored === null) return false;
			$this->filedata(false, $name);
			return true;
		}
		// compared as JSON, which is how they are stored
		if(is_array($stored) && json_encode(array_values($stored)) === json_encode($items)) return false;
		$this->filedata($name, $items);
		return true;
	}

	/**
	 * Get the IDs of the languages that texts of this video can be in
	 *
	 * #pw-internal
	 *
	 * @return array Language IDs, where 0 is the default language
	 *
	 */
	public function langKeys() {
		$keys = array(0);
		$languages = $this->wire()->languages;
		$field = $this->field;
		if(!$languages || ($field && $field->get('noLang'))) return $keys;
		foreach($languages as $language) {
			/** @var Language $language */
			if(!$language->isDefault()) $keys[] = (int) $language->id;
		}
		return $keys;
	}

	/**
	 * Convert a text given to the API to texts per language ID
	 *
	 * @param string|array $value A string for the current language, or an array indexed by language name or ID
	 * @return array Indexed by language ID, where 0 is the default language
	 *
	 */
	protected function langTextInput($value) {
		if(!is_array($value)) return array($this->langTextKey('', true) => (string) $value);
		$out = array();
		$languages = $this->wire()->languages;
		$field = $this->field;
		if($field && $field->get('noLang')) $languages = null;
		foreach($value as $key => $text) {
			if(!is_scalar($text)) continue;
			if($key === 'default' || $key === 0 || $key === '0') {
				$out[0] = (string) $text;
				continue;
			}
			// a language this site does not have is left out, rather than taken for the default language
			$language = $languages ? $languages->get(is_int($key) || ctype_digit("$key") ? (int) $key : (string) $key) : null;
			if(!$language instanceof Page || !$language->id) continue;
			/** @var Language $language */
			$out[$language->isDefault() ? 0 : (int) $language->id] = (string) $text;
		}
		return $out;
	}

	/**
	 * Pick the text for a language from texts per language, falling back to the default language
	 *
	 * @param array|string $texts
	 * @param int $key Language ID, 0 for the default language
	 * @return string
	 *
	 */
	protected static function pickLangText($texts, $key) {
		if(is_string($texts)) return $texts;
		if(!is_array($texts)) return '';
		if(isset($texts[$key]) && strlen((string) $texts[$key])) return (string) $texts[$key];
		return isset($texts[0]) ? (string) $texts[0] : '';
	}

	/*** CHECKS *********************************************************************************/

	/**
	 * Get what the video file tells about itself
	 *
	 * Read from the file when the video is uploaded or replaced and stored with it.
	 * For videos from before that was stored, the file is looked at when asked.
	 *
	 * #pw-group-checks
	 *
	 * @return array Array with keys:
	 *  - `known` (bool): Could the file be read at all?
	 *  - `format` (string): Container: "mp4", "webm", "matroska" or blank.
	 *  - `codec` (string): Identifier of the picture format, i.e. "avc1" (H.264), "hvc1" (H.265), "vp09", "av01".
	 *  - `hasVideo` (bool|null): Is there a picture? Null if not known.
	 *  - `hasAudio` (bool|null): Is there sound? Null if not known.
	 *  - `faststart` (bool|null): MP4: can it start playing while it loads? Null if not known or not an MP4.
	 *  - `ext` (string): File extension.
	 *  - `filesize` (int): Size in bytes.
	 *  - `bitrate` (int): Bits per second over the whole file, 0 if the duration is not known.
	 *
	 */
	public function mediaInfo() {

		$source = $this->isReference() ? $this->original() : $this;

		$info = array(
			'known' => false, 'format' => '', 'codec' => '', 'hasVideo' => null, 'hasAudio' => null, 'faststart' => null,
			'ext' => '', 'filesize' => 0, 'bitrate' => 0,
		);

		if(!$source) return $info;

		$stored = $source->filedata('media');

		if(!is_array($stored)) {
			if($source->mediaCache === null) {
				$filename = $source->filename();
				$source->mediaCache = strlen($filename) && is_file($filename) ? self::mediaInfoFromProbe(SelfHostedVideoProbe::probe($filename)) : array();
			}
			$stored = $source->mediaCache;
		}

		$bool = function($key) use($stored) {
			return isset($stored[$key]) && $stored[$key] !== null ? (bool) $stored[$key] : null;
		};

		$info['format'] = isset($stored['format']) ? (string) $stored['format'] : '';
		$info['codec'] = isset($stored['codec']) ? (string) $stored['codec'] : '';
		$info['hasVideo'] = $bool('video');
		$info['hasAudio'] = $bool('audio');
		$info['faststart'] = $bool('faststart');
		$info['known'] = strlen($info['format']) > 0;
		$info['ext'] = strtolower($source->ext());
		$info['filesize'] = (int) $source->filesize();

		$duration = (float) $source->duration();
		if($duration > 0 && $info['filesize'] > 0) $info['bitrate'] = (int) round($info['filesize'] * 8 / $duration);

		if(!$info['known']) {
			// nothing can be said about a file that could not be read
			$info['hasVideo'] = $info['hasAudio'] = $info['faststart'] = null;
		}

		return $info;
	}

	/**
	 * Reduce what the file probe reports to what is stored with the video
	 *
	 * @param array $probe See SelfHostedVideoProbe::probe()
	 * @return array
	 *
	 */
	protected static function mediaInfoFromProbe(array $probe) {
		$media = array(
			'format' => (string) $probe['format'],
			'codec' => (string) $probe['videoCodec'],
			'video' => $probe['hasVideo'] ? 1 : 0,
			'audio' => $probe['hasAudio'] ? 1 : 0,
		);
		if($probe['faststart'] !== null) $media['faststart'] = $probe['faststart'] ? 1 : 0;
		return $media;
	}

	/**
	 * Store what the file tells about itself with the video
	 *
	 * #pw-internal
	 *
	 * @param array|null $probe Result of SelfHostedVideoProbe::probe(), or omit to look at the file now
	 *
	 */
	public function storeMediaInfo($probe = null) {
		if($this->isReference()) return;
		if($probe === null) {
			$filename = $this->filename();
			if(!strlen($filename) || !is_file($filename)) return;
			$probe = SelfHostedVideoProbe::probe($filename);
		}
		$media = self::mediaInfoFromProbe($probe);
		$this->mediaCache = $media;
		if($this->filedata('media') !== $media) $this->filedata('media', $media);
	}

	/**
	 * Get what an editor should know about the video
	 *
	 * Two kinds of findings: what is missing for people who cannot see or hear the video
	 * (group "accessibility"), and what about the file itself will trouble visitors (group "file").
	 * Hook after this method to add checks of your own.
	 *
	 * ~~~~~
	 * foreach($video->checks() as $check) {
	 *   if($check->status === 'todo' || $check->status === 'warning') echo "<li>$check->label</li>";
	 * }
	 * ~~~~~
	 *
	 * #pw-group-checks
	 *
	 * @return array of WireData with `name`, `group`, `status` ("ok", "todo", "warning" or "info"), `label` and `note`
	 *
	 */
	public function ___checks() {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->checker()->run($this) : array();
	}

	/**
	 * Get the number of checks that ask for attention (status "todo" or "warning")
	 *
	 * #pw-group-checks
	 *
	 * @return int
	 *
	 */
	public function openChecks() {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->checker()->countOpen($this->checks()) : 0;
	}

	/*** OUTPUT *********************************************************************************/

	/**
	 * Render the video for the website
	 *
	 * Returns a native video element that adapts to the width it is given, with the thumbnail
	 * shown before it plays and the title as its name for screen readers. The stylesheet and
	 * script it needs are added along with the first video of a page, unless you turned that
	 * off in the module settings.
	 *
	 * ~~~~~
	 * echo $video->render();
	 * echo $video->render([ 'description' => false ]); // without the description
	 * echo $video->render([ 'autoplay' => true, 'loop' => true ]);
	 * echo $video->render([ 'background' => true, 'shade' => 0.4 ]);
	 * ~~~~~
	 *
	 * #pw-group-output
	 *
	 * @param array $options All optional:
	 *  - `controls` (bool): Show the browser's player controls? (default=true)
	 *  - `autoplay` (bool): Start by itself? The video is then muted, as browsers require. (default=false)
	 *  - `muted` (bool): Start without sound? (default=false)
	 *  - `loop` (bool): Start over when it ends? (default=false)
	 *  - `playsinline` (bool): Play in place on phones rather than full screen? (default=true)
	 *  - `lazy` (bool): Load nothing of the video file before it is played, and the thumbnail only once the
	 *     video comes near the visible part of the page? Use false for a video at the top of a page. (default=true)
	 *  - `preload` (string): "none", "metadata" or "auto" to decide yourself what browsers load ahead. (default=by `lazy`)
	 *  - `poster` (bool|Pageimage|string): False for no thumbnail, or an image or URL to use instead. (default=true)
	 *  - `posterWidth` (int): Width in pixels the thumbnail is reduced to, 0 for its stored size. (default=1280)
	 *  - `caption` (bool|string): Show title, duration and description below the video? False for none of them,
	 *     "title" or "description" for one of them (with the duration), or any other text to show in their place. (default=true)
	 *  - `title` (bool): Show the title below the video? (default=true)
	 *  - `duration` (bool): Show the duration below the title? (default=true)
	 *  - `description` (bool): Show the description below the video? (default=true)
	 *  - `info` (bool|array): Show what the custom fields of the video say (a source, credits …), in a box below the
	 *     video that opens? True for all fields that have a value, or the names of the fields to show, in the order
	 *     wanted. See info(). (default=true)
	 *  - `infoOpen` (bool): Is that box open from the start? (default=false)
	 *  - `infoLabel` (string): The text of the line that opens the box. (default="More about this video")
	 *  - `transcript` (bool|string): Show the text of the captions as a transcript, in a box that opens, below the
	 *     box with the custom fields? "open" for the box open from the start. See transcript(). (default=true)
	 *  - `transcriptLang` (string): Language code of the captions to use. (default=as transcriptTrack() chooses)
	 *  - `transcriptLabel` (string): The text of the line that opens the box. (default="Transcript")
	 *  - `captions` (bool|string): Add the caption tracks of the video? A language code such as "de" also switches
	 *     the captions of that language on from the start. (default=true)
	 *  - `overlays` (bool): Show the overlays of the video on the picture? (default=true)
	 *  - `chapters` (bool): Show the list of chapters below the video? (default=true)
	 *  - `fullscreen` (bool): Add the module's own full screen button, which keeps overlays visible? (default=when the video has overlays)
	 *  - `start` (float|string): Moment to start at, in seconds or as a time such as "1:23". (default=what the
	 *     address of the page asks for with `?t=83`, otherwise the beginning)
	 *  - `single` (bool): Pause other videos of the page when this one starts? (default=true)
	 *  - `pauseOutOfView` (bool): Pause when scrolled out of view, and go on when back? (default=true)
	 *  - `schema` (bool): Add structured data for search engines? (default=true, false in background mode)
	 *  - `resume` (bool|int): Go on where the visitor stopped watching, when they come back? The place is kept in their
	 *     browser only. True for videos of a minute or longer, a number for videos of so many seconds or longer. (default=true)
	 *  - `end` (bool): Offer what is set for the end of the video (watch again, the next video, a link)? (default=true)
	 *  - `includeall` (bool): Render the video also when a text of its page shows it with a tag (text formatter
	 *     "Videos in text")? By default such a video is left out, so that it is not on the page twice. (default=false)
	 *  - `opengraph` (bool): Add the Open Graph tags of the video to the `<head>` of the page, for social networks
	 *     and messengers? Only the first video of a page that has them adds them; tags the page has already are
	 *     not added again. See metaTags(). (default=true, false in background mode)
	 *  - `background` (bool): Render as background video: muted, repeating, without controls, filling the
	 *     element it is placed in, with a button to pause it. (default=false)
	 *  - `shade` (float): In background mode, darken the video by this amount (0–1) so that text on it stays readable. (default=0)
	 *  - `toggle` (bool): In background mode, add the pause button? (default=true)
	 *  - `class` (string): Additional class name(s) for the wrapping element.
	 *  - `id` (string): The id attribute for the wrapping element.
	 *  - `attrs` (array): Additional attributes for the video element, i.e. `[ 'controlslist' => 'nodownload' ]`.
	 *  - `framework` (string|array|bool): CSS framework whose class names the parts of the video get: the name
	 *    of a profile (`uikit3`), a profile as an array, or false for none. By default the one chosen in the module settings.
	 *  - `assets` (bool): True to add the stylesheet and script in any case, false to never add them. (default=module setting)
	 * @return string Markup, or blank if the video has no file to show
	 *
	 */
	public function ___render(array $options = array()) {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->markup()->render($this, $options) : '';
	}

	/**
	 * Get what the custom fields of the video say, ready to be shown on the website
	 *
	 * Custom fields are the fields of a template named "field-" and the name of the video field
	 * (ProcessWire's custom fields for files). This is what render() shows with the option `info`.
	 * Fields without a value are left out, and so is a checkbox that is not checked. A reused
	 * video has the fields of its original.
	 *
	 * ~~~~~
	 * foreach($video->info() as $item) {
	 *   echo "<p><strong>$item[label]:</strong> $item[value]</p>";
	 * }
	 * $video->info([ 'source', 'credits' ]);   // only these, in this order
	 * ~~~~~
	 *
	 * Hook after this method to add an item of your own, or to change how a value is written.
	 *
	 * #pw-group-output
	 *
	 * @param array $names Names of the fields to get, in the order wanted. Default: all, in the order of the template.
	 * @return array of [ `name` (string), `label` (string: plain text), `value` (string: markup, safe to output) ]
	 *
	 */
	public function ___info(array $names = array()) {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->markup()->infoItems($this, $names) : array();
	}

	/**
	 * Get the caption track a transcript is made from
	 *
	 * In this order: the language asked for; the language of the visitor (a ProcessWire language
	 * named like a language code, such as "de"); the first track of the kind "captions" (as a
	 * rule the language spoken in the video); the first track.
	 *
	 * #pw-group-captions
	 *
	 * @param string $lang Language code, or blank to choose as above
	 * @return WireData|null A track as in captions(), or null if the video has none
	 *
	 */
	public function transcriptTrack($lang = '') {
		$tracks = $this->captions();
		if(!count($tracks)) return null;
		$wanted = array();
		$lang = SelfHostedVideoCaptions::cleanLang($lang);
		if($lang) $wanted[] = $lang;
		$language = $this->wire()->languages ? $this->wire()->user->language : null;
		if($language && $language->id && !$language->isDefault()) {
			$code = SelfHostedVideoCaptions::cleanLang($language->name);
			if($code) $wanted[] = $code;
		}
		foreach($wanted as $code) {
			if(isset($tracks[$code])) return $tracks[$code];
			// "de" finds "de-at", "de-at" finds "de"
			foreach($tracks as $key => $track) {
				if(strpos($key, "$code-") === 0 || strpos($code, "$key-") === 0) return $track;
			}
		}
		foreach($tracks as $track) {
			if($track->kind === 'captions') return $track;
		}
		return reset($tracks);
	}

	/**
	 * Get the text of the video's captions as a transcript: paragraphs, each with the time it begins
	 *
	 * The cues of a caption file are joined into paragraphs: a new one begins where another voice
	 * speaks (`<v Name>` in WebVTT), after a pause of two seconds or more, and at the end of a
	 * sentence once a paragraph has run for 20 seconds. This is what render() shows with the
	 * option `transcript`, and what the structured data offers as the transcript of the video.
	 *
	 * ~~~~~
	 * foreach($video->transcript() as $p) {
	 *   echo "<p><a href='?t=" . (int) $p['time'] . "'>$p[timeStr]</a> " . $sanitizer->entities($p['text']) . "</p>";
	 * }
	 * ~~~~~
	 *
	 * #pw-group-captions
	 *
	 * @param string $lang Language code of the captions to use, or blank to choose as transcriptTrack() does
	 * @return array of [ `time` (float, seconds), `timeStr` (string, i.e. "1:23"), `end` (float), `text` (string, plain text),
	 *   `voice` (string, the name of who speaks, blank if the captions do not say), `lang` (string, language code) ]
	 *
	 */
	public function ___transcript($lang = '') {
		$track = $this->transcriptTrack($lang);
		if(!$track || !is_file($track->filename)) return array();
		$out = array();
		foreach(SelfHostedVideoCaptions::paragraphs(SelfHostedVideoCaptions::cues((string) file_get_contents($track->filename))) as $p) {
			$whole = (int) floor($p['time']);
			$h = intdiv($whole, 3600);
			$m = intdiv($whole % 3600, 60);
			$p['timeStr'] = $h ? sprintf('%d:%02d:%02d', $h, $m, $whole % 60) : sprintf('%d:%02d', $m, $whole % 60);
			$p['lang'] = $track->lang;
			$out[] = $p;
		}
		return $out;
	}

	/**
	 * Get the transcript as plain text: its paragraphs, with the name of who speaks in front, separated by blank lines
	 *
	 * #pw-group-captions
	 *
	 * @param string $lang As for transcript()
	 * @return string Blank if the video has no captions
	 *
	 */
	public function transcriptText($lang = '') {
		$out = array();
		$voice = '';
		foreach($this->transcript($lang) as $p) {
			$text = $p['text'];
			if(strlen($p['voice']) && $p['voice'] !== $voice) $text = "$p[voice]: $text";
			if(strlen($p['voice'])) $voice = $p['voice'];
			$out[] = $text;
		}
		return implode("\n\n", $out);
	}

	/**
	 * Get the structured data of the video (schema.org VideoObject) as an array
	 *
	 * This is what render() adds to the page as a JSON-LD block. Hook after this method to
	 * add or change properties.
	 *
	 * ~~~~~
	 * $wire->addHookAfter('Pagevideo::structuredData', function(HookEvent $event) {
	 *   $data = $event->return;
	 *   $data['publisher'] = [ '@type' => 'Organization', 'name' => 'Example Ltd.' ];
	 *   $event->return = $data;
	 * });
	 * ~~~~~
	 *
	 * #pw-group-output
	 *
	 * @return array
	 *
	 */
	public function ___structuredData() {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->markup()->structuredData($this) : array();
	}

	/**
	 * Get the Open Graph tags of the video, for the `<head>` of the page
	 *
	 * Social networks and messengers then show the thumbnail of the video when the page is shared,
	 * and some of them the video. `og:title` and `og:description` are those of the page, so the
	 * template adds them. Blank for a protected video, which those services cannot load.
	 *
	 * ~~~~~
	 * // in the <head> of _main.php
	 * echo $page->videos->metaTags(); // of the first video
	 * ~~~~~
	 *
	 * #pw-group-output
	 *
	 * @param array $options
	 *  - `image` (bool): Add `og:image` with the thumbnail? (default=true)
	 *  - `video` (bool): Add `og:video` with the file? (default=true)
	 * @return string
	 *
	 */
	public function ___metaTags(array $options = array()) {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->markup()->metaTags($this, $options) : '';
	}

	/**
	 * Where the video is placed: on its own page, reused on other pages, and with tags in texts
	 *
	 * A reuse gives the list of its original. Pages in the trash are left out.
	 *
	 * ~~~~~
	 * foreach($video->usage() as $use) {
	 *   echo $use['page']->title . ': ' . $use['kind']; // field, reuse or text
	 * }
	 * ~~~~~
	 *
	 * #pw-group-references
	 *
	 * @return array Each with `page` (Page), `holder` (the page with the field, a repeater item for a
	 *   text in one), `kind` ("field", "reuse" or "text") and `field` (Field: the video field or the text field)
	 *
	 */
	public function usage() {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->usage()->forVideo($this) : array();
	}

	/**
	 * How often the video was watched on the website (anonymous counts, see SelfHostedVideoStats)
	 *
	 * ~~~~~
	 * $stats = $video->stats(30);
	 * echo "$stats[plays] plays, $stats[half]% to the half, $stats[end]% to the end";
	 * ~~~~~
	 *
	 * #pw-group-common
	 *
	 * @param int $days The last so many days, 0 for all that are kept
	 * @return array `plays`, `quarter`, `half`, `threeQuarters`, `end` (percent of the plays), `counts`, `days`
	 *
	 */
	public function stats($days = 30) {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->stats()->forVideo($this, $days) : array();
	}

	/**
	 * The pages the video is placed on (see usage())
	 *
	 * #pw-group-references
	 *
	 * @return PageArray
	 *
	 */
	public function usedOnPages() {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->usage()->pagesForVideo($this) : $this->wire()->pages->newPageArray();
	}

	/**
	 * Get the address of the thumbnail as players show it, with scheme and host
	 *
	 * The same address is used in the player, the structured data, the video sitemap and the Open Graph tags.
	 *
	 * #pw-group-output
	 *
	 * @return string Blank when the video has no thumbnail
	 *
	 */
	public function thumbnailUrl() {
		$fieldtype = $this->videoFieldtype();
		return $fieldtype ? $fieldtype->markup()->thumbnailUrl($this) : '';
	}

	/*** REFERENCES *****************************************************************************/

	/**
	 * Is this a reference to a video that lives on another page (or in another field)?
	 *
	 * #pw-group-references
	 *
	 * @return bool
	 *
	 */
	public function isReference() {
		return !empty($this->data['ref_pages_id']);
	}

	/**
	 * Is this a reference whose original video no longer exists?
	 *
	 * Broken references are left out of the formatted field value automatically.
	 *
	 * #pw-group-references
	 *
	 * @return bool
	 *
	 */
	public function isBroken() {
		return $this->isReference() && !$this->original();
	}

	/**
	 * Get the original video that this reference points to
	 *
	 * The returned Pagevideo is a read-only view of the original as it is stored in the
	 * database: always unformatted, and separate from the value of the page it lives on.
	 * To edit the original, edit it on its own page, which is `$video->original()->page`.
	 *
	 * #pw-group-references
	 *
	 * @return Pagevideo|null Null if this is not a reference, or if the original no longer exists
	 *
	 */
	public function original() {
		if(!$this->isReference()) return null;
		$fieldtype = $this->videoFieldtype();
		if(!$fieldtype) return null;
		return $fieldtype->getOriginal($this->get('ref_pages_id'), $this->get('ref_name'));
	}

	/**
	 * Get the video Fieldtype
	 *
	 * @return FieldtypeSelfHostedVideo|null
	 *
	 */
	protected function videoFieldtype() {
		$field = $this->field;
		$fieldtype = $field ? $field->type : null;
		if(!$fieldtype instanceof FieldtypeSelfHostedVideo) $fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		return $fieldtype instanceof FieldtypeSelfHostedVideo ? $fieldtype : null;
	}

	/**
	 * Make this video a reference to the given original
	 *
	 * #pw-internal Use Pagevideos::addReference() instead
	 *
	 * @param Pagevideo $original
	 * @return $this
	 *
	 */
	public function setReference(Pagevideo $original) {
		$this->set('ref_pages_id', $original->page->id);
		$this->set('ref_name', $original->basename());
		return $this;
	}

	/**
	 * Get the pages that reuse this video through a reference
	 *
	 * ~~~~~
	 * foreach($video->references() as $p) {
	 *   echo "<li>Also used on: $p->path</li>";
	 * }
	 * ~~~~~
	 *
	 * #pw-group-references
	 *
	 * @return PageArray Pages of any status, including unpublished ones
	 *
	 */
	public function references() {
		$pages = $this->wire()->pages;
		$fieldtype = $this->videoFieldtype();
		if($this->isReference() || !$fieldtype || !$this->page->id) {
			return $pages->newPageArray();
		}
		$ids = array();
		foreach($fieldtype->findReferences($this->page, $this->basename()) as $row) {
			$ids[$row['pages_id']] = $row['pages_id'];
		}
		return count($ids) ? $pages->getById(array_values($ids)) : $pages->newPageArray();
	}

	/**
	 * Is this video reused on any other page (or in another field)?
	 *
	 * #pw-group-references
	 *
	 * @return bool
	 *
	 */
	public function isReferenced() {
		$fieldtype = $this->videoFieldtype();
		if($this->isReference() || !$fieldtype || !$this->page->id) return false;
		return count($fieldtype->findReferences($this->page, $this->basename())) > 0;
	}

	/*** PAGEFILE METHODS THAT A REFERENCE TAKES FROM ITS ORIGINAL ******************************/

	/**
	 * Get or set the description (see Pagefile::description for all options)
	 *
	 * @param null|bool|Language|array $language
	 * @param null|string $value
	 * @return string|array
	 *
	 */
	public function description($language = null, $value = null) {
		if(!$this->isReference()) return parent::description($language, $value);
		if($value !== null && $value !== true) return $value; // setting: not possible on a reference
		if(is_array($language)) $language = null; // setting from array: not possible either
		$original = $this->original();
		if(!$original) return $language === true && $value === true ? array() : '';
		$description = $original->description($language, $value);
		if($language === true) {
			// all languages: array is for display, JSON string is the raw value for storage
			if(is_array($description)) {
				foreach($description as $key => $text) $description[$key] = $this->formatText($text);
			}
			return $description;
		}
		return $this->formatText($description);
	}

	/**
	 * Get or set the tags (see Pagefile::tags for all options)
	 *
	 * @param bool|string|array|null $value
	 * @return string|array
	 *
	 */
	public function tags($value = null) {
		if(!$this->isReference()) return parent::tags($value);
		$original = $this->original();
		if(is_bool($value)) return $original ? $original->tags($value) : array();
		$tags = $original ? (string) $original->tags() : '';
		$field = $this->field;
		if($field && $field->get('useTags')) $tags = $this->formatText($tags);
		return $tags;
	}

	/**
	 * Original file name at the time of upload
	 *
	 * @return string
	 *
	 */
	public function uploadName() {
		if(!$this->isReference()) return parent::uploadName();
		$original = $this->original();
		return $original ? $this->formatText($original->getUnformattedUploadName()) : '';
	}

	/**
	 * Upload name without entity encoding
	 *
	 * #pw-internal
	 *
	 * @return string
	 *
	 */
	public function getUnformattedUploadName() {
		$uploadName = (string) $this->filedata('uploadName');
		return strlen($uploadName) ? $uploadName : $this->basename();
	}

	/**
	 * URL to the video file
	 *
	 * @return string Blank for a broken reference
	 *
	 */
	protected function ___url() {
		if(!$this->isReference()) return parent::___url();
		$original = $this->original();
		return $original ? $original->url() : '';
	}

	/**
	 * URL to the video file, including scheme and hostname
	 *
	 * @return string Blank for a broken reference
	 *
	 */
	public function ___httpUrl() {
		if(!$this->isReference()) return parent::___httpUrl();
		$original = $this->original();
		return $original ? $original->httpUrl() : '';
	}

	/**
	 * Full disk path to the video file
	 *
	 * @return string Blank for a broken reference
	 *
	 */
	protected function ___filename() {
		if(!$this->isReference()) return parent::___filename();
		$original = $this->original();
		return $original ? $original->filename() : '';
	}

	/**
	 * Delete the file from disk
	 *
	 * A reference has no file of its own, so removing a reference never deletes the original.
	 *
	 * #pw-internal
	 *
	 * @return bool
	 *
	 */
	public function unlink() {
		if($this->isReference()) return true;
		$thumb = $this->thumb();
		if($thumb) $thumb->unlink(); // also removes its size variations
		foreach($this->captions() as $track) {
			$this->wire()->files->unlink($track->filename);
		}
		return parent::unlink();
	}

	/**
	 * Rename the file
	 *
	 * Pages that reuse this video keep working: their references follow the new name. So do the
	 * tags in texts that place it (such as [[video intro.mov]]) and its counts of plays.
	 *
	 * #pw-group-manipulation
	 *
	 * @param string $basename New file name (no path)
	 * @return string|bool New name on success, false on failure
	 *
	 */
	public function rename($basename) {
		if($this->isReference()) return false;
		$oldName = $this->basename();
		$thumb = $this->thumb();
		$info = $this->thumbInfo();
		$tracks = $this->captions();
		$newName = parent::rename($basename);
		if($newName === false || $newName === $oldName) return $newName;
		if(count($tracks)) {
			// caption files are named after the video too
			$stored = $this->filedata('captions');
			$path = $this->pagefiles->path();
			foreach($tracks as $lang => $track) {
				$file = $this->captionsBasename($lang);
				if($file !== $track->file && @rename($track->filename, $path . $file)) $stored[$lang]['file'] = $file;
			}
			$this->filedata('captions', $stored);
		}
		if($thumb) {
			// the thumbnail is named after the video, so it follows
			$thumbName = $thumb->rename($this->thumbBasename($thumb->ext()));
			if($thumbName !== false) {
				$info['file'] = $thumbName;
				$this->filedata('thumb', $info);
			}
			$this->thumbCache['key'] = '';
		}
		$fieldtype = $this->videoFieldtype();
		if($fieldtype && $this->page->id) $fieldtype->renameReferences($this->page, $oldName, $newName);
		// counts and tags in texts follow the new name
		if($fieldtype && $this->page->id) $fieldtype->videoRenamed($this->page, $oldName, $newName);
		return $newName;
	}

	/**
	 * Copy the file to the given path
	 *
	 * #pw-internal
	 *
	 * @param string $path
	 * @return bool
	 *
	 */
	public function copyToPath($path) {
		if($this->isReference()) return true; // nothing to copy: the file stays with the original
		return parent::copyToPath($path);
	}

	/**
	 * Is the given file one of this video’s files, and which?
	 *
	 * #pw-internal
	 *
	 * @param string $basename File name without path
	 * @return string "video", "thumb" (the thumbnail or a size made from it), "captions", or blank if it is none of them.
	 *   Always blank for a reference, which has no files of its own.
	 *
	 */
	public function ownsFile($basename) {

		if($this->isReference()) return '';

		$basename = (string) $basename;
		if(!strlen($basename)) return '';
		if($basename === $this->basename()) return 'video';

		$info = $this->thumbInfo();
		if($info['file']) {
			if($basename === $info['file']) return 'thumb';
			// sizes and other formats made from the thumbnail: "intro-mp4-thumb.640x0.jpg", "intro-mp4-thumb.webp"
			$stem = pathinfo($info['file'], PATHINFO_FILENAME);
			if(strpos($basename, "$stem.") === 0) return 'thumb';
		}

		foreach($this->captions() as $track) {
			if($basename === $track->file) return 'captions';
		}

		return '';
	}

	/**
	 * Are the files of this video protected, so that only those who may see it can load them?
	 *
	 * They are when the page holding the video (the original, for a reused one) is not public and
	 * ProcessWire is set to protect files: with `$config->pagefileSecure`, or in the "Access" tab
	 * of the template that manages access to the page. See "Protected videos" in the README.
	 *
	 * ~~~~~
	 * if($video->isProtected()) echo "<p>For members</p>";
	 * ~~~~~
	 *
	 * #pw-group-common
	 *
	 * @return bool
	 *
	 */
	public function isProtected() {
		$source = $this->isReference() ? $this->original() : $this;
		if(!$source || !$source->page || !$source->page->id) return false;
		return $source->page->secureFiles() === true;
	}

	/**
	 * Get all file names that belong to this video
	 *
	 * @return array Empty for a reference, which has no files of its own
	 *
	 */
	public function getFiles() {
		if($this->isReference()) return array();
		// the parent method exists since ProcessWire 3.0.233
		$filenames = method_exists(__NAMESPACE__ . '\\Pagefile', 'getFiles') ? parent::getFiles() : array($this->filename());
		$thumb = $this->thumb();
		if($thumb) {
			$filenames[] = $thumb->filename();
			foreach($thumb->getVariations() as $variation) {
				/** @var Pageimage $variation */
				$filenames[] = $variation->filename();
			}
		}
		foreach($this->captions() as $track) {
			$filenames[] = $track->filename;
		}
		return $filenames;
	}

	/**
	 * Debug info
	 *
	 * @return array
	 *
	 */
	public function __debugInfo() {
		$info = parent::__debugInfo();
		$info['title'] = $this->title();
		$info['duration'] = $this->duration();
		$info['width'] = $this->width();
		$info['height'] = $this->height();
		$info['captions'] = array_keys($this->captions());
		if($this->isReference()) {
			$info['reference'] = array(
				'page' => $this->get('ref_pages_id'),
				'name' => $this->get('ref_name'),
				'broken' => $this->isBroken(),
			);
		}
		return $info;
	}
}
