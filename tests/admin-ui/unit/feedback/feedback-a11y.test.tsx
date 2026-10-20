/**
 * Every display and feedback specimen has no axe violation (WCAG 2.2 AA) in
 * light-ltr, dark-ltr, light-rtl and dark-rtl (SP1 Task 6).
 */
import { FeedbackSpecimens } from '../../gallery/specimens/feedback';
import { mockMatchMedia, renderInModes } from '../support/render';

test( 'feedback components have no axe violations in four modes', async () => {
	mockMatchMedia( false );
	await renderInModes( <FeedbackSpecimens /> );
} );
