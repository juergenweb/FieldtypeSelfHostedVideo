<?php namespace ProcessWire;

/**
 * Video Files: profiles that give the parts of a video the class names of a CSS framework
 *
 * A profile is a PHP file that returns an array. It names parts of the markup (the player,
 * an overlay, the list of chapters …) and says which class names and attributes each of them
 * gets. A part that gets class names from a profile no longer gets the look of the module,
 * so that the framework decides how it looks. What makes a part work stays with the module:
 * where it sits, how it scales with the video, and the class names its script looks for.
 *
 * Profiles are looked for in two folders, the second one taking precedence:
 *
 * - /site/modules/FieldtypeSelfHostedVideo/frameworks/      those that come with the module
 * - /site/templates/selfhostedvideo/frameworks/             your own, and your versions of the above
 *
 * The name of a profile is its file name without ".php". See frameworks/uikit3.php for
 * the format and the README for the list of parts. To provide a profile without a file, or
 * to change one, hook after FieldtypeSelfHostedVideo::getFramework().
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoFrameworks extends Wire {

	/**
	 * Where the site keeps its own profiles, below /site/templates/
	 *
	 */
	const siteFolder = 'selfhostedvideo/frameworks/';

	/**
	 * The class name of a part whose look comes from a framework
	 *
	 */
	const plainClass = 'shv-video-plain';

	/**
	 * The parts of the markup a profile can name, each with what it is
	 *
	 * @var array
	 *
	 */
	static protected $parts = array(
		'player' => 'The element around a video with controls',
		'background' => 'The element around a background video',
		'screen' => 'The picture with its overlays and the full screen button',
		'frame' => 'The picture with its overlays',
		'video' => 'The video element',
		'fallback' => 'The paragraph shown by browsers that cannot play video',
		'download' => 'The link to the file in that paragraph',
		'overlays' => 'The layer that holds the overlays',
		'overlay' => 'Every overlay',
		'overlay-dark' => 'An overlay with the look "dark box"',
		'overlay-light' => 'An overlay with the look "light box"',
		'overlay-s' => 'An overlay with small text',
		'overlay-m' => 'An overlay with medium text',
		'overlay-l' => 'An overlay with large text',
		'overlay-link' => 'An overlay that is a link',
		'fullscreen' => 'The full screen button on the picture',
		'toggle' => 'The button that pauses and plays a background video',
		'chapters' => 'The block with the chapters',
		'chapters-label' => 'The label "Chapters"',
		'chapter-list' => 'The list of chapters',
		'chapter-item' => 'An item of that list',
		'chapter' => 'The link of a chapter',
		'chapter-time' => 'The time in that link',
		'chapter-title' => 'The title in that link',
		'caption' => 'The caption below the video',
		'title' => 'The title in the caption',
		'meta' => 'The line with the duration in the caption',
		'duration' => 'The duration in that line',
		'description' => 'The description in the caption',
		'info' => 'The box with more about the video, which opens (a details element)',
		'info-summary' => 'The line that opens and closes that box',
		'info-list' => 'The list of custom fields in that box (a description list)',
		'info-term' => 'The name of a custom field in that list',
		'info-value' => 'The value of a custom field in that list',
		'transcript' => 'The box with the transcript, which opens (a details element)',
		'transcript-summary' => 'The line that opens and closes that box',
		'transcript-body' => 'The text of the transcript in that box',
		'transcript-item' => 'A paragraph of the transcript',
		'transcript-time' => 'The time in front of a paragraph, a link that plays the video from there',
		'transcript-voice' => 'The name of who speaks, in front of a paragraph',
		'text' => 'The element around a video placed in a text',
		'text-left' => 'That element, when the video is aligned left',
		'text-right' => 'That element, when the video is aligned right',
		'text-center' => 'That element, when the video is centred',
	);

	/**
	 * Profiles that were read during this request: [ name => array|null ]
	 *
	 * @var array
	 *
	 */
	protected $loaded = array();

	/**
	 * Names of the profiles being read right now, to notice profiles that build on each other in a circle
	 *
	 * @var array
	 *
	 */
	protected $reading = array();

	/**
	 * Get the names of the parts a profile can give class names to
	 *
	 * @return array [ name => what it is ]
	 *
	 */
	public static function getParts() {
		return self::$parts;
	}

	/**
	 * Get the folders that profiles are looked for in, the one that takes precedence last
	 *
	 * @return array
	 *
	 */
	public function folders() {
		return array(
			__DIR__ . '/frameworks/',
			$this->wire()->config->paths->templates . self::siteFolder,
		);
	}

	/**
	 * Is this a valid name for a profile?
	 *
	 * @param string $name
	 * @return bool
	 *
	 */
	public static function isName($name) {
		return is_string($name) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) === 1;
	}

	/**
	 * Find the profiles there are
	 *
	 * @return array [ name => title ], sorted by title
	 *
	 */
	public function find() {
		$found = array();
		foreach($this->folders() as $folder) {
			if(!is_dir($folder)) continue;
			foreach((array) glob($folder . '*.php') as $file) {
				$name = basename((string) $file, '.php');
				if(!self::isName($name) || isset($found[$name])) continue;
				$profile = $this->get($name);
				if($profile) $found[$name] = $profile['title'];
			}
		}
		asort($found, SORT_NATURAL | SORT_FLAG_CASE);
		return $found;
	}

	/**
	 * Get a profile by its name
	 *
	 * A file of the website takes the place of a file of the module with the same name.
	 *
	 * A profile may build on another one: `'extends' => 'uikit3'` takes the parts and the
	 * stylesheets of that profile and puts its own on top. A file of the website with the name
	 * of a profile of the module builds on that profile of the module in the same way.
	 *
	 * @param string $name
	 * @return array|null The profile as returned by normalize(), or null if there is none of that name.
	 *   `css` is the URL of its stylesheet (or blank), `styles` the URLs of all stylesheets it needs,
	 *   those of the profiles it builds on first.
	 *
	 */
	public function get($name) {

		if(!self::isName($name)) return null;
		if(array_key_exists($name, $this->loaded)) return $this->loaded[$name];
		if(isset($this->reading[$name])) return null; // profiles that build on each other in a circle

		$this->reading[$name] = true;
		$profile = null;

		foreach(array_reverse($this->folders()) as $folder) {
			$profile = $this->load($name, $folder);
			if($profile) break;
		}

		unset($this->reading[$name]);
		$this->loaded[$name] = $profile;

		return $profile;
	}

	/**
	 * Read the profile of a name from a folder, with what it builds on
	 *
	 * @param string $name
	 * @param string $folder
	 * @return array|null Null if the folder has no profile of that name
	 *
	 */
	protected function load($name, $folder) {

		$file = $folder . $name . '.php';
		if(!is_file($file)) return null;

		$data = $this->read($file);
		if(!is_array($data)) return null;

		$profile = self::normalize($data, $name);
		if(strlen($profile['css'])) $profile['css'] = $this->fileUrl($folder . $profile['css']);
		$profile['styles'] = strlen($profile['css']) ? array($profile['css']) : array();

		if(!strlen($profile['extends'])) return $profile;

		if($profile['extends'] === $name) {
			// the profile of the module that this file of the website has the name of
			$own = __DIR__ . '/frameworks/';
			$parent = $folder === $own ? null : $this->load($name, $own);
		} else {
			$parent = $this->get($profile['extends']);
		}

		if(!$parent) {
			$this->wire()->log->save('video-files', "Framework profile $file: there is no profile “$profile[extends]” to build on");
			return $profile;
		}

		// a part of this profile takes the place of the same part of the one it builds on
		$parts = $parent['parts'];
		$given = isset($data['parts']) && is_array($data['parts']) ? $data['parts'] : array();
		foreach($given as $part => $value) {
			// false gives a part back to the module
			if($value === false || $value === null) unset($parts[$part]);
		}
		$profile['parts'] = $profile['parts'] + $parts;
		$profile['styles'] = array_values(array_unique(array_merge($parent['styles'], $profile['styles'])));
		// a file of the website that changes a profile of the module keeps the title of that profile
		if($profile['extends'] === $name && $profile['title'] === $name) $profile['title'] = $parent['title'];

		return $profile;
	}

	/**
	 * Read a profile file
	 *
	 * @param string $file
	 * @return mixed What the file returns
	 *
	 */
	protected function read($file) {
		try {
			return include($file);
		} catch(\Throwable $e) {
			$this->wire()->log->save('video-files', "Framework profile $file: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Get the URL of a file that belongs to a profile, with what tells its versions apart
	 *
	 * @param string $file Full path
	 * @return string Blank if there is no such file below the root of the site
	 *
	 */
	protected function fileUrl($file) {
		$config = $this->wire()->config;
		$file = str_replace('\\', '/', $file);
		if(!is_file($file)) return '';
		$version = '?v=' . (int) @filemtime($file);
		// the folder of the module may be a link to somewhere else: its files are still served from its URL
		$module = str_replace('\\', '/', __DIR__ . '/');
		if(strpos($file, $module) === 0) {
			$url = $config->urls->get('FieldtypeSelfHostedVideo');
			if(!$url) $url = $config->urls->siteModules . 'FieldtypeSelfHostedVideo/';
			return $url . substr($file, strlen($module)) . $version;
		}
		$root = str_replace('\\', '/', $config->paths->root);
		if(strpos($file, $root) !== 0) return '';
		return $config->urls->root . substr($file, strlen($root)) . $version;
	}

	/**
	 * Bring a profile into the form the module works with
	 *
	 * @param array $data What a profile file returns, or what was given as the `framework` option
	 * @param string $name
	 * @return array
	 *  - `name` (string)
	 *  - `title` (string)
	 *  - `css` (string): Name of a stylesheet next to the profile file (later its URL), or blank.
	 *  - `extends` (string): Name of the profile this one builds on, or blank.
	 *  - `parts` (array): [ part => [ `class` (string), `look` (bool), `attrs` (array), `current` (string) ] ]
	 *
	 */
	public static function normalize(array $data, $name = '') {

		$profile = array(
			'name' => (string) $name,
			'title' => isset($data['title']) && is_string($data['title']) && strlen(trim($data['title'])) ? trim($data['title']) : (string) $name,
			'css' => '',
			'extends' => '',
			'parts' => array(),
		);

		// a stylesheet is a file next to the profile, nothing else
		if(isset($data['css']) && is_string($data['css']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.css$/', $data['css'])) {
			$profile['css'] = $data['css'];
		}

		if(isset($data['extends']) && self::isName($data['extends'])) $profile['extends'] = $data['extends'];

		$parts = isset($data['parts']) && is_array($data['parts']) ? $data['parts'] : array();

		foreach($parts as $part => $value) {
			if(!is_string($part) || !preg_match('/^[a-z][a-z0-9-]*$/', $part)) continue;
			if(is_string($value)) $value = array('class' => $value);
			if(!is_array($value)) continue;

			$class = isset($value['class']) ? $value['class'] : '';
			if(is_array($class)) $class = implode(' ', array_filter($class, 'is_string'));
			$class = self::classNames($class);
			$current = isset($value['current']) ? $value['current'] : '';
			if(is_array($current)) $current = implode(' ', array_filter($current, 'is_string'));
			$current = self::classNames($current);

			$attrs = array();
			if(isset($value['attrs']) && is_array($value['attrs'])) {
				foreach($value['attrs'] as $attr => $v) {
					$attr = strtolower(trim((string) $attr));
					if(!preg_match('/^[a-z][a-z0-9_:.-]*$/', $attr)) continue;
					if(strpos($attr, 'on') === 0 || $attr === 'class' || $attr === 'style' || $attr === 'id') continue;
					if($v === true || is_string($v) || is_int($v) || is_float($v)) $attrs[$attr] = $v;
				}
			}

			$item = array(
				'class' => $class,
				// a part that a framework gives class names to gets its look from there
				'look' => isset($value['look']) ? (bool) $value['look'] : !strlen($class),
				'attrs' => $attrs,
				'current' => $current,
			);

			// a part that says nothing is as good as not named
			if(!strlen($class) && $item['look'] && !count($attrs) && !strlen($current)) continue;

			$profile['parts'][$part] = $item;
		}

		return $profile;
	}

	/**
	 * Clean up a list of class names
	 *
	 * @param mixed $value Class names separated by spaces
	 * @return string
	 *
	 */
	protected static function classNames($value) {
		if(!is_string($value)) return '';
		$names = array();
		foreach(preg_split('/\s+/', trim($value)) as $name) {
			// what CSS frameworks use: letters, digits, - _ and the : / @ . [ ] % ! of utility classes
			if(strlen($name) && preg_match('/^[A-Za-z0-9_:@.\/\[\]%!-]+$/', $name)) $names[$name] = $name;
		}
		return implode(' ', $names);
	}

	/**
	 * Give an element what a profile says about its part
	 *
	 * @param array|null $profile A profile as returned by normalize(), or null for none
	 * @param string $part Name of the part
	 * @param array $attrs The attributes of the element so far, `class` among them
	 * @param array $variants More parts this element is, i.e. [ 'overlay-dark', 'overlay-m' ]
	 * @return array The attributes
	 *
	 */
	public static function apply($profile, $part, array $attrs, array $variants = array()) {

		if(!is_array($profile) || empty($profile['parts'])) return $attrs;

		$class = isset($attrs['class']) ? (string) $attrs['class'] : '';
		$plain = false;
		$current = '';

		foreach(array_merge(array($part), $variants) as $name) {
			if(!isset($profile['parts'][$name])) continue;
			$item = $profile['parts'][$name];
			if(strlen($item['class'])) $class .= ' ' . $item['class'];
			if(!$item['look']) $plain = true;
			if(strlen($item['current'])) $current = trim("$current $item[current]");
			foreach($item['attrs'] as $attr => $value) {
				// what the module sets itself is not for a profile to change
				if(!array_key_exists($attr, $attrs)) $attrs[$attr] = $value;
			}
		}

		// the mark of a part whose look comes from the framework: the stylesheet of the module leaves it alone
		if($plain) $class .= ' ' . self::plainClass;
		if(strlen(trim($class))) $attrs['class'] = trim($class);
		// the script gives these class names to the part while it is the current one
		if(strlen($current)) $attrs['data-shv-current-class'] = $current;

		return $attrs;
	}
}
