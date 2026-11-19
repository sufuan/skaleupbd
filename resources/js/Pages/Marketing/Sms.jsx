import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Verdict, fmt, fmtNum, smsParts } from './parts';

export default function Sms({ balance, rate, success_rate, audiences, dnd_count, templates, campaigns }) {
    const [audKey, setAudKey] = useState(audiences.find((a) => a.key === 'bought_30d')?.key || audiences[0]?.key);
    const [message, setMessage] = useState('');
    const [coupon, setCoupon] = useState('');
    const aud = audiences.find((a) => a.key === audKey) || { count: 0, sendable: 0 };
    const parts = useMemo(() => smsParts(message), [message]);
    const chars = useMemo(() => [...message].length, [message]);
    const total = aud.sendable * parts * rate;
    const after = balance - total;
    const warn = message.length > 0 && after < 0;

    const pickTemplate = (id) => {
        const t = templates.find((x) => String(x.id) === String(id));
        if (t) setMessage(t.body);
    };
    const send = () => router.post('/marketing/sms/send', { audience_key: audKey, message, coupon: coupon || null }, { preserveScroll: true });

    return (
        <AppLayout>
            <Head title="Marketing — SMS Marketing" />
            <div className="mkt">
                <div style={{ marginBottom: 14 }}>
                    <h3 style={{ fontSize: 20 }}>SMS Marketing</h3>
                    <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>3 ধাপে campaign পাঠাও — কাদের পাঠাবে, কী লিখবে, কত খরচ হবে। পাঠানোর আগেই সব হিসাব চোখের সামনে।</p>
                </div>
                <div className="mk-strip">
                    <div className="mk-kpi"><b>{fmt(balance)}</b><span>SMS balance (আছে)</span></div>
                    <div className="mk-kpi"><b>{fmt(rate)}</b><span>প্রতি SMS part-এর দাম থেকে শুরু</span></div>
                    <div className="mk-kpi"><b>{success_rate}%</b><span>গত মাসে পাঠানো SMS সফলভাবে গেছে</span></div>
                </div>

                <div className="step-card">
                    <div className="step-title">ধাপ ১ · কাদের পাঠাবে?</div>
                    <div>
                        {audiences.map((a) => (
                            <button key={a.key} className={`aud-chip${a.key === audKey ? ' sel' : ''}`} onClick={() => setAudKey(a.key)}>{a.label} · {fmtNum(a.count)} জন</button>
                        ))}
                    </div>
                    <div style={{ fontSize: 13, color: '#94A3B8', marginTop: 4 }}>"আর পাঠাবেন না" বলা {fmtNum(dnd_count)} জন নিজে থেকে বাদ যাবে — তাদের কাছে কখনো যাবে না।</div>
                </div>

                <div className="step-card">
                    <div className="step-title">ধাপ ২ · কী লিখবে?</div>
                    <div style={{ display: 'flex', gap: 18, flexWrap: 'wrap' }}>
                        <div style={{ flex: 1, minWidth: 280 }}>
                            <select defaultValue="" onChange={(e) => pickTemplate(e.target.value)} style={{ width: '100%', height: 40, border: '1px solid #E2E8F0', borderRadius: 9, padding: '0 10px', fontFamily: 'inherit', marginBottom: 10 }}>
                                <option value="">— তৈরি template বাছাই করো, অথবা নিচে নিজে লেখো —</option>
                                {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </select>
                            <textarea rows="4" value={message} onChange={(e) => setMessage(e.target.value)} style={{ width: '100%', border: '1px solid #E2E8F0', borderRadius: 10, padding: 11, fontFamily: 'inherit', fontSize: 14.5, boxSizing: 'border-box' }} placeholder="এখানে লেখো — ডানদিকে phone-এ যেভাবে দেখাবে, সাথে সাথে দেখতে পাবে"></textarea>
                            <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 10, flexWrap: 'wrap' }}>
                                <label style={{ fontSize: 13.5, color: '#64748B' }}>Coupon যোগ করো:{' '}
                                    <select value={coupon} onChange={(e) => setCoupon(e.target.value)} style={{ height: 36, border: '1px solid #E2E8F0', borderRadius: 8, fontFamily: 'inherit' }}>
                                        <option value="">কুপন নেই</option>
                                        <option value="FEST10">FEST10 — 10% ছাড়</option>
                                        <option value="RETURN50">RETURN50 — ৳50 ছাড়</option>
                                    </select>
                                </label>
                                <span style={{ fontSize: 13, color: '#94A3B8' }}>{chars} অক্ষর · {parts} part</span>
                            </div>
                        </div>
                        <div className="phone">
                            <div className="phone-screen">
                                <div style={{ fontSize: 11.5, color: '#78716C', marginBottom: 8 }}>Customer-এর phone-এ যেভাবে যাবে</div>
                                <div className="sms-bubble">{message || 'লিখলেই এখানে দেখা যাবে…'}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="step-card">
                    <div className="step-title">ধাপ ৩ · কত খরচ হবে?</div>
                    <div style={{ display: 'flex', gap: 26, flexWrap: 'wrap', alignItems: 'center' }}>
                        <div><div style={{ fontSize: 13, color: '#64748B' }}>প্রাপক</div><b style={{ fontSize: 22 }}>{fmtNum(aud.sendable)} জন</b></div>
                        <div><div style={{ fontSize: 13, color: '#64748B' }}>প্রতি জন</div><b style={{ fontSize: 22 }}>{parts} part</b></div>
                        <div><div style={{ fontSize: 13, color: '#64748B' }}>মোট খরচ</div><b style={{ fontSize: 22, color: '#411550' }}>{fmt(total)}</b></div>
                        <div><div style={{ fontSize: 13, color: '#64748B' }}>পাঠানোর পর balance থাকবে</div><b style={{ fontSize: 22 }}>{fmt(after)}</b></div>
                        <button className="btn-p" style={{ marginLeft: 'auto' }} disabled={!message || aud.sendable === 0 || warn} onClick={send}>Campaign পাঠাও</button>
                    </div>
                    {warn && <div style={{ color: '#DC2626', fontSize: 13.5, marginTop: 10 }}>Balance-এ কুলোবে না — হয় audience কমাও, নয় লেখা ছোট করো, নয় balance ভরো।</div>}
                </div>

                <h4 style={{ fontSize: 16.5, margin: '20px 0 10px' }}>আগের Campaign গুলো — ফলাফল</h4>
                <table className="mk-table">
                    <thead><tr><th>Campaign</th><th>কাদের পাঠানো হয়েছিল</th><th>খরচ</th><th>Order এসেছে</th><th>রায়</th></tr></thead>
                    <tbody>
                        {campaigns.map((c) => (
                            <tr key={c.id}>
                                <td style={{ fontWeight: 600 }}>{c.name}<div style={{ fontWeight: 400, color: '#94A3B8', fontSize: 12 }}>{c.date}</div></td>
                                <td>{fmtNum(c.recipients)} জন ({c.audience_label})</td>
                                <td>{fmt(c.total_cost)}</td>
                                <td>{fmtNum(c.orders)}টা · {fmt(c.sales)}</td>
                                <td><Verdict profit={c.profit} /></td>
                            </tr>
                        ))}
                        {campaigns.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>এখনো কোনো campaign পাঠানো হয়নি।</td></tr>}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
