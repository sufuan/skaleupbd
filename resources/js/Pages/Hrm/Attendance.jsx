import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { confirmDialog } from '@/lib/confirm';
import AppLayout from '@/Layouts/AppLayout';
import { TodayTable, LeaveCards } from './parts';

function hoursLabel(minutes) {
    if (!minutes) return '—';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m ? `${h} ঘণ্টা ${m} মিনিট` : `${h} ঘণ্টা`;
}

function DayCard({ my }) {
    const post = (url) => router.post(url, {}, { preserveScroll: true });
    return (
        <div className="x-card">
            <h4>আমার আজকের দিন</h4>
            {!my && (
                <div className="daycard">
                    <p style={{ fontSize: 13.5, color: '#64748B', margin: 0 }}>আজ এখনো Check-in হয়নি।</p>
                    <button type="button" className="btn-p" onClick={() => post('/hrm/attendance/check-in')}>Check-in করো</button>
                </div>
            )}
            {my && (
                <div className="daycard">
                    <div className="nums">
                        <div><b>{my.check_in || '—'}</b><span>Check-in</span></div>
                        <div><b>{my.check_out || '—'}</b><span>Check-out</span></div>
                        <div><b>{my.break_minutes ? `${my.break_minutes} মিনিট` : '—'}</b><span>Break</span></div>
                        <div><b>{hoursLabel(my.work_minutes)}</b><span>কাজের সময়</span></div>
                    </div>
                    {!my.check_out && (
                        <div className="row-actions">
                            <button type="button" className="btn-g" onClick={() => post('/hrm/attendance/break')}>
                                {my.on_break ? 'Break শেষ করো' : 'Break নাও'}
                            </button>
                            <button type="button" className="btn-p" onClick={() => post('/hrm/attendance/check-out')}>Check-out করো</button>
                        </div>
                    )}
                    {my.check_out && <span className="chip chip-green">আজকের কাজ শেষ</span>}
                </div>
            )}
        </div>
    );
}

function LeaveApply({ myLeaves }) {
    const form = useForm({ from_date: '', to_date: '', reason: '' });
    const statusChip = (s) => s === 'approved'
        ? <span className="chip chip-green">অনুমোদিত</span>
        : s === 'rejected'
            ? <span className="chip chip-red">বাতিল</span>
            : <span className="chip chip-amber">অপেক্ষায়</span>;
    return (
        <div className="x-card">
            <h4>ছুটির আবেদন</h4>
            <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/hrm/leaves', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                <div className="fld"><label className="lbl">কবে থেকে</label>
                    <input className="inp" type="date" value={form.data.from_date} onChange={(e) => form.setData('from_date', e.target.value)} required /></div>
                <div className="fld"><label className="lbl">কবে পর্যন্ত</label>
                    <input className="inp" type="date" value={form.data.to_date} onChange={(e) => form.setData('to_date', e.target.value)} required /></div>
                <div className="fld"><label className="lbl">কারণ</label>
                    <input className="inp" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="যেমন: পারিবারিক কাজ" /></div>
                <button className="btn-p" type="submit" disabled={form.processing}>আবেদন পাঠাও</button>
            </form>
            {myLeaves.length > 0 && (
                <table className="x-table" style={{ marginTop: 14 }}>
                    <thead><tr><th>তারিখ</th><th>দিন</th><th>কারণ</th><th>অবস্থা</th></tr></thead>
                    <tbody>
                        {myLeaves.map((l) => (
                            <tr key={l.id}><td>{l.from} — {l.to}</td><td>{l.days}</td><td>{l.reason || '—'}</td><td>{statusChip(l.status)}</td></tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

function ReportTab({ report }) {
    const [month, setMonth] = useState(report.month);
    return (
        <div className="x-card">
            <h4>মাসের Report</h4>
            <div className="form-grid" style={{ marginBottom: 12 }}>
                <div className="fld"><label className="lbl">মাস</label>
                    <input className="inp" type="month" value={month} onChange={(e) => setMonth(e.target.value)} /></div>
                <button type="button" className="btn-p" onClick={() => router.get('/hrm/attendance', { tab: 'report', month }, { preserveState: true })}>দেখুন</button>
                <a className="btn-g" href={`/hrm/attendance/export?month=${report.month}`} style={{ textDecoration: 'none', display: 'inline-block' }}>CSV নামাও</a>
            </div>
            <p className="x-note" style={{ marginTop: 0, marginBottom: 10 }}>এই মাসে কর্মদিবস ছিল {report.working_days} দিন (সাপ্তাহিক ছুটি ও ছুটির দিন বাদে)।</p>
            <table className="x-table">
                <thead><tr><th>নাম</th><th>এসেছে</th><th>দেরি</th><th>ছুটি</th><th>অনুপস্থিত</th><th>মোট কাজের ঘণ্টা</th></tr></thead>
                <tbody>
                    {report.rows.map((r) => (
                        <tr key={r.name}><td>{r.name}</td><td>{r.present}</td><td>{r.late}</td><td>{r.leave}</td><td>{r.absent}</td><td>{r.hours}</td></tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ShiftCard({ shift, staffOptions, canManage }) {
    const [editing, setEditing] = useState(false);
    const [assigning, setAssigning] = useState(false);
    const form = useForm({ name: shift.name, start_time: shift.start, end_time: shift.end });
    const assign = useForm({ member_ids: shift.members.map((m) => m.id) });
    const toggleMember = (id) => {
        const ids = assign.data.member_ids.includes(id)
            ? assign.data.member_ids.filter((x) => x !== id)
            : [...assign.data.member_ids, id];
        assign.setData('member_ids', ids);
    };
    const del = async () => { if (await confirmDialog('এই Shift মুছে ফেলতে চান?')) router.delete(`/hrm/shifts/${shift.id}`, { preserveScroll: true }); };
    return (
        <div className="x-card">
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                <h4 style={{ margin: 0 }}>{shift.name} · {shift.start} – {shift.end}</h4>
                {canManage && (
                    <div className="row-actions">
                        <button type="button" className="btn-mini" onClick={() => setAssigning(!assigning)}>কর্মী ঠিক করো</button>
                        <button type="button" className="btn-mini" onClick={() => setEditing(!editing)}>Edit</button>
                        <button type="button" className="btn-mini danger" onClick={del}>মুছুন</button>
                    </div>
                )}
            </div>
            <div style={{ marginTop: 10, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {shift.members.map((m) => <span key={m.id} className="chip chip-grey">{m.name}</span>)}
                {shift.members.length === 0 && <span style={{ fontSize: 13, color: '#94A3B8' }}>এই Shift-এ এখনো কেউ নেই</span>}
            </div>
            {editing && (
                <form className="form-grid" style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/shifts/${shift.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }}>
                    <div className="fld"><label className="lbl">Shift-এর নাম</label>
                        <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                    <div className="fld"><label className="lbl">শুরু</label>
                        <input className="inp" type="time" value={form.data.start_time} onChange={(e) => form.setData('start_time', e.target.value)} required /></div>
                    <div className="fld"><label className="lbl">শেষ</label>
                        <input className="inp" type="time" value={form.data.end_time} onChange={(e) => form.setData('end_time', e.target.value)} required /></div>
                    <button className="btn-p" type="submit" disabled={form.processing}>সেভ করো</button>
                </form>
            )}
            {assigning && (
                <form style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); assign.post(`/hrm/shifts/${shift.id}/assign`, { preserveScroll: true, onSuccess: () => setAssigning(false) }); }}>
                    <div className="perm-grid">
                        {staffOptions.map((s) => (
                            <label key={s.id}>
                                <input type="checkbox" checked={assign.data.member_ids.includes(s.id)} onChange={() => toggleMember(s.id)} /> {s.name}
                            </label>
                        ))}
                    </div>
                    <p className="x-note">একজন কর্মী একটাই Shift-এ থাকে — অন্য Shift থেকে এখানে আনলে সে সেখান থেকে সরে আসবে।</p>
                    <button className="btn-p" type="submit" disabled={assign.processing} style={{ marginTop: 8 }}>সেভ করো</button>
                </form>
            )}
        </div>
    );
}

function ShiftsTab({ shifts, staffOptions, canManage }) {
    const form = useForm({ name: '', start_time: '09:00', end_time: '18:00' });
    return (
        <>
            {shifts.map((s) => <ShiftCard key={s.id} shift={s} staffOptions={staffOptions} canManage={canManage} />)}
            {canManage && (
                <div className="x-card">
                    <h4>নতুন Shift</h4>
                    <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/hrm/shifts', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                        <div className="fld"><label className="lbl">Shift-এর নাম</label>
                            <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="যেমন: রাতের Shift" required /></div>
                        <div className="fld"><label className="lbl">শুরু</label>
                            <input className="inp" type="time" value={form.data.start_time} onChange={(e) => form.setData('start_time', e.target.value)} required /></div>
                        <div className="fld"><label className="lbl">শেষ</label>
                            <input className="inp" type="time" value={form.data.end_time} onChange={(e) => form.setData('end_time', e.target.value)} required /></div>
                        <button className="btn-p" type="submit" disabled={form.processing}>Shift যোগ করো</button>
                    </form>
                </div>
            )}
        </>
    );
}

export default function Attendance({ tab, my_record, today, pending_leaves, my_leaves, report, shifts, staff_options, can_manage }) {
    return (
        <AppLayout title="Attendance">
            <Head title="Attendance" />
            <div className="hrm">
                <div className="x-head">
                    <h3>Attendance</h3>
                    <p>প্রতিদিনের আসা-যাওয়ার হিসাব। দেরি ধরা হয় নিজের Shift-এর সময় থেকে।</p>
                </div>

                <div className="tabs">
                    <Link href="/hrm/attendance?tab=today" className={tab === 'today' ? 'on' : ''}>আজকের উপস্থিতি</Link>
                    <Link href="/hrm/attendance?tab=report" className={tab === 'report' ? 'on' : ''}>মাসের Report</Link>
                    <Link href="/hrm/attendance?tab=shifts" className={tab === 'shifts' ? 'on' : ''}>Shifts</Link>
                </div>

                {tab === 'today' && (
                    <>
                        <DayCard my={my_record} />
                        <LeaveCards leaves={pending_leaves} canManage={can_manage} />
                        <div className="x-card">
                            <h4>আজ সবাই</h4>
                            <TodayTable rows={today} />
                        </div>
                        <LeaveApply myLeaves={my_leaves} />
                    </>
                )}
                {tab === 'report' && report && <ReportTab report={report} />}
                {tab === 'shifts' && shifts && <ShiftsTab shifts={shifts} staffOptions={staff_options} canManage={can_manage} />}
            </div>
        </AppLayout>
    );
}
