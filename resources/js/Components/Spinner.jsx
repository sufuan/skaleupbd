// Small inline loading spinner (SVG with a SMIL rotation, so it
// needs no CSS). Used on primary buttons while their request is in
// flight (Round 2 finding 16).
export default function Spinner({ size = 14, color = 'currentColor' }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true"
            style={{ display: 'inline-block', verticalAlign: -2, marginRight: 6 }}>
            <circle cx="12" cy="12" r="9" stroke={color} strokeOpacity="0.25" strokeWidth="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke={color} strokeWidth="3" strokeLinecap="round">
                <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite" />
            </path>
        </svg>
    );
}
