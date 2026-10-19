/**
 * Placeholder body of the admin app, loaded as a lazy chunk.
 */
interface PlaceholderProps {
	/** Already-translated title. */
	title: string;
}

export default function Placeholder( { title }: PlaceholderProps ) {
	return <p className="sup-app-placeholder">{ title }</p>;
}
