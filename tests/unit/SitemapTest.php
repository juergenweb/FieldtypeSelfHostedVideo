<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Reach.php';

/**
 * The video sitemap: how texts are shortened and escaped, and how one entry is written
 */
class SitemapTest extends TestCase {

	protected $sitemap;

	protected function setUp(): void {
		$this->sitemap = Reach::module()->sitemap();
	}

	public function testXmlEscapesWhatXmlNeeds() {
		$this->assertSame('a &amp; b &lt;c&gt; &quot;d&quot; &apos;e&apos;', Reach::call($this->sitemap, 'xml', 'a & b <c> "d" \'e\''));
		$this->assertSame('', Reach::call($this->sitemap, 'xml', null));
		$this->assertSame('Grüße', Reach::call($this->sitemap, 'xml', 'Grüße'));
	}

	public function testCutLeavesShortTextsAndTidiesSpaces() {
		$this->assertSame('A short text', Reach::call($this->sitemap, 'cut', "  A  short\n text ", 100));
		$this->assertSame('', Reach::call($this->sitemap, 'cut', null, 100));
	}

	public function testCutBreaksAtASpaceAndAddsAnEllipsis() {
		$text = 'one two three four five six seven eight nine ten';
		$out = Reach::call($this->sitemap, 'cut', $text, 20);
		$this->assertSame('one two three four…', $out);
		$this->assertLessThanOrEqual(20, mb_strlen($out));
	}

	public function testCutWithoutAGoodSpaceCutsInTheWord() {
		$out = Reach::call($this->sitemap, 'cut', str_repeat('x', 50), 20);
		$this->assertSame(str_repeat('x', 19) . '…', $out);
	}

	public function testCutDropsPunctuationBeforeTheEllipsis() {
		$out = Reach::call($this->sitemap, 'cut', 'First part, second part, third part', 22);
		$this->assertSame('First part, second…', $out);
	}

	public function testCutCountsCharactersNotBytes() {
		$out = Reach::call($this->sitemap, 'cut', str_repeat('ä', 30), 10);
		$this->assertSame(10, mb_strlen($out));
	}

	protected function entry(array $video, $loc = 'https://example.com/page/') {
		return Reach::call($this->sitemap, 'renderEntry', array('loc' => $loc, 'videos' => array($video)));
	}

	protected function video(array $over = array()) {
		return $over + array('thumbnailUrl' => 'https://example.com/t.jpg', 'name' => 'A title', 'description' => 'A description', 'contentUrl' => 'https://example.com/v.mp4', 'seconds' => 93, 'uploadDate' => '2026-01-02T03:04:05+00:00', 'keywords' => 'one, two');
	}

	public function testAnEntryHasWhatSearchEnginesAskFor() {
		$xml = $this->entry($this->video());
		$this->assertStringContainsString('<loc>https://example.com/page/</loc>', $xml);
		$this->assertStringContainsString('<video:thumbnail_loc>https://example.com/t.jpg</video:thumbnail_loc>', $xml);
		$this->assertStringContainsString('<video:title>A title</video:title>', $xml);
		$this->assertStringContainsString('<video:description>A description</video:description>', $xml);
		$this->assertStringContainsString('<video:content_loc>https://example.com/v.mp4</video:content_loc>', $xml);
		$this->assertStringContainsString('<video:duration>93</video:duration>', $xml);
		$this->assertStringContainsString('<video:publication_date>2026-01-02T03:04:05+00:00</video:publication_date>', $xml);
		$this->assertStringContainsString('<video:tag>one</video:tag>', $xml);
		$this->assertStringContainsString('<video:tag>two</video:tag>', $xml);
	}

	public function testAnEntryIsWellFormedXmlEvenWithSpecialCharacters() {
		$xml = $this->entry($this->video(array('name' => 'Tom & "Jerry" <3', 'description' => "Line\nbreak & more", 'keywords' => 'a&b, <c>', 'contentUrl' => 'https://example.com/v.mp4?a=1&b=2')), 'https://example.com/?a=1&b=2');
		$doc = new \DOMDocument();
		$wrapped = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . $xml . '</urlset>';
		$this->assertTrue($doc->loadXML($wrapped), 'the XML can be read');
		$this->assertStringContainsString('Tom &amp; &quot;Jerry&quot; &lt;3', $xml);
	}

	public function testWithoutADescriptionTheTitleStandsIn() {
		$xml = $this->entry($this->video(array('description' => '')));
		$this->assertStringContainsString('<video:description>A title</video:description>', $xml);
	}

	public function testLongTitlesAreShortenedTo100Characters() {
		$xml = $this->entry($this->video(array('name' => str_repeat('word ', 40))));
		preg_match('~<video:title>(.*)</video:title>~u', $xml, $m);
		$this->assertLessThanOrEqual(100, mb_strlen(html_entity_decode($m[1])));
	}

	public function testDurationIsLeftOutWhenUnknownAndLimitedToEightHours() {
		$this->assertStringNotContainsString('video:duration', $this->entry($this->video(array('seconds' => 0))));
		$this->assertStringContainsString('<video:duration>28800</video:duration>', $this->entry($this->video(array('seconds' => 99999))));
	}

	public function testAtMost32TagsAndNoEmptyOnes() {
		$tags = implode(', ', array_merge(array('', ' '), array_map(function($n) { return "tag$n"; }, range(1, 40))));
		$xml = $this->entry($this->video(array('keywords' => $tags)));
		$this->assertSame(32, substr_count($xml, '<video:tag>'));
		$this->assertStringNotContainsString('<video:tag></video:tag>', $xml);
	}

	public function testAThumbnailGivenAsAListTakesTheFirst() {
		$xml = $this->entry($this->video(array('thumbnailUrl' => array('https://example.com/a.jpg', 'https://example.com/b.jpg'))));
		$this->assertStringContainsString('https://example.com/a.jpg', $xml);
		$this->assertStringNotContainsString('b.jpg', $xml);
	}

	public function testSeveralVideosOnOnePage() {
		$xml = Reach::call($this->sitemap, 'renderEntry', array('loc' => 'https://example.com/', 'videos' => array($this->video(), $this->video(array('name' => 'Second')))));
		$this->assertSame(1, substr_count($xml, '<url>'));
		$this->assertSame(2, substr_count($xml, '<video:video>'));
	}
}
