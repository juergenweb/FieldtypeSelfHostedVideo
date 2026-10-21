<?php namespace ProcessWire;

require_once(__DIR__ . '/SelfHostedVideoAssets.php');

/**
 * Overview of all videos of the site
 *
 * Lists the videos of all pages and video fields in one place: what they are, where they
 * live, where else they are shown, and what is still open on their checklists. The list
 * can be searched and narrowed down by tag, field and state. Each video leads to the page
 * it is edited on.
 *
 * Only videos of pages the user may view are listed.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 *
 */
class ProcessSelfHostedVideos extends Process {

	/**
	 * Load the styles and script of the Video Manager, which are in the folder "assets" of the module
	 *
	 */
	public function init() {
		parent::init();
		$info = self::getModuleInfo();
		SelfHostedVideoAssets::load('ProcessSelfHostedVideos', $info['version']);
	}

	public static function getModuleInfo() {
		return array(
			'title' => __('Video Manager', __FILE__),
			'summary' => __('Overview of all videos of the site: search, tags, checklists and where each video is used.', __FILE__),
			'version' => '1.0.0',
			'icon' => 'film',
			'requires' => 'FieldtypeSelfHostedVideo',
			'permission' => 'video-overview',
			'permissions' => array(
				'video-overview' => self::permissionTitle,
			),
			'page' => array(
				'name' => 'videos',
				'parent' => 'setup',
				'title' => self::pageTitle,
			),
		);
	}

	/**
	 * Title of the page of the overview in the Setup menu
	 *
	 * It is translated like every other text of this file, see syncPageTitle().
	 *
	 */
	const pageTitle = 'Video Manager';

	/**
	 * The title that page had up to version 0.17
	 *
	 */
	const oldPageTitle = 'Videos';

	/**
	 * Title of the permission to use the overview, and the one it had up to version 0.17
	 *
	 */
	const permissionTitle = 'Use the overview of all videos (Setup > Video Manager)';
	const oldPermissionTitle = 'Use the overview of all videos (Setup > Videos)';

	/**
	 * Videos per page of the list, unless the module settings say otherwise
	 *
	 */
	const perPage = 10;

	/**
	 * Videos per page of the list: the module setting "Videos per page" of the field type
	 *
	 * @return int
	 *
	 */
	public function perPage() {
		$n = (int) $this->wire()->modules->getConfig('FieldtypeSelfHostedVideo', 'managerPerPage');
		return $n > 0 ? min(100, max(1, $n)) : self::perPage;
	}

	/**
	 * Width in pixels of the thumbnails in the list
	 *
	 */
	const thumbWidth = 480;

	/**
	 * The list of videos
	 *
	 * @return string
	 *
	 */
	public function ___execute() {

		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$sanitizer = $this->wire()->sanitizer;

		// the Setup menu and the headline show the title of this page in the user's language
		$page = $this->wire()->page;
		$this->syncPageTitle($page);

		// where a site has one title for all languages, the headline at least is in the user's
		if(in_array((string) $page->get('title'), array(self::oldPageTitle, self::pageTitle), true)) {
			$this->headline($this->_('Video Manager'));
			$this->browserTitle($this->_('Video Manager'));
		}

		if(!$fieldtype instanceof FieldtypeSelfHostedVideo || !count($fieldtype->getVideoFields())) {
			return "<p>" . $sanitizer->entities($this->_('There is no video field yet. Add a field of the type “Video Files” to a template to get started.')) . "</p>";
		}

		$finder = $fieldtype->finder();
		$tags = $finder->tagsInUse();
		$filters = $this->getFilters($fieldtype, $tags);

		// an action for several videos at once: done, then back to the list (or a question first)
		$input = $this->wire()->input;
		if($input->requestMethod('POST') && strlen((string) $input->post('vf_bulk'))) {
			$question = $this->processBulk($fieldtype, $filters);
			if(is_string($question)) return "<div class='ProcessSelfHostedVideos'>$question</div>";
			$this->wire()->session->location($this->url($filters));
			return '';
		}

		$result = $finder->find(array(
			'q' => $filters['q'],
			'tag' => $filters['tag'],
			'field' => $filters['field'],
			'show' => $filters['show'],
			'checklist' => strlen($filters['check']) ? $filters['check'] : 'all',
			'sort' => $filters['sort'],
			'start' => ($filters['pg'] - 1) * $this->perPage(),
			'limit' => $this->perPage(),
		));

		$out = $this->renderBrokenNotice($fieldtype) . $this->renderDuplicatesNotice($fieldtype, $filters) . $this->renderFilters($fieldtype, $filters, $tags);
		// with videos in the list, the summary is part of the bar above it
		if(!count($result['items'])) $out .= $this->renderSummary($result, $filters);

		if(count($result['items'])) {
			$items = '';
			foreach($result['items'] as $video) $items .= $this->renderItem($video, $filters, $tags);
			// the list is a form: each video can be selected for an action on several videos at once
			$out .=
				"<form id='ProcessSelfHostedVideosBulkForm' method='post' action='" . $this->wire()->sanitizer->entities($this->url($filters)) . "'>" .
					$this->wire()->session->CSRF->renderInput('shv-bulk') .
					$this->renderBulk($result, $filters, $tags, $this->renderSummary($result, $filters)) .
					"<ul class='ProcessSelfHostedVideosList'>$items</ul>" .
				"</form>";
			$out .= $this->renderPager($result, $filters);
		}

		return "<div class='ProcessSelfHostedVideos'>$out</div>";
	}

	/**
	 * Say that tags in texts name no video, with a link to the list of them
	 *
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 * @return string
	 *
	 */
	protected function renderBrokenNotice(FieldtypeSelfHostedVideo $fieldtype) {
		$broken = $this->visibleBrokenTags($fieldtype);
		$n = count($broken);
		$sanitizer = $this->wire()->sanitizer;
		$url = $sanitizer->entities($this->wire()->page->url . 'tags/');
		if(!$n) {
			return
				"<p class='ProcessSelfHostedVideosTagsLink'><a href='$url'>" .
					"<i class='fa fa-fw fa-code' aria-hidden='true'></i> " . $sanitizer->entities($this->_('Video tags in texts')) .
				"</a></p>";
		}
		return
			"<p class='ProcessSelfHostedVideosBroken' role='status'>" .
				"<i class='fa fa-fw fa-exclamation-triangle' aria-hidden='true'></i> " .
				$sanitizer->entities(sprintf($this->_n('%d video tag in a text shows nothing: the video it names is not on its page.', '%d video tags in texts show nothing: the videos they name are not on their pages.', $n), $n)) . ' ' .
				"<a href='$url'>" . $sanitizer->entities($this->_('Show them')) . "</a>" .
			"</p>";
	}

	/**
	 * Say that videos were uploaded more than once, and what merging them would free
	 *
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 * @param array $filters
	 * @return string
	 *
	 */
	protected function renderDuplicatesNotice(FieldtypeSelfHostedVideo $fieldtype, array $filters) {
		$groups = $fieldtype->duplicates()->groups();
		if(!count($groups)) return '';
		$sanitizer = $this->wire()->sanitizer;
		$videos = 0;
		$spare = 0;
		foreach($groups as $group) {
			$videos += count($group['rows']);
			$spare += $group['spare'];
		}
		$text = sprintf(
			$this->_n('%1$d video has the same file as another one. Reusing one file for all of them frees %2$s.', '%1$d videos have the same file as another one. Reusing one file for all of them frees %2$s.', $videos),
			$videos, wireBytesStr($spare)
		);
		$link = $filters['show'] === 'duplicates' ? '' :
			' <a href="' . $sanitizer->entities($this->url($filters, array('show' => 'duplicates', 'pg' => 1))) . '">' . $sanitizer->entities($this->_('Show them')) . '</a>';
		$how = $filters['show'] === 'duplicates' ?
			' ' . $sanitizer->entities($this->_('Choose “Keep this one” at the video that is to stay: the others are replaced by reuses of it.')) : '';
		return
			"<p class='ProcessSelfHostedVideosDuplicates' role='status'>" .
				"<i class='fa fa-fw fa-clone' aria-hidden='true'></i> " . $sanitizer->entities($text) . $how . $link .
			"</p>";
	}

	/**
	 * The tags that name no video, of pages the user may view
	 *
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 * @return array See SelfHostedVideoUsage::brokenTags()
	 *
	 */
	protected function visibleBrokenTags(FieldtypeSelfHostedVideo $fieldtype) {
		$out = array();
		foreach($fieldtype->usage()->brokenTags() as $item) {
			if($item['page']->viewable(false) || $item['page']->editable()) $out[] = $item;
		}
		return $out;
	}

	/**
	 * The list of video tags in texts that show nothing
	 *
	 * @return string
	 *
	 */
	public function ___executeTags() {

		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$sanitizer = $this->wire()->sanitizer;
		$input = $this->wire()->input;
		$session = $this->wire()->session;
		$back = $this->wire()->page->url;

		$this->headline($this->_('Video tags in texts'));
		$this->browserTitle($this->_('Video tags in texts'));
		$this->breadcrumb($back, $this->_('Video Manager'));

		if(!$fieldtype instanceof FieldtypeSelfHostedVideo) return '';

		// all texts read again: for lists made before tags were kept, or changed outside the page editor
		if($input->requestMethod('POST') && $input->post('vf_recheck')) {
			if($session->CSRF->hasValidToken('shv-tags')) {
				$n = $fieldtype->usage()->rebuild();
				$this->message(sprintf($this->_n('The texts of %d page were read again.', 'The texts of %d pages were read again.', $n), $n));
			}
			$session->location($back . 'tags/');
			return '';
		}

		$broken = $this->visibleBrokenTags($fieldtype);
		$intro = $sanitizer->entities($this->_('A tag such as [[video intro.mp4]] in a text shows the video of that name of the same page. These tags name a video the page does not have (any more): on the website they show nothing. Change or remove the tag, or put the video on the page again.'));

		if(!count($broken)) {
			$out = "<p class='ProcessSelfHostedVideosTagsNone'><i class='fa fa-fw fa-check-circle' aria-hidden='true'></i> " . $sanitizer->entities($this->_('Every video tag in the texts of the site shows its video.')) . "</p>";
		} else {
			$rows = '';
			foreach($broken as $item) {
				$page = $item['page'];
				$title = htmlspecialchars((string) $page->get('title|name'), ENT_QUOTES, 'UTF-8');
				$link = $page->editable() ? "<a href='" . $sanitizer->entities($page->editUrl()) . "'>$title</a>" : $title;
				$field = $item['field'] ? htmlspecialchars($item['field']->getLabel(), ENT_QUOTES, 'UTF-8') : '';
				$rows .=
					"<tr>" .
						"<td>$link <span class='ProcessSelfHostedVideosPath'>" . $sanitizer->entities($page->path()) . "</span></td>" .
						"<td>$field</td>" .
						"<td><code>[[video " . htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') . "]]</code></td>" .
					"</tr>";
			}
			$out =
				"<table class='ProcessSelfHostedVideosTagsTable'>" .
					"<caption class='ProcessSelfHostedVideosSr'>" . $sanitizer->entities($this->_('Video tags that show nothing')) . "</caption>" .
					"<thead><tr>" .
						"<th scope='col'>" . $sanitizer->entities($this->_('Page')) . "</th>" .
						"<th scope='col'>" . $sanitizer->entities($this->_('Text')) . "</th>" .
						"<th scope='col'>" . $sanitizer->entities($this->_('Tag')) . "</th>" .
					"</tr></thead>" .
					"<tbody>$rows</tbody>" .
				"</table>";
		}

		$recheck =
			"<form method='post' action='" . $sanitizer->entities($back . 'tags/') . "' class='ProcessSelfHostedVideosRecheck'>" .
				$session->CSRF->renderInput('shv-tags') .
				"<button type='submit' name='vf_recheck' value='1' class='ui-button ui-widget ui-corner-all ui-state-default ui-priority-secondary'>" .
					"<i class='fa fa-refresh' aria-hidden='true'></i> " . $sanitizer->entities($this->_('Read all texts again')) .
				"</button>" .
				"<span class='ProcessSelfHostedVideosRecheckNote'>" . $sanitizer->entities($this->_('The list is kept up to date as pages are saved. Reading all texts again is only needed after texts were changed outside the page editor.')) . "</span>" .
			"</form>";

		return
			"<div class='ProcessSelfHostedVideos ProcessSelfHostedVideosTags'>" .
				"<p>$intro</p>" . $out . $recheck .
				"<p><a href='" . $sanitizer->entities($back) . "'><i class='fa fa-fw fa-angle-left' aria-hidden='true'></i> " . $sanitizer->entities($this->_('Back to the Video Manager')) . "</a></p>" .
			"</div>";
	}

	/**
	 * Render a small chart of the plays per day, for the list (decorative: the line above it has the numbers)
	 *
	 * @param array $daily As SelfHostedVideoStats::daily() gives it
	 * @return string Blank when nothing was played
	 *
	 */
	protected function renderSparkline(array $daily) {
		$values = array();
		foreach($daily as $counts) $values[] = $counts['plays'];
		$max = count($values) ? max($values) : 0;
		if($max < 1) return '';
		$w = 90; $h = 18; $n = count($values);
		$slot = $w / $n;
		$bar = max(1, $slot - 1);
		$marks = '';
		foreach($values as $i => $v) {
			if($v < 1) continue;
			$bh = max(1.5, round($v / $max * $h, 2));
			$marks .= "<rect x='" . round($i * $slot, 2) . "' y='" . round($h - $bh, 2) . "' width='" . round($bar, 2) . "' height='$bh' rx='0.6'/>";
		}
		return
			"<svg class='ProcessSelfHostedVideosSpark' viewBox='0 0 $w $h' width='$w' height='$h' aria-hidden='true' focusable='false'>" .
				"<line x1='0' y1='" . ($h - 0.5) . "' x2='$w' y2='" . ($h - 0.5) . "' class='ProcessSelfHostedVideosSparkBase'/>" .
				$marks .
			"</svg>";
	}

	/**
	 * Periods of the page of plays: days => label
	 *
	 * @return array
	 *
	 */
	protected function playPeriods() {
		return array(
			30 => $this->_('30 days'),
			90 => $this->_('90 days'),
			365 => $this->_('12 months'),
			SelfHostedVideoStats::keepDays => $this->_('2 years'),
		);
	}

	/**
	 * The plays of one video: per day (or week), and how far viewers watch
	 *
	 * @return string
	 *
	 */
	public function ___executePlays() {

		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$sanitizer = $this->wire()->sanitizer;
		$input = $this->wire()->input;
		$session = $this->wire()->session;
		$home = $this->wire()->page->url;

		$this->breadcrumb($home, $this->_('Video Manager'));
		$back = (string) $input->get('back');
		if(strpos($back, $home) !== 0 || preg_match('~[\\\\\s]|//~', substr($back, strlen($home)))) $back = $home;

		if(!$fieldtype instanceof FieldtypeSelfHostedVideo) return '';
		$key = (string) $input->get('video');
		$video = $this->videoByKey($fieldtype, $key);
		if(!$video || !$fieldtype->finder()->viewable($video->page)) {
			$this->error($this->_('The video was not found.'));
			$session->location($back);
			return '';
		}

		$title = strlen($video->title()) ? $video->title() : $video->basename();
		$this->headline(sprintf($this->_('Plays: %s'), $title));
		$this->browserTitle(sprintf($this->_('Plays: %s'), $title));

		$stats = $fieldtype->stats();
		$periods = $this->playPeriods();
		$days = (int) $input->get('days');
		if(!isset($periods[$days])) $days = 30;

		// the period: links that keep the rest of the address
		$tabs = '';
		foreach($periods as $d => $label) {
			$url = $home . 'plays/?video=' . rawurlencode($key) . '&days=' . $d . '&back=' . rawurlencode($back);
			$current = $d === $days ? " aria-current='page'" : '';
			$tabs .= "<li><a href='" . $sanitizer->entities($url) . "'$current>" . $sanitizer->entities($label) . "</a></li>";
		}
		$out =
			"<nav class='ProcessSelfHostedVideosPeriods' aria-label='" . $sanitizer->entities($this->_('Period')) . "'><ul>$tabs</ul></nav>";

		$sum = $stats->forVideo($video, $days);
		$daily = $stats->daily($video, $days);

		if(!$fieldtype->useStats()) {
			$out .= "<p class='ProcessSelfHostedVideosNote'>" . $sanitizer->entities($this->_('Plays are not counted at the moment (module setting “Do not count plays”). What was counted before is shown.')) . "</p>";
		}

		// three figures
		$tile = function($label, $value, $note = '') use($sanitizer) {
			return
				"<div class='ProcessSelfHostedVideosTile'>" .
					"<p class='ProcessSelfHostedVideosTileLabel'>" . $sanitizer->entities($label) . "</p>" .
					"<p class='ProcessSelfHostedVideosTileValue'>" . $sanitizer->entities($value) . "</p>" .
					(strlen($note) ? "<p class='ProcessSelfHostedVideosTileNote'>" . $sanitizer->entities($note) . "</p>" : '') .
				"</div>";
		};
		$plays = $sum['plays'];
		$playsLabel = $this->_('Plays');
		$halfLabel = $this->_('Watched to the half');
		$endLabel = $this->_('Watched to the end');
		$thousands = $this->_x(',', 'thousands separator');
		$countOf = function($n) {
			return sprintf($this->_n('%d play', '%d plays', $n), $n);
		};
		$out .=
			"<div class='ProcessSelfHostedVideosTiles'>" .
				$tile($playsLabel, number_format($plays, 0, '', $thousands), $periods[$days]) .
				$tile($halfLabel, $plays ? $sum['half'] . ' %' : '–', $plays ? $countOf($sum['counts']['p50']) : '') .
				$tile($endLabel, $plays ? $sum['end'] . ' %' : '–', $plays ? $countOf($sum['counts']['p100']) : '') .
			"</div>";

		if(!$plays) {
			$first = $stats->firstDay($video);
			$out .= "<p class='ProcessSelfHostedVideosNote'>" . $sanitizer->entities(strlen($first)
				? $this->_('Nobody watched the video in this period. Choose a longer one to see earlier plays.')
				: $this->_('Nobody has watched the video yet. Plays are counted anonymously on the website; plays of those who may edit the page are not counted.')) . "</p>";
		} else {
			$out .= $this->renderPlaysChart($daily, $days);
			$out .= $this->renderReach($sum);
			$out .= $this->renderPlaysTable($daily);
		}

		$out .= "<p class='ProcessSelfHostedVideosNote'>" . $sanitizer->entities($this->_('Counted anonymously: when the video starts, and when a quarter, half, three quarters and all of it have been played. Plays of those who may edit the page are not counted. Counts are kept for two years.')) . "</p>";
		$out .= "<p><a href='" . $sanitizer->entities($back) . "'><i class='fa fa-fw fa-angle-left' aria-hidden='true'></i> " . $sanitizer->entities($this->_('Back to the Video Manager')) . "</a></p>";

		return "<div class='ProcessSelfHostedVideos ProcessSelfHostedVideosPlays'>$out</div>";
	}

	/**
	 * Format a day for the charts
	 *
	 * @param string $day "Y-m-d"
	 * @return string
	 *
	 */
	protected function shortDay($day) {
		// date format of the charts of plays, as PHP's date() takes it: "n/j" for 3/14, "j.n." for 14.3.
		return date($this->_x('n/j', 'short date'), strtotime($day));
	}

	/**
	 * Round a number up to a value that reads well on an axis: 1, 2, 5, 10, 20, 50 …
	 *
	 * @param float $n
	 * @return int
	 *
	 */
	protected function niceStep($n) {
		if($n <= 1) return 1;
		$p = pow(10, floor(log10($n)));
		foreach(array(1, 2, 5, 10) as $m) if($m * $p >= $n) return (int) ($m * $p);
		return (int) (10 * $p);
	}

	/**
	 * Render the chart of plays per day (per week for long periods)
	 *
	 * @param array $daily
	 * @param int $days
	 * @return string
	 *
	 */
	protected function renderPlaysChart(array $daily, $days) {

		$sanitizer = $this->wire()->sanitizer;

		// a column per day, or per week where there would be too many to read
		$weekly = $days > 90;
		$columns = array();
		foreach($daily as $day => $counts) {
			if($weekly) {
				$monday = date('Y-m-d', strtotime('monday this week', strtotime($day)));
				if(!isset($columns[$monday])) $columns[$monday] = array('plays' => 0, 'from' => $day);
				$columns[$monday]['plays'] += $counts['plays'];
			} else {
				$columns[$day] = array('plays' => $counts['plays'], 'from' => $day);
			}
		}

		$values = array_column($columns, 'plays');
		$max = max($values);
		$step = $this->niceStep($max / 4);
		$top = max($step, (int) ceil($max / $step) * $step);

		$W = 760; $H = 240; $left = 44; $right = 8; $topPad = 10; $bottom = 26;
		$plotW = $W - $left - $right;
		$plotH = $H - $topPad - $bottom;
		$n = count($columns);
		$slot = $plotW / $n;
		$barW = min(24, max(1, $slot - 2));

		$grid = '';
		for($v = 0; $v <= $top; $v += $step) {
			$y = round($topPad + $plotH - $v / $top * $plotH, 1) + 0.5;
			$grid .=
				"<line class='ProcessSelfHostedVideosChartGrid' x1='$left' x2='" . ($W - $right) . "' y1='$y' y2='$y'/>" .
				"<text class='ProcessSelfHostedVideosChartTick' x='" . ($left - 8) . "' y='" . ($y + 4) . "' text-anchor='end'>" . ($v >= 1000 ? round($v / 1000, 1) . 'K' : $v) . "</text>";
		}

		$marks = '';
		$labels = '';
		$i = 0;
		$keys = array_keys($columns);
		$labelAt = array(0 => true, (int) floor(($n - 1) / 2) => true, $n - 1 => true);
		foreach($columns as $day => $col) {
			$x = $left + $i * $slot + ($slot - $barW) / 2;
			$tip = $weekly
				? sprintf($this->_n('Week from %1$s: %2$d play', 'Week from %1$s: %2$d plays', $col['plays']), $this->shortDay($col['from']), $col['plays'])
				: sprintf($this->_n('%1$s: %2$d play', '%1$s: %2$d plays', $col['plays']), $this->shortDay($day), $col['plays']);
			$tip = $sanitizer->entities($tip);
			if($col['plays'] > 0) {
				$bh = max(2, $col['plays'] / $top * $plotH);
				$y = $topPad + $plotH - $bh;
				$r = min(4, $barW / 2, $bh);
				$x2 = $x + $barW;
				$yb = $topPad + $plotH;
				// rounded where the value is, square on the baseline
				$d = sprintf('M%.2f %.2f V%.2f Q%.2f %.2f %.2f %.2f H%.2f Q%.2f %.2f %.2f %.2f V%.2f Z',
					$x, $yb, $y + $r, $x, $y, $x + $r, $y, $x2 - $r, $x2, $y, $x2, $y + $r, $yb);
				$marks .= "<path class='ProcessSelfHostedVideosChartBar' d='$d'/>";
			}
			// the place to point at is the whole height of the column
			$marks .= "<rect class='ProcessSelfHostedVideosChartHit' x='" . round($left + $i * $slot, 2) . "' y='$topPad' width='" . round($slot, 2) . "' height='$plotH' data-tip='$tip'><title>$tip</title></rect>";
			if(isset($labelAt[$i])) {
				$anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
				$lx = $i === 0 ? $left + $i * $slot : ($i === $n - 1 ? $left + ($i + 1) * $slot : $left + ($i + 0.5) * $slot);
				$labels .= "<text class='ProcessSelfHostedVideosChartTick' x='" . round($lx, 1) . "' y='" . ($H - 6) . "' text-anchor='$anchor'>" . $sanitizer->entities($this->shortDay($col['from'])) . "</text>";
			}
			$i++;
		}

		$heading = $this->_('Plays per day');
		if($weekly) $heading = $this->_('Plays per week');
		$desc = sprintf($this->_('%1$s, from %2$s to %3$s. Most: %4$d. The numbers are in the table below.'), $heading, $this->shortDay($keys[0]), $this->shortDay(end($keys)), $max);

		return
			"<figure class='ProcessSelfHostedVideosChart'>" .
				"<figcaption class='ProcessSelfHostedVideosChartTitle'>" . $sanitizer->entities($heading) . "</figcaption>" .
				"<div class='ProcessSelfHostedVideosChartArea' tabindex='0'>" .
					"<svg viewBox='0 0 $W $H' role='img' aria-label='" . $sanitizer->entities($desc) . "' preserveAspectRatio='xMidYMid meet'>" .
						$grid .
						"<line class='ProcessSelfHostedVideosChartAxis' x1='$left' x2='" . ($W - $right) . "' y1='" . ($topPad + $plotH + 0.5) . "' y2='" . ($topPad + $plotH + 0.5) . "'/>" .
						$marks . $labels .
					"</svg>" .
					"<div class='ProcessSelfHostedVideosChartTip' hidden></div>" .
				"</div>" .
			"</figure>";
	}

	/**
	 * Render how far viewers watch: of all plays, the share that got to a quarter, the half …
	 *
	 * @param array $sum As SelfHostedVideoStats::forVideo() gives it
	 * @return string
	 *
	 */
	protected function renderReach(array $sum) {
		$sanitizer = $this->wire()->sanitizer;
		$steps = array(
			array($this->_('Started'), 100, $sum['counts']['plays']),
			array($this->_('A quarter'), $sum['quarter'], $sum['counts']['p25']),
			array($this->_('The half'), $sum['half'], $sum['counts']['p50']),
			array($this->_('Three quarters'), $sum['threeQuarters'], $sum['counts']['p75']),
			array($this->_('To the end'), $sum['end'], $sum['counts']['p100']),
		);
		$rows = '';
		foreach($steps as $step) {
			list($label, $percent, $count) = $step;
			$rows .=
				"<li class='ProcessSelfHostedVideosReachRow'>" .
					"<span class='ProcessSelfHostedVideosReachLabel'>" . $sanitizer->entities($label) . "</span>" .
					"<span class='ProcessSelfHostedVideosReachTrack' aria-hidden='true'><span class='ProcessSelfHostedVideosReachBar' style='width:" . (int) $percent . "%'></span></span>" .
					"<span class='ProcessSelfHostedVideosReachValue'><strong>" . (int) $percent . " %</strong> " .
						"<span class='ProcessSelfHostedVideosReachCount'>(" . $sanitizer->entities(sprintf($this->_n('%d play', '%d plays', $count), $count)) . ")</span></span>" .
				"</li>";
		}
		return
			"<section class='ProcessSelfHostedVideosReach'>" .
				"<h2 class='ProcessSelfHostedVideosChartTitle'>" . $sanitizer->entities($this->_('How far viewers watch')) . "</h2>" .
				"<p class='ProcessSelfHostedVideosNote'>" . $sanitizer->entities($this->_('Of all plays in this period, the share that got this far. Where few get to the half, the start may be too slow or the video too long.')) . "</p>" .
				"<ol class='ProcessSelfHostedVideosReachList'>$rows</ol>" .
			"</section>";
	}

	/**
	 * Render the counts per day as a table (the days with plays)
	 *
	 * @param array $daily
	 * @return string
	 *
	 */
	protected function renderPlaysTable(array $daily) {
		$sanitizer = $this->wire()->sanitizer;
		$rows = '';
		foreach(array_reverse($daily, true) as $day => $c) {
			if($c['plays'] < 1 && $c['p25'] + $c['p50'] + $c['p75'] + $c['p100'] < 1) continue;
			$rows .=
				"<tr><th scope='row'>" . $sanitizer->entities(wireDate(strtok((string) $this->wire()->config->dateFormat, ' ') ?: 'Y-m-d', strtotime($day))) . "</th>" .
				"<td>" . $c['plays'] . "</td><td>" . $c['p25'] . "</td><td>" . $c['p50'] . "</td><td>" . $c['p75'] . "</td><td>" . $c['p100'] . "</td></tr>";
		}
		$th = function($label) use($sanitizer) { return "<th scope='col'>" . $sanitizer->entities($label) . "</th>"; };
		$head = '';
		$labels = array(
			$this->_('Day'),
			$this->_('Plays'),
			$this->_('A quarter'),
			$this->_('The half'),
			$this->_('Three quarters'),
			$this->_('To the end'),
		);
		foreach($labels as $label) $head .= $th($label);
		return
			"<details class='ProcessSelfHostedVideosPlaysTable'>" .
				"<summary>" . $sanitizer->entities($this->_('The counts per day as a table')) . "</summary>" .
				"<p class='ProcessSelfHostedVideosNote'>" . $sanitizer->entities($this->_('Newest first. Days without plays are left out.')) . "</p>" .
				"<table class='ProcessSelfHostedVideosTagsTable'>" .
					"<thead><tr>" . $head . "</tr></thead>" .
					"<tbody>$rows</tbody>" .
				"</table>" .
			"</details>";
	}

	/**
	 * Merge videos with the same file: one is kept, the others become reuses of it (asks first)
	 *
	 * @return string
	 *
	 */
	public function ___executeMerge() {

		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$sanitizer = $this->wire()->sanitizer;
		$input = $this->wire()->input;
		$session = $this->wire()->session;
		$home = $this->wire()->page->url;

		$this->headline($this->_('Reuse one file for all'));
		$this->browserTitle($this->_('Reuse one file for all'));
		$this->breadcrumb($home, $this->_('Video Manager'));

		// back to the list as it was, and nowhere else
		$back = (string) ($input->requestMethod('POST') ? $input->post('back') : $input->get('back'));
		if(strpos($back, $home) !== 0 || preg_match('~[\\\\\s]|//~', substr($back, strlen($home)))) $back = $home . '?show=duplicates';

		if(!$fieldtype instanceof FieldtypeSelfHostedVideo) return '';
		$duplicates = $fieldtype->duplicates();
		$key = (string) ($input->requestMethod('POST') ? $input->post('keep') : $input->get('keep'));
		$keep = $this->videoByKey($fieldtype, $key);

		if(!$keep || !$fieldtype->finder()->viewable($keep->page)) {
			$this->error($this->_('The video was not found.'));
			$session->location($back);
			return '';
		}

		$copies = array();
		$left = array();
		foreach($duplicates->forVideo($keep) as $copy) {
			$why = $duplicates->whyNot($keep, $copy);
			if(strlen($why)) {
				$left[] = array($copy, $why);
			} else {
				$copies[] = $copy;
			}
		}

		if($input->requestMethod('POST') && $input->post('vf_merge')) {
			if(!$session->CSRF->hasValidToken('shv-merge')) {
				$this->error($this->_('The form has expired. Please try again.'));
				$session->location($back);
				return '';
			}
			$done = 0;
			$bytes = 0;
			foreach($copies as $copy) {
				try {
					$bytes += $duplicates->merge($keep, $copy);
					$done++;
					// the kept video as it is now: a merge may have changed its page
					$keep = $this->videoByKey($fieldtype, $key);
					if(!$keep) break;
				} catch(\Exception $e) {
					$this->error($e->getMessage());
				}
			}
			if($done) $this->message(sprintf($this->_n('%1$d video now reuses the kept file. %2$s freed.', '%1$d videos now reuse the kept file. %2$s freed.', $done), $done, wireBytesStr($bytes)));
			$session->location($back);
			return '';
		}

		$finder = $fieldtype->finder();
		$usage = $fieldtype->usage();
		$describe = function(Pagevideo $video) use($finder, $sanitizer) {
			$page = $finder->editPage($video->page);
			$hasTitle = strlen($video->title()) > 0;
			$facts = array($page->path(), $video->field->getLabel());
			if($hasTitle) array_unshift($facts, $video->basename());
			return
				"<strong>" . $sanitizer->entities1($hasTitle ? $video->title() : $video->basename()) . "</strong> " .
				"<span class='ProcessSelfHostedVideosPath'>" . $sanitizer->entities(implode(' · ', $facts)) . "</span>";
		};

		$items = '';
		$bytes = 0;
		foreach($copies as $copy) {
			$bytes += (int) $copy->filesize();
			$notes = array();
			// what the copy has of its own, which the kept video's takes the place of
			$own = array();
			if(strlen($copy->title()) && $copy->title() !== $keep->title()) $own[] = sprintf($this->_('title “%s”'), $copy->title());
			if(strlen((string) $copy->description()) && (string) $copy->description() !== (string) $keep->description()) $own[] = $this->_('description');
			$info = $copy->thumbInfo();
			if($copy->hasThumb() && $info['custom']) $own[] = $this->_('thumbnail');
			if(count($copy->captions())) $own[] = $this->_('captions');
			if(count($copy->chapters()) || count($copy->overlays())) $own[] = $this->_('chapters and overlays');
			if(count($own)) $notes[] = sprintf($this->_('Its own %s go, the kept video’s are shown instead.'), implode(', ', $own));
			$reusers = $copy->references()->count();
			if($reusers) $notes[] = sprintf($this->_n('The page that reuses it reuses the kept video.', 'The %d pages that reuse it reuse the kept video.', $reusers), $reusers);
			$texts = count($usage->textHolders($copy->page->id, $copy->basename()));
			if($texts && strtolower($copy->basename()) !== strtolower($keep->basename())) {
				$notes[] = sprintf($this->_('Tags in texts that place it get the file name of the kept video: %s'), '[[video ' . $keep->basename() . ']]');
			}
			$items .=
				"<li>" . $describe($copy) . " <span class='ProcessSelfHostedVideosPath'>(" . $sanitizer->entities($copy->filesizeStr) . ")</span>" .
				(count($notes) ? "<ul class='ProcessSelfHostedVideosMergeNotes'><li>" . implode('</li><li>', array_map(array($sanitizer, 'entities1'), $notes)) . "</li></ul>" : '') .
				"</li>";
		}
		$leftItems = '';
		foreach($left as $item) {
			$leftItems .= "<li>" . $describe($item[0]) . " <span class='ProcessSelfHostedVideosBadge'>" . $sanitizer->entities(sprintf($this->_('left as it is: %s'), $item[1])) . "</span></li>";
		}

		$n = count($copies);
		$out =
			"<p>" . sprintf($sanitizer->entities($this->_('This video stays: %s')), $describe($keep)) . "</p>";
		if($n) {
			$out .=
				"<p>" . $sanitizer->entities($this->_n('The other video with the same file is replaced by a reuse of it, in the same place of its page, and its file is deleted. Pages and texts that showed it show the kept video. Its plays are added to those of the kept video.', 'The other videos with the same file are replaced by reuses of it, each in the same place of its page, and their files are deleted. Pages and texts that showed them show the kept video. Their plays are added to those of the kept video.', $n)) . "</p>" .
				"<ul class='ProcessSelfHostedVideosConfirmList'>$items</ul>";
		}
		if(strlen($leftItems)) $out .= "<ul class='ProcessSelfHostedVideosConfirmList'>$leftItems</ul>";
		if(!$n) $out .= "<p>" . $sanitizer->entities($this->_('There is nothing to merge.')) . "</p>";

		$button = $sanitizer->entities(sprintf($this->_n('Reuse one file (frees %s)', 'Reuse one file for all (frees %s)', $n), wireBytesStr($bytes)));
		return
			"<form class='ProcessSelfHostedVideos ProcessSelfHostedVideosConfirm ProcessSelfHostedVideosMerge' method='post' action='" . $sanitizer->entities($home . 'merge/') . "'>" .
				$session->CSRF->renderInput('shv-merge') .
				$out .
				"<input type='hidden' name='keep' value='" . $sanitizer->entities($key) . "'>" .
				"<input type='hidden' name='back' value='" . $sanitizer->entities($back) . "'>" .
				"<p class='ProcessSelfHostedVideosConfirmButtons'>" .
					($n ? "<button type='submit' name='vf_merge' value='1' class='ui-button ui-widget ui-corner-all ui-state-default'><i class='fa fa-compress' aria-hidden='true'></i> $button</button> " : '') .
					"<a class='ui-button ui-widget ui-corner-all ui-state-default ui-priority-secondary' href='" . $sanitizer->entities($back) . "'>" . $sanitizer->entities($this->_('Cancel')) . "</a>" .
				"</p>" .
			"</form>";
	}

	/**
	 * Get an original video by "page ID|field|file name"
	 *
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 * @param string $key
	 * @return Pagevideo|null
	 *
	 */
	protected function videoByKey(FieldtypeSelfHostedVideo $fieldtype, $key) {
		$parts = explode('|', (string) $key, 3);
		$fields = $fieldtype->getVideoFields();
		if(count($parts) !== 3 || !ctype_digit($parts[0]) || !isset($fields[$parts[1]])) return null;
		$page = $this->wire()->pages->get((int) $parts[0]);
		if(!$page->id || $page->isTrash()) return null;
		$page->of(false);
		$value = $page->getUnformatted($parts[1]);
		$video = $value instanceof Pagevideos ? $value->getFile(basename($parts[2])) : null;
		return $video instanceof Pagevideo && !$video->isReference() ? $video : null;
	}

	/**
	 * Give the page of the overview its title in the languages of the site
	 *
	 * The Setup menu and the headline show the title of the page, which the admin does not
	 * translate with the texts of a module. So the title is kept in line with them: where the
	 * page still has the title the module gave it (or none, in another language), it gets
	 * "Video Manager" as translated for that language. A title somebody chose is left alone.
	 *
	 * @param Page $page The page of the overview
	 * @param bool $all Every language of the site (after an upgrade), or only the user's (default)
	 * @return bool Was a title changed?
	 *
	 */
	public function syncPageTitle(Page $page, $all = false) {

		if(!$page->id || $page->template->name !== 'admin') return false;

		$languages = $this->wire()->languages;
		$user = $this->wire()->user;
		$title = $page->getUnformatted('title');
		$english = self::pageTitle;
		$given = array(self::oldPageTitle, $english);
		$changed = false;
		$formatted = $page->of();

		if(!$languages || !$title instanceof LanguagesValueInterface) {
			// one title for everybody: that of the default language
			$other = $languages && $user->language && $user->language->id && !$user->language->isDefault();
			if($other) $languages->setLanguage($languages->getDefault());
			$want = $this->_('Video Manager');
			if($other) $languages->unsetLanguage();
			if(in_array((string) $title, $given, true) && (string) $title !== $want) {
				$page->of(false);
				$page->set('title', $want);
				$changed = true;
			}
		} else {
			$current = $user->language;
			// an upgrade can run before the language of the user is known
			if(!$current || !$current->id) $current = $languages->getDefault();
			foreach($languages as $language) {
				/** @var Language $language */
				if(!$all && $language->id !== $current->id) continue;
				if($language->id !== $current->id) $languages->setLanguage($language);
				$want = $this->_('Video Manager');
				if($language->id !== $current->id) $languages->unsetLanguage();
				$have = (string) $title->getLanguageValue($language);
				if($have === $want) continue;
				if($language->isDefault()) {
					if(!in_array($have, $given, true)) continue;
				} else {
					// without a translation the title of the default language shows through
					if($want === $english && $have !== self::oldPageTitle) continue;
					if($have !== '' && !in_array($have, $given, true)) continue;
					if($want === $english) $want = '';
					if($have === $want) continue;
				}
				$page->of(false);
				$title->setLanguageValue($language, $want);
				$changed = true;
			}
			if($changed) $page->trackChange('title');
		}

		if($changed) {
			try {
				$page->save(array('quiet' => true));
				// AdminThemeUikit keeps its menus per session. A page that is saved makes it build them
				// again, but an upgrade runs before that theme listens, so its copies are dropped here.
				$session = $this->wire()->session;
				$session->removeFor('AdminThemeUikit', 'prnav');
				$session->removeFor('AdminThemeUikit', 'sidenav');
			} catch(\Exception $e) {
				// the title stays as it was
				$changed = false;
			}
		}

		$page->of($formatted);

		return $changed;
	}

	/**
	 * Upgrade: the page of the overview and its permission get their new titles
	 *
	 * @param int|string $fromVersion
	 * @param int|string $toVersion
	 *
	 */
	public function ___upgrade($fromVersion, $toVersion) {

		$page = $this->wire()->pages->get('template=admin, process=' . $this->className() . ', include=all');
		if($page->id) $this->syncPageTitle($page, true);

		$permission = $this->wire()->permissions->get('video-overview');
		if($permission && $permission->id && (string) $permission->getUnformatted('title') === self::oldPermissionTitle) {
			$permission->of(false);
			$permission->set('title', self::permissionTitle);
			$permission->save('title', array('quiet' => true));
		}
	}

	/**
	 * Get what the list is narrowed down to, from the address of the page
	 *
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 * @param array $tags Tags in use
	 * @return array
	 *
	 */
	protected function getFilters(FieldtypeSelfHostedVideo $fieldtype, array $tags) {

		$input = $this->wire()->input;
		$sanitizer = $this->wire()->sanitizer;

		$filters = array(
			'q' => $sanitizer->text((string) $input->get('q'), array('maxLength' => 200)),
			'tag' => (string) $input->get('tag'),
			'field' => (string) $input->get('field'),
			'show' => (string) $input->get('show'),
			'check' => (string) $input->get('check'),
			'sort' => (string) $input->get('sort'),
			'pg' => max(1, (int) $input->get('pg')),
		);

		if(!isset($tags[$filters['tag']])) $filters['tag'] = '';
		$fields = $fieldtype->getVideoFields();
		if(!isset($fields[$filters['field']])) $filters['field'] = '';
		// an address from before the checklist had a choice of its own
		if($filters['show'] === 'attention') {
			$filters['show'] = 'all';
			if(!strlen($filters['check'])) $filters['check'] = 'open';
		}
		if(!isset($this->showOptions()[$filters['show']])) $filters['show'] = 'all';
		if(!isset($this->checkOptions()[$filters['check']])) $filters['check'] = '';
		if(!isset($this->sortOptions()[$filters['sort']])) $filters['sort'] = 'newest';

		return $filters;
	}

	/**
	 * Choices of which videos to show
	 *
	 * @return array
	 *
	 */
	protected function showOptions() {
		$options = array(
			'all' => $this->_('All videos'),
			'reused' => $this->_('Reused on other pages'),
			'intext' => $this->_('Placed in a text'),
			'protected' => $this->_('Protected'),
			'stream' => $this->_('Not prepared for streaming'),
			'unused' => $this->_('Not on a published page'),
			'duplicates' => $this->_('Uploaded more than once'),
		);
		if($this->statsOn()) {
			$options['unplayed'] = $this->_('Not watched in the last 30 days');
			$options['dropoff'] = $this->_('Viewers stop early');
		}
		return $options;
	}

	/**
	 * Are plays counted?
	 *
	 * @return bool
	 *
	 */
	protected function statsOn() {
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		return $fieldtype instanceof FieldtypeSelfHostedVideo && $fieldtype->useStats();
	}

	/**
	 * Choices of the state of the checklist
	 *
	 * @return array
	 *
	 */
	protected function checkOptions() {
		return array(
			'' => $this->_('All'),
			'open' => $this->_('Open points'),
			'complete' => $this->_('Complete'),
		);
	}

	/**
	 * Choices of order
	 *
	 * @return array
	 *
	 */
	protected function sortOptions() {
		$options = array(
			'newest' => $this->_('Newest first'),
			'oldest' => $this->_('Oldest first'),
			'name' => $this->_('File name'),
			'longest' => $this->_('Longest first'),
			'largest' => $this->_('Largest file first'),
		);
		if($this->statsOn()) {
			$options['mostplayed'] = $this->_('Most watched (30 days)');
			$options['leastplayed'] = $this->_('Least watched (30 days)');
		}
		return $options;
	}

	/**
	 * Build the address of the list with the given filters
	 *
	 * @param array $filters
	 * @param array $change Filters to set differently
	 * @return string
	 *
	 */
	protected function url(array $filters, array $change = array()) {
		$filters = array_merge($filters, $change);
		$params = array();
		foreach($filters as $key => $value) {
			if($key === 'pg' && (int) $value < 2) continue;
			if($key === 'show' && $value === 'all') continue;
			if($key === 'sort' && $value === 'newest') continue;
			if($value === '' || $value === null) continue;
			$params[$key] = $value;
		}
		$url = $this->wire()->page->url();
		return count($params) ? $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : $url;
	}

	/**
	 * Render the form that narrows the list down
	 *
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 * @param array $filters
	 * @param array $tags
	 * @return string
	 *
	 */
	protected function renderFilters(FieldtypeSelfHostedVideo $fieldtype, array $filters, array $tags) {

		$modules = $this->wire()->modules;
		$fields = $fieldtype->getVideoFields();

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->attr('id', 'ProcessSelfHostedVideosFilters');
		$form->attr('method', 'get');
		$form->attr('action', './');
		$form->protectCSRF = false;

		// the search gets the room the others do not need
		$columns = 3 + (count($tags) ? 1 : 0) + (count($fields) > 1 ? 1 : 0);
		$width = min(22, (int) floor(76 / $columns));

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'q');
		$f->attr('value', $filters['q']);
		$f->label = $this->_('Search');
		$f->attr('placeholder', $this->_('Title, file name, page …'));
		$f->columnWidth = 100 - $width * $columns;
		$f->collapsed = Inputfield::collapsedNever;
		$form->add($f);

		if(count($tags)) {
			/** @var InputfieldSelect $f */
			$f = $modules->get('InputfieldSelect');
			$f->attr('name', 'tag');
			$f->label = $this->_('Tag');
			$f->addOption('', $this->_('Any tag'));
			foreach($tags as $tag => $label) $f->addOption($tag, $label);
			$f->attr('value', $filters['tag']);
			$f->columnWidth = $width;
			$f->collapsed = Inputfield::collapsedNever;
			$form->add($f);
		}

		if(count($fields) > 1) {
			$f = $modules->get('InputfieldSelect');
			$f->attr('name', 'field');
			$f->label = $this->_('Field');
			$f->addOption('', $this->_('Any field'));
			foreach($fields as $field) $f->addOption($field->name, $field->getLabel() . " ($field->name)");
			$f->attr('value', $filters['field']);
			$f->columnWidth = $width;
			$f->collapsed = Inputfield::collapsedNever;
			$form->add($f);
		}

		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'show');
		$f->label = $this->_('Show');
		$f->addOptions($this->showOptions());
		$f->attr('value', $filters['show']);
		$f->required = true; // no blank choice
		$f->columnWidth = $width;
		$f->collapsed = Inputfield::collapsedNever;
		$form->add($f);

		// are points of the checklist open, such as a missing title or missing captions?
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'check');
		$f->label = $this->_('Checklist');
		$f->addOptions($this->checkOptions());
		$f->attr('value', $filters['check']);
		$f->columnWidth = $width;
		$f->collapsed = Inputfield::collapsedNever;
		$form->add($f);

		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'sort');
		$f->label = $this->_('Order');
		$f->addOptions($this->sortOptions());
		$f->attr('value', $filters['sort']);
		$f->required = true;
		$f->columnWidth = $width;
		$f->collapsed = Inputfield::collapsedNever;
		$form->add($f);

		/** @var InputfieldSubmit $f */
		$f = $modules->get('InputfieldSubmit');
		$f->attr('name', 'submit_filter');
		$f->attr('value', $this->_('Show videos'));
		$f->icon = 'search';
		$form->add($f);

		return $form->render();
	}

	/**
	 * Render the line that says how many videos were found
	 *
	 * @param array $result
	 * @param array $filters
	 * @return string
	 *
	 */
	protected function renderSummary(array $result, array $filters) {

		$sanitizer = $this->wire()->sanitizer;
		$total = (int) $result['total'];
		$narrowed = strlen($filters['q']) || strlen($filters['tag']) || strlen($filters['field']) || $filters['show'] !== 'all' || strlen($filters['check']);

		if(!$total) {
			$text = $this->_('No videos have been uploaded yet.');
			if($narrowed) $text = $this->_('No videos found for this choice.');
			$out = $sanitizer->entities($text);
		} else {
			$parts = array(sprintf($this->_n('%d video', '%d videos', $total), $total));
			if($result['duration'] > 0) $parts[] = sprintf($this->_('%s playing time'), $this->formatDuration($result['duration']));
			if($result['filesize'] > 0) $parts[] = wireBytesStr($result['filesize'], true);
			$out = $sanitizer->entities(implode(' · ', $parts));
		}

		if($narrowed) {
			$url = $sanitizer->entities($this->url(array('sort' => $filters['sort'])));
			$out .= " <a class='ProcessSelfHostedVideosReset' href='$url'>" . $sanitizer->entities($this->_('Show all videos')) . "</a>";
		}

		// read out when the list changes, which is what a search is for
		return "<p class='ProcessSelfHostedVideosSummary' role='status'>$out</p>";
	}

	/**
	 * Render one video of the list
	 *
	 * @param Pagevideo $video
	 * @param array $filters
	 * @param array $tags Tags in use, with labels
	 * @return string
	 *
	 */
	protected function renderItem(Pagevideo $video, array $filters, array $tags) {

		$sanitizer = $this->wire()->sanitizer;
		$fieldtype = $video->field->type;
		$finder = $fieldtype->finder();
		$page = $video->page;
		$editPage = $finder->editPage($page);

		$title = $video->title();
		$name = strlen($title) ? $title : $video->basename();
		$nameHtml = $sanitizer->entities1($name);

		// the page the video is edited on
		if($editPage->editable()) {
			$editUrl = $editPage->editUrl();
			if($editPage->id === $page->id) {
				// "find-" is the core's way to a field wherever it is: it shows the tab the field is in
				// (a tab of its own included) and scrolls to it. "vf_open" tells the video input which
				// video to open, where the videos of a field are closed items.
				$editUrl .= (strpos($editUrl, '?') === false ? '?' : '&') . 'vf_open=' . $video->field->name . '_' . $video->hash;
				$editUrl .= '#find-' . $video->field->name;
			}
			$heading = "<a href='" . $sanitizer->entities($editUrl) . "'>$nameHtml</a>";
		} else {
			$heading = $nameHtml;
		}

		$badges = '';
		if($video->isProtected()) {
			$badges .= " <span class='ProcessSelfHostedVideosBadge'><i class='fa fa-lock' aria-hidden='true'></i> " . $sanitizer->entities($this->_('Protected')) . "</span>";
		}

		// facts
		$facts = array($sanitizer->entities($video->basename()));
		$duration = $video->durationStr();
		if(strlen($duration)) $facts[] = $duration;
		if($video->width() && $video->height()) $facts[] = $video->width() . '&times;' . $video->height();
		$facts[] = str_replace(' ', '&nbsp;', $video->filesizeStr);
		// the date as the site writes dates, without the time of day
		$dateFormat = strtok((string) $this->wire()->config->dateFormat, ' ');
		$facts[] = sprintf($this->_('added %s'), wireDate($dateFormat ?: 'Y-m-d', $video->created));

		// where it lives
		$pageTitle = $sanitizer->entities1((string) $editPage->get('title|name'));
		$where = sprintf(
			$this->_('On page %1$s, field “%2$s”'),
			"<strong>$pageTitle</strong> <span class='ProcessSelfHostedVideosPath'>" . $sanitizer->entities($editPage->path()) . "</span>",
			$sanitizer->entities($video->field->getLabel())
		);
		if($editPage->isUnpublished()) $where .= " <span class='ProcessSelfHostedVideosBadge'>" . $sanitizer->entities($this->_('Unpublished')) . "</span>";

		// to be chosen for an action on several videos at once, where the user may edit the page
		$select = '';
		if($editPage->editable()) {
			$key = $sanitizer->entities($this->bulkKey($video));
			$select =
				"<label class='ProcessSelfHostedVideosSelect'>" .
					"<input type='checkbox' class='uk-checkbox ProcessSelfHostedVideosSelectBox' name='vf_select[]' value='$key'>" .
					"<span class='ProcessSelfHostedVideosSr'>" . $sanitizer->entities(sprintf($this->_('Select “%s”'), $name)) . "</span>" .
				"</label>";
		}

		$out =
			"<div class='ProcessSelfHostedVideosMedia'>" . $this->renderPlayer($video, $name) . "</div>" .
			"<div class='ProcessSelfHostedVideosMain'>" .
				"<div class='ProcessSelfHostedVideosHead'>$select<h2 class='ProcessSelfHostedVideosTitle'>$heading$badges</h2></div>" .
				"<p class='ProcessSelfHostedVideosFacts'>" . implode(' &middot; ', $facts) . "</p>" .
				"<p class='ProcessSelfHostedVideosWhere'>$where</p>";

		// tags, each a way to all videos with it
		$labels = $video->tagLabels();
		if(count($labels)) {
			$links = array();
			foreach($labels as $tag => $label) {
				$url = $sanitizer->entities($this->url($filters, array('tag' => $tag, 'pg' => 1)));
				$current = $filters['tag'] === (string) $tag ? " aria-current='true'" : '';
				$links[] = "<a class='ProcessSelfHostedVideosTag' href='$url'$current>" . $sanitizer->entities($label) . "</a>";
			}
			$out .=
				"<p class='ProcessSelfHostedVideosTags'><i class='fa fa-fw fa-tags' aria-hidden='true'></i>" .
				"<span class='ProcessSelfHostedVideosSr'>" . $sanitizer->entities($this->_('Tags:')) . " </span>" .
				implode(' ', $links) . "</p>";
		}

		// where else it is shown
		$usedOn = $video->references();
		if($usedOn->count()) {
			$links = array();
			foreach($usedOn as $p) {
				$p = $finder->editPage($p);
				$label = $sanitizer->entities1((string) $p->get('title|name'));
				$links[$p->id] = $p->editable() ? "<a href='" . $sanitizer->entities($p->editUrl()) . "'>$label</a>" : $label;
			}
			$n = count($links);
			$out .=
				"<p class='ProcessSelfHostedVideosUsed'><i class='fa fa-fw fa-link' aria-hidden='true'></i> " .
				sprintf($this->_n('Reused on %d other page:', 'Reused on %d other pages:', $n), $n) . ' ' .
				implode(', ', $links) . "</p>";
		}

		// the same file as other videos
		$out .= $this->renderItemDuplicates($video, $filters);

		// where it is placed: its own page, the pages that reuse it, texts with its tag
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$out .= $fieldtype->usage()->render($video, 'ProcessSelfHostedVideos');
		// how often it is watched
		if($fieldtype->useStats()) $out .= $fieldtype->stats()->render($video, 'ProcessSelfHostedVideos');

		$out .= $this->renderItemActions($video, $filters);

		$out .= "</div><div class='ProcessSelfHostedVideosChecks'>" . $this->renderChecks($video) . "</div>";

		return "<li class='ProcessSelfHostedVideosItem' data-name='" . $sanitizer->entities($video->basename()) . "' data-page='$page->id'>$out</li>";
	}

	/**
	 * Render the line that names the videos with the same file as this one
	 *
	 * @param Pagevideo $video
	 * @param array $filters
	 * @return string
	 *
	 */
	protected function renderItemDuplicates(Pagevideo $video, array $filters) {
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$others = $fieldtype->duplicates()->forVideo($video);
		if(!count($others)) return '';
		$sanitizer = $this->wire()->sanitizer;
		$finder = $fieldtype->finder();
		$links = array();
		foreach($others as $other) {
			$p = $finder->editPage($other->page);
			$label = $sanitizer->entities1((string) $p->get('title|name'));
			if($other->page->id === $video->page->id) $label = $sanitizer->entities1($other->basename());
			$links[] = $p->editable() ? "<a href='" . $sanitizer->entities($p->editUrl()) . "'>$label</a>" : $label;
		}
		$n = count($links);
		$keep = '';
		$canMerge = false;
		foreach($others as $other) {
			if($fieldtype->duplicates()->whyNot($video, $other) === '') $canMerge = true;
		}
		if($canMerge) {
			$url = $this->wire()->page->url . 'merge/?keep=' . rawurlencode($this->bulkKey($video)) . '&back=' . rawurlencode($this->url($filters));
			$keep =
				" <a class='ProcessSelfHostedVideosKeep' href='" . $sanitizer->entities($url) . "'>" .
					"<i class='fa fa-fw fa-compress' aria-hidden='true'></i>" . $sanitizer->entities($this->_('Keep this one, reuse it for the others')) .
				"</a>";
		}
		return
			"<p class='ProcessSelfHostedVideosSame'><i class='fa fa-fw fa-clone' aria-hidden='true'></i> " .
			sprintf($sanitizer->entities($this->_n('The same file as the video on %d other page:', 'The same file as the videos on %d other pages:', $n)), $n) . ' ' .
			implode(', ', $links) . $keep . "</p>";
	}

	/**
	 * Render the links to what can be done with one video: replace its file, see its plays
	 *
	 * @param Pagevideo $video
	 * @param array $filters
	 * @return string
	 *
	 */
	protected function renderItemActions(Pagevideo $video, array $filters) {
		$sanitizer = $this->wire()->sanitizer;
		$fieldtype = $this->wire()->modules->get('FieldtypeSelfHostedVideo');
		$editPage = $fieldtype->finder()->editPage($video->page);
		$links = array();
		if($editPage->id === $video->page->id && $video->page->editable($video->field->name)) {
			// to the page editor, at the button that does it: the file is chosen there
			$url = $video->page->editUrl();
			$url .= (strpos($url, '?') === false ? '?' : '&') . 'vf_open=' . $video->field->name . '_' . $video->hash . '&vf_replace=1';
			$url .= '#find-' . $video->field->name;
			$links[] =
				"<a href='" . $sanitizer->entities($url) . "'>" .
					"<i class='fa fa-fw fa-refresh' aria-hidden='true'></i>" . $sanitizer->entities($this->_('Replace video file')) .
				"</a>";
		}
		if($fieldtype->useStats()) {
			$url = $this->wire()->page->url . 'plays/?video=' . rawurlencode($this->bulkKey($video)) . '&back=' . rawurlencode($this->url($filters));
			$spark = $this->renderSparkline($fieldtype->stats()->daily($video, 30));
			$links[] =
				"<a href='" . $sanitizer->entities($url) . "'>" .
					($spark ?: "<i class='fa fa-fw fa-bar-chart' aria-hidden='true'></i>") . $sanitizer->entities($this->_('Plays in detail')) .
				"</a>";
		}
		if(!count($links)) return '';
		return "<p class='ProcessSelfHostedVideosActions'>" . implode(' ', $links) . "</p>";
	}

	/**
	 * Actions for several videos at once: value => label
	 *
	 * @return array
	 *
	 */
	protected function bulkActions() {
		return array(
			'faststart' => $this->_('Prepare for streaming'),
			'tag' => $this->_('Add a tag'),
			'untag' => $this->_('Remove a tag'),
			'delete' => $this->_('Delete'),
		);
	}

	/**
	 * The value that names a video in the form of the list: page, field and file
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function bulkKey(Pagevideo $video) {
		return $video->page->id . '|' . $video->field->name . '|' . $video->basename();
	}

	/**
	 * Render the bar above the list: what it holds, and the actions on several videos at once
	 *
	 * @param array $result
	 * @param array $filters
	 * @param array $tags Tags in use, with labels
	 * @param string $summary Markup of the summary of the list (see renderSummary())
	 * @return string
	 *
	 */
	protected function renderBulk(array $result, array $filters, array $tags, $summary = '') {

		$sanitizer = $this->wire()->sanitizer;
		$options = "<option value=''>" . $sanitizer->entities($this->_('Choose an action …')) . "</option>";
		foreach($this->bulkActions() as $value => $label) {
			$options .= "<option value='$value'>" . $sanitizer->entities($label) . "</option>";
		}
		$list = '';
		foreach($tags as $tag => $label) {
			$list .= "<option value='" . $sanitizer->entities($tag) . "'>" . ($label !== $tag ? $sanitizer->entities($label) : '') . "</option>";
		}

		$total = (int) $result['total'];
		$all = '';
		if($total > count($result['items'])) {
			$all =
				"<label class='ProcessSelfHostedVideosBulkAll'>" .
					"<input type='checkbox' class='uk-checkbox' name='vf_bulk_all' value='1'> " .
					"<span>" . $sanitizer->entities(sprintf($this->_n('The %d video of this choice', 'All %d videos of this choice, also those on the other pages of the list', $total), $total)) . "</span>" .
				"</label>";
		}

		$group = $sanitizer->entities($this->_('Several videos at once'));
		// texts for the script, which says how many are selected
		$data =
			" data-selected='" . $sanitizer->entities($this->_('%1$d of %2$d selected')) . "'" .
			" data-apply='" . $sanitizer->entities($this->_('Apply')) . "'";

		// One bar above the list: what the list holds, and, once videos are selected, what to do with
		// them. Without the script all of it is shown at once and works the same.
		return
			"<div class='ProcessSelfHostedVideosBar'$data>" .
				"<div class='ProcessSelfHostedVideosBarMain'>" .
					"<label class='ProcessSelfHostedVideosSelectAll'>" .
						"<input type='checkbox' class='uk-checkbox' data-shv-select-all hidden>" .
						"<span class='ProcessSelfHostedVideosSr'>" . $sanitizer->entities($this->_('Select all on this page')) . "</span>" .
					"</label>" .
					$summary .
					"<p class='ProcessSelfHostedVideosSelected' aria-live='polite' hidden></p>" .
					"<button type='button' class='ProcessSelfHostedVideosClear' hidden>" . $sanitizer->entities($this->_('Clear selection')) . "</button>" .
					"<p class='ProcessSelfHostedVideosBarHint' hidden>" .
						"<i class='fa fa-hand-pointer-o' aria-hidden='true'></i> " . $sanitizer->entities($this->_('Select videos to work on several at once.')) .
					"</p>" .
				"</div>" .
				"<div class='ProcessSelfHostedVideosBulk' role='group' aria-label='$group'>" .
					"<label class='ProcessSelfHostedVideosBulkField'>" .
						"<span class='ProcessSelfHostedVideosSr'>" . $sanitizer->entities($this->_('With the selected videos')) . "</span>" .
						"<select name='vf_bulk' class='uk-select ProcessSelfHostedVideosBulkAction'>$options</select>" .
					"</label>" .
					"<label class='ProcessSelfHostedVideosBulkField ProcessSelfHostedVideosBulkTag'>" .
						"<span class='ProcessSelfHostedVideosSr'>" . $sanitizer->entities($this->_('Tag')) . "</span>" .
						"<input type='text' name='vf_bulk_tag' class='uk-input' list='ProcessSelfHostedVideosBulkTags' maxlength='100' autocomplete='off' placeholder='" . $sanitizer->entities($this->_('Tag')) . "'>" .
						"<datalist id='ProcessSelfHostedVideosBulkTags'>$list</datalist>" .
					"</label>" .
					"<button type='submit' class='ProcessSelfHostedVideosBulkApply'>" .
						"<span class='ProcessSelfHostedVideosBulkApplyText'>" . $sanitizer->entities($this->_('Apply')) . "</span>" .
					"</button>" .
					$all .
				"</div>" .
			"</div>";
	}

	/**
	 * Carry out an action on several videos at once
	 *
	 * Only original videos of pages the user may edit are changed. Reused videos are not deleted
	 * (they would vanish from the pages that show them). Deleting asks first.
	 *
	 * @param FieldtypeSelfHostedVideo $fieldtype
	 * @param array $filters
	 * @return string|null The question to ask before deleting, or null when done (messages tell what happened)
	 *
	 */
	protected function processBulk(FieldtypeSelfHostedVideo $fieldtype, array $filters) {

		$input = $this->wire()->input;
		$sanitizer = $this->wire()->sanitizer;
		$pages = $this->wire()->pages;
		$session = $this->wire()->session;
		$finder = $fieldtype->finder();

		if(!$session->CSRF->hasValidToken('shv-bulk')) {
			$this->error($this->_('The form has expired. Please try again.'));
			return null;
		}

		$action = (string) $input->post('vf_bulk');
		$actions = $this->bulkActions();
		if(!isset($actions[$action])) {
			$this->warning($this->_('Please choose an action.'));
			return null;
		}

		// which videos
		$videos = array();
		if((int) $input->post('vf_bulk_all')) {
			$result = $finder->find(array(
				'q' => $filters['q'], 'tag' => $filters['tag'], 'field' => $filters['field'], 'show' => $filters['show'],
				'checklist' => strlen($filters['check']) ? $filters['check'] : 'all', 'sort' => $filters['sort'], 'limit' => 0,
			));
			foreach($result['items'] as $video) $videos[$this->bulkKey($video)] = $video;
		} else {
			$fields = $fieldtype->getVideoFields();
			$keys = $input->post('vf_select');
			foreach(is_array($keys) ? array_slice($keys, 0, 1000) : array() as $key) {
				$parts = explode('|', (string) $key, 3);
				if(count($parts) !== 3 || !ctype_digit($parts[0]) || !isset($fields[$parts[1]])) continue;
				$page = $pages->get((int) $parts[0]);
				if(!$page->id || $page->isTrash()) continue;
				$value = $page->getUnformatted($parts[1]);
				$video = $value instanceof Pagevideos ? $value->getFile(basename($parts[2])) : null;
				if($video instanceof Pagevideo && !$video->isReference()) $videos[$this->bulkKey($video)] = $video;
			}
		}

		if(!count($videos)) {
			$this->warning($this->_('No videos were selected.'));
			return null;
		}

		// only where the user may edit the page
		$allowed = array();
		$notAllowed = 0;
		foreach($videos as $key => $video) {
			// the field itself may have edit rights of its own (Setup > Fields > Access)
			$editPage = $finder->editPage($video->page);
			$editable = $editPage === $video->page && $video->field
				? $editPage->editable($video->field->name)
				: $editPage->editable();
			if($editable) {
				$allowed[$key] = $video;
			} else {
				$notAllowed++;
			}
		}
		if($notAllowed) $this->warning(sprintf($this->_n('%d video was left out: you may not edit its page.', '%d videos were left out: you may not edit their pages.', $notAllowed), $notAllowed));

		$tag = '';
		if($action === 'tag' || $action === 'untag') {
			// one word, as tags are kept: spaces become underscores
			$tag = str_replace(' ', '_', trim($sanitizer->text((string) $input->post('vf_bulk_tag'), array('maxLength' => 100))));
			$tag = trim(preg_replace('/[\s,]+/u', '_', $tag), '_');
			if(!strlen($tag)) {
				$this->warning($this->_('Please enter a tag.'));
				return null;
			}
		}

		if($action === 'delete' && !(int) $input->post('vf_confirm')) {
			return $this->renderDeleteQuestion($allowed, $filters);
		}

		$done = 0;
		$skipped = array();
		$changed = array(); // page id|field => [ page, field name ]

		foreach($allowed as $key => $video) {
			$page = $video->page;
			$field = $video->field;
			$name = strlen($video->title()) ? $video->title() : $video->basename();
			$page->of(false);

			if($action === 'faststart') {
				$error = '';
				if($video->faststart($error)) {
					$done++;
					$changed[$page->id . '|' . $field->name] = array($page, $field->name);
				} else if(strlen($error)) {
					$skipped[] = "$name ($error)";
				}

			} else if($action === 'tag' || $action === 'untag') {
				$useTags = (int) $field->get('useTags');
				if(!$useTags) {
					$skipped[] = sprintf($this->_('%s (its field has no tags)'), $name);
					continue;
				}
				if($action === 'tag' && $useTags === FieldtypeFile::useTagsPredefined) {
					$allowedTags = preg_split('/\s+/', trim((string) $field->get('tagsList')));
					if(!in_array($tag, $allowedTags, true)) {
						$skipped[] = sprintf($this->_('%s (its field only takes the tags set for it)'), $name);
						continue;
					}
				}
				$has = $video->hasTag($tag);
				if($action === 'tag' && !$has) $video->addTag($tag);
				if($action === 'untag' && $has) $video->removeTag($tag);
				if(($action === 'tag') !== $has) {
					$done++;
					$changed[$page->id . '|' . $field->name] = array($page, $field->name);
				}

			} else if($action === 'delete') {
				if($video->isReferenced()) {
					$skipped[] = sprintf($this->_('%s (reused on other pages)'), $name);
					continue;
				}
				$page->getUnformatted($field->name)->delete($video);
				$done++;
				$changed[$page->id . '|' . $field->name] = array($page, $field->name);
			}
		}

		foreach($changed as $item) {
			list($page, $fieldName) = $item;
			$page->trackChange($fieldName);
			$page->save($fieldName);
		}

		$messages = array(
			'faststart' => $this->_n('%d video prepared for streaming.', '%d videos prepared for streaming.', $done),
			'tag' => $this->_n('Tag “%2$s” added to %1$d video.', 'Tag “%2$s” added to %1$d videos.', $done),
			'untag' => $this->_n('Tag “%2$s” removed from %1$d video.', 'Tag “%2$s” removed from %1$d videos.', $done),
			'delete' => $this->_n('%d video deleted.', '%d videos deleted.', $done),
		);
		$this->message(sprintf($messages[$action], $done, $tag));
		if(count($skipped)) {
			$more = count($skipped) > 10 ? ' …' : '';
			$this->warning(sprintf($this->_n('Left as it is: %s', 'Left as they are: %s', count($skipped)), implode('; ', array_slice($skipped, 0, 10)) . $more));
		}

		return null;
	}

	/**
	 * Ask before videos are deleted
	 *
	 * @param array $videos Videos the user may delete, keyed as bulkKey() does
	 * @param array $filters
	 * @return string
	 *
	 */
	protected function renderDeleteQuestion(array $videos, array $filters) {

		$sanitizer = $this->wire()->sanitizer;
		$finder = $this->wire()->modules->get('FieldtypeSelfHostedVideo')->finder();
		$items = '';
		$hidden = '';
		$reused = 0;
		$inText = 0;
		$usage = $this->wire()->modules->get('FieldtypeSelfHostedVideo')->usage();

		foreach($videos as $key => $video) {
			$name = strlen($video->title()) ? $video->title() : $video->basename();
			$page = $finder->editPage($video->page);
			$note = '';
			if($video->isReferenced()) {
				$reused++;
				$note = " <span class='ProcessSelfHostedVideosBadge'>" . $sanitizer->entities($this->_('reused: will be kept')) . "</span>";
			} else {
				$texts = $usage->textsOnPage($video, $video->page);
				if(count($texts)) {
					$inText++;
					$labels = array();
					foreach($texts as $field) $labels[] = '“' . $field->getLabel() . '”';
					$note =
						" <span class='ProcessSelfHostedVideosBadge ProcessSelfHostedVideosBadgeWarn'>" .
						htmlspecialchars(sprintf($this->_('in the text %s: leaves an empty place'), implode(', ', $labels)), ENT_QUOTES, 'UTF-8') .
						"</span>";
				}
			}
			$items .=
				"<li><strong>" . $sanitizer->entities1($name) . "</strong> " .
				"<span class='ProcessSelfHostedVideosPath'>" . $sanitizer->entities($video->basename() . ' · ' . $page->path()) . "</span>$note</li>";
			$hidden .= "<input type='hidden' name='vf_select[]' value='" . $sanitizer->entities($key) . "'>";
		}

		$count = count($videos) - $reused;
		$title = $sanitizer->entities(sprintf($this->_n('Delete %d video?', 'Delete %d videos?', $count), $count));
		$button = $sanitizer->entities(sprintf($this->_n('Delete %d video', 'Delete %d videos', $count), $count));

		return
			"<form class='ProcessSelfHostedVideosConfirm' method='post' action='" . $sanitizer->entities($this->url($filters)) . "'>" .
				$this->wire()->session->CSRF->renderInput('shv-bulk') .
				"<h2>$title</h2>" .
				"<p>" . $sanitizer->entities($this->_('The files are removed from their pages and cannot be brought back. Videos that are reused on other pages are kept.')) . "</p>" .
				($inText ? "<p class='ProcessSelfHostedVideosConfirmWarning' role='alert'><i class='fa fa-fw fa-exclamation-triangle' aria-hidden='true'></i> " .
					htmlspecialchars(sprintf($this->_n('%d of them is placed with a tag in a text of its page. The tag shows nothing there after deleting: remove it, or place another video.', '%d of them are placed with tags in texts of their pages. The tags show nothing there after deleting: remove them, or place other videos.', $inText), $inText), ENT_QUOTES, 'UTF-8') .
					"</p>" : '') .
				"<ul class='ProcessSelfHostedVideosConfirmList'>$items</ul>" .
				$hidden .
				"<input type='hidden' name='vf_bulk' value='delete'>" .
				"<input type='hidden' name='vf_confirm' value='1'>" .
				"<p class='ProcessSelfHostedVideosConfirmButtons'>" .
					($count > 0 ? "<button type='submit' class='ui-button ui-widget ui-corner-all ui-state-default'><i class='fa fa-trash' aria-hidden='true'></i> $button</button> " : '') .
					"<a class='ui-button ui-widget ui-corner-all ui-state-default ui-priority-secondary' href='" . $sanitizer->entities($this->url($filters)) . "'>" . $sanitizer->entities($this->_('Cancel')) . "</a>" .
				"</p>" .
			"</form>";
	}

	/**
	 * Render the small player of a video
	 *
	 * @param Pagevideo $video
	 * @param string $name Title or file name
	 * @return string
	 *
	 */
	protected function renderPlayer(Pagevideo $video, $name) {

		$sanitizer = $this->wire()->sanitizer;
		$poster = '';
		$thumb = $video->thumb();

		if($thumb) {
			$poster = $thumb->URL;
			try {
				if($thumb->width() > self::thumbWidth) {
					$sized = $thumb->width(self::thumbWidth);
					if($sized && is_file($sized->filename())) $poster = $sized->URL;
				}
			} catch(\Exception $e) {
				// the image at its stored size does the job too
			}
		}

		$attrs = "controls preload='none' playsinline";
		if($video->width() && $video->height()) $attrs .= " width='" . $video->width() . "' height='" . $video->height() . "'";
		if(strlen($poster)) $attrs .= " poster='" . $sanitizer->entities($poster) . "'";
		$class = 'ProcessSelfHostedVideosVideo' . ($video->isPortrait() ? ' ProcessSelfHostedVideosVideoPortrait' : '');

		return
			"<video class='$class' $attrs aria-label='" . $sanitizer->entities($name) . "'>" .
				"<source src='" . $sanitizer->entities($video->URL) . "'>" .
			"</video>";
	}

	/**
	 * Render what is open on the checklist of a video
	 *
	 * @param Pagevideo $video
	 * @return string
	 *
	 */
	protected function renderChecks(Pagevideo $video) {

		$sanitizer = $this->wire()->sanitizer;
		$items = '';
		$open = 0;

		foreach($video->checks() as $check) {
			if($check->status !== 'todo' && $check->status !== 'warning') continue;
			$open++;
			$icon = $check->status === 'todo' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle';
			$word = $this->_('Worth knowing:');
			if($check->status === 'todo') $word = $this->_('To do:');
			$note = strlen((string) $check->note) ? "<span class='ProcessSelfHostedVideosCheckNote'>" . $sanitizer->entities($check->note) . "</span>" : '';
			if($check->get('tools')) $note .= $this->wire(new SelfHostedVideoChecks())->renderTools($check, 'ProcessSelfHostedVideos');
			$items .=
				"<li class='ProcessSelfHostedVideosCheck ProcessSelfHostedVideosCheck_$check->status'>" .
					"<i class='fa fa-fw $icon' aria-hidden='true'></i>" .
					"<span class='ProcessSelfHostedVideosCheckText'>" .
						"<span class='ProcessSelfHostedVideosSr'>" . $sanitizer->entities($word) . " </span>" .
						"<span class='ProcessSelfHostedVideosCheckLabel'>" . $sanitizer->entities($check->label) . "</span>" .
						$note .
					"</span>" .
				"</li>";
		}

		if(!$open) {
			return
				"<p class='ProcessSelfHostedVideosChecksDone'><i class='fa fa-fw fa-check-circle' aria-hidden='true'></i> " .
				$sanitizer->entities($this->_('Checklist complete')) . "</p>";
		}

		$legend = $sanitizer->entities(sprintf($this->_n('%d point to check', '%d points to check', $open), $open));

		return "<p class='ProcessSelfHostedVideosChecksLegend'>$legend</p><ul class='ProcessSelfHostedVideosChecksList'>$items</ul>";
	}

	/**
	 * Render the links to the other pages of the list
	 *
	 * @param array $result
	 * @param array $filters
	 * @return string
	 *
	 */
	protected function renderPager(array $result, array $filters) {

		$sanitizer = $this->wire()->sanitizer;
		$perPage = $this->perPage();
		$total = (int) $result['total'];
		$pages = (int) ceil($total / $perPage);
		if($pages < 2) return '';

		$current = (int) floor($result['start'] / $perPage) + 1;
		$first = ($current - 1) * $perPage + 1;
		$last = min($total, $current * $perPage);

		$link = function($n, $text, $class, $rel = '', $label = '') use($sanitizer, $filters) {
			$url = $sanitizer->entities($this->url($filters, array('pg' => $n)));
			return
				"<a class='$class' href='$url'" . ($rel ? " rel='$rel'" : '') . ($label ? " aria-label='" . $sanitizer->entities($label) . "'" : '') . ">" .
					$text .
				"</a>";
		};

		// the first and the last page, the current one and its neighbours; gaps in between
		$numbers = array(1, $pages);
		for($n = $current - 1; $n <= $current + 1; $n++) if($n >= 1 && $n <= $pages) $numbers[] = $n;
		// near either end, the first or last five, so that no gap stands for a single page
		if($current <= 4) for($n = 2; $n <= min(5, $pages); $n++) $numbers[] = $n;
		if($current >= $pages - 3) for($n = max(1, $pages - 4); $n < $pages; $n++) $numbers[] = $n;
		$numbers = array_unique($numbers);
		sort($numbers);

		$items = '';
		$prevNumber = 0;
		foreach($numbers as $n) {
			if($prevNumber && $n > $prevNumber + 1) {
				$items .= "<li class='ProcessSelfHostedVideosPagerGap' aria-hidden='true'>…</li>";
			}
			$label = sprintf($this->_('Page %d'), $n);
			if($n === $current) {
				$items .= "<li><span class='ProcessSelfHostedVideosPagerNumber ProcessSelfHostedVideosPage' aria-current='page'>" .
					"<span class='ProcessSelfHostedVideosSr'>" . $sanitizer->entities(sprintf($this->_('Page %1$d of %2$d'), $current, $pages)) . "</span>" .
					"<span aria-hidden='true'>$n</span></span></li>";
			} else {
				$items .= "<li>" . $link($n, (string) $n, 'ProcessSelfHostedVideosPagerNumber', '', $label) . "</li>";
			}
			$prevNumber = $n;
		}

		$prevText = "<i class='fa fa-angle-left' aria-hidden='true'></i> <span>" . $sanitizer->entities($this->_('Previous')) . "</span>";
		$nextText = "<span>" . $sanitizer->entities($this->_('Next')) . "</span> <i class='fa fa-angle-right' aria-hidden='true'></i>";
		$prev = $current > 1
			? $link($current - 1, $prevText, 'ProcessSelfHostedVideosPagerStep ProcessSelfHostedVideosPrev', 'prev')
			: "<span class='ProcessSelfHostedVideosPagerStep ProcessSelfHostedVideosPagerOff' aria-hidden='true'>$prevText</span>";
		$next = $current < $pages
			? $link($current + 1, $nextText, 'ProcessSelfHostedVideosPagerStep ProcessSelfHostedVideosNext', 'next')
			: "<span class='ProcessSelfHostedVideosPagerStep ProcessSelfHostedVideosPagerOff' aria-hidden='true'>$nextText</span>";

		$range = $first === $last
			? $sanitizer->entities(sprintf($this->_('Video %1$d of %2$d'), $first, $total))
			: $sanitizer->entities(sprintf($this->_('Videos %1$d–%2$d of %3$d'), $first, $last, $total));
		$label = $sanitizer->entities($this->_('Pages of the list'));

		return
			"<nav class='ProcessSelfHostedVideosPager' aria-label='$label'>" .
				"<p class='ProcessSelfHostedVideosPagerRange'>$range</p>" .
				"<div class='ProcessSelfHostedVideosPagerLinks'>" .
					$prev .
					"<ul class='ProcessSelfHostedVideosPagerNumbers'>$items</ul>" .
					$next .
				"</div>" .
			"</nav>";
	}

	/**
	 * Write a number of seconds as hours and minutes, or minutes and seconds
	 *
	 * @param float $seconds
	 * @return string
	 *
	 */
	protected function formatDuration($seconds) {
		$seconds = (int) round($seconds);
		$h = (int) floor($seconds / 3600);
		$m = (int) floor(($seconds % 3600) / 60);
		$s = $seconds % 60;
		return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
	}
}
