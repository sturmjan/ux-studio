/**
 * Typed access to the WordPress globals the e-mail editor uses: `wp.editor` (TinyMCE with the media buttons),
 * `wp.media` (Media Library modal) and `tinymce`. They are loaded by PHP (wp_enqueue_editor() from the Forms
 * module, wp_enqueue_media() from Core\Admin), not bundled.
 */

export interface WpAttachment {
	id: number;
	url: string;
	sizes?: Record< string, { url: string } >;
}

interface WpMediaFrame {
	open: () => void;
	on: ( event: string, callback: () => void ) => void;
	state: () => { get: ( key: string ) => { first: () => { toJSON: () => WpAttachment } } };
}

export interface TinyMceEditor {
	getContent: () => string;
	setContent: ( html: string ) => void;
	insertContent: ( html: string ) => void;
	isHidden: () => boolean;
	on: ( events: string, callback: () => void ) => void;
	focus: () => void;
}

export interface WpEditorSettings {
	tinymce: Record< string, unknown > | boolean;
	quicktags: Record< string, unknown > | boolean;
	mediaButtons: boolean;
}

interface WpGlobal {
	media?: ( options: Record< string, unknown > ) => WpMediaFrame;
	editor?: {
		initialize: ( id: string, settings: WpEditorSettings ) => void;
		remove: ( id: string ) => void;
	};
}

interface WindowWithWp {
	wp?: WpGlobal;
	tinymce?: { get: ( id: string ) => TinyMceEditor | null };
}

const w = (): WindowWithWp => window as unknown as WindowWithWp;

export function wpEditorApi(): WpGlobal[ 'editor' ] | undefined {
	return w().wp?.editor;
}

export function tinymceEditor( id: string ): TinyMceEditor | null {
	return w().tinymce?.get( id ) ?? null;
}

/** Opens the Media Library for one image and resolves with it (null when the modal is closed without a choice). */
export function pickImage( title: string ): Promise< WpAttachment | null > {
	return new Promise( ( resolve ) => {
		const media = w().wp?.media;
		if ( ! media ) {
			resolve( null );
			return;
		}
		const frame = media( { title, multiple: false, library: { type: 'image' } } );
		let chosen = false;
		frame.on( 'select', () => {
			chosen = true;
			resolve( frame.state().get( 'selection' ).first().toJSON() );
		} );
		// WordPress fires "close" first and "select" right after it, so give "select" a tick before giving up.
		frame.on( 'close', () => {
			window.setTimeout( () => {
				if ( ! chosen ) {
					resolve( null );
				}
			}, 0 );
		} );
		frame.open();
	} );
}
