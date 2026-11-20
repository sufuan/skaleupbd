import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { FinActions, fmt, fmtSigned } from './EntrySheet';
import { t } from '@/lib/i18n';

const SRC_LABEL = { manual: 'Written by hand', order: 'Order', courier: 'Courier', system: 'System' };

export default function CashFlow(props) {
    const { tab, days, list, filters, accounts, categories, closedThrough } = props;
    const go = (params) => router.get('/finance/cash-flow', { ...filters, ...params }, { preserveState: true, replace: true });
    const voidTxn = async (x) => { if (await confirmDialog(t('Void this entry?') + ` (${x.note})`)) router.post(`/finance/transactions/${x.id}/void`); };
    const net7 = days.reduce((s, d) => s + d.net, 0);

    return (
        <AppLayout title={t('Cash Flow')}>
            <Head title={t('Cash Flow')} />
            <div className="fin">
                <div className="x-head">
                    <h3>{t('Cash Flow')}</h3>
                    <p>{t("All transactions in one single book — this is the system's only ledger. How the money came and went in the last 7 days — the full list is below.")}</p>
                </div>

                <div style={{ overflowX: 'auto', marginBottom: 14 }}>
                    <table className="x-table">
                        <thead><tr><th>{t('Day')}</th><th>{t('Came in')}</th><th>{t('Went out')}</th><th>{t('Left in hand (net)')}</th></tr></thead>
                        <tbody>
                            {days.map((d) => (
                                <tr key={d.label}><td>{d.label}</td>
                                    <td style={{ color: '#16A34A' }}>{d.in ? '+ ' + fmt(d.in) : '—'}</td>
                                    <td style={{ color: '#DC2626' }}>{d.out ? '− ' + fmt(d.out) : '—'}</td>
                                    <td><b style={{ color: d.net >= 0 ? '#16A34A' : '#DC2626' }}>{d.net >= 0 ? '+ ' : '− '}{fmt(Math.abs(d.net))}</b></td></tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="x-note" style={{ marginBottom: 14 }}>{t('Total net in 7 days:')} <b style={{ color: net7 >= 0 ? '#16A34A' : '#DC2626' }}>{net7 >= 0 ? '+ ' : '− '}{fmt(Math.abs(net7))}</b> {t('— On big-expense days (rent, supplier payment) the net goes down — that is normal.')}</div>

                <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
                    {[['ledger', 'Ledger'], ['activity', 'Activity']].map(([k, l]) => (
                        <button key={k} className={tab === k ? 'btn-p' : 'btn-s'} onClick={() => go({ tab: k })}>{t(l)}</button>
                    ))}
                    <span style={{ marginLeft: 'auto', display: 'flex', gap: 8, alignItems: 'center' }}>
                        {closedThrough && <span className="chip chip-grey">{t('Month closed until :date', { date: closedThrough })}</span>}
                        <button className="btn-s" onClick={async () => { if (await confirmDialog(t('Close the previous month? Entries of that month can no longer be changed or voided.'))) router.post('/finance/close-month'); }}>{t('Close the previous month')}</button>
                    </span>
                </div>

                {tab === 'ledger' && (
                    <>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
                            <select className="inp" style={{ width: 170 }} value={filters.account || ''} onChange={(e) => go({ tab: 'ledger', account: e.target.value || undefined })}>
                                <option value="">{t('All accounts')}</option>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </select>
                            <select className="inp" style={{ width: 170 }} value={filters.category || ''} onChange={(e) => go({ tab: 'ledger', category: e.target.value || undefined })}>
                                <option value="">{t('All categories')}</option>
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                            <select className="inp" style={{ width: 140 }} value={filters.direction || ''} onChange={(e) => go({ tab: 'ledger', direction: e.target.value || undefined })}>
                                <option value="">{t('In + Out')}</option><option value="in">{t('In only')}</option><option value="out">{t('Out only')}</option>
                            </select>
                            <select className="inp" style={{ width: 150 }} value={filters.source || ''} onChange={(e) => go({ tab: 'ledger', source: e.target.value || undefined })}>
                                <option value="">{t('All sources')}</option><option value="manual">{t('Written by hand')}</option><option value="order">{t('Order')}</option><option value="courier">{t('Courier')}</option><option value="system">{t('System')}</option>
                            </select>
                            {filters.account && <span className="chip chip-amber">{t('One account chosen — so the running balance is shown')}</span>}
                        </div>
                        <div style={{ overflowX: 'auto' }}>
                            <table className="x-table">
                                <thead><tr><th>{t('Date')}</th><th>{t('Description')}</th><th>{t('Account')}</th><th>{t('Category')}</th><th>{t('Source')}</th><th>{t('Amount')}</th>{filters.account && <th>{t('Balance')}</th>}<th></th></tr></thead>
                                <tbody>
                                    {list.map((x) => (
                                        <tr key={x.id} style={x.voided ? { opacity: 0.55 } : undefined}>
                                            <td>{x.date}</td>
                                            <td>{x.note}{x.voided && <span className="chip chip-red" style={{ marginLeft: 6 }}>{t('Voided')}</span>}{x.is_reversal && <span className="chip chip-grey" style={{ marginLeft: 6 }}>{t('Reversal entry')}</span>}</td>
                                            <td>{x.account}</td><td>{x.category || '—'}</td><td>{t(SRC_LABEL[x.source] || x.source)}</td>
                                            <td><b style={{ color: x.direction === 'in' ? '#16A34A' : '#DC2626' }}>{fmtSigned(x.amount, x.direction)}</b></td>
                                            {filters.account && <td>{x.running !== undefined && x.running !== null ? fmt(x.running) : '—'}</td>}
                                            <td>{!x.voided && !x.is_reversal && <button className="btn-s" onClick={() => voidTxn(x)}>{t('Void')}</button>}</td>
                                        </tr>
                                    ))}
                                    {!list.length && <tr><td colSpan={8}>{t('No transactions in this filter.')}</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                {tab === 'activity' && (
                    <div style={{ overflowX: 'auto' }}>
                        <table className="x-table">
                            <thead><tr><th>{t('Date')}</th><th>{t('What happened')}</th><th>{t('Description')}</th><th>{t('Who did it')}</th><th>{t('Amount')}</th></tr></thead>
                            <tbody>
                                {list.map((x) => (
                                    <tr key={x.id}>
                                        <td>{x.date}</td>
                                        <td>{x.voided ? <span className="chip chip-red">{t('Entry voided')}</span> : x.is_reversal ? <span className="chip chip-grey">{t('Reversal of the void')}</span> : <span className="chip chip-green">{t('New entry')}</span>}</td>
                                        <td>{x.note}</td><td>{x.by || '—'}</td>
                                        <td><b style={{ color: x.direction === 'in' ? '#16A34A' : '#DC2626' }}>{fmtSigned(x.amount, x.direction)}</b></td>
                                    </tr>
                                ))}
                                {!list.length && <tr><td colSpan={5}>{t('No activity.')}</td></tr>}
                            </tbody>
                        </table>
                    </div>
                )}

                <FinActions accounts={accounts} categories={categories} />
            </div>
        </AppLayout>
    );
}
