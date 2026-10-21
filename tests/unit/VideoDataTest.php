<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

/**
 * What a video knows and keeps: its file's properties, texts, end of the video, chapters, overlays, captions, reuse
 */
class VideoDataTest extends WriteTestCase {

	protected function make(array $media = array(), $name = 'clip.mp4', $seed = 'ONE') {
		return TestSite::page('v-' . uniqid(), function($page) use($media, $name, $seed) {
			TestSite::videos($page)->add(TestSite::file($name, $seed, $media));
		});
	}

	protected function load($page, $name = 'clip.mp4') {
		return TestSite::videos(TestSite::fresh($page))->getFile($name);
	}

	protected function save($video) {
		$video->page->save();
		return $video->page->id;
	}

	protected function vtt($text = "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\nHello\n") {
		$file = sys_get_temp_dir() . '/shvtest-' . uniqid() . '.vtt';
		file_put_contents($file, $text);
		return $file;
	}

	// the file

	public function testItsLengthAndSizeAreReadFromTheFile() {
		$v = $this->load($this->make(array('seconds' => 93.4, 'width' => 1920, 'height' => 1080)));
		$this->assertSame(93.4, $v->duration());
		$this->assertSame('1:33', $v->durationStr());
		$this->assertSame(1920, $v->width());
		$this->assertSame(1080, $v->height());
		$this->assertSame(1.78, $v->ratio());
		$this->assertFalse($v->isPortrait());
	}

	public function testAVideoStoredSidewaysIsPortrait() {
		$v = $this->load($this->make(array('width' => 1920, 'height' => 1080, 'rotation' => 90)));
		$this->assertSame(1080, $v->width());
		$this->assertTrue($v->isPortrait());
		$this->assertSame(0.56, $v->ratio());
	}

	public function testLongVideosHaveHours() {
		$v = $this->load($this->make(array('seconds' => 3725)));
		$this->assertSame('1:02:05', $v->durationStr());
	}

	public function testAFileThatIsNoVideoKnowsNothing() {
		$page = TestSite::page('nov', function($p) {
			$file = sys_get_temp_dir() . '/shvtest-garbage.mp4';
			file_put_contents($file, str_repeat('not a video ', 200));
			TestSite::videos($p)->add($file);
		});
		$v = $this->load($page, 'shvtest-garbage.mp4');
		$this->assertSame(0.0, $v->duration());
		$this->assertSame('', $v->durationStr());
		$this->assertSame(0.0, $v->ratio());
		$this->assertFalse($v->isPortrait());
	}

	// texts

	public function testTitleDescriptionAndTagsAreKept() {
		$page = $this->make();
		$v = $this->load($page);
		$v->title = 'A title';
		$v->description = 'A description';
		$v->tags = 'one, two three';
		$this->save($v);
		$v = $this->load($page);
		$this->assertSame('A title', $v->title);
		$this->assertSame('A description', $v->description);
		$this->assertSame('one, two three', $v->tags);
	}

	public function testTitleIsEncodedOnlyWhenOutputFormattingIsOn() {
		$page = $this->make();
		$v = $this->load($page);
		$v->title = 'Tom & "Jerry" <3';
		$this->save($v);
		$this->assertSame('Tom & "Jerry" <3', TestSite::videos(TestSite::fresh($page))->first()->title);
		\ProcessWire\wire('pages')->uncacheAll();
		$formatted = \ProcessWire\wire('pages')->get($page->id);
		$formatted->of(true);
		$this->assertStringNotContainsString('<3', (string) $formatted->shvtest_videos->first()->title);
		$this->assertStringContainsString('&lt;3', (string) $formatted->shvtest_videos->first()->title);
	}

	// end of the video

	public function testEndActionIsOneOfTheKnownOnes() {
		$page = $this->make();
		$v = $this->load($page);
		$this->assertSame('', $v->endAction());
		$v->endAction('link');
		$this->save($v);
		$this->assertSame('link', $this->load($page)->endAction());
		$v = $this->load($page);
		$v->endAction('explode');
		$this->save($v);
		$this->assertSame('', $this->load($page)->endAction());
	}

	public function testEndLinkKeepsOnlyUsableAddresses() {
		$page = $this->make();
		$v = $this->load($page);
		$v->endLink('https://example.org/x');
		$this->save($v);
		$this->assertSame('https://example.org/x', $this->load($page)->endLink());
		$v = $this->load($page);
		$v->endLink('javascript:alert(1)');
		$this->save($v);
		$this->assertSame('', $this->load($page)->endLink());
	}

	public function testEmbeddingIsAllowBlockOrTheSiteSetting() {
		$page = $this->make();
		$v = $this->load($page);
		$v->embed('block');
		$this->save($v);
		$this->assertSame('block', $this->load($page)->embed());
		$this->assertTrue($this->load($page)->embedBlocked());
		$v = $this->load($page);
		$v->embed('nonsense');
		$this->save($v);
		$this->assertSame('', $this->load($page)->embed());
	}

	// chapters and overlays

	public function testChaptersAreSortedAndKnowTheirEnd() {
		$page = $this->make(array('seconds' => 120));
		$v = $this->load($page);
		$v->setChapters(array(array('time' => '1:00', 'title' => 'Second'), array('time' => 0, 'title' => 'First'), array('time' => '3:00', 'title' => 'Too late')));
		$this->save($v);
		$chapters = array_values($this->load($page)->chapters());
		$this->assertSame(array('First', 'Second', 'Too late'), array_map(function($c) { return $c->title; }, $chapters));
		$this->assertSame('1:00', $chapters[1]->timeStr);
		$this->assertEquals(60, $chapters[0]->end, 'a chapter ends where the next begins');
		$this->assertEquals(120, $chapters[1]->end, 'the last one at the end of the video');
		$this->assertEquals(120, $chapters[2]->time, 'a time behind the end is moved to it');
	}

	public function testNoChaptersNoList() {
		$this->assertSame(array(), $this->load($this->make())->chapters());
	}

	public function testOverlaysKeepPositionAndLink() {
		$page = $this->make(array('seconds' => 60));
		$v = $this->load($page);
		$v->setOverlays(array(array('text' => 'Hello', 'x' => 90, 'y' => 5, 'url' => '/contact/', 'when' => 'range', 'start' => '0:10', 'end' => '0:20')));
		$this->save($v);
		$o = array_values($this->load($page)->overlays());
		$this->assertCount(1, $o);
		$this->assertSame('Hello', $o[0]->text);
		$this->assertSame('/contact/', $o[0]->url);
		$this->assertEquals(90, $o[0]->x);
		$this->assertSame('right', $o[0]->anchorX);
		$this->assertSame('top', $o[0]->anchorY);
		$this->assertSame('range', $o[0]->when);
		$this->assertEquals(10, $o[0]->start);
		$this->assertEquals(20, $o[0]->end);
	}

	// captions

	public function testCaptionsAreStoredAsWebVttAndCanBeRemoved() {
		$page = $this->make();
		$v = $this->load($page);
		$this->assertFalse($v->hasCaptions());
		$v->setCaptions('de', $this->vtt());
		$this->save($v);
		$v = $this->load($page);
		$this->assertTrue($v->hasCaptions());
		$this->assertTrue($v->hasCaptions('de'));
		$this->assertFalse($v->hasCaptions('en'));
		$this->assertArrayHasKey('de', (array) $v->captions());
		$v->removeCaptions('de');
		$this->save($v);
		$this->assertFalse($this->load($page)->hasCaptions());
	}

	public function testCaptionsInSubRipAreConverted() {
		$page = $this->make();
		$v = $this->load($page);
		$track = $v->setCaptions('en', $this->vtt("1\n00:00:01,000 --> 00:00:02,000\nHello\n"));
		$this->assertTrue($track->converted);
	}

	public function testCaptionsThatAreNoCaptionsAreRefused() {
		$v = $this->load($this->make());
		foreach(array('Just text', '', "WEBVTT\n\n00:00:05.000 --> 00:00:04.000\nBackwards\n") as $text) {
			try {
				$v->setCaptions('de', $this->vtt($text));
				$this->fail('refused: ' . var_export($text, true));
			} catch(\ProcessWire\WireException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
		$this->expectException(\ProcessWire\WireException::class);
		$v->setCaptions('not a language', $this->vtt());
	}

	// reuse

	public function testAReuseShowsWhatItsOriginalHas() {
		$page = $this->make(array('seconds' => 60));
		$v = $this->load($page);
		$v->title = 'Original title';
		$v->endAction('replay');
		$this->save($v);
		$original = $this->load($page);
		$reuser = TestSite::page('reuser', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$reuse = TestSite::videos(TestSite::fresh($reuser))->first();
		$this->assertTrue($reuse->isReference());
		$this->assertFalse($original->isReference());
		$this->assertSame($original->page->id, $reuse->original()->page->id);
		$this->assertSame('Original title', $reuse->title);
		$this->assertSame('replay', $reuse->endAction());
		$this->assertSame(60.0, $reuse->duration());
		$this->assertTrue($original->isReferenced());
		$this->assertSame(array($reuser->id), array_values($original->references()->explode('id')));
	}

	public function testAReuseCannotChangeChaptersOrCaptions() {
		$page = $this->make();
		$original = $this->load($page);
		$reuser = TestSite::page('reuser', function($p) use($original) { TestSite::videos($p)->addReference($original); });
		$reuse = TestSite::videos(TestSite::fresh($reuser))->first();
		$this->assertSame(array(), $reuse->setChapters(array(array('time' => 1, 'title' => 'No'))));
		$this->expectException(\ProcessWire\WireException::class);
		$reuse->setCaptions('de', $this->vtt());
	}

	public function testRenamingGivesACleanName() {
		$page = $this->make();
		$p = TestSite::fresh($page);
		TestSite::videos($p)->rename(TestSite::videos($p)->getFile('clip.mp4'), 'My new Clip!.MP4');
		$p->save();
		$p = TestSite::fresh($page);
		$this->assertCount(1, TestSite::videos($p));
		$name = TestSite::videos($p)->first()->basename();
		$this->assertMatchesRegularExpression('/^[a-z0-9._-]+\.mp4$/', $name);
		$this->assertStringContainsString('new', $name);
		$this->assertNull(TestSite::videos($p)->getFile('clip.mp4'));
	}
}
