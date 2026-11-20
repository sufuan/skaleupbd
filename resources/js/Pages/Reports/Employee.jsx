import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmtNum } from './parts';
import { t } from '@/lib/i18n';

export default function Employee({ preset, rows }) {
    return (
        <AppLayout>
            <Head title={t('Reports — Employee Report')} />
            <div className="rep">
                <RepHead title={t('Employee Report')} sub={t('Who is working how — the account of orders and tasks (the attendance account is in HRM).')} preset={preset} base="/reports/employee" exportKey="employee" />
                <table className="x-table">
                    <thead><tr><th>{t('Name')}</th><th>{t('Processed orders')}</th><th>{t('Delivered')}</th><th>{t('Return')}</th><th>{t('Finished tasks')}</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.name}>
                                <td>{r.name}</td>
                                <td>{r.processed > 0 ? t(':count pcs', { count: fmtNum(r.processed) }) : '—'}</td>
                                <td>{r.delivered_pct !== null ? `${r.delivered_pct}%` : '—'}</td>
                                <td>{r.return_pct !== null ? `${r.return_pct}%` : '—'}</td>
                                <td>—</td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>{t('No staff.')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
