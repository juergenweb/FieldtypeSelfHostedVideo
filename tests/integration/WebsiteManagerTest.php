<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/AdminHttpTestCase.php';

/**
 * The list of the video manager with more videos than one page holds: the pages, the order, the filters, and addresses
 * that are made up.
 */
class WebsiteManagerTest extends AdminHttpTestCase {

	protected $total = 0;
	protected $perPage = 10;
	protected $tag = '';

	protected function setUp(): void {
		parent::setUp();
		$this->perPage = (int) \ProcessWire\wire('modules')->get('ProcessSelfHostedVideos')->perPage();
		$this->total = $this->perPage + 3;
		$this->tag = 'mgrtag' . substr(md5(uniqid()), 0, 6);
	}

	/** Titles of the videos, in the order of their file names: "Mgrlist 01 Quokka" ... */
	protected function title($n) {
		return sprintf('Mgrlist %02d Quokka', $n);
	}

	/** One page with enough videos for three pages of the list; file names v01.mp4 ... */
	protected function manyVideos() {
		return $this->inDefault(function() {
			$page = TestSite::page('mgr', function($page) {
				for($n = 1; $n <= $this->total; $n++) {
					TestSite::videos($page)->add(TestSite::file(sprintf('v%02d.mp4', $n), sprintf('MGR%02d', $n), array('seconds' => $n * 10)));
				}
			});
			foreach(TestSite::videos($page) as $video) {
				$n = (int) substr($video->name, 1, 2);
				$video->title = $this->title($n);
				if($n % 2 === 0) $video->tags = $this->tag;
			}
			$page->save();
			return TestSite::fresh($page);
		});
	}

	protected function list($query) {
		$r = $this->asUser(self::userName, $this->adminPath('setup/videos/?' . $query));
		$this->assertSame(200, $r['status'], 'GET ' . $r['url']);
		$this->assertNoModuleError($r['body']);
		return $r['body'];
	}

	/** Numbers of the videos named in the list, in the order they appear */
	protected function numbersIn($body) {
		preg_match_all('~Mgrlist (\d\d) Quokka~', $body, $m);
		return array_values(array_unique(array_map('intval', $m[1])));
	}

	/** Numbers of the videos on page $n of a list in file name order, as they should be */
	protected function expectedPage($n, $total = null) {
		return array_slice(range(1, $total === null ? $this->total : $total), ($n - 1) * $this->perPage, $this->perPage);
	}

	protected function lastPage() {
		return (int) ceil($this->total / $this->perPage);
	}

	public function testTheListHasPagesOfTheSameSize() {
		$this->manyVideos();
		$seen = array();
		for($n = 1; $n <= $this->lastPage(); $n++) {
			$numbers = $this->numbersIn($this->list('q=Quokka&sort=name&pg=' . $n));
			$this->assertSame($this->expectedPage($n), $numbers, "page $n of " . $this->lastPage() . ' (' . $this->perPage . ' on a page)');
			$this->assertSame(array(), array_intersect($seen, $numbers), 'nothing twice');
			$seen = array_merge($seen, $numbers);
		}
		$this->assertSame(range(1, $this->total), $seen, 'and nothing missing');
	}

	public function testTheListNamesThePagesAndLinksToThem() {
		$this->manyVideos();
		$body = $this->list('q=Quokka&sort=name');
		$last = $this->lastPage();
		$this->assertMatchesPattern('~pg=2~', $body, 'a link to the second page');
		$this->assertMatchesPattern('~pg=' . $last . '[^0-9]~', $body, 'and to the last (' . $last . ')');
		$this->assertDoesNotMatchPattern('~pg=' . ($last + 1) . '[^0-9]~', $body, 'but not to one behind it');
		$this->assertMatchesPattern('~sort=name~', $body, 'the order is kept in the links');
		$this->assertMatchesPattern('~q=Quokka~', $body, 'and the search');
	}

	public function testTheOrderCanBeChanged() {
		$this->manyVideos();
		$name = $this->numbersIn($this->list('q=Quokka&sort=name'));
		$this->assertSame($this->expectedPage(1), $name);
		$longest = $this->numbersIn($this->list('q=Quokka&sort=longest'));
		$this->assertSame($this->total, $longest[0], 'the longest first (' . ($this->total * 10) . ' seconds)');
		if($this->perPage > 1) $this->assertSame($this->total - 1, $longest[1]);
	}

	public function testAddressesThatAreMadeUpAreAnsweredNormally() {
		$this->manyVideos();
		foreach(array('pg=999', 'pg=0', 'pg=-3', 'pg=abc', 'pg[]=2', 'sort=nonsense', 'sort[]=name', 'show=nonsense', 'tag=nonsense', 'field=nonsense', 'check=nonsense', 'q[]=x') as $query) {
			$r = $this->asUser(self::userName, $this->adminPath('setup/videos/?q=Quokka&' . $query));
			$this->assertSame(200, $r['status'], $query);
			$this->assertNoModuleError($r['body']);
			$page = $this->withoutDebugBar($r['body']);
			foreach(array('Fatal error', 'Uncaught', 'Stack trace') as $word) {
				$this->assertFalse(strpos($page, $word) !== false, "$query: \"$word\" is in the page: " . $this->around($page, $word));
			}
		}
	}

	public function testAPageBehindTheEndShowsTheLastOrNothingButNoError() {
		$this->manyVideos();
		$numbers = $this->numbersIn($this->list('q=Quokka&sort=name&pg=999'));
		$this->assertTrue($numbers === array() || $numbers === $this->expectedPage($this->lastPage()), 'the last page or an empty one: ' . implode(',', $numbers));
	}

	public function testATagNarrowsTheList() {
		$this->manyVideos();
		$even = $this->numbersIn($this->list('q=Quokka&sort=name&tag=' . $this->tag));
		$expected = array_values(array_filter(range(1, $this->total), function($n) { return $n % 2 === 0; }));
		$this->assertSame(array_slice($expected, 0, $this->perPage), $even, 'only the videos with the tag');
		if(count($expected) > $this->perPage) {
			$this->assertSame(array_slice($expected, $this->perPage, $this->perPage), $this->numbersIn($this->list('q=Quokka&sort=name&pg=2&tag=' . $this->tag)), 'also on the second page');
		}
	}

	public function testTheSearchFindsByFileNameToo() {
		$this->manyVideos();
		$numbers = $this->numbersIn($this->list('q=v03.mp4'));
		$this->assertSame(array(3), $numbers);
	}

	public function testWhatIsSearchedForCannotBreakOutOfThePage() {
		$this->manyVideos();
		$evil = '"><script>window.hacked=1</script>';
		$body = $this->list('q=' . rawurlencode($evil));
		$this->assertStringNotContainsString('<script>window.hacked', $body);
		$this->assertStringContainsString(htmlspecialchars($evil, ENT_QUOTES, 'UTF-8'), preg_replace('~&#0*39;|&apos;~', '&#039;', $body), 'it is shown as text');
	}

	public function testNothingFoundIsSaidSoAndNoVideoIsListed() {
		$this->manyVideos();
		$body = $this->list('q=' . rawurlencode('nothing-like-this-' . uniqid()));
		$this->assertSame(array(), $this->numbersIn($body));
	}

	protected function assertDoesNotMatchPattern($pattern, $text, $message = '') {
		$this->assertTrue(preg_match($pattern, $text) === 0, $message . ' (' . $pattern . ')');
	}
}
