<?php namespace PHPUnit\Framework {

	if(PHP_SAPI !== 'cli') exit;

	/**
	 * Just enough of PHPUnit to run the tests of this module where PHPUnit is not installed.
	 * With PHPUnit installed this file is not loaded.
	 */
	class AssertionFailedError extends \Exception {}
	class SkippedTestError extends \Exception {}

	abstract class TestCase {

		protected $expected = null;
		public $assertions = 0;

		public static function setUpBeforeClass(): void {}
		public static function tearDownAfterClass(): void {}
		protected function setUp(): void {}
		protected function tearDown(): void {}
		public function _setUp() { $this->setUp(); }
		public function _tearDown() { $this->tearDown(); }
		public function _expected() { return $this->expected; }

		protected function fail($message = '') { throw new AssertionFailedError($message); }
		protected function _check($ok, $message, $detail = '') {
			$this->assertions++;
			if(!$ok) throw new AssertionFailedError(trim(($message !== '' ? "$message — " : '') . $detail, ' —'));
		}
		protected function _show($v) { if(is_string($v) && strlen($v) > 600) $v = substr($v, 0, 300) . ' … [' . strlen($v) . ' characters in all] … ' . substr($v, -150); return is_string($v) ? var_export($v, true) : (is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)); }

		public function assertSame($expected, $actual, $message = '') { $this->_check($expected === $actual, $message, 'expected ' . $this->_show($expected) . ', got ' . $this->_show($actual)); }
		public function assertNotSame($expected, $actual, $message = '') { $this->_check($expected !== $actual, $message, 'got the same: ' . $this->_show($actual)); }
		public function assertEquals($expected, $actual, $message = '') { $this->_check($expected == $actual, $message, 'expected ' . $this->_show($expected) . ', got ' . $this->_show($actual)); }
		public function assertEqualsWithDelta($expected, $actual, $delta, $message = '') { $this->_check(is_numeric($actual) && abs($expected - $actual) <= $delta, $message, 'expected ' . $this->_show($expected) . ', got ' . $this->_show($actual)); }
		public function assertTrue($actual, $message = '') { $this->_check($actual === true, $message, 'expected true, got ' . $this->_show($actual)); }
		public function assertFalse($actual, $message = '') { $this->_check($actual === false, $message, 'expected false, got ' . $this->_show($actual)); }
		public function assertNull($actual, $message = '') { $this->_check($actual === null, $message, 'expected null, got ' . $this->_show($actual)); }
		public function assertNotNull($actual, $message = '') { $this->_check($actual !== null, $message, 'got null'); }
		public function assertEmpty($actual, $message = '') { $this->_check(empty($actual), $message, 'expected empty, got ' . $this->_show($actual)); }
		public function assertNotEmpty($actual, $message = '') { $this->_check(!empty($actual), $message, 'got empty'); }
		public function assertCount($count, $actual, $message = '') { $this->_check(count($actual) === $count, $message, "expected $count items, got " . count($actual) . ': ' . $this->_show($actual)); }
		public function assertGreaterThan($min, $actual, $message = '') { $this->_check($actual > $min, $message, 'expected more than ' . $this->_show($min) . ', got ' . $this->_show($actual)); }
		public function assertLessThanOrEqual($max, $actual, $message = '') { $this->_check($actual <= $max, $message, 'expected at most ' . $this->_show($max) . ', got ' . $this->_show($actual)); }
		public function assertContains($needle, $haystack, $message = '') { $this->_check(in_array($needle, (array) $haystack, true), $message, $this->_show($needle) . ' not in ' . $this->_show($haystack)); }
		public function assertNotContains($needle, $haystack, $message = '') { $this->_check(!in_array($needle, (array) $haystack, true), $message, $this->_show($needle) . ' is in ' . $this->_show($haystack)); }
		public function assertArrayHasKey($key, $array, $message = '') { $this->_check(is_array($array) && array_key_exists($key, $array), $message, 'no key ' . $this->_show($key) . ' in ' . $this->_show($array)); }
		public function assertArrayNotHasKey($key, $array, $message = '') { $this->_check(is_array($array) && !array_key_exists($key, $array), $message, 'key ' . $this->_show($key) . ' is there'); }
		public function assertStringContainsString($needle, $haystack, $message = '') { $this->_check(strpos((string) $haystack, $needle) !== false, $message, $this->_show($needle) . ' not in ' . $this->_show($haystack)); }
		public function assertStringNotContainsString($needle, $haystack, $message = '') { $this->_check(strpos((string) $haystack, $needle) === false, $message, $this->_show($needle) . ' is in ' . $this->_show($haystack)); }
		public function assertStringStartsWith($prefix, $string, $message = '') { $this->_check(strpos((string) $string, $prefix) === 0, $message, $this->_show($string) . ' does not start with ' . $this->_show($prefix)); }
		public function assertMatchesRegularExpression($pattern, $string, $message = '') { $this->_check(preg_match($pattern, (string) $string) === 1, $message, $this->_show($string) . " does not match $pattern"); }
		public function assertDoesNotMatchRegularExpression($pattern, $string, $message = '') { $this->_check(preg_match($pattern, (string) $string) !== 1, $message, $this->_show($string) . " matches $pattern"); }
		public function assertInstanceOf($class, $actual, $message = '') { $this->_check($actual instanceof $class, $message, 'not an instance of ' . $class); }
		public static function markTestSkipped($message = '') { throw new SkippedTestError($message); }
		public function expectException($class) { $this->expected = $class; }
	}
}

namespace PHPUnit\Framework\Attributes {
	#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
	class DataProvider { public $method; public function __construct($method) { $this->method = $method; } }
}
