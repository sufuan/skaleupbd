import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { confirmDialog } from '@/lib/confirm';
import AppLayout from '@/Layouts/AppLayout';
import EntrySheet, { FinActions, fmt, fmtSigned } from './EntrySheet';
import { t } from '@/lib/i18n';

export default function IncomeExpense(props) {
    const { tab, categoryFilter, byCategory, list, totalIncome, totalExpense, accounts, categories, canSee } = props;
    const [sheet, setSheet] = useState(false);
    const [kind, setKind] = useState('expense');
    const catForm = useForm({ name: '', kind: 'expense' });
    const [editCat, setEditCat] = useState(null);
    const editForm = useForm({ name: '' });

    const go = (params) => router.get('/finance/income-expense', params, { preserveState: true, replace: true });
    const activeFilter = categoryFilter ? categories.find((c) => c.id === categoryFilter) : null;

    const voidTxn = async (x) => { if (await confirmDialog(t('Void this entry?') + ` (${x.note})`)) router.post(`/finance/transactions/${x.id}/void`); };

    return (
        <AppLayout title={t('Income & Expense')}>
            <Head title={t('Income & Expense')} />
            <div className="fin">
                <div className="x-head">
                    <h3>{t('Income & Expense')}</h3>
                    <p>{t('The book of all income and expenses. Courier payouts and order advances come here on their own — writing them again by hand would count them twice.')}</p>
                </div>

                <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>
                    <button className="btn-p" onClick={() => { setKind('income'); setSheet(true); }}>+ {t('Add income')}</button>
                    <button className="btn-s" onClick={() => { setKind('expense'); setSheet(true); }}>+ {t('Add expense')}</button>
                    <a className="btn-s" href="/finance/income-expense/export" style={{ marginLeft: 'auto' }}>{t('CSV Download')}</a>
                </div>

                <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
                    {[['overview', 'Overview'], ['expense', 'Expense'], ['income', 'Income'], ['settings', 'Settings']].map(([k, l]) => (
                        <button key={k} className={tab === k ? 'btn-p' : 'btn-s'} onClick={() => go({ tab: k })}>{t(l)}</button>
                    ))}
                </div>

                {tab === 'overview' && (
                    <>
                        <div className="x-kpis">
                            <div className="x-kpi green"><b>{fmt(totalIncome)}</b><span>{t('Income this month')}</span></div>
                            <div className="x-kpi red"><b>{fmt(totalExpense)}</b><span>{t('Expenses this month')}</span></div>
                            <div className="x-kpi"><b>{fmt(totalIncome - totalExpense)}</b><span>{t('Net')}</span></div>
                        </div>
                        <div style={{ overflowX: 'auto' }}>
                            <table className="x-table">
                                <thead><tr><th>{t('Category')}</th><th>{t('Income')}</th><th>{t('Expense')}</th></tr></thead>
                                <tbody>
                                    {byCategory.map((r) => (
                                        <tr key={r.name}><td>{r.name}</td>
                                            <td style={{ color: '#16A34A' }}>{r.income ? '+ ' + fmt(r.income) : '—'}</td>
                                            <td style={{ color: '#DC2626' }}>{r.expense ? '− ' + fmt(r.expense) : '—'}</td></tr>
                                    ))}
                                    {!byCategory.length && <tr><td colSpan={3}>{t('No entries this month.')}</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                {(tab === 'expense' || tab === 'income') && (
                    <>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12, flexWrap: 'wrap' }}>
                            <label style={{ fontSize: 13.5, color: '#64748B' }}>{t('Filter by Category:')}</label>
                            <select className="inp" style={{ width: 200 }} value={categoryFilter || ''}
                                onChange={(e) => go({ tab, category: e.target.value || undefined })}>
                                <option value="">{t('All categories')}</option>
                                {categories.filter((c) => c.kind === tab).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                            {activeFilter && <span className="chip chip-amber">{t('Filter: :name — the total below is for this filter', { name: activeFilter.name })}</span>}
                        </div>
                        <TxnTable list={list} onVoid={voidTxn} />
                    </>
                )}

                {tab === 'settings' && (
                    <div className="x-card">
                        <h4>{t('Organize categories')}</h4>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 18 }} className="cust-2col">
                            {['expense', 'income'].map((kind) => (
                                <div key={kind}>
                                    <b style={{ fontSize: 14 }}>{kind === 'expense' ? t('Expense categories') : t('Income categories')}</b>
                                    {categories.filter((c) => c.kind === kind).map((c) => (
                                        <div key={c.id} style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '7px 0', borderBottom: '1px solid #F1F5F9' }}>
                                            <span style={{ flex: 1 }}>{c.name}{c.is_system && <span className="chip chip-grey" style={{ marginLeft: 6 }}>{t('system')}</span>}</span>
                                            <button className="btn-s" onClick={() => { setEditCat(c); editForm.setData('name', c.name); }}>{t('Edit')}</button>
                                            {!c.is_system && <button className="btn-s" onClick={async () => { if (await confirmDialog(t('Delete this category?'))) router.delete(`/finance/categories/${c.id}`); }}>{t('Delete')}</button>}
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                        {editCat && (
                            <form onSubmit={(e) => { e.preventDefault(); editForm.patch(`/finance/categories/${editCat.id}`, { onSuccess: () => setEditCat(null) }); }}
                                style={{ display: 'flex', gap: 8, marginTop: 14, alignItems: 'end' }}>
                                <div><label className="lbl">{t('New name — :name', { name: editCat.name })}</label>
                                    <input className="inp" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required /></div>
                                <button className="btn-p" type="submit">{t('Save')}</button>
                                <button className="btn-s" type="button" onClick={() => setEditCat(null)}>{t('Discard')}</button>
                            </form>
                        )}
                        <form onSubmit={(e) => { e.preventDefault(); catForm.post('/finance/categories', { onSuccess: () => catForm.reset('name') }); }}
                            style={{ display: 'flex', gap: 8, marginTop: 16, alignItems: 'end', flexWrap: 'wrap' }}>
                            <div><label className="lbl">{t('New category')}</label>
                                <input className="inp" value={catForm.data.name} onChange={(e) => catForm.setData('name', e.target.value)} placeholder={t('e.g. Delivery rent')} required /></div>
                            <div><label className="lbl">{t('Type')}</label>
                                <select className="inp" value={catForm.data.kind} onChange={(e) => catForm.setData('kind', e.target.value)}>
                                    <option value="expense">{t('Expense')}</option><option value="income">{t('Income')}</option>
                                </select></div>
                            <button className="btn-p" type="submit" disabled={catForm.processing}>{t('Add')}</button>
                        </form>
                    </div>
                )}

                <EntrySheet open={sheet} kind={kind} setKind={setKind} accounts={accounts} categories={categories} onClose={() => setSheet(false)} />
                <FinActions accounts={accounts} categories={categories} />
            </div>
        </AppLayout>
    );
}

function TxnTable({ list, onVoid }) {
    if (!list.length) return <div className="x-note">{t('No entries.')}</div>;
    const total = list.filter((x) => !x.voided).reduce((s, x) => s + (x.direction === 'in' ? x.amount : -x.amount), 0);
    return (
        <div style={{ overflowX: 'auto' }}>
            <table className="x-table">
                <thead><tr><th>{t('Date')}</th><th>{t('Description')}</th><th>{t('Category')}</th><th>{t('Account')}</th><th>{t('Amount')}</th><th></th></tr></thead>
                <tbody>
                    {list.map((x) => (
                        <tr key={x.id} style={x.voided ? { opacity: 0.55 } : undefined}>
                            <td>{x.date}</td><td>{x.note}{x.voided && <span className="chip chip-red" style={{ marginLeft: 6 }}>{t('Voided')}</span>}</td>
                            <td>{x.category || '—'}</td><td>{x.account}</td>
                            <td><b style={{ color: x.direction === 'in' ? '#16A34A' : '#DC2626' }}>{fmtSigned(x.amount, x.direction)}</b></td>
                            <td>{!x.voided && !x.is_reversal && <button className="btn-s" onClick={() => onVoid(x)}>{t('Void')}</button>}</td>
                        </tr>
                    ))}
                    <tr><td colSpan={4} style={{ textAlign: 'right', color: '#64748B' }}>{t('Total of this list')}</td>
                        <td><b>{fmt(total)}</b></td><td></td></tr>
                </tbody>
            </table>
        </div>
    );
}
