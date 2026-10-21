<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Reach.php';

/**
 * Small helpers of the markup of a video: attributes, ids, numbers, language codes
 */
class MarkupHelpersTest extends TestCase {

	protected $markup;

	protected function setUp(): void {
		$this->markup = Reach::module()->markup();
	}

	public static function numbers() {
		return array(array(5, '5'), array(5.0, '5'), array(5.5, '5.5'), array(5.504, '5.5'), array(5.506, '5.51'), array(0, '0'), array(0.001, '0'), array('12.30', '12.3'), array(100, '100'), array(-1.5, '-1.5'));
	}

	#[DataProvider('numbers')]
	public function testNumbersForMarkupHavePointsAndNoNeedlessZeros($value, $expected) {
		$this->assertSame($expected, Reach::call($this->markup, 'number', $value));
	}

	public function testAttributesAreEscapedAndBooleansHaveNoValue() {
		$out = Reach::call($this->markup, 'attrString', array('class' => 'a b', 'controls' => true, 'hidden' => false, 'x' => null, 'title' => "It's <b>&", 'data-n' => 5));
		$this->assertStringContainsString(" class='a b'", $out);
		$this->assertStringContainsString(' controls', $out);
		$this->assertStringNotContainsString('hidden', $out);
		$this->assertStringNotContainsString(' x', $out);
		$this->assertStringNotContainsString('<b>', $out);
		$this->assertStringNotContainsString("It's", $out, 'a quote cannot end the value');
		$this->assertStringContainsString(" data-n='5'", $out);
	}

	public function testNoAttributesNoString() {
		$this->assertSame('', Reach::call($this->markup, 'attrString', array()));
	}

	public function testMergedAttributesCannotCarryEventHandlers() {
		$out = Reach::call($this->markup, 'mergeAttrs', array('class' => 'shv', 'controls' => true), array('onclick' => 'x()', 'OnLoad' => 'y()', 'data-a' => '1', 'class' => 'extra', 'controls' => false, 'bad name' => 'z'));
		$this->assertSame(array('class' => 'shv extra', 'data-a' => '1'), $out);
	}

	public function testMergingSomethingThatIsNoListChangesNothing() {
		$this->assertSame(array('class' => 'a'), Reach::call($this->markup, 'mergeAttrs', array('class' => 'a'), 'text'));
		$this->assertSame(array('class' => 'a'), Reach::call($this->markup, 'mergeAttrs', array('class' => 'a'), null));
	}

	public function testIdsAreUniqueOnAPage() {
		$a = Reach::call($this->markup, 'uniqueId', 'shv-test-id');
		$b = Reach::call($this->markup, 'uniqueId', 'shv-test-id');
		$c = Reach::call($this->markup, 'uniqueId', 'shv-test-id');
		$this->assertSame('shv-test-id', $a);
		$this->assertSame('shv-test-id-2', $b);
		$this->assertSame('shv-test-id-3', $c);
	}

	public function testIdsKeepOnlyUsableCharacters() {
		$this->assertSame('a-b-c', Reach::call($this->markup, 'uniqueId', 'a b/c'));
		$this->assertSame('video', Reach::call($this->markup, 'uniqueId', ''));
	}

	public function testPreloadFollowsTheOptionOrTheLazyFlag() {
		$this->assertSame('auto', Reach::call($this->markup, 'preloadValue', array('preload' => 'AUTO', 'lazy' => true)));
		$this->assertSame('none', Reach::call($this->markup, 'preloadValue', array('preload' => '', 'lazy' => true)));
		$this->assertSame('metadata', Reach::call($this->markup, 'preloadValue', array('preload' => 'nonsense', 'lazy' => false)));
	}

	public static function tags() {
		return array(array('de', 'de'), array('de-at', 'de-AT'), array('pt-br', 'pt-BR'), array('zh-hant-tw', 'zh-hant-TW'), array('en-gb', 'en-GB'));
	}

	#[DataProvider('tags')]
	public function testLanguageTags($code, $expected) {
		$this->assertSame($expected, Reach::call(get_class($this->markup), 'languageTag', $code));
	}

	public function testDefaultOptionsAreComplete() {
		$o = \ProcessWire\SelfHostedVideoMarkup::getDefaultOptions();
		foreach(array('controls', 'autoplay', 'loop', 'muted', 'lazy', 'preload', 'caption', 'start') as $key) $this->assertArrayHasKey($key, $o);
		$this->assertFalse($o['autoplay'], 'a video does not start by itself unless asked to');
	}

	public function testAbsoluteUrlsAreMadeFromAddressesOfTheSite() {
		$url = $this->markup->absoluteUrl('/site/assets/files/1/a.mp4');
		$this->assertMatchesRegularExpression('~^https?://[^/]+/site/assets/files/1/a\.mp4$~', $url);
		$this->assertSame('https://other.example/a.mp4', $this->markup->absoluteUrl('https://other.example/a.mp4'));
	}
}
