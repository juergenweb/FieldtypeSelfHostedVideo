<?php namespace ProcessWire;

/**
 * SelfHostedVideoMarkup
 *
 * Builds the markup that shows a video on the website: a native video element that adapts
 * to the width it is given, with thumbnail, optional caption and structured data, or a
 * muted background video with a pause button.
 *
 * It is used through `$video->render()` and `$videos->render()`. There is one instance per
 * request, held by the FieldtypeSelfHostedVideo module, which is how the stylesheet and script
 * are added to a page only once.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoMarkup extends Wire {

	/**
	 * Media types by file extension
	 *
	 * @var array
	 *
	 */
	static protected $mediaTypes = array(
		'mp4' => 'video/mp4',
		'm4v' => 'video/mp4',
		'webm' => 'video/webm',
		'mov' => 'video/quicktime',
		'ogv' => 'video/ogg',
		'ogg' => 'video/ogg',
	);

	/**
	 * Have the stylesheet and script been added to the output of this request?
	 *
	 * @var bool
	 *
	 */
	protected $assetsRendered = false;

	/**
	 * Videos whose structured data has been added to the output of this request: [ url => [ script, src ] ]
	 *
	 * @var array
	 *
	 */
	protected $schemaRendered = array();

	/**
	 * Address of the thumbnail as the player being rendered shows it, for its structured data
	 *
	 * @var string|null
	 *
	 */
	protected $schemaThumb = null;

	/**
	 * Open Graph tags of the videos rendered with them, for the <head> of the page: [ 'src' => …, 'tags' => … ]
	 *
	 * The first of them whose file is in the finished page gives its tags.
	 *
	 * @var array
	 *
	 */
	protected $openGraph = array();

	/**
	 * Counts the calls of reset(), for those who keep something that depends on what was output
	 *
	 * @var int
	 *
	 */
	protected $generation = 0;

	/**
	 * Videos rendered during this request: [ "page ID/file name" => true ]
	 *
	 * @var array
	 *
	 */
	protected $rendered = array();

	/**
	 * Number of videos with controls rendered during this request
	 *
	 * @var int
	 *
	 */
	protected $playerCount = 0;

	/**
	 * The id attributes given to videos during this request: [ id => true ]
	 *
	 * @var array
	 *
	 */
	protected $usedIds = array();

	/**
	 * The profile of the CSS framework for the video being rendered, or null for none
	 *
	 * @var array|null
	 *
	 */
	protected $framework = null;

	/**
	 * Profiles of CSS frameworks asked for during this request: [ name => array|null ]
	 *
	 * @var array
	 *
	 */
	protected $frameworks = array();

	/**
	 * Stylesheets of profiles that belong to the output of this request: [ url => added to the output? ]
	 *
	 * @var array
	 *
	 */
	protected $frameworkCss = array();

	/**
	 * Default options for rendering a video
	 *
	 * @return array
	 *
	 */
	public static function getDefaultOptions() {
		return array(
			'controls' => true,
			'autoplay' => false,
			'muted' => false,
			'loop' => false,
			'playsinline' => true,
			'preload' => '',
			'lazy' => null,
			'poster' => true,
			'posterWidth' => 1280,
			'caption' => true,
			'title' => true,
			'duration' => true,
			'description' => true,
			'info' => true,
			'infoOpen' => false,
			'infoLabel' => '',
			'transcript' => true,
			'transcriptOpen' => false,
			'transcriptLang' => '',
			'transcriptLabel' => '',
			'captions' => true,
			'overlays' => true,
			'chapters' => true,
			'fullscreen' => null,
			'start' => null,
			'single' => true,
			'pauseOutOfView' => true,
			'schema' => null,
			'opengraph' => null,
			'resume' => true,
			'end' => true,
			'includeall' => false,
			'background' => false,
			'shade' => 0,
			'toggle' => true,
			'class' => '',
			'id' => '',
			'attrs' => array(),
			'framework' => null,
			'assets' => null,
		);
	}

	/**
	 * Render a video
	 *
	 * See Pagevideo::render() for the options.
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 * @return string Blank when the video has no file to show (a reference whose original is gone)
	 *
	 */
	public function render(Pagevideo $video, array $options = array()) {

		$options = array_merge(self::getDefaultOptions(), $options);
		$background = (bool) $options['background'];

		if($options['lazy'] === null) $options['lazy'] = !$background;
		if($options['schema'] === null) $options['schema'] = !$background;
		if($options['opengraph'] === null) $options['opengraph'] = !$background;

		if($video->isBroken() || !strlen((string) $video->url())) return '';

		// a video that a text of its page shows with a tag is not shown a second time, unless asked for
		if(!$options['includeall'] && $this->isInText($video)) return '';

		$this->rendered[$this->videoKey($video)] = true;

		$this->framework = $this->getFramework($options['framework']);
		$this->expectStyles($this->framework);

		$out = $background ? $this->renderBackground($video, $options) : $this->renderPlayer($video, $options);

		$this->framework = null;

		// the first video of the page is the one social networks show when it is shared
		if($options['opengraph'] && count($this->openGraph) < 20) {
			$src = $this->wire()->sanitizer->entities((string) $video->url());
			if(!isset($this->openGraph[$src])) {
				$tags = (string) $video->metaTags();
				if(strlen($tags)) $this->openGraph[$src] = $tags;
			}
		}

		if($options['schema']) {
			// the structured data names the same thumbnail as the player: search engines ask for one address
			$this->schemaThumb = $this->absoluteUrl($this->posterUrl($video, $options));
			$out .= $this->renderStructuredData($video);
			$this->schemaThumb = null;
		}

		if($options['assets'] !== false) {
			$auto = $options['assets'] === true ? true : $this->useAutoAssets();
			if($auto) $out = $this->renderAssets($options['assets'] === true) . $out;
		}

		return $out;
	}

	/**
	 * Get the profile of a CSS framework
	 *
	 * @param string|array|bool|null $framework Name of a profile, a profile as an array,
	 *   false or a blank string for none, null for the one chosen in the module settings.
	 * @return array|null
	 *
	 */
	public function getFramework($framework = null) {

		if(is_array($framework)) {
			// given with a single video: class names only, as there is no folder a stylesheet could be in
			$profile = SelfHostedVideoFrameworks::normalize($framework, isset($framework['name']) && is_string($framework['name']) ? $framework['name'] : 'custom');
			$profile['css'] = '';
			$profile['styles'] = array();
			return $profile;
		}

		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		if(!$fieldtype) return null;

		if($framework === null) $framework = (string) $fieldtype->get('framework');
		if(!is_string($framework) || !strlen($framework)) return null;

		if(!array_key_exists($framework, $this->frameworks)) {
			$profile = $fieldtype->getFramework($framework);
			if(is_array($profile)) {
				// a hook may have added to it in the short form, and may name a stylesheet by its URL
				$css = isset($profile['css']) && is_string($profile['css']) ? $profile['css'] : '';
				$styles = isset($profile['styles']) && is_array($profile['styles']) ? $profile['styles'] : array();
				$profile = SelfHostedVideoFrameworks::normalize($profile, $framework);
				$profile['css'] = $css;
				$profile['styles'] = array();
				foreach(array_merge($styles, array($css)) as $url) {
					if(is_string($url) && strlen($url) && !in_array($url, $profile['styles'], true)) $profile['styles'][] = $url;
				}
			} else {
				$profile = null;
			}
			$this->frameworks[$framework] = $profile;
		}

		return $this->frameworks[$framework];
	}

	/**
	 * Remember the stylesheets of a profile as part of the output of this request
	 *
	 * @param array|null $profile
	 *
	 */
	protected function expectStyles($profile) {
		if(!is_array($profile) || empty($profile['styles'])) return;
		foreach($profile['styles'] as $url) {
			if(!isset($this->frameworkCss[$url])) $this->frameworkCss[$url] = false;
		}
	}

	/**
	 * Get the attributes of a part of the markup, with what the CSS framework in use adds to them
	 *
	 * @param string $part Name of the part, see SelfHostedVideoFrameworks::getParts()
	 * @param array $attrs Attributes of the element, `class` among them
	 * @param array $variants More parts this element is
	 * @param array|null|bool $framework Profile to use. By default the one of the video being
	 *   rendered; outside of that, pass true for the one chosen in the module settings.
	 * @return array
	 *
	 */
	public function part($part, array $attrs = array(), array $variants = array(), $framework = false) {
		if($framework === true) $framework = $this->getFramework(null);
		if($framework === false) $framework = $this->framework;
		return SelfHostedVideoFrameworks::apply($framework, $part, $attrs, $variants);
	}

	/**
	 * Render the attributes of a part of the markup
	 *
	 * @param string $part
	 * @param array $attrs
	 * @param array $variants
	 * @return string With a leading space
	 *
	 */
	protected function partString($part, array $attrs = array(), array $variants = array()) {
		return $this->attrString($this->part($part, $attrs, $variants));
	}

	/**
	 * Get a number that changes whenever reset() is called
	 *
	 * #pw-internal
	 *
	 * @return int
	 *
	 */
	public function generation() {
		return $this->generation;
	}

	/**
	 * Make sure that a finished page with videos has the stylesheet, the script and the
	 * structured data of its videos
	 *
	 * They are added with the first rendering of a video. That output does not always reach
	 * the page: many templates read a text field once to work with it (a summary, a search
	 * index) and print it later, or render something they do not use. The videos that do reach
	 * the page would then be without their styles, thumbnails, script and structured data.
	 *
	 * @param string $out Markup of a page, or of a part of one
	 * @return string
	 *
	 */
	public function completeAssets($out) {

		if(!is_string($out) || strpos($out, 'data-shv=') === false) return $out;

		// the same goes for the structured data of a video, which is output once
		$schema = '';
		foreach($this->schemaRendered as $item) {
			if(!is_array($item) || !strlen($item['script']) || !strlen($item['src'])) continue;
			if(strpos($out, $item['src']) !== false && strpos($out, $item['script']) === false) $schema .= $item['script'];
		}
		if(strlen($schema)) {
			$at = stripos($out, '</body>');
			$out = $at === false ? $out . $schema : substr($out, 0, $at) . $schema . substr($out, $at);
		}

		$out = $this->completeOpenGraph($out);

		if(!$this->useAutoAssets()) return $out;

		$sanitizer = $this->wire()->sanitizer;
		$urls = $this->assetUrls();
		$add = '';

		if(strpos($out, '/SelfHostedVideo.css') === false) $add .= "<link rel='stylesheet' href='" . $sanitizer->entities($urls['css']) . "'>";
		// the stylesheet of a profile comes after the module's, whose rules it may change
		foreach(array_keys($this->frameworkCss) as $css) {
			if(strpos($out, $sanitizer->entities($css)) === false) $add .= "<link rel='stylesheet' href='" . $sanitizer->entities($css) . "'>";
		}
		if(strpos($out, '/SelfHostedVideo.js') === false) $add .= "<script src='" . $sanitizer->entities($urls['js']) . "' defer></script>";
		if(!strlen($add)) return $out;

		$this->assetsRendered = true;

		$at = stripos($out, '</head>');
		if($at === false) {
			// a part of a page: in front of its first video
			$at = strrpos(substr($out, 0, strpos($out, 'data-shv=')), '<');
			if($at === false) return $add . $out;
		}

		return substr($out, 0, $at) . $add . substr($out, $at);
	}

	/**
	 * Has this video been rendered during this request, i.e. by a tag in a text?
	 *
	 * @param Pagevideo $video
	 * @return bool
	 *
	 */
	public function wasRendered(Pagevideo $video) {
		return isset($this->rendered[$this->videoKey($video)]);
	}

	/**
	 * Get what tells a video apart from all others of the site, whether it is an original or a reuse of one
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function videoKey(Pagevideo $video) {
		if($video->isReference()) return ((int) $video->get('ref_pages_id')) . '/' . $video->get('ref_name');
		return ($video->page ? (int) $video->page->id : 0) . '/' . $video->basename();
	}

	/**
	 * Render a video with controls
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 * @return string
	 *
	 */
	protected function renderPlayer(Pagevideo $video, array $options) {

		$sanitizer = $this->wire()->sanitizer;

		$title = $this->plainText($video, $video->title());
		$description = $this->plainText($video, $video->description());
		$width = $video->width();
		$height = $video->height();
		$ratio = $width && $height ? $width / $height : 0;

		// video element
		$attrs = array('class' => 'shv-video__video');
		if($options['controls']) $attrs['controls'] = true;
		if($options['autoplay']) $attrs['autoplay'] = true;
		if($options['muted'] || $options['autoplay']) $attrs['muted'] = true; // browsers only start muted videos by themselves
		if($options['loop']) $attrs['loop'] = true;
		if($options['playsinline']) $attrs['playsinline'] = true;
		$attrs['preload'] = $this->preloadValue($options);
		if($width && $height) {
			$attrs['width'] = $width;
			$attrs['height'] = $height;
		}
		$poster = $this->posterUrl($video, $options);
		if(strlen($poster)) {
			// a lazy thumbnail is fetched by the script once the video comes near the visible part of the page
			$lazyPoster = $options['lazy'] && !$options['autoplay'];
			$attrs[$lazyPoster ? 'data-shv-poster' : 'poster'] = $poster;
		}
		if(strlen($title)) $attrs['aria-label'] = $title;

		$this->playerCount++;
		$key = $this->linkKey($video);
		$start = $this->startTime($video, $options, $key);

		// overlays lie on the picture. The browser's own full screen would show the video without
		// them, so the module offers a button of its own that takes the overlays along.
		$overlays = $options['overlays'] ? $this->renderOverlays($video) : '';
		$fullscreen = $options['fullscreen'] === null ? strlen($overlays) > 0 : (bool) $options['fullscreen'];
		$button = '';
		if($fullscreen && $options['controls']) {
			$enter = $this->_('Full screen');
			$button =
				"<button" . $this->partString('fullscreen', array(
					'type' => 'button',
					'class' => 'shv-video__fullscreen',
					'data-label-enter' => $enter,
					'data-label-leave' => $this->_('Leave full screen'),
					'aria-label' => $enter,
					'hidden' => true, // shown by the script, where the browser can do it
				)) . ">" .
					"<svg class='shv-video__icon shv-video__icon--enter' viewBox='0 0 16 16' aria-hidden='true'><path d='M2 2h5v2H4v3H2zM9 2h5v5h-2V4H9zM2 9h2v3h3v2H2zM12 9h2v5H9v-2h3z'/></svg>" .
					"<svg class='shv-video__icon shv-video__icon--leave' viewBox='0 0 16 16' aria-hidden='true'><path d='M5 2h2v5H2V5h3zM9 2h2v3h3v2H9zM2 9h5v5H5v-3H2zM9 9h5v2h-3v3H9z'/></svg>" .
				"</button>";
		}

		$attrs = $this->mergeAttrs($this->part('video', $attrs), $options['attrs']);

		$src = (string) $video->get('URL');
		$download = $this->_('Download the video');
		if(strlen($title)) $download = sprintf($this->_('Download the video “%s”'), $title);
		$fallback =
			"<p" . $this->partString('fallback', array('class' => 'shv-video__fallback')) . ">" .
				$sanitizer->entities($this->_('Your browser cannot play this video.')) . ' ' .
				"<a" . $this->partString('download', array('href' => $src)) . ">" .
					$sanitizer->entities($download) .
				"</a>" .
			"</p>";

		$videoTag = "<video" . $this->attrString($attrs) . ">" . $this->renderSource($video, $start) . $this->renderTracks($video, $options) . $fallback . "</video>";

		// going on where the visitor stopped, and what is offered when the video has ended
		// (true: videos of a minute or longer; a number: of so many seconds or longer)
		$resumeMin = $options['resume'] === true ? self::resumeMinDuration : max(0, (float) $options['resume']);
		$resume = $options['resume'] && $options['controls'] && !$options['loop'] && !$options['autoplay'] && !$start
			&& (float) $video->duration() >= $resumeMin;
		$extras = ($resume ? $this->renderResume() : '') . ($options['end'] && $options['controls'] && !$options['loop'] ? $this->renderEnd($video) : '');

		// wrapper
		$classes = array('shv-video');
		if($ratio) $classes[] = 'shv-video--' . ($ratio < 0.999 ? 'portrait' : ($ratio > 1.001 ? 'landscape' : 'square'));
		if(strlen((string) $options['class'])) $classes[] = (string) $options['class'];

		$id = $this->uniqueId(strlen((string) $options['id']) ? (string) $options['id'] : "video-$key");

		$wrap = array('class' => implode(' ', $classes), 'data-shv' => 'player', 'id' => $id, 'data-shv-key' => $key);
		$source = $video->isReference() ? $video->original() : $video;
		if($source && $source->page && $source->page->id) $wrap['data-shv-video'] = $source->page->id . ':' . $source->basename();
		if($resume) $wrap['data-shv-resume'] = '1';
		$statsUrl = $this->statsUrl();
		if(strlen($statsUrl) && isset($wrap['data-shv-video'])) $wrap['data-shv-stats'] = $statsUrl;
		if($this->framework) $wrap['data-shv-framework'] = $this->framework['name'];
		if(strlen($title)) $wrap['data-shv-title'] = $title;
		if(!$options['single']) $wrap['data-shv-single'] = '0';
		if(!$options['pauseOutOfView']) $wrap['data-shv-pause'] = '0';
		if($ratio) $wrap['style'] = '--shv-ratio:' . rtrim(rtrim(number_format($ratio, 4, '.', ''), '0'), '.');

		// Below the picture, in this order: title, duration, description, the box with more about
		// the video, the transcript, chapters. With a title or description the video is a figure. Its caption has to
		// be the last part of a figure, so the box and the chapters are then the end of the caption,
		// and the figure takes its name from the title alone (or the description), not from all of it.
		$chapters = $options['chapters'] ? $this->renderChapters($video, $id, $key) : '';
		$info = $this->renderInfo($video, $options);
		$transcript = $this->renderTranscript($video, $options, $id, $key);
		$caption = $this->renderCaption($video, $options, $title, $description, $id);
		$below = $info . $transcript . $chapters;

		$tag = 'div';
		if(strlen($caption['out'])) {
			$tag = 'figure';
			if(strlen($caption['name'])) $wrap['aria-labelledby'] = $caption['name'];
			$below = "<figcaption" . $this->partString('caption', array('class' => 'shv-video__caption')) . ">$caption[out]$below</figcaption>";
		}

		return
			"<$tag" . $this->partString('player', $wrap) . ">" .
				"<div" . $this->partString('screen', array('class' => 'shv-video__screen')) . ">" .
					"<div" . $this->partString('frame', array('class' => 'shv-video__frame')) . ">$videoTag$overlays$extras</div>" .
					$button .
				"</div>" .
				$below .
			"</$tag>";
	}

	/**
	 * Get what the custom fields of a video say, ready to be shown
	 *
	 * Custom fields are the core's: the fields of a template named "field-" and the name of
	 * the video field. A reused video has those of its original. Fields without a value are
	 * left out, and so is a checkbox that is not checked.
	 *
	 * @param Pagevideo $video
	 * @param array $names Names of the fields to get, in the order wanted. Default: all, in the order of the template.
	 * @return array of [ `name` (string), `label` (string, plain text), `value` (string, markup) ]
	 *
	 */
	public function infoItems(Pagevideo $video, array $names = array()) {

		$source = $video->isReference() ? $video->original() : $video;
		if(!$source instanceof Pagevideo || !$source->pagefiles) return array();

		$template = $source->pagefiles->getFieldsTemplate();
		if(!$template) return array();

		$fieldgroup = $template->fieldgroup;
		$fields = array();

		if(count($names)) {
			foreach($names as $name) {
				$field = is_string($name) && strlen($name) ? $fieldgroup->getFieldContext($name) : null;
				if($field) $fields[$field->name] = $field;
			}
		} else {
			foreach($fieldgroup as $field) {
				$field = $fieldgroup->getFieldContext($field);
				if($field) $fields[$field->name] = $field;
			}
		}

		$items = array();

		foreach($fields as $field) {
			/** @var Field $field */
			if($field->type instanceof FieldtypeFieldsetOpen || $field->type instanceof FieldtypeFieldsetClose) continue;
			$value = $this->infoValue($field, $source->getFieldValue($field->name, true), $source->getFieldValue($field->name, false));
			if(!strlen($value)) continue;
			$items[] = array(
				'name' => $field->name,
				'label' => (string) $field->getLabel(),
				'value' => $value,
			);
		}

		return $items;
	}

	/**
	 * Write the value of a custom field as markup for the website
	 *
	 * @param Field $field
	 * @param mixed $value The value as the field formats it for output
	 * @param mixed $raw The value as it is stored
	 * @return string Blank if there is nothing to show
	 *
	 */
	protected function infoValue(Field $field, $value, $raw) {

		$sanitizer = $this->wire()->sanitizer;
		$type = $field->type;

		if($value === null || $value === false || $value === '') return '';

		if($type instanceof FieldtypeCheckbox) {
			return $value ? $sanitizer->entities($this->_('Yes')) : '';
		}

		if($type instanceof FieldtypeToggle) {
			// yes, no, or not answered. A field that hands out a word of its own for it is taken by that word.
			if(is_string($value) && strlen(trim($value)) && !is_numeric($value)) return $sanitizer->entities1(trim($value));
			if($raw === 1 || $raw === '1' || $raw === true) return $sanitizer->entities($this->_('Yes'));
			if($raw === 0 || $raw === '0' || $raw === false) return $sanitizer->entities($this->_('No'));
			return '';
		}

		if($value === true) return $sanitizer->entities($this->_('Yes'));

		if($value instanceof Page) {
			return $value->id ? $sanitizer->entities1((string) $value->get('title|name')) : '';
		}

		if($value instanceof WireArray || is_array($value)) {
			// pages, options: their titles, one after the other
			$texts = array();
			foreach($value as $item) {
				if($item instanceof Page) {
					$text = $item->id ? (string) $item->get('title|name') : '';
				} else if($item instanceof WireData && strlen((string) $item->get('title'))) {
					$text = (string) $item->get('title');
				} else if(is_scalar($item) || (is_object($item) && method_exists($item, '__toString'))) {
					$text = (string) $item;
				} else {
					$text = '';
				}
				$text = trim($text);
				if(strlen($text)) $texts[] = $sanitizer->entities1($text);
			}
			return implode(', ', $texts);
		}

		if($type instanceof FieldtypeDatetime && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
			// a date without a format of its own: as the site writes dates
			return (int) $value ? $sanitizer->entities(wireDate((string) $this->wire()->config->dateFormat, (int) $value)) : '';
		}

		if(is_int($value) || is_float($value)) return $sanitizer->entities((string) $value);

		if(is_object($value)) {
			if(!method_exists($value, '__toString')) return '';
			return $sanitizer->entities1(trim((string) $value));
		}

		$text = trim((string) $value);
		if(!strlen($text)) return '';

		// not a text field (a date in the format of its field, for one): what the field hands out, as text
		if(!$type instanceof FieldtypeText) return $sanitizer->entities1($text);

		if($type instanceof FieldtypeURL) {
			// an address: a link to it
			$url = $sanitizer->entities(trim((string) $raw));
			return strlen($url) ? "<a href='$url'>$url</a>" : '';
		}

		// Text that the field hands out as markup is taken as it is: rich text, and text that
		// goes through text formatters (which is how ProcessWire encodes text for output).
		$isMarkup = $type instanceof FieldtypeTextarea && (int) $field->get('contentType') > 0;
		$formatters = $field->get('textformatters');
		if($isMarkup || (is_array($formatters) && count($formatters))) return $text;

		// any other text is written as text, with its line breaks
		return nl2br($sanitizer->entities(trim((string) $raw)));
	}

	/**
	 * Render the box with more about a video: what its custom fields say, in a part that opens
	 *
	 * A details element: it opens and closes without a script, is found by the search of the
	 * browser, and screen readers know it. The fields are a description list of names and values.
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 * @return string Blank if the option is off or no field has a value
	 *
	 */
	protected function renderInfo(Pagevideo $video, array $options) {

		$info = $options['info'];
		if(!$info) return '';

		$sanitizer = $this->wire()->sanitizer;
		$items = $video->info(is_array($info) ? $info : array());
		if(!is_array($items) || !count($items)) return '';

		$list = '';
		foreach($items as $item) {
			if(!is_array($item) || !isset($item['label'], $item['value']) || !strlen((string) $item['value'])) continue;
			$name = isset($item['name']) ? (string) $item['name'] : '';
			$list .=
				"<dt" . $this->partString('info-term', array('class' => 'shv-video__info-term', 'data-shv-info' => $name)) . ">" . $sanitizer->entities1((string) $item['label']) . "</dt>" .
				"<dd" . $this->partString('info-value', array('class' => 'shv-video__info-value', 'data-shv-info' => $name)) . ">" . $item['value'] . "</dd>";
		}
		if(!strlen($list)) return '';

		$label = strlen((string) $options['infoLabel']) ? (string) $options['infoLabel'] : $this->_('More about this video');
		$attrs = array('class' => 'shv-video__info');
		if($options['infoOpen'] || $info === 'open') $attrs['open'] = true;

		return
			"<details" . $this->partString('info', $attrs) . ">" .
				"<summary" . $this->partString('info-summary', array('class' => 'shv-video__info-summary')) . ">" . $sanitizer->entities1($label) . "</summary>" .
				"<dl" . $this->partString('info-list', array('class' => 'shv-video__info-list')) . ">$list</dl>" .
			"</details>";
	}

	/**
	 * Render the transcript of a video: the text of its captions, in a box that opens
	 *
	 * Each paragraph begins with the time it is spoken at, a link that plays the video from
	 * there (handled by the script like the links of chapters, and a link to that moment of the
	 * page without it). Where the captions name who speaks, the name is written in front of
	 * the paragraph each time another voice begins.
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 * @param string $id Id of the element around the video
	 * @param string $key Name of the video in links
	 * @return string Blank if the option is off or the video has no captions
	 *
	 */
	protected function renderTranscript(Pagevideo $video, array $options, $id, $key) {

		$transcript = $options['transcript'];
		if(!$transcript) return '';

		$paragraphs = $video->transcript((string) $options['transcriptLang']);
		if(!is_array($paragraphs) || !count($paragraphs)) return '';

		$sanitizer = $this->wire()->sanitizer;
		$playFrom = $sanitizer->entities($this->_('Play from'));
		$items = '';
		$voice = '';
		$lang = '';

		foreach($paragraphs as $p) {
			if(!is_array($p) || !isset($p['time'], $p['text'])) continue;
			$time = (float) $p['time'];
			$timeStr = isset($p['timeStr']) ? (string) $p['timeStr'] : SelfHostedVideoTimeline::formatTime($time);
			if(!$lang && !empty($p['lang'])) $lang = (string) $p['lang'];
			$speaker = '';
			$name = isset($p['voice']) ? (string) $p['voice'] : '';
			if(strlen($name) && $name !== $voice) {
				$speaker = "<span" . $this->partString('transcript-voice', array('class' => 'shv-video__transcript-voice')) . ">" . $sanitizer->entities($name) . ":</span> ";
			}
			if(strlen($name)) $voice = $name;
			$link = array(
				'class' => 'shv-video__transcript-time',
				'href' => $this->momentUrl($key, $time, false) . "#$id",
				'data-shv-seek' => $this->number($time),
			);
			$items .=
				"<p" . $this->partString('transcript-item', array('class' => 'shv-video__transcript-item')) . ">" .
					"<a" . $this->partString('transcript-time', $link) . ">" .
						"<span class='shv-video__hidden'>$playFrom </span>" . $sanitizer->entities($timeStr) .
					"</a> " .
					$speaker . $sanitizer->entities((string) $p['text']) .
				"</p>";
		}
		if(!strlen($items)) return '';

		$label = strlen((string) $options['transcriptLabel']) ? (string) $options['transcriptLabel'] : $this->_('Transcript');
		$attrs = array('class' => 'shv-video__transcript');
		if($options['transcriptOpen'] || $transcript === 'open') $attrs['open'] = true;
		$body = array('class' => 'shv-video__transcript-body');
		// the language of the captions, where the page may be in another one
		if($lang) $body['lang'] = $lang;

		return
			"<details" . $this->partString('transcript', $attrs) . ">" .
				"<summary" . $this->partString('transcript-summary', array('class' => 'shv-video__transcript-summary')) . ">" . $sanitizer->entities1($label) . "</summary>" .
				"<div" . $this->partString('transcript-body', $body) . ">$items</div>" .
			"</details>";
	}

	/**
	 * Render the overlays of a video: text and links on the picture
	 *
	 * Overlays that are shown during the whole video are simply there. Those for a part of
	 * the video or for its end start hidden and are shown by the script when their time comes.
	 *
	 * @param Pagevideo $video
	 * @return string Blank if the video has none
	 *
	 */
	protected function renderOverlays(Pagevideo $video) {

		$sanitizer = $this->wire()->sanitizer;
		$out = '';

		foreach($video->overlays() as $overlay) {
			$text = $this->plainText($video, $overlay->text);
			if(!strlen($text)) continue;

			$url = $video->formatted() ? html_entity_decode((string) $overlay->url, ENT_QUOTES | ENT_HTML5, 'UTF-8') : (string) $overlay->url;
			$style = 'left:' . $this->number($overlay->x) . '%;top:' . $this->number($overlay->y) . '%';
			if($overlay->width > 0) $style .= ';width:' . $this->number($overlay->width) . '%;max-width:none';

			$attrs = array(
				'class' =>
					'shv-video__overlay' .
					' shv-video__overlay--' . ($overlay->look === 'light' ? 'light' : 'dark') .
					' shv-video__overlay--' . (in_array($overlay->size, array('s', 'm', 'l'), true) ? $overlay->size : 'm') .
					' shv-video__overlay--' . $overlay->anchorX .
					' shv-video__overlay--' . $overlay->anchorY,
				'style' => $style,
				'data-shv-overlay' => $overlay->id,
			);

			if($overlay->when === 'range') {
				$attrs['data-when'] = 'range';
				$attrs['data-start'] = $this->number($overlay->start);
				if($overlay->end > 0) $attrs['data-end'] = $this->number($overlay->end);
				if($overlay->start > 0) $attrs['hidden'] = true;
			} else if($overlay->when === 'end') {
				$attrs['data-when'] = 'end';
				$attrs['hidden'] = true;
			}

			$variants = array(
				'overlay-' . ($overlay->look === 'light' ? 'light' : 'dark'),
				'overlay-' . (in_array($overlay->size, array('s', 'm', 'l'), true) ? $overlay->size : 'm'),
			);

			if(strlen($url)) {
				$variants[] = 'overlay-link';
				$attrs = $this->part('overlay', array('href' => $url) + $attrs, $variants);
				$out .= "<a" . $this->attrString($attrs) . ">" . $sanitizer->entities($text) . "</a>";
			} else {
				$attrs = $this->part('overlay', $attrs, $variants);
				$out .= "<div" . $this->attrString($attrs) . ">" . $sanitizer->entities($text) . "</div>";
			}
		}

		if(!strlen($out)) return '';

		// announced to screen reader users when they appear, since they cannot see them come up
		$label = $sanitizer->entities($this->_('Notes on the video'));

		return "<div" . $this->partString('overlays', array('class' => 'shv-video__overlays')) . " role='group' aria-label='$label' aria-live='polite'>$out</div>";
	}

	/**
	 * Render the list of chapters of a video
	 *
	 * Each chapter is a link to its moment. With the script it moves the video there and plays it.
	 * Without, the link loads the page again with the video set to start at that moment.
	 *
	 * @param Pagevideo $video
	 * @param string $id The id attribute of the video
	 * @param string $key Name of the video in links
	 * @return string Blank if the video has no chapters
	 *
	 */
	protected function renderChapters(Pagevideo $video, $id, $key) {

		$sanitizer = $this->wire()->sanitizer;
		$items = '';

		foreach($video->chapters() as $chapter) {
			$title = $this->plainText($video, $chapter->title);
			if(!strlen($title)) continue;
			$attrs = array(
				'class' => 'shv-video__chapter',
				'href' => $this->momentUrl($key, $chapter->time, false) . "#$id",
				'data-shv-seek' => $this->number($chapter->time),
			);
			$items .=
				"<li" . $this->partString('chapter-item', array('class' => 'shv-video__chapter-item')) . ">" .
					"<a" . $this->partString('chapter', $attrs) . ">" .
						"<span" . $this->partString('chapter-time', array('class' => 'shv-video__chapter-time')) . ">" . $sanitizer->entities($chapter->timeStr) . "</span> " .
						"<span" . $this->partString('chapter-title', array('class' => 'shv-video__chapter-title')) . ">" . $sanitizer->entities($title) . "</span>" .
					"</a>" .
				"</li>";
		}

		if(!strlen($items)) return '';

		$label = $sanitizer->entities($this->_('Chapters'));

		return
			"<div" . $this->partString('chapters', array('class' => 'shv-video__chapters')) . ">" .
				"<p" . $this->partString('chapters-label', array('class' => 'shv-video__chapters-label', 'id' => "$id-chapters")) . ">$label</p>" .
				"<ol" . $this->partString('chapter-list', array('class' => 'shv-video__chapter-list', 'role' => 'list', 'aria-labelledby' => "$id-chapters")) . ">$items</ol>" .
			"</div>";
	}

	/**
	 * Get the name of a video in links to a moment of it, i.e. "intro-mp4" for intro.mp4
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	public function linkKey(Pagevideo $video) {
		$source = $video->isReference() ? $video->original() : $video;
		if(!$source) return 'video';
		$key = $this->wire()->sanitizer->pageName($source->basename(false) . '-' . $source->ext(), true);
		return strlen($key) ? $key : 'video';
	}

	/**
	 * Get the address of a moment of a video on the page being shown
	 *
	 * The address is that of the current page with two parameters added: `v` (which video) and `t` (seconds).
	 *
	 * @param string $key Name of the video in links, see linkKey()
	 * @param float $time Seconds
	 * @param bool $http Include scheme and hostname?
	 * @return string
	 *
	 */
	public function momentUrl($key, $time, $http = false) {
		$page = $this->wire()->page;
		$input = $this->wire()->input;
		$params = array();
		foreach($input->get->getArray() as $name => $value) {
			// what else is in the address stays, as far as it is plain
			if($name === 'v' || $name === 't' || $name === 'it' || !is_scalar($value)) continue;
			$params[$name] = $value;
		}
		$params['v'] = $key;
		$params['t'] = $this->number($time);
		$url = $page && $page->id && $page->template != 'admin' ? ($http ? $page->httpUrl() : $page->url()) : '';
		if($page && $page->id && $page->template != 'admin') {
			$segments = $input->urlSegmentStr();
			if(strlen($segments)) $url = rtrim($url, '/') . '/' . $segments . ($page->template->slashUrlSegments === 0 ? '' : '/');
		}
		return $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Get the moment a video is to start at, in seconds
	 *
	 * Either given with the `start` option, or asked for in the address of the page
	 * (`?t=83`, with `v=name` when the page has more than one video).
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 * @param string $key Name of the video in links
	 * @return float 0 for the beginning
	 *
	 */
	protected function startTime(Pagevideo $video, array $options, $key) {

		$start = null;

		if($options['start'] !== null && $options['start'] !== '' && $options['start'] !== false) {
			$start = SelfHostedVideoTimeline::parseTime($options['start']);
		} else {
			$input = $this->wire()->input;
			$t = $input->get('t');
			$v = (string) $input->get('v');
			if($t !== null && is_scalar($t) && (strlen($v) ? $v === $key : $this->playerCount === 1)) {
				$start = SelfHostedVideoTimeline::parseTime(preg_replace('/s$/', '', (string) $t));
			}
		}

		if($start === null || $start <= 0) return 0.0;

		$duration = (float) $video->duration();
		if($duration > 0 && $start >= $duration) return 0.0;

		return round($start, 1);
	}

	/**
	 * Make an id attribute that is not used on the page yet
	 *
	 * @param string $id
	 * @return string
	 *
	 */
	protected function uniqueId($id) {
		$id = preg_replace('/[^A-Za-z0-9_:.-]/', '-', $id);
		if(!strlen($id)) $id = 'video';
		$unique = $id;
		$n = 1;
		while(isset($this->usedIds[$unique])) $unique = $id . '-' . (++$n);
		$this->usedIds[$unique] = true;
		return $unique;
	}

	/**
	 * Write a number for markup: with a point, and without needless zeros
	 *
	 * @param float $value
	 * @return string
	 *
	 */
	protected function number($value) {
		$out = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
		return strlen($out) ? $out : '0';
	}

	/**
	 * Render a background video: muted, repeating, without controls, filling its container
	 *
	 * The video is decoration, so it is hidden from assistive technology. It is started by the
	 * script once it is visible, unless the visitor asked for reduced motion or data saving.
	 * The button that follows it lets everyone pause and play it.
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 * @return string
	 *
	 */
	protected function renderBackground(Pagevideo $video, array $options) {

		$sanitizer = $this->wire()->sanitizer;

		$attrs = array(
			'class' => 'shv-video__video',
			'muted' => true,
			'loop' => true,
			'playsinline' => true,
			'preload' => 'none',
			'disablepictureinpicture' => true,
			'disableremoteplayback' => true,
			'aria-hidden' => 'true',
			'tabindex' => '-1',
		);
		$poster = $this->posterUrl($video, $options);
		if(strlen($poster)) $attrs[$options['lazy'] ? 'data-shv-poster' : 'poster'] = $poster;
		$attrs = $this->mergeAttrs($this->part('video', $attrs), $options['attrs']);

		$classes = array('shv-video', 'shv-video--background');
		if(strlen((string) $options['class'])) $classes[] = (string) $options['class'];
		$wrap = array('class' => implode(' ', $classes), 'data-shv' => 'background');
		if(strlen((string) $options['id'])) $wrap['id'] = (string) $options['id'];
		$shade = (float) $options['shade'];
		if($shade > 0) $wrap['style'] = '--shv-shade:' . rtrim(rtrim(number_format(min(1.0, $shade), 2, '.', ''), '0'), '.');
		if($this->framework) $wrap['data-shv-framework'] = $this->framework['name'];

		$out = "<div" . $this->partString('background', $wrap) . "><video" . $this->attrString($attrs) . ">" . $this->renderSource($video) . "</video></div>";

		if($options['toggle']) {
			$pause = $this->_('Pause background video');
			$play = $this->_('Play background video');
			$button = array(
				'type' => 'button',
				'class' => 'shv-video__toggle',
				'data-shv-toggle' => true,
				'data-state' => 'paused',
				'data-label-pause' => $pause,
				'data-label-play' => $play,
				'aria-label' => $play,
				'hidden' => true, // shown by the script, as it does nothing without it
			);
			$out .=
				"<button" . $this->partString('toggle', $button) . ">" .
					"<svg class='shv-video__icon shv-video__icon--pause' viewBox='0 0 16 16' aria-hidden='true'><path d='M3 2h4v12H3zM9 2h4v12H9z'/></svg>" .
					"<svg class='shv-video__icon shv-video__icon--play' viewBox='0 0 16 16' aria-hidden='true'><path d='M4 2l10 6-10 6z'/></svg>" .
				"</button>";
		}

		return $out;
	}

	/**
	 * Render the source element of a video
	 *
	 * @param Pagevideo $video
	 * @param float $start Moment to start at, in seconds
	 * @return string
	 *
	 */
	protected function renderSource(Pagevideo $video, $start = 0.0) {
		$src = (string) $video->get('URL');
		// a media fragment: browsers start the video at that moment by themselves
		if($start > 0) $src .= '#t=' . $this->number($start);
		$attrs = array('src' => $src);
		$type = $this->mediaType($video);
		if($type) $attrs['type'] = $type;
		return "<source" . $this->attrString($attrs) . ">";
	}

	/**
	 * Render the caption tracks of a video
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 * @return string
	 *
	 */
	protected function renderTracks(Pagevideo $video, array $options) {

		$captions = $options['captions'];
		if($captions === false || $captions === null || $captions === 0 || $captions === '') return '';

		// a language code switches the captions of that language on from the start
		$default = is_string($captions) ? SelfHostedVideoCaptions::cleanLang($captions) : '';
		$out = '';

		foreach($video->captions() as $lang => $track) {
			$attrs = array(
				'kind' => $track->kind,
				'src' => $track->URL,
				'srclang' => $lang,
				'label' => $track->label,
			);
			if($default && ($lang === $default || strpos($lang, "$default-") === 0)) {
				$attrs['default'] = true;
				$default = '';
			}
			$out .= "<track" . $this->attrString($attrs) . ">";
		}

		return $out;
	}

	/**
	 * Render what is written below a video: title, duration and description
	 *
	 * The option `caption` decides which of them: true for those whose own option (`title`,
	 * `duration`, `description`) is on, "title" or "description" for one of them (and the
	 * duration), false for none, any other text to show that in place of title and description.
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 * @param string $title Plain text
	 * @param string $description Plain text
	 * @param string $id Id of the element around the video
	 * @return array [ `out` => markup (blank if there is nothing to show), `name` => id of the part that names the video, or blank ]
	 *
	 */
	protected function renderCaption(Pagevideo $video, array $options, $title, $description, $id) {

		$result = array('out' => '', 'name' => '');
		$caption = $options['caption'];
		if($caption === false || $caption === null || $caption === '' || $caption === 0) return $result;

		$sanitizer = $this->wire()->sanitizer;
		$all = $caption === true || $caption === 1;
		$own = !$all && is_string($caption) && !in_array($caption, array('title', 'description'), true);
		$showTitle = ($all && $options['title']) || $caption === 'title';
		$showDescription = ($all && $options['description']) || $caption === 'description';
		$parts = '';

		if($own) {
			// a text of your own
			$parts .= "<span" . $this->partString('title', array('class' => 'shv-video__title', 'id' => "$id-title")) . ">" . $sanitizer->entities($caption) . "</span>";
			$result['name'] = "$id-title";
		} else if($showTitle && strlen($title)) {
			$parts .= "<span" . $this->partString('title', array('class' => 'shv-video__title', 'id' => "$id-title")) . ">" . $sanitizer->entities($title) . "</span>";
			$result['name'] = "$id-title";
		}

		if($options['duration']) {
			$time = $video->durationStr();
			if(strlen($time)) {
				$parts .=
					"<span" . $this->partString('meta', array('class' => 'shv-video__meta')) . ">" .
						"<span" . $this->partString('duration', array('class' => 'shv-video__duration')) . ">" .
							"<svg class='shv-video__clock' viewBox='0 0 16 16' aria-hidden='true' focusable='false'><path d='M8 1.5a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13zm0 1.5a5 5 0 1 0 0 10A5 5 0 0 0 8 3zm-.75 1.5h1.5v3.19l2.1 2.1-1.06 1.06-2.54-2.54z'/></svg>" .
							"<span class='shv-video__hidden'>" . $sanitizer->entities($this->_('Duration:')) . " </span>" .
							"<time datetime='" . self::isoDuration($video->duration()) . "'>" . $sanitizer->entities($time) . "</time>" .
						"</span>" .
					"</span>";
			}
		}

		if(!$own && $showDescription && strlen($description)) {
			// formatted text may contain markup from the field's text formatters
			$html = $video->formatted() ? (string) $video->description() : $sanitizer->entities($description);
			if(strpos($html, '<') === false) $html = nl2br($html);
			$parts .= "<span" . $this->partString('description', array('class' => 'shv-video__description', 'id' => "$id-description")) . ">$html</span>";
			if(!strlen($result['name'])) $result['name'] = "$id-description";
		}

		// the duration alone is no caption
		if(!strlen($result['name'])) return $result;

		$result['out'] = $parts;
		return $result;
	}

	/**
	 * Get the value for the preload attribute
	 *
	 * @param array $options
	 * @return string
	 *
	 */
	protected function preloadValue(array $options) {
		$preload = strtolower((string) $options['preload']);
		if(in_array($preload, array('none', 'metadata', 'auto'), true)) return $preload;
		return $options['lazy'] ? 'none' : 'metadata';
	}

	/**
	 * Get the URL of the thumbnail to show before the video plays
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 * @return string Blank if there is none
	 *
	 */
	protected function posterUrl(Pagevideo $video, array $options) {

		$poster = $options['poster'];
		if($poster === false || $poster === null || $poster === '' || $poster === 0) return '';

		if(is_string($poster)) return $poster;

		$image = $poster instanceof Pageimage ? $poster : $video->thumb();
		if(!$image instanceof Pageimage) return '';

		$maxWidth = (int) $options['posterWidth'];
		if($maxWidth > 0 && $image->width() > $maxWidth) {
			try {
				$sized = $image->width($maxWidth);
				if($sized instanceof Pageimage && !$sized->error) $image = $sized;
			} catch(\Throwable $e) {
				// the image at its stored size is used
			}
		}

		return (string) $image->get('URL');
	}

	/**
	 * Get the media type of a video file
	 *
	 * @param Pagevideo $video
	 * @return string Blank if not known
	 *
	 */
	public function mediaType(Pagevideo $video) {
		$source = $video->isReference() ? $video->original() : $video;
		$ext = $source ? strtolower($source->ext()) : '';
		return isset(self::$mediaTypes[$ext]) ? self::$mediaTypes[$ext] : '';
	}

	/*** STRUCTURED DATA ************************************************************************/

	/**
	 * Get the structured data (schema.org VideoObject) of a video
	 *
	 * @param Pagevideo $video
	 * @return array Blank array when the video has no file
	 *
	 */
	public function structuredData(Pagevideo $video) {

		$source = $video->isReference() ? $video->original() : $video;
		$url = (string) $video->httpUrl();
		if(!$source || !strlen($url)) return array();

		$title = $this->plainText($video, $video->title());
		$description = $this->plainText($video, $video->description());

		if(!strlen($title)) {
			// a name is required: the file name, made readable
			$title = ucfirst(trim(str_replace(array('-', '_'), ' ', $source->basename(false))));
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@type' => 'VideoObject',
			'name' => $title,
		);

		if(strlen($description)) $data['description'] = $description;

		$thumbUrl = $this->schemaThumb === null ? $this->thumbnailUrl($video) : $this->schemaThumb;
		if(strlen($thumbUrl)) $data['thumbnailUrl'] = array($thumbUrl);

		$created = (int) $source->get('created');
		if($created > 0) $data['uploadDate'] = date('c', $created);

		$duration = $video->duration();
		if($duration > 0) $data['duration'] = self::isoDuration($duration);

		$data['contentUrl'] = $url;

		$type = $this->mediaType($video);
		if($type) $data['encodingFormat'] = $type;

		if($video->width() && $video->height()) {
			$data['width'] = $video->width();
			$data['height'] = $video->height();
		}

		$language = $this->languageCode();
		if(strlen($language)) $data['inLanguage'] = $language;

		$labels = array();
		foreach($video->tagLabels() as $label) {
			$label = $this->plainText($video, $label);
			if(strlen($label)) $labels[] = $label;
		}
		if(count($labels)) $data['keywords'] = implode(', ', $labels);

		// chapters, each with the address of its moment on the page being shown
		$key = $this->linkKey($video);
		$parts = array();
		foreach($video->chapters() as $chapter) {
			$name = $this->plainText($video, $chapter->title);
			if(!strlen($name)) continue;
			$part = array(
				'@type' => 'Clip',
				'name' => $name,
				'startOffset' => (int) round($chapter->time),
			);
			if($chapter->end > $chapter->time) $part['endOffset'] = (int) round($chapter->end);
			$part['url'] = $this->momentUrl($key, $chapter->time, true);
			$parts[] = $part;
		}
		if(count($parts)) $data['hasPart'] = $parts;

		// the text of the captions, as one text
		$transcript = $video->transcriptText();
		if(strlen($transcript)) $data['transcript'] = $transcript;

		return $data;
	}

	/**
	 * Render the structured data of a video as a JSON-LD block, once per video and request
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	public function renderStructuredData(Pagevideo $video) {
		$data = $video->structuredData();
		if(empty($data) || empty($data['contentUrl'])) return '';
		$key = (string) $data['contentUrl'];
		if(isset($this->schemaRendered[$key])) return '';
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
		$json = json_encode($data, $flags);
		$script = $json === false ? '' : "<script type='application/ld+json'>$json</script>";
		// kept, so that it can be added to the finished page should this output never get there
		$this->schemaRendered[$key] = array('script' => $script, 'src' => $this->wire()->sanitizer->entities((string) $video->url()));
		return $script;
	}

	/**
	 * Get the address of the thumbnail of a video as players show it, with scheme and host
	 *
	 * This is the address used in the `poster` of the player (at its default size), in the structured
	 * data, in the video sitemap and in the Open Graph tags: search engines ask for the same one everywhere.
	 *
	 * @param Pagevideo $video
	 * @param int|null $width Width the thumbnail is reduced to, null for the default of render() (1280)
	 * @return string Blank when the video has no thumbnail
	 *
	 */
	public function thumbnailUrl(Pagevideo $video, $width = null) {
		$options = self::getDefaultOptions();
		if($width !== null) $options['posterWidth'] = (int) $width;
		return $this->absoluteUrl($this->posterUrl($video, $options));
	}

	/**
	 * Turn an address of this site into one with scheme and host
	 *
	 * @param string $url
	 * @return string
	 *
	 */
	public function absoluteUrl($url) {
		$url = (string) $url;
		if(!strlen($url) || preg_match('!^[a-z][a-z0-9+.-]*:!i', $url)) return $url;
		$input = $this->wire()->input;
		if(strpos($url, '//') === 0) return ($this->wire()->config->https ? 'https:' : 'http:') . $url;
		if(strpos($url, './') === 0) $url = substr($url, 1); // the root of the site from the command line
		return rtrim($input->httpHostUrl(), '/') . '/' . ltrim($url, '/');
	}

	/**
	 * Get the language of the page being shown as a language code, i.e. "de" or "en-GB"
	 *
	 * The name of the current language when it is a code (as "de" or "en-gb"), otherwise the language
	 * of its locale (the translation of "C" in LanguageSupport, as "de_AT.UTF-8"). Hook after
	 * Pagevideo::structuredData to give a video the language spoken in it, should that differ.
	 *
	 * @param Language|null $language Current language when not given
	 * @return string Blank when not known
	 *
	 */
	public function languageCode($language = null) {
		$languages = $this->wire()->languages;
		if(!$language) $language = $this->wire()->user ? $this->wire()->user->language : null;
		$name = $language && $language->id ? strtolower((string) $language->name) : '';
		if(strlen($name) && $name !== 'default' && preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $name)) {
			return self::languageTag($name);
		}
		$locale = '';
		if($languages && $language && $language->id && method_exists($languages, 'getLocale')) {
			$locale = (string) $languages->getLocale(LC_ALL, $language);
		}
		if(!strlen($locale)) $locale = (string) setlocale(LC_CTYPE, 0);
		$locale = preg_replace('/[.@].*$/', '', $locale);
		if(!preg_match('/^[a-z]{2,3}([_-][a-z0-9]{2,8})?$/i', $locale)) return '';
		return self::languageTag(str_replace('_', '-', $locale));
	}

	/**
	 * Write a language code in the usual way: "de-at" becomes "de-AT"
	 *
	 * @param string $code
	 * @return string
	 *
	 */
	protected static function languageTag($code) {
		$parts = explode('-', strtolower($code));
		foreach($parts as $n => $part) if($n && strlen($part) === 2) $parts[$n] = strtoupper($part);
		return implode('-', $parts);
	}

	/**
	 * Render the Open Graph tags of a video, for the `<head>` of the page
	 *
	 * Social networks and messengers show the video's thumbnail when the page is shared, and some
	 * of them the video itself. Title and description of the shared page are those of the page, so
	 * they are left to the template. Protected videos have none: those services cannot load them.
	 *
	 * @param Pagevideo $video
	 * @param array $options
	 *  - `image` (bool): Add `og:image` with the thumbnail? (default=true)
	 *  - `video` (bool): Add `og:video` with the file? (default=true)
	 * @return string
	 *
	 */
	public function metaTags(Pagevideo $video, array $options = array()) {

		$options = array_merge(array('image' => true, 'video' => true), $options);
		if($video->isBroken() || $video->isProtected()) return '';
		$source = $video->isReference() ? $video->original() : $video;
		if(!$source) return '';

		$sanitizer = $this->wire()->sanitizer;
		$tags = array();

		if($options['video']) {
			$url = (string) $video->httpUrl();
			if(strlen($url)) {
				$tags['og:video'] = $url;
				if(stripos($url, 'https:') === 0) $tags['og:video:secure_url'] = $url;
				$type = $this->mediaType($video);
				if($type) $tags['og:video:type'] = $type;
				if($video->width() && $video->height()) {
					$tags['og:video:width'] = $video->width();
					$tags['og:video:height'] = $video->height();
				}
			}
		}

		if($options['image']) {
			$thumb = $this->thumbnailUrl($video);
			if(strlen($thumb)) {
				$tags['og:image'] = $thumb;
				if(stripos($thumb, 'https:') === 0) $tags['og:image:secure_url'] = $thumb;
				$alt = $this->plainText($video, $video->title());
				if(strlen($alt)) $tags['og:image:alt'] = $alt;
			}
		}

		$out = '';
		foreach($tags as $property => $content) {
			$out .= "<meta property='$property' content='" . $sanitizer->entities((string) $content) . "'>\n";
		}
		return $out;
	}

	/**
	 * Does a text of the video's page show it with a tag (text formatter "Videos in text")?
	 *
	 * @param Pagevideo $video
	 * @return bool
	 *
	 */
	public function isInText(Pagevideo $video) {
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		return $fieldtype instanceof FieldtypeSelfHostedVideo ? $fieldtype->usage()->isInText($video) : false;
	}

	/**
	 * Shortest video (seconds) whose place is remembered for the visitor
	 *
	 */
	const resumeMinDuration = 60;

	/**
	 * Address the script reports plays to, blank when the statistics are off
	 *
	 * @return string
	 *
	 */
	public function statsUrl() {
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		if(!$fieldtype instanceof FieldtypeSelfHostedVideo || !$fieldtype->useStats()) return '';
		return $this->wire()->config->urls->root . SelfHostedVideoStats::path;
	}

	/**
	 * Render the note shown when a video goes on where the visitor stopped (filled by the script)
	 *
	 * @return string
	 *
	 */
	protected function renderResume() {
		$sanitizer = $this->wire()->sanitizer;
		return
			"<div" . $this->partString('resume', array(
				'class' => 'shv-video__resume',
				'role' => 'status',
				'hidden' => true,
				// %s: the moment, as 3:12
				'data-text' => $this->_('Continued at %s'),
			)) . ">" .
				"<span class='shv-video__resume-text'></span> " .
				"<button" . $this->partString('restart', array('type' => 'button', 'class' => 'shv-video__restart')) . ">" .
					$sanitizer->entities($this->_('From the beginning')) .
				"</button>" .
			"</div>";
	}

	/**
	 * Does a link lead to another website?
	 *
	 * Links within the site (an address such as "/contact/", or one with a host name of the
	 * site: $config->httpHosts, with or without "www.") are not, nor are mailto: and tel: links.
	 *
	 * @param string $url
	 * @return bool
	 *
	 */
	public function isExternalLink($url) {
		$url = trim((string) $url);
		if(!preg_match('~^(https?:)?//([^/?#:]+)~i', $url, $m)) return false;
		$host = preg_replace('/^www\./', '', strtolower($m[2]));
		$config = $this->wire()->config;
		$own = is_array($config->httpHosts) ? $config->httpHosts : array();
		$own[] = (string) $config->httpHost;
		foreach($own as $siteHost) {
			$siteHost = preg_replace('/^www\./', '', strtolower(preg_replace('/:\d+$/', '', (string) $siteHost)));
			if(strlen($siteHost) && $siteHost === $host) return false;
		}
		return true;
	}

	/**
	 * Render what the player offers when the video has ended (shown by the script)
	 *
	 * @param Pagevideo $video
	 * @return string Blank when the video has no end action
	 *
	 */
	protected function renderEnd(Pagevideo $video) {

		$action = $video->endAction();
		if($action === '') return '';
		$sanitizer = $this->wire()->sanitizer;
		$buttons = '';

		if($action === 'link') {
			$url = $video->endLink();
			if(!strlen($url)) return '';
			$text = $this->plainText($video, $video->endText());
			if(!strlen($text)) $text = $this->_('More');
			$attrs = array('class' => 'shv-video__end-link', 'href' => $url);
			$after = '';
			if($this->isExternalLink($url)) {
				// a page of another website opens in a new window, and says so
				$attrs['target'] = '_blank';
				$attrs['rel'] = 'noopener';
				$attrs['class'] .= ' shv-video__end-link--external';
				$newWindow = $this->_('(opens in a new window)');
				$after =
					"<svg class='shv-video__end-external' viewBox='0 0 16 16' width='14' height='14' aria-hidden='true' focusable='false'>" .
						"<path d='M9 2h5v5M14 2 7 9M12 9.5V13a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3.5' fill='none' stroke='currentColor' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/>" .
					"</svg>" .
					"<span class='shv-video__hidden'> " . $sanitizer->entities($newWindow) . "</span>";
			}
			$buttons .= "<a" . $this->partString('end-link', $attrs) . ">" . $sanitizer->entities($text) . $after . "</a>";
		} else if($action === 'next') {
			$next = $this->nextVideo($video);
			if($next) {
				$title = $this->plainText($next, $next->title());
				// one text per line: the translation parser of ProcessWire finds only the first of a line
				$label = $this->_('Next video');
				if(strlen($title)) $label = sprintf($this->_('Next video: %s'), $title);
				$buttons .=
					"<button" . $this->partString('end-next', array(
						'type' => 'button',
						'class' => 'shv-video__end-next',
						'data-shv-next' => $this->linkKey($next),
						'hidden' => true, // shown by the script when the page has that video
					)) . ">" . $sanitizer->entities($label) . "</button>";
			}
		}

		$buttons .=
			"<button" . $this->partString('replay', array('type' => 'button', 'class' => 'shv-video__replay')) . ">" .
				"<svg class='shv-video__icon' viewBox='0 0 16 16' aria-hidden='true'><path d='M8 3V0L4 4l4 4V5a4 4 0 1 1-4 4H2a6 6 0 1 0 6-6z'/></svg> " .
				$sanitizer->entities($this->_('Watch again')) .
			"</button>";

		return
			"<div" . $this->partString('end', array(
				'class' => 'shv-video__end',
				'role' => 'group',
				'aria-label' => $this->_('The video has ended'),
				'hidden' => true,
			)) . "><div class='shv-video__end-buttons'>$buttons</div></div>";
	}

	/**
	 * Get the video after this one in its field
	 *
	 * @param Pagevideo $video
	 * @return Pagevideo|null
	 *
	 */
	protected function nextVideo(Pagevideo $video) {
		$list = $video->pagefiles;
		if(!$list instanceof Pagevideos) return null;
		$found = false;
		foreach($list as $item) {
			if($found && $item instanceof Pagevideo && !$item->isBroken()) return $item;
			if($item === $video || $item->basename() === $video->basename()) $found = true;
		}
		return null;
	}

	/**
	 * Add the Open Graph tags of the first video to the <head> of a finished page
	 *
	 * Tags the page has already (from the template or another module) are not added a second time:
	 * a page with its own `og:image` keeps it, and gets only the `og:video` tags.
	 *
	 * @param string $out Markup of the page
	 * @return string
	 *
	 */
	public function completeOpenGraph($out) {

		if(!count($this->openGraph) || !is_string($out)) return $out;
		$end = stripos($out, '</head>');
		if($end === false) return $out;
		$head = substr($out, 0, $end);
		$body = substr($out, $end);

		// the first video that is in the page (a rendered video whose markup was not used does not count)
		$tags = '';
		foreach($this->openGraph as $src => $videoTags) {
			if(strpos($body, $src) === false) continue;
			$tags = $videoTags;
			break;
		}
		if($tags === '' || strpos($head, $tags) !== false) return $out;

		$add = '';
		foreach(explode("\n", trim($tags)) as $tag) {
			if(!preg_match("/property='([^']+)'/", $tag, $m)) continue;
			// og:image:alt and the like belong to the image the page names, so they follow it
			$base = preg_replace('/^(og:(?:video|image)).*$/', '$1', $m[1]);
			if(preg_match('/property\s*=\s*["\']' . preg_quote($base, '/') . '(:[a-z_]+)?["\']/i', $head)) continue;
			$add .= $tag . "\n";
		}
		if($add === '') return $out;

		return substr($out, 0, $end) . $add . substr($out, $end);
	}

	/**
	 * Convert seconds to an ISO 8601 duration, i.e. 93.4 becomes "PT1M33S"
	 *
	 * @param float $seconds
	 * @return string
	 *
	 */
	public static function isoDuration($seconds) {
		$seconds = max(1, (int) round((float) $seconds));
		$h = intdiv($seconds, 3600);
		$m = intdiv($seconds % 3600, 60);
		$s = $seconds % 60;
		$out = 'PT';
		if($h) $out .= $h . 'H';
		if($m) $out .= $m . 'M';
		if($s || (!$h && !$m)) $out .= $s . 'S';
		return $out;
	}

	/*** STYLESHEET AND SCRIPT ******************************************************************/

	/**
	 * Are the stylesheet and script added automatically where a video is rendered?
	 *
	 * @return bool
	 *
	 */
	public function useAutoAssets() {
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		if(!$fieldtype) return true;
		$value = $fieldtype->get('autoAssets');
		return $value === null || $value === '' ? true : (bool) (int) $value;
	}

	/**
	 * Get the URLs of the stylesheet and script for the website
	 *
	 * @return array Array with keys `css` and `js`
	 *
	 */
	public function assetUrls() {
		$config = $this->wire()->config;
		$url = $config->urls->get('FieldtypeSelfHostedVideo');
		if(!$url) $url = $config->urls->siteModules . 'FieldtypeSelfHostedVideo/';
		$url .= 'assets/';
		$info = FieldtypeSelfHostedVideo::getModuleInfo();
		$version = $info['version'];
		if($config->debug) {
			// while developing, follow every change of the files
			$version .= '-' . (int) @filemtime(__DIR__ . '/assets/SelfHostedVideo.css') . '-' . (int) @filemtime(__DIR__ . '/assets/SelfHostedVideo.js');
		}
		return array(
			'css' => $url . 'SelfHostedVideo.css?v=' . $version,
			'js' => $url . 'SelfHostedVideo.js?v=' . $version,
		);
	}

	/**
	 * Render the tags that load the stylesheet and script for the website
	 *
	 * They are returned once per request. Later calls return a blank string, unless forced.
	 *
	 * @param bool $force Return the tags even if they were returned before?
	 * @return string
	 *
	 */
	public function renderAssets($force = false) {

		$sanitizer = $this->wire()->sanitizer;
		$out = '';

		// called for a template, before any video: the profile chosen in the module settings is the one to expect
		$this->expectStyles($this->getFramework(null));

		if(!$this->assetsRendered || $force) {
			$this->assetsRendered = true;
			$urls = $this->assetUrls();
			$out .= "<link rel='stylesheet' href='" . $sanitizer->entities($urls['css']) . "'>";
			foreach(array_keys($this->frameworkCss) as $css) {
				$this->frameworkCss[$css] = true;
				$out .= "<link rel='stylesheet' href='" . $sanitizer->entities($css) . "'>";
			}
			$out .= "<script src='" . $sanitizer->entities($urls['js']) . "' defer></script>";
			return $out;
		}

		// a profile that is first used by a later video of the page
		foreach($this->frameworkCss as $css => $added) {
			if($added) continue;
			$this->frameworkCss[$css] = true;
			$out .= "<link rel='stylesheet' href='" . $sanitizer->entities($css) . "'>";
		}

		return $out;
	}

	/**
	 * Start over, as if nothing had been output during this request
	 *
	 * The module remembers what it has output, so that a page gets the stylesheet, the structured data
	 * of a video and every id attribute only once. Call this when one request writes more than
	 * one document, for instance when a script renders several pages in a row.
	 *
	 */
	public function reset() {
		$this->assetsRendered = false;
		$this->openGraph = array();
		$this->frameworkCss = array();
		$this->frameworks = array();
		$this->generation++;
		$this->schemaRendered = array();
		$this->rendered = array();
		$this->playerCount = 0;
		$this->usedIds = array();
	}

	/*** HELPERS ********************************************************************************/

	/**
	 * Convert a text of the video to plain text, whether output formatting is on or off
	 *
	 * @param Pagevideo $video
	 * @param string $text Text as returned by the video
	 * @return string Plain text, not entity encoded
	 *
	 */
	public function plainText(Pagevideo $video, $text) {
		$text = (string) $text;
		if(!strlen($text)) return '';
		if($video->formatted()) {
			$text = preg_replace('!<br\s*/?>|</p>\s*<p[^>]*>!i', "\n", $text);
			$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		return trim($text);
	}

	/**
	 * Merge attributes given by the site developer into the ones of an element
	 *
	 * @param array $attrs
	 * @param array|mixed $extra
	 * @return array
	 *
	 */
	protected function mergeAttrs(array $attrs, $extra) {
		if(!is_array($extra)) return $attrs;
		foreach($extra as $name => $value) {
			$name = strtolower(trim((string) $name));
			if(!preg_match('/^[a-z][a-z0-9_:.-]*$/', $name)) continue;
			if(strpos($name, 'on') === 0) continue; // no inline event handlers
			if($name === 'class') {
				$attrs['class'] = trim($attrs['class'] . ' ' . $value);
			} else if($value === false || $value === null) {
				unset($attrs[$name]);
			} else {
				$attrs[$name] = $value;
			}
		}
		return $attrs;
	}

	/**
	 * Build an attribute string
	 *
	 * @param array $attrs Values that are boolean true render as attributes without value
	 * @return string With a leading space, or blank
	 *
	 */
	protected function attrString(array $attrs) {
		$sanitizer = $this->wire()->sanitizer;
		$out = '';
		foreach($attrs as $name => $value) {
			if($value === false || $value === null) continue;
			if($value === true) {
				$out .= " $name";
			} else {
				$out .= " $name='" . $sanitizer->entities((string) $value) . "'";
			}
		}
		return $out;
	}
}
