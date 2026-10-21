<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

use ProcessWire\Page;

/**
 * Who may do what with a video: see it in the Video Manager, merge copies, and which files are protected.
 * A visitor (guest) must never be able to change or delete anything.
 */
class PermissionsTest extends WriteTestCase {

	protected function add($name, $seed = 'SAME') {
		return TestSite::page($name, function($page) use($seed) { TestSite::videos($page)->add(TestSite::file('clip.mp4', $seed)); });
	}

	protected function video($page) {
		return TestSite::videos(TestSite::fresh($page))->first();
	}

	public function testAGuestMayViewPublishedPagesOnly() {
		$a = $this->add('a');
		$b = $this->add('b');
		$page = TestSite::fresh($b);
		$page->addStatus(Page::statusUnpublished);
		$page->save();
		$finder = $this->module()->finder();
		$result = $this->asGuest(function() use($finder, $a, $b) {
			\ProcessWire\wire('pages')->uncacheAll();
			return array($finder->viewable(\ProcessWire\wire('pages')->get($a->id)), $finder->viewable(\ProcessWire\wire('pages')->get($b->id)));
		});
		$this->assertSame(array(true, false), $result);
	}

	public function testNobodyViewsAPageInTheTrash() {
		$a = $this->add('a');
		$page = TestSite::fresh($a);
		\ProcessWire\wire('pages')->trash($page);
		$this->assertFalse($this->module()->finder()->viewable(\ProcessWire\wire('pages')->get($a->id)), 'not even a superuser');
	}

	public function testAGuestMayNotMergeAndNothingIsLost() {
		$a = $this->add('a');
		$b = $this->add('b');
		$keep = $this->video($a);
		$copy = $this->video($b);
		$file = $copy->filename();
		$module = $this->module();
		$outcome = $this->asGuest(function() use($module, $keep, $copy) {
			$dup = $module->duplicates();
			$why = $dup->whyNot($keep, $copy);
			try {
				$dup->merge($keep, $copy);
				$thrown = false;
			} catch(\ProcessWire\WireException $e) {
				$thrown = true;
			}
			return array($why !== '', $thrown);
		});
		$this->assertSame(array(true, true), $outcome, 'refused, and merge() throws');
		$this->assertTrue(is_file($file), 'the copy’s file is still there');
		$this->assertFalse($this->video($b)->isReference(), 'and its page still has its own video');
	}

	public function testASuperuserMayMergeTheSameTwoVideos() {
		$a = $this->add('a');
		$b = $this->add('b');
		$this->assertSame('', $this->module()->duplicates()->whyNot($this->video($a), $this->video($b)));
	}

	protected $secure = null;

	protected function secure($on) {
		$config = \ProcessWire\wire('config');
		if($this->secure === null) $this->secure = $config->pagefileSecure;
		$config->pagefileSecure = $on;
	}

	protected function tearDown(): void {
		if($this->secure !== null) \ProcessWire\wire('config')->pagefileSecure = $this->secure;
		$this->secure = null;
		parent::tearDown();
	}

	public function testFilesOfAPageThatVisitorsMayNotSeeAreProtected() {
		$this->secure(true);
		$a = $this->add('a', 'ONE');
		$b = $this->add('b', 'TWO');
		$page = TestSite::fresh($b);
		$page->addStatus(Page::statusUnpublished);
		$page->save();
		$this->assertFalse($this->video($a)->isProtected(), 'a public page');
		$this->assertTrue($this->video($b)->isProtected(), 'an unpublished page');
	}

	public function testAReuseIsProtectedAsItsOriginalIs() {
		$this->secure(true);
		$a = $this->add('a', 'ONE');
		$page = TestSite::fresh($a);
		$page->addStatus(Page::statusUnpublished);
		$page->save();
		$original = $this->video($a);
		$reuser = TestSite::page('r', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$this->assertTrue($this->video($reuser)->isProtected());
	}

	public function testAGuestDoesNotSeeTheVideoManagerListOfUnpublishedPages() {
		$this->add('a', 'ONE');
		$b = $this->add('b', 'TWO');
		$page = TestSite::fresh($b);
		$page->addStatus(Page::statusUnpublished);
		$page->save();
		$module = $this->module();
		$count = $this->asGuest(function() use($module) {
			$module->finder()->forget();
			return $module->finder()->find(array('field' => 'shvtest_videos', 'limit' => 0))['total'];
		});
		$this->assertSame(1, $count);
		$module->finder()->forget();
		$this->assertSame(2, $module->finder()->find(array('field' => 'shvtest_videos', 'limit' => 0))['total']);
	}
}
