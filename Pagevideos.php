<?php namespace ProcessWire;

/**
 * Pagevideos
 *
 * The value of a FieldtypeSelfHostedVideo field: a WireArray of Pagevideo objects.
 * It is a Pagefiles array, so adding, removing, sorting, filtering and finding
 * by tag all work as they do for file fields.
 *
 * ~~~~~
 * // Add a video from a file or URL
 * $page->videos->add('/path/to/intro.mp4');
 * $page->save('videos');
 *
 * // Reuse a video from another page without copying the file
 * $page->videos->addReference($otherPage->videos->first());
 * $page->save('videos');
 *
 * // Videos with a tag
 * foreach($page->videos->findTag('tutorial') as $video) { … }
 * ~~~~~
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 * @method Pagevideos delete($item) Remove a video. Its file is deleted when the page is saved.
 * @method string render(array $options = array()) Render all videos for the website.
 *
 */
class Pagevideos extends Pagefiles {

	/**
	 * Per the WireArray interface, items must be of type Pagevideo
	 *
	 * #pw-internal
	 *
	 * @param mixed $item
	 * @return bool
	 *
	 */
	public function isValidItem($item) {
		return $item instanceof Pagevideo;
	}

	/**
	 * Per the WireArray interface, return a blank Pagevideo
	 *
	 * #pw-internal
	 *
	 * @return Pagevideo
	 *
	 */
	public function makeBlankItem() {
		return $this->wire(new Pagevideo($this, ''));
	}

	/**
	 * Add a video
	 *
	 * - Given a file name or URL, the file is copied into this page’s files and added.
	 * - Given a Pagevideo from another page, its file is copied here as an independent video.
	 * - Given a reference from another page, a reference to the same original is added.
	 *
	 * To reuse a video without copying its file, use addReference() instead.
	 *
	 * #pw-group-manipulation
	 *
	 * @param Pagevideo|string $item
	 * @return $this
	 *
	 */
	public function add($item) {

		$source = null;

		if(is_string($item)) {
			$item = $this->wire(new Pagevideo($this, $item));
		} else if($item instanceof Pagevideo && $item->pagefiles !== $this && !$this->ownsItem($item)) {
			if($item->isReference()) {
				$this->addReference($item);
				return $this;
			}
			$sourcePage = $item->page;
			if($sourcePage && $this->page && "$sourcePage" !== "$this->page") $source = $item; // will be copied
		}

		$result = parent::add($item);

		if($source) {
			// the file was copied here: bring its thumbnail along
			$copy = $this->last();
			if($copy instanceof Pagevideo && $copy !== $source) {
				$thumb = $source->thumb();
				$info = $source->thumbInfo();
				$copy->removeThumb();
				if($thumb) $copy->setThumb($thumb->filename(), array('time' => $info['time'], 'custom' => $info['custom']));
				// and its captions. The copy starts with the details of the source, which name files it does not have.
				$tracks = $source->captions();
				$copy->filedata(false, 'captions');
				foreach($tracks as $lang => $track) {
					try {
						$copy->setCaptions($lang, $track->filename, array('label' => $track->label, 'kind' => $track->kind));
					} catch(WireException $e) {
						// a captions file that cannot be read is left behind
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Reuse a video that lives on another page (or in another video field), without copying its file
	 *
	 * The video file exists once on the server. Title, description, tags, duration and all
	 * other data come from the original and are edited there. Follow up with a page save.
	 *
	 * ~~~~~
	 * $original = $pages->get('/library/')->videos->getFile('intro.mp4');
	 * $page->videos->addReference($original);
	 * $page->save('videos');
	 * ~~~~~
	 *
	 * #pw-group-manipulation
	 * #pw-group-references
	 *
	 * @param Pagevideo $video The video to reuse. If it is itself a reference, its original is used.
	 * @return Pagevideo The reference that was added (or the one already present)
	 * @throws WireException If the given video cannot be reused
	 *
	 */
	public function addReference(Pagevideo $video) {

		$original = $video->isReference() ? $video->original() : $video;

		if(!$original) {
			throw new WireException($this->_('This video cannot be reused because its original no longer exists.'));
		}

		$sourcePage = $original->page;

		if(!$sourcePage || !$sourcePage->id || $original->isNew() && !$this->isSaved($original)) {
			throw new WireException($this->_('A video has to be saved before it can be reused on another page.'));
		}
		if($original->isTemp()) {
			throw new WireException($this->_('A video that is still being uploaded cannot be reused.'));
		}
		if($sourcePage->isTrash()) {
			throw new WireException($this->_('A video on a page in the trash cannot be reused.'));
		}

		// same page and same field: it is already here
		if($this->ownsItem($original)) return $original;

		$key = self::referenceKey($original);
		$existing = $this->getFile($key);
		if($existing instanceof Pagevideo) return $existing;

		/** @var Pagevideo $reference */
		$reference = $this->wire(new Pagevideo($this, $key));
		$reference->setReference($original);

		parent::add($reference);

		return $reference;
	}

	/**
	 * Remove a video
	 *
	 * The file is deleted from disk when the page is saved. Removing a reference never
	 * touches the original. An original that other pages still reuse is not removed:
	 * an error is recorded instead, naming the pages that use it.
	 *
	 * #pw-internal Please use the hookable delete() method for public API
	 *
	 * @param Pagevideo|string $key Video or file name
	 * @return $this
	 * @throws WireException
	 *
	 */
	public function remove($key) {

		$item = is_string($key) ? $this->get($key) : $key;

		if($item instanceof Pagevideo && !$item->isReference() && $this->ownsItem($item)) {
			$usedOn = $item->references();
			if($usedOn->count()) {
				$this->error(sprintf(
					$this->_('Video “%1$s” was not removed because it is used on other pages: %2$s'),
					$item->basename(),
					self::pageList($usedOn)
				));
				return $this;
			}
		}

		return parent::remove($key);
	}

	/**
	 * Queue a rename of a video, which takes place when the page is saved
	 *
	 * #pw-group-manipulation
	 *
	 * @param Pagefile $item
	 * @param string $name New file name
	 * @return Pagefiles
	 *
	 */
	public function rename(Pagefile $item, $name) {
		if($item->pagefiles !== $this) {
			// an object from before the page was last saved: the video meant is the one held here now
			$own = $this->getFile($item->basename());
			if($own instanceof Pagefile) $item = $own;
		}
		// make sure the save hook is in place before the rename is queued, or it would not be carried out
		$this->addSaveHook();
		return parent::rename($item, $name);
	}

	/**
	 * Render all videos for the website, one after the other
	 *
	 * ~~~~~
	 * echo $page->videos->render();
	 * echo $page->videos->render([ 'info' => false ]); // without the box with the custom fields
	 * echo $page->body . $page->videos->render(); // the videos that no text of the page shows with a tag
	 * echo $page->videos->render([ 'includeall' => true ]); // all of them, also those a text shows
	 * ~~~~~
	 *
	 * #pw-group-output
	 *
	 * @param array $options See Pagevideo::render(), and:
	 *  - `once` (bool): Leave out videos that were output earlier in this request, i.e. by a tag
	 *     in a text (see TextformatterSelfHostedVideo)? The text has to be output first. (default=false)
	 * @return string
	 *
	 */
	public function ___render(array $options = array()) {
		$out = array();
		$once = !empty($options['once']);
		unset($options['once']);
		$renderer = null;
		if($once) {
			$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
			$renderer = $fieldtype instanceof FieldtypeSelfHostedVideo ? $fieldtype->markup() : null;
		}
		foreach($this as $video) {
			/** @var Pagevideo $video */
			// a video that a tag has placed in the text of the page is not shown a second time
			if($renderer && $renderer->wasRendered($video)) continue;
			$markup = $video->render($options);
			if(strlen($markup)) $out[] = $markup;
		}
		return implode("\n", $out);
	}

	/**
	 * Get the Open Graph tags of the first video, for the `<head>` of the page
	 *
	 * See Pagevideo::metaTags(). Social networks show one video for a shared page, so this is
	 * the first one that can be shown (a protected video has no tags).
	 *
	 * ~~~~~
	 * // in the <head> of _main.php
	 * if($page->hasField('videos')) echo $page->videos->metaTags();
	 * ~~~~~
	 *
	 * #pw-group-output
	 *
	 * @param array $options See Pagevideo::metaTags()
	 * @return string
	 *
	 */
	public function metaTags(array $options = array()) {
		foreach($this as $video) {
			/** @var Pagevideo $video */
			$out = $video->metaTags($options);
			if(strlen($out)) return $out;
		}
		return '';
	}

	/**
	 * Does the given video belong to the same page and field as this array?
	 *
	 * @param Pagevideo $item
	 * @return bool
	 *
	 */
	protected function ownsItem(Pagevideo $item) {
		$pagefiles = $item->pagefiles;
		if($pagefiles === $this) return true;
		$page = $pagefiles->getPage();
		$field = $pagefiles->getField();
		if(!$page || !$this->page || $page->id !== $this->page->id) return false;
		if($field && $this->field) return $field->id === $this->field->id;
		return $field === $this->field;
	}

	/**
	 * Has the given video been written to the database?
	 *
	 * @param Pagevideo $video
	 * @return bool
	 *
	 */
	protected function isSaved(Pagevideo $video) {
		$field = $video->field;
		$page = $video->page;
		if(!$field || !$page || !$page->id) return false;
		$database = $this->wire()->database;
		$table = $database->escapeTable($field->getTable());
		$query = $database->prepare("SELECT COUNT(*) FROM `$table` WHERE pages_id=:pages_id AND data=:name");
		$query->bindValue(':pages_id', $page->id, \PDO::PARAM_INT);
		$query->bindValue(':name', $video->basename());
		$query->execute();
		$saved = (int) $query->fetchColumn() > 0;
		$query->closeCursor();
		return $saved;
	}

	/**
	 * Get the name under which a reference to the given original is stored
	 *
	 * The name is unique within a page and can never collide with an uploaded file,
	 * i.e. a reference to "intro.mp4" on page 1234 is stored as "intro.ref1234.mp4".
	 *
	 * #pw-internal
	 *
	 * @param Pagevideo $original
	 * @return string
	 *
	 */
	public static function referenceKey(Pagevideo $original) {
		return self::referenceKeyFor($original->page->id, $original->basename());
	}

	/**
	 * Same as referenceKey() but from a page ID and file name
	 *
	 * #pw-internal
	 *
	 * @param int $pageId
	 * @param string $basename
	 * @return string
	 *
	 */
	public static function referenceKeyFor($pageId, $basename) {
		$pageId = (int) $pageId;
		$dot = strrpos($basename, '.');
		if($dot === false) return "$basename.ref$pageId";
		return substr($basename, 0, $dot) . ".ref$pageId" . substr($basename, $dot);
	}

	/**
	 * Readable list of the given pages for use in messages
	 *
	 * #pw-internal
	 *
	 * @param PageArray|array $items
	 * @param int $max Maximum number of pages to name
	 * @return string
	 *
	 */
	public static function pageList($items, $max = 10) {
		$list = array();
		$more = 0;
		foreach($items as $page) {
			/** @var Page $page */
			if(count($list) >= $max) {
				$more++;
				continue;
			}
			$list[] = $page->path();
		}
		$out = implode(', ', $list);
		if($more) $out .= " (+$more)";
		return $out;
	}
}
