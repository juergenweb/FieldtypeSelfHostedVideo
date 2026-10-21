<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ProcessWire\SelfHostedVideoTimeline as T;

/**
 * Chapters and overlays: times as people write them, and what is kept of what the editor sends
 */
class TimelineTest extends TestCase {

	protected function sanitizer() { return \ProcessWire\wire('sanitizer'); }

	public static function times() {
		return array(
			'seconds' => array('83.5', 83.5),
			'minutes' => array('1:23', 83.0),
			'minutes with decimals' => array('1:23.5', 83.5),
			'hours' => array('1:02:05', 3725.0),
			'comma as decimal point' => array('1,5', 1.5),
			'spaces around' => array(' 0:30 ', 30.0),
			'an int' => array(5, 5.0),
			'a float' => array(2.25, 2.25),
			'negative number' => array(-1, null),
			'text' => array('abc', null),
			'blank' => array('', null),
			'too many parts' => array('1:2:3:4', null),
			'a sign' => array('-5', null),
		);
	}

	#[DataProvider('times')]
	public function testParseTime($input, $expected) {
		$this->assertSame($expected, T::parseTime($input));
	}

	public static function formats() {
		return array(
			'zero' => array(0, '0:00'),
			'seconds' => array(9, '0:09'),
			'with a tenth' => array(83.5, '1:23.5'),
			'hours' => array(3725, '1:02:05'),
			'rounds up into the next minute' => array(59.96, '1:00'),
			'negative' => array(-5, '0:00'),
		);
	}

	#[DataProvider('formats')]
	public function testFormatTime($seconds, $expected) {
		$this->assertSame($expected, T::formatTime($seconds));
	}

	public function testFormatAndParseAgree() {
		foreach(array(0.0, 0.5, 59.0, 60.0, 83.5, 600.0, 3599.9, 3600.0, 7325.3) as $seconds) {
			$this->assertEqualsWithDelta($seconds, T::parseTime(T::formatTime($seconds)), 0.051, "$seconds");
		}
	}

	public function testAnchorBreaksAtAThirdAndTwoThirds() {
		$this->assertSame('l', T::anchor(0, 'l', 'c', 'r'));
		$this->assertSame('l', T::anchor(33.9, 'l', 'c', 'r'));
		$this->assertSame('c', T::anchor(34, 'l', 'c', 'r'));
		$this->assertSame('c', T::anchor(66, 'l', 'c', 'r'));
		$this->assertSame('r', T::anchor(66.1, 'l', 'c', 'r'));
	}

	public function testNewIdIsUsable() {
		$ids = array();
		for($n = 0; $n < 50; $n++) {
			$id = T::newId();
			$this->assertMatchesRegularExpression('/^[a-z0-9]{6}$/', $id);
			$ids[$id] = true;
		}
		$this->assertGreaterThan(45, count($ids), 'identifiers differ');
	}

	public static function addresses() {
		return array(
			'a page of the site' => array('/contact/', '/contact/'),
			'with a query' => array('/contact/?a=1', '/contact/?a=1'),
			'an anchor' => array('#form', '#form'),
			'https' => array('https://example.com/a?b=1', 'https://example.com/a?b=1'),
			'http' => array('http://example.com', 'http://example.com'),
			'mail' => array('mailto:a@example.com', 'mailto:a@example.com'),
			'phone' => array('tel:+43123456', 'tel:+43123456'),
			'javascript' => array('javascript:alert(1)', ''),
			'data' => array('data:text/html,x', ''),
			'without a scheme, with two slashes' => array('//evil.example/x', ''),
			'a quote' => array('/a"onmouseover="x', ''),
			'an angle bracket' => array('/a<b', ''),
			'a space' => array('/a b', ''),
			'an anchor with a quote' => array('#a"b', ''),
			'blank' => array('', ''),
			'a long address' => array('/' . str_repeat('a', 1100), ''),
		);
	}

	#[DataProvider('addresses')]
	public function testCleanUrl($input, $expected) {
		$this->assertSame($expected, T::cleanUrl($input, $this->sanitizer()));
	}

	public function testCleanTextsKeepsKnownLanguagesOnly() {
		$out = T::cleanTexts(array(0 => ' Hello ', '1234' => 'Hallo', 99 => 'unknown'), array(0, 1234), $this->sanitizer());
		$this->assertSame(array(0 => 'Hello', 1234 => 'Hallo'), $out);
	}

	public function testCleanTextsTakesAStringForTheDefaultLanguage() {
		$this->assertSame(array(0 => 'Hi'), T::cleanTexts('Hi', array(0, 5), $this->sanitizer()));
	}

	public function testCleanTextsDropsEmptyAndInvalidValues() {
		$this->assertSame(array(), T::cleanTexts(array(0 => '   '), array(0), $this->sanitizer()));
		$this->assertSame(array(), T::cleanTexts(null, array(0), $this->sanitizer()));
		$this->assertSame(array(), T::cleanTexts(array(0 => array('x')), array(0), $this->sanitizer()));
	}

	public function testCleanTextsCutsALineAtItsLimit() {
		$out = T::cleanTexts(array(0 => str_repeat('a', 300)), array(0), $this->sanitizer());
		$this->assertSame(T::maxTitleLength, strlen($out[0]));
	}

	public function testCleanTextsKeepsLineBreaksOfATextAndLimitsEmptyLines() {
		$out = T::cleanTexts(array(0 => "one\r\n\r\n\r\n\r\ntwo\nthree"), array(0), $this->sanitizer(), 'text');
		$this->assertSame("one\n\ntwo\nthree", $out[0]);
	}

	public function testCleanTextsOfTypeUrlCleansAddresses() {
		$out = T::cleanTexts(array(0 => '/ok/', 5 => 'javascript:x'), array(0, 5), $this->sanitizer(), 'url');
		$this->assertSame(array(0 => '/ok/'), $out);
	}

	public function testChaptersAreSortedByTime() {
		$out = T::cleanChapters(array(
			array('time' => '2:00', 'title' => 'Last'),
			array('time' => '0:00', 'title' => 'First'),
			array('time' => '1:00', 'title' => 'Middle'),
		), 300, array(0), $this->sanitizer());
		$this->assertSame(array('First', 'Middle', 'Last'), array_map(function($c) { return $c['title'][0]; }, $out));
		$this->assertSame(array(0.0, 60.0, 120.0), array_column($out, 'time'));
	}

	public function testChaptersWithoutTimeOrTitleAreLeftOut() {
		$out = T::cleanChapters(array(
			array('time' => 'soon', 'title' => 'No time'),
			array('time' => '0:10', 'title' => ''),
			array('title' => 'Missing time'),
			'not an array',
			array('time' => '0:20', 'title' => 'Fine'),
		), 0, array(0), $this->sanitizer());
		$this->assertCount(1, $out);
		$this->assertSame('Fine', $out[0]['title'][0]);
	}

	public function testASecondChapterAtTheSameTimeIsLeftOut() {
		$out = T::cleanChapters(array(
			array('time' => '1:00', 'title' => 'One'),
			array('time' => '60', 'title' => 'Two'),
		), 0, array(0), $this->sanitizer());
		$this->assertCount(1, $out);
		$this->assertSame('One', $out[0]['title'][0]);
	}

	public function testChapterTimesStayInsideTheVideo() {
		$out = T::cleanChapters(array(array('time' => '5:00', 'title' => 'Too late')), 90.55, array(0), $this->sanitizer());
		$this->assertSame(90.5, $out[0]['time']);
	}

	public function testChapterIdsAreKeptWhenUsableAndUnique() {
		$out = T::cleanChapters(array(
			array('id' => 'abc123', 'time' => '0:01', 'title' => 'A'),
			array('id' => 'abc123', 'time' => '0:02', 'title' => 'B'),
			array('id' => 'NOT OK!', 'time' => '0:03', 'title' => 'C'),
		), 0, array(0), $this->sanitizer());
		$ids = array_column($out, 'id');
		$this->assertSame('abc123', $ids[0]);
		$this->assertCount(3, array_unique($ids));
		foreach($ids as $id) $this->assertMatchesRegularExpression('/^[a-z0-9]{4,12}$/', $id);
	}

	public function testChaptersAreLimited() {
		$many = array();
		for($n = 0; $n < T::maxChapters + 20; $n++) $many[] = array('time' => $n, 'title' => "Chapter $n");
		$this->assertCount(T::maxChapters, T::cleanChapters($many, 0, array(0), $this->sanitizer()));
	}

	public function testAnOverlayNeedsText() {
		$this->assertSame(array(), T::cleanOverlays(array(array('text' => '  '), array('x' => 10)), 0, array(0), $this->sanitizer()));
	}

	public function testOverlayDefaults() {
		$o = T::cleanOverlays(array(array('text' => 'Hello')), 0, array(0), $this->sanitizer());
		$this->assertCount(1, $o);
		$o = $o[0];
		$this->assertSame(50.0, $o['x']);
		$this->assertSame(50.0, $o['y']);
		$this->assertSame('c', $o['ax']);
		$this->assertSame('m', $o['ay']);
		$this->assertSame('always', $o['when']);
		$this->assertSame('m', $o['size']);
		$this->assertSame('dark', $o['look']);
		$this->assertArrayNotHasKey('url', $o);
	}

	public function testOverlayPositionIsKeptInsideTheVideoAndAnchoredToTheNearEdge() {
		$o = T::cleanOverlays(array(array('text' => 'A', 'x' => -20, 'y' => 250)), 0, array(0), $this->sanitizer())[0];
		$this->assertSame(0.0, $o['x']);
		$this->assertSame(100.0, $o['y']);
		$this->assertSame('l', $o['ax']);
		$this->assertSame('b', $o['ay']);
	}

	public function testOverlayWidthHasALowerLimit() {
		$o = T::cleanOverlays(array(array('text' => 'A', 'w' => 3)), 0, array(0), $this->sanitizer())[0];
		$this->assertSame(10.0, $o['w']);
		$o = T::cleanOverlays(array(array('text' => 'A', 'w' => 500)), 0, array(0), $this->sanitizer())[0];
		$this->assertSame(100.0, $o['w']);
	}

	public function testOverlayWithoutARangeIsShownAlways() {
		$o = T::cleanOverlays(array(array('text' => 'A', 'when' => 'range', 'start' => 0, 'end' => 0)), 0, array(0), $this->sanitizer())[0];
		$this->assertSame('always', $o['when']);
	}

	public function testOverlayRange() {
		$o = T::cleanOverlays(array(array('text' => 'A', 'when' => 'range', 'start' => '0:05', 'end' => '0:10')), 60, array(0), $this->sanitizer())[0];
		$this->assertSame('range', $o['when']);
		$this->assertSame(5.0, $o['start']);
		$this->assertSame(10.0, $o['end']);
	}

	public function testOverlayRangeThatEndsBeforeItStartsRunsToTheEnd() {
		$o = T::cleanOverlays(array(array('text' => 'A', 'when' => 'range', 'start' => '0:20', 'end' => '0:10')), 60, array(0), $this->sanitizer())[0];
		$this->assertSame(20.0, $o['start']);
		$this->assertSame(0.0, $o['end']);
	}

	public function testOverlayLinkIsCleaned() {
		$o = T::cleanOverlays(array(array('text' => 'A', 'url' => array(0 => 'javascript:x'))), 0, array(0), $this->sanitizer())[0];
		$this->assertArrayNotHasKey('url', $o);
		$o = T::cleanOverlays(array(array('text' => 'A', 'url' => array(0 => '/contact/'))), 0, array(0), $this->sanitizer())[0];
		$this->assertSame(array(0 => '/contact/'), $o['url']);
	}

	public function testOverlayChoicesOutsideTheListGetTheDefault() {
		$o = T::cleanOverlays(array(array('text' => 'A', 'size' => 'xxl', 'look' => 'neon', 'ax' => 'q', 'ay' => 'z', 'when' => 'sometimes')), 0, array(0), $this->sanitizer())[0];
		$this->assertSame('m', $o['size']);
		$this->assertSame('dark', $o['look']);
		$this->assertSame('c', $o['ax']);
		$this->assertSame('m', $o['ay']);
		$this->assertSame('always', $o['when']);
	}

	public function testOverlaysAreLimited() {
		$many = array();
		for($n = 0; $n < T::maxOverlays + 5; $n++) $many[] = array('text' => "Overlay $n");
		$this->assertCount(T::maxOverlays, T::cleanOverlays($many, 0, array(0), $this->sanitizer()));
	}
}
