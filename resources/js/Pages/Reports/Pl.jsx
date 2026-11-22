import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmt, fmtSigned } from './parts';
import { t } from '@/lib/i18n';

const WORD = { today: 'Today', '7d': 'in the last 7 days', month: 'this month' };

export default function Pl({ preset, kpis, rows, estimate_enabled, estimate }) {
    const toggleEstimate = () => router.post('/reports/pl/estimate', { enabled: !estimate_enabled }, { preserveScroll: true });
    return (
        <AppLayout>
            <Head title={t('Reports - Profit & Loss')} />
            <div className="rep">
                <RepHead title={t('Profit & Loss')} sub={t('How much profit was really made :word - after all expenses. Basis of the account: the dates of delivered orders (the same account as Finance).', { word: t(WORD[preset] || 'this month') })} preset={preset} base="/reports" exportKey="pl" />
                <div className="x-kpis">
                    <div className="x-kpi"><b>{fmt(kpis.sales)}</b><span>{t('Total sales')}</span></div>
                    <div className={`x-kpi ${kpis.profit >= 0 ? 'green' : 'red'}`}><b>{fmtSigned(kpis.profit)}</b><span>{t('Net profit')}</span></div>
                    <div className="x-kpi purple"><b>{kpis.margin}%</b><span>{t('Profit rate (margin)')}</span></div>
                    <div className="x-kpi"><b>{fmtSigned(kpis.per_order)}</b><span>{t('Average profit per order')}</span></div>
                </div>
                <table className="x-table">
                    <thead><tr><th>{t('Sector')}</th><th>{t('Amount')}</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.label}>
                                <td>{r.total ? <b>{r.label}</b> : r.label}</td>
                                <td>{r.total ? <b style={{ color: r.amount >= 0 ? '#16A34A' : '#DC2626' }}>{fmtSigned(r.amount)}</b> : fmtSigned(r.amount)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 14 }}>
                    <button type="button" className={`switch${estimate_enabled ? ' on' : ''}`} onClick={toggleEstimate} aria-label={t('Estimated account')} style={{ width: 46, height: 26, borderRadius: 99, background: estimate_enabled ? '#7C3AED' : '#E2E8F0', border: 'none', cursor: 'pointer', position: 'relative', flexShrink: 0 }}>
                        <span style={{ position: 'absolute', top: 3, left: estimate_enabled ? 23 : 3, width: 20, height: 20, borderRadius: '50%', background: '#fff', transition: 'left .15s' }}></span>
                    </button>
                    <span style={{ fontSize: 13.5, color: '#475569' }}>{t('Estimated account - also show the possible profit of orders on the way')}</span>
                </div>
                {estimate_enabled && estimate ? (
                    <div className="x-note">{t('Account rule: the orders still on the way (:orders), assuming :rate% of them will be delivered, may bring about :amount more profit - this estimate is not added to the net profit above; the real account of delivered orders is given.', { orders: estimate.orders, rate: estimate.rate, amount: fmt(estimate.amount) })}</div>
                ) : (
                    <div className="x-note">{t('Account rule: only the real profit of delivered orders is counted - no estimate of orders on the way is added.')}</div>
                )}
                <h4 style={{ fontSize: 16, margin: '18px 0 10px' }}>{t('More reports')}</h4>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <Link className="btn-s" href="/reports/order">{t('Monthly Sales Summary → Order Report')}</Link>
                    <Link className="btn-s" href="/reports/product">{t('Stock and Product report')}</Link>
                    <Link className="btn-s" href="/finance/income-expense">{t('Monthly Expense Summary')}</Link>
                </div>
            </div>
        </AppLayout>
    );
}
