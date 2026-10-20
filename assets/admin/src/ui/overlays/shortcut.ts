/**
 * The palette shortcut (Meta+K on Apple platforms, Ctrl+K elsewhere), its
 * matching rules and the detection of WordPress core's own command palette.
 * Shared by CommandPalette and the modal frame, which keeps Mod+K from
 * core's palette while a SUPCheckout modal is open.
 */
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
 * @return Whether the event is the shortcut's keys (modifier plus K, or the
 *         physical K key), outside IME composition, handled or not.
 */
export function matchesPaletteKeys(
	event: KeyboardEvent,
	shortcut: PaletteShortcut = PALETTE_SHORTCUT
): boolean {
	const key = typeof event.key === 'string' ? event.key.toLowerCase() : '';
	if (
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
	return ! event.defaultPrevented && matchesPaletteKeys( event, shortcut );
}

interface WordPressGlobal {
	wp?: {
		coreCommands?: unknown;
		data?: { select?: ( store: string ) => unknown };
	};
}

interface CommandsStore {
	getCommands?: () => readonly unknown[];
}

/**
 * Whether WordPress core's command palette is mounted on this page, not
 * only loaded: `wp-core-commands` (which mounts it) exposes
 * `wp.coreCommands`, and a mounted palette has registered its commands in
 * the `core/commands` store. The `wp-commands` package alone (loaded as a
 * plugin's dependency, with an empty store) does not count.
 *
 * @return Whether core's palette owns Mod+K here.
 */
export function hasCoreCommandPalette(): boolean {
	if ( typeof window === 'undefined' ) {
		return false;
	}
	const { wp } = window as unknown as WordPressGlobal;
	if ( wp?.coreCommands ) {
		return true;
	}
	try {
		const store = wp?.data?.select?.( 'core/commands' ) as
			CommandsStore | undefined;
		return ( store?.getCommands?.()?.length ?? 0 ) > 0;
	} catch {
		// An unregistered store may throw: core's palette is not here.
		return false;
	}
}
