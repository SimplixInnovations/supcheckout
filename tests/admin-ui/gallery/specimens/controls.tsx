/**
 * Actions and form controls specimens (SP1 Task 5). Each specimen is a
 * `[data-specimen]` element in a fixed DOM order, so the browser suite can
 * check WordPress-CSS isolation, target size, keyboard order, focus rings,
 * forced colors and RTL, and the visual suite (Task 8) can screenshot it.
 *
 * Interactive specimens hold their own state so they behave as a screen would
 * use them; nothing here calls a server.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Button,
	Checkbox,
	Field,
	IconButton,
	RadioGroup,
	SecretInput,
	Select,
	TextInput,
	Textarea,
	ThemeToggle,
	Toggle,
} from '../../../../assets/admin/src/ui';
import { Specimen } from './foundations';

/** Specimen names in DOM order (asserted by the browser suite). */
export const CONTROL_SPECIMENS = [
	'button-variants',
	'button-states',
	'icon-button',
	'text-input-default',
	'text-input-error',
	'textarea-default',
	'secret-input-saved',
	'secret-input-new',
	'select-default',
	'checkbox-checked',
	'checkbox-states',
	'radio-group-list',
	'radio-group-segmented',
	'toggle-default',
	'theme-toggle',
] as const;

const row = {
	display: 'flex',
	flexWrap: 'wrap',
	alignItems: 'center',
	gap: 'var(--sup-space-4)',
} as const;

const stack = { display: 'grid', gap: 'var(--sup-space-5)' } as const;

function CheckoutModeSpecimen() {
	const [ mode, setMode ] = useState( 'redirect' );
	return (
		<RadioGroup
			legend={ __( 'Checkout mode', 'supcheckout' ) }
			name="sup-gallery-checkout-mode"
			value={ mode }
			onChange={ setMode }
			options={ [
				{
					value: 'redirect',
					label: __( 'Redirect to UPayments', 'supcheckout' ),
					description: __(
						'Customers pay on the secure UPayments page.',
						'supcheckout'
					),
				},
				{
					value: 'embedded',
					label: __( 'Embedded card form', 'supcheckout' ),
					description: __(
						'Customers stay on your checkout.',
						'supcheckout'
					),
				},
			] }
		/>
	);
}

function DensitySpecimen() {
	const [ density, setDensity ] = useState( 'comfortable' );
	return (
		<RadioGroup
			legend={ __( 'Table density', 'supcheckout' ) }
			name="sup-gallery-density"
			variant="segmented"
			value={ density }
			onChange={ setDensity }
			options={ [
				{
					value: 'comfortable',
					label: __( 'Comfortable', 'supcheckout' ),
				},
				{ value: 'compact', label: __( 'Compact', 'supcheckout' ) },
			] }
		/>
	);
}

function TogglesSpecimen() {
	const [ testMode, setTestMode ] = useState( false );
	const [ receipts, setReceipts ] = useState( true );
	return (
		<div style={ stack }>
			<Toggle
				label={ __( 'Enable test mode', 'supcheckout' ) }
				description={ __(
					'Payments go to the UPayments sandbox.',
					'supcheckout'
				) }
				checked={ testMode }
				onChange={ setTestMode }
			/>
			<Toggle
				label={ __( 'Send payment receipts', 'supcheckout' ) }
				checked={ receipts }
				onChange={ setReceipts }
			/>
		</div>
	);
}

const ignore = () => undefined;

export function ControlsSpecimens() {
	return (
		<>
			<Specimen
				name="button-variants"
				title={ __( 'Button variants', 'supcheckout' ) }
			>
				<div style={ row }>
					<Button variant="primary">
						{ __( 'Save changes', 'supcheckout' ) }
					</Button>
					<Button>{ __( 'Test connection', 'supcheckout' ) }</Button>
					<Button variant="tertiary">
						{ __( 'View logs', 'supcheckout' ) }
					</Button>
					<Button variant="danger">
						{ __( 'Revoke key', 'supcheckout' ) }
					</Button>
				</div>
			</Specimen>

			<Specimen
				name="button-states"
				title={ __( 'Button sizes, icons and states', 'supcheckout' ) }
			>
				<div style={ row }>
					<Button variant="primary" size="sm" iconStart="refresh">
						{ __( 'Sync now', 'supcheckout' ) }
					</Button>
					<Button size="sm" iconEnd="external">
						{ __( 'Open UPayments dashboard', 'supcheckout' ) }
					</Button>
					<Button
						variant="primary"
						busy
						busyLabel={ __( 'Saving settings', 'supcheckout' ) }
					>
						{ __( 'Save settings', 'supcheckout' ) }
					</Button>
					<Button disabled>
						{ __( 'Unavailable', 'supcheckout' ) }
					</Button>
				</div>
			</Specimen>

			<Specimen
				name="icon-button"
				title={ __( 'Icon buttons', 'supcheckout' ) }
			>
				<div style={ row }>
					<IconButton
						icon="close"
						label={ __( 'Dismiss', 'supcheckout' ) }
					/>
					<IconButton
						icon="copy"
						variant="secondary"
						label={ __( 'Copy merchant ID', 'supcheckout' ) }
					/>
					<IconButton
						icon="more"
						size="sm"
						label={ __( 'More actions', 'supcheckout' ) }
					/>
				</div>
			</Specimen>

			<Specimen
				name="text-input-default"
				title={ __( 'Text input', 'supcheckout' ) }
			>
				<Field
					label={ __( 'Store name', 'supcheckout' ) }
					description={ __(
						'Shown to customers on the payment page.',
						'supcheckout'
					) }
				>
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
			</Specimen>

			<Specimen
				name="text-input-error"
				title={ __( 'Text input with an error', 'supcheckout' ) }
			>
				<Field
					label={ __( 'Merchant ID', 'supcheckout' ) }
					required
					error={ __(
						'Enter the merchant ID from your UPayments dashboard.',
						'supcheckout'
					) }
				>
					{ ( control ) => <TextInput { ...control } /> }
				</Field>
			</Specimen>

			<Specimen
				name="textarea-default"
				title={ __( 'Textarea', 'supcheckout' ) }
			>
				<Field
					label={ __( 'Payment page note', 'supcheckout' ) }
					description={ __(
						'Optional. Up to 200 characters.',
						'supcheckout'
					) }
				>
					{ ( control ) => <Textarea { ...control } rows={ 3 } /> }
				</Field>
			</Specimen>

			<Specimen
				name="secret-input-saved"
				title={ __( 'Secret input, key saved', 'supcheckout' ) }
			>
				<SecretInput
					label={ __( 'Live API key', 'supcheckout' ) }
					description={ __(
						'From your UPayments dashboard.',
						'supcheckout'
					) }
					hasSavedValue
					onChange={ ignore }
				/>
			</Specimen>

			<Specimen
				name="secret-input-new"
				title={ __( 'Secret input, nothing saved', 'supcheckout' ) }
			>
				<SecretInput
					label={ __( 'Sandbox API key', 'supcheckout' ) }
					hasSavedValue={ false }
					onChange={ ignore }
				/>
			</Specimen>

			<Specimen
				name="select-default"
				title={ __( 'Select', 'supcheckout' ) }
			>
				<Field label={ __( 'Environment', 'supcheckout' ) }>
					{ ( control ) => (
						<Select
							{ ...control }
							defaultValue="sandbox"
							options={ [
								{
									value: 'sandbox',
									label: __( 'Sandbox', 'supcheckout' ),
								},
								{
									value: 'live',
									label: __( 'Live', 'supcheckout' ),
								},
							] }
						/>
					) }
				</Field>
			</Specimen>

			<Specimen
				name="checkbox-checked"
				title={ __( 'Checkbox, checked', 'supcheckout' ) }
			>
				<Checkbox
					defaultChecked
					label={ __(
						'Save cards for returning customers',
						'supcheckout'
					) }
					description={ __(
						'Customers can pay with a saved card next time.',
						'supcheckout'
					) }
				/>
			</Specimen>

			<Specimen
				name="checkbox-states"
				title={ __( 'Checkbox states', 'supcheckout' ) }
			>
				<div style={ stack }>
					<Checkbox
						label={ __(
							'Email me a daily summary',
							'supcheckout'
						) }
					/>
					<Checkbox
						indeterminate
						label={ __( 'Select all orders', 'supcheckout' ) }
					/>
					<Checkbox
						disabled
						label={ __(
							'Automatic refunds (not supported)',
							'supcheckout'
						) }
					/>
				</div>
			</Specimen>

			<Specimen
				name="radio-group-list"
				title={ __( 'Radio group', 'supcheckout' ) }
			>
				<CheckoutModeSpecimen />
			</Specimen>

			<Specimen
				name="radio-group-segmented"
				title={ __( 'Segmented radio group', 'supcheckout' ) }
			>
				<DensitySpecimen />
			</Specimen>

			<Specimen
				name="toggle-default"
				title={ __( 'Toggle', 'supcheckout' ) }
			>
				<TogglesSpecimen />
			</Specimen>

			<Specimen
				name="theme-toggle"
				title={ __( 'Theme toggle', 'supcheckout' ) }
			>
				<ThemeToggle />
			</Specimen>
		</>
	);
}
