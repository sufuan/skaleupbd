import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { fmt } from './parts';

function ItemRow({ item, runStatus, canManage }) {
    const [slip, setSlip] = useState(false);
    const form = useForm({ advance_cut: item.advance_cut ?? 0, other_adjust: item.other_adjust ?? 0 });
    const editable = runStatus === 'draft' && canManage;
    return (
        <>
            <tr>
                <td><b>{item.name}</b></td>
                <td>{fmt(item.basic)}</td>
                <td>{item.absent_days} দিন</td>
                <td>− {fmt(item.absent_cut)}</td>
                <td>{fmt(item.advance_cut)}</td>
                <td>{Number(item.other_adjust) >= 0 ? '+ ' : '− '}{fmt(Math.abs(item.other_adjust))}</td>
                <td><b>{fmt(item.net)}</b></td>
                <td>
                    <div className="row-actions">
                        <button type="button" className="btn-mini" onClick={() => setSlip(!slip)}>Payslip</button>
                    </div>
                </td>
            </tr>
            {editable && (
                <tr>
                    <td colSpan={8} style={{ background: '#FAF8FC' }}>
                        <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/payroll/items/${item.id}`, { preserveScroll: true }); }}>
                            <div className="fld"><label className="lbl">{item.name} — Advance কাটা (৳)</label>
                                <input className="inp" type="number" min="0" step="any" value={form.data.advance_cut} onChange={(e) => form.setData('advance_cut', e.target.value)} /></div>
                            <div className="fld"><label className="lbl">অন্যান্য যোগ/কাটা (৳)</label>
                                <input className="inp" type="number" step="any" value={form.data.other_adjust} onChange={(e) => form.setData('other_adjust', e.target.value)} /></div>
                            <button className="btn-p" type="submit" disabled={form.processing}>হিসাব বদলাও</button>
                        </form>
                    </td>
                </tr>
            )}
            {slip && (
                <tr>
                    <td colSpan={8} style={{ background: '#fff' }}>
                        <div style={{ border: '1px dashed #CBD5E1', borderRadius: 10, padding: '12px 16px', maxWidth: 420 }}>
                            <b style={{ fontSize: 15 }}>Payslip — {item.name}</b>
                            <div style={{ fontSize: 13.5, lineHeight: 2, marginTop: 6 }}>
                                মূল বেতন: {fmt(item.basic)}<br />
                                কর্মদিবস: {item.working_days} দিন · অনুপস্থিত: {item.absent_days} দিন<br />
                                অনুপস্থিতির কাটা: − {fmt(item.absent_cut)}<br />
                                Advance কাটা: − {fmt(item.advance_cut)}<br />
                                অন্যান্য: {Number(item.other_adjust) >= 0 ? '+ ' : '− '}{fmt(Math.abs(item.other_adjust))}<br />
                                <b>নিট বেতন: {fmt(item.net)}</b>
                            </div>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

export default function Payroll({ month, run, runs, accounts, can_manage }) {
    const [m, setM] = useState(month);
    const build = useForm({ month });
    const pay = useForm({ account_id: accounts[0]?.id || '' });
    const doPay = (e) => {
        e.preventDefault();
        if (window.confirm(`মোট ${fmt(run.total)} Finance-এ খরচ entry হবে। বেতন দেওয়া হলো?`)) {
            pay.post(`/hrm/payroll/${run.id}/pay`, { preserveScroll: true });
        }
    };
    return (
        <AppLayout title="Payroll">
            <Head title="Payroll" />
            <div className="hrm">
                <div className="x-head">
                    <h3>Payroll — বেতনের হিসাব</h3>
                    <p>মাস বাছলে Attendance থেকে অনুপস্থিতি দেখে হিসাব নিজে তৈরি হয়। বেতন দেওয়া হলে মোট অঙ্কটা একটা entry হয়ে Finance-এ যায়।</p>
                </div>

                <div className="x-card">
                    <form className="form-grid" onSubmit={(e) => { e.preventDefault(); build.setData('month', m); router.post('/hrm/payroll/build', { month: m }, { preserveScroll: true }); }}>
                        <div className="fld"><label className="lbl">মাস</label>
                            <input className="inp" type="month" value={m} onChange={(e) => setM(e.target.value)} required /></div>
                        <button type="button" className="btn-g" onClick={() => router.get('/hrm/payroll', { month: m })}>দেখুন</button>
                        {can_manage && <button className="btn-p" type="submit">{run && run.status === 'draft' ? 'আবার হিসাব করো' : 'হিসাব তৈরি করো'}</button>}
                    </form>
                    <p className="x-note">অনুপস্থিতির হিসাব পুরো মাসের কর্মদিবস ধরে হয় — তাই হিসাবটা মাস শেষে তৈরি করলেই ঠিক আসবে।</p>
                    {runs.length > 0 && (
                        <div style={{ marginTop: 12, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                            {runs.map((r) => (
                                <button key={r.month} type="button" className="btn-mini" onClick={() => { setM(r.month); router.get('/hrm/payroll', { month: r.month }); }}>
                                    {r.month} · {fmt(r.total)} · {r.status === 'paid' ? 'দেওয়া হয়েছে' : 'খসড়া'}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {!run && (
                    <div className="x-card">
                        <p style={{ fontSize: 13.5, color: '#64748B', margin: 0 }}>এই মাসের হিসাব এখনো তৈরি হয়নি। উপরে মাস বেছে "হিসাব তৈরি করো" চাপুন। যেসব কর্মীর মাসিক বেতন User &amp; Role-এ বসানো আছে, শুধু তারাই হিসাবে আসবে।</p>
                    </div>
                )}

                {run && (
                    <div className="x-card" style={{ padding: 0, overflow: 'hidden' }}>
                        <div style={{ padding: '14px 18px 4px', display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
                            <h4 style={{ margin: 0 }}>{month} — মোট {fmt(run.total)}</h4>
                            {run.status === 'paid'
                                ? <span className="chip chip-green">বেতন দেওয়া হয়েছে</span>
                                : <span className="chip chip-amber">খসড়া — এখনো দেওয়া হয়নি</span>}
                        </div>
                        <table className="x-table" style={{ border: 'none' }}>
                            <thead>
                                <tr><th>নাম</th><th>মূল বেতন</th><th>অনুপস্থিত</th><th>অনুপস্থিতির কাটা</th><th>Advance কাটা</th><th>অন্যান্য</th><th>নিট</th><th></th></tr>
                            </thead>
                            <tbody>
                                {run.items.map((i) => <ItemRow key={i.id} item={i} runStatus={run.status} canManage={can_manage} />)}
                                {run.items.length === 0 && (
                                    <tr><td colSpan={8} style={{ color: '#94A3B8' }}>কারো মাসিক বেতন বসানো নেই — User &amp; Role-এ গিয়ে বেতন বসান।</td></tr>
                                )}
                            </tbody>
                        </table>
                        {run.status === 'draft' && can_manage && run.items.length > 0 && (
                            <form className="form-grid" style={{ padding: '14px 18px 18px' }} onSubmit={doPay}>
                                <div className="fld"><label className="lbl">কোন Account থেকে বেতন যাবে</label>
                                    <select className="inp" value={pay.data.account_id} onChange={(e) => pay.setData('account_id', e.target.value)} required>
                                        {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                                    </select></div>
                                <button className="btn-p" type="submit" disabled={pay.processing}>বেতন দেওয়া হলো</button>
                            </form>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
