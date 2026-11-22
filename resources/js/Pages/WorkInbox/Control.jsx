import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { DoneModal, NewTaskModal, PRIORITY_CHIP, TaskCard } from './parts';

// Work Control - the supervisor screen (prototype panel wi-control):
// pool cards first, then everyone's tasks in one table with reassign.
export default function Control({ kpis, poolTasks, tasks, staff, filters, sources }) {
    const [doneTask, setDoneTask] = useState(null);
    const [newOpen, setNewOpen] = useState(false);
    const [f, setF] = useState(filters);

    const apply = (next) => {
        setF(next);
        router.get('/work-inbox/control', next, { preserveScroll: true, preserveState: true });
    };

    return (
        <AppLayout>
            <Head title={t('Work Control')} />
            <div className="wi">
                <div className="wi-head">
                    <div>
                        <h3 style={{ fontSize: 20 }}>{t('Work Control')}</h3>
                        <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>{t('The supervisor screen - everyone\'s work in one place. Move stuck work to someone else.')}</p>
                    </div>
                    <button className="btn-p" onClick={() => setNewOpen(true)}>{t('+ New Task')}</button>
                </div>

                <div className="wi-kpis c4">
                    <div className="wi-kpi"><b>{kpis.open_total}</b><span>{t('Total open work')}</span></div>
                    <div className="wi-kpi amber"><b>{kpis.unassigned}</b><span>{t('Not assigned to anyone')}</span></div>
                    <div className="wi-kpi red"><b>{kpis.overdue}</b><span>Overdue</span></div>
                    <div className="wi-kpi"><b>{kpis.done_today}</b><span>{t('Finished today')}</span></div>
                </div>

                {poolTasks.length > 0 && (
                    <div className="pool-box">
                        <div style={{ fontWeight: 700, color: '#92400E', marginBottom: 4 }}>{t('Not assigned to anyone - pick these up first')}</div>
                        <div style={{ fontSize: 13, color: '#92400E', marginBottom: 10 }}>{t('Work sitting in the pool can be picked up by whoever is free, or you can give it to someone.')}</div>
                        {poolTasks.map((task) => <TaskCard key={task.id} task={task} onDone={setDoneTask} />)}
                    </div>
                )}

                <h4 style={{ fontSize: 16, margin: '6px 0 10px' }}>{t('Everyone\'s open work')}</h4>

                <div className="wi-tools">
                    <input type="text" placeholder={t('Search by invoice, customer or phone')} style={{ flex: 1, minWidth: 200 }} value={f.q}
                        onChange={(e) => setF({ ...f, q: e.target.value })}
                        onKeyDown={(e) => e.key === 'Enter' && apply(f)} />
                    <select value={f.status} onChange={(e) => apply({ ...f, status: e.target.value })}>
                        <option value="open">{t('Open')}</option>
                        <option value="progress">{t('In progress')}</option>
                        <option value="done">{t('Done')}</option>
                        <option value="all">{t('All')}</option>
                    </select>
                    <select value={f.source} onChange={(e) => apply({ ...f, source: e.target.value })}>
                        <option value="">{t('All sources')}</option>
                        {Object.entries(sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <select value={f.assignee} onChange={(e) => apply({ ...f, assignee: e.target.value })}>
                        <option value="">{t('Everyone')}</option>
                        <option value="pool">{t('Pool (Unassigned)')}</option>
                        {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                    <select value={f.due} onChange={(e) => apply({ ...f, due: e.target.value })}>
                        <option value="">{t('Any time')}</option>
                        <option value="today">{t('Due today')}</option>
                        <option value="overdue">Overdue</option>
                    </select>
                </div>

                <div className="wi-table-wrap">
                <table className="wi-table">
                    <thead>
                        <tr><th>{t('Task')}</th><th>{t('Source')}</th><th>{t('Assigned to')}</th><th>{t('Time')}</th><th>{t('Priority')}</th><th>{t('Status')}</th><th>{t('Give to someone else')}</th></tr>
                    </thead>
                    <tbody>
                        {tasks.map((task) => (
                            <tr key={task.id}>
                                <td style={{ fontWeight: 600, color: '#1E293B' }}>{task.title}</td>
                                <td>{sources[task.source] || task.source}</td>
                                <td>{task.assignee_label}</td>
                                <td>{task.due ? task.due.text : (task.status === 'done' || task.status === 'auto_closed' ? '-' : t('Due today'))}</td>
                                <td><span className={`chip ${PRIORITY_CHIP[task.priority] || 'chip-grey'}`}>{task.priority_label}</span></td>
                                <td>
                                    {task.status === 'done' && <span className="chip chip-green">{t('Done')}</span>}
                                    {task.status === 'auto_closed' && <span className="chip chip-grey">{t('Resolved on its own')}</span>}
                                    {task.status === 'open' && <span className="chip chip-grey">{t('Open')}</span>}
                                    {task.status === 'in_progress' && <span className="chip chip-purple">চলছে</span>}
                                </td>
                                <td>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                                        {(task.status === 'open' || task.status === 'in_progress') && (
                                            <select value={task.assignee_id || ''} style={{ height: 32, border: '1px solid #E2E8F0', borderRadius: 7, fontFamily: 'inherit', width: '100%', minWidth: 150 }}
                                                onChange={(e) => router.post(`/work-inbox/tasks/${task.id}/reassign`, { user_id: e.target.value ? Number(e.target.value) : null }, { preserveScroll: true })}>
                                                <option value="">{t('Pool (Unassigned)')}</option>
                                                {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                            </select>
                                        )}
                                        {(task.status === 'open' || task.status === 'in_progress') && (
                                            <span style={{ display: 'flex', gap: 6 }}>
                                                {task.status === 'open' && (
                                                    <button className="btn-s" onClick={() => router.post(`/work-inbox/tasks/${task.id}/start`, {}, { preserveScroll: true })}>{t('Start')}</button>
                                                )}
                                                <button className="btn-s" onClick={() => setDoneTask(task)}>{t('Done')}</button>
                                            </span>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {tasks.length === 0 && (
                            <tr><td colSpan="7" style={{ color: '#64748B' }}>কোনো কাজ নেই - সব পরিষ্কার।</td></tr>
                        )}
                    </tbody>
                </table>
                </div>

                <DoneModal task={doneTask} onClose={() => setDoneTask(null)} />
                {newOpen && <NewTaskModal staff={staff} onClose={() => setNewOpen(false)} />}
            </div>
        </AppLayout>
    );
}
