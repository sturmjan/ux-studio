/* Post Gallery - Frontend Lightbox */

(function () {
	'use strict';

	var galleries = document.querySelectorAll('.uxstudio-gallery[data-lightbox="1"]');
	if (!galleries.length) return;

	var overlay = null;
	var imgEl = null;
	var counterEl = null;
	var items = [];
	var current = 0;
	var startX = 0;
	var isDragging = false;

	function build() {
		overlay = document.createElement('div');
		overlay.className = 'uxstudio-lightbox';
		overlay.innerHTML =
			'<div class="uxstudio-lightbox__toolbar">'
				+ '<span class="uxstudio-lightbox__counter"></span>'
				+ '<button class="uxstudio-lightbox__btn uxstudio-lightbox__close" aria-label="Close">'
					+ '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
				+ '</button>'
			+ '</div>'
			+ '<button class="uxstudio-lightbox__nav uxstudio-lightbox__prev" aria-label="Previous">'
				+ '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>'
			+ '</button>'
			+ '<button class="uxstudio-lightbox__nav uxstudio-lightbox__next" aria-label="Next">'
				+ '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>'
			+ '</button>'
			+ '<div class="uxstudio-lightbox__stage">'
				+ '<img class="uxstudio-lightbox__img" src="" alt="">'
			+ '</div>';
		document.body.appendChild(overlay);

		imgEl = overlay.querySelector('.uxstudio-lightbox__img');
		counterEl = overlay.querySelector('.uxstudio-lightbox__counter');

		overlay.querySelector('.uxstudio-lightbox__close').addEventListener('click', close);
		overlay.querySelector('.uxstudio-lightbox__prev').addEventListener('click', function () { navigate(-1); });
		overlay.querySelector('.uxstudio-lightbox__next').addEventListener('click', function () { navigate(1); });

		overlay.querySelector('.uxstudio-lightbox__stage').addEventListener('click', function (e) {
			if (e.target === this) close();
		});

		overlay.addEventListener('touchstart', function (e) {
			startX = e.touches[0].clientX;
			isDragging = true;
		}, { passive: true });

		overlay.addEventListener('touchend', function (e) {
			if (!isDragging) return;
			isDragging = false;
			var diff = e.changedTouches[0].clientX - startX;
			if (Math.abs(diff) > 50) {
				navigate(diff > 0 ? -1 : 1);
			}
		}, { passive: true });
	}

	function open(gallery, index) {
		items = gallery.querySelectorAll('[data-uxstudio-lightbox]');
		current = index;

		if (!overlay) build();

		show();
		requestAnimationFrame(function () {
			overlay.classList.add('uxstudio-lightbox--open');
		});
		document.body.style.overflow = 'hidden';
	}

	function close() {
		if (!overlay) return;
		overlay.classList.remove('uxstudio-lightbox--open');
		document.body.style.overflow = '';
	}

	function navigate(dir) {
		current = (current + dir + items.length) % items.length;
		show();
	}

	function show() {
		var link = items[current];
		if (!link) return;

		imgEl.classList.add('uxstudio-lightbox__img--loading');
		var img = new Image();
		img.onload = function () {
			imgEl.src = link.href;
			imgEl.alt = link.querySelector('img') ? link.querySelector('img').alt : '';
			imgEl.classList.remove('uxstudio-lightbox__img--loading');
		};
		img.src = link.href;

		counterEl.textContent = (current + 1) + ' / ' + items.length;

		var hasMult = items.length > 1;
		overlay.querySelector('.uxstudio-lightbox__prev').style.display = hasMult ? '' : 'none';
		overlay.querySelector('.uxstudio-lightbox__next').style.display = hasMult ? '' : 'none';

		var prev = (current - 1 + items.length) % items.length;
		var next = (current + 1) % items.length;
		if (items[prev]) new Image().src = items[prev].href;
		if (items[next]) new Image().src = items[next].href;
	}

	// Keyboard
	document.addEventListener('keydown', function (e) {
		if (!overlay || !overlay.classList.contains('uxstudio-lightbox--open')) return;
		if (e.key === 'Escape') close();
		if (e.key === 'ArrowLeft') navigate(-1);
		if (e.key === 'ArrowRight') navigate(1);
	});

	// CAPTURE phase click - fires BEFORE any other handler (Elementor, WoodMart, etc.)
	document.addEventListener('click', function (e) {
		var link = e.target.closest('[data-uxstudio-lightbox]');
		if (!link) return;

		var gallery = link.closest('.uxstudio-gallery[data-lightbox="1"]');
		if (!gallery) return;

		e.preventDefault();
		e.stopPropagation();
		e.stopImmediatePropagation();

		var allLinks = gallery.querySelectorAll('[data-uxstudio-lightbox]');
		var index = Array.prototype.indexOf.call(allLinks, link);
		open(gallery, index >= 0 ? index : 0);
	}, true);
})();
