import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const tk = (n) => `৳${Number(n || 0).toLocaleString('en-IN')}`;

export default function CustomerShow({ customer, orders }) {
    const form = useForm({ name: customer.name || '', address: customer.address || '', district: customer.district || '', area: customer.area || '', email: customer.email || '' });
    const save = (e) => { e.preventDefault(); form.patch(`/customers/${customer.id}`, { preserveScroll: true }); };

    return (
        <AppLayout>
            <Head title={customer.name || t('Customer')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <Link href="/customers" className="btn-ghost">← {t('Customer List')}</Link>
                <h3 style={{ fontSize: 19 }}>{customer.name || '—'}{customer.blacklisted ? <span className="pill red" style={{ marginLeft: 8 }}>{t('Blocked')}</span> : null}</h3>
                <span style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                    <button className="btn-ghost" onClick={() => router.post(`/customers/${customer.id}/blacklist`, {}, { preserveScroll: true })}>{customer.blacklisted ? t('Unblock') : t('Block customer')}</button>
                    <button className="btn-ghost" style={{ color: '#B91C1C' }} onClick={() => { if (confirm(t('Delete this customer?'))) router.delete(`/customers/${customer.id}`); }}>{t('Delete')}</button>
                </span>
            </div>

            <div className="cust-kpis" style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 12, margin: '14px 0' }}>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('TOTAL ORDERS')}</div><div style={{ fontSize: 24, fontWeight: 700, marginTop: 4 }}>{customer.orders}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('DELIVERED')}</div><div style={{ fontSize: 24, fontWeight: 700, marginTop: 4 }}>{customer.delivered}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{customer.success_rate === null ? '' : `${customer.success_rate}% ${t('success')}`}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('TOTAL SPENT')}</div><div style={{ fontSize: 24, fontWeight: 700, marginTop: 4 }}>{tk(customer.spent)}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('CUSTOMER SINCE')}</div><div style={{ fontSize: 24, fontWeight: 700, marginTop: 4 }}>{customer.since}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{t('Last order')}: {customer.last_order || '—'}</div></div>
            </div>

            <div className="cust-2col" style={{ display: 'grid', gridTemplateColumns: '340px 1fr', gap: 12, alignItems: 'start' }}>
                <div className="card">
                    <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Customer details')}</h4>
                    <div style={{ fontSize: 13, marginBottom: 12 }}><div style={{ color: 'var(--muted)' }}>{t('Phone')}</div><div style={{ fontWeight: 600 }}>{customer.phone}</div></div>
                    <form onSubmit={save}>
                        <label className="lbl">{t('Name')}</label>
                        <input className="fld" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        <label className="lbl">{t('Email')}</label>
                        <input className="fld" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        <label className="lbl">{t('Address')}</label>
                        <input className="fld" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                        <label className="lbl">{t('District')}</label>
                        <input className="fld" value={form.data.district} onChange={(e) => form.setData('district', e.target.value)} />
                        <label className="lbl">{t('Area')}</label>
                        <input className="fld" value={form.data.area} onChange={(e) => form.setData('area', e.target.value)} />
                        <button className="btn-primary" disabled={form.processing} style={{ marginTop: 14 }}>{t('Save')}</button>
                    </form>
                </div>
                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Order history')}</h4>
                    <div className="tbl-wrap">
                        <table className="tbl">
                            <thead><tr><th>{t('Invoice')}</th><th>{t('Date')}</th><th>{t('Status')}</th><th>{t('Total')}</th></tr></thead>
                            <tbody>
                                {orders.map((o) => (
                                    <tr key={o.id} onClick={() => router.get(`/orders/${o.id}`)} style={{ cursor: 'pointer' }}>
                                        <td style={{ fontWeight: 600 }}>{o.invoice}</td><td>{o.date}</td><td>{o.status}</td><td style={{ fontWeight: 600 }}>{tk(o.total)}</td>
                                    </tr>
                                ))}
                                {orders.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('No orders yet')}</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
