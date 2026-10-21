/**
 * Video Files: script for videos on the website
 *
 * - Fetches the thumbnail of a video once it comes near the visible part of the page.
 * - Runs background videos: starts them when they are visible, pauses them when they are
 *   not, and leaves them paused for visitors who asked for reduced motion or data saving.
 *   The button next to a background video pauses and plays it.
 *
 * - Runs videos with controls: shows overlays at their time, moves to a chapter when its link
 *   is used and marks the chapter being played, offers a full screen button that keeps the
 *   overlays visible, pauses other videos when one starts, and pauses a video that is
 *   scrolled out of view.
 * - Tells the page what happens. These events start at the element around the video
 *   (class "shv-video") and bubble up to the document:
 *     shv:play, shv:pause, shv:ended,
 *     shv:progress      detail.percent is 25, 50, 75 or 100
 *     shv:chapter       detail.chapter has index, time and title
 *     shv:overlayclick  detail.overlay has id, text and url
 *     shv:fullscreen    detail.active is true or false
 *   Every event has detail.video (the video element), detail.key (name of the video in links),
 *   detail.title, detail.currentTime and detail.duration (both in seconds).
 *
 * - Goes on where the visitor stopped watching a long video (the place is kept in this browser only),
 *   offers what the editor chose when a video has ended (watch again, the next video, a link), and
 *   reports plays to the module's anonymous counts.
 *
 * Markup added to a page later is picked up with FieldtypeSelfHostedVideo.player.init(element).
 * FieldtypeSelfHostedVideo is the only name this script adds to the page.
  *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 */
(function() {
	'use strict';

	// Everything the scripts of this module share with the page is kept under one name,
	// FieldtypeSelfHostedVideo, so that nothing of it can meet a name of another script.
	var ns = window.FieldtypeSelfHostedVideo = window.FieldtypeSelfHostedVideo || {};

	// a page can end up loading this file twice. Once is enough.
	if(ns.player) return;

	var READY = 'data-shv-ready';
	var hasObserver = 'IntersectionObserver' in window;

	function prefersReducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function prefersLessData() {
		var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
		return !!(connection && connection.saveData);
	}

	/**
	 * Call back when the element comes near the visible part of the page (once),
	 * or whenever it enters or leaves it.
	 */
	function watch(element, margin, once, callback) {
		if(!hasObserver) {
			callback(true);
			return null;
		}
		var observer = new IntersectionObserver(function(entries) {
			for(var i = 0; i < entries.length; i++) {
				var visible = entries[i].isIntersecting;
				if(once && !visible) continue;
				if(once) observer.disconnect();
				callback(visible);
			}
		}, { rootMargin: margin });
		observer.observe(element);
		return observer;
	}

	/*** Thumbnails ***************************************************************************/

	function initPoster(video) {
		watch(video, '300px 0px', true, function() {
			var url = video.getAttribute('data-shv-poster');
			if(url && !video.getAttribute('poster')) video.setAttribute('poster', url);
			video.removeAttribute('data-shv-poster');
		});
	}

	/*** Background videos ********************************************************************/

	function initBackground(layer) {

		var video = layer.querySelector('video');
		if(!video) return;

		var button = layer.nextElementSibling;
		if(!button || !button.hasAttribute('data-shv-toggle')) button = null;

		var visible = !hasObserver;
		// paused by the visitor, or not to be started because of the visitor's settings
		var held = prefersReducedMotion() || prefersLessData();

		function showState() {
			if(!button) return;
			button.setAttribute('data-state', held ? 'paused' : 'playing');
			button.setAttribute('aria-label', button.getAttribute(held ? 'data-label-play' : 'data-label-pause') || '');
		}

		function update() {
			if(visible && !held) {
				video.muted = true;
				var started = video.play();
				if(started && typeof started.catch === 'function') {
					started.catch(function(error) {
						// a pause that came while the video was still loading is not a refusal
						if(error && error.name === 'AbortError') return;
						// the browser does not start videos by itself: leave it to the visitor
						held = true;
						showState();
					});
				}
			} else if(!video.paused) {
				video.pause();
			}
		}

		if(button) {
			button.hidden = false;
			button.addEventListener('click', function() {
				held = !held;
				showState();
				update();
			});
		}

		showState();

		if(window.matchMedia) {
			var query = window.matchMedia('(prefers-reduced-motion: reduce)');
			var onChange = function() {
				if(query.matches && !held) {
					held = true;
					showState();
					update();
				}
			};
			if(query.addEventListener) {
				query.addEventListener('change', onChange);
			} else if(query.addListener) {
				query.addListener(onChange);
			}
		}

		if(hasObserver) {
			watch(layer, '0px', false, function(isVisible) {
				visible = isVisible;
				update();
			});
		} else {
			update();
		}
	}

	/*** Videos with controls *****************************************************************/

	var players = [];

	/* where visitors stopped watching, kept in this browser: { "page:file": { t: seconds, at: time } } */

	var PLACES = 'FieldtypeSelfHostedVideo.places';
	var PLACES_MAX = 50;
	var PLACES_DAYS = 60;

	function readPlaces() {
		try {
			var places = JSON.parse(window.localStorage.getItem(PLACES) || '{}');
			return places && typeof places === 'object' ? places : {};
		} catch(error) {
			return {}; // no storage in this browser, or switched off
		}
	}

	function writePlace(id, time) {
		var places = readPlaces();
		var now = Date.now();
		if(time === null) {
			if(!places[id]) return;
			delete places[id];
		} else {
			places[id] = { t: Math.round(time), at: now };
		}
		// the oldest go: after some weeks, or when there are too many
		var ids = Object.keys(places).filter(function(key) {
			return places[key] && now - places[key].at < PLACES_DAYS * 86400000;
		}).sort(function(a, b) { return places[b].at - places[a].at; }).slice(0, PLACES_MAX);
		var kept = {};
		ids.forEach(function(key) { kept[key] = places[key]; });
		try {
			window.localStorage.setItem(PLACES, JSON.stringify(kept));
		} catch(error) {
			// full or not allowed: then it is not remembered
		}
	}

	function formatTime(seconds) {
		seconds = Math.max(0, Math.floor(seconds));
		var h = Math.floor(seconds / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;
		var pad = function(n) { return (n < 10 ? '0' : '') + n; };
		return h ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
	}

	/* the anonymous counts of the module */

	function report(url, video, event) {
		var body = 'v=' + encodeURIComponent(video) + '&e=' + encodeURIComponent(event);
		try {
			if(navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: 'application/x-www-form-urlencoded' }))) return;
		} catch(error) {
			// then as a normal request
		}
		try {
			window.fetch(url, {
				method: 'POST',
				body: body,
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				credentials: 'same-origin',
				keepalive: true
			}).catch(function() {});
		} catch(error) {
			// an old browser: not counted
		}
	}

	function fullscreenElement() {
		return document.fullscreenElement || document.webkitFullscreenElement || null;
	}

	function canFullscreen(element) {
		if(!(element.requestFullscreen || element.webkitRequestFullscreen)) return false;
		return !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);
	}

	function requestFullscreen(element) {
		var fn = element.requestFullscreen || element.webkitRequestFullscreen;
		var result = fn ? fn.call(element) : null;
		if(result && typeof result.catch === 'function') result.catch(function() {});
	}

	function exitFullscreen() {
		var fn = document.exitFullscreen || document.webkitExitFullscreen;
		var result = fn ? fn.call(document) : null;
		if(result && typeof result.catch === 'function') result.catch(function() {});
	}

	/**
	 * A CSS framework may have a class name of its own for the current item of a list.
	 * The link of a chapter and the list item around it say which in data-shv-current-class.
	 */
	function markCurrent(link, on) {
		[ link, link.parentNode ].forEach(function(element) {
			var names = element && element.getAttribute ? element.getAttribute('data-shv-current-class') : '';
			if(!names) return;
			names.split(/\s+/).forEach(function(name) {
				if(!name) return;
				if(on) {
					element.classList.add(name);
				} else {
					element.classList.remove(name);
				}
			});
		});
	}

	function initPlayer(wrapper) {

		var video = wrapper.querySelector('video');
		if(!video) return;

		var screen = wrapper.querySelector('.shv-video__screen');
		var button = wrapper.querySelector('.shv-video__fullscreen');
		var hasControls = video.hasAttribute('controls');
		var key = wrapper.getAttribute('data-shv-key') || '';
		var title = wrapper.getAttribute('data-shv-title') || '';
		var videoId = wrapper.getAttribute('data-shv-video') || '';
		var resumeAsked = !wrapper.hasAttribute('data-shv-resume') || !videoId;
		var player = {
			video: video,
			controls: hasControls,
			single: wrapper.getAttribute('data-shv-single') !== '0',
			resume: false, // paused by the script because it left the visible part of the page
			byScript: false // the next pause is one of the script, not of the visitor
		};
		players.push(player);

		function fire(type, extra) {
			var detail = {
				video: video,
				key: key,
				title: title,
				currentTime: video.currentTime || 0,
				duration: isFinite(video.duration) ? video.duration : 0
			};
			if(extra) for(var name in extra) detail[name] = extra[name];
			var event;
			try {
				event = new CustomEvent('shv:' + type, { bubbles: true, detail: detail });
			} catch(error) {
				return;
			}
			wrapper.dispatchEvent(event);
		}

		/* overlays and chapters: what is shown depends on the moment being played */

		var overlays = [];
		var chapters = [];
		var current = -1;
		var spoken = [];
		var said = -1;
		var frame = 0;
		var i;

		var found = wrapper.querySelectorAll('.shv-video__overlay[data-when]');
		for(i = 0; i < found.length; i++) {
			var end = parseFloat(found[i].getAttribute('data-end'));
			overlays.push({
				element: found[i],
				atEnd: found[i].getAttribute('data-when') === 'end',
				start: parseFloat(found[i].getAttribute('data-start')) || 0,
				end: isNaN(end) ? Infinity : end
			});
		}

		// the paragraphs of a transcript: their time links play from there, but they are no chapters
		found = wrapper.querySelectorAll('.shv-video__transcript-item');
		for(i = 0; i < found.length; i++) {
			var timeLink = found[i].querySelector('[data-shv-seek]');
			if(timeLink) spoken.push({ element: found[i], time: parseFloat(timeLink.getAttribute('data-shv-seek')) || 0 });
		}

		found = wrapper.querySelectorAll('[data-shv-seek]:not(.shv-video__transcript-time)');
		for(i = 0; i < found.length; i++) {
			var titleElement = found[i].querySelector('.shv-video__chapter-title');
			chapters.push({
				element: found[i],
				time: parseFloat(found[i].getAttribute('data-shv-seek')) || 0,
				title: (titleElement || found[i]).textContent
			});
		}

		function update() {
			var time = video.currentTime || 0;
			var n;

			for(n = 0; n < overlays.length; n++) {
				var overlay = overlays[n];
				var show = overlay.atEnd ? video.ended : (time >= overlay.start && time < overlay.end);
				// a link somebody is about to use does not vanish under their fingers
				if(!show && !overlay.element.hidden && overlay.element === document.activeElement) show = true;
				if(overlay.element.hidden === show) overlay.element.hidden = !show;
			}

			var started = time > 0 || !video.paused || video.ended;

			// the paragraph of the transcript being spoken
			if(spoken.length) {
				var line = -1;
				if(started) {
					for(n = 0; n < spoken.length; n++) {
						if(spoken[n].time <= time + 0.05) line = n;
					}
				}
				if(line !== said) {
					if(said > -1) spoken[said].element.removeAttribute('data-shv-current');
					said = line;
					if(line > -1) spoken[line].element.setAttribute('data-shv-current', '');
				}
			}

			if(!chapters.length) return;

			var now = -1;
			if(started) {
				for(n = 0; n < chapters.length; n++) {
					if(chapters[n].time <= time + 0.05) now = n;
				}
			}
			if(now === current) return;
			if(current > -1) {
				chapters[current].element.removeAttribute('aria-current');
				markCurrent(chapters[current].element, false);
			}
			current = now;
			if(now > -1) {
				chapters[now].element.setAttribute('aria-current', 'true');
				markCurrent(chapters[now].element, true);
				fire('chapter', { chapter: { index: now, time: chapters[now].time, title: chapters[now].title } });
			}
		}

		function tick() {
			frame = 0;
			update();
			if(!video.paused && !video.ended && overlays.length) frame = window.requestAnimationFrame(tick);
		}

		if(overlays.length || chapters.length || spoken.length) {
			var names = ['timeupdate', 'seeking', 'seeked', 'ended', 'loadedmetadata'];
			for(i = 0; i < names.length; i++) video.addEventListener(names[i], update);
			video.addEventListener('play', function() {
				if(!frame && window.requestAnimationFrame) tick();
			});
			wrapper.addEventListener('focusout', function() {
				window.setTimeout(update, 0);
			});
			update();
		}

		wrapper.addEventListener('click', function(event) {
			var target = event.target;
			if(!target || !target.closest) return;

			var link = target.closest('[data-shv-seek]');
			if(link && wrapper.contains(link)) {
				if(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return; // opens elsewhere
				event.preventDefault();
				resumeAsked = true; // the visitor chose where to start
				var time = parseFloat(link.getAttribute('data-shv-seek')) || 0;
				try {
					video.currentTime = time;
				} catch(error) {
					// not ready to move yet: do it once it is
					video.addEventListener('loadedmetadata', function() { video.currentTime = time; }, { once: true });
				}
				var started = video.play();
				if(started && typeof started.catch === 'function') started.catch(function() {});
				update();
				return;
			}

			var overlay = target.closest('a.shv-video__overlay');
			if(overlay && wrapper.contains(overlay)) {
				fire('overlayclick', { overlay: {
					id: overlay.getAttribute('data-shv-overlay') || '',
					text: overlay.textContent,
					url: overlay.href
				} });
			}
		});

		/* what happens with the video, told to the page */

		var marks = [25, 50, 75];
		var reached = {};
		var last = 0;
		var jumped = false;

		video.addEventListener('seeking', function() {
			jumped = true;
		});

		video.addEventListener('play', function() {
			wrapper.classList.add('shv-video--playing');
			player.resume = false;
			last = video.currentTime || 0;
			fire('play');

			// one video at a time
			if(!player.single || !hasControls) return;
			for(var n = 0; n < players.length; n++) {
				var other = players[n];
				if(other === player || !other.controls) continue;
				other.resume = false; // and none comes back by itself while this one plays
				if(other.video.paused || !document.documentElement.contains(other.video)) continue;
				other.byScript = true;
				other.video.pause();
			}
		});

		video.addEventListener('pause', function() {
			wrapper.classList.remove('shv-video--playing');
			var auto = player.byScript;
			player.byScript = false;
			if(!auto) player.resume = false;
			if(video.ended) return; // told as "ended"
			fire('pause', { auto: auto });
		});

		video.addEventListener('ended', function() {
			wrapper.classList.remove('shv-video--playing');
			if(!reached[100]) {
				reached[100] = true;
				fire('progress', { percent: 100 });
			}
			fire('ended');
		});

		video.addEventListener('timeupdate', function() {
			var time = video.currentTime || 0;
			var duration = video.duration;
			if(video.seeking) return;
			if(jumped || video.paused) {
				// only what is played counts, not a jump over it
				jumped = false;
				last = time;
				return;
			}
			if(isFinite(duration) && duration > 0 && time > last && time - last < 2) {
				for(var n = 0; n < marks.length; n++) {
					var mark = duration * marks[n] / 100;
					if(!reached[marks[n]] && last < mark && time >= mark) {
						reached[marks[n]] = true;
						fire('progress', { percent: marks[n] });
					}
				}
			}
			last = time;
		});

		/* the anonymous counts: started (once per page view), and how far it was played */

		var statsUrl = wrapper.getAttribute('data-shv-stats') || '';
		if(statsUrl && videoId) {
			var counted = {};
			var count = function(event) {
				if(counted[event]) return;
				counted[event] = true;
				report(statsUrl, videoId, event);
			};
			wrapper.addEventListener('shv:play', function(event) {
				if(event.target === wrapper) count('play');
			});
			wrapper.addEventListener('shv:progress', function(event) {
				if(event.target === wrapper && event.detail) count(String(event.detail.percent));
			});
		}

		/* going on where the visitor stopped */

		var resumeBox = wrapper.querySelector('.shv-video__resume');
		if(!resumeAsked) {
			var saved = 0;
			var resumeTimer = 0;

			var hideResume = function() {
				window.clearTimeout(resumeTimer);
				if(resumeBox) resumeBox.hidden = true;
			};

			var applyPlace = function(place) {
				var duration = video.duration;
				if(!isFinite(duration) || place.t < 10 || place.t > duration - 10) return;
				if((video.currentTime || 0) > 1) return; // already somewhere else
				try {
					video.currentTime = place.t;
				} catch(error) {
					return;
				}
				if(!resumeBox) return;
				var text = resumeBox.querySelector('.shv-video__resume-text');
				if(text) text.textContent = (resumeBox.getAttribute('data-text') || '%s').replace('%s', formatTime(place.t));
				resumeBox.hidden = false;
				resumeTimer = window.setTimeout(hideResume, 10000);
			};

			video.addEventListener('play', function() {
				if(resumeAsked) return;
				resumeAsked = true;
				var place = readPlaces()[videoId];
				if(!place || !place.t) return;
				if(video.readyState >= 1) {
					applyPlace(place);
				} else {
					video.addEventListener('loadedmetadata', function() { applyPlace(place); }, { once: true });
				}
			});

			var keepPlace = function(force) {
				var time = video.currentTime || 0;
				var duration = video.duration;
				if(!isFinite(duration) || duration <= 0) return;
				if(!force && Math.abs(time - saved) < 5) return;
				saved = time;
				// near the start or the end there is nothing to go on with
				writePlace(videoId, time >= 10 && time <= duration - 10 ? time : null);
			};

			video.addEventListener('timeupdate', function() {
				if(!video.paused && !video.seeking) keepPlace(false);
			});
			video.addEventListener('pause', function() {
				if(!video.ended) keepPlace(true);
			});
			video.addEventListener('ended', function() {
				writePlace(videoId, null);
				hideResume();
			});

			if(resumeBox) {
				var restart = resumeBox.querySelector('.shv-video__restart');
				if(restart) restart.addEventListener('click', function() {
					hideResume();
					video.currentTime = 0;
					writePlace(videoId, null);
					var started = video.play();
					if(started && typeof started.catch === 'function') started.catch(function() {});
					video.focus();
				});
			}
		}

		/* what is offered when the video has ended */

		var endBox = wrapper.querySelector('.shv-video__end');
		if(endBox) {
			var nextButton = endBox.querySelector('[data-shv-next]');
			var nextWrapper = function() {
				if(!nextButton) return null;
				var next = nextButton.getAttribute('data-shv-next');
				var all = document.querySelectorAll('[data-shv="player"]');
				for(var n = 0; n < all.length; n++) {
					if(all[n] !== wrapper && all[n].getAttribute('data-shv-key') === next) return all[n];
				}
				return null;
			};
			var hideEnd = function() {
				endBox.hidden = true;
			};

			video.addEventListener('ended', function() {
				if(nextButton) nextButton.hidden = !nextWrapper();
				endBox.hidden = false;
			});
			video.addEventListener('play', hideEnd);
			video.addEventListener('seeking', function() {
				if(!video.ended) hideEnd();
			});

			endBox.addEventListener('click', function(event) {
				var target = event.target && event.target.closest ? event.target : null;
				if(!target) return;
				if(target.closest('.shv-video__replay')) {
					hideEnd();
					video.currentTime = 0;
					var again = video.play();
					if(again && typeof again.catch === 'function') again.catch(function() {});
					video.focus();
				} else if(target.closest('[data-shv-next]')) {
					var nextPlayer = nextWrapper();
					var nextVideo = nextPlayer ? nextPlayer.querySelector('video') : null;
					if(!nextVideo) return;
					hideEnd();
					nextPlayer.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center' });
					try { nextVideo.focus({ preventScroll: true }); } catch(error) { nextVideo.focus(); }
					var started = nextVideo.play();
					if(started && typeof started.catch === 'function') started.catch(function() {});
				}
			});
		}

		/* full screen that takes the overlays along */

		if(button && screen && canFullscreen(screen)) {

			var quiet = 0;

			var showFullscreen = function() {
				var full = fullscreenElement();
				var active = full === screen;
				var was = button.getAttribute('data-state') === 'on';
				button.setAttribute('data-state', active ? 'on' : 'off');
				button.setAttribute('aria-label', button.getAttribute(active ? 'data-label-leave' : 'data-label-enter') || '');
				if(active !== was) fire('fullscreen', { active: active });
			};

			var wake = function() {
				screen.classList.add('shv-video__screen--active');
				window.clearTimeout(quiet);
				quiet = window.setTimeout(function() {
					screen.classList.remove('shv-video__screen--active');
				}, 3000);
			};

			button.hidden = false;
			button.setAttribute('data-state', 'off');
			wrapper.classList.add('shv-video--fullscreen-button');
			// Where the browser lets a page do so, its own button for this makes way. Where it does not
			// (Firefox, Safari), that button stays and shows the video alone, without its overlays.
			video.setAttribute('controlslist', ((video.getAttribute('controlslist') || '') + ' nofullscreen').trim());

			button.addEventListener('click', function() {
				if(fullscreenElement() === screen) {
					exitFullscreen();
				} else {
					requestFullscreen(screen);
				}
			});

			document.addEventListener('fullscreenchange', showFullscreen);
			document.addEventListener('webkitfullscreenchange', showFullscreen);

			screen.addEventListener('pointermove', wake);
			screen.addEventListener('pointerdown', wake);
		}

		/* out of view: pause, and go on when the video is back */

		if(hasObserver && hasControls && wrapper.getAttribute('data-shv-pause') !== '0') {
			watch(screen || video, '0px', false, function(visible) {
				if(!visible) {
					if(video.paused || video.ended) return;
					var full = fullscreenElement();
					if(full && (full === video || full.contains(video))) return;
					if(document.pictureInPictureElement === video) return;
					player.byScript = true;
					player.resume = true;
					video.pause();
				} else if(player.resume) {
					player.resume = false;
					if(!video.paused) return;
					var started = video.play();
					if(started && typeof started.catch === 'function') started.catch(function() {});
				}
			});
		}
	}

	/*** Setup ********************************************************************************/

	function each(root, selector, callback) {
		var list = [];
		if(root.matches && root.matches(selector)) list.push(root);
		var found = root.querySelectorAll ? root.querySelectorAll(selector) : [];
		for(var i = 0; i < found.length; i++) list.push(found[i]);
		for(var n = 0; n < list.length; n++) {
			if(list[n].hasAttribute(READY)) continue;
			list[n].setAttribute(READY, '');
			callback(list[n]);
		}
	}

	function init(root) {
		root = root || document;
		each(root, 'video[data-shv-poster]', initPoster);
		each(root, '[data-shv="background"]', initBackground);
		each(root, '[data-shv="player"]', initPlayer);
	}

	ns.player = { init: init };

	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() { init(document); });
	} else {
		init(document);
	}

})();
