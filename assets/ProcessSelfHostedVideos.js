/**
 * Video Files: the overview of all videos (Setup > Video Manager)
 *
 * The bar above the list: "Select all on this page", how many are selected, and the actions
 * for them, shown once videos are selected. The button says what it will do, and the tag
 * field is only there for the actions that need it. Without this script all of it is there
 * and works too.
 *
 * This file is part of the FieldtypeSelfHostedVideo module for ProcessWire 3.x
 */
(function() {
	'use strict';

	function start() {
		var form = document.getElementById('ProcessSelfHostedVideosBulkForm');
		if(!form) return;
		var bar = form.querySelector('.ProcessSelfHostedVideosBar');
		var all = form.querySelector('[data-shv-select-all]');
		var boxes = form.querySelectorAll('.ProcessSelfHostedVideosSelectBox');
		var action = form.querySelector('.ProcessSelfHostedVideosBulkAction');
		var tag = form.querySelector('.ProcessSelfHostedVideosBulkTag');
		var apply = form.querySelector('.ProcessSelfHostedVideosBulkApply');
		var applyText = form.querySelector('.ProcessSelfHostedVideosBulkApplyText');
		var allOf = form.querySelector('.ProcessSelfHostedVideosBulkAll');
		var summary = bar ? bar.querySelector('.ProcessSelfHostedVideosSummary') : null;
		var selected = bar ? bar.querySelector('.ProcessSelfHostedVideosSelected') : null;
		var clear = bar ? bar.querySelector('.ProcessSelfHostedVideosClear') : null;
		var hint = bar ? bar.querySelector('.ProcessSelfHostedVideosBarHint') : null;
		if(!bar) return;

		function sync() {
			var checked = 0;
			for(var i = 0; i < boxes.length; i++) if(boxes[i].checked) checked++;
			var on = checked > 0;
			if(all) {
				all.checked = on && checked === boxes.length;
				all.indeterminate = on && checked < boxes.length;
			}
			bar.classList.toggle('ProcessSelfHostedVideosBarSelecting', on);
			if(summary) summary.hidden = on;
			if(hint) hint.hidden = on || !boxes.length;
			if(clear) clear.hidden = !on;
			if(selected) {
				selected.hidden = !on;
				var text = on ? bar.getAttribute('data-selected').replace('%1$d', checked).replace('%2$d', boxes.length) : '';
				if(selected.textContent !== text) selected.textContent = text;
			}
			// "all of this choice" only once the whole page is selected
			if(allOf) {
				var whole = on && checked === boxes.length;
				allOf.hidden = !whole;
				if(!whole) allOf.querySelector('input').checked = false;
			}
		}

		function showAction() {
			var value = action ? action.value : '';
			if(tag) {
				var wanted = value === 'tag' || value === 'untag';
				tag.hidden = !wanted;
				var input = tag.querySelector('input');
				if(input) input.required = wanted;
			}
			if(apply && applyText) {
				// the button says what it will do
				var option = action && action.selectedIndex > 0 ? action.options[action.selectedIndex] : null;
				applyText.textContent = option ? option.textContent : bar.getAttribute('data-apply');
				apply.classList.toggle('ProcessSelfHostedVideosBulkApplyReady', !!option);
				apply.classList.toggle('ProcessSelfHostedVideosBulkApplyDanger', value === 'delete');
			}
		}

		bar.classList.add('ProcessSelfHostedVideosBarReady');

		if(all && boxes.length) {
			all.hidden = false;
			all.closest('label').classList.add('ProcessSelfHostedVideosSelectAllReady');
			all.addEventListener('change', function() {
				for(var i = 0; i < boxes.length; i++) boxes[i].checked = all.checked;
				sync();
			});
			for(var i = 0; i < boxes.length; i++) boxes[i].addEventListener('change', sync);
		} else if(all) {
			all.closest('label').hidden = true;
		}

		if(clear) clear.addEventListener('click', function() {
			for(var i = 0; i < boxes.length; i++) boxes[i].checked = false;
			sync();
			if(all && !all.hidden) all.focus();
		});

		if(action) action.addEventListener('change', showAction);
		showAction();
		sync();
	}

	function ready() {
		start();
		charts();
	}

	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready);
	} else {
		ready();
	}

	/**
	 * The chart of plays: a column says its day and number while the pointer is on it
	 * (the same numbers are in the table below the chart)
	 */
	function charts() {
		var areas = document.querySelectorAll('.ProcessSelfHostedVideosChartArea');
		Array.prototype.forEach.call(areas, function(area) {
			var tip = area.querySelector('.ProcessSelfHostedVideosChartTip');
			var active = null;
			if(!tip) return;
			function hide() {
				tip.hidden = true;
				if(active) active.classList.remove('is-active');
				active = null;
			}
			area.addEventListener('pointermove', function(e) {
				var hit = e.target.closest ? e.target.closest('.ProcessSelfHostedVideosChartHit') : null;
				if(!hit) { hide(); return; }
				if(hit !== active) {
					if(active) active.classList.remove('is-active');
					active = hit;
					hit.classList.add('is-active');
					tip.textContent = hit.getAttribute('data-tip');
				}
				var box = area.getBoundingClientRect();
				var col = hit.getBoundingClientRect();
				var x = col.left + col.width / 2 - box.left;
				var half = tip.offsetWidth / 2;
				// where the chart scrolls sideways (on a phone), the tip moves with it
				tip.style.left = (Math.max(half, Math.min(box.width - half, x)) + area.scrollLeft) + 'px';
				tip.style.top = '-2.2em';
				tip.hidden = false;
			});
			area.addEventListener('pointerleave', hide);
			// the newest days first in view
			if(area.scrollWidth > area.clientWidth) area.scrollLeft = area.scrollWidth;
			// the browser's own tooltip would say it a second time
			Array.prototype.forEach.call(area.querySelectorAll('.ProcessSelfHostedVideosChartHit title'), function(t) { t.parentNode.removeChild(t); });
		});
	}

})();
