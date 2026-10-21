<?php namespace ProcessWire;

/**
 * How often videos are watched: anonymous counts per video and day
 *
 * The script of the website reports when a video starts (once per page view) and when a quarter,
 * half, three quarters and all of it have been played. The module adds these up per video and day
 * in its own table. Nothing about the visitor is kept: no address, no cookie of the module, no
 * identifier. Plays of people who may edit the page, and of programs that say they are robots,
 * are not counted. A reused video counts for its original.
 *
 * ~~~~~
 * $stats = $video->stats(30); // the last 30 days
 * echo "$stats[plays] plays, $stats[half]% watched to the half, $stats[end]% to the end";
 * ~~~~~
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class SelfHostedVideoStats extends Wire {

	/**
	 * Address the script reports to, from the root of the site
	 *
	 */
	const path = 'selfhostedvideo-stats';

	/**
	 * Name of the table
	 *
	 */
	const table = 'selfhostedvideo_stats';

	/**
	 * Days the counts are kept
	 *
	 */
	const keepDays = 730;

	/**
	 * What the script reports: name => column
	 *
	 */
	protected static $events = array('play' => 'plays', '25' => 'p25', '50' => 'p50', '75' => 'p75', '100' => 'p100');

	/**
	 * @var FieldtypeSelfHostedVideo
	 *
	 */
	protected $fieldtype;

	/**
	 * @var bool|null
	 *
	 */
	protected $tableExists = null;

	/**
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 *
	 */
	public function __construct(FieldtypeSelfHostedVideo $fieldtype) {
		parent::__construct();
		$this->fieldtype = $fieldtype;
	}

	/**
	 * Create the table, if it is not there
	 *
	 */
	public function install() {
		$config = $this->wire()->config;
		$this->wire()->database->exec(
			"CREATE TABLE IF NOT EXISTS `" . self::table . "` (" .
				"video_pages_id INT UNSIGNED NOT NULL, " .
				"video_name VARCHAR(250) NOT NULL, " .
				"day DATE NOT NULL, " .
				"plays INT UNSIGNED NOT NULL DEFAULT 0, " .
				"p25 INT UNSIGNED NOT NULL DEFAULT 0, " .
				"p50 INT UNSIGNED NOT NULL DEFAULT 0, " .
				"p75 INT UNSIGNED NOT NULL DEFAULT 0, " .
				"p100 INT UNSIGNED NOT NULL DEFAULT 0, " .
				"PRIMARY KEY (video_pages_id, video_name(191), day), " .
				"INDEX day (day)" .
			") ENGINE=$config->dbEngine DEFAULT CHARSET=$config->dbCharset"
		);
		$this->tableExists = true;
	}

	/**
	 * Remove the table
	 *
	 */
	public function uninstall() {
		$this->wire()->database->exec("DROP TABLE IF EXISTS `" . self::table . "`");
		$this->tableExists = false;
	}

	/**
	 * Is the table there?
	 *
	 * @return bool
	 *
	 */
	public function tableExists() {
		if($this->tableExists === null) {
			try {
				$this->tableExists = $this->wire()->database->tableExists(self::table);
			} catch(\Exception $e) {
				$this->tableExists = false;
			}
		}
		return $this->tableExists;
	}

	/**
	 * Is the one asking a program that says it is a robot?
	 *
	 * @param string $agent User agent
	 * @return bool
	 *
	 */
	public static function isRobot($agent) {
		$agent = (string) $agent;
		if($agent === '') return true;
		return (bool) preg_match('/bot\b|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|curl|wget|python|httpclient|java\//i', $agent);
	}

	/**
	 * Count what the script of the website reported
	 *
	 * @param string $video "page ID:file name" of the original video, as in data-shv-video
	 * @param string $event "play", "25", "50", "75" or "100"
	 * @return bool Whether it was counted
	 *
	 */
	public function record($video, $event) {

		$event = (string) $event;
		if(!isset(self::$events[$event])) return false;
		if(!preg_match('/^(\d+):(.{1,250})$/s', (string) $video, $m)) return false;
		$pageId = (int) $m[1];
		$name = (string) $m[2];

		if(self::isRobot(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '')) return false;

		// a video of this site, on a page the one watching may view
		$original = $this->fieldtype->getOriginal($pageId, $name);
		if(!$original instanceof Pagevideo) return false;
		$page = $this->wire()->pages->get($pageId);
		if(!$page->id) return false;
		$user = $this->wire()->user;
		// those who look after the page are not its audience
		if($user->isLoggedin() && $page->editable()) return false;
		if(!$page->viewable(false) && !$this->viewableAsReuse($page, $name)) return false;

		if(!$this->tableExists()) $this->install();
		$column = self::$events[$event];
		$query = $this->wire()->database->prepare(
			"INSERT INTO `" . self::table . "` (video_pages_id, video_name, day, $column) VALUES (:p, :n, :d, 1) " .
			"ON DUPLICATE KEY UPDATE $column=$column+1"
		);
		$query->execute(array(':p' => $pageId, ':n' => $name, ':d' => date('Y-m-d')));

		// now and then, the counts too old to keep go
		if(mt_rand(1, 500) === 1) $this->purge();

		return true;
	}

	/**
	 * May the one asking see a video of a page they cannot view, because a page they can view reuses it?
	 *
	 * @param Page $page Page of the original
	 * @param string $name File name of the original
	 * @return bool
	 *
	 */
	protected function viewableAsReuse(Page $page, $name) {
		foreach($this->fieldtype->findReferences($page, $name) as $row) {
			$reuser = $this->wire()->pages->get((int) $row['pages_id']);
			if($reuser->id && $reuser->viewable(false)) return true;
		}
		return false;
	}

	/**
	 * Remove counts older than keepDays
	 *
	 */
	public function purge() {
		if(!$this->tableExists()) return;
		$query = $this->wire()->database->prepare("DELETE FROM `" . self::table . "` WHERE day < :d");
		$query->execute(array(':d' => date('Y-m-d', time() - self::keepDays * 86400)));
	}

	/**
	 * Forget the counts of a video, or of all videos of a page
	 *
	 * @param int $pageId
	 * @param string $name Blank for all videos of the page
	 *
	 */
	public function remove($pageId, $name = '') {
		if(!$this->tableExists()) return;
		$sql = "DELETE FROM `" . self::table . "` WHERE video_pages_id=:p";
		$bind = array(':p' => (int) $pageId);
		if(strlen($name)) {
			$sql .= " AND video_name=:n";
			$bind[':n'] = $name;
		}
		$query = $this->wire()->database->prepare($sql);
		$query->execute($bind);
	}

	/**
	 * Let the counts of a video belong to another one (after it was renamed, or merged with a copy)
	 *
	 * Counts of the same day are added up.
	 *
	 * @param int $fromPageId
	 * @param string $fromName
	 * @param int $toPageId
	 * @param string $toName
	 *
	 */
	public function move($fromPageId, $fromName, $toPageId, $toName) {
		if(!$this->tableExists()) return;
		if((int) $fromPageId === (int) $toPageId && (string) $fromName === (string) $toName) return;
		$database = $this->wire()->database;
		$table = self::table;
		$bind = array(':fp' => (int) $fromPageId, ':fn' => (string) $fromName);
		$query = $database->prepare("SELECT day, plays, p25, p50, p75, p100 FROM `$table` WHERE video_pages_id=:fp AND video_name=:fn");
		$query->execute($bind);
		$rows = $query->fetchAll(\PDO::FETCH_ASSOC);
		if(!count($rows)) return;
		$insert = $database->prepare(
			"INSERT INTO `$table` (video_pages_id, video_name, day, plays, p25, p50, p75, p100) VALUES (:p, :n, :d, :a, :b, :c, :e, :f) " .
			"ON DUPLICATE KEY UPDATE plays=plays+VALUES(plays), p25=p25+VALUES(p25), p50=p50+VALUES(p50), p75=p75+VALUES(p75), p100=p100+VALUES(p100)"
		);
		foreach($rows as $row) {
			$insert->execute(array(
				':p' => (int) $toPageId, ':n' => (string) $toName, ':d' => $row['day'],
				':a' => (int) $row['plays'], ':b' => (int) $row['p25'], ':c' => (int) $row['p50'], ':e' => (int) $row['p75'], ':f' => (int) $row['p100'],
			));
		}
		$query = $database->prepare("DELETE FROM `$table` WHERE video_pages_id=:fp AND video_name=:fn");
		$query->execute($bind);
	}

	/**
	 * Get the counts of a video
	 *
	 * @param Pagevideo $video An original, or a reuse (then the counts of its original)
	 * @param int $days The last so many days, 0 for all that are kept
	 * @return array
	 *  - `plays` (int): How often it was started (once per page view).
	 *  - `quarter`, `half`, `threeQuarters`, `end` (int): Percent of the plays that got that far (0–100).
	 *  - `counts` (array): The numbers behind them: plays, p25, p50, p75, p100.
	 *  - `days` (int): As asked.
	 *
	 */
	public function forVideo(Pagevideo $video, $days = 30) {

		$counts = array('plays' => 0, 'p25' => 0, 'p50' => 0, 'p75' => 0, 'p100' => 0);
		$out = array('plays' => 0, 'quarter' => 0, 'half' => 0, 'threeQuarters' => 0, 'end' => 0, 'counts' => $counts, 'days' => (int) $days);
		if(!$this->tableExists()) return $out;

		if($video->isReference()) {
			$pageId = (int) $video->get('ref_pages_id');
			$name = (string) $video->get('ref_name');
		} else {
			$pageId = $video->page ? (int) $video->page->id : 0;
			$name = $video->basename();
		}
		if(!$pageId || !strlen($name)) return $out;

		$sql =
			"SELECT SUM(plays) AS plays, SUM(p25) AS p25, SUM(p50) AS p50, SUM(p75) AS p75, SUM(p100) AS p100 " .
			"FROM `" . self::table . "` WHERE video_pages_id=:p AND video_name=:n";
		$bind = array(':p' => $pageId, ':n' => $name);
		if($days > 0) {
			$sql .= " AND day >= :d";
			$bind[':d'] = date('Y-m-d', time() - ((int) $days - 1) * 86400);
		}
		$query = $this->wire()->database->prepare($sql);
		$query->execute($bind);
		$row = $query->fetch(\PDO::FETCH_ASSOC);
		$query->closeCursor();
		if(!$row) return $out;

		foreach($counts as $key => $n) $counts[$key] = (int) $row[$key];
		$plays = $counts['plays'];
		$percent = function($n) use($plays) { return $plays > 0 ? (int) min(100, round($n * 100 / $plays)) : 0; };

		return array(
			'plays' => $plays,
			'quarter' => $percent($counts['p25']),
			'half' => $percent($counts['p50']),
			'threeQuarters' => $percent($counts['p75']),
			'end' => $percent($counts['p100']),
			'counts' => $counts,
			'days' => (int) $days,
		);
	}

	/**
	 * Get the counts of a video per day
	 *
	 * @param Pagevideo $video An original, or a reuse (then the counts of its original)
	 * @param int $days The last so many days, today included
	 * @return array [ "Y-m-d" => [ plays, p25, p50, p75, p100 ] ] for every day, oldest first (zeros where nothing was counted)
	 *
	 */
	public function daily(Pagevideo $video, $days = 30) {
		$days = max(1, (int) $days);
		$out = array();
		$zero = array('plays' => 0, 'p25' => 0, 'p50' => 0, 'p75' => 0, 'p100' => 0);
		$today = strtotime(date('Y-m-d'));
		for($n = $days - 1; $n >= 0; $n--) $out[date('Y-m-d', strtotime("-$n days", $today))] = $zero;
		if(!$this->tableExists()) return $out;
		list($pageId, $name) = $this->videoKey($video);
		if(!$pageId || !strlen($name)) return $out;
		$query = $this->wire()->database->prepare(
			"SELECT day, plays, p25, p50, p75, p100 FROM `" . self::table . "` WHERE video_pages_id=:p AND video_name=:n AND day >= :d"
		);
		$query->execute(array(':p' => $pageId, ':n' => $name, ':d' => key($out)));
		while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
			if(!isset($out[$row['day']])) continue;
			foreach($zero as $key => $n) $out[$row['day']][$key] = (int) $row[$key];
		}
		$query->closeCursor();
		return $out;
	}

	/**
	 * Get the page ID and file name the counts of a video are kept under (its original's)
	 *
	 * @param Pagevideo $video
	 * @return array [ page ID, file name ]
	 *
	 */
	public function videoKey(Pagevideo $video) {
		if($video->isReference()) return array((int) $video->get('ref_pages_id'), (string) $video->get('ref_name'));
		return array($video->page ? (int) $video->page->id : 0, $video->basename());
	}

	/**
	 * Get the day of the first count that is kept for a video
	 *
	 * @param Pagevideo $video
	 * @return string "Y-m-d", or blank when nothing was counted
	 *
	 */
	public function firstDay(Pagevideo $video) {
		if(!$this->tableExists()) return '';
		list($pageId, $name) = $this->videoKey($video);
		$query = $this->wire()->database->prepare("SELECT MIN(day) FROM `" . self::table . "` WHERE video_pages_id=:p AND video_name=:n AND plays>0");
		$query->execute(array(':p' => $pageId, ':n' => $name));
		return (string) $query->fetchColumn();
	}

	/**
	 * Render the counts of a video for the page editor and the Video Manager
	 *
	 * @param Pagevideo $video
	 * @param string $prefix Prefix of the class names
	 * @param int $days
	 * @return string
	 *
	 */
	public function render(Pagevideo $video, $prefix, $days = 30) {

		$stats = $this->forVideo($video, $days);
		$plays = $stats['plays'];
		$text = sprintf($this->_n('Last %d day:', 'Last %d days:', $days), $days) . ' ';

		if(!$plays) {
			$text .= $this->_('not played yet');
		} else {
			$text .=
				sprintf($this->_n('%d play', '%d plays', $plays), $plays) . ' · ' .
				// %d: percent of the plays
				sprintf($this->_('%d%% watched to the half'), $stats['half']) . ' · ' .
				sprintf($this->_('%d%% to the end'), $stats['end']);
		}

		$title = $this->_('Counted anonymously: when the video starts, and when a quarter, half, three quarters and all of it have been played. Plays of those who may edit the page are not counted.');

		return
			"<p class='{$prefix}Stats' title='" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "'>" .
				"<i class='fa fa-fw fa-bar-chart' aria-hidden='true'></i> " .
				htmlspecialchars($text, ENT_QUOTES, 'UTF-8') .
			"</p>";
	}
}
