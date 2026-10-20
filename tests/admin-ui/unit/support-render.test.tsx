/**
 * renderInModes lets delayed live-region text land before axe runs, with
 * real timers and with Jest's fake timers alike (fix round 2, item C).
 */
import { Notice } from '../../../assets/admin/src/ui';
import { renderInModes } from './support/render';

afterEach( () => {
	jest.useRealTimers();
} );

test( 'renderInModes completes under real timers', async () => {
	await renderInModes(
		<Notice tone="success" live>
			Settings saved.
		</Notice>
	);
} );

test( 'renderInModes completes under fake timers, advancing them itself', async () => {
	jest.useFakeTimers();
	await renderInModes(
		<Notice tone="success" live>
			Settings saved.
		</Notice>
	);
}, 10000 );
