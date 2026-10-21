<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/SelfHostedVideoAssets.php';

/**
 * The stylesheets and scripts of the module are in the folder "assets", and the modules that need them ask for them there
 */
class AssetsTest extends TestCase {

	protected function root() {
		return dirname(__DIR__, 2) . '/';
	}

	public function testStylesAndScriptsAreInTheFolderAssets() {
		$files = array('SelfHostedVideo.css', 'SelfHostedVideo.js', 'InputfieldSelfHostedVideo.css', 'InputfieldSelfHostedVideo.js',
			'InputfieldSelfHostedVideoTimeline.js', 'ProcessSelfHostedVideos.css', 'ProcessSelfHostedVideos.js',
			'pwselfhostedvideo.js', 'pwselfhostedvideo-ckeditor.js');
		foreach($files as $file) {
			$this->assertTrue(is_file($this->root() . "assets/$file"), "assets/$file");
			$this->assertGreaterThan(0, filesize($this->root() . "assets/$file"), "assets/$file is not empty");
			$this->assertFalse(is_file($this->root() . $file), "$file is not left next to the module file");
		}
	}

	public function testNoOtherStyleOrScriptIsLeftInTheModuleFolder() {
		$left = array_map('basename', array_merge(glob($this->root() . '*.css'), glob($this->root() . '*.js')));
		$this->assertSame(array(), $left);
	}

	public function testTheFrameworkProfilesStayWhereTheyWere() {
		$this->assertTrue(is_file($this->root() . 'frameworks/uikit3.php'));
		$this->assertTrue(is_file($this->root() . 'frameworks/uikit3.css'));
	}

	public function testLoadingAddsTheFilesOfAModuleOnceWithItsVersion() {
		$config = \ProcessWire\wire('config');
		$list = function($files) { $out = array(); foreach($files as $f) $out[] = (string) $f; return $out; };
		$before = array('styles' => count($list($config->styles)), 'scripts' => count($list($config->scripts)));
		$this->assertSame(2, \ProcessWire\SelfHostedVideoAssets::load('InputfieldSelfHostedVideo', '9.9.9'));
		$this->assertSame(0, \ProcessWire\SelfHostedVideoAssets::load('InputfieldSelfHostedVideo', '9.9.9'), 'a second time: nothing');
		$styles = $list($config->styles);
		$scripts = $list($config->scripts);
		$this->assertSame($before['styles'] + 1, count($styles));
		$this->assertSame($before['scripts'] + 1, count($scripts));
		$this->assertSame(1, preg_match('~/assets/InputfieldSelfHostedVideo\.css\?v=9\.9\.9-~', end($styles)));
		$this->assertSame(1, preg_match('~/assets/InputfieldSelfHostedVideo\.js\?v=9\.9\.9-~', end($scripts)));
		$this->assertSame(0, \ProcessWire\SelfHostedVideoAssets::load('NoSuchModule', '1'), 'a module without such files');
	}

	public function testTheAddressesOnTheWebsiteAreInTheFolderAssets() {
		$urls = \ProcessWire\wire('modules')->get('FieldtypeSelfHostedVideo')->markup()->assetUrls();
		$this->assertSame(1, preg_match('~/FieldtypeSelfHostedVideo/assets/SelfHostedVideo\.css\?v=~', $urls['css']));
		$this->assertSame(1, preg_match('~/FieldtypeSelfHostedVideo/assets/SelfHostedVideo\.js\?v=~', $urls['js']));
	}
}
