import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import EntrySheet, { FinActions, fmt, fmtSigned } from './EntrySheet';

export default function IncomeExpense(props) {
    const { tab, categoryFilter, byCategory, list, totalIncome, totalExpense, accounts, categories, canSee } = props;
    const [sheet, setSheet] = useState(false);
    const [kind, setKind] = useState('expense');
    const catForm = useForm({ name: '', kind: 'expense' });
    const [editCat, setEditCat] = useState(null);
    const editForm = useForm({ name: '' });

    const go = (params) => router.get('/finance/income-expense', params, { preserveState: true, replace: true });
    const activeFilter = categoryFilter ? categories.find((c) => c.id === categoryFilter) : null;

    const voidTxn = (t) => { if (confirm(`এই entry বাতিল করবে? (${t.note})`)) router.post(`/finance/transactions/${t.id}/void`); };

    return (
        <AppLayout title="Income & Expense">
            <Head title="Income & Expense" />
            <div className="fin">
                <div className="x-head">
                    <h3>Income & Expense</h3>
                    <p>সব আয় আর খরচের খাতা। Courier payout আর order advance নিজে থেকে এখানে আসে — হাতে আবার লিখলে দুইবার হবে।</p>
                </div>

                <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>
                    <button className="btn-p" onClick={() => { setKind('income'); setSheet(true); }}>+ আয় যোগ করো</button>
                    <button className="btn-s" onClick={() => { setKind('expense'); setSheet(true); }}>+ খরচ যোগ করো</button>
                    <a className="btn-s" href="/finance/income-expense/export" style={{ marginLeft: 'auto' }}>CSV Download</a>
                </div>

                <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
                    {[['overview', 'Overview'], ['expense', 'Expense'], ['income', 'Income'], ['settings', 'Settings']].map(([k, l]) => (
                        <button key={k} className={tab === k ? 'btn-p' : 'btn-s'} onClick={() => go({ tab: k })}>{l}</button>
                    ))}
                </div>

                {tab === 'overview' && (
                    <>
                        <div className="x-kpis">
                            <div className="x-kpi green"><b>{fmt(totalIncome)}</b><span>এই মাসের আয়</span></div>
                            <div className="x-kpi red"><b>{fmt(totalExpense)}</b><span>এই মাসের খরচ</span></div>
                            <div className="x-kpi"><b>{fmt(totalIncome - totalExpense)}</b><span>নিট</span></div>
                        </div>
                        <div style={{ overflowX: 'auto' }}>
                            <table className="x-table">
                                <thead><tr><th>Category</th><th>আয়</th><th>খরচ</th></tr></thead>
                                <tbody>
                                    {byCategory.map((r) => (
                                        <tr key={r.name}><td>{r.name}</td>
                                            <td style={{ color: '#16A34A' }}>{r.income ? '+ ' + fmt(r.income) : '—'}</td>
                                            <td style={{ color: '#DC2626' }}>{r.expense ? '− ' + fmt(r.expense) : '—'}</td></tr>
                                    ))}
                                    {!byCategory.length && <tr><td colSpan={3}>এই মাসে কোনো entry নেই।</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                {(tab === 'expense' || tab === 'income') && (
                    <>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12, flexWrap: 'wrap' }}>
                            <label style={{ fontSize: 13.5, color: '#64748B' }}>Category দিয়ে ছাঁকো:</label>
                            <select className="inp" style={{ width: 200 }} value={categoryFilter || ''}
                                onChange={(e) => go({ tab, category: e.target.value || undefined })}>
                                <option value="">সব category</option>
                                {categories.filter((c) => c.kind === tab).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                            {activeFilter && <span className="chip chip-amber">ফিল্টার: {activeFilter.name} — নিচের মোট এই ফিল্টারের হিসাব</span>}
                        </div>
                        <TxnTable list={list} onVoid={voidTxn} />
                    </>
                )}

                {tab === 'settings' && (
                    <div className="x-card">
                        <h4>Category সাজানো</h4>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 18 }} className="cust-2col">
                            {['expense', 'income'].map((kind) => (
                                <div key={kind}>
                                    <b style={{ fontSize: 14 }}>{kind === 'expense' ? 'খরচের category' : 'আয়ের category'}</b>
                                    {categories.filter((c) => c.kind === kind).map((c) => (
                                        <div key={c.id} style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '7px 0', borderBottom: '1px solid #F1F5F9' }}>
                                            <span style={{ flex: 1 }}>{c.name}{c.is_system && <span className="chip chip-grey" style={{ marginLeft: 6 }}>system</span>}</span>
                                            <button className="btn-s" onClick={() => { setEditCat(c); editForm.setData('name', c.name); }}>বদলাও</button>
                                            {!c.is_system && <button className="btn-s" onClick={() => { if (confirm('Category মুছবে?')) router.delete(`/finance/categories/${c.id}`); }}>মোছো</button>}
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                        {editCat && (
                            <form onSubmit={(e) => { e.preventDefault(); editForm.patch(`/finance/categories/${editCat.id}`, { onSuccess: () => setEditCat(null) }); }}
                                style={{ display: 'flex', gap: 8, marginTop: 14, alignItems: 'end' }}>
                                <div><label className="lbl">নতুন নাম — {editCat.name}</label>
                                    <input className="inp" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required /></div>
                                <button className="btn-p" type="submit">সেভ করো</button>
                                <button className="btn-s" type="button" onClick={() => setEditCat(null)}>বাদ দাও</button>
                            </form>
                        )}
                        <form onSubmit={(e) => { e.preventDefault(); catForm.post('/finance/categories', { onSuccess: () => catForm.reset('name') }); }}
                            style={{ display: 'flex', gap: 8, marginTop: 16, alignItems: 'end', flexWrap: 'wrap' }}>
                            <div><label className="lbl">নতুন category</label>
                                <input className="inp" value={catForm.data.name} onChange={(e) => catForm.setData('name', e.target.value)} placeholder="যেমন: Delivery ভাড়া" required /></div>
                            <div><label className="lbl">ধরন</label>
                                <select className="inp" value={catForm.data.kind} onChange={(e) => catForm.setData('kind', e.target.value)}>
                                    <option value="expense">খরচ</option><option value="income">আয়</option>
                                </select></div>
                            <button className="btn-p" type="submit" disabled={catForm.processing}>যোগ করো</button>
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
    if (!list.length) return <div className="x-note">কোনো entry নেই।</div>;
    const total = list.filter((t) => !t.voided).reduce((s, t) => s + (t.direction === 'in' ? t.amount : -t.amount), 0);
    return (
        <div style={{ overflowX: 'auto' }}>
            <table className="x-table">
                <thead><tr><th>তারিখ</th><th>বিবরণ</th><th>Category</th><th>Account</th><th>টাকা</th><th></th></tr></thead>
                <tbody>
                    {list.map((t) => (
                        <tr key={t.id} style={t.voided ? { opacity: 0.55 } : undefined}>
                            <td>{t.date}</td><td>{t.note}{t.voided && <span className="chip chip-red" style={{ marginLeft: 6 }}>বাতিল</span>}</td>
                            <td>{t.category || '—'}</td><td>{t.account}</td>
                            <td><b style={{ color: t.direction === 'in' ? '#16A34A' : '#DC2626' }}>{fmtSigned(t.amount, t.direction)}</b></td>
                            <td>{!t.voided && !t.is_reversal && <button className="btn-s" onClick={() => onVoid(t)}>বাতিল করো</button>}</td>
                        </tr>
                    ))}
                    <tr><td colSpan={4} style={{ textAlign: 'right', color: '#64748B' }}>এই তালিকার মোট</td>
                        <td><b>{fmt(total)}</b></td><td></td></tr>
                </tbody>
            </table>
        </div>
    );
}
