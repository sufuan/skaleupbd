import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { confirmDialog } from '@/lib/confirm';
import AppLayout from '@/Layouts/AppLayout';

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
    const del = async () => { if (await confirmDialog('এই Department মুছে ফেলতে চান?')) router.delete(`/hrm/departments/${dept.id}`, { preserveScroll: true }); };
    return (
        <div className="x-card">
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                <h4 style={{ margin: 0 }}>{dept.name}</h4>
                {canManage && (
                    <div className="row-actions">
                        <button type="button" className="btn-mini" onClick={() => setEditing(!editing)}>Edit</button>
                        <button type="button" className="btn-mini danger" onClick={del}>মুছুন</button>
                    </div>
                )}
            </div>
            <p style={{ fontSize: 13.5, color: '#64748B', margin: '8px 0' }}>
                Head: <b style={{ color: '#1E293B' }}>{dept.head ? dept.head.name : 'ঠিক করা হয়নি'}</b> · কর্মী {dept.members.length} জন
            </p>
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {dept.members.map((m) => <span key={m.id} className="chip chip-grey">{m.name}</span>)}
            </div>
            {editing && (
                <form style={{ marginTop: 14 }} onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/departments/${dept.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }}>
                    <div className="form-grid" style={{ marginBottom: 10 }}>
                        <div className="fld"><label className="lbl">Department-এর নাম</label>
                            <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                        <div className="fld"><label className="lbl">Head (দায়িত্বে যিনি)</label>
                            <select className="inp" value={form.data.head_user_id} onChange={(e) => form.setData('head_user_id', e.target.value)}>
                                <option value="">— কেউ না —</option>
                                {staffOptions.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select></div>
                    </div>
                    <label className="lbl">কর্মীরা</label>
                    <MemberPicker options={staffOptions} selected={form.data.member_ids} onToggle={(id) => form.setData('member_ids', toggleIn(form.data.member_ids, id))} />
                    <p className="x-note">একজন কর্মী একটাই Department-এ থাকে — অন্য Department থেকে এখানে আনলে সে সেখান থেকে সরে আসবে।</p>
                    <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 8 }}>সেভ করো</button>
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
    const del = async () => { if (await confirmDialog('এই SOP মুছে ফেলতে চান?')) router.delete(`/hrm/sops/${sop.id}`, { preserveScroll: true }); };
    return (
        <div className="x-card">
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                <h4 style={{ margin: 0 }}>{sop.title}</h4>
                <div className="row-actions">
                    <button type="button" className="btn-mini" onClick={() => setOpen(!open)}>{open ? 'ধাপ লুকাও' : `ধাপ দেখো (${sop.steps.length})`}</button>
                    {canManage && <button type="button" className="btn-mini" onClick={() => setEditing(!editing)}>Edit</button>}
                    {canManage && <button type="button" className="btn-mini danger" onClick={del}>মুছুন</button>}
                </div>
            </div>
            <p style={{ fontSize: 13, color: '#64748B', margin: '6px 0 0' }}>{sop.department_name || 'সব Department'}</p>
            {open && (
                <ol style={{ margin: '10px 0 0', paddingLeft: 20, fontSize: 13.5, lineHeight: 1.9 }}>
                    {sop.steps.map((s, i) => <li key={i}>{s}</li>)}
                </ol>
            )}
            {editing && (
                <form style={{ marginTop: 12 }} onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/sops/${sop.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }}>
                    <div className="form-grid" style={{ marginBottom: 10 }}>
                        <div className="fld"><label className="lbl">SOP-এর নাম</label>
                            <input className="inp" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required /></div>
                        <div className="fld"><label className="lbl">Department</label>
                            <select className="inp" value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)}>
                                <option value="">সব Department</option>
                                {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                            </select></div>
                    </div>
                    <div className="fld" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                        <label className="lbl">ধাপগুলো (এক লাইনে এক ধাপ)</label>
                        <textarea className="inp" rows={5} value={form.data.steps_text} onChange={(e) => form.setData('steps_text', e.target.value)} required />
                    </div>
                    <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 8 }}>সেভ করো</button>
                </form>
            )}
        </div>
    );
}

export default function Departments({ departments, sops, staff_options, can_manage }) {
    const deptForm = useForm({ name: '', head_user_id: '', member_ids: [] });
    const sopForm = useForm({ title: '', department_id: '', steps_text: '' });
    return (
        <AppLayout title="Departments & SOP">
            <Head title="Departments & SOP" />
            <div className="hrm">
                <div className="x-head">
                    <h3>Departments & SOP</h3>
                    <p>কোন দলে কে কাজ করে, আর প্রতিটা কাজের নিয়ম (SOP) — সব এখানে।</p>
                </div>

                {departments.map((d) => <DeptCard key={d.id} dept={d} staffOptions={staff_options} canManage={can_manage} />)}

                {can_manage && (
                    <div className="x-card">
                        <h4>নতুন Department</h4>
                        <form onSubmit={(e) => { e.preventDefault(); deptForm.post('/hrm/departments', { preserveScroll: true, onSuccess: () => deptForm.reset() }); }}>
                            <div className="form-grid" style={{ marginBottom: 10 }}>
                                <div className="fld"><label className="lbl">Department-এর নাম</label>
                                    <input className="inp" value={deptForm.data.name} onChange={(e) => deptForm.setData('name', e.target.value)} placeholder="যেমন: Delivery Team" required /></div>
                                <div className="fld"><label className="lbl">Head (দায়িত্বে যিনি)</label>
                                    <select className="inp" value={deptForm.data.head_user_id} onChange={(e) => deptForm.setData('head_user_id', e.target.value)}>
                                        <option value="">— কেউ না —</option>
                                        {staff_options.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                    </select></div>
                            </div>
                            <label className="lbl">কর্মীরা</label>
                            <MemberPicker options={staff_options} selected={deptForm.data.member_ids} onToggle={(id) => deptForm.setData('member_ids', toggleIn(deptForm.data.member_ids, id))} />
                            <button className="btn-p" type="submit" disabled={deptForm.processing} style={{ marginTop: 10 }}>Department যোগ করো</button>
                        </form>
                    </div>
                )}

                <div className="x-head" style={{ marginTop: 22 }}>
                    <h3>SOP — কাজের নিয়ম</h3>
                    <p>প্রতিটা কাজ কীভাবে হবে, ধাপে ধাপে লেখা। নতুন কর্মী এটা পড়লেই কাজ বুঝে যাবে।</p>
                </div>

                {sops.map((s) => <SopCard key={s.id} sop={s} departments={departments} canManage={can_manage} />)}

                {can_manage && (
                    <div className="x-card">
                        <h4>নতুন SOP</h4>
                        <form onSubmit={(e) => { e.preventDefault(); sopForm.post('/hrm/sops', { preserveScroll: true, onSuccess: () => sopForm.reset() }); }}>
                            <div className="form-grid" style={{ marginBottom: 10 }}>
                                <div className="fld"><label className="lbl">SOP-এর নাম</label>
                                    <input className="inp" value={sopForm.data.title} onChange={(e) => sopForm.setData('title', e.target.value)} placeholder="যেমন: Refund দেওয়ার নিয়ম" required /></div>
                                <div className="fld"><label className="lbl">Department</label>
                                    <select className="inp" value={sopForm.data.department_id} onChange={(e) => sopForm.setData('department_id', e.target.value)}>
                                        <option value="">সব Department</option>
                                        {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                                    </select></div>
                            </div>
                            <div className="fld" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                                <label className="lbl">ধাপগুলো (এক লাইনে এক ধাপ)</label>
                                <textarea className="inp" rows={4} value={sopForm.data.steps_text} onChange={(e) => sopForm.setData('steps_text', e.target.value)} required />
                            </div>
                            <button className="btn-p" type="submit" disabled={sopForm.processing} style={{ marginTop: 8 }}>SOP যোগ করো</button>
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
