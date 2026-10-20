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
 *   at runtime (`wp.coreCommands`, or commands registered in the
 *   `core/commands` store: see hasCoreCommandPalette); nothing of it is
 *   bundled or declared as a dependency. While any SUPCheckout modal is
 *   open, the modal frame marks Mod+K handled so core's palette (which
 *   bails on a handled key) never opens over it.
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
import {
	hasCoreCommandPalette,
	isPaletteShortcut,
	matchesPaletteKeys,
	PALETTE_SHORTCUT,
} from './shortcut';
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

export {
	hasCoreCommandPalette,
	isPaletteShortcut,
	matchesPaletteKeys,
	PALETTE_SHORTCUT,
	paletteShortcut,
} from './shortcut';
export type { PaletteShortcut, ShortcutModifier } from './shortcut';

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
			// The open palette closes on its own keys, even when the modal
			// frame's trap has already marked them handled.
			if ( isOpen.current && matchesPaletteKeys( event ) ) {
				event.preventDefault();
				show( false );
				return;
			}
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
