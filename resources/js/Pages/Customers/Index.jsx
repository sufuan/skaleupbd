import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const tk = (n) => `৳${Number(n || 0).toLocaleString('en-IN')}`;

export default function CustomersIndex({ customers, stats, q }) {
    const [search, setSearch] = useState(q || '');
    const [addOpen, setAddOpen] = useState(false);
    const form = useForm({ name: '', phone: '', address: '', district: '' });

    const submitSearch = (e) => { e.preventDefault(); router.get('/customers', { q: search }, { preserveState: true }); };
    const add = (e) => { e.preventDefault(); form.post('/customers', { onSuccess: () => { setAddOpen(false); form.reset(); } }); };

    return (
        <AppLayout>
            <Head title={t('Customer List')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <h3 style={{ fontSize: 19 }}>{t('Customer List')}</h3>
                <span style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                    <form onSubmit={submitSearch}>
                        <input className="fld" style={{ maxWidth: 230 }} placeholder={t('Search name or phone...')} value={search} onChange={(e) => setSearch(e.target.value)} />
                    </form>
                    <button className="btn-primary" onClick={() => setAddOpen(true)}>{t('Add Customer')}</button>
                </span>
            </div>

            <div className="cust-kpis" style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12, margin: '14px 0' }}>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('TOTAL CUSTOMERS')}</div><div style={{ fontSize: 24, fontWeight: 700, marginTop: 4 }}>{stats.total}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{t('Your shop customers')}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('AVERAGE ORDER VALUE')}</div><div style={{ fontSize: 24, fontWeight: 700, marginTop: 4 }}>{tk(stats.aov)}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{t('Per order, cancelled excluded')}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('CUSTOMER LIFETIME VALUE')}</div><div style={{ fontSize: 24, fontWeight: 700, marginTop: 4 }}>{tk(stats.clv)}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{t('Average per customer')}</div></div>
            </div>

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead><tr><th>{t('Customer')}</th><th>{t('Phone')}</th><th>{t('Orders')}</th><th>{t('Success Rate')}</th><th>{t('Total Spent')}</th><th>{t('Last Order')}</th></tr></thead>
                        <tbody>
                            {customers.map((c) => (
                                <tr key={c.id} onClick={() => router.get(`/customers/${c.id}`)} style={{ cursor: 'pointer' }}>
                                    <td><div style={{ fontWeight: 600 }}>{c.name || '—'}{c.blacklisted ? <span className="pill red" style={{ marginLeft: 6 }}>{t('Blocked')}</span> : null}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{c.address || c.district || ''}</div></td>
                                    <td>{c.phone}</td>
                                    <td>{c.delivered} / {c.orders}</td>
                                    <td>{c.success_rate === null ? '—' : `${c.success_rate}%`}</td>
                                    <td style={{ fontWeight: 600 }}>{tk(c.spent)}</td>
                                    <td>{c.last_order || '—'}</td>
                                </tr>
                            ))}
                            {customers.length === 0 && <tr><td colSpan={6} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('No customers found')}</td></tr>}
                        </tbody>
                    </table>
                </div>
                <div style={{ padding: '10px 14px', fontSize: 12, color: 'var(--muted)' }}>{t('Total')} {customers.length} {t('customers')}</div>
            </div>

            {addOpen && (
                <div className="modal-overlay" onClick={() => setAddOpen(false)}>
                    <div className="modal" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 440 }}>
                        <h3 style={{ fontSize: 17, marginBottom: 14 }}>{t('Add Customer')}</h3>
                        <form onSubmit={add}>
                            <label className="lbl">{t('Name')}</label>
                            <input className="fld" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            <label className="lbl">{t('Phone')}</label>
                            <input className="fld" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} required />
                            <label className="lbl">{t('Address')}</label>
                            <input className="fld" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                            <label className="lbl">{t('District')}</label>
                            <input className="fld" value={form.data.district} onChange={(e) => form.setData('district', e.target.value)} />
                            <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
                                <button type="button" className="btn-ghost" onClick={() => setAddOpen(false)}>{t('Cancel')}</button>
                                <button className="btn-primary" disabled={form.processing || !form.data.name.trim() || !form.data.phone.trim()} style={{ marginLeft: 'auto' }}>{t('Save')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
