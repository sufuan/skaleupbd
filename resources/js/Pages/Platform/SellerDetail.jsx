import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const tk = (n) => `৳${Number(n || 0).toLocaleString('en-IN')}`;

export default function SellerDetail({ seller, subscriptions, payments, packages, customers }) {
    const profile = useForm({ name: seller.name, owner_name: seller.owner_name || '', phone: seller.phone || '', email: seller.email || '', address: seller.address || '' });
    const sub = useForm({ package_id: packages[0]?.id || '', amount: '', method: 'bKash', note: '' });
    const pay = useForm({ amount: '', method: 'bKash', note: '' });

    const saveProfile = (e) => { e.preventDefault(); profile.patch(`/admin/sellers/${seller.id}`, { preserveScroll: true }); };
    const doSubscribe = (e) => { e.preventDefault(); sub.post(`/admin/sellers/${seller.id}/subscribe`, { preserveScroll: true, onSuccess: () => sub.reset('amount', 'note') }); };
    const doPay = (e) => { e.preventDefault(); pay.post(`/admin/sellers/${seller.id}/payments`, { preserveScroll: true, onSuccess: () => pay.reset() }); };
    const pkgPrice = packages.find((p) => String(p.id) === String(sub.data.package_id))?.price;

    return (
        <AppLayout>
            <Head title={seller.name} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <Link href="/admin/sellers" className="btn-ghost">← {t('Sellers')}</Link>
                <h3 style={{ fontSize: 19 }}>{seller.name}</h3>
                {seller.status === 'active' ? <span className="pill green">{t('Active')}</span> : seller.status === 'expired' ? <span className="pill red">{t('Expired')}</span> : <span className="pill amber">{t('No package')}</span>}
            </div>

            <div className="plat-kpis" style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 12, margin: '14px 0' }}>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('CURRENT PACKAGE')}</div><div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{seller.package || '—'}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{seller.expires_at ? `${t('Expires')} ${seller.expires_at} (${seller.days_left} ${t('days left')})` : ''}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('PAID TO US')}</div><div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{tk(seller.paid_total)}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{t('All time')}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('SHOP SALES')}</div><div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{tk(seller.revenue)}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{seller.orders} {t('orders')}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('SHOP CUSTOMERS')}</div><div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{seller.customers}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{t('People who buy from this shop')}</div></div>
            </div>

            <div className="plat-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, alignItems: 'start' }}>
                <div className="card">
                    <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Seller details')}</h4>
                    <form onSubmit={saveProfile}>
                        <label className="lbl">{t('Shop name')}</label>
                        <input className="fld" value={profile.data.name} onChange={(e) => profile.setData('name', e.target.value)} required />
                        <label className="lbl">{t('Owner name')}</label>
                        <input className="fld" value={profile.data.owner_name} onChange={(e) => profile.setData('owner_name', e.target.value)} />
                        <label className="lbl">{t('Phone')}</label>
                        <input className="fld" value={profile.data.phone} onChange={(e) => profile.setData('phone', e.target.value)} />
                        <label className="lbl">{t('Email')}</label>
                        <input className="fld" value={profile.data.email} onChange={(e) => profile.setData('email', e.target.value)} />
                        <label className="lbl">{t('Address')}</label>
                        <input className="fld" value={profile.data.address} onChange={(e) => profile.setData('address', e.target.value)} />
                        <button className="btn-primary" disabled={profile.processing} style={{ marginTop: 14 }}>{t('Save')}</button>
                    </form>
                </div>

                <div>
                    <div className="card" style={{ marginBottom: 12 }}>
                        <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Give / renew package')}</h4>
                        <form onSubmit={doSubscribe}>
                            <label className="lbl">{t('Package')}</label>
                            <select className="fld" value={sub.data.package_id} onChange={(e) => sub.setData('package_id', e.target.value)}>
                                {packages.map((p) => <option key={p.id} value={p.id}>{p.name} — {tk(p.price)} / {p.duration_days} {t('days')}</option>)}
                            </select>
                            <label className="lbl">{t('Amount received')} ({t('blank = package price')}{pkgPrice ? ` ${tk(pkgPrice)}` : ''})</label>
                            <input className="fld" type="number" min="0" value={sub.data.amount} onChange={(e) => sub.setData('amount', e.target.value)} placeholder={pkgPrice || ''} />
                            <label className="lbl">{t('Payment method')}</label>
                            <select className="fld" value={sub.data.method} onChange={(e) => sub.setData('method', e.target.value)}>
                                <option>bKash</option><option>Nagad</option><option>Bank</option><option>Cash</option>
                            </select>
                            <label className="lbl">{t('Note')}</label>
                            <input className="fld" value={sub.data.note} onChange={(e) => sub.setData('note', e.target.value)} />
                            <button className="btn-primary" disabled={sub.processing} style={{ marginTop: 14 }}>{t('Assign package + record payment')}</button>
                        </form>
                    </div>
                    <div className="card">
                        <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Record a payment')}</h4>
                        <form onSubmit={doPay}>
                            <label className="lbl">{t('Amount')}</label>
                            <input className="fld" type="number" min="1" value={pay.data.amount} onChange={(e) => pay.setData('amount', e.target.value)} required />
                            <label className="lbl">{t('Payment method')}</label>
                            <select className="fld" value={pay.data.method} onChange={(e) => pay.setData('method', e.target.value)}>
                                <option>bKash</option><option>Nagad</option><option>Bank</option><option>Cash</option>
                            </select>
                            <label className="lbl">{t('Note')}</label>
                            <input className="fld" value={pay.data.note} onChange={(e) => pay.setData('note', e.target.value)} />
                            <button className="btn-primary" disabled={pay.processing} style={{ marginTop: 14 }}>{t('Record payment')}</button>
                        </form>
                    </div>
                </div>

                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Package history')}</h4>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('Package')}</th><th>{t('Amount')}</th><th>{t('From')}</th><th>{t('To')}</th><th>{t('Status')}</th></tr></thead>
                        <tbody>
                            {subscriptions.map((s) => <tr key={s.id}><td style={{ fontWeight: 600 }}>{s.package}</td><td>{tk(s.amount)}</td><td>{s.starts_at}</td><td>{s.expires_at}</td><td>{s.status === 'active' ? <span className="pill green">{t('Active')}</span> : <span className="pill">{s.status}</span>}</td></tr>)}
                            {subscriptions.length === 0 && <tr><td colSpan={5} style={{ textAlign: 'center', padding: 20, color: 'var(--muted)' }}>{t('No packages yet')}</td></tr>}
                        </tbody>
                    </table></div>
                </div>

                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Payments from this seller')}</h4>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('When')}</th><th>{t('Amount')}</th><th>{t('Method')}</th><th>{t('Note')}</th></tr></thead>
                        <tbody>
                            {payments.map((p) => <tr key={p.id}><td>{p.when}</td><td style={{ fontWeight: 600 }}>{tk(p.amount)}</td><td>{p.method}</td><td>{p.note || ''}</td></tr>)}
                            {payments.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 20, color: 'var(--muted)' }}>{t('No payments yet')}</td></tr>}
                        </tbody>
                    </table></div>
                </div>
            </div>

            <div className="card" style={{ padding: 0, overflow: 'hidden', marginTop: 12 }}>
                <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t("This seller's customers")}</h4>
                <div className="tbl-wrap"><table className="tbl">
                    <thead><tr><th>{t('Customer')}</th><th>{t('Phone')}</th><th>{t('Orders')}</th><th>{t('Total Spent')}</th><th>{t('Last Order')}</th></tr></thead>
                    <tbody>
                        {customers.map((c) => <tr key={c.id}><td><div style={{ fontWeight: 600 }}>{c.name || '—'}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{c.address || ''}</div></td><td>{c.phone}</td><td>{c.delivered} / {c.orders}</td><td style={{ fontWeight: 600 }}>{tk(c.spent)}</td><td>{c.last_order || '—'}</td></tr>)}
                        {customers.length === 0 && <tr><td colSpan={5} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('This seller has no customers yet')}</td></tr>}
                    </tbody>
                </table></div>
            </div>
        </AppLayout>
    );
}
