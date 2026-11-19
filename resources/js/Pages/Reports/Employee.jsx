import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmtNum } from './parts';

export default function Employee({ preset, rows }) {
    return (
        <AppLayout>
            <Head title="Reports — Employee Report" />
            <div className="rep">
                <RepHead title="Employee Report" sub="কে কেমন কাজ করছে — order আর task-এর হিসাব (উপস্থিতির হিসাব HRM-এ)।" preset={preset} base="/reports/employee" exportKey="employee" />
                <table className="x-table">
                    <thead><tr><th>নাম</th><th>Order process করেছে</th><th>Delivered</th><th>Return</th><th>Task শেষ করেছে</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.name}>
                                <td>{r.name}</td>
                                <td>{r.processed > 0 ? `${fmtNum(r.processed)}টা` : '—'}</td>
                                <td>{r.delivered_pct !== null ? `${r.delivered_pct}%` : '—'}</td>
                                <td>{r.return_pct !== null ? `${r.return_pct}%` : '—'}</td>
                                <td>—</td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>কোনো staff নেই।</td></tr>}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
