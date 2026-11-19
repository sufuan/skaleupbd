import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import EntrySheet, { FinActions, fmt, fmtMoney, fmtSigned } from './EntrySheet';

function SetupWizard() {
    const form = useForm({ name: 'Cash', type: 'cash', opening_balance: '' });
    const add = (name, type) => form.setData({ name, type, opening_balance: '' });
    return (
        <div className="x-card">
            <h4>প্রথম সেটআপ — ৩ ধাপ</h4>
            <p style={{ fontSize: 13.5, color: '#64748B', marginBottom: 12 }}>
                ১. টাকা যেখানে থাকে সেই account যোগ করো (Cash, bKash, Bank — যা আছে) · ২. প্রতিটার শুরুর balance লেখো · ৩. Category নিজে থেকেই সাজানো আছে (Ad, Packaging, ভাড়া, বেতন…)।
            </p>
            <div className="cat-chips" style={{ marginBottom: 12 }}>
                <button type="button" onClick={() => add('Cash', 'cash')}>Cash</button>
                <button type="button" onClick={() => add('bKash', 'mobile')}>bKash</button>
                <button type="button" onClick={() => add('Nagad', 'mobile')}>Nagad</button>
                <button type="button" onClick={() => add('Bank', 'bank')}>Bank</button>
            </div>
            <form onSubmit={(e) => { e.preventDefault(); form.post('/finance/accounts', { onSuccess: () => form.reset('opening_balance') }); }}
                style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'end' }}>
                <div><label className="lbl">Account-এর নাম</label>
                    <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                <div><label className="lbl">শুরুর balance (৳)</label>
                    <input className="inp" type="number" min="0" step="any" value={form.data.opening_balance} onChange={(e) => form.setData('opening_balance', e.target.value)} placeholder="0" /></div>
                <button className="btn-p" type="submit" disabled={form.processing}>Account যোগ করো</button>
            </form>
        </div>
    );
}

export default function Dashboard(props) {
    const { setupDone, canSee, period, accounts, categories, figures, bridge, dues, totalCash,
        inventoryValue, stalePayouts, cashAccount, closingToday, dailyClosingOn, recent } = props;
    const [more, setMore] = useState(false);
    const [counted, setCounted] = useState('');
    const setPeriod = (p) => router.get('/finance', { period: p }, { preserveState: true, replace: true });

    return (
        <AppLayout title="Finance Dashboard">
            <Head title="Finance Dashboard" />
            <div className="fin">
                <div className="x-head">
                    <h3>Finance Dashboard</h3>
                    <p>দোকানের টাকার পুরো ছবি এক পর্দায়। Courier-এর টাকার বিস্তারিত হিসাব Delivery → Courier Ledger-এ, একটাই জায়গায় থাকে।</p>
                </div>

                {!setupDone && <SetupWizard />}

                {setupDone && !canSee && (
                    <>
                        <div className="x-card"><h4>নতুন entry দাও</h4>
                            <p style={{ fontSize: 13.5, color: '#64748B' }}>তোমার কাজ শুধু খরচ/আয় লেখা — balance আর লাভের হিসাব মালিক দেখবেন।</p></div>
                        <h4 style={{ fontSize: 16, margin: '16px 0 10px' }}>সাম্প্রতিক লেনদেন</h4>
                        <RecentTable recent={recent} />
                        <FinActions accounts={accounts} categories={categories} />
                    </>
                )}

                {setupDone && canSee && figures && (
                    <>
                        <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>
                            {[['today', 'আজ'], ['week', 'এই সপ্তাহ'], ['month', 'এই মাস'], ['last_month', 'গত মাস']].map(([k, l]) => (
                                <button key={k} className={period === k ? 'btn-p' : 'btn-s'} onClick={() => setPeriod(k)}>{l}</button>
                            ))}
                        </div>
                        <div className="x-kpis">
                            <div className={`x-kpi ${figures.profit >= 0 ? 'green' : 'red'}`}><b>{fmtMoney(figures.profit)}</b><span>লাভ (এই সময়ে)</span></div>
                            <div className="x-kpi purple"><b>{fmt(totalCash)}</b><span>হাতে টাকা (সব account)</span></div>
                            <div className="x-kpi"><b>{fmt(bridge.still)}</b><span>Courier-এর কাছে পাওনা{bridge.estimate ? ' (অনুমান)' : ''}</span></div>
                            <div className="x-kpi red"><b>{fmt(figures.expense + figures.rtoLoss)}</b><span>খরচ (এই সময়ে)</span></div>
                        </div>

                        <div className="x-card">
                            <h4>COD টাকার সেতু — টাকা এখন কোন ধাপে</h4>
                            <div className="bridge">
                                <div className="bstep"><b>{fmt(bridge.sales)}</b><span>Delivered বিক্রি</span></div>
                                <div className="barrow">→</div>
                                <div className="bstep"><b>− {fmt(bridge.charges)}</b><span>Courier charge কাটা</span></div>
                                <div className="barrow">→</div>
                                <div className="bstep"><b>{fmt(bridge.received)}</b><span>Courier পাঠিয়েছে</span></div>
                                <div className="barrow">→</div>
                                <div className="bstep"><b>{fmt(bridge.still)}</b><span>Courier-এর কাছে বাকি{bridge.estimate ? ' (অনুমান)' : ''}</span></div>
                            </div>
                            <div className="x-note">বিক্রি বেশি অথচ হাতে টাকা কম দেখালে ভুল না — বাকিটা courier-এর কাছে আছে। বিস্তারিত: <Link href="/delivery/ledger" style={{ color: '#411550', fontWeight: 600 }}>Courier Ledger</Link>
                                {bridge.estimate && <> · Pathao-র API-তে payment-এর হিসাব নেই, তাই ওর অংশটা Delivered থেকে হাতে-লেখা payout বাদ দিয়ে অনুমান।</>}</div>
                        </div>

                        {stalePayouts.count > 0 && (
                            <div className="x-card">
                                <h4>সাপ্তাহিক courier payout মেলানো</h4>
                                <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', alignItems: 'center' }}>
                                    <span style={{ fontSize: 14 }}>৭ দিনের বেশি পুরনো <b>{stalePayouts.count}টা</b> Delivered parcel-এর <b>{fmt(stalePayouts.amount)}</b> এখনো আসেনি।</span>
                                    <button className="btn-s" style={{ marginLeft: 'auto' }}
                                        onClick={() => router.post('/finance/payout-task')}>Work Inbox-এ task তোলো</button>
                                </div>
                            </div>
                        )}

                        {dailyClosingOn && cashAccount && (
                            <div className="x-card">
                                <h4>আজকের হিসাব মেলানো — {cashAccount.name}</h4>
                                <div style={{ display: 'flex', gap: 26, flexWrap: 'wrap', alignItems: 'center' }}>
                                    <div><div style={{ fontSize: 13, color: '#64748B' }}>Cash-এ থাকা উচিত</div><b style={{ fontSize: 20 }}>{fmt(cashAccount.balance)}</b></div>
                                    <div><div style={{ fontSize: 13, color: '#64748B' }}>হাতে গুনে পাওয়া</div>
                                        <input className="inp" type="number" min="0" step="any" style={{ width: 140 }} value={counted}
                                            onChange={(e) => setCounted(e.target.value)} placeholder="0" /></div>
                                    {closingToday
                                        ? <span className={`chip ${Math.abs(closingToday.difference) < 0.01 ? 'chip-green' : 'chip-red'}`}>{Math.abs(closingToday.difference) < 0.01 ? 'মিলেছে' : 'মেলেনি'}</span>
                                        : <button className="btn-s" style={{ marginLeft: 'auto' }} disabled={!counted}
                                            onClick={() => router.post('/finance/daily-close', { account_id: cashAccount.id, counted })}>হিসাব মেলাও</button>}
                                </div>
                                <div className="x-note">না মিললে Work Inbox-এ নিজে থেকে task উঠবে (নিয়ম 32) — যাতে পরদিন সকালেই চোখে পড়ে।</div>
                            </div>
                        )}

                        <button className="btn-s" onClick={() => setMore(!more)} style={{ marginBottom: 12 }}>{more ? 'কম দেখুন' : 'আরও দেখুন'} {more ? '⌃' : '⌄'}</button>
                        {more && (
                            <div className="x-kpis">
                                <div className="x-kpi"><b>{fmt(figures.revenue)}</b><span>Delivered বিক্রি</span></div>
                                <div className="x-kpi"><b>{fmt(figures.otherIncome)}</b><span>অন্য আয়</span></div>
                                <div className="x-kpi red"><b>{fmt(figures.rtoLoss)}</b><span>Return/RTO ক্ষতি</span></div>
                                <div className="x-kpi red"><b>{fmt(dues.total)}</b><span>Customer বকেয়া ({dues.count}টা order) — <Link href="/orders" style={{ color: '#411550' }}>তালিকা</Link></span></div>
                                <div className="x-kpi"><b>{fmt(figures.productCost)}</b><span>পণ্যের খরচ</span></div>
                                <div className="x-kpi"><b>{fmt(figures.courierCharges)}</b><span>Courier charge (Delivered)</span></div>
                                <div className="x-kpi"><b>{fmt(inventoryValue)}</b><span>Inventory-র মূল্য (cost)</span></div>
                                <div className="x-kpi"><b>{fmt(figures.revenue + figures.otherIncome - figures.expense)}</b><span>নিট (আয় − খরচ)</span></div>
                            </div>
                        )}

                        <h4 style={{ fontSize: 16, margin: '16px 0 10px' }}>সাম্প্রতিক লেনদেন</h4>
                        <RecentTable recent={recent} />
                        <FinActions accounts={accounts} categories={categories} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function RecentTable({ recent = [] }) {
    if (!recent.length) return <div className="x-note">এখনো কোনো লেনদেন নেই।</div>;
    return (
        <div style={{ overflowX: 'auto' }}>
            <table className="x-table">
                <thead><tr><th>তারিখ</th><th>বিবরণ</th><th>Account</th><th>আয় / খরচ</th></tr></thead>
                <tbody>
                    {recent.map((t) => (
                        <tr key={t.id} style={t.voided ? { opacity: 0.55 } : undefined}>
                            <td>{t.date}</td>
                            <td>{t.note}{t.voided && <span className="chip chip-red" style={{ marginLeft: 6 }}>বাতিল</span>}</td>
                            <td>{t.account}</td>
                            <td><b style={{ color: t.direction === 'in' ? '#16A34A' : '#DC2626' }}>{fmtSigned(t.amount, t.direction)}</b></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
