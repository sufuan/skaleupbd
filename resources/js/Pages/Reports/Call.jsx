import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { RepHead, fmtNum } from './parts';
import { t } from '@/lib/i18n';

export default function Call({ preset, kpis, best_slot }) {
    return (
        <AppLayout>
            <Head title={t('Reports — Call Center Report')} />
            <div className="rep">
                <RepHead title={t('Call Center Report')} sub={t('How much work is happening on calls — answered, talked, and orders from them.')} preset={preset} base="/reports/call" exportKey="call" />
                <div className="x-kpis">
                    <div className="x-kpi"><b>{fmtNum(kpis.total)}</b><span>{t('Total calls in this period')}</span></div>
                    <div className="x-kpi green"><b>{kpis.answered_pct}%</b><span>{t('Calls answered')}</span></div>
                    <div className="x-kpi"><b>{t(':count pcs', { count: fmtNum(kpis.orders) })}</b><span>{t('Orders from calls')}</span></div>
                    <div className="x-kpi"><b>{kpis.avg}</b><span>{t('Average talk time (minutes)')}</span></div>
                </div>
                {best_slot ? (
                    <div className="x-note">{t('Most orders came from calls at :slot — you can add people at that time.', { slot: best_slot })}</div>
                ) : (
                    <div className="x-note">{t('No order came from calls in this period.')}</div>
                )}
            </div>
        </AppLayout>
    );
}
