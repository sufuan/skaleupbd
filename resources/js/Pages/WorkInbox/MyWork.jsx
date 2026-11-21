import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { DoneModal, NewTaskModal, TaskCard } from './parts';

// My Work — my own tasks (prototype panel wi-my). The page exists but
// stays OFF until the shop enables it in Work Inbox Settings; the
// controller redirects here only when the flag is on.
export default function MyWork({ kpis, tasks, staff, sources }) {
    const [doneTask, setDoneTask] = useState(null);
    const [newOpen, setNewOpen] = useState(false);
    const [tab, setTab] = useState('open');
    const [q, setQ] = useState('');
    const [source, setSource] = useState('');
    const [due, setDue] = useState('');
    const [checked, setChecked] = useState({});

    const visible = useMemo(() => tasks.filter((task) => {
        const statusOk = tab === 'open' ? task.status === 'open'
            : tab === 'progress' ? task.status === 'in_progress'
            : (task.status === 'done' || task.status === 'auto_closed');
        if (!statusOk) return false;
        if (source && task.source !== source) return false;
        if (due === 'today' && !(task.due && !task.is_overdue)) return false;
        if (due === 'overdue' && !task.is_overdue) return false;
        if (q && !(task.title + ' ' + (task.source_label || '')).toLowerCase().includes(q.toLowerCase())) return false;
        return true;
    }), [tasks, tab, source, due, q]);

    const checkedIds = Object.entries(checked).filter(([, v]) => v).map(([k]) => Number(k));
    const bulkDone = () => router.post('/work-inbox/tasks/bulk-done', { ids: checkedIds }, {
        preserveScroll: true, onSuccess: () => setChecked({}),
    });

    return (
        <AppLayout>
            <Head title={t('My Work')} />
            <div className="wi">
                <div className="wi-head">
                    <div>
                        <h3 style={{ fontSize: 20 }}>{t('My Work')}</h3>
                        <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>সিস্টেম যেসব জায়গায় তোমার হাত চায়, সব এখানে। একটার পর একটা শেষ করো — কিছুই হারাবে না।</p>
                    </div>
                    <button className="btn-p" onClick={() => setNewOpen(true)}>{t('+ New Task')}</button>
                </div>

                <div className="wi-kpis">
                    <div className="wi-kpi"><b>{kpis.open}</b><span>খোলা কাজ</span></div>
                    <div className="wi-kpi red"><b>{kpis.overdue}</b><span>Overdue (সময় পার)</span></div>
                    <div className="wi-kpi amber"><b>{kpis.due_today}</b><span>আজকের মধ্যে</span></div>
                    <div className="wi-kpi red"><b>{kpis.critical}</b><span>খুব জরুরি</span></div>
                    <div className="wi-kpi"><b>{kpis.done_today}</b><span>আজ শেষ করেছি</span></div>
                </div>

                <div className="wi-tools">
                    <input type="text" placeholder="Invoice, customer বা phone দিয়ে খোঁজো" style={{ flex: 1, minWidth: 200 }} value={q} onChange={(e) => setQ(e.target.value)} />
                    <select value={source} onChange={(e) => setSource(e.target.value)}>
                        <option value="">সব উৎস</option>
                        {Object.entries(sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <select value={due} onChange={(e) => setDue(e.target.value)}>
                        <option value="">সব সময়</option>
                        <option value="today">আজকের</option>
                        <option value="overdue">Overdue</option>
                    </select>
                    {checkedIds.length > 0 && (
                        <button className="btn-s" onClick={bulkDone}>{t('Complete selected')}</button>
                    )}
                </div>

                <div className="wi-tabs">
                    <button className={`ptab${tab === 'open' ? ' act' : ''}`} onClick={() => setTab('open')}>{t('Open')}</button>
                    <button className={`ptab${tab === 'progress' ? ' act' : ''}`} onClick={() => setTab('progress')}>চলছে</button>
                    <button className={`ptab${tab === 'done' ? ' act' : ''}`} onClick={() => setTab('done')}>{t('Done')}</button>
                </div>

                <div>
                    {visible.map((task) => (
                        <TaskCard key={task.id} task={task} onDone={setDoneTask} selectable
                            checked={!!checked[task.id]}
                            onCheck={(id, v) => setChecked((p) => ({ ...p, [id]: v }))} />
                    ))}
                    {visible.length === 0 && (
                        <div className="tk"><div className="tk-main"><div className="tk-title">এখানে কোনো কাজ নেই</div></div></div>
                    )}
                </div>

                <DoneModal task={doneTask} onClose={() => setDoneTask(null)} />
                {newOpen && <NewTaskModal staff={staff} onClose={() => setNewOpen(false)} />}
            </div>
        </AppLayout>
    );
}
