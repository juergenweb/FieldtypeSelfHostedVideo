<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

/**
 * Where videos are placed (in a field, reused, in a text), tags that name nothing, and tags that follow a rename
 */
class UsageStoreTest extends WriteTestCase {

	protected function usage() {
		return $this->module()->usage();
	}

	protected function library($body = '') {
		return TestSite::page('lib', function($page) use($body) {
			TestSite::videos($page)->add(TestSite::file('clip.mp4'));
			if(strlen($body)) $page->shvtest_body = $body;
		});
	}

	protected function video($page, $name = 'clip.mp4') {
		return TestSite::videos(TestSite::fresh($page))->getFile($name);
	}

	protected function kinds($video) {
		$kinds = array_map(function($u) { return $u['page']->name . ':' . $u['kind']; }, $this->usage()->forVideo($video));
		sort($kinds);
		return $kinds;
	}

	public function testAVideoInAFieldIsPlacedThere() {
		$page = $this->library();
		$this->assertSame(array('shvtest-lib:field'), $this->kinds($this->video($page)));
	}

	public function testATagInATextPlacesTheVideoAsWell() {
		$page = $this->library('<p>[[video clip.mp4]]</p>');
		$this->assertSame(array('shvtest-lib:field', 'shvtest-lib:text'), $this->kinds($this->video($page)));
	}

	public function testATextOnAnotherPageCanPlaceAVideoByTheNameOfThePageToo() {
		$lib = $this->library();
		TestSite::page('other', function($p) use($lib) { $p->shvtest_body = '<p>[[video clip.mp4]]</p>'; });
		// a tag names a video of the page it is on or one that page reuses: here it names nothing
		$broken = array_map(function($b) { return $b['page']->name . ':' . $b['name']; }, $this->usage()->brokenTags());
		$this->assertContains('shvtest-other:clip.mp4', $broken);
	}

	public function testAReuseIsListedAsAReuse() {
		$lib = $this->library();
		$original = $this->video($lib);
		TestSite::page('reuser', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$this->assertSame(array('shvtest-lib:field', 'shvtest-reuser:reuse'), $this->kinds($original));
		$reuse = TestSite::videos(TestSite::fresh(\ProcessWire\wire('pages')->get('name=shvtest-reuser')))->first();
		$this->assertSame($this->kinds($original), $this->kinds($reuse), 'a reuse is asked as its original is');
	}

	public function testATagThatNamesNoVideoIsReported() {
		$page = $this->library('<p>[[video missing.mp4]] and [[video clip.mp4]]</p>');
		$names = array();
		foreach($this->usage()->brokenTags() as $b) if($b['page']->id === $page->id) $names[] = $b['name'];
		$this->assertSame(array('missing.mp4'), $names);
	}

	public function testTagNamesAreFoundInAnyCase() {
		$page = $this->library('<p>[[video CLIP.MP4]]</p>');
		$this->assertContains('shvtest-lib:text', $this->kinds($this->video($page)));
	}

	public function testAPageInTheTrashPlacesNothing() {
		$page = $this->library('<p>[[video clip.mp4]]</p>');
		$this->assertCount(2, $this->usage()->forVideo($this->video($page)));
		\ProcessWire\wire('pages')->trash(TestSite::fresh($page));
		$this->assertSame(array(), $this->usage()->forVideo($this->video($page), true));
		$this->assertSame(array(), $this->usage()->forVideo($this->video($page)));
	}

	public function testDeletingAPageForgetsWhereItPlacedVideos() {
		$page = $this->library('<p>[[video clip.mp4]]</p>');
		$id = $page->id;
		\ProcessWire\wire('pages')->delete(TestSite::fresh($page), true);
		$q = \ProcessWire\wire('database')->prepare('SELECT COUNT(*) FROM selfhostedvideo_usage WHERE pages_id=:p OR video_pages_id=:p');
		$q->execute(array(':p' => $id));
		$this->assertSame(0, (int) $q->fetchColumn());
	}

	public function testRenameTagsChangesNamesAndKeepsOptions() {
		$page = $this->library('<p>[[video clip.mp4]]</p><p><b>[[video  CLIP.MP4  align=right width=40]]</b></p><p>[[video other.mp4 caption]]</p>');
		$changed = $this->usage()->renameTags(array($page->id), 'clip.mp4', 'renamed.mp4');
		$this->assertSame(array($page->id), $changed);
		$this->assertSame('<p>[[video renamed.mp4]]</p><p><b>[[video  renamed.mp4  align=right width=40]]</b></p><p>[[video other.mp4 caption]]</p>', TestSite::body($page->id));
	}

	public function testRenameTagsOnlyLooksAtThePagesItIsGiven() {
		$a = $this->library('<p>[[video clip.mp4]]</p>');
		$b = TestSite::page('b', function($p) { $p->shvtest_body = '<p>[[video clip.mp4]]</p>'; });
		$this->assertSame(array($a->id), $this->usage()->renameTags(array($a->id), 'clip.mp4', 'renamed.mp4'));
		$this->assertSame('<p>[[video clip.mp4]]</p>', TestSite::body($b->id));
	}

	public function testRenameTagsHasNothingToDoForTheSameOrBlankNames() {
		$page = $this->library('<p>[[video clip.mp4]]</p>');
		$this->assertSame(array(), $this->usage()->renameTags(array($page->id), 'clip.mp4', 'clip.mp4'));
		$this->assertSame(array(), $this->usage()->renameTags(array($page->id), '', 'x.mp4'));
		$this->assertSame(array(), $this->usage()->renameTags(array($page->id), 'clip.mp4', ''));
		$this->assertSame(array(), $this->usage()->renameTags(array($page->id), 'nothing.mp4', 'x.mp4'));
		$this->assertSame('<p>[[video clip.mp4]]</p>', TestSite::body($page->id));
	}

	public function testRenameTagsDoesNotTouchTextThatOnlyLooksSimilar() {
		$page = $this->library('<p>[[video my-clip.mp4]] [[video clip.mp4.bak]] clip.mp4 [video clip.mp4]</p>');
		$this->assertSame(array(), $this->usage()->renameTags(array($page->id), 'clip.mp4', 'renamed.mp4'));
	}

	public function testRenameTagsUpdatesThePageInMemoryWithoutMakingItChanged() {
		$page = $this->library('<p>[[video clip.mp4]]</p>');
		$loaded = TestSite::fresh($page);
		$this->usage()->renameTags(array($page->id), 'clip.mp4', 'renamed.mp4');
		$this->assertSame('<p>[[video renamed.mp4]]</p>', (string) $loaded->getUnformatted('shvtest_body'));
		$this->assertFalse($loaded->isChanged('shvtest_body'));
	}

	public function testMovingAVideoMovesWhereItIsPlaced() {
		$page = $this->library('<p>[[video clip.mp4]]</p>');
		$this->usage()->moveVideo($page->id, 'clip.mp4', $page->id, 'moved.mp4');
		$q = \ProcessWire\wire('database')->prepare('SELECT DISTINCT video_name FROM selfhostedvideo_usage WHERE video_pages_id=:p');
		$q->execute(array(':p' => $page->id));
		$this->assertSame(array('moved.mp4'), $q->fetchAll(\PDO::FETCH_COLUMN));
	}

	public function testTextHoldersAreThePagesWhoseTextsPlaceAVideo() {
		$page = $this->library('<p>[[video clip.mp4]]</p>');
		$this->assertSame(array($page->id), $this->usage()->textHolders($page->id, 'clip.mp4'));
		$this->assertSame(array(), $this->usage()->textHolders($page->id, 'nothing.mp4'));
	}
}
