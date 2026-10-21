<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ProcessWire\SelfHostedVideoCaptions as C;

/**
 * Caption files: language codes, WebVTT and SubRip, cues and the paragraphs of a transcript
 */
class CaptionsTest extends TestCase {

	public static function codes() {
		return array(
			'upper case' => array('DE', 'de'),
			'underscore' => array('pt_BR', 'pt-br'),
			'three letters' => array('fil', 'fil'),
			'with spaces around' => array(' en ', 'en'),
			'script and region' => array('zh-Hant-TW', 'zh-hant-tw'),
			'one letter' => array('d', ''),
			'digits first' => array('12', ''),
			'a path' => array('../de', ''),
			'a quote' => array('de"x', ''),
			'blank' => array('', ''),
		);
	}

	#[DataProvider('codes')]
	public function testCleanLang($input, $expected) {
		$this->assertSame($expected, C::cleanLang($input));
	}

	public function testLabelOfAKnownLanguageIsItsOwnName() {
		$this->assertSame('Deutsch', C::label('de'));
	}

	public function testLabelOfARegionAddsTheRegion() {
		$this->assertSame('Português (BR)', C::label('pt-br'));
	}

	public function testLabelOfAnUnknownLanguageIsTheCodeInCapitals() {
		$this->assertSame('QQ', C::label('qq'));
	}

	public function testLanguagesAreKeyedByCode() {
		$languages = C::languages();
		$this->assertArrayHasKey('de', $languages);
		$this->assertArrayHasKey('en', $languages);
	}

	// WebVTT and SubRip

	public function testWebVttIsKeptAndCounted() {
		$vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nHello\n\n00:00:05.000 --> 00:01:06.500\nWorld\n";
		$r = C::toVtt($vtt);
		$this->assertSame($vtt, $r['vtt']);
		$this->assertSame(2, $r['cues']);
		$this->assertSame(66.5, $r['end']);
		$this->assertFalse($r['converted']);
		$this->assertSame(0, $r['skipped']);
	}

	public function testSubRipBecomesWebVtt() {
		$srt = "1\r\n00:00:01,500 --> 00:00:04,000\r\nHello\r\n\r\n2\r\n00:00:05,000 --> 00:00:06,250\r\nWorld\r\n";
		$r = C::toVtt($srt);
		$this->assertTrue($r['converted']);
		$this->assertSame(2, $r['cues']);
		$this->assertSame(6.25, $r['end']);
		$this->assertSame("WEBVTT\n\n00:00:01.500 --> 00:00:04.000\nHello\n\n00:00:05.000 --> 00:00:06.250\nWorld\n", $r['vtt']);
	}

	public function testSubRipWithShortNumbersAndStyling() {
		$srt = "1\n0:00:01,5 --> 00:00:04,000 X1:100 Y1:20\n<font color=\"red\">Hi</font> {\\an8}there\n";
		$r = C::toVtt($srt);
		$this->assertSame(1, $r['cues']);
		$this->assertStringContainsString("00:00:01.500 --> 00:00:04.000\nHi there\n", $r['vtt']);
	}

	public function testByteOrderMarkIsRemoved() {
		$r = C::toVtt("\xEF\xBB\xBFWEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n");
		$this->assertStringStartsWith('WEBVTT', $r['vtt']);
	}

	public function testTextThatIsNotUtf8IsTakenToBeWindows1252() {
		$r = C::toVtt("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nSch\xF6ne Gr\xFC\xDFe\n");
		$this->assertSame(1, $r['cues']);
		$this->assertStringContainsString('Schöne Grüße', $r['vtt']);
	}

	public function testTextThatIsNeitherFormatGivesNothing() {
		foreach(array('', "   \n", 'Just some text', "<html><body>Not a caption file</body></html>", "WEBVTT\n\nonly a header") as $text) {
			$r = C::toVtt($text);
			$this->assertSame('', $r['vtt'], var_export($text, true));
			$this->assertSame(0, $r['cues']);
		}
	}

	public function testACaptionThatEndsBeforeItStartsIsSkipped() {
		$r = C::toVtt("WEBVTT\n\n00:00:05.000 --> 00:00:04.000\nBackwards\n\n00:00:06.000 --> 00:00:07.000\nFine\n");
		$this->assertSame(1, $r['cues']);
		$this->assertSame(1, $r['skipped']);
	}

	public function testMinutesAbove59AreSkipped() {
		$r = C::toVtt("WEBVTT\n\n00:75:00.000 --> 00:76:00.000\nNo\n\n00:00:01.000 --> 00:00:02.000\nYes\n");
		$this->assertSame(1, $r['cues']);
		$this->assertSame(1, $r['skipped']);
	}

	public function testAFileWithoutAnyReadableCaptionGivesNothing() {
		$r = C::toVtt("WEBVTT\n\n00:00:05.000 --> 00:00:04.000\nBackwards\n");
		$this->assertSame('', $r['vtt']);
		$this->assertSame(0, $r['cues']);
	}

	public function testNullBytesAreRemoved() {
		$r = C::toVtt("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nH\0i\n");
		$this->assertStringNotContainsString("\0", $r['vtt']);
	}

	// cues

	public function testCuesAreReadWithTheirTimes() {
		$cues = C::cues("WEBVTT\n\n1\n00:00:01.000 --> 00:00:02.500\nHello\n\n01:00:00.000 --> 01:00:01.000\nLater\n");
		$this->assertCount(2, $cues);
		// (whole seconds come back as int, others as float: compared by value)
		$this->assertEquals(array('start' => 1, 'end' => 2.5, 'text' => 'Hello', 'voice' => ''), $cues[0]);
		$this->assertEquals(3600, $cues[1]['start']);
	}

	public function testCuesAreSortedByStart() {
		$cues = C::cues("WEBVTT\n\n00:00:09.000 --> 00:00:10.000\nSecond\n\n00:00:01.000 --> 00:00:02.000\nFirst\n");
		$this->assertSame(array('First', 'Second'), array_column($cues, 'text'));
	}

	public function testCueTagsAreTakenOutAndEntitiesDecoded() {
		$cues = C::cues("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n<i>Tom</i> &amp; <b>Jerry</b> &lt;3\nsecond&nbsp;line\n");
		$this->assertSame('Tom & Jerry <3 second line', $cues[0]['text']);
	}

	public function testCueVoiceIsReadAndTakenOutOfTheText() {
		$cues = C::cues("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n<v.loud Anna>Hi there</v>\n");
		$this->assertSame('Anna', $cues[0]['voice']);
		$this->assertSame('Hi there', $cues[0]['text']);
	}

	public function testBlocksWithoutCuesAreIgnored() {
		$cues = C::cues("WEBVTT Title\n\nNOTE a comment\n--> not a time\n\nSTYLE\n::cue { color: red }\n\n00:00:01.000 --> 00:00:02.000\nOnly this\n\n00:00:03.000 --> 00:00:04.000\n\n");
		$this->assertCount(1, $cues);
		$this->assertSame('Only this', $cues[0]['text']);
	}

	public function testCueWithZeroLengthIsIgnored() {
		$this->assertSame(array(), C::cues("WEBVTT\n\n00:00:01.000 --> 00:00:01.000\nNothing\n"));
	}

	public function testDirectionalMarksAreRemoved() {
		$cues = C::cues("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n\xE2\x80\x8EHello\xE2\x80\x8F\n");
		$this->assertSame('Hello', $cues[0]['text']);
	}

	// paragraphs

	protected function cue($start, $end, $text, $voice = '') {
		return array('start' => $start, 'end' => $end, 'text' => $text, 'voice' => $voice);
	}

	public function testCuesCloseTogetherFormOneParagraph() {
		$p = C::paragraphs(array($this->cue(0, 2, 'One'), $this->cue(2.5, 4, 'two'), $this->cue(4.5, 6, 'three')));
		$this->assertCount(1, $p);
		$this->assertSame('One two three', $p[0]['text']);
		$this->assertEquals(0, $p[0]['time']);
		$this->assertEquals(6, $p[0]['end']);
	}

	public function testAPauseOfTwoSecondsBeginsAParagraph() {
		$p = C::paragraphs(array($this->cue(0, 2, 'One'), $this->cue(3.9, 5, 'Still')));
		$this->assertCount(1, $p);
		$p = C::paragraphs(array($this->cue(0, 2, 'One'), $this->cue(4, 5, 'New')));
		$this->assertCount(2, $p);
		$this->assertEquals(4, $p[1]['time']);
	}

	public function testAnotherVoiceBeginsAParagraph() {
		$p = C::paragraphs(array($this->cue(0, 2, 'Hi', 'Anna'), $this->cue(2, 3, 'Hello', 'Ben'), $this->cue(3, 4, 'Bye', 'Ben')));
		$this->assertCount(2, $p);
		$this->assertSame('Anna', $p[0]['voice']);
		$this->assertSame('Hello Bye', $p[1]['text']);
	}

	public function testTheSameTextTwiceInARowIsWrittenOnce() {
		$p = C::paragraphs(array($this->cue(0, 2, 'Line'), $this->cue(1, 3, 'Line'), $this->cue(3, 4, 'Next')));
		$this->assertSame('Line Next', $p[0]['text']);
	}

	public function testALongParagraphIsBrokenAtTheEndOfASentence() {
		$cues = array();
		for($t = 0; $t < 30; $t += 2) $cues[] = $this->cue($t, $t + 2, $t == 20 ? 'Sentence ends.' : "Words $t");
		$p = C::paragraphs($cues);
		$this->assertCount(2, $p);
		$this->assertEquals(22, $p[1]['time'], 'the cue after 20 seconds and a full stop');
	}

	public function testALongParagraphWithoutASentenceEndIsNotBroken() {
		$cues = array();
		for($t = 0; $t < 40; $t += 2) $cues[] = $this->cue($t, $t + 2, "Words $t");
		$this->assertCount(1, C::paragraphs($cues));
	}

	public function testNoCuesNoParagraphs() {
		$this->assertSame(array(), C::paragraphs(array()));
	}
}
