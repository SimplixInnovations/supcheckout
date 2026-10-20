/**
 * Every control specimen has no axe violation (WCAG 2.2 AA) in light-ltr,
 * dark-ltr, light-rtl and dark-rtl (SP1 Task 5).
 */
import { ControlsSpecimens } from '../../gallery/specimens/controls';
import { mockMatchMedia, renderInModes } from '../support/render';

// axe over every specimen in four modes is slow on a loaded runner.
jest.setTimeout( 60_000 );

test( 'controls have no axe violations in four modes', async () => {
	mockMatchMedia( false );
	await renderInModes( <ControlsSpecimens /> );
} );
