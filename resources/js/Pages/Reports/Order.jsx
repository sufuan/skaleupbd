import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmt, fmtNum } from './parts';

export default function Order({ preset, kpis, rows }) {
    return (
        <AppLayout>
            <Head title="Reports — Order Report" />
            <div className="rep">
                <RepHead title="Order Report" sub="Order কোথা থেকে আসছে আর কতগুলো পৌঁছাচ্ছে — সময়ের ছবি।" preset={preset} base="/reports/order" exportKey="order" />
                <div className="x-kpis">
                    <div className="x-kpi"><b>{fmtNum(kpis.total)}</b><span>মোট order</span></div>
                    <div className="x-kpi green"><b>{kpis.delivered_pct}%</b><span>Delivered</span></div>
                    <div className="x-kpi red"><b>{kpis.returned_pct}%</b><span>Returned</span></div>
                    <div className="x-kpi"><b>{kpis.cancelled_pct}%</b><span>Cancelled</span></div>
                </div>
                <table className="x-table">
                    <thead><tr><th>উৎস</th><th>Order</th><th>Delivered</th><th>Returned</th><th>বিক্রি</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.source}><td>{r.source}</td><td>{fmtNum(r.orders)}</td><td>{r.delivered_pct}%</td><td>{r.returned_pct}%</td><td>{fmt(r.sales)}</td></tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>এই সময়ে কোনো order নেই।</td></tr>}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
