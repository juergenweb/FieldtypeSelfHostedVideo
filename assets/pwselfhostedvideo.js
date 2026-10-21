/**
 * Video Files: button for the rich text editor (TinyMCE)
 *
 * Adds the button and menu item "Insert video". It asks the server for the videos of the page
 * being edited, lets the editor choose one, and puts a tag such as [[video intro.mp4]] into
 * the text. On the website the text formatter "Videos in text" shows the video in its place.
 * With the cursor on a tag, the button changes that tag instead.
 *
 * The button is added by the module to the editor of every text field that has the text
 * formatter. Its name for toolbar settings is "pwselfhostedvideo".
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 */
(function() {
	'use strict';

	// Everything the scripts of this module share with the page is kept under one name,
	// FieldtypeSelfHostedVideo, so that nothing of it can meet a name of another script.
	var ns = window.FieldtypeSelfHostedVideo = window.FieldtypeSelfHostedVideo || {};

	// the plugin is added to the editor once, however often the page loads this file
	if(ns.tinymce) return;
	ns.tinymce = {};

	var TAG = /^\s*\[\[video\s+([^\]\s]+)([^\]]*)\]\]\s*$/i;

	function settings() {
		var config = window.ProcessWire && ProcessWire.config ? ProcessWire.config.FieldtypeSelfHostedVideo : null;
		return config || { labels: {}, editUrl: '' };
	}

	function label(name, fallback) {
		var labels = settings().labels || {};
		return labels[name] || fallback || '';
	}

	function escapeHtml(text) {
		return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	/**
	 * The page being edited, and the repeater item the text is in, if it is in one
	 */
	function pageIds(editor) {
		var element = editor.getElement();
		var input = document.getElementById('Inputfield_id');
		var wrap = element && element.closest ? element.closest('.Inputfield') : null;
		var item = element && element.closest ? element.closest('.InputfieldRepeaterItem') : null;
		return {
			page: input && input.value ? input.value : (wrap ? wrap.getAttribute('data-pid') || '' : ''),
			item: item ? item.getAttribute('data-page') || '' : ''
		};
	}

	/**
	 * Ask the server for the videos of the page
	 */
	function loadVideos(editor) {
		var ids = pageIds(editor);
		var url = settings().editUrl + '?id=' + encodeURIComponent(ids.page) + '&shv=list';
		if(ids.item) url += '&item=' + encodeURIComponent(ids.item);
		return window.fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function(response) {
			if(!response.ok) throw new Error('status ' + response.status);
			return response.json();
		}).then(function(data) {
			if(!data || !Array.isArray(data.videos)) throw new Error('unexpected answer');
			return data.videos;
		});
	}

	/**
	 * The tag the cursor is on, if the paragraph it is in holds a tag and nothing else
	 */
	function currentTag(editor) {
		var block = editor.dom.getParent(editor.selection.getNode(), 'p');
		if(!block) return null;
		var match = TAG.exec(block.textContent || '');
		if(!match) return null;

		var tag = { block: block, name: match[1], caption: '', layout: '', width: '', rest: [] };
		var tokens = match[2].split(/\s+/);

		for(var i = 0; i < tokens.length; i++) {
			var token = tokens[i];
			if(!token) continue;
			var lower = token.toLowerCase();
			if(lower === 'caption') {
				tag.caption = ''; // title, duration and description are shown anyway
			} else if(lower === 'nocaption') {
				tag.caption = 'none';
			} else if(lower === 'caption=title') {
				tag.caption = 'title';
			} else if(lower.indexOf('align=') === 0) {
				var align = lower.slice(6);
				if(align === 'left' || align === 'right' || align === 'center') tag.layout = align;
			} else if(lower.indexOf('width=') === 0) {
				tag.width = lower.slice(6).replace(/[^0-9]/g, '');
			} else {
				tag.rest.push(token); // what the dialog does not know is kept as it is
			}
		}

		return tag;
	}

	function buildTag(data, existing) {
		var tag = '[[video ' + data.video;
		if(data.caption === 'none') tag += ' nocaption';
		if(data.caption === 'title') tag += ' caption=title';
		if(data.layout) {
			var width = existing && existing.width && existing.layout ? existing.width : '50';
			tag += ' align=' + data.layout + ' width=' + width;
		}
		if(existing && existing.rest.length) tag += ' ' + existing.rest.join(' ');
		return tag + ']]';
	}

	function showDialog(editor, videos) {

		if(!videos.length) {
			editor.windowManager.alert(label('none', 'This page has no videos yet.'));
			return;
		}

		var existing = currentTag(editor);
		var initial = { video: videos[0].name, caption: '', layout: '' };
		var items = [];

		for(var i = 0; i < videos.length; i++) {
			var video = videos[i];
			var text = video.title === video.name ? video.facts : video.title + ' (' + video.facts + ')';
			items.push({ value: video.name, text: text });
			if(existing && video.name.toLowerCase() === existing.name.toLowerCase()) initial.video = video.name;
		}

		if(existing) {
			initial.caption = existing.caption;
			initial.layout = existing.layout;
		}

		editor.windowManager.open({
			title: label('title', 'Insert a video of this page'),
			body: {
				type: 'panel',
				items: [
					{ type: 'selectbox', name: 'video', label: label('video', 'Video'), items: items },
					{
						type: 'selectbox',
						name: 'caption',
						label: label('caption', 'Text below the video'),
						items: [
							{ value: '', text: label('captionBoth', 'Title, duration and description') },
							{ value: 'title', text: label('captionTitle', 'Title') },
							{ value: 'none', text: label('captionNone', 'None') }
						]
					},
					{
						type: 'selectbox',
						name: 'layout',
						label: label('layout', 'Place'),
						items: [
							{ value: '', text: label('layoutFull', 'Full width') },
							{ value: 'left', text: label('layoutLeft', 'Left of the text, half width') },
							{ value: 'right', text: label('layoutRight', 'Right of the text, half width') },
							{ value: 'center', text: label('layoutCenter', 'Centred, half width') }
						]
					},
					{ type: 'htmlpanel', html: '<p style="margin: 0.5em 0 0; font-size: 0.875em;">' + escapeHtml(label('hint')) + '</p>' }
				]
			},
			buttons: [
				{ type: 'cancel', text: label('cancel', 'Cancel') },
				{ type: 'submit', text: label('insert', 'Insert'), primary: true }
			],
			initialData: initial,
			onSubmit: function(api) {
				var tag = buildTag(api.getData(), existing);
				if(existing && existing.block.parentNode) {
					editor.undoManager.transact(function() {
						editor.dom.setHTML(existing.block, editor.dom.encode(tag));
					});
				} else {
					editor.insertContent('<p>' + editor.dom.encode(tag) + '</p>');
				}
				editor.nodeChanged();
				api.close();
			}
		});
	}

	function open(editor) {
		editor.setProgressState(true);
		loadVideos(editor).then(function(videos) {
			editor.setProgressState(false);
			showDialog(editor, videos);
		}, function() {
			editor.setProgressState(false);
			editor.windowManager.alert(label('error', 'The videos of this page could not be loaded.'));
		});
	}

	window.tinymce.PluginManager.add('pwselfhostedvideo', function(editor) {

		var text = label('button', 'Insert video');

		editor.ui.registry.addButton('pwselfhostedvideo', {
			icon: 'embed',
			tooltip: text,
			onAction: function() { open(editor); }
		});

		editor.ui.registry.addMenuItem('pwselfhostedvideo', {
			icon: 'embed',
			text: text + '…',
			onAction: function() { open(editor); }
		});

		return {
			getMetadata: function() {
				return { name: 'Video Files for ProcessWire' };
			}
		};
	});

})();
