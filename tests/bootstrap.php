<?php
/**
 * Bootstrap of the unit tests: starts ProcessWire (the classes of the module build on it) and loads the module.
 *
 * Where ProcessWire is: the environment variable PW_ROOT (the folder with index.php and wire/), or else the
 * website this module is installed in (site/modules/FieldtypeSelfHostedVideo/tests/ lies below it).
 * The tests only read: they change no page, no field and no file of the website.
 */

if(PHP_SAPI !== 'cli') exit("Run the tests from the command line.\n");

$root = (string) getenv('PW_ROOT');
if(!strlen($root)) {
	$dir = realpath(__DIR__ . '/../../../../');
	if($dir && is_file("$dir/index.php") && is_dir("$dir/wire")) $root = $dir;
}
if(!strlen($root) || !is_file("$root/index.php")) {
	fwrite(STDERR, "ProcessWire not found. Set PW_ROOT to the folder that holds index.php and wire/.\n");
	exit(2);
}
if(!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = getenv('PW_HOST') ?: 'localhost';

chdir($root);
require $root . '/index.php';

// the module extends FieldtypeFile, which ProcessWire loads when something asks for it
\ProcessWire\wire('modules')->get('FieldtypeFile');
if(!\ProcessWire\wire('modules')->get('FieldtypeSelfHostedVideo')) {
	fwrite(STDERR, "The module FieldtypeSelfHostedVideo is not installed in this website.\n");
	exit(2);
}

// PHPUnit is used where it is installed; without it a small stand-in runs the same test classes
if(!class_exists('PHPUnit\Framework\TestCase')) require __DIR__ . '/standin.php';
