import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';

const tk = (n) => `৳${Number(n || 0).toLocaleString('en-IN')}`;
const num = (n) => Number(n || 0).toLocaleString('en-IN');

const STATUS = {
    pending_payment: ['Waiting for payment', 'amber'],
    pending_review: ['Waiting for review', 'amber'],
    approved: ['Approved', 'green'],
    rejected: ['Rejected', 'red'],
};

function ClaimRow({ c }) {
    const reject = useForm({ admin_note: '' });
    const [showReject, setShowReject] = useState(false);
    const approve = async () => {
        if (await confirmDialog(`${t('Approve this payment and credit')} ${num(c.pack_credits)} ${t('points to')} ${c.shop}?`)) {
            router.post(`/admin/claims/${c.id}/approve`, {}, { preserveScroll: true });
        }
    };
    const doReject = (e) => { e.preventDefault(); reject.post(`/admin/claims/${c.id}/reject`, { preserveScroll: true }); };

    return (
        <tr style={c.status === 'pending_review' ? { background: '#FFF8E6' } : undefined}>
            <td>
                <div style={{ fontWeight: 600 }}>{c.shop || '—'}</div>
                <div style={{ fontSize: 12, color: 'var(--muted)' }}>{c.when}</div>
            </td>
            <td>
                <div style={{ fontWeight: 600 }}>{c.pack_name}</div>
                <div style={{ fontSize: 12, color: 'var(--muted)' }}>{num(c.pack_credits)} {t('points')} · {c.validity_type === 'monthly' ? t('Monthly') : t('Yearly wallet')}</div>
            </td>
            <td>
                <div style={{ fontWeight: 700 }}>{tk(c.total_bdt)}</div>
                {Number(c.onboarding_fee_bdt) > 0 && <div style={{ fontSize: 12, color: 'var(--muted)' }}>{tk(c.amount_bdt)} + {tk(c.onboarding_fee_bdt)} {t('onboarding')}</div>}
            </td>
            <td>
                {c.method ? <><div>{c.method}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{c.sender_number}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>TrxID {c.trx_id}</div></> : '—'}
            </td>
            <td><span className={`pill ${(STATUS[c.status] || ['', ''])[1]}`}>{t((STATUS[c.status] || [c.status])[0])}</span>
                {c.status === 'rejected' && c.admin_note && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>{c.admin_note}</div>}
            </td>
            <td style={{ whiteSpace: 'nowrap' }}>
                <div style={{ display: 'flex', gap: 6, alignItems: 'flex-start', flexWrap: 'wrap' }}>
                    <Link className="btn-ghost" href={`/admin/sellers/${c.business_id}`}>{t('Seller')}</Link>
                    {c.status === 'pending_review' && (
                        <>
                            <button className="btn pri" onClick={approve}>{t('Approve')}</button>
                            <button className="btn" style={{ color: '#B91C1C' }} onClick={() => setShowReject((s) => !s)}>{t('Reject')}</button>
                        </>
                    )}
                </div>
                {c.status === 'pending_review' && showReject && (
                    <form onSubmit={doReject} style={{ marginTop: 8, display: 'flex', gap: 6 }}>
                        <input style={{ height: 38, border: '1px solid #D9CDE4', borderRadius: 8, padding: '0 10px', fontSize: 13.5, fontFamily: 'inherit', width: 220 }} placeholder={t('Why? The seller sees this note')} value={reject.data.admin_note} onChange={(e) => reject.setData('admin_note', e.target.value)} required />
                        <button className="btn" style={{ color: '#B91C1C' }} disabled={reject.processing}>{t('Confirm reject')}</button>
                    </form>
                )}
            </td>
        </tr>
    );
}

export default function Claims({ claims, counts }) {
    return (
        <AppLayout>
            <Head title={t('Payment claims')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <h3 style={{ fontSize: 19 }}>{t('Payment claims')}</h3>
                {counts.pending_review > 0 && <span className="pill amber">{counts.pending_review} {t('waiting for review')}</span>}
                <span className="pill">{counts.pending_payment} {t('waiting for payment')}</span>
                <span className="pill green">{counts.approved} {t('approved')}</span>
                <span className="pill red">{counts.rejected} {t('rejected')}</span>
            </div>
            <div style={{ fontSize: 13, color: 'var(--muted)', margin: '6px 0 12px' }}>{t('Sellers pay by bKash / Nagad and submit the payment here. Approving credits the pack points to their shop.')}</div>

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead><tr><th>{t('Seller (Shop)')}</th><th>{t('Pack')}</th><th>{t('Total')}</th><th>{t('Payment')}</th><th>{t('Status')}</th><th></th></tr></thead>
                        <tbody>
                            {claims.map((c) => <ClaimRow key={c.id} c={c} />)}
                            {claims.length === 0 && <tr><td colSpan={6} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('No payment claims yet')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
