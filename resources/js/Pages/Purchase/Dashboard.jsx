import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { GuideBtn, PageHead, fmt, fmtDate } from './shared';

// Purchase Dashboard — exact copy of EcomDrive's Purchase Dashboard
// content: Right now cards, This period, Top suppliers, Most bought
// products, 6-month chart, Recent purchases, Overdue supplier payments.
const PERIODS = [['today', 'Today'], ['yesterday', 'Yest.'], ['30d', '30D'], ['month', 'Month']];

export default function Dashboard({ rightNow, periodKey, supplierFilter, suppliers, period, topSuppliers, mostBought, months, recent, overdueRows }) {
    const go = (patch) => router.get('/purchase', { period: periodKey, supplier: supplierFilter || undefined, ...patch }, { preserveState: true, replace: true });
    const maxMonth = Math.max(1, ...months.map((m) => m.amount));

    return (
        <AppLayout>
            <Head title={t('Purchase Dashboard')} />
            <div className="pur">
                <PageHead title={t('Purchase Dashboard')}>
                    <GuideBtn />
                    <Link href="/purchases/create" className="btn pri">{t('New Purchase')}</Link>
                </PageHead>

                <div className="sec-t">{t('Right now')}</div>
                <div className="stat-grid">
                    <Link href="/suppliers" className="stat-card2" style={{ textDecoration: 'none', color: 'inherit' }}>
                        <div className="lb">{t('Owed to suppliers')}</div>
                        <div className="vl">{fmt(rightNow.owed)}</div>
                    </Link>
                    <Link href="/suppliers" className="stat-card2" style={{ textDecoration: 'none', color: 'inherit' }}>
                        <div className="lb">{t('Overdue')}</div>
                        <div className="vl red">{fmt(rightNow.overdue)}</div>
                        <div className="sb">{rightNow.overdueSuppliers} {t('suppliers')}</div>
                    </Link>
                    <Link href="/purchases?tab=receive" className="stat-card2" style={{ textDecoration: 'none', color: 'inherit' }}>
                        <div className="lb">{t('Goods not received')}</div>
                        <div className="vl">{rightNow.goodsNotReceived}</div>
                        <div className="sb">{fmt(rightNow.goodsNotReceivedValue)} {t('of purchases')}</div>
                    </Link>
                    <Link href="/suppliers?tab=reorder" className="stat-card2" style={{ textDecoration: 'none', color: 'inherit' }}>
                        <div className="lb">{t('Low stock on items you buy')}</div>
                        <div className="vl">{rightNow.lowStock}</div>
                    </Link>
                </div>

                <div className="sec-t">{t('This period')}</div>
                <div className="flex" style={{ flexWrap: 'wrap', marginBottom: 12 }}>
                    <span className="seg">
                        {PERIODS.map(([k, l]) => (
                            <button key={k} type="button" className={periodKey === k ? 'on' : ''} onClick={() => go({ period: k })}>{t(l)}</button>
                        ))}
                    </span>
                    <span className="spacer" />
                    <select className="inp" style={{ width: 220 }} value={supplierFilter || ''} onChange={(e) => go({ supplier: e.target.value || undefined })}>
                        <option value="">{t('All suppliers')}</option>
                        {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                </div>
                <div className="stat-grid c3">
                    <div className="stat-card2">
                        <div className="lb">{t('Purchased')}</div>
                        <div className="vl">{fmt(period.purchased)}</div>
                        <div className="sb">{period.purchasesCount} {t('purchases')}</div>
                    </div>
                    <div className="stat-card2">
                        <div className="lb">{t('Paid')}</div>
                        <div className="vl">{fmt(period.paid)}</div>
                    </div>
                    <div className="stat-card2">
                        <div className="lb">{t('Received value')}</div>
                        <div className="vl">{fmt(period.receivedValue)}</div>
                    </div>
                </div>

                <div className="grid2" style={{ marginTop: 16 }}>
                    <div className="card">
                        <div className="b" style={{ fontSize: 15, marginBottom: 10 }}>{t('Top suppliers')}</div>
                        {topSuppliers.length === 0 && <div className="sm mut">{t('No supplier purchases in this period.')}</div>}
                        {topSuppliers.map((s, i) => (
                            <div key={s.id} className="check-row">
                                <span className="b">{i + 1}. {s.name}</span>
                                <span className="spacer" />
                                <span className="b">{fmt(s.amount)}</span>
                            </div>
                        ))}
                    </div>
                    <div className="card">
                        <div className="b" style={{ fontSize: 15, marginBottom: 10 }}>{t('Most bought products')}</div>
                        {mostBought.length === 0 && <div className="sm mut">{t('No purchases in this period.')}</div>}
                        {mostBought.map((p) => (
                            <div key={p.sku + p.name} className="check-row">
                                <span style={{ flex: 1, minWidth: 0 }}>
                                    <span className="b" style={{ display: 'block', fontSize: 13.5 }}>{p.name}</span>
                                    <span className="sm mut">{t('SKU')} {p.sku} · {p.qty} {t('pcs')}</span>
                                </span>
                                <span className="b">{fmt(p.amount)}</span>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="card" style={{ marginTop: 16 }}>
                    <div className="b" style={{ fontSize: 15 }}>{t('Purchases, last 6 months')}</div>
                    <div className="bars">
                        {months.map((m) => (
                            <div key={m.label} className="bar-col">
                                <span className="sm b">{fmt(m.amount)}</span>
                                <div className="bar" style={{ height: `${Math.max(2, (m.amount / maxMonth) * 100)}%` }} />
                                <span className="bar-lb">{m.label}</span>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="sec-t">{t('Recent purchases')}</div>
                <div className="card">
                    <div className="flex" style={{ marginBottom: 6 }}>
                        <span className="spacer" />
                        <Link href="/purchases" className="mini-link">{t('View all')}</Link>
                    </div>
                    {recent.length === 0 && <div className="sm mut">{t('No purchases yet.')}</div>}
                    {recent.map((r) => (
                        <div key={r.id} className="check-row">
                            <span style={{ flex: 1 }}>
                                <span className="b" style={{ display: 'block', fontSize: 13.5 }}>{r.title}</span>
                                <span className="sm mut">{fmtDate(r.date)}</span>
                            </span>
                            <span className="b">{fmt(r.amount)}</span>
                            <span className={`pill ${r.status === 'Received' ? 'green' : 'amber'}`}>{t(r.status)}</span>
                        </div>
                    ))}
                </div>

                <div className="sec-t">{t('Overdue supplier payments')}</div>
                <div className="card">
                    <div className="tbl-wrap">
                        <table className="tbl">
                            <thead><tr><th>{t('Supplier')}</th><th>{t('Overdue')}</th><th>{t('Bills')}</th><th>{t('Oldest due date')}</th><th>{t('Action')}</th></tr></thead>
                            <tbody>
                                {overdueRows.map((r) => (
                                    <tr key={r.supplier_id}>
                                        <td className="b">{r.name}</td>
                                        <td className="b" style={{ color: 'var(--bad)' }}>{fmt(r.overdue)}</td>
                                        <td>{r.bills}</td>
                                        <td>{fmtDate(r.oldest_due_date)}</td>
                                        <td><Link href="/suppliers" className="mini-link">{t('Open Supplier Ledger')}</Link></td>
                                    </tr>
                                ))}
                                {overdueRows.length === 0 && <tr><td colSpan={5} className="mut">{t('No overdue supplier payments.')}</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
