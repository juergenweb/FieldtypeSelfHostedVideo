<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ProcessWire\SelfHostedVideoDelivery;

/**
 * How a video file is answered to a browser: ranges, validators, and the media type
 */
class DeliveryTest extends TestCase {

	/** @var SelfHostedVideoDelivery */
	protected $delivery;
	protected $file;

	protected function setUp(): void {
		$this->delivery = \ProcessWire\wire('modules')->get('FieldtypeSelfHostedVideo')->delivery();
		$this->file = sys_get_temp_dir() . '/shv-unit-' . getmypid() . '.mp4';
		file_put_contents($this->file, str_repeat('0123456789', 100)); // 1000 bytes
		touch($this->file, 1700000000);
	}

	protected function tearDown(): void {
		if(is_file($this->file)) unlink($this->file);
	}

	public static function ranges() {
		return array(
			'the first bytes' => array('bytes=0-99', 1000, array(0, 99)),
			'to the end' => array('bytes=900-', 1000, array(900, 999)),
			'the last bytes' => array('bytes=-100', 1000, array(900, 999)),
			'more than there is, from the end' => array('bytes=-5000', 1000, array(0, 999)),
			'an end beyond the file' => array('bytes=500-5000', 1000, array(500, 999)),
			'one byte' => array('bytes=7-7', 1000, array(7, 7)),
			'spaces and capitals' => array(' Bytes = 10 - 19 ', 1000, array(10, 19)),
			'a start behind the file' => array('bytes=1000-', 1000, false),
			'an end before the start' => array('bytes=50-10', 1000, false),
			'no last bytes' => array('bytes=-0', 1000, false),
			'an empty file' => array('bytes=0-10', 0, false),
			'several ranges' => array('bytes=0-10,20-30', 1000, null),
			'another unit' => array('items=0-10', 1000, null),
			'neither start nor end' => array('bytes=-', 1000, null),
			'not a range' => array('bytes=abc', 1000, null),
			'blank' => array('', 1000, null),
		);
	}

	#[DataProvider('ranges')]
	public function testParseRange($header, $size, $expected) {
		$this->assertSame($expected, $this->delivery->parseRange($header, $size));
	}

	public static function types() {
		return array(
			array('a.mp4', 'video/mp4'), array('a.M4V', 'video/mp4'), array('a.webm', 'video/webm'),
			array('a.mov', 'video/quicktime'), array('a.ogv', 'video/ogg'), array('a.vtt', 'text/vtt; charset=utf-8'),
			array('a.jpg', 'image/jpeg'), array('a.png', 'image/png'), array('a.webp', 'image/webp'),
			array('a.exe', 'application/octet-stream'), array('noextension', 'application/octet-stream'),
			array('/path/with.dots/file.MP4', 'video/mp4'),
		);
	}

	#[DataProvider('types')]
	public function testContentType($name, $expected) {
		$this->assertSame($expected, $this->delivery->contentType($name));
	}

	public function testEtagChangesWithTheFile() {
		$first = $this->delivery->etag($this->file);
		$this->assertMatchesRegularExpression('/^"[0-9a-f]+-[0-9a-f]+"$/', $first);
		$this->assertSame($first, $this->delivery->etag($this->file));
		touch($this->file, 1700000100);
		clearstatcache();
		$this->assertNotSame($first, $this->delivery->etag($this->file));
	}

	public function testWholeFile() {
		$plan = $this->delivery->plan($this->file, array('method' => 'GET'));
		$this->assertSame(200, $plan['status']);
		$this->assertSame(0, $plan['start']);
		$this->assertSame(1000, $plan['length']);
		$this->assertSame('video/mp4', $plan['headers']['Content-Type']);
		$this->assertSame('bytes', $plan['headers']['Accept-Ranges']);
		$this->assertSame('1000', $plan['headers']['Content-Length']);
		$this->assertSame('private, no-cache', $plan['headers']['Cache-Control']);
		$this->assertSame('nosniff', $plan['headers']['X-Content-Type-Options']);
	}

	public function testPartOfTheFile() {
		$plan = $this->delivery->plan($this->file, array('method' => 'GET', 'range' => 'bytes=100-199'));
		$this->assertSame(206, $plan['status']);
		$this->assertSame(100, $plan['start']);
		$this->assertSame(100, $plan['length']);
		$this->assertSame('bytes 100-199/1000', $plan['headers']['Content-Range']);
		$this->assertSame('100', $plan['headers']['Content-Length']);
	}

	public function testRangeOutsideTheFile() {
		$plan = $this->delivery->plan($this->file, array('method' => 'GET', 'range' => 'bytes=2000-'));
		$this->assertSame(416, $plan['status']);
		$this->assertSame('bytes */1000', $plan['headers']['Content-Range']);
		$this->assertSame(0, $plan['length']);
	}

	public function testHeadSendsHeadersButNoBody() {
		$plan = $this->delivery->plan($this->file, array('method' => 'HEAD'));
		$this->assertSame(200, $plan['status']);
		$this->assertSame(0, $plan['length']);
		$this->assertSame('1000', $plan['headers']['Content-Length']);
	}

	public function testOtherMethodsAreRefused() {
		foreach(array('POST', 'PUT', 'DELETE') as $method) {
			$plan = $this->delivery->plan($this->file, array('method' => $method));
			$this->assertSame(405, $plan['status'], $method);
			$this->assertSame('GET, HEAD', $plan['headers']['Allow']);
		}
	}

	public function testBrowserThatHasTheFileGetsNotModified() {
		$etag = $this->delivery->etag($this->file);
		$plan = $this->delivery->plan($this->file, array('method' => 'GET', 'ifNoneMatch' => $etag));
		$this->assertSame(304, $plan['status']);
		$this->assertSame(0, $plan['length']);
		$this->assertArrayNotHasKey('Content-Type', $plan['headers']);
		$this->assertSame($etag, $plan['headers']['ETag']);
	}

	public function testWeakValidatorsAndListsAreRecognized() {
		$etag = $this->delivery->etag($this->file);
		foreach(array("W/$etag", "\"other\", $etag", '*') as $header) {
			$this->assertSame(304, $this->delivery->plan($this->file, array('method' => 'GET', 'ifNoneMatch' => $header))['status'], $header);
		}
		$this->assertSame(200, $this->delivery->plan($this->file, array('method' => 'GET', 'ifNoneMatch' => '"other"'))['status']);
	}

	public function testIfModifiedSince() {
		$later = gmdate('D, d M Y H:i:s', 1700000500) . ' GMT';
		$earlier = gmdate('D, d M Y H:i:s', 1600000000) . ' GMT';
		$this->assertSame(304, $this->delivery->plan($this->file, array('method' => 'GET', 'ifModifiedSince' => $later))['status']);
		$this->assertSame(200, $this->delivery->plan($this->file, array('method' => 'GET', 'ifModifiedSince' => $earlier))['status']);
		$this->assertSame(200, $this->delivery->plan($this->file, array('method' => 'GET', 'ifModifiedSince' => 'garbage'))['status']);
	}

	public function testIfNoneMatchWinsOverIfModifiedSince() {
		$later = gmdate('D, d M Y H:i:s', 1700000500) . ' GMT';
		$plan = $this->delivery->plan($this->file, array('method' => 'GET', 'ifNoneMatch' => '"other"', 'ifModifiedSince' => $later));
		$this->assertSame(200, $plan['status']);
	}

	public function testIfRangeOfAnotherVersionSendsTheWholeFile() {
		$etag = $this->delivery->etag($this->file);
		$same = $this->delivery->plan($this->file, array('method' => 'GET', 'range' => 'bytes=0-9', 'ifRange' => $etag));
		$this->assertSame(206, $same['status']);
		$other = $this->delivery->plan($this->file, array('method' => 'GET', 'range' => 'bytes=0-9', 'ifRange' => '"old"'));
		$this->assertSame(200, $other['status']);
		$this->assertSame(1000, $other['length']);
		$weak = $this->delivery->plan($this->file, array('method' => 'GET', 'range' => 'bytes=0-9', 'ifRange' => "W/$etag"));
		$this->assertSame(200, $weak['status'], 'a weak validator does not make a range safe');
	}

	public function testIfRangeByDate() {
		$date = gmdate('D, d M Y H:i:s', 1700000000) . ' GMT';
		$this->assertSame(206, $this->delivery->plan($this->file, array('method' => 'GET', 'range' => 'bytes=0-9', 'ifRange' => $date))['status']);
		$old = gmdate('D, d M Y H:i:s', 1600000000) . ' GMT';
		$this->assertSame(200, $this->delivery->plan($this->file, array('method' => 'GET', 'range' => 'bytes=0-9', 'ifRange' => $old))['status']);
	}
}
