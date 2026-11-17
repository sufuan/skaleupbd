import { t } from '@/lib/i18n';

export const fmt = (n) => '৳' + Math.round(Number(n) || 0).toLocaleString('en-US');

export const STATUS_PILL = {
    Pending: 'amber', Processing: 'blue', Shipped: '', Delivered: 'green',
    Returned: 'red', Cancelled: '', Incomplete: 'amber', 'Pending Return': 'red',
    'Pending Cancel': 'red', Partial: 'blue', Lost: 'red',
};

export function StatusPill({ status }) {
    return <span className={`pill ${STATUS_PILL[status] || ''}`}>{t(status)}</span>;
}

/** Success-rate ring + one-line verdict, as in the prototype Order List. */
export function SuccessRing({ fraud, size = 44 }) {
    const rate = fraud?.rate;
    const verdict = fraud?.verdict || 'not_checked';
    const color = verdict === 'safe' ? 'var(--ok)' : verdict === 'risky' ? 'var(--bad)' : '#b8a8d4';
    const r = (size - 6) / 2, c = 2 * Math.PI * r;
    const frac = rate === null || rate === undefined ? 0 : rate / 100;
    const label = verdict === 'safe' ? 'Safe' : verdict === 'risky' ? 'Risky — take advance' : 'Not checked';
    return (
        <div className="flex" style={{ gap: 9 }}>
            <svg width={size} height={size} style={{ transform: 'rotate(-90deg)', flexShrink: 0 }}>
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--donut-track, #efe9f8)" strokeWidth="5" />
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color} strokeWidth="5" strokeLinecap="round" strokeDasharray={`${frac * c} ${c}`} />
                <text x="50%" y="50%" textAnchor="middle" dominantBaseline="central" fontSize="11" fontWeight="700" fill="var(--ink)" style={{ transform: 'rotate(90deg)', transformOrigin: 'center' }}>{rate === null || rate === undefined ? '—' : `${rate}%`}</text>
            </svg>
            <div>
                <div className="b sm" style={{ color }}>{t(label)}</div>
                {fraud?.note && <div className="sm mut" style={{ maxWidth: 170 }}>{fraud.note}</div>}
            </div>
        </div>
    );
}
