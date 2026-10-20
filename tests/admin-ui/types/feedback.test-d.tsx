/**
 * Compile-time API contracts of the display and feedback components (SP1
 * Task 6).
 *
 * Type-checked, never run: `npm run typecheck` includes this file through
 * tsconfig.tests.json. Each `@ts-expect-error` must meet a real error, so a
 * contract that stops being enforced fails the typecheck as an unused
 * directive (TS2578).
 */
import {
	Badge,
	Button,
	Card,
	EmptyState,
	IconButton,
	Notice,
	ProgressBar,
	Skeleton,
	SkeletonGroup,
	StatusPill,
	Stepper,
	Tooltip,
	useToast,
} from '../../../assets/admin/src/ui';

const noop = () => undefined;
const steps = [ { id: 'a', label: 'Connect' } ];

// Usages the contracts allow.
export const allowed = [
	<Card key="a" title="Connection" headingLevel={ 3 } as="div" actions="x">
		Body
	</Card>,
	<Badge key="b" tone="accent">
		New
	</Badge>,
	<StatusPill key="c" status="neutral">
		Not connected
	</StatusPill>,
	<Notice key="d" tone="warning" title="Test mode" live onDismiss={ noop }>
		Payments go to the sandbox.
	</Notice>,
	<EmptyState
		key="e"
		icon="inbox"
		title="No payments"
		description="None yet."
		headingLevel={ 3 }
		action={ <Button>Refresh</Button> }
	/>,
	<SkeletonGroup key="f" label="payments">
		<Skeleton shape="circle" height="control" />
		<Skeleton width="40%" height="3rem" />
	</SkeletonGroup>,
	<ProgressBar key="g" label="Import" value={ 3 } max={ 10 } valueText="3" />,
	<Stepper
		key="h"
		label="Setup"
		steps={ steps }
		currentId="a"
		completedIds={ [] }
	/>,
	<Tooltip key="i" content="Copies the ID" side="inline-end">
		<Button>Copy</Button>
	</Tooltip>,
	<IconButton key="j" icon="copy" label="Copy" showTooltip />,
];

export function ToastUsage() {
	const toast = useToast();
	const id: string = toast.show( {
		tone: 'success',
		title: 'Saved',
		description: 'Settings are live.',
		action: { label: 'Undo', onClick: noop },
	} );
	toast.dismiss( id );
	// @ts-expect-error toasts are success, info or error; a warning is a Notice
	toast.show( { tone: 'warning', title: 'Careful' } );
	// @ts-expect-error a toast needs a title
	toast.show( { tone: 'info' } );
	return null;
}

// Usages the contracts reject.
export const rejected = [
	// @ts-expect-error a Card heading is level 2, 3 or 4
	<Card key="a" title="x" headingLevel={ 1 }>
		Body
	</Card>,
	// @ts-expect-error a Badge is neutral or accent; status uses StatusPill
	<Badge key="b" tone="danger">
		x
	</Badge>,
	// @ts-expect-error a StatusPill's text is a plain string
	<StatusPill key="c" status="success">
		<strong>Live</strong>
	</StatusPill>,
	// @ts-expect-error a StatusPill needs a status
	<StatusPill key="d">Live</StatusPill>,
	// @ts-expect-error a Notice needs a tone
	<Notice key="e">Body</Notice>,
	// @ts-expect-error Notice tones are info, success, warning and error
	<Notice key="f" tone="danger">
		Body
	</Notice>,
	// @ts-expect-error an EmptyState needs a description
	<EmptyState key="g" title="No payments" />,
	// @ts-expect-error a SkeletonGroup needs its loading label
	<SkeletonGroup key="h">
		<Skeleton />
	</SkeletonGroup>,
	// @ts-expect-error Skeleton shapes are line, block and circle
	<Skeleton key="i" shape="square" />,
	// @ts-expect-error a ProgressBar needs a label
	<ProgressBar key="j" value={ 3 } />,
	// @ts-expect-error a Stepper needs its completed steps
	<Stepper key="k" label="Setup" steps={ steps } currentId="a" />,
	// @ts-expect-error a Tooltip wraps one element, not text
	<Tooltip key="l" content="x">
		Copy
	</Tooltip>,
	// @ts-expect-error Tooltip sides are logical: no left or right
	<Tooltip key="m" content="x" side="left">
		<Button>Copy</Button>
	</Tooltip>,
];
