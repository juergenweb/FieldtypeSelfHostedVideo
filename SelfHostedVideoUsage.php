<?php namespace ProcessWire;

/**
 * Where each video is placed: on its own page, reused on other pages, and in texts
 *
 * The module keeps a list in its own table: for each original video (its page and file name),
 * the pages it is placed on, and how:
 *
 * - `field`  in a video field of its own page (where it was uploaded)
 * - `reuse`  reused in a video field of another page
 * - `text`   with a tag such as [[video intro.mp4]] in a text that has the text formatter
 *            "Videos in text" (in any language of the text)
 *
 * The list of a page is made again whenever the page is saved, and the rows of a page go when it
 * is deleted. For a text in a repeater item, the item is the page that holds the text; it is
 * shown as the page the item belongs to.
 *
 * ~~~~~
 * foreach($video->usage() as $use) {
 *   echo $use['page']->title . ' (' . $use['kind'] . ', ' . $use['field']->name . ')';
 * }
 * $video->usedOnPages(); // the pages, as a PageArray
 * ~~~~~
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoUsage extends Wire {

	/**
	 * Name of the table
	 *
	 */
	const table = 'selfhostedvideo_usage';

	/**
	 * @var FieldtypeSelfHostedVideo
	 *
	 */
	protected $fieldtype;

	/**
	 * Does the table exist? (null: not yet asked)
	 *
	 * @var bool|null
	 *
	 */
	protected $tableExists = null;

	/**
	 * Tags found by the last findForPage() that name no video: [ fields_id => [ lowercase name => true ] ]
	 *
	 * @var array
	 *
	 */
	protected $missing = array();

	/**
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 *
	 */
	public function __construct(FieldtypeSelfHostedVideo $fieldtype) {
		parent::__construct();
		$this->fieldtype = $fieldtype;
	}

	/**
	 * Create the table, if it is not there
	 *
	 * @return bool
	 *
	 */
	public function install() {
		$table = self::table;
		$this->wire()->database->exec(
			"CREATE TABLE IF NOT EXISTS `$table` (" .
				"video_pages_id INT UNSIGNED NOT NULL, " .
				"video_name VARCHAR(250) NOT NULL, " .
				"pages_id INT UNSIGNED NOT NULL, " .
				"kind VARCHAR(16) NOT NULL, " .
				"fields_id INT UNSIGNED NOT NULL DEFAULT 0, " .
				"PRIMARY KEY (video_pages_id, video_name(191), pages_id, kind, fields_id), " .
				"INDEX pages_id (pages_id)" .
			") ENGINE={$this->wire()->config->dbEngine} DEFAULT CHARSET={$this->wire()->config->dbCharset}"
		);
		$this->tableExists = true;
		return true;
	}

	/**
	 * Remove the table
	 *
	 */
	public function uninstall() {
		$this->wire()->database->exec("DROP TABLE IF EXISTS `" . self::table . "`");
		$this->tableExists = false;
	}

	/**
	 * Is the table there?
	 *
	 * @return bool
	 *
	 */
	public function tableExists() {
		if($this->tableExists === null) {
			try {
				$this->tableExists = $this->wire()->database->tableExists(self::table);
			} catch(\Exception $e) {
				$this->tableExists = false;
			}
		}
		return $this->tableExists;
	}

	/**
	 * Get the text fields of a template whose texts can place videos (text formatter "Videos in text")
	 *
	 * @param Template $template
	 * @return Field[]
	 *
	 */
	public function textFields(Template $template) {
		$fields = array();
		foreach($template->fieldgroup as $field) {
			/** @var Field $field */
			$formatters = $field->get('textformatters');
			if(is_array($formatters) && in_array('TextformatterSelfHostedVideo', $formatters, true)) $fields[] = $field;
		}
		return $fields;
	}

	/**
	 * Can pages of this template place videos (a video field, or a text with the formatter)?
	 *
	 * @param Template|null $template
	 * @return bool
	 *
	 */
	public function isRelevant($template) {
		if(!$template instanceof Template) return false;
		foreach($template->fieldgroup as $field) {
			if($field->type instanceof FieldtypeSelfHostedVideo) return true;
		}
		return count($this->textFields($template)) > 0;
	}

	/**
	 * Names of the videos the texts of a page place with tags, kept per request: [ "page|modified" => names ]
	 *
	 * @var array
	 *
	 */
	protected $tagNames = array();

	/**
	 * Get the file names the texts of a page name in video tags (texts of its repeater items too)
	 *
	 * Read from the texts themselves, in every language, so that it holds before the page is saved.
	 *
	 * @param Page $page
	 * @return array Lower case file names as keys
	 *
	 */
	public function textTagNames(Page $page) {
		if(!$page->id || !$page->template) return array();
		$key = $page->id . '|' . $page->modified;
		if(isset($this->tagNames[$key])) return $this->tagNames[$key];
		if(count($this->tagNames) > 100) $this->tagNames = array();
		$formatter = $this->wire()->modules->isInstalled('TextformatterSelfHostedVideo')
			? $this->wire()->modules->get('TextformatterSelfHostedVideo') : null;
		$names = array();
		if($formatter instanceof TextformatterSelfHostedVideo) $this->collectTagNames($page, $formatter, $names, 0);
		$this->tagNames[$key] = $names;
		return $names;
	}

	/**
	 * Add the video tags of the texts of a page (and of its repeater items) to a list
	 *
	 * @param Page $page
	 * @param TextformatterSelfHostedVideo $formatter
	 * @param array $names
	 * @param int $depth
	 *
	 */
	protected function collectTagNames(Page $page, TextformatterSelfHostedVideo $formatter, array &$names, $depth) {
		if(!$page->template) return;
		foreach($this->textFields($page->template) as $field) {
			$value = $page->getUnformatted($field->name);
			$texts = array();
			if($value instanceof LanguagesPageFieldValue) {
				$languages = $this->wire()->languages;
				if($languages) foreach($languages as $language) $texts[] = (string) $value->getLanguageValue($language->id);
			} else if(is_string($value)) {
				$texts[] = $value;
			}
			foreach($texts as $text) {
				if(stripos($text, '[[video') === false) continue;
				if(!preg_match_all('~' . TextformatterSelfHostedVideo::tagPattern . '~isu', $text, $matches)) continue;
				foreach($matches[1] as $spec) {
					$tag = $formatter->parseTag($spec);
					if(strlen($tag['name'])) $names[strtolower($tag['name'])] = true;
				}
			}
		}
		if($depth >= 2) return;
		// the texts of repeater items show the videos of the page they belong to
		foreach($page->template->fieldgroup as $field) {
			if(!$field->type instanceof FieldtypeRepeater) continue;
			$value = $page->getUnformatted($field->name);
			if($value instanceof Page) $value = array($value);
			if(!$value instanceof PageArray && !is_array($value)) continue;
			foreach($value as $item) {
				if($item instanceof Page && $item->id && !$item->isUnpublished()) $this->collectTagNames($item, $formatter, $names, $depth + 1);
			}
		}
	}

	/**
	 * Does a text of the video's page place it with a tag?
	 *
	 * @param Pagevideo $video
	 * @return bool
	 *
	 */
	public function isInText(Pagevideo $video) {
		$page = $video->page;
		if(!$page instanceof Page || !$page->id) return false;
		$names = $this->textTagNames($page);
		if(!count($names)) return false;
		if(isset($names[strtolower($video->basename())])) return true;
		// a reused video goes by the file name of its original
		return $video->isReference() && isset($names[strtolower((string) $video->get('ref_name'))]);
	}

	/**
	 * Find the videos a page places: in its video fields, and with tags in its texts
	 *
	 * @param Page $page
	 * @return array Rows: video_pages_id, video_name, pages_id, kind, fields_id
	 *
	 */
	public function findForPage(Page $page) {

		$rows = array();
		$this->missing = array();
		if(!$page->id || !$page->template) return $rows;

		$add = function(Pagevideo $video, $kind, Field $field) use(&$rows, $page) {
			if($video->isReference()) {
				$pageId = (int) $video->get('ref_pages_id');
				$name = (string) $video->get('ref_name');
				// a reused video placed with a tag in a text is that text's, the field has it already
				if($kind === 'field') $kind = 'reuse';
			} else {
				if($video->isTemp()) return;
				$pageId = $video->page ? (int) $video->page->id : 0;
				$name = $video->basename();
			}
			if(!$pageId || !strlen($name)) return;
			$row = array('video_pages_id' => $pageId, 'video_name' => $name, 'pages_id' => (int) $page->id, 'kind' => $kind, 'fields_id' => (int) $field->id);
			$rows[implode('|', $row)] = $row;
		};

		$of = $page->of();
		$page->of(false);

		foreach($page->template->fieldgroup as $field) {
			if(!$field->type instanceof FieldtypeSelfHostedVideo) continue;
			$value = $page->get($field->name);
			if($value instanceof Pagevideo) $value = array($value);
			if(!$value instanceof Pagevideos && !is_array($value)) continue;
			foreach($value as $video) {
				if($video instanceof Pagevideo) $add($video, 'field', $field);
			}
		}

		$formatter = null;
		foreach($this->textFields($page->template) as $field) {
			$value = $page->get($field->name);
			$texts = array();
			if($value instanceof LanguagesPageFieldValue) {
				$languages = $this->wire()->languages;
				if($languages) foreach($languages as $language) $texts[] = (string) $value->getLanguageValue($language->id);
			} else if(is_string($value)) {
				$texts[] = $value;
			}
			foreach($texts as $text) {
				if(stripos($text, '[[video') === false) continue;
				if(!$formatter) $formatter = $this->wire()->modules->get('TextformatterSelfHostedVideo');
				if(!$formatter instanceof TextformatterSelfHostedVideo) break 2;
				if(!preg_match_all('~' . TextformatterSelfHostedVideo::tagPattern . '~isu', $text, $matches)) continue;
				foreach($matches[1] as $spec) {
					$tag = $formatter->parseTag($spec);
					if(!strlen($tag['name'])) continue;
					$video = $formatter->findVideo($page, $tag['name']);
					if($video) {
						$add($video, 'text', $field);
					} else {
						$this->missing[(int) $field->id][strtolower($tag['name'])] = true;
						// kept as well, for the list of tags that show nothing (video_pages_id 0, the name as written)
						$row = array('video_pages_id' => 0, 'video_name' => mb_substr($tag['name'], 0, 250), 'pages_id' => (int) $page->id, 'kind' => 'missing', 'fields_id' => (int) $field->id);
						$rows[implode('|', $row)] = $row;
					}
				}
			}
		}

		$page->of($of);

		return array_values($rows);
	}

	/**
	 * Make the list of a page again (after it was saved)
	 *
	 * @param Page $page
	 *
	 */
	public function updatePage(Page $page) {
		// the tags of its texts are read anew (a save within the same second keeps the time of change)
		$this->tagNames = array();
		if(!$page->id || !$this->tableExists()) return array();
		$database = $this->wire()->database;
		$table = self::table;
		$id = (int) $page->id;
		$trash = $page->isTrash();
		$rows = $trash ? array() : $this->findForPage($page);
		$names = array();
		foreach($rows as $row) {
			if($row['kind'] === 'field' && $row['video_pages_id'] === $id) $names[] = $row['video_name'];
		}

		// tags in texts that showed a video before this save and show nothing now
		$broken = array();
		if(!$trash) {
			$query = $database->prepare("SELECT video_pages_id, video_name, pages_id, fields_id FROM `$table` WHERE kind='text' AND (pages_id=:a OR video_pages_id=:b)");
			$query->execute(array(':a' => $id, ':b' => $id));
			foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$holder = (int) $row['pages_id'];
				$name = (string) $row['video_name'];
				if($holder === $id) {
					// a text of this page: its tag is there still, but finds no video
					if(!isset($this->missing[(int) $row['fields_id']][strtolower($name)])) continue;
				} else {
					// a text of another page (a repeater item of this one) with a video of this page that is gone
					if((int) $row['video_pages_id'] !== $id || in_array($name, $names, true)) continue;
				}
				$broken[] = array('name' => $name, 'pages_id' => $holder, 'fields_id' => (int) $row['fields_id']);
			}
		}

		// videos that were on this page and are gone: what was counted for them goes too
		if(!$trash) {
			$query = $database->prepare("SELECT DISTINCT video_name FROM `$table` WHERE video_pages_id=:id AND pages_id=:p AND kind='field'");
			$query->execute(array(':id' => $id, ':p' => $id));
			foreach($query->fetchAll(\PDO::FETCH_COLUMN) as $gone) {
				if(!in_array($gone, $names, true)) $this->fieldtype->stats()->remove($id, $gone);
			}
		}

		$query = $database->prepare("DELETE FROM `$table` WHERE pages_id=:id");
		$query->execute(array(':id' => $id));
		// videos that are no longer on their page are placed nowhere
		$sql = "DELETE FROM `$table` WHERE video_pages_id=:id";
		$bind = array(':id' => $id);
		if(count($names)) {
			$in = array();
			foreach(array_values($names) as $n => $name) { $in[] = ":n$n"; $bind[":n$n"] = $name; }
			$sql .= " AND video_name NOT IN(" . implode(',', $in) . ")";
		}
		$query = $database->prepare($sql);
		$query->execute($bind);
		if(count($rows)) {
			$query = $database->prepare("INSERT IGNORE INTO `$table` (video_pages_id, video_name, pages_id, kind, fields_id) VALUES (:v, :n, :p, :k, :f)");
			foreach($rows as $row) {
				$query->execute(array(':v' => $row['video_pages_id'], ':n' => $row['video_name'], ':p' => $row['pages_id'], ':k' => $row['kind'], ':f' => $row['fields_id']));
			}
		}
		return $broken;
	}

	/**
	 * Forget a page: what it places, and where its own videos were placed (after it was deleted)
	 *
	 * @param Page|int $page
	 *
	 */
	public function removePage($page) {
		if(!$this->tableExists()) return;
		$id = (int) ($page instanceof Page ? $page->id : $page);
		$query = $this->wire()->database->prepare("DELETE FROM `" . self::table . "` WHERE pages_id=:a OR video_pages_id=:b");
		$query->execute(array(':a' => $id, ':b' => $id));
	}

	/**
	 * Make the lists of all pages that can place videos (after an upgrade, or to repair them)
	 *
	 * @return int Number of pages looked at
	 *
	 */
	public function rebuild() {
		if(!$this->tableExists()) $this->install();
		$this->wire()->database->exec("DELETE FROM `" . self::table . "`");
		$templates = array();
		foreach($this->wire()->templates as $template) {
			if($this->isRelevant($template)) $templates[] = $template->id;
		}
		if(!count($templates)) return 0;
		$pages = $this->wire()->pages;
		$n = 0;
		$ids = $pages->findIDs('templates_id=' . implode('|', $templates) . ', include=all');
		foreach(array_chunk($ids, 100) as $chunk) {
			foreach($pages->getById($chunk) as $page) {
				$this->updatePage($page);
				$n++;
			}
			$pages->uncacheAll();
		}
		return $n;
	}

	/**
	 * Get where a video is placed
	 *
	 * @param Pagevideo $video An original, or a reuse (then it is the list of its original)
	 * @param bool $includeTrash Also pages in the trash?
	 * @return array Each with `page` (Page; for a repeater item the page it belongs to), `holder` (the
	 *   page that has the field), `kind` ("field", "reuse" or "text") and `field` (Field|null)
	 *
	 */
	public function forVideo(Pagevideo $video, $includeTrash = false) {

		$out = array();
		if(!$this->tableExists()) return $out;

		if($video->isReference()) {
			$pageId = (int) $video->get('ref_pages_id');
			$name = (string) $video->get('ref_name');
		} else {
			$pageId = $video->page ? (int) $video->page->id : 0;
			$name = $video->basename();
		}
		if(!$pageId || !strlen($name)) return $out;

		$query = $this->wire()->database->prepare(
			"SELECT pages_id, kind, fields_id FROM `" . self::table . "` WHERE video_pages_id=:p AND video_name=:n " .
			"ORDER BY FIELD(kind, 'field', 'reuse', 'text'), pages_id, fields_id"
		);
		$query->execute(array(':p' => $pageId, ':n' => $name));
		$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
		$query->closeCursor();

		$pages = $this->wire()->pages;
		$fields = $this->wire()->fields;
		foreach($rows as $row) {
			$holder = $pages->get((int) $row['pages_id']);
			if(!$holder->id) continue;
			$page = $holder;
			$guard = 0;
			while(method_exists($page, 'getForPage') && $guard++ < 10) {
				$for = $page->getForPage();
				if(!$for || !$for->id) break;
				$page = $for;
			}
			if(!$includeTrash && $page->isTrash()) continue;
			$out[] = array(
				'page' => $page,
				'holder' => $holder,
				'kind' => (string) $row['kind'],
				'field' => $fields->get((int) $row['fields_id']),
			);
		}

		return $out;
	}

	/**
	 * Render where a video is placed, for the page editor and the Video Manager
	 *
	 * One line: the pages (linked to their editor where the user may edit them), each with how the
	 * video is placed there: in a video field, reused, or in a text.
	 *
	 * @param Pagevideo $video
	 * @param string $prefix Prefix of the class names, i.e. "InputfieldSelfHostedVideo"
	 * @param bool $newWindow Open the links in a new window (from the page editor, where changes may be unsaved)
	 * @return string Blank when the video is placed nowhere
	 *
	 */
	public function render(Pagevideo $video, $prefix, $newWindow = false) {

		$uses = $this->forVideo($video);
		if(!count($uses)) return '';

		$sanitizer = $this->wire()->sanitizer;
		$byPage = array();
		foreach($uses as $use) {
			$id = $use['page']->id;
			if(!isset($byPage[$id])) $byPage[$id] = array('page' => $use['page'], 'how' => array());
			$label = $use['field'] ? (string) $use['field']->getLabel() : '';
			if($use['kind'] === 'text') {
				$how = sprintf($this->_('in the text “%s”'), $label);
			} else if($use['kind'] === 'reuse') {
				$how = sprintf($this->_('reused in “%s”'), $label);
			} else {
				$how = sprintf($this->_('video field “%s”'), $label);
			}
			$byPage[$id]['how'][$how] = htmlspecialchars($how, ENT_QUOTES, 'UTF-8');
		}

		$target = $newWindow ? " target='_blank'" : '';
		$items = array();
		foreach($byPage as $item) {
			$page = $item['page'];
			$title = htmlspecialchars((string) $page->get('title|name'), ENT_QUOTES, 'UTF-8', false);
			$link = $page->editable() ? "<a href='" . $sanitizer->entities($page->editUrl()) . "'$target>$title</a>" : $title;
			$items[] = "<span class='{$prefix}PlacedPage'>$link (" . implode(', ', $item['how']) . ")</span>";
		}

		$n = count($byPage);
		$intro = sprintf($this->_n('Placed on %d page:', 'Placed on %d pages:', $n), $n);

		return
			"<p class='{$prefix}Placed'><i class='fa fa-fw fa-map-marker' aria-hidden='true'></i> " .
			htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . ' ' . implode('; ', $items) .
			"</p>";
	}

	/**
	 * Get the texts of a page that place a video with a tag
	 *
	 * @param Pagevideo $video An original, or a reuse (then the tags of its original)
	 * @param Page $page The page (texts of its repeater items count too)
	 * @return Field[] The text fields, by ID
	 *
	 */
	public function textsOnPage(Pagevideo $video, Page $page) {
		$out = array();
		foreach($this->forVideo($video, true) as $use) {
			if($use['kind'] !== 'text' || $use['page']->id !== $page->id || !$use['field']) continue;
			$out[$use['field']->id] = $use['field'];
		}
		return $out;
	}

	/**
	 * Say which tags in texts show nothing now (see updatePage())
	 *
	 * @param array $broken As updatePage() returns it
	 * @return string Blank when there are none
	 *
	 */
	public function brokenMessage(array $broken) {
		if(!count($broken)) return '';
		$pages = $this->wire()->pages;
		$fields = $this->wire()->fields;
		$names = array();
		$places = array();
		foreach($broken as $item) {
			$names[$item['name']] = '“' . $item['name'] . '”';
			$page = $pages->get((int) $item['pages_id']);
			$guard = 0;
			while($page->id && method_exists($page, 'getForPage') && $guard++ < 10) {
				$for = $page->getForPage();
				if(!$for || !$for->id) break;
				$page = $for;
			}
			$field = $fields->get((int) $item['fields_id']);
			$places[] = sprintf($this->_('%1$s (text “%2$s”)'), (string) $page->get('title|name'), $field ? $field->getLabel() : '');
		}
		return
			sprintf($this->_n('The video %1$s was placed with a tag in a text: %2$s.', 'The videos %1$s were placed with tags in texts: %2$s.', count($names)), implode(', ', $names), implode(', ', array_unique($places))) . ' ' .
			$this->_('The tag shows nothing there now: remove it, or place another video.');
	}

	/**
	 * Get the tags in texts of the site that name no video, as found when their pages were last saved
	 *
	 * @param bool $includeTrash Also those of pages in the trash?
	 * @return array Each with `page` (Page; for a repeater item the page it belongs to), `holder` (the page
	 *   with the text), `field` (Field|null) and `name` (the file name the tag gives)
	 *
	 */
	public function brokenTags($includeTrash = false) {
		$out = array();
		if(!$this->tableExists()) return $out;
		$query = $this->wire()->database->prepare("SELECT pages_id, fields_id, video_name FROM `" . self::table . "` WHERE kind='missing' ORDER BY pages_id, fields_id, video_name");
		$query->execute();
		$pages = $this->wire()->pages;
		$fields = $this->wire()->fields;
		foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$holder = $pages->get((int) $row['pages_id']);
			if(!$holder->id) continue;
			$page = $holder;
			$guard = 0;
			while(method_exists($page, 'getForPage') && $guard++ < 10) {
				$for = $page->getForPage();
				if(!$for || !$for->id) break;
				$page = $for;
			}
			if(!$includeTrash && ($page->isTrash() || $holder->isTrash())) continue;
			$out[] = array('page' => $page, 'holder' => $holder, 'field' => $fields->get((int) $row['fields_id']), 'name' => (string) $row['video_name']);
		}
		return $out;
	}

	/**
	 * Get the keys ("page ID/file name") of the videos that a text places with a tag
	 *
	 * @return array Keys as array keys
	 *
	 */
	public function textKeys() {
		$out = array();
		if(!$this->tableExists()) return $out;
		$query = $this->wire()->database->query("SELECT DISTINCT video_pages_id, video_name FROM `" . self::table . "` WHERE kind='text'");
		foreach($query->fetchAll(\PDO::FETCH_NUM) as $row) $out[$row[0] . '/' . $row[1]] = true;
		return $out;
	}

	/**
	 * Get the pages a video is placed on
	 *
	 * @param Pagevideo $video
	 * @return PageArray
	 *
	 */
	public function pagesForVideo(Pagevideo $video) {
		$list = $this->wire()->pages->newPageArray();
		foreach($this->forVideo($video) as $use) $list->add($use['page']);
		return $list;
	}

	/**
	 * Give the video tags in the texts of pages another file name (after a video was renamed or merged)
	 *
	 * The texts are changed in the database, in every language, without saving the pages: this may
	 * happen while one of them is being saved. Pages already in memory get the new texts as well.
	 * Repeater items of the pages are included. Options of a tag stay as they were.
	 *
	 * @param array $pageIds Pages whose texts may name the video
	 * @param string $oldName File name the tags give now (in any case)
	 * @param string $newName File name they get
	 * @return array IDs of the pages whose texts were changed
	 *
	 */
	public function renameTags(array $pageIds, $oldName, $newName) {

		$changed = array();
		$oldName = (string) $oldName;
		$newName = (string) $newName;
		if(!strlen($oldName) || !strlen($newName) || $oldName === $newName) return $changed;

		$formatter = $this->wire()->modules->isInstalled('TextformatterSelfHostedVideo')
			? $this->wire()->modules->get('TextformatterSelfHostedVideo') : null;
		if(!$formatter instanceof TextformatterSelfHostedVideo) return $changed;

		$pages = $this->wire()->pages;
		$database = $this->wire()->database;

		// the pages, and the repeater items that belong to them
		$holders = array();
		$collect = function(Page $page, $depth) use(&$collect, &$holders) {
			if(!$page->id || !$page->template || isset($holders[$page->id])) return;
			$holders[$page->id] = $page;
			if($depth >= 2) return;
			foreach($page->template->fieldgroup as $field) {
				if(!$field->type instanceof FieldtypeRepeater) continue;
				$value = $page->getUnformatted($field->name);
				if($value instanceof Page) $value = array($value);
				if(!$value instanceof PageArray && !is_array($value)) continue;
				foreach($value as $item) if($item instanceof Page) $collect($item, $depth + 1);
			}
		};
		foreach(array_unique(array_map('intval', $pageIds)) as $id) {
			if($id < 1) continue;
			$page = $pages->get($id);
			if($page->id) $collect($page, 0);
		}

		foreach($holders as $id => $page) {
			foreach($this->textFields($page->template) as $field) {
				$table = $database->escapeTable($field->getTable());
				try {
					$query = $database->prepare("SELECT * FROM `$table` WHERE pages_id=:id");
					$query->execute(array(':id' => $id));
					$row = $query->fetch(\PDO::FETCH_ASSOC);
					$query->closeCursor();
				} catch(\PDOException $e) {
					continue;
				}
				if(!$row) continue;
				$set = array();
				foreach($row as $column => $text) {
					if(!preg_match('/^data\d*$/', $column) || !is_string($text) || stripos($text, '[[video') === false) continue;
					$new = $this->replaceTagName($formatter, $text, $oldName, $newName);
					if($new !== $text) $set[$column] = $new;
				}
				if(!count($set)) continue;
				$sql = array();
				$bind = array(':id' => $id);
				foreach(array_keys($set) as $n => $column) {
					$sql[] = '`' . $database->escapeCol($column) . "`=:v$n";
					$bind[":v$n"] = $set[$column];
				}
				$query = $database->prepare("UPDATE `$table` SET " . implode(', ', $sql) . " WHERE pages_id=:id");
				$query->execute($bind);
				$changed[$id] = $id;
				// the page in memory has the new text as well, without counting as a change of its own
				$value = $page->getUnformatted($field->name);
				if($value instanceof LanguagesPageFieldValue) {
					$languages = $this->wire()->languages;
					if($languages) foreach($languages as $language) {
						$text = (string) $value->getLanguageValue($language->id);
						$new = $this->replaceTagName($formatter, $text, $oldName, $newName);
						if($new !== $text) $value->setLanguageValue($language->id, $new);
					}
				} else if(is_string($value)) {
					$page->setQuietly($field->name, $this->replaceTagName($formatter, $value, $oldName, $newName));
				}
			}
		}

		$this->tagNames = array();

		return array_values($changed);
	}

	/**
	 * Give the tags of a text that name a video another file name
	 *
	 * @param TextformatterSelfHostedVideo $formatter
	 * @param string $text
	 * @param string $oldName
	 * @param string $newName
	 * @return string
	 *
	 */
	protected function replaceTagName(TextformatterSelfHostedVideo $formatter, $text, $oldName, $newName) {
		$old = strtolower($oldName);
		$result = preg_replace_callback('~' . TextformatterSelfHostedVideo::tagPattern . '~isu', function($m) use($formatter, $old, $newName) {
			$tag = $formatter->parseTag($m[1]);
			if(strtolower($tag['name']) !== $old) return $m[0];
			// the name as written: the first word of the tag, maybe with a path in front of it
			$pos = stripos($m[1], $tag['name']);
			if($pos === false) return $m[0];
			$spec = substr($m[1], 0, $pos) . $newName . substr($m[1], $pos + strlen($tag['name']));
			return str_replace($m[1], $spec, $m[0]);
		}, (string) $text);
		return $result === null ? (string) $text : $result;
	}

	/**
	 * Let the rows of a video belong to another one (after a video was renamed or merged)
	 *
	 * @param int $fromPageId
	 * @param string $fromName
	 * @param int $toPageId
	 * @param string $toName
	 *
	 */
	public function moveVideo($fromPageId, $fromName, $toPageId, $toName) {
		if(!$this->tableExists()) return;
		if((int) $fromPageId === (int) $toPageId && (string) $fromName === (string) $toName) return;
		$database = $this->wire()->database;
		$table = self::table;
		$bind = array(':fp' => (int) $fromPageId, ':fn' => (string) $fromName);
		$query = $database->prepare("UPDATE IGNORE `$table` SET video_pages_id=:tp, video_name=:tn WHERE video_pages_id=:fp AND video_name=:fn");
		$query->execute(array_merge($bind, array(':tp' => (int) $toPageId, ':tn' => (string) $toName)));
		// rows the other video had already
		$query = $database->prepare("DELETE FROM `$table` WHERE video_pages_id=:fp AND video_name=:fn");
		$query->execute($bind);
		$this->tagNames = array();
	}

	/**
	 * Get the pages whose texts place a video with a tag (repeater items as they are)
	 *
	 * @param int $pageId Page of the original
	 * @param string $name File name of the original
	 * @return array Page IDs
	 *
	 */
	public function textHolders($pageId, $name) {
		if(!$this->tableExists()) return array();
		$query = $this->wire()->database->prepare("SELECT DISTINCT pages_id FROM `" . self::table . "` WHERE kind='text' AND video_pages_id=:p AND video_name=:n");
		$query->execute(array(':p' => (int) $pageId, ':n' => (string) $name));
		return array_map('intval', $query->fetchAll(\PDO::FETCH_COLUMN));
	}
}
