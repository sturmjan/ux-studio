/* Post Gallery - Admin (Media Picker + Drag & Drop) */

(function () {
	'use strict';

	var grid = document.getElementById('uxstudio-gallery-grid');
	var addBtn = document.getElementById('uxstudio-gallery-add');
	var idsInput = document.getElementById('uxstudio-gallery-ids');

	if (!grid || !addBtn || !idsInput) return;

	var __ = (window.wp && wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };

	var mediaFrame = null;

	// Media Picker
	addBtn.addEventListener('click', function (e) {
		e.preventDefault();

		if (mediaFrame) {
			mediaFrame.open();
			return;
		}

		mediaFrame = wp.media({
			title: __('Select gallery images', 'ux-studio'),
			button: { text: __('Add to gallery', 'ux-studio') },
			multiple: true,
			library: { type: 'image' }
		});

		mediaFrame.on('select', function () {
			var selection = mediaFrame.state().get('selection');
			selection.each(function (attachment) {
				var data = attachment.toJSON();
				// Skip if already in gallery
				if (grid.querySelector('[data-id="' + data.id + '"]')) return;

				var thumb = data.sizes && data.sizes.thumbnail
					? data.sizes.thumbnail.url
					: data.url;

				var item = document.createElement('div');
				item.className = 'uxstudio-gallery-metabox__item';
				item.dataset.id = data.id;
				item.draggable = true;
				item.innerHTML = '<img src="' + thumb + '" alt="">'
					+ '<button type="button" class="uxstudio-gallery-metabox__remove" title="' + __('Remove', 'ux-studio') + '">&times;</button>';

				grid.appendChild(item);
			});
			updateIds();
		});

		mediaFrame.open();
	});

	// Remove
	grid.addEventListener('click', function (e) {
		var btn = e.target.closest('.uxstudio-gallery-metabox__remove');
		if (!btn) return;
		e.preventDefault();
		var item = btn.closest('.uxstudio-gallery-metabox__item');
		if (item) {
			item.remove();
			updateIds();
		}
	});

	// Drag & Drop Sorting
	var dragItem = null;

	grid.addEventListener('dragstart', function (e) {
		var item = e.target.closest('.uxstudio-gallery-metabox__item');
		if (!item) return;
		dragItem = item;
		item.classList.add('uxstudio-gallery-metabox__item--dragging');
		e.dataTransfer.effectAllowed = 'move';
	});

	grid.addEventListener('dragover', function (e) {
		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';
		var target = e.target.closest('.uxstudio-gallery-metabox__item');
		if (!target || target === dragItem) return;

		var rect = target.getBoundingClientRect();
		var midX = rect.left + rect.width / 2;
		if (e.clientX < midX) {
			grid.insertBefore(dragItem, target);
		} else {
			grid.insertBefore(dragItem, target.nextSibling);
		}
	});

	grid.addEventListener('dragend', function () {
		if (dragItem) {
			dragItem.classList.remove('uxstudio-gallery-metabox__item--dragging');
			dragItem = null;
			updateIds();
		}
	});

	// Update hidden input
	function updateIds() {
		var items = grid.querySelectorAll('.uxstudio-gallery-metabox__item');
		var ids = [];
		items.forEach(function (item) {
			ids.push(item.dataset.id);
		});
		idsInput.value = ids.join(',');
	}
})();
