/**
 * ThemeToggle: the Appearance choice (Light, Dark, Match system) as a
 * segmented RadioGroup bound to `useTheme()` of the nearest SupRoot.
 *
 * It has no styles of its own: it is a RadioGroup.
 */
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { useTheme } from '../foundations/theme';
import type { ThemePreference } from '../foundations/theme';
import { RadioGroup } from './RadioGroup';
import type { RadioOption } from './RadioGroup';

const PREFERENCES: readonly ThemePreference[] = [ 'light', 'dark', 'system' ];

function isPreference( value: string ): value is ThemePreference {
	return ( PREFERENCES as readonly string[] ).includes( value );
}

export function ThemeToggle() {
	const { preference, setPreference } = useTheme();
	const name = `sup-theme-${ useId() }`;
	const options: RadioOption[] = [
		{ value: 'light', label: __( 'Light', 'supcheckout' ), icon: 'sun' },
		{ value: 'dark', label: __( 'Dark', 'supcheckout' ), icon: 'moon' },
		{
			value: 'system',
			label: __( 'Match system', 'supcheckout' ),
			icon: 'monitor',
		},
	];

	return (
		<RadioGroup
			legend={ __( 'Appearance', 'supcheckout' ) }
			name={ name }
			variant="segmented"
			value={ preference }
			options={ options }
			onChange={ ( value ) => {
				if ( isPreference( value ) ) {
					setPreference( value );
				}
			} }
		/>
	);
}
