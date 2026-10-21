/**
 * Video Files: button for the rich text editor CKEditor
 *
 * The same as pwselfhostedvideo.js does for TinyMCE: a button "Insert video" that asks the server
 * for the videos of the page being edited, lets the editor choose one, and puts a tag such as
 * [[video intro.mp4]] into the text. On the website the text formatter "Videos in text" shows
 * the video in its place. With the cursor on a tag, the button changes that tag instead.
 *
 * The button is added by the module to the editor of every text field that has the text
 * formatter. Its name for toolbar settings is "PWSelfHostedVideo".
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 */
(function() {
	'use strict';

	// Everything the scripts of this module share with the page is kept under one name,
	// FieldtypeSelfHostedVideo, so that nothing of it can meet a name of another script.
	var ns = window.FieldtypeSelfHostedVideo = window.FieldtypeSelfHostedVideo || {};

	// the plugin is added to the editor once, however often the page loads this file
	if(ns.ckeditor) return;
	ns.ckeditor = {};

	var TAG = /^\s*\[\[video\s+([^\]\s]+)([^\]]*)\]\]\s*$/i;

	function settings() {
		var config = window.ProcessWire && ProcessWire.config ? ProcessWire.config.FieldtypeSelfHostedVideo : null;
		return config || { labels: {}, editUrl: '' };
	}

	function label(name, fallback) {
		var labels = settings().labels || {};
		return labels[name] || fallback || '';
	}

	/**
	 * The page being edited, and the repeater item the text is in, if it is in one
	 */
	function pageIds(editor) {
		var element = editor.element ? editor.element.$ : null;
		var input = document.getElementById('Inputfield_id');
		var wrap = element && element.closest ? element.closest('.Inputfield') : null;
		var item = element && element.closest ? element.closest('.InputfieldRepeaterItem') : null;
		return {
			page: input && input.value ? input.value : (wrap ? wrap.getAttribute('data-pid') || '' : ''),
			item: item ? item.getAttribute('data-page') || '' : ''
		};
	}

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
		var selection = editor.getSelection();
		var start = selection ? selection.getStartElement() : null;
		var block = start ? start.getAscendant('p', true) : null;
		if(!block) return null;
		var match = TAG.exec(block.getText() || '');
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

	/**
	 * Where the cursor is in its paragraph: in an empty one, at its end, at its start, or elsewhere (null)
	 */
	function currentPlace(editor) {
		var selection = editor.getSelection();
		var ranges = selection ? selection.getRanges() : [];
		var start = selection ? selection.getStartElement() : null;
		var block = start ? start.getAscendant('p', true) : null;
		if(!block || !ranges.length || !ranges[0].collapsed) return null;
		var text = (block.getText() || '').replace(/[\s\u00a0\u200b]/g, '');
		var where = null;
		if(!text.length) {
			where = 'empty';
		} else if(ranges[0].checkEndOfBlock()) {
			where = 'end';
		} else if(ranges[0].checkStartOfBlock()) {
			where = 'start';
		}
		return where ? { block: block, where: where } : null;
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

	CKEDITOR.dialog.add('pwselfhostedvideoDialog', function(editor) {
		return {
			title: label('title', 'Insert a video of this page'),
			minWidth: 380,
			minHeight: 160,
			contents: [ {
				id: 'main',
				elements: [
					{ type: 'select', id: 'video', label: label('video', 'Video'), items: [ [ '' ] ] },
					{
						type: 'select',
						id: 'caption',
						label: label('caption', 'Text below the video'),
						items: [
							[ label('captionBoth', 'Title, duration and description'), '' ],
							[ label('captionTitle', 'Title'), 'title' ],
							[ label('captionNone', 'None'), 'none' ]
						]
					},
					{
						type: 'select',
						id: 'layout',
						label: label('layout', 'Place'),
						items: [
							[ label('layoutFull', 'Full width'), '' ],
							[ label('layoutLeft', 'Left of the text, half width'), 'left' ],
							[ label('layoutRight', 'Right of the text, half width'), 'right' ],
							[ label('layoutCenter', 'Centred, half width'), 'center' ]
						]
					},
					{ type: 'html', html: '<p style="white-space:normal;max-width:26em;margin-top:0.5em">' + CKEDITOR.tools.htmlEncode(label('hint')) + '</p>' }
				]
			} ],
			onShow: function() {
				var videos = editor._pwselfhostedvideo ? editor._pwselfhostedvideo.videos : [];
				var existing = editor._pwselfhostedvideo ? editor._pwselfhostedvideo.existing : null;
				var select = this.getContentElement('main', 'video');
				var chosen = videos.length ? videos[0].name : '';

				select.clear();
				for(var i = 0; i < videos.length; i++) {
					var video = videos[i];
					select.add(video.title === video.name ? video.facts : video.title + ' (' + video.facts + ')', video.name);
					if(existing && video.name.toLowerCase() === existing.name.toLowerCase()) chosen = video.name;
				}

				this.setValueOf('main', 'video', chosen);
				this.setValueOf('main', 'caption', existing ? existing.caption : '');
				this.setValueOf('main', 'layout', existing ? existing.layout : '');
			},
			onOk: function() {
				var existing = editor._pwselfhostedvideo ? editor._pwselfhostedvideo.existing : null;
				var tag = buildTag({
					video: this.getValueOf('main', 'video'),
					caption: this.getValueOf('main', 'caption'),
					layout: this.getValueOf('main', 'layout')
				}, existing);

				editor.fire('saveSnapshot');
				if(existing && existing.block.getParent()) {
					existing.block.setText(tag);
				} else {
					// A paragraph of its own: in an empty paragraph the tag takes its place, at the end or
					// the start of a paragraph it goes after or before it, and anywhere else the editor
					// splits the paragraph around it.
					var place = editor._pwselfhostedvideo ? editor._pwselfhostedvideo.place : null;
					var paragraph = editor.document.createElement('p');
					paragraph.setText(tag);
					if(place && place.block.getParent() && place.where === 'empty') {
						place.block.setText(tag);
						paragraph = place.block;
					} else if(place && place.block.getParent() && place.where === 'end') {
						paragraph.insertAfter(place.block);
					} else if(place && place.block.getParent() && place.where === 'start') {
						paragraph.insertBefore(place.block);
					} else {
						editor.insertElement(paragraph);
					}
					var range = editor.createRange();
					range.moveToElementEditEnd(paragraph);
					editor.getSelection().selectRanges([ range ]);
				}
				editor.fire('saveSnapshot');
				editor.fire('change');
			}
		};
	});

	CKEDITOR.plugins.add('pwselfhostedvideo', {

		requires: 'dialog',

		init: function(editor) {

			editor.addCommand('pwselfhostedvideo', {
				// a tag is plain text: nothing the content filter of the editor has to allow
				exec: function(editor) {
					// where the cursor is has to be read before anything else takes the focus
					var existing = currentTag(editor);
					var place = existing ? null : currentPlace(editor);
					loadVideos(editor).then(function(videos) {
						if(!videos.length) {
							window.alert(label('none', 'This page has no videos yet.'));
							return;
						}
						editor._pwselfhostedvideo = { videos: videos, existing: existing, place: place };
						editor.openDialog('pwselfhostedvideoDialog');
					}, function() {
						window.alert(label('error', 'The videos of this page could not be loaded.'));
					});
				}
			});

			if(editor.ui.addButton) {
				editor.ui.addButton('PWSelfHostedVideo', {
					label: label('button', 'Insert video'),
					command: 'pwselfhostedvideo',
					toolbar: 'insert,90',
					icon: this.path + 'pwselfhostedvideo-ckeditor.svg'
				});
			}
		}
	});

})();
