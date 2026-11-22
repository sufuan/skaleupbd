import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const VIEWS = ['My Dashboard', 'Overview', 'Decision', 'Operations', 'Products', 'Marketing', 'Customers & Team', 'Activity'];

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

const CalSvg = ({ size = 11 }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4" /><path d="M8 2v4" /><path d="M3 10h18" /></svg>
);

/** Display-only day label (not a control). */
const TodayPill = ({ label }) => (
    <span className="today-pill"><span style={{ marginRight: 4, display: 'inline-flex' }}><CalSvg /></span>{label}</span>
);

const BASIS_LABELS = { approved: 'Approved date', created: 'Web order date', courier: 'Courier date' };

/** The counting-basis switch: a real dropdown, applied server-side. */
function BasisSelect({ basis, onBasis }) {
    return (
        <select className="basis-sel" value={basis} onChange={(e) => onBasis(e.target.value)} title={t('Counted on')} aria-label={t('Counted on')}>
            <option value="approved">{t('Approved date')}</option>
            <option value="created">{t('Web order date')}</option>
            <option value="courier">{t('Courier date')}</option>
        </select>
    );
}

/** Display-only chip naming the active counting basis on cards the switch drives. */
const BasisChip = ({ basis }) => <span className="today-pill">{t(BASIS_LABELS[basis] || 'Approved date')}</span>;

function Delta({ k, money }) {
    if (k.pct === null) return <>{t('Previous')} {money ? fmt(k.prev) : String(Math.round(k.prev))}</>;
    return <><span className={k.pct >= 0 ? 'up' : 'down'}>{k.pct >= 0 ? '↗' : '↘'} {Math.abs(k.pct).toFixed(1)}%</span> &nbsp;{t('Previous')} {money ? fmt(k.prev) : String(Math.round(k.prev))}</>;
}

function DashKpi({ label, k, money, pill, hint, basis, onBasis }) {
    return (
        <div className="card kpi">
            <div className="kpi-top"><span className="kpi-lbl">{t(label)}</span>
                <span className="kpi-controls"><BasisSelect basis={basis} onBasis={onBasis} /><TodayPill label={pill} /></span></div>
            <div className="val">{money ? fmt(k.value) : String(Math.round(k.value))}</div>
            <div className="sub"><Delta k={k} money={money} /></div>
            {hint && <div className="sub" style={{ marginTop: 2 }}>{t(hint)}</div>}
        </div>
    );
}

const SRC_PALETTE = ['#4f46e5', '#2563eb', '#16A34A', '#D97706', '#DC2626', '#7C3AED', '#0891B2'];
const HOURS = ['12 AM', '1 AM', '2 AM', '3 AM', '4 AM', '5 AM', '6 AM', '7 AM', '8 AM', '9 AM', '10 AM', '11 AM', '12 PM', '1 PM', '2 PM', '3 PM', '4 PM', '5 PM', '6 PM', '7 PM', '8 PM', '9 PM', '10 PM', '11 PM'];

function MyDashboard({ my, onBasis }) {
    const pill = my.is_today ? t('Today') : my.day_short;
    const [worTab, setWorTab] = useState('web');
    const worVals = my.web_series.map((d) => (worTab === 'web' ? d.web : d.inc));
    const worMax = Math.max(1, ...worVals);
    const [srcMode, setSrcMode] = useState('Source');
    const [srcView, setSrcView] = useState('chart');
    const srcRows = srcMode === 'Source' ? my.sources : my.channels;
    const srcTotal = srcRows.reduce((a, x) => a + x.count, 0);
    const srcValue = srcRows.reduce((a, x) => a + x.value, 0);
    const [range, setRange] = useState(30);
    const slice = range === 30 ? my.counts30 : my.counts30.slice(-range);
    const cMax = Math.max(1, ...slice.map((d) => Math.max(d.created, d.sent)));
    const cSum = slice.reduce((a, d) => a + d.created, 0);
    const sSum = slice.reduce((a, d) => a + d.sent, 0);
    const [earn, setEarn] = useState(null);
    const [earnBusy, setEarnBusy] = useState(false);
    const loadEarn = () => {
        setEarnBusy(true);
        fetch('/dashboard/earnings', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json()).then((j) => { setEarn(j); setEarnBusy(false); })
            .catch(() => setEarnBusy(false));
    };
    const [hMode, setHMode] = useState('combined');
    const [hDay, setHDay] = useState('day');
    const hSeries = (hDay === 'day' ? my.hourly_day : my.hourly_prev)[hMode];
    const hMax = Math.max(1, ...hSeries);
    const hPoints = hSeries.map((v, h) => `${((h / 23) * 300).toFixed(1)},${(84 - (v / hMax) * 74).toFixed(1)}`).join(' ');
    const topMax = Math.max(1, ...my.top_products.map((p) => p.qty));
    return (
        <>
            <div className="kpi-grid">
                <DashKpi label="Total Orders" k={my.kpis.orders} pill={pill} basis={my.basis} onBasis={onBasis} />
                <DashKpi label="Revenue" k={my.kpis.revenue} money pill={pill} hint="Delivered orders only" basis={my.basis} onBasis={onBasis} />
                <DashKpi label="Profit" k={my.kpis.profit} money pill={pill} basis={my.basis} onBasis={onBasis} />
                <div className="card kpi">
                    <div className="kpi-top"><span className="kpi-lbl">{t('Pending Web Orders')}</span>
                        <span className="kpi-controls"><TodayPill label={pill} /></span></div>
                    <div className="val">{my.pending_web}</div>
                    <div className="sub">&nbsp;</div>
                </div>
            </div>

            <div className="dash-2col">
                <div className="card">
                    <div style={{ display: 'flex', alignItems: 'center' }}><h3>{t('Web Order Report')}</h3>
                        <span style={{ marginLeft: 'auto' }}><TodayPill label={pill} /></span></div>
                    <div style={{ background: '#F9FAFB', border: '1px solid #E5E7EB', borderRadius: 10, padding: '2px 12px', margin: '12px 0 14px' }}>
                        <div className="ptabs" style={{ borderBottom: 'none', margin: 0 }}>
                            <span className={`ptab ${worTab === 'web' ? 'act' : ''}`} onClick={() => setWorTab('web')}>{t('Web Orders')}</span>
                            <span className={`ptab ${worTab === 'inc' ? 'act' : ''}`} onClick={() => setWorTab('inc')}>{t('Incomplete Orders')}</span>
                        </div>
                    </div>
                    {worVals.every((v) => v === 0) ? (
                        <div className="dashed-empty">
                            <div><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><path d="M3 7h18" /><path d="M9 5V3.5A1.5 1.5 0 0 1 10.5 2h3A1.5 1.5 0 0 1 15 3.5V5" /></svg></div>
                            <b style={{ color: 'var(--ink)' }}>{t('No web orders in this period')}</b><br />{t('The selected date range has no web orders to chart.')}
                        </div>
                    ) : (
                        <>
                            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 6, height: 110, borderBottom: '1px solid var(--line)' }}>
                                {my.web_series.map((d, i) => (
                                    <div key={d.date} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'flex-end', gap: 4, height: '100%' }}>
                                        <span style={{ fontSize: 11, fontWeight: 700 }}>{worVals[i] || ''}</span>
                                        {worVals[i] > 0 && <div title={d.date} style={{ width: '70%', background: '#2563eb', height: Math.max(3, Math.round((worVals[i] / worMax) * 84)), borderRadius: '3px 3px 0 0' }} />}
                                    </div>
                                ))}
                            </div>
                            <div style={{ display: 'flex', fontSize: 9.5, color: 'var(--mut2)', marginTop: 5 }}>
                                {my.web_series.map((d) => <span key={d.date} style={{ flex: 1, textAlign: 'center' }}>{d.date}</span>)}
                            </div>
                        </>
                    )}
                </div>

                <div className="card">
                    <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}><h3>{t('Orders by Source')}</h3>
                        <span style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
                            <BasisChip basis={my.basis} />
                            <TodayPill label={pill} />
                        </span></div>
                    <div style={{ display: 'flex', alignItems: 'center', margin: '12px 0', flexWrap: 'wrap' }}>
                        <span className="seg-light" style={{ gap: 2, alignItems: 'center' }}>
                            <span style={{ fontSize: 11, letterSpacing: '.08em', color: 'var(--mut2)', fontWeight: 700, padding: '0 6px 0 8px' }}>VIEW</span>
                            <span className="seg-light" style={{ background: 'transparent', padding: 0 }}>
                                <button type="button" className={srcMode === 'Source' ? 'on' : ''} onClick={() => setSrcMode('Source')}>{t('Source')}</button>
                                <button type="button" className={srcMode === 'Channel' ? 'on' : ''} onClick={() => setSrcMode('Channel')}>{t('Channel')}</button>
                            </span>
                            <span style={{ width: 1, alignSelf: 'stretch', background: '#E2E8F0', margin: '3px 3px' }} />
                            <span className="seg-light" style={{ background: 'transparent', padding: 0 }}>
                                <button type="button" className={srcView === 'chart' ? 'on' : ''} onClick={() => setSrcView('chart')}>{t('Chart')}</button>
                                <button type="button" className={srcView === 'table' ? 'on' : ''} onClick={() => setSrcView('table')}>{t('Table')}</button>
                            </span>
                        </span>
                    </div>
                    {srcView === 'chart' ? (
                        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 24 }}>
                            <div>
                                <Donut size={170} stroke={26} parts={srcRows.map((x, i) => ({ v: x.count, color: SRC_PALETTE[i % SRC_PALETTE.length] }))} />
                                <div style={{ textAlign: 'center', marginTop: 12 }}>
                                    <b style={{ fontSize: 13.5 }}>{t('Total Orders')}: {srcTotal}</b><br />
                                    <span style={{ fontSize: 12.5, color: 'var(--mut)' }}>{t('Total Value')}: {fmt(srcValue)}</span>
                                </div>
                            </div>
                            <div style={{ fontSize: 13.5 }}>
                                {srcRows.map((x, i) => (
                                    <div key={x.label} style={{ marginBottom: 8 }}>
                                        <span style={{ display: 'inline-block', width: 10, height: 10, borderRadius: '50%', background: SRC_PALETTE[i % SRC_PALETTE.length], marginRight: 7 }} />{x.label} ({x.count})<br />
                                        <span style={{ color: 'var(--mut)', marginLeft: 17 }}>{fmt(x.value)}</span>
                                    </div>
                                ))}
                                {srcRows.length === 0 && <span className="mut">{t('No orders in this period')}</span>}
                            </div>
                        </div>
                    ) : (
                        <table className="tbl">
                            <thead><tr><th>{t('Source')}</th><th>{t('Orders')}</th><th>{t('Value')}</th></tr></thead>
                            <tbody>
                                {srcRows.map((x) => <tr key={x.label}><td>{x.label}</td><td>{x.count}</td><td>{fmt(x.value)}</td></tr>)}
                                {srcRows.length === 0 && <tr><td colSpan="3" className="mut">{t('No orders in this period')}</td></tr>}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            <div className="card" style={{ marginBottom: 18 }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', flexWrap: 'wrap', gap: 8 }}>
                    <div><h3>{t('Order Counts')}</h3><div className="card-sub" style={{ margin: '2px 0 0' }}>{t('Orders created vs sent to courier in the selected range')}</div></div>
                    <span style={{ marginLeft: 'auto', display: 'flex', gap: 8, alignItems: 'flex-start', flexWrap: 'wrap', justifyContent: 'flex-end' }}>
                        <select className="ctl-btn" value={range} onChange={(e) => setRange(Number(e.target.value))}>
                            <option value={30}>{t('Last 30 days')}</option><option value={7}>{t('Last 7 days')}</option><option value={1}>{t('Today')}</option>
                        </select>
                        <span className="legend-big on"><span className="lb-lbl">{t('Created')}</span><span className="lb-num" style={{ color: '#2563EB' }}>{cSum}</span></span>
                        <span className="legend-big"><span className="lb-lbl">{t('Sent to Courier')}</span><span className="lb-num" style={{ color: '#16a34a' }}>{sSum}</span></span>
                    </span>
                </div>
                <div style={{ height: 14 }} />
                <div style={{ display: 'flex', alignItems: 'flex-end', gap: 3, height: 110, borderBottom: '1px solid var(--line)' }}>
                    {slice.map((d) => (
                        <div key={d.date} title={`${d.date} - ${t('Created')} ${d.created} · ${t('Sent to Courier')} ${d.sent}`} style={{ flex: 1, display: 'flex', alignItems: 'flex-end', gap: 1, height: '100%' }}>
                            <div style={{ flex: 1, background: d.created ? '#2563eb' : 'transparent', height: d.created ? `${Math.max(4, (d.created / cMax) * 100)}%` : 0, borderRadius: '3px 3px 0 0' }} />
                            <div style={{ flex: 1, background: d.sent ? '#16a34a' : 'transparent', height: d.sent ? `${Math.max(4, (d.sent / cMax) * 100)}%` : 0, borderRadius: '3px 3px 0 0' }} />
                        </div>
                    ))}
                </div>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 9.5, color: 'var(--mut2)', marginTop: 5 }}>
                    {slice.map((d, i) => <span key={d.date} style={{ flex: 1, textAlign: 'center' }}>{i % 2 === 0 ? d.date : ''}</span>)}
                </div>
            </div>

            <div className="card" style={{ marginBottom: 18 }}>
                <div style={{ display: 'flex', alignItems: 'center' }}><h3>{t('Earning Reports')}</h3>
                    <span style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
                        <span className="today-pill">{t('Year')}</span>
                    </span></div>
                {!earn ? (
                    <div className="dashed-empty" style={{ marginTop: 12 }}>
                        {t('This report is loaded only when you ask for it. Press the button to load this year\'s earnings.')}<br /><br />
                        <button type="button" className="ctl-btn" style={{ background: 'var(--acc)', color: '#fff', borderColor: 'var(--acc)', padding: '9px 16px' }} onClick={loadEarn} disabled={earnBusy}>
                            {earnBusy ? t('Loading...') : t('Load Earning Reports')}
                        </button>
                    </div>
                ) : (
                    <table className="tbl" style={{ marginTop: 12 }}>
                        <thead><tr><th>{t('Month')} {earn.year}</th><th>{t('Revenue')}</th><th>{t('Profit')}</th></tr></thead>
                        <tbody>
                            {earn.months.filter((m) => m.revenue > 0 || m.profit !== 0).map((m) => (
                                <tr key={m.label}><td>{m.label}</td><td>{fmt(m.revenue)}</td><td>{fmt(m.profit)}</td></tr>
                            ))}
                            {earn.months.every((m) => m.revenue === 0 && m.profit === 0) && <tr><td colSpan="3" className="mut">{t('No earnings recorded this year yet.')}</td></tr>}
                            <tr><td className="b">{t('Total')}</td><td className="b">{fmt(earn.months.reduce((a, m) => a + m.revenue, 0))}</td><td className="b">{fmt(earn.months.reduce((a, m) => a + m.profit, 0))}</td></tr>
                        </tbody>
                    </table>
                )}
            </div>

            <div className="dash-2col">
                <div className="card">
                    <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}><h3>{t('Hourly Combined')}</h3></div>
                    <div className="card-sub" style={{ margin: '2px 0 10px' }}>{hDay === 'day' ? my.day : my.prev_iso}{hDay === 'day' && my.is_today ? ` · ${t('Today so far')} (${String(my.until_hour).padStart(2, '0')}:00)` : ''}</div>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                        <select className="ctl-btn" value={hMode} onChange={(e) => setHMode(e.target.value)}>
                            <option value="combined">{t('Combined')}</option><option value="web">{t('Web Orders')}</option><option value="inc">{t('Incomplete Orders')}</option>
                        </select>
                        <span className="seg">
                            <button type="button" className={hDay === 'day' ? 'on' : ''} onClick={() => setHDay('day')}>{my.is_today ? t('Today') : my.day_short}</button>
                            <button type="button" className={hDay === 'prev' ? 'on' : ''} onClick={() => setHDay('prev')}>{my.is_today ? t('Yesterday') : my.prev_short}</button>
                        </span>
                    </div>
                    <div style={{ display: 'flex', gap: 6, marginTop: 12 }}>
                        <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'space-between', fontSize: 9.5, color: 'var(--mut2)', height: 86, padding: '1px 0' }}>
                            {[4, 3, 2, 1, 0].map((i) => <span key={i}>{Math.round((hMax * i) / 4)}</span>)}
                        </div>
                        <div style={{ flex: 1, minWidth: 0 }}><svg viewBox="0 0 300 86" preserveAspectRatio="none" style={{ width: '100%', height: 86, display: 'block' }}>
                            <line x1="0" y1="10" x2="300" y2="10" stroke="#eef2f7" /><line x1="0" y1="29" x2="300" y2="29" stroke="#eef2f7" />
                            <line x1="0" y1="48" x2="300" y2="48" stroke="#eef2f7" /><line x1="0" y1="67" x2="300" y2="67" stroke="#eef2f7" />
                            <line x1="0" y1="84" x2="300" y2="84" stroke="#e5e7eb" />
                            <polyline points={hPoints} fill="none" stroke="#16a34a" strokeWidth="2" />
                        </svg></div>
                    </div>
                    <div className="hour-labels" style={{ display: 'flex', justifyContent: 'space-between', fontSize: 8.5, color: 'var(--mut2)', marginLeft: 16, overflow: 'hidden' }}>
                        {HOURS.filter((_, i) => i % 3 === 0).map((h) => <span key={h} style={{ whiteSpace: 'nowrap' }}>{h}</span>)}
                    </div>
                </div>

                <div className="card">
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}><h3>{t('Top Selling Products')}</h3>
                        {my.top_products.length > 0 && <span className="pill grey">{t('Top')} {my.top_products.length}</span>}
                        <span style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
                            <BasisChip basis={my.basis} />
                            <TodayPill label={pill} />
                        </span></div>
                    <div style={{ marginTop: 14 }}>
                        {my.top_products.map((p, i) => (
                            <div key={p.sku + p.name} style={{ border: '1px solid #E5E7EB', borderRadius: 12, padding: '14px 16px', marginBottom: 12 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                    <span style={{ width: 26, height: 26, borderRadius: '50%', background: '#F1F5F9', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: 12.5 }}>{i + 1}</span>
                                    <span style={{ width: 44, height: 44, borderRadius: 8, background: '#F4EDF7', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7C3AED" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" /><path d="M3.3 7 12 12l8.7-5" /><path d="M12 22V12" /></svg>
                                    </span>
                                    <span><b>{p.name}</b><br /><span style={{ fontSize: 12, color: 'var(--mut)' }}>SKU {p.sku} - {t('Return')} {Number(p.return_pct).toFixed(1)}%</span></span>
                                    <span className="pill" style={{ marginLeft: 'auto', background: '#DBEAFE', color: '#1D4ED8' }}>{p.qty}</span>
                                </div>
                                <div style={{ height: 6, background: '#dbeafe', borderRadius: 99, marginTop: 10 }}>
                                    <div style={{ width: `${Math.round((p.qty / topMax) * 100)}%`, height: '100%', background: '#2563eb', borderRadius: 99 }} />
                                </div>
                            </div>
                        ))}
                        {my.top_products.length === 0 && <div className="mut">{t('No products sold in this period')}</div>}
                    </div>
                </div>
            </div>
        </>
    );
}

function OvKpi({ label, value, sub }) {
    return (
        <div className="card kpi">
            <div className="kpi-top"><span className="kpi-lbl">{t(label)}</span></div>
            <div className="val">{value}</div>
            <div className="sub">{sub || <>&nbsp;</>}</div>
        </div>
    );
}

function Overview({ ov, dayLabel }) {
    const funnel = [
        { label: 'Web Orders', v: ov.web, color: '#5b2a86' },
        { label: 'Incomplete', v: ov.incomplete, color: '#a678d8' },
        { label: 'Processing', v: ov.processing, color: '#7a4bb3' },
    ];
    const fMax = Math.max(1, ...funnel.map((f) => f.v));
    const counts = [
        { label: 'Created', v: ov.received, color: '#2563eb' },
        { label: 'Sent to Courier', v: ov.sent, color: '#16a34a' },
    ];
    const cMax = Math.max(1, ...counts.map((c) => c.v));
    const barRow = (f, max) => (
        <div className="funnel-row" key={f.label}>
            <span style={{ width: 130 }}>{t(f.label)}</span>
            <div className="funnel-bar" style={{ width: `${Math.max(3, (f.v / max) * 220)}px`, background: f.color }} />
            <b>{f.v}</b>
        </div>
    );
    return (
        <>
            <div className="kpi-grid">
                <OvKpi label="Orders Received" value={String(ov.received)} sub={t('Last 30 days')} />
                <OvKpi label="Approved Orders" value={String(ov.approved)} sub={t('Processing + Shipped + Delivered')} />
                <OvKpi label="Processing" value={String(ov.processing)} />
                <OvKpi label="Shipped" value={String(ov.shipped)} />
                <OvKpi label="Delivered" value={String(ov.delivered)} />
                <OvKpi label="Cancelled" value={String(ov.cancelled)} sub={`${ov.cancelled_pct}% ${t('of total')}`} />
                <OvKpi label="Return" value={String(ov.returned)} />
                <OvKpi label="Revenue" value={fmt(ov.revenue)} sub={<Delta k={{ pct: ov.revenue_pct, prev: ov.revenue_prev }} money />} />
                <OvKpi label="Profit" value={fmt(ov.profit)} sub={t('Estimated')} />
                <OvKpi label="Ad Spend" value={fmt(ov.ad_spend)} sub={t('No ad accounts connected')} />
                <OvKpi label="Courier Cost" value={fmt(ov.courier_cost)} sub={dayLabel} />
                <OvKpi label="Pending COD" value={fmt(ov.pending_cod)} sub={t('COD receivable')} />
            </div>
            <div className="dash-2col">
                <div className="card">
                    <h3>{t('Order Funnel')}</h3>
                    <div className="card-sub">{t('Last 30 days')}</div>
                    <div style={{ marginTop: 10 }}>{funnel.map((f) => barRow(f, fMax))}</div>
                </div>
                <div className="card">
                    <h3>{t('Order Counts')}</h3>
                    <div className="card-sub">{t('Last 30 days')}</div>
                    <div style={{ marginTop: 10 }}>{counts.map((f) => barRow(f, cMax))}</div>
                </div>
            </div>
        </>
    );
}

function DecisionView({ dc }) {
    const verdict = dc.verdict === 'major' ? 'Major rule breach: act now' : dc.verdict === 'some' ? 'Some rules need attention' : 'No major rule breach';
    return (
        <div className="card decision-box">
            <h3>{t('Decision Box')}</h3>
            <div className="conf-wrap">
                <Donut size={150} stroke={16} parts={[{ v: dc.confidence, color: '#411550' }, { v: 100 - dc.confidence, color: '#e9d5ff' }]} />
                <div className="conf-num">{dc.confidence}%</div>
            </div>
            <div className="conf-lbl">{t('Confidence')}</div>
            <p style={{ marginTop: 12 }}>{t('How much of today\'s rules are working properly (orders, stock and courier together).')}</p>
            <p>{t('আজকের নিয়মগুলোর কতভাগ ঠিকঠাক চলছে (order, stock, courier মিলিয়ে)')}</p>
            <div className="verdict">{t(verdict)}</div>
            <div className="sub" style={{ fontSize: 13, color: 'var(--mut)' }}>
                {dc.breaching} / {dc.live_rules} {t('rules need attention')} · {dc.open_tasks} {t('open tasks')} · {dc.critical_open} {t('critical')}
            </div>
            <p style={{ marginTop: 16 }}><a className="btn pri" href="/work-inbox/control">{t('Open Work Inbox')}</a></p>
        </div>
    );
}

function OperationsView({ ops }) {
    const pillFor = (st) => st === 'Pending' ? 'amber' : st === 'Processing' ? 'blue' : '';
    return (
        <>
            <div className="card" style={{ marginBottom: 18 }}>
                <h3>{t('Stuck Orders')}</h3>
                <div className="card-sub">{t('Open a box to see the work in Work Inbox')}</div>
                <div className="stuck-grid">
                    {ops.stuck.map((s) => (
                        <a key={s.label} className="stuck" href="/work-inbox/control">
                            <span className="stuck-n">{s.count}</span>
                            <span className="stuck-l">{t(s.label)}</span>
                        </a>
                    ))}
                </div>
            </div>
            <div className="card">
                <h3>{t('Courier Table - Orders Not Sent To Courier')}</h3>
                <div className="card-sub">{ops.not_sent_total} {t('orders waiting')}{ops.couriers.length > 0 ? ` · ${ops.couriers.join(', ')}` : ''}</div>
                <table className="tbl">
                    <thead><tr><th>{t('Invoice')}</th><th>{t('Customer')}</th><th>{t('Courier')}</th><th>{t('Status')}</th></tr></thead>
                    <tbody>
                        {ops.courier_rows.map((r) => (
                            <tr key={r.invoice}><td className="b">{r.invoice}</td><td>{r.name}</td><td>{r.courier}</td><td><span className={`pill ${pillFor(r.status)}`}>{t(r.status)}</span></td></tr>
                        ))}
                        {ops.courier_rows.length === 0 && <tr><td colSpan="4" className="mut">{t('No orders are waiting')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </>
    );
}

function ProductsView({ prod }) {
    const max = Math.max(1, ...prod.top.map((p) => p.qty));
    return (
        <div className="dash-2col">
            <div className="card">
                <h3>{t('Top Selling Products')}</h3>
                <div className="card-sub">{t('Last 30 days')}</div>
                <div style={{ marginTop: 10 }}>
                    {prod.top.map((p) => (
                        <div className="bar-row" key={p.name}>
                            <span className="bar-l">{p.name}</span>
                            <span className="bar-track"><span className="bar-fill" style={{ width: `${Math.max(3, (p.qty / max) * 100)}%` }} /></span>
                            <b>{p.qty}</b>
                        </div>
                    ))}
                    {prod.top.length === 0 && <div className="mut">{t('No products sold in this period')}</div>}
                </div>
            </div>
            <div className="card">
                <h3>{t('Low And Dead Stock')}</h3>
                <div className="card-sub">{prod.low_total_stock} {t('in stock')} · {prod.low_count} {t('at or below alert level')} · {prod.total_sold} {t('sold all time')}</div>
                <table className="tbl" style={{ marginTop: 10 }}>
                    <thead><tr><th>{t('Product')}</th><th>{t('Stock')}</th><th>{t('Sold')}</th></tr></thead>
                    <tbody>
                        {prod.low_rows.map((r) => <tr key={r.name}><td>{r.name}</td><td>{r.stock}</td><td>{r.sold}</td></tr>)}
                        {prod.low_rows.length === 0 && <tr><td colSpan="3" className="mut">{t('No low stock products')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function MarketingView({ mkt }) {
    const pMax = Math.max(1, ...mkt.profit_days.map((d) => Math.abs(d.profit)));
    const hourLabel = (h) => `${((h + 11) % 12) + 1}${h < 12 ? 'a' : 'p'}`;
    return (
        <div className="dash-2col">
            <div className="card">
                <h3>{t('Ad Spend Vs Profit')}</h3>
                <div className="card-sub">{t('No campaign data')}</div>
                <div style={{ marginTop: 10 }}>
                    {mkt.profit_days.map((d) => (
                        <div className="bar-row" key={d.label}>
                            <span className="bar-l">{d.label}</span>
                            <span className="bar-track"><span className="bar-fill" style={{ width: `${Math.max(3, (Math.abs(d.profit) / pMax) * 100)}%` }} /></span>
                            <b>{fmt(d.profit)}</b>
                        </div>
                    ))}
                </div>
                <div className="sub" style={{ fontSize: 13, color: 'var(--mut)', marginTop: 12 }}>
                    {t('Ad Spend')} {fmt(mkt.ad_spend)} · {t('Revenue from ads')} {fmt(mkt.ad_revenue)} · {t('True ROAS')} -
                </div>
            </div>
            <div className="card">
                <h3>{t('Hourly Order Heatmap')}</h3>
                <div className="card-sub">{t(mkt.heatmap_weekday)} · {t('orders per hour')}</div>
                <div className="hm-grid">
                    {mkt.heatmap_hours.map((n, h) => (
                        <div key={h} className={`hm-cell ${n === 0 ? '' : n === 1 ? 'h1' : n <= 3 ? 'h2' : 'h3'}`}>{hourLabel(h)}{n > 0 ? ` · ${n}` : ''}</div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function TeamView({ team }) {
    const c = team.customers;
    return (
        <div className="dash-2col">
            <div className="card">
                <h3>{t('Agent Performance')}</h3>
                <div className="card-sub">{t('Last 30 days')}</div>
                <table className="tbl" style={{ marginTop: 10 }}>
                    <thead><tr><th>#</th><th>{t('Agent')}</th><th>{t('Processed')}</th><th>{t('Processing')}</th><th>{t('Revenue')}</th></tr></thead>
                    <tbody>
                        {team.agents.map((a) => (
                            <tr key={a.rank + a.name}><td>{a.rank}</td><td>{a.name}</td><td>{a.processed}</td><td>{a.processing_pct}%</td><td>{fmt(a.revenue)}</td></tr>
                        ))}
                        {team.agents.length === 0 && <tr><td colSpan="5" className="mut">{t('No processed orders in this period')}</td></tr>}
                    </tbody>
                </table>
            </div>
            <div className="card">
                <h3>{t('Customer Details')}</h3>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 24, marginTop: 12 }}>
                    <Donut size={170} stroke={26} parts={[
                        { v: c.repeat, color: '#411550' },
                        { v: c.one, color: '#a855f7' },
                        { v: c.none, color: '#e9d5ff' },
                    ]} />
                    <div style={{ fontSize: 13.5 }}>
                        <div style={{ marginBottom: 8 }}><span className="legend-dot" style={{ background: '#411550' }} />{t('Repeat')} ({c.repeat})</div>
                        <div style={{ marginBottom: 8 }}><span className="legend-dot" style={{ background: '#a855f7' }} />{t('One order')} ({c.one})</div>
                        <div style={{ marginBottom: 14 }}><span className="legend-dot" style={{ background: '#e9d5ff' }} />{t('No orders yet')} ({c.none})</div>
                        <div>{t('Repeat customers')} - {c.repeat}</div>
                        <div style={{ marginTop: 4 }}>{t('Repeat rate')} - {c.repeat_rate}%</div>
                        <div style={{ marginTop: 4 }}>{t('District')}: {c.district_label === 'Unknown' ? t('Unknown') : c.district_label} - {c.district_count}</div>
                    </div>
                </div>
            </div>
        </div>
    );
}

function ActivityView({ act, my }) {
    return (
        <div className="dash-2col">
            <div className="card">
                <h3>{t('Live Activity Feed')}</h3>
                <div style={{ marginTop: 6 }}>
                    {act.feed.map((a, i) => (
                        <div className="feed-it" key={i}>
                            <div>{a.text}</div>
                            <div className="feed-when">{a.invoice} · {a.when}</div>
                        </div>
                    ))}
                    {act.feed.length === 0 && <div className="mut" style={{ padding: '10px 0' }}>{t('No activity yet')}</div>}
                </div>
            </div>
            <div>
                <div className="card" style={{ marginBottom: 18 }}>
                    <h3>{t('Orders by Date Basis')}</h3>
                    <div className="kpi" style={{ padding: '10px 0 0' }}>
                        <div className="kpi-lbl">{t('Total Orders')}</div>
                        <div className="val">{Math.round(my.kpis.orders.value)}</div>
                        <div className="sub"><Delta k={my.kpis.orders} /></div>
                        <div className="kpi-lbl" style={{ marginTop: 12 }}>{t('Total Sales')}</div>
                        <div className="val">{fmt(my.kpis.revenue.value)}</div>
                        <div className="sub"><Delta k={my.kpis.revenue} money /></div>
                    </div>
                </div>
                <div className="card" style={{ marginBottom: 18 }}>
                    <h3>{t('Web Orders / Incomplete')}</h3>
                    {act.web_day + act.inc_day === 0 ? (
                        <div className="mut" style={{ marginTop: 8 }}>{t('No web orders in this period')}</div>
                    ) : (
                        <div style={{ marginTop: 8, fontSize: 13.5 }}>
                            <div>{t('Web Orders')}: <b>{act.web_day}</b></div>
                            <div style={{ marginTop: 4 }}>{t('Incomplete Orders')}: <b>{act.inc_day}</b></div>
                        </div>
                    )}
                </div>
                <div className="card">
                    <h3>{t('Order Counts')}</h3>
                    <div style={{ marginTop: 8 }}>
                        <div className="bar-row"><span className="bar-l">{t('Created')}</span><b>{act.created_day}</b></div>
                        <div className="bar-row"><span className="bar-l">{t('Sent to Courier')}</span><b>{act.sent_day}</b></div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Dashboard({ my, ov, dc, ops, prod, mkt, team, act, billing }) {
    const [view, setView] = useState('My Dashboard');
    const [clock, setClock] = useState('--:--:--');
    useEffect(() => {
        const f = () => setClock(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date()));
        f();
        const id = setInterval(f, 1000);
        return () => clearInterval(id);
    }, []);
    const todayIso = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date());
    const navDay = (iso) => router.get('/dashboard', {
        ...(iso ? { day: iso } : {}),
        ...(my.basis !== 'approved' ? { basis: my.basis } : {}),
    }, { preserveScroll: true });
    const navBasis = (b) => router.get('/dashboard', {
        ...(!my.is_today ? { day: my.day } : {}),
        ...(b !== 'approved' ? { basis: b } : {}),
    }, { preserveScroll: true });
    return (
        <AppLayout>
            <Head title={t('Dashboard')} />
            {billing && billing.blocked && (
                <div style={{ background: '#FDECEC', border: '1px solid #F3B4B4', color: '#8B1E1E', borderRadius: 10, padding: '12px 14px', marginBottom: 12, fontSize: 14 }}>
                    <b>{t('Your points are finished. The shop is view-only.')}</b> {t('New orders are paused until you buy a points pack.')} <a href="/billing" style={{ fontWeight: 700, color: '#8B1E1E', textDecoration: 'underline' }}>{t('Go to Points & Billing')}</a>
                </div>
            )}
            {billing && !billing.blocked && (billing.low || billing.ending_soon) && (
                <div style={{ background: '#FFF6E0', border: '1px solid #F0D48A', color: '#7A5200', borderRadius: 10, padding: '12px 14px', marginBottom: 12, fontSize: 14 }}>
                    <b>{t('Points are running low')}</b>: {billing.balance} {t('points left')}{billing.cycle_end ? ` · ${t('pack resets')} ${billing.cycle_end}` : ''}{billing.wallet_expires_at ? ` · ${t('wallet ends')} ${billing.wallet_expires_at}` : ''}. <a href="/billing" style={{ fontWeight: 700, color: '#7A5200', textDecoration: 'underline' }}>{t('Top up')}</a>
                </div>
            )}
            <div className="dash-pg">
                <div className="dash-head">
                    <div className="ptabs" style={{ flex: 1, minWidth: 420, marginBottom: 0 }}>
                        {VIEWS.map((v) => <span key={v} className={`ptab ${view === v ? 'act' : ''}`} onClick={() => setView(v)}>{t(v)}</span>)}
                    </div>
                    <div className="dash-tools">
                        <div className="dhaka-box"><div className="dhaka-lbl">{t('DHAKA TIME')}</div><div className="dhaka-clock">{clock}</div></div>
                        <button type="button" className="ctl-btn" title={t('Previous day')} onClick={() => navDay(my.prev_iso)}>‹</button>
                        <input type="date" className="ctl-btn date-pick" aria-label={t('Pick a date')} value={my.day} max={todayIso}
                            onChange={(e) => { if (e.target.value) navDay(e.target.value); }} />
                        {!my.is_today && (
                            <button type="button" className="ctl-btn" onClick={() => navDay(null)}>
                                <span style={{ marginRight: 5, display: 'inline-flex', verticalAlign: -3 }}><CalSvg size={14} /></span>{t('Today')}
                            </button>
                        )}
                        <button type="button" className="ctl-btn" title={t('Refresh')} style={{ padding: '7px 9px' }} onClick={() => router.reload()}>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36" /><path d="M21 3v6h-6" /></svg>
                        </button>
                    </div>
                </div>
                {view === 'My Dashboard' && <MyDashboard my={my} onBasis={navBasis} />}
                {view === 'Overview' && <Overview ov={ov} dayLabel={my.is_today ? t('Today') : my.day_short} />}
                {view === 'Decision' && <DecisionView dc={dc} />}
                {view === 'Operations' && <OperationsView ops={ops} />}
                {view === 'Products' && <ProductsView prod={prod} />}
                {view === 'Marketing' && <MarketingView mkt={mkt} />}
                {view === 'Customers & Team' && <TeamView team={team} />}
                {view === 'Activity' && <ActivityView act={act} my={my} />}
            </div>
        </AppLayout>
    );
}
