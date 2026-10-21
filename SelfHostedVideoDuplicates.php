<?php namespace ProcessWire;

/**
 * Videos uploaded more than once: the same file on several pages, each a copy of its own
 *
 * Videos are the same when their files are: same size, and the same SHA-1 checksum. Checksums are
 * made only for files that have the size of another one, and are kept (in the cache of ProcessWire)
 * until the file changes.
 *
 * A group of the same videos can be merged: one of them is kept, and each of the others is
 * replaced by a reuse of it (a reference, see Pagevideos::addReference()). The copies' files are
 * deleted. What the copies placed stays placed: their pages show the kept video where they showed
 * the copy, pages that reused a copy reuse the kept video, and tags in texts that named a copy name
 * the kept video. Their counts of plays are added to those of the kept video.
 *
 * ~~~~~
 * $duplicates = $modules->get('FieldtypeSelfHostedVideo')->duplicates();
 * foreach($duplicates->groups() as $group) {
 *   echo count($group['rows']) . " × " . $group['filesize'] . " bytes\n";
 * }
 * $duplicates->merge($keep, $copy);
 * ~~~~~
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoDuplicates extends Wire {

	/**
	 * @var FieldtypeSelfHostedVideo
	 *
	 */
	protected $fieldtype;

	/**
	 * Groups found in this request (null: not looked for yet)
	 *
	 * @var array|null
	 *
	 */
	protected $groups = null;

	/**
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 *
	 */
	public function __construct(FieldtypeSelfHostedVideo $fieldtype) {
		parent::__construct();
		$this->fieldtype = $fieldtype;
	}

	/**
	 * Get the groups of videos with the same file, among the videos the current user may see
	 *
	 * @return array Keyed by checksum, each with `rows` (as SelfHostedVideoFinder finds them, oldest
	 *   first), `filesize` (bytes of one file) and `spare` (bytes that merging would free)
	 *
	 */
	public function groups() {

		if($this->groups !== null) return $this->groups;

		$this->groups = array();
		$finder = $this->fieldtype->finder();
		$bySize = array();

		foreach($finder->allRows() as $row) {
			if($row['filesize'] > 0) $bySize[$row['filesize']][] = $row;
		}

		foreach($bySize as $size => $rows) {
			if(count($rows) < 2) continue;
			$byHash = array();
			foreach($rows as $row) {
				$video = $finder->getVideo($row);
				$hash = $video ? $this->checksum($video) : '';
				if(strlen($hash)) $byHash[$hash][] = $row;
			}
			foreach($byHash as $hash => $same) {
				if(count($same) < 2) continue;
				usort($same, function($a, $b) {
					if($a['created'] !== $b['created']) return $a['created'] < $b['created'] ? -1 : 1;
					return $a['pages_id'] - $b['pages_id'];
				});
				$this->groups[$hash] = array(
					'rows' => $same,
					'filesize' => (int) $size,
					'spare' => (int) $size * (count($same) - 1),
				);
			}
		}

		return $this->groups;
	}

	/**
	 * Get the keys ("page ID/file name") of the videos that have the same file as another: key => checksum
	 *
	 * @return array
	 *
	 */
	public function keys() {
		$out = array();
		foreach($this->groups() as $hash => $group) {
			foreach($group['rows'] as $row) $out[$row['pages_id'] . '/' . $row['name']] = $hash;
		}
		return $out;
	}

	/**
	 * Get the other videos with the same file as this one
	 *
	 * @param Pagevideo $video An original
	 * @return Pagevideo[]
	 *
	 */
	public function forVideo(Pagevideo $video) {
		$out = array();
		if($video->isReference() || !$video->page || !$video->page->id) return $out;
		$keys = $this->keys();
		$key = $video->page->id . '/' . $video->basename();
		if(!isset($keys[$key])) return $out;
		$finder = $this->fieldtype->finder();
		foreach($this->groups[$keys[$key]]['rows'] as $row) {
			if($row['pages_id'] . '/' . $row['name'] === $key) continue;
			$other = $finder->getVideo($row);
			if($other) $out[] = $other;
		}
		return $out;
	}

	/**
	 * Get the checksum of a video file (kept until the file changes)
	 *
	 * @param Pagevideo $video
	 * @return string SHA-1, or blank where the file cannot be read
	 *
	 */
	public function checksum(Pagevideo $video) {
		if($video->isReference()) return '';
		$filename = $video->filename();
		if(!strlen($filename) || !is_file($filename) || !is_readable($filename)) return '';
		clearstatcache(true, $filename);
		$cache = $this->wire()->cache;
		$key = 'SelfHostedVideo.sha1.' . md5($filename . '|' . filesize($filename) . '|' . filemtime($filename));
		$hash = (string) $cache->get($key);
		if(strlen($hash) === 40) return $hash;
		$hash = (string) @hash_file('sha1', $filename);
		if(strlen($hash) === 40) $cache->save($key, $hash, WireCache::expireNever);
		return strlen($hash) === 40 ? $hash : '';
	}

	/**
	 * Can this copy be merged into the kept video by the current user? Says why not.
	 *
	 * @param Pagevideo $keep
	 * @param Pagevideo $copy
	 * @return string Blank when it can, or the reason
	 *
	 */
	public function whyNot(Pagevideo $keep, Pagevideo $copy) {
		if($keep->isReference() || $copy->isReference()) return $this->_('a reused video');
		if($keep === $copy || ($keep->page->id === $copy->page->id && $keep->basename() === $copy->basename())) return $this->_('the same video');
		if($copy->page->isTrash() || $keep->page->isTrash()) return $this->_('on a page in the trash');
		// the kept video has to be seen wherever the copy was seen
		$finder = $this->fieldtype->finder();
		if($finder->editPage($keep->page)->isUnpublished() && !$finder->editPage($copy->page)->isUnpublished()) return $this->_('the video to keep is on an unpublished page');
		$a = $this->checksum($keep);
		if(!strlen($a) || $a !== $this->checksum($copy)) return $this->_('not the same file');
		$editPage = $finder->editPage($copy->page);
		if(!$editPage->editable() || !$copy->page->editable($copy->field->name)) return $this->_('you may not edit its page');
		foreach($copy->references() as $p) {
			if(!$finder->editPage($p)->editable()) return $this->_('reused on a page you may not edit');
		}
		return '';
	}

	/**
	 * Replace a copy by a reuse of the kept video, and delete the copy's file
	 *
	 * @param Pagevideo $keep The video that stays
	 * @param Pagevideo $copy A video with the same file
	 * @return int Bytes freed (0 when the copy was not merged; see whyNot())
	 * @throws WireException
	 *
	 */
	public function merge(Pagevideo $keep, Pagevideo $copy) {

		$why = $this->whyNot($keep, $copy);
		if(strlen($why)) throw new WireException(sprintf($this->_('“%1$s” cannot be merged: %2$s.'), $copy->basename(), $why));

		$pages = $this->wire()->pages;
		$database = $this->wire()->database;
		$fieldtype = $this->fieldtype;
		$usage = $fieldtype->usage();

		$keepPage = $keep->page;
		$keepName = $keep->basename();
		$keepField = $keep->field;
		$copyPage = $copy->page;
		$copyName = $copy->basename();
		$copyField = $copy->field;
		$bytes = (int) $copy->filesize();

		// what to look at afterwards: the copy's page, the pages that reuse it, the texts that place it
		$touched = array($copyPage->id => $copyPage->id, $keepPage->id => $keepPage->id);
		$textPages = array($copyPage->id);
		foreach($fieldtype->findReferences($copyPage, $copyName) as $row) {
			$touched[(int) $row['pages_id']] = (int) $row['pages_id'];
			$textPages[] = (int) $row['pages_id'];
		}
		$textPages = array_merge($textPages, $usage->textHolders($copyPage->id, $copyName));

		// 1. pages that reuse the copy reuse the kept video instead
		$newKey = Pagevideos::referenceKeyFor($keepPage->id, $keepName);
		foreach($fieldtype->getVideoFields() as $field) {
			$table = $database->escapeTable($field->getTable());
			try {
				$query = $database->prepare("SELECT pages_id, data FROM `$table` WHERE ref_pages_id=:p AND ref_name=:n");
				$query->execute(array(':p' => $copyPage->id, ':n' => $copyName));
				$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
			} catch(\PDOException $e) {
				continue;
			}
			foreach($rows as $row) {
				$pageId = (int) $row['pages_id'];
				// a page that has the kept video in this field already (as its own or reused) needs no second one
				$query = $database->prepare("SELECT COUNT(*) FROM `$table` WHERE pages_id=:id AND (data=:key OR (ref_pages_id=0 AND data=:name AND :same=1))");
				$query->execute(array(':id' => $pageId, ':key' => $newKey, ':name' => $keepName, ':same' => (int) ($pageId === $keepPage->id && $field->id === $keepField->id)));
				if((int) $query->fetchColumn() > 0) {
					$query = $database->prepare("DELETE FROM `$table` WHERE pages_id=:id AND data=:old");
					$query->execute(array(':id' => $pageId, ':old' => $row['data']));
				} else {
					$query = $database->prepare("UPDATE `$table` SET ref_pages_id=:kp, ref_name=:kn, data=:key WHERE pages_id=:id AND data=:old");
					$query->execute(array(':kp' => $keepPage->id, ':kn' => $keepName, ':key' => $newKey, ':id' => $pageId, ':old' => $row['data']));
				}
			}
		}

		// 2. the counts of the copy are the kept video's now
		$fieldtype->stats()->move($copyPage->id, $copyName, $keepPage->id, $keepName);

		// 3. tags in texts that named the copy name the kept video
		$usage->renameTags($textPages, $copyName, $keepName);
		$usage->moveVideo($copyPage->id, $copyName, $keepPage->id, $keepName);

		// 4. on the copy's page, the kept video takes the copy's place
		$this->uncache($touched);
		$copyPage = $pages->getById(array($copyPage->id), array('getOne' => true, 'cache' => false));
		$copyPage->of(false);
		$videos = $copyPage->getUnformatted($copyField->name);
		$copy = $videos instanceof Pagevideos ? $videos->getFile($copyName) : null;
		if(!$copy instanceof Pagevideo) throw new WireException(sprintf($this->_('“%s” was not found on its page.'), $copyName));
		if($copyPage->id !== $keepPage->id || $copyField->id !== $keepField->id) {
			$keep = $this->fieldtype->getOriginal($keepPage->id, $keepName);
			if(!$keep instanceof Pagevideo) throw new WireException(sprintf($this->_('“%s” was not found on its page.'), $keepName));
			$reference = $videos->addReference($keep);
			if($reference !== $keep) $videos->insertBefore($reference, $copy);
		}
		$videos->delete($copy);
		$copyPage->trackChange($copyField->name);
		$copyPage->save($copyField->name);

		// 5. the lists of where videos are placed
		$this->uncache($touched);
		foreach($pages->getById(array_values($touched), array('cache' => false)) as $page) $usage->updatePage($page);
		$this->groups = null;
		$this->fieldtype->finder()->forget();

		return $bytes;
	}

	/**
	 * Forget pages kept in memory, and the originals remembered for them
	 *
	 * @param array $ids
	 *
	 */
	protected function uncache(array $ids) {
		$pages = $this->wire()->pages;
		foreach($ids as $id) {
			$page = $pages->getCache((int) $id);
			if($page instanceof Page) $pages->uncache($page);
			$this->fieldtype->forgetOriginals((int) $id);
		}
	}
}
