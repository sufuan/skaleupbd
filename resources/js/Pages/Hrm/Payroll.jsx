import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { confirmDialog } from '@/lib/confirm';
import AppLayout from '@/Layouts/AppLayout';
import { fmt } from './parts';
import { t } from '@/lib/i18n';

function ItemRow({ item, runStatus, canManage }) {
    const [slip, setSlip] = useState(false);
    const form = useForm({ advance_cut: item.advance_cut ?? 0, other_adjust: item.other_adjust ?? 0 });
    const editable = runStatus === 'draft' && canManage;
    return (
        <>
            <tr>
                <td><b>{item.name}</b></td>
                <td>{fmt(item.basic)}</td>
                <td>{item.absent_days} {t('days')}</td>
                <td>− {fmt(item.absent_cut)}</td>
                <td>{fmt(item.advance_cut)}</td>
                <td>{Number(item.other_adjust) >= 0 ? '+ ' : '− '}{fmt(Math.abs(item.other_adjust))}</td>
                <td><b>{fmt(item.net)}</b></td>
                <td>
                    <div className="row-actions">
                        <button type="button" className="btn-mini" onClick={() => setSlip(!slip)}>{t('Payslip')}</button>
                    </div>
                </td>
            </tr>
            {editable && (
                <tr>
                    <td colSpan={8} style={{ background: '#FAF8FC' }}>
                        <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/payroll/items/${item.id}`, { preserveScroll: true }); }}>
                            <div className="fld"><label className="lbl">{t(':name - Advance cut (৳)', { name: item.name })}</label>
                                <input className="inp" type="number" min="0" step="any" value={form.data.advance_cut} onChange={(e) => form.setData('advance_cut', e.target.value)} /></div>
                            <div className="fld"><label className="lbl">{t('Other additions/cuts (৳)')}</label>
                                <input className="inp" type="number" step="any" value={form.data.other_adjust} onChange={(e) => form.setData('other_adjust', e.target.value)} /></div>
                            <button className="btn-p" type="submit" disabled={form.processing}>{t('Change the account')}</button>
                        </form>
                    </td>
                </tr>
            )}
            {slip && (
                <tr>
                    <td colSpan={8} style={{ background: '#fff' }}>
                        <div style={{ border: '1px dashed #CBD5E1', borderRadius: 10, padding: '12px 16px', maxWidth: 420 }}>
                            <b style={{ fontSize: 15 }}>{t('Payslip - :name', { name: item.name })}</b>
                            <div style={{ fontSize: 13.5, lineHeight: 2, marginTop: 6 }}>
                                {t('Basic salary:')} {fmt(item.basic)}<br />
                                {t('Working days: :wd days · Absent: :ad days', { wd: item.working_days, ad: item.absent_days })}<br />
                                {t('Absence cut:')} − {fmt(item.absent_cut)}<br />
                                {t('Advance cut:')} − {fmt(item.advance_cut)}<br />
                                {t('Other:')} {Number(item.other_adjust) >= 0 ? '+ ' : '− '}{fmt(Math.abs(item.other_adjust))}<br />
                                <b>{t('Net salary:')} {fmt(item.net)}</b>
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
    const doPay = async (e) => {
        e.preventDefault();
        if (await confirmDialog(t('A total of :total will become an expense entry in Finance. Salary paid?', { total: fmt(run.total) }))) {
            pay.post(`/hrm/payroll/${run.id}/pay`, { preserveScroll: true });
        }
    };
    return (
        <AppLayout title={t('Payroll')}>
            <Head title={t('Payroll')} />
            <div className="hrm">
                <div className="x-head">
                    <h3>{t('Payroll - salary accounts')}</h3>
                    <p>{t('Pick a month and the account is made on its own by looking at absences in Attendance. When the salary is paid, the total amount becomes one entry in Finance.')}</p>
                </div>

                <div className="x-card">
                    <form className="form-grid" onSubmit={(e) => { e.preventDefault(); build.setData('month', m); router.post('/hrm/payroll/build', { month: m }, { preserveScroll: true }); }}>
                        <div className="fld"><label className="lbl">{t('Month')}</label>
                            <input className="inp" type="month" value={m} onChange={(e) => setM(e.target.value)} required /></div>
                        <button type="button" className="btn-g" onClick={() => router.get('/hrm/payroll', { month: m })}>{t('View')}</button>
                        {can_manage && <button className="btn-p" type="submit">{run && run.status === 'draft' ? t('Calculate again') : t('Make the account')}</button>}
                    </form>
                    <p className="x-note">{t("The absence account is made over the whole month's working days - so the account comes out right when it is made at the end of the month.")}</p>
                    {runs.length > 0 && (
                        <div style={{ marginTop: 12, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                            {runs.map((r) => (
                                <button key={r.month} type="button" className="btn-mini" onClick={() => { setM(r.month); router.get('/hrm/payroll', { month: r.month }); }}>
                                    {r.month} · {fmt(r.total)} · {r.status === 'paid' ? t('Paid') : t('Draft')}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {!run && (
                    <div className="x-card">
                        <p style={{ fontSize: 13.5, color: '#64748B', margin: 0 }}>{t('The account for this month has not been made yet. Pick a month above and press "Make the account". Only the staff whose monthly salary is set in Settings → Staff & Roles come into this account.')}</p>
                    </div>
                )}

                {run && (
                    <div className="x-card" style={{ padding: 0, overflow: 'hidden' }}>
                        <div style={{ padding: '14px 18px 4px', display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
                            <h4 style={{ margin: 0 }}>{t(':month - Total :total', { month, total: fmt(run.total) })}</h4>
                            {run.status === 'paid'
                                ? <span className="chip chip-green">{t('Salary paid')}</span>
                                : <span className="chip chip-amber">{t('Draft - not paid yet')}</span>}
                        </div>
                        <table className="x-table" style={{ border: 'none' }}>
                            <thead>
                                <tr><th>{t('Name')}</th><th>{t('Basic salary')}</th><th>{t('Absent')}</th><th>{t('Absence cut')}</th><th>{t('Advance cut')}</th><th>{t('Other')}</th><th>{t('Net')}</th><th></th></tr>
                            </thead>
                            <tbody>
                                {run.items.map((i) => <ItemRow key={i.id} item={i} runStatus={run.status} canManage={can_manage} />)}
                                {run.items.length === 0 && (
                                    <tr><td colSpan={8} style={{ color: '#94A3B8' }}>{t('No one has a monthly salary set - go to Settings → Staff & Roles and set the salaries.')}</td></tr>
                                )}
                            </tbody>
                        </table>
                        {run.status === 'draft' && can_manage && run.items.length > 0 && (
                            <form className="form-grid" style={{ padding: '14px 18px 18px' }} onSubmit={doPay}>
                                <div className="fld"><label className="lbl">{t('From which Account will the salary go')}</label>
                                    <select className="inp" value={pay.data.account_id} onChange={(e) => pay.setData('account_id', e.target.value)} required>
                                        {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                                    </select></div>
                                <button className="btn-p" type="submit" disabled={pay.processing}>{t('Salary is paid')}</button>
                            </form>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
