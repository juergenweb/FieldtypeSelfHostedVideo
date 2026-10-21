<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use ProcessWire\SelfHostedVideoProbe as Probe;
use ProcessWire\SelfHostedVideoFaststart as Faststart;

require_once __DIR__ . '/MediaFiles.php';

/**
 * What is read from a video file (format, size, length, tracks) and the preparing of an MP4 for streaming
 */
class FilesTest extends TestCase {

	protected $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/shv-unit-files-' . getmypid() . '/';
		@mkdir($this->dir);
	}

	protected function tearDown(): void {
		foreach(glob($this->dir . '*') as $file) @unlink($file);
		@rmdir($this->dir);
	}

	protected function write($name, $bytes) {
		file_put_contents($this->dir . $name, $bytes);
		return $this->dir . $name;
	}

	// Probe: MP4

	public function testMp4SizeLengthAndCodec() {
		$info = Probe::probe($this->write('a.mp4', MediaFiles::mp4(array('seconds' => 93.4, 'width' => 1920, 'height' => 1080))['bytes']));
		$this->assertSame('mp4', $info['format']);
		$this->assertSame(93.4, $info['duration']);
		$this->assertSame(1920, $info['width']);
		$this->assertSame(1080, $info['height']);
		$this->assertSame('avc1', $info['videoCodec']);
		$this->assertTrue($info['hasVideo']);
		$this->assertFalse($info['hasAudio']);
		$this->assertSame(0, $info['rotation']);
	}

	public function testMp4WithAudio() {
		$info = Probe::probe($this->write('a.mp4', MediaFiles::mp4(array('audio' => true))['bytes']));
		$this->assertTrue($info['hasAudio']);
	}

	public function testMp4StoredSidewaysHasItsSizeSwapped() {
		foreach(array(90, 270) as $rotation) {
			$info = Probe::probe($this->write('r.mp4', MediaFiles::mp4(array('width' => 1920, 'height' => 1080, 'rotation' => $rotation))['bytes']));
			$this->assertSame($rotation, $info['rotation'], "rotation $rotation");
			$this->assertSame(1080, $info['width']);
			$this->assertSame(1920, $info['height']);
		}
		$info = Probe::probe($this->write('r.mp4', MediaFiles::mp4(array('width' => 1920, 'height' => 1080, 'rotation' => 180))['bytes']));
		$this->assertSame(180, $info['rotation']);
		$this->assertSame(1920, $info['width']);
	}

	public function testMp4KnowsWhereItsIndexIs() {
		$this->assertFalse(Probe::probe($this->write('slow.mp4', MediaFiles::mp4(array('faststart' => false))['bytes']))['faststart']);
		$this->assertTrue(Probe::probe($this->write('fast.mp4', MediaFiles::mp4(array('faststart' => true))['bytes']))['faststart']);
	}

	public function testMp4TimescaleIsRespected() {
		$moov = MediaFiles::moov(array(MediaFiles::track('vide', array('width' => 2, 'height' => 2))), 10, 90000);
		$bytes = MediaFiles::box('ftyp', 'isom' . pack('N', 0) . 'isom') . $moov;
		$this->assertSame(10.0, Probe::probe($this->write('t.mp4', $bytes))['duration']);
	}

	// Probe: WebM

	public function testWebmSizeLengthAndCodec() {
		$info = Probe::probe($this->write('a.webm', MediaFiles::webm(array('seconds' => 12.5))));
		$this->assertSame('webm', $info['format']);
		$this->assertSame(12.5, $info['duration']);
		$this->assertSame(1280, $info['width']);
		$this->assertSame(720, $info['height']);
		$this->assertSame('V_VP9', $info['videoCodec']);
		$this->assertTrue($info['hasVideo']);
		$this->assertFalse($info['hasAudio']);
		$this->assertNull($info['faststart']);
	}

	public function testWebmWithAudioAndTimestampScale() {
		$info = Probe::probe($this->write('a.webm', MediaFiles::webm(array('seconds' => 3, 'scale' => 500000, 'audio' => true))));
		$this->assertTrue($info['hasAudio']);
		$this->assertSame(3.0, $info['duration']);
	}

	public function testMatroskaIsToldFromWebm() {
		$this->assertSame('matroska', Probe::probe($this->write('a.mkv', MediaFiles::webm(array('docType' => 'matroska'))))['format']);
	}

	public function testWebmDisplaySizeWinsOverThePixelSize() {
		$info = Probe::probe($this->write('a.webm', MediaFiles::webm(array('width' => 720, 'height' => 576, 'displayWidth' => 1024, 'displayHeight' => 576))));
		$this->assertSame(1024, $info['width']);
		$this->assertSame(576, $info['height']);
	}

	// Probe: what is no video

	public function testAFileThatDoesNotExistOrIsNoVideoIsToldNothing() {
		$none = Probe::probe($this->dir . 'missing.mp4');
		$this->assertSame('', $none['format']);
		$this->assertSame(0.0, $none['duration']);
		$this->assertFalse($none['hasVideo']);
		foreach(array('', 'tiny', str_repeat('x', 5000), "GIF89a" . str_repeat("\0", 100)) as $n => $bytes) {
			$info = Probe::probe($this->write("n$n.mp4", $bytes));
			$this->assertSame('', $info['format'], "case $n");
			$this->assertFalse($info['hasVideo']);
		}
	}

	public function testDamagedFilesDoNotThrow() {
		$good = MediaFiles::mp4()['bytes'];
		foreach(array(20, 40, 60, strlen($good) - 10) as $cut) {
			$info = Probe::probe($this->write('cut.mp4', substr($good, 0, $cut)));
			$this->assertTrue(is_array($info), "cut at $cut");
		}
		$web = MediaFiles::webm();
		foreach(array(10, 30, 60, strlen($web) - 5) as $cut) {
			$this->assertTrue(is_array(Probe::probe($this->write('cut.webm', substr($web, 0, $cut)))), "cut at $cut");
		}
		// a box that claims to be larger than the file
		$bad = MediaFiles::box('ftyp', 'isom' . pack('N', 0) . 'isom') . pack('N', 0x7FFFFFFF) . 'moov' . 'xxxx';
		$this->assertTrue(is_array(Probe::probe($this->write('bad.mp4', $bad))));
	}

	// Faststart

	public function testAnIndexAfterTheMediaDataNeedsPreparing() {
		$this->assertTrue(Faststart::needed($this->write('slow.mp4', MediaFiles::mp4(array('faststart' => false))['bytes'])));
		$this->assertFalse(Faststart::needed($this->write('fast.mp4', MediaFiles::mp4(array('faststart' => true))['bytes'])));
	}

	public function testOtherFilesNeedNothing() {
		$this->assertFalse(Faststart::needed($this->dir . 'missing.mp4'));
		$this->assertFalse(Faststart::needed($this->write('a.webm', MediaFiles::webm())));
		$this->assertFalse(Faststart::needed($this->write('text.mp4', str_repeat('hello ', 100))));
	}

	public function testPreparingMovesTheIndexToTheFrontAndKeepsTheChunkOffsetsRight() {
		$made = MediaFiles::mp4(array('faststart' => false));
		$file = $this->write('slow.mp4', $made['bytes']);
		$before = MediaFiles::chunkOffset($made['bytes']);
		$this->assertSame($made['chunk'], substr($made['bytes'], $before, strlen($made['chunk'])), 'the offset points at the data');

		$error = '';
		$this->assertTrue(Faststart::fix($file, $error), $error);
		clearstatcache();
		$after = file_get_contents($file);

		$this->assertSame(strlen($made['bytes']), strlen($after), 'the same size');
		$this->assertFalse(Faststart::needed($file));
		$this->assertTrue(Probe::probe($file)['faststart']);
		$offset = MediaFiles::chunkOffset($after);
		$this->assertGreaterThan($before, $offset, 'the data moved back');
		$this->assertSame($made['chunk'], substr($after, $offset, strlen($made['chunk'])), 'and the offset moved with it');
		$was = Probe::probe($this->write('orig.mp4', $made['bytes']));
		$now = Probe::probe($file);
		unset($was['faststart'], $now['faststart']);
		$this->assertEquals($was, $now, 'what is read from the file stays the same');
		$this->assertSame(array(), glob($this->dir . '*.tmp'), 'no temporary file is left');
	}

	public function testPreparingAPreparedFileChangesNothing() {
		$file = $this->write('fast.mp4', MediaFiles::mp4(array('faststart' => true))['bytes']);
		$before = file_get_contents($file);
		$error = 'unset';
		$this->assertFalse(Faststart::fix($file, $error));
		$this->assertSame($before, file_get_contents($file));
	}

	public function testPreparingSomethingThatIsNoMp4SaysSo() {
		$error = '';
		$this->assertFalse(Faststart::fix($this->write('x.mp4', str_repeat('x', 200)), $error));
		$this->assertNotSame('', $error);
	}

	public function testPreparingKeepsFilePermissions() {
        if(DIRECTORY_SEPARATOR === '\\') $this->markTestSkipped('Windows has no Unix file permissions.');
		$file = $this->write('slow.mp4', MediaFiles::mp4()['bytes']);
		chmod($file, 0640);
		$this->assertTrue(Faststart::fix($file));
		clearstatcache();
		$this->assertSame(0640, fileperms($file) & 0777);
	}

	public function testPreparingAFileWithAnIncompleteBoxLeavesItAlone() {
		$bytes = substr(MediaFiles::mp4()['bytes'], 0, -6);
		$file = $this->write('cut.mp4', $bytes);
		$error = '';
		$this->assertFalse(Faststart::fix($file, $error));
		$this->assertSame($bytes, file_get_contents($file));
	}
}
