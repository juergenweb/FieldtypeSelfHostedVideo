<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Files as browsers and caches ask for them: asking again whether a file has changed, the thumbnail the player and
 * the search engines name, and addresses that must not give anything away.
 */
class WebsiteFilesTest extends HttpTestCase {

	protected function videoOf($page) {
		return TestSite::videos(TestSite::fresh($page))->first();
	}

	/** Address in an attribute of the page ('' when there is none) */
	protected function attribute($body, $name) {
		return preg_match('~\s' . preg_quote($name, '~') . '=[\'"]([^\'"]+)[\'"]~', $body, $m) ? html_entity_decode($m[1]) : '';
	}

	/** Path (with query) of an address the page names, whether it has the host or not */
	protected function pathOf($address) {
		$path = (string) parse_url($address, PHP_URL_PATH);
		$query = (string) parse_url($address, PHP_URL_QUERY);
		return $path . (strlen($query) ? '?' . $query : '');
	}

	// the files of the module

	public function testEveryFileInTheAssetsFolderIsServedWithItsType() {
		$types = array('css' => 'text/css', 'js' => 'javascript', 'svg' => 'image/svg+xml');
		$folder = \ProcessWire\wire('config')->paths->get('FieldtypeSelfHostedVideo') . 'assets/';
		$files = glob($folder . '*.*');
		$this->assertGreaterThan(5, count($files));
		foreach($files as $file) {
			$ext = pathinfo($file, PATHINFO_EXTENSION);
			$r = $this->http('GET', '/site/modules/FieldtypeSelfHostedVideo/assets/' . basename($file));
			$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
			$this->assertSame(filesize($file), strlen($r['body']), basename($file) . ' is sent whole');
			if(isset($types[$ext])) $this->assertStringContainsString($types[$ext], $r['headers']['content-type'] ?? '', basename($file));
		}
	}

	// asking again

	public function testABrowserThatHasTheFileIsToldItHasNotChanged() {
		$video = $this->videoOf($this->pageWithVideo('files-cache'));
		$path = $this->filePath($video);
		$first = $this->http('GET', $path);
		$this->assertSame(200, $first['status'], 'GET ' . $first['url']);
		$etag = $first['headers']['etag'] ?? '';
		$modified = $first['headers']['last-modified'] ?? '';
		if(!strlen($etag) && !strlen($modified)) {
			$this->markTestSkipped('The web server sends the file without ETag and Last-Modified, so a browser cannot ask whether it has changed.');
		}

		if(strlen($etag)) {
			$r = $this->http('GET', $path, array('If-None-Match' => $etag));
			$this->assertSame(304, $r['status'], 'with its ETag');
			$this->assertSame('', $r['body'], 'and no file');
			$r = $this->http('GET', $path, array('If-None-Match' => '"something-else"'));
			$this->assertSame(200, $r['status'], 'another ETag gets the file');
			$this->assertSame(strlen($first['body']), strlen($r['body']));
		}
		if(strlen($modified)) {
			$r = $this->http('GET', $path, array('If-Modified-Since' => $modified));
			$this->assertSame(304, $r['status'], 'with its date');
			$this->assertSame('', $r['body']);
			$r = $this->http('GET', $path, array('If-Modified-Since' => gmdate('D, d M Y H:i:s', strtotime($modified) - 86400) . ' GMT'));
			$this->assertSame(200, $r['status'], 'a date from before the change gets the file');
		}
	}

	public function testAFileThatWasReplacedHasAnotherETag() {
		$page = $this->pageWithVideo('files-etag', 'ONE');
		$video = $this->videoOf($page);
		$path = $this->filePath($video);
		$first = $this->http('GET', $path);
		$etag = $first['headers']['etag'] ?? '';
		if(!strlen($etag)) $this->markTestSkipped('The web server sends the file without an ETag.');
		clearstatcache();
		file_put_contents($video->filename(), file_get_contents($video->filename()) . 'MORE');
		clearstatcache();
		$r = $this->http('GET', $path, array('If-None-Match' => $etag));
		$this->assertSame(200, $r['status'], 'the browser that has the old file gets the new');
		$this->assertNotSame($etag, $r['headers']['etag'] ?? '');
		$this->assertSame('MORE', substr($r['body'], -4));
	}

	// the thumbnail

	public function testThePosterOfThePlayerIsAnImage() {
		$page = $this->withThumb($this->pageWithVideo('files-poster', 'POSTER'));
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$poster = $this->attribute($r['body'], 'poster') ?: $this->attribute($r['body'], 'data-shv-poster');
		$this->assertNotSame('', $poster, 'the player names a thumbnail');
		$image = $this->http('GET', $this->pathOf($poster));
		$this->assertSame(200, $image['status'], 'GET ' . $image['url']);
		$this->assertStringStartsWith('image/', $image['headers']['content-type'] ?? '');
		$this->assertTrue(@getimagesizefromstring($image['body']) !== false, 'and it is a picture');
	}

	public function testTheDataForSearchEnginesNamesTheSameThumbnail() {
		$page = $this->withThumb($this->pageWithVideo('files-schema', 'SCHEMA'));
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		$poster = $this->attribute($r['body'], 'poster') ?: $this->attribute($r['body'], 'data-shv-poster');
		$this->assertSame(1, preg_match('~<script type=.application/ld\+json.>(.*?)</script>~s', $r['body'], $m), 'the page has structured data');
		$data = json_decode($m[1], true);
		$this->assertTrue(is_array($data), 'it is valid JSON');
		$this->assertSame('VideoObject', $data['@type'] ?? '');
		$thumb = is_array($data['thumbnailUrl'] ?? null) ? ($data['thumbnailUrl'][0] ?? '') : ($data['thumbnailUrl'] ?? '');
		$this->assertNotSame('', $thumb, 'it names a thumbnail');
		$this->assertSame($this->pathOf($poster), $this->pathOf($thumb), 'the one the player shows');
		$this->assertSame(200, $this->http('GET', $this->pathOf($thumb))['status'], 'it can be fetched');
		$this->assertTrue(preg_match('~^https?://~', $thumb) === 1, 'with host, as search engines need it');
		$this->assertStringContainsString('/files/' . $page->id . '/', $data['contentUrl'] ?? ($data['embedUrl'] ?? ''), 'and the file is named too');
	}

	public function testAVideoWithoutThumbnailHasNoBrokenPoster() {
		$page = $this->pageWithVideo('files-nothumb', 'NOTHUMB');
		$r = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $r['status'], $r['status'] === 200 ? '' : $this->describe($page, $r));
		foreach(array('poster', 'data-shv-poster') as $name) {
			$address = $this->attribute($r['body'], $name);
			if(!strlen($address)) continue;
			$this->assertSame(200, $this->http('GET', $this->pathOf($address))['status'], "$name $address is found");
		}
	}

	// addresses that give nothing away

	public function testAnAddressWithDotsCannotLeaveTheFolderOfThePage() {
		$video = $this->videoOf($this->pageWithVideo('files-dots'));
		$id = $video->page->id;
		$config = \ProcessWire\wire('config');
		foreach(array(
			"/site/assets/files/$id/..%2f..%2f..%2fconfig.php",
			"/site/assets/files/$id/%2e%2e/%2e%2e/%2e%2e/config.php",
			"/site/assets/files/$id/..%5c..%5c..%5cconfig.php",
			"/site/assets/files/$id/clip.mp4%00.jpg",
			"/site/assets/files/$id/..%2fclip.mp4",
		) as $path) {
			$r = $this->http('GET', $path);
			$this->assertNotSame(200, $r['status'], "GET $path");
			$this->assertStringNotContainsString((string) $config->dbPass !== '' ? (string) $config->dbPass : 'no-password-set', $r['body'], "GET $path");
			$this->assertStringNotContainsString('<?php', $r['body'], "GET $path");
		}
	}

	public function testThePhpFilesOfTheModuleAreNotSent() {
		$base = \ProcessWire\wire('config')->urls->get('FieldtypeSelfHostedVideo');
		$root = rtrim(\ProcessWire\wire('config')->urls->root, '/');
		foreach(array('FieldtypeSelfHostedVideo.module.php', 'SelfHostedVideoStats.php', 'composer.json', 'tests/run.php', 'tests/unit/TestSite.php') as $file) {
			$path = '/site/modules/FieldtypeSelfHostedVideo/' . $file;
			$r = $this->http('GET', $path);
			$this->assertStringNotContainsString('<?php', $r['body'], "GET $path shows no source code");
			$this->assertStringNotContainsString('namespace ProcessWire', $r['body'], "GET $path");
		}
	}
}
