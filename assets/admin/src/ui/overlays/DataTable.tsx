/**
 * DataTable: a semantic `<table>` for lists such as payments and orders.
 *
 * - The `<caption>` names the table (visually hidden with `captionHidden`
 *   when a heading above already says it) and the scroll region around it:
 *   `div[role=region][tabindex=0]`, so keyboard users can scroll a wide
 *   table (WCAG 2.1.1) and screen readers hear what the region holds.
 * - Headers are `<th scope="col">`. A sortable header holds a button named
 *   by its text; the sorted column's `<th>` carries `aria-sort`, so the
 *   state is announced on the header, and the caller owns the order
 *   (`sort`, `onSortChange`): the table never reorders rows itself.
 * - Numeric columns (amounts, order numbers, counts) are end-aligned and
 *   isolated LTR in tabular figures (`Numeric`), so `12.500 KWD` and
 *   `#10234` keep their order inside RTL rows (Review Focus 4).
 * - `loading` marks the table `aria-busy`, shows `skeletonRows` rows of
 *   hidden skeletons and fills a pre-mounted polite status outside the
 *   table, LIVE_REGION_DELAY_MS after it exists.
 * - No rows and not loading: `empty` spans every column (an EmptyState,
 *   ideally, saying why it is empty and how to fill it).
 */
import type { ReactNode } from 'react';
import { useId } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { classNames } from '../controls/classNames';
import { Icon } from '../foundations/Icon';
import { Numeric } from '../foundations/Numeric';
import { useLiveRegionReady } from '../feedback/liveRegion';
import { Skeleton } from '../feedback/Skeleton';
import './DataTable.css';

export type SortDirection = 'ascending' | 'descending';
export type ColumnAlign = 'start' | 'end';
export type TableDensity = 'comfortable' | 'compact';

export interface Column< Row > {
	id: string;
	/** Translated header text. */
	header: string;
	cell: ( row: Row ) => ReactNode;
	/** Defaults to `start`, or `end` for a numeric column. */
	align?: ColumnAlign;
	/** Amounts, identifiers and counts: end-aligned, isolated LTR. */
	numeric?: boolean;
	sortable?: boolean;
}

export interface TableSort {
	columnId: string;
	direction: SortDirection;
}

export interface DataTableProps< Row > {
	/** Translated caption; it names the table and its scroll region. */
	caption: string;
	/** Hide the caption visually; it still names the table. */
	captionHidden?: boolean;
	columns: readonly Column< Row >[];
	rows: readonly Row[];
	/** A stable, unique key per row. */
	rowKey: ( row: Row ) => string;
	loading?: boolean;
	/** Placeholder rows while loading; defaults to 5. */
	skeletonRows?: number;
	/** Shown across every column when there are no rows. */
	empty?: ReactNode;
	sort?: TableSort;
	/** Called with the id of a sortable column whose header was pressed. */
	onSortChange?: ( columnId: string ) => void;
	density?: TableDensity;
}

const DEFAULT_SKELETON_ROWS = 5;

function alignOf< Row >( column: Column< Row > ): ColumnAlign {
	return column.align ?? ( column.numeric ? 'end' : 'start' );
}

function cellClass< Row >( column: Column< Row > ): string {
	return classNames(
		'sup-table__cell',
		alignOf( column ) === 'end' && 'sup-table__cell--end'
	);
}

function renderCell< Row >( column: Column< Row >, row: Row ): ReactNode {
	const value = column.cell( row );
	if ( ! column.numeric ) {
		return value;
	}
	if ( typeof value === 'string' || typeof value === 'number' ) {
		return <Numeric>{ String( value ) }</Numeric>;
	}
	return (
		<bdi dir="ltr" className="sup-numeric">
			{ value }
		</bdi>
	);
}

export function DataTable< Row >( {
	caption,
	captionHidden = false,
	columns,
	rows,
	rowKey,
	loading = false,
	skeletonRows = DEFAULT_SKELETON_ROWS,
	empty,
	sort,
	onSortChange,
	density = 'comfortable',
}: DataTableProps< Row > ) {
	const captionId = useId();
	const statusReady = useLiveRegionReady( true );

	let body: ReactNode;
	if ( loading ) {
		body = Array.from( { length: skeletonRows }, ( _, index ) => (
			<tr key={ index } className="sup-table__row" aria-hidden="true">
				{ columns.map( ( column ) => (
					<td key={ column.id } className={ cellClass( column ) }>
						<Skeleton width={ column.numeric ? '60%' : '80%' } />
					</td>
				) ) }
			</tr>
		) );
	} else if ( rows.length === 0 ) {
		body = (
			<tr className="sup-table__row">
				<td
					className="sup-table__cell sup-table__empty"
					colSpan={ columns.length }
				>
					{ empty ?? __( 'Nothing to show yet.', 'supcheckout' ) }
				</td>
			</tr>
		);
	} else {
		body = rows.map( ( row ) => (
			<tr key={ rowKey( row ) } className="sup-table__row">
				{ columns.map( ( column ) => (
					<td key={ column.id } className={ cellClass( column ) }>
						{ renderCell( column, row ) }
					</td>
				) ) }
			</tr>
		) );
	}

	return (
		<div className="sup-table-wrap">
			<div
				className="sup-table-region"
				role="region"
				tabIndex={ 0 }
				aria-labelledby={ captionId }
				data-sup-density={ density }
			>
				<table
					className="sup-table"
					aria-busy={ loading ? true : undefined }
				>
					<caption
						id={ captionId }
						className={
							captionHidden
								? 'sup-visually-hidden'
								: 'sup-table__caption'
						}
					>
						{ caption }
					</caption>
					<thead>
						<tr className="sup-table__row">
							{ columns.map( ( column ) => {
								const sorted =
									sort?.columnId === column.id
										? sort.direction
										: undefined;
								return (
									<th
										key={ column.id }
										scope="col"
										className={ classNames(
											cellClass( column ),
											'sup-table__header'
										) }
										aria-sort={ sorted }
									>
										{ column.sortable ? (
											<button
												type="button"
												className={ classNames(
													'sup-table__sort',
													sorted &&
														`sup-table__sort--${ sorted }`
												) }
												onClick={ () =>
													onSortChange?.( column.id )
												}
											>
												<span>{ column.header }</span>
												<span
													className="sup-table__sort-icon"
													aria-hidden="true"
												>
													<Icon
														name="chevron-down"
														size={ 16 }
													/>
												</span>
											</button>
										) : (
											column.header
										) }
									</th>
								);
							} ) }
						</tr>
					</thead>
					<tbody>{ body }</tbody>
				</table>
			</div>
			<span role="status" className="sup-visually-hidden">
				{ statusReady && loading
					? sprintf(
							/* translators: %s: the table caption, e.g. "Recent payments". */
							__( 'Loading: %s', 'supcheckout' ),
							caption
						)
					: '' }
			</span>
		</div>
	);
}
