import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { t } from '@/lib/i18n';
import { confirmDialog } from '@/lib/confirm';

const ACTION_LABELS = { view: 'View', edit: 'Create and edit', delete: 'Delete', export: 'Export' };

function TickGrid({ modules, actions, ticks, onToggle }) {
    return (
        <div style={{ overflowX: 'auto' }}>
            <table className="x-table" style={{ minWidth: 560 }}>
                <thead>
                    <tr>
                        <th>{t('Module')}</th>
                        {actions.map((a) => <th key={a} style={{ textAlign: 'center' }}>{t(ACTION_LABELS[a])}</th>)}
                    </tr>
                </thead>
                <tbody>
                    {modules.map((m) => (
                        <tr key={m.key}>
                            <td>{t(m.label)}</td>
                            {actions.map((a) => (
                                <td key={a} style={{ textAlign: 'center' }}>
                                    {m.owner_only
                                        ? <span style={{ fontSize: 12, color: '#94A3B8' }}>{t('Owner only')}</span>
                                        : <input type="checkbox" checked={!!ticks?.[m.key]?.[a]} onChange={() => onToggle(m.key, a)} />}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function emptyTicks(modules) {
    const out = {};
    modules.forEach((m) => { if (!m.owner_only) out[m.key] = { view: false, edit: false, delete: false, export: false }; });
    return out;
}

function RoleForm({ modules, actions, role = null, onDone }) {
    const form = useForm({
        name: role?.name || '',
        ticks: role ? { ...emptyTicks(modules), ...role.ticks } : emptyTicks(modules),
    });
    const toggle = (key, action) => {
        const next = { ...form.data.ticks, [key]: { ...form.data.ticks[key], [action]: !form.data.ticks[key]?.[action] } };
        form.setData('ticks', next);
    };
    const save = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { form.reset(); onDone && onDone(); } };
        if (role) form.patch(`/settings/staff-roles/${role.id}`, opts);
        else form.post('/settings/staff-roles', opts);
    };
    return (
        <form onSubmit={save}>
            <div className="frow">
                <label className="fl">{t('Role name')}</label>
                <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required placeholder={t('e.g. Delivery Manager')} />
            </div>
            {form.errors.name && <p style={{ color: '#DC2626', fontSize: 13 }}>{form.errors.name}</p>}
            <div style={{ height: 10 }} />
            <TickGrid modules={modules} actions={actions} ticks={form.data.ticks} onToggle={toggle} />
            <p className="sm mut" style={{ marginTop: 8 }}>{t('Points & Billing and Settings stay with the owner only - they cannot be given to any role. A change here applies at once, from the next page the staff member opens.')}</p>
            <div className="flex mt16"><span className="spacer" />
                <button className="btn pri" disabled={form.processing || !form.data.name.trim()}>{role ? t('Save') : t('Add Role')}</button>
            </div>
        </form>
    );
}

function StaffRow({ person, roles, moduleLabels }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        name: person.name,
        role_id: person.role_id || '',
        monthly_salary: person.monthly_salary || '',
        staff_active: person.staff_active,
    });
    const save = (e) => {
        e.preventDefault();
        form.patch(`/settings/staff/${person.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };
    const seen = person.view_modules.map((k) => moduleLabels[k]).filter(Boolean);
    return (
        <>
            <tr>
                <td>
                    <b>{person.name}</b>
                    <div style={{ fontSize: 12, color: '#94A3B8' }}>{person.email}</div>
                </td>
                <td>{person.is_owner ? t('Owner') : person.role_name}</td>
                <td style={{ maxWidth: 300, fontSize: 12.5, color: '#64748B' }}>{seen.join(', ') || '-'}</td>
                <td>{person.monthly_salary ? `৳${Number(person.monthly_salary).toLocaleString('en-IN')}` : '-'}</td>
                <td>{person.staff_active
                    ? <span className="pill green">{t('On')}</span>
                    : <span className="pill">{t('Off')}</span>}</td>
                <td><button type="button" className="btn" onClick={() => setEditing(!editing)}>{t('Edit')}</button></td>
            </tr>
            {editing && (
                <tr>
                    <td colSpan={6} style={{ background: '#FAF8FC' }}>
                        <form onSubmit={save}>
                            <div className="frow">
                                <label className="fl">{t('Name')}</label>
                                <input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            </div>
                            {!person.is_owner && (
                                <div className="frow">
                                    <label className="fl">{t('Role')}</label>
                                    <select className="inp" value={form.data.role_id} onChange={(e) => form.setData('role_id', e.target.value)}>
                                        <option value="">{t('No role (Dashboard only)')}</option>
                                        {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                                    </select>
                                </div>
                            )}
                            <div className="frow">
                                <label className="fl">{t('Monthly salary (৳)')}</label>
                                <input className="inp" type="number" min="0" step="any" value={form.data.monthly_salary} onChange={(e) => form.setData('monthly_salary', e.target.value)} placeholder="0" />
                            </div>
                            <div className="frow">
                                <label className="fl">{t('Status')}</label>
                                <select className="inp" value={form.data.staff_active ? '1' : '0'} onChange={(e) => form.setData('staff_active', e.target.value === '1')}>
                                    <option value="1">{t('On')}</option>
                                    <option value="0">{t('Off')}</option>
                                </select>
                            </div>
                            {person.is_owner && <p className="sm mut">{t('The owner always has every permission. Only the name and salary can be changed here.')}</p>}
                            {!person.is_owner && <p className="sm mut">{t('Setting the status to Off logs this staff member out at once, on every device.')}</p>}
                            <div className="flex mt16"><span className="spacer" />
                                <button className="btn pri" disabled={form.processing}>{t('Save')}</button>
                            </div>
                        </form>
                    </td>
                </tr>
            )}
        </>
    );
}

export default function StaffRoles({ data }) {
    const { roles, staff, modules, actions } = data;
    const [editingRole, setEditingRole] = useState(null);
    const [showNewRole, setShowNewRole] = useState(false);
    const moduleLabels = {};
    modules.forEach((m) => { moduleLabels[m.key] = t(m.label); });

    const newStaff = useForm({ name: '', email: '', password: '', role_id: '', monthly_salary: '' });
    const addStaff = (e) => {
        e.preventDefault();
        newStaff.post('/settings/staff', { preserveScroll: true, onSuccess: () => newStaff.reset() });
    };
    const removeRole = async (role) => {
        if (await confirmDialog(t('Delete this role? Staff who have it must be moved to another role first.'))) {
            router.delete(`/settings/staff-roles/${role.id}`, { preserveScroll: true });
        }
    };

    return (
        <div>
            <div className="card">
                <h4 style={{ fontSize: 15, marginBottom: 4 }}>{t('Staff & Roles')}</h4>
                <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 10 }}>
                    {t('Who will have a login in your shop and what each person can do - everything is set from here. Make a role once, give it to as many staff as you like. Without the View tick, that page does not appear in their menu, and opening the direct link does not let them in either.')}
                </div>
                <div style={{ overflowX: 'auto' }}>
                    <table className="x-table" style={{ border: 'none', minWidth: 640 }}>
                        <thead>
                            <tr>
                                <th style={{ width: 210 }}>{t('Name')}</th>
                                <th style={{ width: 150 }}>{t('Role')}</th>
                                <th>{t('What they can see')}</th>
                                <th style={{ width: 115 }}>{t('Monthly salary')}</th>
                                <th style={{ width: 80 }}>{t('Status')}</th>
                                <th style={{ width: 90 }}></th>
                            </tr>
                        </thead>
                        <tbody>
                            {staff.map((p) => <StaffRow key={p.id} person={p} roles={roles} moduleLabels={moduleLabels} />)}
                        </tbody>
                    </table>
                </div>
                <p className="sm mut" style={{ marginTop: 8 }}>{t('A staff member is never deleted - set the status to Off and they are logged out at once and stay out of the working list. Finance rule: a role that can view and edit Finance sees all the accounts; the rest can only add entries.')}</p>
            </div>

            <div className="card">
                <h4 style={{ fontSize: 15, marginBottom: 4 }}>{t('New Staff')}</h4>
                <form onSubmit={addStaff}>
                    <div className="frow">
                        <label className="fl">{t('Name')}</label>
                        <input className="inp" value={newStaff.data.name} onChange={(e) => newStaff.setData('name', e.target.value)} required />
                    </div>
                    <div className="frow">
                        <label className="fl">{t('Email (login)')}</label>
                        <input className="inp" type="email" value={newStaff.data.email} onChange={(e) => newStaff.setData('email', e.target.value)} required />
                    </div>
                    <div className="frow">
                        <label className="fl">{t('Password')}</label>
                        <input className="inp" type="text" value={newStaff.data.password} onChange={(e) => newStaff.setData('password', e.target.value)} required />
                    </div>
                    <div className="frow">
                        <label className="fl">{t('Role')}</label>
                        <select className="inp" value={newStaff.data.role_id} onChange={(e) => newStaff.setData('role_id', e.target.value)}>
                            <option value="">{t('No role (Dashboard only)')}</option>
                            {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                        </select>
                    </div>
                    <div className="frow">
                        <label className="fl">{t('Monthly salary (৳)')}</label>
                        <input className="inp" type="number" min="0" step="any" value={newStaff.data.monthly_salary} onChange={(e) => newStaff.setData('monthly_salary', e.target.value)} placeholder="0" />
                    </div>
                    {newStaff.errors.email && <p style={{ color: '#DC2626', fontSize: 13 }}>{newStaff.errors.email}</p>}
                    <div className="flex mt16"><span className="spacer" />
                        <button className="btn pri" disabled={newStaff.processing || !newStaff.data.name.trim() || !newStaff.data.email.trim() || !newStaff.data.password}>{t('Add Staff')}</button>
                    </div>
                </form>
            </div>

            <div className="card">
                <h4 style={{ fontSize: 15, marginBottom: 4 }}>{t('Roles')}</h4>
                <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 10 }}>
                    {t('A role is a set of ticks: View lets them open the page, Create and edit lets them add and change, Delete lets them remove, Export lets them download. Tick only what the work needs.')}
                </div>
                {roles.length === 0 && <p className="sm mut">{t('No roles yet. Make the first one below.')}</p>}
                {roles.map((r) => (
                    <div key={r.id} style={{ borderTop: '1px solid #EDE9F2', padding: '10px 0' }}>
                        <div className="flex" style={{ alignItems: 'center', gap: 8 }}>
                            <b>{r.name}</b>
                            <span className="sm mut">{r.staff_count} {t('staff')}</span>
                            <span className="spacer" />
                            <button className="btn" onClick={() => setEditingRole(editingRole === r.id ? null : r.id)}>{t('Edit')}</button>
                            <button className="btn" onClick={() => removeRole(r)}>{t('Delete')}</button>
                        </div>
                        <div className="sm mut" style={{ marginTop: 4 }}>
                            {modules.filter((m) => r.ticks?.[m.key]?.view).map((m) => t(m.label)).join(', ') || t('Nothing yet')}
                        </div>
                        {editingRole === r.id && (
                            <div style={{ marginTop: 10 }}>
                                <RoleForm modules={modules} actions={actions} role={r} onDone={() => setEditingRole(null)} />
                            </div>
                        )}
                    </div>
                ))}
                <div className="flex mt16">
                    <span className="spacer" />
                    <button className="btn" onClick={() => setShowNewRole(!showNewRole)}>{t('New Role')}</button>
                </div>
                {showNewRole && <RoleForm modules={modules} actions={actions} onDone={() => setShowNewRole(false)} />}
            </div>
        </div>
    );
}
