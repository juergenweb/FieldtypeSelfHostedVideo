<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

/**
 * Counting plays: what is counted, what is not, and how counts are read and moved
 */
class StatsStoreTest extends WriteTestCase {

	protected function stats() {
		return $this->module()->stats();
	}

	protected function addPage($name = 'a', $video = 'clip.mp4') {
		return TestSite::page($name, function($page) use($video) {
			TestSite::videos($page)->add(TestSite::file($video));
		});
	}

	protected function row($pageId, $name) {
		$q = \ProcessWire\wire('database')->prepare('SELECT * FROM selfhostedvideo_stats WHERE video_pages_id=:p AND video_name=:n AND day=:d');
		$q->execute(array(':p' => $pageId, ':n' => $name, ':d' => date('Y-m-d')));
		return $q->fetch(\PDO::FETCH_ASSOC);
	}

	protected function put($pageId, $name, $daysAgo, array $counts) {
		$counts += array('plays' => 0, 'p25' => 0, 'p50' => 0, 'p75' => 0, 'p100' => 0);
		$q = \ProcessWire\wire('database')->prepare('INSERT INTO selfhostedvideo_stats (video_pages_id, video_name, day, plays, p25, p50, p75, p100) VALUES (:p, :n, :d, :a, :b, :c, :e, :f)');
		$q->execute(array(':p' => $pageId, ':n' => $name, ':d' => date('Y-m-d', time() - $daysAgo * 86400), ':a' => $counts['plays'], ':b' => $counts['p25'], ':c' => $counts['p50'], ':e' => $counts['p75'], ':f' => $counts['p100']));
	}

	public function testPlaysAndProgressAreCounted() {
		$page = $this->addPage();
		$stats = $this->stats();
		$this->asGuest(function() use($stats, $page) {
			$this->assertTrue($stats->record("{$page->id}:clip.mp4", 'play'));
			$this->assertTrue($stats->record("{$page->id}:clip.mp4", 'play'));
			$this->assertTrue($stats->record("{$page->id}:clip.mp4", '25'));
			$this->assertTrue($stats->record("{$page->id}:clip.mp4", '100'));
		});
		$row = $this->row($page->id, 'clip.mp4');
		$this->assertSame('2/1/0/0/1', "$row[plays]/$row[p25]/$row[p50]/$row[p75]/$row[p100]");
	}

	public function testThingsThatAreNoPlayAreNotCounted() {
		$page = $this->addPage();
		$stats = $this->stats();
		$this->asGuest(function() use($stats, $page) {
			$this->assertFalse($stats->record("{$page->id}:clip.mp4", '33'), 'an event that does not exist');
			$this->assertFalse($stats->record("{$page->id}:clip.mp4", ''), 'no event');
			$this->assertFalse($stats->record('nonsense', 'play'), 'no video');
			$this->assertFalse($stats->record("{$page->id}:other.mp4", 'play'), 'a video the page does not have');
			$this->assertFalse($stats->record("999999999:clip.mp4", 'play'), 'a page that does not exist');
			$this->assertFalse($stats->record("{$page->id}:" . str_repeat('x', 300), 'play'), 'a very long name');
		});
		$this->assertFalse($this->row($page->id, 'clip.mp4'));
	}

	public function testRobotsAreNotCounted() {
		$page = $this->addPage();
		$stats = $this->stats();
		$this->asGuest(function() use($stats, $page) {
			foreach(array('', 'Googlebot/2.1', 'curl/8.0', 'HeadlessChrome/120') as $agent) {
				$_SERVER['HTTP_USER_AGENT'] = $agent;
				$this->assertFalse($stats->record("{$page->id}:clip.mp4", 'play'), $agent);
			}
		});
		$this->assertFalse($this->row($page->id, 'clip.mp4'));
	}

	public function testThoseWhoEditThePageAreNotItsAudience() {
		$page = $this->addPage();
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120';
		$this->assertFalse($this->stats()->record("{$page->id}:clip.mp4", 'play'));
		unset($_SERVER['HTTP_USER_AGENT']);
		$this->assertFalse($this->row($page->id, 'clip.mp4'));
	}

	public function testAPageThatVisitorsCannotSeeCountsNothing() {
		$page = TestSite::page('hidden', function($page) {
			TestSite::videos($page)->add(TestSite::file('clip.mp4'));
			$page->addStatus(\ProcessWire\Page::statusUnpublished);
		});
		$stats = $this->stats();
		$this->asGuest(function() use($stats, $page) {
			$this->assertFalse($stats->record("{$page->id}:clip.mp4", 'play'));
		});
	}

	public function testAVideoOnAHiddenPageCountsWhenAVisiblePageReusesIt() {
		$hidden = TestSite::page('hidden', function($page) {
			TestSite::videos($page)->add(TestSite::file('clip.mp4'));
			$page->addStatus(\ProcessWire\Page::statusUnpublished);
		});
		$original = TestSite::videos(TestSite::fresh($hidden))->first();
		TestSite::page('visible', function($page) use($original) { TestSite::videos($page)->addReference($original); });
		$stats = $this->stats();
		$this->asGuest(function() use($stats, $hidden) {
			$this->assertTrue($stats->record("{$hidden->id}:clip.mp4", 'play'));
		});
		$this->assertSame(1, TestSite::plays($hidden->id, 'clip.mp4'));
	}

	public function testCountsOfTheSameDayAreAddedUpWhenMoved() {
		$this->put(1, 'from.mp4', 0, array('plays' => 3, 'p50' => 1));
		$this->put(1, 'from.mp4', 2, array('plays' => 5));
		$this->put(1, 'to.mp4', 0, array('plays' => 4, 'p50' => 2));
		$this->stats()->move(1, 'from.mp4', 1, 'to.mp4');
		$this->assertSame(0, TestSite::plays(1, 'from.mp4'));
		$this->assertSame(12, TestSite::plays(1, 'to.mp4'));
		$row = $this->row(1, 'to.mp4');
		$this->assertSame(7, (int) $row['plays']);
		$this->assertSame(3, (int) $row['p50']);
		$this->stats()->remove(1);
	}

	public function testMovingToItselfOrFromNothingChangesNothing() {
		$this->put(1, 'a.mp4', 0, array('plays' => 3));
		$this->stats()->move(1, 'a.mp4', 1, 'a.mp4');
		$this->stats()->move(1, 'nothing.mp4', 1, 'a.mp4');
		$this->assertSame(3, TestSite::plays(1, 'a.mp4'));
		$this->stats()->remove(1);
	}

	public function testPercentagesAreOfThePlays() {
		$page = $this->addPage();
		$this->put($page->id, 'clip.mp4', 1, array('plays' => 10, 'p25' => 8, 'p50' => 5, 'p75' => 3, 'p100' => 1));
		$video = TestSite::videos(TestSite::fresh($page))->first();
		$s = $this->stats()->forVideo($video, 30);
		$this->assertSame(10, $s['plays']);
		$this->assertSame(array(80, 50, 30, 10), array($s['quarter'], $s['half'], $s['threeQuarters'], $s['end']));
		$this->assertSame(30, $s['days']);
	}

	public function testOnlyTheLastDaysCountAndZeroMeansAll() {
		$page = $this->addPage();
		$this->put($page->id, 'clip.mp4', 1, array('plays' => 4));
		$this->put($page->id, 'clip.mp4', 40, array('plays' => 6));
		$video = TestSite::videos(TestSite::fresh($page))->first();
		$this->assertSame(4, $this->stats()->forVideo($video, 30)['plays']);
		$this->assertSame(10, $this->stats()->forVideo($video, 0)['plays']);
	}

	public function testNothingCountedMeansZerosNotErrors() {
		$page = $this->addPage();
		$s = $this->stats()->forVideo(TestSite::videos(TestSite::fresh($page))->first(), 30);
		$this->assertSame(array(0, 0, 0, 0, 0), array($s['plays'], $s['quarter'], $s['half'], $s['threeQuarters'], $s['end']));
	}

	public function testAReuseHasTheCountsOfItsOriginal() {
		$page = $this->addPage();
		$this->put($page->id, 'clip.mp4', 0, array('plays' => 7));
		$original = TestSite::videos(TestSite::fresh($page))->first();
		$reuser = TestSite::page('reuse', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$reuse = TestSite::videos(TestSite::fresh($reuser))->first();
		$this->assertTrue($reuse->isReference());
		$this->assertSame(7, $this->stats()->forVideo($reuse, 30)['plays']);
		$this->assertSame(array($page->id, 'clip.mp4'), $this->stats()->videoKey($reuse));
	}

	public function testDailyHasEveryDayOldestFirst() {
		$page = $this->addPage();
		$this->put($page->id, 'clip.mp4', 0, array('plays' => 2));
		$this->put($page->id, 'clip.mp4', 3, array('plays' => 5, 'p100' => 1));
		$this->put($page->id, 'clip.mp4', 20, array('plays' => 9));
		$days = $this->stats()->daily(TestSite::videos(TestSite::fresh($page))->first(), 7);
		$this->assertCount(7, $days);
		$keys = array_keys($days);
		$this->assertSame(date('Y-m-d'), end($keys));
		$this->assertSame(date('Y-m-d', time() - 6 * 86400), $keys[0]);
		$this->assertSame(2, $days[date('Y-m-d')]['plays']);
		$this->assertSame(5, $days[date('Y-m-d', time() - 3 * 86400)]['plays']);
		$this->assertSame(1, $days[date('Y-m-d', time() - 3 * 86400)]['p100']);
		$this->assertSame(7, array_sum(array_column($days, 'plays')), 'the day 20 days ago is outside');
	}

	public function testFirstDay() {
		$page = $this->addPage();
		$video = TestSite::videos(TestSite::fresh($page))->first();
		$this->assertSame('', $this->stats()->firstDay($video));
		$this->put($page->id, 'clip.mp4', 12, array('plays' => 1));
		$this->put($page->id, 'clip.mp4', 3, array('plays' => 1));
		$this->put($page->id, 'clip.mp4', 40, array('plays' => 0, 'p25' => 1));
		$this->assertSame(date('Y-m-d', time() - 12 * 86400), $this->stats()->firstDay($video));
	}

	public function testRemoveForgetsOneVideoOrAllOfAPage() {
		$this->put(1, 'a.mp4', 0, array('plays' => 1));
		$this->put(1, 'b.mp4', 0, array('plays' => 1));
		$this->stats()->remove(1, 'a.mp4');
		$this->assertSame(0, TestSite::plays(1, 'a.mp4'));
		$this->assertSame(1, TestSite::plays(1, 'b.mp4'));
		$this->stats()->remove(1);
		$this->assertSame(0, TestSite::plays(1, 'b.mp4'));
	}

	public function testCountsOlderThanTheLimitAreDropped() {
		$limit = \ProcessWire\SelfHostedVideoStats::keepDays;
		$this->put(1, 'old.mp4', $limit + 5, array('plays' => 1));
		$this->put(1, 'young.mp4', $limit - 5, array('plays' => 1));
		$this->stats()->purge();
		$this->assertSame(0, TestSite::plays(1, 'old.mp4'));
		$this->assertSame(1, TestSite::plays(1, 'young.mp4'));
		$this->stats()->remove(1);
	}
}
