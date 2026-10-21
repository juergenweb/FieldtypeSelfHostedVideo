<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ProcessWire\Pagevideo;
use ProcessWire\Pagevideos;
use ProcessWire\SelfHostedVideoMarkup;
use ProcessWire\SelfHostedVideoStats;

/**
 * Small helpers that decide what is stored, linked or counted
 */
class HelpersTest extends TestCase {

	// Pagevideo

	public static function seconds() {
		return array(
			'a number' => array(12.3456, 12.346),
			'a numeric string' => array('7.5', 7.5),
			'zero' => array(0, 0.0),
			'negative' => array(-3, 0.0),
			'text' => array('abc', 0.0),
			'null' => array(null, 0.0),
			'infinite' => array(INF, 0.0),
			'not a number' => array(NAN, 0.0),
			'ten days is the limit' => array(9999999, 864000.0),
			'an array' => array(array(1), 0.0),
		);
	}

	#[DataProvider('seconds')]
	public function testCleanSeconds($input, $expected) {
		$this->assertSame($expected, Pagevideo::cleanSeconds($input));
	}

	public function testStorableDataReplacesNumbersJsonCannotHold() {
		$data = Pagevideo::storableData(array('a' => INF, 'b' => array('c' => NAN, 'd' => 1.5, 'e' => array('f' => -INF)), 'g' => 'text', 'h' => 3));
		$this->assertSame(array('a' => 0.0, 'b' => array('c' => 0.0, 'd' => 1.5, 'e' => array('f' => 0.0)), 'g' => 'text', 'h' => 3), $data);
		$this->assertTrue(json_encode($data) !== false, 'and it can be stored as JSON');
	}

	public function testStorableDataLeavesOtherValuesAlone() {
		$this->assertSame('x', Pagevideo::storableData('x'));
		$this->assertSame(array(), Pagevideo::storableData(array()));
		$this->assertSame(2.5, Pagevideo::storableData(2.5));
	}

	public static function links() {
		return array(
			'a page of the site' => array('/contact/', '/contact/'),
			'an anchor' => array('#form', '#form'),
			'a query' => array('?page=2', '?page=2'),
			'https' => array('https://example.com/a', 'https://example.com/a'),
			'upper case scheme' => array('HTTPS://EXAMPLE.COM', 'HTTPS://EXAMPLE.COM'),
			'mail' => array('mailto:a@example.com', 'mailto:a@example.com'),
			'phone' => array('tel:+43123', 'tel:+43123'),
			'spaces are encoded' => array('/my page/', '/my%20page/'),
			'line breaks become spaces' => array("/a\nb", '/a%20b'),
			'control characters go' => array("/a\x00b", '/a%20b'),
			'trimmed' => array('  /x/  ', '/x/'),
			'javascript' => array('javascript:alert(1)', ''),
			'data' => array('data:text/html;base64,AAAA', ''),
			'another host without a scheme' => array('//evil.example/x', ''),
			'a bare word' => array('contact', ''),
			'ftp' => array('ftp://example.com', ''),
			'blank' => array('', ''),
			'a very long address' => array('/' . str_repeat('a', 2100), ''),
		);
	}

	#[DataProvider('links')]
	public function testCleanLink($input, $expected) {
		$this->assertSame($expected, Pagevideo::cleanLink($input));
	}

	// Pagevideos

	public static function keys() {
		return array(
			array(5, 'clip.mp4', 'clip.ref5.mp4'),
			array('12', 'my.clip.webm', 'my.clip.ref12.webm'),
			array(7, 'noextension', 'noextension.ref7'),
			array(0, 'a.mp4', 'a.ref0.mp4'),
		);
	}

	#[DataProvider('keys')]
	public function testReferenceKeyFor($pageId, $name, $expected) {
		$this->assertSame($expected, Pagevideos::referenceKeyFor($pageId, $name));
	}

	public function testPageListNamesPagesAndCountsTheRest() {
		$pages = array();
		foreach(array('/a/', '/b/', '/c/', '/d/') as $path) $pages[] = new class($path) { public $p; function __construct($p) { $this->p = $p; } function path() { return $this->p; } };
		$this->assertSame('/a/, /b/, /c/, /d/', Pagevideos::pageList($pages));
		$this->assertSame('/a/, /b/ (+2)', Pagevideos::pageList($pages, 2));
		$this->assertSame('', Pagevideos::pageList(array()));
	}

	// Markup

	public static function durations() {
		return array(
			array(93.4, 'PT1M33S'), array(0, 'PT1S'), array(0.2, 'PT1S'), array(59, 'PT59S'), array(60, 'PT1M'),
			array(3600, 'PT1H'), array(3661, 'PT1H1M1S'), array(7200.4, 'PT2H'), array('90', 'PT1M30S'),
		);
	}

	#[DataProvider('durations')]
	public function testIsoDuration($seconds, $expected) {
		$this->assertSame($expected, SelfHostedVideoMarkup::isoDuration($seconds));
	}

	protected function withHosts($httpHost, array $httpHosts, $fn) {
		$config = \ProcessWire\wire('config');
		$was = array($config->httpHost, $config->httpHosts);
		$config->httpHost = $httpHost;
		$config->httpHosts = $httpHosts;
		try {
			$fn(\ProcessWire\wire('modules')->get('FieldtypeSelfHostedVideo')->markup());
		} finally {
			$config->httpHost = $was[0];
			$config->httpHosts = $was[1];
		}
	}

	public static function externalLinks() {
		return array(
			'another website' => array('https://example.org/x', true),
			'http' => array('http://example.org', true),
			'without a scheme' => array('//example.org/x', true),
			'capitals' => array('HTTPS://EXAMPLE.ORG', true),
			'a lookalike' => array('https://my.site.evil.net/', true),
			'a subdomain is another site' => array('https://blog.my.site/', true),
			'the site' => array('https://my.site/page', false),
			'the site with www' => array('https://www.my.site/page', false),
			'the site in capitals' => array('HTTPS://MY.SITE', false),
			'the site with a port' => array('https://my.site:8443/x', false),
			'another name of the site' => array('https://alias.example/x', false),
			'its www' => array('http://www.alias.example/', false),
			'a path' => array('/contact/', false),
			'an anchor' => array('#form', false),
			'mail' => array('mailto:a@example.org', false),
			'phone' => array('tel:+43123', false),
			'blank' => array('', false),
		);
	}

	#[DataProvider('externalLinks')]
	public function testIsExternalLink($url, $expected) {
		$this->withHosts('my.site', array('my.site', 'www.alias.example:80'), function($markup) use($url, $expected) {
			$this->assertSame($expected, $markup->isExternalLink($url));
		});
	}

	public function testIsExternalLinkWithoutAListOfHosts() {
		$this->withHosts('my.site:8088', array(), function($markup) {
			$this->assertFalse($markup->isExternalLink('http://my.site/x'));
			$this->assertTrue($markup->isExternalLink('http://other.site/x'));
		});
	}

	// Stats

	public static function agents() {
		return array(
			'a browser' => array('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36', false),
			'a phone' => array('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', false),
			'Googlebot' => array('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true),
			'bingbot' => array('Mozilla/5.0 (compatible; bingbot/2.0)', true),
			'a crawler' => array('SomeCrawler/1.0', true),
			'a spider' => array('Baiduspider', true),
			'headless Chrome' => array('Mozilla/5.0 HeadlessChrome/120.0', true),
			'Lighthouse' => array('Mozilla/5.0 Chrome-Lighthouse', true),
			'curl' => array('curl/8.0', true),
			'Python' => array('python-requests/2.31', true),
			'a link preview' => array('Slackbot-LinkExpanding 1.0', true),
			'none' => array('', true),
			'null' => array(null, true),
		);
	}

	#[DataProvider('agents')]
	public function testIsRobot($agent, $expected) {
		$this->assertSame($expected, SelfHostedVideoStats::isRobot($agent));
	}
}
