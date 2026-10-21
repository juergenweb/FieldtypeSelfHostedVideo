<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

/**
 * Texts of a video in several languages (needs a website with at least two languages, otherwise skipped):
 * a text in each language, the default language as the fallback, and a field that is not multi-language
 */
class LanguagesTest extends WriteTestCase {

	protected $second = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$languages = \ProcessWire\wire('languages');
		if(!$languages || $languages->count() < 2) {
			TestSite::end();
			self::markTestSkipped('The website has no second language.');
		}
	}

	protected function setUp(): void {
		parent::setUp();
		foreach(\ProcessWire\wire('languages') as $language) {
			if(!$language->isDefault()) { $this->second = $language; break; }
		}
	}

	protected function video($title = null) {
		$page = TestSite::page('l-' . uniqid(), function($page) {
			TestSite::videos($page)->add(TestSite::file('clip.mp4'));
		});
		$video = TestSite::videos($page)->first();
		if($title !== null) {
			$video->title(array('default' => $title[0], $this->second->name => $title[1]));
			$video->page->save();
			$video = TestSite::videos(TestSite::fresh($page))->first();
		}
		return $video;
	}

	protected function inLanguage($language, $fn) {
		$user = \ProcessWire\wire('user');
		$was = $user->language;
		$user->language = $language;
		try {
			return $fn();
		} finally {
			$user->language = $was;
		}
	}

	public function testATitleIsKeptForEachLanguage() {
		$video = $this->video(array('Hello', 'Hallo'));
		$this->assertSame('Hello', $video->title('default'));
		$this->assertSame('Hallo', $video->title($this->second));
		$this->assertSame('Hallo', $video->title($this->second->name));
		$this->assertSame('Hallo', $video->title($this->second->id));
	}

	public function testTheTitleIsGivenInTheLanguageOfTheUser() {
		$video = $this->video(array('Hello', 'Hallo'));
		$default = \ProcessWire\wire('languages')->getDefault();
		$this->assertSame('Hello', $this->inLanguage($default, function() use($video) { return $video->title(); }));
		$this->assertSame('Hallo', $this->inLanguage($this->second, function() use($video) { return $video->title(); }));
	}

	public function testWithoutATextInTheLanguageOfTheUserTheDefaultOneIsGiven() {
		$video = $this->video(array('Hello', ''));
		$this->assertSame('Hello', $this->inLanguage($this->second, function() use($video) { return $video->title(); }));
		$this->assertSame('', $video->title($this->second), 'asked for that language by name: blank');
	}

	public function testAllTitlesAsAnArrayByLanguageName() {
		$video = $this->video(array('Hello', 'Hallo'));
		$all = $video->title(true);
		$this->assertSame('Hello', $all['default']);
		$this->assertSame('Hallo', $all[$this->second->name]);
	}

	public function testSettingATextOfOneLanguageLeavesTheOthers() {
		$video = $this->video(array('Hello', 'Hallo'));
		$video->title($this->second, 'Servus');
		$video->page->save();
		$video = TestSite::videos(TestSite::fresh($video->page))->first();
		$this->assertSame('Hello', $video->title('default'));
		$this->assertSame('Servus', $video->title($this->second));
	}

	public function testTheDescriptionHasItsOwnTextsToo() {
		$video = $this->video();
		$video->langText('description', $this->second, 'Beschreibung');
		$video->langText('description', 'default', 'Description');
		$video->page->save();
		$video = TestSite::videos(TestSite::fresh($video->page))->first();
		$this->assertSame('Beschreibung', $video->langText('description', $this->second));
		$this->assertSame('Description', $video->langText('description', 'default'));
	}

	public function testAReuseShowsTheTextsOfItsOriginal() {
		$video = $this->video(array('Hello', 'Hallo'));
		$reuser = TestSite::page('lr', function($p) use($video) { TestSite::videos($p)->addReference($video); });
		$reuse = TestSite::videos(TestSite::fresh($reuser))->first();
		$this->assertTrue($reuse->isReference());
		$this->assertSame('Hallo', $reuse->title($this->second));
		$this->assertSame('Hello', $reuse->title('default'));
	}

	public function testTheSearchOfTheVideoManagerFindsTextsOfAnyLanguage() {
		$this->video(array('Sunrise', 'Sonnenaufgang'));
		$finder = $this->module()->finder();
		$finder->forget();
		$this->assertSame(1, $finder->find(array('field' => 'shvtest_videos', 'q' => 'Sonnenaufgang'))['total']);
		$this->assertSame(1, $finder->find(array('field' => 'shvtest_videos', 'q' => 'sunrise'))['total']);
	}

	public function testAFieldThatIsNotMultiLanguageKeepsOneTextForAll() {
		$field = \ProcessWire\wire('fields')->get('shvtest_videos');
		$field->set('noLang', 1);
		$field->save();
		try {
			$video = $this->video();
			$video->title($this->second, 'Only one');
			$video->page->save();
			$video = TestSite::videos(TestSite::fresh($video->page))->first();
			$this->assertSame('Only one', $video->title('default'));
		} finally {
			$field->set('noLang', 0);
			$field->save();
		}
	}
}
