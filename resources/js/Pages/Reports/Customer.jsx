import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmt, fmtNum } from './parts';
import { t } from '@/lib/i18n';

const WORD = { today: 'Today', '7d': 'in the last 7 days', month: 'this month' };

export default function Customer({ preset, kpis, rows }) {
    return (
        <AppLayout>
            <Head title={t('Reports - Customer Report')} />
            <div className="rep">
                <RepHead title={t('Customer Report')} sub={t('Whether customers are growing, whether they are buying again - the real strength of the business.')} preset={preset} base="/reports/customer" exportKey="customer" />
                <div className="x-kpis">
                    <div className="x-kpi"><b>{fmtNum(kpis.total)}</b><span>{t('Total customers')}</span></div>
                    <div className="x-kpi green"><b>{fmtNum(kpis.new)}</b><span>{t(':word new', { word: t(WORD[preset] || 'this month') })}</span></div>
                    <div className="x-kpi purple"><b>{kpis.repeat_pct}%</b><span>{t('Bought again (repeat)')}</span></div>
                    <div className="x-kpi"><b>{fmt(kpis.avg_value)}</b><span>{t('Average order value')}</span></div>
                </div>
                <table className="x-table">
                    <thead><tr><th>{t('Best customer')}</th><th>{t('Order')}</th><th>{t('Total bought')}</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.name}><td>{r.name}</td><td>{fmtNum(r.orders)}</td><td>{fmt(r.spent)}</td></tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="3" style={{ color: '#94A3B8' }}>{t('No order in this period.')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
