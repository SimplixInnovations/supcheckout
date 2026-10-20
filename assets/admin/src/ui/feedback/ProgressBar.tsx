/**
 * ProgressBar: a labelled `progressbar` on Base UI Progress.
 *
 * With a `value` it is determinate: `aria-valuenow`, `aria-valuemax` and a
 * value text (`valueText`, or a locale-formatted percentage) that is also
 * shown at the inline end of the label. Without a `value` it is
 * indeterminate: no `aria-valuenow`, no value text unless `valueText` is
 * given, and a sliding segment that stops under reduced motion.
 *
 * The bar is decoration for the visible label and value text, which carry
 * the meaning; it never replaces them.
 */
import { Progress as BaseProgress } from '@base-ui/react/progress';

import './ProgressBar.css';

export interface ProgressBarProps {
	/** Translated visible label; the progressbar's accessible name. */
	label: string;
	/** Current value; omit for indeterminate progress. */
	value?: number;
	/** Maximum value; defaults to 100. */
	max?: number;
	/** Translated value text, e.g. "2 of 4 steps"; replaces the percentage. */
	valueText?: string;
}

export function ProgressBar( {
	label,
	value,
	max = 100,
	valueText,
}: ProgressBarProps ) {
	const indeterminate = value === undefined;
	// Base UI's indeterminate default text is untranslated English: replace it
	// with ours, or with nothing (aria-valuetext is then omitted).
	const indeterminateText = indeterminate
		? { 'aria-valuetext': valueText }
		: {};
	return (
		<BaseProgress.Root
			className="sup-progress"
			value={ indeterminate ? null : value }
			max={ max }
			getAriaValueText={
				valueText === undefined ? undefined : () => valueText
			}
			{ ...indeterminateText }
		>
			<BaseProgress.Label className="sup-progress__label">
				{ label }
			</BaseProgress.Label>
			{ ( ! indeterminate || valueText !== undefined ) && (
				<BaseProgress.Value
					className="sup-progress__value"
					aria-hidden="true"
				>
					{ ( formatted ) => valueText ?? formatted }
				</BaseProgress.Value>
			) }
			<BaseProgress.Track className="sup-progress__track">
				<BaseProgress.Indicator className="sup-progress__indicator" />
			</BaseProgress.Track>
		</BaseProgress.Root>
	);
}
