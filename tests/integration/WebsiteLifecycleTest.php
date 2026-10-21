<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/HttpTestCase.php';

/**
 * What the website shows after a video is deleted, renamed, replaced or hidden: the address of its file, the page, the
 * video sitemap, the counted plays and the list of where videos are placed. (The unit tests look at the same things in
 * the database; these ask the website, as visitors and search engines do.)
 */
class WebsiteLifecycleTest extends HttpTestCase {

	protected function videoOf($page) {
		return TestSite::videos(TestSite::fresh($page))->first();
	}

	protected function sitemap() {
		$this->module()->sitemap()->write();
		$r = $this->http('GET', '/sitemap-videos.xml');
		$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
		return $r['body'];
	}

	protected function needSitemap() {
		if(!$this->module()->useSitemap()) $this->markTestSkipped('The video sitemap is switched off in the module settings.');
	}

	protected function count($page, $name = 'clip.mp4') {
		return TestSite::plays($page->id, $name);
	}

	protected function usageRows($pageId) {
		return (int) \ProcessWire\wire('database')->query("SELECT COUNT(*) FROM selfhostedvideo_usage WHERE pages_id=" . (int) $pageId . " OR video_pages_id=" . (int) $pageId)->fetchColumn();
	}

	protected function play($page, $name = 'clip.mp4') {
		return $this->http('POST', '/selfhostedvideo-stats', array('Content-Type' => 'application/x-www-form-urlencoded'), http_build_query(array('v' => "{$page->id}:$name", 'e' => 'play')));
	}

	public function testADeletedPageLeavesNothingBehind() {
		$this->needSitemap();
		$page = $this->withThumb($this->pageWithVideo('life-delete', 'DEL', array(), '<p>[[video clip.mp4]]</p>'));
		$id = $page->id;
		$path = $this->pagePath($page);
		$file = $this->filePath($this->videoOf($page));
		$this->play($page);
		$this->assertSame(200, $this->http('GET', $path)['status']);
		$this->assertSame(200, $this->http('GET', $file)['status']);
		$this->assertStringContainsString("/files/$id/clip.mp4", $this->sitemap());
		$this->assertSame(1, $this->count($page), 'a play is counted');
		$this->assertGreaterThan(0, $this->usageRows($id), 'and the video is placed on its page');

		\ProcessWire\wire('pages')->delete(TestSite::fresh($page), true);

		$this->assertSame(404, $this->http('GET', $path)['status'], 'the page');
		$this->assertSame(404, $this->http('GET', $file)['status'], 'the file');
		$this->assertStringNotContainsString("/files/$id/", $this->sitemap(), 'the sitemap');
		$this->assertSame(0, TestSite::plays($id, 'clip.mp4'), 'the counted plays');
		$this->assertSame(0, $this->usageRows($id), 'the places');
	}

	public function testARemovedVideoIsGoneFromThePageTheFileAndTheSitemap() {
		$this->needSitemap();
		$page = $this->withThumb($this->pageWithVideo('life-remove', 'REM'));
		$path = $this->pagePath($page);
		$file = $this->filePath($this->videoOf($page));
		$this->play($page);
		$this->assertSame(200, $this->http('GET', $file)['status']);

		$page = TestSite::fresh($page);
		TestSite::videos($page)->delete(TestSite::videos($page)->first());
		$page->save();

		$r = $this->http('GET', $path);
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$this->assertStringNotContainsString('<video', $this->mainOf($r['body']), 'no player');
		$this->assertSame(404, $this->http('GET', $file)['status'], 'no file');
		$this->assertStringNotContainsString("/files/{$page->id}/", $this->sitemap(), 'not in the sitemap');
		$this->assertSame(0, $this->count($page), 'no plays kept');
	}

	public function testARenamedVideoIsFoundUnderTheNewNameOnly() {
		$page = $this->pageWithVideo('life-rename', 'REN', array(), '<p>Text [[video clip.mp4 nocaption]]</p>');
		$this->play($page);
		$oldFile = $this->filePath($this->videoOf($page));
		$before = $this->http('GET', $oldFile);
		$this->assertSame(200, $before['status']);

		$page = TestSite::fresh($page);
		TestSite::videos($page)->rename(TestSite::videos($page)->getFile('clip.mp4'), 'new-name.mp4');
		$page->save();

		$this->assertSame(404, $this->http('GET', $oldFile)['status'], 'the old address');
		$new = $this->http('GET', preg_replace('~clip\.mp4$~', 'new-name.mp4', $oldFile));
		$this->assertSame(200, $new['status'], 'GET ' . $new['url']);
		$this->assertSame($before['body'], $new['body'], 'the same file');
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$main = $this->mainOf($r['body']);
		$this->assertStringContainsString('new-name.mp4', $main, 'the player has the new name');
		$this->assertStringNotContainsString('clip.mp4', $main, 'and not the old');
		$this->assertSame(1, substr_count($main, '<video'), 'one player, from the tag in the text');
		$this->assertSame(1, TestSite::plays($page->id, 'new-name.mp4'), 'the plays moved along');
		$this->assertSame(0, TestSite::plays($page->id, 'clip.mp4'));
		$this->assertStringContainsString('[[video new-name.mp4 nocaption]]', TestSite::body($page->id), 'and the tag');
	}

	public function testAReplacedFileIsSentAtTheSameAddress() {
		$page = $this->pageWithVideo('life-replace', 'OLD');
		$video = $this->videoOf($page);
		$file = $this->filePath($video);
		$old = $this->http('GET', $file);
		$this->assertSame(200, $old['status']);

		$new = TestSite::file('other.mp4', 'NEW-CONTENT', array('seconds' => 20));
		$page = TestSite::fresh($page);
		TestSite::videos($page)->first()->replaceFile($new);
		$page->save();

		$r = $this->http('GET', $file);
		$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
		$this->assertNotSame($old['body'], $r['body'], 'the new content');
		$this->assertSame(file_get_contents(TestSite::videos(TestSite::fresh($page))->first()->filename()), $r['body']);
		$this->assertSame(200, $this->http('GET', $this->pagePath($page))['status']);
	}

	public function testHidingAPageTakesItOutOfTheSitemapAndShowingItBringsItBack() {
		$this->needSitemap();
		$page = $this->withThumb($this->pageWithVideo('life-hide', 'HID'));
		$id = $page->id;
		$this->assertStringContainsString("/files/$id/clip.mp4", $this->sitemap());

		$page = $this->unpublish($page);
		$this->assertStringNotContainsString("/files/$id/", $this->sitemap(), 'hidden');
		$this->assertContains($this->http('GET', $this->pagePath($page))['status'], array(404, 403, 401));

		$page = TestSite::fresh($page);
		$page->removeStatus(\ProcessWire\Page::statusUnpublished);
		$page->save();
		$this->assertStringContainsString("/files/$id/clip.mp4", $this->sitemap(), 'and back');
		$this->assertSame(200, $this->http('GET', $this->pagePath($page))['status']);
	}
}
