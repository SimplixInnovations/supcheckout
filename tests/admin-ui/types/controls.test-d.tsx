/**
 * Compile-time API contracts of the actions and form controls (SP1 Task 5).
 *
 * Type-checked, never run: `npm run typecheck` includes this file through
 * tsconfig.tests.json. Each `@ts-expect-error` must meet a real error, so a
 * contract that stops being enforced fails the typecheck as an unused
 * directive (TS2578).
 */
import {
	Button,
	Checkbox,
	Field,
	IconButton,
	RadioGroup,
	SecretInput,
	Select,
	TextInput,
	Toggle,
} from '../../../assets/admin/src/ui';

const noop = () => undefined;

// Usages the contracts allow.
export const allowed = [
	<Button key="a" variant="primary" size="sm" busy busyLabel="Saving">
		Save
	</Button>,
	<Button key="b" iconStart="refresh" iconEnd="chevron-end" type="submit">
		Sync
	</Button>,
	<IconButton key="c" icon="close" label="Close" />,
	<Field key="d" label="Store name" required>
		{ ( control ) => <TextInput { ...control } /> }
	</Field>,
	<SecretInput key="e" label="API key" hasSavedValue onChange={ noop } />,
	<Select key="f" options={ [ { value: 'kwd', label: 'KWD' } ] } invalid />,
	<Checkbox key="g" label="Select all" indeterminate />,
	<RadioGroup
		key="h"
		legend="Mode"
		name="mode"
		value="a"
		onChange={ ( value: string ) => value }
		options={ [ { value: 'a', label: 'A', icon: 'sun' } ] }
		variant="segmented"
	/>,
	<Toggle key="i" label="Test mode" checked onChange={ noop } />,
];

// Usages the contracts reject.
export const rejected = [
	// @ts-expect-error label is required
	<IconButton key="a" icon="close" />,
	<SecretInput
		key="b"
		label="API key"
		hasSavedValue
		// @ts-expect-error a saved secret is never passed in
		value="sk_live"
		onChange={ noop }
	/>,
	<SecretInput
		key="c"
		label="API key"
		hasSavedValue
		// @ts-expect-error nor as a default value
		defaultValue="sk_live"
		onChange={ noop }
	/>,
	// @ts-expect-error the saved state is required, never inferred
	<SecretInput key="d" label="API key" onChange={ noop } />,
	// @ts-expect-error only the four variants exist
	<Button key="e" variant="link">
		Save
	</Button>,
	// @ts-expect-error icons come from the IconName union
	<Button key="f" iconStart="rocket">
		Save
	</Button>,
	// @ts-expect-error IconButton takes no visible children
	<IconButton key="g" icon="close" label="Close">
		Close
	</IconButton>,
	<Field key="h" label="Store name">
		{ /* @ts-expect-error Field children is a render function, not an element */ }
		<TextInput />
	</Field>,
	// @ts-expect-error RadioGroup needs a legend
	<RadioGroup
		key="i"
		name="mode"
		value="a"
		onChange={ noop }
		options={ [] }
	/>,
	// @ts-expect-error Toggle is controlled: onChange is required
	<Toggle key="j" label="Test mode" checked />,
	// @ts-expect-error Select needs its options
	<Select key="k" />,
];
