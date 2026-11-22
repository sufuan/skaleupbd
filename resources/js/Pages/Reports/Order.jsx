import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmt, fmtNum } from './parts';
import { t } from '@/lib/i18n';

export default function Order({ preset, kpis, rows }) {
    return (
        <AppLayout>
            <Head title={t('Reports - Order Report')} />
            <div className="rep">
                <RepHead title={t('Order Report')} sub={t('Where orders come from and how many reach - the picture over time.')} preset={preset} base="/reports/order" exportKey="order" />
                <div className="x-kpis">
                    <div className="x-kpi"><b>{fmtNum(kpis.total)}</b><span>{t('Total orders')}</span></div>
                    <div className="x-kpi green"><b>{kpis.delivered_pct}%</b><span>{t('Delivered')}</span></div>
                    <div className="x-kpi red"><b>{kpis.returned_pct}%</b><span>{t('Returned')}</span></div>
                    <div className="x-kpi"><b>{kpis.cancelled_pct}%</b><span>{t('Cancelled')}</span></div>
                </div>
                <table className="x-table">
                    <thead><tr><th>{t('Source')}</th><th>{t('Order')}</th><th>{t('Delivered')}</th><th>{t('Returned')}</th><th>{t('Sales')}</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.source}><td>{r.source}</td><td>{fmtNum(r.orders)}</td><td>{r.delivered_pct}%</td><td>{r.returned_pct}%</td><td>{fmt(r.sales)}</td></tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>{t('No order in this period.')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
