import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';
import { tk } from '@/lib/money';

const num = (n) => Number(n || 0).toLocaleString('en-IN');

function ClaimActions({ c }) {
    const reject = useForm({ admin_note: '' });
    const [showReject, setShowReject] = useState(false);
    const approve = async () => {
        // State the balance replacement BEFORE confirming: a monthly
        // pack swaps the whole current balance for the pack points.
        const balNote = c.validity_type === 'monthly'
            ? ` ${t('Their current balance of')} ${num(c.current_balance)} ${t('points will be replaced by the pack points - leftover points do not carry over.')}`
            : ` ${t('Their current balance is')} ${num(c.current_balance)} ${t('points.')}`;
        const msg = (c.seller_pending
            ? `${t('Approve this payment, credit')} ${num(c.pack_credits)} ${t('points and activate this seller? Their account is still pending - this one approval opens it.')}`
            : `${t('Approve this payment and credit')} ${num(c.pack_credits)} ${t('points to this seller?')}`) + balNote;
        if (await confirmDialog(msg)) {
            router.post(`/admin/claims/${c.id}/approve`, {}, { preserveScroll: true });
        }
    };
    const doReject = (e) => { e.preventDefault(); reject.post(`/admin/claims/${c.id}/reject`, { preserveScroll: true }); };
    return (
        <span style={{ display: 'inline-flex', gap: 6, alignItems: 'flex-start', flexWrap: 'wrap' }}>
            <button className="btn pri" onClick={approve}>{c.seller_pending ? t('Approve payment & activate seller') : t('Approve')}</button>
            <button className="btn" style={{ color: '#B91C1C' }} onClick={() => setShowReject((s) => !s)}>{t('Reject')}</button>
            {showReject && (
                <form onSubmit={doReject} style={{ display: 'flex', gap: 6 }}>
                    <input style={{ height: 38, border: '1px solid #D9CDE4', borderRadius: 8, padding: '0 10px', fontSize: 13.5, fontFamily: 'inherit', width: 200 }} placeholder={t('Why? The seller sees this note')} value={reject.data.admin_note} onChange={(e) => reject.setData('admin_note', e.target.value)} required />
                    <button className="btn" style={{ color: '#B91C1C' }} disabled={reject.processing}>{t('Confirm reject')}</button>
                </form>
            )}
        </span>
    );
}

function ApprovalCard({ seller }) {
    const reject = useForm({ status_note: '' });
    const [showReject, setShowReject] = useState(false);
    const approve = async () => {
        if (await confirmDialog(`${t('Approve this seller? Their full dashboard opens right away.')}`)) {
            router.post(`/admin/sellers/${seller.id}/approve`, {}, { preserveScroll: true });
        }
    };
    const doReject = (e) => { e.preventDefault(); reject.post(`/admin/sellers/${seller.id}/reject`, { preserveScroll: true }); };
    const st = seller.approval_status || 'approved';
    return (
        <div className="card" style={{ marginTop: 12, borderColor: st === 'pending' ? '#F0D9A8' : undefined }}>
            <h4 style={{ fontSize: 15, marginBottom: 8 }}>{t('Account approval')}</h4>
            <div style={{ fontSize: 14 }}>
                {st === 'approved' && <><span className="pill green">{t('Approved')}</span>{seller.approved_at && <span style={{ color: 'var(--muted)', marginLeft: 8 }}>{seller.approved_at}</span>}</>}
                {st === 'pending' && <span className="pill amber">{t('Pending approval')}</span>}
                {st === 'rejected' && <><span className="pill red">{t('Rejected')}</span>{seller.status_note && <div style={{ color: 'var(--muted)', marginTop: 6 }}>{seller.status_note}</div>}</>}
                {seller.previously_rejected && <span className="pill red" style={{ marginLeft: 8 }}>{t('Previously rejected')}</span>}
            </div>
            <div style={{ marginTop: 12, display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-start' }}>
                {st !== 'approved' && <button className="btn pri" onClick={approve}>{t('Approve seller')}</button>}
                <button className="btn" style={{ color: '#B91C1C' }} onClick={() => setShowReject((s) => !s)}>{st === 'approved' ? t('Suspend seller') : t('Reject')}</button>
                {showReject && (
                    <form onSubmit={doReject} style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                        <input style={{ height: 38, border: '1px solid #D9CDE4', borderRadius: 8, padding: '0 10px', fontSize: 13.5, fontFamily: 'inherit', width: 260 }} placeholder={t('Why? The seller sees this note')} value={reject.data.status_note} onChange={(e) => reject.setData('status_note', e.target.value)} required />
                        <button className="btn" style={{ color: '#B91C1C' }} disabled={reject.processing}>{st === 'approved' ? t('Confirm suspend') : t('Confirm reject')}</button>
                    </form>
                )}
            </div>
            {st === 'approved' && (
                <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 8 }}>{t('Suspending stops their dashboard, storefront and API until you approve them again.')}</div>
            )}
        </div>
    );
}

export default function SellerDetail({ seller, purchaseHistory = [], payments, packages, customers, wallet, tokenLedger, claims }) {
    const profile = useForm({ name: seller.name, owner_name: seller.owner_name || '', phone: seller.phone || '', email: seller.email || '', address: seller.address || '' });
    const sub = useForm({ package_id: packages[0]?.id || '', amount: '', method: 'bKash', note: '' });
    const pay = useForm({ amount: '', method: 'bKash', note: '' });
    const credit = useForm({ amount: '', note: '' });

    const saveProfile = (e) => { e.preventDefault(); profile.patch(`/admin/sellers/${seller.id}`, { preserveScroll: true }); };
    // The confirm states the balance replacement BEFORE it happens: a
    // monthly pack swaps the whole current balance for the pack points.
    const doSubscribe = async (e) => {
        e.preventDefault();
        const pack = packages.find((x) => String(x.id) === String(sub.data.package_id));
        const bal = num(wallet?.balance || 0);
        const msg = pack?.validity_type === 'monthly'
            ? `${t('Record this purchase? It credits')} ${num(pack.credits)} ${t('points and replaces the current balance of')} ${bal} ${t('points - leftover points do not carry over.')}`
            : `${t('Record this purchase? It credits')} ${num(pack?.credits || 0)} ${t('points. Current balance:')} ${bal} ${t('points.')}`;
        if (await confirmDialog(msg)) sub.post(`/admin/sellers/${seller.id}/subscribe`, { preserveScroll: true, onSuccess: () => sub.reset('amount', 'note') });
    };
    const doCredit = (e) => { e.preventDefault(); credit.post(`/admin/sellers/${seller.id}/points`, { preserveScroll: true, onSuccess: () => credit.reset() }); };
    const doPay = (e) => { e.preventDefault(); pay.post(`/admin/sellers/${seller.id}/payments`, { preserveScroll: true, onSuccess: () => pay.reset() }); };
    const doRefund = async () => { if (await confirmDialog(t('Refund this seller\'s first purchase? Their points go to zero and the money rows are reversed. This can be done only once.'))) router.post(`/admin/sellers/${seller.id}/refund-first-purchase`, {}, { preserveScroll: true }); };
    const pkgPrice = packages.find((p) => String(p.id) === String(sub.data.package_id))?.price;

    return (
        <AppLayout>
            <Head title={seller.name} />
            <style>{`.sd-pg .lbl { display:block; font-size:13px; font-weight:600; margin-top:12px; }
.sd-pg .fld { display:block; width:100%; height:40px; border-radius:8px; font-size:14px; border:1px solid #D9CDE4; background:#fff; padding:0 12px; margin-top:5px; font-family:inherit; color:var(--ink); box-sizing:border-box; }
.sd-pg textarea.fld { height:auto; padding:8px 12px; }`}</style>
            <div className="sd-pg">
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <Link href="/admin/sellers" className="btn-ghost">← {t('Sellers')}</Link>
                <h3 style={{ fontSize: 19 }}>{seller.name}</h3>
                <button type="button" className="btn-ghost" onClick={() => router.post(`/admin/sellers/${seller.id}/view`)}>{t('View shop')}</button>
                {seller.status === 'active' ? <span className="pill green">{t('Active')}</span> : seller.status === 'expired' ? <span className="pill red">{t('Expired')}</span> : <span className="pill amber">{t('No package')}</span>}
            </div>

            <div className="plat-kpis" style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 12, margin: '14px 0' }}>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('POINTS BALANCE')}</div><div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{num(wallet?.balance ?? 0)}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{wallet?.pack ? `${wallet.pack} · ${wallet.cycle_end ? `${t('resets')} ${wallet.cycle_end}` : wallet.wallet_expires_at ? `${t('until')} ${wallet.wallet_expires_at}` : ''}` : t('No pack yet')}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('PAID TO US')}</div><div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{tk(seller.paid_total)}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{t('All time')}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('SHOP BOOKED ORDER VALUE')}</div><div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{tk(seller.revenue)}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{seller.orders} {t('orders')}</div></div>
                <div className="card"><div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('SHOP CUSTOMERS')}</div><div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{seller.customers}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{t('People who buy from this shop')}</div></div>
            </div>

            <ApprovalCard seller={seller} />

            <div className="plat-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, alignItems: 'start', marginTop: 12 }}>
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
                        <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Record a pack purchase')}</h4>
                        <form onSubmit={doSubscribe}>
                            <label className="lbl">{t('Pack')}</label>
                            <select className="fld" value={sub.data.package_id} onChange={(e) => sub.setData('package_id', e.target.value)}>
                                {packages.map((p) => <option key={p.id} value={p.id}>{p.name} - {tk(p.price)} · {num(p.credits)} {t('points')} · {p.validity_type === 'monthly' ? t('Monthly') : t('Yearly wallet')}</option>)}
                            </select>
                            <label className="lbl">{t('Amount received')} ({t('blank = pack price')}{pkgPrice ? ` ${tk(pkgPrice)}` : ''})</label>
                            <input className="fld" type="number" min="0" value={sub.data.amount} onChange={(e) => sub.setData('amount', e.target.value)} placeholder={pkgPrice || ''} />
                            <label className="lbl">{t('Payment method')}</label>
                            <select className="fld" value={sub.data.method} onChange={(e) => sub.setData('method', e.target.value)}>
                                <option>bKash</option><option>Nagad</option><option>Bank</option><option>Cash</option>
                            </select>
                            <label className="lbl">{t('Note')}</label>
                            <input className="fld" value={sub.data.note} onChange={(e) => sub.setData('note', e.target.value)} />
                            <button className="btn-primary" disabled={sub.processing} style={{ marginTop: 14 }}>{t('Credit points + record payment')}</button>
                        </form>
                        {wallet?.first_purchase_at && (
                            <div style={{ marginTop: 12, fontSize: 12.5, color: 'var(--muted)' }}>
                                {t('First purchase')}: {wallet.first_purchase_at}
                                {wallet.refunded_at && <> · {t('Refunded')}: {wallet.refunded_at}</>}
                            </div>
                        )}
                        {wallet?.refund_eligible && (
                            <button className="btn" style={{ color: '#B91C1C', marginTop: 10 }} onClick={doRefund}>{t('Refund first purchase (7-day)')}</button>
                        )}
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
                    <div className="card">
                        <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Add points')}</h4>
                        <form onSubmit={doCredit}>
                            <label className="lbl">{t('Amount')}</label>
                            <input className="fld" type="number" min="1" step="1" value={credit.data.amount} onChange={(e) => credit.setData('amount', e.target.value)} required />
                            <label className="lbl">{t('Note')}</label>
                            <input className="fld" value={credit.data.note} onChange={(e) => credit.setData('note', e.target.value)} required />
                            <button className="btn-primary" disabled={credit.processing} style={{ marginTop: 14 }}>{t('Add points')}</button>
                        </form>
                    </div>
                </div>

                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Package history')}</h4>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('Package')}</th><th>{t('Amount')}</th><th>{t('Method')}</th><th>{t('When')}</th></tr></thead>
                        <tbody>
                            {purchaseHistory.map((p) => <tr key={p.id}><td style={{ fontWeight: 600 }}>{p.label}</td><td>{tk(p.amount)}</td><td>{p.method || ''}</td><td style={{ whiteSpace: 'nowrap' }}>{p.when}</td></tr>)}
                            {purchaseHistory.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 20, color: 'var(--muted)' }}>{t('No purchases yet')}</td></tr>}
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

            {(claims || []).length > 0 && (
                <div className="card" style={{ padding: 0, overflow: 'hidden', marginTop: 12 }}>
                    <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Payment claims')}</h4>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('When')}</th><th>{t('Pack')}</th><th>{t('Total')}</th><th>{t('Payment')}</th><th>{t('Status')}</th><th></th></tr></thead>
                        <tbody>
                            {claims.map((c) => (
                                <tr key={c.id}>
                                    <td style={{ whiteSpace: 'nowrap' }}>{c.when}</td>
                                    <td><div style={{ fontWeight: 600 }}>{c.pack_name}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{num(c.pack_credits)} {t('points')}</div></td>
                                    <td style={{ fontWeight: 700 }}>{tk(c.total_bdt)}</td>
                                    <td>{c.method ? <><div>{c.method}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{c.sender_number} · TrxID {c.trx_id}</div></> : '-'}</td>
                                    <td>{c.status === 'approved' ? <span className="pill green">{t('Approved')}</span> : c.status === 'rejected' ? <span className="pill red">{t('Rejected')}</span> : c.status === 'pending_review' ? <span className="pill amber">{t('Waiting for review')}</span> : <span className="pill amber">{t('Waiting for payment')}</span>}
                                        {c.status === 'rejected' && c.admin_note && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>{c.admin_note}</div>}
                                    </td>
                                    <td style={{ whiteSpace: 'nowrap' }}>
                                        {c.status === 'pending_review' && <ClaimActions c={c} />}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                </div>
            )}

            <div className="card" style={{ padding: 0, overflow: 'hidden', marginTop: 12 }}>
                <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Points history')}</h4>
                <div className="tbl-wrap"><table className="tbl">
                    <thead><tr><th>{t('When')}</th><th>{t('Kind')}</th><th>{t('Points')}</th><th>{t('Balance')}</th><th>{t('Note')}</th></tr></thead>
                    <tbody>
                        {(tokenLedger || []).map((r) => <tr key={r.id}><td style={{ whiteSpace: 'nowrap' }}>{r.when}</td><td>{t(r.kind)}</td><td style={{ fontWeight: 700, color: r.amount < 0 ? '#B91C1C' : '#15803D' }}>{r.amount > 0 ? '+' : ''}{num(r.amount)}</td><td>{num(r.balance_after)}</td><td>{r.invoice ? `${t('Order')} ${r.invoice}` : (r.note || '')}</td></tr>)}
                        {(!tokenLedger || tokenLedger.length === 0) && <tr><td colSpan={5} style={{ textAlign: 'center', padding: 20, color: 'var(--muted)' }}>{t('No points activity yet')}</td></tr>}
                    </tbody>
                </table></div>
            </div>

            <div className="card" style={{ padding: 0, overflow: 'hidden', marginTop: 12 }}>
                <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t("This seller's customers")}</h4>
                <div className="tbl-wrap"><table className="tbl">
                    <thead><tr><th>{t('Customer')}</th><th>{t('Phone')}</th><th>{t('Orders')}</th><th>{t('Total Spent')}</th><th>{t('Last Order')}</th></tr></thead>
                    <tbody>
                        {customers.map((c) => <tr key={c.id}><td><div style={{ fontWeight: 600 }}>{c.name || '-'}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{c.address || ''}</div></td><td>{c.phone}</td><td>{c.delivered} / {c.orders}</td><td style={{ fontWeight: 600 }}>{tk(c.spent)}</td><td>{c.last_order || '-'}</td></tr>)}
                        {customers.length === 0 && <tr><td colSpan={5} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('This seller has no customers yet')}</td></tr>}
                    </tbody>
                </table></div>
            </div>
            </div>
        </AppLayout>
    );
}
