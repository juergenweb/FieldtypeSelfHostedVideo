<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Reach.php';
require_once dirname(__DIR__, 2) . '/SelfHostedVideoMigration.php';

/**
 * The move from the module under its old name (FieldtypeVideoFile): the renaming of settings, translation files and paths.
 *
 * Only what changes nothing is tested: the steps that change the database or move files of the website
 * (prepare() and finish()) are not run here.
 */
class MigrationTest extends TestCase {

	protected $migration;

	protected function setUp(): void {
		$this->migration = new \ProcessWire\SelfHostedVideoMigration(Reach::module());
		\ProcessWire\wire($this->migration);
	}

	protected function rename($value) {
		return Reach::call($this->migration, 'renameIn', $value);
	}

	public function testEveryOldClassHasANewOneThatExists() {
		foreach(\ProcessWire\SelfHostedVideoMigration::classNames as $old => $new) {
			$this->assertStringContainsString('VideoFile', $old);
			$this->assertStringNotContainsString('VideoFile', $new);
			$this->assertNotNull(\ProcessWire\wire('modules')->getModuleInfo($new, array('noCache' => true)), "$new is a module");
		}
	}

	public function testTheNamesInSettingsAreAllMappedToNewOnes() {
		$names = \ProcessWire\SelfHostedVideoMigration::settingNames;
		foreach(\ProcessWire\SelfHostedVideoMigration::classNames as $old => $new) $this->assertSame($new, $names[$old]);
		$this->assertSame('PWSelfHostedVideo', $names['PWVideoFile'], 'the button of the rich text editor');
		$this->assertSame('pwselfhostedvideo', $names['pwvideofile'], 'and its plugin');
	}

	public static function strings() {
		return array(
			'type of a field' => array('FieldtypeVideoFile', 'FieldtypeSelfHostedVideo'),
			'input' => array('InputfieldVideoFile', 'InputfieldSelfHostedVideo'),
			'formatter' => array('TextformatterVideoFile', 'TextformatterSelfHostedVideo'),
			'manager' => array('ProcessVideoFiles', 'ProcessSelfHostedVideos'),
			'toolbar of an editor' => array('bold italic PWVideoFile link', 'bold italic PWSelfHostedVideo link'),
			'plugin of an editor' => array('extraPlugins: pwvideofile', 'extraPlugins: pwselfhostedvideo'),
			'unrelated' => array('nothing to do here', 'nothing to do here'),
			'empty' => array('', ''),
			'already new' => array('FieldtypeSelfHostedVideo', 'FieldtypeSelfHostedVideo'),
		);
	}

	#[DataProvider('strings')]
	public function testStringsGetTheirNewNames($old, $new) {
		$this->assertSame($new, $this->rename($old));
	}

	public function testNestedSettingsAreRenamedInKeysAndValues() {
		$old = array(
			'type' => 'FieldtypeVideoFile',
			'toolbar' => array('PWVideoFile', 'bold'),
			'FieldtypeVideoFile' => array('a' => 1, 'plugin' => 'pwvideofile'),
			'textformatters' => array('TextformatterVideoFile'),
			5 => 7,
			'flag' => true,
			'none' => null,
		);
		$new = array(
			'type' => 'FieldtypeSelfHostedVideo',
			'toolbar' => array('PWSelfHostedVideo', 'bold'),
			'FieldtypeSelfHostedVideo' => array('a' => 1, 'plugin' => 'pwselfhostedvideo'),
			'textformatters' => array('TextformatterSelfHostedVideo'),
			5 => 7,
			'flag' => true,
			'none' => null,
		);
		$this->assertSame($new, $this->rename($old));
	}

	public function testRenamingTwiceChangesNothingMore() {
		$once = $this->rename(array('a' => 'FieldtypeVideoFile InputfieldVideoFile PWVideoFile'));
		$this->assertSame($once, $this->rename($once));
	}

	public function testNumbersAndObjectsPassThroughUnchanged() {
		$this->assertSame(12, $this->rename(12));
		$this->assertSame(1.5, $this->rename(1.5));
		$this->assertSame(array(), $this->rename(array()));
	}

	public static function textdomains() {
		return array(
			array('fieldtypevideofile', 'fieldtypeselfhostedvideo'),
			array('inputfieldvideofile', 'inputfieldselfhostedvideo'),
			array('textformattervideofile', 'textformatterselfhostedvideo'),
			array('processvideofiles', 'processselfhostedvideos'),
			array('wire--modules--process--processpagelist--processpagelist-module', 'wire--modules--process--processpagelist--processpagelist-module'),
			array('fieldtypeselfhostedvideo', 'fieldtypeselfhostedvideo'),
		);
	}

	#[DataProvider('textdomains')]
	public function testTranslationFilesGetTheirNewNames($old, $new) {
		$this->assertSame($new, \ProcessWire\SelfHostedVideoMigration::newTextdomain($old));
	}

	public static function paths() {
		return array(
			array('FieldtypeVideoFile.module.php', 'FieldtypeSelfHostedVideo.module.php'),
			array('/site/modules/FieldtypeVideoFile/InputfieldVideoFile.js', '/site/modules/FieldtypeSelfHostedVideo/InputfieldSelfHostedVideo.js'),
			array('ProcessVideoFiles.module.php', 'ProcessSelfHostedVideos.module.php'),
			array('FieldtypeSelfHostedVideo.module.php', 'FieldtypeSelfHostedVideo.module.php'),
			array('', ''),
		);
	}

	#[DataProvider('paths')]
	public function testPathsOfModuleFilesGetTheirNewNames($old, $new) {
		$this->assertSame($new, \ProcessWire\SelfHostedVideoMigration::newPath($old));
	}

	public function testTheMarkersInHtaccessFilesBecomeTheNewOnes() {
		$markers = \ProcessWire\SelfHostedVideoMigration::markers;
		$this->assertCount(2, $markers);
		$this->assertSame(array_values($markers), array(\ProcessWire\SelfHostedVideoEmbed::markerStart, \ProcessWire\SelfHostedVideoEmbed::markerEnd));
		foreach(array_keys($markers) as $old) $this->assertStringContainsString('FieldtypeVideoFile', $old);
		foreach($markers as $new) $this->assertStringNotContainsString('VideoFile', $new);
	}

	public function testTheOldModulesAreLookedForInTheModulesTable() {
		$rows = $this->migration->oldModules();
		$this->assertTrue(is_array($rows));
		foreach($rows as $class => $row) {
			$this->assertArrayHasKey($class, \ProcessWire\SelfHostedVideoMigration::classNames);
			$this->assertTrue(is_int($row['id']));
			$this->assertTrue(is_array($row['data']));
		}
		$this->assertTrue(is_bool($this->migration->needed()));
		$this->assertTrue(is_bool($this->migration->oldFolderExists()));
		$this->assertSame('FieldtypeVideoFile/', substr($this->migration->oldFolderPath(), -19));
	}
}
