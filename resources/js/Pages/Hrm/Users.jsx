import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { fmt } from './parts';

function UserRow({ user, modules, roles, canManage }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        name: user.name,
        role: user.role,
        use_default: user.uses_default,
        permissions: user.permissions,
        monthly_salary: user.monthly_salary || '',
        staff_active: user.staff_active,
    });
    const togglePerm = (key) => {
        const list = form.data.permissions.includes(key)
            ? form.data.permissions.filter((x) => x !== key)
            : [...form.data.permissions, key];
        form.setData('permissions', list);
    };
    const labels = user.permissions.map((k) => modules[k]).filter(Boolean);
    return (
        <>
            <tr>
                <td>
                    <b>{user.name}</b>
                    <div style={{ fontSize: 12, color: '#94A3B8' }}>{user.email}</div>
                </td>
                <td>{user.role_label}</td>
                <td style={{ maxWidth: 320, fontSize: 12.5, color: '#64748B' }}>
                    {user.uses_default ? `${user.role_label}-এর default — ` : ''}{labels.join(', ') || '—'}
                </td>
                <td>{user.monthly_salary ? fmt(user.monthly_salary) : '—'}</td>
                <td>{user.staff_active ? <span className="chip chip-green">চালু</span> : <span className="chip chip-grey">বন্ধ</span>}</td>
                <td>
                    {canManage && (
                        <button type="button" className="btn-mini" onClick={() => setEditing(!editing)}>Edit</button>
                    )}
                </td>
            </tr>
            {editing && (
                <tr>
                    <td colSpan={6} style={{ background: '#FAF8FC' }}>
                        <form onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/users/${user.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }}>
                            <div className="form-grid" style={{ marginBottom: 10 }}>
                                <div className="fld"><label className="lbl">নাম</label>
                                    <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                                <div className="fld"><label className="lbl">Role</label>
                                    <select className="inp" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                        {Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </select></div>
                                <div className="fld"><label className="lbl">মাসিক বেতন (৳)</label>
                                    <input className="inp" type="number" min="0" step="any" value={form.data.monthly_salary} onChange={(e) => form.setData('monthly_salary', e.target.value)} placeholder="0" /></div>
                                <div className="fld"><label className="lbl">অবস্থা</label>
                                    <select className="inp" value={form.data.staff_active ? '1' : '0'} onChange={(e) => form.setData('staff_active', e.target.value === '1')}>
                                        <option value="1">চালু</option>
                                        <option value="0">বন্ধ</option>
                                    </select></div>
                            </div>
                            <label style={{ display: 'flex', gap: 7, alignItems: 'center', fontSize: 13.5, marginBottom: 8 }}>
                                <input type="checkbox" checked={form.data.use_default} onChange={(e) => form.setData('use_default', e.target.checked)} />
                                Role-এর default permission ব্যবহার করো
                            </label>
                            {!form.data.use_default && (
                                <>
                                    <label className="lbl">যা যা দেখতে পারবে</label>
                                    <div className="perm-grid">
                                        {Object.entries(modules).map(([key, label]) => (
                                            <label key={key}>
                                                <input type="checkbox" checked={form.data.permissions.includes(key)} onChange={() => togglePerm(key)} /> {label}
                                            </label>
                                        ))}
                                    </div>
                                </>
                            )}
                            <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 10 }}>সেভ করো</button>
                        </form>
                    </td>
                </tr>
            )}
        </>
    );
}

export default function Users({ users, modules, roles, can_manage }) {
    const form = useForm({ name: '', email: '', password: '', role: 'staff', monthly_salary: '' });
    return (
        <AppLayout title="User & Role">
            <Head title="User & Role" />
            <div className="hrm">
                <div className="x-head">
                    <h3>User &amp; Role</h3>
                    <p>কার login থাকবে আর সে কোন কোন page দেখতে পারবে — সব এখান থেকে ঠিক হয়। Permission না থাকলে সেই page menu-তেও আসবে না, সরাসরি link খুললেও ঢুকতে পারবে না।</p>
                </div>

                <div className="x-card" style={{ padding: 0, overflow: 'hidden' }}>
                    <table className="x-table" style={{ border: 'none' }}>
                        <thead>
                            <tr><th>নাম</th><th>Role</th><th>যা যা দেখতে পারে</th><th>মাসিক বেতন</th><th>অবস্থা</th><th></th></tr>
                        </thead>
                        <tbody>
                            {users.map((u) => <UserRow key={u.id} user={u} modules={modules} roles={roles} canManage={can_manage} />)}
                        </tbody>
                    </table>
                </div>
                <p className="x-note">Finance-এর নিয়ম: Admin আর Accountant সব হিসাব দেখতে পারে; বাকিরা শুধু entry দিতে পারে। Role বদলালে এই নিয়ম নিজে থেকে বদলে যায়। User মোছা যায় না — অবস্থা "বন্ধ" করে দিলেই সে আর তালিকায় কাজের মধ্যে থাকে না।</p>

                {can_manage && (
                    <div className="x-card">
                        <h4>নতুন User</h4>
                        <form onSubmit={(e) => { e.preventDefault(); form.post('/hrm/users', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                            <div className="form-grid">
                                <div className="fld"><label className="lbl">নাম</label>
                                    <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                                <div className="fld"><label className="lbl">Email (login)</label>
                                    <input className="inp" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /></div>
                                <div className="fld"><label className="lbl">Password</label>
                                    <input className="inp" type="text" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required /></div>
                                <div className="fld"><label className="lbl">Role</label>
                                    <select className="inp" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                        {Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </select></div>
                                <div className="fld"><label className="lbl">মাসিক বেতন (৳)</label>
                                    <input className="inp" type="number" min="0" step="any" value={form.data.monthly_salary} onChange={(e) => form.setData('monthly_salary', e.target.value)} placeholder="0" /></div>
                                <button className="btn-p" type="submit" disabled={form.processing}>User যোগ করো</button>
                            </div>
                            <p className="x-note">নতুন User তার Role-এর default permission পায় — পরে Edit করে কমানো-বাড়ানো যায়।</p>
                            {form.errors.email && <p style={{ color: '#DC2626', fontSize: 13 }}>{form.errors.email}</p>}
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
