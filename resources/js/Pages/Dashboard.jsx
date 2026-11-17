import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const VIEWS = ['My Dashboard', 'Overview', 'Decision', 'Operations', 'Products', 'Marketing', 'Customers & Team'];

function Kpi({ label, value, delta, prev }) {
    return (
        <div className="card kpi">
            <div className="lb"><span>{t(label)}</span><span className="pill">{t('Today')}</span></div>
            <div className="vl">{value}</div>
            {delta && <div className="dl"><b>↗ {delta}</b> &nbsp;{t('Previous')} {prev}</div>}
        </div>
    );
}

function Donut({ size = 190, stroke = 26, parts }) {
    const r = (size - stroke) / 2, c = 2 * Math.PI * r, total = parts.reduce((a, p) => a + p.v, 0) || 1;
    let off = 0;
    return (
        <svg width={size} height={size} style={{ transform: 'rotate(-90deg)' }}>
            <circle cx={size / 2} cy={size / 2} r={r} fill="none" style={{ stroke: 'var(--donut-track, #efe9f8)' }} strokeWidth={stroke} />
            {parts.map((p, i) => {
                const frac = p.v / total, el = <circle key={i} cx={size / 2} cy={size / 2} r={r} fill="none" stroke={p.color} strokeWidth={stroke} strokeDasharray={`${frac * c} ${c}`} strokeDashoffset={-off * c} />;
                off += frac; return el;
            })}
        </svg>
    );
}

const fmt = (n) => '৳' + Math.round(Number(n) || 0).toLocaleString('en-US');
const PALETTE = ['#5b2a86', '#411550', '#7a4bb3', '#9570c4', '#c9b3ec', '#e3d5f7'];

function MyDashboard({ stats }) {
    const rows = [
        { label: 'Pending', v: stats.pending, color: '#a678d8' },
        { label: 'Processing', v: stats.processing, color: '#7a4bb3' },
        { label: 'Shipped', v: stats.shipped, color: '#9570c4' },
        { label: 'Delivered', v: stats.delivered, color: '#411550' },
        { label: 'Returned', v: stats.returned, color: '#c9b3ec' },
        { label: 'Cancelled', v: stats.cancelled, color: '#e3d5f7' },
    ];
    const max = Math.max(1, ...rows.map((r) => r.v));
    const top = stats.sources[0];
    return (
        <>
            <div className="kpis">
                <Kpi label="Total Orders" value={String(stats.total)} />
                <Kpi label="Revenue" value={fmt(stats.revenue)} />
                <Kpi label="Profit" value={fmt(stats.profit)} />
                <Kpi label="Pending Web Orders" value={String(stats.pending + stats.incomplete)} />
            </div>
            <div className="dash-grid">
                <div className="card">
                    <div className="flex"><h3>{t('Web Order Report')}</h3><span className="spacer" /><span className="pill">{t('Today')} ⌄</span></div>
                    <div className="ptabs" style={{ marginTop: 10 }}>
                        <span className="ptab act">{t('Web Orders')}</span><span className="ptab">{t('Incomplete Orders')}</span>
                    </div>
                    {stats.total === 0 ? (
                        <div className="empty-box">
                            <div className="b" style={{ color: 'var(--ink)' }}>{t('No web orders in this period')}</div>
                            <div className="sm" style={{ marginTop: 4 }}>{t('The selected date range has no web orders to chart.')}</div>
                        </div>
                    ) : rows.map((r) => (
                        <div className="funnel-row" key={r.label}>
                            <span style={{ width: 110 }}>{t(r.label)}</span>
                            <div className="funnel-bar" style={{ width: `${Math.max(4, (r.v / max) * 220)}px`, background: r.color }} />
                            <b>{r.v}</b>
                        </div>
                    ))}
                </div>
                <div className="card">
                    <div className="flex"><h3>{t('Orders by Source')}</h3><span className="spacer" /><span className="pill">▼ {t('Approved')}</span><span className="pill">{t('Today')}</span></div>
                    <div className="flex" style={{ justifyContent: 'center', margin: '14px 0 4px' }}>
                        <Donut parts={stats.sources.map((x, i) => ({ v: x.count, color: PALETTE[i % PALETTE.length] }))} />
                    </div>
                    <div className="sm" style={{ textAlign: 'center' }}>
                        {top && <><span className="legend-dot" style={{ background: '#5b2a86' }} />{top.label} ({top.count}) · {fmt(top.value)}</>}
                        <div className="b" style={{ marginTop: 8, fontSize: 14 }}>{t('Total Orders')}: {stats.total}</div>
                        <div className="mut">{t('Total Value')}: {fmt(stats.sources.reduce((a, x) => a + x.value, 0))}</div>
                    </div>
                </div>
            </div>
            <div className="card">
                <div className="flex"><div><h3>{t('Order Counts')}</h3><div className="card-sub">{t('Orders created vs sent to courier in the selected range')}</div></div>
                    <span className="spacer" /><span className="pill">{t('Last 30 days')} ⌄</span></div>
                <div className="flex" style={{ gap: 14 }}>
                    <div className="stat-box" style={{ minWidth: 130 }}>
                        <div className="sm mut">{t('Created')}</div><div style={{ fontSize: 26, fontWeight: 800, color: '#5b2a86' }}>{stats.total}</div>
                    </div>
                    <div className="stat-box alt" style={{ minWidth: 130 }}>
                        <div className="sm mut">{t('Sent to Courier')}</div><div style={{ fontSize: 26, fontWeight: 800 }}>{stats.sent}</div>
                    </div>
                </div>
            </div>
        </>
    );
}

function Overview({ stats }) {
    const total = Math.max(1, stats.total);
    const funnel = [
        { label: 'Web Orders', v: stats.total, w: 100, color: '#5b2a86' },
        { label: 'Incomplete Orders', v: stats.incomplete, w: (stats.incomplete / total) * 100, color: '#a678d8' },
        { label: 'Cancelled', v: stats.cancelled, w: (stats.cancelled / total) * 100, color: '#c9b3ec' },
        { label: 'Processing', v: stats.processing, w: (stats.processing / total) * 100, color: '#7a4bb3' },
        { label: 'Shipped', v: stats.shipped, w: (stats.shipped / total) * 100, color: '#9570c4' },
        { label: 'Delivered', v: stats.delivered, w: (stats.delivered / total) * 100, color: '#411550' },
    ];
    const pillFor = (st) => st === 'Pending' ? 'amber' : st === 'Processing' ? 'blue' : '';
    return (
        <>
            <div className="kpis">
                <Kpi label="Total Orders" value={String(stats.total)} />
                <Kpi label="Approved Orders" value={String(stats.approved)} />
                <Kpi label="Revenue" value={fmt(stats.revenue)} />
                <Kpi label="Profit" value={fmt(stats.profit)} />
            </div>
            <div className="dash-grid">
                <div className="card">
                    <h3>{t('Web Order Funnel')}</h3>
                    <div className="card-sub">{t('Last 30 days')} · {t('All numbers come from the same accounts')}</div>
                    {funnel.map((f) => (
                        <div className="funnel-row" key={f.label}>
                            <span style={{ width: 130 }}>{t(f.label)}</span>
                            <div className="funnel-bar" style={{ width: `${Math.max(3, f.w * 2.4)}px`, background: f.color }} />
                            <b>{f.v}</b>{f.label === 'Cancelled' && stats.total > 0 && <span className="mut sm">({Math.round((stats.cancelled / stats.total) * 100)}%)</span>}
                        </div>
                    ))}
                    <div className="mt16 sm note-box" style={{ padding: '10px 12px' }}>
                        <b>{t('Confidence 65%')}</b> — {t('How much of today\'s rules are working properly (orders, stock and courier together).')}
                    </div>
                </div>
                <div className="card">
                    <div className="flex"><h3>{t('Orders by Source')}</h3><span className="spacer" /><span className="pill">{t('Last 30 days')} ⌄</span></div>
                    <div className="flex" style={{ justifyContent: 'center', margin: '12px 0 4px' }}>
                        <Donut parts={[{ v: stats.processing, color: '#5b2a86' }, { v: stats.delivered, color: '#411550' }, { v: stats.cancelled, color: '#c9b3ec' }, { v: stats.shipped, color: '#9570c4' }, { v: stats.incomplete, color: '#e3d5f7' }]} />
                    </div>
                    <div className="sm" style={{ textAlign: 'center' }}>
                        <div><span className="legend-dot" style={{ background: '#7a4bb3' }} />{t('Processing')} {stats.processing} · <span className="legend-dot" style={{ background: '#411550' }} />{t('Delivered')} {stats.delivered}</div>
                        <div className="mut" style={{ marginTop: 3 }}>{t('Cancelled')} {stats.cancelled} · {t('Shipped')} {stats.shipped} · {t('Incomplete')} {stats.incomplete}</div>
                        <div className="b" style={{ marginTop: 8, fontSize: 14 }}>{t('Total Orders')}: {stats.total}</div>
                    </div>
                    <div className="mt16 flex" style={{ gap: 12 }}>
                        <div className="stat-box" style={{ flex: 1, textAlign: 'center', padding: '12px 18px' }}>
                            <div className="sm mut">{t('Created')}</div><div style={{ fontSize: 22, fontWeight: 800, color: '#5b2a86' }}>{stats.total}</div>
                        </div>
                        <div className="stat-box alt" style={{ flex: 1, textAlign: 'center', padding: '12px 18px' }}>
                            <div className="sm mut">{t('Sent to Courier')}</div><div style={{ fontSize: 22, fontWeight: 800 }}>{stats.sent}</div>
                        </div>
                        <div className="stat-box alt" style={{ flex: 1, textAlign: 'center', padding: '12px 18px' }}>
                            <div className="sm mut">{t('Created Today')}</div><div style={{ fontSize: 22, fontWeight: 800 }}>{stats.created_today}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div className="card">
                <h3>{t('Courier Table — Orders Not Sent To Courier')}</h3>
                <div className="card-sub">{stats.courier_rows.length} {t('orders')}</div>
                <table className="tbl">
                    <thead><tr><th>{t('Invoice')}</th><th>{t('Customer')}</th><th>{t('Courier')}</th><th>{t('Status')}</th></tr></thead>
                    <tbody>
                        {stats.courier_rows.map((r) => (
                            <tr key={r.invoice}><td className="b">{r.invoice}</td><td>{r.name}</td><td>{r.courier}</td><td><span className={`pill ${pillFor(r.status)}`}>{t(r.status)}</span></td></tr>
                        ))}
                        {stats.courier_rows.length === 0 && <tr><td colSpan="4" className="mut">{t('No orders are waiting')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </>
    );
}

export default function Dashboard({ stats }) {
    const [view, setView] = useState('Overview');
    return (
        <AppLayout>
            <Head title={t('Dashboard')} />
            <div className="ptabs">
                {VIEWS.map((v) => <span key={v} className={`ptab ${view === v ? 'act' : ''}`} onClick={() => setView(v)}>{t(v)}</span>)}
                <span className="spacer" />
                <span className="pill" style={{ alignSelf: 'center' }}>{t('Today')} ⌄</span>
            </div>
            {view === 'My Dashboard' && <MyDashboard stats={stats} />}
            {view === 'Overview' && <Overview stats={stats} />}
            {!['My Dashboard', 'Overview'].includes(view) && (
                <div className="card" style={{ textAlign: 'center', padding: '52px 18px' }}>
                    <h3>{t(view)}</h3>
                    <p className="mut" style={{ marginTop: 8 }}>{t('Coming Soon')}</p>
                </div>
            )}
        </AppLayout>
    );
}
