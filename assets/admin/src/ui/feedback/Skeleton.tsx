/**
 * Skeleton and SkeletonGroup: placeholders that hold the layout while data
 * loads, so content does not jump when it arrives.
 *
 * A Skeleton is always `aria-hidden`: its shape means nothing to assistive
 * technology. Wrap skeletons in one SkeletonGroup per loading area. The
 * group marks its placeholders `aria-busy` and announces "Loading {label}…"
 * once through a polite status that sits outside the busy subtree and is
 * mounted empty before its text (see liveRegion.ts). Replace the whole group
 * with the content when it loads.
 *
 * The shimmer is a slow pulse between two decorative tokens; it stops under
 * reduced motion.
 */
import type { CSSProperties, ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { classNames } from '../controls/classNames';
import { useAfterFirstCommit } from './liveRegion';
import './Skeleton.css';

export type SkeletonShape = 'line' | 'block' | 'circle';
export type SkeletonHeight = 'text' | 'title' | 'control';

export interface SkeletonProps {
	/** `line` (default), `block` or `circle`. */
	shape?: SkeletonShape;
	/** Any CSS length or percentage for the inline size, e.g. `60%`. */
	width?: string;
	/** A preset matching our type and control sizes, or any CSS length. */
	height?: SkeletonHeight | ( string & Record< never, never > );
}

const HEIGHT_PRESETS: ReadonlySet< string > = new Set( [
	'text',
	'title',
	'control',
] );

export function Skeleton( {
	shape = 'line',
	width,
	height = shape === 'block' ? 'control' : 'text',
}: SkeletonProps ) {
	const preset = HEIGHT_PRESETS.has( height ) ? height : undefined;
	const style: CSSProperties = {};
	if ( width !== undefined && shape !== 'circle' ) {
		style.inlineSize = width;
	}
	if ( preset === undefined ) {
		style.blockSize = height;
	}
	return (
		<span
			className={ classNames(
				'sup-skeleton',
				`sup-skeleton--${ shape }`,
				preset && `sup-skeleton--height-${ preset }`
			) }
			style={ style }
			aria-hidden="true"
		/>
	);
}

export interface SkeletonGroupProps {
	/**
	 * Translated name of what is loading, in the middle of a sentence:
	 * "payments" is announced as "Loading payments…".
	 */
	label: string;
	children: ReactNode;
}

export function SkeletonGroup( { label, children }: SkeletonGroupProps ) {
	const committed = useAfterFirstCommit();
	return (
		<div className="sup-skeleton-group">
			<div className="sup-skeleton-group__shapes" aria-busy="true">
				{ children }
			</div>
			<span role="status" className="sup-visually-hidden">
				{ committed &&
					sprintf(
						/* translators: %s: what is loading, e.g. "payments". */
						__( 'Loading %s…', 'supcheckout' ),
						label
					) }
			</span>
		</div>
	);
}
