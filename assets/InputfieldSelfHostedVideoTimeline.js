/**
 * Video Files input: editor for the chapters and overlays of a video
 *
 * Opens below a video in the page editor. It has a large preview on which overlays are placed
 * by dragging or with the arrow keys, a timeline that shows when chapters begin and overlays
 * are visible, and a list each for chapters and overlays. On the timeline, chapter marks and
 * the bars of overlays can be dragged to another time, and the ends of a bar to another
 * beginning or end. Everything is kept in a hidden field of the form and saved with the page.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 */
(function($) {

	'use strict';

	// Everything the scripts of this module share with the page is kept under one name,
	// FieldtypeSelfHostedVideo, so that nothing of it can meet a name of another script.
	var ns = window.FieldtypeSelfHostedVideo = window.FieldtypeSelfHostedVideo || {};

	// set up once, however often the page loads this file
	if(ns.timeline) return;
	ns.timeline = {};

	function config() {
		var c = window.ProcessWire && ProcessWire.config ? ProcessWire.config.InputfieldSelfHostedVideo : null;
		return c && c.timeline ? c.timeline : { labels: {}, languages: [ { id: 0, title: '', editable: true } ], language: 0 };
	}

	function label(name) {
		var labels = config().labels || {};
		return labels[name] || '';
	}

	/** Fill the placeholders %s, %d, %1$s … of a text */
	function fill(text) {
		var args = Array.prototype.slice.call(arguments, 1);
		var n = 0;
		return String(text).replace(/%(?:(\d+)\$)?[sd]/g, function(match, position) {
			var value = position ? args[parseInt(position, 10) - 1] : args[n++];
			return value === undefined ? '' : value;
		});
	}

	function parseTime(value) {
		value = String(value === undefined || value === null ? '' : value).trim().replace(',', '.');
		var m = /^(?:(\d+):)?(?:(\d+):)?(\d+(?:\.\d+)?)$/.exec(value);
		if(!m) return null;
		if(m[1] !== undefined && m[2] !== undefined) return parseInt(m[1], 10) * 3600 + parseInt(m[2], 10) * 60 + parseFloat(m[3]);
		if(m[1] !== undefined) return parseInt(m[1], 10) * 60 + parseFloat(m[3]);
		return parseFloat(m[3]);
	}

	function formatTime(seconds) {
		seconds = Math.max(0, Math.round((parseFloat(seconds) || 0) * 10) / 10);
		var whole = Math.floor(seconds);
		var tenth = Math.round((seconds - whole) * 10);
		if(tenth >= 10) {
			whole++;
			tenth = 0;
		}
		var h = Math.floor(whole / 3600);
		var m = Math.floor((whole % 3600) / 60);
		var s = whole % 60;
		var out = h ? h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s : m + ':' + (s < 10 ? '0' : '') + s;
		return tenth ? out + '.' + tenth : out;
	}

	function newId() {
		var id = '';
		while(id.length < 6) id += Math.floor(Math.random() * 36).toString(36);
		return id;
	}

	function clamp(value, min, max) {
		return Math.max(min, Math.min(max, value));
	}

	function round1(value) {
		return Math.round(value * 10) / 10;
	}

	function anchor(percent, first, middle, last) {
		if(percent < 34) return first;
		if(percent > 66) return last;
		return middle;
	}

	function announce(text) {
		var $live = $('#InputfieldSelfHostedVideoLive');
		if(!$live.length) {
			$live = $('<div id="InputfieldSelfHostedVideoLive" class="InputfieldSelfHostedVideoLive" role="status" aria-live="polite"></div>').appendTo(document.body);
		}
		$live.text(text);
	}

	var counter = 0;

	/**
	 * The editor of one video
	 */
	function Editor($root) {

		var self = this;
		var $field = $root.find('.InputfieldSelfHostedVideoTimelineData');
		var $panel = $root.find('.InputfieldSelfHostedVideoTimelinePanel');
		var $toggle = $root.find('.InputfieldSelfHostedVideoTimelineToggle');
		var uid = 'vftl' + (++counter);
		var languages = $root.attr('data-nolang') === '1' ? [ config().languages[0] ] : config().languages;
		var lang = 0;
		var data = { chapters: [], overlays: [] };
		var selected = '';
		var openCards = {};      // the overlays whose settings are open: they are closed until asked for
		var built = false;
		var video = null;
		var barDrag = null;      // what is being dragged on the timeline
		var barStale = false;    // chapter marks are no longer in the order of their times
		var skipClick = false;   // the click that ends a drag is not a click on the bar
		var wanted = null;       // where the preview is to go once it has arrived where it is going
		var $stage, $layer, $bar, $chapters, $overlays, $langs;

		try {
			var parsed = JSON.parse($field.val() || '{}');
			if(parsed && $.isArray(parsed.chapters)) data.chapters = parsed.chapters;
			if(parsed && $.isArray(parsed.overlays)) data.overlays = parsed.overlays;
		} catch(e) {
			data = { chapters: [], overlays: [] };
		}

		// texts per language are objects indexed by language ID. A list (as stored when only
		// the default language has a text) becomes one.
		function texts(value) {
			var out = {};
			if(typeof value === 'string') {
				if(value) out[0] = value;
			} else if(value && typeof value === 'object') {
				$.each(value, function(key, text) {
					if(typeof text === 'string' && text.length) out[key] = text;
				});
			}
			return out;
		}

		$.each(data.chapters, function(n, chapter) {
			chapter.title = texts(chapter.title);
			if(!chapter.id) chapter.id = newId();
		});
		$.each(data.overlays, function(n, overlay) {
			overlay.text = texts(overlay.text);
			overlay.url = texts(overlay.url);
			if(!overlay.id) overlay.id = newId();
		});

		$.each(languages, function(n, language) {
			if(String(language.id) === String(config().language)) lang = language.id;
		});

		function language() {
			for(var n = 0; n < languages.length; n++) {
				if(languages[n].id === lang) return languages[n];
			}
			return languages[0];
		}

		function duration() {
			if(video && isFinite(video.duration) && video.duration > 0) return video.duration;
			return parseFloat($root.attr('data-duration')) || 0;
		}

		function now() {
			return video ? round1(video.currentTime || 0) : 0;
		}

		function textOf(map, fallback) {
			if(map && map[lang]) return map[lang];
			return fallback && map && map[0] ? map[0] : '';
		}

		function setText(map, value) {
			if(value) {
				map[lang] = value;
			} else {
				delete map[lang];
			}
		}

		/** Write everything to the form field, so that it is saved with the page */
		function commit() {
			data.chapters.sort(function(a, b) { return a.time - b.time; });
			$field.val(JSON.stringify(data)).trigger('change');
			$root.closest('.Inputfield').addClass('InputfieldStateChanged');
			showCount();
		}

		function showCount() {
			var parts = [];
			var c = data.chapters.length, o = data.overlays.length;
			if(c) parts.push(c === 1 ? label('chapterCount1') : fill(label('chapterCountN'), c));
			if(o) parts.push(o === 1 ? label('overlayCount1') : fill(label('overlayCountN'), o));
			$root.find('.InputfieldSelfHostedVideoTimelineCount').text(parts.length ? '(' + parts.join(', ') + ')' : '');
		}

		function seek(time) {
			if(!video) return;
			try {
				video.currentTime = Math.max(0, time);
			} catch(e) { /* not ready to be moved yet */ }
			refreshTime();
		}

		/** Follow something that is being moved: go there as soon as the preview is ready for it */
		function scrub(time) {
			if(!video) return;
			wanted = time;
			if(!video.seeking) arrive();
		}

		function arrive() {
			if(wanted === null) return;
			var time = wanted;
			wanted = null;
			seek(time);
		}

		/*** Building the panel ***************************************************************/

		function build() {
			built = true;

			var html =
				'<p class="InputfieldSelfHostedVideoTimelineHint detail">' + $('<i>').text(label('saveHint')).html() + '</p>';

			if(languages.length > 1) {
				html +=
					'<div class="InputfieldSelfHostedVideoTimelineLangs" role="group" aria-labelledby="' + uid + '_langs">' +
						'<span class="InputfieldSelfHostedVideoLegend detail" id="' + uid + '_langs"></span>' +
					'</div>';
			}

			html +=
				'<div class="InputfieldSelfHostedVideoStage shv-video__frame">' +
					'<video class="InputfieldSelfHostedVideoStageVideo" controls playsinline preload="metadata"></video>' +
					'<div class="shv-video__overlays"></div>' +
				'</div>' +
				'<p class="InputfieldSelfHostedVideoTimelineHint detail" id="' + uid + '_drag"></p>' +
				'<div class="InputfieldSelfHostedVideoBar" role="group"></div>' +
				'<p class="InputfieldSelfHostedVideoTimelineHint InputfieldSelfHostedVideoBarHint detail" id="' + uid + '_bar"></p>' +
				'<div class="InputfieldSelfHostedVideoTimelineLists">' +
					// groups with a line around them and their name in it, as the areas of a video are
					'<fieldset class="InputfieldSelfHostedVideoArea InputfieldSelfHostedVideoChapters" aria-describedby="' + uid + '_chapters_note">' +
						'<legend class="InputfieldSelfHostedVideoLegend" id="' + uid + '_chapters"></legend>' +
						'<div class="InputfieldSelfHostedVideoAreaBody">' +
							'<p class="InputfieldSelfHostedVideoTimelineHint InputfieldSelfHostedVideoChaptersNote detail" id="' + uid + '_chapters_note"></p>' +
							'<div class="InputfieldSelfHostedVideoChapterList"></div>' +
							'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoChapterAdd"><i class="fa fa-fw fa-plus" aria-hidden="true"></i><span></span></button>' +
						'</div>' +
					'</fieldset>' +
					'<fieldset class="InputfieldSelfHostedVideoArea InputfieldSelfHostedVideoOverlays">' +
						'<legend class="InputfieldSelfHostedVideoLegend" id="' + uid + '_overlays"></legend>' +
						'<div class="InputfieldSelfHostedVideoAreaBody">' +
							'<div class="InputfieldSelfHostedVideoOverlayList"></div>' +
							'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoOverlayAdd"><i class="fa fa-fw fa-plus" aria-hidden="true"></i><span></span></button>' +
						'</div>' +
					'</fieldset>' +
				'</div>';

			$panel.html(html);

			$stage = $panel.find('.InputfieldSelfHostedVideoStage');
			$layer = $stage.find('.shv-video__overlays');
			$bar = $panel.find('.InputfieldSelfHostedVideoBar');
			$chapters = $panel.find('.InputfieldSelfHostedVideoChapterList');
			$overlays = $panel.find('.InputfieldSelfHostedVideoOverlayList');
			$langs = $panel.find('.InputfieldSelfHostedVideoTimelineLangs');
			video = $stage.find('video')[0];

			$panel.find('#' + uid + '_langs').text(label('language'));
			$panel.find('#' + uid + '_drag').text(label('dragHint'));
			$panel.find('#' + uid + '_bar').text(label('barHint'));
			$panel.find('#' + uid + '_chapters').text(label('chapters'));
			$panel.find('#' + uid + '_chapters_note').text(label('chaptersNote'));
			$panel.find('#' + uid + '_overlays').text(label('overlays'));
			$panel.find('.InputfieldSelfHostedVideoOverlayAdd span').text(label('addOverlay'));
			$bar.attr('aria-label', label('timeline'));
			$(video).attr({ src: $root.attr('data-url'), 'aria-label': label('stage') });
			if($root.attr('data-poster')) $(video).attr('poster', $root.attr('data-poster'));
			if(parseInt($root.attr('data-width'), 10) && parseInt($root.attr('data-height'), 10)) {
				$stage.css('aspect-ratio', $root.attr('data-width') + ' / ' + $root.attr('data-height'));
				$stage.toggleClass('InputfieldSelfHostedVideoStagePortrait', parseInt($root.attr('data-width'), 10) < parseInt($root.attr('data-height'), 10));
			}

			$(video).on('timeupdate seeked play pause ended loadedmetadata durationchange', refreshTime);
			$(video).on('seeked', arrive);
			$(video).on('loadedmetadata durationchange', placeBars);

			bind();
			renderLangs();
			renderAll();
		}

		function renderAll() {
			renderChapters();
			renderOverlays();
			renderStage();
			renderBar();
			refreshTime();
		}

		function renderLangs() {
			if(languages.length < 2) return;
			$langs.find('button').remove();
			$.each(languages, function(n, language) {
				$('<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoLang"></button>')
					.text(language.title)
					.attr({ 'data-lang': language.id, 'aria-pressed': language.id === lang ? 'true' : 'false' })
					.appendTo($langs);
			});
		}

		/*** Chapters *************************************************************************/

		function renderChapters() {
			var editable = language().editable !== false;
			$chapters.empty();

			if(!data.chapters.length) {
				$('<p class="InputfieldSelfHostedVideoNone detail"></p>').text(label('noChapters')).appendTo($chapters);
			}

			$.each(data.chapters, function(n, chapter) {
				var number = n + 1;
				var $row = $(
					'<div class="InputfieldSelfHostedVideoChapter">' +
						'<input type="text" class="InputfieldSelfHostedVideoChapterTime uk-input" inputmode="decimal" autocomplete="off">' +
						'<input type="text" class="InputfieldSelfHostedVideoChapterTitle uk-input" maxlength="200" autocomplete="off">' +
						'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoChapterGo"><i class="fa fa-fw fa-step-forward" aria-hidden="true"></i><span></span></button>' +
						'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoChapterRemove"><i class="fa fa-fw fa-trash-o" aria-hidden="true"></i><span class="InputfieldSelfHostedVideoSr"></span></button>' +
					'</div>'
				).attr('data-id', chapter.id);

				$row.find('.InputfieldSelfHostedVideoChapterTime').val(formatTime(chapter.time)).attr('aria-label', fill(label('chapterTime'), number));
				$row.find('.InputfieldSelfHostedVideoChapterTitle')
					.val(textOf(chapter.title, false))
					.attr({ 'aria-label': fill(label('chapterTitle'), number), placeholder: lang !== 0 && chapter.title[0] ? chapter.title[0] : label('title') })
					.prop('readonly', !editable);
				$row.find('.InputfieldSelfHostedVideoChapterGo span').text(label('goTo'));
				$row.find('.InputfieldSelfHostedVideoChapterGo').attr('aria-label', fill(label('goToLabel'), formatTime(chapter.time)));
				$row.find('.InputfieldSelfHostedVideoChapterRemove').attr('title', label('remove')).find('span').text(fill(label('removeChapter'), number));
				$chapters.append($row);
			});

			refreshAdd();
		}

		function refreshAdd() {
			if(!built) return;
			$panel.find('.InputfieldSelfHostedVideoChapterAdd span').text(fill(label('addChapter'), formatTime(now())));
		}

		function chapterById(id) {
			for(var n = 0; n < data.chapters.length; n++) {
				if(data.chapters[n].id === id) return data.chapters[n];
			}
			return null;
		}

		/*** Overlays *************************************************************************/

		function overlayById(id) {
			for(var n = 0; n < data.overlays.length; n++) {
				if(data.overlays[n].id === id) return data.overlays[n];
			}
			return null;
		}

		function field(labelText, control, hint) {
			var id = uid + '_f' + (++counter);
			var $wrap = $('<div class="InputfieldSelfHostedVideoOverlayField"></div>');
			$('<label></label>').attr('for', id).text(labelText).appendTo($wrap);
			$(control).attr('id', id).appendTo($wrap);
			if(hint) {
				$('<span class="InputfieldSelfHostedVideoFieldHint detail"></span>').attr('id', id + '_h').text(hint).appendTo($wrap);
				$wrap.find('#' + id).attr('aria-describedby', id + '_h');
			}
			return $wrap;
		}

		function select(name, options, value) {
			var $select = $('<select class="uk-select"></select>').attr('data-prop', name);
			$.each(options, function(key, text) {
				$('<option></option>').attr('value', key).text(text).appendTo($select);
			});
			return $select.val(value);
		}

		function cardOf(id) {
			return $overlays.children('.InputfieldSelfHostedVideoOverlayCard').filter(function() { return $(this).attr('data-id') === id; });
		}

		/** Open or close the settings of an overlay */
		function setCardOpen(id, open) {
			if(open) {
				openCards[id] = true;
			} else {
				delete openCards[id];
			}
			var $card = cardOf(id);
			$card.toggleClass('InputfieldSelfHostedVideoOverlayOpen', !!open);
			$card.find('.InputfieldSelfHostedVideoOverlayToggle').attr('aria-expanded', open ? 'true' : 'false');
			$card.children('.InputfieldSelfHostedVideoAreaBody').prop('hidden', !open);
		}

		/** What a closed overlay says about itself: its text, and when it is shown */
		function summaryOf(overlay) {
			var text = textOf(overlay.text, true).replace(/\s+/g, ' ').trim() || label('emptyText');
			var when = label('whenAlways');
			if(overlay.when === 'end') when = label('whenEnd');
			if(overlay.when === 'range') when = formatTime(startOf(overlay)) + ' – ' + formatTime(endOf(overlay));
			return text + ' · ' + when;
		}

		function showSummary(overlay) {
			cardOf(overlay.id).children('.InputfieldSelfHostedVideoOverlaySummary').text(summaryOf(overlay));
		}

		function renderOverlays() {
			var editable = language().editable !== false;
			$overlays.empty();

			if(!data.overlays.length) {
				$('<p class="InputfieldSelfHostedVideoNone detail"></p>').text(label('noOverlays')).appendTo($overlays);
			}

			$.each(data.overlays, function(n, overlay) {
				var number = n + 1;
				var legendId = uid + '_o' + overlay.id;
				// each overlay is a group of its own, with its name in the line around it
				// Its settings are closed until its name is clicked: closed, it says in a line what it shows and when.
				var isOpen = openCards[overlay.id] === true;
				var bodyId = legendId + '_body';
				var $card = $('<fieldset class="InputfieldSelfHostedVideoArea InputfieldSelfHostedVideoOverlayCard"></fieldset>').attr('data-id', overlay.id);
				var $legend = $('<legend class="InputfieldSelfHostedVideoLegend InputfieldSelfHostedVideoOverlayName"></legend>').attr('id', legendId).appendTo($card);
				$('<button type="button" class="InputfieldSelfHostedVideoOverlayToggle"><span></span><i class="fa fa-fw fa-angle-down" aria-hidden="true"></i></button>')
					.attr({ 'aria-expanded': isOpen ? 'true' : 'false', 'aria-controls': bodyId }).appendTo($legend)
					.find('span').text(fill(label('overlayN'), number));
				$('<p class="InputfieldSelfHostedVideoOverlaySummary"></p>').text(summaryOf(overlay)).appendTo($card);
				var $body = $('<div class="InputfieldSelfHostedVideoAreaBody"></div>').attr('id', bodyId).prop('hidden', !isOpen).appendTo($card);
				$card.toggleClass('InputfieldSelfHostedVideoOverlayOpen', isOpen);
				var $grid = $('<div class="InputfieldSelfHostedVideoOverlayGrid"></div>').appendTo($body);

				field(label('text'), $('<textarea class="uk-textarea" rows="2" maxlength="500" data-prop="text"></textarea>')
					.val(textOf(overlay.text, false))
					.attr('placeholder', lang !== 0 && overlay.text[0] ? overlay.text[0] : '')
					.prop('readonly', !editable)
				).addClass('InputfieldSelfHostedVideoOverlayWide').appendTo($grid);

				// when it is shown comes right after the text: it is what is asked for most
				field(label('when'), select('when', { always: label('whenAlways'), range: label('whenRange'), end: label('whenEnd') }, overlay.when || 'always'))
					.addClass('InputfieldSelfHostedVideoOverlayWide').appendTo($grid);

				var $times = $('<div class="InputfieldSelfHostedVideoOverlayTimes"></div>').prop('hidden', overlay.when !== 'range').appendTo($grid);
				$.each([ 'start', 'end' ], function(i, prop) {
					var $f = field(prop === 'start' ? label('from') : label('to'),
						$('<input type="text" class="uk-input" inputmode="decimal" autocomplete="off">').attr('data-prop', prop)
							.val(prop === 'end' && !(overlay.end > 0) ? '' : formatTime(overlay[prop] || 0)),
						prop === 'end' ? label('toHint') : ''
					);
					$('<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoOverlayNow"></button>')
						.attr({ 'data-prop': prop, 'aria-label': label('nowLabel') }).text(label('now')).insertAfter($f.find('input'));
					$f.appendTo($times);
				});

				field(label('link'), $('<input type="text" class="uk-input" maxlength="1024" autocomplete="off" data-prop="url">')
					.val(textOf(overlay.url, false))
					.attr('placeholder', lang !== 0 && overlay.url[0] ? overlay.url[0] : '')
					.prop('readonly', !editable), label('linkHint')
				).addClass('InputfieldSelfHostedVideoOverlayWide').appendTo($grid);

				field(label('posX'), $('<input type="number" class="uk-input" min="0" max="100" step="1" data-prop="x">').val(Math.round(overlay.x))).appendTo($grid);
				field(label('posY'), $('<input type="number" class="uk-input" min="0" max="100" step="1" data-prop="y">').val(Math.round(overlay.y))).appendTo($grid);
				field(label('width'), $('<input type="number" class="uk-input" min="10" max="100" step="1" data-prop="w">').val(overlay.w > 0 ? Math.round(overlay.w) : ''), label('widthHint')).appendTo($grid);
				field(label('size'), select('size', { s: label('sizeS'), m: label('sizeM'), l: label('sizeL') }, overlay.size || 'm')).appendTo($grid);
				field(label('look'), select('look', { dark: label('lookDark'), light: label('lookLight') }, overlay.look || 'dark')).appendTo($grid);

				// removing it comes last, after everything that is filled in
				var $foot = $('<div class="InputfieldSelfHostedVideoOverlayFoot"></div>').appendTo($body);
				$('<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoOverlayRemove"><i class="fa fa-fw fa-trash-o" aria-hidden="true"></i><span></span></button>')
					.attr('aria-label', fill(label('removeOverlay'), number)).appendTo($foot).find('span').text(label('remove'));

				$card.toggleClass('InputfieldSelfHostedVideoOverlaySelected', overlay.id === selected);
				$overlays.append($card);
			});
		}

		/*** Stage: the overlays on the picture ***********************************************/

		function describe(overlay, number) {
			return fill(label('overlayLabel'), number, textOf(overlay.text, true) || label('emptyText'), Math.round(overlay.x), Math.round(overlay.y));
		}

		function place($el, overlay) {
			var ax = { l: 'left', c: 'center', r: 'right' }[overlay.ax] || 'center';
			var ay = { t: 'top', m: 'middle', b: 'bottom' }[overlay.ay] || 'middle';
			$el.attr('class', 'shv-video__overlay InputfieldSelfHostedVideoOverlay' +
				' shv-video__overlay--' + (overlay.look === 'light' ? 'light' : 'dark') +
				' shv-video__overlay--' + (overlay.size || 'm') +
				' shv-video__overlay--' + ax + ' shv-video__overlay--' + ay +
				(overlay.id === selected ? ' InputfieldSelfHostedVideoOverlayOn' : ''));
			$el.css({ left: overlay.x + '%', top: overlay.y + '%', width: overlay.w > 0 ? overlay.w + '%' : '', maxWidth: overlay.w > 0 ? 'none' : '' });
		}

		function renderStage() {
			$layer.empty();
			$.each(data.overlays, function(n, overlay) {
				var text = textOf(overlay.text, true);
				var $el = $('<div tabindex="0" role="button"></div>')
					.attr({ 'data-id': overlay.id, 'aria-label': describe(overlay, n + 1), 'aria-describedby': uid + '_drag' })
					.text(text || label('emptyText'))
					.toggleClass('InputfieldSelfHostedVideoOverlayEmpty', !text);
				place($el, overlay);
				$el.toggleClass('InputfieldSelfHostedVideoOverlayEmpty', !text);
				$layer.append($el);
			});
			refreshTime();
		}

		function stageOverlay(id) {
			return $layer.children().filter(function() { return $(this).attr('data-id') === id; });
		}

		function selectOverlay(id) {
			selected = id;
			$layer.children().each(function() { $(this).toggleClass('InputfieldSelfHostedVideoOverlayOn', $(this).attr('data-id') === id); });
			$overlays.children('.InputfieldSelfHostedVideoOverlayCard').each(function() { $(this).toggleClass('InputfieldSelfHostedVideoOverlaySelected', $(this).attr('data-id') === id); });
			$bar.find('.InputfieldSelfHostedVideoBarOverlay').each(function() { $(this).toggleClass('InputfieldSelfHostedVideoBarOn', $(this).attr('data-id') === id); });
		}

		/**
		 * Move an overlay so that its box sits at the given place of the stage (in pixels from the
		 * top left). Near an edge it holds on to that edge, in the middle to its centre, so that
		 * a longer text in another language still fits.
		 */
		function moveBox(overlay, $el, left, top) {
			var stage = $stage[0].getBoundingClientRect();
			var box = $el[0].getBoundingClientRect();
			if(!stage.width || !stage.height) return;

			left = clamp(left, 0, Math.max(0, stage.width - box.width));
			top = clamp(top, 0, Math.max(0, stage.height - box.height));

			var cx = (left + box.width / 2) / stage.width * 100;
			var cy = (top + box.height / 2) / stage.height * 100;

			overlay.ax = anchor(cx, 'l', 'c', 'r');
			overlay.ay = anchor(cy, 't', 'm', 'b');
			overlay.x = round1(clamp((overlay.ax === 'l' ? left : (overlay.ax === 'r' ? left + box.width : left + box.width / 2)) / stage.width * 100, 0, 100));
			overlay.y = round1(clamp((overlay.ay === 't' ? top : (overlay.ay === 'b' ? top + box.height : top + box.height / 2)) / stage.height * 100, 0, 100));

			place($el, overlay);
			showPosition(overlay);
		}

		function showPosition(overlay) {
			var $card = $overlays.children().filter(function() { return $(this).attr('data-id') === overlay.id; });
			$card.find('[data-prop="x"]').val(Math.round(overlay.x));
			$card.find('[data-prop="y"]').val(Math.round(overlay.y));
			var n = $.inArray(overlay, data.overlays);
			stageOverlay(overlay.id).attr('aria-label', describe(overlay, n + 1));
		}

		/*** Timeline bar *********************************************************************/

		function renderBar() {
			var total = duration();
			$bar.empty();
			barStale = false;

			if(!total) {
				$('<p class="InputfieldSelfHostedVideoNone detail"></p>').text(label('noDuration')).appendTo($bar);
				return;
			}

			var $rows = $('<div class="InputfieldSelfHostedVideoBarRows"></div>').appendTo($bar);
			var $row = $('<div class="InputfieldSelfHostedVideoBarRow InputfieldSelfHostedVideoBarChapters"></div>').appendTo($rows);

			$.each(data.chapters, function(n, chapter) {
				$('<button type="button" class="InputfieldSelfHostedVideoBarChapter"></button>')
					.attr({ 'data-id': chapter.id, 'aria-describedby': uid + '_bar' })
					.appendTo($row);
			});

			$.each(data.overlays, function(n, overlay) {
				var $r = $('<div class="InputfieldSelfHostedVideoBarRow"></div>').attr('data-id', overlay.id).appendTo($rows);
				$('<button type="button" class="InputfieldSelfHostedVideoBarOverlay"></button>')
					.attr({ 'data-id': overlay.id, 'aria-describedby': uid + '_bar' })
					.append($('<span></span>'))
					.appendTo($r);
				// the two ends of the bar: each a slider of its own, so that the keyboard reaches them too
				$.each([ 'start', 'end' ], function(i, edge) {
					$('<span class="InputfieldSelfHostedVideoBarHandle" role="slider" tabindex="0"></span>')
						.addClass(edge === 'start' ? 'InputfieldSelfHostedVideoBarHandleStart' : 'InputfieldSelfHostedVideoBarHandleEnd')
						.attr({ 'data-id': overlay.id, 'data-edge': edge })
						.appendTo($r);
				});
			});

			$('<div class="InputfieldSelfHostedVideoBarHead" aria-hidden="true"></div>').appendTo($rows);
			$('<div class="InputfieldSelfHostedVideoBarTip" aria-hidden="true" hidden></div>').appendTo($rows);
			$('<div class="InputfieldSelfHostedVideoBarScale detail" aria-hidden="true"><span>0:00</span><span></span></div>').appendTo($bar).find('span').last().text(formatTime(total));

			placeBars();
		}

		/** Put marks, bars and their ends where the data says they are. Nothing is rebuilt, so it can be done while dragging. */
		function placeBars() {
			var total = duration();
			if(!built || !total) return;

			var percent = function(time) { return clamp(time / total * 100, 0, 100); };
			var byId = function(selector, id) {
				return $bar.find(selector).filter(function() { return $(this).attr('data-id') === id; });
			};

			$.each(data.chapters, function(n, chapter) {
				var name = textOf(chapter.title, true);
				byId('.InputfieldSelfHostedVideoBarChapter', chapter.id)
					.attr({ title: formatTime(chapter.time) + ' ' + name, 'aria-label': fill(label('barChapter'), name || (n + 1), formatTime(chapter.time)) })
					.css('left', percent(chapter.time) + '%');
			});

			$.each(data.overlays, function(n, overlay) {
				var name = textOf(overlay.text, true);
				var begin = startOf(overlay), end = endOf(overlay);
				var from = 0, to = 100;
				if(overlay.when === 'range') {
					from = percent(begin);
					to = percent(end);
				} else if(overlay.when === 'end') {
					from = 98;
				}
				var $r = byId('.InputfieldSelfHostedVideoBarRow', overlay.id);
				$r.find('.InputfieldSelfHostedVideoBarOverlay')
					.attr('aria-label', fill(label('barOverlay'), n + 1, name || label('emptyText')))
					.toggleClass('InputfieldSelfHostedVideoBarOn', overlay.id === selected)
					.toggleClass('InputfieldSelfHostedVideoBarMovable', overlay.when === 'range')
					.css({ left: from + '%', width: Math.max(1.5, to - from) + '%' })
					.find('span').text((n + 1) + ' ' + name);
				$r.find('.InputfieldSelfHostedVideoBarHandle').each(function() {
					var first = $(this).attr('data-edge') === 'start';
					// an overlay for the end of the video has no times to change
					$(this).prop('hidden', overlay.when === 'end').css('left', (first ? from : to) + '%').attr({
						'aria-label': fill(label(first ? 'barStart' : 'barEnd'), n + 1),
						'aria-valuemin': first ? 0 : round1(Math.min(begin + 0.1, total)),
						'aria-valuemax': first ? latestStart(end) : round1(total),
						'aria-valuenow': round1(first ? begin : end),
						'aria-valuetext': formatTime(first ? begin : end)
					});
				});
			});
		}

		/*** Times of an overlay ***************************************************************/

		function startOf(overlay) {
			return overlay.when === 'range' ? clamp(overlay.start || 0, 0, duration()) : 0;
		}

		function endOf(overlay) {
			var total = duration();
			if(overlay.when !== 'range' || !(overlay.end > 0)) return total;
			return clamp(Math.max(overlay.end, startOf(overlay) + 0.1), 0, total);
		}

		/** The latest beginning of an overlay that ends at the given time: it is shown for a tenth of a second at least */
		function latestStart(end) {
			return Math.max(0, Math.floor((end - 0.1) * 10 + 0.000001) / 10);
		}

		function timesOf(overlay) {
			return { when: overlay.when || 'always', start: overlay.start || 0, end: overlay.end || 0, from: startOf(overlay), to: endOf(overlay) };
		}

		/** An end at the end of the video is stored as "until the end" */
		function setRange(overlay, begin, end) {
			overlay.when = 'range';
			overlay.start = round1(begin);
			overlay.end = end >= duration() - 0.05 ? 0 : round1(end);
		}

		/**
		 * Give an overlay other times, starting from those it had (origin, from timesOf)
		 *
		 * what: "start" or "end" for one end of its time, "move" for all of it at its length.
		 * An overlay that is shown during the whole video becomes one for a part of it.
		 * Returns whether anything is different from the origin.
		 */
		function retime(overlay, origin, what, time) {
			var total = duration();

			overlay.when = origin.when;
			overlay.start = origin.start;
			overlay.end = origin.end;

			if(what === 'move') {
				var length = origin.to - origin.from;
				var begin = clamp(round1(time), 0, Math.max(0, total - length));
				setRange(overlay, begin, begin + length);
			} else if(what === 'start') {
				setRange(overlay, clamp(round1(time), 0, latestStart(origin.to)), origin.to);
			} else {
				setRange(overlay, origin.from, Math.max(origin.from + 0.1, Math.min(total, time)));
			}

			// from the first moment to the last is the whole video, as it was before
			if(origin.when !== 'range' && !(overlay.start > 0) && !(overlay.end > 0)) {
				overlay.when = origin.when;
				overlay.start = origin.start;
				overlay.end = origin.end;
			}

			placeBars();
			showTimes(overlay);

			return overlay.when !== origin.when || (overlay.start || 0) !== origin.start || (overlay.end || 0) !== origin.end;
		}

		/** Show the times of an overlay in its settings */
		function showTimes(overlay) {
			var $card = $overlays.children().filter(function() { return $(this).attr('data-id') === overlay.id; });
			$card.find('select[data-prop="when"]').val(overlay.when || 'always');
			$card.find('.InputfieldSelfHostedVideoOverlayTimes').prop('hidden', overlay.when !== 'range');
			$card.find('input[data-prop="start"]').removeAttr('aria-invalid').val(formatTime(overlay.start || 0));
			$card.find('input[data-prop="end"]').removeAttr('aria-invalid').val(overlay.end > 0 ? formatTime(overlay.end) : '');
			showSummary(overlay);
		}

		function timesText(overlay) {
			return fill(label('overlayTimed'), $.inArray(overlay, data.overlays) + 1, formatTime(startOf(overlay)), formatTime(endOf(overlay)));
		}

		/** Give a chapter another time, without sorting the chapters yet */
		function moveChapter(chapter, time) {
			var total = duration();
			chapter.time = clamp(round1(time), 0, total ? Math.floor(total * 10) / 10 : 0);
			placeBars();
			var $row = $chapters.children().filter(function() { return $(this).attr('data-id') === chapter.id; });
			$row.find('.InputfieldSelfHostedVideoChapterTime').removeAttr('aria-invalid').val(formatTime(chapter.time));
		}

		/** The chapters are in another order now: the list is rebuilt, the marks stay as they are while one of them is in use */
		function chapterMoved(chapter) {
			commit();
			renderChapters();
			placeBars();
			barStale = true;
			refreshTime();
			announce(fill(label('chapterMoved'), formatTime(chapter.time)));
		}

		/** A small note above what is being dragged, with its time */
		function showTip(text, time, element) {
			var $tip = $bar.find('.InputfieldSelfHostedVideoBarTip');
			var rows = $bar.find('.InputfieldSelfHostedVideoBarRows')[0];
			var row = $(element).closest('.InputfieldSelfHostedVideoBarRow')[0];
			var total = duration();
			if(!$tip.length || !rows || !row || !total) return;
			$tip.text(text).prop('hidden', false);
			var half = $tip[0].offsetWidth / 2;
			$tip.css({
				left: clamp(time / total * rows.clientWidth, half, Math.max(half, rows.clientWidth - half)) + 'px',
				top: (row.offsetTop - $tip[0].offsetHeight - 4) + 'px'
			});
		}

		function hideTip() {
			$bar.find('.InputfieldSelfHostedVideoBarTip').prop('hidden', true);
		}

		/** Show what belongs to the current position of the preview */
		function refreshTime() {
			if(!built || !video) return;

			var total = duration();
			var time = video.currentTime || 0;
			var ended = video.ended || (total > 0 && time >= total - 0.05);
			var playing = !video.paused && !video.ended;

			if(total && !$bar.find('.InputfieldSelfHostedVideoBarRows').length) renderBar();
			$bar.find('.InputfieldSelfHostedVideoBarHead').css('left', (total ? clamp(time / total * 100, 0, 100) : 0) + '%');

			$.each(data.overlays, function(n, overlay) {
				var active = true;
				if(overlay.when === 'range') {
					active = time >= (overlay.start || 0) && (!(overlay.end > 0) || time < overlay.end);
				} else if(overlay.when === 'end') {
					active = ended;
				}
				// while it plays the preview shows what visitors will see. Paused, everything can be reached.
				stageOverlay(overlay.id).prop('hidden', playing && !active).toggleClass('InputfieldSelfHostedVideoOverlayOff', !playing && !active);
			});

			var current = '';
			$.each(data.chapters, function(n, chapter) {
				if(chapter.time <= time + 0.05) current = chapter.id;
			});
			$chapters.children('.InputfieldSelfHostedVideoChapter').each(function() {
				$(this).toggleClass('InputfieldSelfHostedVideoChapterNow', $(this).attr('data-id') === current);
			});

			refreshAdd();
		}

		/*** What the editor does *************************************************************/

		function bind() {

			// these inputs are part of the page form: Enter in one of them must not save the page
			$panel.on('keydown', 'input', function(event) {
				if(event.key !== 'Enter') return;
				event.preventDefault();
				$(this).trigger('change');
			});

			// language of the texts
			$panel.on('click', '.InputfieldSelfHostedVideoLang', function() {
				lang = parseInt($(this).attr('data-lang'), 10) || 0;
				renderLangs();
				renderAll();
				$langs.find('[aria-pressed="true"]').trigger('focus');
				return false;
			});

			// chapters
			$panel.on('click', '.InputfieldSelfHostedVideoChapterAdd', function() {
				var time = now();
				var existing = null;
				$.each(data.chapters, function(n, chapter) {
					if(Math.abs(chapter.time - time) < 0.05) existing = chapter;
				});
				if(!existing) {
					existing = { id: newId(), time: time, title: {} };
					data.chapters.push(existing);
					commit();
					renderChapters();
					renderBar();
					refreshTime();
					announce(fill(label('chapterAdded'), formatTime(time)));
				}
				$chapters.children().filter(function() { return $(this).attr('data-id') === existing.id; }).find('.InputfieldSelfHostedVideoChapterTitle').trigger('focus');
				return false;
			});

			$panel.on('input', '.InputfieldSelfHostedVideoChapterTitle', function() {
				var chapter = chapterById($(this).closest('.InputfieldSelfHostedVideoChapter').attr('data-id'));
				if(!chapter) return;
				setText(chapter.title, $(this).val().trim());
				commit();
			});

			$panel.on('change', '.InputfieldSelfHostedVideoChapterTitle', function() {
				renderBar();
				refreshTime();
			});

			$panel.on('change', '.InputfieldSelfHostedVideoChapterTime', function() {
				var $input = $(this);
				var id = $input.closest('.InputfieldSelfHostedVideoChapter').attr('data-id');
				var chapter = chapterById(id);
				var time = parseTime($input.val());
				var total = duration();
				if(!chapter) return;
				if(time === null) {
					$input.attr('aria-invalid', 'true').val(formatTime(chapter.time));
					announce(label('badTime'));
					return;
				}
				if(total && time > total) time = Math.floor(total * 10) / 10;
				chapter.time = round1(time);
				commit();
				renderChapters();
				renderBar();
				refreshTime();
				$chapters.children().filter(function() { return $(this).attr('data-id') === id; }).find('.InputfieldSelfHostedVideoChapterTime').trigger('focus');
			});

			$panel.on('click', '.InputfieldSelfHostedVideoChapterGo', function() {
				var chapter = chapterById($(this).closest('.InputfieldSelfHostedVideoChapter').attr('data-id'));
				if(chapter) seek(chapter.time);
				return false;
			});

			$panel.on('click', '.InputfieldSelfHostedVideoChapterRemove', function() {
				var id = $(this).closest('.InputfieldSelfHostedVideoChapter').attr('data-id');
				data.chapters = $.grep(data.chapters, function(chapter) { return chapter.id !== id; });
				commit();
				renderChapters();
				renderBar();
				refreshTime();
				announce(label('chapterRemoved'));
				$panel.find('.InputfieldSelfHostedVideoChapterAdd').trigger('focus');
				return false;
			});

			// overlays: list
			$panel.on('click', '.InputfieldSelfHostedVideoOverlayAdd', function() {
				var overlay = { id: newId(), text: {}, url: {}, x: 50, y: 50, ax: 'c', ay: 'm', w: 0, when: 'always', start: 0, end: 0, size: 'm', look: 'dark' };
				data.overlays.push(overlay);
				selected = overlay.id;
				// a new overlay is there to be filled in
				openCards[overlay.id] = true;
				commit();
				renderOverlays();
				renderStage();
				renderBar();
				refreshTime();
				announce(label('overlayAdded'));
				$overlays.children().last().find('textarea').trigger('focus');
				return false;
			});

			$panel.on('click', '.InputfieldSelfHostedVideoOverlayRemove', function() {
				var id = $(this).closest('.InputfieldSelfHostedVideoOverlayCard').attr('data-id');
				data.overlays = $.grep(data.overlays, function(overlay) { return overlay.id !== id; });
				if(selected === id) selected = '';
				delete openCards[id];
				commit();
				renderOverlays();
				renderStage();
				renderBar();
				refreshTime();
				announce(label('overlayRemoved'));
				$panel.find('.InputfieldSelfHostedVideoOverlayAdd').trigger('focus');
				return false;
			});

			$panel.on('focusin', '.InputfieldSelfHostedVideoOverlayCard', function() {
				var id = $(this).attr('data-id');
				if(id !== selected) selectOverlay(id);
			});

			// the name of an overlay opens and closes its settings, and so does the line a closed one shows
			$panel.on('click', '.InputfieldSelfHostedVideoOverlayToggle, .InputfieldSelfHostedVideoOverlaySummary', function() {
				var id = $(this).closest('.InputfieldSelfHostedVideoOverlayCard').attr('data-id');
				setCardOpen(id, openCards[id] !== true);
				if(id !== selected) selectOverlay(id);
				return false;
			});

			$panel.on('input change', '.InputfieldSelfHostedVideoOverlayCard [data-prop]', function(event) {
				var $input = $(this);
				var $card = $input.closest('.InputfieldSelfHostedVideoOverlayCard');
				var overlay = overlayById($card.attr('data-id'));
				var prop = $input.attr('data-prop');
				var total = duration();
				if(!overlay || $input.is('button')) return;

				if(prop === 'text' || prop === 'url') {
					setText(overlay[prop], prop === 'text' ? $input.val().replace(/\s+$/, '') : $input.val().trim());
				} else if(prop === 'x' || prop === 'y') {
					var value = parseFloat($input.val());
					if(isNaN(value)) return;
					overlay[prop] = clamp(value, 0, 100);
					if(prop === 'x') overlay.ax = anchor(overlay.x, 'l', 'c', 'r');
					if(prop === 'y') overlay.ay = anchor(overlay.y, 't', 'm', 'b');
				} else if(prop === 'w') {
					var width = parseFloat($input.val());
					overlay.w = isNaN(width) || width <= 0 ? 0 : clamp(width, 10, 100);
				} else if(prop === 'start' || prop === 'end') {
					if(event.type !== 'change') return;
					var text = $input.val().trim();
					var time = text === '' ? 0 : parseTime(text);
					if(time === null) {
						$input.attr('aria-invalid', 'true').val(prop === 'end' && !(overlay.end > 0) ? '' : formatTime(overlay[prop] || 0));
						announce(label('badTime'));
						return;
					}
					if(total && time > total) time = Math.floor(total * 10) / 10;
					overlay[prop] = round1(time);
					$input.removeAttr('aria-invalid').val(prop === 'end' && !(overlay.end > 0) ? '' : formatTime(overlay[prop]));
				} else {
					overlay[prop] = $input.val();
					if(prop === 'when') $card.find('.InputfieldSelfHostedVideoOverlayTimes').prop('hidden', overlay.when !== 'range');
				}

				commit();

				var $el = stageOverlay(overlay.id);
				var shown = textOf(overlay.text, true);
				$el.text(shown || label('emptyText'));
				place($el, overlay);
				$el.toggleClass('InputfieldSelfHostedVideoOverlayEmpty', !shown);
				$el.attr('aria-label', describe(overlay, $.inArray(overlay, data.overlays) + 1));
				showSummary(overlay);
				if(event.type === 'change' || prop === 'when') renderBar();
				refreshTime();
			});

			$panel.on('click', '.InputfieldSelfHostedVideoOverlayNow', function() {
				var $button = $(this);
				$button.siblings('input').val(formatTime(now())).trigger('change');
				return false;
			});

			// overlays: on the picture
			var drag = null;

			// (the admin's jQuery may not pass on the details of pointer events, so the browser's own event is used)
			$layer.on('pointerdown', '.InputfieldSelfHostedVideoOverlay', function(jqEvent) {
				var event = jqEvent.originalEvent || jqEvent;
				if(event.button !== undefined && event.button !== 0) return;
				var overlay = overlayById($(this).attr('data-id'));
				if(!overlay) return;
				var stage = $stage[0].getBoundingClientRect();
				var box = this.getBoundingClientRect();
				drag = { overlay: overlay, $el: $(this), dx: event.clientX - box.left, dy: event.clientY - box.top, stage: stage, moved: false };
				selectOverlay(overlay.id);
				try {
					this.setPointerCapture(event.pointerId);
				} catch(e) { /* dragging works without it, as long as the pointer stays on the overlay */ }
				jqEvent.preventDefault();
				this.focus();
			});

			$layer.on('pointermove', '.InputfieldSelfHostedVideoOverlay', function(jqEvent) {
				var event = jqEvent.originalEvent || jqEvent;
				if(!drag || drag.$el[0] !== this) return;
				var stage = $stage[0].getBoundingClientRect();
				drag.moved = true;
				moveBox(drag.overlay, drag.$el, event.clientX - stage.left - drag.dx, event.clientY - stage.top - drag.dy);
			});

			$layer.on('pointerup pointercancel', '.InputfieldSelfHostedVideoOverlay', function() {
				if(!drag) return;
				var moved = drag.moved;
				var overlay = drag.overlay;
				drag = null;
				if(moved) {
					commit();
					announce(fill(label('moved'), Math.round(overlay.x), Math.round(overlay.y)));
				}
			});

			$layer.on('focus', '.InputfieldSelfHostedVideoOverlay', function() {
				var id = $(this).attr('data-id');
				if(id !== selected) selectOverlay(id);
			});

			$layer.on('keydown', '.InputfieldSelfHostedVideoOverlay', function(event) {
				var overlay = overlayById($(this).attr('data-id'));
				var steps = { ArrowLeft: [ -1, 0 ], ArrowRight: [ 1, 0 ], ArrowUp: [ 0, -1 ], ArrowDown: [ 0, 1 ] };
				if(!overlay) return;

				if(event.key === 'Enter' || event.key === ' ') {
					// on to the settings of this overlay
					setCardOpen(overlay.id, true);
					$overlays.children().filter(function() { return $(this).attr('data-id') === overlay.id; }).find('textarea').trigger('focus');
					event.preventDefault();
					return;
				}
				if(!steps[event.key]) return;

				var stage = $stage[0].getBoundingClientRect();
				var box = this.getBoundingClientRect();
				var step = event.shiftKey ? 5 : 1;
				moveBox(overlay, $(this),
					box.left - stage.left + steps[event.key][0] * step / 100 * stage.width,
					box.top - stage.top + steps[event.key][1] * step / 100 * stage.height);
				commit();
				announce(fill(label('moved'), Math.round(overlay.x), Math.round(overlay.y)));
				event.preventDefault();
			});

			// timeline
			$bar.on('click', '.InputfieldSelfHostedVideoBarChapter', function() {
				var chapter = chapterById($(this).attr('data-id'));
				if(!chapter || skipClick) return false;
				seek(chapter.time);
				$chapters.children().filter(function() { return $(this).attr('data-id') === chapter.id; }).find('.InputfieldSelfHostedVideoChapterTitle').trigger('focus');
				return false;
			});

			$bar.on('click', '.InputfieldSelfHostedVideoBarOverlay', function() {
				var overlay = overlayById($(this).attr('data-id'));
				if(!overlay || skipClick) return false;
				if(overlay.when === 'range') seek(overlay.start || 0);
				if(overlay.when === 'end' && duration()) seek(duration());
				selectOverlay(overlay.id);
				// a click on its bar leads to its settings
				setCardOpen(overlay.id, true);
				$overlays.children().filter(function() { return $(this).attr('data-id') === overlay.id; }).find('textarea').trigger('focus');
				return false;
			});

			$bar.on('click', '.InputfieldSelfHostedVideoBarRows', function(jqEvent) {
				var event = jqEvent.originalEvent || jqEvent;
				if(skipClick || $(event.target).closest('button, .InputfieldSelfHostedVideoBarHandle').length) return;
				var rect = this.getBoundingClientRect();
				var total = duration();
				if(total && rect.width) seek(clamp((event.clientX - rect.left) / rect.width, 0, 1) * total);
			});

			// timeline: dragging chapter marks, the bars of overlays and the ends of the bars
			var movable = '.InputfieldSelfHostedVideoBarChapter, .InputfieldSelfHostedVideoBarOverlay, .InputfieldSelfHostedVideoBarHandle';

			$bar.on('pointerdown', movable, function(jqEvent) {
				var event = jqEvent.originalEvent || jqEvent;
				if(event.button !== undefined && event.button !== 0) return;
				var $el = $(this);
				var id = $el.attr('data-id');
				var row = $el.closest('.InputfieldSelfHostedVideoBarRow')[0];
				var d = { el: this, row: row, x: event.clientX, moved: false, what: '' };
				if(!duration() || !row) return;

				if($el.hasClass('InputfieldSelfHostedVideoBarChapter')) {
					d.chapter = chapterById(id);
					if(!d.chapter) return;
					d.what = 'chapter';
					d.time = d.chapter.time;
				} else {
					d.overlay = overlayById(id);
					if(!d.overlay) return;
					d.origin = timesOf(d.overlay);
					d.what = $el.attr('data-edge') || 'move';
					// a bar over the whole video has nowhere to go; its ends can be pulled in
					if(d.what === 'move' && d.overlay.when !== 'range') d.what = '';
					if(id !== selected) selectOverlay(id);
				}

				barDrag = d;
				try {
					this.setPointerCapture(event.pointerId);
				} catch(e) { /* dragging works without it, as long as the pointer stays on the element */ }
				jqEvent.preventDefault();
				this.focus();
			});

			$bar.on('pointermove', movable, function(jqEvent) {
				var event = jqEvent.originalEvent || jqEvent;
				var d = barDrag;
				if(!d || d.el !== this || !d.what) return;

				var width = d.row.getBoundingClientRect().width;
				var distance = event.clientX - d.x;
				if(!width) return;
				if(!d.moved) {
					// a few pixels of play, so that a click stays a click
					if(Math.abs(distance) < 4) return;
					d.moved = true;
					if(video && !video.paused) video.pause();
					$bar.addClass('InputfieldSelfHostedVideoBarDragging');
				}

				var by = distance / width * duration();
				if(d.what === 'chapter') {
					moveChapter(d.chapter, d.time + by);
					showTip(formatTime(d.chapter.time), d.chapter.time, this);
					scrub(d.chapter.time);
				} else {
					d.changed = retime(d.overlay, d.origin, d.what, (d.what === 'end' ? d.origin.to : d.origin.from) + by);
					var begin = startOf(d.overlay), end = endOf(d.overlay);
					if(d.what === 'move') {
						showTip(formatTime(begin) + ' – ' + formatTime(end), (begin + end) / 2, this);
					} else {
						showTip(formatTime(d.what === 'end' ? end : begin), d.what === 'end' ? end : begin, this);
					}
					scrub(d.what === 'end' ? end : begin);
				}
			});

			$bar.on('pointerup pointercancel', movable, function(jqEvent) {
				var d = barDrag;
				var cancelled = jqEvent.type === 'pointercancel';
				if(!d || d.el !== this) return;
				barDrag = null;
				hideTip();
				$bar.removeClass('InputfieldSelfHostedVideoBarDragging');
				if(!d.moved) return;

				// the click that follows belongs to the drag
				skipClick = true;
				setTimeout(function() { skipClick = false; }, 50);

				if(d.what === 'chapter') {
					// a drag that the browser took over (to scroll the page) changes nothing
					if(cancelled) moveChapter(d.chapter, d.time);
					if(d.chapter.time !== d.time) chapterMoved(d.chapter);
				} else if(cancelled) {
					d.overlay.when = d.origin.when;
					d.overlay.start = d.origin.start;
					d.overlay.end = d.origin.end;
					placeBars();
					showTimes(d.overlay);
					refreshTime();
				} else if(d.changed) {
					commit();
					refreshTime();
					announce(timesText(d.overlay));
				}
			});

			// timeline: the same with the keyboard
			$bar.on('keydown', movable, function(event) {
				var $el = $(this);
				var id = $el.attr('data-id');
				var total = duration();
				var step = event.shiftKey ? 1 : 0.1;
				if(!total || barDrag || event.altKey || event.ctrlKey || event.metaKey) return;

				if($el.hasClass('InputfieldSelfHostedVideoBarHandle')) {
					var edge = $el.attr('data-edge');
					var overlay = overlayById(id);
					if(!overlay) return;
					var origin = timesOf(overlay);
					var at = edge === 'end' ? origin.to : origin.from;
					var keys = { ArrowLeft: -step, ArrowDown: -step, ArrowRight: step, ArrowUp: step, PageDown: -1, PageUp: 1 };
					var time;
					if(event.key === 'Home') {
						time = edge === 'end' ? origin.from : 0;
					} else if(event.key === 'End') {
						time = edge === 'end' ? total : origin.to;
					} else if(keys[event.key]) {
						time = at + keys[event.key];
					} else {
						return;
					}
					event.preventDefault();
					if(retime(overlay, origin, edge, time)) {
						commit();
						scrub(edge === 'end' ? endOf(overlay) : startOf(overlay));
					}
					return;
				}

				// marks and bars are buttons: left and right move them, up and down are left to the page
				if(event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
				if(event.key === 'ArrowLeft') step = -step;

				if($el.hasClass('InputfieldSelfHostedVideoBarChapter')) {
					var chapter = chapterById(id);
					if(!chapter) return;
					var before = chapter.time;
					event.preventDefault();
					moveChapter(chapter, before + step);
					if(chapter.time !== before) {
						chapterMoved(chapter);
						scrub(chapter.time);
					}
				} else {
					var bar = overlayById(id);
					if(!bar || bar.when !== 'range') return;
					var was = timesOf(bar);
					event.preventDefault();
					if(retime(bar, was, 'move', was.from + step)) {
						commit();
						scrub(startOf(bar));
						announce(timesText(bar));
					}
				}
			});

			// chapter marks that were moved past each other are put in order once the timeline is left
			$bar.on('focusout', function() {
				if(!barStale) return;
				setTimeout(function() {
					if(barStale && !barDrag && !$.contains($bar[0], document.activeElement)) renderBar();
				}, 0);
			});
		}

		/*** Opening and closing **************************************************************/

		function storageKey() {
			// by the page being edited: the rest of the address changes when the page is saved
			var id = /[?&]id=(\d+)/.exec(location.search);
			return 'InputfieldSelfHostedVideoTimeline:' + location.pathname + ':' + (id ? id[1] : '') + ':' + $root.attr('data-name');
		}

		this.open = function(focus) {
			if(!built) build();
			$panel.prop('hidden', false);
			$toggle.attr('aria-expanded', 'true');
			try {
				window.sessionStorage.setItem(storageKey(), '1');
			} catch(e) { /* nothing to remember it in */ }
			renderBar();
			refreshTime();
			if(focus) $panel.find('button, input, textarea, select, video').first().trigger('focus');
		};

		this.close = function() {
			if(video && !video.paused) video.pause();
			$panel.prop('hidden', true);
			$toggle.attr('aria-expanded', 'false');
			try {
				window.sessionStorage.removeItem(storageKey());
			} catch(e) { /* nothing was remembered */ }
		};

		this.toggle = function() {
			if($panel.prop('hidden')) {
				self.open(false);
			} else {
				self.close();
			}
		};

		showCount();

		// open again after the page was saved, if it was open before
		try {
			if(window.sessionStorage.getItem(storageKey()) === '1') self.open(false);
		} catch(e) { /* stays closed */ }
	}

	function editorOf($root) {
		var editor = $root.data('vfEditor');
		if(!editor) {
			editor = new Editor($root);
			$root.data('vfEditor', editor);
		}
		return editor;
	}

	function setup($scope) {
		$scope.find('.InputfieldSelfHostedVideoTimeline').addBack('.InputfieldSelfHostedVideoTimeline').each(function() {
			if($(this).find('.InputfieldSelfHostedVideoTimelineData').length) editorOf($(this));
		});
	}

	$(document).ready(function() {

		$(document).on('click', '.InputfieldSelfHostedVideoTimelineToggle', function() {
			editorOf($(this).closest('.InputfieldSelfHostedVideoTimeline')).toggle();
			return false;
		});

		$(document).on('AjaxUploadDone', '.InputfieldFileList', function() {
			setup($(this));
		});
		$(document).on('reloaded', '.Inputfield', function() {
			setup($(this));
		});

		setup($(document.body));
	});

})(jQuery);
