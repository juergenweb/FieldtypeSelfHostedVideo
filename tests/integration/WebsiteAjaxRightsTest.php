<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/AdminHttpTestCase.php';

/**
 * The requests the page editor's script sends (thumbnail, replace, captions, upload, reuse ...): they change a page
 * only for someone who may edit it and sends the token of the form. Everyone else gets nothing and changes nothing.
 */
class WebsiteAjaxRightsTest extends AdminHttpTestCase {

	protected $page = null;

	protected function setUp(): void {
		parent::setUp();
		$this->page = $this->inDefault(function() {
			$page = $this->pageWithVideo('rights', 'RIGHTS');
			$video = TestSite::videos($page)->first();
			$video->title = 'Secret rights title';
			$page->save();
			return TestSite::fresh($page);
		});
	}

	/** What the page keeps of its video: every file of its folder with a checksum, and what the video knows */
	protected function snapshot() {
		$page = TestSite::fresh($this->page);
		$video = TestSite::videos($page)->first();
		$files = array();
		foreach((array) glob($page->filesPath() . '{,.}*', GLOB_BRACE) as $file) {
			if(is_file($file)) $files[basename($file)] = md5_file($file);
		}
		ksort($files);
		return array(
			'count' => TestSite::videos($page)->count(),
			'files' => $files,
			'captions' => $video ? array_keys($video->captions()) : array(),
			'thumb' => $video && $video->thumb() ? 1 : 0,
			'title' => $video ? $video->title() : '',
		);
	}

	/** The token of the form of the page editor, with the cookies of the user who got it */
	protected function token($who) {
		$r = $this->asUser($who, $this->adminPath('page/edit/?id=' . $this->page->id));
		$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
		$found = preg_match('~<input[^>]*class=[\'"]_post_token[\'"][^>]*name=[\'"]([^\'"]+)[\'"][^>]*value=[\'"]([^\'"]+)[\'"]~', $r['body'], $m)
			|| preg_match('~<input[^>]*name=[\'"](TOKEN[^\'"]+)[\'"][^>]*value=[\'"]([^\'"]+)[\'"]~', $r['body'], $m);
		$this->assertTrue((bool) $found, 'the form has a token');
		return array($m[1], $m[2]);
	}

	/** Send a request of the script. $who null: nobody signed in. $token: the token of the form to send along, or null */
	protected function ajax($who, $action, array $params = array(), $body = '', $token = null) {
		$id = $this->page->id;
		$query = array('id' => $id, 'InputfieldFileAjax' => 1, 'eid' => $id, 'shv' => $action, 'vf_name' => 'clip.mp4') + $params;
		$headers = array('X-FIELDNAME' => 'shvtest_videos', 'X-REQUESTED-WITH' => 'XMLHttpRequest', 'Content-Type' => 'application/octet-stream');
		if($who !== null) $headers['Cookie'] = $this->cookieHeader($who);
		if($token) $headers['X-' . $token[0]] = $token[1];
		$path = $this->adminPath('page/edit/?' . http_build_query($query));
		$r = $this->http('POST', $path, $headers, $body);
		if($who !== null) $this->remember($who, $r['cookies']);
		return $r;
	}

	protected function png() {
		return MediaFiles::png(8);
	}

	protected function vtt() {
		return "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nHello there\n";
	}

	/** The requests that change something, with what they send */
	protected function changes() {
		$evil = TestSite::file('evil.mp4', 'EVIL');
		return array(
			'thumb' => array('thumb', array('vf_time' => 1, 'vf_custom' => 1), $this->png()),
			'caption' => array('caption', array('vf_lang' => 'de', 'vf_label' => 'Deutsch'), $this->vtt()),
			'replace' => array('replace', array('vf_filename' => 'evil.mp4'), file_get_contents($evil)),
			'faststart' => array('faststart', array(), ''),
			'report' => array('report', array('vf_duration' => 99, 'vf_width' => 1, 'vf_height' => 1), ''),
		);
	}

	public function testAnEditorWithTheTokenCanChangeAVideo() {
		// (this one makes sure the requests of the others are made as the script makes them: they would change something)
		$before = $this->snapshot();
		$token = $this->token(self::userName);
		$r = $this->ajax(self::userName, 'caption', array('vf_lang' => 'de', 'vf_label' => 'Deutsch'), $this->vtt(), $token);
		$data = json_decode($r['body'], true);
		$this->assertTrue(is_array($data) && isset($data['error']), 'an answer of the script: ' . substr($r['body'], 0, 200));
		$this->assertFalse($data['error'], (string) ($data['message'] ?? ''));
		$after = $this->snapshot();
		$this->assertSame(array('de'), $after['captions'], 'the captions were saved');
		$this->assertNotSame($before['files'], $after['files']);

		$r = $this->ajax(self::userName, 'thumb', array('vf_time' => 1, 'vf_custom' => 1), $this->png(), $token);
		$data = json_decode($r['body'], true);
		$this->assertTrue(is_array($data) && $data['error'] === false, 'thumbnail: ' . substr($r['body'], 0, 200));
		$this->assertSame(1, $this->snapshot()['thumb'], 'the thumbnail was saved');

		$mid = $this->snapshot();
		$evil = TestSite::file('evil.mp4', 'EVIL');
		$r = $this->ajax(self::userName, 'replace', array('vf_filename' => 'evil.mp4'), file_get_contents($evil), $token);
		$data = json_decode($r['body'], true);
		$this->assertTrue(is_array($data) && $data['error'] === false, 'replace: ' . substr($r['body'], 0, 200));
		$this->assertNotSame($mid['files'], $this->snapshot()['files'], 'the file was replaced');
	}

	public function testNobodySignedInChangesNothing() {
		$before = $this->snapshot();
		foreach($this->changes() as $name => $c) {
			$r = $this->ajax(null, $c[0], $c[1], $c[2]);
			$this->assertStringNotContainsString('"error":false', $r['body'], $name);
			$this->assertSame($before, $this->snapshot(), "$name changed the page");
		}
	}

	public function testAUserWithoutTheRightToEditChangesNothing() {
		$before = $this->snapshot();
		$this->asUser(self::plainName, $this->adminPath('page/'));
		foreach($this->changes() as $name => $c) {
			$r = $this->ajax(self::plainName, $c[0], $c[1], $c[2]);
			$this->assertStringNotContainsString('"error":false', $r['body'], $name);
			$this->assertSame($before, $this->snapshot(), "$name changed the page");
		}
	}

	public function testAnEditorWithoutTheTokenChangesNothing() {
		$before = $this->snapshot();
		$this->asUser(self::userName, $this->adminPath('page/'));
		foreach($this->changes() as $name => $c) {
			$r = $this->ajax(self::userName, $c[0], $c[1], $c[2], null);
			$this->assertStringNotContainsString('"error":false', $r['body'], $name);
			$this->assertSame($before, $this->snapshot(), "$name without a token changed the page");
		}
	}

	public function testAnEditorWithAWrongTokenChangesNothing() {
		$before = $this->snapshot();
		$token = $this->token(self::userName);
		foreach($this->changes() as $name => $c) {
			$r = $this->ajax(self::userName, $c[0], $c[1], $c[2], array($token[0], 'not-the-token'));
			$this->assertStringNotContainsString('"error":false', $r['body'], $name);
			$this->assertSame($before, $this->snapshot(), "$name with a wrong token changed the page");
		}
	}

	public function testTheListOfVideosForTheEditorIsNotGivenToOthers() {
		// the rich text editor asks which videos the page has; it is the page editor that decides who may ask
		$path = $this->adminPath('page/edit/?id=' . $this->page->id . '&shv=list');
		$headers = array('X-REQUESTED-WITH' => 'XMLHttpRequest');
		$guest = $this->http('GET', $path, $headers);
		$this->assertStringNotContainsString('Secret rights title', $guest['body'], 'not signed in');
		$this->asUser(self::plainName, $this->adminPath('page/'));
		$plain = $this->http('GET', $path, $headers + array('Cookie' => $this->cookieHeader(self::plainName)));
		$this->assertStringNotContainsString('Secret rights title', $plain['body'], 'signed in without the right');
		$this->asUser(self::userName, $this->adminPath('page/'));
		$editor = $this->http('GET', $path, $headers + array('Cookie' => $this->cookieHeader(self::userName)));
		$this->assertStringContainsString('Secret rights title', $editor['body'], 'the editor gets it (so the others are not turned away by a mistake in the request)');
	}

	public function testTheCaptionsOfAPageAreNotGivenToOthers() {
		$page = TestSite::fresh($this->page);
		$video = TestSite::videos($page)->first();
		$file = sys_get_temp_dir() . '/shvtest-' . uniqid() . '.vtt';
		file_put_contents($file, "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nSecret caption words\n");
		$video->setCaptions('de', $file);
		$page->save();
		@unlink($file);
		$r = $this->ajax(null, 'captiontext', array('vf_lang' => 'de'));
		$this->assertStringNotContainsString('Secret caption words', $r['body'], 'not signed in');
		$this->asUser(self::plainName, $this->adminPath('page/'));
		$r = $this->ajax(self::plainName, 'captiontext', array('vf_lang' => 'de'));
		$this->assertStringNotContainsString('Secret caption words', $r['body'], 'without the right');
	}
}
