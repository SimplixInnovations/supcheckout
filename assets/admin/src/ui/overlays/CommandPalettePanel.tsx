/**
 * The command palette panel: the lazy `command-palette` chunk.
 *
 * Base UI Autocomplete rendered inline in the palette dialog: a combobox
 * input that filters a grouped listbox. The first match is always
 * highlighted, so Enter runs it; the arrow keys move the highlight. The
 * search ignores case, accents, Arabic tashkeel and tatweel
 * (`normalizeForSearch`) and also matches each command's keywords.
 *
 * Only this module's strings live in this chunk, so its translations come
 * from the chunk's own JSON, which AdminAssets inlines before the app runs.
 */
import { Autocomplete } from '@base-ui/react/autocomplete';
import {
	useId,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Icon } from '../foundations/Icon';
import type { Command } from './CommandPalette';
import { matchesSearch } from './search';

interface CommandGroup {
	label: string;
	items: Command[];
}

export interface CommandPalettePanelProps {
	commands: readonly Command[];
	onRun: ( command: Command ) => void;
}

/**
 * @param commands The registry, in display order.
 * @param fallback The heading of commands without a group.
 * @return Groups in first-appearance order, each with its commands.
 */
function groupCommands(
	commands: readonly Command[],
	fallback: string
): CommandGroup[] {
	const groups = new Map< string, Command[] >();
	for ( const command of commands ) {
		const label = command.group ?? fallback;
		groups.set( label, [ ...( groups.get( label ) ?? [] ), command ] );
	}
	return Array.from( groups, ( [ label, items ] ) => ( { label, items } ) );
}

export default function CommandPalettePanel( {
	commands,
	onRun,
}: CommandPalettePanelProps ) {
	const [ query, setQuery ] = useState( '' );
	const inputRef = useRef< HTMLInputElement >( null );
	const hintId = useId();
	const fallbackGroup = __( 'Commands', 'supcheckout' );

	const groups = useMemo(
		() => groupCommands( commands, fallbackGroup ),
		[ commands, fallbackGroup ]
	);
	const filtered = useMemo(
		() =>
			groups
				.map( ( group ) => ( {
					...group,
					items: group.items.filter( ( command ) =>
						matchesSearch(
							[ command.label, ...( command.keywords ?? [] ) ],
							query
						)
					),
				} ) )
				.filter( ( group ) => group.items.length > 0 ),
		[ groups, query ]
	);

	// The dialog focused its first tabbable element before this chunk
	// arrived; the search input is where typing belongs.
	useLayoutEffect( () => {
		inputRef.current?.focus();
	}, [] );

	return (
		<Autocomplete.Root
			inline
			open
			items={ groups }
			filteredItems={ filtered }
			value={ query }
			onValueChange={ ( value ) => setQuery( value ) }
			itemToStringValue={ ( command: Command ) => command.label }
			autoHighlight="always"
			keepHighlight
		>
			<div className="sup-palette__search">
				<Icon name="search" size={ 20 } />
				<Autocomplete.Input
					ref={ inputRef }
					className="sup-palette__input"
					aria-label={ __( 'Search commands', 'supcheckout' ) }
					aria-describedby={ hintId }
					placeholder={ __(
						'Type a command or search…',
						'supcheckout'
					) }
				/>
			</div>
			<div className="sup-palette__results">
				<Autocomplete.Empty className="sup-palette__empty">
					{ __( 'No matching commands', 'supcheckout' ) }
				</Autocomplete.Empty>
				<Autocomplete.List className="sup-palette__list">
					{ ( group: CommandGroup ) => (
						<Autocomplete.Group
							key={ group.label }
							items={ group.items }
							className="sup-palette__group"
						>
							<Autocomplete.GroupLabel className="sup-palette__group-label">
								{ group.label }
							</Autocomplete.GroupLabel>
							<Autocomplete.Collection>
								{ ( command: Command ) => (
									<Autocomplete.Item
										key={ command.id }
										value={ command }
										className="sup-palette__item"
										onClick={ () => onRun( command ) }
									>
										{ command.label }
									</Autocomplete.Item>
								) }
							</Autocomplete.Collection>
						</Autocomplete.Group>
					) }
				</Autocomplete.List>
			</div>
			<p id={ hintId } className="sup-palette__hint">
				{ __(
					'Use the arrow keys to choose a command, then press Enter.',
					'supcheckout'
				) }
			</p>
		</Autocomplete.Root>
	);
}
