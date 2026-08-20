/**
 * SPA entry: mounts the app into #ux-studio-root on the UX Studio admin page.
 */
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { App } from './app/App';
import { queryClient } from './app/api';
import { apply as applyTheme } from './app/prefs';
import './style.scss';

const el = document.getElementById( 'ux-studio-root' );
if ( el ) {
	applyTheme();
	createRoot( el ).render(
		<QueryClientProvider client={ queryClient }>
			<App />
		</QueryClientProvider>
	);
}
