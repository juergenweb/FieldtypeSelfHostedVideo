<?php namespace ProcessWire\SelfHostedVideoTests;

/**
 * Makes (up) and removes (down) what the browser tests need in the website: test pages with videos, a template file for
 * them and a user of their own with a password made for the test. Called by run.js, which reads what `up` prints:
 *
 *   php tests/browser/setup.php up      prints JSON with addresses and the sign-in of the test user
 *   php tests/browser/setup.php down
 *
 * Needs SHV_TEST_WRITE=1 (it writes to the database of the website), like the other tests that save pages.
 */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/unit/TestSite.php';

$wire = \ProcessWire\wire();
$mode = isset($argv[1]) ? $argv[1] : '';
if(!TestSite::enabled()) { fwrite(STDERR, "Set SHV_TEST_WRITE=1: this writes test pages to the database of the website.\n"); exit(3); }
if(!in_array($mode, array('up', 'down'), true)) { fwrite(STDERR, "Usage: php setup.php up|down\n"); exit(2); }

$userName = 'shvtest-browser';
$templateFile = $wire->config->paths->templates . TestSite::prefix . '.php';
$keep = __DIR__ . '/.state/';
$sitemapBackup = $keep . 'sitemap-videos.xml';

/** Path of a page on the website, with the language of the website if it has one */
$pathOf = function($page) use($wire) {
	$root = rtrim($wire->config->urls->root, '/');
	$url = $page->url;
	$languages = $wire->languages;
	if($languages) {
		try { $local = (string) $page->localUrl($languages->getDefault()); } catch(\Exception $e) { $local = ''; }
		if(strlen($local)) $url = $local;
	}
	if(strlen($root) && strpos($url, $root . '/') === 0) $url = substr($url, strlen($root));
	return '/' . ltrim($url, '/');
};

$removeUser = function() use($wire, $userName) {
	$user = $wire->users->get('name=' . $userName);
	if($user && $user->id) $wire->users->delete($user, true);
};

if($mode === 'down') {
	TestSite::begin();
	TestSite::end();
	$removeUser();
	// (only the file the tests made: one of the website's own with this name stays)
	if(is_file($templateFile) && strpos((string) file_get_contents($templateFile), 'shvtest-marker') !== false) @unlink($templateFile);
	if(is_file($sitemapBackup)) {
		$target = $wire->modules->get('FieldtypeSelfHostedVideo')->sitemap()->filename();
		file_put_contents($target, file_get_contents($sitemapBackup));
		@unlink($sitemapBackup);
	}
	@rmdir($keep);
	exit(0);
}

// up
if(is_file($templateFile)) {
	// a run that was stopped before it could clean up leaves the file of the tests behind: that one is taken away
	if(strpos((string) file_get_contents($templateFile), 'shvtest-marker') !== false) {
		@unlink($templateFile);
	} else {
		fwrite(STDERR, "The file $templateFile exists already and is not marked as the tests', so they do not touch it.\nIf an earlier run was stopped, run: php tests/browser/setup.php down   (with SHV_TEST_WRITE=1) and try again.\n");
		exit(4);
	}
}
@mkdir($keep);
$sitemap = $wire->modules->get('FieldtypeSelfHostedVideo')->sitemap()->filename();
if(is_file($sitemap)) file_put_contents($sitemapBackup, file_get_contents($sitemap));

TestSite::begin(); // (and no end: the pages stay for the browser until `down`)
$removeUser();

// a page of the website with the head, so the files of the player and of the field are in the page as on a website
file_put_contents($templateFile, '<?php namespace ProcessWire; /* shvtest-marker: made by the tests of FieldtypeSelfHostedVideo, removed by them */ ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $page->title ?></title></head><body><main><?= $page->shvtest_body ?><?= $page->shvtest_videos->render() ?></main></body></html>');
$template = $wire->templates->get(TestSite::prefix);
if($template) {
	$property = new \ReflectionProperty($template, 'filenameExists');
	$property->setAccessible(true);
	$property->setValue($template, null);
}

// texts are saved in the default language, which is what the page shows
$languages = $wire->languages;
if($languages) $wire->user->language = $languages->getDefault();

$video = __DIR__ . '/samples/play.webm';
$play = TestSite::page('browser-play', function($page) use($video) {
	TestSite::videos($page)->add($video);
}, 'Browser test: playing');
$clip = TestSite::videos($play)->first();
$clip->title = 'A short test film';
$vtt = sys_get_temp_dir() . '/shvtest-play-' . getmypid() . '.vtt';
copy(__DIR__ . '/samples/play.vtt', $vtt);
$clip->setCaptions('en', $vtt);
@unlink($vtt);
$clip->setChapters(array(array('time' => 0, 'title' => 'Beginning'), array('time' => 3, 'title' => 'Second half')));
$clip->setOverlays(array(
	array('text' => 'Always shown', 'x' => 5, 'y' => 5),
	array('text' => 'Only in the middle', 'x' => 50, 'y' => 40, 'when' => 'range', 'start' => 2, 'end' => 4),
	array('text' => 'Thanks for watching', 'x' => 50, 'y' => 60, 'when' => 'end'),
));
$play->save();
$play = TestSite::fresh($play);

$upload = TestSite::page('browser-upload', null, 'Browser test: upload');

// a page whose text is edited with CKEditor (the field of the test pages uses it when the website has it)
$ckeditor = $wire->modules->isInstalled('InputfieldCKEditor');
if($ckeditor) {
	$body = $wire->fields->get(TestSite::prefix . '_body');
	$body->inputfieldClass = 'InputfieldCKEditor';
	$wire->fields->save($body);
}
$editor = TestSite::page('browser-editor', function($page) use($video) {
	TestSite::videos($page)->add($video);
	$page->shvtest_body = '<p>Intro</p>';
}, 'Browser test: editor');
$editVideo = TestSite::videos($editor)->first();
$editVideo->title = 'Dialog test film';
$editor->save();

$password = bin2hex(random_bytes(10)) . 'Aa1!';
$user = $wire->users->add($userName);
$user->pass = $password;
$user->addRole('superuser');
$user->save();

$admin = $wire->pages->get($wire->config->adminRootPageID);
echo json_encode(array(
	'play' => array('id' => $play->id, 'path' => $pathOf($play), 'file' => basename($clip->filename())),
	'upload' => array('id' => $upload->id, 'path' => $pathOf($upload), 'folder' => $upload->filesManager()->path()),
	'editor' => array('id' => $editor->id, 'path' => $pathOf($editor), 'ckeditor' => $ckeditor, 'file' => basename($editVideo->filename())),
	'admin' => '/' . $admin->name . '/',
	'user' => $userName,
	'password' => $password,
));
