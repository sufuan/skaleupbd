import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmt, fmtNum } from './parts';

export default function Product({ preset, rows, worst }) {
    const chip = (m) => (m >= 30 ? 'chip-green' : m >= 20 ? 'chip-amber' : 'chip-red');
    return (
        <AppLayout>
            <Head title="Reports — Product Report" />
            <div className="rep">
                <RepHead title="Product Report" sub="কোন product-এ লাভ বেশি, কোনটায় কম — কেনার সিদ্ধান্তের জন্য।" preset={preset} base="/reports/product" exportKey="product" />
                <table className="x-table">
                    <thead><tr><th>Product</th><th>বিক্রি হয়েছে</th><th>আয়</th><th>লাভ</th><th>Margin</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.product}>
                                <td>{r.product}</td><td>{fmtNum(r.qty)}টা</td><td>{fmt(r.revenue)}</td><td>{fmt(r.profit)}</td>
                                <td><span className={`chip ${chip(r.margin)}`}>{r.margin}%{r.margin < 20 ? ' — কম' : ''}</span></td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>এই সময়ে কোনো delivered বিক্রি নেই।</td></tr>}
                    </tbody>
                </table>
                {worst && <div className="x-note">{worst} product-এ লাভ খুব কম — দাম বাড়ানো বা কেনা কমানোর কথা ভাবো।</div>}
            </div>
        </AppLayout>
    );
}
