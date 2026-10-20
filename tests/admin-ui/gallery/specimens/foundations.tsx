/**
 * Foundations specimens: typography, Numeric, icons, Spinner and
 * VisuallyHidden. Each specimen is a `[data-specimen]` element with a fixed
 * width, in a fixed DOM order, so browser, axe and visual tests can target it.
 */
import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import {
	Heading,
	Icon,
	ICON_NAMES,
	Numeric,
	Spinner,
	Text,
	VisuallyHidden,
} from '../../../../assets/admin/src/ui';
import { SpecimenFrame } from './frame';

/** Fixed specimen width in CSS px (visual determinism). */
const SPECIMEN_WIDTH = 640;

interface SpecimenProps {
	name: string;
	title: string;
	children: ReactNode;
}

/** The specimen card: fixed width on the surface tone. */
export const SPECIMEN_STYLE = {
	inlineSize: SPECIMEN_WIDTH,
	padding: 'var(--sup-space-6)',
	display: 'grid',
	gap: 'var(--sup-space-4)',
	backgroundColor: 'var(--sup-color-bg-surface)',
	border: '1px solid var(--sup-color-border-default)',
	borderRadius: 'var(--sup-radius-card)',
} as const;

export function Specimen( { name, title, children }: SpecimenProps ) {
	return (
		<section
			data-specimen={ name }
			aria-label={ title }
			style={ SPECIMEN_STYLE }
		>
			{ children }
		</section>
	);
}

export function FoundationsSpecimens() {
	return (
		<>
			<SpecimenFrame name="foundations-type">
				<Specimen
					name="foundations-headings"
					title={ __( 'Headings', 'supcheckout' ) }
				>
					<Heading level={ 2 }>
						{ __( 'Payments overview', 'supcheckout' ) }
					</Heading>
					<Heading level={ 3 }>
						{ __( 'Recent transactions', 'supcheckout' ) }
					</Heading>
					<Heading level={ 4 }>
						{ __( 'Settlement details', 'supcheckout' ) }
					</Heading>
					<Heading level={ 4 } size="lg">
						{ __(
							'A level 4 heading at the large size',
							'supcheckout'
						) }
					</Heading>
				</Specimen>

				<Specimen
					name="foundations-text"
					title={ __( 'Text', 'supcheckout' ) }
				>
					<Text>
						{ __(
							'Body text explains what a setting does and what happens next.',
							'supcheckout'
						) }
					</Text>
					<Text variant="muted">
						{ __(
							'Muted text adds secondary detail without competing.',
							'supcheckout'
						) }
					</Text>
					<Text variant="caption">
						{ __(
							'Caption text labels small details.',
							'supcheckout'
						) }
					</Text>
					<Text variant="strong">
						{ __(
							'Strong text marks the key fact.',
							'supcheckout'
						) }
					</Text>
				</Specimen>

				<Specimen
					name="foundations-numeric"
					title={ __( 'Numbers and identifiers', 'supcheckout' ) }
				>
					<Text>
						{ __( 'Amount:', 'supcheckout' ) }{ ' ' }
						<Numeric kind="amount">12.500 KWD</Numeric>
					</Text>
					<Text>
						{ __( 'Order:', 'supcheckout' ) }{ ' ' }
						<Numeric kind="id">#10234</Numeric>
					</Text>
					<Text>
						{ __( 'Count:', 'supcheckout' ) }{ ' ' }
						<Numeric>1,024</Numeric>
					</Text>
				</Specimen>
			</SpecimenFrame>

			<SpecimenFrame name="foundations-icons">
				<Specimen
					name="foundations-icons"
					title={ __( 'Icons', 'supcheckout' ) }
				>
					<div
						style={ {
							display: 'flex',
							flexWrap: 'wrap',
							gap: 'var(--sup-space-4)',
							color: 'var(--sup-color-fg-default)',
						} }
					>
						{ ICON_NAMES.map( ( name ) => (
							<Icon key={ name } name={ name } label={ name } />
						) ) }
					</div>
				</Specimen>

				<Specimen
					name="foundations-spinner"
					title={ __( 'Spinner', 'supcheckout' ) }
				>
					<div
						style={ { display: 'flex', gap: 'var(--sup-space-4)' } }
					>
						<Spinner
							label={ __( 'Loading payments', 'supcheckout' ) }
						/>
						<Spinner size={ 16 } />
					</div>
				</Specimen>

				<Specimen
					name="foundations-visually-hidden"
					title={ __( 'Visually hidden', 'supcheckout' ) }
				>
					<Text>
						{ __( 'Refund', 'supcheckout' ) }
						<VisuallyHidden>
							{ __( 'for order 10234', 'supcheckout' ) }
						</VisuallyHidden>
					</Text>
				</Specimen>
			</SpecimenFrame>
		</>
	);
}
