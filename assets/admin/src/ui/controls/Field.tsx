/**
 * Field: a visible label, an optional description and an optional error,
 * wired to one control through a render function.
 *
 * The render function receives the control's `id`, its `aria-describedby`
 * (description first, then error), `aria-invalid` when there is an error and
 * `required`. "(required)" is visible text inside the label, never an
 * asterisk alone. The error carries an icon and text, never color alone.
 *
 * The error is not a live region: an error that appears after submit is not
 * announced by itself. Screens (SP2) move focus to the first invalid field,
 * or to an error summary that links to the fields, after a failed submit.
 * `required` is passed to the native control, so the browser and assistive
 * technology expose it too.
 */
import type { ReactNode } from 'react';
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Icon } from '../foundations/Icon';
import { classNames, describedBy } from './classNames';
import './Field.css';

/** Props a Field hands to its control. */
export interface FieldControlProps {
	id: string;
	'aria-describedby'?: string;
	'aria-invalid'?: true;
	required?: boolean;
}

export interface FieldProps {
	/** Translated visible label. */
	label: string;
	/** Translated help text. */
	description?: string;
	/** Translated error message; marks the control invalid. */
	error?: string;
	required?: boolean;
	children: ( control: FieldControlProps ) => ReactNode;
}

export function Field( {
	label,
	description,
	error,
	required = false,
	children,
}: FieldProps ) {
	const id = useId();
	const descriptionId = `${ id }-description`;
	const errorId = `${ id }-error`;
	const control: FieldControlProps = {
		id,
		'aria-describedby': describedBy(
			description && descriptionId,
			error && errorId
		),
		...( error ? { 'aria-invalid': true as const } : {} ),
		...( required ? { required: true } : {} ),
	};

	return (
		<div
			className={ classNames(
				'sup-field',
				error && 'sup-field--invalid'
			) }
		>
			<label className="sup-field__label" htmlFor={ id }>
				{ label }
				{ required && (
					<>
						{ ' ' }
						<span className="sup-field__required">
							{ __( '(required)', 'supcheckout' ) }
						</span>
					</>
				) }
			</label>
			{ description && (
				<p id={ descriptionId } className="sup-field__description">
					{ description }
				</p>
			) }
			{ children( control ) }
			{ error && (
				<div id={ errorId } className="sup-field__error">
					<Icon name="error" size={ 16 } />
					<span>{ error }</span>
				</div>
			) }
		</div>
	);
}
