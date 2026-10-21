<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/HttpTestCase.php';

/**
 * What a visitor and a search engine get from a video with captions, chapters and overlays, and how the tag in a text
 * can change the look of a video.
 */
class WebsiteContentTest extends HttpTestCase {

	protected function videoOf($page) {
		return TestSite::videos(TestSite::fresh($page))->first();
	}

	/**
	 * Do something as a user who writes in the default language. (A website with several languages shows the page in its
	 * default language, but a script saves texts in the language of its user, who may speak another one.)
	 */
	protected function inDefaultLanguage($fn) {
		$languages = \ProcessWire\wire('languages');
		if(!$languages) return $fn();
		$user = \ProcessWire\wire('user');
		$was = $user->language;
		$user->language = $languages->getDefault();
		try {
			return $fn();
		} finally {
			$user->language = $was;
		}
	}

	/** A page with a video of two minutes that has captions in German and English, two chapters and two overlays */
	protected function richPage($name, array $options = array()) {
		return $this->inDefaultLanguage(function() use($name) { return $this->buildRichPage($name); });
	}

	protected function buildRichPage($name) {
		$page = $this->pageWithVideo($name, 'RICH', array('seconds' => 120));
		$video = $this->videoOf($page);
		$de = sys_get_temp_dir() . '/shvtest-' . uniqid() . '.vtt';
		file_put_contents($de, "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nGuten Tag zusammen\n\n00:00:05.000 --> 00:00:08.000\nWillkommen am Bergsee\n");
		$en = sys_get_temp_dir() . '/shvtest-' . uniqid() . '.vtt';
		file_put_contents($en, "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nGood day everybody\n");
		$video->setCaptions('de', $de);
		$video->setCaptions('en', $en);
		$video->setChapters(array(array('time' => 0, 'title' => 'Arrival'), array('time' => 60, 'title' => 'The lake')));
		$video->setOverlays(array(
			array('text' => 'Book a tour', 'url' => '/tours/', 'x' => 90, 'y' => 10),
			array('text' => 'The end', 'when' => 'end'),
		));
		$video->page->save();
		@unlink($de);
		@unlink($en);
		return TestSite::fresh($page);
	}

	protected function jsonLd($body) {
		$this->assertSame(1, preg_match('~<script type=.application/ld\+json.>(.*?)</script>~s', $body, $m), 'the page has structured data');
		$data = json_decode($m[1], true);
		$this->assertTrue(is_array($data), 'it is valid JSON');
		return $data;
	}

	// captions

	public function testCaptionsAreOfferedAsTracksAndFetched() {
		$page = $this->richPage('content-captions');
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$this->assertSame(1, preg_match("~<track [^>]*srclang=.de.[^>]*>~", $r['body'], $m), 'a track in German');
		$this->assertSame(1, preg_match("~<track [^>]*srclang=.en.[^>]*>~", $r['body']), 'and one in English');
		$this->assertSame(1, preg_match('~src=[\'"]([^\'"]+)[\'"]~', $m[0], $src), 'the track names a file');
		$file = $this->http('GET', $this->pathOf(html_entity_decode($src[1])));
		$this->assertSame(200, $file['status'], 'GET ' . $file['url']);
		$this->assertStringStartsWith('WEBVTT', $file['body']);
		$this->assertStringContainsString('Willkommen am Bergsee', $file['body']);
		$this->assertNoModuleError($r['body']);
		$type = $file['headers']['content-type'] ?? '';
		if(!strlen($type)) $this->markTestSkipped('The web server sends .vtt files without a Content-Type (add "AddType text/vtt .vtt" to its settings, as it is for a web server that knows WebVTT).');
		$this->assertStringContainsString('text/vtt', $type);
	}

	public function testTheTranscriptHoldsTheTextOfTheCaptions() {
		$page = $this->richPage('content-transcript');
		$r = $this->http('GET', $this->pagePath($page));
		$main = $this->mainOf($r['body']);
		$this->assertStringContainsString('shv-video__transcript', $main);
		$this->assertTrue(strpos($main, 'Guten Tag zusammen') !== false || strpos($main, 'Good day everybody') !== false, 'in one of the languages');
	}

	public function testTheTranscriptCanBeLeftOutAndTheCaptionsStay() {
		$page = $this->richPage('content-notranscript');
		$video = $this->videoOf($page);
		$html = $video->render(array('transcript' => false, 'includeall' => false));
		$this->assertStringNotContainsString('shv-video__transcript', $html);
		$this->assertStringContainsString('<track', $html, 'the captions stay');
	}

	// chapters and overlays

	public function testChaptersAreListedAndNamedForSearchEngines() {
		$page = $this->richPage('content-chapters');
		$r = $this->http('GET', $this->pagePath($page));
		$main = $this->mainOf($r['body']);
		$this->assertStringContainsString('Arrival', $main);
		$this->assertStringContainsString('The lake', $main);
		$this->assertStringContainsString("data-shv-seek='60'", $main);
		$data = $this->jsonLd($r['body']);
		$this->assertCount(2, $data['hasPart'] ?? array(), 'two parts');
		$this->assertSame('Clip', $data['hasPart'][1]['@type']);
		$this->assertSame('The lake', $data['hasPart'][1]['name']);
		$this->assertEquals(60, $data['hasPart'][1]['startOffset']);
		$this->assertEquals(120, $data['hasPart'][1]['endOffset'], 'the last one ends with the video');
	}

	public function testOverlaysLieOnThePictureWithTheirLink() {
		$page = $this->richPage('content-overlays');
		$r = $this->http('GET', $this->pagePath($page));
		$main = $this->mainOf($r['body']);
		$this->assertStringContainsString('Book a tour', $main);
		$this->assertStringContainsString('The end', $main);
		$this->assertStringContainsString("href='/tours/'", $main);
		$without = $this->videoOf($page)->render(array('overlays' => false));
		$this->assertStringNotContainsString('Book a tour', $without);
	}

	public function testTheStructuredDataDescribesTheVideo() {
		$page = $this->richPage('content-schema');
		$r = $this->http('GET', $this->pagePath($page));
		$data = $this->jsonLd($r['body']);
		$this->assertSame('https://schema.org', preg_replace('~^http:~', 'https:', rtrim((string) ($data['@context'] ?? ''), '/')));
		$this->assertSame('VideoObject', $data['@type']);
		$this->assertNotSame('', (string) ($data['name'] ?? ''));
		$this->assertSame('PT2M', $data['duration'], 'two minutes, in the way search engines read it');
		$this->assertMatchesIso($data['uploadDate'] ?? '');
	}

	protected function assertMatchesIso($date) {
		$this->assertTrue(preg_match('~^\d{4}-\d{2}-\d{2}~', (string) $date) === 1, 'an upload date: ' . $date);
	}

	// the tag in a text

	public function testTheTagCanPutTheVideoBesideTheText() {
		$page = $this->pageWithVideo('content-tag', 'TAG', array(), '<p>Left [[video clip.mp4 align=right width=40]] text</p>');
		$r = $this->http('GET', $this->pagePath($page));
		$main = $this->mainOf($r['body']);
		$this->assertStringContainsString('shv-video-text--right', $main);
		$this->assertStringContainsString('--shv-width:40%', $main);
	}

	public function testTheTagCanLeaveOutTheCaption() {
		foreach(array('' => true, ' nocaption' => false) as $flag => $caption) {
			$page = $this->pageWithVideo('content-cap' . (strlen($flag) ? 'n' : 'y'), 'TAG2', array(), "<p>[[video clip.mp4$flag]]</p>");
			$this->inDefaultLanguage(function() use($page) {
				$video = $this->videoOf($page);
				$video->title = 'A caption that should be seen once';
				$video->page->save();
			});
			$r = $this->http('GET', $this->pagePath($page));
			$main = $this->mainOf($r['body']);
			$this->assertSame(1, substr_count($main, '<video'), 'the video once' . $flag);
			$this->assertSame($caption ? 1 : 0, substr_count($main, '<figcaption'), $caption ? 'with its caption' : 'without a caption');
		}
	}

	public function testTheTagIgnoresValuesItDoesNotKnow() {
		$page = $this->pageWithVideo('content-bad', 'TAG3', array(), '<p>[[video clip.mp4 align=\'"><b>x</b> width=5000 foo=bar]]</p>');
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$main = $this->mainOf($r['body']);
		$this->assertStringNotContainsString('<b>x</b>', $main);
		$this->assertStringNotContainsString('--shv-width:5000', $main);
		$this->assertNoModuleError($r['body']);
	}

	protected function pathOf($address) {
		$path = (string) parse_url($address, PHP_URL_PATH);
		$query = (string) parse_url($address, PHP_URL_QUERY);
		return $path . (strlen($query) ? '?' . $query : '');
	}
}
