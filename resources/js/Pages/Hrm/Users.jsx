import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { fmt } from './parts';
import { t } from '@/lib/i18n';

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
                    {user.uses_default ? t(":role's default — ", { role: user.role_label }) : ''}{labels.join(', ') || '—'}
                </td>
                <td>{user.monthly_salary ? fmt(user.monthly_salary) : '—'}</td>
                <td>{user.staff_active ? <span className="chip chip-green">{t('On')}</span> : <span className="chip chip-grey">{t('Off')}</span>}</td>
                <td>
                    {canManage && (
                        <button type="button" className="btn-mini" onClick={() => setEditing(!editing)}>{t('Edit')}</button>
                    )}
                </td>
            </tr>
            {editing && (
                <tr>
                    <td colSpan={6} style={{ background: '#FAF8FC' }}>
                        <form onSubmit={(e) => { e.preventDefault(); form.patch(`/hrm/users/${user.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }}>
                            <div className="form-grid" style={{ marginBottom: 10 }}>
                                <div className="fld"><label className="lbl">{t('Name')}</label>
                                    <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                                <div className="fld"><label className="lbl">{t('Role')}</label>
                                    <select className="inp" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                        {Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </select></div>
                                <div className="fld"><label className="lbl">{t('Monthly salary (৳)')}</label>
                                    <input className="inp" type="number" min="0" step="any" value={form.data.monthly_salary} onChange={(e) => form.setData('monthly_salary', e.target.value)} placeholder="0" /></div>
                                <div className="fld"><label className="lbl">{t('Status')}</label>
                                    <select className="inp" value={form.data.staff_active ? '1' : '0'} onChange={(e) => form.setData('staff_active', e.target.value === '1')}>
                                        <option value="1">{t('On')}</option>
                                        <option value="0">{t('Off')}</option>
                                    </select></div>
                            </div>
                            <label style={{ display: 'flex', gap: 7, alignItems: 'center', fontSize: 13.5, marginBottom: 8 }}>
                                <input type="checkbox" checked={form.data.use_default} onChange={(e) => form.setData('use_default', e.target.checked)} />
                                {t('Use the default permissions of the Role')}
                            </label>
                            {!form.data.use_default && (
                                <>
                                    <label className="lbl">{t('What they will be able to see')}</label>
                                    <div className="perm-grid">
                                        {Object.entries(modules).map(([key, label]) => (
                                            <label key={key}>
                                                <input type="checkbox" checked={form.data.permissions.includes(key)} onChange={() => togglePerm(key)} /> {label}
                                            </label>
                                        ))}
                                    </div>
                                </>
                            )}
                            <button className="btn-p" type="submit" disabled={form.processing} style={{ marginTop: 10 }}>{t('Save')}</button>
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
        <AppLayout title={t('User & Role')}>
            <Head title={t('User & Role')} />
            <div className="hrm">
                <div className="x-head">
                    <h3>{t('User & Role')}</h3>
                    <p>{t('Who will have a login and which pages they can see — everything is set from here. Without permission, that page does not appear in the menu, and opening the direct link does not let them in either.')}</p>
                </div>

                <div className="x-card" style={{ padding: 0, overflow: 'hidden' }}>
                    <table className="x-table" style={{ border: 'none' }}>
                        <thead>
                            <tr><th>{t('Name')}</th><th>{t('Role')}</th><th>{t('What they can see')}</th><th>{t('Monthly salary')}</th><th>{t('Status')}</th><th></th></tr>
                        </thead>
                        <tbody>
                            {users.map((u) => <UserRow key={u.id} user={u} modules={modules} roles={roles} canManage={can_manage} />)}
                        </tbody>
                    </table>
                </div>
                <p className="x-note">{t('Finance rule: Admin and Accountant can see all the accounts; the rest can only add entries. Changing the Role changes this rule on its own. A User cannot be deleted — just set the status to "Off" and they stay out of the working list.')}</p>

                {can_manage && (
                    <div className="x-card">
                        <h4>{t('New User')}</h4>
                        <form onSubmit={(e) => { e.preventDefault(); form.post('/hrm/users', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                            <div className="form-grid">
                                <div className="fld"><label className="lbl">{t('Name')}</label>
                                    <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
                                <div className="fld"><label className="lbl">{t('Email (login)')}</label>
                                    <input className="inp" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /></div>
                                <div className="fld"><label className="lbl">{t('Password')}</label>
                                    <input className="inp" type="text" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required /></div>
                                <div className="fld"><label className="lbl">{t('Role')}</label>
                                    <select className="inp" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                        {Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </select></div>
                                <div className="fld"><label className="lbl">{t('Monthly salary (৳)')}</label>
                                    <input className="inp" type="number" min="0" step="any" value={form.data.monthly_salary} onChange={(e) => form.setData('monthly_salary', e.target.value)} placeholder="0" /></div>
                                <button className="btn-p" type="submit" disabled={form.processing}>{t('Add User')}</button>
                            </div>
                            <p className="x-note">{t('A new User gets the default permissions of their Role — later they can be raised or lowered with Edit.')}</p>
                            {form.errors.email && <p style={{ color: '#DC2626', fontSize: 13 }}>{form.errors.email}</p>}
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
