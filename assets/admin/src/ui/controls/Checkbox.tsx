/**
 * Checkbox: a native `<input type="checkbox">` with a visible label, an
 * optional description and the indeterminate (mixed) state.
 *
 * The input is transparent and covers a 24 px target; a decorative sibling
 * draws the box, the check and the dash with tokens, so WordPress's
 * `forms.css` checkmark (`:checked::before`) never shows. `indeterminate` is a
 * DOM property, not an attribute, so it is applied in a layout effect.
 */
import type {
	InputHTMLAttributes,
	MutableRefObject,
	ReactNode,
	Ref,
	RefCallback,
} from 'react';
import {
	forwardRef,
	useCallback,
	useId,
	useLayoutEffect,
	useRef,
} from '@wordpress/element';

import { Icon } from '../foundations/Icon';
import { classNames, describedBy } from './classNames';
import './Checkbox.css';

export interface CheckboxProps extends Omit<
	InputHTMLAttributes< HTMLInputElement >,
	'type'
> {
	/** Translated visible label. */
	label: ReactNode;
	/** Translated help text, linked as the accessible description. */
	description?: string;
	/** Shows and exposes the mixed state. */
	indeterminate?: boolean;
}

/**
 * @param inner     The component's own ref.
 * @param forwarded The caller's ref.
 * @return One callback ref that sets both.
 */
function useBothRefs< T >(
	inner: MutableRefObject< T | null >,
	forwarded: Ref< T >
): RefCallback< T > {
	return useCallback(
		( node: T | null ) => {
			inner.current = node;
			if ( typeof forwarded === 'function' ) {
				forwarded( node );
			} else if ( forwarded ) {
				( forwarded as MutableRefObject< T | null > ).current = node;
			}
		},
		[ inner, forwarded ]
	);
}

export const Checkbox = forwardRef< HTMLInputElement, CheckboxProps >(
	function SupCheckbox(
		{
			label,
			description,
			indeterminate = false,
			className,
			id,
			'aria-describedby': ariaDescribedBy,
			...rest
		},
		ref
	) {
		const generatedId = useId();
		const inputId = id ?? generatedId;
		const descriptionId = `${ inputId }-description`;
		const input = useRef< HTMLInputElement | null >( null );
		const setRef = useBothRefs( input, ref );

		useLayoutEffect( () => {
			if ( input.current ) {
				input.current.indeterminate = indeterminate;
			}
		}, [ indeterminate ] );

		return (
			<div className={ classNames( 'sup-checkbox', className ) }>
				<span className="sup-checkbox__control">
					<input
						{ ...rest }
						ref={ setRef }
						id={ inputId }
						type="checkbox"
						className="sup-checkbox__input"
						aria-describedby={ describedBy(
							ariaDescribedBy,
							description && descriptionId
						) }
					/>
					<span
						className="sup-checkbox__indicator"
						aria-hidden="true"
					>
						<Icon name="check" size={ 16 } />
						<span className="sup-checkbox__dash" />
					</span>
				</span>
				<span className="sup-checkbox__text">
					<label className="sup-checkbox__label" htmlFor={ inputId }>
						{ label }
					</label>
					{ description && (
						<span
							id={ descriptionId }
							className="sup-checkbox__description"
						>
							{ description }
						</span>
					) }
				</span>
			</div>
		);
	}
);
