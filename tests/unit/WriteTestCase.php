<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestSite.php';

/**
 * Base of the tests that save pages: they run only with SHV_TEST_WRITE=1 (they make and remove test fields,
 * a template and pages in the database of the website), and every test starts without pages of the tests.
 */
abstract class WriteTestCase extends TestCase {

	public static function setUpBeforeClass(): void {
		if(!TestSite::enabled()) self::markTestSkipped('Saves test pages in the database: set the environment variable SHV_TEST_WRITE=1 to run these tests.');
		TestSite::begin();
	}

	public static function tearDownAfterClass(): void {
		if(TestSite::enabled()) TestSite::end();
	}

	protected function setUp(): void {
		TestSite::wipe();
	}

	protected function tearDown(): void {
		TestSite::wipe();
	}

	protected function module() {
		return \ProcessWire\wire('modules')->get('FieldtypeSelfHostedVideo');
	}

	protected function asGuest($fn) {
		$wire = \ProcessWire\wire();
		$was = $wire->user;
		$wire->users->setCurrentUser($wire->users->get('guest'));
		$agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
		try {
			return $fn();
		} finally {
			$wire->users->setCurrentUser($was);
			if($agent === null) unset($_SERVER['HTTP_USER_AGENT']); else $_SERVER['HTTP_USER_AGENT'] = $agent;
		}
	}
}
