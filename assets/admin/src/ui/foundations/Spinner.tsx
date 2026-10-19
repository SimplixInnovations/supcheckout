/**
 * Spinner: an indeterminate activity indicator.
 *
 * Without a label it is decorative. With a translated label it is a polite
 * `status` whose text is visually hidden.
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
		<span className={ className } role="status">
			<span className="sup-spinner__ring" aria-hidden="true" />
			<VisuallyHidden>{ label }</VisuallyHidden>
		</span>
	);
}
