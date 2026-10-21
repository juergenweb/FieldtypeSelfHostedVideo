<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ProcessWire\SelfHostedVideoFrameworks as F;

require_once __DIR__ . '/Reach.php';

/**
 * Profiles for CSS frameworks: what is kept of a profile, and how it changes an element
 */
class FrameworksTest extends TestCase {

	public static function names() {
		return array(
			array('uikit', true), array('bootstrap5', true), array('my_profile-2', true), array('0abc', true),
			array('', false), array('-x', false), array('_x', false), array('Upper', false), array('a b', false),
			array('../etc', false), array('a.b', false), array(str_repeat('a', 65), false), array(5, false), array(null, false),
		);
	}

	#[DataProvider('names')]
	public function testIsName($name, $expected) {
		$this->assertSame($expected, F::isName($name));
	}

	public function testNormalizeKeepsTitleAndStylesheetAndParent() {
		$p = F::normalize(array('title' => '  My Framework ', 'css' => 'my.css', 'extends' => 'uikit', 'parts' => array()), 'mine');
		$this->assertSame('mine', $p['name']);
		$this->assertSame('My Framework', $p['title']);
		$this->assertSame('my.css', $p['css']);
		$this->assertSame('uikit', $p['extends']);
		$this->assertSame(array(), $p['parts']);
	}

	public function testNormalizeTakesTheNameAsTitleWhenThereIsNone() {
		$this->assertSame('mine', F::normalize(array(), 'mine')['title']);
		$this->assertSame('mine', F::normalize(array('title' => '  ', 'parts' => 'x'), 'mine')['title']);
	}

	public function testNormalizeAcceptsOnlyAStylesheetNextToTheProfile() {
		foreach(array('../evil.css', 'sub/a.css', 'a.js', 'a.css.php', 'https://x.example/a.css', '.hidden.css', '') as $css) {
			$this->assertSame('', F::normalize(array('css' => $css))['css'], $css);
		}
	}

	public function testNormalizeRejectsABadParentName() {
		$this->assertSame('', F::normalize(array('extends' => '../x'))['extends']);
	}

	public function testAPartGivenAsAStringIsItsClass() {
		$p = F::normalize(array('parts' => array('player' => 'uk-card uk-card-body')));
		$this->assertSame('uk-card uk-card-body', $p['parts']['player']['class']);
		$this->assertFalse($p['parts']['player']['look'], 'a part with class names takes its look from them');
	}

	public function testClassNamesAreCleanedAndDeduplicated() {
		$p = F::normalize(array('parts' => array('player' => array('class' => array('a', 'b', 'a', 5, 'w-1/2 md:flex w-[10px] [&>p]:mt-2'), 'current' => 'is-on  is-on "bad" <x>'))));
		$this->assertSame('a b w-1/2 md:flex w-[10px]', $p['parts']['player']['class'], 'a name with & or > cannot end up in markup');
		$this->assertSame('is-on', $p['parts']['player']['current']);
	}

	public function testAttributesThatCouldDoHarmAreDropped() {
		$p = F::normalize(array('parts' => array('player' => array('class' => 'x', 'attrs' => array(
			'data-uk-video' => true, 'ARIA-label' => 'Video', 'onclick' => 'alert(1)', 'onload' => 'x', 'class' => 'y', 'style' => 'color:red', 'id' => 'z',
			'bad name' => 'x', 'array' => array(1), 'object' => new \stdClass(), 'width' => 5,
		)))));
		$this->assertSame(array('data-uk-video' => true, 'aria-label' => 'Video', 'width' => 5), $p['parts']['player']['attrs']);
	}

	public function testAPartThatSaysNothingIsLeftOut() {
		$p = F::normalize(array('parts' => array('player' => array(), 'overlay' => '', 'other' => array('class' => 'x'))));
		$this->assertSame(array('other'), array_keys($p['parts']));
	}

	public function testPartNamesMustBeLowerCaseWords() {
		$p = F::normalize(array('parts' => array('Player' => 'x', '1st' => 'x', 'chapter-list' => 'x', 0 => 'x')));
		$this->assertSame(array('chapter-list'), array_keys($p['parts']));
	}

	public function testLookCanBeSetEitherWay() {
		$p = F::normalize(array('parts' => array('a' => array('class' => 'x', 'look' => true), 'b' => array('attrs' => array('data-x' => 1)))));
		$this->assertTrue($p['parts']['a']['look']);
		$this->assertTrue($p['parts']['b']['look'], 'no class names: the look stays with the module');
	}

	public function testApplyWithoutAProfileChangesNothing() {
		$attrs = array('class' => 'shv-video', 'id' => 'a');
		$this->assertSame($attrs, F::apply(null, 'player', $attrs));
		$this->assertSame($attrs, F::apply(F::normalize(array()), 'player', $attrs));
	}

	public function testApplyAddsClassNamesAndMarksThePartAsPlain() {
		$profile = F::normalize(array('parts' => array('player' => 'uk-card')));
		$out = F::apply($profile, 'player', array('class' => 'shv-video'));
		$this->assertMatchesRegularExpression('/^shv-video uk-card /', $out['class']);
		$this->assertStringContainsString(F::plainClass, $out['class']);
	}

	public function testApplyWithClassNamesButTheModulesLookKeepsTheLook() {
		$profile = F::normalize(array('parts' => array('player' => array('class' => 'uk-card', 'look' => true))));
		$out = F::apply($profile, 'player', array('class' => 'shv-video'));
		$this->assertSame('shv-video uk-card', $out['class']);
	}

	public function testApplyAlsoUsesVariantsOfAPart() {
		$profile = F::normalize(array('parts' => array('overlay' => array('class' => 'o', 'look' => true), 'overlay-dark' => array('class' => 'dark', 'look' => true))));
		$out = F::apply($profile, 'overlay', array('class' => 'shv'), array('overlay-dark', 'overlay-m'));
		$this->assertSame('shv o dark', $out['class']);
	}

	public function testApplyDoesNotChangeWhatTheModuleSetItself() {
		$profile = F::normalize(array('parts' => array('player' => array('class' => 'x', 'look' => true, 'attrs' => array('controls' => 'no', 'data-new' => '1')))));
		$out = F::apply($profile, 'player', array('class' => 'a', 'controls' => true));
		$this->assertTrue($out['controls']);
		$this->assertSame('1', $out['data-new']);
	}

	public function testApplyPassesTheCurrentClassOnToTheScript() {
		$profile = F::normalize(array('parts' => array('chapter' => array('class' => 'c', 'look' => true, 'current' => 'active'))));
		$out = F::apply($profile, 'chapter', array('class' => 'shv'));
		$this->assertSame('active', $out['data-shv-current-class']);
	}

	public function testApplyToAPartThatIsNotNamedChangesNothing() {
		$profile = F::normalize(array('parts' => array('player' => 'x')));
		$this->assertSame(array('class' => 'a'), F::apply($profile, 'chapter', array('class' => 'a')));
	}

	public function testThePartsThatCanBeNamedAreListed() {
		$parts = F::getParts();
		foreach(array('player', 'overlay', 'chapters') as $part) $this->assertArrayHasKey($part, $parts);
	}

	public function testTheProfilesThatComeWithTheModuleAreValid() {
		$frameworks = Reach::module()->frameworks();
		$found = $frameworks->find();
		$this->assertGreaterThan(2, count($found));
		foreach(array_keys($found) as $name) {
			$this->assertTrue(F::isName($name), $name);
			$profile = $frameworks->get($name);
			$this->assertTrue(is_array($profile), "$name loads");
			$this->assertNotSame('', $profile['title']);
		}
	}
}
