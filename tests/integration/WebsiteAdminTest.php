<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/AdminHttpTestCase.php';

/**
 * The module in the admin, as an editor meets it: the page with the field, the video manager, and who may open it.
 */
class WebsiteAdminTest extends AdminHttpTestCase {

	protected function videoPage($name, $title = 'Admin test clip') {
		return $this->inDefault(function() use($name, $title) {
			$page = $this->pageWithVideo($name);
			$video = TestSite::videos($page)->first();
			$video->title = $title;
			$page->save();
			return TestSite::fresh($page);
		});
	}

	/** The files of the module the page names (css and script) must be there */
	protected function assertAssetsFound($body, array $names) {
		foreach($names as $name) {
			$this->assertSame(1, preg_match('~(?:href|src)=[\'"]([^\'"]*/assets/' . preg_quote($name, '~') . '[^\'"]*)[\'"]~', $body, $m), "the page names assets/$name");
			$r = $this->http('GET', html_entity_decode($m[1]));
			$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
			$this->assertGreaterThan(500, strlen($r['body']));
		}
	}

	public function testTheEditorOfAPageShowsTheFieldWithItsFiles() {
		$page = $this->videoPage('admin-edit');
		$r = $this->asUser(self::userName, $this->adminPath('page/edit/?id=' . $page->id));
		$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
		$this->assertStringContainsString('shvtest_videos', $r['body'], 'the field is in the form');
		$this->assertStringContainsString('clip.mp4', $r['body'], 'with the video');
		$this->assertAssetsFound($r['body'], array('InputfieldSelfHostedVideo.css', 'InputfieldSelfHostedVideo.js'));
		$this->assertNoModuleError($r['body']);
	}

	public function testTheVideoManagerListsTheVideosAndLoadsItsFiles() {
		$page = $this->videoPage('admin-manager', 'Admin findable zebra');
		$this->videoPage('admin-manager2', 'Admin other giraffe');
		// (the overview has many videos on a website that is in use: it is asked for this one)
		$r = $this->asUser(self::userName, $this->adminPath('setup/videos/?q=zebra'));
		$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
		$this->assertStringContainsString('Admin findable zebra', $r['body']);
		$this->assertStringContainsString('clip.mp4', $r['body'], 'its file');
		$this->assertMatchesPattern('~page/edit/\?id=' . $page->id . '[\'"&]~', $r['body'], 'and a link to the page that has it');
		$this->assertStringNotContainsString('Admin other giraffe', $r['body'], 'and not the other');
		$this->assertAssetsFound($r['body'], array('ProcessSelfHostedVideos.css', 'ProcessSelfHostedVideos.js'));
		$this->assertNoModuleError($r['body']);
	}

	public function testTheVideoManagerIsClosedToVisitorsWhoAreNotSignedIn() {
		$this->videoPage('admin-guest', 'Admin secret walrus');
		$r = $this->http('GET', $this->adminPath('setup/videos/?q=walrus'));
		$this->assertStringNotContainsString('Admin secret walrus', $r['body']);
	}

	public function testTheVideoManagerIsClosedToAUserWithoutTheRight() {
		$this->videoPage('admin-plain', 'Admin hidden penguin');
		$r = $this->asUser(self::plainName, $this->adminPath('setup/videos/?q=penguin'));
		$this->assertStringNotContainsString('Admin hidden penguin', $r['body'], 'status ' . $r['status']);
	}
}
