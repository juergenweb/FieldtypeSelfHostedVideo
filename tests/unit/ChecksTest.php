<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Reach.php';

/**
 * The checklist of a video: naming its format, warning about formats browsers do not play
 *
 * (Texts are in the language of the website, so only whether there is a warning is tested, not its words.)
 */
class ChecksTest extends TestCase {

	protected $checks;

	protected function setUp(): void {
		$this->checks = \ProcessWire\wire(new \ProcessWire\SelfHostedVideoChecks());
	}

	protected function describe($codec, $format = 'mp4', $ext = 'mp4') {
		return $this->checks->describeFormat(array('codec' => $codec, 'format' => $format, 'ext' => $ext));
	}

	public static function good() {
		return array(
			'H.264 in MP4' => array('avc1', 'mp4', 'mp4', 'H.264', 'MP4'),
			'H.264 with another tag' => array('AVC3', 'mp4', 'm4v', 'H.264', 'MP4'),
			'VP9 in WebM' => array('V_VP9', 'webm', 'webm', 'VP9', 'WebM'),
			'VP9 in MP4' => array('vp09', 'mp4', 'mp4', 'VP9', 'MP4'),
			'VP8 in WebM' => array('V_VP8', 'webm', 'webm', 'VP8', 'WebM'),
			'AV1 in WebM' => array('V_AV1', 'webm', 'webm', 'AV1', 'WebM'),
			'AV1 in MP4' => array('av01', 'mp4', 'mp4', 'AV1', 'MP4'),
		);
	}

	#[DataProvider('good')]
	public function testFormatsThatPlayHaveNoProblem($codec, $format, $ext, $name, $container) {
		$d = $this->describe($codec, $format, $ext);
		$this->assertStringContainsString($name, $d['name']);
		$this->assertStringContainsString($container, $d['name']);
		$this->assertSame('', $d['problem']);
	}

	public static function bad() {
		return array(
			'H.265' => array('hvc1', 'mp4', 'mp4', 'H.265'),
			'H.265 with the other tag' => array('hev1', 'mp4', 'mp4', 'H.265'),
			'ProRes' => array('apcn', 'mp4', 'mov', 'ProRes'),
			'MPEG-4 Visual' => array('mp4v', 'mp4', 'mp4', 'MPEG-4 Visual'),
			'something else in MP4' => array('xyz9', 'mp4', 'mp4', 'XYZ9'),
			'something else in WebM' => array('V_THEORA', 'webm', 'webm', 'THEORA'),
		);
	}

	#[DataProvider('bad')]
	public function testFormatsThatMayNotPlayAreWarnedAbout($codec, $format, $ext, $name) {
		$d = $this->describe($codec, $format, $ext);
		$this->assertStringContainsString($name, $d['name']);
		$this->assertNotSame('', $d['problem']);
	}

	public function testAMovFileIsCalledQuickTimeAndWarnedAbout() {
		$d = $this->describe('avc1', 'mp4', 'mov');
		$this->assertStringContainsString('QuickTime', $d['name']);
		$this->assertNotSame('', $d['problem']);
	}

	public function testMatroskaIsWarnedAbout() {
		$d = $this->describe('V_VP9', 'matroska', 'mkv');
		$this->assertStringContainsString('Matroska', $d['name']);
		$this->assertNotSame('', $d['problem']);
	}

	public function testTheWarningAboutTheCodecWinsOverTheOneAboutTheContainer() {
		$hevc = $this->describe('hvc1', 'mp4', 'mov');
		$this->assertSame($this->describe('hvc1', 'mp4', 'mp4')['problem'], $hevc['problem']);
	}

	public function testWithoutACodecOnlytheContainerIsNamed() {
		$d = $this->describe('', 'webm', 'webm');
		$this->assertSame('WebM', $d['name']);
		$this->assertSame('', $d['problem']);
		$d = $this->describe('', '', 'xyz');
		$this->assertSame('', $d['name']);
		$this->assertSame('', $d['problem']);
	}

	protected function makeCheck($status) {
		$c = \ProcessWire\wire(new \ProcessWire\WireData());
		$c->status = $status;
		return $c;
	}

	public function testOnlyTodosAndWarningsCountAsOpen() {
		$list = array($this->makeCheck('ok'), $this->makeCheck('todo'), $this->makeCheck('warning'), $this->makeCheck('info'), $this->makeCheck('todo'));
		$this->assertSame(3, $this->checks->countOpen($list));
		$this->assertSame(0, $this->checks->countOpen(array()));
	}

	public static function titles() {
		return array(
			array('  A   Title ', 'a title'),
			array("Line\nbreak", 'line break'),
			array('<b>Bold</b> &amp; plain', 'bold & plain'),
			array('ÄÖÜ', 'äöü'),
			array('', ''),
		);
	}

	#[DataProvider('titles')]
	public function testTitlesAreComparedWithoutMarkupSpacesAndCase($title, $expected) {
		$this->assertSame($expected, Reach::call($this->checks, 'titleKey', $title));
	}

	public static function numbers() {
		return array(array(5, '5'), array(5.04, '5'), array(5.06, '5.1'), array(12.34, '12.3'), array(0, '0'), array('7.5', '7.5'), array(1234.56, '1234.6'));
	}

	#[DataProvider('numbers')]
	public function testNumbersHaveOneDecimalAtMost($value, $expected) {
		$this->assertSame($expected, str_replace(',', '.', Reach::call($this->checks, 'number', $value)));
	}
}
