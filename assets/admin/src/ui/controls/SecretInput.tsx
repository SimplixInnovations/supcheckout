/**
 * SecretInput: a write-only field for an API key or other secret
 * (spec §6: "write-only and never shown back").
 *
 * - By type: there is no `value` or `defaultValue` prop. The caller only says
 *   whether a secret is saved (`hasSavedValue`) and receives what is typed
 *   through `onChange`.
 * - At runtime: a saved secret is never in the DOM. The saved state renders
 *   no input at all, only "A key is saved…" and a Replace key button.
 *   Replacing (or having nothing saved) shows an empty, uncontrolled
 *   `type="password"` input: the typed text lives in the input's DOM value
 *   only, never in a `value` attribute, props or state, so it is absent from
 *   the markup. `autocomplete="new-password"` stops the browser filling a
 *   saved password, and spell checking (which could send the text to a
 *   service) is off.
 *
 * Replace key moves focus into the input and says the saved key stays in use
 * until the new one is saved. Cancel clears the draft (onChange with an
 * empty string), returns to the saved state and puts focus back on Replace
 * key. After the screen saves a replacement it bumps `savedRevision`: the
 * component then returns to the saved state the same way, unmounting the
 * input (so the typed text leaves the page), and moves focus to Replace key
 * only if focus was inside the field, never away from another control.
 */
import { useId, useLayoutEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Icon } from '../foundations/Icon';
import { Button } from './Button';
import { describedBy } from './classNames';
import { Field } from './Field';
import './TextInput.css';
import './SecretInput.css';

export interface SecretInputProps {
	/** Translated visible label. */
	label: string;
	/** Whether a secret is already saved on the server. */
	hasSavedValue: boolean;
	/** Receives the typed secret; an empty string clears the draft. */
	onChange: ( value: string ) => void;
	/** Translated help text. */
	description?: string;
	/** Translated error message; marks the input invalid. */
	error?: string;
	required?: boolean;
	/**
	 * Bump after each successful save of a replacement (any changed number):
	 * the field returns to the saved state and the draft is cleared.
	 */
	savedRevision?: number;
	/** Never accepted: a saved secret is never passed back in. */
	value?: never;
	/** Never accepted: a saved secret is never passed back in. */
	defaultValue?: never;
}

type FocusTarget = 'input' | 'replace' | null;

export function SecretInput( {
	label,
	hasSavedValue,
	onChange,
	description,
	error,
	required = false,
	savedRevision = 0,
}: SecretInputProps ) {
	// The revision a replacement started at; a new revision ends it.
	const [ replacingAt, setReplacingAt ] = useState< number | null >( null );
	const replacing = replacingAt === savedRevision;
	const root = useRef< HTMLDivElement >( null );
	const seen = useRef( { revision: savedRevision, replacing } );
	const input = useRef< HTMLInputElement >( null );
	const replace = useRef< HTMLButtonElement >( null );
	const pendingFocus = useRef< FocusTarget >( null );
	const id = useId();
	const labelId = `${ id }-label`;
	const savedId = `${ id }-saved`;
	const descriptionId = `${ id }-description`;
	const keepId = `${ id }-keep`;

	useLayoutEffect( () => {
		const target = pendingFocus.current;
		pendingFocus.current = null;
		if ( target === 'input' ) {
			input.current?.focus();
		} else if ( target === 'replace' ) {
			replace.current?.focus();
		}
	}, [ replacing ] );

	// A save (new revision) ended a replacement: clear the draft and, if focus
	// was inside the field (now on the body, as the input unmounted), move it
	// to Replace key.
	useLayoutEffect( () => {
		const previous = seen.current;
		seen.current = { revision: savedRevision, replacing };
		if ( previous.revision === savedRevision || ! previous.replacing ) {
			return;
		}
		setReplacingAt( null );
		onChange( '' );
		const doc = root.current?.ownerDocument;
		const active = doc?.activeElement;
		if (
			! active ||
			active === doc?.body ||
			root.current?.contains( active )
		) {
			replace.current?.focus();
		}
	}, [ savedRevision, replacing, onChange ] );

	if ( hasSavedValue && ! replacing ) {
		return (
			<div
				ref={ root }
				role="group"
				aria-labelledby={ labelId }
				className="sup-secret-input sup-secret-input--saved"
			>
				<span id={ labelId } className="sup-field__label">
					{ label }
				</span>
				<div className="sup-secret-input__saved">
					<Icon name="lock" size={ 16 } />
					<span id={ savedId }>
						{ __(
							'A key is saved. For security it is never shown.',
							'supcheckout'
						) }
					</span>
				</div>
				{ description && (
					<p id={ descriptionId } className="sup-field__description">
						{ description }
					</p>
				) }
				<div className="sup-secret-input__actions">
					<Button
						ref={ replace }
						size="sm"
						aria-describedby={ describedBy(
							savedId,
							description && descriptionId
						) }
						onClick={ () => {
							pendingFocus.current = 'input';
							setReplacingAt( savedRevision );
						} }
					>
						{ __( 'Replace key', 'supcheckout' ) }
					</Button>
				</div>
			</div>
		);
	}

	return (
		<div ref={ root } className="sup-secret-input">
			<Field
				label={ label }
				description={ description }
				error={ error }
				required={ required }
			>
				{ ( control ) => (
					<>
						<div className="sup-secret-input__row">
							<input
								{ ...control }
								aria-describedby={ describedBy(
									control[ 'aria-describedby' ],
									hasSavedValue && keepId
								) }
								ref={ input }
								type="password"
								className="sup-input sup-secret-input__input"
								autoComplete="new-password"
								autoCapitalize="off"
								autoCorrect="off"
								spellCheck={ false }
								data-1p-ignore=""
								data-lpignore="true"
								onChange={ ( event ) =>
									onChange( event.target.value )
								}
							/>
							{ hasSavedValue && (
								<Button
									size="md"
									variant="tertiary"
									onClick={ () => {
										onChange( '' );
										pendingFocus.current = 'replace';
										setReplacingAt( null );
									} }
								>
									{ __( 'Cancel', 'supcheckout' ) }
								</Button>
							) }
						</div>
						{ hasSavedValue && (
							<p id={ keepId } className="sup-field__description">
								{ __(
									'The saved key stays in use until you save the new one.',
									'supcheckout'
								) }
							</p>
						) }
					</>
				) }
			</Field>
		</div>
	);
}
