import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { DoneModal, NewTaskModal, PRIORITY_CHIP, TaskCard } from './parts';

// Work Control — the supervisor screen (prototype panel wi-control):
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
                        <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>Supervisor-এর পর্দা — সবার কাজ একসাথে। আটকে থাকা কাজ অন্য কাউকে সরিয়ে দাও।</p>
                    </div>
                    <button className="btn-p" onClick={() => setNewOpen(true)}>{t('+ New Task')}</button>
                </div>

                <div className="wi-kpis c4">
                    <div className="wi-kpi"><b>{kpis.open_total}</b><span>মোট খোলা কাজ</span></div>
                    <div className="wi-kpi amber"><b>{kpis.unassigned}</b><span>কাউকে দেওয়া হয়নি</span></div>
                    <div className="wi-kpi red"><b>{kpis.overdue}</b><span>Overdue</span></div>
                    <div className="wi-kpi"><b>{kpis.done_today}</b><span>আজ শেষ হয়েছে</span></div>
                </div>

                {poolTasks.length > 0 && (
                    <div className="pool-box">
                        <div style={{ fontWeight: 700, color: '#92400E', marginBottom: 4 }}>কাউকে দেওয়া হয়নি — এগুলো আগে ধরো</div>
                        <div style={{ fontSize: 13, color: '#92400E', marginBottom: 10 }}>পুলে পড়ে থাকা কাজ যে ফ্রি সে তুলে নিতে পারে, অথবা তুমি কাউকে দিয়ে দাও।</div>
                        {poolTasks.map((task) => <TaskCard key={task.id} task={task} onDone={setDoneTask} />)}
                    </div>
                )}

                <h4 style={{ fontSize: 16, margin: '6px 0 10px' }}>সবার খোলা কাজ</h4>

                <div className="wi-tools">
                    <input type="text" placeholder="Invoice, customer বা phone দিয়ে খোঁজো" style={{ flex: 1, minWidth: 200 }} value={f.q}
                        onChange={(e) => setF({ ...f, q: e.target.value })}
                        onKeyDown={(e) => e.key === 'Enter' && apply(f)} />
                    <select value={f.status} onChange={(e) => apply({ ...f, status: e.target.value })}>
                        <option value="open">{t('Open')}</option>
                        <option value="progress">চলছে</option>
                        <option value="done">{t('Done')}</option>
                        <option value="all">{t('All')}</option>
                    </select>
                    <select value={f.source} onChange={(e) => apply({ ...f, source: e.target.value })}>
                        <option value="">সব উৎস</option>
                        {Object.entries(sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <select value={f.assignee} onChange={(e) => apply({ ...f, assignee: e.target.value })}>
                        <option value="">সবাই</option>
                        <option value="pool">পুল (Unassigned)</option>
                        {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                    <select value={f.due} onChange={(e) => apply({ ...f, due: e.target.value })}>
                        <option value="">সব সময়</option>
                        <option value="today">আজকের</option>
                        <option value="overdue">Overdue</option>
                    </select>
                </div>

                <table className="wi-table">
                    <thead>
                        <tr><th>কাজ</th><th>উৎস</th><th>দায়িত্বে</th><th>সময়</th><th>গুরুত্ব</th><th>অবস্থা</th><th>অন্য কাউকে দাও</th></tr>
                    </thead>
                    <tbody>
                        {tasks.map((task) => (
                            <tr key={task.id}>
                                <td style={{ fontWeight: 600, color: '#1E293B' }}>{task.title}</td>
                                <td>{sources[task.source] || task.source}</td>
                                <td>{task.assignee_label}</td>
                                <td>{task.due ? task.due.text : (task.status === 'done' || task.status === 'auto_closed' ? '—' : 'আজকের মধ্যে')}</td>
                                <td><span className={`chip ${PRIORITY_CHIP[task.priority] || 'chip-grey'}`}>{task.priority_label}</span></td>
                                <td>
                                    {task.status === 'done' && <span className="chip chip-green">{t('Done')}</span>}
                                    {task.status === 'auto_closed' && <span className="chip chip-grey">নিজে থেকে মিটে গেছে</span>}
                                    {task.status === 'open' && <span className="chip chip-grey">{t('Open')}</span>}
                                    {task.status === 'in_progress' && <span className="chip chip-purple">চলছে</span>}
                                </td>
                                <td>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                                        {(task.status === 'open' || task.status === 'in_progress') && (
                                            <select value={task.assignee_id || ''} style={{ height: 32, border: '1px solid #E2E8F0', borderRadius: 7, fontFamily: 'inherit' }}
                                                onChange={(e) => router.post(`/work-inbox/tasks/${task.id}/reassign`, { user_id: e.target.value ? Number(e.target.value) : null }, { preserveScroll: true })}>
                                                <option value="">পুল (Unassigned)</option>
                                                {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                            </select>
                                        )}
                                        {(task.status === 'open' || task.status === 'in_progress') && (
                                            <span style={{ display: 'flex', gap: 6 }}>
                                                {task.status === 'open' && (
                                                    <button className="btn-s" onClick={() => router.post(`/work-inbox/tasks/${task.id}/start`, {}, { preserveScroll: true })}>শুরু করো</button>
                                                )}
                                                <button className="btn-s" onClick={() => setDoneTask(task)}>{t('Done')}</button>
                                            </span>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {tasks.length === 0 && (
                            <tr><td colSpan="7" style={{ color: '#64748B' }}>কোনো কাজ নেই — সব পরিষ্কার।</td></tr>
                        )}
                    </tbody>
                </table>

                <DoneModal task={doneTask} onClose={() => setDoneTask(null)} />
                {newOpen && <NewTaskModal staff={staff} onClose={() => setNewOpen(false)} />}
            </div>
        </AppLayout>
    );
}
