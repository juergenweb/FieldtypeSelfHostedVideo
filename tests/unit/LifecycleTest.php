<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

/**
 * What happens to videos when pages are saved, trashed, deleted or cloned: a video that is reused on another page
 * cannot be lost by mistake, files go with their page, and reuses keep the data they are searched by.
 */
class LifecycleTest extends WriteTestCase {

	protected function library() {
		return TestSite::page('lib', function($page) { TestSite::videos($page)->add(TestSite::file('clip.mp4', 'LIB')); });
	}

	protected function video($page) {
		return TestSite::videos(TestSite::fresh($page))->first();
	}

	protected function reuser($original, $name = 'r') {
		return TestSite::page($name, function($p) use($original) { TestSite::videos($p)->addReference($original); });
	}

	protected function pages() {
		return \ProcessWire\wire('pages');
	}

	protected function exists($id) {
		$this->pages()->uncacheAll();
		return $this->pages()->get((int) $id, array('findOne' => true))->id > 0 || (bool) $this->pages()->count("id=$id, include=all");
	}

	public function testAPageWhoseVideoIsReusedCannotBeDeleted() {
		$lib = $this->library();
		$file = $this->video($lib)->filename();
		$this->reuser($this->video($lib));
		$page = TestSite::fresh($lib);
		$thrown = false;
		try {
			$this->pages()->delete($page);
		} catch(\ProcessWire\WireException $e) {
			$thrown = true;
		}
		$this->assertTrue($thrown, 'refused with an exception');
		$this->assertTrue($this->exists($lib->id));
		$this->assertTrue(is_file($file), 'the file is still there');
	}

	public function testNorCanItBeMovedToTheTrash() {
		$lib = $this->library();
		$this->reuser($this->video($lib));
		$thrown = false;
		try {
			$this->pages()->trash(TestSite::fresh($lib));
		} catch(\ProcessWire\WireException $e) {
			$thrown = true;
		}
		$this->assertTrue($thrown);
		$this->assertFalse(TestSite::fresh($lib)->isTrash());
	}

	public function testTheMessageNamesThePagesThatReuseIt() {
		$lib = $this->library();
		$reuser = $this->reuser($this->video($lib), 'user-of-it');
		$blocking = $this->module()->getBlockingReferences(TestSite::fresh($lib));
		$this->assertSame(array((int) $reuser->id), array_keys($blocking));
		$message = '';
		try {
			$this->pages()->delete(TestSite::fresh($lib));
		} catch(\ProcessWire\WireException $e) {
			$message = $e->getMessage();
		}
		$this->assertStringContainsString('user-of-it', $message);
	}

	public function testOnceTheReuseIsGoneThePageCanBeDeleted() {
		$lib = $this->library();
		$file = $this->video($lib)->filename();
		$reuser = $this->reuser($this->video($lib));
		$this->pages()->delete(TestSite::fresh($reuser), true);
		$this->assertTrue(is_file($file), 'the reuser had no file of its own to take along');
		$this->pages()->delete(TestSite::fresh($lib), true);
		$this->assertFalse(is_file($file), 'now the file goes with its page');
	}

	public function testABranchWithTheVideoAndItsReuseCanBeDeletedTogether() {
		$parent = TestSite::page('parent', function($p) { TestSite::videos($p)->add(TestSite::file('clip.mp4', 'PAR')); });
		$original = $this->video($parent);
		$child = $this->pages()->add('shvtest', $parent, array('name' => 'shvtest-child', 'title' => 'Child'));
		$child->of(false);
		TestSite::videos($child)->addReference($original);
		$child->save();
		$this->assertSame(array(), $this->module()->getBlockingReferences(TestSite::fresh($parent)), 'a reuse inside the branch does not block');
		$this->pages()->delete(TestSite::fresh($parent), true);
		$this->assertFalse($this->exists($parent->id));
		$this->assertFalse($this->exists($child->id));
	}

	public function testDeletingAPageTakesItsFilesAlong() {
		$lib = $this->library();
		$file = $this->video($lib)->filename();
		$this->assertTrue(is_file($file));
		$this->pages()->delete(TestSite::fresh($lib), true);
		$this->assertFalse(is_file($file));
	}

	public function testChangesToTheOriginalReachTheDataItsReusesAreSearchedBy() {
		$lib = $this->library();
		$reuser = $this->reuser($this->video($lib));
		$video = $this->video($lib);
		$video->description = 'A new description';
		$video->tags = 'fresh tag';
		$video->page->save();
		$row = \ProcessWire\wire('database')->query('SELECT description, tags, duration, width, height FROM field_shvtest_videos WHERE pages_id=' . (int) $reuser->id . ' AND ref_pages_id>0')->fetch(\PDO::FETCH_ASSOC);
		$this->assertStringContainsString('A new description', $row['description'], '(stored as JSON, for the languages)');
		$this->assertSame('fresh tag', $row['tags']);
		$this->assertEqualsWithDelta(5.0, (float) $row['duration'], 0.01);
		$this->assertSame(640, (int) $row['width']);
		$this->assertSame(360, (int) $row['height']);
	}

	public function testAClonedPageHasCopiesOfItsVideosNotReuses() {
		$lib = $this->library();
		$original = $this->video($lib);
		$clone = $this->pages()->clone(TestSite::fresh($lib), $this->pages()->get('/'), true, array('name' => 'shvtest-lib-clone'));
		$this->assertTrue($clone && $clone->id > 0);
		$copy = TestSite::videos(TestSite::fresh($clone))->first();
		$this->assertNotNull($copy);
		$this->assertFalse($copy->isReference());
		$this->assertSame('clip.mp4', $copy->basename());
		$this->assertNotSame($original->filename(), $copy->filename());
		$this->assertTrue(is_file($copy->filename()));
		$this->assertTrue(is_file($original->filename()), 'the original is untouched');
		$this->assertSame(sha1_file($original->filename()), sha1_file($copy->filename()));
	}

	public function testTheCopyOfAPageThatReusesAVideoReusesItToo() {
		$lib = $this->library();
		$reuser = $this->reuser($this->video($lib));
		$clone = $this->pages()->clone(TestSite::fresh($reuser), $this->pages()->get('/'), true, array('name' => 'shvtest-r-clone'));
		$copy = TestSite::videos(TestSite::fresh($clone))->first();
		$this->assertNotNull($copy);
		$this->assertTrue($copy->isReference());
		$this->assertSame($lib->id, (int) $copy->original()->page->id);
	}

	public function testRemovingAVideoFromItsPageDeletesItsFileAndCounts() {
		$lib = $this->library();
		$db = \ProcessWire\wire('database');
		$db->exec("INSERT INTO selfhostedvideo_stats (video_pages_id, video_name, day, plays) VALUES ({$lib->id}, 'clip.mp4', '" . date('Y-m-d') . "', 4)");
		$page = TestSite::fresh($lib);
		$video = TestSite::videos($page)->first();
		$file = $video->filename();
		TestSite::videos($page)->delete($video);
		$page->save();
		$this->assertSame(0, TestSite::videos(TestSite::fresh($lib))->count());
		$this->assertFalse(is_file($file));
		$this->assertSame(0, TestSite::plays($lib->id, 'clip.mp4'), 'its counts go too');
	}
}
