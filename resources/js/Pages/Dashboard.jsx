import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const VIEWS = ['My Dashboard', 'Overview', 'Decision', 'Operations', 'Products', 'Marketing', 'Customers & Team'];

function Kpi({ label, value, delta, prev }) {
    return (
        <div className="card kpi">
            <div className="lb"><span>{label}</span><span className="pill">Today</span></div>
            <div className="vl">{value}</div>
            {delta && <div className="dl"><b>↗ {delta}</b> &nbsp;Previous {prev}</div>}
        </div>
    );
}

function Donut({ size = 190, stroke = 26, parts }) {
    const r = (size - stroke) / 2, c = 2 * Math.PI * r, total = parts.reduce((a, p) => a + p.v, 0) || 1;
    let off = 0;
    return (
        <svg width={size} height={size} style={{ transform: 'rotate(-90deg)' }}>
            <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#efe9f8" strokeWidth={stroke} />
            {parts.map((p, i) => {
                const frac = p.v / total, el = <circle key={i} cx={size / 2} cy={size / 2} r={r} fill="none" stroke={p.color} strokeWidth={stroke} strokeDasharray={`${frac * c} ${c}`} strokeDashoffset={-off * c} />;
                off += frac; return el;
            })}
        </svg>
    );
}

function MyDashboard() {
    return (
        <>
            <div className="kpis">
                <Kpi label="Total Orders" value="8" delta="700.0%" prev="1" />
                <Kpi label="Revenue" value="৳4,342" delta="985.5%" prev="৳400" />
                <Kpi label="Profit" value="৳2,942" delta="2263.8%" prev="৳124" />
                <Kpi label="Pending Web Orders" value="0" />
            </div>
            <div className="dash-grid">
                <div className="card">
                    <div className="flex"><h3>Web Order Report</h3><span className="spacer" /><span className="pill">Today ⌄</span></div>
                    <div className="ptabs" style={{ marginTop: 10 }}>
                        <span className="ptab act">Web Orders</span><span className="ptab">Incomplete Orders</span>
                    </div>
                    <div className="empty-box">
                        <div className="b" style={{ color: 'var(--ink)' }}>No web orders in this period</div>
                        <div className="sm" style={{ marginTop: 4 }}>The selected date range has no web orders to chart.</div>
                    </div>
                </div>
                <div className="card">
                    <div className="flex"><h3>Orders by Source</h3><span className="spacer" /><span className="pill">▼ Approved</span><span className="pill">Today</span></div>
                    <div className="flex" style={{ justifyContent: 'center', margin: '14px 0 4px' }}>
                        <Donut parts={[{ v: 8, color: '#5b2a86' }]} />
                    </div>
                    <div className="sm" style={{ textAlign: 'center' }}>
                        <span className="legend-dot" style={{ background: '#5b2a86' }} />Manual Website Source (8) · ৳4,342
                        <div className="b" style={{ marginTop: 8, fontSize: 14 }}>Total Orders: 8</div>
                        <div className="mut">Total Value: ৳4,342</div>
                    </div>
                </div>
            </div>
            <div className="card">
                <div className="flex"><div><h3>Order Counts</h3><div className="card-sub">Orders created vs sent to courier in the selected range</div></div>
                    <span className="spacer" /><span className="pill">Last 30 days ⌄</span></div>
                <div className="flex" style={{ gap: 14 }}>
                    <div style={{ background: '#f4eefc', borderRadius: 12, padding: '16px 22px', minWidth: 130 }}>
                        <div className="sm mut">Created</div><div style={{ fontSize: 26, fontWeight: 800, color: '#5b2a86' }}>8</div>
                    </div>
                    <div style={{ border: '1px solid var(--line)', borderRadius: 12, padding: '16px 22px', minWidth: 130 }}>
                        <div className="sm mut">Sent to Courier</div><div style={{ fontSize: 26, fontWeight: 800 }}>0</div>
                    </div>
                </div>
            </div>
        </>
    );
}

function Overview() {
    const funnel = [
        { label: 'Web Orders', v: 71, w: 100, color: '#5b2a86' },
        { label: 'Incomplete Orders', v: 3, w: 12, color: '#a678d8' },
        { label: 'Cancelled', v: 11, w: 30, color: '#c9b3ec' },
        { label: 'Processing', v: 40, w: 78, color: '#7a4bb3' },
        { label: 'Shipped', v: 5, w: 18, color: '#9570c4' },
        { label: 'Delivered', v: 12, w: 34, color: '#411550' },
    ];
    const courierRows = [
        ['OB286', 'M. Fahim'], ['OB285', 'Khan Tanvir Islam'], ['OB284', 'Nozrul Islam'], ['OB283', 'F.A. Rayhan'], ['OB282', 'Anwar Hossain'],
    ];
    return (
        <>
            <div className="kpis">
                <Kpi label="Total Orders" value="71" />
                <Kpi label="Approved Orders" value="57" />
                <Kpi label="Revenue" value="৳38,650" />
                <Kpi label="Profit" value="৳11,240" />
            </div>
            <div className="dash-grid">
                <div className="card">
                    <h3>Web Order Funnel</h3>
                    <div className="card-sub">Last 30 days · সব সংখ্যা একই হিসাব থেকে</div>
                    {funnel.map((f) => (
                        <div className="funnel-row" key={f.label}>
                            <span style={{ width: 130 }}>{f.label}</span>
                            <div className="funnel-bar" style={{ width: `${f.w * 2.4}px`, background: f.color }} />
                            <b>{f.v}</b>{f.label === 'Cancelled' && <span className="mut sm">(15%)</span>}
                        </div>
                    ))}
                    <div className="mt16 sm" style={{ background: '#f8f4fd', borderRadius: 10, padding: '10px 12px' }}>
                        <b>Confidence 65%</b> — আজকের নিয়মগুলোর কতভাগ ঠিকঠাক চলছে (order, stock আর courier মিলিয়ে)।
                    </div>
                </div>
                <div className="card">
                    <div className="flex"><h3>Orders by Source</h3><span className="spacer" /><span className="pill">Last 30 days ⌄</span></div>
                    <div className="flex" style={{ justifyContent: 'center', margin: '12px 0 4px' }}>
                        <Donut parts={[{ v: 40, color: '#5b2a86' }, { v: 12, color: '#411550' }, { v: 11, color: '#c9b3ec' }, { v: 5, color: '#9570c4' }, { v: 3, color: '#e3d5f7' }]} />
                    </div>
                    <div className="sm" style={{ textAlign: 'center' }}>
                        <div><span className="legend-dot" style={{ background: '#7a4bb3' }} />Processing 40 · <span className="legend-dot" style={{ background: '#411550' }} />Delivered 12</div>
                        <div className="mut" style={{ marginTop: 3 }}>Cancelled 11 · Shipped 5 · Incomplete 3</div>
                        <div className="b" style={{ marginTop: 8, fontSize: 14 }}>Total Orders: 71</div>
                    </div>
                    <div className="mt16 flex" style={{ gap: 12 }}>
                        <div style={{ background: '#f4eefc', borderRadius: 12, padding: '12px 18px', flex: 1, textAlign: 'center' }}>
                            <div className="sm mut">Created</div><div style={{ fontSize: 22, fontWeight: 800, color: '#5b2a86' }}>71</div>
                        </div>
                        <div style={{ border: '1px solid var(--line)', borderRadius: 12, padding: '12px 18px', flex: 1, textAlign: 'center' }}>
                            <div className="sm mut">Sent to Courier</div><div style={{ fontSize: 22, fontWeight: 800 }}>17</div>
                        </div>
                        <div style={{ border: '1px solid var(--line)', borderRadius: 12, padding: '12px 18px', flex: 1, textAlign: 'center' }}>
                            <div className="sm mut">Created Today</div><div style={{ fontSize: 22, fontWeight: 800 }}>8</div>
                        </div>
                    </div>
                </div>
            </div>
            <div className="card">
                <h3>Courier Table — Orders Not Sent To Courier</h3>
                <div className="card-sub">5 orders · steadfast</div>
                <table className="tbl">
                    <thead><tr><th>Invoice</th><th>Customer</th><th>Courier</th><th>Status</th></tr></thead>
                    <tbody>
                        {courierRows.map(([id, name]) => (
                            <tr key={id}><td className="b">{id}</td><td>{name}</td><td>steadfast</td><td><span className="pill amber">Pending</span></td></tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

export default function Dashboard() {
    const [view, setView] = useState('Overview');
    return (
        <AppLayout>
            <Head title="Dashboard" />
            <div className="ptabs">
                {VIEWS.map((v) => <span key={v} className={`ptab ${view === v ? 'act' : ''}`} onClick={() => setView(v)}>{v}</span>)}
                <span className="spacer" />
                <span className="pill" style={{ alignSelf: 'center' }}>Today ⌄</span>
            </div>
            {view === 'My Dashboard' && <MyDashboard />}
            {view === 'Overview' && <Overview />}
            {!['My Dashboard', 'Overview'].includes(view) && (
                <div className="card">
                    <h3>{view}</h3>
                    <p className="mut" style={{ marginTop: 8 }}>এই view-এর data আসবে সংশ্লিষ্ট module তৈরি হলে — module ধরে ধরে build-এর সময় এটা live data দিয়ে ভরে যাবে। এখন My Dashboard আর Overview view তৈরি আছে।</p>
                </div>
            )}
        </AppLayout>
    );
}
