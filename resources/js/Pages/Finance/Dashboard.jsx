import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import EntrySheet, { FinActions, fmt, fmtMoney, fmtSigned } from './EntrySheet';
import { t } from '@/lib/i18n';

function SetupWizard() {
    const form = useForm({ name: 'Cash', type: 'cash', opening_balance: '' });
    const add = (name, type) => form.setData({ name, type, opening_balance: '' });
    return (
        <div className="x-card">
            <h4>{t('First setup - 3 steps')}</h4>
            <p style={{ fontSize: 13.5, color: '#64748B', marginBottom: 12 }}>
                {t('1. Add the accounts where the money is kept (Cash, bKash, Bank - whatever you have) · 2. Write the opening balance of each one · 3. Categories are already arranged on their own (Ad, Packaging, Rent, Salary…).')}
            </p>
            <div className="cat-chips" style={{ marginBottom: 12 }}>
                <button type="button" onClick={() => add('Cash', 'cash')}>{t('Cash')}</button>
                <button type="button" onClick={() => add('bKash', 'mobile')}>{t('bKash')}</button>
                <button type="button" onClick={() => add('Nagad', 'mobile')}>{t('Nagad')}</button>
                <button type="button" onClick={() => add('Bank', 'bank')}>{t('Bank')}</button>
            </div>
            <form onSubmit={(e) => { e.preventDefault(); form.post('/finance/accounts', { onSuccess: () => form.reset('opening_balance') }); }}
                style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'end' }}>
                <div><label className="lbl">{t('Account name')}</label>
                    <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                <div><label className="lbl">{t('Opening balance (৳)')}</label>
                    <input className="inp" type="number" min="0" step="any" value={form.data.opening_balance} onChange={(e) => form.setData('opening_balance', e.target.value)} placeholder="0" /></div>
                <button className="btn-p" type="submit" disabled={form.processing}>{t('Add account')}</button>
            </form>
        </div>
    );
}

export default function Dashboard(props) {
    const { setupDone, canSee, period, accounts, categories, figures, bridge, dues, totalCash,
        inventoryValue, supplierDue, stalePayouts, cashAccount, closingToday, dailyClosingOn, recent } = props;
    const [more, setMore] = useState(false);
    const [counted, setCounted] = useState('');
    const setPeriod = (p) => router.get('/finance', { period: p }, { preserveState: true, replace: true });

    return (
        <AppLayout title={t('Finance Dashboard')}>
            <Head title={t('Finance Dashboard')} />
            <div className="fin">
                <div className="x-head">
                    <h3>{t('Finance Dashboard')}</h3>
                    <p>{t("The full picture of the shop's money on one screen. The detailed account of courier money is in Delivery → Courier Ledger - it lives in one place only.")}</p>
                </div>

                {!setupDone && <SetupWizard />}

                {setupDone && !canSee && (
                    <>
                        <div className="x-card"><h4>{t('Add a new entry')}</h4>
                            <p style={{ fontSize: 13.5, color: '#64748B' }}>{t('Your only job is to write down expenses and income - the owner will see the balance and profit accounts.')}</p></div>
                        <h4 style={{ fontSize: 16, margin: '16px 0 10px' }}>{t('Recent transactions')}</h4>
                        <RecentTable recent={recent} />
                        <FinActions accounts={accounts} categories={categories} />
                    </>
                )}

                {setupDone && canSee && figures && (
                    <>
                        <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>
                            {[['today', 'Today'], ['week', 'This week'], ['month', 'This month'], ['last_month', 'Last month']].map(([k, l]) => (
                                <button key={k} className={period === k ? 'btn-p' : 'btn-s'} onClick={() => setPeriod(k)}>{t(l)}</button>
                            ))}
                        </div>
                        <div className="x-kpis">
                            <div className={`x-kpi ${figures.profit >= 0 ? 'green' : 'red'}`}><b>{fmtMoney(figures.profit)}</b><span>{t('Profit (this period)')}</span></div>
                            <div className="x-kpi purple"><b>{fmt(totalCash)}</b><span>{t('Cash in hand (all accounts)')}</span></div>
                            <div className="x-kpi"><b>{fmt(bridge.still)}</b><span>{t('Due from courier')}{bridge.estimate ? t(' (estimate)') : ''}</span></div>
                            <div className="x-kpi red"><b>{fmt(figures.expense + figures.rtoLoss)}</b><span>{t('Expenses (this period)')}</span></div>
                        </div>

                        <div className="x-card">
                            <h4>{t('COD money bridge - which step the money is at now')}</h4>
                            <div className="bridge">
                                <div className="bstep"><b>{fmt(bridge.sales)}</b><span>{t('Delivered sales')}</span></div>
                                <div className="barrow">→</div>
                                <div className="bstep"><b>− {fmt(bridge.charges)}</b><span>{t('Courier charge deducted')}</span></div>
                                <div className="barrow">→</div>
                                <div className="bstep"><b>{fmt(bridge.received)}</b><span>{t('Sent by courier')}</span></div>
                                <div className="barrow">→</div>
                                <div className="bstep"><b>{fmt(bridge.still)}</b><span>{t('Still with the courier')}{bridge.estimate ? t(' (estimate)') : ''}</span></div>
                            </div>
                            <div className="x-note">{t('If sales are high but cash in hand looks low, that is not a mistake - the rest is with the courier. Details:')} <Link href="/delivery/ledger" style={{ color: 'var(--acc-ink)', fontWeight: 600 }}>{t('Courier Ledger')}</Link>
                                {bridge.estimate && <>{t(" · Pathao's API has no payment account, so its part is estimated by subtracting hand-written payouts from Delivered.")}</>}</div>
                        </div>

                        {stalePayouts.count > 0 && (
                            <div className="x-card">
                                <h4>{t('Weekly courier payout matching')}</h4>
                                <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', alignItems: 'center' }}>
                                    <span style={{ fontSize: 14 }}>{t('More than 7 days old :count Delivered parcels worth :amount have not arrived yet.', { count: stalePayouts.count, amount: fmt(stalePayouts.amount) })}</span>
                                    <button className="btn-s" style={{ marginLeft: 'auto' }}
                                        onClick={() => router.post('/finance/payout-task')}>{t('Raise a task in Work Inbox')}</button>
                                </div>
                            </div>
                        )}

                        {dailyClosingOn && cashAccount && (
                            <div className="x-card">
                                <h4>{t("Today's account matching - :name", { name: cashAccount.name })}</h4>
                                <div style={{ display: 'flex', gap: 26, flexWrap: 'wrap', alignItems: 'center' }}>
                                    <div><div style={{ fontSize: 13, color: '#64748B' }}>{t('Should be in Cash')}</div><b style={{ fontSize: 20 }}>{fmt(cashAccount.balance)}</b></div>
                                    <div><div style={{ fontSize: 13, color: '#64748B' }}>{t('Counted in hand')}</div>
                                        <input className="inp" type="number" min="0" step="any" style={{ width: 140 }} value={counted}
                                            onChange={(e) => setCounted(e.target.value)} placeholder="0" /></div>
                                    {closingToday
                                        ? <span className={`chip ${Math.abs(closingToday.difference) < 0.01 ? 'chip-green' : 'chip-red'}`}>{Math.abs(closingToday.difference) < 0.01 ? t('Matched') : t('Not matched')}</span>
                                        : <button className="btn-s" style={{ marginLeft: 'auto' }} disabled={!counted}
                                            onClick={() => router.post('/finance/daily-close', { account_id: cashAccount.id, counted })}>{t('Match the accounts')}</button>}
                                </div>
                                <div className="x-note">{t('If it does not match, a task is raised in Work Inbox on its own (rule 32) - so it catches the eye the next morning.')}</div>
                            </div>
                        )}

                        <button className="btn-s" onClick={() => setMore(!more)} style={{ marginBottom: 12 }}>{more ? t('See less') : t('See more')} {more ? '⌃' : '⌄'}</button>
                        {more && (
                            <div className="x-kpis">
                                <div className="x-kpi"><b>{fmt(figures.revenue)}</b><span>{t('Delivered sales')}</span></div>
                                <div className="x-kpi"><b>{fmt(figures.otherIncome)}</b><span>{t('Other income')}</span></div>
                                <div className="x-kpi red"><b>{fmt(figures.rtoLoss)}</b><span>{t('Return/RTO loss')}</span></div>
                                <div className="x-kpi red"><b>{fmt(dues.total)}</b><span>{t('Customer dues (:count orders) -', { count: dues.count })} <Link href="/orders" style={{ color: 'var(--acc-ink)' }}>{t('List')}</Link></span></div>
                                <div className="x-kpi red"><b>{fmt(supplierDue || 0)}</b><span>{t('Supplier due -')} <Link href="/suppliers" style={{ color: 'var(--acc-ink)' }}>{t('Suppliers')}</Link></span></div>
                                <div className="x-kpi"><b>{fmt(figures.productCost)}</b><span>{t('Product cost')}</span></div>
                                <div className="x-kpi"><b>{fmt(figures.courierCharges)}</b><span>{t('Courier charge (Delivered)')}</span></div>
                                <div className="x-kpi"><b>{fmt(inventoryValue)}</b><span>{t('Inventory value (cost)')}</span></div>
                                <div className="x-kpi"><b>{fmt(figures.revenue + figures.otherIncome - figures.expense)}</b><span>{t('Net (income − expense)')}</span></div>
                            </div>
                        )}

                        <h4 style={{ fontSize: 16, margin: '16px 0 10px' }}>{t('Recent transactions')}</h4>
                        <RecentTable recent={recent} />
                        <FinActions accounts={accounts} categories={categories} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function RecentTable({ recent = [] }) {
    if (!recent.length) return <div className="x-note">{t('No transactions yet.')}</div>;
    return (
        <div style={{ overflowX: 'auto' }}>
            <table className="x-table">
                <thead><tr><th>{t('Date')}</th><th>{t('Description')}</th><th>{t('Account')}</th><th>{t('Income / Expense')}</th></tr></thead>
                <tbody>
                    {recent.map((x) => (
                        <tr key={x.id} style={x.voided ? { opacity: 0.55 } : undefined}>
                            <td>{x.date}</td>
                            <td>{x.note}{x.voided && <span className="chip chip-red" style={{ marginLeft: 6 }}>{t('Voided')}</span>}</td>
                            <td>{x.account}</td>
                            <td><b style={{ color: x.direction === 'in' ? '#16A34A' : '#DC2626' }}>{fmtSigned(x.amount, x.direction)}</b></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
