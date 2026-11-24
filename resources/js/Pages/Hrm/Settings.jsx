import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';

const DAYS = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

export default function Settings({ settings, holidays, can_manage }) {
    const form = useForm({
        late_grace: settings.late_grace,
        max_break: settings.max_break,
        weekly_off: settings.weekly_off,
        require_checkin: settings.require_checkin,
        auto_checkout: settings.auto_checkout,
        payroll_enabled: settings.payroll_enabled,
    });
    const hol = useForm({ date: '', name: '' });
    const delHoliday = async (id) => { if (await confirmDialog(t('Delete this holiday?'))) router.delete(`/hrm/holidays/${id}`, { preserveScroll: true }); };

    return (
        <AppLayout title={t('HR Settings')}>
            <Head title={t('HR Settings')} />
            <div className="hrm">
                <div className="x-head">
                    <h3>{t('HR Settings')}</h3>
                    <p>{t('The attendance rules change from here. Only an Admin can change this page.')}</p>
                </div>

                <form onSubmit={(e) => { e.preventDefault(); form.post('/hrm/settings', { preserveScroll: true }); }}>
                    <div className="x-card">
                        <h4>{t('Attendance rules')}</h4>
                        <div className="form-grid">
                            <div className="fld"><label className="lbl">{t('Late grace (minutes)')}</label>
                                <input className="inp" type="number" min="0" max="120" value={form.data.late_grace} onChange={(e) => form.setData('late_grace', e.target.value)} disabled={!can_manage} required />
                                <span className="x-note" style={{ marginTop: 2 }}>{t('Coming within this time after the Shift starts is not counted as late.')}</span></div>
                            <div className="fld"><label className="lbl">{t('Maximum Break (minutes)')}</label>
                                <input className="inp" type="number" min="0" max="480" value={form.data.max_break} onChange={(e) => form.setData('max_break', e.target.value)} disabled={!can_manage} required /></div>
                            <div className="fld"><label className="lbl">{t('Weekly off')}</label>
                                <select className="inp" value={form.data.weekly_off} onChange={(e) => form.setData('weekly_off', e.target.value)} disabled={!can_manage}>
                                    {DAYS.map((d) => <option key={d} value={d}>{t(d)}</option>)}
                                </select></div>
                        </div>
                        <div className="switch-row">
                            <div>
                                <b style={{ fontSize: 14 }}>{t('Work blocked without Check-in')}</b>
                                <div style={{ fontSize: 13, color: '#64748B' }}>{t('When on, staff cannot open any other page until they Check-in today. This rule does not apply to Admins.')}</div>
                            </div>
                            <input type="checkbox" checked={form.data.require_checkin} onChange={(e) => form.setData('require_checkin', e.target.checked)} disabled={!can_manage} style={{ width: 20, height: 20 }} />
                        </div>
                        <div className="switch-row">
                            <div>
                                <b style={{ fontSize: 14 }}>{t('Auto Check-out')}</b>
                                <div style={{ fontSize: 13, color: '#64748B' }}>{t('If there is still no Check-out 10 minutes after the Shift ends, the system checks out on its own.')}</div>
                            </div>
                            <input type="checkbox" checked={form.data.auto_checkout} onChange={(e) => form.setData('auto_checkout', e.target.checked)} disabled={!can_manage} style={{ width: 20, height: 20 }} />
                        </div>
                        {can_manage && <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 12 }}>{t('Save')}</button>}
                    </div>

                    <div className="x-card">
                        <h4>{t('Payroll - salary accounts')}</h4>
                        <div className="switch-row" style={{ borderBottom: 'none', padding: 0 }}>
                            <div>
                                <b style={{ fontSize: 14 }}>{t('Turn on Payroll')}</b>
                                <div style={{ fontSize: 13, color: '#64748B' }}>{t('It is off now. Turning it on brings the Payroll menu into the sidebar - there, every month the salary account is made from Attendance, and the total becomes an expense in Finance. While off, salary keeps running through Finance expense entries as before.')}</div>
                            </div>
                            <input type="checkbox" checked={form.data.payroll_enabled} onChange={(e) => form.setData('payroll_enabled', e.target.checked)} disabled={!can_manage} style={{ width: 20, height: 20 }} />
                        </div>
                        {can_manage && <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 12 }}>{t('Save')}</button>}
                    </div>
                </form>

                <div className="x-card">
                    <h4>{t('Holidays (of the year)')}</h4>
                    {holidays.length === 0 && <p style={{ fontSize: 13.5, color: '#94A3B8' }}>{t('No holiday added yet.')}</p>}
                    {holidays.map((h) => (
                        <div key={h.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid #F1F5F9' }}>
                            <span style={{ fontSize: 13.5 }}><b>{h.name}</b> · {h.date}</span>
                            {can_manage && <button type="button" className="btn-mini danger" onClick={() => delHoliday(h.id)}>{t('Delete')}</button>}
                        </div>
                    ))}
                    {can_manage && Object.keys(hol.errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" style={{ marginTop: 12 }}>
                            {Object.values(hol.errors)[0]}
                        </div>
                    )}
                    {can_manage && (
                        <form className="form-grid" style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); hol.post('/hrm/holidays', { preserveScroll: true, onSuccess: () => hol.reset() }); }}>
                            <div className="fld"><label className="lbl">{t('Date')}</label>
                                <input className="inp" type="date" value={hol.data.date} onChange={(e) => hol.setData('date', e.target.value)} required /></div>
                            <div className="fld"><label className="lbl">{t('Day name')}</label>
                                <input className="inp" value={hol.data.name} onChange={(e) => hol.setData('name', e.target.value)} placeholder={t('e.g. Eid-ul-Fitr')} required /></div>
                            <button className="btn-p" type="submit" disabled={hol.processing}>{t('Add')}</button>
                        </form>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
