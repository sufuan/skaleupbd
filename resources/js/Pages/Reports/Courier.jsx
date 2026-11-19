import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmtNum } from './parts';

export default function Courier({ preset, word, rows, best }) {
    const chip = (p) => (p >= 79 ? 'chip-green' : p >= 65 ? 'chip-amber' : 'chip-red');
    return (
        <AppLayout>
            <Head title="Reports — Courier Report" />
            <div className="rep">
                <RepHead title="Courier Report" sub="কোন courier কেমন কাজ করছে — শুধু কাজের হিসাব। টাকার হিসাব এখানে নেই, ওটা Delivery → Courier Ledger-এ।" preset={preset} base="/reports/courier" exportKey="courier" />
                <table className="x-table">
                    <thead><tr><th>Courier</th><th>পাঠানো</th><th>Delivered</th><th>Return</th><th>গড় সময়</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.courier}>
                                <td>{r.courier}</td><td>{fmtNum(r.sent)}টা</td>
                                <td><span className={`chip ${chip(r.delivered_pct)}`}>{r.delivered_pct}%</span></td>
                                <td>{r.return_pct}%</td>
                                <td>{r.avg_days !== null ? `${r.avg_days} দিন` : '—'}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>এই সময়ে কোনো parcel পাঠানো হয়নি।</td></tr>}
                    </tbody>
                </table>
                {best && <div className="x-note">{word} সবচেয়ে ভালো: <b>{best}</b> — delivered সবচেয়ে বেশি, সময়ও সবচেয়ে কম।</div>}
            </div>
        </AppLayout>
    );
}
