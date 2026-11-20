import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmt, fmtNum } from './parts';
import { t } from '@/lib/i18n';

export default function Product({ preset, rows, worst }) {
    const chip = (m) => (m >= 30 ? 'chip-green' : m >= 20 ? 'chip-amber' : 'chip-red');
    return (
        <AppLayout>
            <Head title={t('Reports — Product Report')} />
            <div className="rep">
                <RepHead title={t('Product Report')} sub={t('Which product has more profit, which has less — for buying decisions.')} preset={preset} base="/reports/product" exportKey="product" />
                <table className="x-table">
                    <thead><tr><th>{t('Product')}</th><th>{t('Sold')}</th><th>{t('Income')}</th><th>{t('Profit')}</th><th>{t('Margin')}</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.product}>
                                <td>{r.product}</td><td>{t(':count pcs', { count: fmtNum(r.qty) })}</td><td>{fmt(r.revenue)}</td><td>{fmt(r.profit)}</td>
                                <td><span className={`chip ${chip(r.margin)}`}>{r.margin}%{r.margin < 20 ? t(' — low') : ''}</span></td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>{t('No delivered sales in this period.')}</td></tr>}
                    </tbody>
                </table>
                {worst && <div className="x-note">{t(':product has very low profit — think about raising the price or buying less.', { product: worst })}</div>}
            </div>
        </AppLayout>
    );
}
