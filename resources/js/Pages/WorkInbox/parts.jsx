import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { t } from '@/lib/i18n';

// Shared pieces of the Work Inbox pages — markup copied from the
// prototype (workinbox.html): .tk task cards + the two .wi-modal boxes.

export const PRIORITY_CHIP = { critical: 'chip-red', urgent: 'chip-amber', normal: 'chip-grey' };

export function TaskCard({ task, onDone, selectable = false, checked = false, onCheck }) {
    const done = task.status === 'done';
    const auto = task.status === 'auto_closed';
    const closed = done || auto;
    return (
        <div className={`tk${closed ? ' done' : ''}`}>
            {selectable && !closed && (
                <input type="checkbox" checked={checked} onChange={(e) => onCheck && onCheck(task.id, e.target.checked)} style={{ marginTop: 4, width: 16, height: 16 }} />
            )}
            <div className="tk-main">
                <div className="tk-title">{task.title}</div>
                <div className="tk-meta">
                    {done && <span className="chip chip-green">{t('Done')}</span>}
                    {auto && <span className="chip chip-grey">নিজে থেকে মিটে গেছে</span>}
                    {!closed && <span className={`chip ${PRIORITY_CHIP[task.priority] || 'chip-grey'}`}>{task.priority_label}</span>}
                    {!closed && task.due && <span className={`chip ${task.due.chip}`}>{task.due.text}</span>}
                    {task.status === 'in_progress' && <span className="chip chip-purple">চলছে</span>}
                    {task.source_label && <span>{task.source_label}</span>}
                    {!closed && <span>তৈরি: {task.created_label}</span>}
                    {!closed && <span>দায়িত্বে: {task.assignee_label}</span>}
                    {done && task.done_label && <span>শেষ: {task.done_label}{task.done_by_label ? ` · ${task.done_by_label}` : ''}</span>}
                    {auto && task.closed_label && <span>বন্ধ: {task.closed_label}</span>}
                </div>
                <div className="tk-cap">
                    {task.rule_num
                        ? <>নিয়ম {task.rule_num}: {task.rule_name} · <Link href="/work-inbox/settings">Settings-এ নিয়মটা দেখো</Link></>
                        : <>হাতে বানানো task (Manual)</>}
                    {done && task.done_note && <> · <b>কীভাবে মিটলো:</b> {task.done_note}</>}
                    {auto && task.auto_close_reason && <> · {task.auto_close_reason}</>}
                </div>
            </div>
            {!closed && (
                <div className="tk-actions">
                    {task.link && <a className="btn-p" href={task.link}>{task.link_label}</a>}
                    {task.status === 'open' && (
                        <button className="btn-s" onClick={() => router.post(`/work-inbox/tasks/${task.id}/start`, {}, { preserveScroll: true })}>শুরু করো</button>
                    )}
                    <button className="btn-s" onClick={() => onDone && onDone(task)}>{t('Done')}</button>
                </div>
            )}
        </div>
    );
}

export function DoneModal({ task, onClose }) {
    const [note, setNote] = useState('');
    const [err, setErr] = useState('');
    if (!task) return null;
    const save = () => {
        if (task.note_required && note.trim() === '') {
            setErr('এই কাজটায় note লেখা বাধ্যতামূলক — এক লাইন লিখে দাও।');
            return;
        }
        router.post(`/work-inbox/tasks/${task.id}/done`, { note }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (e) => setErr(e.note || 'হয়নি, আবার চেষ্টা করো'),
        });
    };
    return (
        <div className="wi-modal open">
            <div className="wi-modal-box">
                <h4 style={{ fontSize: 17, marginBottom: 4 }}>কাজটা শেষ — কীভাবে মিটলো?</h4>
                <p style={{ fontSize: 13, color: '#64748B' }}>
                    {task.note_required ? 'এই কাজটায় note লেখা বাধ্যতামূলক — এক লাইনে লিখে দাও।' : 'এক লাইনে লিখে রাখো, পরে যে কেউ দেখলে বুঝবে।'}
                </p>
                <textarea rows="3" value={note} onChange={(e) => setNote(e.target.value)} placeholder="যেমন: courier-এ পাঠিয়ে দিয়েছি, CN নম্বর ..."></textarea>
                {err && <div style={{ color: '#DC2626', fontSize: 13, marginBottom: 8 }}>{err}</div>}
                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                    <button className="btn-s" onClick={onClose}>বাদ দাও</button>
                    <button className="btn-p" onClick={save}>Done — শেষ করো</button>
                </div>
            </div>
        </div>
    );
}

export function NewTaskModal({ staff, onClose }) {
    const [title, setTitle] = useState('');
    const [who, setWho] = useState('self');
    const [priority, setPriority] = useState('normal');
    const save = () => {
        if (title.trim() === '') return;
        const data = { title, priority };
        if (who === 'self') data.assign = 'self';
        else if (who === 'pool') data.assign = 'pool';
        else { data.assign = 'user'; data.assign_user = Number(who); }
        router.post('/work-inbox/tasks', data, { preserveScroll: true, onSuccess: () => onClose() });
    };
    return (
        <div className="wi-modal open">
            <div className="wi-modal-box">
                <h4 style={{ fontSize: 17, marginBottom: 4 }}>নতুন Task (হাতে বানানো)</h4>
                <label style={{ fontSize: 13, color: '#64748B' }}>কী করতে হবে</label>
                <input type="text" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="যেমন: কাল সকালে Zeotexbd-কে phone করতে হবে" />
                <label style={{ fontSize: 13, color: '#64748B' }}>কাকে দেবে</label>
                <select value={who} onChange={(e) => setWho(e.target.value)}>
                    <option value="self">নিজেকে</option>
                    {(staff || []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    <option value="pool">পুল (যে ফ্রি সে নেবে)</option>
                </select>
                <label style={{ fontSize: 13, color: '#64748B' }}>গুরুত্ব</label>
                <select value={priority} onChange={(e) => setPriority(e.target.value)}>
                    <option value="normal">সাধারণ</option>
                    <option value="urgent">জরুরি</option>
                    <option value="critical">খুব জরুরি</option>
                </select>
                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                    <button className="btn-s" onClick={onClose}>বাদ দাও</button>
                    <button className="btn-p" onClick={save}>Task বানাও</button>
                </div>
            </div>
        </div>
    );
}
