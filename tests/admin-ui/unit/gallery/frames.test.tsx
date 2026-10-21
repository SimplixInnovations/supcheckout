/**
 * The gallery renders exactly the frames SPECIMEN_GROUPS and EXTRA_GROUPS
 * list (SP1 Task 8): per view, in list order, each frame holding exactly
 * its specimens, and every specimen of the view framed once except the
 * ungrouped behavioral fixtures. The visual suite iterates the same list,
 * so a specimen can never render without a baseline or the other way round.
 */
import { act, cleanup, render } from '@testing-library/react';

import { LIVE_REGION_DELAY_MS, SupRoot } from '../../../../assets/admin/src/ui';
import { FocusForcedColorsFrame } from '../../gallery/specimens/focus';
import {
	EXTRA_GROUPS,
	GALLERY_VIEWS,
	SPECIMEN_GROUPS,
	UNGROUPED_SPECIMENS,
} from '../../gallery/specimens/index';
import type { GalleryView, SpecimenGroup } from '../../gallery/specimens/index';
import { GalleryContent } from '../../gallery/views';
import { mockMatchMedia, renderInModes } from '../support/render';

// The whole AppShell view in jsdom, then axe in four modes, on a loaded runner.
const TIMEOUT_MS = 60_000;
jest.setTimeout( TIMEOUT_MS );

beforeEach( () => {
	mockMatchMedia( false );
} );

afterEach( () => {
	act( () => cleanup() );
} );

function framesOf( view: GalleryView ): readonly SpecimenGroup[] {
	return [ ...SPECIMEN_GROUPS, ...EXTRA_GROUPS ].filter(
		( group ) => group.view === view && group.capture === 'frame'
	);
}

async function renderView( view: GalleryView ): Promise< HTMLElement > {
	const { container } = render(
		<SupRoot preference="light">
			<GalleryContent view={ view } />
		</SupRoot>
	);
	await act( async () => {
		await new Promise( ( resolve ) =>
			setTimeout( resolve, LIVE_REGION_DELAY_MS * 2 )
		);
	} );
	return container;
}

const attributes = ( nodes: Iterable< Element >, name: string ) =>
	Array.from( nodes, ( node ) => node.getAttribute( name ) ?? '' );

test.each( GALLERY_VIEWS )(
	'the %s view renders its frames in SPECIMEN_GROUPS order with exactly their specimens',
	async ( view ) => {
		const container = await renderView( view );
		const frames = Array.from(
			container.querySelectorAll( '[data-specimen-group]' )
		);
		const expected = framesOf( view );
		expect( attributes( frames, 'data-specimen-group' ) ).toEqual(
			expected.map( ( group ) => group.name )
		);
		frames.forEach( ( frame, index ) => {
			expect(
				attributes(
					frame.querySelectorAll( '[data-specimen]' ),
					'data-specimen'
				)
			).toEqual( expected[ index ].specimens );
		} );
		expect(
			container.querySelectorAll(
				'[data-specimen-group] [data-specimen-group]'
			)
		).toHaveLength( 0 );
	}
);

test.each( GALLERY_VIEWS )(
	'every specimen of the %s view sits in a frame unless it is an ungrouped fixture',
	async ( view ) => {
		const container = await renderView( view );
		const loose = Array.from(
			container.querySelectorAll( '[data-specimen]' )
		).filter( ( node ) => ! node.closest( '[data-specimen-group]' ) );
		expect( attributes( loose, 'data-specimen' ) ).toEqual(
			UNGROUPED_SPECIMENS[ view ]
		);
	}
);

test.each( GALLERY_VIEWS )(
	'frames in the %s view shrink-wrap: their grid container aligns items to the start',
	async ( view ) => {
		const container = await renderView( view );
		const parents = new Set(
			Array.from(
				container.querySelectorAll( '[data-specimen-group]' ),
				( frame ) => frame.parentElement
			)
		);
		expect( parents.size ).toBeGreaterThan( 0 );
		for ( const parent of parents ) {
			expect( parent?.style.display ).toBe( 'grid' );
			expect( parent?.style.justifyItems ).toBe( 'start' );
		}
	}
);

test( 'element captures render no frame in either view', async () => {
	const names = SPECIMEN_GROUPS.filter(
		( group ) => group.capture === 'element'
	).map( ( group ) => group.name );
	for ( const view of GALLERY_VIEWS ) {
		const container = await renderView( view );
		for ( const name of names ) {
			expect(
				container.querySelector( `[data-specimen-group="${ name }"]` )
			).toBeNull();
		}
		act( () => cleanup() );
	}
} );

test( 'the forced-colors focus frame has no axe violations in four modes', async () => {
	await renderInModes( <FocusForcedColorsFrame /> );
} );
