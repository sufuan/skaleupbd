import { t } from '@/lib/i18n';

export const fmt = (n) => '৳' + Math.round(Number(n) || 0).toLocaleString('en-US');

export const STATUS_PILL = {
    Pending: 'amber', Processing: 'blue', Shipped: '', Delivered: 'green',
    Returned: 'red', Cancelled: '', Incomplete: 'amber', 'Pending Return': 'red',
    'Pending Cancel': 'red', Lost: 'red',
};

export function StatusPill({ status }) {
    return <span className={`pill ${STATUS_PILL[status] || ''}`}>{t(status)}</span>;
}

/**
 * The prototype Order List success cell, copied from orders.html ringHTML +
 * verdictHTML: a 28px ring beside "Success: X% / Order: d/t" lines, with the
 * verdict written underneath.
 */
export function SuccessCell({ fraud }) {
    const rate = fraud?.rate;
    const verdict = fraud?.verdict || 'not_checked';
    const d = fraud?.delivered ?? 0;
    const total = fraud?.orders ?? 0;
    const has = rate !== null && rate !== undefined;
    const pct = has ? Math.round(rate) : null;
    const color = !has ? '#CBD5E1' : pct >= 70 ? '#16A34A' : pct >= 40 ? '#F59E0B' : '#EF4444';
    const off = has ? (69.1 * (1 - pct / 100)).toFixed(1) : 69.1;
    const vLabel = verdict === 'safe' ? 'Safe' : verdict === 'risky' ? 'Risky - take advance' : 'Not checked';
    const vColor = verdict === 'safe' ? '#16A34A' : verdict === 'risky' ? '#DC2626' : '#64748B';
    return (
        <span style={{ display: 'inline-block' }}>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                <svg width="28" height="28" viewBox="0 0 28 28">
                    <circle cx="14" cy="14" r="11" fill="none" stroke="#E5E7EB" strokeWidth="3.5" />
                    <circle cx="14" cy="14" r="11" fill="none" stroke={color} strokeWidth="3.5" strokeLinecap="round" strokeDasharray="69.1" strokeDashoffset={off} transform="rotate(-90 14 14)" />
                </svg>
                <span style={{ fontSize: 12, lineHeight: 1.5, whiteSpace: 'nowrap' }}>
                    <span style={{ color: has ? color : '#94A3B8', fontWeight: 600 }}>{t('Success')}: {has ? `${pct}%` : '-'}</span><br />
                    {t('Order')}: {d}/{total}
                </span>
            </span><br />
            <span style={{ fontSize: 11.5, fontWeight: 600, color: vColor }}>{t(vLabel)}</span>
        </span>
    );
}

/** Success-rate ring + one-line verdict, as in the prototype Order List. */
export function SuccessRing({ fraud, size = 44 }) {
    const rate = fraud?.rate;
    const verdict = fraud?.verdict || 'not_checked';
    const color = verdict === 'safe' ? 'var(--ok)' : verdict === 'risky' ? 'var(--bad)' : '#b8a8d4';
    const r = (size - 6) / 2, c = 2 * Math.PI * r;
    const frac = rate === null || rate === undefined ? 0 : rate / 100;
    const label = verdict === 'safe' ? 'Safe' : verdict === 'risky' ? 'Risky - take advance' : 'Not checked';
    return (
        <div className="flex" style={{ gap: 9 }}>
            <svg width={size} height={size} style={{ transform: 'rotate(-90deg)', flexShrink: 0 }}>
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--donut-track, #efe9f8)" strokeWidth="5" />
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color} strokeWidth="5" strokeLinecap="round" strokeDasharray={`${frac * c} ${c}`} />
                <text x="50%" y="50%" textAnchor="middle" dominantBaseline="central" fontSize="11" fontWeight="700" fill="var(--ink)" style={{ transform: 'rotate(90deg)', transformOrigin: 'center' }}>{rate === null || rate === undefined ? '-' : `${rate}%`}</text>
            </svg>
            <div>
                <div className="b sm" style={{ color }}>{t(label)}</div>
                {fraud?.note && <div className="sm mut" style={{ maxWidth: 170 }}>{fraud.note}</div>}
            </div>
        </div>
    );
}
