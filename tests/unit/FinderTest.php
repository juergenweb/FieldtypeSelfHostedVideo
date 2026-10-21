<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

use ProcessWire\Page;

/**
 * The search of the Video Manager: words, tags, sorting, paging, and which videos are shown for each choice of "show"
 * (only the test field is asked, so that the videos of the website do not matter)
 */
class FinderTest extends WriteTestCase {

	protected function finder() {
		$this->module()->finder()->forget();
		return $this->module()->finder();
	}

	/** Names of the videos found (page name : file name), in order */
	protected function found(array $options = array()) {
		$result = $this->finder()->find($options + array('field' => 'shvtest_videos', 'limit' => 0));
		return array_map(function($v) { return str_replace('shvtest-', '', $v->page->name) . ':' . $v->basename(); }, $result['items']);
	}

	protected function sorted(array $options = array()) {
		$names = $this->found($options);
		sort($names);
		return $names;
	}

	/** A page with one video; $data is set on the video */
	protected function add($name, $file = 'clip.mp4', array $data = array(), array $media = array(), $seed = null, $title = '') {
		return TestSite::page($name, function($page) use($file, $data, $media, $seed, $name) {
			$videos = TestSite::videos($page);
			$videos->add(TestSite::file($file, $seed === null ? $name : $seed, $media));
			$video = $videos->getFile($file);
			foreach($data as $key => $value) $video->$key = $value;
		}, $title);
	}

	// search

	public function testWithoutWordsAllVideosOfTheFieldAreFound() {
		$this->add('a', 'one.mp4');
		$this->add('b', 'two.mp4');
		$this->assertSame(array('a:one.mp4', 'b:two.mp4'), $this->sorted());
		$this->assertSame(2, $this->finder()->find(array('field' => 'shvtest_videos'))['total']);
	}

	public function testAWordIsLookedForInFileNameTitleDescriptionAndTags() {
		$this->add('a', 'sunrise.mp4');
		$this->add('b', 'x.mp4', array('title' => 'Sunrise at the lake'));
		$this->add('c', 'y.mp4', array('description' => 'A film about the sunrise'));
		$this->add('d', 'z.mp4', array('tags' => 'nature sunrise'));
		$this->add('e', 'other.mp4');
		$this->assertSame(array('a:sunrise.mp4', 'b:x.mp4', 'c:y.mp4', 'd:z.mp4'), $this->sorted(array('q' => 'sunrise')));
	}

	public function testSearchIgnoresCaseAndNeedsOnlyAPartOfAWord() {
		$this->add('a', 'interview.mp4');
		$this->assertSame(array('a:interview.mp4'), $this->found(array('q' => 'INTERV')));
		$this->assertSame(array('a:interview.mp4'), $this->found(array('q' => 'view')));
	}

	public function testAllWordsHaveToBeFoundButNotInTheSamePlace() {
		$this->add('a', 'lake.mp4', array('title' => 'Morning'));
		$this->add('b', 'lake2.mp4', array('title' => 'Evening'));
		$this->assertSame(array('a:lake.mp4'), $this->found(array('q' => 'lake morning')));
		$this->assertSame(array(), $this->found(array('q' => 'lake morning evening')));
	}

	public function testTheTitleOfThePageFindsItsVideos() {
		$this->add('a', 'x.mp4', array(), array(), null, 'Our annual meeting');
		$this->add('b', 'y.mp4');
		$this->assertSame(array('a:x.mp4'), $this->found(array('q' => 'annual')));
	}

	public function testLettersWithAccentsAreFound() {
		$this->add('a', 'x.mp4', array('title' => 'Größe und Übung', 'description' => 'Für Anfänger'));
		$this->assertSame(array('a:x.mp4'), $this->found(array('q' => 'Größe')));
		$this->assertSame(array('a:x.mp4'), $this->found(array('q' => 'übung')));
		$this->assertSame(array('a:x.mp4'), $this->found(array('q' => 'anfänger')));
	}

	public function testPercentUnderscoreAndBackslashAreJustLetters() {
		$this->add('a', 'a_b.mp4', array('title' => '100% sure'));
		$this->add('b', 'axb.mp4');
		$this->assertSame(array('a:a_b.mp4'), $this->found(array('q' => 'a_b')));
		$this->assertSame(array('a:a_b.mp4'), $this->found(array('q' => '100%')));
		$this->assertSame(array('a:a_b.mp4'), $this->found(array('q' => '%')), '% is not a joker: only what has a percent sign');
		$this->assertSame(array(), $this->found(array('q' => '\\')));
	}

	public function testQuotesAndSqlInTheWordsDoNothingHarmful() {
		$this->add('a', 'x.mp4');
		$this->assertSame(array(), $this->found(array('q' => "' OR 1=1 --")));
		$this->assertSame(array(), $this->found(array('q' => '"; DROP TABLE pages; --')));
		$this->assertSame(array('a:x.mp4'), $this->found(array()));
	}

	public function testOnlySixWordsCountAndTooLongOnesAreLeftOut() {
		$this->add('a', 'x.mp4', array('title' => 'one two three four five six'));
		$this->assertSame(array('a:x.mp4'), $this->found(array('q' => 'one two three four five six seven eight')));
		$this->assertSame(array('a:x.mp4'), $this->found(array('q' => 'one ' . str_repeat('x', 101))));
		$this->assertSame(array('a:x.mp4'), $this->found(array('q' => '   ')));
	}

	public function testATagFiltersByWholeTag() {
		$this->add('a', 'a.mp4', array('tags' => 'news interview'));
		$this->add('b', 'b.mp4', array('tags' => 'newsletter'));
		$this->add('c', 'c.mp4', array('tags' => 'two words'));
		$this->assertSame(array('a:a.mp4'), $this->found(array('tag' => 'news')));
		$this->assertSame(array('a:a.mp4'), $this->found(array('tag' => 'interview')));
		$this->assertSame(array(), $this->found(array('tag' => 'new')));
		$this->assertSame(array('a:a.mp4'), $this->found(array('tag' => 'news', 'q' => 'a.mp4')));
	}

	public function testAnotherFieldIsNotSearched() {
		$this->add('a', 'a.mp4');
		$this->assertSame(array(), $this->found(array('field' => 'nothing_here')));
	}

	// sorting and paging

	public function testSortedByName() {
		$this->add('a', 'clip10.mp4');
		$this->add('b', 'clip2.mp4');
		$this->add('c', 'clip1.mp4');
		$this->assertSame(array('c:clip1.mp4', 'b:clip2.mp4', 'a:clip10.mp4'), $this->found(array('sort' => 'name')));
	}

	public function testSortedByAge() {
		$this->add('a', 'a.mp4');
		$this->add('b', 'b.mp4');
		$this->add('c', 'c.mp4');
		$this->assertSame(array('c:c.mp4', 'b:b.mp4', 'a:a.mp4'), $this->found());
		$this->assertSame(array('c:c.mp4', 'b:b.mp4', 'a:a.mp4'), $this->found(array('sort' => 'newest')));
		$this->assertSame(array('a:a.mp4', 'b:b.mp4', 'c:c.mp4'), $this->found(array('sort' => 'oldest')));
		$this->assertSame(array('c:c.mp4', 'b:b.mp4', 'a:a.mp4'), $this->found(array('sort' => 'nonsense')), 'an unknown choice is the default');
	}

	public function testSortedByLengthAndSize() {
		$this->add('a', 'a.mp4', array(), array('seconds' => 30.0, 'chunk' => 'x'));
		$this->add('b', 'b.mp4', array(), array('seconds' => 300.0, 'chunk' => str_repeat('x', 5000)));
		$this->add('c', 'c.mp4', array(), array('seconds' => 3.0, 'chunk' => str_repeat('x', 100)));
		$this->assertSame(array('b:b.mp4', 'a:a.mp4', 'c:c.mp4'), $this->found(array('sort' => 'longest')));
		$this->assertSame(array('b:b.mp4', 'c:c.mp4', 'a:a.mp4'), $this->found(array('sort' => 'largest')));
	}

	public function testSortedByPlaysOfTheLastThirtyDays() {
		$a = $this->add('a', 'a.mp4');
		$b = $this->add('b', 'b.mp4');
		$c = $this->add('c', 'c.mp4');
		$db = \ProcessWire\wire('database');
		$today = date('Y-m-d');
		$old = date('Y-m-d', time() - 60 * 86400);
		$db->exec("INSERT INTO selfhostedvideo_stats (video_pages_id, video_name, day, plays) VALUES ({$a->id}, 'a.mp4', '$today', 2), ({$b->id}, 'b.mp4', '$today', 7), ({$c->id}, 'c.mp4', '$old', 99)");
		$this->assertSame(array('b:b.mp4', 'a:a.mp4', 'c:c.mp4'), $this->found(array('sort' => 'mostplayed')), 'plays of 60 days ago do not count');
		$this->assertSame(array('c:c.mp4', 'a:a.mp4', 'b:b.mp4'), $this->found(array('sort' => 'leastplayed')));
	}

	public function testPagingTotalsAndTheLastPage() {
		foreach(array('a', 'b', 'c', 'd', 'e') as $n) $this->add($n, "$n.mp4", array(), array('seconds' => 10.0));
		$finder = $this->finder();
		$r = $finder->find(array('field' => 'shvtest_videos', 'sort' => 'name', 'limit' => 2, 'start' => 0));
		$this->assertSame(5, $r['total']);
		$this->assertSame(2, count($r['items']));
		$this->assertEqualsWithDelta(50.0, $r['duration'], 0.01, 'the length of all that match, not of the page');
		$this->assertGreaterThan(0, $r['filesize']);
		$r = $finder->find(array('field' => 'shvtest_videos', 'sort' => 'name', 'limit' => 2, 'start' => 4));
		$this->assertSame(array('e.mp4'), array_map(function($v) { return $v->basename(); }, $r['items']));
		$r = $finder->find(array('field' => 'shvtest_videos', 'sort' => 'name', 'limit' => 2, 'start' => 40));
		$this->assertSame(4, $r['start'], 'behind the last page: the last page');
		$this->assertSame(array('e.mp4'), array_map(function($v) { return $v->basename(); }, $r['items']));
		$r = $finder->find(array('field' => 'shvtest_videos', 'limit' => 0, 'start' => -3));
		$this->assertSame(5, count($r['items']));
		$this->assertSame(0, $r['start']);
	}

	// which pages and videos count

	public function testAReuseIsNotListedButItsOriginalIsReused() {
		$a = $this->add('a', 'a.mp4');
		$original = TestSite::videos(TestSite::fresh($a))->first();
		TestSite::page('r', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$this->add('b', 'b.mp4');
		$this->assertSame(array('a:a.mp4', 'b:b.mp4'), $this->sorted());
		$this->assertSame(array('a:a.mp4'), $this->found(array('show' => 'reused')));
	}

	public function testVideosOnPagesInTheTrashAreLeftOut() {
		$a = $this->add('a', 'a.mp4');
		$this->add('b', 'b.mp4');
		$page = TestSite::fresh($a);
		\ProcessWire\wire('pages')->trash($page);
		$this->assertSame(array('b:b.mp4'), $this->found());
	}

	public function testUnusedMeansOnNoPublishedPage() {
		$a = $this->add('a', 'a.mp4');
		$b = $this->add('b', 'b.mp4');
		$page = TestSite::fresh($a);
		$page->addStatus(Page::statusUnpublished);
		$page->save();
		$this->assertSame(array('a:a.mp4'), $this->found(array('show' => 'unused')));
		// reused on a published page: it is used
		$original = TestSite::videos(TestSite::fresh($a))->first();
		TestSite::page('r', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$this->assertSame(array(), $this->found(array('show' => 'unused')));
	}

	public function testAGuestSeesOnlyVideosOfPagesThatMayBeViewed() {
		$a = $this->add('a', 'a.mp4');
		$b = $this->add('b', 'b.mp4');
		$page = TestSite::fresh($a);
		$page->addStatus(Page::statusUnpublished);
		$page->save();
		$finder = $this;
		$names = $this->asGuest(function() use($finder) { return $finder->found(); });
		$this->assertSame(array('b:b.mp4'), $names);
		$this->assertSame(array('a:a.mp4', 'b:b.mp4'), $this->sorted(), 'the superuser sees both');
	}

	// the choices of "show"

	public function testStreamShowsMp4FilesNotPreparedForStreaming() {
		$this->add('a', 'a.mp4');
		$b = $this->add('b', 'b.mp4');
		// (a file is prepared for streaming when it is uploaded: put one that is not in place of it)
		$video = TestSite::videos(TestSite::fresh($b))->first();
		file_put_contents($video->filename(), MediaFiles::mp4(array('faststart' => false, 'chunk' => 'SLOWSLOWSLOW..'))['bytes']);
		clearstatcache();
		$video->storeMediaInfo();
		$video->page->save();
		$this->assertSame(array('b:b.mp4'), $this->found(array('show' => 'stream')));
	}

	public function testInTextShowsVideosPlacedWithATag() {
		$this->add('a', 'a.mp4');
		TestSite::page('t', function($p) {
			TestSite::videos($p)->add(TestSite::file('t.mp4', 'T'));
			$p->shvtest_body = '<p>[[video t.mp4]]</p>';
		});
		$this->assertSame(array('t:t.mp4'), $this->found(array('show' => 'intext')));
	}

	public function testUnplayedAndDropoffUseTheLastThirtyDays() {
		$a = $this->add('a', 'a.mp4');
		$b = $this->add('b', 'b.mp4');
		$c = $this->add('c', 'c.mp4');
		$d = $this->add('d', 'd.mp4');
		$db = \ProcessWire\wire('database');
		$today = date('Y-m-d');
				$half = 'p50';
		// b: 10 plays, 1 to the half (stops early). c: 10 plays, 9 to the half. d: 2 plays only
		$db->exec("INSERT INTO selfhostedvideo_stats (video_pages_id, video_name, day, plays, `$half`) VALUES ({$b->id}, 'b.mp4', '$today', 10, 1), ({$c->id}, 'c.mp4', '$today', 10, 9), ({$d->id}, 'd.mp4', '$today', 2, 0)");
		$this->assertSame(array('a:a.mp4'), $this->found(array('show' => 'unplayed')));
		$this->assertSame(array('b:b.mp4'), $this->found(array('show' => 'dropoff')), 'enough plays and most stopped early');
	}

	public function testDuplicatesAreTogetherOldestFirst() {
		$a = $this->add('a', 'a.mp4', array(), array(), 'SAME');
		$this->add('x', 'x.mp4', array(), array(), 'OTHER');
		$b = $this->add('b', 'b.mp4', array(), array(), 'SAME');
		$this->assertSame(array('a:a.mp4', 'b:b.mp4'), $this->found(array('show' => 'duplicates')));
		$this->assertSame(array('a:a.mp4', 'b:b.mp4'), $this->found(array('show' => 'duplicates', 'sort' => 'newest')), 'the order of a group does not depend on the sort');
	}

	public function testTheChecklistChoicesDivideTheVideosInTwo() {
		$this->add('a', 'a.mp4');
		$this->add('b', 'b.mp4', array('title' => 'Has a title'), array('faststart' => true));
		$all = $this->sorted();
		$open = $this->sorted(array('checklist' => 'open'));
		$complete = $this->sorted(array('checklist' => 'complete'));
		$both = array_merge($open, $complete);
		sort($both);
		$this->assertSame($all, $both);
		$this->assertSame($open, $this->sorted(array('show' => 'attention')), 'the old name of the choice');
	}
}
