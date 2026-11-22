import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmtNum } from './parts';
import { t } from '@/lib/i18n';

export default function Courier({ preset, word, rows, best }) {
    const chip = (p) => (p >= 79 ? 'chip-green' : p >= 65 ? 'chip-amber' : 'chip-red');
    return (
        <AppLayout>
            <Head title={t('Reports - Courier Report')} />
            <div className="rep">
                <RepHead title={t('Courier Report')} sub={t('Which courier is working how - only the work account. The money account is not here; that is in Delivery → Courier Ledger.')} preset={preset} base="/reports/courier" exportKey="courier" />
                <table className="x-table">
                    <thead><tr><th>{t('Courier')}</th><th>{t('Sent')}</th><th>{t('Delivered')}</th><th>{t('Return')}</th><th>{t('Average time')}</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.courier}>
                                <td>{r.courier}</td><td>{t(':count pcs', { count: fmtNum(r.sent) })}</td>
                                <td><span className={`chip ${chip(r.delivered_pct)}`}>{r.delivered_pct}%</span></td>
                                <td>{r.return_pct}%</td>
                                <td>{r.avg_days !== null ? t(':count days', { count: r.avg_days }) : '-'}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><td colSpan="5" style={{ color: '#94A3B8' }}>{t('No parcel was sent in this period.')}</td></tr>}
                    </tbody>
                </table>
                {best && <div className="x-note">{word} {t('is the best:')} <b>{best}</b> {t('- most delivered, and the least time too.')}</div>}
            </div>
        </AppLayout>
    );
}
