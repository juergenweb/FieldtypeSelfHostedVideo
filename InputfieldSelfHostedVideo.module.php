<?php namespace ProcessWire;

require_once(__DIR__ . '/SelfHostedVideoAssets.php');
require_once(__DIR__ . '/SelfHostedVideoUpload.php');

/**
 * ProcessWire Video File Inputfield
 *
 * Input for video fields in the page editor. Builds on the core file input, so uploading,
 * sorting, deleting, descriptions and tags work as editors know them, and adds:
 *
 * - a playable preview of each video with its duration and dimensions,
 * - a title and a thumbnail alternative text per language,
 * - a thumbnail that is captured in the browser right after upload, which the editor
 *   can replace with another frame or with an image of their own,
 * - replacing the video file while keeping everything entered for it,
 * - a read-only card for videos that are reused from another page.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 * @property float|int $thumbTime Position in the video (seconds) that the automatic thumbnail is taken from (default=2)
 *
 */
class InputfieldSelfHostedVideo extends InputfieldFile {

	/**
	 * Module info
	 *
	 * @return array
	 *
	 */
	public static function getModuleInfo() {
		return array(
			'title' => __('Video Files', __FILE__),
			'version' => '1.0.0',
			'summary' => __('Input for video fields: upload of large files, preview, title, thumbnail, captions, chapters, overlays, checklist, tags and file replacement.', __FILE__),
			'icon' => 'film',
			'requires' => 'FieldtypeSelfHostedVideo',
		);
	}

	/**
	 * Largest thumbnail image accepted, in bytes
	 *
	 */
	const maxThumbBytes = 20971520;

	/**
	 * Width in pixels of the thumbnail shown in the page editor
	 *
	 */
	const adminThumbWidth = 640;

	/**
	 * Number of pictures of the video offered to choose the thumbnail from
	 *
	 */
	const frameSuggestions = 6;

	/**
	 * Largest part of a file sent in one request, in bytes (less where the server accepts less)
	 *
	 */
	const chunkBytes = 8388608;

	/**
	 * Smallest part of a file the script falls back to, in bytes
	 *
	 */
	const minChunkBytes = 262144;

	/**
	 * Seconds after which the parts of an upload that was never finished are removed
	 *
	 */
	const uploadLifetime = 86400;

	/**
	 * How much of the start of a file is compared before an interrupted upload is continued
	 *
	 */
	const resumeCheckBytes = 65536;

	/**
	 * Longest title and thumbnail text, in characters
	 *
	 */
	const maxTitleLength = 255;

	/**
	 * Longest description, in characters
	 *
	 */
	const maxDescriptionLength = 5000;

	/**
	 * Number of videos the picker for reusing a video shows at a time
	 *
	 */
	const reusePageSize = 8;

	/**
	 * Does a text field of the page understand video tags? Null until asked.
	 *
	 * @var bool|null
	 *
	 */
	protected $textTags = null;

	/**
	 * Initialize
	 *
	 */
	public function init() {
		parent::init();
		$this->set('thumbTime', 2);
		$this->set('maxVideoMB', 0);
		$this->set('warnBitrate', SelfHostedVideoChecks::defaultMaxBitrate);
		$this->set('warnSizeMB', 0);
		$this->set('noReuse', 0);
		$this->set('noEditorTab', 0);
		$this->set('itemClass', 'InputfieldFileItem InputfieldSelfHostedVideoItem ui-widget ui-widget-content');
		$this->labels['choose-file'] = $this->_('Choose video');
		$this->labels['drag-drop'] = $this->_('drag and drop videos in here');
	}

	/*** RENDER *********************************************************************************/

	/**
	 * Render ready: load assets and make texts available to the script
	 *
	 * @param Inputfield|null $parent
	 * @param bool $renderValueMode
	 * @return bool
	 *
	 */
	public function renderReady(?Inputfield $parent = null, $renderValueMode = false) {

		$config = $this->wire()->config;
		$modules = $this->wire()->modules;

		// styles and upload script of the core file input, which this input builds on
		$modules->loadModuleFileAssets('InputfieldFile');

		// the editor for chapters and overlays, and the styles overlays have on the website
		$info = self::getModuleInfo();
		$url = $config->urls->get('InputfieldSelfHostedVideo') . 'assets/';
		$version = $info['version'] . ($config->debug ? '-' . (int) @filemtime(__DIR__ . '/assets/InputfieldSelfHostedVideoTimeline.js') . '-' . (int) @filemtime(__DIR__ . '/assets/SelfHostedVideo.css') : '');
		$config->scripts->add($url . 'InputfieldSelfHostedVideoTimeline.js?v=' . $version);
		$config->styles->add($url . 'SelfHostedVideo.css?v=' . $version);

		$settings = $config->js('InputfieldSelfHostedVideo');
		if(!is_array($settings) || empty($settings['labels'])) {
			$config->js('InputfieldSelfHostedVideo', array(
				'timeline' => $this->getTimelineSettings(),
				'maxThumbBytes' => self::maxThumbBytes,
				'maxCaptionBytes' => SelfHostedVideoCaptions::maxBytes,
				'chunk' => array(
					'max' => $this->getChunkLimit(),
					'min' => min(self::minChunkBytes, $this->getChunkLimit()),
					'start' => min(2097152, $this->getChunkLimit()),
				),
				'retryDelays' => array(1000, 3000, 6000),
				'perPage' => $this->wire()->modules->get('FieldtypeSelfHostedVideo')->editorPerPage(),
				'labels' => array(
					'pagerLabel' => $this->_('Pages of the list'),
					'pagerPage' => $this->_('Page %d'),
					'pagerPageOf' => $this->_('Page %1$d of %2$d'),
					'pagerOne' => $this->_('Video %1$d of %2$d'),
					'pagerRange' => $this->_('Videos %1$d–%2$d of %3$d'),
					'pagerPrev' => $this->_('Previous'),
					'pagerNext' => $this->_('Next'),
					'perPage' => $this->_('Per page'),
					'moveLabel' => $this->_('Move “%s”'),
					'moveMenu' => $this->_('Move the video'),
					'moveUp' => $this->_('Up one place'),
					'moveDown' => $this->_('Down one place'),
					'moveFirst' => $this->_('To the start'),
					'moveLast' => $this->_('To the end'),
					'movePosition' => $this->_('To position'),
					'moveOf' => $this->_('of %d'),
					'moveGo' => $this->_('Move'),
					'moveHint' => $this->_('Drag, or use the arrow keys'),
					'moved' => $this->_('“%1$s” is now at position %2$d of %3$d.'),
					'moveSave' => $this->_('Save the page to keep the new order.'),
					'perPageAll' => $this->_('Show all'),
					'uploading' => $this->_('Uploading “%s”'),
					'progress' => $this->_('%1$s of %2$s'),
					'finishing' => $this->_('Finishing…'),
					'cancel' => $this->_('Cancel'),
					'cancelUpload' => $this->_('Cancel upload of “%s”'),
					'retry' => $this->_('Try again'),
					'retrying' => $this->_('Connection lost. Trying again…'),
					'offline' => $this->_('No internet connection. The upload goes on by itself once the connection is back.'),
					'online' => $this->_('The connection is back. The upload goes on.'),
					/* translators: %s is an amount of data, such as 3.2 MB: the speed per second */
					'speed' => $this->_('%s/s'),
					/* translators: %s is a time, such as 3 min */
					'remaining' => $this->_('about %s left'),
					'timeSeconds' => $this->_('%d s'),
					'timeMinutes' => $this->_('%d min'),
					'timeHours' => $this->_('%1$d h %2$d min'),
					'checking' => $this->_('Checking the file…'),
					'unplayable' => $this->_('Your browser cannot play this video, so many visitors could not watch it either. It is best exported again as MP4 (H.264).'),
					'uploadAnyway' => $this->_('Upload anyway'),
					'uploadAnywayName' => $this->_('Upload “%s” anyway'),
					'dontUpload' => $this->_('Do not upload'),
					/* translators: %1$s is a file name, %2$s its size, %3$s how much had arrived, such as 63% */
					'resumeNotice' => $this->_('The upload of “%1$s” (%2$s) was interrupted at %3$s. Choose the same file again to continue where it stopped.'),
					'resumeChoose' => $this->_('Choose file again'),
					'resumeChooseName' => $this->_('Choose “%s” again to continue its upload'),
					'resumeDiscard' => $this->_('Discard'),
					'resumeDiscardName' => $this->_('Discard the interrupted upload of “%s”'),
					'resumeDiscarded' => $this->_('The interrupted upload was discarded.'),
					/* translators: %1$s is the chosen file, %2$s and %3$s name and size of the file that was being uploaded */
					'resumeWrong' => $this->_('“%1$s” is not the file that was being uploaded (“%2$s”, %3$s). Choose that file to continue.'),
					/* translators: %s is an amount of data, such as 820 MB */
					'resumed' => $this->_('Continuing where it stopped: %s were already on the server.'),
					'resumeLost' => $this->_('What had arrived is no longer on the server. The upload starts over.'),
					/* translators: %1$d is the number of the file being sent, %2$d how many there are, %3$s how much is done in percent, %4$s the size of all files */
					'totalLine' => $this->_('Video %1$d of %2$d · %3$s of %4$s in total'),
					'totalOf' => $this->_('video %1$d of %2$d'),
					'totalLabel' => $this->_('All uploads of this field'),
					'totalPaused' => $this->_('paused'),
					'pause' => $this->_('Pause'),
					'continue' => $this->_('Continue'),
					'pauseName' => $this->_('Pause the upload of “%s”'),
					'continueName' => $this->_('Continue the upload of “%s”'),
					/* translators: %1$s is how much is done in percent, %2$s and %3$s amounts of data */
					'pausedAt' => $this->_('Paused at %1$s · %2$s of %3$s. What has arrived stays on the server.'),
					'pausedName' => $this->_('The upload of “%s” is paused.'),
					'continuedName' => $this->_('The upload of “%s” goes on.'),
					'stalled' => $this->_('The upload was interrupted. Nothing is lost: “Try again” continues where it stopped.'),
					'uploadError' => $this->_('The upload failed:'),
					'uploadDone' => $this->_('“%s” was uploaded.'),
					'uploadCancelled' => $this->_('Upload of “%s” cancelled.'),
					'tooBigMax' => $this->_('This file is larger than the %s allowed for this field.'),
					'tooMany' => $this->_('No more videos can be added to this field.'),
					'leave' => $this->_('A video is still being uploaded. If you leave now, it is not added to the page.'),
					'waiting' => $this->_('Waiting for its turn…'),
					'captionChoose' => $this->_('Please choose the language of the captions first.'),
					'captionCode' => $this->_('Please enter a language code such as “rm” and the name of the language.'),
					'captionSaving' => $this->_('Saving captions…'),
					'captionRemoving' => $this->_('Removing captions…'),
					'captionError' => $this->_('The captions could not be saved:'),
					'captionEditing' => $this->_('Edit the captions: %s'),
					'captionEditHint' => $this->_('WebVTT: a line with the times, such as 00:00:01.000 --> 00:00:04.000, then the text. A blank line between two captions. The captions are saved right away, the page does not need saving.'),
					'captionEditSave' => $this->_('Save captions'),
					'captionEditCancel' => $this->_('Cancel'),
					'captionLoading' => $this->_('Loading the captions…'),
					'captionLeave' => $this->_('The captions have changes that are not saved. Leave them?'),
					'captionRemoveConfirm' => $this->_('Remove the captions in %s? This takes effect right away.'),
					'captionTooBig' => $this->_('This file is too large for a captions file.'),
					'emptyFile' => $this->_('This file is empty.'),
					// one translatable text per line: the translation tools of ProcessWire find only the first of a line
					'units' => array(
						$this->_('KB'),
						$this->_('MB'),
						$this->_('GB'),
					),
					'creating' => $this->_('Creating thumbnail…'),
					'saving' => $this->_('Saving thumbnail…'),
					'saved' => $this->_('Thumbnail saved.'),
					'failed' => $this->_('No thumbnail could be created from this video in your browser. You can upload an image instead.'),
					'saveError' => $this->_('The thumbnail could not be saved:'),
					/* translators: %s is a position in the video, such as 1:05 */
					'frameAt' => $this->_('Picture at %s'),
					'frameCurrent' => $this->_('in use'),
					'framesFailed' => $this->_('Your browser cannot make pictures of this video. The other two ways still work.'),
					'badImage' => $this->_('Please choose a JPEG or PNG image.'),
					'tooBig' => $this->_('This file is too large to upload.'),
					'badExtension' => $this->_('This file type is not allowed here. Allowed:'),
					'replacing' => $this->_('Uploading replacement…'),
					'replaced' => $this->_('Video file replaced.'),
					'replaceError' => $this->_('The video file could not be replaced:'),
					'replaceConfirm' => $this->_('Replace the video file with “%s”? Title, description, tags and all other details are kept. This takes effect right away.'),
					'requestError' => $this->_('The server did not answer as expected.'),
					'faststarting' => $this->_('Preparing the file…'),
					'faststartDone' => $this->_('The file is prepared for streaming.'),
					'faststartError' => $this->_('The file could not be prepared:'),
					'reuseSearch' => $this->_('Search'),
					'reuseSearchHint' => $this->_('Title, description, file name or page'),
					'reuseTag' => $this->_('Tag'),
					'reuseAnyTag' => $this->_('Any tag'),
					'reuseGo' => $this->_('Search'),
					'reuseSearching' => $this->_('Searching…'),
					'reuseNone' => $this->_('No videos found.'),
					'reuseFound' => $this->_('%d videos found.'),
					'reuseFoundOne' => $this->_('1 video found.'),
					'reuseShown' => $this->_('%1$d of %2$d videos shown.'),
					'reuseMore' => $this->_('Show more'),
					'reuseUse' => $this->_('Use this video'),
					'reuseUseLabel' => $this->_('Use this video: %s'),
					'reuseHere' => $this->_('Already here'),
					'reuseAdding' => $this->_('Adding video…'),
					'reuseAdded' => $this->_('“%s” was added. It is saved already.'),
					'reuseError' => $this->_('The video could not be added:'),
					'reuseResults' => $this->_('Videos found'),
					'reuseProtected' => $this->_('Protected'),
				),
			));
		}

		$this->addClass('InputfieldSelfHostedVideo', 'wrapClass');
		$this->addClass('InputfieldFile', 'wrapClass');
		$this->wrapAttr('data-vf-max-bytes', $this->getMaxVideoBytes());

		// the styles and script of this input (ProcessWire loads them itself only from the folder of the module)
		SelfHostedVideoAssets::load('InputfieldSelfHostedVideo', $info['version']);

		return parent::renderReady($parent, $renderValueMode);
	}

	/**
	 * Render one video
	 *
	 * @param Pagefile $pagefile
	 * @param string $id
	 * @param int $n
	 * @return string
	 *
	 */
	protected function ___renderItem($pagefile, $id, $n) {

		if(!$pagefile instanceof Pagevideo) return parent::___renderItem($pagefile, $id, $n);

		$sanitizer = $this->wire()->sanitizer;
		$isReference = $pagefile->isReference();
		$isBroken = $pagefile->isBroken();
		$editable = !$this->renderValueMode && !$isReference;

		// header: name, facts, delete
		if($isReference) {
			$displayName = $isBroken ? $this->_('Missing video') : $pagefile->title();
			if(!strlen($displayName)) $displayName = $pagefile->get('ref_name');
			$name = "<span class='InputfieldFileName'>" . $sanitizer->entities($displayName) . "</span>";
			$icon = "<i class='fa fa-fw fa-link'></i>";
			$deleteLabel = $this->_('Remove from this page');
		} else {
			$displayName = $this->getDisplayBasename($pagefile);
			$tooltip = $sanitizer->entities($pagefile->basename);
			$url = $sanitizer->entities($pagefile->url);
			$name = "<a class='InputfieldFileName pw-tooltip' title='$tooltip' target='_blank' href='$url'>$displayName</a>";
			$icon = "<i class='fa fa-fw fa-film'></i>";
			$deleteLabel = $this->labels['delete'];
		}

		$deleteLabel = $sanitizer->entities($deleteLabel);

		// in a field for several videos each video is an accordion item: closed, with a header
		// that shows what it is, and opened to work on it
		$accordion = $this->useAccordion();
		$dataId = $sanitizer->entities($id . '_data');
		// a video that was just uploaded is there to be filled in
		$open = !$accordion || $pagefile->isTemp();

		if($accordion) {
			$out =
				"<p class='InputfieldFileInfo InputfieldItemHeader ui-state-default ui-widget-header InputfieldSelfHostedVideoHead'>" .
				$this->renderItemGrip($pagefile) .
				$this->renderItemToggle($pagefile, $id, $open) . ' ';
		} else {
			$out =
				"<p class='InputfieldFileInfo InputfieldItemHeader ui-state-default ui-widget-header'>" .
				"$icon&nbsp;$name " .
				"<span class='InputfieldFileStats'>" . $this->renderItemStats($pagefile) . "</span> " .
				"<span class='InputfieldSelfHostedVideoBadgeSlot'>" . $this->renderItemBadge($pagefile) . "</span> ";
		}

		// an original that other pages show cannot be deleted. Say so here, instead of refusing after the fact.
		$usedOn = !$isReference ? $this->renderItemUsedOn($pagefile) : '';

		if(!$this->renderValueMode && strlen($usedOn)) {
			$lockLabel = $sanitizer->entities($this->_('Cannot be deleted: reused on other pages'));
			$out .=
				"<span class='InputfieldFileDelete InputfieldSelfHostedVideoLocked' title='$lockLabel'>" .
					"<i class='fa fa-fw fa-lock' aria-hidden='true'></i><span class='InputfieldSelfHostedVideoSr'>$lockLabel</span></span>";
		} else if(!$this->renderValueMode) $out .=
			"<label class='InputfieldFileDelete'>" .
				"<input type='checkbox' name='delete_$id' value='1' title='$deleteLabel' aria-label='$deleteLabel' />" .
				"<i class='fa fa-fw fa-trash'></i></label>";

		$out .= "</p>";

		// marked for deletion: what a tag in a text of this page would lose (shown by the script)
		if(!$this->renderValueMode && !strlen($usedOn)) $out .= $this->renderItemDeleteWarning($pagefile);

		$out .=
			"<div class='InputfieldFileData description ui-widget-content" . ($accordion ? ' InputfieldSelfHostedVideoBody' : '') . "'" .
			($accordion ? " id='$dataId'" : '') . ($open ? '' : ' hidden') . ">";

		if($isBroken) {
			$out .=
				"<p class='InputfieldSelfHostedVideoNote ui-state-error-text'>" .
				$sanitizer->entities($this->_('The original of this reused video no longer exists. Please remove it from this page.')) .
				"</p>";
		} else {
			$attrs = $this->getItemAttributes($pagefile, $editable);
			$out .=
				"<div " . $this->getAttributesString($attrs) . ">" .
					"<div class='InputfieldSelfHostedVideoMedia'>" .
						$this->renderItemAreas($pagefile, $id, $editable && !$this->noUpload, $accordion && !$isReference) .
						$this->renderItemTag($pagefile) .
						$this->renderItemChecklist($pagefile) .
					"</div>" .
					"<div class='InputfieldSelfHostedVideoFields'>" .
						($isReference ? $this->renderItemReference($pagefile) : $this->renderItemFields($pagefile, $id, $n)) .
						($isReference ? '' : $this->renderItemCustomFields($pagefile, $id)) .
						$usedOn .
						$this->renderItemPlaced($pagefile) .
					"</div>" .
				"</div>" .
				$this->renderItemTimeline($pagefile, $id, $editable && !$this->noUpload) .
				$this->renderItemFoot();
		}

		if(!$this->renderValueMode) {
			$out .= "<input class='InputfieldFileSort' type='text' name='sort_$id' value='$n' />";
		}

		$out .= "</div>";

		return $out;
	}

	/**
	 * Render the foot of a video: a link back to the top of the page, where the page is saved
	 *
	 * Each video has one, so that it is at hand wherever an editor finished with a video in a long list.
	 *
	 * @return string
	 *
	 */
	protected function renderItemFoot() {
		$label = $this->wire()->sanitizer->entities($this->_('Back to top'));
		return
			"<div class='InputfieldSelfHostedVideoFoot'>" .
				"<a class='InputfieldSelfHostedVideoTop' href='#'>" .
					"<span class='InputfieldSelfHostedVideoTopIcon' aria-hidden='true'><i class='fa fa-arrow-up'></i></span>" .
					"<span>$label</span>" .
				"</a>" .
			"</div>";
	}

	/**
	 * Render the custom fields of a video, where the field has some
	 *
	 * Custom fields are the core's: the fields of a template named "field-" and the name of the
	 * video field. They follow the module's own inputs of a video, below the captions.
	 *
	 * Where there are some, they get a heading of their own, written like those of the
	 * module's inputs ("Title", "Captions").
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @return string
	 *
	 */
	protected function renderItemCustomFields(Pagevideo $video, $id = '') {
		$sanitizer = $this->wire()->sanitizer;
		$headingId = 'customfields_' . (strlen($id) ? $id : md5($video->basename()));
		$heading = "<p class='InputfieldSelfHostedVideoLabel InputfieldSelfHostedVideoCustomLabel detail' id='$headingId'>" .
			$sanitizer->entities($this->_('Other fields')) . "</p>";
		$wrap = function($out) use($heading, $headingId) {
			return "<div class='InputfieldSelfHostedVideoCustom' role='group' aria-labelledby='$headingId'>$heading$out</div>";
		};

		if($this->renderValueMode) {
			// A field that is only shown says what the custom fields hold, written as on the website.
			// (Their inputs would stay inputs here: a rich text field brings its editor even when asked for its value.)
			$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
			$out = '';
			foreach($fieldtype->markup()->infoItems($video) as $item) {
				$out .=
					"<div class='InputfieldSelfHostedVideoText InputfieldSelfHostedVideoText_custom'>" .
					"<span class='detail'>" . $sanitizer->entities1($item['label']) . ":</span> " . $item['value'] .
					"</div>";
			}
			return strlen($out) ? $wrap($out) : '';
		}

		$inputfields = $this->getItemInputfields($video);
		if(!$inputfields || !count($inputfields->getAll())) return '';
		return $wrap($inputfields->render());
	}

	/**
	 * Is each video of this field shown as an accordion item?
	 *
	 * In a field for several videos, while it is edited. A field for one video shows that video
	 * open, and so does a field that is only shown (locked), where nothing could open an item.
	 *
	 * @return bool
	 *
	 */
	public function useAccordion() {
		return !$this->renderValueMode && (int) $this->maxFiles !== 1;
	}

	/**
	 * Render the handle at the start of an item's header by which the video is moved in the list
	 *
	 * Dragged with the mouse or a finger. As a button it also opens a menu that moves the video
	 * up, down, to the start, to the end or to a position (for the keyboard, and for those who
	 * cannot drag), and the arrow keys move it while it has the focus.
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderItemGrip(Pagevideo $video) {
		$label = $this->wire()->sanitizer->entities($this->_('Move'));
		return
			"<button type='button' class='InputfieldSelfHostedVideoGrip' aria-haspopup='true' aria-expanded='false' title='$label'>" .
				"<span class='InputfieldSelfHostedVideoGripDots' aria-hidden='true'></span>" .
				"<span class='InputfieldSelfHostedVideoSr'>$label</span>" .
			"</button>";
	}

	/**
	 * Render the part of an item's header that opens and closes the item: thumbnail, title,
	 * duration and other facts, and whether the checklist has open points
	 *
	 * It is an element with the role of a button and not a button element, because the list of
	 * videos is sorted by dragging an item at its header, which does not start on a button.
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @param bool $open
	 * @return string
	 *
	 */
	protected function renderItemToggle(Pagevideo $video, $id, $open) {

		$sanitizer = $this->wire()->sanitizer;
		$isReference = $video->isReference();
		$fileName = $isReference ? (string) $video->get('ref_name') : $video->basename();

		if($video->isBroken()) {
			$title = $this->_('Missing video');
		} else {
			// the title the inputs of the item show: in the user's language, else in the default language
			$languages = $this->wire()->languages;
			$title = $languages && $this->noLang ? $video->title($languages->getDefault()) : $video->title();
			$title = trim(strip_tags(html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
			if(!strlen($title)) $title = $fileName;
		}

		$thumb = $video->isBroken() ? '' : $this->getAdminThumbUrl($video);
		$picture = strlen($thumb)
			? "<img src='" . $sanitizer->entities($thumb) . "' alt='' loading='lazy' />"
			: "<i class='fa fa-fw " . ($isReference ? 'fa-link' : 'fa-film') . "' aria-hidden='true'></i>";

		$attrs = array(
			'class' => 'InputfieldSelfHostedVideoToggle',
			'role' => 'button',
			'tabindex' => '0',
			'aria-expanded' => $open ? 'true' : 'false',
			'aria-controls' => $id . '_data',
			'data-fallback' => $fileName,
		);

		// the title in the header follows what is typed into the title of the video
		if(!$isReference) $attrs['data-title-inputs'] = implode(' ', $this->getTitleInputNames($id));

		return
			"<span " . $this->getAttributesString($attrs) . ">" .
				"<span class='InputfieldSelfHostedVideoBadgeSlot'>" . $this->renderItemBadge($video) . "</span>" .
				"<span class='InputfieldSelfHostedVideoHeadThumb" . ($video->isPortrait() ? ' InputfieldSelfHostedVideoHeadThumbPortrait' : '') . "'>$picture</span>" .
				"<span class='InputfieldSelfHostedVideoHeadText'>" .
					"<span class='InputfieldSelfHostedVideoHeadTitle'>" . $sanitizer->entities($title) . "</span> " .
					"<span class='InputfieldSelfHostedVideoHeadFacts'>" .
						"<span class='InputfieldFileStats'>" . $this->renderItemStats($video) . "</span>" .
						($video->isBroken() ? '' : " <span class='InputfieldSelfHostedVideoHeadFile'>&middot; <span class='InputfieldFileName'>" . $sanitizer->entities($fileName) . "</span></span>") .
					"</span>" .
				"</span>" .
				"<i class='fa fa-fw fa-angle-down InputfieldSelfHostedVideoChevron' aria-hidden='true'></i>" .
			"</span>";
	}

	/**
	 * Get the names of the inputs that hold the title of a video, the one of the user's language first
	 *
	 * @param string $id
	 * @return array
	 *
	 */
	protected function getTitleInputNames($id) {
		$languages = $this->wire()->languages;
		$names = array();
		if($languages && $languages->count() > 1 && !$this->noLang) {
			$language = $this->wire()->user->language;
			if($language && $language->id && !$language->isDefault()) $names[] = "title{$language->id}_$id";
		}
		$names[] = "title_$id";
		return $names;
	}

	/**
	 * Render the link that opens the file of a video in a new tab
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderItemFileLink(Pagevideo $video) {
		$sanitizer = $this->wire()->sanitizer;
		$name = $sanitizer->entities($video->basename());
		$url = $sanitizer->entities($video->url);
		return
			"<p class='InputfieldSelfHostedVideoFileLink detail'>" .
				"<a class='InputfieldFileLink' target='_blank' rel='noopener' href='$url'>" .
				"<i class='fa fa-fw fa-external-link' aria-hidden='true'></i> $name</a>" .
			"</p>";
	}

	/**
	 * Render one area of what is shown below the preview of a video: a group with a legend
	 *
	 * @param string $class Class name of the area
	 * @param string $legend Its legend, as text
	 * @param string $body Its contents, as markup
	 * @param string $legendId Optional id for the legend
	 * @return string
	 *
	 */
	protected function renderItemArea($class, $legend, $body, $legendId = '') {
		$sanitizer = $this->wire()->sanitizer;
		$id = strlen($legendId) ? " id='" . $sanitizer->entities($legendId) . "'" : '';
		return
			"<fieldset class='InputfieldSelfHostedVideoArea $class'>" .
				"<legend class='InputfieldSelfHostedVideoLegend'$id>" . $sanitizer->entities($legend) . "</legend>" .
				"<div class='InputfieldSelfHostedVideoAreaBody'>$body</div>" .
			"</fieldset>";
	}

	/**
	 * Render the areas that come first in the left column of a video: the video with its
	 * thumbnail, and the video file
	 *
	 * Without tools (a field that takes no uploads, a video that is only shown, a reused video)
	 * the first area is the video alone, with the link to its file where there is one.
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @param bool $tools Thumbnail and replacement tools?
	 * @param bool $link The link to the file? (where the header of the video does not have it)
	 * @return string
	 *
	 */
	protected function renderItemAreas(Pagevideo $video, $id, $tools, $link) {
		$player = $this->renderItemPlayer($video);
		$fileLink = $link ? $this->renderItemFileLink($video) : '';
		if($tools) return $this->renderItemTools($video, $id, $fileLink, $player);
		return $this->renderItemArea('InputfieldSelfHostedVideoPreview', $this->_('Video'), $player . $fileLink);
	}

	/**
	 * Render the tag that places a video in a text, where a text field of the page understands it
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderItemTag(Pagevideo $video) {

		if($this->renderValueMode || $video->isTemp() || !$this->pageHasTextTags()) return '';

		$sanitizer = $this->wire()->sanitizer;
		$name = $video->isReference() ? (string) $video->get('ref_name') : $video->basename();

		// what the tag is for: without this an editor cannot know
		$hint = $this->_('Put this tag into a text of this page, and the website shows the video at that place. In a rich text editor the button “Insert video” writes the tag for you.');

		return $this->renderItemArea(
			'InputfieldSelfHostedVideoTag',
			$this->_('To place in a text'),
			"<code>[[video " . $sanitizer->entities($name) . "]]</code>" .
			"<p class='InputfieldSelfHostedVideoHint detail'>" . $sanitizer->entities($hint) . "</p>"
		);
	}

	/**
	 * Does a text field of the page being edited show videos where it has a video tag?
	 *
	 * @return bool
	 *
	 */
	protected function pageHasTextTags() {

		if($this->textTags !== null) return $this->textTags;
		$this->textTags = false;

		$page = $this->hasPage;
		$guard = 0;

		while($page instanceof Page && $page->id && $page->template && $guard++ < 10) {
			foreach($page->template->fieldgroup as $field) {
				$formatters = $field->get('textformatters');
				if(is_array($formatters) && in_array('TextformatterSelfHostedVideo', $formatters, true)) {
					$this->textTags = true;
					return true;
				}
			}
			// a video in a repeater item can be placed in the texts of the page the item belongs to
			$page = method_exists($page, 'getForPage') ? $page->getForPage() : null;
		}

		return false;
	}

	/**
	 * Render the warning shown when a video that a text of this page places is marked for deletion
	 *
	 * @param Pagevideo $video
	 * @return string Blank when no text of the page has its tag
	 *
	 */
	protected function renderItemDeleteWarning(Pagevideo $video) {
		if($video->isTemp()) return '';
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$page = $this->hasPage && $this->hasPage->id ? $this->hasPage : $video->page;
		if(!$fieldtype instanceof FieldtypeSelfHostedVideo || !$page || !$page->id) return '';
		$fields = $fieldtype->usage()->textsOnPage($video, $page);
		if(!count($fields)) return '';
		$labels = array();
		foreach($fields as $field) $labels[] = '“' . $field->getLabel() . '”';
		$text =
			sprintf($this->_n('This video is placed with a tag in the text %s of this page.', 'This video is placed with tags in the texts %s of this page.', count($labels)), implode(', ', $labels)) . ' ' .
			$this->_('After saving, the tag shows nothing there: remove it, or place another video.');
		return
			"<p class='InputfieldSelfHostedVideoDeleteWarning' role='alert' hidden>" .
				"<i class='fa fa-fw fa-exclamation-triangle' aria-hidden='true'></i> " .
				htmlspecialchars($text, ENT_QUOTES, 'UTF-8') .
			"</p>";
	}

	/**
	 * Render where a video is placed: its own page, the pages that reuse it, texts with its tag
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderItemPlaced(Pagevideo $video) {
		if($video->isTemp()) return '';
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		if(!$fieldtype instanceof FieldtypeSelfHostedVideo) return '';
		$out = $fieldtype->usage()->render($video, 'InputfieldSelfHostedVideo', true);
		if(strlen($out)) $out = str_replace("class='InputfieldSelfHostedVideoPlaced'", "class='InputfieldSelfHostedVideoNote InputfieldSelfHostedVideoPlaced notes'", $out);
		// how often it is watched
		if($fieldtype->useStats()) {
			$out .= str_replace("class='InputfieldSelfHostedVideoStats'", "class='InputfieldSelfHostedVideoNote InputfieldSelfHostedVideoStats notes'", $fieldtype->stats()->render($video, 'InputfieldSelfHostedVideo'));
		}
		return $out;
	}

	/**
	 * Render the note that names the other pages a video is reused on
	 *
	 * @param Pagevideo $video
	 * @return string Blank if it is not reused anywhere
	 *
	 */
	protected function renderItemUsedOn(Pagevideo $video) {

		if($video->isReference() || $video->isTemp() || !$video->isReferenced()) return '';

		$sanitizer = $this->wire()->sanitizer;
		$finder = $this->wire()->modules->get('FieldtypeSelfHostedVideo')->finder();
		$links = array();

		foreach($video->references() as $page) {
			$page = $finder->editPage($page);
			$label = $sanitizer->entities1((string) $page->get('title|name'));
			$links[$page->id] = $page->editable()
				? "<a href='" . $sanitizer->entities($page->editUrl()) . "' target='_blank'>$label</a>"
				: $label;
		}

		$n = count($links);
		if(!$n) return '';

		return
			"<p class='InputfieldSelfHostedVideoNote InputfieldSelfHostedVideoUsedOn notes'><i class='fa fa-fw fa-link' aria-hidden='true'></i> " .
			sprintf($this->_n('Reused on %d other page:', 'Reused on %d other pages:', $n), $n) . ' ' . implode(', ', $links) . '. ' .
			$sanitizer->entities($this->_('What you change here shows there too. To delete this video, remove it from those pages first.')) .
			"</p>";
	}

	/**
	 * The file types a file input of this field asks for: any video, plus the allowed extensions
	 *
	 * "video/*" lets phones offer their videos and the camera in video mode; the extensions keep
	 * files the computer does not know as video choosable. What is not allowed is refused anyway.
	 *
	 * @return string
	 *
	 */
	public function getAcceptAttribute() {
		$accept = array('video/*');
		foreach($this->getAllowedExtensions(true) as $ext) $accept[] = ".$ext";
		return implode(',', $accept);
	}

	/**
	 * Render the upload area, followed by the way to reuse a video of another page
	 *
	 * The way to reuse is there when the field allows it and there is a video to reuse.
	 *
	 * @param Pagefiles|null $value
	 * @return string
	 *
	 */
	protected function ___renderUpload($value) {

		// the file input asks for videos: phones open their videos (gallery, camera in video mode)
		// instead of all photos and files, and the file dialog of a computer shows videos
		$accept = $this->getAttribute('accept');
		$this->setAttribute('accept', $this->getAcceptAttribute());
		$out = parent::___renderUpload($value);
		if($accept === null) {
			$this->removeAttr('accept');
		} else {
			$this->setAttribute('accept', $accept);
		}
		if(!strlen($out) || $this->noReuse || $this->uploadOnlyMode) return $out;

		// nothing to offer while no other page, and no other video field of this page, has a
		// video that could be shown here
		$videos = $value instanceof Pagevideos ? $value : $this->value;
		if(!$videos instanceof Pagevideos) return $out;
		if(!$this->wire()->modules->get('FieldtypeSelfHostedVideo')->finder()->hasReusable($videos)) return $out;

		$sanitizer = $this->wire()->sanitizer;
		$id = 'reuse_' . $sanitizer->entities($this->attr('id'));
		$label = $sanitizer->entities($this->_('Reuse a video from another page'));
		$note = $sanitizer->entities($this->_('The video is shown here without a second copy of its file. Its title, thumbnail, captions and everything else stay with the page it comes from and are edited there.'));

		return $out .
			"<div class='InputfieldSelfHostedVideoReuse'>" .
				"<button type='button' class='InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoReuseToggle' aria-expanded='false' aria-controls='$id'>" .
					"<i class='fa fa-fw fa-link' aria-hidden='true'></i> $label</button>" .
				"<div class='InputfieldSelfHostedVideoReusePanel' id='$id' hidden>" .
					"<p class='InputfieldSelfHostedVideoReuseNote detail'>$note</p>" .
				"</div>" .
			"</div>";
	}

	/**
	 * Render the facts shown next to the name: file size, duration, dimensions
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderItemStats(Pagevideo $video) {

		$stats = array();

		if($video->isReference()) $stats[] = $this->_('Reused');
		if($video->isBroken()) return implode(' &middot; ', $stats);

		// the duration first: it is what tells videos apart at a glance
		$duration = $video->durationStr();
		if(strlen($duration)) $stats[] = "<span class='InputfieldSelfHostedVideoDuration'>$duration</span>";

		$stats[] = str_replace(' ', '&nbsp;', $video->filesizeStr);

		if($video->width() && $video->height()) $stats[] = $video->width() . '&times;' . $video->height();

		return implode(' &middot; ', $stats);
	}

	/**
	 * Render the sign in the header of a video that says whether points of its checklist are open
	 *
	 * In the header of an accordion item it is always there: a tick when nothing is open, an
	 * exclamation mark when something is. Above a video that is shown open, it is a label
	 * with the number of open points, and absent when there are none.
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderItemBadge(Pagevideo $video) {

		$sanitizer = $this->wire()->sanitizer;
		$open = $video->isBroken() ? 0 : $video->openChecks();
		$label = $open ? sprintf($this->_n('%d point to check', '%d points to check', $open), $open) : '';

		if(!$this->useAccordion()) {
			if(!$open) return '';
			return
				"<span class='InputfieldSelfHostedVideoBadge'><i class='fa fa-exclamation-circle' aria-hidden='true'></i> " .
				$sanitizer->entities($label) .
				"</span>";
		}

		if($video->isBroken()) $label = $this->_('The original of this video no longer exists');
		$warn = strlen($label) > 0;
		if(!$warn) $label = $this->_('Nothing to check');
		$label = $sanitizer->entities($label);

		return
			"<span class='InputfieldSelfHostedVideoSign " . ($warn ? 'InputfieldSelfHostedVideoSignWarn' : 'InputfieldSelfHostedVideoSignOk') . "' title='$label'>" .
				"<i class='fa fa-fw " . ($warn ? 'fa-exclamation' : 'fa-check') . "' aria-hidden='true'></i>" .
				"<span class='InputfieldSelfHostedVideoSr'>$label</span>" .
			"</span>";
	}

	/**
	 * Render the checklist of a video: what is missing for accessibility, and what to know about the file
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderItemChecklist(Pagevideo $video) {

		$sanitizer = $this->wire()->sanitizer;
		$checks = $video->checks();
		if(!count($checks)) return '';

		$icons = array('ok' => 'fa-check-circle', 'todo' => 'fa-exclamation-circle', 'warning' => 'fa-exclamation-triangle', 'info' => 'fa-info-circle');
		$words = array(
			'ok' => $this->_('Fine:'),
			'todo' => $this->_('To do:'),
			'warning' => $this->_('Worth knowing:'),
			'info' => $this->_('Note:'),
		);
		$id = 'checklist_' . $this->name . '_' . $video->hash;
		$items = '';

		foreach($checks as $check) {
			$status = isset($icons[$check->status]) ? $check->status : 'info';
			$note = strlen((string) $check->note) ? "<span class='InputfieldSelfHostedVideoCheckNote'>" . $sanitizer->entities($check->note) . "</span>" : '';
			if($check->name === 'faststart' && !$this->renderValueMode && !$this->noUpload && !$video->isReference()) {
				// the module can do it: a button right there
				$note .=
					"<span class='InputfieldSelfHostedVideoCheckAction'>" .
						"<button type='button' class='ui-button ui-widget ui-corner-all ui-state-default ui-priority-secondary InputfieldSelfHostedVideoFaststart'>" .
							"<i class='fa fa-bolt' aria-hidden='true'></i> " . $sanitizer->entities($this->_('Prepare for streaming')) .
						"</button> " .
						"<span class='InputfieldSelfHostedVideoFaststartStatus' role='status'></span>" .
					"</span>";
			}
			if($check->get('tools')) {
				$note .= $this->wire(new SelfHostedVideoChecks())->renderTools($check, 'InputfieldSelfHostedVideo');
			}
			if($check->get('replace') && !$this->renderValueMode && !$this->noUpload) {
				// the smaller file goes in with the button of the area "Video file"
				$note .=
					"<span class='InputfieldSelfHostedVideoCheckAction'>" .
						"<button type='button' class='ui-button ui-widget ui-corner-all ui-state-default ui-priority-secondary InputfieldSelfHostedVideoCheckReplace'>" .
							"<i class='fa fa-upload' aria-hidden='true'></i> " . $sanitizer->entities($this->_('Replace video file')) .
						"</button>" .
					"</span>";
			}
			$items .=
				"<li class='InputfieldSelfHostedVideoCheck InputfieldSelfHostedVideoCheck_$status' data-check='" . $sanitizer->entities($check->name) . "'" . ($check->get('ask') ? " data-ask='1'" : '') . ">" .
					"<i class='fa fa-fw {$icons[$status]}' aria-hidden='true'></i>" .
					"<span class='InputfieldSelfHostedVideoCheckText'>" .
						"<span class='InputfieldSelfHostedVideoSr'>" . $sanitizer->entities($words[$status]) . " </span>" .
						"<span class='InputfieldSelfHostedVideoCheckLabel'>" . $sanitizer->entities($check->label) . "</span>" .
						$note .
					"</span>" .
				"</li>";
		}

		return $this->renderItemArea(
			'InputfieldSelfHostedVideoChecklist',
			$this->_('Checklist'),
			"<ul class='InputfieldSelfHostedVideoChecks' aria-labelledby='$id'>$items</ul>",
			$id
		);
	}

	/**
	 * Get what the editor for chapters and overlays needs to know: languages and texts
	 *
	 * @return array
	 *
	 */
	protected function getTimelineSettings() {

		$languages = $this->wire()->languages;
		$user = $this->wire()->user;
		$list = array();
		$current = 0;

		if($languages && $languages->count() > 1) {
			foreach($languages as $language) {
				/** @var Language $language */
				$id = $language->isDefault() ? 0 : (int) $language->id;
				$title = (string) $language->get('title|name');
				$list[] = array('id' => $id, 'title' => $title, 'editable' => (bool) $languages->editable($language));
				if($user->language && $user->language->id === $language->id) $current = $id;
			}
			// the default language first: its texts are what the others fall back to
			usort($list, function($a, $b) {
				return ($a['id'] === 0 ? 0 : 1) <=> ($b['id'] === 0 ? 0 : 1);
			});
		} else {
			$list[] = array('id' => 0, 'title' => '', 'editable' => true);
		}

		return array(
			'languages' => $list,
			'language' => $current,
			'labels' => array(
				'saveHint' => $this->_('Chapters and overlays are saved with the page. On the website the overlays appear on the video, and the chapters as a list below it.'),
				'language' => $this->_('Texts in'),
				'stage' => $this->_('Preview with overlays'),
				'dragHint' => $this->_('Drag an overlay to where it belongs, or move it with the arrow keys (with Shift in larger steps). Keep clear of the lower edge, where the player has its controls. While the preview is paused, overlays that are not shown at this moment are pale.'),
				'timeline' => $this->_('Timeline'),
				'noDuration' => $this->_('The timeline appears once the length of the video is known.'),
				'chapters' => $this->_('Chapters'),
				'chaptersNote' => $this->_('Chapters split the video into parts, each with a title. On the website they are listed below the video, and a click on a chapter jumps to that part.'),
				'overlays' => $this->_('Overlays'),
				'noChapters' => $this->_('No chapters yet. Move the preview to where a chapter begins, then add it. Chapters without a title are not saved.'),
				'noOverlays' => $this->_('No overlays yet. An overlay is a short text on the picture that can also be a link. Overlays without text are not saved.'),
				'addChapter' => $this->_('Add chapter at %s'),
				'addOverlay' => $this->_('Add overlay'),
				'title' => $this->_('Title'),
				'chapterTime' => $this->_('Time of chapter %d'),
				'chapterTitle' => $this->_('Title of chapter %d'),
				'goTo' => $this->_('Go to'),
				'goToLabel' => $this->_('Go to %s in the preview'),
				'remove' => $this->_('Remove'),
				'removeChapter' => $this->_('Remove chapter %d'),
				'removeOverlay' => $this->_('Remove overlay %d'),
				'overlayN' => $this->_('Overlay %d'),
				'emptyText' => $this->_('(no text yet)'),
				'text' => $this->_('Text'),
				'link' => $this->_('Link (optional)'),
				'linkHint' => $this->_('An address such as /contact/ or https://example.com/'),
				'when' => $this->_('When it is shown'),
				'whenAlways' => $this->_('During the whole video'),
				'whenRange' => $this->_('During a part of the video (from … to …)'),
				'whenEnd' => $this->_('When the video has ended'),
				'from' => $this->_('From'),
				'to' => $this->_('To'),
				'toHint' => $this->_('Empty: until the end'),
				'now' => $this->_('Now'),
				'nowLabel' => $this->_('Now: take the current position of the preview'),
				'posX' => $this->_('From the left (%)'),
				'posY' => $this->_('From the top (%)'),
				'width' => $this->_('Width (%)'),
				'widthHint' => $this->_('Empty: as wide as the text'),
				'size' => $this->_('Text size'),
				'sizeS' => $this->_('Small'),
				'sizeM' => $this->_('Medium'),
				'sizeL' => $this->_('Large'),
				'look' => $this->_('Look'),
				'lookDark' => $this->_('Dark box'),
				'lookLight' => $this->_('Light box'),
				'overlayLabel' => $this->_('Overlay %1$d: %2$s. %3$d percent from the left, %4$d percent from the top.'),
				'barChapter' => $this->_('Chapter %1$s at %2$s'),
				'barOverlay' => $this->_('Overlay %1$d: %2$s'),
				'moved' => $this->_('Moved to %1$d percent from the left, %2$d percent from the top.'),
				'barHint' => $this->_('On the timeline, drag a chapter mark or the bar of an overlay to another time, or drag an end of a bar to change when the overlay begins or ends. With the keyboard: left and right arrow keys, with Shift in steps of a second.'),
				'barStart' => $this->_('Overlay %d: beginning'),
				'barEnd' => $this->_('Overlay %d: end'),
				'chapterMoved' => $this->_('Chapter moved to %s.'),
				'overlayTimed' => $this->_('Overlay %1$d is shown from %2$s to %3$s.'),
				'badTime' => $this->_('Please enter a time such as 1:23.'),
				'chapterAdded' => $this->_('Chapter added at %s. Enter its title.'),
				'chapterRemoved' => $this->_('Chapter removed.'),
				'overlayAdded' => $this->_('Overlay added in the middle of the picture. Enter its text.'),
				'overlayRemoved' => $this->_('Overlay removed.'),
				'chapterCount1' => $this->_('1 chapter'),
				'chapterCountN' => $this->_('%d chapters'),
				'overlayCount1' => $this->_('1 overlay'),
				'overlayCountN' => $this->_('%d overlays'),
			),
		);
	}

	/**
	 * Render the entry to the editor for chapters and overlays of a video
	 *
	 * The editor itself is built by the script. What it edits travels in a hidden field of the form.
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @param bool $editable
	 * @return string
	 *
	 */
	protected function renderItemTimeline(Pagevideo $video, $id, $editable) {

		$sanitizer = $this->wire()->sanitizer;
		$data = $video->timelineData();
		$chapters = count($data['chapters']);
		$overlays = count($data['overlays']);

		$counts = array();
		if($chapters) $counts[] = sprintf($this->_n('%d chapter', '%d chapters', $chapters), $chapters);
		if($overlays) $counts[] = sprintf($this->_n('%d overlay', '%d overlays', $overlays), $overlays);
		$counts = count($counts) ? '(' . implode(', ', $counts) . ')' : '';

		$label = $sanitizer->entities($this->_('Chapters and overlays'));

		if(!$editable) {
			if(!strlen($counts)) return '';
			return
				"<p class='InputfieldSelfHostedVideoTimelineSummary detail'><i class='fa fa-fw fa-sliders' aria-hidden='true'></i> " .
				"$label " . $sanitizer->entities($counts) . "</p>";
		}

		$languages = $this->wire()->languages;
		$attrs = array(
			'class' => 'InputfieldSelfHostedVideoTimeline',
			'data-name' => $video->basename(),
			'data-url' => $video->URL,
			'data-poster' => (string) $this->getAdminThumbUrl($video),
			'data-duration' => $video->duration(),
			'data-width' => $video->width(),
			'data-height' => $video->height(),
			'data-nolang' => !$languages || $languages->count() < 2 || $this->noLang ? '1' : '0',
		);

		$json = $sanitizer->entities(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		return
			"<div " . $this->getAttributesString($attrs) . ">" .
				"<button type='button' class='InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoTimelineToggle' aria-expanded='false' aria-controls='timelinepanel_$id'>" .
					"<i class='fa fa-fw fa-sliders' aria-hidden='true'></i><span>$label</span> " .
					"<span class='InputfieldSelfHostedVideoTimelineCount'>" . $sanitizer->entities($counts) . "</span>" .
				"</button>" .
				"<div class='InputfieldSelfHostedVideoTimelinePanel' id='timelinepanel_$id' hidden></div>" .
				"<textarea class='InputfieldSelfHostedVideoTimelineData' name='timeline_$id' hidden aria-hidden='true' tabindex='-1'>$json</textarea>" .
			"</div>";
	}

	/**
	 * Process what the editor for chapters and overlays sent with the form
	 *
	 * @param Pagevideo $video
	 * @param string $json
	 * @return bool True if anything changed
	 *
	 */
	protected function processInputTimeline(Pagevideo $video, $json) {

		if(strlen($json) > 400000) {
			$this->error($this->_('Chapters and overlays were not saved, because there were too many of them.'));
			return false;
		}

		$data = json_decode($json, true);
		if(!is_array($data)) return false;

		$sanitizer = $this->wire()->sanitizer;
		$languages = $this->wire()->languages;
		$langKeys = $video->langKeys();
		$duration = (float) $video->duration();
		$stored = $video->timelineData();
		$changed = false;

		// languages the current user may not edit keep their texts
		$locked = array();
		if($languages && count($langKeys) > 1) {
			foreach($languages as $language) {
				/** @var Language $language */
				if(!$languages->editable($language)) $locked[] = $language->isDefault() ? 0 : (int) $language->id;
			}
		}

		if(isset($data['chapters']) && is_array($data['chapters'])) {
			$chapters = SelfHostedVideoTimeline::cleanChapters($data['chapters'], $duration, $langKeys, $sanitizer);
			$chapters = SelfHostedVideoTimeline::keepLockedTexts($chapters, $stored['chapters'], $locked, array('title'));
			if($video->storeTimeline('chapters', $chapters)) $changed = true;
		}

		if(isset($data['overlays']) && is_array($data['overlays'])) {
			$overlays = SelfHostedVideoTimeline::cleanOverlays($data['overlays'], $duration, $langKeys, $sanitizer);
			$overlays = SelfHostedVideoTimeline::keepLockedTexts($overlays, $stored['overlays'], $locked, array('text', 'url'));
			if($video->storeTimeline('overlays', $overlays)) $changed = true;
		}

		return $changed;
	}

	/**
	 * Render the caption tracks of a video and what is needed to add one
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @param bool $editable
	 * @return string
	 *
	 */
	protected function renderItemCaptions(Pagevideo $video, $id, $editable) {

		$sanitizer = $this->wire()->sanitizer;
		$label = $sanitizer->entities($this->_('Captions'));
		$out = "<p class='InputfieldSelfHostedVideoLabel detail' id='captions_$id'>$label</p>";
		$list = $this->renderItemCaptionList($video, $editable);

		if($editable) {
			$options = "<option value=''>" . $sanitizer->entities($this->_('Language…')) . "</option>";
			foreach(SelfHostedVideoCaptions::languages() as $code => $name) {
				$options .= "<option value='$code'>" . $sanitizer->entities($name) . " ($code)</option>";
			}
			$options .= "<option value='other'>" . $sanitizer->entities($this->_('Other language…')) . "</option>";

			$langLabel = $sanitizer->entities($this->_('Language of the captions'));
			$codeLabel = $sanitizer->entities($this->_('Language code, e.g. rm'));
			$nameLabel = $sanitizer->entities($this->_('Name of the language, e.g. Rumantsch'));
			$add = $sanitizer->entities($this->_('Add captions file'));
			$hint = $sanitizer->entities($this->_('WebVTT (.vtt) or SubRip (.srt) file. Captions are saved right away. A file for a language that has captions takes their place.'));
			$noSpeech = $sanitizer->entities($this->_('This video has no speech or other sound that matters'));
			$checked = $video->noSpeech() ? " checked='checked'" : '';
			// a video without speech needs no captions: all about them stays out of the way
			$hidden = $video->noSpeech() ? ' hidden' : '';

			$out .=
				"<label class='InputfieldSelfHostedVideoNoSpeech'>" .
					"<input type='checkbox' class='uk-checkbox' name='noSpeech_$id' value='1' aria-controls='captionsbody_$id'$checked> $noSpeech" .
				"</label>" .
				"<input type='hidden' name='noSpeechShown_$id' value='1'>" .
				"<div class='InputfieldSelfHostedVideoCaptionsBody' id='captionsbody_$id'$hidden>" .
					$list .
					"<div class='InputfieldSelfHostedVideoCaptionAdd'>" .
						"<label class='InputfieldSelfHostedVideoSr' for='captionlang_$id'>$langLabel</label>" .
						"<select id='captionlang_$id' class='InputfieldSelfHostedVideoCaptionLang uk-select'>$options</select>" .
						"<input type='text' class='InputfieldSelfHostedVideoCaptionCode uk-input' maxlength='20' placeholder='$codeLabel' aria-label='$codeLabel' hidden>" .
						"<input type='text' class='InputfieldSelfHostedVideoCaptionName uk-input' maxlength='60' placeholder='$nameLabel' aria-label='$nameLabel' hidden>" .
						"<button type='button' class='InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoCaptionUpload' data-accept='.vtt,.srt,text/vtt'>" .
							"<i class='fa fa-fw fa-cc' aria-hidden='true'></i><span>$add</span></button>" .
					"</div>" .
					"<p class='InputfieldSelfHostedVideoCaptionHint detail'>$hint</p>" .
					"<p class='InputfieldSelfHostedVideoCaptionStatus detail' role='status' aria-live='polite'></p>" .
				"</div>";
		} else {
			$out .= $list;
		}

		return "<div class='InputfieldSelfHostedVideoCaptions' role='group' aria-labelledby='captions_$id'>$out</div>";
	}

	/**
	 * Render the list of caption tracks of a video
	 *
	 * @param Pagevideo $video
	 * @param bool $editable
	 * @return string
	 *
	 */
	protected function renderItemCaptionList(Pagevideo $video, $editable) {

		$sanitizer = $this->wire()->sanitizer;
		$tracks = $video->captions();
		$items = '';

		foreach($tracks as $lang => $track) {
			$name = $sanitizer->entities($track->label);
			$facts = $sanitizer->entities($lang . ' · ' . sprintf($this->_n('%d caption', '%d captions', $track->cues), $track->cues));
			$url = $sanitizer->entities($track->URL);
			$view = $sanitizer->entities($this->_('Show file'));
			$viewLabel = $sanitizer->entities(sprintf($this->_('Show file of the captions in %s'), $track->label));
			$button = '';
			if($editable) {
				$remove = $sanitizer->entities($this->_('Remove'));
				$removeLabel = $sanitizer->entities(sprintf($this->_('Remove captions: %s'), $track->label));
				$edit = $sanitizer->entities($this->_('Edit'));
				$editLabel = $sanitizer->entities(sprintf($this->_('Edit captions: %s'), $track->label));
				$button =
					"<button type='button' class='InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoCaptionEdit' aria-label='$editLabel' aria-expanded='false'>" .
					"<i class='fa fa-fw fa-pencil' aria-hidden='true'></i><span>$edit</span></button>" .
					"<button type='button' class='InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoCaptionRemove' aria-label='$removeLabel'>" .
					"<i class='fa fa-fw fa-trash-o' aria-hidden='true'></i><span>$remove</span></button>";
			}
			$items .=
				"<li class='InputfieldSelfHostedVideoTrack' data-lang='$lang' data-label='$name'>" .
					"<span class='InputfieldSelfHostedVideoTrackName'>$name</span> " .
					"<span class='InputfieldSelfHostedVideoTrackFacts detail'>$facts</span> " .
					"<a class='InputfieldSelfHostedVideoTrackLink' href='$url' target='_blank' rel='noopener' aria-label='$viewLabel'>$view</a>" .
					$button .
				"</li>";
		}

		if(!strlen($items)) {
			$none = $sanitizer->entities($this->_('No captions yet.'));
			return "<div class='InputfieldSelfHostedVideoCaptionList'><p class='InputfieldSelfHostedVideoNoTracks detail'>$none</p></div>";
		}

		return "<div class='InputfieldSelfHostedVideoCaptionList'><ul class='InputfieldSelfHostedVideoTracks'>$items</ul></div>";
	}

	/**
	 * Get the caption tracks of a video in the form the script uses to update the preview player
	 *
	 * @param Pagevideo $video
	 * @return array
	 *
	 */
	protected function getItemTracks(Pagevideo $video) {
		$tracks = array();
		foreach($video->captions() as $lang => $track) {
			$tracks[] = array('lang' => $lang, 'label' => $track->label, 'kind' => $track->kind, 'url' => $track->URL);
		}
		return $tracks;
	}

	/**
	 * Get the attributes of the element that holds one video, which the script reads its settings from
	 *
	 * @param Pagevideo $video
	 * @param bool $editable
	 * @return array
	 *
	 */
	protected function getItemAttributes(Pagevideo $video, $editable) {

		$info = $video->thumbInfo();
		$hasThumb = $video->hasThumb();
		$class = 'InputfieldSelfHostedVideoBox';
		if($video->isReference()) $class .= ' InputfieldSelfHostedVideoReference';
		if($video->isPortrait()) $class .= ' InputfieldSelfHostedVideoPortrait';

		$attrs = array('class' => $class);

		if($editable && !$this->noUpload) {
			$time = $hasThumb && !$info['custom'] ? $info['time'] : (float) $this->thumbTime;
			$attrs['data-name'] = $video->basename();
			$attrs['data-url'] = $video->URL;
			$attrs['data-thumb-time'] = $info['stale'] ? $info['time'] : $time;
			$attrs['data-default-time'] = max(0.0, (float) $this->thumbTime);
			$attrs['data-needs-thumb'] = (!$hasThumb || $info['stale']) ? '1' : '0';
			$attrs['data-custom'] = $hasThumb && $info['custom'] ? '1' : '0';
			$attrs['data-extensions'] = $this->getAllowedExtensions();
		}

		return $attrs;
	}

	/**
	 * Render the preview player
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderItemPlayer(Pagevideo $video) {

		$sanitizer = $this->wire()->sanitizer;
		$attrs = array(
			'class' => 'InputfieldSelfHostedVideoPlayer',
			'src' => $video->URL,
			'controls' => 'controls',
			'preload' => 'none',
			'playsinline' => 'playsinline',
		);

		$poster = $this->getAdminThumbUrl($video);
		if($poster) $attrs['poster'] = $poster;

		if($video->width() && $video->height()) {
			$attrs['width'] = $video->width();
			$attrs['height'] = $video->height();
		}

		$label = $video->title();
		if(!strlen($label)) $label = $video->isReference() ? $video->get('ref_name') : $video->basename();
		$attrs['aria-label'] = sprintf($this->_('Preview of %s'), $label);

		$out = "<video";
		foreach($attrs as $key => $value) {
			$out .= " $key='" . $sanitizer->entities($value) . "'";
		}
		$out .= ">";
		foreach($this->getItemTracks($video) as $track) {
			$out .=
				"<track kind='" . $sanitizer->entities($track['kind']) . "' src='" . $sanitizer->entities($track['url']) . "'" .
				" srclang='" . $sanitizer->entities($track['lang']) . "' label='" . $sanitizer->entities($track['label']) . "'>";
		}
		$out .= "</video>";

		return "<div class='InputfieldSelfHostedVideoPlayerWrap'>$out</div>";
	}

	/**
	 * Get the URL of the thumbnail in the size used by the page editor
	 *
	 * @param Pagevideo $video
	 * @return string Blank if the video has no thumbnail
	 *
	 */
	public function getAdminThumbUrl(Pagevideo $video) {
		$thumb = $video->thumb();
		if(!$thumb) return '';
		try {
			if($thumb->width() > self::adminThumbWidth) {
				$sized = $thumb->width(self::adminThumbWidth);
				if($sized && is_file($sized->filename())) return $sized->URL;
			}
		} catch(\Exception $e) {
			// could not be resized here: the full size image does the job too
		}
		return $thumb->URL;
	}

	/**
	 * Render the thumbnail and replacement tools shown below the player
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @return string
	 *
	 */
	protected function renderItemTools(Pagevideo $video, $id, $fileLink = '', $player = '') {

		// note: no file inputs in here. The core upload script takes the first file input it finds
		// in a field for its own, so the script creates one only for the moment a file is chosen.

		$sanitizer = $this->wire()->sanitizer;
		$info = $video->thumbInfo();
		$custom = $video->hasThumb() && $info['custom'];

		$useFrame = $sanitizer->entities($this->_('Use current frame'));
		$uploadImage = $sanitizer->entities($this->_('Upload image'));
		$resetFrame = $sanitizer->entities($this->_('Back to video frame'));
		$replace = $sanitizer->entities($this->_('Replace video file'));
		$retry = $sanitizer->entities($this->_('Try again'));
		$cancel = $sanitizer->entities($this->_('Cancel'));
		$intro = $sanitizer->entities($this->_('Choose the thumbnail in one of three ways: a frame you pick in the video (1), one of the suggested pictures (2) or an image of your own (3). Changes are saved right away.'));
		$hint = $sanitizer->entities($this->_('Play or drag the video to the picture you want, then choose “Use current frame”.'));
		$frameTitle = $sanitizer->entities($this->_('Frame from the video'));
		$framesLabel = $sanitizer->entities($this->_('Suggested pictures'));
		$framesHint = $sanitizer->entities($this->_('Click a picture to use it.'));
		$framesWait = $sanitizer->entities($this->_('Making pictures…'));
		$imageTitle = $sanitizer->entities($this->_('Image of your own'));
		$imageHint = $sanitizer->entities($this->_('A JPEG or PNG image, for example a picture with the title of the video.'));
		// the time of the picture now in use, where it is a picture of the video
		$current = $video->hasThumb() && !$info['custom'] ? ' data-current="' . round((float) $info['time'], 2) . '"' : '';
		$hidden = $custom ? '' : ' hidden';
		$accept = $sanitizer->entities($this->getAcceptAttribute());
		// styled by this module alone, so that the tools look the same in every admin theme style
		$button = 'InputfieldSelfHostedVideoButton';

		// one of the three ways to a thumbnail: number, title, and what belongs to it
		$step = function($n, $class, $title, $body, $attrs = '') use($id) {
			return
				"<div class='InputfieldSelfHostedVideoStep $class' role='group' aria-labelledby='vf_step{$n}_$id'$attrs>" .
					"<p class='InputfieldSelfHostedVideoStepTitle' id='vf_step{$n}_$id'>" .
						"<span class='InputfieldSelfHostedVideoStepNumber' aria-hidden='true'>$n</span>$title</p>" .
					$body .
				"</div>";
		};

		// the video is in the area of its thumbnail: it is where the picture is chosen
		$thumbnail =
			"<p class='InputfieldSelfHostedVideoIntro detail'>$intro</p>" .
			$player .
			$step(1, 'InputfieldSelfHostedVideoStepFrame', $frameTitle,
				"<p class='InputfieldSelfHostedVideoHint detail'>$hint</p>" .
				"<button type='button' class='$button InputfieldSelfHostedVideoUseFrame'>" .
					"<i class='fa fa-fw fa-camera' aria-hidden='true'></i><span>$useFrame</span></button>") .
			"<hr class='InputfieldSelfHostedVideoStepRule'>" .
			// six pictures spread over the video, made in the browser once this part is in view
			$step(2, 'InputfieldSelfHostedVideoFrames', $framesLabel,
				"<p class='InputfieldSelfHostedVideoHint InputfieldSelfHostedVideoFramesHint detail'>$framesHint</p>" .
				"<p class='InputfieldSelfHostedVideoFramesNote detail'>$framesWait</p>" .
				"<ul class='InputfieldSelfHostedVideoFrameList'></ul>",
				" data-count='" . self::frameSuggestions . "'$current") .
			"<hr class='InputfieldSelfHostedVideoStepRule'>" .
			$step(3, 'InputfieldSelfHostedVideoStepImage', $imageTitle,
				"<p class='InputfieldSelfHostedVideoHint detail'>$imageHint</p>" .
				"<button type='button' class='$button InputfieldSelfHostedVideoUploadImage' data-accept='image/jpeg,image/png'>" .
					"<i class='fa fa-fw fa-picture-o' aria-hidden='true'></i><span>$uploadImage</span></button> " .
				"<button type='button' class='$button InputfieldSelfHostedVideoResetFrame'$hidden>" .
					"<i class='fa fa-fw fa-undo' aria-hidden='true'></i><span>$resetFrame</span></button>") .
			"<p class='InputfieldSelfHostedVideoStatus detail' role='status' aria-live='polite'></p>";

		// the file itself: what it is called (where the header of the video does not say), and a new one for it
		$file =
			$fileLink .
			"<button type='button' class='$button InputfieldSelfHostedVideoReplaceFile' data-accept='$accept'>" .
				"<i class='fa fa-fw fa-refresh' aria-hidden='true'></i><span>$replace</span></button>" .
			"<button type='button' class='$button InputfieldSelfHostedVideoDuring InputfieldSelfHostedVideoReplaceRetry' hidden>$retry</button> " .
			"<button type='button' class='$button InputfieldSelfHostedVideoDuring InputfieldSelfHostedVideoReplaceCancel' hidden>$cancel</button>" .
			"<p class='InputfieldSelfHostedVideoReplaceStatus detail' role='status' aria-live='polite'></p>";

		return
			$this->renderItemArea('InputfieldSelfHostedVideoTools InputfieldSelfHostedVideoThumbTools InputfieldSelfHostedVideoPreview', $this->_('Video and thumbnail'), $thumbnail, "thumbtools_$id") .
			$this->renderItemArea('InputfieldSelfHostedVideoTools InputfieldSelfHostedVideoReplace', $this->_('Video file'), $file);
	}

	/**
	 * Render the inputs of one uploaded video: title, description, tags, thumbnail text
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @param int $n
	 * @return string
	 *
	 */
	protected function renderItemFields(Pagevideo $video, $id, $n) {

		$sanitizer = $this->wire()->sanitizer;

		// description and tags come from the file input, each under a visible label of its own
		$useTags = $this->useTags;
		$this->useTags = 0;
		$description = $this->renderItemDescriptionField($video, $id, $n);
		// how long a description may be, said where it is typed
		$description = preg_replace("/<(input type='text'|textarea)(?![^>]*maxlength)/", "<$1 maxlength='" . self::maxDescriptionLength . "'", $description);
		$this->useTags = $useTags;

		if(strlen($description) && !$this->renderValueMode) {
			$label = $sanitizer->entities1($this->labels['description']);
			$description =
				"<div class='InputfieldSelfHostedVideoLangText InputfieldSelfHostedVideoLangText_description' role='group' aria-label='$label'>" .
				"<p class='InputfieldSelfHostedVideoLabel detail'>$label</p>$description</div>";
		}

		$tags = '';
		if($useTags) {
			if($this->renderValueMode) {
				$list = $video->tags(true);
				if(is_array($list) && count($list)) {
					$tags = "<p class='InputfieldSelfHostedVideoText detail'><i class='fa fa-fw fa-tags'></i> " .
						$sanitizer->entities(implode(', ', $list)) . "</p>";
				}
			} else {
				$label = $sanitizer->entities1($this->labels['tags']);
				$tags =
					"<div class='InputfieldSelfHostedVideoLangText InputfieldSelfHostedVideoLangText_tags'>" .
					"<p class='InputfieldSelfHostedVideoLabel detail'>$label</p>" .
					$this->renderItemTagsField($video, $id, $n) . "</div>";
			}
		}

		return
			$this->renderItemLangText($video, $id, 'title', $this->_('Title')) .
			$description .
			$tags .
			$this->renderItemLangText($video, $id, 'thumbAlt', $this->_('Thumbnail text'),
				$this->_('Describes the thumbnail for people who cannot see it. Leave empty to use the title.')) .
			$this->renderItemCaptions($video, $id, !$this->renderValueMode && !$this->noUpload) .
			$this->renderItemEnd($video, $id) .
			$this->renderItemEmbed($video, $id);
	}

	/**
	 * Render the choice what the player offers when the video has ended
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @return string
	 *
	 */
	protected function renderItemEnd(Pagevideo $video, $id) {

		$sanitizer = $this->wire()->sanitizer;
		$label = $sanitizer->entities($this->_('At the end of the video'));
		$own = $video->endAction();
		$options = array(
			'' => $this->_('Nothing: the last picture stays'),
			'replay' => $this->_('Offer to watch it again'),
			'next' => $this->_('Offer the next video of this field'),
			'link' => $this->_('Show a button with a link'),
		);

		if($this->renderValueMode) {
			if($own === '') return '';
			return
				"<div class='InputfieldSelfHostedVideoText InputfieldSelfHostedVideoText_end'>" .
				"<span class='detail'>$label:</span> " . $sanitizer->entities($options[$own]) .
				($own === 'link' && strlen($video->endLink()) ? ' (' . $sanitizer->entities($video->endLink()) . ')' : '') .
				"</div>";
		}

		$select = '';
		foreach($options as $value => $text) {
			$selected = $value === $own ? " selected='selected'" : '';
			$select .= "<option value='$value'$selected>" . $sanitizer->entities($text) . "</option>";
		}
		$notes = $sanitizer->entities($this->_('Shown on the picture when the video has ended, with a button to watch it again. The next video is the one after this in the field, if the page shows it.'));
		$linkLabel = $sanitizer->entities($this->_('Address of the button'));
		$link = $sanitizer->entities($video->endLink());
		$placeholder = $sanitizer->entities($this->_('/contact/ or https://…'));
		$linkNote = $sanitizer->entities($this->_('A page of this website opens in the same window, a page of another website in a new one.'));

		return
			"<div class='InputfieldSelfHostedVideoLangText InputfieldSelfHostedVideoEnd'>" .
				"<label for='end_$id' class='detail'>$label</label>" .
				"<select id='end_$id' name='end_$id' class='uk-select InputfieldSelfHostedVideoEndSelect' aria-describedby='endnote_$id'>$select</select>" .
				"<input type='hidden' name='endShown_$id' value='1'>" .
				"<p class='InputfieldSelfHostedVideoNotes notes' id='endnote_$id'>$notes</p>" .
				"<div class='InputfieldSelfHostedVideoEndLink'" . ($own === 'link' ? '' : " data-shv-hidden='1'") . ">" .
					"<div class='InputfieldSelfHostedVideoLangText InputfieldSelfHostedVideoLangText_endLink'>" .
						"<label for='endLink_$id' class='detail'>$linkLabel</label>" .
						"<input type='text' name='endLink_$id' id='endLink_$id' value='$link' maxlength='2048' placeholder='$placeholder' aria-describedby='endLinkNote_$id' />" .
						"<p class='InputfieldSelfHostedVideoNotes notes' id='endLinkNote_$id'>$linkNote</p>" .
					"</div>" .
					$this->renderItemLangText($video, $id, 'endText',
						$this->_('Text of the button'),
						$this->_('Leave empty for “More”.')) .
				"</div>" .
			"</div>";
	}

	/**
	 * Render the choice whether other websites may embed the video
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @return string
	 *
	 */
	protected function renderItemEmbed(Pagevideo $video, $id) {

		$sanitizer = $this->wire()->sanitizer;
		$embed = $this->wire()->modules->get('FieldtypeSelfHostedVideo')->embedRules();
		$label = $sanitizer->entities($this->_('Embedding on other websites'));
		$allowed = $sanitizer->entities($this->_('Allowed'));
		$blocked = $sanitizer->entities($this->_('Blocked'));
		$own = $video->embed();

		if($this->renderValueMode || $this->noUpload) {
			if($own === '' && $embed->siteAllows()) return '';
			return
				"<div class='InputfieldSelfHostedVideoText InputfieldSelfHostedVideoText_embed'>" .
				"<span class='detail'>$label:</span> " . ($embed->isBlocked($video) ? $blocked : $allowed) .
				"</div>";
		}

		// one text per line: the translation parser of ProcessWire finds only the first of a line
		$siteSetting = $embed->siteAllows()
			? $this->_('allowed')
			: $this->_('blocked');
		$site = sprintf($this->_('As set for the site (%s)'), $siteSetting);
		$options = array('' => $site, 'allow' => $this->_('Allowed'), 'block' => $this->_('Blocked'));
		$select = '';
		foreach($options as $value => $text) {
			$selected = $value === $own ? " selected='selected'" : '';
			$select .= "<option value='$value'$selected>" . $sanitizer->entities($text) . "</option>";
		}
		$notes = $sanitizer->entities($this->_('Blocked: other websites cannot show the video, its thumbnail or its captions in their pages. This website, its subdomains and the websites allowed in the settings of the module still can.'));

		return
			"<div class='InputfieldSelfHostedVideoLangText InputfieldSelfHostedVideoEmbed'>" .
				"<label for='embed_$id' class='detail'>$label</label>" .
				"<select id='embed_$id' name='embed_$id' class='uk-select InputfieldSelfHostedVideoEmbedSelect' aria-describedby='embednote_$id'>$select</select>" .
				"<input type='hidden' name='embedShown_$id' value='1'>" .
				"<p class='InputfieldSelfHostedVideoNotes notes' id='embednote_$id'>$notes</p>" .
			"</div>";
	}

	/**
	 * Render what is shown for a video reused from another page
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderItemReference(Pagevideo $video) {

		$sanitizer = $this->wire()->sanitizer;
		$original = $video->original();
		$out = '';

		$title = $video->title();
		if(strlen($title)) $out .= "<p class='InputfieldSelfHostedVideoTitle'><strong>" . $sanitizer->entities1($title) . "</strong></p>";

		$description = $video->description();
		if(strlen($description)) $out .= "<p class='InputfieldSelfHostedVideoText'>" . $sanitizer->entities1($description) . "</p>";

		$tags = $video->tags(true);
		if(is_array($tags) && count($tags)) {
			$out .= "<p class='InputfieldSelfHostedVideoText detail'><i class='fa fa-fw fa-tags'></i> " .
				$sanitizer->entities(implode(', ', $tags)) . "</p>";
		}

		if($video->hasCaptions()) $out .= $this->renderItemCaptions($video, $this->name . '_' . $video->hash, false);

		if($original) {
			$page = $original->page;
			$pageTitle = $sanitizer->entities1((string) $page->get('title|name'));
			$path = $sanitizer->entities($page->path());
			if($page->editable()) {
				$link = "<a href='" . $sanitizer->entities($page->editUrl()) . "' target='_blank'>$pageTitle</a>";
			} else {
				$link = $pageTitle;
			}
			$out .=
				"<p class='InputfieldSelfHostedVideoNote notes'>" .
				sprintf(
					$this->_('This video is reused from %1$s (%2$s). Its title, description, tags, thumbnail and captions are edited there.'),
					$link,
					$path
				) .
				"</p>";
		}

		return $out;
	}

	/**
	 * Render a short text input in one or more languages, such as the title
	 *
	 * @param Pagevideo $video
	 * @param string $id
	 * @param string $key Name of the text, i.e. "title"
	 * @param string $label
	 * @param string $notes Optional notes shown below the input
	 * @return string
	 *
	 */
	protected function renderItemLangText(Pagevideo $video, $id, $key, $label, $notes = '') {

		$sanitizer = $this->wire()->sanitizer;
		$languages = $this->wire()->languages;
		$user = $this->wire()->user;
		$label = $sanitizer->entities1($label);
		$notes = strlen($notes) ? "<p class='InputfieldSelfHostedVideoNotes notes'>" . $sanitizer->entities1($notes) . "</p>" : '';

		if($this->renderValueMode) {
			$value = $video->langText($key, $languages && !$this->noLang ? $user->language : null);
			if(!strlen($value)) return '';
			return
				"<div class='InputfieldSelfHostedVideoText InputfieldSelfHostedVideoText_$key'>" .
				"<span class='detail'>$label:</span> " . $sanitizer->entities1($value) .
				"</div>";
		}

		$useLanguages = $languages && $languages->count() > 1 && !$this->noLang;

		if(!$useLanguages) {
			$name = "{$key}_$id";
			$value = $sanitizer->entities($video->langText($key, $languages ? $languages->getDefault() : null));
			return
				"<div class='InputfieldSelfHostedVideoLangText InputfieldSelfHostedVideoLangText_$key'>" .
				"<label for='$name' class='detail'>$label</label>" .
				"<input type='text' name='$name' id='$name' value='$value' maxlength='" . self::textLength($key) . "' />" .
				$notes .
				"</div>";
		}

		// one input per language, in language tabs when that module is installed (as descriptions do)
		$modules = $this->wire()->modules;
		$hasLangTabs = $modules->isInstalled('LanguageTabs');
		$tabSettings = array();
		if($hasLangTabs) {
			/** @var LanguageTabs $languageTabs */
			$languageTabs = $modules->getModule('LanguageTabs');
			$tabSettings = $languageTabs->getSettings();
		}

		$tabs = '';
		$inputs = '';

		foreach($languages as $language) {
			/** @var Language $language */
			$tabField = empty($tabSettings['tabField']) ? 'title' : $tabSettings['tabField'];
			$langLabel = (string) $language->getUnformatted($tabField);
			if(!strlen($langLabel)) $langLabel = $language->get('name');
			$langLabel = $sanitizer->entities($langLabel);
			$name = $language->isDefault() ? "{$key}_$id" : "{$key}{$language->id}_$id";
			$labelClass = 'detail LanguageSupportLabel';
			$readonly = '';
			if(!$languages->editable($language)) {
				$labelClass .= ' LanguageNotEditable';
				$langLabel = "<s>$langLabel</s>";
				$readonly = " readonly='readonly'";
			}
			$tabID = "langTab_{$id}_{$key}__$language";
			$aClass = "langTab$language";
			if(!empty($tabSettings['aClass'])) $aClass .= ' ' . $tabSettings['aClass'];
			$value = $sanitizer->entities($video->langText($key, $language));
			$tabs .= "<li><a data-lang='$language' class='$aClass' href='#$tabID'>$langLabel</a></li>";
			$inputs .=
				"<div class='InputfieldSelfHostedVideoLangInput LanguageSupport' data-language='$language' id='$tabID'>" .
				"<label for='$name' class='$labelClass'>$langLabel</label>" .
				"<input type='text' name='$name' id='$name' value='$value' maxlength='" . self::textLength($key) . "' aria-label='$label ($langLabel)'$readonly />" .
				"</div>";
		}

		if($hasLangTabs) {
			$ulClass = empty($tabSettings['ulClass']) ? '' : " class='$tabSettings[ulClass]'";
			$ulAttr = empty($tabSettings['ulAttrs']) ? '' : " $tabSettings[ulAttrs]";
			$inputs =
				"<div class='hasLangTabs langTabsContainer'>" .
					"<div class='langTabs'>" .
						"<ul $ulAttr$ulClass>$tabs</ul>" .
						$inputs .
					"</div>" .
				"</div>";
			if($this->isAjax) {
				$js = 'script';
				$inputs .= "<$js>setupLanguageTabs($('#wrap_" . $this->attr('id') . "'));</$js>";
			}
		}

		return
			"<div class='InputfieldSelfHostedVideoLangText InputfieldSelfHostedVideoLangText_$key' role='group' aria-label='$label'>" .
			"<p class='InputfieldSelfHostedVideoLabel detail'>$label</p>" .
			$inputs .
			$notes .
			"</div>";
	}

	/*** INPUT **********************************************************************************/

	/**
	 * A video was uploaded: read what the file tells about itself before it is shown
	 *
	 * @param Pagefile $pagefile
	 *
	 */
	protected function ___fileAdded(Pagefile $pagefile) {
		if($pagefile instanceof Pagevideo) {
			// an MP4 whose index is at its end: moved to the front, so that it can start while it loads
			$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
			if($fieldtype && (int) $fieldtype->get('faststart')) $pagefile->faststart();
			$pagefile->probe();
		}
		parent::___fileAdded($pagefile);
	}

	/**
	 * Get the video that stands in the way of a new upload in a single video field, if any
	 *
	 * In a field limited to one video, an upload takes the place of the video that is there.
	 * That is not possible while other pages reuse that video.
	 *
	 * @return Pagevideo|null
	 *
	 */
	protected function getIrreplaceableVideo() {
		if((int) $this->maxFiles !== 1 || !$this->value instanceof Pagevideos || !count($this->value)) return null;
		$current = $this->value->first();
		if(!$current instanceof Pagevideo || $current->isReference() || $current->isTemp()) return null;
		return $current->isReferenced() ? $current : null;
	}

	/**
	 * Process input to add an uploaded file
	 *
	 * @param string $filename
	 * @return Pagefile|null
	 *
	 */
	protected function ___processInputAddFile($filename) {

		$current = $this->getIrreplaceableVideo();

		if($current && $filename !== $current->basename()) {
			$file = $this->value->path() . basename($filename);
			if(is_file($file)) $this->wire()->files->unlink($file);
			$message = sprintf(
				$this->_('“%s” is used on other pages, so another video cannot take its place here. To give it a new file, use “Replace video file”.'),
				$current->basename()
			);
			if($this->isAjax && !$this->noAjax) {
				$this->ajaxResponse(true, $message);
			} else {
				$this->error($message);
			}
			return null;
		}

		return parent::___processInputAddFile($filename);
	}

	/**
	 * Get what is carried over from a video when another file takes its place in a single video field
	 *
	 * @param Pagefile $pagefile
	 * @param array $metadata
	 * @return array
	 *
	 */
	protected function ___extractMetadata(Pagefile $pagefile, array $metadata = []) {
		$metadata = parent::___extractMetadata($pagefile, $metadata);
		// texts and tags carry over, the thumbnail does not: it shows a picture of the old file
		if(isset($metadata['filedata']) && is_array($metadata['filedata'])) {
			// (nor what else belongs to the old file: its captions and what is known about the file)
			unset($metadata['filedata']['thumb'], $metadata['filedata']['uploadName']);
			unset($metadata['filedata']['captions'], $metadata['filedata']['media'], $metadata['filedata']['unplayable'], $metadata['filedata']['noSpeech']);
			unset($metadata['filedata']['chapters'], $metadata['filedata']['overlays']);
			if(!count($metadata['filedata'])) unset($metadata['filedata']);
		}
		return $metadata;
	}

	/**
	 * Process input for one video
	 *
	 * @param WireInputData $input
	 * @param Pagefile $pagefile
	 * @param int $n
	 * @return bool True if anything changed
	 *
	 */
	protected function ___processInputFile(WireInputData $input, Pagefile $pagefile, $n) {

		if($pagefile->isTemp() && count($this->value) > 1 && $this->getIrreplaceableVideo()) {
			// a new upload waiting to take the place of a video that cannot be removed: drop the upload
			// (the file input would otherwise keep trying to remove the video that has to stay)
			$this->error(sprintf(
				$this->_('“%s” was not added, because the video it would replace is used on other pages.'),
				$pagefile->basename()
			));
			$this->value->remove($pagefile);
			$this->trackChange('value');
			return true;
		}

		$changed = parent::___processInputFile($input, $pagefile, $n);

		if(!$pagefile instanceof Pagevideo || $pagefile->isReference()) return $changed;

		$id = $this->name . '_' . $pagefile->hash;

		foreach(array('title', 'thumbAlt') as $key) {
			if($this->processInputLangText($input, $pagefile, $id, $key)) $changed = true;
		}

		// the description is taken by the file input as typed: it gets the same care
		if($this->cleanDescription($pagefile)) $changed = true;

		if(isset($input["timeline_$id"]) && !$this->noUpload) {
			if($this->processInputTimeline($pagefile, (string) $input["timeline_$id"])) $changed = true;
		}

		if(isset($input["embedShown_$id"]) && !$pagefile->isReference()) {
			$embed = (string) $input["embed_$id"];
			$embed = in_array($embed, array('allow', 'block'), true) ? $embed : '';
			if($embed !== $pagefile->embed()) {
				$pagefile->embed($embed);
				$changed = true;
			}
		}

		if(isset($input["endShown_$id"])) {
			$end = (string) $input["end_$id"];
			if($end !== $pagefile->endAction()) {
				$pagefile->endAction($end);
				$changed = true;
			}
			if(isset($input["endLink_$id"])) {
				$typed = trim((string) $input["endLink_$id"]);
				$link = Pagevideo::cleanLink($typed);
				if($link !== $pagefile->endLink()) {
					$pagefile->endLink($link);
					$changed = true;
				}
				if(strlen($typed) && $link === '') {
					$this->error(sprintf($this->_('%1$s: the address “%2$s” was not kept. Use an address of this website (beginning with /), or one beginning with https://, mailto: or tel:.'), $pagefile->basename(), $typed));
				} else if($pagefile->endAction() === 'link' && $link === '') {
					$this->warning(sprintf($this->_('%s: the button at the end of the video needs an address. Without one it is not shown.'), $pagefile->basename()));
				}
			}
			if($this->processInputLangText($input, $pagefile, $id, 'endText')) $changed = true;
		}

		if(isset($input["noSpeechShown_$id"])) {
			$noSpeech = !empty($input["noSpeech_$id"]);
			if($noSpeech !== $pagefile->noSpeech()) {
				$pagefile->noSpeech($noSpeech);
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Longest text for an input, in characters
	 *
	 * @param string $key "title", "description" or "thumbAlt"
	 * @return int
	 *
	 */
	public static function textLength($key) {
		return $key === 'description' ? self::maxDescriptionLength : self::maxTitleLength;
	}

	/**
	 * Clean what was entered into a text input: one line of text, no control characters, not too long
	 *
	 * Markup is kept as typed: it is text, which the output escapes (or the text formatters of the field handle).
	 *
	 * @param mixed $value
	 * @param string $key "title", "description" or "thumbAlt"
	 * @return string
	 *
	 */
	protected function cleanText($value, $key, $multiLine = false) {
		if(!is_string($value)) return '';
		$value = $this->wire()->sanitizer->text($value, array(
			'maxLength' => self::textLength($key),
			'stripTags' => false,
			'multiLine' => $multiLine,
		));
		// control characters, which have no place in a text (line breaks and tabs where lines are allowed)
		$value = preg_replace($multiLine ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $value);
		return trim((string) $value);
	}

	/**
	 * Clean the description of a video in every language, as entered into the file input
	 *
	 * @param Pagevideo $video
	 * @return bool True if anything changed
	 *
	 */
	protected function cleanDescription(Pagevideo $video) {
		$languages = $this->wire()->languages;
		$multiLine = (int) $this->descriptionRows > 1;
		$changed = false;
		$list = $languages && !$this->noLang ? $languages : array(null);
		foreach($list as $language) {
			$value = (string) $video->description($language);
			if(!strlen($value)) continue;
			$clean = $this->cleanText($value, 'description', $multiLine);
			if($clean === $value) continue;
			$video->description($language, $clean);
			$changed = true;
		}
		return $changed;
	}

	/**
	 * Process a short text input in one or more languages
	 *
	 * @param WireInputData $input
	 * @param Pagevideo $video
	 * @param string $id
	 * @param string $key
	 * @return bool True if anything changed
	 *
	 */
	protected function processInputLangText(WireInputData $input, Pagevideo $video, $id, $key) {

		$languages = $this->wire()->languages;
		$useLanguages = $languages && $languages->count() > 1 && !$this->noLang;
		$changed = false;

		if(!$useLanguages) {
			$name = "{$key}_$id";
			if(!isset($input[$name])) return false;
			$value = $this->cleanText($input[$name], $key);
			$language = $languages ? $languages->getDefault() : null;
			if($value !== $video->langText($key, $language)) {
				$video->langText($key, $language ? $language : 'default', $value);
				$changed = true;
			}
			return $changed;
		}

		foreach($languages as $language) {
			/** @var Language $language */
			if(!$languages->editable($language)) continue;
			$name = $language->isDefault() ? "{$key}_$id" : "{$key}{$language->id}_$id";
			if(!isset($input[$name])) continue;
			$value = $this->cleanText($input[$name], $key);
			if($value === $video->langText($key, $language)) continue;
			$video->langText($key, $language, $value);
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Process input
	 *
	 * Besides what the file input does, this answers the requests the script sends in the
	 * background: parts of a file being uploaded, saving a thumbnail and replacing a video file.
	 *
	 * @param WireInputData $input
	 * @return self
	 *
	 */
	public function ___processInput(WireInputData $input) {

		$action = $this->getAjaxAction();

		if($action === 'chunk' || $action === 'cancel' || $action === 'resume') {
			// in the page editor these requests are answered earlier, without saving the page
			// (see FieldtypeSelfHostedVideo::hookAjaxSave). This is for everywhere else.
			echo json_encode($this->processUploadRequest($action));
			return $this;
		}

		if($action === 'find') {
			// the same goes for the search of the picker, which changes nothing
			echo json_encode($this->processFindRequest());
			return $this;
		}

		if($action === 'captiontext') {
			// reading captions to edit them changes nothing either
			echo json_encode($this->processCaptionTextRequest());
			return $this;
		}

		if($action === 'embedcheck') {
			// asking the web server changes nothing either
			echo json_encode($this->processEmbedCheckRequest());
			return $this;
		}

		if(!$action) {
			$token = $this->getUploadToken();
			if($token) return $this->processInputParts($input, $token);
			return parent::___processInput($input);
		}

		try {
			if($this->noUpload) throw new WireException($this->_('Changes are not allowed for this field.'));
			if(!$this->value instanceof Pagevideos) throw new WireException($this->_('No videos found.'));
			$video = $action === 'reuse' ? null : $this->getAjaxVideo();
			if($action === 'reuse') {
				$response = $this->processAjaxReuse();
			} else if($action === 'thumb') {
				$response = $this->processAjaxThumb($video);
			} else if($action === 'caption' || $action === 'uncaption') {
				$response = $this->processAjaxCaptions($video, $action === 'uncaption');
			} else if($action === 'report') {
				$response = $this->processAjaxReport($video);
			} else if($action === 'faststart') {
				$response = $this->processAjaxFaststart($video);
			} else {
				$response = $this->processAjaxReplace($video);
			}
			$response['error'] = false;
			$this->trackChange('value');
		} catch(\Exception $e) {
			$response = array('error' => true, 'message' => $e->getMessage());
		}

		echo json_encode($response);

		return $this;
	}

	/**
	 * Ask the web server whether it keeps other websites from embedding a video, for its checklist
	 *
	 * @return array Response with the checklist, the facts in the header and the sign
	 *
	 */
	public function processEmbedCheckRequest() {
		try {
			if(!$this->value instanceof Pagevideos) throw new WireException($this->_('No videos found.'));
			$video = $this->getAjaxVideo();
			$source = $video->isReference() ? $video->original() : $video;
			if(!$source) throw new WireException($this->_('No videos found.'));
			$this->wire()->modules->get('FieldtypeSelfHostedVideo')->embedRules()->serverBlocks($source);
			return array(
				'error' => false,
				'checklist' => $this->renderItemChecklist($video),
				'stats' => $this->renderItemStats($video),
				'badge' => $this->renderItemBadge($video),
			);
		} catch(\Exception $e) {
			return array('error' => true, 'message' => $e->getMessage());
		}
	}

	/**
	 * Answer a search of the picker for videos to reuse
	 *
	 * @return array Response
	 *
	 */
	public function processFindRequest() {

		try {
			if($this->noUpload || $this->noReuse) throw new WireException($this->_('Changes are not allowed for this field.'));
			if(!$this->value instanceof Pagevideos) throw new WireException($this->_('No videos found.'));

			$input = $this->wire()->input;
			$sanitizer = $this->wire()->sanitizer;
			$finder = $this->wire()->modules->get('FieldtypeSelfHostedVideo')->finder();
			$tags = $finder->tagsInUse();
			$tag = (string) $input->get('vf_tag');
			$limit = self::reusePageSize;

			$result = $finder->find(array(
				'q' => $sanitizer->text((string) $input->get('vf_q'), array('maxLength' => 200)),
				'tag' => isset($tags[$tag]) ? $tag : '',
				'start' => max(0, (int) $input->get('vf_start')),
				'limit' => $limit,
			));

			$items = array();
			foreach($result['items'] as $video) $items[] = $this->getReuseItem($video);

			return array(
				'error' => false,
				'items' => $items,
				'total' => $result['total'],
				'start' => $result['start'],
				'tags' => $tags,
			);

		} catch(\Exception $e) {
			return array('error' => true, 'message' => $e->getMessage());
		}
	}

	/**
	 * Describe a video for the list of the picker
	 *
	 * @param Pagevideo $video An original video
	 * @return array
	 *
	 */
	protected function getReuseItem(Pagevideo $video) {

		$finder = $this->wire()->modules->get('FieldtypeSelfHostedVideo')->finder();
		$page = $finder->editPage($video->page);
		$title = $video->title();

		$facts = array($video->basename());
		$duration = $video->durationStr();
		if(strlen($duration)) $facts[] = $duration;
		if($video->width() && $video->height()) $facts[] = $video->width() . '×' . $video->height();

		$thumb = '';
		$image = $video->thumb();
		if($image) {
			$thumb = $image->URL;
			try {
				$sized = $image->height(180);
				if($sized && is_file($sized->filename())) $thumb = $sized->URL;
			} catch(\Exception $e) {
				// the image at its stored size does the job too
			}
		}

		// its own videos and those it shows already cannot be added a second time
		$here = $this->value->page->id === $video->page->id && $this->value->field && $video->field && $this->value->field->name === $video->field->name;
		if(!$here) $here = $this->value->getFile(Pagevideos::referenceKey($video)) instanceof Pagevideo;

		return array(
			'page' => $video->page->id,
			'field' => $video->field->name,
			'name' => $video->basename(),
			'title' => strlen($title) ? $title : $video->basename(),
			'facts' => implode(' · ', $facts),
			'where' => ((string) $page->get('title|name')) . ' (' . $page->path() . ')',
			'tags' => implode(', ', $video->tagLabels()),
			'thumb' => $thumb,
			'here' => $here,
			'protected' => $video->isProtected(),
		);
	}

	/**
	 * Add a video of another page to this field as a reference, as asked for by the picker
	 *
	 * @return array Response
	 * @throws WireException
	 *
	 */
	protected function processAjaxReuse() {

		if($this->noReuse) throw new WireException($this->_('Changes are not allowed for this field.'));

		$input = $this->wire()->input;
		$pages = $this->wire()->pages;
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$finder = $fieldtype->finder();
		$gone = $this->_('This video is no longer there. Please search again.');

		$source = $pages->get((int) $input->get('vf_ref_page'));
		$fieldName = $this->wire()->sanitizer->fieldName((string) $input->get('vf_ref_field'));
		$name = basename((string) $input->get('vf_ref_name'));

		if(!$source->id || !strlen($fieldName) || !strlen($name) || !$finder->viewable($source)) throw new WireException($gone);

		$field = $this->wire()->fields->get($fieldName);
		$videos = $field && $field->type instanceof FieldtypeSelfHostedVideo ? $source->getUnformatted($fieldName) : null;
		$original = $videos instanceof Pagevideos ? $videos->getFile($name) : null;

		if(!$original instanceof Pagevideo || $original->isReference() || $original->isTemp()) throw new WireException($gone);

		$max = (int) $this->maxFiles;
		$replaced = false;

		if($max === 1 && count($this->value)) {
			// a field for one video: the new one takes the place of the one that is there
			$current = $this->value->first();
			if($current === $original) throw new WireException($this->_('This video is here already.'));
			if($this->getIrreplaceableVideo()) {
				throw new WireException(sprintf(
					$this->_('“%s” is used on other pages, so another video cannot take its place here.'),
					$current->basename()
				));
			}
			$this->value->delete($current);
			$replaced = true;
		} else if($max > 1 && count($this->value) >= $max) {
			throw new WireException($this->_('No more videos can be added to this field.'));
		}

		$before = count($this->value);
		$reference = $this->value->addReference($original);

		if($reference === $original || (!$replaced && count($this->value) === $before)) {
			throw new WireException($this->_('This video is here already.'));
		}

		$n = 0;
		foreach($this->value as $item) {
			if($item === $reference) break;
			$n++;
		}

		$this->currentItem = $reference;
		$title = $reference->title();

		return array(
			'message' => sprintf($this->_('“%s” was added. It is saved already.'), strlen($title) ? $title : $name),
			'markup' => $this->renderItemWrap($this->renderItem($reference, $this->pagefileId($reference), $n)),
			'replaced' => $replaced,
			'count' => count($this->value),
		);
	}

	/**
	 * Get the action requested by the script, if any
	 *
	 * @return string One of "thumb", "replace", "chunk", "cancel", "caption", "uncaption", "report", "find", "reuse", or blank string
	 *
	 */
	protected function getAjaxAction() {
		if(!$this->isAjax || !$this->wire()->config->ajax) return '';
		$input = $this->wire()->input;
		if(!$input->requestMethod('POST')) return '';
		$action = (string) $input->get('shv');
		return in_array($action, array('thumb', 'replace', 'chunk', 'cancel', 'resume', 'embedcheck', 'caption', 'uncaption', 'captiontext', 'report', 'faststart', 'find', 'reuse'), true) ? $action : '';
	}

	/**
	 * Get the video that the request from the script is about
	 *
	 * @return Pagevideo
	 * @throws WireException
	 *
	 */
	protected function getAjaxVideo() {
		$name = basename((string) $this->wire()->input->get('vf_name'));
		$video = strlen($name) ? $this->value->getFile($name) : null;
		if(!$video instanceof Pagevideo) throw new WireException($this->_('This video is no longer here. Please reload the page.'));
		if($video->isReference()) throw new WireException($this->_('A reused video is edited on the page it comes from.'));
		return $video;
	}

	/**
	 * Save a thumbnail sent by the script, along with what the browser found out about the video
	 *
	 * @param Pagevideo $video
	 * @return array Response
	 * @throws WireException
	 *
	 */
	protected function processAjaxThumb(Pagevideo $video) {

		$input = $this->wire()->input;
		$tmp = $this->receiveRequestBody(self::maxThumbBytes, 'thumb');

		try {
			if(!Pagevideo::thumbImageType($tmp)) {
				throw new WireException($this->_('The file is not a JPEG or PNG image.'));
			}
			$thumb = $video->setThumb($tmp, array(
				'time' => (float) $input->get('vf_time'),
				'custom' => (bool) (int) $input->get('vf_custom'),
				'move' => true,
			));
			if(!$thumb) throw new WireException($this->_('The image could not be stored.'));
		} finally {
			if(is_file($tmp)) $this->wire()->files->unlink($tmp);
		}

		// what the browser measured fills in whatever the file itself did not reveal
		$duration = (float) $input->get('vf_duration');
		$width = (int) $input->get('vf_width');
		$height = (int) $input->get('vf_height');
		if(!$video->duration() && $duration > 0 && $duration < 864000) $video->set('duration', $duration);
		if((!$video->width() || !$video->height()) && $width > 0 && $height > 0 && $width < 100000 && $height < 100000) {
			$video->set('width', $width);
			$video->set('height', $height);
		}

		// a picture taken from the video shows that this browser plays it
		if(!((int) $input->get('vf_custom')) && $video->filedata('unplayable') !== null) $video->filedata(false, 'unplayable');

		return array(
			'message' => $this->_('Thumbnail saved.'),
			'thumb' => $this->getAdminThumbUrl($video),
			'custom' => $video->thumbInfo()['custom'],
			'stats' => $this->renderItemStats($video),
			'badge' => $this->renderItemBadge($video),
			'checklist' => $this->renderItemChecklist($video),
			'portrait' => $video->isPortrait(),
			'width' => $video->width(),
			'height' => $video->height(),
		);
	}

	/**
	 * Save the captions file sent by the script for one language, or remove the captions of a language
	 *
	 * @param Pagevideo $video
	 * @param bool $remove
	 * @return array Response
	 * @throws WireException
	 *
	 */
	protected function processAjaxCaptions(Pagevideo $video, $remove = false) {

		$input = $this->wire()->input;
		$lang = SelfHostedVideoCaptions::cleanLang((string) $input->get('vf_lang'));

		if(!$lang) throw new WireException($this->_('Please choose the language of the captions.'));

		if($remove) {
			$tracks = $video->captions();
			$name = isset($tracks[$lang]) ? $tracks[$lang]->label : strtoupper($lang);
			$video->removeCaptions($lang);
			$message = sprintf($this->_('Captions removed: %s.'), $name);
		} else {
			$tmp = $this->receiveRequestBody(SelfHostedVideoCaptions::maxBytes, 'captions');
			$options = array('label' => (string) $input->get('vf_label'));
			$tracks = $video->captions();
			if((int) $input->get('vf_edit') && isset($tracks[$lang])) {
				// captions edited in the page editor keep their name and kind
				$options = array('label' => $tracks[$lang]->label, 'kind' => $tracks[$lang]->kind);
			}
			try {
				$track = $video->setCaptions($lang, $tmp, $options);
			} finally {
				if(is_file($tmp)) $this->wire()->files->unlink($tmp);
			}
			$count = sprintf($this->_n('%d caption', '%d captions', $track->cues), $track->cues);
			$message = sprintf($this->_('Captions saved: %1$s (%2$s).'), $track->label, $count);
			if($track->converted) $message .= ' ' . $this->_('The SubRip file was converted to WebVTT.');
			if($track->skipped) {
				$message .= ' ' . sprintf($this->_n(
					'%d caption has times that cannot be read and will not be shown.',
					'%d captions have times that cannot be read and will not be shown.',
					$track->skipped), $track->skipped);
			}
		}

		return array(
			'message' => $message,
			'captions' => $this->renderItemCaptionList($video, true),
			'tracks' => $this->getItemTracks($video),
			'checklist' => $this->renderItemChecklist($video),
			'stats' => $this->renderItemStats($video),
			'badge' => $this->renderItemBadge($video),
		);
	}

	/**
	 * Answer the script that wants the text of a caption file, to edit it in the page editor
	 *
	 * @return array Response: `text`, `lang`, `label`, `kind`, or `error` and `message`
	 *
	 */
	public function processCaptionTextRequest() {
		try {
			if($this->noUpload || $this->renderValueMode) throw new WireException($this->_('Changes are not allowed for this field.'));
			if(!$this->value instanceof Pagevideos) throw new WireException($this->_('No videos found.'));
			$video = $this->getAjaxVideo();
			$lang = SelfHostedVideoCaptions::cleanLang((string) $this->wire()->input->get('vf_lang'));
			$tracks = $video->captions();
			if(!$lang || !isset($tracks[$lang])) throw new WireException($this->_('These captions are no longer here. Please reload the page.'));
			$track = $tracks[$lang];
			$text = (string) @file_get_contents($track->filename, false, null, 0, SelfHostedVideoCaptions::maxBytes);
			return array('error' => false, 'text' => $text, 'lang' => $lang, 'label' => $track->label, 'kind' => $track->kind);
		} catch(\Exception $e) {
			return array('error' => true, 'message' => $e->getMessage());
		}
	}

	/**
	 * Take note of what the script found out about a video in the editor's browser
	 *
	 * For now that is one thing: the browser could not play the file.
	 *
	 * @param Pagevideo $video
	 * @return array Response
	 *
	 */
	protected function processAjaxReport(Pagevideo $video) {

		$input = $this->wire()->input;
		$sanitizer = $this->wire()->sanitizer;

		if((int) $input->get('vf_playable')) {
			if($video->filedata('unplayable') !== null) $video->filedata(false, 'unplayable');
		} else {
			$agent = trim($sanitizer->text((string) $input->get('vf_agent'), array('maxLength' => 40, 'stripTags' => true)));
			$video->filedata('unplayable', strlen($agent) ? $agent : $this->_('the browser'));
		}

		return array(
			'checklist' => $this->renderItemChecklist($video),
			'stats' => $this->renderItemStats($video),
			'badge' => $this->renderItemBadge($video),
		);
	}

	/**
	 * Prepare the file of a video for streaming, asked for from its checklist
	 *
	 * @param Pagevideo $video
	 * @return array Response
	 * @throws WireException
	 *
	 */
	protected function processAjaxFaststart(Pagevideo $video) {
		if($video->isReference()) throw new WireException($this->_('This video is edited on the page it comes from.'));
		$error = '';
		if(!$video->faststart($error) && strlen($error)) {
			throw new WireException(sprintf($this->_('The file could not be prepared: %s'), $error));
		}
		return array(
			'checklist' => $this->renderItemChecklist($video),
			'stats' => $this->renderItemStats($video),
			'badge' => $this->renderItemBadge($video),
		);
	}

	/**
	 * Replace the file of a video with the one sent by the script, keeping everything entered for it
	 *
	 * @param Pagevideo $video
	 * @return array Response
	 * @throws WireException
	 *
	 */
	protected function processAjaxReplace(Pagevideo $video) {

		$input = $this->wire()->input;
		$sanitizer = $this->wire()->sanitizer;
		$files = $this->wire()->files;

		$uploadName = basename(str_replace('\\', '/', rawurldecode((string) $input->get('vf_filename'))));
		$ext = strtolower((string) pathinfo($uploadName, PATHINFO_EXTENSION));

		if(!strlen($ext) || !in_array($ext, $this->getAllowedExtensions(true), true)) {
			throw new WireException(sprintf($this->_('This file type is not allowed here. Allowed: %s'), $this->getAllowedExtensions()));
		}

		if(strlen((string) $input->get('vf_id'))) {
			// the file was sent in parts before this request
			$part = $this->getUploadPartFile($this->getUploadId());
			clearstatcache(true, $part);
			if(!is_file($part) || filesize($part) !== (int) $input->get('vf_size')) {
				if(is_file($part)) $files->unlink($part);
				throw new WireException($this->_('The upload did not arrive completely. Please upload the file again.'));
			}
			$tmp = "$part.$ext";
			if(!@rename($part, $tmp)) throw new WireException($this->_('The upload could not be received.'));
		} else {
			$tmp = $this->receiveRequestBody((int) $this->maxFilesize, 'replace', $ext);
		}

		try {
			if($sanitizer->validateFile($tmp, array('pagefile' => $video)) === false) {
				throw new WireException($this->_('File failed validation'));
			}
			if(!$video->replaceFile($tmp, true)) {
				throw new WireException($this->_('The file could not be put in place.'));
			}
		} finally {
			if(is_file($tmp)) $files->unlink($tmp);
		}

		if($ext !== strtolower($video->ext())) {
			// a different format: the file name follows, and so do the pages that reuse the video
			$newName = $video->pagefiles->cleanBasename($video->basename(false) . ".$ext", true, false, true);
			if($video->rename($newName) === false) {
				throw new WireException($this->_('The file was replaced, but could not be given its new file extension.'));
			}
		}

		if(strlen($uploadName) && $uploadName !== $video->basename()) {
			$video->filedata('uploadName', $sanitizer->text($uploadName));
		}

		$video->modified = time();
		$video->modifiedUser = $this->wire()->user;

		// find the position of the video, which the rendered item needs for sorting
		$n = 0;
		foreach($this->value as $item) {
			if($item === $video) break;
			$n++;
		}

		$this->currentItem = $video;

		return array(
			'message' => $this->_('Video file replaced.'),
			'markup' => $this->fileAddedGetMarkup($video, $n),
			'name' => $video->basename(),
		);
	}

	/**
	 * Write the body of the current request to a temporary file
	 *
	 * @param int $maxBytes Largest size accepted, or 0 for no limit
	 * @param string $prefix Prefix for the temporary file name
	 * @param string $ext Optional extension for the temporary file
	 * @return string Full path to the temporary file
	 * @throws WireException
	 *
	 */
	protected function receiveRequestBody($maxBytes, $prefix, $ext = '') {

		$path = $this->value->path();
		if(!is_dir($path) || !is_writable($path)) throw new WireException($this->_('The files directory of this page is not writable.'));

		// a dot-file inside the page’s own files directory: same disk, so moving it into place is instant
		$tmp = $path . '.shv-' . $prefix . '-' . bin2hex(random_bytes(8)) . (strlen($ext) ? ".$ext" : '.tmp');
		$in = fopen('php://input', 'rb');
		$out = $in ? fopen($tmp, 'wb') : false;

		if(!$in || !$out) {
			if($in) fclose($in);
			throw new WireException($this->_('The upload could not be received.'));
		}

		$bytes = 0;
		$tooBig = false;

		while(!feof($in)) {
			$chunk = fread($in, 1048576);
			if($chunk === false || $chunk === '') break;
			$bytes += strlen($chunk);
			if($maxBytes > 0 && $bytes > $maxBytes) {
				$tooBig = true;
				break;
			}
			fwrite($out, $chunk);
		}

		fclose($in);
		fclose($out);

		if($tooBig || !$bytes) {
			$this->wire()->files->unlink($tmp);
			$message = $this->_('No file was received.');
			if($tooBig) $message = $this->_('The file is too large.');
			throw new WireException($message);
		}

		return $tmp;
	}

	/*** UPLOAD IN PARTS ************************************************************************/

	/**
	 * Get the largest video file accepted, in bytes
	 *
	 * @return int 0 for no limit
	 *
	 */
	public function getMaxVideoBytes() {
		$mb = (float) $this->maxVideoMB;
		return $mb > 0 ? (int) round($mb * 1048576) : 0;
	}

	/**
	 * Get the largest part of a file the script may send in one request, in bytes
	 *
	 * @return int
	 *
	 */
	public function getChunkLimit() {
		$limit = self::chunkBytes;
		$post = trim((string) ini_get('post_max_size'));
		// "0" means the server sets no limit
		$postBytes = $post === '' || $post === '0' ? 0 : (int) $this->strToBytes($post);
		if($postBytes > 0) $limit = min($limit, (int) floor($postBytes * 0.9));
		return max(16384, $limit);
	}

	/**
	 * Get the directory where the parts of unfinished uploads are kept
	 *
	 * It lies in the cache directory, which is not reachable from the web.
	 *
	 * @return string
	 *
	 */
	public function getUploadDir() {
		return $this->wire()->config->paths->cache . 'FieldtypeSelfHostedVideo/';
	}

	/**
	 * Get the identifier of the upload that the current request belongs to
	 *
	 * @return string
	 * @throws WireException
	 *
	 */
	protected function getUploadId() {
		$id = (string) $this->wire()->input->get('vf_id');
		if(!preg_match('/^[a-z0-9]{16,40}$/', $id)) throw new WireException($this->_('The upload could not be received.'));
		return $id;
	}

	/**
	 * Get the file in which the parts of an upload are put together
	 *
	 * Its name ties it to the user, page and field, so nobody else can add to it or use it.
	 *
	 * @param string $id Identifier of the upload
	 * @return string Full path
	 * @throws WireException
	 *
	 */
	protected function getUploadPartFile($id) {

		$dir = $this->getUploadDir();
		if(!is_dir($dir)) $this->wire()->files->mkdir($dir, true);
		if(!is_dir($dir) || !is_writable($dir)) {
			throw new WireException($this->_('The directory for uploads on the server is not writable.'));
		}

		$page = $this->value instanceof Pagefiles ? $this->value->getPage() : null;
		$field = $this->value instanceof Pagefiles ? $this->value->getField() : null;

		return $dir . 'upload-' .
			(int) $this->wire()->user->id . '-' .
			($page ? (int) $page->id : 0) . '-' .
			($field ? (int) $field->id : 0) . '-' .
			$id . '.part';
	}

	/**
	 * Remove the parts of uploads that were never finished
	 *
	 * @param int $maxAge Age in seconds from which parts are removed
	 * @return int Number of files removed
	 *
	 */
	public function cleanUploadParts($maxAge = self::uploadLifetime) {
		$dir = $this->getUploadDir();
		if(!is_dir($dir)) return 0;
		$n = 0;
		$limit = time() - (int) $maxAge;
		foreach(new \DirectoryIterator($dir) as $file) {
			if(!$file->isFile() || strpos($file->getFilename(), 'upload-') !== 0) continue;
			if($file->getMTime() > $limit) continue;
			if($this->wire()->files->unlink($file->getPathname())) $n++;
		}
		return $n;
	}

	/**
	 * Answer a request of the script that belongs to an upload in parts
	 *
	 * @param string $action Either "chunk" (a part of the file is in the request body) or "cancel"
	 * @return array Response for the script
	 *
	 */
	public function processUploadRequest($action) {
		try {
			if($this->noUpload) throw new WireException($this->_('Changes are not allowed for this field.'));
			if(!$this->value instanceof Pagevideos) throw new WireException($this->_('No videos found.'));
			if($action === 'cancel') {
				$part = $this->getUploadPartFile($this->getUploadId());
				if(is_file($part)) $this->wire()->files->unlink($part);
				return array('error' => false);
			}
			if($action === 'resume') return $this->resumeUploadPart();
			return $this->receiveUploadPart();
		} catch(\Exception $e) {
			return array('error' => true, 'message' => $e->getMessage());
		}
	}

	/**
	 * Tell from where an interrupted upload can go on
	 *
	 * The script names the point it knows to have arrived (`vf_at`) and sends two pieces of the
	 * file it wants to continue with: its start (`vf_head` bytes, up to 64 kB) and the 64 kB before
	 * that point. Only when both are the same as what has arrived does the upload go on from there;
	 * otherwise what has arrived belongs to another file and is dropped. What arrived is kept for a
	 * day after its last part (see cleanUploadParts()).
	 *
	 * @return array Response with `error` and `received` (where to go on, 0 to start over)
	 *
	 */
	protected function resumeUploadPart() {

		$input = $this->wire()->input;
		$part = $this->getUploadPartFile($this->getUploadId());
		$size = (int) $input->get('vf_size');
		$at = (int) $input->get('vf_at');
		$headLength = (int) $input->get('vf_head');
		$max = self::resumeCheckBytes;

		clearstatcache(true, $part);
		if(!is_file($part)) return array('error' => false, 'received' => 0);

		$have = (int) filesize($part);
		$body = (string) @file_get_contents('php://input', false, null, 0, 2 * $max);
		$head = substr($body, 0, $headLength);
		$tail = (string) substr($body, $headLength);
		$tailStart = $at - strlen($tail);

		$same = $size > 0 && $at > 0 && $at < $size && $at <= $have &&
			$headLength === min($size, $max) && strlen($head) === $headLength &&
			strlen($tail) === min($at, $max) && $tailStart >= 0 &&
			(string) @file_get_contents($part, false, null, 0, min($headLength, $have)) === substr($head, 0, min($headLength, $have)) &&
			(string) @file_get_contents($part, false, null, $tailStart, strlen($tail)) === $tail;

		if(!$same) {
			$this->wire()->files->unlink($part);
			return array('error' => false, 'received' => 0);
		}

		// kept for another day from now; anything after the point checked is sent again
		@touch($part);
		return array('error' => false, 'received' => $at);
	}

	/**
	 * Receive one part of a file and add it to what has arrived so far
	 *
	 * The script names the position (offset) each part belongs to. That makes sending a part
	 * again harmless, and lets an interrupted upload continue where it stopped:
	 *
	 * - Bytes beyond the offset are dropped, as they come from a request that did not finish.
	 * - If less than the offset has arrived, the script is told how much, and continues there.
	 *
	 * @return array Response with keys `error`, `received` (bytes on the server now) and `done`,
	 *   or with `error` and `code` when the script should adjust and send again:
	 *   "offset" (continue from `received`), "empty" (send smaller parts), "short" and "busy" (send again)
	 * @throws WireException When the upload cannot go on
	 *
	 */
	protected function receiveUploadPart() {

		$input = $this->wire()->input;
		$id = $this->getUploadId();
		$offset = (int) $input->get('vf_offset');
		$size = (int) $input->get('vf_size');
		$forReplace = $input->get('vf_for') === 'replace';

		$name = basename(str_replace('\\', '/', rawurldecode((string) $input->get('vf_filename'))));
		$ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

		if(!strlen($ext) || !in_array($ext, $this->getAllowedExtensions(true), true)) {
			throw new WireException(sprintf($this->_('This file type is not allowed here. Allowed: %s'), $this->getAllowedExtensions()));
		}
		if($size < 1 || $offset < 0 || $offset >= $size) {
			throw new WireException($this->_('The upload could not be received.'));
		}

		$max = $this->getMaxVideoBytes();
		if($max && $size > $max) {
			throw new WireException(sprintf($this->_('This file is larger than the %s allowed for this field.'), wireBytesStr($max)));
		}

		$part = $this->getUploadPartFile($id);

		if($offset === 0) {
			// before the first part: everything that would make the upload pointless
			$this->cleanUploadParts();
			if($forReplace) {
				$this->getAjaxVideo();
			} else {
				$total = count($this->value);
				if($this->maxFiles > 1 && $total >= $this->maxFiles) {
					throw new WireException($this->_('No more videos can be added to this field.'));
				}
				$current = $this->getIrreplaceableVideo();
				if($current) {
					throw new WireException(sprintf(
						$this->_('“%s” is used on other pages, so another video cannot take its place here. To give it a new file, use “Replace video file”.'),
						$current->basename()
					));
				}
			}
			$free = @disk_free_space($this->getUploadDir());
			if($free !== false && $size > $free - 67108864) {
				throw new WireException($this->_('There is not enough space on the server for this file.'));
			}
		}

		$fp = @fopen($part, 'c+b');
		if(!$fp) throw new WireException($this->_('The upload could not be received.'));

		$bytes = 0;
		$short = false;

		if(!flock($fp, LOCK_EX | LOCK_NB)) {
			// an earlier request for this upload is still being written
			fclose($fp);
			return array('error' => true, 'code' => 'busy', 'received' => $offset, 'message' => $this->_('The server is still busy with this upload.'));
		}

		try {
			$stat = fstat($fp);
			$have = $stat ? (int) $stat['size'] : 0;

			if($have < $offset) {
				return array('error' => true, 'code' => 'offset', 'received' => $have, 'message' => $this->_('A part of the upload is missing on the server.'));
			}

			if($have > $offset) ftruncate($fp, $offset);
			fseek($fp, $offset);

			$in = fopen('php://input', 'rb');
			if(!$in) throw new WireException($this->_('The upload could not be received.'));

			$problem = '';

			while(!feof($in)) {
				$data = fread($in, 1048576);
				if($data === false || $data === '') break;
				$length = strlen($data);
				if($offset + $bytes + $length > $size) {
					$problem = $this->_('More data arrived than the file has.');
					break;
				}
				if(fwrite($fp, $data) !== $length) {
					$problem = $this->_('The server could not store the upload. Its disk may be full.');
					break;
				}
				$bytes += $length;
			}

			fclose($in);

			if($problem) {
				ftruncate($fp, $offset);
				throw new WireException($problem);
			}

			$length = (int) $input->get('vf_length');
			if($bytes && $length > 0 && $bytes !== $length) {
				// the part was cut short on its way: drop it, the script sends it again
				ftruncate($fp, $offset);
				$short = true;
			}

			fflush($fp);

		} finally {
			flock($fp, LOCK_UN);
			fclose($fp);
		}

		if(!$bytes) {
			// typically a part larger than the server accepts in one request
			return array('error' => true, 'code' => 'empty', 'received' => $offset, 'message' => $this->_('The server received no data.'));
		}

		if($short) {
			return array('error' => true, 'code' => 'short', 'received' => $offset, 'message' => $this->_('A part of the upload arrived incomplete.'));
		}

		$received = $offset + $bytes;

		return array('error' => false, 'received' => $received, 'done' => $received >= $size);
	}

	/**
	 * Does the current request ask to add a file that was sent in parts before?
	 *
	 * The script finishes an upload by handing the core upload script a tiny stand-in file
	 * that names the upload. From there on the core does what it does with every upload.
	 *
	 * @return array|null Array with `id` and `size` of the upload, or null if this is no such request
	 *
	 */
	protected function getUploadToken() {
		if(!$this->isAjax || empty($_SERVER['HTTP_X_FILENAME'])) return null;
		$length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if($length < 1 || $length > 128) return null;
		$body = (string) @file_get_contents('php://input', false, null, 0, 128);
		if(!preg_match('/^shv-upload:([a-z0-9]{16,40}):(\d{1,18})$/', $body, $matches)) return null;
		return array('id' => $matches[1], 'size' => (int) $matches[2]);
	}

	/**
	 * Process input for a file that was sent in parts: hand it to the core upload
	 *
	 * @param WireInputData $input
	 * @param array $token See getUploadToken()
	 * @return self
	 *
	 */
	protected function processInputParts(WireInputData $input, array $token) {

		$files = $this->wire()->files;
		$part = '';
		$error = '';

		try {
			if(is_null($this->value)) throw new WireException($this->_('No videos found.'));
			$part = $this->getUploadPartFile($token['id']);
			clearstatcache(true, $part);
			if(!is_file($part) || filesize($part) !== $token['size']) {
				$error = $this->_('The upload did not arrive completely. Please upload the file again.');
			}
		} catch(\Exception $e) {
			$error = $e->getMessage();
		}

		if($error) {
			if($part && is_file($part)) $files->unlink($part);
			$this->ajaxResponse(true, $error);
			echo $this->renderAjaxResponse();
			return $this;
		}

		/** @var SelfHostedVideoUpload $upload */
		$upload = $this->wire(new SelfHostedVideoUpload($this->attr('name')));
		$upload->setLocalFile($part);
		$this->wireUpload = $upload;

		try {
			parent::___processInput($input);
		} finally {
			// still there if the file was refused
			if(is_file($part)) $files->unlink($part);
		}

		return $this;
	}

	/**
	 * Uninstall: remove what is left of unfinished uploads
	 *
	 */
	public function ___uninstall() {
		$dir = $this->getUploadDir();
		if(is_dir($dir)) $this->wire()->files->rmdir($dir, true);
	}

	/*** CONFIGURATION **************************************************************************/

	/**
	 * Configuration shown on the Input tab of the field settings
	 *
	 * @return InputfieldWrapper
	 *
	 */
	public function ___getConfigInputfields() {

		$inputfields = parent::___getConfigInputfields();

		/** @var InputfieldFloat $f */
		$f = $this->wire()->modules->get('InputfieldFloat');
		$f->attr('name', 'thumbTime');
		$f->attr('value', (float) $this->thumbTime);
		$f->attr('min', 0);
		$f->set('precision', 1);
		$f->label = $this->_('Thumbnail position');
		$f->description = $this->_('How many seconds into a video the automatic thumbnail is taken. The very first picture of a video is often black, so a little later usually looks better.');
		$f->notes = $this->_('For videos shorter than twice this value, the picture in the middle of the video is used.');
		$f->icon = 'camera';
		$f->columnWidth = 50;
		$inputfields->prepend($f);

		/** @var InputfieldFloat $f */
		$f = $this->wire()->modules->get('InputfieldFloat');
		$f->attr('name', 'maxVideoMB');
		$f->attr('value', (float) $this->maxVideoMB > 0 ? (float) $this->maxVideoMB : '');
		$f->attr('min', 0);
		$f->set('precision', 1);
		$f->label = $this->_('Largest video file');
		$f->description = $this->_('Size in megabytes above which a video is refused. Leave empty for no limit.');
		$f->notes = $this->_('Videos are sent to the server in parts, so the upload limits of the server do not apply to them.');
		$f->icon = 'upload';
		$f->columnWidth = 50;
		$inputfields->insertAfter($f, $inputfields->getChildByName('thumbTime'));
		$last = $f;

		/** @var InputfieldFloat $f */
		$f = $this->wire()->modules->get('InputfieldFloat');
		$f->attr('name', 'warnBitrate');
		$f->attr('value', (float) $this->warnBitrate);
		$f->attr('min', 0);
		$f->set('precision', 1);
		$f->label = $this->_('Point out videos above this data rate');
		$f->description = $this->_('Megabits per second. Videos above it load slowly on phones and slower connections, and the checklist of the video says so.');
		$f->notes = $this->_('8 suits Full HD video. Enter 0 to switch this check off.');
		$f->icon = 'tachometer';
		$f->columnWidth = 50;
		$inputfields->insertAfter($f, $last);
		$last = $f;

		/** @var InputfieldFloat $f */
		$f = $this->wire()->modules->get('InputfieldFloat');
		$f->attr('name', 'warnSizeMB');
		$f->attr('value', (float) $this->warnSizeMB > 0 ? (float) $this->warnSizeMB : '');
		$f->attr('min', 0);
		$f->set('precision', 1);
		$f->label = $this->_('Point out videos above this file size');
		$f->description = $this->_('Megabytes. Larger videos are still accepted, and the checklist of the video says so. Leave empty to switch this check off.');
		$f->icon = 'hdd-o';
		$f->columnWidth = 50;
		$inputfields->insertAfter($f, $last);
		$last = $f;

		/** @var InputfieldCheckbox $f */
		$f = $this->wire()->modules->get('InputfieldCheckbox');
		$f->attr('name', 'noReuse');
		$f->attr('value', 1);
		if($this->noReuse) $f->attr('checked', 'checked');
		$f->label = $this->_('Uploads only');
		$f->label2 = $this->_('Do not offer to reuse videos of other pages in this field');
		$f->description = $this->_('By default editors can show a video of another page here without uploading it again. They can choose from the videos of all pages they may view.');
		$f->icon = 'link';
		$inputfields->insertAfter($f, $last);
		$last = $f;

		/** @var InputfieldCheckbox $f */
		$f = $this->wire()->modules->get('InputfieldCheckbox');
		$f->attr('name', 'noEditorTab');
		$f->attr('value', 1);
		if($this->noEditorTab) $f->attr('checked', 'checked');
		$f->label = $this->_('Never in a tab');
		$f->label2 = $this->_('Keep this field among the other fields of the page');
		$f->description = $this->_('The settings of the module (Modules > Video Files) can show video fields in tabs of their own, or all of them in one tab “Videos”. Checked, this field is left out of that and stays where the template puts it.');
		$f->icon = 'folder-o';
		$inputfields->insertAfter($f, $last);

		return $inputfields;
	}

	/**
	 * Settings of the field that can differ per template
	 *
	 * @param Field $field
	 * @return array
	 *
	 */
	public function ___getConfigAllowContext($field) {
		$names = parent::___getConfigAllowContext($field);
		$names[] = 'noEditorTab';
		return $names;
	}
}
