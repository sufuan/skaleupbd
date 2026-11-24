import { useEffect } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const tk = (n) => `৳${Number(n || 0).toLocaleString('en-IN')}`;
const num = (n) => Number(n || 0).toLocaleString('en-IN');

const KIND = {
    purchase: ['Pack purchase', 'green'],
    deduct: ['Order', ''],
    reset: ['Cycle reset', 'amber'],
    refund: ['Refund', 'red'],
    manual_credit: ['Manual credit', 'green'],
    adjust: ['Adjustment', ''],
};

const CLAIM_STATUS = {
    pending_payment: ['Waiting for your payment', 'amber'],
    pending_review: ['Waiting for confirmation', 'amber'],
    approved: ['Approved', 'green'],
    rejected: ['Not approved', 'red'],
};

function ClaimBreakdown({ claim }) {
    return (
        <div style={{ fontSize: 14, display: 'grid', gap: 4, maxWidth: 420 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 20 }}><span>{claim.pack_name} ({num(claim.pack_credits)} {t('points')})</span><b>{tk(claim.amount_bdt)}</b></div>
            {Number(claim.onboarding_fee_bdt) > 0 && (
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 20 }}><span>{t('Onboarding fee (one-time)')}</span><b>{tk(claim.onboarding_fee_bdt)}</b></div>
            )}
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 20, borderTop: '1px solid var(--border, #E7DEF0)', paddingTop: 6, marginTop: 2, fontSize: 16 }}>
                <span style={{ fontWeight: 700 }}>{t('Total to pay')}</span><b>{tk(claim.total_bdt)}</b>
            </div>
        </div>
    );
}

function PendingClaimCard({ claim, paymentInstructions, platformPayment }) {
    const form = useForm({ method: 'bKash', sender_number: '', trx_id: '' });
    const submit = (e) => { e.preventDefault(); form.post(`/billing/claims/${claim.id}/submit`, { preserveScroll: true }); };

    if (claim.status === 'pending_review') {
        return (
            <div style={{ background: '#EFF6FF', border: '1px solid #B9D4F5', color: '#1E3A5F', borderRadius: 10, padding: '14px 16px', marginBottom: 14 }}>
                <div style={{ fontSize: 15.5, fontWeight: 700, marginBottom: 6 }}>{t('Payment submitted - waiting for confirmation')}</div>
                <ClaimBreakdown claim={claim} />
                <div style={{ fontSize: 13.5, marginTop: 10 }}>
                    {t('You told us:')} {claim.method} · {t('from')} {claim.sender_number} · TrxID {claim.trx_id}
                </div>
                <div style={{ fontSize: 13, marginTop: 4 }}>{t('We check payments by hand - your points appear here as soon as we confirm.')}</div>
            </div>
        );
    }

    return (
        <div style={{ background: '#FFF8E6', border: '1px solid #F0D48A', color: '#5F4700', borderRadius: 10, padding: '14px 16px', marginBottom: 14 }}>
            <div style={{ fontSize: 15.5, fontWeight: 700, marginBottom: 6 }}>{t('Your pending payment')}</div>
            <ClaimBreakdown claim={claim} />
            {platformPayment && platformPayment.length > 0 && (
                <div style={{ background: '#fff', border: '1px solid #F0D48A', borderRadius: 8, padding: '10px 12px', marginTop: 12 }}>
                    <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 4 }}>{t('Pay to these numbers (Personal)')}</div>
                    {platformPayment.map((n) => (
                        <div key={n.method} style={{ fontSize: 15, display: 'flex', gap: 10 }}><span style={{ minWidth: 60, fontWeight: 600 }}>{n.label}</span><b>{n.number}</b></div>
                    ))}
                </div>
            )}
            {paymentInstructions
                ? <div style={{ fontSize: 14, whiteSpace: 'pre-wrap', background: '#fff', border: '1px solid #F0D48A', borderRadius: 8, padding: '10px 12px', marginTop: 12 }}>{paymentInstructions}</div>
                : null}
            <form onSubmit={submit} className="claim-form" style={{ marginTop: 14, maxWidth: 420 }}>
                <div style={{ fontSize: 14, fontWeight: 700, marginBottom: 6 }}>{t('Already paid? Tell us the details')}</div>
                <label className="lbl">{t('Payment method')}</label>
                <select className="fld" value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>
                    <option value="bKash">bKash</option>
                    <option value="Nagad">Nagad</option>
                </select>
                <label className="lbl">{t('Your mobile number (the one you paid from)')}</label>
                <input className="fld" value={form.data.sender_number} onChange={(e) => form.setData('sender_number', e.target.value)} placeholder="01XXXXXXXXX" required />
                {form.errors.sender_number && <div style={{ color: '#B91C1C', fontSize: 12.5 }}>{form.errors.sender_number}</div>}
                <label className="lbl">{t('Transaction ID (TrxID)')}</label>
                <input className="fld" value={form.data.trx_id} onChange={(e) => form.setData('trx_id', e.target.value)} required />
                {form.errors.trx_id && <div style={{ color: '#B91C1C', fontSize: 12.5 }}>{form.errors.trx_id}</div>}
                <button className="btn pri" disabled={form.processing || !form.data.sender_number.trim() || !form.data.trx_id.trim()} style={{ marginTop: 12 }}>{t('Submit payment details')}</button>
            </form>
        </div>
    );
}

export default function Index({ balance, pointsPerOrder, threshold, blocked, low, pendingApproval, pack, packs, onboardingFee, firstPurchase, paymentInstructions, platformPayment, openClaim, pastClaims, preselectedPackId, ledger }) {
    const pages = [];
    for (let p = 1; p <= (ledger.last_page || 1); p++) pages.push(p);
    const lastRejected = !openClaim && pastClaims && pastClaims.length > 0 && pastClaims[0].status === 'rejected' ? pastClaims[0] : null;

    useEffect(() => {
        if (preselectedPackId && !openClaim) {
            const el = document.getElementById(`pack-row-${preselectedPackId}`);
            if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }, [preselectedPackId, openClaim]);

    const buy = (packId) => router.post('/billing/claims', { pack_id: packId }, { preserveScroll: true });

    return (
        <AppLayout>
            <Head title={t('Points & Billing')} />
            <style>{`@media (max-width: 760px) { .bill-kpis, .bill-2col { grid-template-columns: 1fr !important; } }
.claim-form .lbl { display:block; font-size:13px; font-weight:600; margin-top:10px; }
.claim-form .fld { display:block; width:100%; height:40px; border-radius:8px; font-size:14px; border:1px solid #D9CDE4; background:#fff; padding:0 12px; margin-top:5px; font-family:inherit; color:var(--ink); box-sizing:border-box; }`}</style><h3 style={{ fontSize: 19, marginBottom: 4 }}>{t('Points & Billing')}</h3>
            <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 14 }}>{t('One point is spent every time a new order enters your shop. Points are never returned - even if the order is later cancelled or returned.')}</div>

            {pendingApproval ? (
                <div style={{ background: '#EFF6FF', border: '1px solid #B9D4F5', color: '#1E3A5F', borderRadius: 10, padding: '12px 14px', marginBottom: 14, fontSize: 14 }}>
                    <b>{t('Your account is under review')}</b> {t('You can already buy a points pack, so your shop is ready the moment it is approved.')}
                </div>
            ) : blocked && (
                <div style={{ background: '#FDECEC', border: '1px solid #F3B4B4', color: '#8B1E1E', borderRadius: 10, padding: '12px 14px', marginBottom: 14, fontSize: 14 }}>
                    <b>{t('Your shop is view-only right now.')}</b> {t('Your points are finished, so new orders and changes are paused. Buy a points pack to start again - everything you see here stays safe.')}
                </div>
            )}
            {!pendingApproval && !blocked && low && (
                <div style={{ background: '#FFF6E0', border: '1px solid #F0D48A', color: '#7A5200', borderRadius: 10, padding: '12px 14px', marginBottom: 14, fontSize: 14 }}>
                    <b>{t('Points are running low.')}</b> {t('Top up soon so your orders never stop.')}
                </div>
            )}

            {openClaim && <PendingClaimCard claim={openClaim} paymentInstructions={paymentInstructions} platformPayment={platformPayment} />}

            {lastRejected && (
                <div style={{ background: '#FDECEC', border: '1px solid #F3B4B4', color: '#8B1E1E', borderRadius: 10, padding: '12px 14px', marginBottom: 14, fontSize: 14 }}>
                    <b>{t('Your last payment was not approved.')}</b>
                    {lastRejected.admin_note && <div style={{ marginTop: 4 }}>{t('Our note:')} {lastRejected.admin_note}</div>}
                    <div style={{ marginTop: 4 }}>{t('Pick a pack below to start a new payment request.')}</div>
                </div>
            )}

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12, marginBottom: 14 }} className="bill-kpis">
                <div className="card">
                    <div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('POINTS BALANCE')}</div>
                    <div style={{ fontSize: 34, fontWeight: 800, marginTop: 4 }}>{num(balance)}</div>
                    <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('points left')}</div>
                </div>
                <div className="card">
                    <div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('CURRENT PACK')}</div>
                    <div style={{ fontSize: 20, fontWeight: 700, marginTop: 6 }}>{pack ? pack.name : t('No pack yet')}</div>
                    <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>
                        {pack && pack.validity_type === 'monthly' && pack.cycle_end ? `${t('Points reset on')} ${pack.cycle_end}` : ''}
                        {pack && pack.validity_type === 'yearly_wallet' && pack.wallet_expires_at ? `${t('Wallet valid until')} ${pack.wallet_expires_at}` : ''}
                        {!pack ? t('Buy a pack below to start taking orders') : ''}
                    </div>
                </div>
                <div className="card">
                    <div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{t('PER ORDER')}</div>
                    <div style={{ fontSize: 20, fontWeight: 700, marginTop: 6 }}>{num(pointsPerOrder)} {pointsPerOrder === 1 ? t('point per order') : t('points per order')}</div>
                    <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Low-balance warning at')} {num(threshold)} {t('points')}</div>
                </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, alignItems: 'start' }} className="bill-2col">
                <div>
                    <div className="card">
                        <h4 style={{ fontSize: 15, marginBottom: 4 }}>{openClaim ? t('Packs') : t('Choose a pack')}</h4>
                        <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 10 }}>{t('Pay by bKash / Nagad, submit the payment details, and we credit your points as soon as we confirm.')}</div>
                        <div style={{ fontSize: 13, color: '#7A5200', background: '#FFF6E0', border: '1px solid #F0D48A', borderRadius: 8, padding: '8px 10px', marginBottom: 10 }}>{t('A monthly pack replaces your current points balance when it is approved - leftover points do not carry over.')}</div>
                        {platformPayment && platformPayment.length > 0 && (
                            <div style={{ background: 'var(--surface2, #F6F1FA)', borderRadius: 8, padding: '10px 12px', marginBottom: 10 }}>
                                <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 4 }}>{t('Pay to these numbers (Personal)')}</div>
                                {platformPayment.map((n) => (
                                    <div key={n.method} style={{ fontSize: 15, display: 'flex', gap: 10 }}><span style={{ minWidth: 60, fontWeight: 600 }}>{n.label}</span><b>{n.number}</b></div>
                                ))}
                            </div>
                        )}
                        {paymentInstructions
                            ? <div style={{ fontSize: 14, whiteSpace: 'pre-wrap', background: 'var(--surface2, #F6F1FA)', borderRadius: 8, padding: '10px 12px' }}>{paymentInstructions}</div>
                            : null}
                        {!firstPurchase && Number(onboardingFee) > 0 && (
                            <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 10 }}>{t('A one-time onboarding fee of')} {tk(onboardingFee)} {t('applies to your first purchase.')}</div>
                        )}
                        <div style={{ marginTop: 14, display: 'grid', gap: 8 }}>
                            {packs.map((p) => (
                                <div key={p.id} id={`pack-row-${p.id}`} style={{ display: 'flex', alignItems: 'center', gap: 10, border: '1px solid var(--border, #E7DEF0)', borderRadius: 10, padding: '10px 12px', ...(preselectedPackId === p.id && !openClaim ? { border: '2px solid #411550', background: '#F6F1FA' } : {}) }}>
                                    <div>
                                        <div style={{ fontWeight: 700, fontSize: 14 }}>{p.name}</div>
                                        <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{num(p.credits)} {t('points')} · {tk(p.rate)} / {t('order')} · {p.validity_type === 'monthly' ? t('Monthly') : t('Yearly wallet')}</div>
                                    </div>
                                    <div style={{ marginLeft: 'auto', fontWeight: 700 }}>{tk(p.price_bdt)}</div>
                                    {!openClaim && <button className="btn pri" onClick={() => buy(p.id)}>{t('Buy')}</button>}
                                </div>
                            ))}
                            {packs.length === 0 && <div style={{ fontSize: 13.5, color: 'var(--muted)' }}>{t('No packs are on sale right now.')}</div>}
                        </div>
                        {openClaim && <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 10 }}>{t('You already have a payment in progress - finish it above before starting another.')}</div>}
                        <div style={{ marginTop: 12 }}><Link href="/pricing" style={{ fontSize: 13.5, fontWeight: 600 }}>{t('See full pricing')} →</Link></div>
                    </div>

                    {pastClaims && pastClaims.length > 0 && (
                        <div className="card" style={{ marginTop: 12 }}>
                            <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Your payment requests')}</h4>
                            <div style={{ display: 'grid', gap: 8 }}>
                                {pastClaims.map((c) => (
                                    <div key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13.5 }}>
                                        <div>
                                            <div style={{ fontWeight: 600 }}>{c.pack_name} - {tk(c.total_bdt)}{Number(c.onboarding_fee_bdt) > 0 && <span style={{ fontWeight: 400, fontSize: 12, color: 'var(--muted)' }}> ({t('pack')} {tk(c.amount_bdt)} + {t('onboarding')} {tk(c.onboarding_fee_bdt)})</span>}</div>
                                            <div style={{ fontSize: 12, color: 'var(--muted)' }}>{c.when}</div>
                                        </div>
                                        <span className={`pill ${(CLAIM_STATUS[c.status] || ['', ''])[1]}`} style={{ marginLeft: 'auto' }}>{t((CLAIM_STATUS[c.status] || [c.status])[0])}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Points history')}</h4>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('When')}</th><th>{t('What')}</th><th>{t('Points')}</th><th>{t('Balance')}</th></tr></thead>
                        <tbody>
                            {ledger.data.map((r) => (
                                <tr key={r.id}>
                                    <td style={{ whiteSpace: 'nowrap' }}>{r.when}</td>
                                    <td>
                                        <span className={`pill ${(KIND[r.kind] || ['', ''])[1]}`}>{t((KIND[r.kind] || [r.kind])[0])}</span>
                                        <div style={{ fontSize: 12, color: 'var(--muted)' }}>{r.invoice ? `${t('Order')} ${r.invoice}` : (r.note || '')}</div>
                                    </td>
                                    <td style={{ fontWeight: 700, color: r.amount < 0 ? '#B91C1C' : '#15803D' }}>{r.amount > 0 ? '+' : ''}{num(r.amount)}</td>
                                    <td>{num(r.balance_after)}</td>
                                </tr>
                            ))}
                            {ledger.data.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 20, color: 'var(--muted)' }}>{t('Nothing here yet')}</td></tr>}
                        </tbody>
                    </table></div>
                    {ledger.last_page > 1 && (
                        <div style={{ display: 'flex', gap: 6, padding: 12, flexWrap: 'wrap' }}>
                            {pages.map((p) => (
                                <button key={p} className={p === ledger.current_page ? 'btn pri' : 'btn'} onClick={() => router.get('/billing', { page: p }, { preserveScroll: true })}>{p}</button>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
