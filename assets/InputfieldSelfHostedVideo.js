/**
 * Video Files input for the ProcessWire page editor
 *
 * - Creates a thumbnail in the browser for every video that has none, and saves it.
 * - Lets the editor use another frame, one of six suggested pictures or an image of their own as the thumbnail.
 * - Replaces the file of a video while keeping everything entered for it.
 * - Saves and removes caption files, and keeps the checklist of a video up to date.
 * - Sends videos to the server in parts, so that their size does not depend on the upload
 *   limits of the server, with progress, a way to cancel, and a way to continue after an
 *   interruption. Adding the finished file to the page is left to the core file input script.
 *
 * - Offers the videos of other pages for reuse: a search inside the field, and a button
 *   that adds the chosen video here without copying its file.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 */
(function($) {

	'use strict';

	// Everything the scripts of this module share with the page is kept under one name,
	// FieldtypeSelfHostedVideo, so that nothing of it can meet a name of another script.
	var ns = window.FieldtypeSelfHostedVideo = window.FieldtypeSelfHostedVideo || {};

	// set up once, however often the page loads this file
	if(ns.input) return;
	ns.input = {};

	var QUALITIES = [0.9, 0.8, 0.7, 0.6];
	var LOAD_TIMEOUT = 30000;

	var queue = [];
	var busy = false;

	function settings() {
		var config = window.ProcessWire && ProcessWire.config ? ProcessWire.config.InputfieldSelfHostedVideo : null;
		return config || { labels: {}, maxThumbBytes: 20971520 };
	}

	function escapeHtml(text) {
		return $('<div>').text(String(text)).html();
	}

	function label(name) {
		var labels = settings().labels || {};
		return labels[name] || '';
	}

	/**
	 * Where and how to send requests for the field a video belongs to (same as the core upload does)
	 *
	 * Takes any element inside the field, or the content element of the field itself.
	 */
	function context($box) {
		var $content = $box.hasClass('InputfieldFileInit') ? $box : $box.closest('.InputfieldContent');
		var $form = $content.parents('form').first();
		var $repeaterItem = $content.closest('.InputfieldRepeaterItem');
		var $upload = $content.find('.InputfieldFileUpload').first();
		var url = $upload.data('posturl');

		if($repeaterItem.length) {
			url = $repeaterItem.attr('data-editUrl');
		} else if(!url) {
			url = $form.attr('action');
		}

		url = String(url || './');
		url += (url.indexOf('?') > -1 ? '&' : '?') + 'InputfieldFileAjax=1';

		var $id = $('#Inputfield_id');
		if($id.length) url += '&eid=' + encodeURIComponent($id.val());

		var fieldName = String($upload.data('fieldname') || '');
		if(fieldName.indexOf('[') > -1) fieldName = fieldName.slice(0, -2);

		var $token = $form.find('input._post_token');

		return {
			url: url,
			fieldName: fieldName,
			tokenName: $token.attr('name'),
			tokenValue: $token.val(),
			maxFilesize: parseInt($upload.data('maxfilesize'), 10) || 0
		};
	}

	/**
	 * Send a request for one video and resolve with the parsed answer
	 */
	function send($box, params, body, onProgress) {
		return new Promise(function(resolve, reject) {
			var c = context($box);
			var url = c.url;

			$.each(params, function(key, value) {
				url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(value);
			});

			var xhr = new XMLHttpRequest();
			xhr.open('POST', url, true);
			xhr.setRequestHeader('X-FIELDNAME', c.fieldName);
			xhr.setRequestHeader('Content-Type', 'application/octet-stream');
			if(c.tokenName) xhr.setRequestHeader('X-' + c.tokenName, c.tokenValue);
			xhr.setRequestHeader('X-REQUESTED-WITH', 'XMLHttpRequest');

			if(onProgress && xhr.upload) {
				xhr.upload.addEventListener('progress', function(e) {
					if(e.lengthComputable) onProgress(e.loaded / e.total);
				});
			}

			xhr.addEventListener('load', function() {
				var response = null;
				try {
					response = JSON.parse(xhr.responseText);
				} catch(e) {
					response = null;
				}
				if(!response || typeof response !== 'object') {
					reject(new Error(label('requestError')));
				} else if(response.error) {
					reject(new Error(response.message || label('requestError')));
				} else {
					resolve(response);
				}
			});

			xhr.addEventListener('error', function() {
				reject(new Error(label('requestError')));
			});

			xhr.send(body);
		});
	}

	/**
	 * Load a video out of sight and bring it to the given time, ready for its picture to be taken
	 */
	function loadFrame(url, time, exact) {
		return new Promise(function(resolve, reject) {
			var video = document.createElement('video');
			var settled = false;

			video.muted = true;
			video.playsInline = true;
			video.setAttribute('playsinline', '');
			video.preload = 'auto';
			video.setAttribute('aria-hidden', 'true');
			video.tabIndex = -1;
			video.style.cssText = 'position:absolute;left:-9999px;top:0;width:2px;height:2px;opacity:0;pointer-events:none';
			document.body.appendChild(video);

			function cleanup() {
				video.removeAttribute('src');
				try { video.load(); } catch(e) { /* nothing to release */ }
				if(video.parentNode) video.parentNode.removeChild(video);
			}

			function finish(error) {
				if(settled) return;
				settled = true;
				clearTimeout(timer);
				if(error) {
					cleanup();
					reject(error);
				} else {
					resolve({ video: video, cleanup: cleanup });
				}
			}

			function ready() {
				// give the browser a moment to have the picture at hand
				var go = function() { setTimeout(function() { finish(); }, 80); };
				if(video.readyState >= 2) {
					go();
				} else {
					video.addEventListener('canplay', go, { once: true });
				}
			}

			var timer = setTimeout(function() { finish(new Error('timeout')); }, LOAD_TIMEOUT);

			video.addEventListener('error', function() { finish(new Error('decode')); });

			video.addEventListener('loadedmetadata', function() {
				var duration = isFinite(video.duration) ? video.duration : 0;
				var t = Math.max(0, parseFloat(time) || 0);
				if(duration > 0 && t > duration / 2 && !exact) t = duration / 2;
				if(duration > 0 && t > duration - 0.05) t = Math.max(0, duration - 0.05);
				if(t < 0.05) {
					if(video.readyState >= 2) {
						ready();
					} else {
						video.addEventListener('loadeddata', ready, { once: true });
					}
				} else {
					video.addEventListener('seeked', ready, { once: true });
					video.currentTime = t;
				}
			}, { once: true });

			video.src = url;
			video.load();
		});
	}

	/**
	 * Take the current picture of a video element as a JPEG that stays below the given size
	 */
	function grab(video, maxBytes) {
		return new Promise(function(resolve, reject) {
			var width = video.videoWidth;
			var height = video.videoHeight;

			if(!width || !height) {
				reject(new Error('no picture'));
				return;
			}

			var scale = 1;
			var q = 0;

			function attempt() {
				var canvas = document.createElement('canvas');
				canvas.width = Math.max(1, Math.round(width * scale));
				canvas.height = Math.max(1, Math.round(height * scale));
				try {
					canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
				} catch(e) {
					reject(e);
					return;
				}
				canvas.toBlob(function(blob) {
					if(!blob) {
						reject(new Error('no picture'));
					} else if(!maxBytes || blob.size <= maxBytes) {
						resolve(blob);
					} else if(q < QUALITIES.length - 1) {
						q++;
						attempt();
					} else if(scale > 0.2) {
						// still too large for this server: a smaller picture is better than none
						scale *= 0.75;
						q = 1;
						attempt();
					} else {
						reject(new Error('too large'));
					}
				}, 'image/jpeg', QUALITIES[q]);
			}

			attempt();
		});
	}

	function maxThumbBytes($box) {
		var max = settings().maxThumbBytes || 0;
		var server = context($box).maxFilesize;
		if(server && (!max || server < max)) max = server;
		return max;
	}

	function setStatus($box, text, isError) {
		var $status = $box.find('.InputfieldSelfHostedVideoStatus');
		$status.text(text || '').toggleClass('ui-state-error-text', !!isError);
		var timer = $status.data('vfTimer');
		if(timer) clearTimeout(timer);
		if(text && !isError) {
			$status.data('vfTimer', setTimeout(function() { $status.text(''); }, 6000));
		}
	}

	function setBusy($box, state) {
		$box.toggleClass('InputfieldSelfHostedVideoBusy', state);
		// the buttons that belong to a running upload stay usable
		$box.find('.InputfieldSelfHostedVideoTools button').not('.InputfieldSelfHostedVideoDuring').prop('disabled', state);
	}

	/**
	 * Let the editor choose a file
	 *
	 * The file input exists only for the moment of choosing and lives outside of the field,
	 * because the core upload script claims the first file input it finds inside a field.
	 */
	function pickFile(accept, callback) {
		$('.InputfieldSelfHostedVideoPicker').remove();
		var input = document.createElement('input');
		input.type = 'file';
		input.accept = accept || '';
		input.className = 'InputfieldSelfHostedVideoPicker';
		input.tabIndex = -1;
		input.setAttribute('aria-hidden', 'true');
		input.style.display = 'none';
		input.addEventListener('change', function() {
			var file = input.files && input.files[0];
			if(input.parentNode) input.parentNode.removeChild(input);
			if(file) callback(file);
		});
		document.body.appendChild(input);
		input.click();
	}

	/**
	 * Show what the server answered after saving a thumbnail
	 */
	function applyThumb($box, response) {
		var $player = $box.find('video.InputfieldSelfHostedVideoPlayer');
		if(response.thumb) $player.attr('poster', response.thumb);
		if(response.width && response.height) $player.attr({ width: response.width, height: response.height });
		if(response.stats) $box.closest('.InputfieldFileItem').find('.InputfieldFileStats').first().html(response.stats);
		$box.toggleClass('InputfieldSelfHostedVideoPortrait', !!response.portrait);
		// the small picture in the header of an accordion item
		var $head = $box.closest('.InputfieldFileItem').find('.InputfieldSelfHostedVideoHeadThumb').first();
		if($head.length && response.thumb) {
			$head.empty().append($('<img alt="">').attr('src', response.thumb));
			$head.toggleClass('InputfieldSelfHostedVideoHeadThumbPortrait', !!response.portrait);
		}
		applyChecks($box, response);
		$box.attr('data-needs-thumb', '0').attr('data-custom', response.custom ? '1' : '0');
		$box.removeClass('InputfieldSelfHostedVideoNoThumb');
		$box.find('.InputfieldSelfHostedVideoResetFrame').prop('hidden', !response.custom);
	}

	function saveThumb($box, blob, info) {
		setStatus($box, label('saving'));
		return send($box, {
			shv: 'thumb',
			vf_name: $box.attr('data-name'),
			vf_time: info.time || 0,
			vf_duration: info.duration || 0,
			vf_width: info.width || 0,
			vf_height: info.height || 0,
			vf_custom: info.custom ? 1 : 0
		}, blob).then(function(response) {
			applyThumb($box, response);
			markFrame($box, info.custom ? null : Math.round((info.time || 0) * 100) / 100);
			setStatus($box, label('saved'));
			$box.trigger('shvThumbSaved', [ response ]);
			return response;
		});
	}

	function describe(video) {
		return {
			time: video.currentTime || 0,
			duration: isFinite(video.duration) ? video.duration : 0,
			width: video.videoWidth || 0,
			height: video.videoHeight || 0
		};
	}

	/**
	 * Take the picture at the given time from the video file and save it as the thumbnail
	 */
	function createThumb($box, time, exact) {
		setBusy($box, true);
		setStatus($box, label('creating'));
		return loadFrame($box.attr('data-url'), time, exact).then(function(loaded) {
			var info = describe(loaded.video);
			return grab(loaded.video, maxThumbBytes($box)).then(function(blob) {
				loaded.cleanup();
				return saveThumb($box, blob, info);
			}, function(error) {
				loaded.cleanup();
				throw error;
			});
		}).then(function(response) {
			setBusy($box, false);
			return response;
		}, function(error) {
			setBusy($box, false);
			$box.addClass('InputfieldSelfHostedVideoNoThumb');
			var known = error && (error.message === 'decode' || error.message === 'timeout' || error.message === 'no picture' || error.message === 'too large');
			setStatus($box, known || !error ? label('failed') : label('saveError') + ' ' + error.message, true);
			if(error && (error.message === 'decode' || error.message === 'no picture')) {
				// this browser cannot play the file: worth noting on the checklist of the video
				send($box, { shv: 'report', vf_name: $box.attr('data-name'), vf_playable: 0, vf_agent: browserName() }, null).then(function(response) {
					applyChecks($box, response);
				}, function() { /* the editor has been told already */ });
			}
			$box.trigger('shvThumbFailed', [ error ]);
		});
	}

	function next() {
		if(busy) return;
		var $box = queue.shift();
		if(!$box) return;
		if(!document.body.contains($box[0]) || $box.attr('data-needs-thumb') !== '1') {
			next();
			return;
		}
		busy = true;
		createThumb($box, $box.attr('data-thumb-time')).then(function() {
			busy = false;
			next();
		});
	}

	/**
	 * Find videos without a thumbnail and create one for each, one after the other
	 */
	/**
	 * Checklists that wait for the server to say whether it keeps other websites from embedding a video
	 */
	var embedQueue = [];
	var embedBusy = false;

	function askEmbed($root) {
		$root.find('.InputfieldSelfHostedVideoCheck[data-ask="1"]').each(function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			if(!$box.length || $box.data('vfEmbedAsked')) return;
			$box.data('vfEmbedAsked', true);
			embedQueue.push($box);
		});
		nextEmbed();
	}

	function nextEmbed() {
		if(embedBusy) return;
		var $box = embedQueue.shift();
		if(!$box) return;
		if(!document.body.contains($box[0]) || !$box.attr('data-name')) {
			nextEmbed();
			return;
		}
		embedBusy = true;
		send($box, { shv: 'embedcheck', vf_name: $box.attr('data-name') }, null).then(function(response) {
			applyChecks($box, response);
		}, function() { /* the checklist keeps saying it is being checked */ }).then(function() {
			embedBusy = false;
			nextEmbed();
		});
	}

	function scan($root) {
		watchFrames($root);
		askEmbed($root);
		// once the core has set up the upload of the field
		setTimeout(function() { showResumable($root); }, 300);
		$root.find('.InputfieldSelfHostedVideoBox[data-needs-thumb="1"]').addBack('.InputfieldSelfHostedVideoBox[data-needs-thumb="1"]').each(function() {
			var $box = $(this);
			if($box.data('vfQueued')) return;
			$box.data('vfQueued', true);
			queue.push($box);
		});
		next();
	}

	/*** Suggested pictures: six frames spread over the video to choose the thumbnail from ******/

	var frameQueue = [];
	var frameBusy = false;
	var frameObserver = null;

	// width in pixels of a suggested picture
	var FRAME_WIDTH = 240;

	function clockTime(seconds) {
		var t = Math.max(0, Math.round(seconds || 0));
		var h = Math.floor(t / 3600);
		var m = Math.floor((t % 3600) / 60);
		var s = t % 60;
		return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
	}

	/**
	 * Mark the suggested picture that is the thumbnail now
	 *
	 * @param $box
	 * @param time Position of the thumbnail in the video, or null for an image of the editor's own
	 */
	function markFrame($box, time) {
		var $frames = $box.find('.InputfieldSelfHostedVideoFrames');
		if(!$frames.length) return;
		if(time === null || time === undefined) {
			$frames.removeAttr('data-current');
		} else {
			$frames.attr('data-current', time);
		}
		$frames.find('.InputfieldSelfHostedVideoFrame').each(function() {
			var on = time !== null && time !== undefined && Math.abs(parseFloat($(this).attr("data-time")) - time) < 0.06;
			$(this).attr('aria-pressed', on ? 'true' : 'false');
		});
	}

	/**
	 * Make the suggested pictures of one video, one after the other, from a copy out of sight
	 */
	function makeFrames($frames) {
		return new Promise(function(resolve) {
			var $box = $frames.closest('.InputfieldSelfHostedVideoBox');
			var url = $box.attr('data-url');
			var count = parseInt($frames.attr('data-count'), 10) || 6;
			var $list = $frames.find('.InputfieldSelfHostedVideoFrameList');
			var video = document.createElement('video');
			var finished = false;
			var timer = 0;

			if(!url || !document.body.contains($frames[0])) {
				resolve();
				return;
			}

			video.muted = true;
			video.playsInline = true;
			video.setAttribute('playsinline', '');
			video.preload = 'metadata';
			video.setAttribute('aria-hidden', 'true');
			video.tabIndex = -1;
			video.style.cssText = 'position:absolute;left:-9999px;top:0;width:2px;height:2px;opacity:0;pointer-events:none';
			document.body.appendChild(video);

			function finish() {
				if(finished) return;
				finished = true;
				clearTimeout(timer);
				video.removeAttribute('src');
				try { video.load(); } catch(e) { /* nothing to release */ }
				if(video.parentNode) video.parentNode.removeChild(video);
				// a browser that cannot play the file has no pictures to offer
				if(!$list.children().length) {
					$frames.addClass('InputfieldSelfHostedVideoFramesFailed');
					$frames.find('.InputfieldSelfHostedVideoFramesHint').prop('hidden', true);
					$frames.find('.InputfieldSelfHostedVideoFramesNote').text(label('framesFailed')).prop('hidden', false);
				}
				resolve();
			}

			function arm() {
				clearTimeout(timer);
				timer = setTimeout(finish, LOAD_TIMEOUT);
			}

			function add(time) {
				var width = video.videoWidth;
				var height = video.videoHeight;
				var canvas = document.createElement('canvas');
				var scale = Math.min(1, FRAME_WIDTH / width);
				var src;
				canvas.width = Math.max(1, Math.round(width * scale));
				canvas.height = Math.max(1, Math.round(height * scale));
				try {
					canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
					src = canvas.toDataURL('image/jpeg', 0.75);
				} catch(e) {
					return false;
				}
				var clock = clockTime(time);
				var $button = $('<button type="button" class="InputfieldSelfHostedVideoFrame" aria-pressed="false"></button>')
					.attr('data-time', time)
					.attr('aria-label', label('frameAt').replace('%s', clock))
					.prop('disabled', $box.hasClass('InputfieldSelfHostedVideoBusy'))
					.append($('<img alt="">').attr({ src: src, width: canvas.width, height: canvas.height }))
					.append($('<span class="InputfieldSelfHostedVideoFrameTime" aria-hidden="true"></span>').text(clock))
					.append($('<span class="InputfieldSelfHostedVideoFrameMark" aria-hidden="true"><i class="fa fa-check"></i></span>').attr('title', label('frameCurrent')));
				$list.append($('<li></li>').append($button));
				if($list.children().length >= count) $frames.find('.InputfieldSelfHostedVideoFramesNote').text('').prop('hidden', true);
				var current = $frames.attr('data-current');
				markFrame($box, current === undefined || current === '' ? null : parseFloat(current));
				return true;
			}

			arm();
			video.addEventListener('error', finish);
			video.addEventListener('loadedmetadata', function() {
				var duration = isFinite(video.duration) ? video.duration : 0;
				var times = [];
				if(!duration || !video.videoWidth) {
					finish();
					return;
				}
				for(var i = 0; i < count; i++) {
					times.push(Math.round((i + 0.5) / count * duration * 10) / 10);
				}
				$list.empty();
				(function step(n) {
					if(finished) return;
					if(n >= times.length) {
						finish();
						return;
					}
					arm();
					video.addEventListener('seeked', function() {
						// give the browser a moment to have the picture at hand
						setTimeout(function() {
							if(finished) return;
							if(!add(times[n])) {
								finish();
								return;
							}
							step(n + 1);
						}, 60);
					}, { once: true });
					video.currentTime = times[n];
				})(0);
			}, { once: true });

			video.src = url;
			video.load();
		});
	}

	function nextFrames() {
		if(frameBusy) return;
		var $frames = frameQueue.shift();
		if(!$frames) return;
		frameBusy = true;
		makeFrames($frames).then(function() {
			frameBusy = false;
			nextFrames();
		});
	}

	function queueFrames($frames) {
		if($frames.data('vfFramesQueued')) return;
		$frames.data('vfFramesQueued', true);
		frameQueue.push($frames);
		nextFrames();
	}

	/**
	 * Make the suggested pictures of a video once its thumbnail area comes into view
	 *
	 * A video in a closed item, a tab that is not open or a field that is collapsed costs nothing.
	 */
	function watchFrames($root) {
		$root.find('.InputfieldSelfHostedVideoFrames').addBack('.InputfieldSelfHostedVideoFrames').each(function() {
			var $frames = $(this);
			var area = $frames.closest('.InputfieldSelfHostedVideoAreaBody')[0];
			if(!area || $frames.data('vfFramesWatched')) return;
			$frames.data('vfFramesWatched', true);
			if(!('IntersectionObserver' in window)) {
				queueFrames($frames);
				return;
			}
			if(!frameObserver) {
				frameObserver = new IntersectionObserver(function(entries) {
					entries.forEach(function(entry) {
						if(!entry.isIntersecting) return;
						frameObserver.unobserve(entry.target);
						var $f = $(entry.target).data('vfFrames');
						if($f && document.body.contains($f[0])) queueFrames($f);
					});
				}, { rootMargin: '200px 0px' });
			}
			$(area).data('vfFrames', $frames);
			frameObserver.observe(area);
		});
	}

	/*** Accordion: in a field for several videos, a video is closed until it is worked on *****/

	/**
	 * Open or close the item of a video
	 */
	function setOpen($item, open) {
		var $toggle = $item.children('.InputfieldFileInfo').find('.InputfieldSelfHostedVideoToggle').first();
		var $body = $item.children('.InputfieldSelfHostedVideoBody').first();
		if(!$toggle.length || !$body.length) return;
		// the core slides this part when a video is marked for deletion, which leaves styles behind
		$body.stop(true, true).css({ display: '', height: '', overflow: '' }).prop('hidden', !open);
		$toggle.attr('aria-expanded', open ? 'true' : 'false');
		$item.toggleClass('InputfieldSelfHostedVideoOpen', !!open);
		// Custom fields of the video were hidden until now. Some inputs, a rich text editor for
		// one, only set themselves up once they can be seen, and wait for the event the page
		// editor sends when it opens a field.
		if(open) {
			$body.find('.InputfieldSelfHostedVideoCustom').find('.Inputfields').first().children('.Inputfield').not('.InputfieldStateCollapsed').each(function() {
				$(this).trigger('opened', [ $(this) ]);
			});
		}
	}

	/**
	 * Make the headers say what their items are: open or closed
	 *
	 * Needed where a header was replaced without its item, as the core does for a file that
	 * overwrites another.
	 */
	function syncItems($root) {
		$root.find('.InputfieldSelfHostedVideoToggle').each(function() {
			var $item = $(this).closest('.InputfieldFileItem');
			var $body = $item.children('.InputfieldSelfHostedVideoBody').first();
			if($body.length) setOpen($item, !$body.prop('hidden'));
		});
	}

	/**
	 * Show in the header of an item the title that its inputs hold
	 */
	function updateHeadTitle($item) {
		var $toggle = $item.children('.InputfieldFileInfo').find('.InputfieldSelfHostedVideoToggle').first();
		if(!$toggle.length) return;
		var names = String($toggle.attr('data-title-inputs') || '').split(/\s+/);
		var $inputs = $item.find('.InputfieldSelfHostedVideoLangText_title input');
		var text = '';
		if(!$inputs.length) return;
		$.each(names, function(n, name) {
			if(text || !name) return;
			$inputs.each(function() {
				if(!text && this.name === name) text = $.trim($(this).val());
			});
		});
		$toggle.find('.InputfieldSelfHostedVideoHeadTitle').text(text || $toggle.attr('data-fallback') || '');
		labelGrip($item);
	}

	/**
	 * Where the items that are open are remembered while the page is saved
	 */
	function openKey() {
		var id = /[?&]id=(\d+)/.exec(window.location.search);
		return 'InputfieldSelfHostedVideoOpen:' + window.location.pathname + (id ? ':' + id[1] : '');
	}

	function rememberOpen() {
		var ids = [];
		$('.InputfieldSelfHostedVideoBody').each(function() {
			if(!this.hidden && this.id) ids.push(this.id);
		});
		try {
			window.sessionStorage.setItem(openKey(), JSON.stringify({ time: Date.now(), ids: ids }));
		} catch(e) { /* then the items are closed after saving */ }
	}

	// what was open when the page was saved, and is not on the page yet (a field that loads later)
	var remembered = [];

	// the video the address of the page asks for, until it is found
	var askedFor = '';

	/**
	 * Scroll to a video, once the page editor has shown its field
	 *
	 * The page editor first shows the tab the field is in and scrolls to the field (the address
	 * ends in "#find-" and the name of the field). In a long list the video can still be out of view.
	 */
	function bringIntoView($item) {
		setTimeout(function() {
			var head = $item.children('.InputfieldFileInfo')[0];
			if(!head || !document.body.contains(head)) return;
			var rect = head.getBoundingClientRect();
			// not shown: its tab is not the one that is open
			if(!rect.height) return;
			var height = window.innerHeight || document.documentElement.clientHeight;
			if(rect.top < 90 || rect.top > height * 0.6) window.scrollTo(0, Math.max(0, rect.top + window.pageYOffset - 100));
		}, 900);
	}

	/**
	 * Show a button of an item, give it the focus and mark it for a moment
	 *
	 * @param $button
	 */
	function bringToButton($button) {
		setTimeout(function() {
			var button = $button[0];
			if(!button || !document.body.contains(button) || !button.getBoundingClientRect().height) return;
			var rect = button.getBoundingClientRect();
			var height = window.innerHeight || document.documentElement.clientHeight;
			window.scrollTo(0, Math.max(0, rect.top + window.pageYOffset - height / 3));
			button.focus({ preventScroll: true });
			$button.addClass('InputfieldSelfHostedVideoAsked');
			setTimeout(function() { $button.removeClass('InputfieldSelfHostedVideoAsked'); }, 4000);
		}, 900);
	}

	/**
	 * Open the items that were open when the page was saved, and those with an input the server
	 * did not accept
	 *
	 * @param $root A field that was loaded later, or nothing for the page as it arrives
	 */
	function restoreOpen($root) {
		if(!$root) {
			var stored = null;
			try {
				stored = JSON.parse(window.sessionStorage.getItem(openKey()) || 'null');
				window.sessionStorage.removeItem(openKey());
			} catch(e) { /* nothing remembered */ }
			// only right after saving: a later visit starts with closed items
			if(stored && $.isArray(stored.ids) && Date.now() - stored.time < 120000) remembered = stored.ids;
			// a link that leads to one video, as those of the overview of all videos do
			var asked = /[?&]vf_open=([A-Za-z0-9_]+)/.exec(window.location.search);
			if(asked) {
				askedFor = asked[1] + '_data';
				remembered.push(askedFor);
			}
			$root = $(document);
		}
		remembered = $.grep(remembered, function(id) {
			var body = document.getElementById(id);
			if(!body || !$(body).hasClass('InputfieldSelfHostedVideoBody')) return true;
			var $item = $(body).closest('.InputfieldFileItem');
			setOpen($item, true);
			if(id === askedFor) {
				revealItem($item);
				askedFor = '';
				// "Replace video file" in the Video Manager: to the button that does it
				var $replace = /[?&]vf_replace=1(&|$)/.test(window.location.search) ? $item.find('.InputfieldSelfHostedVideoReplaceFile').first() : $();
				if($replace.length) {
					bringToButton($replace);
				} else {
					bringIntoView($item);
				}
			}
			return false;
		});
		// an input the server did not accept must not hide
		$root.find('.InputfieldSelfHostedVideoBody[hidden]').each(function() {
			if($(this).find('.InputfieldStateError').length) setOpen($(this).closest('.InputfieldFileItem'), true);
		});
		// and must not be on another page
		var $error = $root.find('.InputfieldSelfHostedVideoBody .InputfieldStateError').first();
		if($error.length) revealItem($error.closest('.InputfieldFileItem'));
	}

	/*** Order: each video has a handle to drag it, and a menu that moves it without dragging *****/

	function itemTitle($item) {
		var $title = $item.children('.InputfieldFileInfo').find('.InputfieldSelfHostedVideoHeadTitle').first();
		return $.trim($title.text());
	}

	function labelGrip($item) {
		var $grip = $item.children('.InputfieldFileInfo').find('.InputfieldSelfHostedVideoGrip').first();
		if(!$grip.length) return;
		$grip.attr('aria-label', label('moveLabel').replace('%s', itemTitle($item)));
		$grip.attr('title', label('moveHint'));
	}

	/**
	 * Let the core's sorting of a list start only at the handles, upright, with a slot where the video will land
	 */
	function configureSort($list) {
		if(!$list.length || !$list.is('.ui-sortable') || $list.data('vfSortReady')) return;
		if(!$list.find('.InputfieldSelfHostedVideoGrip').length) return;
		$list.data('vfSortReady', true);
		$list.sortable('option', {
			handle: '.InputfieldSelfHostedVideoGrip',
			// the handle is a button, which the sorting leaves alone by default
			cancel: 'input,textarea,select,option,.InputfieldSelfHostedVideoMoveMenu',
			items: '> li.InputfieldFileItem:not(.InputfieldSelfHostedVideoOffPage)',
			axis: 'y',
			tolerance: 'pointer',
			placeholder: 'InputfieldSelfHostedVideoDropSlot',
			forcePlaceholderSize: true,
			cursor: 'grabbing',
			opacity: 0.92
		});
		$list.on('sortstart', function(event, ui) {
			closeMoveMenu();
			// while a video is dragged all videos are closed, so that it passes few and short ones;
			// the header that is held stays under the pointer
			var head = ui.item.children('.InputfieldFileInfo')[0];
			var before = ui.placeholder[0].getBoundingClientRect().top;
			$list.addClass('InputfieldSelfHostedVideoSorting');
			ui.placeholder.height(head ? head.offsetHeight : 48);
			var after = ui.placeholder[0].getBoundingClientRect().top;
			if(after !== before) window.scrollBy(0, after - before);
			$list.sortable('refreshPositions');
		}).on('sortstop', function(event, ui) {
			var head = ui.item.children('.InputfieldFileInfo')[0];
			var before = head ? head.getBoundingClientRect().top : 0;
			$list.removeClass('InputfieldSelfHostedVideoSorting');
			var after = head ? head.getBoundingClientRect().top : 0;
			if(head && after !== before) window.scrollBy(0, after - before);
			afterMove(ui.item, false);
		});
	}

	function configureAllSorts($root) {
		$root.find('.InputfieldSelfHostedVideo .InputfieldFileList').each(function() { configureSort($(this)); });
		$root.find('.InputfieldSelfHostedVideo .InputfieldFileList > li').each(function() { labelGrip($(this)); });
	}

	/**
	 * What follows a move: the order for saving, the page it is on, what is said
	 */
	function afterMove($item, focus) {
		var $list = $item.parent();
		var $items = pagerItems($list);
		$items.each(function(n) { $(this).find('.InputfieldFileSort').first().val(n); });
		var $field = $list.closest('.InputfieldSelfHostedVideo');
		$list.closest('.Inputfield').addClass('InputfieldStateChanged');
		$list.closest('.Inputfield').trigger('sorted', [ $item ]);
		if($field.data('vfPager')) showPage($field, $field.data('vfPage') || 1, false);
		revealItem($item);
		$item.addClass('InputfieldSelfHostedVideoMoved');
		setTimeout(function() { $item.removeClass('InputfieldSelfHostedVideoMoved'); }, 1600);
		var position = $items.index($item) + 1;
		announce(label('moved').replace('%1$s', itemTitle($item)).replace('%2$d', position).replace('%3$d', $items.length) + ' ' + label('moveSave'));
		if(focus) {
			var $grip = $item.children('.InputfieldFileInfo').find('.InputfieldSelfHostedVideoGrip').first();
			try { $grip[0].focus({ preventScroll: true }); } catch(e) { $grip.trigger('focus'); }
			var rect = $grip[0].getBoundingClientRect();
			var height = window.innerHeight || 800;
			if(rect.top < 70 || rect.bottom > height - 40) window.scrollTo(0, Math.max(0, rect.top + window.pageYOffset - height / 3));
		}
	}

	/**
	 * Move a video to a place in its list: a number from 0, or "up", "down", "first", "last"
	 */
	function moveItem($item, to) {
		var $list = $item.parent();
		var $items = pagerItems($list);
		var from = $items.index($item);
		var last = $items.length - 1;
		if(to === 'up') to = from - 1;
		else if(to === 'down') to = from + 1;
		else if(to === 'first') to = 0;
		else if(to === 'last') to = last;
		to = Math.max(0, Math.min(last, parseInt(to, 10)));
		if(isNaN(to) || to === from) return false;
		var $target = $items.eq(to);
		if(to < from) $item.insertBefore($target); else $item.insertAfter($target);
		afterMove($item, true);
		return true;
	}

	function closeMoveMenu(focus) {
		var $menu = $('.InputfieldSelfHostedVideoMoveMenu');
		if(!$menu.length) return;
		var $grip = $menu.data('grip');
		$menu.remove();
		if($grip) {
			$grip.attr('aria-expanded', 'false');
			if(focus) $grip.trigger('focus');
		}
	}

	function openMoveMenu($grip) {
		closeMoveMenu();
		var $item = $grip.closest('li.InputfieldFileItem');
		var $items = pagerItems($item.parent());
		var index = $items.index($item);
		var count = $items.length;
		var id = 'InputfieldSelfHostedVideoMove_' + Date.now();
		var button = function(move, icon, text, off) {
			return '<button type="button" class="InputfieldSelfHostedVideoMoveAction" data-move="' + move + '"' + (off ? ' disabled' : '') + '>' +
				'<i class="fa fa-fw ' + icon + '" aria-hidden="true"></i> <span>' + escapeHtml(label(text)) + '</span></button>';
		};
		var $menu = $(
			'<div class="InputfieldSelfHostedVideoMoveMenu" role="group" id="' + id + '">' +
				'<p class="InputfieldSelfHostedVideoMoveMenuTitle"></p>' +
				button('up', 'fa-angle-up', 'moveUp', index === 0) +
				button('down', 'fa-angle-down', 'moveDown', index === count - 1) +
				button('first', 'fa-angle-double-up', 'moveFirst', index === 0) +
				button('last', 'fa-angle-double-down', 'moveLast', index === count - 1) +
				'<div class="InputfieldSelfHostedVideoMovePosition">' +
					'<label><span></span> <input type="number" min="1" max="' + count + '" value="' + (index + 1) + '"> <span></span></label>' +
					'<button type="button" class="InputfieldSelfHostedVideoMoveAction" data-move="position"></button>' +
				'</div>' +
			'</div>'
		);
		$menu.attr('aria-label', label('moveMenu'));
		$menu.find('.InputfieldSelfHostedVideoMoveMenuTitle').text(itemTitle($item));
		var $spans = $menu.find('.InputfieldSelfHostedVideoMovePosition label > span');
		$spans.eq(0).text(label('movePosition'));
		$spans.eq(1).text(label('moveOf').replace('%d', count));
		$menu.find('[data-move="position"]').text(label('moveGo'));
		$menu.data('grip', $grip).data('item', $item);
		$grip.closest('.InputfieldFileInfo').after($menu);
		$grip.attr('aria-expanded', 'true').attr('aria-controls', id);
		var $first = $menu.find('button:not([disabled])').first();
		if($first.length) $first.trigger('focus');
	}

	$(document).on('mouseover focusin touchstart', '.InputfieldSelfHostedVideoGrip', function() {
		// the core makes a list sortable once it is shown, after this script has started
		configureSort($(this).closest('.InputfieldFileList'));
	}).on('click', '.InputfieldSelfHostedVideoGrip', function(event) {
		event.preventDefault();
		// the end of a drag is not a click
		if($(this).closest('.InputfieldFileJustSorted').length) return false;
		if($(this).attr('aria-expanded') === 'true') closeMoveMenu(true); else openMoveMenu($(this));
		return false;
	}).on('keydown', '.InputfieldSelfHostedVideoGrip', function(event) {
		var key = event.key;
		if(key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'Home' && key !== 'End') return;
		event.preventDefault();
		closeMoveMenu();
		var move = { ArrowUp: 'up', ArrowDown: 'down', Home: 'first', End: 'last' }[key];
		moveItem($(this).closest('li.InputfieldFileItem'), move);
	}).on('click', '.InputfieldSelfHostedVideoMoveAction', function(event) {
		event.preventDefault();
		var $menu = $(this).closest('.InputfieldSelfHostedVideoMoveMenu');
		var $item = $menu.data('item');
		var move = $(this).attr('data-move');
		if(move === 'position') move = (parseInt($menu.find('input[type=number]').val(), 10) || 1) - 1;
		var $grip = $item.children('.InputfieldFileInfo').find('.InputfieldSelfHostedVideoGrip').first();
		$menu.data('grip', null);
		closeMoveMenu();
		$grip.attr('aria-expanded', 'false');
		if(!moveItem($item, move)) $grip.trigger('focus');
		return false;
	}).on('keydown', '.InputfieldSelfHostedVideoMoveMenu', function(event) {
		if(event.key === 'Escape') {
			event.preventDefault();
			closeMoveMenu(true);
		} else if(event.key === 'Enter' && $(event.target).is('input')) {
			// not the form: the position
			event.preventDefault();
			$(this).find('[data-move="position"]').trigger('click');
		}
	}).on('mousedown touchstart focusin', function(event) {
		var $menu = $('.InputfieldSelfHostedVideoMoveMenu');
		if(!$menu.length) return;
		if($(event.target).closest('.InputfieldSelfHostedVideoMoveMenu, .InputfieldSelfHostedVideoGrip').length) return;
		closeMoveMenu();
	});

	/*** Pages: a field with many videos shows them a page at a time **************************/

	// the choices of "Per page"; 0 = all
	var PER_PAGE_CHOICES = [ 0, 5, 10, 15, 20, 30, 50 ];
	var PER_PAGE_KEY = 'InputfieldSelfHostedVideoPerPage';

	/**
	 * Videos per page: the editor's own choice in this browser, or the module setting; 0 = all
	 */
	function perPage() {
		var chosen = null;
		try {
			chosen = window.localStorage.getItem(PER_PAGE_KEY);
		} catch(e) { /* the module setting */ }
		if(chosen !== null && chosen !== '' && PER_PAGE_CHOICES.indexOf(parseInt(chosen, 10)) > -1) return parseInt(chosen, 10);
		var n = parseInt(settings().perPage, 10);
		return n > 0 ? n : 0;
	}

	function perPageChoices() {
		var choices = PER_PAGE_CHOICES.slice(0);
		var setting = parseInt(settings().perPage, 10);
		if(setting > 0 && choices.indexOf(setting) < 0) choices.push(setting);
		return choices.sort(function(a, b) { return a - b; });
	}

	function pageKey($field) {
		var id = /[?&]id=(\d+)/.exec(window.location.search);
		return 'InputfieldSelfHostedVideoPage:' + window.location.pathname + (id ? ':' + id[1] : '') + ':' + ($field.attr('id') || '');
	}

	function pagerList($field) {
		return $field.children('.InputfieldContent').find('.InputfieldFileList').first();
	}

	function pagerItems($list) {
		return $list.children('li.InputfieldFileItem');
	}

	/**
	 * The page numbers to link: the first and the last, the current one and its neighbours,
	 * and near either end the first or last five, so that no gap stands for a single page
	 */
	function pageNumbers(current, pages) {
		var numbers = [ 1, pages ];
		var n;
		for(n = current - 1; n <= current + 1; n++) if(n >= 1 && n <= pages) numbers.push(n);
		if(current <= 4) for(n = 2; n <= Math.min(5, pages); n++) numbers.push(n);
		if(current >= pages - 3) for(n = Math.max(1, pages - 4); n < pages; n++) numbers.push(n);
		numbers = $.grep(numbers, function(v, i) { return numbers.indexOf(v) === i; });
		numbers.sort(function(a, b) { return a - b; });
		return numbers;
	}

	function format(text, values) {
		$.each(values, function(i, value) {
			text = text.replace('%' + (i + 1) + '$d', value);
		});
		return text.replace('%d', values[0]);
	}

	/**
	 * Show one page of the videos of a field, and the links to the others
	 *
	 * @param $field The video field (.InputfieldSelfHostedVideo)
	 * @param page Number of the page, from 1; out of range is brought in
	 * @param moved Was the page changed by a link? Then the list is brought into view.
	 */
	function showPage($field, page, moved) {
		var $list = pagerList($field);
		var $items = pagerItems($list);
		var n = perPage();
		var total = $items.length;
		var $pager = $list.next('.InputfieldSelfHostedVideoPager');

		// a short list needs neither pages nor the choice of how many
		var smallest = perPageChoices()[1] || 5;
		if(!$list.length || total <= smallest || !$field.find('.InputfieldSelfHostedVideoToggle').length) {
			$items.removeClass('InputfieldSelfHostedVideoOffPage');
			$pager.remove();
			$field.removeData('vfPage');
			$field.data('vfPageCount', total);
			return;
		}
		if(!n || n > total) n = total;

		var pages = Math.ceil(total / n);
		page = Math.max(1, Math.min(pages, parseInt(page, 10) || 1));
		$items.each(function(i) {
			$(this).toggleClass('InputfieldSelfHostedVideoOffPage', Math.floor(i / n) + 1 !== page);
		});
		$field.data('vfPage', page);
		$field.data('vfPageCount', total);

		if(!$pager.length) {
			$pager = $(
				'<nav class="InputfieldSelfHostedVideoPager">' +
					'<p class="InputfieldSelfHostedVideoPagerRange" aria-live="polite"></p>' +
					'<div class="InputfieldSelfHostedVideoPagerLinks"></div>' +
					'<label class="InputfieldSelfHostedVideoPerPage"><span></span> <select class="uk-select uk-form-small"></select></label>' +
				'</nav>'
			);
			$pager.attr('aria-label', label('pagerLabel'));
			$pager.find('.InputfieldSelfHostedVideoPerPage span').text(label('perPage'));
			var options = '';
			$.each(perPageChoices(), function(i, value) {
				options += '<option value="' + value + '">' + (value ? value : escapeHtml(label('perPageAll'))) + '</option>';
			});
			$pager.find('.InputfieldSelfHostedVideoPerPage select').html(options);
			$list.after($pager);
		}

		var chosen = perPage();
		var $select = $pager.find('.InputfieldSelfHostedVideoPerPage select');
		if(String($select.val()) !== String(chosen)) $select.val(String(chosen));

		var first = (page - 1) * n + 1;
		var last = Math.min(total, page * n);
		var range = first === last ? format(label('pagerOne'), [ first, total ]) : format(label('pagerRange'), [ first, last, total ]);
		var $range = $pager.find('.InputfieldSelfHostedVideoPagerRange');
		if($range.text() !== range) $range.text(range);

		var html = '';
		var step = function(to, text, which) {
			if(to < 1 || to > pages) {
				return '<span class="InputfieldSelfHostedVideoPagerStep InputfieldSelfHostedVideoPagerOff" aria-hidden="true">' + text + '</span>';
			}
			return '<button type="button" class="InputfieldSelfHostedVideoPagerStep InputfieldSelfHostedVideoPager' + which + '" data-page="' + to + '">' + text + '</button>';
		};
		html += step(page - 1, '<i class="fa fa-angle-left" aria-hidden="true"></i> <span>' + escapeHtml(label('pagerPrev')) + '</span>', 'Prev');
		html += '<ul class="InputfieldSelfHostedVideoPagerNumbers">';
		var previous = 0;
		$.each(pageNumbers(page, pages), function(i, number) {
			if(previous && number > previous + 1) html += '<li class="InputfieldSelfHostedVideoPagerGap" aria-hidden="true">…</li>';
			if(number === page) {
				html += '<li><span class="InputfieldSelfHostedVideoPagerNumber InputfieldSelfHostedVideoPagerCurrent" aria-current="page">' +
					'<span class="InputfieldSelfHostedVideoSr">' + escapeHtml(format(label('pagerPageOf'), [ page, pages ])) + '</span>' +
					'<span aria-hidden="true">' + number + '</span></span></li>';
			} else {
				html += '<li><button type="button" class="InputfieldSelfHostedVideoPagerNumber" data-page="' + number + '" aria-label="' +
					escapeHtml(format(label('pagerPage'), [ number ])) + '">' + number + '</button></li>';
			}
			previous = number;
		});
		html += '</ul>';
		html += step(page + 1, '<span>' + escapeHtml(label('pagerNext')) + '</span> <i class="fa fa-angle-right" aria-hidden="true"></i>', 'Next');
		// all on one page: nothing to go to
		if(pages < 2) html = '';
		$pager.find('.InputfieldSelfHostedVideoPagerLinks').html(html);

		if(moved) {
			var top = $list[0].getBoundingClientRect().top;
			if(top < 0 || top > (window.innerHeight || 800) * 0.5) window.scrollTo(0, Math.max(0, top + window.pageYOffset - 90));
			// the keyboard goes on from the first video of the page
			var $toggle = $items.not('.InputfieldSelfHostedVideoOffPage').first().find('.InputfieldSelfHostedVideoToggle').first();
			if($toggle.length) {
				try { $toggle[0].focus({ preventScroll: true }); } catch(e) { $toggle.trigger('focus'); }
			}
		}
	}

	/**
	 * Show the page the item of a video is on
	 */
	function revealItem($item) {
		if(!$item.hasClass('InputfieldSelfHostedVideoOffPage')) return;
		var $field = $item.closest('.InputfieldSelfHostedVideo');
		var index = pagerItems($item.parent()).index($item);
		var n = perPage();
		if(index >= 0 && n) showPage($field, Math.floor(index / n) + 1, false);
	}

	/**
	 * Give a field its pages, on the page it was on before saving, or where something asks for attention
	 */
	function setupPages($field) {
		var $list = pagerList($field);
		if(!$list.length || $field.data('vfPager')) return;
		$field.data('vfPager', true);
		var page = 1;
		try {
			var stored = JSON.parse(window.sessionStorage.getItem(pageKey($field)) || 'null');
			window.sessionStorage.removeItem(pageKey($field));
			if(stored && Date.now() - stored.time < 120000) page = stored.page;
		} catch(e) { /* the first page */ }
		showPage($field, page, false);

		// videos added (upload, reuse) or taken away: the pages follow, and a new video is shown
		if(window.MutationObserver) {
			new MutationObserver(function(records) {
				var added = null;
				$.each(records, function(i, record) {
					$.each(record.addedNodes, function(j, node) {
						if(node.nodeType === 1 && $(node).is('li.InputfieldFileItem')) added = node;
					});
				});
				var before = $field.data('vfPageCount') || 0;
				var count = pagerItems($list).length;
				showPage($field, $field.data('vfPage') || 1, false);
				if(added && count > before) revealItem($(added));
			}).observe($list[0], { childList: true });
		}
		$list.on('sortupdate', function() { showPage($field, $field.data('vfPage') || 1, false); });
	}

	function setupAllPages($root) {
		$root.find('.InputfieldSelfHostedVideo').addBack('.InputfieldSelfHostedVideo').each(function() { setupPages($(this)); });
	}

	/*** Captions and checklist ***************************************************************/

	/**
	 * Show the checklist and the header facts the server sent along with an answer
	 */
	function applyChecks($box, response) {
		if(typeof response.checklist === 'string') {
			var $old = $box.find('.InputfieldSelfHostedVideoChecklist');
			if($old.length) {
				$old.replaceWith(response.checklist);
			} else {
				$box.find('.InputfieldSelfHostedVideoMedia').append(response.checklist);
			}
		}
		var $item = $box.closest('.InputfieldFileItem');
		if(response.stats) $item.find('.InputfieldFileStats').first().html(response.stats);
		if(typeof response.badge === 'string') $item.find('.InputfieldSelfHostedVideoBadgeSlot').first().html(response.badge);
	}

	/**
	 * Show the caption tracks the server sent, in the list and in the preview player
	 */
	function applyCaptions($box, response) {
		if(typeof response.captions === 'string') $box.find('.InputfieldSelfHostedVideoCaptionList').replaceWith(response.captions);
		var player = $box.find('video.InputfieldSelfHostedVideoPlayer')[0];
		if(player && $.isArray(response.tracks)) {
			$(player).children('track').remove();
			$.each(response.tracks, function(n, track) {
				$('<track>').attr({ kind: track.kind, src: track.url, srclang: track.lang, label: track.label }).appendTo(player);
			});
		}
		applyChecks($box, response);
	}

	function captionStatus($box, text, isError) {
		$box.find('.InputfieldSelfHostedVideoCaptionStatus').text(text || '').toggleClass('ui-state-error-text', !!isError);
	}

	function captionBusy($box, state) {
		$box.find('.InputfieldSelfHostedVideoCaptions button, .InputfieldSelfHostedVideoCaptions select').prop('disabled', state);
	}

	/**
	 * Name and version of this browser, for the note that it could not play a video
	 */
	function browserName() {
		var agent = navigator.userAgent || '';
		var found =
			/(Edg)\/(\d+)/.exec(agent) || /(OPR)\/(\d+)/.exec(agent) || /(Firefox)\/(\d+)/.exec(agent) ||
			/(Chrome)\/(\d+)/.exec(agent) || /Version\/(\d+).*(Safari)/.exec(agent);
		if(!found) return '';
		if(found[2] === 'Safari') return 'Safari ' + found[1];
		var names = { Edg: 'Edge', OPR: 'Opera' };
		return (names[found[1]] || found[1]) + ' ' + found[2];
	}

	/**
	 * Which language the editor chose for a captions file: { lang, label }, or null with a message shown
	 */
	function chosenLanguage($box) {
		var $select = $box.find('.InputfieldSelfHostedVideoCaptionLang');
		var value = String($select.val() || '');

		if(!value) {
			captionStatus($box, label('captionChoose'), true);
			$select.trigger('focus');
			return null;
		}
		if(value !== 'other') return { lang: value, label: '' };

		var $code = $box.find('.InputfieldSelfHostedVideoCaptionCode');
		var code = String($code.val() || '').trim().toLowerCase().replace(/_/g, '-');
		var name = String($box.find('.InputfieldSelfHostedVideoCaptionName').val() || '').trim();

		if(!/^[a-z]{2,3}(-[a-z0-9]{2,8}){0,3}$/.test(code) || !name) {
			captionStatus($box, label('captionCode'), true);
			$code.trigger('focus');
			return null;
		}
		return { lang: code, label: name };
	}

	function rememberLanguage(value) {
		try {
			window.localStorage.setItem('InputfieldSelfHostedVideoLang', value);
		} catch(e) { /* nothing to remember it in */ }
	}

	/**
	 * Offer the language the editor chose last
	 */
	function presetLanguages($root) {
		var value = '';
		try {
			value = window.localStorage.getItem('InputfieldSelfHostedVideoLang') || '';
		} catch(e) {
			value = '';
		}
		if(!value || value === 'other') return;
		$root.find('.InputfieldSelfHostedVideoCaptionLang').addBack('.InputfieldSelfHostedVideoCaptionLang').each(function() {
			var $select = $(this);
			if(!$select.val() && $select.find('option[value="' + value + '"]').length) $select.val(value);
		});
	}

	/*** Upload in parts **********************************************************************/

	var uploadQueue = [];      // uploads waiting for their turn
	var activeUpload = null;   // the upload being sent
	var passing = false;       // true while a stand-in file is handed to the core upload script
	var transfers = 0;         // files on their way to the server (uploads and replacements)
	var partsSupport = null;

	/**
	 * Can this browser hand a file to the core upload script (needed to finish an upload in parts)?
	 */
	function canSendInParts() {
		if(partsSupport === null) {
			try {
				var data = new DataTransfer();
				data.items.add(new File(['x'], 'x.txt'));
				partsSupport = data.files.length === 1 && typeof File.prototype.slice === 'function';
			} catch(e) {
				partsSupport = false;
			}
		}
		return partsSupport;
	}

	function chunkSettings() {
		var c = settings().chunk || {};
		var max = parseInt(c.max, 10) || 8388608;
		var min = Math.min(max, parseInt(c.min, 10) || 262144);
		return { max: max, min: min, start: Math.max(min, Math.min(max, parseInt(c.start, 10) || 2097152)) };
	}

	/**
	 * Measures how fast a file goes to the server, over the last ten seconds
	 */
	function rateMeter() {
		var samples = [];
		return {
			reset: function() {
				samples = [];
			},
			add: function(sent) {
				var now = Date.now();
				samples.push({ t: now, s: sent });
				while(samples.length > 2 && now - samples[0].t > 10000) samples.shift();
			},
			/**
			 * Bytes per second, 0 until there is enough to tell
			 */
			rate: function() {
				if(samples.length < 2) return 0;
				var a = samples[0];
				var b = samples[samples.length - 1];
				var seconds = (b.t - a.t) / 1000;
				return seconds < 2 || b.s <= a.s ? 0 : (b.s - a.s) / seconds;
			},
			/**
			 * Speed and time left as text, blank until there is enough to tell
			 */
			text: function(sent, total) {
				if(samples.length < 2) return '';
				var a = samples[0];
				var b = samples[samples.length - 1];
				var seconds = (b.t - a.t) / 1000;
				if(seconds < 2 || b.s <= a.s) return '';
				var rate = (b.s - a.s) / seconds;
				return label('speed').replace('%s', formatBytes(rate)) + ' · ' +
					label('remaining').replace('%s', timeLeft(Math.max(0, total - sent) / rate));
			}
		};
	}

	/**
	 * A time that is left, rounded the way people say it: "45 s", "3 min", "1 h 20 min"
	 */
	function timeLeft(seconds) {
		if(seconds < 60) return label('timeSeconds').replace('%d', Math.max(5, Math.ceil(seconds / 5) * 5));
		if(seconds < 3600) return label('timeMinutes').replace('%d', Math.ceil(seconds / 60));
		var minutes = Math.ceil(seconds / 60);
		return label('timeHours').replace('%1$d', Math.floor(minutes / 60)).replace('%2$d', minutes % 60);
	}

	/**
	 * Read what the browser can tell about a video file before it is uploaded
	 *
	 * Resolves with { playable, duration, width, height }, with { playable: false } when the
	 * browser cannot show the picture, or with null when it cannot tell (no answer in time).
	 */
	function probeFile(file) {
		return new Promise(function(resolve) {
			if(!window.URL || !window.URL.createObjectURL) {
				resolve(null);
				return;
			}
			var url = window.URL.createObjectURL(file);
			var video = document.createElement('video');
			var meta = null;
			var finished = false;
			var timer = setTimeout(function() { finish(meta && !meta.width ? { playable: false } : null); }, 10000);

			function finish(result) {
				if(finished) return;
				finished = true;
				clearTimeout(timer);
				video.removeAttribute('src');
				try { video.load(); } catch(e) { /* nothing to release */ }
				window.URL.revokeObjectURL(url);
				resolve(result);
			}

			video.muted = true;
			video.preload = 'auto';
			video.addEventListener('loadedmetadata', function() {
				meta = {
					playable: true,
					duration: isFinite(video.duration) ? video.duration : 0,
					width: video.videoWidth || 0,
					height: video.videoHeight || 0
				};
				// sound alone, or a picture this browser cannot decode
				if(!meta.width) finish({ playable: false });
			});
			// the first picture is there: the browser can show the video
			video.addEventListener('loadeddata', function() {
				finish(meta && meta.width ? meta : { playable: false });
			});
			video.addEventListener('error', function() { finish({ playable: false }); });
			video.src = url;
		});
	}

	/**
	 * Is the file H.264 video? Looks for the name of the codec where MP4 and MOV files describe their tracks.
	 *
	 * A few browsers (Chromium as some Linux systems bring it) play no H.264 at all, though nearly
	 * every visitor's browser does. Such a file is not one to warn about.
	 */
	function isH264(file) {
		var span = 4194304;
		var parts = [file.slice(0, Math.min(file.size, span))];
		if(file.size > span) parts.push(file.slice(Math.max(span, file.size - span)));
		return Promise.all(parts.map(function(part) {
			return new Promise(function(resolve) {
				var reader = new FileReader();
				reader.onload = function() {
					var bytes = new Uint8Array(reader.result);
					// "avcC": the settings of an H.264 track, right after its sample entry "avc1"/"avc3"
					for(var i = 0; i < bytes.length - 3; i++) {
						if(bytes[i] === 0x61 && bytes[i + 1] === 0x76 && bytes[i + 2] === 0x63 && bytes[i + 3] === 0x43) {
							resolve(true);
							return;
						}
					}
					resolve(false);
				};
				reader.onerror = function() { resolve(false); };
				reader.readAsArrayBuffer(part);
			});
		})).then(function(found) {
			return found.indexOf(true) > -1;
		});
	}

	/**
	 * Check a file before upload: what the browser can tell, and whether it is worth a warning
	 */
	function checkFile(file) {
		return probeFile(file).then(function(result) {
			if(!result || result.playable) return result;
			return isH264(file).then(function(h264) {
				return h264 ? null : result;
			});
		});
	}

	var probeChain = Promise.resolve();

	/*** Continuing an upload after the page was closed or reloaded ****************************/

	// Uploads on their way are remembered in this browser, with where they are going. What has
	// arrived stays on the server for a day after its last part: choosing the same file again
	// within that time continues the upload where it stopped.
	var RESUME_KEY = 'InputfieldSelfHostedVideoUploads';
	var RESUME_AGE = 86400000;

	function resumeRecords() {
		var list = [];
		try {
			list = JSON.parse(window.localStorage.getItem(RESUME_KEY) || '[]');
		} catch(e) {
			list = [];
		}
		if(!$.isArray(list)) list = [];
		var now = Date.now();
		return $.grep(list, function(r) { return r && r.id && r.place && now - r.time < RESUME_AGE; });
	}

	function resumeWrite(list) {
		try {
			if(list.length) {
				window.localStorage.setItem(RESUME_KEY, JSON.stringify(list));
			} else {
				window.localStorage.removeItem(RESUME_KEY);
			}
		} catch(e) { /* then an interrupted upload starts over */ }
	}

	function resumeSave(record) {
		var list = $.grep(resumeRecords(), function(r) { return r.id !== record.id; });
		list.push(record);
		resumeWrite(list);
	}

	function resumeDrop(id) {
		resumeWrite($.grep(resumeRecords(), function(r) { return r.id !== id; }));
		$('.InputfieldSelfHostedVideoResumeItem[data-id="' + id + '"]').remove();
		$('.InputfieldSelfHostedVideoResume').each(function() {
			if(!$(this).find('.InputfieldSelfHostedVideoResumeItem').length) $(this).remove();
		});
	}

	/**
	 * Where uploads of a field go: the page (or repeater item) and the field
	 */
	function resumePlace($content) {
		var c = context($content);
		return c.url + '|' + c.fieldName;
	}

	function resumeInUse(id) {
		if(activeUpload && activeUpload.resumeId === id) return true;
		return $.grep(uploadQueue, function(u) { return u.resumeId === id; }).length > 0;
	}

	/**
	 * The interrupted upload of this file to this field, if there is one
	 */
	function resumeFind($content, file) {
		var place = resumePlace($content);
		var found = $.grep(resumeRecords(), function(r) {
			return r.place === place && r.name === file.name && r.size === file.size && !resumeInUse(r.id);
		});
		return found.length ? found[0] : null;
	}

	/**
	 * Show the interrupted uploads of the video fields in $root, each with what to do about it
	 */
	function showResumable($root) {
		var records = resumeRecords();
		if(!records.length) return;
		$root.find('.InputfieldSelfHostedVideo > .InputfieldContent.InputfieldFileInit').addBack('.InputfieldSelfHostedVideo > .InputfieldContent.InputfieldFileInit').each(function() {
			var $content = partsContent(this);
			if(!$content) return;
			var place = resumePlace($content);
			var $upload = $content.find('.InputfieldFileUpload').first();
			$.each(records, function(n, r) {
				if(r.place !== place || resumeInUse(r.id) || $content.find('.InputfieldSelfHostedVideoResumeItem[data-id="' + r.id + '"]').length) return;
				var $box = $content.find('.InputfieldSelfHostedVideoResume').first();
				if(!$box.length) {
					$box = $('<div class="InputfieldSelfHostedVideoResume"></div>');
					$upload.before($box);
				}
				var percent = r.size ? Math.floor((r.sent || 0) / r.size * 100) : 0;
				var $item = $('<div class="InputfieldSelfHostedVideoResumeItem"></div>').attr('data-id', r.id);
				$('<p class="InputfieldSelfHostedVideoResumeText"></p>')
					.append('<i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i> ')
					.append($('<span></span>').text(label('resumeNotice').replace('%1$s', r.name).replace('%2$s', formatBytes(r.size)).replace('%3$s', percent + '%')))
					.appendTo($item);
				$('<p class="InputfieldSelfHostedVideoResumeButtons"></p>')
					.append($('<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoResumeChoose"></button>')
						.append('<i class="fa fa-fw fa-upload" aria-hidden="true"></i>')
						.append($('<span></span>').text(label('resumeChoose')))
						.attr('aria-label', label('resumeChooseName').replace('%s', r.name)))
					.append(' ')
					.append($('<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoResumeDiscard"></button>')
						.text(label('resumeDiscard'))
						.attr('aria-label', label('resumeDiscardName').replace('%s', r.name)))
					.appendTo($item);
				$('<p class="InputfieldSelfHostedVideoResumeStatus" role="status" aria-live="polite"></p>').appendTo($item);
				$box.append($item);
			});
		});
	}

	function newUploadId() {
		var id = '';
		var values = new Uint8Array(12);
		if(window.crypto && window.crypto.getRandomValues) {
			window.crypto.getRandomValues(values);
		} else {
			for(var n = 0; n < values.length; n++) values[n] = Math.floor(Math.random() * 256);
		}
		for(var i = 0; i < values.length; i++) id += (values[i] < 16 ? '0' : '') + values[i].toString(16);
		return id;
	}

	function formatBytes(bytes) {
		var units = settings().labels && settings().labels.units ? settings().labels.units : ['KB', 'MB', 'GB'];
		var lang = document.documentElement.lang || undefined;
		var number = function(value, digits) {
			try {
				return value.toLocaleString(lang, { minimumFractionDigits: digits, maximumFractionDigits: digits });
			} catch(e) {
				return value.toFixed(digits);
			}
		};
		if(bytes < 1048576) return number(bytes > 0 ? Math.max(1, Math.round(bytes / 1024)) : 0, 0) + ' ' + units[0];
		if(bytes < 1073741824) return number(bytes / 1048576, bytes < 104857600 ? 1 : 0) + ' ' + units[1];
		return number(bytes / 1073741824, 2) + ' ' + units[2];
	}

	/**
	 * Tell screen reader users what happens, without moving their focus
	 */
	function announce(text) {
		var $live = $('#InputfieldSelfHostedVideoLive');
		if(!$live.length) {
			$live = $('<div id="InputfieldSelfHostedVideoLive" class="InputfieldSelfHostedVideoLive" role="status" aria-live="polite"></div>').appendTo(document.body);
		}
		$live.text(text);
	}

	function warnBeforeLeaving(event) {
		event.preventDefault();
		event.returnValue = label('leave');
		return label('leave');
	}

	var finishing = 0;      // files that have arrived and are being added to the page
	var leaveWarning = false;

	/**
	 * Is a video on its way? Being checked, waiting for its turn or for the editor's decision, being
	 * sent (also paused or waiting for the connection), or being added to the page at the end.
	 */
	function uploadsUnfinished() {
		if(transfers > 0 || finishing > 0 || activeUpload) return true;
		return $.grep(uploadQueue, function(u) { return u.check !== 'removed' && document.body.contains(u.$item[0]); }).length > 0;
	}

	/**
	 * Ask before the page is closed or left while a video is on its way, and only then
	 * (a page that always asks is kept out of the browser's quick back and forward)
	 */
	function syncLeaveWarning() {
		var want = uploadsUnfinished();
		if(want === leaveWarning) return;
		leaveWarning = want;
		// the page editor asks before saving while the page has this class
		$('body').toggleClass('pw-uploading', want);
		if(want) {
			window.addEventListener('beforeunload', warnBeforeLeaving);
		} else {
			window.removeEventListener('beforeunload', warnBeforeLeaving);
		}
	}

	function transferStarted() {
		transfers++;
		syncLeaveWarning();
	}

	function transferEnded() {
		transfers = Math.max(0, transfers - 1);
		syncLeaveWarning();
	}

	/**
	 * Send one request of an upload in parts
	 *
	 * Calls back with the HTTP status and the parsed answer (null if there is none),
	 * unless the request was aborted.
	 */
	function sendPart($content, params, body, onProgress, callback) {
		var c = context($content);
		var url = c.url;

		$.each(params, function(key, value) {
			url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(value);
		});

		var xhr = new XMLHttpRequest();
		var aborted = false;

		xhr.open('POST', url, true);
		xhr.setRequestHeader('X-FIELDNAME', c.fieldName);
		xhr.setRequestHeader('Content-Type', 'application/octet-stream');
		if(c.tokenName) xhr.setRequestHeader('X-' + c.tokenName, c.tokenValue);
		xhr.setRequestHeader('X-REQUESTED-WITH', 'XMLHttpRequest');

		if(onProgress && xhr.upload) {
			xhr.upload.addEventListener('progress', function(e) {
				if(e.lengthComputable && !aborted) onProgress(e.loaded);
			});
		}

		xhr.addEventListener('abort', function() { aborted = true; });

		xhr.addEventListener('load', function() {
			var response = null;
			try {
				response = JSON.parse(xhr.responseText);
			} catch(e) {
				response = null;
			}
			if(!response || typeof response !== 'object') response = null;
			if(callback) callback(xhr.status, response);
		});

		xhr.addEventListener('error', function() {
			if(callback) callback(0, null);
		});

		xhr.send(body);

		return xhr;
	}

	/**
	 * Send a file to the server in parts
	 *
	 * options: $content (content element of the field), file, params (added to every request),
	 * onProgress(bytesSent, bytesTotal), onState(state, message) with the states "uploading",
	 * "retrying" (waiting to try again by itself) and "stalled" (waiting for the editor).
	 *
	 * Returns an object with: promise (resolves with { id, size } once the whole file is on the
	 * server, rejects with an Error that has "cancelled" set when cancelled), cancel(), retry().
	 */
	function transfer(options) {

		var file = options.file;
		var id = options.id || newUploadId();
		var limits = chunkSettings();
		var size = limits.start;
		var max = limits.max;
		var offset = 0;
		var attempts = 0;
		var resyncs = 0;
		var xhr = null;
		var timer = null;
		var cancelled = false;
		var stalled = false;
		var settled = false;
		var offline = false;
		var paused = false;
		// an interrupted upload asks the server first how much it has, until it has an answer
		var needsCheck = !!options.resume;
		var resolve, reject;

		var promise = new Promise(function(a, b) {
			resolve = a;
			reject = b;
		});

		var state = function(name, message) {
			if(options.onState) options.onState(name, message);
		};

		var progress = function(sent) {
			if(options.onProgress) options.onProgress(Math.max(0, Math.min(file.size, sent)), file.size);
		};

		/**
		 * The connection is gone: wait for it without using up the tries, then go on by itself
		 */
		function goOffline() {
			if(offline || cancelled || settled || paused) return;
			offline = true;
			if(timer) {
				clearTimeout(timer);
				timer = null;
			}
			if(xhr) {
				xhr.abort();
				xhr = null;
			}
			state('offline');
		}

		function onOffline() {
			goOffline();
		}

		function onOnline() {
			if(cancelled || settled || paused) return;
			if(!offline && !stalled) return;
			offline = false;
			stalled = false;
			attempts = 0;
			state('online');
			state('uploading');
			// the server answers with what it has, and the part goes again from there
			timer = setTimeout(function() {
				timer = null;
				go();
			}, 500);
		}

		window.addEventListener('offline', onOffline);
		window.addEventListener('online', onOnline);

		function settle(error) {
			if(settled) return;
			settled = true;
			window.removeEventListener('offline', onOffline);
			window.removeEventListener('online', onOnline);
			transferEnded();
			if(error) {
				reject(error);
			} else {
				resolve({ id: id, size: file.size });
			}
		}

		function again(message) {
			if(navigator.onLine === false) {
				goOffline();
				return;
			}
			var delays = settings().retryDelays || [1000, 3000, 6000];
			if(attempts < delays.length) {
				state('retrying');
				timer = setTimeout(function() {
					timer = null;
					state('uploading');
					go();
				}, delays[attempts++]);
			} else {
				stalled = true;
				state('stalled', message || '');
			}
		}

		/**
		 * Send the next part, or first ask how much of an interrupted upload the server has
		 */
		function go() {
			if(needsCheck) {
				checkResume();
			} else {
				step();
			}
		}

		function checkResume() {
			if(cancelled || offline || paused) return;
			// the start of the file and the 64 kB before the point known to have arrived: the server
			// goes on only when both are what it has
			var at = Math.max(0, Math.min(file.size - 1, parseInt(options.resumeAt, 10) || 0));
			var head = file.slice(0, Math.min(file.size, 65536));
			var check = new Blob([ head, file.slice(Math.max(0, at - 65536), at) ]);
			xhr = sendPart(options.$content, { shv: 'resume', vf_id: id, vf_size: file.size, vf_at: at, vf_head: head.size }, check, null, function(status, response) {
				xhr = null;
				if(cancelled || offline || paused) return;
				if(!response) {
					// no answer: never start over on a guess, that would drop what has arrived
					again();
					return;
				}
				needsCheck = false;
				var received = !response.error ? parseInt(response.received, 10) || 0 : 0;
				if(received > 0 && received < file.size) {
					offset = received;
					progress(offset);
				}
				state('resumed', String(offset));
				step();
			});
		}

		function step() {
			if(cancelled || offline || paused) return;
			if(offset >= file.size) {
				settle();
				return;
			}

			var from = offset;
			var started = Date.now();
			var part = file.slice(from, Math.min(file.size, from + size));
			var params = $.extend({
				shv: 'chunk',
				vf_id: id,
				vf_offset: from,
				vf_length: part.size,
				vf_size: file.size,
				vf_filename: file.name
			}, options.params || {});

			xhr = sendPart(options.$content, params, part, function(loaded) {
				progress(from + loaded);
			}, function(status, response) {
				xhr = null;
				if(cancelled || offline || paused) return;

				if(response && !response.error) {
					// the server says how much it has, and that is what counts
					var received = parseInt(response.received, 10);
					if(isNaN(received) || received <= from) {
						again();
						return;
					}
					attempts = 0;
					resyncs = 0;
					offset = received;
					progress(offset);
					if(options.onStored) options.onStored(offset);
					var elapsed = Date.now() - started;
					if(elapsed < 2000) {
						size = Math.min(max, size * 2);
					} else if(elapsed > 20000) {
						size = Math.max(limits.min, Math.floor(size / 2));
					}
					step();
					return;
				}

				if(status === 413 || (response && response.code === 'empty')) {
					// this server accepts less in one request: send smaller parts
					if(size > limits.min) {
						size = Math.max(limits.min, Math.floor(size / 2));
						max = size;
						step();
					} else {
						again(response ? response.message : '');
					}
					return;
				}

				if(response && response.code === 'offset' && resyncs < 5) {
					// the server has less than we thought: continue from what it has
					resyncs++;
					offset = Math.max(0, Math.min(file.size, parseInt(response.received, 10) || 0));
					progress(offset);
					step();
					return;
				}

				if(response && response.error && !response.code) {
					// a refusal: trying again would not help
					settle(new Error(response.message || label('requestError')));
					return;
				}

				again(response ? response.message : '');
			});
		}

		transferStarted();

		if(!file.size) {
			settle(new Error(label('emptyFile')));
		} else {
			state('uploading');
			go();
		}

		return {
			id: id,
			promise: promise,
			retry: function() {
				if(!stalled || cancelled || settled || paused) return;
				stalled = false;
				attempts = 0;
				state('uploading');
				go();
			},
			/**
			 * Stop sending right away; what has arrived stays, the part that was on its way goes again
			 */
			pause: function() {
				if(paused || cancelled || settled) return;
				paused = true;
				if(timer) {
					clearTimeout(timer);
					timer = null;
				}
				if(xhr) {
					xhr.abort();
					xhr = null;
				}
				offline = false;
				stalled = false;
				state('paused');
			},
			resume: function() {
				if(!paused || cancelled || settled) return;
				paused = false;
				attempts = 0;
				if(navigator.onLine === false) {
					goOffline();
					return;
				}
				state('uploading');
				go();
			},
			isPaused: function() {
				return paused;
			},
			cancel: function() {
				if(cancelled || settled) return;
				cancelled = true;
				if(timer) clearTimeout(timer);
				if(xhr) xhr.abort();
				// let the server drop what it has
				sendPart(options.$content, { shv: 'cancel', vf_id: id }, null, null, null);
				var error = new Error('cancelled');
				error.cancelled = true;
				settle(error);
			}
		};
	}

	/**
	 * The content element of the video field that the given element belongs to,
	 * if uploads to that field are to be sent in parts
	 */
	function partsContent(element) {
		if(!canSendInParts()) return null;
		var $content = $(element).closest('.InputfieldContent.InputfieldFileInit');
		if(!$content.length || !$content.parent().hasClass('InputfieldSelfHostedVideo')) return null;
		// only where the core uploads in the background too
		if(!$content.find('.InputfieldFileUpload .AjaxUploadDropHere').length) return null;
		return $content;
	}

	function errorItem(name, message) {
		var $item = $('<li class="InputfieldFile ui-widget AjaxUpload"><p class="InputfieldFileInfo ui-widget ui-widget-header InputfieldItemHeader ui-state-error">&nbsp; <span class="InputfieldFileName"></span> <span class="InputfieldFileStats"></span></p></li>');
		$item.find('.InputfieldFileName').text(name);
		$item.find('.InputfieldFileStats').text(' • ' + message);
		return $item;
	}

	/**
	 * Take files chosen or dropped for a video field: check them and line them up for upload
	 */
	function addFiles($content, files) {

		var $upload = $content.find('.InputfieldFileUpload').first();
		var $list = $content.find('.InputfieldFileList').first();
		var $inputfield = $content.closest('.Inputfield');
		var extensions = String($upload.data('extensions') || '').toLowerCase().split(/\s+/);
		var maxFiles = parseInt($content.find('.InputfieldFileMaxFiles').val(), 10) || 0;
		var maxBytes = parseInt($inputfield.attr('data-vf-max-bytes'), 10) || 0;
		var room = Infinity;

		if(!$list.length) return;

		if(maxFiles > 1) {
			room = maxFiles - $list.children('.InputfieldFileItem').length - $list.children('.InputfieldSelfHostedVideoProgress').length;
		}

		for(var i = 0; i < files.length; i++) {
			var file = files[i];
			var ext = file.name.indexOf('.') > -1 ? file.name.split('.').pop().toLowerCase() : '';
			var problem = '';

			if($.inArray(ext, extensions) < 0) {
				problem = label('badExtension') + ' ' + extensions.join(', ');
			} else if(!file.size) {
				problem = label('emptyFile');
			} else if(maxBytes && file.size > maxBytes) {
				problem = label('tooBigMax').replace('%s', formatBytes(maxBytes));
			} else if(room < 1) {
				problem = label('tooMany');
			}

			if(problem) {
				$list.append(errorItem(file.name, problem));
				announce(file.name + ': ' + problem);
			} else {
				room--;
				queueUpload($content, $list, file, resumeFind($content, file));
			}

			if(maxFiles === 1) break;
		}

		$inputfield.addClass('InputfieldStateChanged');
		nextUpload();
	}

	/**
	 * Show a file in the list as waiting for upload and put it in the queue
	 */
	function queueUpload($content, $list, file, resume) {

		var $item = $(
			'<li class="InputfieldFile ui-widget AjaxUpload InputfieldSelfHostedVideoProgress">' +
				'<p class="InputfieldFileInfo ui-widget ui-widget-header InputfieldItemHeader">' +
					'<i class="fa fa-fw fa-spin fa-spinner" aria-hidden="true"></i> ' +
					'<span class="InputfieldFileName"></span>' +
					'<span class="InputfieldFileStats"></span>' +
				'</p>' +
				'<div class="ui-progressbar ui-widget ui-widget-content ui-corner-all" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
					'<div class="ui-progressbar-value ui-widget-header InputfieldItemHeader ui-corner-left" style="width: 0%"></div>' +
				'</div>' +
				'<div class="InputfieldSelfHostedVideoProgressRow">' +
					'<span class="InputfieldSelfHostedVideoProgressText detail"></span>' +
					'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoRetry" hidden></button>' +
					'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoAnyway" hidden></button>' +
					'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoPause" hidden><i class="fa fa-fw fa-pause" aria-hidden="true"></i><span></span></button>' +
					'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoCancel"></button>' +
				'</div>' +
			'</li>'
		);

		$item.find('.InputfieldFileName').text(file.name);
		$item.find('.InputfieldFileStats').text(' • ' + formatBytes(file.size));
		$item.find('.ui-progressbar').attr('aria-label', label('uploading').replace('%s', file.name));
		$item.find('.InputfieldSelfHostedVideoProgressText').text(label('checking'));
		$item.find('.InputfieldSelfHostedVideoRetry').text(label('retry'));
		$item.find('.InputfieldSelfHostedVideoAnyway').text(label('uploadAnyway')).attr('aria-label', label('uploadAnywayName').replace('%s', file.name));
		$item.find('.InputfieldSelfHostedVideoCancel').text(label('cancel')).attr('aria-label', label('cancelUpload').replace('%s', file.name));

		$list.append($item);
		$content.closest('.Inputfield').removeClass('InputfieldFileEmpty');

		var upload = { $content: $content, $item: $item, file: file, transfer: null, milestone: 0, frame: 0, sent: 0, check: 'pending', meter: rateMeter(), resume: resume || null, resumeId: resume ? resume.id : newUploadId(), phase: 'waiting' };
		upload.batch = batchOf($content);
		upload.batch.uploads.push(upload);
		updateTotal(upload.batch);
		if(resume) $content.find('.InputfieldSelfHostedVideoResumeItem[data-id="' + resume.id + '"]').remove();
		$content.find('.InputfieldSelfHostedVideoResume').each(function() {
			if(!$(this).find('.InputfieldSelfHostedVideoResumeItem').length) $(this).remove();
		});
		$item.data('vfUpload', upload);
		uploadQueue.push(upload);
		syncLeaveWarning();

		// what the browser can tell about the file, before it is sent: one file after the other
		probeChain = probeChain.then(function() {
			if(!document.body.contains($item[0])) return null;
			return checkFile(file);
		}).then(function(result) {
			applyProbe(upload, result);
		}, function() {
			applyProbe(upload, null);
		});
	}

	/**
	 * Mark a text as a warning: in the colour of normal text (the theme greys out "detail" text)
	 */
	function warnText($el, on) {
		$el.toggleClass('InputfieldSelfHostedVideoWarning', !!on).toggleClass('detail', !on);
	}

	/**
	 * The icon at the start of an upload's header: turning while it goes, a sign while it waits
	 */
	function uploadIcon($item, name) {
		$item.children('.InputfieldFileInfo').find('.fa').first()
			.attr('class', name === 'warning' ? 'fa fa-fw fa-exclamation-triangle' : (name === 'offline' ? 'fa fa-fw fa-wifi' : (name === 'paused' ? 'fa fa-fw fa-pause' : 'fa fa-fw fa-spin fa-spinner')));
	}

	/*** Several files at once: one line for all of them, above the button that chooses files *****/

	/**
	 * The files of a field that are on their way together: those chosen since its last upload ended
	 */
	function batchOf($content) {
		var batch = $content.data('vfBatch');
		var open = batch && $.grep(batch.uploads, function(u) { return u.phase === 'waiting' || u.phase === 'sending' || u.phase === 'blocked'; }).length;
		if(!open) {
			batch = { $content: $content, uploads: [] };
			$content.data('vfBatch', batch);
		}
		return batch;
	}

	function setPhase(upload, phase) {
		upload.phase = phase;
		if(upload.batch) updateTotal(upload.batch);
	}

	/**
	 * Show how far the files of a field are together: "Video 2 of 4 · 38% of 1.2 GB in total · about 5 min left"
	 *
	 * Only while two or more files are on their way. A file that waits for the editor's decision does not count.
	 */
	function updateTotal(batch) {
		var $content = batch.$content;
		var counted = $.grep(batch.uploads, function(u) { return u.phase !== 'gone' && u.phase !== 'blocked'; });
		var finished = $.grep(counted, function(u) { return u.phase === 'done'; }).length;
		var $total = $content.find('.InputfieldSelfHostedVideoTotal').first();

		if(counted.length < 2 || finished === counted.length) {
			$total.remove();
			return;
		}

		if(!$total.length) {
			$total = $(
				'<div class="InputfieldSelfHostedVideoTotal">' +
					'<div class="ui-progressbar ui-widget ui-widget-content ui-corner-all" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
						'<div class="ui-progressbar-value ui-widget-header InputfieldItemHeader ui-corner-left" style="width: 0%"></div>' +
					'</div>' +
					'<p class="InputfieldSelfHostedVideoTotalText detail"></p>' +
				'</div>'
			);
			$total.find('[role=progressbar]').attr('aria-label', label('totalLabel'));
			$content.find('.InputfieldFileUpload').first().before($total);
		}

		var size = 0;
		var sent = 0;
		var current = 0;
		var active = null;
		$.each(counted, function(n, u) {
			size += u.file.size;
			if(u.phase === 'done') {
				sent += u.file.size;
			} else if(u.phase === 'sending') {
				sent += u.sent;
				active = u;
				current = n + 1;
			}
		});
		if(!current) current = Math.min(counted.length, finished + 1);

		var percent = size ? Math.floor(sent / size * 100) : 0;
		var text = label('totalLine').replace('%1$d', current).replace('%2$d', counted.length)
			.replace('%3$s', percent + '%').replace('%4$s', formatBytes(size));
		if(active && active.transfer && active.transfer.isPaused()) {
			text += ' · ' + label('totalPaused');
		} else if(active) {
			var rate = active.meter.rate();
			if(rate) text += ' · ' + label('remaining').replace('%s', timeLeft(Math.max(0, size - sent) / rate));
		}

		$total.find('[role=progressbar]').attr('aria-valuenow', percent).children().css('width', percent + '%');
		$total.find('.InputfieldSelfHostedVideoTotalText').text(text);
	}

	/**
	 * Show what the check before uploading found, and let the upload go ahead where nothing speaks against it
	 */
	function applyProbe(upload, result) {
		var $item = upload.$item;
		if(!document.body.contains($item[0]) || upload.check !== 'pending') return;
		var $text = $item.find('.InputfieldSelfHostedVideoProgressText');

		if(result && !result.playable) {
			// the editor decides: such a file may be wanted all the same (for browsers that can play it)
			upload.check = 'blocked';
			setPhase(upload, 'blocked');
			$item.addClass('InputfieldSelfHostedVideoBlocked');
			uploadIcon($item, 'warning');
			warnText($text.text(label('unplayable')), true);
			$item.find('.InputfieldSelfHostedVideoAnyway').prop('hidden', false);
			$item.find('.InputfieldSelfHostedVideoCancel').text(label('dontUpload'));
			announce(upload.file.name + ': ' + label('unplayable'));
		} else {
			upload.check = 'ok';
			if(result && result.width) {
				var facts = [];
				if(result.duration) facts.push(clockTime(result.duration));
				facts.push(result.width + '×' + result.height);
				$item.find('.InputfieldFileStats').text(' • ' + formatBytes(upload.file.size) + ' • ' + facts.join(' • '));
			}
			if(upload !== activeUpload) $text.text(label('waiting'));
		}
		nextUpload();
	}

	function showUploadProgress(upload) {
		upload.frame = 0;
		var total = upload.file.size;
		var percent = total ? Math.floor(upload.sent / total * 100) : 0;
		var $bar = upload.$item.find('.ui-progressbar');
		$bar.attr('aria-valuenow', percent);
		// the number is in the text below the bar, where it is readable in every theme
		$bar.children().css('width', percent + '%');
		var rate = upload.meter.text(upload.sent, total);
		if(!upload.note) {
			upload.$item.find('.InputfieldSelfHostedVideoProgressText').text(
				percent + '% · ' + label('progress').replace('%1$s', formatBytes(upload.sent)).replace('%2$s', formatBytes(total)) +
				(rate && percent < 100 ? ' · ' + rate : '')
			);
		}
		if(upload.batch) updateTotal(upload.batch);
		var milestone = Math.floor(percent / 25) * 25;
		if(milestone > upload.milestone && milestone < 100) {
			upload.milestone = milestone;
			announce(label('uploading').replace('%s', upload.file.name) + ': ' + milestone + '%' + (rate ? ' · ' + rate : ''));
		}
	}

	/**
	 * Start the next upload in the queue, one at a time
	 */
	function nextUpload() {

		if(activeUpload) return;

		// in their order, as soon as the check before uploading lets them go
		var upload = null;
		for(var i = 0; i < uploadQueue.length; i++) {
			if(uploadQueue[i].check === 'ok') {
				upload = uploadQueue.splice(i, 1)[0];
				break;
			}
			if(uploadQueue[i].check === 'pending') break;
		}

		if(!upload) {
			// a file that waits for the editor's decision is not on its way
			var checking = $.grep(uploadQueue, function(u) { return u.check === 'pending'; }).length;
			if(!checking) $('body').removeClass('pw-uploading');
			syncLeaveWarning();
			return;
		}
		if(!document.body.contains(upload.$item[0])) {
			// its place in the page is gone, i.e. the item of a repeater was removed
			nextUpload();
			return;
		}

		activeUpload = upload;
		$('body').addClass('pw-uploading');
		setPhase(upload, 'sending');
		var counted = $.grep(upload.batch.uploads, function(u) { return u.phase !== 'gone' && u.phase !== 'blocked'; });
		announce(label('uploading').replace('%s', upload.file.name) +
			(counted.length > 1 ? ' (' + label('totalOf').replace('%1$d', $.inArray(upload, counted) + 1).replace('%2$d', counted.length) + ')' : ''));
		upload.$item.find('.InputfieldSelfHostedVideoPause').prop('hidden', false).attr('aria-label', label('pauseName').replace('%s', upload.file.name))
			.find('span').text(label('pause'));

		var record = {
			place: resumePlace(upload.$content),
			id: upload.resumeId,
			name: upload.file.name,
			size: upload.file.size,
			sent: upload.resume ? upload.resume.sent || 0 : 0,
			time: Date.now()
		};
		var stored = 0;
		resumeSave(record);

		upload.transfer = transfer({
			$content: upload.$content,
			file: upload.file,
			id: upload.resumeId,
			resume: !!upload.resume,
			resumeAt: upload.resume ? upload.resume.sent || 0 : 0,
			onStored: function(offset) {
				record.sent = offset;
				record.time = Date.now();
				if(record.time - stored > 1000) {
					stored = record.time;
					resumeSave(record);
				}
			},
			onProgress: function(sent) {
				upload.sent = sent;
				upload.meter.add(sent);
				$('body').addClass('pw-uploading');
				if(!upload.frame) upload.frame = window.requestAnimationFrame(function() { showUploadProgress(upload); });
			},
			onState: function(state, message) {
				var $text = upload.$item.find('.InputfieldSelfHostedVideoProgressText');
				var $retry = upload.$item.find('.InputfieldSelfHostedVideoRetry');
				if(state === 'online') {
					announce(label('online'));
					return;
				}
				if(state === 'resumed') {
					var at = parseInt(message, 10) || 0;
					upload.meter.reset();
					upload.milestone = Math.floor(at / upload.file.size * 4) * 25;
					announce(at ? label('resumed').replace('%s', formatBytes(at)) : label('resumeLost'));
					if(!at) {
						upload.note = true;
						$text.text(label('resumeLost'));
						setTimeout(function() { upload.note = false; }, 3000);
					}
					return;
				}
				var $pause = upload.$item.find('.InputfieldSelfHostedVideoPause');
				var isPaused = state === 'paused';
				$pause.prop('hidden', state === 'stalled')
					.attr('aria-label', label(isPaused ? 'continueName' : 'pauseName').replace('%s', upload.file.name));
				$pause.find('span').text(label(isPaused ? 'continue' : 'pause'));
				$pause.find('.fa').attr('class', 'fa fa-fw ' + (isPaused ? 'fa-play' : 'fa-pause'));
				upload.$item.toggleClass('InputfieldSelfHostedVideoPaused', isPaused);
				upload.note = state !== 'uploading';
				// a pause says nothing about the speed of the connection
				if(state !== 'uploading') upload.meter.reset();
				upload.$item.toggleClass('InputfieldSelfHostedVideoStalled', state === 'stalled');
				upload.$item.toggleClass('InputfieldSelfHostedVideoOffline', state === 'offline');
				uploadIcon(upload.$item, state === 'offline' ? 'offline' : '');
				if(upload.batch) updateTotal(upload.batch);
				$text.toggleClass('ui-state-error-text', state === 'stalled');
				warnText($text, state === 'offline');
				$retry.prop('hidden', state !== 'stalled');
				if(isPaused) {
					var pct = upload.file.size ? Math.floor(upload.sent / upload.file.size * 100) : 0;
					$text.text(label('pausedAt').replace('%1$s', pct + '%').replace('%2$s', formatBytes(upload.sent)).replace('%3$s', formatBytes(upload.file.size)));
					uploadIcon(upload.$item, 'paused');
					announce(label('pausedName').replace('%s', upload.file.name));
				} else if(state === 'offline') {
					$text.text(label('offline'));
					announce(label('offline'));
				} else if(state === 'retrying') {
					$text.text(label('retrying'));
				} else if(state === 'stalled') {
					$text.text(label('stalled') + (message ? ' (' + message + ')' : ''));
					announce(label('stalled'));
					$retry.trigger('focus');
				} else {
					showUploadProgress(upload);
				}
			}
		});

		upload.transfer.promise.then(function(result) {
			resumeDrop(result.id);
			setPhase(upload, 'done');
			finishUpload(upload, result);
		}, function(error) {
			// cancelled, or refused by the server: nothing to continue later
			resumeDrop(upload.resumeId);
			setPhase(upload, 'gone');
			if(error && error.cancelled) {
				upload.$item.remove();
				announce(label('uploadCancelled').replace('%s', upload.file.name));
			} else {
				var message = error && error.message ? error.message : label('requestError');
				upload.$item.replaceWith(errorItem(upload.file.name, message));
				announce(label('uploadError') + ' ' + message);
			}
		}).then(function() {
			activeUpload = null;
			nextUpload();
			syncLeaveWarning();
		});
	}

	/**
	 * The whole file is on the server: let the core upload script add it to the page
	 *
	 * The core script gets a tiny stand-in file that carries the name of the video and tells the
	 * server which upload it stands for. From there on everything happens as with any upload.
	 */
	function finishUpload(upload, result) {

		var $list = upload.$content.find('.InputfieldFileList').first();
		var input = upload.$content.find('.InputfieldFileUpload input[type=file]')[0];
		var name = upload.file.name;

		if(!input || !document.body.contains(upload.$item[0])) {
			upload.$item.remove();
			sendPart(upload.$content, { shv: 'cancel', vf_id: result.id }, null, null, null);
			return;
		}

		upload.sent = upload.file.size;
		showUploadProgress(upload);
		upload.$item.find('.InputfieldSelfHostedVideoProgressText').text(label('finishing'));

		// until the page has the video, leaving would lose it
		finishing++;
		var finished = false;
		var finish = function() {
			if(finished) return;
			finished = true;
			finishing = Math.max(0, finishing - 1);
			syncLeaveWarning();
		};
		setTimeout(finish, 120000);
		upload.$item.find('button').remove();

		var before = $list.children('li.AjaxUpload').toArray();
		var data = new DataTransfer();
		data.items.add(new File([ 'shv-upload:' + result.id + ':' + result.size ], name, { type: 'application/octet-stream' }));

		passing = true;
		try {
			input.files = data.files;
			input.dispatchEvent(new Event('change', { bubbles: true }));
		} finally {
			passing = false;
		}

		// the core script has added a progress item of its own: it takes the place of ours
		var $core = $list.children('li.AjaxUpload').filter(function() {
			return $.inArray(this, before) < 0;
		}).first();

		if(!$core.length) {
			upload.$item.remove();
			finish();
			return;
		}

		$core.find('.InputfieldFileStats').text(' • ' + formatBytes(upload.file.size) + ' · ' + label('finishing'));
		upload.$item.replaceWith($core);

		if(window.MutationObserver) {
			// tell screen reader users how it ended
			var observer = new MutationObserver(function(mutations) {
				for(var i = 0; i < mutations.length; i++) {
					if($.inArray($core[0], Array.prototype.slice.call(mutations[i].removedNodes)) < 0) continue;
					observer.disconnect();
					finish();
					var $next = $(mutations[i].nextSibling);
					if($next.find('.ui-state-error').length) {
						announce(label('uploadError') + ' ' + $next.find('.InputfieldFileStats').text().replace(/^[\s-]+/, ''));
					} else {
						announce(label('uploadDone').replace('%s', name));
					}
					return;
				}
			});
			observer.observe($list[0], { childList: true });
		}
	}

	/**
	 * Replace the file of a video
	 */
	function replaceFile($box, file) {
		var $status = $box.find('.InputfieldSelfHostedVideoReplaceStatus');
		var $retry = $box.find('.InputfieldSelfHostedVideoReplaceRetry');
		var $cancel = $box.find('.InputfieldSelfHostedVideoReplaceCancel');
		var $content = $box.closest('.InputfieldContent');
		var maxBytes = parseInt($content.closest('.Inputfield').attr('data-vf-max-bytes'), 10) || 0;
		var extensions = String($box.attr('data-extensions') || '').toLowerCase().split(/\s+/);
		var ext = file.name.indexOf('.') > -1 ? file.name.split('.').pop().toLowerCase() : '';
		var name = $box.attr('data-name');

		function fail(text) {
			$status.text(text).addClass('ui-state-error-text');
		}

		function done() {
			setBusy($box, false);
			$retry.add($cancel).prop('hidden', true).off('click.vf');
		}

		$status.text('').removeClass('ui-state-error-text');

		if($.inArray(ext, extensions) < 0) {
			fail(label('badExtension') + ' ' + extensions.join(', '));
			return;
		}
		if(!file.size) {
			fail(label('emptyFile'));
			return;
		}
		if(maxBytes && file.size > maxBytes) {
			fail(label('tooBigMax').replace('%s', formatBytes(maxBytes)));
			return;
		}
		if(!window.confirm(label('replaceConfirm').replace('%s', file.name))) return;

		setBusy($box, true);
		$status.text(label('replacing'));

		var meter = rateMeter();
		var paused = false;
		var sending = transfer({
			$content: $content,
			file: file,
			params: { vf_for: 'replace', vf_name: name },
			onProgress: function(sent, total) {
				meter.add(sent);
				var rate = meter.text(sent, total);
				if(!paused) $status.text(label('replacing') + ' ' + Math.floor(sent / total * 100) + '%' + (rate && sent < total ? ' · ' + rate : ''));
			},
			onState: function(state, message) {
				if(state === 'online') {
					announce(label('online'));
					return;
				}
				paused = state !== 'uploading';
				if(paused) meter.reset();
				$retry.prop('hidden', state !== 'stalled');
				$status.toggleClass('ui-state-error-text', state === 'stalled');
				warnText($status, state === 'offline');
				if(state === 'offline') {
					$status.text(label('offline'));
				} else if(state === 'retrying') {
					$status.text(label('retrying'));
				} else if(state === 'stalled') {
					$status.text(label('stalled') + (message ? ' (' + message + ')' : ''));
					$retry.trigger('focus');
				} else {
					$status.text(label('replacing'));
				}
			}
		});

		$cancel.prop('hidden', false).off('click.vf').on('click.vf', function() {
			sending.cancel();
			return false;
		});
		$retry.off('click.vf').on('click.vf', function() {
			sending.retry();
			return false;
		});

		sending.promise.then(function(result) {
			$cancel.prop('hidden', true);
			$status.text(label('finishing'));
			// until the server has put the new file in place, leaving would lose it
			finishing++;
			syncLeaveWarning();
			var finish = function() {
				finishing = Math.max(0, finishing - 1);
				syncLeaveWarning();
			};
			return send($box, {
				shv: 'replace',
				vf_name: name,
				vf_filename: file.name,
				vf_id: result.id,
				vf_size: result.size
			}, null).then(function(response) {
				finish();
				return response;
			}, function(error) {
				finish();
				throw error;
			});
		}).then(function(response) {
			var $old = $box.closest('.InputfieldFileItem');
			var $list = $old.closest('.InputfieldFileList');
			var $new = $(response.markup);
			var values = {};

			// what the editor typed but has not saved yet stays. Input names end in an identifier
			// of the file name, which changes when the replacement has another file extension.
			var key = function(name) { return name.replace(/_[0-9a-f]{32}$/, ''); };

			$old.find('input[type=text], textarea').each(function() {
				if(this.name) values[key(this.name)] = $(this).val();
			});

			// the editor is working in this video: it stays open
			var wasOpen = !$old.children('.InputfieldSelfHostedVideoBody').prop('hidden');

			$old.replaceWith($new);
			$new.addClass('InputfieldFileItemExisting');

			$new.find('input[type=text], textarea').each(function() {
				if(this.name && Object.prototype.hasOwnProperty.call(values, key(this.name))) $(this).val(values[key(this.name)]);
			});

			setOpen($new, wasOpen);
			updateHeadTitle($new);

			var $inputfields = $new.find('.Inputfield');
			if($inputfields.length && typeof window.InputfieldsInit === 'function') {
				window.InputfieldsInit($new.find('.Inputfields'));
				$inputfields.trigger('reloaded', [ 'InputfieldFileUpload' ]);
			}

			$new.find('.InputfieldSelfHostedVideoReplaceStatus').text(label('replaced'));
			$new.closest('.Inputfield').addClass('InputfieldStateChanged');
			$list.trigger('AjaxUploadDone');
		}, function(error) {
			done();
			if(error && error.cancelled) {
				$status.text('').removeClass('ui-state-error-text');
			} else {
				fail(label('replaceError') + ' ' + (error ? error.message : ''));
			}
		});
	}

	// Files chosen or dropped for a video field are taken over before the core upload script
	// sees them. These listeners run first, because they listen on the way down to the target.
	document.addEventListener('change', function(event) {
		var input = event.target;
		if(passing || !input || input.type !== 'file' || !$(input).closest('.InputfieldFileUpload').length) return;
		var $content = partsContent(input);
		if(!$content) return;
		event.stopPropagation();
		event.preventDefault();
		var files = Array.prototype.slice.call(input.files || []);
		input.value = '';
		addFiles($content, files);
	}, true);

	document.addEventListener('drop', function(event) {
		var data = event.dataTransfer;
		if(!data || !data.files || !data.files.length) return;
		var $content = partsContent(event.target);
		if(!$content) return;
		event.stopPropagation();
		event.preventDefault();
		$content.removeClass('ui-state-hover').closest('.Inputfield').removeClass('pw-drag-in-file');
		addFiles($content, Array.prototype.slice.call(data.files));
	}, true);

	/*** "Back to top" at the foot of each video ************************************************/

	$(document).ready(function() {

		// the link at the foot of each video
		$(document).on('click', '.InputfieldSelfHostedVideoTop', function() {
			window.scrollTo(0, 0);
			// where keyboard users go on from: the headline of the page
			var $head = $('#pw-content-head h1, #headline, h1').first();
			if($head.length) {
				if(!$head.attr('tabindex')) $head.attr('tabindex', '-1');
				$head[0].focus({ preventScroll: true });
			}
			return false;
		});

		// accordion: the header of a video opens and closes it
		$(document).on('click', '.InputfieldSelfHostedVideoToggle', function(event) {
			var $item = $(this).closest('.InputfieldFileItem');
			setOpen($item, $(this).attr('aria-expanded') !== 'true');
			// sorting by dragging keeps a click of the mouse from moving the focus here, so that
			// the Tab key would not go on from the video that was just opened
			if(event.originalEvent && event.originalEvent.detail > 0 && document.activeElement !== this) {
				$(this).addClass('InputfieldSelfHostedVideoToggleClicked');
				try { this.focus({ preventScroll: true }); } catch(e) { /* an old browser */ }
			}
		}).on('blur keydown', '.InputfieldSelfHostedVideoToggle', function() {
			$(this).removeClass('InputfieldSelfHostedVideoToggleClicked');
		}).on('keydown', '.InputfieldSelfHostedVideoToggle', function(event) {
			if(event.target !== this) return;
			if(event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
				event.preventDefault();
				$(this).trigger('click');
			}
		}).on('change', '.InputfieldSelfHostedVideoHead .InputfieldFileDelete input', function() {
			// a video marked for deletion closes
			if(this.checked) setOpen($(this).closest('.InputfieldFileItem'), false);
		}).on('change', '.InputfieldSelfHostedVideoEndSelect', function() {
			// the address and text of the button only for "Show a button with a link"
			var $link = $(this).closest('.InputfieldSelfHostedVideoEnd').find('.InputfieldSelfHostedVideoEndLink');
			if(this.value === 'link') {
				$link.removeAttr('data-shv-hidden');
			} else {
				$link.attr('data-shv-hidden', '1');
			}
		}).on('change', '.InputfieldSelfHostedVideo .InputfieldFileDelete input', function() {
			// a video that a text of the page places: say what the text loses
			var $warning = $(this).closest('.InputfieldFileItem').children('.InputfieldSelfHostedVideoDeleteWarning');
			if($warning.length) $warning.prop('hidden', !this.checked);
		}).on('input change', '.InputfieldSelfHostedVideoLangText_title input', function() {
			updateHeadTitle($(this).closest('.InputfieldFileItem'));
		}).on('AjaxUploadDone', '.InputfieldSelfHostedVideo .InputfieldFileList', function() {
			syncItems($(this));
			var $uploaded = $(this);
			setTimeout(function() { configureAllSorts($uploaded.closest('.InputfieldSelfHostedVideo')); }, 600);
		}).on('submit', 'form', function() {
			if($(this).find('.InputfieldSelfHostedVideoBody').length) rememberOpen();
			// the page of each field, to be shown again after saving
			$(this).find('.InputfieldSelfHostedVideo').each(function() {
				var page = $(this).data('vfPage');
				if(!page) return;
				try {
					window.sessionStorage.setItem(pageKey($(this)), JSON.stringify({ time: Date.now(), page: page }));
				} catch(e) { /* then the first page */ }
			});
		}).on('reloaded', '.Inputfield', function() {
			// a field that is loaded when its tab or its repeater item is opened
			setupAllPages($(this));
			configureAllSorts($(this));
			if($(this).find('.InputfieldSelfHostedVideoBody').length) restoreOpen($(this));
		}).on('change', '.InputfieldSelfHostedVideoPerPage select', function() {
			// the choice holds for all video fields, in this browser, until it is changed again
			try {
				window.localStorage.setItem(PER_PAGE_KEY, String(parseInt($(this).val(), 10) || 0));
			} catch(e) { /* for this page only */ }
			var n = perPage();
			$('.InputfieldSelfHostedVideo').each(function() {
				var $field = $(this);
				if(!$field.data('vfPager')) return;
				// the page that holds the video that was the first one shown
				var $items = pagerItems(pagerList($field));
				var firstShown = $items.index($items.not('.InputfieldSelfHostedVideoOffPage').first());
				showPage($field, n && firstShown > 0 ? Math.floor(firstShown / n) + 1 : 1, false);
			});
		}).on('click', '.InputfieldSelfHostedVideoPager button[data-page]', function() {
			showPage($(this).closest('.InputfieldSelfHostedVideo'), $(this).attr('data-page'), true);
			return false;
		});

		// an input the browser does not accept (a required one, say) is shown, on its page and opened
		document.addEventListener('invalid', function(event) {
			var $item = $(event.target).closest('.InputfieldSelfHostedVideo .InputfieldFileItem');
			if(!$item.length) return;
			revealItem($item);
			if($item.children('.InputfieldSelfHostedVideoBody').prop('hidden')) setOpen($item, true);
		}, true);

		setupAllPages($(document.body));
		configureAllSorts($(document.body));
		setTimeout(function() { configureAllSorts($(document.body)); }, 600);
		restoreOpen();
		syncItems($(document));

		// use the picture currently shown in the player
		$(document).on('click', '.InputfieldSelfHostedVideoUseFrame', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			var player = $box.find('video.InputfieldSelfHostedVideoPlayer')[0];

			if(player && player.readyState >= 2 && player.videoWidth) {
				// the player holds the very picture the editor is looking at
				var info = describe(player);
				setBusy($box, true);
				grab(player, maxThumbBytes($box)).then(function(blob) {
					return saveThumb($box, blob, info);
				}).then(function() {
					setBusy($box, false);
				}, function(error) {
					setBusy($box, false);
					setStatus($box, label('saveError') + ' ' + (error ? error.message : ''), true);
				});
			} else {
				// nothing played yet: take the usual position
				createThumb($box, $box.attr('data-default-time'));
			}
			return false;
		});

		// one of the suggested pictures
		$(document).on('click', '.InputfieldSelfHostedVideoFrame', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			if($(this).attr('aria-pressed') === 'true') return false;
			createThumb($box, parseFloat($(this).attr('data-time')) || 0, true);
			return false;
		});

		// back from an uploaded image to a frame of the video
		$(document).on('click', '.InputfieldSelfHostedVideoResetFrame', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			createThumb($box, $box.attr('data-default-time'));
			return false;
		});

		// an image of the editor's own
		$(document).on('click', '.InputfieldSelfHostedVideoUploadImage', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');

			pickFile($(this).attr('data-accept'), function(file) {
				var max = maxThumbBytes($box);
				var player = $box.find('video.InputfieldSelfHostedVideoPlayer')[0];

				if(file.type !== 'image/jpeg' && file.type !== 'image/png') {
					setStatus($box, label('badImage'), true);
					return;
				}
				if(max && file.size > max) {
					setStatus($box, label('tooBig'), true);
					return;
				}

				var info = player ? describe(player) : {};
				info.time = 0;
				info.custom = true;
				setBusy($box, true);
				saveThumb($box, file, info).then(function() {
					setBusy($box, false);
				}, function(error) {
					setBusy($box, false);
					setStatus($box, label('saveError') + ' ' + (error ? error.message : ''), true);
				});
			});
			return false;
		});

		// a new file for an existing video
		$(document).on('click', '.InputfieldSelfHostedVideoReplaceFile', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			pickFile($(this).attr('data-accept'), function(file) {
				replaceFile($box, file);
			});
			return false;
		});

		// the same from the checklist of a video that is too large: the progress shows in the area "Video file"
		$(document).on('click', '.InputfieldSelfHostedVideoCheckReplace', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			var $button = $box.find('.InputfieldSelfHostedVideoReplaceFile').first();
			if(!$button.length) return false;
			pickFile($button.attr('data-accept'), function(file) {
				var status = $box.find('.InputfieldSelfHostedVideoReplaceStatus').get(0) || $button.get(0);
				replaceFile($box, file);
				// after the browser has put the focus back on the button that opened the file choice
				setTimeout(function() {
					if(status.scrollIntoView) status.scrollIntoView({ block: 'center' });
				}, 50);
			});
			return false;
		});

		// captions: a video without speech needs none, so all about them is hidden
		$(document).on('change', '.InputfieldSelfHostedVideoNoSpeech input[type=checkbox]', function() {
			var $body = $(this).closest('.InputfieldSelfHostedVideoCaptions').find('.InputfieldSelfHostedVideoCaptionsBody').first();
			// an open caption editor keeps its text and is back when the mark is taken back
			$body.prop('hidden', this.checked);
		});

		// captions: choosing a language that is not in the list asks for its code and name
		$(document).on('change', '.InputfieldSelfHostedVideoCaptionLang', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			var other = $(this).val() === 'other';
			$box.find('.InputfieldSelfHostedVideoCaptionCode, .InputfieldSelfHostedVideoCaptionName').prop('hidden', !other);
			captionStatus($box, '');
			if(other) {
				$box.find('.InputfieldSelfHostedVideoCaptionCode').trigger('focus');
			} else if($(this).val()) {
				rememberLanguage($(this).val());
			}
		});

		// captions: a file for the chosen language
		$(document).on('click', '.InputfieldSelfHostedVideoCaptionUpload', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			var chosen = chosenLanguage($box);
			if(!chosen) return false;

			pickFile($(this).attr('data-accept'), function(file) {
				var max = settings().maxCaptionBytes || 2097152;
				if(file.size > max) {
					captionStatus($box, label('captionTooBig'), true);
					return;
				}
				captionBusy($box, true);
				captionStatus($box, label('captionSaving'));
				send($box, {
					shv: 'caption',
					vf_name: $box.attr('data-name'),
					vf_lang: chosen.lang,
					vf_label: chosen.label,
					vf_filename: file.name
				}, file).then(function(response) {
					captionBusy($box, false);
					applyCaptions($box, response);
					captionStatus($box, response.message || '');
					$box.find('.InputfieldSelfHostedVideoCaptionCode, .InputfieldSelfHostedVideoCaptionName').val('');
				}, function(error) {
					captionBusy($box, false);
					captionStatus($box, label('captionError') + ' ' + (error ? error.message : ''), true);
				});
			});
			return false;
		});

		// checklist: prepare an MP4 for streaming (its index moved to the front of the file)
		$(document).on('click', '.InputfieldSelfHostedVideoFaststart', function() {
			var $button = $(this);
			var $box = $button.closest('.InputfieldSelfHostedVideoBox');
			var $status = $button.siblings('.InputfieldSelfHostedVideoFaststartStatus');
			$button.prop('disabled', true);
			$status.text(label('faststarting')).removeClass('ui-state-error-text');
			send($box, { shv: 'faststart', vf_name: $box.attr('data-name') }, null).then(function(response) {
				applyChecks($box, response);
				// the point is gone from the new checklist: say what happened there, where the button was
				var $list = $box.find('.InputfieldSelfHostedVideoChecklist .InputfieldSelfHostedVideoAreaBody').first();
				$('<p class="InputfieldSelfHostedVideoFaststartDone notes" role="status"></p>').text(label('faststartDone')).appendTo($list.length ? $list : $box.find('.InputfieldSelfHostedVideoChecklist').first());
				var $player = $box.find('video.InputfieldSelfHostedVideoPlayer');
				if($player.length) $player[0].load();
			}, function(error) {
				$button.prop('disabled', false);
				$status.text(label('faststartError') + ' ' + (error ? error.message : '')).addClass('ui-state-error-text');
			});
			return false;
		});

		// captions: edit the text of one language right here, and save it as a new file for that language
		var editorCount = 0;

		// (texts for the page come encoded for HTML: these are put in as text)
		function plain(text) {
			var element = document.createElement('textarea');
			element.innerHTML = text || '';
			return element.value;
		}

		function closeCaptionEditor($box, lang) {
			$box.find('.InputfieldSelfHostedVideoCaptionEditor').remove();
			$box.find('.InputfieldSelfHostedVideoCaptionEdit').attr('aria-expanded', 'false');
			if(lang) $box.find('.InputfieldSelfHostedVideoTrack[data-lang="' + lang + '"] .InputfieldSelfHostedVideoCaptionEdit').trigger('focus');
		}

		$(document).on('click', '.InputfieldSelfHostedVideoCaptionEdit', function() {
			var $button = $(this);
			var $box = $button.closest('.InputfieldSelfHostedVideoBox');
			var $track = $button.closest('.InputfieldSelfHostedVideoTrack');
			var lang = $track.attr('data-lang');
			var $open = $box.find('.InputfieldSelfHostedVideoCaptionEditor');
			if($open.length) {
				var same = $open.attr('data-lang') === lang;
				var changed = $open.find('textarea').val() !== $open.data('original');
				if(changed && !window.confirm(label('captionLeave'))) return false;
				closeCaptionEditor($box, '');
				if(same) {
					$button.trigger('focus');
					return false;
				}
			}
			captionBusy($box, true);
			captionStatus($box, label('captionLoading'));
			send($box, { shv: 'captiontext', vf_name: $box.attr('data-name'), vf_lang: lang }, null).then(function(response) {
				captionBusy($box, false);
				captionStatus($box, '');
				var id = 'InputfieldSelfHostedVideoCaptionText' + (++editorCount);
				var $editor = $('<div class="InputfieldSelfHostedVideoCaptionEditor"></div>').attr('data-lang', lang);
				$('<label class="InputfieldSelfHostedVideoLabel"></label>').attr('for', id).text(plain(label('captionEditing')).replace('%s', response.label || lang)).appendTo($editor);
				var $text = $('<textarea class="uk-textarea InputfieldSelfHostedVideoCaptionText" rows="14" spellcheck="true" autocomplete="off"></textarea>')
					.attr({ id: id, lang: lang, 'aria-describedby': id + 'Hint' }).val(response.text || '').appendTo($editor);
				$('<p class="InputfieldSelfHostedVideoCaptionEditHint detail"></p>').attr('id', id + 'Hint').text(plain(label('captionEditHint'))).appendTo($editor);
				var $buttons = $('<div class="InputfieldSelfHostedVideoCaptionEditButtons"></div>').appendTo($editor);
				$('<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoCaptionEditSave"><i class="fa fa-fw fa-check" aria-hidden="true"></i></button>')
					.append($('<span></span>').text(plain(label('captionEditSave')))).appendTo($buttons);
				$('<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoCaptionEditCancel"><i class="fa fa-fw fa-times" aria-hidden="true"></i></button>')
					.append($('<span></span>').text(plain(label('captionEditCancel')))).appendTo($buttons);
				$editor.data('original', $text.val());
				$box.find('.InputfieldSelfHostedVideoCaptionList').after($editor);
				$box.find('.InputfieldSelfHostedVideoTrack[data-lang="' + lang + '"] .InputfieldSelfHostedVideoCaptionEdit').attr('aria-expanded', 'true').attr('aria-controls', id);
				$text.trigger('focus');
			}, function(error) {
				captionBusy($box, false);
				captionStatus($box, label('captionError') + ' ' + (error ? error.message : ''), true);
			});
			return false;
		});

		$(document).on('click', '.InputfieldSelfHostedVideoCaptionEditCancel', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			var $editor = $(this).closest('.InputfieldSelfHostedVideoCaptionEditor');
			if($editor.find('textarea').val() !== $editor.data('original') && !window.confirm(label('captionLeave'))) return false;
			closeCaptionEditor($box, $editor.attr('data-lang'));
			return false;
		});

		$(document).on('keydown', '.InputfieldSelfHostedVideoCaptionText', function(event) {
			if(event.key !== 'Escape') return;
			event.preventDefault();
			$(this).closest('.InputfieldSelfHostedVideoCaptionEditor').find('.InputfieldSelfHostedVideoCaptionEditCancel').trigger('click');
		});

		$(document).on('click', '.InputfieldSelfHostedVideoCaptionEditSave', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			var $editor = $(this).closest('.InputfieldSelfHostedVideoCaptionEditor');
			var lang = $editor.attr('data-lang');
			var text = $editor.find('textarea').val();
			captionBusy($box, true);
			$editor.find('button, textarea').prop('disabled', true);
			captionStatus($box, label('captionSaving'));
			var body = new Blob([ text ], { type: 'text/vtt' });
			send($box, { shv: 'caption', vf_name: $box.attr('data-name'), vf_lang: lang, vf_edit: 1, vf_filename: lang + '.vtt' }, body).then(function(response) {
				captionBusy($box, false);
				applyCaptions($box, response);
				captionStatus($box, response.message || '');
				closeCaptionEditor($box, lang);
			}, function(error) {
				captionBusy($box, false);
				$editor.find('button, textarea').prop('disabled', false);
				captionStatus($box, label('captionError') + ' ' + (error ? error.message : ''), true);
				$editor.find('textarea').trigger('focus');
			});
			return false;
		});

		// captions: remove those of one language
		$(document).on('click', '.InputfieldSelfHostedVideoCaptionRemove', function() {
			var $box = $(this).closest('.InputfieldSelfHostedVideoBox');
			var $track = $(this).closest('.InputfieldSelfHostedVideoTrack');
			if(!window.confirm(label('captionRemoveConfirm').replace('%s', $track.find('.InputfieldSelfHostedVideoTrackName').text()))) return false;
			captionBusy($box, true);
			captionStatus($box, label('captionRemoving'));
			send($box, { shv: 'uncaption', vf_name: $box.attr('data-name'), vf_lang: $track.attr('data-lang') }, null).then(function(response) {
				captionBusy($box, false);
				applyCaptions($box, response);
				captionStatus($box, response.message || '');
				$box.find('.InputfieldSelfHostedVideoCaptionUpload').trigger('focus');
			}, function(error) {
				captionBusy($box, false);
				captionStatus($box, label('captionError') + ' ' + (error ? error.message : ''), true);
			});
			return false;
		});

		// the editor saves the page while a video is on its way. The page editor has asked whether
		// to go on, so the browser does not need to ask a second time.
		$(document).on('submit', 'form', function() {
			window.removeEventListener('beforeunload', warnBeforeLeaving);
			leaveWarning = false;
		});

		// an interrupted upload: choose the file again, or let it go
		$(document).on('click', '.InputfieldSelfHostedVideoResumeChoose', function() {
			var $item = $(this).closest('.InputfieldSelfHostedVideoResumeItem');
			var $content = $item.closest('.InputfieldContent');
			var id = $item.attr('data-id');
			var record = $.grep(resumeRecords(), function(r) { return r.id === id; })[0];
			var $status = $item.find('.InputfieldSelfHostedVideoResumeStatus');
			if(!record) {
				$item.remove();
				return false;
			}
			var accept = $content.find('.InputfieldFileUpload input[type=file]').first().attr('accept') ||
				$.map(String($content.find('.InputfieldFileUpload').first().data('extensions') || '').split(/\s+/), function(e) { return e ? '.' + e : null; }).join(',');
			pickFile(accept, function(file) {
				if(file.name !== record.name || file.size !== record.size) {
					$status.text(label('resumeWrong').replace('%1$s', file.name).replace('%2$s', record.name).replace('%3$s', formatBytes(record.size))).addClass('ui-state-error-text');
					return;
				}
				addFiles($content, [ file ]);
			});
			return false;
		});

		$(document).on('click', '.InputfieldSelfHostedVideoResumeDiscard', function() {
			var $item = $(this).closest('.InputfieldSelfHostedVideoResumeItem');
			var $content = $item.closest('.InputfieldContent');
			var id = $item.attr('data-id');
			var $next = $item.closest('.InputfieldContent').find('.InputfieldFileUpload button, .InputfieldFileUpload .InputfieldFileUploadButton, .InputfieldFileUpload input').filter(':visible').first();
			// let the server drop what it has
			sendPart($content, { shv: 'cancel', vf_id: id }, null, null, null);
			resumeDrop(id);
			announce(label('resumeDiscarded'));
			if($next.length) $next.trigger('focus');
			return false;
		});

		// buttons of an upload on its way
		$(document).on('click', '.InputfieldSelfHostedVideoCancel, .InputfieldSelfHostedVideoRetry, .InputfieldSelfHostedVideoAnyway, .InputfieldSelfHostedVideoPause', function() {
			var $item = $(this).closest('.InputfieldSelfHostedVideoProgress');
			var upload = $item.data('vfUpload');
			if(!upload) return false;
			if($(this).hasClass('InputfieldSelfHostedVideoPause')) {
				// the button stays where it is and says what it does next
				if(!upload.transfer) return false;
				if(upload.transfer.isPaused()) {
					upload.transfer.resume();
					announce(label('continuedName').replace('%s', upload.file.name));
				} else {
					upload.transfer.pause();
				}
			} else if($(this).hasClass('InputfieldSelfHostedVideoAnyway')) {
				// the editor wants the file all the same
				upload.check = 'ok';
				setPhase(upload, 'waiting');
				$item.removeClass('InputfieldSelfHostedVideoBlocked');
				uploadIcon($item, '');
				$(this).prop('hidden', true);
				$item.find('.InputfieldSelfHostedVideoCancel').text(label('cancel')).trigger('focus');
				warnText($item.find('.InputfieldSelfHostedVideoProgressText').text(label('waiting')), false);
				nextUpload();
			} else if($(this).hasClass('InputfieldSelfHostedVideoRetry')) {
				if(upload.transfer) upload.transfer.retry();
			} else if(upload.transfer) {
				upload.transfer.cancel();
			} else {
				// still waiting for its turn
				var at = $.inArray(upload, uploadQueue);
				if(at > -1) uploadQueue.splice(at, 1);
				upload.check = 'removed';
				setPhase(upload, 'gone');
				$item.remove();
				syncLeaveWarning();
				// an interrupted upload that was to go on stays offered
				if(upload.resume) showResumable(upload.$content.closest('.InputfieldSelfHostedVideo'));
				announce(label('uploadCancelled').replace('%s', upload.file.name));
				nextUpload();
			}
			return false;
		});

		// videos that arrived by upload or by reloading part of the editor
		$(document).on('AjaxUploadDone', '.InputfieldFileList', function() {
			presetLanguages($(this));
			scan($(this));
		});
		$(document).on('reloaded', '.Inputfield', function() {
			presetLanguages($(this));
			scan($(this));
		});

		presetLanguages($(document.body));
		scan($(document.body));
	});

	/*** Reusing a video of another page ********************************************************/

	function reuseStatus($panel, text, isError) {
		$panel.find('.InputfieldSelfHostedVideoReuseStatus').text(text || '').toggleClass('ui-state-error-text', !!isError);
	}

	/**
	 * Build the search of the picker, the first time it is opened
	 */
	function buildReuse($panel) {
		if($panel.data('vfBuilt')) return;
		$panel.data('vfBuilt', true);
		var id = $panel.attr('id');
		$panel.append(
			'<div class="InputfieldSelfHostedVideoReuseSearch" role="search">' +
				'<div class="InputfieldSelfHostedVideoReuseField InputfieldSelfHostedVideoReuseFieldQ">' +
					'<label for="' + id + '_q">' + escapeHtml(label('reuseSearch')) + '</label>' +
					'<input type="search" class="uk-input InputfieldSelfHostedVideoReuseQ" id="' + id + '_q" autocomplete="off" placeholder="' + escapeHtml(label('reuseSearchHint')) + '">' +
				'</div>' +
				'<div class="InputfieldSelfHostedVideoReuseField InputfieldSelfHostedVideoReuseFieldTag" hidden>' +
					'<label for="' + id + '_tag">' + escapeHtml(label('reuseTag')) + '</label>' +
					'<select class="uk-select InputfieldSelfHostedVideoReuseTag" id="' + id + '_tag"></select>' +
				'</div>' +
				'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoReuseGo">' +
					'<i class="fa fa-fw fa-search" aria-hidden="true"></i> ' + escapeHtml(label('reuseGo')) + '</button>' +
			'</div>' +
			'<p class="InputfieldSelfHostedVideoReuseStatus" role="status"></p>' +
			'<ul class="InputfieldSelfHostedVideoReuseList" aria-label="' + escapeHtml(label('reuseResults')) + '"></ul>' +
			'<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoReuseMore" hidden>' + escapeHtml(label('reuseMore')) + '</button>'
		);

		// The search has nothing that is saved with the page. What happens in it stays in it, so that
		// the page editor does not take it for a change of the page, and the Enter key searches
		// instead of saving the page.
		$panel.find('.InputfieldSelfHostedVideoReuseQ').on('change input', function(event) {
			event.stopPropagation();
		}).on('keydown', function(event) {
			if(event.which !== 13) return;
			event.preventDefault();
			event.stopPropagation();
			reuseFind($panel, false);
		});

		$panel.find('.InputfieldSelfHostedVideoReuseTag').on('change', function(event) {
			event.stopPropagation();
			reuseFind($panel, false);
		});
	}

	function reuseItem(item) {
		var $li = $('<li class="InputfieldSelfHostedVideoReuseItem"></li>').data('vfItem', item);
		var $thumb = $('<span class="InputfieldSelfHostedVideoReuseThumb"></span>').appendTo($li);
		if(item.thumb) {
			// the picture says nothing the text next to it does not say
			$('<img alt="" loading="lazy">').attr('src', item.thumb).appendTo($thumb);
		} else {
			$thumb.append('<i class="fa fa-film" aria-hidden="true"></i>');
		}
		var $text = $('<span class="InputfieldSelfHostedVideoReuseText"></span>').appendTo($li);
		var $title = $('<strong class="InputfieldSelfHostedVideoReuseTitle"></strong>').text(item.title).appendTo($text);
		if(item['protected']) {
			$title.append(' ').append($('<span class="InputfieldSelfHostedVideoReuseBadge"></span>').text(label('reuseProtected')).prepend('<i class="fa fa-lock" aria-hidden="true"></i> '));
		}
		$('<span class="InputfieldSelfHostedVideoReuseFacts"></span>').text(item.facts).appendTo($text);
		$('<span class="InputfieldSelfHostedVideoReuseWhere"></span>').text(item.where).appendTo($text);
		if(item.tags) {
			$('<span class="InputfieldSelfHostedVideoReuseTags"></span>').text(item.tags).prepend('<i class="fa fa-fw fa-tags" aria-hidden="true"></i> ').appendTo($text);
		}
		var $button = $('<button type="button" class="InputfieldSelfHostedVideoButton InputfieldSelfHostedVideoReuseUse"></button>').appendTo($li);
		reuseButton($button, item, item.here);
		return $li;
	}

	function reuseButton($button, item, here) {
		if(here) {
			// stays where it is, so that the keyboard does not lose its place
			$button.text(label('reuseHere')).attr('aria-disabled', 'true').removeAttr('aria-label').addClass('InputfieldSelfHostedVideoReuseDone');
		} else {
			$button.text(label('reuseUse')).attr('aria-label', label('reuseUseLabel').replace('%s', item.title));
		}
	}

	/**
	 * Ask the server for videos and show them
	 */
	function reuseFind($panel, more) {
		var $list = $panel.find('.InputfieldSelfHostedVideoReuseList');
		var $more = $panel.find('.InputfieldSelfHostedVideoReuseMore');
		var start = more ? $list.children().length : 0;
		var ticket = ($panel.data('vfTicket') || 0) + 1;

		$panel.data('vfTicket', ticket);
		reuseStatus($panel, label('reuseSearching'));
		$panel.attr('aria-busy', 'true');

		send($panel, {
			shv: 'find',
			vf_q: $panel.find('.InputfieldSelfHostedVideoReuseQ').val() || '',
			vf_tag: $panel.find('.InputfieldSelfHostedVideoReuseTag').val() || '',
			vf_start: start
		}, null).then(function(response) {
			if($panel.data('vfTicket') !== ticket) return; // an answer to an earlier search
			$panel.removeAttr('aria-busy');

			var $select = $panel.find('.InputfieldSelfHostedVideoReuseTag');
			if(!$select.children().length && response.tags && !$.isEmptyObject(response.tags)) {
				$select.append($('<option value=""></option>').text(label('reuseAnyTag')));
				$.each(response.tags, function(tag, text) {
					$select.append($('<option></option>').attr('value', tag).text(text));
				});
				$panel.find('.InputfieldSelfHostedVideoReuseFieldTag').prop('hidden', false);
			}

			if(!more) $list.empty();
			var $first = null;
			$.each(response.items || [], function(i, item) {
				var $li = reuseItem(item).appendTo($list);
				if(!$first) $first = $li;
			});

			var shown = $list.children().length;
			var total = parseInt(response.total, 10) || 0;
			$more.prop('hidden', shown >= total);

			if(!total) {
				reuseStatus($panel, label('reuseNone'));
			} else if(shown < total) {
				reuseStatus($panel, label('reuseShown').replace('%1$d', shown).replace('%2$d', total));
			} else {
				reuseStatus($panel, total === 1 ? label('reuseFoundOne') : label('reuseFound').replace('%d', total));
			}

			// "Show more" has gone or moved down: go on at the first of the new videos
			if(more && $first) $first.find('.InputfieldSelfHostedVideoReuseUse').trigger('focus');
		}, function(error) {
			if($panel.data('vfTicket') !== ticket) return;
			$panel.removeAttr('aria-busy');
			reuseStatus($panel, error ? error.message : label('requestError'), true);
		});
	}

	/**
	 * Add the chosen video to the field
	 */
	function reuseAdd($button) {
		var $li = $button.closest('.InputfieldSelfHostedVideoReuseItem');
		var $panel = $button.closest('.InputfieldSelfHostedVideoReusePanel');
		var item = $li.data('vfItem');
		if(!item || $button.attr('aria-disabled') === 'true' || $panel.data('vfAdding')) return;

		$panel.data('vfAdding', true);
		reuseStatus($panel, label('reuseAdding'));

		send($panel, { shv: 'reuse', vf_ref_page: item.page, vf_ref_field: item.field, vf_ref_name: item.name }, null).then(function(response) {
			$panel.data('vfAdding', false);

			var $content = $panel.closest('.InputfieldContent');
			var $inputfield = $content.closest('.Inputfield');
			var $list = $content.find('.InputfieldFileList').first();

			if(!$list.length) {
				$list = $('<ul class="InputfieldFileList ui-helper-clearfix"></ul>');
				var $placeholder = $content.find('.InputfieldFileListPlaceholder').first();
				if($placeholder.length) {
					$placeholder.replaceWith($list);
				} else {
					$content.prepend($list);
				}
			}

			if(response.replaced) {
				$list.children('li').remove();
				// the video that was replaced can be chosen again
				$panel.find('.InputfieldSelfHostedVideoReuseDone').each(function() {
					var other = $(this).closest('.InputfieldSelfHostedVideoReuseItem').data('vfItem');
					$(this).removeAttr('aria-disabled').removeClass('InputfieldSelfHostedVideoReuseDone');
					if(other) reuseButton($(this), other, false);
				});
			}

			$list.append($(response.markup));

			var count = $list.children('li').length;
			$inputfield.removeClass('InputfieldFileEmpty InputfieldFileSingle InputfieldFileMultiple')
				.addClass(count === 1 ? 'InputfieldFileSingle' : 'InputfieldFileMultiple');

			reuseButton($button, item, true);
			reuseStatus($panel, response.message || label('reuseAdded').replace('%s', item.title));
			$list.trigger('AjaxUploadDone');
		}, function(error) {
			$panel.data('vfAdding', false);
			reuseStatus($panel, label('reuseError') + ' ' + (error ? error.message : ''), true);
		});
	}

	$(document).on('click', '.InputfieldSelfHostedVideoReuseToggle', function() {
		var $toggle = $(this);
		var $panel = $(document.getElementById($toggle.attr('aria-controls')));
		var open = $toggle.attr('aria-expanded') !== 'true';
		$toggle.attr('aria-expanded', open ? 'true' : 'false');
		$panel.prop('hidden', !open);
		if(open) {
			var first = !$panel.data('vfBuilt');
			buildReuse($panel);
			if(first) reuseFind($panel, false);
			$panel.find('.InputfieldSelfHostedVideoReuseQ').trigger('focus');
		}
		return false;
	});

	$(document).on('click', '.InputfieldSelfHostedVideoReuseGo', function() {
		reuseFind($(this).closest('.InputfieldSelfHostedVideoReusePanel'), false);
		return false;
	});

	$(document).on('click', '.InputfieldSelfHostedVideoReuseMore', function() {
		reuseFind($(this).closest('.InputfieldSelfHostedVideoReusePanel'), true);
		return false;
	});

	$(document).on('click', '.InputfieldSelfHostedVideoReuseUse', function() {
		reuseAdd($(this));
		return false;
	});

})(jQuery);
