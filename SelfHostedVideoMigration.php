<?php namespace ProcessWire;

/**
 * Move a site from the module under its old name, FieldtypeVideoFile, to FieldtypeSelfHostedVideo
 *
 * Runs once, when FieldtypeSelfHostedVideo is installed on a site that had FieldtypeVideoFile
 * (its folder already deleted, the module not uninstalled). Nothing of the videos moves: the
 * files, the tables of the fields and the data of each video stay where they are. What carries
 * the old names is changed:
 *
 * - the type of the video fields, their input and the text formatter of text fields
 *   (also where a template overrides the settings of a field)
 * - the button of the rich text editors (TinyMCE and CKEditor)
 * - the settings of the four modules
 * - the Video Manager under Setup: in its place again if it was there, otherwise left out
 * - the rules for embedding in the .htaccess files of pages
 * - the translations (files of each language)
 * - the folder of your own framework profiles in /site/templates/, and the log
 *
 * #pw-internal
 *
 */
class SelfHostedVideoMigration extends Wire {

	/**
	 * Old class names and their new ones, longest first
	 *
	 */
	const classNames = array(
		'FieldtypeVideoFile' => 'FieldtypeSelfHostedVideo',
		'InputfieldVideoFile' => 'InputfieldSelfHostedVideo',
		'TextformatterVideoFile' => 'TextformatterSelfHostedVideo',
		'ProcessVideoFiles' => 'ProcessSelfHostedVideos',
	);

	/**
	 * Names in the settings of fields: the classes, and the button and plugin of the rich text editors
	 *
	 */
	const settingNames = array(
		'FieldtypeVideoFile' => 'FieldtypeSelfHostedVideo',
		'InputfieldVideoFile' => 'InputfieldSelfHostedVideo',
		'TextformatterVideoFile' => 'TextformatterSelfHostedVideo',
		'ProcessVideoFiles' => 'ProcessSelfHostedVideos',
		'PWVideoFile' => 'PWSelfHostedVideo',
		'pwvideofile' => 'pwselfhostedvideo',
	);

	/**
	 * Old lines around the rules for embedding in .htaccess files, and the new ones
	 *
	 */
	const markers = array(
		'# BEGIN FieldtypeVideoFile: embedding on other websites' => SelfHostedVideoEmbed::markerStart,
		'# END FieldtypeVideoFile' => SelfHostedVideoEmbed::markerEnd,
	);

	/**
	 * @var FieldtypeSelfHostedVideo
	 *
	 */
	protected $fieldtype;

	/**
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 *
	 */
	public function __construct(FieldtypeSelfHostedVideo $fieldtype) {
		parent::__construct();
		$this->fieldtype = $fieldtype;
	}

	/**
	 * Path of the folder of the old module
	 *
	 * @return string
	 *
	 */
	public function oldFolderPath() {
		return $this->wire()->config->paths->siteModules . 'FieldtypeVideoFile/';
	}

	/**
	 * Is the old module still there (its folder, or its class loaded)?
	 *
	 * @return bool
	 *
	 */
	public function oldFolderExists() {
		return is_file($this->oldFolderPath() . 'FieldtypeVideoFile.module.php')
			|| class_exists(__NAMESPACE__ . '\\FieldtypeVideoFile', false);
	}

	/**
	 * Rows of the old modules in the modules table: [ class => [ 'id' => int, 'data' => array ] ]
	 *
	 * @return array
	 *
	 */
	public function oldModules() {
		$database = $this->wire()->database;
		$names = array_keys(self::classNames);
		$query = $database->prepare('SELECT id, class, data FROM modules WHERE class IN(' . implode(',', array_fill(0, count($names), '?')) . ')');
		$query->execute($names);
		$rows = array();
		while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
			$data = strlen((string) $row['data']) ? json_decode($row['data'], true) : array();
			$rows[$row['class']] = array('id' => (int) $row['id'], 'data' => is_array($data) ? $data : array());
		}
		$query->closeCursor();
		return $rows;
	}

	/**
	 * Does this site have anything of the old module?
	 *
	 * @return bool
	 *
	 */
	public function needed() {
		if(count($this->oldModules())) return true;
		$query = $this->wire()->database->prepare('SELECT COUNT(*) FROM fields WHERE type=:type');
		$query->bindValue(':type', 'FieldtypeVideoFile');
		$query->execute();
		$n = (int) $query->fetchColumn();
		$query->closeCursor();
		return $n > 0;
	}

	/**
	 * First step, before the parts of the new module are installed
	 *
	 * Takes the old Video Manager page out of Setup, so that the new one can take its place.
	 *
	 * @return array What the second step needs to know
	 *
	 */
	public function prepare() {
		$old = $this->oldModules();
		$state = array(
			'modules' => $old,
			'manager' => false,
		);
		if(isset($old['ProcessVideoFiles'])) {
			$state['manager'] = true;
			$query = $this->wire()->database->prepare('SELECT pages_id FROM field_process WHERE data=:id');
			$query->bindValue(':id', $old['ProcessVideoFiles']['id'], \PDO::PARAM_INT);
			$query->execute();
			$ids = $query->fetchAll(\PDO::FETCH_COLUMN);
			$query->closeCursor();
			foreach($ids as $id) $this->deletePage((int) $id);
		}
		// pages of the Video Manager that no module answers any more
		$this->fieldtype->removeOrphanedManagerPagesNow();
		return $state;
	}

	/**
	 * Second step, after the new module and its parts are installed
	 *
	 * @param array $state From prepare()
	 *
	 */
	public function finish(array $state) {

		$modules = $this->wire()->modules;
		$old = $state['modules'];

		// the settings of the four modules
		foreach(self::classNames as $oldName => $newName) {
			if(empty($old[$oldName]['data'])) continue;
			$data = $old[$oldName]['data'];
			if(!$modules->isInstalled($newName)) continue;
			if($newName === 'FieldtypeSelfHostedVideo') $data['videoManager'] = $state['manager'] ? 1 : 0;
			$modules->saveConfig($newName, array_merge($modules->getConfig($newName), $data));
		}
		if(empty($old['FieldtypeVideoFile']['data'])) {
			$modules->saveConfig('FieldtypeSelfHostedVideo', array_merge($modules->getConfig('FieldtypeSelfHostedVideo'), array('videoManager' => $state['manager'] ? 1 : 0)));
		}

		// the Video Manager stays out where it was out
		if(!$state['manager'] && count($old) && $modules->isInstalled('ProcessSelfHostedVideos')) {
			$modules->uninstall('ProcessSelfHostedVideos');
			$this->fieldtype->removeOrphanedManagerPagesNow();
		}

		$fields = $this->renameInFields();
		$this->renameModuleRows($old);
		$htaccess = $this->renameMarkers();
		$translations = $this->renameTranslations();
		$this->renameFiles();
		$this->wire()->cache->delete('videofile-embedcheck-*');

		$modules->resetCache();

		// the errors about the missing old module, from the start of this request, no longer apply
		$notices = $this->wire()->notices;
		foreach($notices->getArray() as $notice) {
			if(strpos((string) $notice->text, "'FieldtypeVideoFile'") !== false) $notices->remove($notice);
		}

		$this->message(sprintf(
			$this->_('Moved from FieldtypeVideoFile: %1$d video fields, %2$d settings of other fields, %3$d .htaccess files, %4$d translation files.'),
			$fields['video'], $fields['other'], $htaccess, $translations
		));
		foreach($fields['mov'] as $name) {
			$this->message(sprintf($this->_('The video field “%s” now also accepts MOV files, as iPhones record them.'), $name));
		}
	}

	/**
	 * Change the old names in the types and settings of all fields, and in the settings of fields for templates
	 *
	 * @return array [ 'video' => number of video fields, 'other' => number of other fields and template settings changed, 'mov' => names of video fields that now also accept MOV ]
	 *
	 */
	protected function renameInFields() {
		$database = $this->wire()->database;
		$result = array('video' => 0, 'other' => 0, 'mov' => array());

		$query = $database->prepare('UPDATE fields SET type=:new WHERE type=:old');
		$query->bindValue(':new', 'FieldtypeSelfHostedVideo');
		$query->bindValue(':old', 'FieldtypeVideoFile');
		$query->execute();
		$result['video'] = $query->rowCount();

		foreach(array('fields' => 'id', 'fieldgroups_fields' => 'fieldgroups_id') as $table => $key) {
			$where = $table === 'fields' ? 'id' : 'fieldgroups_id, fields_id';
			$query = $database->prepare("SELECT $where, data FROM $table WHERE data LIKE :a OR data LIKE :b OR data LIKE :c");
			$query->bindValue(':a', '%VideoFile%');
			$query->bindValue(':b', '%videofile%');
			$query->bindValue(':c', '%ProcessVideoFiles%');
			$query->execute();
			$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
			$query->closeCursor();
			foreach($rows as $row) {
				$data = json_decode((string) $row['data'], true);
				if(!is_array($data)) continue;
				$changed = $this->renameIn($data);
				if($changed === $data) continue;
				if($table === 'fields') {
					$update = $database->prepare('UPDATE fields SET data=:data WHERE id=:id');
					$update->bindValue(':id', (int) $row['id'], \PDO::PARAM_INT);
				} else {
					$update = $database->prepare('UPDATE fieldgroups_fields SET data=:data WHERE fieldgroups_id=:g AND fields_id=:f');
					$update->bindValue(':g', (int) $row['fieldgroups_id'], \PDO::PARAM_INT);
					$update->bindValue(':f', (int) $row['fields_id'], \PDO::PARAM_INT);
				}
				$update->bindValue(':data', json_encode($changed));
				$update->execute();
				$result['other']++;
			}
		}
		// what the upgrade to 0.34.0 did for fields that still had the old default: MOV, as phones record it
		$query = $database->prepare('SELECT id, name, data FROM fields WHERE type=:type');
		$query->bindValue(':type', 'FieldtypeSelfHostedVideo');
		$query->execute();
		$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
		$query->closeCursor();
		foreach($rows as $row) {
			$data = json_decode((string) $row['data'], true);
			if(!is_array($data) || !isset($data['extensions'])) continue;
			$extensions = preg_split('/[\s,]+/', strtolower(trim((string) $data['extensions'])), -1, PREG_SPLIT_NO_EMPTY);
			sort($extensions);
			if($extensions !== array('mp4', 'webm')) continue;
			$data['extensions'] = 'mp4 webm mov';
			$update = $database->prepare('UPDATE fields SET data=:data WHERE id=:id');
			$update->bindValue(':id', (int) $row['id'], \PDO::PARAM_INT);
			$update->bindValue(':data', json_encode($data));
			$update->execute();
			$result['mov'][] = $row['name'];
		}

		return $result;
	}

	/**
	 * Change the old names in the strings of a value (keys too)
	 *
	 * @param mixed $value
	 * @return mixed
	 *
	 */
	protected function renameIn($value) {
		if(is_string($value)) return strtr($value, self::settingNames);
		if(!is_array($value)) return $value;
		$out = array();
		foreach($value as $k => $v) $out[is_string($k) ? strtr($k, self::settingNames) : $k] = $this->renameIn($v);
		return $out;
	}

	/**
	 * Remove the rows of the old modules (their settings were taken over)
	 *
	 * @param array $old
	 *
	 */
	protected function renameModuleRows(array $old) {
		$database = $this->wire()->database;
		foreach($old as $class => $row) {
			$query = $database->prepare('DELETE FROM modules WHERE id=:id AND class=:class');
			$query->bindValue(':id', $row['id'], \PDO::PARAM_INT);
			$query->bindValue(':class', $class);
			$query->execute();
		}
		// what the core remembers about modules that are gone
		$query = $database->prepare("DELETE FROM caches WHERE name LIKE 'Modules.%' OR name LIKE 'ModulesVerbose.%' OR name LIKE 'ModulesUninstalled.%'");
		try {
			$query->execute();
		} catch(\Exception $e) {
			// older cores have other names for it; resetCache() covers them
		}
	}

	/**
	 * Give the rules for embedding in the .htaccess files of pages the new marker lines
	 *
	 * @return int Number of files changed
	 *
	 */
	protected function renameMarkers() {
		$root = $this->wire()->config->paths->files;
		if(!is_dir($root)) return 0;
		$n = 0;
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $file) {
			if($file->getFilename() !== '.htaccess') continue;
			$path = $file->getPathname();
			$content = (string) @file_get_contents($path);
			if(strpos($content, '# BEGIN FieldtypeVideoFile') === false) continue;
			if(@file_put_contents($path, strtr($content, self::markers), LOCK_EX) !== false) $n++;
		}
		return $n;
	}

	/**
	 * Move the translations of the module to the new names of its files
	 *
	 * The translations are kept: the texts are the same, only the names of the files changed.
	 *
	 * @return int Number of translation files moved
	 *
	 */
	protected function renameTranslations() {
		$languages = $this->wire()->languages;
		if(!$languages) return 0;
		$files = $this->wire()->files;
		$tmpDir = $this->wire()->config->paths->cache . 'SelfHostedVideoMigration/';
		$n = 0;
		foreach($languages as $language) {
			$changed = false;
			$language->of(false);
			foreach(array('language_files_site', 'language_files') as $fieldName) {
				if(!$language->template->hasField($fieldName)) continue;
				$pagefiles = $language->get($fieldName);
				if(!$pagefiles instanceof Pagefiles) continue;
				foreach($pagefiles->getArray() as $pagefile) {
					$basename = $pagefile->basename;
					if(strpos($basename, 'site--modules--fieldtypevideofile--') !== 0) continue;
					$newName = self::newTextdomain($basename);
					$json = json_decode((string) @file_get_contents($pagefile->filename), true);
					if(is_array($json)) {
						if(isset($json['file'])) $json['file'] = self::newPath($json['file']);
						if(isset($json['textdomain'])) $json['textdomain'] = self::newTextdomain($json['textdomain']);
						if(!$pagefiles->get($newName)) {
							if(!is_dir($tmpDir)) $files->mkdir($tmpDir, true);
							$tmp = $tmpDir . $newName;
							file_put_contents($tmp, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
							$pagefiles->add($tmp);
							$files->unlink($tmp);
						}
						$n++;
					}
					$pagefiles->delete($pagefile);
					$changed = true;
				}
			}
			if($changed) $language->save();
		}
		if(is_dir($tmpDir)) $files->rmdir($tmpDir, true);
		return $n;
	}

	/**
	 * New name of a translation file (or text domain)
	 *
	 * @param string $name
	 * @return string
	 *
	 */
	public static function newTextdomain($name) {
		return str_replace(array('processvideofiles', 'videofile'), array('processselfhostedvideos', 'selfhostedvideo'), $name);
	}

	/**
	 * New path of a file of the module
	 *
	 * @param string $path
	 * @return string
	 *
	 */
	public static function newPath($path) {
		return str_replace(array('ProcessVideoFiles', 'VideoFile'), array('ProcessSelfHostedVideos', 'SelfHostedVideo'), $path);
	}

	/**
	 * The folder of your own framework profiles and the log file get the new names
	 *
	 */
	protected function renameFiles() {
		$config = $this->wire()->config;
		$old = $config->paths->templates . 'videofile/';
		$new = $config->paths->templates . 'selfhostedvideo/';
		if(is_dir($old . 'frameworks') && !is_dir($new)) {
			if(@rename($old, $new)) {
				$this->message(sprintf($this->_('Your framework profiles were moved to %s.'), $config->urls->templates . 'selfhostedvideo/frameworks/'));
			} else {
				$this->warning(sprintf($this->_('Move the folder %1$s to %2$s: framework profiles of your own are now found there.'), $config->urls->templates . 'videofile/', $config->urls->templates . 'selfhostedvideo/'));
			}
		}
		$log = $config->paths->logs . 'videofile.txt';
		$newLog = $config->paths->logs . 'selfhostedvideo.txt';
		if(is_file($log) && !is_file($newLog)) @rename($log, $newLog);
	}

	/**
	 * Delete a page of the old Video Manager, which is a system page
	 *
	 * @param int $id
	 *
	 */
	protected function deletePage($id) {
		$pages = $this->wire()->pages;
		$page = $pages->get($id);
		if(!$page->id) return;
		$page->addStatus(Page::statusSystemOverride);
		$page->removeStatus(Page::statusSystem);
		$page->removeStatus(Page::statusSystemID);
		$page->save();
		$pages->delete($page, true);
	}
}
