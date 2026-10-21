<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/HttpTestCase.php';

/**
 * The page of a website with several languages: each address shows the texts of its language, and a text that has
 * no translation shows the default language's. (Needs a website with a second language and addresses per language,
 * otherwise skipped.)
 */
class WebsiteLanguagesTest extends HttpTestCase {

	protected $second = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$languages = \ProcessWire\wire('languages');
		if(!$languages || $languages->count() < 2 || !\ProcessWire\wire('modules')->isInstalled('LanguageSupportPageNames')) {
			TestSite::end();
			self::markTestSkipped('The website has no second language with addresses of its own (LanguageSupportPageNames).');
		}
	}

	protected function setUp(): void {
		parent::setUp();
		foreach(\ProcessWire\wire('languages') as $language) {
			if(!$language->isDefault()) { $this->second = $language; break; }
		}
	}

	/** A page that is open in both languages, with a title of the video in each */
	protected function twoLanguagePage($name, $english, $other) {
		$second = $this->second;
		$page = TestSite::page($name, function($page) use($second, $name) {
			TestSite::videos($page)->add(TestSite::file('clip.mp4', 'LANG'));
			$page->set('name' . $second->id, 'sprache-' . $name);
			$page->set('status' . $second->id, 1);
		});
		$video = TestSite::videos($page)->first();
		$video->title(array('default' => $english, $second->name => $other));
		$page->save();
		return TestSite::fresh($page);
	}

	/** Path of the page in a language, as the website answers it */
	protected function pathIn($page, $language) {
		$root = rtrim(\ProcessWire\wire('config')->urls->root, '/');
		$url = $this->localUrl($page, $language);
		$this->assertNotSame('', $url, 'the page has an address in ' . $language->name);
		// (a script that does not run under the address of the website may not know the folder or the slash in front)
		if(strlen($root) && strpos($url, $root . '/') === 0) $url = substr($url, strlen($root));
		return '/' . ltrim($url, '/');
	}

	protected function languageOfDefault() {
		return \ProcessWire\wire('languages')->getDefault();
	}

	public function testEachAddressShowsTheTitlesOfItsLanguage() {
		$page = $this->twoLanguagePage('lang-a', 'Mountain lake', 'Bergsee');

		$en = $this->http('GET', $this->pathIn($page, $this->languageOfDefault()));
		$this->assertSame(200, $en['status'], $en['status'] === 200 ? '' : $this->describe($page, $en));
		$de = $this->http('GET', $this->pathIn($page, $this->second));
		$this->assertSame(200, $de['status'], $de['status'] === 200 ? '' : $this->describe($page, $de));

		$this->assertStringContainsString('Mountain lake', $this->mainOf($en['body']));
		$this->assertStringNotContainsString('Bergsee', $this->mainOf($en['body']), 'the other language does not leak into the default one');
		$this->assertStringContainsString('Bergsee', $this->mainOf($de['body']));
		$this->assertStringNotContainsString('Mountain lake', $this->mainOf($de['body']), 'nor the default into the other');
		$this->assertNoModuleError($en['body']);
		$this->assertNoModuleError($de['body']);
	}

	public function testATitleThatIsNotTranslatedShowsTheDefaultLanguage() {
		$page = $this->twoLanguagePage('lang-b', 'Only English', '');
		$de = $this->http('GET', $this->pathIn($page, $this->second));
		$this->assertSame(200, $de['status'], $de['status'] === 200 ? '' : $this->describe($page, $de));
		$this->assertStringContainsString('Only English', $this->mainOf($de['body']));
	}

	public function testBothLanguagesPlayTheSameFileAndCountTheSameVideo() {
		$page = $this->twoLanguagePage('lang-c', 'One', 'Eins');
		$video = TestSite::videos($page)->first();
		foreach(array($this->languageOfDefault(), $this->second) as $language) {
			$r = $this->http('GET', $this->pathIn($page, $language));
			$this->assertStringContainsString(basename($this->filePath($video)), $r['body'], $language->name . ': the file');
			$this->assertStringContainsString('data-shv-video=\'' . $page->id . ':clip.mp4\'', $r['body'], $language->name . ': what plays are counted by');
		}
	}

	public function testAPageClosedInOneLanguageStaysClosedThere() {
		$page = $this->twoLanguagePage('lang-d', 'Open', 'Offen');
		$page->set('status' . $this->second->id, 0);
		$page->save();
		$page = TestSite::fresh($page);
		$en = $this->http('GET', $this->pagePath($page));
		$this->assertSame(200, $en['status'], $en['status'] === 200 ? '' : $this->describe($page, $en));
		$this->assertStringContainsString('<video', $en['body']);
		$de = $this->http('GET', '/' . $this->second->name . '/sprache-lang-d/');
		$this->assertNotSame(200, $de['status'], 'the language is switched off for the page');
		$this->assertStringNotContainsString('Offen', $de['body']);
	}
}
