import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { TodayTable, LeaveCards } from './parts';
import { t } from '@/lib/i18n';

export default function Dashboard({ stats, today, pending_leaves, can_manage }) {
    return (
        <AppLayout title={t('HR Dashboard')}>
            <Head title={t('HR Dashboard')} />
            <div className="hrm">
                <div className="x-head">
                    <h3>{t('HR Dashboard')}</h3>
                    <p>{t('Who came today, who is on leave - the whole team picture on one screen.')}</p>
                </div>

                <div className="x-kpis">
                    <div className="x-kpi"><b>{stats.total}</b><span>{t('Total staff')}</span></div>
                    <div className="x-kpi green"><b>{stats.present}</b><span>{t('Came today')}</span></div>
                    <div className="x-kpi"><b>{stats.leave}</b><span>{t('On leave today')}</span></div>
                    <div className="x-kpi red"><b>{stats.late}</b><span>{t('Came late')}</span></div>
                </div>

                <LeaveCards leaves={pending_leaves} canManage={can_manage} />

                <div className="x-card">
                    <h4>{t("Today's attendance")}</h4>
                    <TodayTable rows={today} />
                </div>
            </div>
        </AppLayout>
    );
}
