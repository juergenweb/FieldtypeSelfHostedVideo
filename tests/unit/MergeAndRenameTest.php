<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

/**
 * Videos that are the same file (found, merged) and videos that get another name: what they had stays with them
 */
class MergeAndRenameTest extends WriteTestCase {

	protected function dup() {
		// (groups are kept for the rest of a request: after pages were added, look anew)
		$ft = $this->module();
		$ft->finder()->forget();
		return \ProcessWire\wire(new \ProcessWire\SelfHostedVideoDuplicates($ft));
	}

	protected function add($name, $video, $seed = 'ONE', $body = '', $fileAs = null) {
		return TestSite::page($name, function($page) use($video, $seed, $body) {
			TestSite::videos($page)->add(TestSite::file($video, $seed));
			if(strlen($body)) $page->shvtest_body = $body;
		});
	}

	protected function video($page, $name) {
		return TestSite::videos(TestSite::fresh($page))->getFile($name);
	}

	protected function mine(array $groups) {
		$out = array();
		foreach($groups as $hash => $g) {
			$rows = array_values(array_filter($g['rows'], function($r) { $p = \ProcessWire\wire('pages')->get((int) $r['pages_id']); return $p->id && $p->template && $p->template->name === TestSite::prefix; }));
			if(count($rows) > 1) $out[] = array_map(function($r) { return $r['pages_id'] . ':' . $r['name']; }, $rows);
		}
		return $out;
	}

	public function testTheSameFileOnTwoPagesIsOneGroupOldestFirst() {
		$a = $this->add('a', 'talk.mp4');
		sleep(1);
		$b = $this->add('b', 'talk.mp4');
		$groups = $this->mine($this->dup()->groups());
		$this->assertSame(array(array("{$a->id}:talk.mp4", "{$b->id}:talk.mp4")), $groups);
	}

	public function testAnotherNameDoesNotMatterButAnotherContentDoes() {
		$a = $this->add('a', 'talk.mp4');
		$b = $this->add('b', 'our-talk.mp4');
		$c = $this->add('c', 'faked.mp4', 'TWO');
		$groups = $this->mine($this->dup()->groups());
		$this->assertCount(1, $groups);
		$this->assertContains("{$a->id}:talk.mp4", $groups[0]);
		$this->assertContains("{$b->id}:our-talk.mp4", $groups[0]);
		$this->assertNotContains("{$c->id}:faked.mp4", $groups[0], 'same size, other contents');
	}

	public function testAReuseIsNoCopy() {
		$a = $this->add('a', 'talk.mp4');
		$original = $this->video($a, 'talk.mp4');
		TestSite::page('r', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$this->assertSame(array(), $this->mine($this->dup()->groups()));
	}

	public function testWhyNot() {
		$a = $this->add('a', 'talk.mp4');
		$b = $this->add('b', 'talk.mp4');
		$c = $this->add('c', 'faked.mp4', 'TWO');
		$va = $this->video($a, 'talk.mp4');
		$this->assertSame('', $this->dup()->whyNot($va, $this->video($b, 'talk.mp4')));
		$this->assertNotSame('', $this->dup()->whyNot($va, $this->video($c, 'faked.mp4')), 'not the same file');
		$this->assertNotSame('', $this->dup()->whyNot($va, $va), 'itself');
		$reuser = TestSite::page('r', function($p) use($va) { TestSite::videos($p)->addReference($va); });
		$this->assertNotSame('', $this->dup()->whyNot($va, TestSite::videos(TestSite::fresh($reuser))->first()), 'a reuse');
	}

	public function testMergingKeepsTagsReusesAndCounts() {
		$a = $this->add('a', 'talk.mp4');
		sleep(1);
		$b = $this->add('b', 'talk-copy.mp4', 'ONE', '<p>[[video talk-copy.mp4 caption]]</p>');
		$copy = $this->video($b, 'talk-copy.mp4');
		$reuser = TestSite::page('r', function($p) use($copy) { TestSite::videos($p)->addReference($copy); $p->shvtest_body = '<p>[[video talk-copy.mp4]]</p>'; });
		$q = \ProcessWire\wire('database');
		$q->exec("INSERT INTO selfhostedvideo_stats (video_pages_id, video_name, day, plays) VALUES ({$a->id}, 'talk.mp4', '" . date('Y-m-d') . "', 5), ({$b->id}, 'talk-copy.mp4', '" . date('Y-m-d') . "', 3)");
		$file = $copy->filename();
		$this->assertTrue(is_file($file));

		$freed = $this->dup()->merge($this->video($a, 'talk.mp4'), $this->video($b, 'talk-copy.mp4'));

		$this->assertGreaterThan(0, $freed);
		$this->assertFalse(is_file($file), 'the copy’s file is gone');
		$pb = TestSite::fresh($b);
		$videos = TestSite::videos($pb);
		$this->assertSame(1, $videos->count());
		$this->assertTrue($videos->first()->isReference());
		$this->assertSame($a->id, (int) $videos->first()->get('ref_pages_id'));
		$this->assertSame('<p>[[video talk.mp4 caption]]</p>', TestSite::body($b->id), 'the tag names the kept video, with its options');
		$this->assertSame('<p>[[video talk.mp4]]</p>', TestSite::body($reuser->id), 'and on the page that reused the copy');
		$pr = TestSite::fresh($reuser);
		$r = TestSite::videos($pr)->first();
		$this->assertSame(array($a->id, 'talk.mp4'), $this->module()->stats()->videoKey($r), 'the page that reused the copy reuses the kept video');
		$this->assertSame(8, TestSite::plays($a->id, 'talk.mp4'));
		$this->assertSame(0, TestSite::plays($b->id, 'talk-copy.mp4'));
		$this->assertSame(array(), $this->mine($this->dup()->groups()));
		$broken = array_filter($this->module()->usage()->brokenTags(), function($x) { return $x['page']->template->name === TestSite::prefix; });
		$this->assertCount(0, $broken, 'no tag names nothing');
	}

	public function testMergingRefusesWhatIsNotTheSameFile() {
		$a = $this->add('a', 'talk.mp4');
		$c = $this->add('c', 'faked.mp4', 'TWO');
		$this->expectException(\ProcessWire\WireException::class);
		$this->dup()->merge($this->video($a, 'talk.mp4'), $this->video($c, 'faked.mp4'));
	}

	public function testTwoCopiesOnOnePageLeaveOne() {
		$page = TestSite::page('twice', function($p) {
			TestSite::videos($p)->add(TestSite::file('one.mp4'));
			TestSite::videos($p)->add(TestSite::file('two.mp4'));
			$p->shvtest_body = '<p>[[video two.mp4]]</p>';
		});
		$this->dup()->merge($this->video($page, 'one.mp4'), $this->video($page, 'two.mp4'));
		$p = TestSite::fresh($page);
		$this->assertSame(1, TestSite::videos($p)->count());
		$this->assertSame('<p>[[video one.mp4]]</p>', TestSite::body($page->id));
	}

	// renaming

	public function testRenamingCarriesCountsAndTagsAlong() {
		$a = $this->add('a', 'clip.mp4', 'ONE', '<p>[[video clip.mp4 nocaption]] [[video  CLIP.mp4  align=right]]</p>');
		$copy = $this->video($a, 'clip.mp4');
		$reuser = TestSite::page('r', function($p) use($copy) { TestSite::videos($p)->addReference($copy); $p->shvtest_body = '<p>[[video clip.mp4 caption]]</p>'; });
		\ProcessWire\wire('database')->exec("INSERT INTO selfhostedvideo_stats (video_pages_id, video_name, day, plays) VALUES ({$a->id}, 'clip.mp4', '" . date('Y-m-d') . "', 9)");

		$page = TestSite::fresh($a);
		TestSite::videos($page)->rename(TestSite::videos($page)->getFile('clip.mp4'), 'our-clip.mp4');
		$page->save();

		$this->assertInstanceOf(\ProcessWire\Pagevideo::class, TestSite::videos(TestSite::fresh($a))->getFile('our-clip.mp4'));
		$this->assertSame(9, TestSite::plays($a->id, 'our-clip.mp4'));
		$this->assertSame(0, TestSite::plays($a->id, 'clip.mp4'));
		$this->assertSame('<p>[[video our-clip.mp4 nocaption]] [[video  our-clip.mp4  align=right]]</p>', TestSite::body($a->id));
		$this->assertSame('<p>[[video our-clip.mp4 caption]]</p>', TestSite::body($reuser->id), 'the page that reuses it');
	}

	public function testRenamingWhileAnotherFieldOfThePageIsSavedKeepsBoth() {
		$a = $this->add('a', 'clip.mp4', 'ONE', '<p>[[video clip.mp4]]</p>');
		$page = TestSite::fresh($a);
		TestSite::videos($page)->rename(TestSite::videos($page)->getFile('clip.mp4'), 'final.mp4');
		$page->title = 'A new title';
		$page->save();
		$this->assertSame('<p>[[video final.mp4]]</p>', TestSite::body($a->id));
		// (a site with several languages keeps the title in the language of the user: look at every column of the table)
		$row = \ProcessWire\wire('database')->query('SELECT * FROM field_title WHERE pages_id=' . (int) $a->id)->fetch(\PDO::FETCH_ASSOC);
		$this->assertContains('A new title', array_values((array) $row));
	}
}
