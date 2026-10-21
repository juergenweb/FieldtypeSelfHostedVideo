<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/WriteTestCase.php';

/**
 * Files that are not what they should be: empty, cut off, not a video at all, odd names. They have to be saved
 * without an error, say nothing wrong about themselves, and not harm the rest (the Video Manager, a page's other videos).
 */
class OddFilesTest extends WriteTestCase {

	protected function put($name, $bytes) {
		$file = sys_get_temp_dir() . '/shvtest-odd-' . getmypid() . '-' . $name;
		file_put_contents($file, $bytes);
		return $file;
	}

	protected function pageWith($path) {
		return TestSite::page('odd-' . uniqid(), function($page) use($path) { TestSite::videos($page)->add($path); });
	}

	protected function first($page) {
		return TestSite::videos(TestSite::fresh($page))->first();
	}

	public function testAnEmptyFileIsSavedAndKnowsNothing() {
		$video = $this->first($this->pageWith($this->put('empty.mp4', '')));
		$this->assertNotNull($video);
		$this->assertSame(0.0, $video->duration());
		$this->assertSame(0, $video->filesize());
		$this->assertNull($video->mediaInfo()['faststart']);
		$this->assertFalse($video->mediaInfo()['known']);
	}

	public function testAFileThatIsCutOffIsSavedWithoutAnError() {
		$bytes = MediaFiles::mp4(array('faststart' => true))['bytes'];
		$video = $this->first($this->pageWith($this->put('cut.mp4', substr($bytes, 0, (int) (strlen($bytes) / 2)))));
		$this->assertNotNull($video);
		$this->assertSame('shvtest-odd-' . getmypid() . '-cut.mp4', $video->basename());
		$this->assertTrue(is_float($video->duration()));
	}

	public function testATextFileCalledMp4IsNotAKnownVideo() {
		$video = $this->first($this->pageWith($this->put('fake.mp4', "This is not a video.\nJust text.")));
		$this->assertFalse($video->mediaInfo()['known']);
		$this->assertSame(0.0, $video->duration());
		$this->assertSame(0.0, $video->ratio());
	}

	public function testAVideoWithoutAnyVideoStreamHasNoSize() {
		$video = $this->first($this->pageWith($this->put('sound.mp4', MediaFiles::mp4(array('width' => 0, 'height' => 0))['bytes'])));
		$this->assertSame(0, $video->width());
		$this->assertSame(0.0, $video->ratio());
	}

	public function testAnOddFileDoesNotStopTheOtherVideosOfThePageFromBeingRead() {
		$page = TestSite::page('mixed', function($page) {
			TestSite::videos($page)->add(TestSite::file('good.mp4', 'GOOD', array('seconds' => 12.0)));
			TestSite::videos($page)->add(sys_get_temp_dir() . '/' . basename($this->put('bad.mp4', 'garbage')));
		});
		$videos = TestSite::videos(TestSite::fresh($page));
		$this->assertSame(2, $videos->count());
		$this->assertSame(12.0, $videos->getFile('good.mp4')->duration());
	}

	public function testOddFilesAreListedInTheVideoManagerAndSearched() {
		$this->pageWith($this->put('empty.mp4', ''));
		$this->pageWith($this->put('fake2.mp4', 'not a video'));
		$finder = $this->module()->finder();
		$finder->forget();
		$result = $finder->find(array('field' => 'shvtest_videos', 'limit' => 0, 'sort' => 'longest'));
		$this->assertSame(2, $result['total']);
		$finder->forget();
		$this->assertSame(1, $finder->find(array('field' => 'shvtest_videos', 'q' => 'fake2'))['total']);
	}

	public function testNamesWithSpacesAccentsAndCapitalsAreMadeSafe() {
		$names = array('My Holiday Film.MP4', 'Größe über Alles.mp4', "quote'and\"double.mp4", 'a  b   c.mp4');
		// (Windows does not allow a double quote in a file name)
		if(DIRECTORY_SEPARATOR === '\\') $names[2] = "quote'and'single.mp4";
		foreach($names as $n => $name) {
			$path = $this->put("n$n.mp4", MediaFiles::mp4(array('chunk' => "NAME$n..........."))['bytes']);
			$page = TestSite::page("name$n", function($page) use($path, $name) {
				$copy = dirname($path) . '/' . $name;
				copy($path, $copy);
				TestSite::videos($page)->add($copy);
			});
			$video = $this->first($page);
			$this->assertNotNull($video, $name);
			$this->assertTrue(is_file($video->filename()), "file of '$name'");
			$this->assertSame(1, preg_match('/^[a-z0-9._-]+\.mp4$/i', $video->basename()), "'{$video->basename()}' from '$name'");
			// a tag finds it by the name it has now
			$page->of(false);
			$page->shvtest_body = '[[video ' . $video->basename() . ']]';
			$page->save();
			$this->assertSame($video->basename(), TestSite::videos(TestSite::fresh($page))->first()->basename());
		}
	}

	// (names much longer than 128 characters lose their ending in ProcessWire itself, also the extension: not tested)
	public function testALongNameStillWorks() {
		$name = str_repeat('long', 25) . '.mp4';
		$path = sys_get_temp_dir() . '/' . $name;
		file_put_contents($path, MediaFiles::mp4()['bytes']);
		$video = $this->first($this->pageWith($path));
		$this->assertNotNull($video);
		$this->assertTrue(is_file($video->filename()));
		$this->assertSame('.mp4', substr($video->basename(), -4));
		@unlink($path);
	}
}
