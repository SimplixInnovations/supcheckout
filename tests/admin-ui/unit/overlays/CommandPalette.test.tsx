/**
 * CommandPalette: Mod+K opens a dialog whose lazily loaded panel filters
 * commands with diacritic-insensitive search (SP1 Task 7).
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
	Button,
	CommandPalette,
	ConfirmDialog,
	isPaletteShortcut,
	normalizeForSearch,
	paletteShortcut,
	PALETTE_SHORTCUT,
	SupRoot,
} from '../../../../assets/admin/src/ui';
import type { Command, ConfirmResult } from '../../../../assets/admin/src/ui';

function commands( perform = jest.fn() ): Command[] {
	return [
		{
			id: 'payments',
			label: 'Open payments',
			group: 'Go to',
			keywords: [ 'orders', 'transactions' ],
			perform,
		},
		{
			id: 'settings',
			label: 'Open checkout settings',
			group: 'Go to',
			perform: jest.fn(),
		},
		{
			id: 'test',
			label: 'تطبيق الاختبار',
			group: 'Actions',
			perform: jest.fn(),
		},
	];
}

function renderPalette( list: Command[] = commands() ) {
	return render(
		<SupRoot>
			<Button>Before</Button>
			<CommandPalette commands={ list } />
		</SupRoot>
	);
}

const pressShortcut = () =>
	fireEvent.keyDown(
		document.body.ownerDocument.activeElement ?? document.body,
		{
			key: 'k',
			code: 'KeyK',
			ctrlKey: true,
		}
	);

async function openWithShortcut() {
	pressShortcut();
	const dialog = await screen.findByRole( 'dialog', {
		name: 'Command palette',
	} );
	const input = await within( dialog ).findByRole( 'combobox', {
		name: 'Search commands',
	} );
	await waitFor( () => expect( input ).toHaveFocus() );
	return { dialog, input };
}

test( 'the shortcut is Meta+K on macOS and Ctrl+K elsewhere', () => {
	expect( paletteShortcut( 'MacIntel' ).ariaKeyShortcuts ).toBe( 'Meta+K' );
	expect( paletteShortcut( 'iPhone' ).modifier ).toBe( 'meta' );
	expect( paletteShortcut( 'Win32' ).ariaKeyShortcuts ).toBe( 'Control+K' );
	expect( paletteShortcut( 'Linux x86_64' ).modifier ).toBe( 'ctrl' );
	expect( PALETTE_SHORTCUT.ariaKeyShortcuts ).toBe( 'Control+K' );
} );

test( 'Mod+K opens the palette and Escape returns focus', async () => {
	renderPalette();
	const before = screen.getByRole( 'button', { name: 'Before' } );
	before.focus();
	const { dialog } = await openWithShortcut();
	expect( dialog.closest( '.sup-portal-layer' ) ).not.toBeNull();
	expect( within( dialog ).getAllByRole( 'option' ) ).toHaveLength( 3 );

	await userEvent.keyboard( '{Escape}' );
	await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).toBeNull() );
	await waitFor( () => expect( before ).toHaveFocus() );
} );

test( 'the trigger button opens the palette', async () => {
	renderPalette();
	await userEvent.click(
		screen.getByRole( 'button', { name: /^Search commands/ } )
	);
	expect(
		await screen.findByRole( 'dialog', { name: 'Command palette' } )
	).toBeInTheDocument();
} );

interface WpGlobal {
	wp?: Record< string, unknown >;
}

/**
 * Pretends WordPress core's command palette is on the page, as `wp-commands`
 * (and its `core/commands` store) would be.
 *
 * @param shape `commands` for the script global, `store` for the data store.
 * @return Removes it again.
 */
function withCorePalette( shape: 'commands' | 'store' ): () => void {
	const win = window as unknown as WpGlobal;
	const previous = win.wp;
	win.wp =
		shape === 'commands'
			? { commands: { CommandMenu: () => null } }
			: {
					data: {
						select: ( store: string ) =>
							store === 'core/commands'
								? { getCommands: () => [] }
								: undefined,
					},
				};
	return () => {
		win.wp = previous;
	};
}

test.each( [ 'commands', 'store' ] as const )(
	"Mod+K is left to WordPress core's command palette when it is present (%s)",
	async ( shape ) => {
		const restore = withCorePalette( shape );
		// Core's palette listens on document and toggles on Mod+K.
		const corePalette = jest.fn();
		document.addEventListener( 'keydown', corePalette );
		try {
			renderPalette();
			const trigger = screen.getByRole( 'button', {
				name: /^Search commands/,
			} );
			expect( trigger ).not.toHaveAttribute( 'aria-keyshortcuts' );
			expect( trigger.querySelector( 'kbd' ) ).toBeNull();
			pressShortcut();
			expect( corePalette ).toHaveBeenCalledTimes( 1 );
			expect( corePalette.mock.calls[ 0 ][ 0 ].defaultPrevented ).toBe(
				false
			);
			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			// The trigger still opens the SUPCheckout palette.
			await userEvent.click( trigger );
			expect(
				await screen.findByRole( 'dialog', { name: 'Command palette' } )
			).toBeInTheDocument();
		} finally {
			document.removeEventListener( 'keydown', corePalette );
			restore();
		}
	}
);

test( "without core's palette Mod+K opens ours and still reaches document listeners", async () => {
	const documentListener = jest.fn();
	document.addEventListener( 'keydown', documentListener );
	try {
		renderPalette();
		expect(
			screen.getByRole( 'button', { name: /^Search commands/ } )
		).toHaveAttribute( 'aria-keyshortcuts', 'Control+K' );
		pressShortcut();
		expect(
			await screen.findByRole( 'dialog', { name: 'Command palette' } )
		).toBeInTheDocument();
		// No stopPropagation: the key is handled, not swallowed.
		expect( documentListener ).toHaveBeenCalledTimes( 1 );
		expect( documentListener.mock.calls[ 0 ][ 0 ].defaultPrevented ).toBe(
			true
		);
	} finally {
		document.removeEventListener( 'keydown', documentListener );
	}
} );

test( 'Mod+K never opens the palette over another modal, such as a pending confirmation', async () => {
	const perform = jest.fn();
	const onConfirm = jest.fn( () => new Promise< ConfirmResult >( () => {} ) );
	render(
		<SupRoot>
			<CommandPalette commands={ commands( perform ) } />
			<ConfirmDialog
				open
				onOpenChange={ () => undefined }
				title="Refund 12.500 KWD?"
				consequence="This cannot be undone."
				confirmLabel="Refund 12.500 KWD"
				tone="money"
				onConfirm={ onConfirm }
			/>
		</SupRoot>
	);
	await waitFor( () =>
		expect( screen.getByRole( 'button', { name: 'Cancel' } ) ).toHaveFocus()
	);
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Refund 12.500 KWD' } )
	);
	expect( onConfirm ).toHaveBeenCalledTimes( 1 );
	pressShortcut();
	await userEvent.keyboard( '{Enter}' );
	expect(
		screen.queryByRole( 'dialog', { name: 'Command palette' } )
	).toBeNull();
	expect( perform ).not.toHaveBeenCalled();
	expect( onConfirm ).toHaveBeenCalledTimes( 1 );
} );

test( 'the shortcut matches the physical K key on other layouts and never throws without a key', () => {
	const shortcut = paletteShortcut( 'Win32' );
	const event = ( init: Partial< KeyboardEvent > ) =>
		( { ctrlKey: true, ...init } ) as KeyboardEvent;
	// Arabic layout: the K key types "ن".
	expect(
		isPaletteShortcut( event( { key: 'ن', code: 'KeyK' } ), shortcut )
	).toBe( true );
	expect(
		isPaletteShortcut(
			event( { key: undefined as unknown as string, code: 'KeyK' } ),
			shortcut
		)
	).toBe( true );
	expect(
		isPaletteShortcut(
			event( { key: undefined as unknown as string, code: 'KeyJ' } ),
			shortcut
		)
	).toBe( false );
	expect(
		isPaletteShortcut( event( { key: 'j', code: 'KeyJ' } ), shortcut )
	).toBe( false );
} );

test( 'an Arabic keyboard layout opens the palette with Ctrl and the K key', async () => {
	renderPalette();
	fireEvent.keyDown( document.body, {
		key: 'ن',
		code: 'KeyK',
		ctrlKey: true,
	} );
	expect(
		await screen.findByRole( 'dialog', { name: 'Command palette' } )
	).toBeInTheDocument();
} );

test( 'the shortcut is ignored during IME composition and when an input already handled it', () => {
	render(
		<SupRoot>
			<input
				aria-label="Editor"
				onKeyDown={ ( event ) => {
					if ( event.key === 'k' && event.ctrlKey ) {
						event.preventDefault();
					}
				} }
			/>
			<CommandPalette commands={ commands() } />
		</SupRoot>
	);
	fireEvent.keyDown( document.body, {
		key: 'k',
		ctrlKey: true,
		isComposing: true,
	} );
	fireEvent.keyDown( document.body, {
		key: 'Process',
		keyCode: 229,
		ctrlKey: true,
	} );
	fireEvent.keyDown( screen.getByRole( 'textbox', { name: 'Editor' } ), {
		key: 'k',
		ctrlKey: true,
	} );
	// The wrong modifier for this platform, or extra modifiers, do nothing.
	fireEvent.keyDown( document.body, { key: 'k', metaKey: true } );
	fireEvent.keyDown( document.body, {
		key: 'k',
		ctrlKey: true,
		shiftKey: true,
	} );
	expect( screen.queryByRole( 'dialog' ) ).toBeNull();
} );

test( 'search ignores Arabic diacritics and tatweel', () => {
	expect( normalizeForSearch( 'تَطْبِيـق' ) ).toBe(
		normalizeForSearch( 'تطبيق' )
	);
	expect( normalizeForSearch( 'تَطْبِيـق' ) ).toBe( 'تطبيق' );
	// Superscript alef (U+0670) and the full tashkeel range.
	expect( normalizeForSearch( 'هٰذا' ) ).toBe( 'هذا' );
	expect( normalizeForSearch( 'مُحَمَّدٌ' ) ).toBe( 'محمد' );
} );

test( 'search ignores Latin accents, case and compatibility forms', () => {
	expect( normalizeForSearch( 'Café Crème' ) ).toBe( 'cafe creme' );
	expect( normalizeForSearch( 'ÅNGSTRÖM' ) ).toBe( 'angstrom' );
	expect( normalizeForSearch( 'ﬁle' ) ).toBe( 'file' );
	expect( normalizeForSearch( 'Ｐａｙ' ) ).toBe( 'pay' );
} );

test( 'typing filters commands by label and keywords, ignoring diacritics', async () => {
	renderPalette();
	const { dialog, input } = await openWithShortcut();
	await userEvent.type( input, 'TRANSACT' );
	expect(
		within( dialog )
			.getAllByRole( 'option' )
			.map( ( o ) => o.textContent )
	).toEqual( [ 'Open payments' ] );
	await userEvent.clear( input );
	await userEvent.type( input, 'تَطْبِيق' );
	expect(
		within( dialog )
			.getAllByRole( 'option' )
			.map( ( o ) => o.textContent )
	).toEqual( [ 'تطبيق الاختبار' ] );
} );

test( 'groups are labelled', async () => {
	renderPalette();
	const { dialog } = await openWithShortcut();
	expect(
		within( dialog ).getByRole( 'group', { name: 'Go to' } )
	).toBeInTheDocument();
	expect(
		within( dialog ).getByRole( 'group', { name: 'Actions' } )
	).toBeInTheDocument();
} );

test( 'empty results show "No matching commands"', async () => {
	renderPalette();
	const { dialog, input } = await openWithShortcut();
	await userEvent.type( input, 'zzzz' );
	expect( within( dialog ).queryAllByRole( 'option' ) ).toHaveLength( 0 );
	expect(
		within( dialog ).getByText( 'No matching commands' )
	).toBeVisible();
} );

test( 'an empty registry still opens and says it has no commands yet', async () => {
	renderPalette( [] );
	const { dialog } = await openWithShortcut();
	// Base UI fills its polite status after mount, so it is announced.
	expect(
		await within( dialog ).findByText( 'No commands yet' )
	).toBeVisible();
	expect( within( dialog ).getByRole( 'status' ) ).toHaveTextContent(
		'No commands yet'
	);
	expect( within( dialog ).queryByText( 'No matching commands' ) ).toBeNull();
} );

test( 'Enter performs the active command once, after the palette has closed', async () => {
	const openWhenPerformed: boolean[] = [];
	const perform = jest.fn( () => {
		openWhenPerformed.push(
			screen.queryByRole( 'dialog', { name: 'Command palette' } ) !== null
		);
	} );
	renderPalette( commands( perform ) );
	const { input } = await openWithShortcut();
	await userEvent.type( input, 'pay' );
	await userEvent.keyboard( '{Enter>3}{/Enter}' );
	await waitFor( () => expect( perform ).toHaveBeenCalledTimes( 1 ) );
	expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	expect( openWhenPerformed ).toEqual( [ false ] );
} );

test( 'clicking a command performs it once and closes', async () => {
	const perform = jest.fn();
	renderPalette( commands( perform ) );
	const { dialog } = await openWithShortcut();
	await userEvent.click(
		within( dialog ).getByRole( 'option', { name: 'Open payments' } )
	);
	await waitFor( () => expect( perform ).toHaveBeenCalledTimes( 1 ) );
	await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).toBeNull() );
} );

test( 'the shortcut toggles an open palette closed', async () => {
	renderPalette();
	await openWithShortcut();
	pressShortcut();
	await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).toBeNull() );
} );
