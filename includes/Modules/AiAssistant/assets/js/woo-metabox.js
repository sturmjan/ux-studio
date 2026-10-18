(function () {
	'use strict';

	var cfg = window.uxStudioAiWoo;
	var desc = document.getElementById('uxstudio-ai-woo-desc');
	if (!cfg || !desc) {
		return;
	}

	var btn = document.getElementById('uxstudio-ai-woo-generate');
	var spinner = document.getElementById('uxstudio-ai-woo-spinner');
	var errorBox = document.getElementById('uxstudio-ai-woo-error');
	var result = document.getElementById('uxstudio-ai-woo-result');
	var shortBox = document.getElementById('uxstudio-ai-woo-short');
	var fullBox = document.getElementById('uxstudio-ai-woo-full');
	var label = btn.textContent;
	var data = null;

	// HTML -> plain text without ever executing it (DOMParser documents are inert).
	function toText(html) {
		return new DOMParser().parseFromString(html || '', 'text/html').body.textContent || '';
	}

	function setEditor(id, html) {
		var editor = window.tinymce && window.tinymce.get(id);
		if (editor && !editor.isHidden()) {
			editor.setContent(html);
		}
		var textarea = document.getElementById(id);
		if (textarea) {
			textarea.value = html;
		}
	}

	desc.addEventListener('input', function () {
		btn.disabled = desc.value.trim().length < 10;
	});

	btn.addEventListener('click', function () {
		btn.disabled = true;
		btn.textContent = cfg.i18n.generating;
		spinner.classList.add('is-active');
		errorBox.textContent = '';
		errorBox.style.color = '';

		fetch(cfg.url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify({
				description: desc.value,
				length: document.getElementById('uxstudio-ai-woo-length').value,
				tone: document.getElementById('uxstudio-ai-woo-tone').value
			})
		})
			.then(function (res) {
				return res.json().then(function (json) {
					if (!res.ok) {
						throw new Error(json && json.message ? json.message : cfg.i18n.failed);
					}
					return json;
				});
			})
			.then(function (json) {
				data = json.data || {};
				shortBox.textContent = toText(data.short_description);
				fullBox.textContent = toText(data.content);
				result.hidden = false;
			})
			.catch(function (e) {
				errorBox.textContent = e.message || cfg.i18n.failed;
			})
			.then(function () {
				btn.textContent = label;
				btn.disabled = desc.value.trim().length < 10;
				spinner.classList.remove('is-active');
			});
	});

	document.getElementById('uxstudio-ai-woo-apply').addEventListener('click', function () {
		if (!data) {
			return;
		}
		if (data.short_description) {
			setEditor('excerpt', data.short_description);
		}
		if (data.content) {
			setEditor('content', data.content);
		}
		errorBox.style.color = '#00a32a';
		errorBox.textContent = cfg.i18n.inserted;
	});

	document.getElementById('uxstudio-ai-woo-again').addEventListener('click', function () {
		result.hidden = true;
		errorBox.textContent = '';
		errorBox.style.color = '';
	});
})();
