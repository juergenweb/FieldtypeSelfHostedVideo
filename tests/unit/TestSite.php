<?php namespace ProcessWire\SelfHostedVideoTests;

use ProcessWire\Page;

require_once __DIR__ . '/MediaFiles.php';

/**
 * A small site of its own for the tests that save pages: two fields (video, text), a template and
 * pages named shvtest-… under the home page. Everything is made when the tests start and removed
 * when they end, also what an earlier run left behind.
 *
 * These tests write to the database of the website, so they run only when the environment variable
 * SHV_TEST_WRITE is set to 1 (see README).
 */
class TestSite {

	const prefix = 'shvtest';

	protected static $users = 0;
	protected static $was = null;
	protected static $dir = '';
	protected static $sitemap = null;

	public static function enabled() {
		return getenv('SHV_TEST_WRITE') === '1';
	}

	public static function begin() {
		$wire = \ProcessWire\wire();
		if(!$wire->modules->isInstalled('TextformatterSelfHostedVideo')) {
			\PHPUnit\Framework\TestCase::markTestSkipped('The text formatter of the module is not installed.');
		}
		if(self::$users++ > 0) return;
		self::$was = $wire->user;
		// the module writes the sitemap of videos again when pages with videos change, here with the addresses of a script
		// from the command line: the file is put back as it was
		$sitemap = $wire->modules->get('FieldtypeSelfHostedVideo')->sitemap()->filename();
		self::$sitemap = array($sitemap, is_file($sitemap) ? file_get_contents($sitemap) : null);
		$super = $wire->users->find('roles=superuser, limit=1')->first();
		if(!$super || !$super->id) \PHPUnit\Framework\TestCase::markTestSkipped('No superuser to save pages as.');
		$wire->users->setCurrentUser($super);
		self::$dir = sys_get_temp_dir() . '/shvtest-' . getmypid() . '/';
		@mkdir(self::$dir);
		self::remove();
		$wire->modules->get('FieldtypeSelfHostedVideo')->stats()->install();
		$wire->modules->get('FieldtypeSelfHostedVideo')->usage()->install();

		$fields = $wire->fields;
		$video = new \ProcessWire\Field();
		$video->type = $wire->modules->get('FieldtypeSelfHostedVideo');
		$video->name = self::prefix . '_videos';
		$video->label = 'Test videos';
		$fields->save($video);
		$body = new \ProcessWire\Field();
		$body->type = $wire->modules->get('FieldtypeTextarea');
		$body->name = self::prefix . '_body';
		$body->label = 'Test text';
		$body->textformatters = array('TextformatterSelfHostedVideo');
		$fields->save($body);
		$group = new \ProcessWire\Fieldgroup();
		$group->name = self::prefix;
		$group->add($fields->get('title'));
		$group->add($video);
		$group->add($body);
		$group->save();
		$template = new \ProcessWire\Template();
		$template->name = self::prefix;
		$template->fieldgroup = $group;
		$template->save();
	}

	public static function end() {
		if(--self::$users > 0) return;
		self::$users = 0;
		self::remove();
		$wire = \ProcessWire\wire();
		if(self::$sitemap) {
			// put back now, and once more after the module’s own write at the end of the script
			self::restoreSitemap(self::$sitemap);
			register_shutdown_function(array(__CLASS__, 'restoreSitemap'), self::$sitemap);
			self::$sitemap = null;
		}
		if(self::$was) $wire->users->setCurrentUser(self::$was);
		if(self::$dir && is_dir(self::$dir)) {
			foreach(glob(self::$dir . '*') as $file) @unlink($file);
			@rmdir(self::$dir);
		}
	}

	public static function restoreSitemap(array $sitemap) {
		list($file, $content) = $sitemap;
		if($content !== null) {
			@file_put_contents($file, $content);
		} else if(is_file($file) && strpos((string) file_get_contents($file, false, null, 0, 512), '<!-- FieldtypeSelfHostedVideo') !== false) {
			@unlink($file);
		}
	}

	/**
	 * Take away pages, template and fields of the tests (also those of an interrupted run)
	 */
	protected static function remove() {
		$wire = \ProcessWire\wire();
		self::wipe();
		$template = $wire->templates->get(self::prefix);
		if($template) $wire->templates->delete($template);
		$group = $wire->fieldgroups->get(self::prefix);
		if($group) $wire->fieldgroups->delete($group);
		foreach(array('_videos', '_body') as $suffix) {
			$field = $wire->fields->get(self::prefix . $suffix);
			if($field) $wire->fields->delete($field);
		}
	}

	/**
	 * Delete all pages of the tests, with what is kept about them
	 */
	public static function wipe() {
		$wire = \ProcessWire\wire();
		$template = $wire->templates->get(self::prefix);
		if(!$template) return;
		$table = 'field_' . self::prefix . '_videos';
		try {
			$wire->database->exec("DELETE FROM `$table` WHERE ref_pages_id>0");
		} catch(\PDOException $e) {
		}
		$wire->pages->uncacheAll();
		foreach($wire->pages->find('template=' . self::prefix . ', include=all') as $page) {
			$wire->modules->get('FieldtypeSelfHostedVideo')->stats()->remove($page->id);
			$wire->pages->delete($page, true);
		}
		$wire->pages->uncacheAll();
	}

	/**
	 * A video file to add to a page: tiny MP4 whose contents depend on $seed (the same seed, the same file)
	 *
	 * @return string Path of the file
	 */
	public static function file($name, $seed = 'ONE', array $media = array()) {
		$chunk = str_pad(substr($seed, 0, 14), 14, '.');
		file_put_contents(self::$dir . $name, MediaFiles::mp4($media + array('chunk' => $chunk))['bytes']);
		return self::$dir . $name;
	}

	/**
	 * Save a new page, as it is built by the callback
	 *
	 * @param string $name Page name without the prefix
	 * @param callable $build Gets the page (output formatting off)
	 * @return Page
	 */
	public static function page($name, $build = null, $title = '') {
		$pages = \ProcessWire\wire('pages');
		$page = $pages->add(self::prefix, '/', array('name' => self::prefix . '-' . $name, 'title' => strlen($title) ? $title : "Test $name"));
		$page->of(false);
		if($build) $build($page);
		$page->save();
		return $pages->getById(array($page->id), array('getOne' => true, 'cache' => false));
	}

	public static function fresh($page) {
		$pages = \ProcessWire\wire('pages');
		$id = $page instanceof Page ? $page->id : (int) $page;
		$pages->uncacheAll();
		$page = $pages->get($id);
		$page->of(false);
		return $page;
	}

	public static function videos(Page $page) {
		return $page->getUnformatted(self::prefix . '_videos');
	}

	public static function body($pageId) {
		$q = \ProcessWire\wire('database')->prepare('SELECT data FROM field_' . self::prefix . '_body WHERE pages_id=:p');
		$q->execute(array(':p' => (int) $pageId));
		return (string) $q->fetchColumn();
	}

	public static function plays($pageId, $name) {
		$q = \ProcessWire\wire('database')->prepare('SELECT COALESCE(SUM(plays),0) FROM selfhostedvideo_stats WHERE video_pages_id=:p AND video_name=:n');
		$q->execute(array(':p' => (int) $pageId, ':n' => $name));
		return (int) $q->fetchColumn();
	}
}
