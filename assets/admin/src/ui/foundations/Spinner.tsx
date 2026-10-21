/**
 * Spinner: an indeterminate activity indicator.
 *
 * Without a label it is decorative. With a translated label it is a polite
 * `status` whose text is visually hidden.
 *
 * A status region announces reliably only when its text changes after the
 * region is mounted; a spinner mounted together with its label may be
 * silent. It suits "this area is busy", not alerts. Toast and Notice (Task 6)
 * must announce through a live region that is mounted before the message.
 */
import { VisuallyHidden } from './VisuallyHidden';

export type SpinnerSize = 16 | 20;

export interface SpinnerProps {
	label?: string;
	size?: SpinnerSize;
}

export function Spinner( { label, size = 20 }: SpinnerProps ) {
	const className = `sup-spinner sup-spinner--${ size }`;
	if ( ! label ) {
		return (
			<span className={ className } aria-hidden="true">
				<span className="sup-spinner__ring" />
			</span>
		);
	}
	return (
		<span className={ className } role="status" aria-live="polite">
			<span className="sup-spinner__ring" aria-hidden="true" />
			<VisuallyHidden>{ label }</VisuallyHidden>
		</span>
	);
}
