/**
 * WordPress' own editor (TinyMCE + Text tab + "Add Media") for the message of an e-mail action. The textarea is
 * created by hand inside an empty container, because wp.editor.initialize() wraps it in its own markup and React
 * must never own a node that is moved around by someone else.
 */
import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';
import { __ } from '@wordpress/i18n';
import { tinymceEditor, wpEditorApi } from './wpGlobals';

export interface WpEditorHandle {
	/** Insert text (e.g. a merge tag) at the cursor, in the visual and in the text tab. */
	insertText: ( text: string ) => void;
}

interface Props {
	id: string;
	value: string;
	onChange: ( html: string ) => void;
}

const BLOCK_FORMATS = 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4';

const WpEditor = forwardRef< WpEditorHandle, Props >( function WpEditor( { id, value, onChange }, ref ) {
	const container = useRef< HTMLDivElement >( null );
	const changeRef = useRef( onChange );
	const lastSeen = useRef( value );
	const valueRef = useRef( value );
	const ready = useRef( false );

	changeRef.current = onChange;
	valueRef.current = value;

	useImperativeHandle( ref, () => ( {
		insertText: ( text: string ) => {
			const editor = tinymceEditor( id );
			if ( editor && ! editor.isHidden() ) {
				editor.focus();
				editor.insertContent( text );
				return;
			}
			const textarea = document.getElementById( id );
			if ( textarea instanceof HTMLTextAreaElement ) {
				const start = textarea.selectionStart;
				const end = textarea.selectionEnd;
				textarea.setRangeText( text, start, end, 'end' );
				textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				textarea.focus();
			}
		},
	} ), [ id ] );

	useEffect( () => {
		const host = container.current;
		const api = wpEditorApi();
		if ( ! host || ! api ) {
			return undefined;
		}

		const textarea = document.createElement( 'textarea' );
		textarea.id = id;
		textarea.value = valueRef.current;
		textarea.rows = 10;
		host.appendChild( textarea );

		function emit( html: string ): void {
			if ( html !== lastSeen.current ) {
				lastSeen.current = html;
				changeRef.current( html );
			}
		}

		api.initialize( id, {
			tinymce: {
				wpautop: true,
				height: 260,
				menubar: false,
				toolbar1: 'formatselect,bold,italic,underline,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
				toolbar2: '',
				block_formats: BLOCK_FORMATS,
				setup: ( editor: { on: ( ev: string, cb: () => void ) => void; getContent: () => string } ) => {
					editor.on( 'init', () => {
						// What TinyMCE makes of the stored HTML is the baseline: only a real edit marks the form as changed.
						lastSeen.current = editor.getContent();
						ready.current = true;
					} );
					editor.on( 'change keyup input undo redo', () => emit( editor.getContent() ) );
				},
			},
			quicktags: { buttons: 'strong,em,link,ul,ol,li,img,close' },
			mediaButtons: true,
		} );

		const onText = (): void => emit( textarea.value );
		textarea.addEventListener( 'input', onText );

		return () => {
			textarea.removeEventListener( 'input', onText );
			try {
				api.remove( id );
			} catch {
				// The editor was never fully built (module removed before init finished).
			}
			host.innerHTML = '';
			ready.current = false;
		};
	}, [ id ] );

	// A different action took this slot (an earlier one was removed), or the value was replaced from outside.
	useEffect( () => {
		if ( value === lastSeen.current ) {
			return;
		}
		lastSeen.current = value;
		const editor = tinymceEditor( id );
		if ( editor ) {
			editor.setContent( value );
		}
		const textarea = document.getElementById( id );
		if ( textarea instanceof HTMLTextAreaElement ) {
			textarea.value = value;
		}
	}, [ value, id ] );

	if ( ! wpEditorApi() ) {
		return (
			<textarea
				id={ id }
				rows={ 8 }
				value={ value }
				aria-label={ __( 'Message', 'ux-studio' ) }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		);
	}

	return <div ref={ container } className="uxs-wp-editor" />;
} );

export default WpEditor;
