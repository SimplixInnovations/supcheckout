/**
 * CommandPalette: a keyboard-first way to reach any command.
 *
 * - The trigger button in the AppShell header always opens it.
 * - Mod+K (PALETTE_SHORTCUT: Meta+K on macOS, Ctrl+K elsewhere) belongs to
 *   WordPress core's command palette (wp-commands) wherever core loads it:
 *   the SUPCheckout palette then binds no key and announces none, and
 *   Sub-project 2 registers SUPCheckout commands into core's palette
 *   (`wp.data.dispatch( 'core/commands' ).registerCommand()`). Only where
 *   core's palette is absent does Mod+K toggle this one. Core is detected
 *   at runtime (`wp.commands` or the `core/commands` store); nothing of it
 *   is bundled or declared as a dependency.
 * - The binding listens on `document.body`, after every element and React
 *   handler, so an editor that binds Mod+K keeps it (`defaultPrevented`);
 *   it never stops propagation. It ignores IME composition, matches the
 *   physical K key on other layouts (`code`), and never opens the palette
 *   over another modal (a pending confirmation, any dialog): the trigger
 *   is then behind that modal (`inert` / `aria-hidden`).
 * - The palette is a modal Dialog in the portal layer. Its panel (Base UI
 *   Autocomplete and the search) is the lazy `command-palette` chunk: it
 *   loads on first open, so screens that never open it never pay for it.
 *   Until it arrives the dialog shows a placeholder and its Close button.
 * - Choosing a command (Enter on the highlighted one, or a click) closes
 *   the palette; `perform` runs exactly once per opening, after the close
 *   transition has finished, and focus has returned to where it was before
 *   the palette opened.
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
	/** The physical key, for layouts where K types another letter. */
	code: 'KeyK';
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
		? {
				modifier: 'meta',
				key: 'k',
				code: 'KeyK',
				ariaKeyShortcuts: 'Meta+K',
			}
		: {
				modifier: 'ctrl',
				key: 'k',
				code: 'KeyK',
				ariaKeyShortcuts: 'Control+K',
			};
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
	const key = typeof event.key === 'string' ? event.key.toLowerCase() : '';
	if (
		event.defaultPrevented ||
		event.isComposing ||
		event.keyCode === IME_KEY_CODE ||
		event.altKey ||
		event.shiftKey ||
		( key !== shortcut.key && event.code !== shortcut.code )
	) {
		return false;
	}
	return shortcut.modifier === 'meta'
		? event.metaKey && ! event.ctrlKey
		: event.ctrlKey && ! event.metaKey;
}

interface WordPressGlobal {
	wp?: {
		commands?: unknown;
		data?: { select?: ( store: string ) => unknown };
	};
}

/**
 * @return Whether WordPress core's command palette is on this page.
 */
export function hasCoreCommandPalette(): boolean {
	if ( typeof window === 'undefined' ) {
		return false;
	}
	const { wp } = window as unknown as WordPressGlobal;
	if ( wp?.commands ) {
		return true;
	}
	try {
		return Boolean( wp?.data?.select?.( 'core/commands' ) );
	} catch {
		// An unregistered store may throw: core's palette is not here.
		return false;
	}
}

/** Somewhere a modal has made the page behind it unreachable. */
const BEHIND_MODAL = '[inert], [aria-hidden="true"]';

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
	const [ bindShortcut ] = useState( () => ! hasCoreCommandPalette() );
	const isOpen = useRef( false );
	const triggerRef = useRef< HTMLButtonElement >( null );
	// One run per opening, whatever repeats (held Enter, a double click).
	const ran = useRef( false );
	// The chosen command, run once the palette has finished closing.
	const queued = useRef< Command | null >( null );

	const show = useCallback( ( next: boolean ) => {
		if ( next ) {
			ran.current = false;
			queued.current = null;
		}
		isOpen.current = next;
		setOpen( next );
	}, [] );

	useEffect( () => {
		if ( ! bindShortcut ) {
			return undefined;
		}
		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( ! isPaletteShortcut( event ) ) {
				return;
			}
			const target = event.target as HTMLElement | null;
			if ( target?.isContentEditable ) {
				return;
			}
			// Another modal is open (the palette's own is handled above):
			// never open over it, and leave the key alone.
			if (
				! isOpen.current &&
				triggerRef.current?.closest( BEHIND_MODAL )
			) {
				return;
			}
			event.preventDefault();
			show( ! isOpen.current );
		};
		const { body } = document;
		body.addEventListener( 'keydown', onKeyDown );
		return () => body.removeEventListener( 'keydown', onKeyDown );
	}, [ bindShortcut, show ] );

	const run = useCallback(
		( command: Command ) => {
			if ( ran.current ) {
				return;
			}
			ran.current = true;
			queued.current = command;
			show( false );
		},
		[ show ]
	);

	const afterTransition = useCallback( ( next: boolean ) => {
		if ( next ) {
			return;
		}
		const command = queued.current;
		queued.current = null;
		command?.perform();
	}, [] );

	const keyName =
		PALETTE_SHORTCUT.modifier === 'meta'
			? '⌘'
			: _x( 'Ctrl', 'keyboard key', 'supcheckout' );

	return (
		<>
			<Button
				ref={ triggerRef }
				className="sup-palette-trigger"
				iconStart="search"
				aria-haspopup="dialog"
				aria-keyshortcuts={
					bindShortcut ? PALETTE_SHORTCUT.ariaKeyShortcuts : undefined
				}
				onClick={ () => show( true ) }
			>
				{ __( 'Search commands', 'supcheckout' ) }
				{ bindShortcut && (
					<kbd className="sup-kbd" dir="ltr" aria-hidden="true">
						{ keyName }
						<span className="sup-kbd__key">K</span>
					</kbd>
				) }
			</Button>
			<DialogFrame
				open={ open }
				size="md"
				className="sup-palette"
				onOpenChange={ ( next ) => show( next ) }
				onOpenChangeComplete={ afterTransition }
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
