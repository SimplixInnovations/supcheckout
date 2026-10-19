/**
 * Numeric: an amount, identifier or count that keeps its order inside RTL
 * text (`12.500 KWD`, `#10234`). It is isolated as LTR with `<bdi dir="ltr">`
 * and set in tabular figures.
 */
export type NumericKind = 'amount' | 'id' | 'count';

export interface NumericProps {
	/** Already-formatted value. */
	children: string;
	kind?: NumericKind;
}

export function Numeric( { children, kind = 'count' }: NumericProps ) {
	return (
		<bdi dir="ltr" className={ `sup-numeric sup-numeric--${ kind }` }>
			{ children }
		</bdi>
	);
}
