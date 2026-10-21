<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/HttpTestCase.php';

/**
 * The module as a visitor meets it: the page with the player, the video file, parts of it, the counting of plays,
 * the video sitemap, and pages that must stay closed.
 */
class WebsiteTest extends HttpTestCase {

	protected function videoOf($page) {
		return TestSite::videos(TestSite::fresh($page))->first();
	}

	protected function fileSize($video) {
		clearstatcache();
		return filesize($video->filename());
	}

	// the page

	public function testThePageShowsThePlayerWithItsVideo() {
		$page = $this->pageWithVideo('web-a');
		$video = $this->videoOf($page);
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$this->assertStringContainsString('<video', $r['body']);
		$this->assertStringContainsString('shv-video', $r['body']);
		$this->assertStringContainsString(basename($this->filePath($video)), $r['body'], 'the player has the address of the file');
		$this->assertStringContainsString('data-shv-video=\'' . $page->id . ':clip.mp4\'', $r['body'], 'and what the script reports its plays by');
		$this->assertNoModuleError($r['body']);
	}

	public function testATagInATextShowsTheVideoOnce() {
		$page = $this->pageWithVideo('web-tag', 'HTTP', array(), '<p>Before [[video clip.mp4]] after</p>');
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$main = $this->mainOf($r['body']);
		$this->assertSame(1, substr_count($main, '<video'));
		$this->assertStringContainsString('Before', $main);
		$this->assertStringNotContainsString('[[video', $main, 'the tag is gone');
	}

	public function testATagThatNamesNoVideoShowsNothingAndNoError() {
		$page = $this->pageWithVideo('web-gone', 'HTTP', array(), '<p>[[video missing.mp4]]</p>');
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$main = $this->mainOf($r['body']);
		$this->assertStringNotContainsString('missing.mp4', preg_replace('/<!--.*?-->/s', '', $main), 'at most a comment for the editor');
		$this->assertSame(1, substr_count($main, '<video'), 'only the video of the field');
		$this->assertNoModuleError($r['body']);
	}

	public function testTextsOfAVideoCannotBreakOutOfThePage() {
		$page = $this->pageWithVideo('web-xss');
		$video = $this->videoOf($page);
		$video->title = '</script><script>window.hacked=1</script><img src=x onerror=alert(1)>';
		$video->description = '"><script>window.hacked=2</script>';
		$video->tags = '"><b>x</b>';
		$video->page->save();
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$main = $this->mainOf($r['body']);
		$this->assertStringNotContainsString('<script>window.hacked', $main);
		$this->assertStringNotContainsString('<img src=x', $main);
		$dom = new \DOMDocument();
		@$dom->loadHTML('<!doctype html><meta charset="utf-8">' . $main);
		foreach($dom->getElementsByTagName('img') as $img) $this->assertFalse($img->hasAttribute('onerror'), 'no image of the texts');
		foreach($dom->getElementsByTagName('script') as $script) {
			// (the data for search engines is JSON in a script, where < is written as \u003C)
			if($script->getAttribute('type') === 'application/ld+json') $this->assertStringNotContainsString('<', $script->textContent);
			else $this->assertStringNotContainsString('hacked', $script->textContent);
		}
		foreach($dom->getElementsByTagName('*') as $el) $this->assertFalse($el->hasAttribute('onerror'));
	}

	public function testTheStylesheetAndScriptOfThePlayerAreFound() {
		$page = $this->pageWithVideo('web-assets');
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		foreach(array('css' => 'text/css', 'js' => 'javascript') as $ext => $type) {
			$this->assertSame(1, preg_match('~(?:href|src)=[\'"]([^\'"]*/assets/SelfHostedVideo\.' . $ext . '[^\'"]*)[\'"]~', $r['body'], $m), "the page names assets/SelfHostedVideo.$ext");
			$asset = $this->http('GET', html_entity_decode($m[1]));
			$this->assertSame(200, $asset['status'], 'GET ' . $asset['url']);
			$this->assertStringContainsString($type, $asset['headers']['content-type'] ?? '');
			$this->assertGreaterThan(1000, strlen($asset['body']));
		}
	}

	// the file

	public function testTheFileIsServedWithItsTypeAndSize() {
		$video = $this->videoOf($this->pageWithVideo('web-file'));
		$r = $this->http('GET', $this->filePath($video));
		$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
		$this->assertStringStartsWith('video/mp4', $r['headers']['content-type']);
		$this->assertSame($this->fileSize($video), strlen($r['body']));
		$this->assertSame(file_get_contents($video->filename()), $r['body']);
		$this->assertSame('bytes', strtolower($r['headers']['accept-ranges'] ?? ''), 'a player may ask for parts');
	}

	public function testAPartOfTheFileCanBeAskedFor() {
		$video = $this->videoOf($this->pageWithVideo('web-range'));
		$size = $this->fileSize($video);
		$bytes = file_get_contents($video->filename());
		$r = $this->http('GET', $this->filePath($video), array('Range' => 'bytes=4-13'));
		$this->assertSame(206, $r['status']);
		$this->assertSame("bytes 4-13/$size", $r['headers']['content-range']);
		$this->assertSame(substr($bytes, 4, 10), $r['body']);

		$r = $this->http('GET', $this->filePath($video), array('Range' => 'bytes=-7'));
		$this->assertSame(206, $r['status']);
		$this->assertSame(substr($bytes, -7), $r['body'], 'the last bytes');

		$r = $this->http('GET', $this->filePath($video), array('Range' => 'bytes=' . ($size + 100) . '-'));
		$this->assertSame(416, $r['status'], 'behind the end');
	}

	public function testAFileThatDoesNotExistIsNotFound() {
		$video = $this->videoOf($this->pageWithVideo('web-none'));
		$r = $this->http('GET', $this->filePath($video) . '.nothing');
		$this->assertSame(404, $r['status']);
	}

	public function testThePageAndFileOfAnUnpublishedPageAreClosedToVisitors() {
		$page = $this->unpublish($this->pageWithVideo('web-closed'));
		$video = $this->videoOf($page);
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertContains($r['status'], array(404, 403, 401));
		$this->assertStringNotContainsString('<video', $r['body']);
		if(!\ProcessWire\wire('config')->pagefileSecure) {
			$this->markTestSkipped('This website does not protect the files of pages that are not public ($config->pagefileSecure): its web server sends them to whoever knows the address.');
		}
		$r = $this->http('GET', $this->filePath($video));
		$this->assertNotSame(200, $r['status'], 'the file of the page is not sent');
		$this->assertNotSame(file_get_contents($video->filename()), $r['body']);
	}

	// counting plays

	protected function count($page) {
		return TestSite::plays($page->id, 'clip.mp4');
	}

	protected function report($pageId, $event, $name = 'clip.mp4', array $headers = array()) {
		return $this->http('POST', '/selfhostedvideo-stats', $headers + array('Content-Type' => 'application/x-www-form-urlencoded'), http_build_query(array('v' => "$pageId:$name", 'e' => $event)));
	}

	public function testPlaysAreCountedWhenTheScriptReportsThem() {
		$page = $this->pageWithVideo('web-count');
		$this->assertSame(0, $this->count($page));
		$r = $this->report($page->id, 'play');
		$this->assertSame(204, $r['status']);
		$this->assertSame(1, $this->count($page));
		$this->report($page->id, 'play');
		$this->report($page->id, '50');
		$this->assertSame(2, $this->count($page), 'two plays');
		$this->assertSame(1, (int) \ProcessWire\wire('database')->query("SELECT SUM(p50) FROM selfhostedvideo_stats WHERE video_pages_id={$page->id}")->fetchColumn(), 'and one report of the half');
	}

	public function testRobotsAndNonsenseAreNotCounted() {
		$page = $this->pageWithVideo('web-nocount');
		$this->report($page->id, 'play', 'clip.mp4', array('User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'));
		$this->report($page->id, 'play', 'clip.mp4', array('User-Agent' => 'curl/8.0'));
		$this->report($page->id, 'bogus');
		$this->report($page->id, 'play', 'other.mp4');
		$this->report(99999999, 'play');
		$r = $this->http('POST', '/selfhostedvideo-stats', array('Content-Type' => 'application/x-www-form-urlencoded'), 'v=' . rawurlencode("{$page->id}:clip.mp4") . '&e=');
		$this->assertSame(0, $this->count($page));
		$this->assertSame(204, $r['status'], 'the script always gets the same answer');
	}

	public function testOnlyAPostCounts() {
		$page = $this->pageWithVideo('web-get');
		$r = $this->http('GET', '/selfhostedvideo-stats?v=' . rawurlencode("{$page->id}:clip.mp4") . '&e=play');
		$this->assertNotSame(204, $r['status']);
		$this->assertSame(0, $this->count($page));
	}

	public function testPlaysOfAClosedPageAreNotCounted() {
		$page = $this->unpublish($this->pageWithVideo('web-closed2'));
		$this->report($page->id, 'play');
		$this->assertSame(0, $this->count($page));
	}

	// the video sitemap

	public function testTheSitemapNamesPublishedVideosOnly() {
		if(!$this->module()->useSitemap()) $this->markTestSkipped('The video sitemap is switched off in the module settings.');
		$open = $this->withThumb($this->pageWithVideo('web-site-open', 'SITE1'));
		$closed = $this->unpublish($this->withThumb($this->pageWithVideo('web-site-closed', 'SITE2')));
		$this->module()->sitemap()->write();
		$r = $this->http('GET', '/sitemap-videos.xml');
		$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
		$this->assertStringContainsString('xml', $r['headers']['content-type']);
		$this->assertTrue(@simplexml_load_string($r['body']) !== false, 'it is well-formed XML');
		$this->assertStringContainsString("/files/{$open->id}/clip.mp4", $r['body'], 'the video of the published page');
		$this->assertStringNotContainsString("/files/{$closed->id}/", $r['body'], 'not that of the unpublished one');
		$this->assertStringNotContainsString("/files/-{$closed->id}/", $r['body']);
	}
}
