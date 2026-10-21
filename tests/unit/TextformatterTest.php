<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Video tags in texts: what a tag says, and how a text with tags is rebuilt (here for tags that name no video)
 */
class TextformatterTest extends TestCase {

	protected $formatter;

	protected function setUp(): void {
		$this->formatter = \ProcessWire\wire('modules')->get('TextformatterSelfHostedVideo');
		if(!$this->formatter) $this->markTestSkipped('The text formatter of the module is not installed.');
	}

	public function testTheNameIsTheFirstWord() {
		$tag = $this->formatter->parseTag('intro.mp4 caption align=right width=40');
		$this->assertSame('intro.mp4', $tag['name']);
		$this->assertSame('right', $tag['align']);
		$this->assertSame(40, $tag['width']);
		$this->assertSame(array('caption' => true), $tag['options']);
	}

	public function testANameMayBeQuotedAndMayHaveAPathInFront() {
		$this->assertSame('intro.mp4', $this->formatter->parseTag('"intro.mp4"')['name']);
		$this->assertSame('intro.mp4', $this->formatter->parseTag("'intro.mp4'")['name']);
		$this->assertSame('intro.mp4', $this->formatter->parseTag('/site/assets/files/12/intro.mp4')['name']);
		$this->assertSame('intro.mp4', $this->formatter->parseTag('../../intro.mp4')['name']);
	}

	public function testMarkupAndEntitiesAnEditorLeftInATagAreRemoved() {
		$tag = $this->formatter->parseTag('<strong>intro.mp4</strong>&nbsp;<em>autoplay</em>');
		$this->assertSame('intro.mp4', $tag['name']);
		$this->assertTrue($tag['options']['autoplay']);
		$tag = $this->formatter->parseTag("intro.mp4\xC2\xA0nocaption");
		$this->assertFalse($tag['options']['caption']);
	}

	public static function options() {
		return array(
			'caption' => array('caption', array('caption' => true)),
			'caption=title' => array('caption=title', array('caption' => 'title')),
			'caption=description' => array('caption=description', array('caption' => 'description')),
			'caption=other' => array('caption=other', array('caption' => true)),
			'nocaption' => array('nocaption', array('caption' => false)),
			'notitle' => array('notitle', array('title' => false)),
			'noduration' => array('noduration', array('duration' => false)),
			'nodescription' => array('nodescription', array('description' => false)),
			'info' => array('info', array('info' => true)),
			'info=open' => array('info=open', array('info' => true, 'infoOpen' => true)),
			'noinfo' => array('noinfo', array('info' => false)),
			'transcript' => array('transcript', array('transcript' => true)),
			'transcript=open' => array('transcript=open', array('transcript' => true, 'transcriptOpen' => true)),
			'notranscript' => array('notranscript', array('transcript' => false)),
			'autoplay' => array('autoplay', array('autoplay' => true)),
			'autoplay=yes' => array('autoplay=yes', array('autoplay' => true)),
			'autoplay=no' => array('autoplay=no', array('autoplay' => false)),
			'loop=0' => array('loop=0', array('loop' => false)),
			'muted=false' => array('muted=false', array('muted' => false)),
			'muted=OFF' => array('muted=OFF', array('muted' => false)),
			'nocontrols' => array('nocontrols', array('controls' => false)),
			'nochapters' => array('nochapters', array('chapters' => false)),
			'nooverlays' => array('nooverlays', array('overlays' => false)),
			'start=1:23' => array('start=1:23', array('start' => '1:23')),
			'start=83.5' => array('start=83.5', array('start' => '83.5')),
			'start=bad' => array('start=abc', array()),
			'captions=de' => array('captions=de', array('captions' => 'de')),
			'captions=pt-BR' => array('captions=pt-BR', array('captions' => 'pt-BR')),
			'captions=bad' => array('captions=../x', array()),
			'class=wide' => array('class=wide', array('class' => 'wide')),
			'class=bad' => array('class=1abc', array()),
			'class with a bad character' => array('class=a$b', array()),
			'class=injection' => array("class=a'onclick", array()),
			'unknown' => array('shiny=yes', array()),
		);
	}

	#[DataProvider('options')]
	public function testOptions($spec, $expected) {
		$this->assertSame($expected, $this->formatter->parseTag("v.mp4 $spec")['options']);
	}

	public function testOptionsAreNotCaseSensitiveButNamesOfValuesAre() {
		$this->assertSame(array('autoplay' => true, 'controls' => false), $this->formatter->parseTag('v.mp4 AutoPlay NoControls')['options']);
	}

	public function testAlignAndWidthOnlyTakeKnownValues() {
		foreach(array('left', 'right', 'center') as $align) $this->assertSame($align, $this->formatter->parseTag("v.mp4 align=$align")['align']);
		$this->assertSame('', $this->formatter->parseTag('v.mp4 align=middle')['align']);
		$this->assertSame(0, $this->formatter->parseTag('v.mp4 width=5')['width']);
		$this->assertSame(10, $this->formatter->parseTag('v.mp4 width=10')['width']);
		$this->assertSame(100, $this->formatter->parseTag('v.mp4 width=100')['width']);
		$this->assertSame(0, $this->formatter->parseTag('v.mp4 width=101')['width']);
		$this->assertSame(0, $this->formatter->parseTag('v.mp4 width=wide')['width']);
	}

	public function testATagWithoutAnythingGivesBlank() {
		$tag = $this->formatter->parseTag('');
		$this->assertSame('', $tag['name']);
		$this->assertSame(array(), $tag['options']);
	}

	public function testTheTagPatternFindsTagsWithSpacesAndNonBreakingSpaces() {
		$pattern = '~' . \ProcessWire\TextformatterSelfHostedVideo::tagPattern . '~isu';
		$this->assertSame(1, preg_match($pattern, 'a [[video x.mp4]] b', $m));
		$this->assertSame('x.mp4', $m[1]);
		$this->assertSame(1, preg_match($pattern, '[[video   x.mp4 nocaption  ]]', $m));
		$this->assertSame('x.mp4 nocaption', $m[1]);
		$this->assertSame(1, preg_match($pattern, '[[video&nbsp;x.mp4]]'));
		$this->assertSame(1, preg_match($pattern, "[[video\xC2\xA0x.mp4]]"));
		$this->assertSame(1, preg_match($pattern, '[[VIDEO x.mp4]]'));
		$this->assertSame(0, preg_match($pattern, '[[video]]'));
		$this->assertSame(0, preg_match($pattern, '[[videos x.mp4]]'));
		$this->assertSame(0, preg_match($pattern, '[video x.mp4]'));
	}

	public function testTwoTagsOnOneLineAreTwoTags() {
		$pattern = '~' . \ProcessWire\TextformatterSelfHostedVideo::tagPattern . '~isu';
		$this->assertSame(2, preg_match_all($pattern, '[[video a.mp4]] and [[video b.mp4]]'));
	}

	// replaceTags, for a page without videos: every tag names nothing

	protected function replace($text) {
		return $this->formatter->replaceTags($text, new \ProcessWire\Page());
	}

	public function testATextWithoutTagsIsLeftAlone() {
		$text = '<p>Nothing to see: [video a.mp4] and video.</p>';
		$this->assertSame($text, $this->replace($text));
		$this->assertSame('', $this->replace(''));
	}

	public function testATagThatNamesNoVideoBecomesAComment() {
		$this->assertSame('<!-- video “gone.mp4” not found on this page -->', $this->replace('[[video gone.mp4]]'));
	}

	public function testTheNameInTheCommentCannotCloseIt() {
		$out = $this->replace('[[video a--b.mp4 ]]');
		$this->assertStringNotContainsString('--b', $out);
		$out = $this->replace('[[video "><script>alert(1)</script>.mp4]]');
		$this->assertStringNotContainsString('<script>', $out);
	}

	public function testAParagraphWithATagIsSplitAroundIt() {
		$this->assertSame(
			'<p class="a">Before</p><!-- video “x.mp4” not found on this page --><p class="a">after</p>',
			$this->replace('<p class="a">Before [[video x.mp4]] after</p>')
		);
	}

	public function testAParagraphThatOnlyHoldsATagIsReplacedAsAWhole() {
		$this->assertSame('<!-- video “x.mp4” not found on this page -->', $this->replace('<p>[[video x.mp4]]</p>'));
		$this->assertSame('<!-- video “x.mp4” not found on this page -->', $this->replace('<p>&nbsp;[[video x.mp4]] </p>'));
	}

	public function testTagsInAListAreReplacedInPlace() {
		$this->assertSame('<ul><li>One <!-- video “x.mp4” not found on this page --></li></ul>', $this->replace('<ul><li>One [[video x.mp4]]</li></ul>'));
	}

	public function testSeveralTagsInOneParagraph() {
		$out = $this->replace('<p>A [[video a.mp4]] B [[video b.mp4]] C</p>');
		$this->assertSame(2, substr_count($out, '<!--'));
		$this->assertSame(3, substr_count($out, '<p>'));
	}

	public function testATextThatIsNoUtf8IsLeftAlone() {
		$text = "<p>Caf\xE9 [[video a.mp4]]</p>";
		$this->assertSame($text, $this->replace($text));
	}
}
