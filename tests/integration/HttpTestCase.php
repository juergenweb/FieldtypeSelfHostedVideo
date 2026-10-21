<?php namespace ProcessWire\SelfHostedVideoTests;

require_once dirname(__DIR__) . '/unit/WriteTestCase.php';

/**
 * Base of the tests that ask the running website over HTTP, as a visitor or a player does.
 *
 * They need the address of the website in the environment variable SHV_TEST_URL (for example http://webseite2.test/)
 * and, as the other tests that save pages, SHV_TEST_WRITE=1. They make a template file site/templates/shvtest.php
 * (removed at the end) so that the test pages can be opened. Without these they are skipped.
 */
abstract class HttpTestCase extends WriteTestCase {

	protected static $templateFile = '';

	public static function setUpBeforeClass(): void {
		if(!strlen((string) getenv('SHV_TEST_URL'))) self::markTestSkipped('Asks the website over HTTP: set the environment variable SHV_TEST_URL (for example http://webseite2.test/).');
		parent::setUpBeforeClass();
		$config = \ProcessWire\wire('config');
		self::$templateFile = $config->paths->templates . TestSite::prefix . '.php';
		// (a run that was stopped before it could clean up leaves the file of the tests behind: that one is taken away)
		if(is_file(self::$templateFile) && strpos((string) file_get_contents(self::$templateFile), 'shvtest-marker') !== false) @unlink(self::$templateFile);
		if(is_file(self::$templateFile)) {
			TestSite::end();
			self::markTestSkipped('The file ' . self::$templateFile . ' exists already: it is not the tests’, so they do not touch it.');
		}
		file_put_contents(self::$templateFile, '<?php namespace ProcessWire; /* shvtest-marker: made by the tests of FieldtypeSelfHostedVideo, removed by them */ ?><!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= $page->title ?></title></head><body><main><?= $page->shvtest_body ?><?= $page->shvtest_videos->render() ?></main></body></html>');
		self::forgetTemplateFile();
	}

	/** The template has been asked for its file before it existed: ask again */
	protected static function forgetTemplateFile() {
		$template = \ProcessWire\wire('templates')->get(TestSite::prefix);
		if(!$template) return;
		$property = new \ReflectionProperty($template, 'filenameExists');
		$property->setAccessible(true);
		$property->setValue($template, null);
	}

	public static function tearDownAfterClass(): void {
		if(self::$templateFile && is_file(self::$templateFile) && strpos((string) file_get_contents(self::$templateFile), 'shvtest-marker') !== false) @unlink(self::$templateFile);
		self::$templateFile = '';
		parent::tearDownAfterClass();
	}

	protected function baseUrl() {
		return rtrim((string) getenv('SHV_TEST_URL'), '/');
	}

	/**
	 * Ask the website
	 *
	 * @param string $method
	 * @param string $path Path on the website (or a whole address)
	 * @param array $headers Header lines by name
	 * @param string|null $body
	 * @return array status, headers (names in lower case), body
	 */
	protected function http($method, $path, array $headers = array(), $body = null) {
		$url = preg_match('~^https?://~i', $path) ? $path : $this->baseUrl() . '/' . ltrim($path, '/');
		$lines = array();
		$headers += array('User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
		foreach($headers as $name => $value) $lines[] = "$name: $value";
		$context = stream_context_create(array('http' => array(
			'method' => $method,
			'header' => implode("\r\n", $lines),
			'content' => $body === null ? '' : $body,
			'ignore_errors' => true,
			'follow_location' => 0,
			'timeout' => 30,
		)));
		$text = @file_get_contents($url, false, $context);
		if($text === false && empty($http_response_header)) {
			$this->fail("The website does not answer at $url");
		}
		$status = 0;
		$out = array();
		$cookies = array();
		foreach((array) $http_response_header as $line) {
			if(preg_match('~^HTTP/\S+ (\d+)~', $line, $m)) { $status = (int) $m[1]; $out = array(); $cookies = array(); continue; }
			if(strpos($line, ':') !== false) {
				list($k, $v) = explode(':', $line, 2);
				$out[strtolower(trim($k))] = trim($v);
				if(strtolower(trim($k)) === 'set-cookie') $cookies[] = trim($v);
			}
		}
		return array('url' => $url, 'status' => $status, 'headers' => $out, 'cookies' => $cookies, 'body' => (string) $text);
	}

	/** What the website says about a page, to help find out why it is not shown (for the message of a failed test) */
	protected function describe($page, array $r = array()) {
		$wire = \ProcessWire\wire();
		$page = TestSite::fresh($page);
		$guest = $wire->users->get('guest');
		$was = $wire->user;
		$wire->users->setCurrentUser($guest);
		$viewable = $page->viewable(false) ? 'yes' : 'no';
		$wire->users->setCurrentUser($was);
		$info = array(
			"GET " . (isset($r['url']) ? $r['url'] : '?'),
			'status ' . (isset($r['status']) ? $r['status'] : '?'),
			'page status ' . $page->status,
			'template file ' . ($page->template->filenameExists() ? 'exists' : 'MISSING') . ' (' . $page->template->filename() . ')',
			'viewable by guests: ' . $viewable,
			'path ' . $page->path . ', url ' . $page->url,
		);
		if($wire->languages) {
			foreach($wire->languages as $language) {
				$info[] = 'language ' . $language->name . ($language->isDefault() ? ' (default)' : '') . ': page name "' . $page->get($language->isDefault() ? 'name' : 'name' . $language->id) . '", status ' . ($language->isDefault() ? 'always' : (int) $page->get('status' . $language->id)) . ', url ' . $this->localUrl($page, $language);
			}
		}
		$info[] = 'answer: ' . substr(trim(preg_replace('/\s+/', ' ', strip_tags(isset($r['body']) ? $r['body'] : ''))), 0, 150);
		return implode("\n    ", $info);
	}

	/**
	 * No PHP warning or error of this module in the page. (A website in debug mode shows its debug bar and the notices of other
	 * modules in every page: only what names this module counts.)
	 */
	protected function assertNoModuleError($body) {
		// (a message of PHP is "Warning: text in /path/FieldtypeSelfHostedVideo/File.php on line 5", with a colon, in plain text or with the
		// <b> of html_errors: the debug bar of a website in debug mode lists hooks called "Warning()" in tables, and that is not one)
		preg_match_all('/\b(?:Warning|Notice|Deprecated|Fatal error|Parse error)(?:<\/b>)?:\s[^\n]{0,600}?FieldtypeSelfHostedVideo[^\n]{0,200}/i', $body, $found);
		// (the debug bar also lists errors of earlier requests, among them those of the tests themselves: they are not the page's)
		$found = array_values(array_filter($found[0], function($line) { return !preg_match('~tests[\\\\/]+(?:unit|integration)~', $line); }));
		$this->assertSame(array(), array_map(function($l) { return substr($l, 0, 250); }, $found), 'warnings of the module in the page');
	}

	/**
	 * What the template of the tests puts in <main>: the rest of a page (layout, debug bar of a website in debug mode)
	 * is of the website, not of the module
	 */
	protected function mainOf($body) {
		return preg_match('~<main>(.*?)</main>~s', $body, $m) ? $m[1] : $body;
	}

	/** The page without the debug bar of a website in debug mode (Tracy), which lists logs, hooks and traces of the whole request */
	protected function withoutDebugBar($body) {
		$cut = strlen($body);
		foreach(array('id="tracy-debug"', "id='tracy-debug'", 'Tracy.Debug.init', 'tracy-debug') as $marker) {
			$at = strpos($body, $marker);
			if($at !== false && $at < $cut) $cut = $at;
		}
		return substr($body, 0, $cut);
	}

	/** A few words around the first place a text is found, for the message of a failed test */
	protected function around($body, $needle, $width = 160) {
		$at = strpos($body, $needle);
		if($at === false) return '';
		return trim(preg_replace('/\s+/', ' ', strip_tags(substr($body, max(0, $at - $width), $width * 2))));
	}

	protected function localUrl($page, $language) {
		try {
			return (string) $page->localUrl($language);
		} catch(\Exception $e) {
			return '(no address per language)';
		}
	}

	/** A published page with one video; the video's file name is clip.mp4 */
	protected function pageWithVideo($name, $seed = 'HTTP', array $media = array(), $body = '') {
		return TestSite::page($name, function($page) use($seed, $media, $body) {
			TestSite::videos($page)->add(TestSite::file('clip.mp4', $seed, $media));
			if(strlen($body)) $page->shvtest_body = $body;
		});
	}

	/** Give the video of a page a thumbnail (the sitemap leaves out videos without one) */
	protected function withThumb($page) {
		$page = TestSite::fresh($page);
		$file = sys_get_temp_dir() . '/shvtest-thumb-' . $page->id . '.png';
		file_put_contents($file, MediaFiles::png(8));
		TestSite::videos($page)->first()->setThumb($file, array('custom' => true));
		$page->save();
		@unlink($file);
		return TestSite::fresh($page);
	}

	protected function unpublish($page) {
		$page = TestSite::fresh($page);
		$page->addStatus(\ProcessWire\Page::statusUnpublished);
		$page->save();
		return TestSite::fresh($page);
	}

	/**
	 * Path of a page on the website. (Not $page->url: this script does not run under the address of the website,
	 * so ProcessWire does not know the folder the website may be in.)
	 */
protected function pagePath($page) {
		// the address with the language of the website, if it has one, and without the folder this script thinks it is in
		$root = rtrim(\ProcessWire\wire('config')->urls->root, '/');
		$url = $page->url;
		// a page made by a script is active in the default language only; the user of the script may speak another one
		$languages = \ProcessWire\wire('languages');
		if($languages) {
			$local = $this->localUrl($page, $languages->getDefault());
			if(strlen($local) && $local[0] === '/') $url = $local;
		}
		return strlen($root) && strpos($url, $root . '/') === 0 ? substr($url, strlen($root)) : $url;
	}

	/** Path of the file of a video, as the website sends it (a protected file is asked for without the dash of its folder) */
	protected function filePath($video) {
		$root = str_replace('\\', '/', \ProcessWire\wire('config')->paths->root);
		$file = str_replace('\\', '/', $video->filename());
		$path = '/' . ltrim(substr($file, strlen($root)), '/');
		return preg_replace('~/files/-(\d)~', '/files/$1', $path);
	}
}
