<?php namespace ProcessWire;

/**
 * SelfHostedVideoChecks
 *
 * Looks at a video and says what an editor should know about it: what is missing for
 * people who cannot see or hear it, and what about the file itself will trouble visitors
 * (a format not every browser plays, a file that has to load completely before it starts,
 * a data rate too high for phones).
 *
 * It is used through `$video->checks()`. Nothing here blocks anything: these are hints.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoChecks extends Wire {

	/**
	 * Data rate in Mbit/s above which a video counts as heavy, unless the field says otherwise
	 *
	 */
	const defaultMaxBitrate = 8;

	/**
	 * Seconds that captions may run beyond the end of a video before that is pointed out
	 *
	 */
	const captionsOverrun = 5;

	/**
	 * Get the checks for a video
	 *
	 * @param Pagevideo $video
	 * @return array of WireData, each with:
	 *  - `name` (string): Identifier of the check, i.e. "title", "captions", "format", "faststart", "bitrate".
	 *  - `group` (string): "accessibility", "file" or "search".
	 *  - `status` (string): "ok", "todo" (missing, the editor can add it), "warning" (worth knowing about the file) or "info".
	 *  - `label` (string): What was found, in a few words.
	 *  - `note` (string): What to do about it, blank when there is nothing to do.
	 *
	 */
	public function run(Pagevideo $video) {

		$checks = array();
		$source = $video->isReference() ? $video->original() : $video;
		if(!$source) return $checks;

		$info = $source->mediaInfo();
		$duration = (float) $source->duration();

		// ACCESSIBILITY

		$title = trim(strip_tags((string) $source->title()));

		if(strlen($title)) {
			$checks[] = $this->check('title', 'accessibility', 'ok', $this->_('Title'));
		} else {
			$checks[] = $this->check('title', 'accessibility', 'todo', $this->_('Title missing'),
				$this->_('The title tells people using a screen reader what the video is.'));
		}

		$tracks = $source->captions();

		if(count($tracks)) {
			$names = array();
			foreach($tracks as $track) $names[] = $track->label;
			$checks[] = $this->check('captions', 'accessibility', 'ok', sprintf($this->_('Captions: %s'), implode(', ', $names)));
		} else if($info['hasAudio'] === false) {
			$checks[] = $this->check('captions', 'accessibility', 'ok', $this->_('No sound, so no captions needed'));
		} else if($source->noSpeech()) {
			$checks[] = $this->check('captions', 'accessibility', 'ok', $this->_('Marked as without speech, so no captions needed'));
		} else {
			$checks[] = $this->check('captions', 'accessibility', 'todo', $this->_('Captions missing'),
				$this->_('People who cannot hear the video need captions. Add a captions file, or mark the video as having no speech.'));
		}

		if($duration > 0) {
			foreach($tracks as $track) {
				if((float) $track->end <= $duration + self::captionsOverrun) continue;
				$checks[] = $this->check('captionsLength', 'accessibility', 'warning',
					sprintf($this->_('Captions (%s) run longer than the video'), $track->label),
					$this->_('Do they belong to this file? Captions that do not match the picture confuse more than they help.'));
			}
		}

		// FILE

		$problems = 0;

		if($info['hasVideo'] === false) {
			$problems++;
			$checks[] = $this->check('picture', 'file', 'warning', $this->_('No picture found in the file'),
				$this->_('This looks like a sound file. Players show an empty area for it.'));
		}

		$format = $this->describeFormat($info);
		$unplayable = (string) $source->filedata('unplayable');

		if($format['problem']) {
			$problems++;
			$checks[] = $this->check('format', 'file', 'warning', sprintf($this->_('Format: %s'), $format['name']), $format['problem']);
		} else if(strlen($unplayable)) {
			$problems++;
			$name = strlen($format['name']) ? ' (' . $format['name'] . ')' : '';
			$note = $this->_('The browser this video was last opened with in the editor could not play it. Visitors with that browser see a player that does nothing.');
			// no advice to use the format the file already has
			if(strpos($format['name'], 'H.264') !== 0) $note .= ' ' . $this->_('H.264 in an MP4 file plays everywhere.');
			$checks[] = $this->check('format', 'file', 'warning', sprintf($this->_('Did not play in %s'), $unplayable) . $name, $note);
		}

		if($info['faststart'] === false) {
			$problems++;
			$checks[] = $this->check('faststart', 'file', 'warning', $this->_('Not prepared for streaming'),
				$this->wire()->modules->isInstalled('ProcessSelfHostedVideos')
					? $this->_('The file has to load completely before it starts to play. “Prepare for streaming” in the page editor or in the Video Manager fixes this without changing picture or sound.')
					: $this->_('The file has to load completely before it starts to play. “Prepare for streaming” in the page editor fixes this without changing picture or sound.'));
		}

		$field = $source->field;
		$maxBitrate = $field ? $field->get('warnBitrate') : null;
		$maxBitrate = $maxBitrate === null || $maxBitrate === '' ? (float) self::defaultMaxBitrate : (float) $maxBitrate;
		$maxSize = $field ? (float) $field->get('warnSizeMB') : 0.0;
		$size = $info['filesize'];

		if($maxBitrate > 0 && $info['bitrate'] > $maxBitrate * 1000000) {
			$problems++;
			$check = $this->check('bitrate', 'file', 'warning',
				sprintf($this->_('High data rate: %s Mbit/s'), $this->number($info['bitrate'] / 1000000)),
				sprintf($this->_('Above %s Mbit/s a video starts slowly and stalls on phones and slower connections.'), $this->number($maxBitrate)) . ' ' .
				$this->smallerAdvice());
			$this->addTools($check, $source, $video);
			$checks[] = $check;
		}

		if($maxSize > 0 && $size > $maxSize * 1048576) {
			$problems++;
			$check = $this->check('size', 'file', 'warning',
				sprintf($this->_('Large file: %s'), wireBytesStr($size)),
				sprintf($this->_('This field asks for videos of up to %s MB.'), $this->number($maxSize)) . ' ' .
				$this->smallerAdvice());
			$this->addTools($check, $source, $video);
			$checks[] = $check;
		}

		if($info['known'] && (!$duration || !$source->width() || !$source->height()) && $info['hasVideo'] !== false) {
			$problems++;
			$checks[] = $this->check('metadata', 'file', 'warning', $this->_('Duration or picture size unknown'),
				$this->_('The file does not say how long or how large the video is. Players cannot keep its place free while the page loads.'));
		}

		if(!$problems) {
			$label = $this->_('File: nothing found');
			if(strlen($format['name'])) $label = sprintf($this->_('File: %s, nothing found'), $format['name']);
			$checks[] = $this->check('file', 'file', 'ok', $label);
		}

		// SEARCH ENGINES

		foreach($this->checkSearch($source, $info) as $check) $checks[] = $check;

		$access = $this->checkAccess($source);
		if($access) $checks[] = $access;

		$embed = $this->checkEmbed($source);
		if($embed) $checks[] = $embed;

		return $checks;
	}

	/**
	 * Name the format of a video file and say what is wrong with it, if anything
	 *
	 * @param array $info See Pagevideo::mediaInfo()
	 * @return array Array with `name` (i.e. "H.264 in MP4", blank if unknown) and `problem` (blank if none)
	 *
	 */
	public function describeFormat(array $info) {

		$codec = strtolower((string) $info['codec']);
		$container = (string) $info['format'];
		$ext = strtolower((string) $info['ext']);
		$name = '';
		$problem = '';

		$containers = array('mp4' => 'MP4', 'webm' => 'WebM', 'matroska' => 'Matroska');
		$containerName = isset($containers[$container]) ? $containers[$container] : '';
		if($container === 'mp4' && $ext === 'mov') $containerName = 'QuickTime';

		if(strpos($codec, 'avc') === 0) {
			$name = 'H.264';
		} else if(strpos($codec, 'hvc') === 0 || strpos($codec, 'hev') === 0) {
			$name = 'H.265 (HEVC)';
			$problem = $this->_('H.265 plays in Safari and on newer devices, but not in every browser. Export the video as H.264 so that everyone can watch it.');
		} else if(strpos($codec, 'av01') === 0 || strpos($codec, 'v_av1') === 0) {
			$name = 'AV1';
		} else if(strpos($codec, 'vp09') === 0 || strpos($codec, 'v_vp9') === 0) {
			$name = 'VP9';
		} else if(strpos($codec, 'vp08') === 0 || strpos($codec, 'v_vp8') === 0) {
			$name = 'VP8';
		} else if(strpos($codec, 'ap') === 0 && strlen($codec) === 4) {
			$name = 'ProRes';
			$problem = $this->_('ProRes is a format for editing, not for the web: browsers do not play it and the files are very large. Export the video as H.264.');
		} else if($codec === 'mp4v') {
			$name = 'MPEG-4 Visual';
			$problem = $this->_('This older format does not play in most browsers. Export the video as H.264.');
		} else if(strlen($codec)) {
			$name = strtoupper(preg_replace('/^v_/', '', $codec));
			if($container === 'mp4' || $container === 'webm') {
				$problem = $this->_('Browsers may not play this format. H.264 in an MP4 file plays everywhere.');
			}
		}

		if(!$problem && ($containerName === 'QuickTime' || $containerName === 'Matroska')) {
			$problem = sprintf($this->_('%s files do not play in every browser. Export the video as an MP4 file.'), $containerName);
		}

		if(strlen($name) && strlen($containerName)) {
			$name = sprintf($this->_('%1$s in %2$s'), $name, $containerName);
		} else if(!strlen($name)) {
			$name = $containerName;
		}

		return array('name' => $name, 'problem' => $problem);
	}

	/**
	 * Titles of all videos of the site, by language, to find videos that share one; see titleTwin()
	 *
	 * Built once per request, and forgotten when a video field is saved.
	 *
	 * @var array|null
	 *
	 */
	protected static $titles = null;

	/**
	 * Forget the titles of all videos, after a video field was saved
	 *
	 */
	public static function forgetTitles() {
		self::$titles = null;
	}

	/**
	 * What search engines need to show a video as a video: a thumbnail, a description, a title of its own
	 *
	 * Left out for videos that search engines cannot see anyway: protected ones, those of pages
	 * that are not public, and sound files.
	 *
	 * @param Pagevideo $video The original video
	 * @param array $info See Pagevideo::mediaInfo()
	 * @return array of WireData
	 *
	 */
	protected function checkSearch(Pagevideo $video, array $info) {

		$checks = array();
		$page = $video->page;
		if(!$page || !$page->id || $page->isTrash()) return $checks;
		if($video->isProtected()) return $checks;
		if(!$page->isUnpublished() && !$page->isPublic()) return $checks;
		if($info['hasVideo'] === false) return $checks;

		$thumb = $video->thumb();
		if(!$thumb) {
			$checks[] = $this->check('searchThumb', 'search', 'todo', $this->_('Search engines: thumbnail missing'),
				$this->_('Without a thumbnail, search engines do not show the video as a video. Pick one in “Video and thumbnail”.'));
		} else if($thumb->width() && $thumb->height() && ($thumb->width() < 60 || $thumb->height() < 30)) {
			$checks[] = $this->check('searchThumb', 'search', 'todo', sprintf($this->_('Search engines: thumbnail too small (%1$d × %2$d pixels)'), $thumb->width(), $thumb->height()),
				$this->_('Search engines need a thumbnail of at least 60 × 30 pixels, and prefer a large one. Pick another in “Video and thumbnail”.'));
		}

		$description = trim(strip_tags((string) $video->description()));
		if(!strlen($description)) {
			$checks[] = $this->check('searchDescription', 'search', 'info', $this->_('Search engines: no description'),
				$this->_('A sentence or two on what the video shows is what search engines show with it. Without one they show the title only.'));
		}

		$twin = $this->titleTwin($video);
		if($twin) {
			$checks[] = $this->check('searchTitle', 'search', 'warning', $this->_('Search engines: the same title as another video'),
				sprintf($this->_('The video “%1$s” on the page “%2$s” has this title too. Search engines want a title of its own for each video, to tell them apart.'), $twin['name'], $twin['page']));
		}

		return $checks;
	}

	/**
	 * Find another video of the site with the same title as this one, in any language
	 *
	 * Reuses of a video are the same video, and are not counted. Neither are videos in the trash.
	 *
	 * @param Pagevideo $video The original video
	 * @return array|null Array with `name` (file name) and `page` (title of its page), or null if there is none
	 *
	 */
	public function titleTwin(Pagevideo $video) {

		$page = $video->page;
		$field = $video->field;
		if(!$page || !$field) return null;

		$own = $this->titleKeys($video->filedata('title'));
		if(!count($own)) {
			// a title set but not yet stored in the file's data
			$title = trim((string) $video->title());
			if(strlen($title)) $own = array('0|' . $this->titleKey($title) => true);
		}
		if(!count($own)) return null;

		$titles = $this->allTitles();
		$self = "$field->id:$page->id:" . $video->basename();

		foreach(array_keys($own) as $key) {
			if(!isset($titles[$key])) continue;
			foreach($titles[$key] as $other) {
				if($other === $self) continue;
				list($fieldId, $pageId, $name) = explode(':', $other, 3);
				$otherPage = $this->wire()->pages->get((int) $pageId);
				$pageTitle = $otherPage->id ? (string) $otherPage->getUnformatted('title|name') : (string) $pageId;
				return array('name' => $name, 'page' => $pageTitle);
			}
		}

		return null;
	}

	/**
	 * Get the titles of all original videos of the site that are not in the trash
	 *
	 * @return array Keys "language|title" pointing to lists of "field:page:file"
	 *
	 */
	protected function allTitles() {

		if(self::$titles !== null) return self::$titles;
		self::$titles = array();

		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		if(!$fieldtype instanceof FieldtypeSelfHostedVideo) return self::$titles;
		$database = $this->wire()->database;
		$trash = Page::statusTrash;

		foreach($fieldtype->getVideoFields() as $field) {
			$table = $database->escapeTable($field->getTable());
			try {
				$hasRef = $database->columnExists($table, 'ref_pages_id');
				$sql =
					"SELECT t.pages_id, t.data, t.filedata FROM `$table` AS t " .
					"JOIN pages AS p ON p.id=t.pages_id " .
					"WHERE (p.status & $trash)=0 AND t.filedata LIKE '%\"title\"%'" .
					($hasRef ? " AND t.ref_pages_id=0" : "");
				$query = $database->prepare($sql);
				$query->execute();
				while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
					$data = json_decode((string) $row['filedata'], true);
					if(!is_array($data) || !isset($data['title'])) continue;
					$item = "$field->id:$row[pages_id]:$row[data]";
					foreach(array_keys($this->titleKeys($data['title'])) as $key) self::$titles[$key][] = $item;
				}
				$query->closeCursor();
			} catch(\Exception $e) {
				// a table that cannot be read has no titles to compare
			}
		}

		return self::$titles;
	}

	/**
	 * Keys for the titles of a video, one per language: "0|title" for the default language
	 *
	 * @param string|array|null $value Title as stored: a string, or texts by language ID
	 * @return array Keys as array keys
	 *
	 */
	protected function titleKeys($value) {
		$keys = array();
		if(!is_array($value)) $value = array('0' => $value);
		foreach($value as $lang => $text) {
			if(!is_string($text) && !is_numeric($text)) continue;
			$text = $this->titleKey((string) $text);
			if(strlen($text)) $keys[((int) $lang) . '|' . $text] = true;
		}
		return $keys;
	}

	/**
	 * A title as it is compared: without tags, spaces at the ends and differences of case
	 *
	 * @param string $title
	 * @return string
	 *
	 */
	protected function titleKey($title) {
		$title = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
		return function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
	}

	/**
	 * Say who can load the file of a video, where that is not everybody or not what one would expect
	 *
	 * @param Pagevideo $video The original video
	 * @return array|null
	 *
	 */
	protected function checkAccess(Pagevideo $video) {

		$page = $video->page;
		if(!$page || !$page->id || $page->isTrash()) return null;

		if($video->isProtected()) {
			$note = '';
			$fieldtype = $video->field ? $video->field->type : null;
			if($fieldtype instanceof FieldtypeSelfHostedVideo) {
				$ids = array();
				foreach($fieldtype->findReferences($page, $video->basename()) as $row) $ids[$row['pages_id']] = true;
				$n = count($ids);
				if($n) $note = sprintf($this->_n('It is reused on %d other page: who may view that page can load it too.', 'It is reused on %d other pages: who may view one of them can load it too.', $n), $n);
			}
			return $this->check('access', 'file', 'info', $this->_('Protected: only those who may view this page can load the video'), $note);
		}

		// a page that asks visitors to sign in, with files that do not
		if(!$page->isUnpublished() && !$page->isPublic()) {
			// the setting counts on the template that manages access to the page, which can be that of a parent
			$template = $page->getAccessTemplate();
			$name = $template ? $template->name : $page->template->name;
			return $this->check('access', 'file', 'warning', $this->_('Not protected: this page is not public, but its files are'),
				sprintf($this->_('Anyone who has the address of the video file can load it without signing in. To protect the files of such pages, answer “Prevent direct access to file assets owned by pages using this template?” with yes in the Access tab of the template “%s”, or set $config->pagefileSecure = true in /site/config.php.'), $name));
		}

		return null;
	}

	/**
	 * Say whether other websites are kept from embedding the video, where that is blocked
	 *
	 * @param Pagevideo $video The original video
	 * @return WireData|null
	 *
	 */
	protected function checkEmbed(Pagevideo $video) {

		$page = $video->page;
		if(!$page || !$page->id || $page->isTrash()) return null;
		$fieldtype = $video->field ? $video->field->type : null;
		if(!$fieldtype instanceof FieldtypeSelfHostedVideo) return null;

		$rules = $fieldtype->embedRules();
		if(!$rules->isBlocked($video)) return null;

		$label = $this->_('Embedding on other websites: blocked');

		if($video->isProtected()) {
			return $this->check('embed', 'file', 'info', $label, $this->_('The module sends the files of protected videos and applies the rule itself.'));
		}

		$server = $rules->serverName();
		$readsRules = $server === 'apache' || $server === 'litespeed';
		$howTo = $readsRules
			? ($rules->hasRules($video)
				? $this->_('The module wrote the rules into the file .htaccess in the folder of this page, but the web server does not apply them. It has to allow .htaccess files to do so (AllowOverride All, or at least AuthConfig).')
				: $this->_('The module could not write the rules into the folder of this page. The folder has to be writable for the web server.'))
			: $this->_('This web server does not read the rules the module writes (.htaccess). Add the rule from “Embedding on other websites” in the README to its configuration.');

		$known = $rules->known($video);

		if($known === 'yes') {
			return $this->check('embed', 'file', 'info', $label, $this->_('Checked: the web server refuses the file to pages of other websites.'));
		}
		if($known === 'no') {
			return $this->check('embed', 'file', 'warning', $this->_('Embedding is blocked, but the web server still sends the file to other websites'), $howTo);
		}
		if($known === 'unknown') {
			return $this->check('embed', 'file', 'info', $label, $this->_('Whether the web server applies it could not be checked from here.') . ' ' .
				($readsRules ? $this->_('The rules are in the file .htaccess in the folder of this page.') : $howTo));
		}

		$check = $this->check('embed', 'file', 'info', $label, $this->_('Checking whether the web server applies it…'));
		$check->set('ask', 1);
		return $check;
	}

	/**
	 * Count the checks that ask for attention
	 *
	 * @param array $checks As returned by run()
	 * @return int
	 *
	 */
	public function countOpen(array $checks) {
		$n = 0;
		foreach($checks as $check) {
			if($check->status === 'todo' || $check->status === 'warning') $n++;
		}
		return $n;
	}

	/**
	 * What to do about a video that is too large
	 *
	 * @return string
	 *
	 */
	protected function smallerAdvice() {
		return $this->_('Make it smaller (MP4 with H.264, at most 1080p, about 5 Mbit/s), then upload it with “Replace video file”: title, description and all other details are kept.');
	}

	/**
	 * Give a check about the size of a video the free tools for making it smaller
	 *
	 * Services the video would have to be uploaded to are left out for protected videos.
	 *
	 * @param WireData $check
	 * @param Pagevideo $video The video with the file (the original of a reused video)
	 * @param Pagevideo $shown The video the checklist is for
	 *
	 */
	protected function addTools(WireData $check, Pagevideo $video, Pagevideo $shown) {
		$fieldtype = $video->field ? $video->field->type : null;
		if(!$fieldtype instanceof FieldtypeSelfHostedVideo) return;
		$protected = $video->isProtected();
		$check->set('tools', $fieldtype->optimizeTools(!$protected));
		$check->set('toolsProtected', $protected);
		$check->set('replace', !$shown->isReference());
	}

	/**
	 * Markup of the free tools a check offers, for the checklists in the page editor and the Video Manager
	 *
	 * @param WireData $check
	 * @param string $prefix Start of the class names, i.e. "InputfieldSelfHostedVideo"
	 * @return string Blank when the check offers none
	 *
	 */
	public function renderTools(WireData $check, $prefix) {
		$tools = $check->get('tools');
		if(!is_array($tools) || !count($tools)) return '';
		$sanitizer = $this->wire()->sanitizer;
		$kinds = array(
			'browser' => $this->_('works in the browser, the video is not uploaded'),
			'upload' => $this->_('the video is uploaded to the service'),
			'program' => $this->_('a program for your computer'),
		);
		$newWindow = $sanitizer->entities($this->_('opens in a new window'));
		$items = '';
		foreach($tools as $tool) {
			$kind = isset($kinds[$tool['kind']]) ? $kinds[$tool['kind']] : '';
			$items .=
				"<li>" .
					"<a href='" . $sanitizer->entities($tool['url']) . "' target='_blank' rel='noopener noreferrer'>" .
						$sanitizer->entities($tool['name']) .
						" <i class='fa fa-external-link' aria-hidden='true'></i><span class='{$prefix}Sr'> ($newWindow)</span>" .
					"</a>" .
					(strlen($kind) ? " <span class='{$prefix}CheckToolKind'>– " . $sanitizer->entities($kind) . "</span>" : '') .
				"</li>";
		}
		$out =
			"<span class='{$prefix}CheckTools'>" .
				"<span class='{$prefix}CheckToolsLabel'>" . $sanitizer->entities($this->_('Free tools for making it smaller:')) . "</span>" .
				"<ul class='{$prefix}CheckToolsList'>$items</ul>";
		if($check->get('toolsProtected')) {
			$out .= "<span class='{$prefix}CheckToolKind'>" . $sanitizer->entities($this->_('This video is protected, so services it would have to be uploaded to are not offered.')) . "</span>";
		}
		return $out . "</span>";
	}

	/**
	 * Make one check
	 *
	 * @param string $name
	 * @param string $group
	 * @param string $status
	 * @param string $label
	 * @param string $note
	 * @return WireData
	 *
	 */
	protected function check($name, $group, $status, $label, $note = '') {
		/** @var WireData $check */
		$check = $this->wire(new WireData());
		$check->setArray(array(
			'name' => $name,
			'group' => $group,
			'status' => $status,
			'label' => $label,
			'note' => $note,
		));
		return $check;
	}

	/**
	 * Format a number with one decimal at most, in the way of the current language
	 *
	 * @param float $value
	 * @return string
	 *
	 */
	protected function number($value) {
		$value = round((float) $value, 1);
		$decimals = abs($value - round($value)) < 0.05 ? 0 : 1;
		$point = $this->_x('.', 'decimal point');
		return number_format($value, $decimals, $point, '');
	}
}
