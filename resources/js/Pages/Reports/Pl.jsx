import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmt, fmtSigned } from './parts';

const WORD = { today: 'আজ', '7d': 'গত 7 দিনে', month: 'এই মাসে' };

export default function Pl({ preset, kpis, rows, estimate_enabled, estimate }) {
    const toggleEstimate = () => router.post('/reports/pl/estimate', { enabled: !estimate_enabled }, { preserveScroll: true });
    return (
        <AppLayout>
            <Head title="Reports — Profit & Loss" />
            <div className="rep">
                <RepHead title="Profit & Loss" sub={`${WORD[preset] || 'এই মাসে'} আসলেই কত লাভ হলো — সব খরচ বাদ দিয়ে। হিসাবের ভিত্তি: delivered order-এর তারিখ (Finance-এর সঙ্গে একই হিসাব)।`} preset={preset} base="/reports" exportKey="pl" />
                <div className="x-kpis">
                    <div className="x-kpi"><b>{fmt(kpis.sales)}</b><span>মোট বিক্রি</span></div>
                    <div className={`x-kpi ${kpis.profit >= 0 ? 'green' : 'red'}`}><b>{fmtSigned(kpis.profit)}</b><span>নিট লাভ</span></div>
                    <div className="x-kpi purple"><b>{kpis.margin}%</b><span>লাভের হার (margin)</span></div>
                    <div className="x-kpi"><b>{fmtSigned(kpis.per_order)}</b><span>প্রতি order-এ গড় লাভ</span></div>
                </div>
                <table className="x-table">
                    <thead><tr><th>খাত</th><th>টাকা</th></tr></thead>
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
                    <button type="button" className={`switch${estimate_enabled ? ' on' : ''}`} onClick={toggleEstimate} aria-label="অনুমানের হিসাব" style={{ width: 46, height: 26, borderRadius: 99, background: estimate_enabled ? '#7C3AED' : '#E2E8F0', border: 'none', cursor: 'pointer', position: 'relative', flexShrink: 0 }}>
                        <span style={{ position: 'absolute', top: 3, left: estimate_enabled ? 23 : 3, width: 20, height: 20, borderRadius: '50%', background: '#fff', transition: 'left .15s' }}></span>
                    </button>
                    <span style={{ fontSize: 13.5, color: '#475569' }}>অনুমানের হিসাব — পথে থাকা order-এর সম্ভাব্য লাভও দেখাও</span>
                </div>
                {estimate_enabled && estimate ? (
                    <div className="x-note">হিসাবের নিয়ম: যেসব order এখনো পথে আছে ({estimate.orders}টা), সেগুলোর {estimate.rate}% delivered হবে ধরে আনুমানিক আরও {fmt(estimate.amount)} লাভ হতে পারে — উপরের নিট লাভে এই অনুমান যোগ করা হয়নি, delivered-এর আসল হিসাবই দেওয়া আছে।</div>
                ) : (
                    <div className="x-note">হিসাবের নিয়ম: শুধু delivered order-এর আসল লাভ ধরা হয়েছে — পথে থাকা order-এর কোনো অনুমান যোগ করা হয়নি।</div>
                )}
                <h4 style={{ fontSize: 16, margin: '18px 0 10px' }}>আরও বিবরণী</h4>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <Link className="btn-s" href="/reports/order">Monthly Sales Summary → Order Report</Link>
                    <Link className="btn-s" href="/reports/product">Stock ও Product বিবরণী</Link>
                    <Link className="btn-s" href="/finance/income-expense">Monthly Expense Summary</Link>
                </div>
            </div>
        </AppLayout>
    );
}
