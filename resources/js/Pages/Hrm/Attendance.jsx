import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { confirmDialog } from '@/lib/confirm';
import AppLayout from '@/Layouts/AppLayout';
import { TodayTable, LeaveCards } from './parts';
import { t } from '@/lib/i18n';

function hoursLabel(minutes) {
    if (!minutes) return '-';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m ? t(':h hours :m minutes', { h, m }) : t(':h hours', { h });
}

function DayCard({ my }) {
    const post = (url) => router.post(url, {}, { preserveScroll: true });
    return (
        <div className="x-card">
            <h4>{t('My day today')}</h4>
            {!my && (
                <div className="daycard">
                    <p style={{ fontSize: 13.5, color: '#64748B', margin: 0 }}>{t('Not checked in yet today.')}</p>
                    <button type="button" className="btn-p" onClick={() => post('/hrm/attendance/check-in')}>{t('Check in')}</button>
                </div>
            )}
            {my && (
                <div className="daycard">
                    <div className="nums">
                        <div><b>{my.check_in || '-'}</b><span>{t('Check-in')}</span></div>
                        <div><b>{my.check_out || '-'}</b><span>{t('Check-out')}</span></div>
                        <div><b>{my.break_minutes ? t(':n minutes', { n: my.break_minutes }) : '-'}</b><span>{t('Break')}</span></div>
                        <div><b>{hoursLabel(my.work_minutes)}</b><span>{t('Work time')}</span></div>
                    </div>
                    {!my.check_out && (
                        <div className="row-actions">
                            <button type="button" className="btn-g" onClick={() => post('/hrm/attendance/break')}>
                                {my.on_break ? t('End break') : t('Take a break')}
                            </button>
                            <button type="button" className="btn-p" onClick={() => post('/hrm/attendance/check-out')}>{t('Check out')}</button>
                        </div>
                    )}
                    {my.check_out && <span className="chip chip-green">{t("Today's work is done")}</span>}
                </div>
            )}
        </div>
    );
}

function LeaveApply({ myLeaves }) {
    const form = useForm({ from_date: '', to_date: '', reason: '' });
    const statusChip = (s) => s === 'approved'
        ? <span className="chip chip-green">{t('Approved')}</span>
        : s === 'rejected'
            ? <span className="chip chip-red">{t('Rejected')}</span>
            : <span className="chip chip-amber">{t('Waiting')}</span>;
    return (
        <div className="x-card">
            <h4>{t('Leave application')}</h4>
            <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/hrm/leaves', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                <div className="fld"><label className="lbl">{t('From when')}</label>
                    <input className="inp" type="date" value={form.data.from_date} onChange={(e) => form.setData('from_date', e.target.value)} required /></div>
                <div className="fld"><label className="lbl">{t('Until when')}</label>
                    <input className="inp" type="date" value={form.data.to_date} onChange={(e) => form.setData('to_date', e.target.value)} required /></div>
                <div className="fld"><label className="lbl">{t('Reason')}</label>
                    <input className="inp" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder={t('e.g. Family work')} /></div>
                <button className="btn-p" type="submit" disabled={form.processing}>{t('Send application')}</button>
            </form>
            {myLeaves.length > 0 && (
                <table className="x-table" style={{ marginTop: 14 }}>
                    <thead><tr><th>{t('Date')}</th><th>{t('Days')}</th><th>{t('Reason')}</th><th>{t('Status')}</th></tr></thead>
                    <tbody>
                        {myLeaves.map((l) => (
                            <tr key={l.id}><td>{l.from} - {l.to}</td><td>{l.days}</td><td>{l.reason || '-'}</td><td>{statusChip(l.status)}</td></tr>
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
            <h4>{t('Monthly Report')}</h4>
            <div className="form-grid" style={{ marginBottom: 12 }}>
                <div className="fld"><label className="lbl">{t('Month')}</label>
                    <input className="inp" type="month" value={month} onChange={(e) => setMonth(e.target.value)} /></div>
                <button type="button" className="btn-p" onClick={() => router.get('/hrm/attendance', { tab: 'report', month }, { preserveState: true })}>{t('View')}</button>
                <a className="btn-g" href={`/hrm/attendance/export?month=${report.month}`} style={{ textDecoration: 'none', display: 'inline-block' }}>{t('Download CSV')}</a>
            </div>
            <p className="x-note" style={{ marginTop: 0, marginBottom: 10 }}>{t('This month had :days working days (excluding weekly offs and holidays).', { days: report.working_days })}</p>
            <table className="x-table">
                <thead><tr><th>{t('Name')}</th><th>{t('Came in')}</th><th>{t('Late')}</th><th>{t('Leave')}</th><th>{t('Absent')}</th><th>{t('Total work hours')}</th></tr></thead>
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
    const del = async () => { if (await confirmDialog(t('Delete this Shift?'))) router.delete(`/hrm/shifts/${shift.id}`, { preserveScroll: true }); };
    return (
        <div className="x-card">
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                <h4 style={{ margin: 0 }}>{shift.name} · {shift.start} – {shift.end}</h4>
                {canManage && (
                    <div className="row-actions">
                        <button type="button" className="btn-mini" onClick={() => setAssigning(!assigning)}>{t('Set staff')}</button>
                        <button type="button" className="btn-mini" onClick={() => setEditing(!editing)}>{t('Edit')}</button>
                        <button type="button" className="btn-mini danger" onClick={del}>{t('Delete')}</button>
                    </div>
                )}
            </div>
            <div style={{ marginTop: 10, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {shift.members.map((m) => <span key={m.id} className="chip chip-grey">{m.name}</span>)}
                {shift.members.length === 0 && <span style={{ fontSize: 13, color: '#94A3B8' }}>{t('No one in this Shift yet')}</span>}
            </div>
            {editing && (
                <form className="form-grid" style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/shifts/${shift.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }}>
                    <div className="fld"><label className="lbl">{t('Shift name')}</label>
                        <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                    <div className="fld"><label className="lbl">{t('Start')}</label>
                        <input className="inp" type="time" value={form.data.start_time} onChange={(e) => form.setData('start_time', e.target.value)} required /></div>
                    <div className="fld"><label className="lbl">{t('End')}</label>
                        <input className="inp" type="time" value={form.data.end_time} onChange={(e) => form.setData('end_time', e.target.value)} required /></div>
                    <button className="btn-p" type="submit" disabled={form.processing}>{t('Save')}</button>
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
                    <p className="x-note">{t('One staff member stays in one Shift only - bringing them here from another Shift moves them out of there.')}</p>
                    <button className="btn-p" type="submit" disabled={assign.processing} style={{ marginTop: 8 }}>{t('Save')}</button>
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
                    <h4>{t('New Shift')}</h4>
                    <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/hrm/shifts', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                        <div className="fld"><label className="lbl">{t('Shift name')}</label>
                            <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={t('e.g. Night Shift')} required /></div>
                        <div className="fld"><label className="lbl">{t('Start')}</label>
                            <input className="inp" type="time" value={form.data.start_time} onChange={(e) => form.setData('start_time', e.target.value)} required /></div>
                        <div className="fld"><label className="lbl">{t('End')}</label>
                            <input className="inp" type="time" value={form.data.end_time} onChange={(e) => form.setData('end_time', e.target.value)} required /></div>
                        <button className="btn-p" type="submit" disabled={form.processing}>{t('Add Shift')}</button>
                    </form>
                </div>
            )}
        </>
    );
}

export default function Attendance({ tab, my_record, today, pending_leaves, my_leaves, report, shifts, staff_options, can_manage }) {
    return (
        <AppLayout title={t('Attendance')}>
            <Head title={t('Attendance')} />
            <div className="hrm">
                <div className="x-head">
                    <h3>{t('Attendance')}</h3>
                    <p>{t("The daily coming-and-going account. Lateness is counted from your own Shift's time.")}</p>
                </div>

                <div className="tabs">
                    <Link href="/hrm/attendance?tab=today" className={tab === 'today' ? 'on' : ''}>{t("Today's attendance")}</Link>
                    <Link href="/hrm/attendance?tab=report" className={tab === 'report' ? 'on' : ''}>{t('Monthly Report')}</Link>
                    <Link href="/hrm/attendance?tab=shifts" className={tab === 'shifts' ? 'on' : ''}>{t('Shifts')}</Link>
                </div>

                {tab === 'today' && (
                    <>
                        <DayCard my={my_record} />
                        <LeaveCards leaves={pending_leaves} canManage={can_manage} />
                        <div className="x-card">
                            <h4>{t('Everyone today')}</h4>
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
