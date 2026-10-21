<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

/**
 * The markup of a video on the website: what the options switch on and off, and that texts cannot break out of it
 */
class VideoRenderTest extends WriteTestCase {

	protected $page;

	protected function make(array $media = array(), array $set = array()) {
		$page = TestSite::page('r-' . uniqid(), function($p) use($media) {
			TestSite::videos($p)->add(TestSite::file('clip.mp4', 'ONE', $media));
		});
		if(count($set)) {
			$v = TestSite::videos(TestSite::fresh($page))->first();
			foreach($set as $key => $value) $v->$key = $value;
			$v->page->save();
		}
		$this->page = $page;
		return TestSite::videos(TestSite::fresh($page))->first();
	}

	protected function render($video, array $options = array()) {
		$this->module()->markup()->reset();
		// (without a CSS framework: the website being tested may have chosen one, which adds class names)
		return $video->render($options + array('includeall' => true, 'framework' => false));
	}

	public function testThePlayerHasTheVideoItsSizeAndTheFile() {
		$v = $this->make(array('width' => 1280, 'height' => 720, 'seconds' => 65), array('title' => 'My video'));
		$html = $this->render($v);
		$this->assertStringContainsString('<figure class=\'shv-video shv-video--landscape\'', $html);
		$this->assertStringContainsString('<video class=\'shv-video__video\' controls', $html);
		$this->assertStringContainsString("width='1280' height='720'", $html);
		$this->assertStringContainsString("type='video/mp4'", $html);
		$this->assertStringContainsString('/clip.mp4', $html);
		$this->assertStringContainsString("data-shv-video='{$v->page->id}:clip.mp4'", $html);
		$this->assertStringContainsString('My video', $html);
		$this->assertStringContainsString("datetime='PT1M5S'", $html);
	}

	public function testAPortraitVideoIsMarkedAsOne() {
		$v = $this->make(array('width' => 720, 'height' => 1280));
		$this->assertStringContainsString('shv-video--portrait', $this->render($v));
	}

	public function testOptionsSwitchThingsOnAndOff() {
		$v = $this->make();
		$html = $this->render($v, array('controls' => false, 'autoplay' => true, 'muted' => true, 'loop' => true));
		$this->assertDoesNotMatchRegularExpression('/<video[^>]* controls/', $html);
		$this->assertMatchesRegularExpression('/<video[^>]* autoplay/', $html);
		$this->assertMatchesRegularExpression('/<video[^>]* muted/', $html);
		$this->assertMatchesRegularExpression('/<video[^>]* loop/', $html);
		$plain = $this->render($v);
		$this->assertMatchesRegularExpression('/<video[^>]* controls/', $plain);
		$this->assertDoesNotMatchRegularExpression('/<video[^>]* autoplay/', $plain);
	}

	public function testTheCaptionCanBeLeftOut() {
		$v = $this->make(array(), array('title' => 'My video'));
		$this->assertStringContainsString('<figcaption', $this->render($v));
		$this->assertStringNotContainsString('<figcaption', $this->render($v, array('caption' => false)));
	}

	public function testTextsCannotBreakOutOfTheMarkup() {
		$evil = '</script><script>alert(1)</script><img src=x onerror=alert(2)>"\'';
		$v = $this->make(array(), array('title' => $evil, 'description' => $evil));
		$html = $this->render($v);
		$this->assertStringNotContainsString('<script>alert', $html);
		$this->assertStringNotContainsString('<img src=x', $html);
		$this->assertStringNotContainsString('</script><script>', $html);
		$this->assertSame(2, substr_count($html, '<script'), 'only the own script and the structured data');
		// the structured data can be read back as it was written
		preg_match('~<script type=\'application/ld\+json\'>(.*?)</script>~s', $html, $m);
		$data = json_decode($m[1], true);
		$this->assertSame($evil, $data['name']);
	}

	public function testStructuredDataDescribesTheVideo() {
		$v = $this->make(array('seconds' => 93, 'width' => 1920, 'height' => 1080), array('title' => 'My video', 'description' => 'About it', 'tags' => 'a, b'));
		$d = $v->structuredData();
		$this->assertSame('VideoObject', $d['@type']);
		$this->assertSame('My video', $d['name']);
		$this->assertSame('About it', $d['description']);
		$this->assertSame('PT1M33S', $d['duration']);
		$this->assertSame('video/mp4', $d['encodingFormat']);
		$this->assertSame(1920, $d['width']);
		$this->assertSame('a, b', $d['keywords']);
		$this->assertMatchesRegularExpression('~^https?://[^/]+/.*clip\.mp4$~', $d['contentUrl']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $d['uploadDate']);
	}

	public function testMetaTagsForSharing() {
		$v = $this->make(array('width' => 1920, 'height' => 1080));
		$meta = $v->metaTags();
		$this->assertStringContainsString("<meta property='og:video' content='http", $meta);
		$this->assertStringContainsString("<meta property='og:video:type' content='video/mp4'>", $meta);
		$this->assertStringContainsString("<meta property='og:video:width' content='1920'>", $meta);
	}

	public function testTheSameVideoTwiceOnAPageGetsDifferentIds() {
		$v = $this->make();
		$this->module()->markup()->reset();
		$a = $v->render(array('includeall' => true, 'framework' => false));
		$b = $v->render(array('includeall' => true, 'framework' => false));
		preg_match_all("/<(?:figure|div)[^>]* id='(video-[^']+)'/", $a . $b, $m);
		$this->assertCount(2, $m[1]);
		$this->assertNotSame($m[1][0], $m[1][1]);
	}

	public function testAReuseShowsTheFileOfItsOriginal() {
		$original = $this->make(array(), array('title' => 'Shared'));
		$reuser = TestSite::page('reuser', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$reuse = TestSite::videos(TestSite::fresh($reuser))->first();
		$html = $this->render($reuse);
		$this->assertStringContainsString("/{$original->page->id}/clip.mp4", $html);
		$this->assertStringContainsString("data-shv-video='{$original->page->id}:clip.mp4'", $html, 'plays are counted for the original');
		$this->assertStringContainsString('Shared', $html);
	}

	public function testCaptionsBecomeTracks() {
		$v = $this->make();
		$file = sys_get_temp_dir() . '/shvtest-' . uniqid() . '.vtt';
		file_put_contents($file, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello\n");
		$v->setCaptions('de', $file);
		$v->page->save();
		$html = $this->render(TestSite::videos(TestSite::fresh($this->page))->first());
		$this->assertMatchesRegularExpression("/<track [^>]*srclang='de'/", $html);
	}

	public function testChaptersAreListed() {
		$v = $this->make(array('seconds' => 120));
		$v->setChapters(array(array('time' => 0, 'title' => 'The beginning'), array('time' => 60, 'title' => 'The middle')));
		$v->page->save();
		$html = $this->render(TestSite::videos(TestSite::fresh($this->page))->first());
		$this->assertStringContainsString('shv-video__chapter-list', $html);
		$this->assertStringContainsString('The beginning', $html);
		$this->assertStringContainsString("data-shv-seek='60'", $html);
		$without = $this->render(TestSite::videos(TestSite::fresh($this->page))->first(), array('chapters' => false));
		$this->assertStringNotContainsString('shv-video__chapter-list', $without);
		$this->assertStringContainsString('"hasPart"', $without, 'search engines still get them');
	}

	public function testTheButtonAfterTheVideoOpensOtherWebsitesInANewWindow() {
		$v = $this->make();
		$v->endAction('link');
		$v->endLink('https://other.example/page');
		$v->endText(null, 'Read on');
		$v->page->save();
		$html = $this->render(TestSite::videos(TestSite::fresh($this->page))->first());
		$this->assertStringContainsString("target='_blank'", $html);
		$this->assertStringContainsString("rel='noopener'", $html);
		$v = TestSite::videos(TestSite::fresh($this->page))->first();
		$v->endLink('/contact/');
		$v->page->save();
		$html = $this->render(TestSite::videos(TestSite::fresh($this->page))->first());
		$this->assertStringContainsString("href='/contact/'", $html);
		$this->assertStringNotContainsString("target='_blank'", $html);
	}

	// video tags in a text, with a video behind them

	public function testATagInATextShowsTheVideo() {
		$v = $this->make(array(), array('title' => 'In the text'));
		$formatter = \ProcessWire\wire('modules')->get('TextformatterSelfHostedVideo');
		$page = TestSite::fresh($this->page);
		$this->module()->markup()->reset();
		$html = $formatter->replaceTags('<p>Before [[video CLIP.mp4 align=right width=40 nocaption]] after</p>', $page);
		$this->assertStringContainsString('<p>Before</p>', $html);
		$this->assertStringContainsString('<p>after</p>', $html);
		$this->assertStringContainsString('shv-video-text--right', $html);
		$this->assertStringContainsString("style='--shv-width:40%'", $html);
		$this->assertSame(1, substr_count($html, '<video '));
		$this->assertStringNotContainsString('<figcaption', $html);
	}

	public function testATagForAVideoThePageDoesNotHaveIsACommentNotAnError() {
		$this->make();
		$formatter = \ProcessWire\wire('modules')->get('TextformatterSelfHostedVideo');
		$html = $formatter->replaceTags('<p>[[video other.mp4]]</p>', TestSite::fresh($this->page));
		$this->assertStringContainsString('<!-- video “other.mp4” not found', $html);
	}

	public function testATagAlsoFindsAVideoThePageReuses() {
		$original = $this->make();
		$reuser = TestSite::page('reuser', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$formatter = \ProcessWire\wire('modules')->get('TextformatterSelfHostedVideo');
		$this->module()->markup()->reset();
		$html = $formatter->replaceTags('<p>[[video clip.mp4]]</p>', TestSite::fresh($reuser));
		$this->assertSame(1, substr_count($html, '<video '));
	}
}
