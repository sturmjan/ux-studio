/**
 * External Permalinks - block editor document setting panel.
 */
(function () {
	'use strict';

	var config = window.uxStudioExternalPermalinks || {};
	if (!config.is_block_editor) {
		return;
	}

	if (typeof wp === 'undefined' || !wp.plugins || !wp.element || !wp.data) {
		return;
	}

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel) ||
		(wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	var TextControl = wp.components.TextControl;
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var select = wp.data.select;
	var dispatch = wp.data.dispatch;
	var i18n = config.i18n || {};

	if (!PluginDocumentSettingPanel) {
		return;
	}

	var Panel = function () {
		var postId = select('core/editor').getCurrentPostId();
		var meta = select('core/editor').getEditedPostAttribute('meta') || {};
		var state = useState(meta._links_to || '');
		var value = state[0];
		var setValue = state[1];

		useEffect(function () {
			var current = select('core/editor').getEditedPostAttribute('meta') || {};
			setValue(current._links_to || '');
		}, [postId]);

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'uxstudio-external-permalinks',
				title: i18n.panelTitle || 'External Permalink',
				className: 'uxstudio-external-permalinks-panel'
			},
			el(TextControl, {
				label: i18n.urlLabel || 'URL',
				help: i18n.urlHelp || 'Enter a full URL starting with https://',
				value: value,
				type: 'url',
				placeholder: 'https://',
				onChange: function (next) {
					setValue(next);
					dispatch('core/editor').editPost({ meta: { _links_to: next } });
				}
			})
		);
	};

	wp.domReady(function () {
		registerPlugin('uxstudio-external-permalinks', { render: Panel });
	});
})();
