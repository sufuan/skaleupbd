import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { FinActions, fmt, fmtSigned } from './EntrySheet';

const SRC_LABEL = { manual: 'হাতে লেখা', order: 'Order', courier: 'Courier', system: 'System' };

export default function CashFlow(props) {
    const { tab, days, list, filters, accounts, categories, closedThrough } = props;
    const go = (params) => router.get('/finance/cash-flow', { ...filters, ...params }, { preserveState: true, replace: true });
    const voidTxn = (t) => { if (confirm(`এই entry বাতিল করবে? (${t.note})`)) router.post(`/finance/transactions/${t.id}/void`); };
    const net7 = days.reduce((s, d) => s + d.net, 0);

    return (
        <AppLayout title="Cash Flow">
            <Head title="Cash Flow" />
            <div className="fin">
                <div className="x-head">
                    <h3>Cash Flow</h3>
                    <p>সব লেনদেন একটাই খাতায় — এটাই system-এর একমাত্র ledger। গত 7 দিনে টাকা কেমন এসেছে-গেছে, নিচে পুরো তালিকা।</p>
                </div>

                <div style={{ overflowX: 'auto', marginBottom: 14 }}>
                    <table className="x-table">
                        <thead><tr><th>দিন</th><th>এসেছে</th><th>গেছে</th><th>হাতে রইল (net)</th></tr></thead>
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
                <div className="x-note" style={{ marginBottom: 14 }}>7 দিনে মোট net: <b style={{ color: net7 >= 0 ? '#16A34A' : '#DC2626' }}>{net7 >= 0 ? '+ ' : '− '}{fmt(Math.abs(net7))}</b> — বড় খরচের দিনগুলোতে (ভাড়া, supplier payment) net কমে যায়, এটা স্বাভাবিক।</div>

                <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
                    {[['ledger', 'Ledger'], ['activity', 'Activity']].map(([k, l]) => (
                        <button key={k} className={tab === k ? 'btn-p' : 'btn-s'} onClick={() => go({ tab: k })}>{l}</button>
                    ))}
                    <span style={{ marginLeft: 'auto', display: 'flex', gap: 8, alignItems: 'center' }}>
                        {closedThrough && <span className="chip chip-grey">{closedThrough} পর্যন্ত মাস বন্ধ</span>}
                        <button className="btn-s" onClick={() => { if (confirm('আগের মাস বন্ধ করবে? ওই মাসের entry আর বদলানো/বাতিল হবে না।')) router.post('/finance/close-month'); }}>আগের মাস বন্ধ করো</button>
                    </span>
                </div>

                {tab === 'ledger' && (
                    <>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
                            <select className="inp" style={{ width: 170 }} value={filters.account || ''} onChange={(e) => go({ tab: 'ledger', account: e.target.value || undefined })}>
                                <option value="">সব account</option>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </select>
                            <select className="inp" style={{ width: 170 }} value={filters.category || ''} onChange={(e) => go({ tab: 'ledger', category: e.target.value || undefined })}>
                                <option value="">সব category</option>
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                            <select className="inp" style={{ width: 140 }} value={filters.direction || ''} onChange={(e) => go({ tab: 'ledger', direction: e.target.value || undefined })}>
                                <option value="">আসা + যাওয়া</option><option value="in">শুধু আসা</option><option value="out">শুধু যাওয়া</option>
                            </select>
                            <select className="inp" style={{ width: 150 }} value={filters.source || ''} onChange={(e) => go({ tab: 'ledger', source: e.target.value || undefined })}>
                                <option value="">সব উৎস</option><option value="manual">হাতে লেখা</option><option value="order">Order</option><option value="courier">Courier</option><option value="system">System</option>
                            </select>
                            {filters.account && <span className="chip chip-amber">একটা account বাছা — তাই চলমান balance দেখাচ্ছে</span>}
                        </div>
                        <div style={{ overflowX: 'auto' }}>
                            <table className="x-table">
                                <thead><tr><th>তারিখ</th><th>বিবরণ</th><th>Account</th><th>Category</th><th>উৎস</th><th>টাকা</th>{filters.account && <th>Balance</th>}<th></th></tr></thead>
                                <tbody>
                                    {list.map((t) => (
                                        <tr key={t.id} style={t.voided ? { opacity: 0.55 } : undefined}>
                                            <td>{t.date}</td>
                                            <td>{t.note}{t.voided && <span className="chip chip-red" style={{ marginLeft: 6 }}>বাতিল</span>}{t.is_reversal && <span className="chip chip-grey" style={{ marginLeft: 6 }}>উল্টো entry</span>}</td>
                                            <td>{t.account}</td><td>{t.category || '—'}</td><td>{SRC_LABEL[t.source] || t.source}</td>
                                            <td><b style={{ color: t.direction === 'in' ? '#16A34A' : '#DC2626' }}>{fmtSigned(t.amount, t.direction)}</b></td>
                                            {filters.account && <td>{t.running !== undefined && t.running !== null ? fmt(t.running) : '—'}</td>}
                                            <td>{!t.voided && !t.is_reversal && <button className="btn-s" onClick={() => voidTxn(t)}>বাতিল করো</button>}</td>
                                        </tr>
                                    ))}
                                    {!list.length && <tr><td colSpan={8}>এই ছাঁকনিতে কোনো লেনদেন নেই।</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                {tab === 'activity' && (
                    <div style={{ overflowX: 'auto' }}>
                        <table className="x-table">
                            <thead><tr><th>তারিখ</th><th>কী হয়েছে</th><th>বিবরণ</th><th>কে করেছে</th><th>টাকা</th></tr></thead>
                            <tbody>
                                {list.map((t) => (
                                    <tr key={t.id}>
                                        <td>{t.date}</td>
                                        <td>{t.voided ? <span className="chip chip-red">বাতিল হয়েছে</span> : t.is_reversal ? <span className="chip chip-grey">বাতিলের উল্টো entry</span> : <span className="chip chip-green">নতুন entry</span>}</td>
                                        <td>{t.note}</td><td>{t.by || '—'}</td>
                                        <td><b style={{ color: t.direction === 'in' ? '#16A34A' : '#DC2626' }}>{fmtSigned(t.amount, t.direction)}</b></td>
                                    </tr>
                                ))}
                                {!list.length && <tr><td colSpan={5}>কোনো activity নেই।</td></tr>}
                            </tbody>
                        </table>
                    </div>
                )}

                <FinActions accounts={accounts} categories={categories} />
            </div>
        </AppLayout>
    );
}
