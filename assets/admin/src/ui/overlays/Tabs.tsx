/**
 * Tabs: sections of one screen on Base UI Tabs.
 *
 * - A `tablist` named by `label`; each tab controls one `tabpanel`, which
 *   is named by its tab and focusable so keyboard users reach its content.
 * - Roving focus: Tab enters the selected tab, the arrow keys move between
 *   tabs and follow the reading direction (ArrowLeft moves forward in RTL,
 *   through SupRoot's DirectionProvider), Home and End jump to the ends.
 * - `activation="automatic"` (default) selects on arrow focus, for panels
 *   that render at once; `manual` selects on Enter or Space, for panels
 *   that are expensive to show.
 * - `badge` adds a short count to a tab and is read as part of its name.
 *
 * Use tabs for parallel views of the same object, never for steps (that is
 * the Stepper) or navigation between screens (that is the AppShell nav).
 */
import type { ReactNode } from 'react';
import { Tabs as BaseTabs } from '@base-ui/react/tabs';

import { Badge } from '../feedback/Badge';
import './Tabs.css';

export interface TabItem {
	id: string;
	/** Translated tab label. */
	label: string;
	/** Short count or label shown after the tab label, e.g. "3". */
	badge?: string;
	panel: ReactNode;
}

export type TabsActivation = 'automatic' | 'manual';

export interface TabsProps {
	/** Translated name of the tab list. */
	label: string;
	tabs: readonly TabItem[];
	/** The selected tab (controlled). Omit to let Tabs hold the selection. */
	selectedId?: string;
	onSelect?: ( id: string ) => void;
	activation?: TabsActivation;
}

export function Tabs( {
	label,
	tabs,
	selectedId,
	onSelect,
	activation = 'automatic',
}: TabsProps ) {
	const controlled = selectedId === undefined ? {} : { value: selectedId };
	return (
		<BaseTabs.Root
			className="sup-tabs"
			defaultValue={ tabs[ 0 ]?.id }
			{ ...controlled }
			onValueChange={ ( value ) => {
				if ( typeof value === 'string' ) {
					onSelect?.( value );
				}
			} }
		>
			<BaseTabs.List
				className="sup-tabs__list"
				aria-label={ label }
				activateOnFocus={ activation === 'automatic' }
			>
				{ tabs.map( ( tab ) => (
					<BaseTabs.Tab
						key={ tab.id }
						value={ tab.id }
						className="sup-tabs__tab"
					>
						<span className="sup-tabs__label">{ tab.label }</span>
						{ tab.badge !== undefined && (
							<Badge>{ tab.badge }</Badge>
						) }
					</BaseTabs.Tab>
				) ) }
			</BaseTabs.List>
			{ tabs.map( ( tab ) => (
				<BaseTabs.Panel
					key={ tab.id }
					value={ tab.id }
					className="sup-tabs__panel"
				>
					{ tab.panel }
				</BaseTabs.Panel>
			) ) }
		</BaseTabs.Root>
	);
}
