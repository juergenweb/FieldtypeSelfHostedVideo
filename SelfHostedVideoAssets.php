<?php namespace ProcessWire;

/**
 * Loads the stylesheet and script that carry the name of a module of this package
 *
 * The files are kept in the folder "assets" of the module. ProcessWire loads such files by itself only
 * when they are next to the module file, so the modules that have them (the input and the Video Manager)
 * ask for them here. The addresses carry the version of the module, and while the website is in debug
 * mode the time the file was changed, so that a browser fetches a new file at once.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 * #pw-internal
 *
 */
class SelfHostedVideoAssets {

	/**
	 * Folder of the files, with the slash at the end
	 *
	 */
	const folder = 'assets/';

	/**
	 * Classes whose files were added in this request
	 *
	 * @var array
	 *
	 */
	protected static $done = array();

	/**
	 * Add the files ClassName.css and ClassName.js of a module, where they exist
	 *
	 * @param string $class Name of the module class
	 * @param string $version Version of the module
	 * @return int Number of files added
	 *
	 */
	public static function load($class, $version) {
		if(isset(self::$done[$class])) return 0;
		self::$done[$class] = true;
		$config = wire()->config;
		$path = __DIR__ . '/' . self::folder;
		$url = $config->urls->get($class) . self::folder;
		$count = 0;
		foreach(array('styles' => 'css', 'scripts' => 'js') as $type => $ext) {
			$file = "$path$class.$ext";
			if(!is_file($file)) continue;
			$config->$type->add("$url$class.$ext?v=$version-" . ($config->debug ? (int) filemtime($file) : $config->version));
			$count++;
		}
		return $count;
	}
}
