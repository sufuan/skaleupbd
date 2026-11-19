import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { TodayTable, LeaveCards } from './parts';

export default function Dashboard({ stats, today, pending_leaves, can_manage }) {
    return (
        <AppLayout title="HR Dashboard">
            <Head title="HR Dashboard" />
            <div className="hrm">
                <div className="x-head">
                    <h3>HR Dashboard</h3>
                    <p>আজ কে এসেছে, কে ছুটিতে — দলের পুরো ছবি এক পর্দায়।</p>
                </div>

                <div className="x-kpis">
                    <div className="x-kpi"><b>{stats.total}</b><span>মোট কর্মী</span></div>
                    <div className="x-kpi green"><b>{stats.present}</b><span>আজ এসেছে</span></div>
                    <div className="x-kpi"><b>{stats.leave}</b><span>ছুটিতে আছে</span></div>
                    <div className="x-kpi red"><b>{stats.late}</b><span>দেরি করে এসেছে</span></div>
                </div>

                <LeaveCards leaves={pending_leaves} canManage={can_manage} />

                <div className="x-card">
                    <h4>আজকের উপস্থিতি</h4>
                    <TodayTable rows={today} />
                </div>
            </div>
        </AppLayout>
    );
}
