import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Verdict, fmt, fmtNum } from './parts';

const PRESETS = [['today', 'আজ'], ['7d', 'শেষ 7 দিন'], ['month', 'এই মাস'], ['custom', 'নিজের তারিখ']];

export default function Report({ rows, totals, window_days, preset, from, to }) {
    const [win, setWin] = useState(window_days);
    const [cFrom, setCFrom] = useState(from);
    const [cTo, setCTo] = useState(to);
    const go = (p, f = cFrom, t = cTo) => router.get('/marketing/report', p === 'custom' ? { preset: p, from: f, to: t } : { preset: p }, { preserveScroll: true });
    const saveWin = () => { if (Number(win) !== window_days) router.post('/marketing/report/window', { days: Number(win) }, { preserveScroll: true }); };

    return (
        <AppLayout>
            <Head title="Marketing — SMS Report" />
            <div className="mkt">
                <div style={{ marginBottom: 14 }}>
                    <h3 style={{ fontSize: 20 }}>SMS Report</h3>
                    <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>SMS পাঠিয়ে কতটা order বাড়ল — campaign আর automation, সব হিসাব এক জায়গায়। কোন SMS টাকা আয় করছে, কোনটা পানিতে যাচ্ছে, এখানেই পরিষ্কার।</p>
                </div>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginBottom: 16 }}>
                    <div className="ptabs" style={{ marginBottom: 0, borderBottom: 'none' }}>
                        {PRESETS.map(([p, label]) => (
                            <button key={p} className={`ptab${preset === p ? ' act' : ''}`} onClick={() => go(p)}>{label}</button>
                        ))}
                    </div>
                    {preset === 'custom' && (
                        <span style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
                            <input type="date" value={cFrom} onChange={(e) => setCFrom(e.target.value)} style={{ height: 34, border: '1px solid #E2E8F0', borderRadius: 8, padding: '0 8px', fontFamily: 'inherit' }} />
                            <input type="date" value={cTo} onChange={(e) => setCTo(e.target.value)} style={{ height: 34, border: '1px solid #E2E8F0', borderRadius: 8, padding: '0 8px', fontFamily: 'inherit' }} />
                            <button className="btn-s" onClick={() => go('custom')}>দেখো</button>
                        </span>
                    )}
                    <div className="flow-time" style={{ marginTop: 0, marginLeft: 'auto' }}>
                        <span className="ft-lab">Order গোনার নিয়ম:</span> SMS-এর পর
                        <input type="number" className="ft-in" min="1" max="90" value={win} onChange={(e) => setWin(e.target.value)} onBlur={saveWin} /> দিনের মধ্যে order এলে ওই SMS-এর নামে গোনা হবে
                    </div>
                </div>
                <div className="mk-strip">
                    <div className="mk-kpi"><b>{fmt(totals.sms_cost)}</b><span>এই সময়ের মোট SMS খরচ</span></div>
                    <div className="mk-kpi"><b>{fmtNum(totals.orders)}টা order · {fmt(totals.sales)}</b><span>SMS থেকে আসা বিক্রি</span></div>
                    <div className="mk-kpi"><b style={{ color: totals.profit >= 0 ? '#16A34A' : '#DC2626' }}>{fmt(totals.profit)}</b><span>SMS বাদে নিট লাভ (order-এর লাভ − SMS খরচ)</span></div>
                </div>
                <table className="mk-table">
                    <thead><tr><th>SMS</th><th>ধরন</th><th>পাঠানো হয়েছে</th><th>Order এসেছে</th><th>বিক্রি</th><th>SMS খরচ</th><th>নিট লাভ</th><th>রায়</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.type + ':' + r.key}>
                                <td style={{ fontWeight: 600 }}>{r.label}</td>
                                <td>{r.type === 'campaign' ? 'Campaign' : 'Automation'}</td>
                                <td>{fmtNum(r.sent)} জন</td>
                                <td>{fmtNum(r.orders)}টা</td>
                                <td>{fmt(r.sales)}</td>
                                <td>{fmt(r.sms_cost)}</td>
                                <td style={r.profit < 0 ? { color: '#DC2626' } : undefined}>{r.profit < 0 ? '−' + fmt(Math.abs(r.profit)) : fmt(r.profit)}</td>
                                <td><Verdict profit={r.profit} /></td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="8" style={{ color: '#94A3B8' }}>এই সময়ে কোনো marketing SMS পাঠানো হয়নি।</td></tr>}
                    </tbody>
                </table>
                <p style={{ fontSize: 13, color: '#94A3B8', marginTop: 12, lineHeight: 1.7 }}>নিয়ম: customer SMS পাওয়ার পর {fmtNum(window_days)} দিনের মধ্যে order করলে order-টা ওই SMS-এর নামে গোনা হয়। এর মধ্যে একাধিক SMS গিয়ে থাকলে সবচেয়ে শেষেরটা পায়। Order-এর খবরের SMS (ধন্যবাদ · courier-এ পাঠানোর খবর · delivered ধন্যবাদ) order বাড়ানোর জন্য নয় — ওগুলো এই report-এ ধরা হয়নি। নিট লাভ = ওই order গুলোর লাভ (delivered ধরে) − SMS খরচ।</p>
            </div>
        </AppLayout>
    );
}
