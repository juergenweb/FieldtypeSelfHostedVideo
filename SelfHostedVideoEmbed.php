<?php namespace ProcessWire;

/**
 * Embedding on other websites: who may show the files of a video in their pages
 *
 * Another website can put a video of this site into its own pages, with the address of the
 * file (`<video src="https://this-site/site/assets/files/…/intro.mp4">`): visitors of that site
 * watch it at the cost of this one. Whether that is allowed is a setting of the module, which each
 * video can change for itself. Blocked means:
 *
 * - A browser that asks for a file of the video for a page of another website gets "403 Forbidden".
 *   Browsers say where a request comes from (`Sec-Fetch-Site`, and what for: `Sec-Fetch-Dest`);
 *   older ones only send the address of the page (`Referer`).
 * - The files are sent with `Cross-Origin-Resource-Policy: same-site`, so that browsers do not show
 *   them in pages of other websites either (left out while other websites are allowed by name, as
 *   that header knows no exceptions).
 *
 * Not blocked: the website itself and its subdomains, the websites allowed by name in the module
 * settings, opening the address of a file directly (a link to it), and programs that are no browsers
 * and send none of the above. Whoever may watch a video can still save it.
 *
 * Protected videos are sent by the module, which applies the rules itself. Other videos are sent by
 * the web server: for Apache (and servers that read `.htaccess` the same way) the module writes the
 * rules into a `.htaccess` file in the folder of each page that has blocked videos. Other web servers
 * need a rule in their configuration (see "Embedding on other websites" in the README).
 *
 * ProcessWire 3.x, Copyright 2026
 * https://processwire.com
 *
 */
class SelfHostedVideoEmbed extends Wire {

	/**
	 * The lines this module writes into a .htaccess file start and end with these
	 *
	 */
	const markerStart = '# BEGIN FieldtypeSelfHostedVideo: embedding on other websites';
	const markerEnd = '# END FieldtypeSelfHostedVideo';

	/**
	 * How long the result of a check of the web server is kept, in seconds
	 *
	 */
	const checkLifetime = 86400;

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

	/*** WHAT IS SET ****************************************************************************/

	/**
	 * Does the site allow embedding, unless a video says otherwise?
	 *
	 * @return bool
	 *
	 */
	public function siteAllows() {
		return (int) $this->fieldtype->get('embed') !== 0;
	}

	/**
	 * The websites that may embed blocked videos all the same
	 *
	 * Written in the settings one per line (or separated by spaces or commas), with or without
	 * "https://" and "www."; each also stands for its subdomains.
	 *
	 * @return array Host names, lower case
	 *
	 */
	public function allowedHosts() {
		return self::parseHosts((string) $this->fieldtype->get('embedHosts'));
	}

	/**
	 * Read a list of websites into host names
	 *
	 * @param string $text
	 * @return array
	 *
	 */
	public static function parseHosts($text) {
		$hosts = array();
		foreach(preg_split('/[\s,;]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $item) {
			$item = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $item);
			$item = preg_replace('#[/?\#].*$#', '', $item);
			$item = preg_replace('#:\d+$#', '', $item);
			$item = preg_replace('#^(\*\.|www\.)#', '', $item);
			$item = trim($item, '.');
			if(!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $item)) continue;
			$hosts[$item] = $item;
		}
		return array_values($hosts);
	}

	/**
	 * Is embedding of this video blocked, after its own setting and that of the site?
	 *
	 * @param Pagevideo $video
	 * @return bool
	 *
	 */
	public function isBlocked(Pagevideo $video) {
		$own = $video->embed();
		if($own === 'block') return true;
		if($own === 'allow') return false;
		return !$this->siteAllows();
	}

	/*** REQUESTS (protected videos, which the module sends) *************************************/

	/**
	 * May the current request have a file of a video whose embedding is blocked?
	 *
	 * @param array|null $server Request headers as in $_SERVER (for tests), or null for the current request
	 * @return bool
	 *
	 */
	public function requestAllowed($server = null) {
		if($server === null) $server = $_SERVER;
		$config = $this->wire()->config;
		$own = array_merge(array((string) $config->httpHost), (array) $config->httpHosts);
		return self::decide($server, self::parseHosts(implode(' ', $own)), $this->allowedHosts());
	}

	/**
	 * Decide about one request
	 *
	 * @param array $server Request headers as in $_SERVER
	 * @param array $ownHosts Host names of this website
	 * @param array $allowedHosts Host names of websites allowed by name
	 * @return bool
	 *
	 */
	public static function decide(array $server, array $ownHosts, array $allowedHosts) {

		$site = strtolower(trim(isset($server['HTTP_SEC_FETCH_SITE']) ? (string) $server['HTTP_SEC_FETCH_SITE'] : ''));
		$dest = strtolower(trim(isset($server['HTTP_SEC_FETCH_DEST']) ? (string) $server['HTTP_SEC_FETCH_DEST'] : ''));
		$from = isset($server['HTTP_ORIGIN']) && strlen((string) $server['HTTP_ORIGIN']) && $server['HTTP_ORIGIN'] !== 'null' ? (string) $server['HTTP_ORIGIN'] : '';
		if($from === '' && isset($server['HTTP_REFERER'])) $from = (string) $server['HTTP_REFERER'];
		$host = strtolower((string) parse_url($from, PHP_URL_HOST));

		if($site !== '') {
			// "same-origin", "same-site" (a subdomain) and "none" (typed in, a bookmark)
			if($site !== 'cross-site') return true;
			// a link to the file, opened by the visitor: that is no embedding
			if($dest === 'document') return true;
			return $host !== '' && self::hostListed($host, $allowedHosts);
		}

		// older browsers say where a request comes from only by the address of the page
		if($host === '') return true;
		return self::hostListed($host, $ownHosts) || self::hostListed($host, $allowedHosts);
	}

	/**
	 * Is the host one of the list, or a subdomain of one?
	 *
	 * @param string $host
	 * @param array $list
	 * @return bool
	 *
	 */
	public static function hostListed($host, array $list) {
		foreach($list as $item) {
			if($host === $item) return true;
			$tail = '.' . $item;
			if(strlen($host) > strlen($tail) && substr($host, -strlen($tail)) === $tail) return true;
		}
		return false;
	}

	/**
	 * Headers for the files of a blocked video
	 *
	 * @return array
	 *
	 */
	public function headers() {
		$headers = array('Vary' => 'Sec-Fetch-Site, Sec-Fetch-Dest, Referer');
		if(!count($this->allowedHosts())) $headers['Cross-Origin-Resource-Policy'] = 'same-site';
		return $headers;
	}

	/*** .HTACCESS (videos the web server sends) ************************************************/

	/**
	 * Get the rules for the folder of a page, for its videos that are blocked
	 *
	 * @param Page $page
	 * @return string Blank when no video of the page is blocked
	 *
	 */
	public function rulesFor(Page $page) {

		$names = array();
		foreach($this->videosOf($page) as $video) {
			if(!$this->isBlocked($video)) continue;
			foreach($video->filePatterns() as $pattern) $names[] = $pattern;
		}
		if(!count($names)) return '';

		$files = implode('|', array_unique($names));
		$allowed = $this->allowedHosts();
		$hostsPattern = function(array $hosts) {
			return implode('|', array_map(function($host) { return preg_quote($host, '#'); }, $hosts));
		};

		// the request is for one of these files, and comes from a page of another website
		$expr =
			"%{REQUEST_URI} =~ m#/(?:$files)$#" .
			" && ((%{HTTP:Sec-Fetch-Site} == 'cross-site' && %{HTTP:Sec-Fetch-Dest} != 'document')" .
			" || (%{HTTP:Sec-Fetch-Site} == '' && %{HTTP_REFERER} =~ m#^https?://# && !(%{HTTP_REFERER} -strmatch '*://%{HTTP_HOST}/*' || %{HTTP_REFERER} -strmatch '*.%{HTTP_HOST}/*')))";
		// unless it is a website allowed by name
		if(count($allowed)) $expr .= " && !(%{HTTP_REFERER} =~ m#^https?://(?:[^/@]+\.)?(?:" . $hostsPattern($allowed) . ")(?::\d+)?(?:/|$)#i)";

		$lines = array(
			self::markerStart,
			'# Written by the module from its settings and those of the videos of this page. Changes are lost.',
			'<IfModule mod_authz_core.c>',
			"  <If \"$expr\">",
			'    Require all denied',
			'  </If>',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			"  <If \"%{REQUEST_URI} =~ m#/(?:$files)$#\">",
			'    Header merge Vary "Sec-Fetch-Site, Sec-Fetch-Dest, Referer"',
		);
		if(!count($allowed)) $lines[] = '    Header always set Cross-Origin-Resource-Policy "same-site"';
		$lines[] = '  </If>';
		$lines[] = '</IfModule>';
		$lines[] = self::markerEnd;

		return implode("\n", $lines) . "\n";
	}

	/**
	 * Write the rules for a page into the .htaccess file of its folder (or take them out)
	 *
	 * Lines that others put into that file are kept. A file that holds nothing but the rules of
	 * this module is removed once they are no longer needed.
	 *
	 * @param Page $page
	 * @return bool|null True if the file was changed, false if it could not be, null if nothing had to change
	 *
	 */
	public function writeFor(Page $page) {

		if(!$page->id || !$page->template) return null;
		if(!$this->hasVideoField($page)) return null;

		$rules = $this->rulesFor($page);
		$path = $page->filesManager()->path(); // the folder in use: the one with a hyphen for protected files
		$file = $path . '.htaccess';

		$current = is_file($file) ? (string) @file_get_contents($file) : '';
		$others = self::withoutRules($current);
		$content = strlen($rules) ? (strlen(trim($others)) ? rtrim($others) . "\n\n" : '') . $rules : $others;

		if($content === $current) return null;

		if(!strlen(trim($content))) {
			return is_file($file) ? $this->wire()->files->unlink($file) : null;
		}

		if(!is_dir($path) && !$this->wire()->files->mkdir($path, true)) return false;
		$tmp = $file . '.' . getmypid() . '.tmp';
		if(@file_put_contents($tmp, $content, LOCK_EX) === false) return false;
		$this->wire()->files->chmod($tmp);
		if(!@rename($tmp, $file)) {
			@unlink($tmp);
			return false;
		}
		return true;
	}

	/**
	 * Write the rules for every page that has videos, as after a change of the settings
	 *
	 * @return int Number of folders changed
	 *
	 */
	public function writeAll() {
		$n = 0;
		$pages = $this->wire()->pages;
		$done = array();
		foreach($this->wire()->fields->find('type=' . $this->fieldtype->className()) as $field) {
			foreach($pages->findIDs("$field->name.count>0, include=all") as $id) {
				if(isset($done[$id])) continue;
				$done[$id] = true;
				$page = $pages->get((int) $id);
				if($this->writeFor($page)) $n++;
				$pages->uncache($page);
			}
		}
		return $n;
	}

	/**
	 * Take the lines of this module out of the content of a .htaccess file
	 *
	 * @param string $content
	 * @return string
	 *
	 */
	public static function withoutRules($content) {
		// also the lines written under the old name of the module, FieldtypeVideoFile
		$markers = array(
			self::markerStart => self::markerEnd,
			'# BEGIN FieldtypeVideoFile: embedding on other websites' => '# END FieldtypeVideoFile',
		);
		$found = false;
		foreach($markers as $markerStart => $markerEnd) {
			$start = strpos($content, $markerStart);
			if($start === false) continue;
			$found = true;
			$end = strpos($content, $markerEnd, $start);
			$end = $end === false ? strlen($content) : $end + strlen($markerEnd);
			$content = rtrim(substr($content, 0, $start)) . "\n" . ltrim(substr($content, $end));
		}
		if(!$found) return $content;
		return trim($content) === '' ? '' : trim($content) . "\n";
	}

	/**
	 * Does the .htaccess file of the page’s folder hold the rules for this video?
	 *
	 * @param Pagevideo $video
	 * @return bool
	 *
	 */
	public function hasRules(Pagevideo $video) {
		if(!$video->page || !$video->page->id) return false;
		$file = $video->page->filesManager()->path() . '.htaccess';
		$content = is_file($file) ? (string) @file_get_contents($file) : '';
		$patterns = $video->filePatterns();
		return strpos($content, self::markerStart) !== false && strpos($content, $patterns[0]) !== false;
	}

	/*** IS IT IN FORCE? *************************************************************************/

	/**
	 * Does the web server keep other websites from embedding this video? Asks it, see known()
	 *
	 * For a video the web server sends, the module asks for its file the way a page of another
	 * website would, and looks at the answer. The answer is kept for a day (an hour when it could
	 * not be told), and asked anew when the rules or the file change.
	 *
	 * @param Pagevideo $video The original video
	 * @return bool|null True if refused, false if sent all the same, null if it could not be told
	 *
	 */
	public function serverBlocks(Pagevideo $video) {
		if($video->isProtected()) return true; // the module sends these files itself
		$known = $this->known($video);
		if($known !== '') return $known === 'yes' ? true : ($known === 'no' ? false : null);
		$url = $video->httpUrl();
		$result = strlen($url) ? $this->askServer($url) : null;
		$this->wire()->cache->save($this->checkKey($video), $result === null ? 'unknown' : ($result ? 'yes' : 'no'), $result === null ? 3600 : self::checkLifetime);
		return $result;
	}

	/**
	 * What the last check of the web server found for this video, without asking it again
	 *
	 * @param Pagevideo $video The original video
	 * @return string "yes" (refused), "no" (sent all the same), "unknown" (could not be told) or blank (not asked yet)
	 *
	 */
	public function known(Pagevideo $video) {
		$stored = $this->wire()->cache->get($this->checkKey($video));
		return in_array($stored, array('yes', 'no', 'unknown'), true) ? $stored : '';
	}

	/**
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function checkKey(Pagevideo $video) {
		$file = $video->page ? $video->page->filesManager()->path() . '.htaccess' : '';
		clearstatcache();
		$rules = $file && is_file($file) ? filemtime($file) . '-' . filesize($file) : '';
		return 'shv-embedcheck-' . md5($video->httpUrl() . '|' . $rules . '|' . @filemtime($video->filename()) . '|' . implode(',', $this->allowedHosts()));
	}

	/**
	 * Ask the web server for one byte of a file, as a page of another website would
	 *
	 * @param string $url
	 * @return bool|null
	 *
	 */
	protected function askServer($url) {
		if(!function_exists('curl_init')) return null;
		$curl = curl_init($url);
		curl_setopt_array($curl, array(
			CURLOPT_NOBODY => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 4,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_HTTPHEADER => array(
				'Range: bytes=0-0',
				'Sec-Fetch-Site: cross-site',
				'Sec-Fetch-Dest: video',
				'Sec-Fetch-Mode: no-cors',
				'Referer: https://embedding-check.invalid/',
			),
		));
		curl_exec($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if($status === 403) return true;
		if($status === 200 || $status === 206) return false;
		return null;
	}

	/**
	 * What web server is this? Lower case name, blank if unknown
	 *
	 * @return string
	 *
	 */
	public function serverName() {
		$software = strtolower(isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '');
		foreach(array('apache', 'litespeed', 'nginx', 'iis', 'caddy') as $name) {
			if(strpos($software, $name) !== false) return $name;
		}
		return '';
	}

	/*** HELPERS ********************************************************************************/

	/**
	 * Get the videos of a page that have files of their own
	 *
	 * @param Page $page
	 * @return array of Pagevideo
	 *
	 */
	protected function videosOf(Page $page) {
		$videos = array();
		foreach($page->template->fieldgroup as $field) {
			if(!$field->type instanceof FieldtypeSelfHostedVideo) continue;
			$value = $page->getUnformatted($field->name);
			if(!$value instanceof Pagefiles) continue;
			foreach($value as $video) {
				if($video instanceof Pagevideo && !$video->isReference()) $videos[] = $video;
			}
		}
		return $videos;
	}

	/**
	 * @param Page $page
	 * @return bool
	 *
	 */
	protected function hasVideoField(Page $page) {
		foreach($page->template->fieldgroup as $field) {
			if($field->type instanceof FieldtypeSelfHostedVideo) return true;
		}
		return false;
	}
}
