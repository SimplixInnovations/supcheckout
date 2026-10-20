/**
 * CommandPalette: a keyboard-first way to reach any command.
 *
 * - PALETTE_SHORTCUT (Meta+K on macOS, Ctrl+K elsewhere) toggles it from
 *   anywhere on the page, and the trigger button in the AppShell header
 *   opens it. The shortcut is ignored during an IME composition and when an
 *   earlier handler already used the key (`defaultPrevented`), so an editor
 *   that binds Mod+K keeps it. The trigger announces it (`aria-keyshortcuts`).
 * - wp-admin has its own command palette (wp-commands) on the same keys,
 *   listening on `document`. The listener here sits on `document.body`: it
 *   runs after every element and React handler (so inputs keep first
 *   refusal) and before `document`, and it stops a handled Mod+K there, so
 *   on SUPCheckout screens the key opens one palette, this one.
 * - The palette is a modal Dialog in the portal layer. Its panel (Base UI
 *   Autocomplete and the search) is the lazy `command-palette` chunk: it
 *   loads on first open, so screens that never open it never pay for it.
 *   Until it arrives the dialog shows a placeholder and its Close button.
 * - Activating a command (Enter on the highlighted one, or a click) closes
 *   the palette and runs `perform` exactly once per opening; focus returns
 *   to where it was before the palette opened.
 *
 * The registry is the caller's: the palette never invents, fetches or
 * persists commands.
 */
import {
	lazy,
	Suspense,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { Dialog as BaseDialog } from '@base-ui/react/dialog';
import { __, _x } from '@wordpress/i18n';

import { Button } from '../controls/Button';
import { DialogFrame } from './Dialog';
import './CommandPalette.css';

export interface Command {
	id: string;
	/** Translated label; the search matches it. */
	label: string;
	/** Translated group heading, e.g. "Go to"; defaults to "Commands". */
	group?: string;
	/** Translated extra search terms, e.g. synonyms. */
	keywords?: readonly string[];
	/** Runs once when the command is chosen, after the palette closes. */
	perform: () => void;
}

export type ShortcutModifier = 'meta' | 'ctrl';

export interface PaletteShortcut {
	modifier: ShortcutModifier;
	/** The key, compared case-insensitively. */
	key: 'k';
	/** The value for `aria-keyshortcuts`. */
	ariaKeyShortcuts: 'Meta+K' | 'Control+K';
}

const APPLE_PLATFORM = /mac|iphone|ipad|ipod/i;

/**
 * @param platform `navigator.platform` (or the UA-CH platform).
 * @return Meta+K on Apple platforms, Ctrl+K elsewhere.
 */
export function paletteShortcut( platform: string ): PaletteShortcut {
	return APPLE_PLATFORM.test( platform )
		? { modifier: 'meta', key: 'k', ariaKeyShortcuts: 'Meta+K' }
		: { modifier: 'ctrl', key: 'k', ariaKeyShortcuts: 'Control+K' };
}

interface UserAgentDataNavigator {
	userAgentData?: { platform?: string };
}

function currentPlatform(): string {
	if ( typeof navigator === 'undefined' ) {
		return '';
	}
	const uaData = ( navigator as Navigator & UserAgentDataNavigator )
		.userAgentData;
	return uaData?.platform || navigator.platform || '';
}

/** The palette shortcut of this browser's platform. */
export const PALETTE_SHORTCUT: PaletteShortcut =
	paletteShortcut( currentPlatform() );

/** keyCode of keydown events sent while an IME composes. */
const IME_KEY_CODE = 229;

/**
 * @param event    A keydown event.
 * @param shortcut The shortcut to match.
 * @return Whether the event is exactly the shortcut, outside composition and
 *         not already handled.
 */
export function isPaletteShortcut(
	event: KeyboardEvent,
	shortcut: PaletteShortcut = PALETTE_SHORTCUT
): boolean {
	if (
		event.defaultPrevented ||
		event.isComposing ||
		event.keyCode === IME_KEY_CODE ||
		event.altKey ||
		event.shiftKey ||
		event.key.toLowerCase() !== shortcut.key
	) {
		return false;
	}
	return shortcut.modifier === 'meta'
		? event.metaKey && ! event.ctrlKey
		: event.ctrlKey && ! event.metaKey;
}

const Panel = lazy(
	() =>
		import(
			/* webpackChunkName: "command-palette" */ './CommandPalettePanel'
		)
);

export interface CommandPaletteProps {
	commands: readonly Command[];
}

export function CommandPalette( { commands }: CommandPaletteProps ) {
	const [ open, setOpen ] = useState( false );
	// One run per opening, whatever repeats (held Enter, a double click).
	const ran = useRef( false );

	const show = useCallback( ( next: boolean ) => {
		if ( next ) {
			ran.current = false;
		}
		setOpen( next );
	}, [] );

	useEffect( () => {
		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( ! isPaletteShortcut( event ) ) {
				return;
			}
			const target = event.target as HTMLElement | null;
			if ( target?.isContentEditable ) {
				return;
			}
			event.preventDefault();
			// One palette per key press: WordPress's listens on document.
			event.stopPropagation();
			setOpen( ( current ) => {
				if ( ! current ) {
					ran.current = false;
				}
				return ! current;
			} );
		};
		const { body } = document;
		body.addEventListener( 'keydown', onKeyDown );
		return () => body.removeEventListener( 'keydown', onKeyDown );
	}, [] );

	const run = useCallback( ( command: Command ) => {
		if ( ran.current ) {
			return;
		}
		ran.current = true;
		setOpen( false );
		command.perform();
	}, [] );

	const keyName =
		PALETTE_SHORTCUT.modifier === 'meta'
			? '⌘'
			: _x( 'Ctrl', 'keyboard key', 'supcheckout' );

	return (
		<>
			<Button
				className="sup-palette-trigger"
				iconStart="search"
				aria-haspopup="dialog"
				aria-keyshortcuts={ PALETTE_SHORTCUT.ariaKeyShortcuts }
				onClick={ () => show( true ) }
			>
				{ __( 'Search commands', 'supcheckout' ) }
				<kbd className="sup-kbd" dir="ltr" aria-hidden="true">
					{ keyName }
					<span className="sup-kbd__key">K</span>
				</kbd>
			</Button>
			<DialogFrame
				open={ open }
				size="md"
				className="sup-palette"
				onOpenChange={ ( next ) => show( next ) }
			>
				<BaseDialog.Title className="sup-visually-hidden">
					{ __( 'Command palette', 'supcheckout' ) }
				</BaseDialog.Title>
				<Suspense
					fallback={
						<div
							className="sup-palette__fallback"
							aria-hidden="true"
						/>
					}
				>
					<Panel commands={ commands } onRun={ run } />
				</Suspense>
				<div className="sup-palette__bar">
					<BaseDialog.Close className="sup-palette__close">
						{ __( 'Close', 'supcheckout' ) }
						<kbd className="sup-kbd" dir="ltr" aria-hidden="true">
							{ _x( 'Esc', 'keyboard key', 'supcheckout' ) }
						</kbd>
					</BaseDialog.Close>
				</div>
			</DialogFrame>
		</>
	);
}
