/**
 * The light-ltr-only `focus-forced-colors` frame (SP1 Task 8). The visual
 * suite captures it under `forced-colors: active` with the first button
 * focused, so the baseline shows every control boundary and the focus ring
 * in system colors. Not a `[data-specimen]`, so the browser suite's
 * specimen order is unchanged.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Button,
	Checkbox,
	Field,
	IconButton,
	TextInput,
	Toggle,
} from '../../../../assets/admin/src/ui';
import { SPECIMEN_STYLE } from './foundations';
import { SpecimenFrame } from './frame';

const row = {
	display: 'flex',
	flexWrap: 'wrap',
	alignItems: 'center',
	gap: 'var(--sup-space-4)',
} as const;

export function FocusForcedColorsFrame() {
	const [ testMode, setTestMode ] = useState( true );
	return (
		<SpecimenFrame name="focus-forced-colors">
			<section
				aria-label={ __( 'Focus in forced colors', 'supcheckout' ) }
				style={ SPECIMEN_STYLE }
			>
				<div style={ row }>
					<Button variant="primary">
						{ __( 'Save changes', 'supcheckout' ) }
					</Button>
					<Button>{ __( 'Test connection', 'supcheckout' ) }</Button>
					<IconButton
						icon="copy"
						variant="secondary"
						label={ __( 'Copy merchant ID', 'supcheckout' ) }
					/>
				</div>
				<Field label={ __( 'Store name', 'supcheckout' ) }>
					{ ( control ) => (
						<TextInput
							{ ...control }
							defaultValue={ __(
								'Simplix Store',
								'supcheckout'
							) }
						/>
					) }
				</Field>
				<Checkbox
					defaultChecked
					label={ __(
						'Save cards for returning customers',
						'supcheckout'
					) }
				/>
				<Toggle
					label={ __( 'Enable test mode', 'supcheckout' ) }
					checked={ testMode }
					onChange={ setTestMode }
				/>
			</section>
		</SpecimenFrame>
	);
}
