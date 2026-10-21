<?php namespace ProcessWire;

/**
 * Video sitemap: all public videos of the site, for search engines
 *
 * The module writes the file `sitemap-videos.xml` into the root folder of the website (unless the
 * module setting "No video sitemap" is checked), in the format Google describes for videos: each public page that shows videos, and for
 * each of its videos the thumbnail, title, description, file, duration, upload date and tags.
 * They are the same as in the structured data of the video (see Pagevideo::structuredData()),
 * so a hook that changes those changes the sitemap too.
 *
 * Left out: pages that guests may not view, unpublished pages and pages without a template file,
 * protected videos, and videos without a thumbnail (search engines require one). On a site with
 * several languages, each language of a page has an entry of its own, with the texts in that language.
 *
 * The file is written again at the end of each request that saved or deleted a page with videos, or
 * changed the module settings. A file of the same name that the module did not write is left alone.
 * Where the file cannot be written, the module answers its address itself (kept for an hour).
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoSitemap extends Wire {

	/**
	 * Address of the sitemap, from the root of the site
	 *
	 */
	const path = 'sitemap-videos.xml';

	/**
	 * Most entries in one sitemap file (the limit of the sitemap format)
	 *
	 */
	const maxEntries = 50000;

	/**
	 * Name of the cached sitemap
	 *
	 */
	const cacheName = 'sitemap-videos';

	/**
	 * Comment in the file that marks it as written by the module
	 *
	 */
	const marker = '<!-- FieldtypeSelfHostedVideo: written by the module, written again when pages with videos change -->';

	/**
	 * @var FieldtypeSelfHostedVideo
	 *
	 */
	protected $fieldtype;

	/**
	 * What the sitemap made last held: [ 'pages' => n, 'videos' => n ]
	 *
	 * @var array
	 *
	 */
	protected $stats = array('pages' => 0, 'videos' => 0);

	/**
	 * Did the last write() change the file?
	 *
	 * @var bool
	 *
	 */
	protected $changed = false;

	/**
	 * Did the last write() change the file? (False when the sitemap was the same as before)
	 *
	 * @return bool
	 *
	 */
	public function changed() {
		return $this->changed;
	}

	/**
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 *
	 */
	public function __construct(FieldtypeSelfHostedVideo $fieldtype) {
		parent::__construct();
		$this->fieldtype = $fieldtype;
	}

	/**
	 * Get the full address of the sitemap
	 *
	 * @return string
	 *
	 */
	public function url() {
		return $this->fieldtype->markup()->absoluteUrl($this->wire()->config->urls->root . self::path);
	}

	/**
	 * Get the path of the sitemap file in the root folder of the website
	 *
	 * @return string
	 *
	 */
	public function filename() {
		return $this->wire()->config->paths->root . self::path;
	}

	/**
	 * Is the file there, and written by the module?
	 *
	 * @return bool
	 *
	 */
	public function isOwnFile() {
		$file = $this->filename();
		if(!is_file($file)) return false;
		$fp = @fopen($file, 'rb');
		if(!$fp) return false;
		$start = (string) fread($fp, 512);
		fclose($fp);
		return strpos($start, self::marker) !== false;
	}

	/**
	 * Is there a file of that name that the module did not write?
	 *
	 * @return bool
	 *
	 */
	public function isForeignFile() {
		return is_file($this->filename()) && !$this->isOwnFile();
	}

	/**
	 * Write the sitemap file
	 *
	 * @return bool False when it could not be written, or when a file of that name is not the module's
	 *
	 */
	public function write() {
		$file = $this->filename();
		if($this->isForeignFile()) {
			$this->wire()->log->save('selfhostedvideo', "Video sitemap not written: $file is not a file of the module");
			return false;
		}
		$this->changed = false;
		$xml = $this->render();
		// the same as before: nothing to write
		if(is_file($file) && @file_get_contents($file) === $xml) return true;
		$tmp = $file . '.' . getmypid() . '.tmp';
		$ok = @file_put_contents($tmp, $xml, LOCK_EX) !== false;
		if($ok) {
			$this->wire()->files->chmod($tmp);
			$ok = @rename($tmp, $file);
		}
		if(!$ok) {
			if(is_file($tmp)) @unlink($tmp);
			$this->wire()->log->save('selfhostedvideo', "Video sitemap could not be written to $file");
			return false;
		}
		$this->forget();
		$this->changed = true;
		return true;
	}

	/**
	 * Remove the sitemap file, if it is the module's
	 *
	 * @return bool True if there is none of the module's now
	 *
	 */
	public function remove() {
		if(!$this->isOwnFile()) return true;
		$this->forget();
		return @unlink($this->filename());
	}

	/**
	 * Get the sitemap: the file when the module wrote it, otherwise made now (and kept for an hour)
	 *
	 * @return string XML
	 *
	 */
	public function get() {
		if($this->isOwnFile()) {
			$xml = @file_get_contents($this->filename());
			if(is_string($xml) && strlen($xml)) return $xml;
		}
		$cache = $this->wire()->cache;
		$name = self::cacheName . '-' . md5($this->url());
		$xml = $cache->getFor($this->fieldtype, $name);
		if(is_string($xml) && strlen($xml)) return $xml;
		$xml = $this->render();
		$cache->saveFor($this->fieldtype, $name, $xml, 3600);
		return $xml;
	}

	/**
	 * Forget the kept sitemap, so that the next request makes it again
	 *
	 */
	public function forget() {
		// the module keeps nothing else in its own part of the cache
		$this->wire()->cache->deleteFor($this->fieldtype);
	}

	/**
	 * Make the sitemap
	 *
	 * @return string XML
	 *
	 */
	public function render() {

		$entries = array();
		$this->stats = array('pages' => 0, 'videos' => 0);
		$pageIds = array();
		$videos = array();
		foreach($this->entries() as $entry) {
			$entries[] = $this->renderEntry($entry);
			$pageIds[$entry['loc']] = true;
			foreach($entry['videos'] as $data) $videos[$data['contentUrl']] = true;
			$this->stats = array('pages' => count($pageIds), 'videos' => count($videos));
			if(count($entries) >= self::maxEntries) break;
		}

		return
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
			self::marker . "\n" .
			"<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:video=\"http://www.google.com/schemas/sitemap-video/1.1\">\n" .
			implode('', $entries) .
			"</urlset>\n";
	}

	/**
	 * What the sitemap made last holds
	 *
	 * @return array `pages` (addresses) and `videos` (different video files)
	 *
	 */
	public function stats() {
		return $this->stats;
	}

	/**
	 * Get the entries of the sitemap: a page address with the videos shown there
	 *
	 * @return array Each with `loc` (string) and `videos` (array of structured data, see Pagevideo::structuredData())
	 *
	 */
	public function entries() {

		$pages = $this->wire()->pages;
		$languages = $this->wire()->languages;
		$user = $this->wire()->user;
		$multi = $languages && $this->wire()->modules->isInstalled('LanguageSupportPageNames') && $languages->count() > 1;
		$entries = array();
		$locs = array();
		$fields = $this->fieldtype->getVideoFields();
		if(!count($fields)) return $entries;

		$ids = $this->pageIds($fields);
		$userLanguage = $user ? $user->language : null;

		foreach(array_chunk($ids, 100) as $chunk) {
			foreach($pages->getById($chunk) as $page) {
				/** @var Page $page */
				if(!$this->isListed($page)) continue;
				// one address per page: the texts of the default language, whoever made the sitemap
				$list = $multi ? $languages : array($languages ? $languages->getDefault() : null);
				foreach($list as $language) {
					/** @var Language|null $language */
					if($multi && !$page->viewable($language, false)) continue;
					if($language) $user->language = $language;
					$videos = $this->pageVideos($page, $fields);
					$loc = $multi ? $page->localHttpUrl($language) : $page->httpUrl();
					// a language without an address of its own for the page has the address of another
					if(count($videos) && !isset($locs[$loc])) {
						$locs[$loc] = true;
						$entries[] = array('loc' => $loc, 'videos' => $videos);
					}
				}
				if($userLanguage) $user->language = $userLanguage;
				if($page->id !== $this->wire()->page->id) $pages->uncache($page);
				if(count($entries) >= self::maxEntries) break 2;
			}
		}

		if($userLanguage) $user->language = $userLanguage;

		return $entries;
	}

	/**
	 * Get the IDs of all pages that have videos of their own or reused ones
	 *
	 * @param Field[] $fields
	 * @return array
	 *
	 */
	protected function pageIds(array $fields) {
		$database = $this->wire()->database;
		$ids = array();
		foreach($fields as $field) {
			$table = $database->escapeTable($field->getTable());
			try {
				$query = $database->prepare("SELECT DISTINCT pages_id FROM `$table`");
				$query->execute();
				while($id = $query->fetchColumn()) $ids[(int) $id] = (int) $id;
				$query->closeCursor();
			} catch(\Exception $e) {
				// a field whose table is missing has no videos
			}
		}
		ksort($ids);
		return array_values($ids);
	}

	/**
	 * May this page be in the sitemap: public, published, with a template file, not in the trash?
	 *
	 * @param Page $page
	 * @return bool
	 *
	 */
	public function isListed(Page $page) {
		if(!$page->id || $page->isTrash() || $page->isUnpublished()) return false;
		if(!$page->template || !$page->template->filenameExists()) return false;
		if($page->template->name === 'admin') return false;
		// what guests may see; the one asking may be signed in
		return $page->isPublic();
	}

	/**
	 * Get the structured data of the videos of a page that belong in the sitemap
	 *
	 * @param Page $page
	 * @param Field[] $fields
	 * @return array
	 *
	 */
	protected function pageVideos(Page $page, array $fields) {
		$out = array();
		$seen = array();
		$of = $page->of();
		$page->of(true);
		foreach($fields as $field) {
			if(!$page->hasField($field)) continue;
			$value = $page->get($field->name);
			if($value instanceof Pagevideo) $value = array($value);
			if(!$value instanceof Pagevideos && !is_array($value)) continue;
			foreach($value as $video) {
				if(!$video instanceof Pagevideo) continue;
				if($video->isBroken() || $video->isProtected()) continue;
				$data = $video->structuredData();
				if(empty($data['contentUrl']) || empty($data['thumbnailUrl'])) continue;
				if(isset($seen[$data['contentUrl']])) continue;
				$seen[$data['contentUrl']] = true;
				$data['seconds'] = (int) round((float) $video->duration());
				$out[] = $data;
			}
		}
		$page->of($of);
		return $out;
	}

	/**
	 * Render one entry of the sitemap
	 *
	 * @param array $entry
	 * @return string
	 *
	 */
	protected function renderEntry(array $entry) {

		$out = "\t<url>\n\t\t<loc>" . $this->xml($entry['loc']) . "</loc>\n";

		foreach($entry['videos'] as $data) {
			$thumb = is_array($data['thumbnailUrl']) ? reset($data['thumbnailUrl']) : (string) $data['thumbnailUrl'];
			$title = isset($data['name']) ? (string) $data['name'] : '';
			// a description is required: the title stands in for it
			$description = isset($data['description']) && strlen((string) $data['description']) ? (string) $data['description'] : $title;
			$out .= "\t\t<video:video>\n";
			$out .= "\t\t\t<video:thumbnail_loc>" . $this->xml($thumb) . "</video:thumbnail_loc>\n";
			$out .= "\t\t\t<video:title>" . $this->xml($this->cut($title, 100)) . "</video:title>\n";
			$out .= "\t\t\t<video:description>" . $this->xml($this->cut($description, 2048)) . "</video:description>\n";
			$out .= "\t\t\t<video:content_loc>" . $this->xml($data['contentUrl']) . "</video:content_loc>\n";
			$seconds = isset($data['seconds']) ? (int) $data['seconds'] : 0;
			if($seconds > 0) $out .= "\t\t\t<video:duration>" . min(28800, max(1, $seconds)) . "</video:duration>\n";
			if(!empty($data['uploadDate'])) $out .= "\t\t\t<video:publication_date>" . $this->xml($data['uploadDate']) . "</video:publication_date>\n";
			if(!empty($data['keywords'])) {
				$tags = array_slice(array_filter(array_map('trim', explode(',', (string) $data['keywords']))), 0, 32);
				foreach($tags as $tag) $out .= "\t\t\t<video:tag>" . $this->xml($tag) . "</video:tag>\n";
			}
			$out .= "\t\t</video:video>\n";
		}

		return $out . "\t</url>\n";
	}

	/**
	 * Shorten a text to a number of characters, at a space where there is one
	 *
	 * @param string $text
	 * @param int $max
	 * @return string
	 *
	 */
	protected function cut($text, $max) {
		$text = trim(preg_replace('/\s+/u', ' ', (string) $text));
		if(mb_strlen($text) <= $max) return $text;
		$text = mb_substr($text, 0, $max - 1);
		$space = mb_strrpos($text, ' ');
		if($space !== false && $space > $max / 2) $text = mb_substr($text, 0, $space);
		return rtrim($text, ' ,.;:') . '…';
	}

	/**
	 * Escape a text for XML
	 *
	 * @param string $text
	 * @return string
	 *
	 */
	protected function xml($text) {
		return htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
