import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmt, fmtNum } from './parts';

const WORD = { today: 'আজ', '7d': 'গত 7 দিনে', month: 'এই মাসে' };

export default function Customer({ preset, kpis, rows }) {
    return (
        <AppLayout>
            <Head title="Reports — Customer Report" />
            <div className="rep">
                <RepHead title="Customer Report" sub="Customer বাড়ছে কিনা, আবার কিনছে কিনা — ব্যবসার আসল শক্তি।" preset={preset} base="/reports/customer" exportKey="customer" />
                <div className="x-kpis">
                    <div className="x-kpi"><b>{fmtNum(kpis.total)}</b><span>মোট customer</span></div>
                    <div className="x-kpi green"><b>{fmtNum(kpis.new)}</b><span>{WORD[preset] || 'এই মাসে'} নতুন</span></div>
                    <div className="x-kpi purple"><b>{kpis.repeat_pct}%</b><span>আবার কিনেছে (repeat)</span></div>
                    <div className="x-kpi"><b>{fmt(kpis.avg_value)}</b><span>গড় order-এর মূল্য</span></div>
                </div>
                <table className="x-table">
                    <thead><tr><th>সেরা customer</th><th>Order</th><th>মোট কেনা</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.name}><td>{r.name}</td><td>{fmtNum(r.orders)}টা</td><td>{fmt(r.spent)}</td></tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="3" style={{ color: '#94A3B8' }}>এই সময়ে কোনো order নেই।</td></tr>}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
