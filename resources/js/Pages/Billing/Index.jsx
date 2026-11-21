import { Head, Link, router } from '@inertiajs/react';
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

export default function Index({ balance, pointsPerOrder, threshold, blocked, low, pack, packs, onboardingFee, firstPurchase, paymentInstructions, ledger }) {
    const pages = [];
    for (let p = 1; p <= (ledger.last_page || 1); p++) pages.push(p);
    return (
        <AppLayout>
            <Head title={t('Points & Billing')} />
            <style>{`@media (max-width: 760px) { .bill-kpis, .bill-2col { grid-template-columns: 1fr !important; } }`}</style><h3 style={{ fontSize: 19, marginBottom: 4 }}>{t('Points & Billing')}</h3>
            <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 14 }}>{t('One point is spent every time a new order enters your shop. Points are never returned — even if the order is later cancelled or returned.')}</div>

            {blocked && (
                <div style={{ background: '#FDECEC', border: '1px solid #F3B4B4', color: '#8B1E1E', borderRadius: 10, padding: '12px 14px', marginBottom: 14, fontSize: 14 }}>
                    <b>{t('Your shop is view-only right now.')}</b> {t('Your points are finished, so new orders and changes are paused. Buy a points pack to start again — everything you see here stays safe.')}
                </div>
            )}
            {!blocked && low && (
                <div style={{ background: '#FFF6E0', border: '1px solid #F0D48A', color: '#7A5200', borderRadius: 10, padding: '12px 14px', marginBottom: 14, fontSize: 14 }}>
                    <b>{t('Points are running low.')}</b> {t('Top up soon so your orders never stop.')}
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
                    <div style={{ fontSize: 20, fontWeight: 700, marginTop: 6 }}>{num(pointsPerOrder)} {t('point(s) per order')}</div>
                    <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Low-balance warning at')} {num(threshold)} {t('points')}</div>
                </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, alignItems: 'start' }} className="bill-2col">
                <div className="card">
                    <h4 style={{ fontSize: 15, marginBottom: 4 }}>{t('How to buy points')}</h4>
                    <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 10 }}>{t('Pay by bKash / Nagad / bank, then tell us — we credit your points right away.')}</div>
                    {paymentInstructions
                        ? <div style={{ fontSize: 14, whiteSpace: 'pre-wrap', background: 'var(--surface2, #F6F1FA)', borderRadius: 8, padding: '10px 12px' }}>{paymentInstructions}</div>
                        : <div style={{ fontSize: 13.5, color: 'var(--muted)' }}>{t('Payment details are being set up — please contact us to top up.')}</div>}
                    {!firstPurchase && Number(onboardingFee) > 0 && (
                        <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 10 }}>{t('A one-time onboarding fee of')} {tk(onboardingFee)} {t('applies to your first purchase.')}</div>
                    )}
                    <div style={{ marginTop: 14, display: 'grid', gap: 8 }}>
                        {packs.map((p) => (
                            <div key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 10, border: '1px solid var(--border, #E7DEF0)', borderRadius: 10, padding: '10px 12px' }}>
                                <div>
                                    <div style={{ fontWeight: 700, fontSize: 14 }}>{p.name}</div>
                                    <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{num(p.credits)} {t('points')} · {tk(p.rate)} / {t('order')} · {p.validity_type === 'monthly' ? t('Monthly') : t('Yearly wallet')}</div>
                                </div>
                                <div style={{ marginLeft: 'auto', fontWeight: 700 }}>{tk(p.price_bdt)}</div>
                            </div>
                        ))}
                        {packs.length === 0 && <div style={{ fontSize: 13.5, color: 'var(--muted)' }}>{t('No packs are on sale right now.')}</div>}
                    </div>
                    <div style={{ marginTop: 12 }}><Link href="/pricing" style={{ fontSize: 13.5, fontWeight: 600 }}>{t('See full pricing')} →</Link></div>
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
