<?php namespace ProcessWire;

require_once(__DIR__ . '/SelfHostedVideoProbe.php');
require_once(__DIR__ . '/SelfHostedVideoFaststart.php');
// the old FieldtypeVideoFile has classes of the same names: while its folder is still there, it is not
// installed alongside (see ___install()), and loading this file must not stop the site
if(!class_exists(__NAMESPACE__ . '\\Pagevideo', false)) require_once(__DIR__ . '/Pagevideo.php');
if(!class_exists(__NAMESPACE__ . '\\Pagevideos', false)) require_once(__DIR__ . '/Pagevideos.php');
require_once(__DIR__ . '/SelfHostedVideoMarkup.php');
require_once(__DIR__ . '/SelfHostedVideoFrameworks.php');
require_once(__DIR__ . '/SelfHostedVideoCaptions.php');
require_once(__DIR__ . '/SelfHostedVideoChecks.php');
require_once(__DIR__ . '/SelfHostedVideoTimeline.php');
require_once(__DIR__ . '/SelfHostedVideoDelivery.php');
require_once(__DIR__ . '/SelfHostedVideoEmbed.php');
require_once(__DIR__ . '/SelfHostedVideoFinder.php');
require_once(__DIR__ . '/SelfHostedVideoSitemap.php');
require_once(__DIR__ . '/SelfHostedVideoUsage.php');
require_once(__DIR__ . '/SelfHostedVideoStats.php');
require_once(__DIR__ . '/SelfHostedVideoDuplicates.php');

/**
 * ProcessWire Video File Fieldtype
 *
 * Field that stores one or more self-hosted video files. Built on the core file field,
 * so uploads, descriptions, tags, sorting and multi-language support behave as editors
 * already know them. On top of that, each video has a title, duration and dimensions
 * that can be used in selectors, and a video can be reused on other pages without
 * copying its file.
 *
 * ~~~~~
 * // Output
 * echo $page->videos->render();
 * echo $page->hero_video->render([ 'background' => true ]);
 * foreach($page->videos as $video) {
 *   echo "<h3>$video->title</h3>" . $video->render() . "<p>$video->durationStr</p>";
 * }
 *
 * // Find pages by video properties
 * $pages->find('videos.duration>60');
 * $pages->find('videos.tags=tutorial');
 * $pages->find('videos.width>=1920, videos.count>1');
 *
 * // All videos with a tag, wherever they are
 * $videos = $modules->get('FieldtypeSelfHostedVideo')->findVideos('tutorial');
 * ~~~~~
 *
 * @method WireArray findVideos($tag = '', array $options = array())
 *
 * @property int $faststart Prepare MP4 and MOV files for streaming when they are uploaded or replaced? (module setting, default=1)
 * @property int $autoAssets Add the stylesheet and script for the website automatically where a video is rendered? (module setting, default=1)
 * @property string $framework Name of the CSS framework whose class names the videos on the website get (module setting, default='')
 * @property int $editorTab Where video fields are in the page editor: 0 among the other fields, 1 each in a tab of its own, 2 all in one tab "Videos" (module setting, default=0)
 * @method array|null getFramework($name) Get the profile of a CSS framework. Hook after it to change a profile or to provide one.
 *
 */
class FieldtypeSelfHostedVideo extends FieldtypeFile implements FieldtypeHasFiles, FieldtypeHasPagefiles, ConfigurableModule {

	/**
	 * Module info
	 *
	 * @return array
	 *
	 */
	public static function getModuleInfo() {
		return array(
			'title' => __('Video Files', __FILE__),
			'version' => '1.0.0',
			'summary' => __('Field that stores one or more self-hosted video files, with title, duration, dimensions, tags and reuse across pages.', __FILE__),
			'icon' => 'film',
			'autoload' => true, // needed so that deleting pages can be checked for videos used elsewhere
			'requires' => 'ProcessWire>=3.0.227, PHP>=8.1.0',
			'installs' => array('InputfieldSelfHostedVideo', 'ProcessSelfHostedVideos', 'TextformatterSelfHostedVideo'),
		);
	}

	/**
	 * File schema is configured to store video properties and references (flag)
	 *
	 * Columns: 'duration', 'width', 'height', 'ratio', 'ref_pages_id', 'ref_name'
	 *
	 */
	const fileSchemaVideo = 512;

	/**
	 * Counter for unique table aliases in match queries
	 *
	 * @var int
	 *
	 */
	static protected $aliasCount = 0;

	/**
	 * Original videos as stored in the database, for use by references: [ pageId => [ name => Pagevideo ] ]
	 *
	 * @var array
	 *
	 */
	protected $originals = array();

	/**
	 * Builds the markup for the website
	 *
	 * @var SelfHostedVideoMarkup|null
	 *
	 */
	protected $markup = null;

	/**
	 * Finds the profiles of CSS frameworks
	 *
	 * @var SelfHostedVideoFrameworks|null
	 *
	 */
	protected $frameworks = null;

	/**
	 * Name (and id) of the tab that holds all video fields of a page, with the setting editorTab = 2
	 *
	 */
	const editorTabName = 'shv_tab';

	/**
	 * Free tools for making a video smaller, offered in the checklist: one per line, "Name | address | kind"
	 *
	 * Kind: "browser" (works in the browser, the video is not uploaded), "upload" (the video is uploaded
	 * to the service) or "program" (a program for one's own computer).
	 *
	 */
	const defaultOptimizeTools = "VdoCipher | https://www.vdocipher.com/tools/video-compressor/ | browser\nFreeConvert | https://www.freeconvert.com/video-compressor | upload\nAdobe Express | https://www.adobe.com/express/feature/video/compress | upload\nHandBrake | https://handbrake.fr/ | program";

	/**
	 * Runs the checks of a video
	 *
	 * @var SelfHostedVideoChecks|null
	 *
	 */
	protected $checker = null;

	/**
	 * Object that sends the files of protected videos
	 *
	 * @var SelfHostedVideoDelivery|null
	 *
	 */
	protected $delivery = null;

	/**
	 * Object that keeps other websites from embedding videos, where that is blocked
	 *
	 * @var SelfHostedVideoEmbed|null
	 *
	 */
	protected $embedRules = null;

	/**
	 * True while the Video Manager is being added or removed from the module settings
	 *
	 * @var bool
	 *
	 */
	protected $switchingManager = false;

	/**
	 * Object that finds videos across the site
	 *
	 * @var SelfHostedVideoFinder|null
	 *
	 */
	protected $finder = null;

	/**
	 * Files of pages the visitor may not view that may be loaded all the same,
	 * because the video is shown on a page the visitor may view: [ page ID => file name ]
	 *
	 * @var array
	 *
	 */
	protected $fileGrants = array();

	/**
	 * @var SelfHostedVideoSitemap|null
	 *
	 */
	protected $sitemap = null;

	/**
	 * @var SelfHostedVideoUsage|null
	 *
	 */
	protected $usage = null;

	/**
	 * @var SelfHostedVideoDuplicates|null
	 *
	 */
	protected $duplicates = null;

	/**
	 * @var SelfHostedVideoStats|null
	 *
	 */
	protected $stats = null;

	/**
	 * Version of the list of where videos are placed; a lower stored one makes it again (see init())
	 *
	 */
	const usageVersion = 3; // 2: with the table of the counts (0.52.0); 3: with the tags that name no video (0.54.0)

	/**
	 * Construct: default module settings
	 *
	 */
	public function __construct() {
		parent::__construct();
		$this->set('autoAssets', 1);
		$this->set('faststart', 1);
		$this->set('framework', '');
		$this->set('editorTab', 0);
		$this->set('embed', 1);
		$this->set('embedHosts', '');
		$this->set('managerPerPage', 10);
		$this->set('editorPerPage', 10);
		$this->set('optimizeTools', self::defaultOptimizeTools);
		$this->set('noVideoSitemap', 0);
		$this->set('noStats', 0);
	}

	/**
	 * What a site with the module under its old name FieldtypeVideoFile had, found when this one was installed
	 *
	 * @var array|null
	 *
	 */
	protected $migration = null;

	/**
	 * Uninstall: possible once no field uses the module; removes the video sitemap file
	 *
	 */
	public function ___uninstall() {
		$result = parent::___uninstall();
		// the video sitemap goes with the module (a file of the same name it did not write stays)
		$this->sitemap()->remove();
		$this->usage()->uninstall();
		$this->stats()->uninstall();
		return $result;
	}

	/**
	 * Install: take over the videos, fields and settings of the module under its old name FieldtypeVideoFile
	 *
	 * The old folder has to be gone, or both would be loaded. The old module must not be uninstalled
	 * before: that is only possible after deleting its fields, and with them the videos.
	 *
	 * @throws WireException While the folder of the old module is still there
	 *
	 */
	public function ___install() {
		require_once(__DIR__ . '/SelfHostedVideoMigration.php');
		$migration = $this->wire(new SelfHostedVideoMigration($this));
		if($migration->oldFolderExists()) {
			throw new WireException(sprintf($this->_('Delete the folder %s first (do not uninstall the module there), then install this one again. It takes over the video fields, their videos and the settings.'), $migration->oldFolderPath()));
		}
		if(!$migration->needed()) return;
		// before the parts of the module are installed: the old Video Manager would keep the new one from its place under Setup
		$this->migration = $migration->prepare();
		// kept in the settings too, should the request end before the parts are installed (see init())
		$modules = $this->wire()->modules;
		$modules->saveConfig($this, array_merge($modules->getConfig($this), array('migrationState' => json_encode($this->migration))));
		$this->addHookAfter('Modules::install', $this, 'hookMigrationInstalled');
	}

	/**
	 * Finish the move from FieldtypeVideoFile, once the parts of the module are installed too
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookMigrationInstalled(HookEvent $event) {
		if($this->migration === null) return;
		$modules = $this->wire()->modules;
		$info = $modules->getModuleInfo($this);
		foreach($info['installs'] as $name) {
			if(!$modules->isInstalled($name)) return;
		}
		$this->finishMigration();
	}

	/**
	 * Second step of the move from FieldtypeVideoFile
	 *
	 */
	protected function finishMigration() {
		$state = $this->migration;
		if(!is_array($state)) {
			$state = json_decode((string) $this->get('migrationState'), true);
		}
		$this->migration = null;
		$modules = $this->wire()->modules;
		$config = $modules->getConfig($this);
		unset($config['migrationState']);
		$modules->saveConfig($this, $config);
		$this->set('migrationState', '');
		if(!is_array($state)) return;
		require_once(__DIR__ . '/SelfHostedVideoMigration.php');
		$migration = $this->wire(new SelfHostedVideoMigration($this));
		$this->switchingManager = true;
		try {
			$migration->finish($state);
		} catch(\Exception $e) {
			$this->error($e->getMessage());
		}
		$this->switchingManager = false;
	}

	/**
	 * Remove pages of the Video Manager under Setup that no installed module answers any more (for the move from the old name)
	 *
	 * #pw-internal
	 *
	 * @return int
	 *
	 */
	public function removeOrphanedManagerPagesNow() {
		return $this->removeOrphanedManagerPages();
	}

	/**
	 * Upgrade from one version to another: install parts that were added since
	 *
	 * @param int|string $fromVersion
	 * @param int|string $toVersion
	 *
	 */
	public function ___upgrade($fromVersion, $toVersion) {
		$modules = $this->wire()->modules;
		if(!$modules->isInstalled('InputfieldSelfHostedVideo') && $modules->isInstallable('InputfieldSelfHostedVideo')) {
			$modules->install('InputfieldSelfHostedVideo');
			$this->message($this->_('Installed the video input for the page editor.'));
		}
		// the Video Manager is optional (module settings): added here only for sites from before it existed
		$from = $modules->formatVersion($fromVersion);
		if(!$modules->isInstalled('ProcessSelfHostedVideos') && $modules->isInstallable('ProcessSelfHostedVideos') && version_compare($from, '0.9.0', '<')) {
			$this->removeOrphanedManagerPages();
			$modules->install('ProcessSelfHostedVideos');
			$this->message($this->_('Installed the overview of all videos: Setup > Video Manager.'));
		} else if($modules->isInstalled('ProcessSelfHostedVideos')) {
			// loading it lets it take its own upgrade steps now, not when its page is opened
			$modules->getModule('ProcessSelfHostedVideos', array('noPermissionCheck' => true));
		}
		if(!$modules->isInstalled('TextformatterSelfHostedVideo') && $modules->isInstallable('TextformatterSelfHostedVideo')) {
			$modules->install('TextformatterSelfHostedVideo');
			$this->message($this->_('Installed the text formatter “Videos in text”. Add it to a text field to place videos in its text.'));
		}
		// before 0.34.0 the default was "mp4 webm"; the move from FieldtypeVideoFile (0.40.0 and 0.41.0) left such fields as they were
		if(version_compare($from, '0.34.0', '<') || (version_compare($from, '0.40.0', '>=') && version_compare($from, '0.41.1', '<'))) {
			$this->upgradeExtensions();
		}
	}

	/**
	 * Video fields that still allow the old default "mp4 webm" also allow MOV (what iPhones record)
	 *
	 * Fields with file types of their own choosing are left alone.
	 *
	 * @return int Number of fields changed
	 *
	 */
	public function upgradeExtensions() {
		$n = 0;
		foreach($this->wire()->fields->find('type=' . $this->className()) as $field) {
			$extensions = preg_split('/[\s,]+/', strtolower(trim((string) $field->get('extensions'))), -1, PREG_SPLIT_NO_EMPTY);
			sort($extensions);
			if($extensions !== array('mp4', 'webm')) continue;
			$field->set('extensions', 'mp4 webm mov');
			$this->wire()->fields->save($field);
			$this->message(sprintf($this->_('The video field “%s” now also accepts MOV files, as iPhones record them.'), $field->name));
			$n++;
		}
		return $n;
	}

	/**
	 * Initialize: add hooks
	 *
	 */
	public function init() {
		parent::init();
		// the move from FieldtypeVideoFile was interrupted after its first step
		if($this->migration === null && strlen((string) $this->get('migrationState'))) $this->finishMigration();
		$this->addHookBefore('Pages::trash', $this, 'hookPagesDelete');
		$this->addHookBefore('Pages::delete', $this, 'hookPagesDelete');
		// after the hook of the repeater field, which points the request to the repeater item it is for
		$this->addHookBefore('ProcessPageEdit::ajaxSave', $this, 'hookAjaxSave', array('priority' => 200));
		// protected videos: who may load their files (after the core has decided), and how they are sent
		$this->addHookAfter('Page::viewable', $this, 'hookPageViewable', array('priority' => 200));
		$this->addHookBefore('ProcessPageView::sendFile', $this, 'hookSendFile');
		// embedding on other websites: rules for the web server in the folders of pages with blocked videos
		$this->addHookAfter('Pages::saved', $this, 'hookPageSaved');
		$this->addHookAfter('Pages::savedField', $this, 'hookPageSaved');
		$this->addHookAfter('Modules::saveConfig', $this, 'hookSaveConfig');
		// say in the page editor why a page cannot be put in the trash, before anybody tries
		$this->addHookAfter('ProcessPageEdit::buildFormDelete', $this, 'hookBuildFormDelete');
		// the module setting that gives video fields a tab of their own in the page editor
		$this->addHookAfter('ProcessPageEdit::buildFormContent', $this, 'hookBuildFormContent');
		// videos in text: a button for the rich text editor, and the list of videos it offers
		$this->addHookAfter('Field::getInputfield', $this, 'hookGetInputfield');
		$this->addHookBefore('ProcessPageEdit::execute', $this, 'hookPageEditExecute');
		// a finished page with videos always has their stylesheet and script
		$this->addHookAfter('Page::render', $this, 'hookPageRender', array('priority' => 200));
		// the same button for CKEditor, added to its settings once it has worked them out
		$this->addHookAfter('InputfieldCKEditor::renderReadyHook', $this, 'hookCKEditorReady');
		// the video sitemap for search engines: the file sitemap-videos.xml in the root folder of the website
		if($this->useSitemap()) {
			// answered by the module where the file cannot be written
			$this->addHook('/' . SelfHostedVideoSitemap::path, $this, 'hookSitemap');
			// a file that is missing (a new installation, an upgrade, deleted by hand) is written at the end of this request
			if(!is_file($this->sitemap()->filename()) && !$this->wire()->cache->get(self::sitemapFailedCache)) $this->updateSitemap();
		}
		// saved pages are handled in hookPageSaved(); a deleted one may have had videos too
		$this->addHookAfter('Pages::deleted', $this, 'hookSitemapPageDeleted');
		// where each video is placed: kept up to date as pages are saved and deleted
		$this->addHookAfter('Pages::saved', $this, 'hookUsageSaved');
		$this->addHookAfter('Pages::savedField', $this, 'hookUsageSaved');
		$this->addHookAfter('Pages::deleted', $this, 'hookUsageDeleted');
		// made for all pages once: after installing, upgrading, or moving from the old module
		if((int) $this->get('usageVersion') < self::usageVersion && !$this->get('migrationState')) $this->scheduleUsageRebuild();
		// how often videos are watched: what the script of the website reports
		if($this->useStats()) $this->addHook('/' . SelfHostedVideoStats::path, $this, 'hookStats');
		// the notice that the video sitemap was written, in the admin request after it was
		$this->addHookBefore('ProcessController::execute', $this, 'hookSitemapNotice');
	}

	/**
	 * Are plays counted (module setting "Do not count plays" not checked)?
	 *
	 * @return bool
	 *
	 */
	public function useStats() {
		return !(int) $this->get('noStats');
	}

	/**
	 * Get the counts of how often videos are watched
	 *
	 * @return SelfHostedVideoStats
	 *
	 */
	public function stats() {
		if(!$this->stats) $this->stats = $this->wire(new SelfHostedVideoStats($this));
		return $this->stats;
	}

	/**
	 * Count what the script of the website reports (a POST to /selfhostedvideo-stats)
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookStats(HookEvent $event) {
		$input = $this->wire()->input;
		if($input->requestMethod() !== 'POST') return; // not found
		try {
			$this->stats()->record((string) $input->post('v'), (string) $input->post('e'));
		} catch(\Exception $e) {
			$this->wire()->log->save('selfhostedvideo', 'Statistics: ' . $e->getMessage());
		}
		if(!headers_sent()) {
			http_response_code(204);
			header('Cache-Control: no-store');
			header('X-Robots-Tag: noindex');
		}
		$event->return = '';
	}

	/**
	 * Get the videos that were uploaded more than once
	 *
	 * @return SelfHostedVideoDuplicates
	 *
	 */
	public function duplicates() {
		if(!$this->duplicates) $this->duplicates = $this->wire(new SelfHostedVideoDuplicates($this));
		return $this->duplicates;
	}

	/**
	 * Get the list of where videos are placed
	 *
	 * @return SelfHostedVideoUsage
	 *
	 */
	public function usage() {
		if(!$this->usage) $this->usage = $this->wire(new SelfHostedVideoUsage($this));
		return $this->usage;
	}

	/**
	 * Make the list of where videos are placed for all pages, at the end of this request
	 *
	 */
	protected function scheduleUsageRebuild() {
		$run = function() {
			try {
				$this->usage()->install();
				$this->stats()->install();
				$n = $this->usage()->rebuild();
				$modules = $this->wire()->modules;
				$modules->saveConfig($this, array_merge($modules->getConfig($this), array('usageVersion' => self::usageVersion)));
				$this->set('usageVersion', self::usageVersion);
				$this->wire()->log->save('selfhostedvideo', "Where videos are placed: listed for $n pages");
			} catch(\Throwable $e) {
				$this->wire()->log->save('selfhostedvideo', 'Where videos are placed: ' . $e->getMessage());
			}
		};
		if($this->wire()->config->cli) {
			register_shutdown_function($run);
		} else {
			$this->addHookAfter('ProcessWire::finished', function(HookEvent $event) use($run) { $run(); });
		}
	}

	/**
	 * Keep the list of where videos are placed up to date when a page is saved
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookUsageSaved(HookEvent $event) {
		$page = $event->arguments(0);
		if(!$page instanceof Page || !$page->id) return;
		$usage = $this->usage();
		if(!$usage->isRelevant($page->template)) return;
		try {
			// a copy as stored: the page being saved may hold values that were not saved
			$pages = $this->wire()->pages;
			$fresh = method_exists($pages, 'getFresh') ? $pages->getFresh($page->id) : $page;
			if($fresh && $fresh->id) {
				// tags in texts that show nothing now, because the video they place was deleted or removed
				$message = $usage->brokenMessage($usage->updatePage($fresh));
				if(strlen($message)) $this->warning($message);
			}
		} catch(\Exception $e) {
			$this->wire()->log->save('selfhostedvideo', 'Where videos are placed, page ' . $page->id . ': ' . $e->getMessage());
		}
	}

	/**
	 * Forget a deleted page in the list of where videos are placed
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookUsageDeleted(HookEvent $event) {
		$page = $event->arguments(0);
		if(!$page instanceof Page || !$page->id) return;
		$this->usage()->removePage($page);
		// its videos are gone, and with them what was counted for them
		$this->stats()->remove($page->id);
	}

	/**
	 * Say in the admin that the video sitemap was written again (in a request before this one)
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookSitemapNotice(HookEvent $event) {
		$session = $this->wire()->session;
		$user = $this->wire()->user;
		if(!$session || !$user || !$user->isLoggedin()) return;
		$info = $session->getFor($this, 'sitemapWritten');
		if(!is_array($info)) return;
		$session->removeFor($this, 'sitemapWritten');
		if($this->wire()->config->ajax) return;
		$videos = (int) $info['videos'];
		$pages = (int) $info['pages'];
		$this->message(
			sprintf($this->_('The video sitemap %s was written again:'), SelfHostedVideoSitemap::path) . ' ' .
			sprintf($this->_n('%d video', '%d videos', $videos), $videos) . ', ' .
			sprintf($this->_n('%d page', '%d pages', $pages), $pages) . '.'
		);
	}

	/**
	 * Name of the cache entry that remembers for an hour that the sitemap file could not be written
	 *
	 */
	const sitemapFailedCache = 'shv-sitemap-failed';

	/**
	 * Is the sitemap file to be written again at the end of this request?
	 *
	 * @var bool
	 *
	 */
	protected $sitemapPending = false;

	/**
	 * Is the video sitemap wanted (module setting "No video sitemap" not checked)?
	 *
	 * @return bool
	 *
	 */
	public function useSitemap() {
		return !(int) $this->get('noVideoSitemap');
	}

	/**
	 * Write the sitemap file again at the end of this request, once however many pages change
	 *
	 */
	public function updateSitemap() {
		if($this->sitemapPending) return;
		$this->sitemapPending = true;
		$this->sitemap()->forget();
		if($this->wire()->config->cli) {
			// scripts from the command line do not finish the way requests do
			register_shutdown_function(array($this, 'writeSitemap'));
		} else {
			$this->addHookAfter('ProcessWire::finished', $this, 'writeSitemap');
		}
	}

	/**
	 * Write the sitemap file now (called at the end of the request, see updateSitemap())
	 *
	 * #pw-internal
	 *
	 * @return bool
	 *
	 */
	public function writeSitemap() {
		if(!$this->sitemapPending) return false;
		$this->sitemapPending = false;
		if(!$this->useSitemap()) return false;
		try {
			$ok = $this->sitemap()->write();
		} catch(\Throwable $e) {
			$this->wire()->log->save('selfhostedvideo', 'Video sitemap: ' . $e->getMessage());
			$ok = false;
		}
		$cache = $this->wire()->cache;
		if($ok) {
			$cache->delete(self::sitemapFailedCache);
			// said in the next admin request of the one who changed something (see hookSitemapNotice())
			$user = $this->wire()->user;
			$session = $this->wire()->session;
			if($this->sitemap()->changed() && $user && $user->isLoggedin() && $session && !$this->wire()->config->cli) {
				$session->setFor($this, 'sitemapWritten', $this->sitemap()->stats());
			}
		} else {
			// not tried again with every request
			$cache->save(self::sitemapFailedCache, '1', 3600);
		}
		return $ok;
	}

	/**
	 * Answer the address of the video sitemap
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookSitemap(HookEvent $event) {
		if(!headers_sent()) {
			header('Content-Type: application/xml; charset=utf-8');
			header('X-Robots-Tag: noindex');
		}
		$event->return = $this->sitemap()->get();
	}

	/**
	 * Write the video sitemap again after a page with videos was deleted
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookSitemapPageDeleted(HookEvent $event) {
		if(!$this->useSitemap()) return;
		$page = $event->arguments(0);
		if(!$page instanceof Page || !$page->template) return;
		foreach($page->template->fieldgroup as $field) {
			if($field->type instanceof FieldtypeSelfHostedVideo) {
				$this->updateSitemap();
				return;
			}
		}
	}

	/**
	 * Get the video sitemap
	 *
	 * ~~~~~
	 * $xml = $modules->get('FieldtypeSelfHostedVideo')->sitemap()->render();
	 * ~~~~~
	 *
	 * @return SelfHostedVideoSitemap
	 *
	 */
	public function sitemap() {
		if(!$this->sitemap) $this->sitemap = $this->wire(new SelfHostedVideoSitemap($this));
		return $this->sitemap;
	}

	/**
	 * Add the stylesheet and the script to a rendered page that has videos but not these two
	 *
	 * See SelfHostedVideoMarkup::completeAssets() for how that comes about.
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookPageRender(HookEvent $event) {
		$out = $event->return;
		if(!is_string($out) || strpos($out, 'data-shv=') === false) return;
		$page = $event->object;
		if($page instanceof Page && $page->template && $page->template->name === 'admin') return;
		$event->return = $this->markup()->completeAssets($out);
	}

	/**
	 * Does a field show videos where its text has a video tag?
	 *
	 * @param Field|null $field
	 * @return bool
	 *
	 */
	protected function hasTextFormatter($field) {
		if(!$field instanceof Field) return false;
		$formatters = $field->get('textformatters');
		return is_array($formatters) && in_array('TextformatterSelfHostedVideo', $formatters, true);
	}

	/**
	 * Make the texts and the address the editor buttons need available to their scripts
	 *
	 */
	protected function addRichTextConfig() {
		$config = $this->wire()->config;
		if($config->js('FieldtypeSelfHostedVideo')) return;
		$config->js('FieldtypeSelfHostedVideo', array(
			'editUrl' => $config->urls->admin . 'page/edit/',
			'labels' => array(
				'button' => $this->_('Insert video'),
				'title' => $this->_('Insert a video of this page'),
				'video' => $this->_('Video'),
				'caption' => $this->_('Text below the video'),
				'captionNone' => $this->_('None'),
				'captionTitle' => $this->_('Title'),
				'captionBoth' => $this->_('Title, duration and description'),
				'layout' => $this->_('Place'),
				'layoutFull' => $this->_('Full width'),
				'layoutLeft' => $this->_('Left of the text, half width'),
				'layoutRight' => $this->_('Right of the text, half width'),
				'layoutCenter' => $this->_('Centred, half width'),
				'insert' => $this->_('Insert'),
				'cancel' => $this->_('Cancel'),
				'none' => $this->_('This page has no videos yet. Upload a video to a video field of this page or reuse one there, then it can be placed in the text.'),
				'error' => $this->_('The videos of this page could not be loaded.'),
				'hint' => $this->_('The text shows a tag such as [[video intro.mp4]]. On the website the video appears in its place.'),
			),
		));
	}

	/**
	 * Add the button that inserts a video tag to CKEditor, for fields that have the
	 * text formatter "Videos in text"
	 *
	 * CKEditor has put its settings for the field into the page by now. The plugin and the
	 * button are added to them there, which also covers fields that take their settings
	 * from another field.
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookCKEditorReady(HookEvent $event) {

		/** @var Inputfield $inputfield */
		$inputfield = $event->object;
		if(!$this->hasTextFormatter($inputfield->hasField)) return;

		$config = $this->wire()->config;

		// the name CKEditor files the settings of a field under
		$name = 'InputfieldCKEditor_' . $inputfield->attr('name');
		if(strpos($name, '_repeater')) $name = preg_replace('/_repeater\d+/', '', $name);

		$settings = $config->js($name);
		if(!is_array($settings) || !isset($settings['toolbar'])) return;

		$plugins = array_filter(explode(',', isset($settings['extraPlugins']) ? (string) $settings['extraPlugins'] : ''), 'strlen');
		if(!in_array('pwselfhostedvideo', $plugins, true)) $plugins[] = 'pwselfhostedvideo';
		$settings['extraPlugins'] = implode(',', $plugins);

		$hasButton = false;
		foreach((array) $settings['toolbar'] as $group) {
			if(is_array($group) && in_array('PWSelfHostedVideo', $group, true)) $hasButton = true;
		}
		if(!$hasButton && is_array($settings['toolbar'])) $settings['toolbar'][] = array('PWSelfHostedVideo');

		$config->js($name, $settings);

		// where the editor finds the plugin
		$global = $config->js('InputfieldCKEditor');
		if(is_array($global)) {
			if(!isset($global['plugins']) || !is_array($global['plugins'])) $global['plugins'] = array();
			$global['plugins']['pwselfhostedvideo'] = $config->urls->get($this->className()) . 'assets/pwselfhostedvideo-ckeditor.js';
			$config->js('InputfieldCKEditor', $global);
		}

		$this->addRichTextConfig();
	}

	/**
	 * Add the button that inserts a video tag to the rich text editor of fields
	 * that have the text formatter "Videos in text"
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookGetInputfield(HookEvent $event) {

		$inputfield = $event->return;
		if(!$inputfield instanceof Inputfield || !wireInstanceOf($inputfield, 'InputfieldTinyMCE')) return;

		if(!$this->hasTextFormatter($event->object)) return;

		$config = $this->wire()->config;

		// the editor wants the address of a plugin from the root of the installation
		$url = $config->urls->get($this->className()) . 'assets/pwselfhostedvideo.js';
		$url = '/' . ltrim(substr($url, strlen($config->urls->root)), '/');

		$plugins = $inputfield->get('extPlugins');
		if(!is_array($plugins)) $plugins = array();
		if(!in_array($url, $plugins, true)) {
			$plugins[] = $url;
			$inputfield->set('extPlugins', $plugins);
		}

		$toolbar = (string) $inputfield->get('toolbar');
		if(strpos($toolbar, 'pwselfhostedvideo') === false) $inputfield->set('toolbar', trim("$toolbar pwselfhostedvideo"));

		$this->addRichTextConfig();
	}

	/**
	 * Answer the rich text editor's question for the videos of the page being edited
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookPageEditExecute(HookEvent $event) {

		$input = $this->wire()->input;
		if($input->get('shv') !== 'list' || !$this->wire()->config->ajax || !$input->requestMethod('GET')) return;

		/** @var ProcessPageEdit $process */
		$process = $event->object;
		$page = $process->getPage(); // the page editor has made sure that the user may edit it
		if(!$page || !$page->id) return;

		$event->replace = true;
		header('Content-Type: application/json; charset=utf-8');
		$event->return = json_encode(array('videos' => $this->getTextVideos($page, (int) $input->get('item'))));
	}

	/**
	 * Get the videos a text of a page can show, as the rich text editor lists them
	 *
	 * #pw-internal
	 *
	 * @param Page $page The page being edited
	 * @param int $itemId ID of the repeater item the text is in, if it is in one
	 * @return array of [ name, title, facts ]
	 *
	 */
	public function getTextVideos(Page $page, $itemId = 0) {

		$sources = array();

		if($itemId > 0) {
			// an item counts only if it belongs to the page being edited
			$item = $this->wire()->pages->get((int) $itemId);
			$owner = $item;
			$guard = 0;
			while($owner->id && method_exists($owner, 'getForPage') && $guard++ < 10) $owner = $owner->getForPage();
			if($item->id && $item->id !== $page->id && $owner->id === $page->id) $sources[] = $item;
		}

		$sources[] = $page;
		$list = array();

		foreach($sources as $source) {
			$source->of(false);
			foreach($this->getPageVideos($source) as $video) {
				$name = $video->isReference() ? (string) $video->get('ref_name') : $video->basename();
				$key = strtolower($name);
				if(isset($list[$key])) continue;
				$title = $video->title();
				$facts = array($name);
				$duration = $video->durationStr();
				if(strlen($duration)) $facts[] = $duration;
				$list[$key] = array(
					'name' => $name,
					'title' => strlen($title) ? $title : $name,
					'facts' => implode(' · ', $facts),
				);
			}
		}

		return array_values($list);
	}

	/**
	 * Get all videos of a page, from all its video fields
	 *
	 * Formatted values when output formatting of the page is on. Reused videos whose original is gone are left out.
	 *
	 * @param Page $page
	 * @return array of Pagevideo
	 *
	 */
	public function getPageVideos(Page $page) {

		$list = array();
		if(!$page->id || !$page->template) return $list;

		foreach($page->template->fieldgroup as $field) {
			/** @var Field $field */
			if(!$field->type instanceof FieldtypeSelfHostedVideo) continue;
			$value = $page->get($field->name);
			if($value instanceof Pagevideo) {
				$items = array($value);
			} else if($value instanceof Pagevideos) {
				$items = $value;
			} else {
				// field formatted as a string, or empty
				$items = $page->getUnformatted($field->name);
				if(!$items instanceof Pagevideos) continue;
			}
			foreach($items as $video) {
				if($video instanceof Pagevideo && !$video->isBroken()) $list[] = $video;
			}
		}

		return $list;
	}

	/**
	 * Show the video fields of the page being edited in tabs, when the module setting asks for it
	 *
	 * ProcessWire can show any field as a tab (Visibility > "Tab" in the settings of a field).
	 * Setting 1 gives the video fields that presentation, so the core builds the tabs: one per
	 * field, labelled with the label of the field, after "Content". Setting 2 puts all of them
	 * into one tab "Videos", in the order of the template, as a tab of the template would
	 * (an opening and a closing marker around them, which the core turns into the tab).
	 * A field that is hidden or sits in a fieldset or in a tab is left where it is, and so is
	 * a field whose own setting "Never in a tab" is checked.
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookBuildFormContent(HookEvent $event) {

		$mode = (int) $this->get('editorTab');
		if($mode !== 1 && $mode !== 2) return;

		$wrapper = $event->return;
		if(!$wrapper instanceof InputfieldWrapper) return;

		// while the editor is shown, fieldsets are markers in a flat list: an opening one, what is in it, a closing one
		$flat = !$this->wire()->input->requestMethod('POST');
		// one tab for all: only a matter of showing. Saved, the fields are where they always are.
		if($mode === 2 && !$flat) return;

		$depth = 0;
		$tab = array(Inputfield::collapsedTab, Inputfield::collapsedTabAjax, Inputfield::collapsedTabLocked);
		$locked = array(Inputfield::collapsedNoLocked, Inputfield::collapsedBlankLocked, Inputfield::collapsedYesLocked);
		$found = array();

		foreach($wrapper as $inputfield) {
			/** @var Inputfield $inputfield */
			if($flat && $inputfield instanceof InputfieldFieldsetClose) {
				if($depth > 0) $depth--;
				continue;
			}
			if($flat && $inputfield instanceof InputfieldFieldsetOpen) {
				$depth++;
				continue;
			}
			if($depth > 0 || !$inputfield instanceof InputfieldSelfHostedVideo) continue;

			$collapsed = (int) $inputfield->getSetting('collapsed');
			if($collapsed === Inputfield::collapsedHidden || in_array($collapsed, $tab, true)) continue;
			// the field itself asks to stay among the other fields (Input > "Never in a tab")
			if((int) $inputfield->getSetting('noEditorTab')) continue;

			if($mode === 1) {
				$inputfield->collapsed = in_array($collapsed, $locked, true) ? Inputfield::collapsedTabLocked : Inputfield::collapsedTab;
			} else {
				$found[] = $inputfield;
			}
		}

		if(!count($found)) return;

		// the classes of tabs of a template
		$modules = $this->wire()->modules;
		$modules->get('FieldtypeFieldsetTabOpen');
		$modules->get('FieldtypeFieldsetClose');

		$open = $this->wire(new InputfieldFieldsetTabOpen());
		$open->attr('name', self::editorTabName);
		$open->attr('id', self::editorTabName);
		$open->label = $this->_('Videos');
		$wrapper->insertBefore($open, $found[0]);

		$last = $open;
		foreach($found as $inputfield) {
			if($inputfield !== $found[0]) {
				$wrapper->remove($inputfield);
				$wrapper->insertAfter($inputfield, $last);
			}
			$last = $inputfield;
		}

		$close = $this->wire(new InputfieldFieldsetClose());
		$close->attr('id+name', self::editorTabName . '_END');
		$wrapper->insertAfter($close, $last);
	}

	/**
	 * Replace the "Move to trash" form of the page editor with an explanation, for a page
	 * whose videos are reused on other pages
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookBuildFormDelete(HookEvent $event) {

		$wrapper = $event->return;
		/** @var ProcessPageEdit $process */
		$process = $event->object;
		$page = $process->getPage();

		if(!$wrapper instanceof InputfieldWrapper || !count($wrapper->children()) || !$page || !$page->id) return;

		$blocking = $this->getBlockingReferences($page);
		if(!count($blocking)) return;

		$sanitizer = $this->wire()->sanitizer;
		$pages = $this->wire()->pages;
		$finder = $this->finder();

		// video => pages that reuse it
		$uses = array();
		foreach($blocking as $rows) {
			foreach($rows as $row) $uses[$row['ref_pages_id'] . '/' . $row['ref_name']][$row['pages_id']] = $row;
		}

		$items = '';
		foreach($uses as $rows) {
			$first = reset($rows);
			$original = $this->getOriginal($first['ref_pages_id'], $first['ref_name']);
			$title = $original ? $original->title() : '';
			$name = strlen($title) ? "$title ({$first['ref_name']})" : $first['ref_name'];
			$links = array();
			foreach($rows as $row) {
				$p = $finder->editPage($pages->get((int) $row['pages_id']));
				if(!$p->id) continue;
				$label = $sanitizer->entities1((string) $p->get('title|name'));
				$links[$p->id] = $p->editable() ? "<a href='" . $sanitizer->entities($p->editUrl()) . "'>$label</a>" : $label;
			}
			$where = '';
			if((int) $first['ref_pages_id'] !== $page->id) {
				$child = $pages->get((int) $first['ref_pages_id']);
				if($child->id) $where = ' ' . sprintf($this->_('(on the page %s below this one)'), $sanitizer->entities($child->path()));
			}
			$items .= "<li><strong>" . $sanitizer->entities($name) . "</strong>$where: " . implode(', ', $links) . "</li>";
		}

		foreach($wrapper->children() as $child) $wrapper->remove($child);

		/** @var InputfieldMarkup $f */
		$f = $this->wire()->modules->get('InputfieldMarkup');
		$f->attr('id+name', 'shv_delete_blocked');
		$f->label = $this->_('This page cannot be moved to the trash yet');
		$f->icon = 'lock';
		$f->value =
			"<p>" . $sanitizer->entities($this->_('Videos of this page are reused on other pages, which would lose them. Remove these videos from the pages named here first:')) . "</p>" .
			"<ul class='InputfieldSelfHostedVideoBlocked'>$items</ul>";
		$wrapper->add($f);
	}

	/**
	 * Decide whether a file of a video may be loaded, after ProcessWire has decided for the page
	 *
	 * ProcessWire asks `$page->viewable('file.mp4')` and then `$page->viewable(false)` before it sends
	 * a protected file. Both answers are "no" for a visitor who may not view the page. Where the file
	 * belongs to a video that allowFile() lets the visitor have, both become "yes" for this file.
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookPageViewable(HookEvent $event) {

		$arg = $event->arguments(0);

		if($arg === false) {
			// the page itself, asked for right after one of its files
			if($event->return) return;
			/** @var Page $page */
			$page = $event->object;
			if(!isset($this->fileGrants[$page->id])) return;
			if($this->requestedFile() === $this->fileGrants[$page->id]) $event->return = true;
			return;
		}

		if(!is_string($arg) || !strpos($arg, '.') || strpos($arg, '/') !== false) return;

		/** @var Page $page */
		$page = $event->object;
		$video = $this->getVideoByFile($page, $arg);
		if(!$video) return;

		$allowed = $event->return !== false;

		// "no" for this file on a page that may be viewed comes from elsewhere, such as access settings of the field
		if(!$allowed && $page->viewable(false)) return;

		$result = (bool) $this->allowFile($video, $allowed);

		if($result && !$allowed) {
			$this->fileGrants[$page->id] = $arg;
			$event->return = true;
		} else if(!$result && $allowed) {
			$event->return = false;
		}
	}

	/**
	 * Send the files of protected videos in the way players need them
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookSendFile(HookEvent $event) {

		$page = $event->arguments(0);
		$basename = (string) $event->arguments(1);

		if(!$page instanceof Page || !strlen($basename) || strpos($basename, '/') !== false) return;
		if(!$page->hasFilesPath() || !$page->secureFiles()) return; // a file that is not protected: the core points to it

		$filename = $page->filesPath() . $basename;
		if(!is_file($filename)) return; // the core answers that it was not found
		$video = $this->getVideoByFile($page, $basename);
		if(!$video) return;

		$event->replace = true;

		$embed = $this->embedRules();
		if(!$embed->isBlocked($video)) {
			$this->delivery()->send($filename);
			return;
		}

		if(!$embed->requestAllowed()) {
			// a page of another website asks for it
			if(!headers_sent()) {
				http_response_code(403);
				header('Content-Type: text/plain; charset=utf-8');
				foreach($embed->headers() as $name => $value) header("$name: $value");
				header('Cache-Control: no-store');
			}
			echo 'Forbidden';
			return;
		}

		$this->delivery()->send($filename, $embed->headers());
	}

	/**
	 * Get the name of the file the current request asks for, if it asks for a file of a page
	 *
	 * @return string
	 *
	 */
	protected function requestedFile() {
		$pages = $this->wire()->pages;
		if(!method_exists($pages, 'request')) return '';
		$request = $pages->request();
		return $request ? (string) $request->getFile() : '';
	}

	/**
	 * Get the video on the given page that a file belongs to
	 *
	 * #pw-internal
	 *
	 * @param Page $page
	 * @param string $basename Name of a video file, of a thumbnail (in any size) or of a captions file
	 * @return Pagevideo|null
	 *
	 */
	public function getVideoByFile(Page $page, $basename) {

		if(!$page->id || !$page->template) return null;

		foreach($page->template->fieldgroup as $field) {
			/** @var Field $field */
			if(!$field->type instanceof FieldtypeSelfHostedVideo) continue;
			$videos = $page->getUnformatted($field->name);
			if(!$videos instanceof Pagefiles) continue;
			foreach($videos as $video) {
				/** @var Pagevideo $video */
				if($video instanceof Pagevideo && $video->ownsFile($basename) !== '') return $video;
			}
		}

		return null;
	}

	/**
	 * May the current user load the files of a protected video?
	 *
	 * Asked when the video file, its thumbnail or its captions are requested, and only for videos
	 * whose files are protected (see `$video->isProtected()`). Files that are not protected are
	 * sent by the web server, and nobody is asked.
	 *
	 * The answer of ProcessWire is given as `$allowed`: yes if the user may view the page holding
	 * the video. The module adds one rule: a video that is reused on another page may be loaded by
	 * everybody who may view that page. To add rules of your own, hook this method:
	 *
	 * ~~~~~
	 * // in /site/ready.php: videos tagged "premium" only for users with the role "subscriber"
	 * $wire->addHookAfter('FieldtypeSelfHostedVideo::allowFile', function(HookEvent $event) {
	 *   $video = $event->arguments(0);
	 *   if($video->hasTag('premium') && !$event->wire()->user->hasRole('subscriber')) $event->return = false;
	 * });
	 * ~~~~~
	 *
	 * @param Pagevideo $video The original video
	 * @param bool $allowed May the user view the page holding the video?
	 * @return bool
	 *
	 */
	public function ___allowFile(Pagevideo $video, $allowed) {

		if($allowed) return true;
		if(!$video->page || !$video->page->id) return false;

		$pages = $this->wire()->pages;

		foreach($this->findReferences($video->page, $video->basename()) as $row) {
			$page = $pages->get((int) $row['pages_id']);
			$guard = 0;
			// a repeater item is shown by the page it belongs to, when it is switched on
			while($page->id && method_exists($page, 'getForPage') && $guard++ < 10) {
				if($page->isUnpublished()) continue 2;
				$page = $page->getForPage();
			}
			if($page->id && $page->viewable(false)) return true;
		}

		return false;
	}

	/**
	 * Get the object that finds videos across the site, for the overview and the picker in the admin
	 *
	 * #pw-internal
	 *
	 * @return SelfHostedVideoFinder
	 *
	 */
	public function finder() {
		if(!$this->finder) $this->finder = $this->wire(new SelfHostedVideoFinder());
		return $this->finder;
	}

	/**
	 * Get the object that sends the files of protected videos
	 *
	 * #pw-internal
	 *
	 * @return SelfHostedVideoDelivery
	 *
	 */
	public function delivery() {
		if(!$this->delivery) $this->delivery = $this->wire(new SelfHostedVideoDelivery());
		return $this->delivery;
	}

	/**
	 * Get the object that keeps other websites from embedding videos, where that is blocked
	 *
	 * #pw-internal
	 *
	 * @return SelfHostedVideoEmbed
	 *
	 */
	public function embedRules() {
		if(!$this->embedRules) $this->embedRules = $this->wire(new SelfHostedVideoEmbed($this));
		return $this->embedRules;
	}

	/**
	 * Keep the rules for embedding in the folder of a page up to date when its videos are saved
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookPageSaved(HookEvent $event) {
		$page = $event->arguments(0);
		if(!$page instanceof Page || !$page->id) return;
		if($event->method === 'savedField') {
			$field = $event->arguments(1);
			if(!$field instanceof Field || !$field->type instanceof FieldtypeSelfHostedVideo) return;
		} else {
			// only pages that can have videos (not users and others saved along the way)
			$hasVideos = false;
			if($page->template) foreach($page->template->fieldgroup as $field) {
				if($field->type instanceof FieldtypeSelfHostedVideo) $hasVideos = true;
			}
			if(!$hasVideos) return;
		}
		// the video sitemap is written again at the end of the request
		if($this->useSitemap()) $this->updateSitemap();
		try {
			// a copy as stored: reading the videos of the page being saved would change what its saver holds
			$pages = $this->wire()->pages;
			$fresh = method_exists($pages, 'getFresh') ? $pages->getFresh($page->id) : null;
			if(!$fresh || !$fresh->id) return;
			$this->embedRules()->writeFor($fresh);
		} catch(\Exception $e) {
			$this->wire()->log->save('selfhostedvideo', 'Embedding rules for page ' . $page->id . ': ' . $e->getMessage());
		}
	}

	/**
	 * Remove pages of the Video Manager under Setup that no installed module answers any more
	 *
	 * Such a page would stay in the menu without content, and keep a new installation from making its own.
	 *
	 * @return int Number of pages removed
	 *
	 */
	protected function removeOrphanedManagerPages() {
		$pages = $this->wire()->pages;
		$database = $this->wire()->database;
		$setup = $pages->get((int) $this->wire()->config->adminRootPageID)->child('name=setup, include=all');
		if(!$setup->id) return 0;
		$n = 0;
		foreach($pages->find("parent=$setup, name^=videos, template=admin, include=all") as $page) {
			// "videos", or "videos-1" and so on where an earlier one was in the way
			if(!preg_match('/^videos(-\d+)?$/', $page->name)) continue;
			$query = $database->prepare('SELECT m.class FROM field_process fp LEFT JOIN modules m ON m.id=fp.data WHERE fp.pages_id=:id');
			$query->bindValue(':id', (int) $page->id, \PDO::PARAM_INT);
			$query->execute();
			$class = (string) $query->fetchColumn();
			$query->closeCursor();
			// a page of another module, or of the installed Video Manager, stays
			if(strlen($class) && ($class !== 'ProcessSelfHostedVideos' || $this->wire()->modules->isInstalled('ProcessSelfHostedVideos'))) continue;
			$page->addStatus(Page::statusSystemOverride);
			$page->removeStatus(Page::statusSystem);
			$page->removeStatus(Page::statusSystemID);
			$page->save();
			$pages->delete($page, true);
			$n++;
		}
		return $n;
	}

	/**
	 * Write the rules for embedding of all pages when the settings of the module change
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookSaveConfig(HookEvent $event) {
		$class = $event->arguments(0);
		if(is_object($class)) $class = $class->className();
		if($class !== $this->className() && $class !== __NAMESPACE__ . '\\' . $this->className()) return;
		$data = $event->arguments(1);
		if(!is_array($data)) return;

		// the Video Manager: added or removed as asked
		if(array_key_exists('videoManager', $data) && !$this->switchingManager) {
			$modules = $this->wire()->modules;
			$want = (bool) (int) $data['videoManager'];
			$has = $modules->isInstalled('ProcessSelfHostedVideos');
			$this->switchingManager = true;
			try {
				if($want && !$has && $modules->isInstallable('ProcessSelfHostedVideos')) {
					$this->removeOrphanedManagerPages();
					$modules->install('ProcessSelfHostedVideos');
					$this->message($this->_('The Video Manager was added under Setup.'));
				} else if(!$want && $has) {
					$modules->uninstall('ProcessSelfHostedVideos');
					$this->removeOrphanedManagerPages();
					$this->message($this->_('The Video Manager was removed from Setup.'));
				}
			} catch(\Exception $e) {
				$this->error($e->getMessage());
			}
			$this->switchingManager = false;
		}

		// the video sitemap: written, or removed as asked
		if(array_key_exists('noVideoSitemap', $data)) {
			$this->set('noVideoSitemap', (int) $data['noVideoSitemap']);
			$this->wire()->cache->delete(self::sitemapFailedCache);
			if($this->useSitemap()) {
				$this->updateSitemap();
			} else if($this->sitemap()->isOwnFile()) {
				if($this->sitemap()->remove()) $this->message(sprintf($this->_('The video sitemap %s was removed.'), SelfHostedVideoSitemap::path));
			}
		}

		$changed = false;
		foreach(array('embed', 'embedHosts') as $key) {
			if(!array_key_exists($key, $data)) continue;
			if((string) $this->get($key) !== (string) $data[$key]) $changed = true;
			$this->set($key, $data[$key]);
		}
		if(!$changed) return;
		$n = $this->embedRules()->writeAll();
		if($n) $this->message(sprintf($this->_n('The rules for embedding were written for %d page.', 'The rules for embedding were written for %d pages.', $n), $n));
	}

	/**
	 * Answer the requests of an upload in parts, and the searches of the picker, before the page editor does
	 *
	 * A large video arrives in many requests. Left to the page editor, each of them would end
	 * in a save of the page, with everything that hangs on a save (caches cleared, hooks of
	 * other modules run). The parts only need to be put together, so they are received here,
	 * after the same checks the page editor makes, and the page is saved once at the end.
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookAjaxSave(HookEvent $event) {

		$action = (string) $this->wire()->input->get('shv');
		if($action !== 'chunk' && $action !== 'cancel' && $action !== 'resume' && $action !== 'find' && $action !== 'captiontext' && $action !== 'embedcheck') return;
		if(empty($_SERVER['HTTP_X_FIELDNAME']) || !$this->wire()->input->requestMethod('POST')) return;

		$event->replace = true;

		/** @var ProcessPageEdit $process */
		$process = $event->object;
		/** @var Page $page */
		$page = $event->arguments(0);

		try {
			if($this->wire()->config->demo) throw new WireException($this->_('Uploads are disabled in demo mode.'));
			if($page->hasStatus(Page::statusLocked)) throw new WireException($this->_('This page is locked for edits.'));

			$key = $this->wire()->sanitizer->fieldName($_SERVER['HTTP_X_FIELDNAME']);
			if(!strlen($key) || !$process->ajaxEditable($page, $key)) {
				throw new WireException($this->_('You do not have permission to change this field.'));
			}

			$this->wire()->session->CSRF->validate();

			$field = $page->template->fieldgroup->getFieldContext($key);
			$inputfield = $field && $field->type instanceof FieldtypeSelfHostedVideo ? $field->getInputfield($page) : null;
			if(!$inputfield instanceof InputfieldSelfHostedVideo) throw new WireException($this->_('This is not a video field.'));

			$inputfield->attr('name', $key);
			$inputfield->val($page->getUnformatted($key));

			if($action === 'find') {
				$response = $inputfield->processFindRequest();
			} else if($action === 'captiontext') {
				$response = $inputfield->processCaptionTextRequest();
			} else if($action === 'embedcheck') {
				$response = $inputfield->processEmbedCheckRequest();
			} else {
				$response = $inputfield->processUploadRequest($action);
			}

		} catch(\Throwable $e) {
			$response = array('error' => true, 'message' => $e->getMessage());
		}

		echo json_encode($response);
	}

	/*** OUTPUT *********************************************************************************/

	/**
	 * Get the object that builds the markup for the website
	 *
	 * #pw-internal
	 *
	 * @return SelfHostedVideoMarkup
	 *
	 */
	public function markup() {
		if(!$this->markup) $this->markup = $this->wire(new SelfHostedVideoMarkup());
		return $this->markup;
	}

	/**
	 * Get the object that finds the profiles of CSS frameworks
	 *
	 * #pw-internal
	 *
	 * @return SelfHostedVideoFrameworks
	 *
	 */
	public function frameworks() {
		if(!$this->frameworks) $this->frameworks = $this->wire(new SelfHostedVideoFrameworks());
		return $this->frameworks;
	}

	/**
	 * Get the profile of a CSS framework: the class names it gives to the parts of a video
	 *
	 * Profiles are files, see SelfHostedVideoFrameworks. Hook after this method to change a profile
	 * or to provide one without a file:
	 *
	 * ~~~~~
	 * $wire->addHookAfter('FieldtypeSelfHostedVideo::getFramework', function(HookEvent $event) {
	 *   if($event->arguments(0) !== 'uikit3') return;
	 *   $profile = $event->return;
	 *   $profile['parts']['caption'] = 'uk-text-center';
	 *   $event->return = $profile;
	 * });
	 * ~~~~~
	 *
	 * @param string $name Name of the profile, i.e. "uikit3"
	 * @return array|null Array with `name`, `title`, `css` (URL of a stylesheet, or blank) and `parts`,
	 *   or null if there is no profile of that name
	 *
	 */
	public function ___getFramework($name) {
		return $this->frameworks()->get((string) $name);
	}

	/**
	 * Get the object that runs the checks of a video
	 *
	 * #pw-internal
	 *
	 * @return SelfHostedVideoChecks
	 *
	 */
	public function checker() {
		if(!$this->checker) $this->checker = $this->wire(new SelfHostedVideoChecks());
		return $this->checker;
	}

	/**
	 * Render the tags that load the stylesheet and script for the website
	 *
	 * Only needed when you turned off adding them automatically in the module settings,
	 * or when you prefer them in the head of your pages. Videos rendered after this call
	 * do not add them again.
	 *
	 * ~~~~~
	 * echo $modules->get('FieldtypeSelfHostedVideo')->renderAssets(); // i.e. before </head>
	 * ~~~~~
	 *
	 * @return string
	 *
	 */
	public function renderAssets() {
		return $this->markup()->renderAssets(true);
	}

	/**
	 * Get the URLs of the stylesheet and script for the website
	 *
	 * @return array Array with keys `css` and `js`
	 *
	 */
	public function getAssetUrls() {
		return $this->markup()->assetUrls();
	}

	/*** VALUES *********************************************************************************/

	/**
	 * Get blank value
	 *
	 * @param Page $page
	 * @param Field $field
	 * @return Pagevideos
	 *
	 */
	public function getBlankValue(Page $page, Field $field) {
		/** @var Pagevideos $pagevideos */
		$pagevideos = $this->wire(new Pagevideos($page));
		$pagevideos->setField($field);
		$pagevideos->setTrackChanges(true);
		return $pagevideos;
	}

	/**
	 * Get a blank Pagevideo
	 *
	 * @param Pagefiles $pagefiles
	 * @param string $filename
	 * @return Pagevideo
	 *
	 */
	protected function getBlankPagefile(Pagefiles $pagefiles, $filename) {
		return $this->wire(new Pagevideo($pagefiles, $filename));
	}

	/**
	 * Default file extensions
	 *
	 * @return string
	 *
	 */
	protected function getDefaultFileExtensions() {
		// MOV: what iPhones record
		return 'mp4 webm mov';
	}

	/**
	 * Wake up one video: convert a database row to a Pagevideo and add it to the Pagevideos
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param Pagefiles $pagefiles
	 * @param array $a Row from the database
	 * @return Pagefile
	 *
	 */
	protected function wakeupFile(Page $page, Field $field, Pagefiles $pagefiles, array $a) {
		$pagefile = parent::wakeupFile($page, $field, $pagefiles, $a);
		if($pagefile instanceof Pagevideo) $pagefile->wakeupVideo($a);
		return $pagefile;
	}

	/**
	 * Put one video to sleep: convert a Pagevideo to a database row
	 *
	 * A reference is stored with a copy of the original’s description, tags and metadata,
	 * so that selectors also find the pages that reuse a video.
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param Pagefile $pagefile
	 * @return array
	 *
	 */
	protected function sleepFile(Page $page, Field $field, Pagefile $pagefile) {

		$isVideo = $pagefile instanceof Pagevideo;

		if($isVideo && !$pagefile->isReference() && $pagefile->isNew() && (int) $this->get('faststart')) {
			// a video added from the API: prepared for streaming as uploads in the page editor are
			/** @var Pagevideo $pagefile */
			$track = $pagefile->trackChanges();
			if($track) $pagefile->setTrackChanges(false);
			$pagefile->faststart();
			if($track) $pagefile->setTrackChanges(true);
		}

		if($isVideo && !$pagefile->isReference() && (!$pagefile->get('duration') || !$pagefile->get('width'))) {
			// metadata not yet known: read it from the file, without recording it as an edit
			/** @var Pagevideo $pagefile */
			$track = $pagefile->trackChanges();
			if($track) $pagefile->setTrackChanges(false);
			$pagefile->probe();
			if($track) $pagefile->setTrackChanges(true);
		}

		if($isVideo && !$pagefile->isReference() && !is_array($pagefile->filedata('media'))) {
			// a video from before the module kept what the file tells about itself
			$track = $pagefile->trackChanges();
			if($track) $pagefile->setTrackChanges(false);
			$pagefile->storeMediaInfo();
			if($track) $pagefile->setTrackChanges(true);
		}

		if($isVideo) {
			// JSON has no infinite numbers: one of them would lose all data of the video
			$filedata = $pagefile->filedata();
			if(is_array($filedata)) {
				$storable = Pagevideo::storableData($filedata);
				if($storable !== $filedata) $pagefile->filedata($storable);
			}
		}

		$item = parent::sleepFile($page, $field, $pagefile);
		$fileSchema = (int) $field->get('fileSchema');

		if($isVideo && ($fileSchema & self::fileSchemaVideo)) {
			/** @var Pagevideo $pagefile */
			$item['duration'] = number_format($pagefile->duration(), 3, '.', '');
			$item['width'] = $pagefile->width();
			$item['height'] = $pagefile->height();
			$item['ratio'] = number_format(min($pagefile->ratio(), 99.99), 2, '.', '');
			$item['ref_pages_id'] = (int) $pagefile->get('ref_pages_id');
			$item['ref_name'] = (string) $pagefile->get('ref_name');
		}

		return $item;
	}

	/**
	 * Format the value for output
	 *
	 * Same as for file fields. In addition, reused videos are left out when their original
	 * is gone, hidden, still uploading, or on a page in the trash.
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param Pagevideos $value
	 * @return Pagevideos|Pagevideo|string|null
	 *
	 */
	public function ___formatValue(Page $page, Field $field, $value) {
		if($value instanceof Pagevideos) {
			foreach($value as $video) {
				/** @var Pagevideo $video */
				if(!$video->isReference()) continue;
				$original = $video->original();
				$hidden = $original && method_exists($original, 'hidden') && $original->hidden(); // 3.0.237+
				if(!$original || $hidden || $original->isTemp() || $original->page->isTrash()) {
					$value->removeQuietly($video);
				}
			}
		}
		return parent::___formatValue($page, $field, $value);
	}

	/*** SAVING *********************************************************************************/

	/**
	 * Save the field for the given page
	 *
	 * @param Page $page
	 * @param Field $field
	 * @return bool
	 *
	 */
	public function ___savePageField(Page $page, Field $field) {
		$value = $page->get($field->name);
		$changed = $page->isChanged($field->name) || ($value instanceof Wire && $value->isChanged());
		$result = parent::___savePageField($page, $field);
		$this->forgetOriginals($page->id);
		SelfHostedVideoChecks::forgetTitles();
		if($result && $changed) $this->syncReferences($page, $field);
		return $result;
	}

	/**
	 * Save a single video independently of its page
	 *
	 * #pw-internal
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param Pagefile $pagefile
	 * @param array $columns
	 * @return bool
	 *
	 */
	public function saveFile(Page $page, Field $field, Pagefile $pagefile, array $columns = array()) {
		$result = parent::saveFile($page, $field, $pagefile, $columns);
		$this->forgetOriginals($page->id);
		if($result && $pagefile instanceof Pagevideo && !$pagefile->isReference()) {
			$this->syncReferences($page, $field, $pagefile);
		}
		return $result;
	}

	/**
	 * Update specific columns for a single video
	 *
	 * #pw-internal
	 *
	 * @param Page $page
	 * @param Field $field
	 * @param Pagefile $pagefile
	 * @param array $a
	 * @return bool
	 *
	 */
	public function saveFileCols(Page $page, Field $field, Pagefile $pagefile, array $a) {
		$result = parent::saveFileCols($page, $field, $pagefile, $a);
		$this->forgetOriginals($page->id);
		return $result;
	}

	/**
	 * Delete the field from the given page
	 *
	 * @param Page $page
	 * @param Field $field
	 * @return bool
	 *
	 */
	public function ___deletePageField(Page $page, Field $field) {
		$result = parent::___deletePageField($page, $field);
		$this->forgetOriginals($page->id);
		return $result;
	}

	/**
	 * Move this field’s data from one page to another
	 *
	 * @param Page $src
	 * @param Page $dst
	 * @param Field $field
	 * @return bool
	 *
	 */
	public function ___replacePageField(Page $src, Page $dst, Field $field) {
		$result = parent::___replacePageField($src, $dst, $field);
		$this->forgetOriginals($src->id);
		$this->forgetOriginals($dst->id);
		return $result;
	}

	/*** DATABASE SCHEMA ************************************************************************/

	/**
	 * Check and update database schema according to current version and features
	 *
	 * @param Field $field
	 * @param array $schema Updated directly
	 * @param int $fileSchema The fileSchema version flags integer
	 * @return int Updated fileSchema flags integer
	 *
	 */
	protected function updateDatabaseSchema(Field $field, array &$schema, $fileSchema) {

		// while a field is being changed from another type, the core works on a temporary copy of it:
		// describe the columns but leave settings and stored flags alone, they are settled on the next call
		$changingType = (bool) $field->get('prevFieldtype');

		if($field->get('useTags') === null && !$changingType) {
			// tags are on for video fields unless switched off in the field settings
			$field->set('useTags', self::useTagsNormal);
		}

		$fileSchema = parent::updateDatabaseSchema($field, $schema, $fileSchema);
		$database = $this->wire()->database;
		$maxLen = $database->getMaxIndexLength();
		$tableExists = $database->tableExists($field->getTable());

		if(!$tableExists && !$changingType && $field->get('useTags') && !($fileSchema & self::fileSchemaTags)) {
			// table is about to be created: have the tags column in it from the start,
			// rather than added on a later request as file fields do
			$schema['tags'] = 'varchar(250) NOT NULL';
			$schema['keys']['tags'] = 'FULLTEXT KEY tags (tags)';
			$fileSchema = $fileSchema | self::fileSchemaTags;
		}

		$schema['duration'] = 'decimal(10,3) NOT NULL DEFAULT 0';
		$schema['width'] = 'int unsigned NOT NULL DEFAULT 0';
		$schema['height'] = 'int unsigned NOT NULL DEFAULT 0';
		$schema['ratio'] = 'decimal(4,2) NOT NULL DEFAULT 0';
		$schema['ref_pages_id'] = 'int unsigned NOT NULL DEFAULT 0';
		$schema['ref_name'] = "varchar($maxLen) NOT NULL DEFAULT ''";

		$schema['keys']['duration'] = 'index (duration)';
		$schema['keys']['width'] = 'index (width)';
		$schema['keys']['height'] = 'index (height)';
		$schema['keys']['ratio'] = 'index (ratio)';
		$schema['keys']['ref_pages_id'] = 'index (ref_pages_id)';

		if(!($fileSchema & self::fileSchemaVideo) && !$changingType) {
			$numErrors = 0;
			if($tableExists) {
				// existing table, i.e. a file field that was changed to a video field
				foreach(array('duration', 'width', 'height', 'ratio', 'ref_pages_id', 'ref_name') as $column) {
					if(!$this->addColumn($field, $column, $schema)) $numErrors++;
					if($numErrors) break;
				}
			}
			if(!$numErrors) $fileSchema = $fileSchema | self::fileSchemaVideo;
		}

		return $fileSchema;
	}

	/*** FINDING ********************************************************************************/

	/**
	 * Get match query for page finding selectors
	 *
	 * Adds to what file fields support:
	 *
	 * - `field.tags=foo` matches videos having the tag “foo” (among any others).
	 * - `field.tags!=foo` matches pages that have videos, none of them tagged “foo”.
	 * - `field.duration`, `field.width`, `field.height` and `field.ratio` accept number comparisons.
	 *
	 * @param DatabaseQuerySelect|PageFinderDatabaseQuerySelect $query
	 * @param string $table
	 * @param string $subfield
	 * @param string $operator
	 * @param mixed $value
	 * @return DatabaseQuery|DatabaseQuerySelect
	 * @throws PageFinderSyntaxException
	 *
	 */
	public function getMatchQuery($query, $table, $subfield, $operator, $value) {

		if($subfield === 'tags' && ($operator === '=' || $operator === '!=') && $query->field) {
			$schema = $this->getDatabaseSchema($query->field);
			if(isset($schema['tags'])) return $this->getMatchQueryTags($query, $table, $operator, $value);
		}

		if(in_array($subfield, array('duration', 'ratio', 'width', 'height'), true)) {
			$database = $this->wire()->database;
			if(!$database->isOperator($operator, WireDatabasePDO::operatorTypeComparison)) {
				$fieldName = $query->field ? $query->field->name : $table;
				throw new PageFinderSyntaxException("Invalid operator '$operator' for: $fieldName.$subfield");
			}
			$isInt = $subfield === 'width' || $subfield === 'height';
			if(is_array($value)) {
				foreach($value as $k => $v) $value[$k] = $isInt ? (int) $v : (float) $v;
			} else {
				$value = $isInt ? (int) $value : (float) $value;
			}
		}

		return parent::getMatchQuery($query, $table, $subfield, $operator, $value);
	}

	/**
	 * Match query for whole tags
	 *
	 * @param DatabaseQuerySelect|PageFinderDatabaseQuerySelect $query
	 * @param string $table
	 * @param string $operator Either "=" or "!="
	 * @param string|array $value
	 * @return DatabaseQuery|DatabaseQuerySelect
	 *
	 */
	protected function getMatchQueryTags($query, $table, $operator, $value) {

		$database = $this->wire()->database;
		$table = $database->escapeTable($table);
		$values = is_array($value) ? $value : array($value);

		if($operator === '!=') {
			// exclude pages where any video has the tag, so look at all rows of the real table
			$fieldTable = $database->escapeTable($query->field->getTable());
			$alias = $fieldTable . '__nt' . (++self::$aliasCount);
			$col = "$alias.tags";
		} else {
			$fieldTable = '';
			$alias = '';
			$col = "$table.tags";
		}

		// tags are separated by spaces (commas and pipes are also accepted), compare whole words
		$haystack = "CONCAT(' ', REPLACE(REPLACE(LOWER($col), ',', ' '), '|', ' '), ' ')";
		$conditions = array();

		foreach($values as $tag) {
			$tag = trim((string) $tag);
			$tag = function_exists('mb_strtolower') ? mb_strtolower($tag) : strtolower($tag);
			$tag = str_replace(' ', '_', $tag);
			if(!strlen($tag)) {
				$conditions[] = "$col=''";
				continue;
			}
			$bindKey = $query->bindValueGetKey('% ' . addcslashes($tag, '%_\\') . ' %');
			$conditions[] = "$haystack LIKE $bindKey";
		}

		$sql = '(' . implode(' OR ', $conditions) . ')';

		if($operator === '!=') {
			$query->where("pages.id NOT IN (SELECT $alias.pages_id FROM `$fieldTable` AS $alias WHERE $sql)");
		} else {
			$query->where($sql);
		}

		return $query;
	}

	/**
	 * Get selector info, as used by Lister and the selector builder
	 *
	 * @param Field $field
	 * @param array $data
	 * @return array
	 *
	 */
	public function ___getSelectorInfo(Field $field, array $data = array()) {
		$info = parent::___getSelectorInfo($field, $data);
		$labels = array(
			'duration' => $this->_('duration (seconds)'),
			'width' => $this->_('width (pixels)'),
			'height' => $this->_('height (pixels)'),
			'ratio' => $this->_('aspect ratio (width ÷ height)'),
			'ref_pages_id' => $this->_('reused from page (ID)'),
			'ref_name' => $this->_('reused file name'),
		);
		foreach($labels as $name => $label) {
			if(isset($info['subfields'][$name])) $info['subfields'][$name]['label'] = $label;
		}
		return $info;
	}

	/**
	 * Find videos across all pages, optionally limited to those with given tag(s)
	 *
	 * ~~~~~
	 * $fieldtype = $modules->get('FieldtypeSelfHostedVideo');
	 *
	 * // All videos tagged “tutorial”, newest first
	 * $videos = $fieldtype->findVideos('tutorial')->sort('-created');
	 *
	 * // Videos having either tag, only from product pages
	 * $videos = $fieldtype->findVideos('tutorial|howto', [ 'selector' => 'template=product' ]);
	 *
	 * foreach($videos as $video) {
	 *   echo "<li><a href='{$video->page->url}'>$video->title</a> ($video->durationStr)</li>";
	 * }
	 * ~~~~~
	 *
	 * @param string|array $tag Tag(s) to match, or blank for all videos. Specify one of:
	 *  - `foo` (single tag): videos having the tag.
	 *  - `foo|bar` (OR tags): videos having at least one of the tags.
	 *  - `foo,bar` or `['foo', 'bar']` (AND tags): videos having all of the tags.
	 * @param array $options
	 *  - `fields` (array|string): Only look in these video fields (default=all video fields).
	 *  - `selector` (string): Additional selector the pages have to match, i.e. "template=product" (default='').
	 *  - `include` (string): Which pages to include: "hidden", "unpublished", "all" or blank for
	 *     only pages that appear in lists (default="hidden").
	 *  - `references` (bool): Also return reused videos? By default each video is returned once,
	 *     from the page it was uploaded to (default=false).
	 *  - `limit` (int): Maximum number of videos to return, or 0 for no limit (default=0).
	 * @return WireArray Array of Pagevideo objects
	 *
	 */
	public function ___findVideos($tag = '', array $options = array()) {

		$defaults = array(
			'fields' => array(),
			'selector' => '',
			'include' => 'hidden',
			'references' => false,
			'limit' => 0,
		);

		$options = array_merge($defaults, $options);
		$sanitizer = $this->wire()->sanitizer;
		$pages = $this->wire()->pages;
		$limit = (int) $options['limit'];

		/** @var WireArray $videos */
		$videos = $this->wire(new WireArray());

		if(is_array($tag)) $tag = implode(',', $tag);
		$tag = trim((string) $tag);

		$fieldNames = $options['fields'];
		if(!is_array($fieldNames)) $fieldNames = explode(' ', str_replace(',', ' ', (string) $fieldNames));
		$fieldNames = array_filter(array_map('trim', $fieldNames), 'strlen');

		foreach($this->getVideoFields() as $field) {

			if(count($fieldNames) && !in_array($field->name, $fieldNames, true)) continue;

			if(strlen($tag)) {
				// a field with tags switched off has no tagged videos
				$schema = $this->getDatabaseSchema($field);
				if(!isset($schema['tags'])) continue;
			}

			// find the pages first, so that access control and page status are respected
			$selector = array();
			if(!strlen($tag)) {
				$selector[] = "$field->name.count>0";
			} else if(strpos($tag, '|') !== false) {
				$tags = array();
				foreach(explode('|', $tag) as $t) {
					$t = $sanitizer->selectorValue(str_replace(' ', '_', trim($t)));
					if(strlen($t)) $tags[] = $t;
				}
				if(!count($tags)) continue;
				$selector[] = "$field->name.tags=" . implode('|', $tags);
			} else {
				foreach(explode(',', $tag) as $t) {
					$t = $sanitizer->selectorValue(str_replace(' ', '_', trim($t)));
					if(strlen($t)) $selector[] = "$field->name.tags=$t";
				}
				if(!count($selector)) continue;
			}
			if(strlen($options['include'])) $selector[] = 'include=' . $sanitizer->name($options['include']);
			if(strlen($options['selector'])) $selector[] = trim($options['selector'], ', ');

			foreach($pages->find(implode(', ', $selector)) as $page) {
				/** @var Page $page */
				$value = $page->get($field->name);
				if($value instanceof Pagevideo) {
					$items = array($value);
				} else if($value instanceof Pagevideos) {
					$items = $value;
				} else {
					// field formatted as a string, or empty: use the unformatted value instead
					$items = $page->getUnformatted($field->name);
					if(!$items instanceof Pagevideos) continue;
				}
				foreach($items as $video) {
					/** @var Pagevideo $video */
					if(!$options['references'] && $video->isReference()) continue;
					if($video->isBroken()) continue;
					if(strlen($tag) && !$video->hasTag($tag)) continue;
					$videos->add($video);
					if($limit && $videos->count() >= $limit) return $videos;
				}
			}
		}

		return $videos;
	}

	/*** REFERENCES (VIDEOS REUSED ON OTHER PAGES) **********************************************/

	/**
	 * Get all fields that use this Fieldtype
	 *
	 * @return Field[]
	 *
	 */
	public function getVideoFields() {
		$fields = array();
		// exact type match: looking for inherited types would load every Fieldtype module
		foreach($this->wire()->fields->findByType($this->className(), array('inherit' => false)) as $field) {
			if($field instanceof Field && $field->id) $fields[$field->name] = $field;
		}
		return $fields;
	}

	/**
	 * Get an original video as it is stored in the database
	 *
	 * This is what references read their url, title, description, tags and metadata from.
	 * The returned Pagevideo is always unformatted and separate from the value held by
	 * its page, so that it is unaffected by the page’s output formatting state.
	 *
	 * #pw-internal
	 *
	 * @param int $pageId ID of the page holding the original
	 * @param string $name File name of the original
	 * @return Pagevideo|null
	 *
	 */
	public function getOriginal($pageId, $name) {

		$pageId = (int) $pageId;
		$name = (string) $name;

		if($pageId < 1 || !strlen($name)) return null;

		if(!isset($this->originals[$pageId])) {
			// keep memory use in check for long running scripts
			if(count($this->originals) >= 250) $this->originals = array();
			$this->originals[$pageId] = $this->loadOriginals($pageId);
		}

		return isset($this->originals[$pageId][$name]) ? $this->originals[$pageId][$name] : null;
	}

	/**
	 * Forget the originals remembered for the given page (or all pages), so they are loaded again when needed
	 *
	 * #pw-internal
	 *
	 * @param int $pageId Page ID, or 0 for all
	 *
	 */
	public function forgetOriginals($pageId = 0) {
		if($pageId) {
			unset($this->originals[(int) $pageId]);
		} else {
			$this->originals = array();
		}
	}

	/**
	 * Load all original videos of the given page from the database
	 *
	 * @param int $pageId
	 * @return Pagevideo[] Indexed by file name
	 *
	 */
	protected function loadOriginals($pageId) {

		$originals = array();
		$pages = $this->wire()->pages;

		// when the page is already loaded, take it as it is: $pages->get() would reset its output formatting
		$cache = $pages->getCache();
		$page = isset($cache[$pageId]) ? $cache[$pageId] : $pages->get($pageId);

		if(!$page instanceof Page || !$page->id || !$page->template) return $originals;

		foreach($this->getVideoFields() as $field) {

			if(!$page->template->fieldgroup->hasField($field)) continue;

			$contextField = $page->getField($field->name);
			if($contextField instanceof Field) $field = $contextField;

			// loading a value resets the page’s change tracking for the field, which is not wanted here
			$changed = $page->isChanged($field->name);
			$rows = $this->loadPageField($page, $field);
			$videos = empty($rows) ? null : $this->wakeupValue($page, $field, $rows);
			if($changed) $page->trackChange($field->name);

			if(!$videos instanceof Pagevideos) continue;

			foreach($videos as $video) {
				/** @var Pagevideo $video */
				if($video->isReference() || isset($originals[$video->basename()])) continue;
				$originals[$video->basename()] = $video;
			}
		}

		return $originals;
	}

	/**
	 * Find where videos of the given page(s) are reused
	 *
	 * #pw-internal
	 *
	 * @param Page|int|array $page Page, page ID or array of page IDs that hold the originals
	 * @param string $name Optionally limit to the original with this file name
	 * @return array Array of rows, each an array with these keys:
	 *  - `pages_id` (int): ID of the page that reuses the video.
	 *  - `field` (string): Name of the field it is reused in.
	 *  - `ref_pages_id` (int): ID of the page holding the original.
	 *  - `ref_name` (string): File name of the original.
	 *
	 */
	public function findReferences($page, $name = '') {

		$database = $this->wire()->database;
		$rows = array();

		if($page instanceof Page) {
			$ids = array($page->id);
		} else if(is_array($page)) {
			$ids = $page;
		} else {
			$ids = array($page);
		}

		foreach($ids as $key => $id) {
			$id = (int) $id;
			if($id > 0) {
				$ids[$key] = $id;
			} else {
				unset($ids[$key]);
			}
		}

		if(!count($ids)) return $rows;

		foreach($this->getVideoFields() as $field) {
			$table = $database->escapeTable($field->getTable());
			foreach(array_chunk($ids, 500) as $chunk) {
				$sql = "SELECT pages_id, ref_pages_id, ref_name FROM `$table` WHERE ref_pages_id IN(" . implode(',', $chunk) . ")";
				if(strlen($name)) $sql .= " AND ref_name=:name";
				try {
					$query = $database->prepare($sql);
					if(strlen($name)) $query->bindValue(':name', $name);
					$query->execute();
				} catch(\PDOException $e) {
					// table without reference columns yet (schema not yet updated): no references there
					continue 2;
				}
				while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
					$rows[] = array(
						'pages_id' => (int) $row['pages_id'],
						'field' => $field->name,
						'ref_pages_id' => (int) $row['ref_pages_id'],
						'ref_name' => (string) $row['ref_name'],
					);
				}
				$query->closeCursor();
			}
		}

		return $rows;
	}

	/**
	 * Update the copies of description, tags and metadata that references keep for use in selectors
	 *
	 * Called automatically whenever a page with videos is saved.
	 *
	 * #pw-internal
	 *
	 * @param Page $page Page holding the originals
	 * @param Field $field Field holding the originals
	 * @param Pagevideo|null $only Optionally update references to this one video only
	 * @return int Number of database rows updated
	 *
	 */
	public function syncReferences(Page $page, Field $field, ?Pagevideo $only = null) {

		if(!$page->id) return 0;

		$videos = $page->getUnformatted($field->name);
		if(!$videos instanceof Pagevideos || !$videos->count()) return 0;

		$references = $this->findReferences($page, $only ? $only->basename() : '');
		if(!count($references)) return 0;

		$database = $this->wire()->database;
		$fields = $this->getVideoFields();
		$numUpdated = 0;
		$done = array();

		foreach($references as $row) {

			$key = $row['field'] . '/' . $row['ref_name'];
			if(isset($done[$key]) || !isset($fields[$row['field']])) continue;
			$done[$key] = true;

			$video = $videos->getFile($row['ref_name']);
			if(!$video instanceof Pagevideo || $video->isReference()) continue;

			$refField = $fields[$row['field']];
			$schema = $this->getDatabaseSchema($refField);
			$table = $database->escapeTable($refField->getTable());

			$values = array(
				'description' => $video->description(true),
				'tags' => $video->tags(),
				'filesize' => $video->filesize(),
				'duration' => number_format($video->duration(), 3, '.', ''),
				'width' => $video->width(),
				'height' => $video->height(),
				'ratio' => number_format(min($video->ratio(), 99.99), 2, '.', ''),
			);

			$sets = array();
			foreach($values as $col => $value) {
				if(isset($schema[$col])) $sets[] = "$col=:$col";
			}
			if(!count($sets)) continue;

			$sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE ref_pages_id=:ref_pages_id AND ref_name=:ref_name";
			$query = $database->prepare($sql);
			foreach($values as $col => $value) {
				if(isset($schema[$col])) $query->bindValue(":$col", $value);
			}
			$query->bindValue(':ref_pages_id', $page->id, \PDO::PARAM_INT);
			$query->bindValue(':ref_name', $row['ref_name']);
			$query->execute();
			$numUpdated += $query->rowCount();
		}

		return $numUpdated;
	}

	/**
	 * Make references follow an original that was renamed
	 *
	 * #pw-internal
	 *
	 * @param Page $page Page holding the original
	 * @param string $oldName Previous file name
	 * @param string $newName New file name
	 * @return int Number of references updated
	 *
	 */
	public function renameReferences(Page $page, $oldName, $newName) {

		$references = $this->findReferences($page, $oldName);
		if(!count($references)) return 0;

		$database = $this->wire()->database;
		$fields = $this->getVideoFields();
		$numUpdated = 0;
		$done = array();

		foreach($references as $row) {
			if(isset($done[$row['field']]) || !isset($fields[$row['field']])) continue;
			$done[$row['field']] = true;
			$table = $database->escapeTable($fields[$row['field']]->getTable());
			$query = $database->prepare(
				"UPDATE `$table` SET ref_name=:new_name, data=:new_key " .
				"WHERE ref_pages_id=:ref_pages_id AND ref_name=:old_name"
			);
			$query->bindValue(':new_name', $newName);
			$query->bindValue(':new_key', Pagevideos::referenceKeyFor($page->id, $newName));
			$query->bindValue(':ref_pages_id', $page->id, \PDO::PARAM_INT);
			$query->bindValue(':old_name', $oldName);
			$query->execute();
			$numUpdated += $query->rowCount();
		}

		$this->uncachePages($references);

		return $numUpdated;
	}

	/**
	 * Carry what belongs to a video over to its new file name (after it was renamed)
	 *
	 * Its counts follow, and so do the tags in texts that place it: in the texts of its own page
	 * and of the pages that reuse it. Called by Pagevideo::rename(), after renameReferences().
	 *
	 * @param Page $page Page of the video
	 * @param string $oldName
	 * @param string $newName
	 * @return array IDs of the pages whose texts were changed
	 *
	 */
	public function videoRenamed(Page $page, $oldName, $newName) {
		if(!$page->id || $oldName === $newName) return array();
		$this->stats()->move($page->id, $oldName, $page->id, $newName);
		$usage = $this->usage();
		$ids = array($page->id);
		foreach($this->findReferences($page, $newName) as $row) $ids[] = (int) $row['pages_id'];
		$ids = array_merge($ids, $usage->textHolders($page->id, $oldName));
		$changed = $usage->renameTags($ids, $oldName, $newName);
		$usage->moveVideo($page->id, $oldName, $page->id, $newName);
		return $changed;
	}

	/**
	 * Remove all references to the given original, so that it is no longer reused anywhere
	 *
	 * After this the original can be deleted. The pages that reused it simply lose that video.
	 *
	 * ~~~~~
	 * $video = $page->videos->getFile('intro.mp4');
	 * $modules->get('FieldtypeSelfHostedVideo')->removeReferences($video);
	 * $page->videos->delete($video);
	 * $page->save('videos');
	 * ~~~~~
	 *
	 * @param Pagevideo $video The original
	 * @return int Number of references removed
	 *
	 */
	public function removeReferences(Pagevideo $video) {

		if($video->isReference() || !$video->page->id) return 0;

		$references = $this->findReferences($video->page, $video->basename());
		if(!count($references)) return 0;

		$database = $this->wire()->database;
		$fields = $this->getVideoFields();
		$numRemoved = 0;
		$done = array();

		foreach($references as $row) {
			if(isset($done[$row['field']]) || !isset($fields[$row['field']])) continue;
			$done[$row['field']] = true;
			$table = $database->escapeTable($fields[$row['field']]->getTable());
			$query = $database->prepare("DELETE FROM `$table` WHERE ref_pages_id=:ref_pages_id AND ref_name=:ref_name");
			$query->bindValue(':ref_pages_id', $video->page->id, \PDO::PARAM_INT);
			$query->bindValue(':ref_name', $video->basename());
			$query->execute();
			$numRemoved += $query->rowCount();
		}

		$this->uncachePages($references);

		return $numRemoved;
	}

	/**
	 * Uncache the pages named in the given reference rows, so they reload their videos
	 *
	 * @param array $references Rows from findReferences()
	 *
	 */
	protected function uncachePages(array $references) {
		$pages = $this->wire()->pages;
		foreach($references as $row) {
			$page = $pages->getCache($row['pages_id']);
			if($page instanceof Page) $pages->uncache($page);
		}
	}

	/**
	 * Hook before Pages::trash and Pages::delete
	 *
	 * Stops a page from being trashed or deleted while its videos are reused on pages
	 * outside of the branch that is being removed.
	 *
	 * #pw-internal
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 *
	 */
	public function hookPagesDelete(HookEvent $event) {

		$page = $event->arguments(0);
		if(!$page instanceof Page || !$page->id) return;

		$blocking = $this->getBlockingReferences($page);
		if(!count($blocking)) return;

		$pages = $this->wire()->pages;
		$usedOn = $pages->getById(array_keys($blocking));

		throw new WireException(sprintf(
			$this->_('Page %1$s was not removed because its videos are used on other pages: %2$s'),
			$page->path(),
			Pagevideos::pageList($usedOn)
		));
	}

	/**
	 * Get the references that stand in the way of removing the given page and its children
	 *
	 * #pw-internal
	 *
	 * @param Page $page
	 * @return array Reference rows (see findReferences) grouped by the ID of the page that reuses a video
	 *
	 */
	public function getBlockingReferences(Page $page) {

		$blocking = array();
		if(!$page->id || !count($this->getVideoFields())) return $blocking;

		$ids = array($page->id => $page->id);
		if($page->numChildren) {
			foreach($this->wire()->pages->findIDs("has_parent=$page->id, include=all") as $id) {
				$ids[(int) $id] = (int) $id;
			}
		}

		foreach($this->findReferences(array_values($ids)) as $row) {
			// a reference from inside the branch is removed along with it, so it does not block
			if(isset($ids[$row['pages_id']])) continue;
			$blocking[$row['pages_id']][] = $row;
		}

		return $blocking;
	}

	/*** TAG LABELS *****************************************************************************/

	/**
	 * Get the labels entered for predefined tags in the field settings
	 *
	 * @param Field $field
	 * @param string|int|Language|null $language Language name, ID or object. Omit for the current language.
	 * @return array Labels indexed by lowercase tag
	 *
	 */
	public function getTagLabels(Field $field, $language = null) {

		$languages = $this->wire()->languages;
		$labels = $this->parseTagLabels((string) $field->get('tagLabels'));

		if(!$languages) return $labels;

		if($language === null) {
			$language = $this->wire()->user->language;
		} else if(!is_object($language)) {
			$language = $languages->get(ctype_digit("$language") ? (int) $language : (string) $language);
		}

		if($language instanceof Page && $language->id && !$language->isDefault()) {
			// translated labels, with the default language as fallback for each tag
			foreach($this->parseTagLabels((string) $field->get("tagLabels$language->id")) as $tag => $label) {
				$labels[$tag] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Parse tag labels text, one "tag=Label" per line
	 *
	 * @param string $text
	 * @return array
	 *
	 */
	protected function parseTagLabels($text) {
		$labels = array();
		foreach(explode("\n", str_replace("\r", "\n", $text)) as $line) {
			if(strpos($line, '=') === false) continue;
			list($tag, $label) = explode('=', $line, 2);
			$tag = trim($tag);
			$tag = function_exists('mb_strtolower') ? mb_strtolower($tag) : strtolower($tag);
			$tag = str_replace(' ', '_', $tag);
			$label = trim($label);
			if(strlen($tag) && strlen($label)) $labels[$tag] = $label;
		}
		return $labels;
	}

	/*** FIELD SETUP AND CONFIGURATION **********************************************************/

	/**
	 * Get the Inputfield used for input
	 *
	 * The dedicated video Inputfield is used when installed, otherwise the core file Inputfield.
	 *
	 * @param Page $page
	 * @param Field $field
	 * @return Inputfield
	 *
	 */
	public function getInputfield(Page $page, Field $field) {
		$default = $this->getDefaultInputfieldClass();
		$this->defaultInputfieldClass = $default;
		$inputfieldClass = (string) $field->get('inputfieldClass');
		if(strlen($inputfieldClass) && $inputfieldClass !== $default) {
			// only the video input or a module derived from it can edit a video field
			$ns = __NAMESPACE__ . '\\';
			$valid = $this->wire()->modules->isInstalled($inputfieldClass)
				&& $default !== 'InputfieldFile'
				&& is_subclass_of($ns . $inputfieldClass, $ns . $default);
			if(!$valid) $field->set('inputfieldClass', '');
		}
		return parent::getInputfield($page, $field);
	}

	/**
	 * Get default Inputfield class name
	 *
	 * #pw-internal
	 *
	 * @return string
	 *
	 */
	public function getDefaultInputfieldClass() {
		$class = str_replace('Fieldtype', 'Inputfield', $this->className());
		return $this->wire()->modules->isInstalled($class) ? $class : 'InputfieldFile';
	}

	/**
	 * Get setup options for new fields
	 *
	 * @return array
	 *
	 */
	public function ___getFieldSetups() {
		$setups = parent::___getFieldSetups();
		if(isset($setups['single'])) $setups['single']['title'] = $this->_('Single video');
		if(isset($setups['multiple'])) $setups['multiple']['title'] = $this->_('Multiple videos');
		return $setups;
	}

	/**
	 * Get compatible Fieldtypes
	 *
	 * A video field cannot be changed to another type, because other types would not
	 * understand reused videos. (A file field can be changed to a video field.)
	 *
	 * @param Field $field
	 * @return Fieldtypes
	 *
	 */
	public function ___getCompatibleFieldtypes(Field $field) {
		/** @var Fieldtypes $fieldtypes */
		$fieldtypes = $this->wire(new Fieldtypes());
		$fieldtypes->add($this);
		return $fieldtypes;
	}

	/**
	 * Called when a field of this type is about to be saved: fill in defaults for settings not yet made
	 *
	 * This applies to new fields and to file fields that were just changed to video fields.
	 *
	 * @param Field $field
	 *
	 */
	public function ___saveFieldReady(Field $field) {
		parent::___saveFieldReady($field);
		if($field->get('useTags') === null) $field->set('useTags', self::useTagsNormal);
		if(!$field->get('extensions')) $field->set('extensions', $this->getDefaultFileExtensions());
		if(!is_array($field->get('textformatters'))) $field->set('textformatters', array('TextformatterEntities'));
	}

	/**
	 * Field configuration
	 *
	 * @param Field $field
	 * @return InputfieldWrapper
	 *
	 */
	public function ___getConfigInputfields(Field $field) {

		$inputfields = parent::___getConfigInputfields($field);
		$languages = $this->wire()->languages;

		/** @var InputfieldTextarea $f */
		$f = $this->wire()->modules->get('InputfieldTextarea');
		$f->attr('name', 'tagLabels');
		$f->attr('rows', 5);
		$f->attr('value', (string) $field->get('tagLabels'));
		$f->label = $this->_('Labels for predefined tags');
		$f->description =
			$this->_('Optionally enter a label for each predefined tag, one per line, in the format: tag=Label') . ' ' .
			$this->_('Labels are meant for display to site visitors and can be translated. A tag without a label is displayed as the tag itself.');
		$f->notes = $this->_('Example: tutorial=Step-by-step tutorials');
		$f->icon = 'tags';
		$f->showIf = 'useTags>1';
		$f->collapsed = Inputfield::collapsedBlank;

		if($languages) {
			$f->set('useLanguages', true);
			foreach($languages as $language) {
				/** @var Language $language */
				if($language->isDefault()) continue;
				$f->set("value$language->id", (string) $field->get("tagLabels$language->id"));
			}
		}

		$tagsList = $inputfields->getChildByName('tagsList');
		$parent = $tagsList ? $tagsList->getParent() : null;

		if($parent instanceof InputfieldWrapper) {
			$parent->insertAfter($f, $tagsList);
		} else {
			$inputfields->add($f);
		}

		return $inputfields;
	}

	/**
	 * Free tools for making a video smaller, as offered in the checklist of a video that is too large
	 *
	 * From the module setting “Tools for making videos smaller”. Lines that are not
	 * "Name | https://address | kind" are left out.
	 *
	 * @param bool $withUpload Include services the video has to be uploaded to? (false for protected videos)
	 * @return array Arrays with `name`, `url` and `kind` ("browser", "upload" or "program")
	 *
	 */
	public function optimizeTools($withUpload = true) {
		$sanitizer = $this->wire()->sanitizer;
		$tools = array();
		$text = (string) $this->get('optimizeTools');
		foreach(preg_split('/\r\n|\r|\n/', $text) as $line) {
			$parts = array_map('trim', explode('|', $line));
			if(count($parts) < 2) continue;
			$name = $sanitizer->text($parts[0], array('maxLength' => 60));
			$url = $sanitizer->httpUrl($parts[1]);
			if(!strlen($name) || !preg_match('#^https?://#i', $url)) continue;
			$kind = isset($parts[2]) ? strtolower($parts[2]) : 'upload';
			if(!in_array($kind, array('browser', 'upload', 'program'), true)) $kind = 'upload';
			if($kind === 'upload' && !$withUpload) continue;
			$tools[] = array('name' => $name, 'url' => $url, 'kind' => $kind);
			if(count($tools) >= 8) break;
		}
		return $tools;
	}

	/**
	 * Videos per page of a video field in the page editor (module setting, 1 to 100, default 10)
	 *
	 * @return int
	 *
	 */
	public function editorPerPage() {
		$n = (int) $this->get('editorPerPage');
		return $n > 0 ? min(100, $n) : 10;
	}

	/**
	 * Module settings
	 *
	 * @param InputfieldWrapper $inputfields
	 *
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {

		$urls = $this->getAssetUrls();

		/** @var InputfieldRadios $f */
		$f = $this->wire()->modules->get('InputfieldRadios');
		$f->attr('name', 'autoAssets');
		$f->label = $this->_('Stylesheet and script for the website');
		$f->description = $this->_('Videos shown with render() need a small stylesheet and script, which make them fit the width of the page and run background videos.');
		$f->addOption(1, $this->_('Add them automatically where a video is shown'));
		$f->addOption(0, $this->_('I add them to my templates myself'));
		$f->attr('value', (int) $this->get('autoAssets'));
		$f->notes = sprintf(
			$this->_('To add them yourself, output %1$s in your template, or link these files: %2$s'),
			"`\$modules->get('FieldtypeSelfHostedVideo')->renderAssets()`",
			'`' . preg_replace('/\\?v=.*$/', '', $urls['css']) . '`, `' . preg_replace('/\\?v=.*$/', '', $urls['js']) . '`'
		);
		$f->icon = 'code';
		$inputfields->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $this->wire()->modules->get('InputfieldCheckbox');
		$f->attr('name', 'faststart');
		$f->attr('value', 1);
		if((int) $this->get('faststart')) $f->attr('checked', 'checked');
		$f->label = $this->_('Prepare videos for streaming');
		$f->label2 = $this->_('Prepare MP4 and MOV files for streaming when they are uploaded or replaced');
		$f->description = $this->_('Many programs write the index of an MP4 file at its end. A browser then has to load the whole file before the video starts. Checked, the module moves the index to the front of the file. Picture and sound stay exactly as they are; nothing is converted.');
		$f->notes = $this->_('Videos that are already there can be prepared in the page editor: their checklist offers it.');
		$f->icon = 'bolt';
		$inputfields->add($f);

		/** @var InputfieldTextarea $f */
		$f = $this->wire()->modules->get('InputfieldTextarea');
		$f->attr('name', 'optimizeTools');
		$f->attr('value', (string) $this->get('optimizeTools'));
		$f->attr('rows', 5);
		$f->label = $this->_('Tools for making videos smaller');
		$f->description = $this->_('Offered in the checklist of a video whose data rate or file size is too high, so that editors can make it smaller and replace the file. One tool per line: name | address | kind.');
		$f->notes =
			$this->_('Kind: browser (works in the browser, the video is not uploaded), upload (the video is uploaded to the service), program (a program for one\'s own computer).') . "\n" .
			$this->_('Services the video is uploaded to are not offered for protected videos. Leave empty to offer no tools.');
		$f->collapsed = Inputfield::collapsedYes;
		$f->icon = 'compress';
		$inputfields->add($f);

		/** @var InputfieldSelect $f */
		$f = $this->wire()->modules->get('InputfieldSelect');
		$f->attr('name', 'framework');
		$f->label = $this->_('CSS framework of the website');
		$f->description = $this->_('Videos on the website can get the class names of a CSS framework, so that overlays, chapters and the title below a video look like the rest of your website. The module does not load the framework: your website has to include it already.');
		$f->addOption('', $this->_('None: the look of the module'));
		foreach($this->frameworks()->find() as $name => $title) $f->addOption($name, $title);
		$f->attr('value', (string) $this->get('framework'));
		$f->notes = sprintf(
			$this->_('A framework is a file that names the classes for each part of a video. The files in %1$s show how. To add a framework, or to change one of the above, put a file of your own into %2$s. See “CSS frameworks” in the README.'),
			'`' . $this->wire()->config->urls->get('FieldtypeSelfHostedVideo') . 'frameworks/`',
			'`' . $this->wire()->config->urls->templates . SelfHostedVideoFrameworks::siteFolder . '`'
		);
		$f->icon = 'paint-brush';
		$inputfields->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $this->wire()->modules->get('InputfieldCheckbox');
		$f->attr('name', 'noStats');
		$f->attr('value', 1);
		if(!$this->useStats()) $f->attr('checked', 'checked');
		$f->label = $this->_('Statistics');
		$f->label2 = $this->_('Do not count plays');
		$f->description = $this->_('The module counts how often each video is started on the website, and how many of those plays reach a quarter, the half, three quarters and the end. The page editor and the Video Manager show it for the last 30 days. The counts are anonymous: per video and day, without addresses, cookies or anything else about the visitor. Plays of those who may edit the page, and of robots, are not counted.');
		$f->notes = sprintf($this->_('The script of the website reports to %s. Counts older than two years are removed.'), '`' . $this->wire()->config->urls->root . SelfHostedVideoStats::path . '`');
		$f->icon = 'bar-chart';
		$inputfields->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $this->wire()->modules->get('InputfieldCheckbox');
		$f->attr('name', 'noVideoSitemap');
		$f->attr('value', 1);
		if(!$this->useSitemap()) $f->attr('checked', 'checked');
		$f->label = $this->_('Video sitemap');
		$f->label2 = sprintf($this->_('Do not create a video sitemap (%s)'), SelfHostedVideoSitemap::path);
		$f->description = sprintf(
			$this->_('The module writes the file %s into the root folder of the website: the videos of all public pages with thumbnail, title, description, file, duration and upload date, so that search engines find them. It is written again whenever a page with videos is saved or deleted. Protected videos and videos without a thumbnail are left out.'),
			'`' . SelfHostedVideoSitemap::path . '`'
		);
		$sitemap = $this->sitemap();
		$url = $sitemap->url();
		$notes = array();
		if($this->useSitemap()) {
			if($sitemap->isForeignFile()) {
				$notes[] = sprintf($this->_('There is a file %s in the root folder that the module did not write. It is left alone; remove or rename it to get the video sitemap.'), '`' . SelfHostedVideoSitemap::path . '`');
			} else if($sitemap->isOwnFile()) {
				$notes[] = sprintf($this->_('Written %1$s: %2$s'), wireRelativeTimeStr(filemtime($sitemap->filename())), "[$url]($url)");
			} else if($this->wire()->cache->get(self::sitemapFailedCache)) {
				$notes[] = sprintf($this->_('The file could not be written into the root folder (%1$s), so the module answers its address itself: %2$s'), '`' . $this->wire()->config->paths->root . '`', "[$url]($url)");
			} else {
				$notes[] = sprintf($this->_('The file is written at the end of this request: %s'), "[$url]($url)");
			}
		}
		$notes[] = sprintf(
			$this->_('Tell search engines about it: add the line %1$s to the file robots.txt of the website, or submit %2$s in Google Search Console (Sitemaps).'),
			"`Sitemap: $url`",
			"`$url`"
		);
		$f->notes = implode("\n", $notes);
		$f->icon = 'sitemap';
		$inputfields->add($f);

		/** @var InputfieldRadios $f */
		$f = $this->wire()->modules->get('InputfieldRadios');
		$f->attr('name', 'editorTab');
		$f->addOption(0, $this->_('Among the other fields of the page'));
		$f->addOption(1, $this->_('Each video field in a tab of its own, named after the field, after “Content”'));
		$f->addOption(2, $this->_('All video fields together in one tab “Videos”, after “Content”'));
		$mode = (int) $this->get('editorTab');
		$f->attr('value', $mode === 1 || $mode === 2 ? $mode : 0);
		$f->label = $this->_('Video fields in the page editor');
		$f->description = $this->_('Where the video fields of a page are shown in the page editor. A page can have several video fields, for one video or for many.');
		$f->notes = $this->_('A video field that is hidden, that sits in a fieldset or in a tab, or that belongs to a repeater item stays where it is, and so does one whose setting “Never in a tab” is checked (Setup > Fields > the field > Input). For a single field, ProcessWire offers a tab of its own in the settings of the field: Input > Visibility > Tab.');
		$f->icon = 'folder-o';
		$inputfields->add($f);

		/** @var InputfieldInteger $f */
		$f = $this->wire()->modules->get('InputfieldInteger');
		$f->attr('name', 'editorPerPage');
		$f->attr('value', $this->editorPerPage());
		$f->attr('min', 1);
		$f->attr('max', 100);
		$f->inputType = 'number';
		$f->label = $this->_('Videos per page in the page editor');
		$f->description = $this->_('A video field with more videos than this shows them in pages, with links to the other pages below the list. It applies to all video fields, in a tab or not.');
		$f->notes = $this->_('1 to 100. Default: 10.');
		$f->icon = 'list-ol';
		$inputfields->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $this->wire()->modules->get('InputfieldCheckbox');
		$f->attr('name', 'videoManager');
		$f->attr('value', 1);
		if($this->wire()->modules->isInstalled('ProcessSelfHostedVideos')) $f->attr('checked', 'checked');
		$f->label = $this->_('Video Manager');
		$f->label2 = $this->_('Show the Video Manager under Setup');
		$f->description = $this->_('An overview of all videos of the site, with search, filters, checklists and actions on several videos at once. Who may use it is set with the permission “video-overview”.');
		$f->notes = $this->_('Unchecked, the page Setup > Video Manager and its permission are removed. The videos themselves are not affected, and checking it again brings the Video Manager back.');
		$f->icon = 'film';
		$inputfields->add($f);

		/** @var InputfieldInteger $f */
		$f = $this->wire()->modules->get('InputfieldInteger');
		$f->attr('name', 'managerPerPage');
		$f->attr('value', max(1, min(100, (int) $this->get('managerPerPage') ?: 10)));
		$f->attr('min', 1);
		$f->attr('max', 100);
		$f->inputType = 'number';
		$f->label = $this->_('Videos per page in the Video Manager');
		$f->description = $this->_('How many videos each page of the list in the Video Manager shows. Below the list are links to the other pages.');
		$f->notes = $this->_('1 to 100. Default: 10.');
		$f->showIf = 'videoManager=1';
		$f->icon = 'list-ol';
		$inputfields->add($f);

		/** @var InputfieldFieldset $fs */
		$fs = $this->wire()->modules->get('InputfieldFieldset');
		$fs->attr('name', '_embedSettings');
		$fs->label = $this->_('Embedding on other websites');
		$fs->description = $this->_('Other websites can show a video of this site in their pages with the address of its file. Their visitors then watch it at the cost of this site.');
		$fs->icon = 'share-alt';
		$inputfields->add($fs);

		/** @var InputfieldRadios $f */
		$f = $this->wire()->modules->get('InputfieldRadios');
		$f->attr('name', 'embed');
		$f->label = $this->_('Other websites may embed videos');
		$f->addOption(1, $this->_('Allowed'));
		$f->addOption(0, $this->_('Blocked: only this website (and its subdomains) may show the videos'));
		$f->attr('value', (int) $this->get('embed') === 0 ? 0 : 1);
		$f->notes = $this->_('Each video can be set differently in the page editor. Blocked concerns the video file, its thumbnail and its captions. A link to the file still opens it, and whoever may watch a video can still save it.');
		$fs->add($f);

		/** @var InputfieldTextarea $f */
		$f = $this->wire()->modules->get('InputfieldTextarea');
		$f->attr('name', 'embedHosts');
		$f->label = $this->_('Websites that may embed blocked videos all the same');
		$f->description = $this->_('One per line, such as partner.example. Each also stands for its subdomains.');
		$f->attr('rows', 3);
		$f->attr('value', implode("\n", SelfHostedVideoEmbed::parseHosts((string) $this->get('embedHosts'))));
		$server = $this->embedRules()->serverName();
		$f->notes = $server === 'apache' || $server === 'litespeed'
			? $this->_('Videos the web server sends get the rules in a file .htaccess in the folder of their page, written by the module. Protected videos are sent by the module, which applies the rules itself.')
			: $this->_('Protected videos are sent by the module, which applies the rules itself. For other videos the web server has to: Apache reads the rules the module writes into the folders of the pages; nginx and others need a rule in their configuration, see “Embedding on other websites” in the README. The checklist of a video says whether it is blocked.');
		$f->collapsed = Inputfield::collapsedBlank;
		$fs->add($f);
	}
}
