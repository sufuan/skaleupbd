import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import DomainLocked from './DomainLocked';
import { t } from '@/lib/i18n';

const STATUS = {
    draft: ['Draft — not live', 'amber'],
    published: ['Live', 'green'],
    taken_down: ['Taken down by the platform', 'red'],
};

export default function Overview({ sf, state, pageCount, livePageCount, viewsTotal, orderCount, openReports, hasAddress }) {
    const otpSend = useForm({ phone: sf.phoneToVerify || sf.contactMobile || '' });
    const otpVerify = useForm({ code: '' });
    const [label, tone] = STATUS[sf.status] || [sf.status, ''];
    const hasStore = !!(state && state.hasStore);

    const checkRow = (ok, text, href, cta) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 0', borderBottom: '1px solid var(--border, #E7DEF0)' }}>
            <span className={`pill ${ok ? 'green' : 'amber'}`}>{ok ? t('Done') : t('To do')}</span>
            <span style={{ flex: 1, fontSize: 14.5 }}>{t(text)}</span>
            {!ok && href && <Link className="btn" href={href}>{t(cta)}</Link>}
        </div>
    );

    return (
        <AppLayout>
            <Head title={t('Storefront')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
                <h3 style={{ fontSize: 19, margin: 0 }}>{t('Storefront')}</h3>
                {hasStore && <span className={`pill ${tone}`}>{t(label)}</span>}
                {hasStore && (sf.status === 'published'
                    ? <button className="btn" onClick={() => router.post('/storefront/unpublish')}>{t('Unpublish')}</button>
                    : (hasAddress && sf.phoneVerified && !sf.domainLocked)
                        ? <button className="btn pri" onClick={() => router.post('/storefront/publish')}>{t('Publish storefront')}</button>
                        : <button className="btn pri" disabled style={{ opacity: 0.45, cursor: 'not-allowed' }} title={t('Finish the checklist below first — your shop address and mobile verification are needed')}>{t('Publish storefront')}</button>)}
                {sf.subdomainUrl && <a className="btn" href={sf.subdomainUrl} target="_blank" rel="noreferrer">{t('View shop')}</a>}
            </div>

            {sf.domainLocked && <DomainLocked />}
            {sf.status === 'taken_down' && (
                <div style={{ background: '#FEF2F2', border: '1px solid #F3C2BF', color: '#8B1E1E', borderRadius: 10, padding: '12px 15px', marginBottom: 14, fontSize: 14.5 }}>
                    {t('This storefront was taken down by the platform team. Contact support to restore it.')}
                </div>
            )}
            {sf.unpublishedReason === 'plan_lapsed' && sf.status !== 'published' && (
                <div style={{ background: '#FFFBEB', border: '1px solid #F0DFA8', color: '#7A5200', borderRadius: 10, padding: '12px 15px', marginBottom: 14, fontSize: 14.5 }}>
                    {t('Your storefront was unpublished because your points pack ended. Buy a pack, then publish again.')}
                </div>
            )}

            {!hasStore ? (
                <div className="card" style={{ textAlign: 'center', padding: '30px 20px' }}>
                    <div style={{ fontWeight: 700, fontSize: 17, marginBottom: 6 }}>{t('You do not have a store yet')}</div>
                    <p style={{ color: 'var(--muted)', fontSize: 14.5, margin: '0 0 16px' }}>{t('Make your free store in five easy steps — your products come in automatically.')}</p>
                    <Link className="btn pri" href="/storefront/wizard">{t('Create your store')}</Link>
                </div>
            ) : (
                <>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 12, marginBottom: 14 }} className="sf-kpis">
                        <div className="card">
                            <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Your link')}</div>
                            <div style={{ fontWeight: 700, fontSize: 15, wordBreak: 'break-all' }}>{sf.subdomainUrl || t('Not set yet')}</div>
                            {sf.customDomainUrl && <div style={{ fontSize: 13, color: 'var(--muted)' }}>{sf.customDomainUrl}</div>}
                        </div>
                        <div className="card">
                            <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Pages')}</div>
                            <div style={{ fontWeight: 800, fontSize: 24 }}>{pageCount} <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--muted)' }}>({livePageCount} {t('live')})</span></div>
                        </div>
                        <div className="card">
                            <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Total views')}</div>
                            <div style={{ fontWeight: 800, fontSize: 24 }}>{viewsTotal}</div>
                        </div>
                        <div className="card">
                            <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Orders from the storefront')}</div>
                            <div style={{ fontWeight: 800, fontSize: 24 }}>{orderCount}</div>
                        </div>
                    </div>

                    <div className="card">
                        <div style={{ fontWeight: 700, fontSize: 15.5, marginBottom: 4 }}>{t('Before you publish')}</div>
                        {checkRow(hasAddress, 'Shop address added', '/storefront/settings', 'Add')}
                        {checkRow(!!sf.phoneVerified, 'Mobile number verified', null, null)}
                        {checkRow(!sf.domainLocked, 'First pack payment done', '/billing', 'Pay')}
                        {!sf.phoneVerified && (
                            <div className="sf-form" style={{ paddingTop: 10 }}>
                                <label className="lbl">{t('Mobile number')}</label>
                                <div style={{ display: 'flex', gap: 8 }}>
                                    <input className="fld" style={{ flex: 1 }} value={otpSend.data.phone} onChange={(e) => otpSend.setData('phone', e.target.value)} placeholder="01XXXXXXXXX" inputMode="tel" />
                                    <button className="btn" disabled={otpSend.processing} onClick={() => otpSend.post('/storefront/otp/send', { preserveScroll: true })}>{t('Send code')}</button>
                                </div>
                                {otpSend.errors.phone && <div style={{ color: '#B91C1C', fontSize: 13, marginTop: 5 }}>{otpSend.errors.phone}</div>}
                                <label className="lbl" style={{ marginTop: 10 }}>{t('Code from the SMS')}</label>
                                <div style={{ display: 'flex', gap: 8 }}>
                                    <input className="fld" style={{ flex: 1 }} value={otpVerify.data.code} onChange={(e) => otpVerify.setData('code', e.target.value)} placeholder="123456" inputMode="numeric" />
                                    <button className="btn pri" disabled={otpVerify.processing} onClick={() => otpVerify.post('/storefront/otp/verify', { preserveScroll: true })}>{t('Verify')}</button>
                                </div>
                                {otpVerify.errors.code && <div style={{ color: '#B91C1C', fontSize: 13, marginTop: 5 }}>{otpVerify.errors.code}</div>}
                            </div>
                        )}
                        <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
                            <Link className="btn" href="/storefront/pages">{t('My Pages')}</Link>
                            <Link className="btn" href="/storefront/settings">{t('Store Settings')}</Link>
                            {Number(openReports) > 0 && <span className="pill red">{openReports} {t('open report(s) — see Store Settings')}</span>}
                        </div>
                    </div>
                </>
            )}
            <style>{`.sf-form .lbl { display:block; font-size:13px; font-weight:600; }
.sf-form .fld { display:block; width:100%; height:40px; border-radius:8px; font-size:14px; border:1px solid #D9CDE4; background:#fff; padding:0 12px; margin-top:5px; font-family:inherit; color:var(--ink); box-sizing:border-box; }
@media (max-width: 900px){ .sf-kpis{grid-template-columns:repeat(2,1fr) !important} }`}</style>
        </AppLayout>
    );
}
