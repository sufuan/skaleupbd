import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import EntrySheet, { FinActions, fmt } from './EntrySheet';
import { t } from '@/lib/i18n';

const TYPE_LABEL = { cash: 'Cash', bank: 'Bank', mobile: 'Mobile Money' };

export default function Accounts({ accounts = [], categories = [], canSee }) {
    const [sheet, setSheet] = useState(false);
    const [edit, setEdit] = useState(null);
    const add = useForm({ name: '', type: 'cash', opening_balance: '' });
    const editForm = useForm({ name: '', type: 'cash', active: true });

    const openEdit = (a) => { setEdit(a); editForm.setData({ name: a.name, type: a.type, active: a.active }); };

    return (
        <AppLayout title={t('Accounts & Bank')}>
            <Head title={t('Accounts & Bank')} />
            <div className="fin">
                <div className="x-head">
                    <h3>{t('Accounts & Bank')}</h3>
                    <p>{t('Where the money is — the state of every account today. To see all transactions, go to the Cash Flow page (one single ledger).')}</p>
                </div>

                {canSee && (
                    <div className="x-kpis">
                        {accounts.map((a) => (
                            <div className="x-kpi" key={a.id}><b>{fmt(a.balance)}</b><span>{a.name} ({t(TYPE_LABEL[a.type])})</span></div>
                        ))}
                    </div>
                )}

                <div style={{ overflowX: 'auto', marginBottom: 14 }}>
                    <table className="x-table">
                        <thead><tr><th>{t('Account')}</th><th>{t('Came in today')}</th><th>{t('Went out today')}</th><th>{t('Balance')}</th><th></th></tr></thead>
                        <tbody>
                            {accounts.map((a) => (
                                <tr key={a.id} style={!a.active ? { opacity: 0.55 } : undefined}>
                                    <td>{a.name} <span style={{ color: '#94A3B8' }}>· {t(TYPE_LABEL[a.type])}</span>{!a.active && <span className="chip chip-grey" style={{ marginLeft: 6 }}>{t('Inactive')}</span>}</td>
                                    <td style={{ color: '#16A34A' }}>+ {fmt(a.today_in)}</td>
                                    <td style={{ color: '#DC2626' }}>− {fmt(a.today_out)}</td>
                                    <td><b>{canSee ? fmt(a.balance) : '—'}</b></td>
                                    <td><button className="btn-s" onClick={() => openEdit(a)}>{t('Edit')}</button></td>
                                </tr>
                            ))}
                            {!accounts.length && <tr><td colSpan={5}>{t('No account yet — add one from below.')}</td></tr>}
                        </tbody>
                    </table>
                </div>

                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
                    <button className="btn-p" onClick={() => setSheet(true)}>+ {t('Transaction (deposit / withdrawal / Transfer)')}</button>
                </div>

                <div className="x-card">
                    <h4>{t('New Account')}</h4>
                    <form onSubmit={(e) => { e.preventDefault(); add.post('/finance/accounts', { onSuccess: () => add.reset() }); }}
                        style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'end' }}>
                        <div><label className="lbl">{t('Name')}</label>
                            <input className="inp" value={add.data.name} onChange={(e) => add.setData('name', e.target.value)} placeholder={t('e.g. Dutch-Bangla Bank')} required /></div>
                        <div><label className="lbl">{t('Type')}</label>
                            <select className="inp" value={add.data.type} onChange={(e) => add.setData('type', e.target.value)}>
                                <option value="cash">{t('Cash')}</option><option value="mobile">{t('Mobile Money')}</option><option value="bank">{t('Bank')}</option>
                            </select></div>
                        <div><label className="lbl">{t('Opening balance (৳)')}</label>
                            <input className="inp" type="number" min="0" step="any" value={add.data.opening_balance} onChange={(e) => add.setData('opening_balance', e.target.value)} placeholder="0" /></div>
                        <button className="btn-p" type="submit" disabled={add.processing}>{t('Add')}</button>
                    </form>
                </div>

                {edit && (
                    <div className="x-card">
                        <h4>{t('Change Account — :name', { name: edit.name })}</h4>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.patch(`/finance/accounts/${edit.id}`, { onSuccess: () => setEdit(null) }); }}
                            style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'end' }}>
                            <div><label className="lbl">{t('Name')}</label>
                                <input className="inp" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required /></div>
                            <div><label className="lbl">{t('Type')}</label>
                                <select className="inp" value={editForm.data.type} onChange={(e) => editForm.setData('type', e.target.value)}>
                                    <option value="cash">{t('Cash')}</option><option value="mobile">{t('Mobile Money')}</option><option value="bank">{t('Bank')}</option>
                                </select></div>
                            <label style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 14 }}>
                                <input type="checkbox" checked={editForm.data.active} onChange={(e) => editForm.setData('active', e.target.checked)} /> {t('Is active')}
                            </label>
                            <button className="btn-p" type="submit" disabled={editForm.processing}>{t('Save')}</button>
                            <button className="btn-s" type="button" onClick={() => setEdit(null)}>{t('Discard')}</button>
                        </form>
                    </div>
                )}

                <EntrySheet open={sheet} kind="deposit" accounts={accounts} categories={categories} onClose={() => setSheet(false)} />
                <FinActions accounts={accounts} categories={categories} />
            </div>
        </AppLayout>
    );
}
