import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { confirmDialog } from '@/lib/confirm';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

function MemberPicker({ options, selected, onToggle }) {
    return (
        <div className="perm-grid">
            {options.map((s) => (
                <label key={s.id}>
                    <input type="checkbox" checked={selected.includes(s.id)} onChange={() => onToggle(s.id)} /> {s.name}
                </label>
            ))}
        </div>
    );
}

function toggleIn(list, id) {
    return list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
}

function DeptCard({ dept, staffOptions, canManage }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        name: dept.name,
        head_user_id: dept.head ? String(dept.head.id) : '',
        member_ids: dept.members.map((m) => m.id),
    });
    const del = async () => { if (await confirmDialog(t('Delete this Department?'))) router.delete(`/hrm/departments/${dept.id}`, { preserveScroll: true }); };
    return (
        <div className="x-card">
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                <h4 style={{ margin: 0 }}>{dept.name}</h4>
                {canManage && (
                    <div className="row-actions">
                        <button type="button" className="btn-mini" onClick={() => setEditing(!editing)}>{t('Edit')}</button>
                        <button type="button" className="btn-mini danger" onClick={del}>{t('Delete')}</button>
                    </div>
                )}
            </div>
            <p style={{ fontSize: 13.5, color: '#64748B', margin: '8px 0' }}>
                {t('Head:')} <b style={{ color: '#1E293B' }}>{dept.head ? dept.head.name : t('Not set')}</b> · {t(':count staff', { count: dept.members.length })}
            </p>
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {dept.members.map((m) => <span key={m.id} className="chip chip-grey">{m.name}</span>)}
            </div>
            {editing && (
                <form style={{ marginTop: 14 }} onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/departments/${dept.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }}>
                    <div className="form-grid" style={{ marginBottom: 10 }}>
                        <div className="fld"><label className="lbl">{t('Department name')}</label>
                            <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                        <div className="fld"><label className="lbl">{t('Head (the person in charge)')}</label>
                            <select className="inp" value={form.data.head_user_id} onChange={(e) => form.setData('head_user_id', e.target.value)}>
                                <option value="">{t('— No one —')}</option>
                                {staffOptions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select></div>
                    </div>
                    <label className="lbl">{t('Staff members')}</label>
                    <MemberPicker options={staffOptions} selected={form.data.member_ids} onToggle={(id) => form.setData('member_ids', toggleIn(form.data.member_ids, id))} />
                    <p className="x-note">{t('One staff member stays in one Department only — bringing them here from another Department moves them out of there.')}</p>
                    <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 8 }}>{t('Save')}</button>
                </form>
            )}
        </div>
    );
}

function SopCard({ sop, departments, canManage }) {
    const [editing, setEditing] = useState(false);
    const [open, setOpen] = useState(false);
    const form = useForm({
        title: sop.title,
        department_id: sop.department_id ? String(sop.department_id) : '',
        steps_text: (sop.steps || []).join('\n'),
    });
    const del = async () => { if (await confirmDialog(t('Delete this SOP?'))) router.delete(`/hrm/sops/${sop.id}`, { preserveScroll: true }); };
    return (
        <div className="x-card">
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                <h4 style={{ margin: 0 }}>{sop.title}</h4>
                <div className="row-actions">
                    <button type="button" className="btn-mini" onClick={() => setOpen(!open)}>{open ? t('Hide steps') : t('See steps (:count)', { count: sop.steps.length })}</button>
                    {canManage && <button type="button" className="btn-mini" onClick={() => setEditing(!editing)}>{t('Edit')}</button>}
                    {canManage && <button type="button" className="btn-mini danger" onClick={del}>{t('Delete')}</button>}
                </div>
            </div>
            <p style={{ fontSize: 13, color: '#64748B', margin: '6px 0 0' }}>{sop.department_name || t('All Departments')}</p>
            {open && (
                <ol style={{ margin: '10px 0 0', paddingLeft: 20, fontSize: 13.5, lineHeight: 1.9 }}>
                    {sop.steps.map((s, i) => <li key={i}>{s}</li>)}
                </ol>
            )}
            {editing && (
                <form style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/sops/${sop.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }}>
                    <div className="form-grid" style={{ marginBottom: 10 }}>
                        <div className="fld"><label className="lbl">{t('SOP name')}</label>
                            <input className="inp" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required /></div>
                        <div className="fld"><label className="lbl">{t('Department')}</label>
                            <select className="inp" value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)}>
                                <option value="">{t('All Departments')}</option>
                                {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                            </select></div>
                    </div>
                    <div className="fld" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                        <label className="lbl">{t('Steps (one step per line)')}</label>
                        <textarea className="inp" rows={5} value={form.data.steps_text} onChange={(e) => form.setData('steps_text', e.target.value)} required />
                    </div>
                    <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 8 }}>{t('Save')}</button>
                </form>
            )}
        </div>
    );
}

export default function Departments({ departments, sops, staff_options, can_manage }) {
    const deptForm = useForm({ name: '', head_user_id: '', member_ids: [] });
    const sopForm = useForm({ title: '', department_id: '', steps_text: '' });
    return (
        <AppLayout title={t('Departments & SOP')}>
            <Head title={t('Departments & SOP')} />
            <div className="hrm">
                <div className="x-head">
                    <h3>{t('Departments & SOP')}</h3>
                    <p>{t('Who works in which team, and the rules (SOP) for every job — all here.')}</p>
                </div>

                {departments.map((d) => <DeptCard key={d.id} dept={d} staffOptions={staff_options} canManage={can_manage} />)}

                {can_manage && (
                    <div className="x-card">
                        <h4>{t('New Department')}</h4>
                        <form onSubmit={(e) => { e.preventDefault(); deptForm.post('/hrm/departments', { preserveScroll: true, onSuccess: () => deptForm.reset() }); }}>
                            <div className="form-grid" style={{ marginBottom: 10 }}>
                                <div className="fld"><label className="lbl">{t('Department name')}</label>
                                    <input className="inp" value={deptForm.data.name} onChange={(e) => deptForm.setData('name', e.target.value)} placeholder={t('e.g. Delivery Team')} required /></div>
                                <div className="fld"><label className="lbl">{t('Head (the person in charge)')}</label>
                                    <select className="inp" value={deptForm.data.head_user_id} onChange={(e) => deptForm.setData('head_user_id', e.target.value)}>
                                        <option value="">{t('— No one —')}</option>
                                        {staff_options.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                    </select></div>
                            </div>
                            <label className="lbl">{t('Staff members')}</label>
                            <MemberPicker options={staff_options} selected={deptForm.data.member_ids} onToggle={(id) => deptForm.setData('member_ids', toggleIn(deptForm.data.member_ids, id))} />
                            <button className="btn-p" type="submit" disabled={deptForm.processing} style={{ marginTop: 10 }}>{t('Add Department')}</button>
                        </form>
                    </div>
                )}

                <div className="x-head" style={{ marginTop: 22 }}>
                    <h3>{t('SOP — work rules')}</h3>
                    <p>{t('How every job is done, written step by step. A new staff member reads this and understands the work.')}</p>
                </div>

                {sops.map((s) => <SopCard key={s.id} sop={s} departments={departments} canManage={can_manage} />)}

                {can_manage && (
                    <div className="x-card">
                        <h4>{t('New SOP')}</h4>
                        <form onSubmit={(e) => { e.preventDefault(); sopForm.post('/hrm/sops', { preserveScroll: true, onSuccess: () => sopForm.reset() }); }}>
                            <div className="form-grid" style={{ marginBottom: 10 }}>
                                <div className="fld"><label className="lbl">{t('SOP name')}</label>
                                    <input className="inp" value={sopForm.data.title} onChange={(e) => sopForm.setData('title', e.target.value)} placeholder={t('e.g. Rules for giving a refund')} required /></div>
                                <div className="fld"><label className="lbl">{t('Department')}</label>
                                    <select className="inp" value={sopForm.data.department_id} onChange={(e) => sopForm.setData('department_id', e.target.value)}>
                                        <option value="">{t('All Departments')}</option>
                                        {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                                    </select></div>
                            </div>
                            <div className="fld" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                                <label className="lbl">{t('Steps (one step per line)')}</label>
                                <textarea className="inp" rows={4} value={sopForm.data.steps_text} onChange={(e) => sopForm.setData('steps_text', e.target.value)} required />
                            </div>
                            <button className="btn-p" type="submit" disabled={sopForm.processing} style={{ marginTop: 8 }}>{t('Add SOP')}</button>
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
