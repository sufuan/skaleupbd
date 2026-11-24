import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { tk } from '@/lib/money';


export default function Sellers({ sellers, q, pendingApprovals }) {
    const [search, setSearch] = useState(q || '');
    const submit = (e) => { e.preventDefault(); router.get('/admin/sellers', { q: search }, { preserveState: true }); };
    const pill = (s) => s.status === 'active' ? <span className="pill green">{t('Active')}</span> : s.status === 'expired' ? <span className="pill red">{t('Expired')}</span> : s.status === 'cancelled' ? <span className="pill">{t('Cancelled')}</span> : <span className="pill amber">{t('No package')}</span>;
    const approvalPill = (s) => s.approval_status === 'pending' ? <span className="pill amber">{t('Pending approval')}</span> : s.approval_status === 'rejected' ? <span className="pill red">{t('Rejected')}</span> : <span className="pill green">{t('Approved')}</span>;

    return (
        <AppLayout>
            <Head title={t('Sellers')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <h3 style={{ fontSize: 19 }}>{t('Sellers')}</h3>
                <span style={{ marginLeft: 'auto', flex: '0 1 340px', width: '100%', maxWidth: 340 }}>
                    <form onSubmit={submit} style={{ width: '100%' }}>
                        <input className="fld" style={{ width: '100%' }} placeholder={t('Search shop, owner or phone...')} value={search} onChange={(e) => setSearch(e.target.value)} />
                    </form>
                </span>
            </div>
            <div style={{ fontSize: 13, color: 'var(--muted)', margin: '6px 0 12px' }}>{t('These are the shops that buy the SkaleUp service - our customers.')}</div>
            {pendingApprovals > 0 && (
                <div style={{ background: '#FFF7E6', border: '1px solid #F0D9A8', borderRadius: 10, padding: '10px 14px', fontSize: 14, marginBottom: 12 }}>
                    <b>{pendingApprovals}</b> {t('seller(s) are waiting for approval. Open a seller and use Approve or Reject.')}
                </div>
            )}

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead><tr><th>{t('Seller (Shop)')}</th><th>{t('Owner / Contact')}</th><th>{t('Package')}</th><th>{t('Points')}</th><th>{t('Status')}</th><th>{t('Approval')}</th><th>{t('Paid to us')}</th><th>{t('Orders')}</th><th>{t('Booked order value')}</th><th>{t('Customers')}</th><th></th></tr></thead>
                        <tbody>
                            {sellers.map((s) => (
                                <tr key={s.id}>
                                    <td><div style={{ fontWeight: 600 }}>{s.name}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{t('Joined')} {s.joined}</div></td>
                                    <td><div>{s.owner_name || '-'}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{s.phone || ''}</div></td>
                                    <td>{s.token_pack ? <><div style={{ fontWeight: 600 }}>{s.token_pack}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{s.token_until ? `${t('until')} ${s.token_until}` : ''}</div></> : '-'}</td>
                                    <td style={{ fontWeight: 700 }}>{Number(s.token_balance || 0).toLocaleString('en-IN')}</td>
                                    <td>{pill(s)}</td>
                                    <td>{approvalPill(s)}</td>
                                    <td style={{ fontWeight: 600 }}>{tk(s.paid_total)}</td>
                                    <td>{s.orders}</td>
                                    <td style={{ fontWeight: 600 }}>{tk(s.revenue)}</td>
                                    <td>{s.customers}</td>
                                    <td style={{ whiteSpace: 'nowrap' }}><Link className="btn-ghost" href={`/admin/sellers/${s.id}`}>{t('View')}</Link> <button type="button" className="btn-ghost" onClick={() => router.post(`/admin/sellers/${s.id}/view`)}>{t('View shop')}</button></td>
                                </tr>
                            ))}
                            {sellers.length === 0 && <tr><td colSpan={11} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('No sellers found')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
