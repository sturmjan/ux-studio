/**
 * Static registry of group-C modules that ship their own SPA screen instead of
 * the generic settings renderer. Each entry lazy-loads src/modules/<id>/Page.tsx
 * (default export) - only fetched when its route is visited (code-splitting).
 *
 * A module with a custom page still gets settings for free by embedding
 * <SettingsFields> + useModuleSettings() from ../app/SettingsForm inside its
 * own tabs (see any existing Page.tsx for the pattern).
 */
import { lazy } from 'react';

export const MODULE_PAGES: Record< string, ReturnType< typeof lazy > > = {
	'admin-columns': lazy( () => import( './admin-columns/Page' ) ),
	'admin-customiser': lazy( () => import( './admin-customiser/Page' ) ),
	'smtp-email': lazy( () => import( './smtp-email/Page' ) ),
	'security-optimization': lazy( () => import( './security-optimization/Page' ) ),
	'code-snippets': lazy( () => import( './code-snippets/Page' ) ),
	'content-sync': lazy( () => import( './content-sync/Page' ) ),
	'instagram-feed': lazy( () => import( './instagram-feed/Page' ) ),
	'review-aggregator': lazy( () => import( './review-aggregator/Page' ) ),
	'service-requests': lazy( () => import( './service-requests/Page' ) ),
	'dashboard-widgets': lazy( () => import( './dashboard-widgets/Page' ) ),
	'activity-log': lazy( () => import( './activity-log/Page' ) ),
	'email-log': lazy( () => import( './email-log/Page' ) ),
	'rollback-manager': lazy( () => import( './rollback-manager/Page' ) ),
	'folder-manager': lazy( () => import( './folder-manager/Page' ) ),
	'download-files': lazy( () => import( './download-files/Page' ) ),
	'elementor-import': lazy( () => import( './elementor-import/Page' ) ),
	'cron-control': lazy( () => import( './cron-control/Page' ) ),
	'exit-popup': lazy( () => import( './exit-popup/Page' ) ),
	'bot-throttle': lazy( () => import( './bot-throttle/Page' ) ),
	'vulnerability-scanner': lazy( () => import( './vulnerability-scanner/Page' ) ),
	'third-party-login': lazy( () => import( './third-party-login/Page' ) ),
	'google-review-request': lazy( () => import( './google-review-request/Page' ) ),
	'page-load': lazy( () => import( './page-load/Page' ) ),
	'popup-manager': lazy( () => import( './popup-manager/Page' ) ),
	'stock-photos': lazy( () => import( './stock-photos/Page' ) ),
	'opening-hours': lazy( () => import( './opening-hours/Page' ) ),
	'ai-markdown': lazy( () => import( './ai-markdown/Page' ) ),
	'notice-board': lazy( () => import( './notice-board/Page' ) ),
	'image-optimizer': lazy( () => import( './image-optimizer/Page' ) ),
	'push-notifications': lazy( () => import( './push-notifications/Page' ) ),
	'performance-optimization': lazy( () => import( './performance-optimization/Page' ) ),
	'ai-panel': lazy( () => import( './ai-panel/Page' ) ),
	'file-manager': lazy( () => import( './file-manager/Page' ) ),
	'ai-assistant': lazy( () => import( './ai-assistant/Page' ) ),
};
