<?php namespace ProcessWire;

/**
 * Finds videos across all pages and video fields of the site
 *
 * Used by the overview of all videos in the admin and by the picker for reusing a video
 * on another page. The search runs in the database on file names, texts and tags, so that
 * only the pages of the videos shown have to be loaded.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoFinder extends Wire {

	/**
	 * Days the counts are summed up for: sorting by plays, "not watched", "viewers stop early"
	 *
	 */
	const playDays = 30;

	/**
	 * "Viewers stop early": at least this many plays in the last playDays days ...
	 *
	 */
	const dropoffMinPlays = 5;

	/**
	 * ... and fewer than this percent of them reached the half
	 *
	 */
	const dropoffHalf = 30;

	/**
	 * Get the plays of the last playDays days of all videos
	 *
	 * @return array Keys "page ID/file name", each with `plays` and `half` (percent of the plays that reached the half)
	 *
	 */
	public function recentPlays() {
		$out = array();
		$fieldtype = $this->fieldtype();
		if(!$fieldtype || !$fieldtype->stats()->tableExists()) return $out;
		$query = $this->wire()->database->prepare(
			"SELECT video_pages_id, video_name, SUM(plays) AS plays, SUM(p50) AS p50 FROM `" . SelfHostedVideoStats::table . "` " .
			"WHERE day >= :d GROUP BY video_pages_id, video_name"
		);
		$query->execute(array(':d' => date('Y-m-d', time() - (self::playDays - 1) * 86400)));
		foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$plays = (int) $row['plays'];
			$out[$row['video_pages_id'] . '/' . $row['video_name']] = array(
				'plays' => $plays,
				'half' => $plays > 0 ? (int) round(min(100, (int) $row['p50'] * 100 / $plays)) : 0,
			);
		}
		return $out;
	}

	/**
	 * Find videos
	 *
	 * Only original videos are found, not their reuses on other pages, and only videos of
	 * pages the current user may view. Pages in the trash are left out.
	 *
	 * @param array $options
	 *  - `q` (string): Words to look for in title, description, file name, tags and the title of the page. All have to be found.
	 *  - `tag` (string): Only videos with this tag.
	 *  - `field` (string): Only videos of the video field with this name.
	 *  - `show` (string): "all" (default), "reused" (videos that are reused on other pages),
	 *     "protected" (videos whose files are protected), "stream" (MP4 files not prepared for streaming)
	 *     or "unused" (videos that are on no published page: neither their own page nor a page that reuses them is published),
	 *     "intext" (placed with a tag in a text), "unplayed" (not watched in the last 30 days) or
	 *     "dropoff" (at least 5 plays in the last 30 days, fewer than 30% of them to the half) or
	 *     "duplicates" (the same file as another video; each group together, oldest first, whatever `sort` says).
	 *  - `checklist` (string): "all" (default), "open" (videos with open points on their checklist) or
	 *     "complete" (videos without). The older `show` value "attention" does the same as "open".
	 *  - `sort` (string): "newest" (default), "oldest", "name", "longest", "largest", "mostplayed" or "leastplayed"
	 *     (plays of the last 30 days).
	 *  - `start` (int): Index of the first video to return (default=0).
	 *  - `limit` (int): Number of videos to return, 0 for all (default=25).
	 * @return array
	 *  - `items` (array): The videos (Pagevideo objects).
	 *  - `total` (int): Number of videos that match.
	 *  - `start` (int): Index of the first video returned.
	 *  - `limit` (int)
	 *  - `duration` (float): Seconds of all matching videos together.
	 *  - `filesize` (int): Bytes of all matching videos together.
	 *
	 */
	public function find(array $options = array()) {

		$defaults = array(
			'q' => '',
			'tag' => '',
			'field' => '',
			'show' => 'all',
			'checklist' => 'all',
			'sort' => 'newest',
			'start' => 0,
			'limit' => 25,
		);

		$options = array_merge($defaults, $options);
		$limit = max(0, (int) $options['limit']);
		$start = max(0, (int) $options['start']);

		$show = (string) $options['show'];
		$checklist = (string) $options['checklist'];
		// the name this choice had while it was one of "show"
		if($show === 'attention') $checklist = 'open';

		$rows = $this->findRows($options);
		$rows = $this->filterByAccess($rows);

		if($show === 'reused') {
			$used = $this->usedKeys();
			foreach($rows as $key => $row) {
				if(!isset($used[$row['pages_id'] . '/' . $row['name']])) unset($rows[$key]);
			}
		}

		if($show === 'intext') {
			// placed with a tag in a text (as found when the pages were last saved)
			$fieldtype = $this->fieldtype();
			$inText = $fieldtype ? $fieldtype->usage()->textKeys() : array();
			foreach($rows as $key => $row) {
				if(!isset($inText[$row['pages_id'] . '/' . $row['name']])) unset($rows[$key]);
			}
		}

		if($show === 'duplicates') {
			// the same file as another video: each group together, the oldest of a group first
			$fieldtype = $this->fieldtype();
			$groups = $fieldtype ? $fieldtype->duplicates()->keys() : array();
			$order = array_flip(array_values(array_unique($groups)));
			foreach($rows as $key => $row) {
				$k = $row['pages_id'] . '/' . $row['name'];
				if(!isset($groups[$k])) {
					unset($rows[$key]);
				} else {
					$rows[$key]['group'] = $order[$groups[$k]];
				}
			}
		}

		$sort = (string) $options['sort'];
		if($show === 'unplayed' || $show === 'dropoff' || $sort === 'mostplayed' || $sort === 'leastplayed') {
			$plays = $this->recentPlays();
			foreach($rows as $key => $row) {
				$k = $row['pages_id'] . '/' . $row['name'];
				$rows[$key]['plays'] = isset($plays[$k]) ? $plays[$k]['plays'] : 0;
				$rows[$key]['half'] = isset($plays[$k]) ? $plays[$k]['half'] : 0;
				// nobody watched it in the last 30 days
				if($show === 'unplayed' && $rows[$key]['plays'] > 0) unset($rows[$key]);
				// enough plays to tell, and most of them stopped before the half
				if($show === 'dropoff' && !($rows[$key]['plays'] >= self::dropoffMinPlays && $rows[$key]['half'] < self::dropoffHalf)) unset($rows[$key]);
			}
		}

		if($show === 'protected' || $show === 'stream' || $show === 'unused' || $checklist === 'open' || $checklist === 'complete') {
			// these depend on more than the database row tells, so each video is asked
			foreach($rows as $key => $row) {
				$video = $this->getVideo($row);
				$keep = (bool) $video;
				if($keep && $show === 'protected') $keep = $video->isProtected();
				if($keep && $show === 'stream') $keep = $video->mediaInfo()['faststart'] === false;
				if($keep && $show === 'unused') $keep = !$this->isOnPublishedPage($video);
				if($keep && $checklist === 'open') $keep = $video->openChecks() > 0;
				if($keep && $checklist === 'complete') $keep = $video->openChecks() === 0;
				if(!$keep) unset($rows[$key]);
			}
		}

		if($show === 'duplicates') {
			$rows = array_values($rows);
			usort($rows, function($a, $b) {
				if($a['group'] !== $b['group']) return $a['group'] - $b['group'];
				if($a['created'] !== $b['created']) return $a['created'] < $b['created'] ? -1 : 1;
				return $a['pages_id'] - $b['pages_id'];
			});
		} else {
			$rows = $this->sortRows(array_values($rows), (string) $options['sort']);
		}

		$result = array(
			'items' => array(),
			'total' => count($rows),
			'start' => $start,
			'limit' => $limit,
			'duration' => 0.0,
			'filesize' => 0,
		);

		foreach($rows as $row) {
			$result['duration'] += $row['duration'];
			$result['filesize'] += $row['filesize'];
		}

		if($start >= $result['total'] && $result['total'] > 0 && $limit > 0) {
			// asked for a page behind the last one: show the last one
			$start = (int) (floor(($result['total'] - 1) / $limit) * $limit);
			$result['start'] = $start;
		}

		foreach($limit ? array_slice($rows, $start, $limit) : array_slice($rows, $start) as $row) {
			$video = $this->getVideo($row);
			if($video) $result['items'][] = $video;
		}

		return $result;
	}

	/**
	 * Get the rows of all videos the current user may see
	 *
	 * @return array As findRows() gives them
	 *
	 */
	public function allRows() {
		if($this->allRows === null) {
			$this->allRows = $this->filterByAccess($this->findRows(array('q' => '', 'tag' => '', 'field' => '')));
		}
		return $this->allRows;
	}

	/**
	 * Forget the rows found in this request (after videos changed)
	 *
	 */
	public function forget() {
		$this->allRows = null;
	}

	/**
	 * Rows of allRows(), for this request
	 *
	 * @var array|null
	 *
	 */
	protected $allRows = null;

	/**
	 * Get the rows of all videos that match, from the tables of all video fields
	 *
	 * @param array $options See find()
	 * @return array of [ pages_id, field, name, created, filesize, duration ]
	 *
	 */
	protected function findRows(array $options) {

		$database = $this->wire()->database;
		$fieldtype = $this->fieldtype();
		$rows = array();
		if(!$fieldtype) return $rows;

		$terms = $this->terms((string) $options['q']);
		$tag = str_replace(' ', '_', trim((string) $options['tag']));
		$onlyField = trim((string) $options['field']);
		$trash = (int) Page::statusTrash;

		// pages whose title has the words, so that "videos of the page about X" can be found
		$titlePages = array();
		foreach($terms as $n => $term) $titlePages[$n] = $this->pagesByTitle($term);

		foreach($fieldtype->getVideoFields() as $field) {

			if(strlen($onlyField) && $field->name !== $onlyField) continue;

			$table = $database->escapeTable($field->getTable());
			$schema = $fieldtype->getDatabaseSchema($field);
			$hasTags = isset($schema['tags']);
			if(strlen($tag) && !$hasTags) continue;

			$where = array('t.ref_pages_id=0', "p.status<$trash");
			// a video that is still being uploaded carries a date from 1970 until its page is saved
			$where[] = "(t.created IS NULL OR t.created>'1980-01-01 00:00:00')";
			$binds = array();

			if(strlen($tag)) {
				$where[] = "CONCAT(' ', t.tags, ' ') LIKE :tag";
				$binds[':tag'] = '% ' . $this->like($tag) . ' %';
			}

			foreach($terms as $n => $term) {
				$any = array("t.data LIKE :q$n", "t.description LIKE :q$n", "t.filedata LIKE :q$n");
				$binds[":q$n"] = '%' . $this->like($term) . '%';
				if($hasTags) $any[] = "t.tags LIKE :q$n";
				// texts are stored as JSON, where letters such as "ü" can be written as "ü"
				foreach($this->jsonVariants($term) as $v => $variant) {
					$any[] = "t.description LIKE :j{$n}_$v";
					$any[] = "t.filedata LIKE :j{$n}_$v";
					$binds[":j{$n}_$v"] = '%' . $this->like($variant) . '%';
				}
				if(count($titlePages[$n])) $any[] = 't.pages_id IN(' . implode(',', $titlePages[$n]) . ')';
				$where[] = '(' . implode(' OR ', $any) . ')';
			}

			$sql =
				"SELECT t.pages_id, t.data, UNIX_TIMESTAMP(t.created) AS created, t.filesize, t.duration " .
				"FROM `$table` t JOIN pages p ON p.id=t.pages_id " .
				"WHERE " . implode(' AND ', $where);

			try {
				$query = $database->prepare($sql);
				foreach($binds as $key => $value) $query->bindValue($key, $value);
				$query->execute();
			} catch(\PDOException $e) {
				// a table that does not have the columns of a video field yet
				continue;
			}

			while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
				$rows[] = array(
					'pages_id' => (int) $row['pages_id'],
					'field' => $field->name,
					'name' => (string) $row['data'],
					'created' => (int) $row['created'],
					'filesize' => (int) $row['filesize'],
					'duration' => (float) $row['duration'],
				);
			}

			$query->closeCursor();
		}

		return $rows;
	}

	/**
	 * Leave out the videos of pages the current user may not view
	 *
	 * @param array $rows
	 * @return array
	 *
	 */
	protected function filterByAccess(array $rows) {

		if($this->wire()->user->isSuperuser() || !count($rows)) return $rows;

		$pages = $this->wire()->pages;
		$allowed = array();
		$ids = array();
		foreach($rows as $row) $ids[$row['pages_id']] = $row['pages_id'];

		foreach(array_chunk(array_values($ids), 200) as $chunk) {
			foreach($pages->getById($chunk) as $page) {
				/** @var Page $page */
				$allowed[$page->id] = $this->viewable($page);
			}
		}

		foreach($rows as $key => $row) {
			if(empty($allowed[$row['pages_id']])) unset($rows[$key]);
		}

		return $rows;
	}

	/**
	 * May the current user see the videos of this page in the admin?
	 *
	 * Yes if the user may view the page. An item of a repeater field is seen through the page it belongs to.
	 *
	 * @param Page $page
	 * @return bool
	 *
	 */
	public function viewable(Page $page) {
		$guard = 0;
		while($page->id && method_exists($page, 'getForPage') && $guard++ < 10) $page = $page->getForPage();
		if(!$page->id || $page->isTrash()) return false;
		return $page->viewable(false) || $page->editable();
	}

	/**
	 * Is a video on a published page: its own page, or a page that reuses it?
	 *
	 * For a video in a repeater item, the page the item belongs to counts.
	 *
	 * @param Pagevideo $video An original video
	 * @return bool
	 *
	 */
	public function isOnPublishedPage(Pagevideo $video) {
		if($video->page && $video->page->id && !$this->editPage($video->page)->isUnpublished()) return true;
		foreach($video->references() as $p) {
			$p = $this->editPage($p);
			if($p->id && !$p->isUnpublished() && !$p->isTrash()) return true;
		}
		return false;
	}

	/**
	 * Get the page an editor works on to change a video: the page itself, or the one a repeater item belongs to
	 *
	 * @param Page $page
	 * @return Page
	 *
	 */
	public function editPage(Page $page) {
		$guard = 0;
		while($page->id && method_exists($page, 'getForPage') && $guard++ < 10) $page = $page->getForPage();
		return $page;
	}

	/**
	 * Is there a video the current user could reuse in the given field of a page?
	 *
	 * That is an original video of another page, or of another video field of the same page,
	 * that the user may see and that the field does not show already. Asked by the page editor,
	 * which offers to reuse a video only when there is one.
	 *
	 * @param Pagevideos $value The videos of the field as they are now
	 * @return bool
	 *
	 */
	public function hasReusable(Pagevideos $value) {

		$pageId = $value->page ? (int) $value->page->id : 0;
		$fieldName = $value->field ? $value->field->name : '';
		$superuser = $this->wire()->user->isSuperuser();
		$candidates = array();

		foreach($this->findRows(array('q' => '', 'tag' => '', 'field' => '')) as $row) {
			// the videos of the field itself
			if($row['pages_id'] === $pageId && $row['field'] === $fieldName) continue;
			// and those it shows already
			if($value->getFile(Pagevideos::referenceKeyFor($row['pages_id'], $row['name'])) instanceof Pagevideo) continue;
			if($superuser) return true;
			$candidates[$row['pages_id']] = $row['pages_id'];
		}

		// may the user see one of the pages that are left? Asked in portions, as one is enough.
		$pages = $this->wire()->pages;
		foreach(array_chunk(array_values($candidates), 50) as $chunk) {
			foreach($pages->getById($chunk) as $page) {
				/** @var Page $page */
				if($this->viewable($page)) return true;
			}
		}

		return false;
	}

	/**
	 * Get the keys ("page ID/file name") of all videos that are reused somewhere
	 *
	 * @return array [ key => number of reuses ]
	 *
	 */
	public function usedKeys() {

		$database = $this->wire()->database;
		$fieldtype = $this->fieldtype();
		$used = array();
		if(!$fieldtype) return $used;

		foreach($fieldtype->getVideoFields() as $field) {
			$table = $database->escapeTable($field->getTable());
			try {
				$query = $database->prepare("SELECT ref_pages_id, ref_name, COUNT(*) AS n FROM `$table` WHERE ref_pages_id>0 GROUP BY ref_pages_id, ref_name");
				$query->execute();
			} catch(\PDOException $e) {
				continue;
			}
			while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
				$key = ((int) $row['ref_pages_id']) . '/' . $row['ref_name'];
				$used[$key] = (isset($used[$key]) ? $used[$key] : 0) + (int) $row['n'];
			}
			$query->closeCursor();
		}

		return $used;
	}

	/**
	 * Get all tags that videos of the site have, with their labels
	 *
	 * @return array [ tag => label ], sorted by label
	 *
	 */
	public function tagsInUse() {

		$database = $this->wire()->database;
		$fieldtype = $this->fieldtype();
		$tags = array();
		if(!$fieldtype) return $tags;

		foreach($fieldtype->getVideoFields() as $field) {
			$schema = $fieldtype->getDatabaseSchema($field);
			if(!isset($schema['tags'])) continue;
			$table = $database->escapeTable($field->getTable());
			$labels = $fieldtype->getTagLabels($field);
			try {
				$query = $database->prepare("SELECT DISTINCT tags FROM `$table` WHERE tags!=''");
				$query->execute();
			} catch(\PDOException $e) {
				continue;
			}
			while($row = $query->fetch(\PDO::FETCH_NUM)) {
				foreach(explode(' ', (string) $row[0]) as $tag) {
					$tag = trim($tag);
					if(!strlen($tag) || isset($tags[$tag])) continue;
					$tags[$tag] = isset($labels[$tag]) && strlen($labels[$tag]) ? $labels[$tag] : str_replace('_', ' ', $tag);
				}
			}
			$query->closeCursor();
		}

		uasort($tags, 'strnatcasecmp');

		return $tags;
	}

	/**
	 * Get the video of a row
	 *
	 * @param array $row
	 * @return Pagevideo|null
	 *
	 */
	public function getVideo(array $row) {
		$page = $this->wire()->pages->get((int) $row['pages_id']);
		if(!$page->id) return null;
		$videos = $page->getUnformatted($row['field']);
		if(!$videos instanceof Pagevideos) return null;
		$video = $videos->getFile($row['name']);
		return $video instanceof Pagevideo && !$video->isReference() ? $video : null;
	}

	/**
	 * Sort rows
	 *
	 * @param array $rows
	 * @param string $sort
	 * @return array
	 *
	 */
	protected function sortRows(array $rows, $sort) {

		$keys = array(
			'mostplayed' => array('plays', -1),
			'leastplayed' => array('plays', 1),
			'newest' => array('created', -1),
			'oldest' => array('created', 1),
			'name' => array('name', 1),
			'longest' => array('duration', -1),
			'largest' => array('filesize', -1),
		);

		list($key, $direction) = isset($keys[$sort]) ? $keys[$sort] : $keys['newest'];

		usort($rows, function($a, $b) use($key, $direction) {
			if($key === 'name') {
				$result = strnatcasecmp($a['name'], $b['name']);
			} else {
				$result = $a[$key] <=> $b[$key];
			}
			if($result === 0) {
				// the same order every time. Pages are numbered as they are made, so this also
				// puts videos added in the same second in the order asked for.
				$result = $a['pages_id'] <=> $b['pages_id'];
				if($result === 0) $result = strnatcasecmp($a['name'], $b['name']);
			}
			return $result * $direction;
		});

		return $rows;
	}

	/**
	 * Split what was typed into words to look for
	 *
	 * @param string $q
	 * @return array At most six words
	 *
	 */
	protected function terms($q) {
		$terms = array();
		foreach(preg_split('/\s+/u', trim($q)) as $term) {
			$term = trim($term);
			if(strlen($term) && mb_strlen($term) <= 100) $terms[] = $term;
			if(count($terms) >= 6) break;
		}
		return $terms;
	}

	/**
	 * Get the ways a word can be written in the stored JSON, where they differ from the word itself
	 *
	 * @param string $term
	 * @return array
	 *
	 */
	protected function jsonVariants($term) {
		$variants = array();
		$forms = array($term, mb_strtolower($term), mb_convert_case($term, MB_CASE_TITLE), mb_strtoupper($term));
		foreach(array_unique($forms) as $form) {
			$json = substr((string) json_encode($form), 1, -1);
			if($json !== $form && strlen($json)) $variants[$json] = $json;
		}
		return array_values($variants);
	}

	/**
	 * Get the IDs of pages whose title has the given word
	 *
	 * @param string $term
	 * @return array
	 *
	 */
	protected function pagesByTitle($term) {
		$value = $this->wire()->sanitizer->selectorValue($term);
		if(!strlen($value)) return array();
		$ids = array();
		try {
			foreach($this->wire()->pages->findIDs("title%=$value, include=all, limit=500") as $id) $ids[] = (int) $id;
		} catch(\Exception $e) {
			// a site without a title field
		}
		return $ids;
	}

	/**
	 * Prepare a text for use in LIKE, where "%", "_" and "\" have a meaning
	 *
	 * @param string $text
	 * @return string
	 *
	 */
	protected function like($text) {
		return addcslashes($text, '%_\\');
	}

	/**
	 * Get the field type module
	 *
	 * @return FieldtypeSelfHostedVideo|null
	 *
	 */
	protected function fieldtype() {
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		return $fieldtype instanceof FieldtypeSelfHostedVideo ? $fieldtype : null;
	}
}
