(function () {
	'use strict';

	var bar, track, content;
	var isMarquee = false;
	var resizeTimer;

	function init() {
		bar = document.getElementById('uxstudio-top-bar');
		if (!bar) return;

		track = bar.querySelector('.uxstudio-top-bar__track');
		content = bar.querySelector('.uxstudio-top-bar__content');
		if (!track || !content) return;

		checkOverflow();

		window.addEventListener('resize', function () {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(checkOverflow, 200);
		});
	}

	function checkOverflow() {
		// Reset marquee state to measure naturally
		if (isMarquee) {
			bar.classList.remove('uxstudio-top-bar--marquee');
			var clone = track.querySelector('.uxstudio-top-bar__content--clone');
			if (clone) clone.remove();
			track.style.removeProperty('--uxstudio-tb-duration');
			isMarquee = false;
		}

		var barWidth = bar.offsetWidth;
		var contentWidth = content.scrollWidth;

		if (contentWidth > barWidth) {
			enableMarquee(contentWidth);
		}
	}

	function enableMarquee(contentWidth) {
		// Duplicate content for seamless loop
		var clone = content.cloneNode(true);
		clone.classList.add('uxstudio-top-bar__content--clone');
		clone.setAttribute('aria-hidden', 'true');
		track.appendChild(clone);

		// Calculate duration: ~60px per second
		var speed = 60;
		var duration = contentWidth / speed;
		bar.style.setProperty('--uxstudio-tb-duration', duration + 's');

		bar.classList.add('uxstudio-top-bar--marquee');
		isMarquee = true;
	}

	// Init on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
