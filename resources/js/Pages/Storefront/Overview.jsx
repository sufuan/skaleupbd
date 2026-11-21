import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const STATUS = {
    draft: ['Draft — not live', 'amber'],
    published: ['Live', 'green'],
    taken_down: ['Taken down by the platform', 'red'],
};

export default function Overview({ sf, orderCount, openReports, hasAddress }) {
    const otpSend = useForm({ phone: '' });
    const otpVerify = useForm({ code: '' });
    const [label, tone] = STATUS[sf.status] || [sf.status, ''];

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
                <span className={`pill ${tone}`}>{t(label)}</span>
                {sf.status === 'published'
                    ? <button className="btn" onClick={() => router.post('/storefront/unpublish')}>{t('Unpublish')}</button>
                    : <button className="btn pri" onClick={() => router.post('/storefront/publish')}>{t('Publish storefront')}</button>}
                {sf.subdomainUrl && <a className="btn" href={sf.subdomainUrl} target="_blank" rel="noreferrer">{t('View shop')}</a>}
            </div>

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

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12, marginBottom: 14 }} className="sf-kpis">
                <div className="card">
                    <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Shop address')}</div>
                    <div style={{ fontWeight: 700, fontSize: 15.5, wordBreak: 'break-all' }}>{sf.subdomainUrl || t('Not set yet')}</div>
                    {sf.customDomainUrl && <div style={{ fontSize: 13, color: 'var(--muted)' }}>{sf.customDomainUrl}</div>}
                </div>
                <div className="card">
                    <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Orders from the storefront')}</div>
                    <div style={{ fontWeight: 800, fontSize: 24 }}>{orderCount}</div>
                </div>
                <div className="card">
                    <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Open page reports')}</div>
                    <div style={{ fontWeight: 800, fontSize: 24 }}>{openReports}</div>
                </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, alignItems: 'start' }} className="sf-2col">
                <div className="card">
                    <h4 style={{ fontSize: 15.5, margin: '2px 0 6px' }}>{t('Before you publish')}</h4>
                    {checkRow(hasAddress, 'Choose your shop address', '/storefront/subdomain', 'Choose')}
                    {checkRow(sf.phoneVerified, 'Verify your mobile number', null, null)}
                    {checkRow(true, 'Build your page', '/storefront/builder', 'Open builder')}
                    <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 10 }}>
                        {t('Your free subdomain keeps working even after you connect your own domain.')}
                    </div>
                </div>

                <div className="card">
                    <h4 style={{ fontSize: 15.5, margin: '2px 0 6px' }}>{t('Mobile verification')}</h4>
                    {sf.phoneVerified ? (
                        <p style={{ fontSize: 14.5, margin: 0 }}><span className="pill green">{t('Verified')}</span> {sf.phoneToVerify || ''}</p>
                    ) : (
                        <>
                            <form onSubmit={(e) => { e.preventDefault(); otpSend.post('/storefront/otp/send', { preserveScroll: true }); }}>
                                <label className="lbl">{t('Your mobile number')}</label>
                                <div style={{ display: 'flex', gap: 8 }}>
                                    <input className="fld" style={{ flex: 1 }} placeholder="01XXXXXXXXX" value={otpSend.data.phone} onChange={(e) => otpSend.setData('phone', e.target.value)} required />
                                    <button className="btn pri" disabled={otpSend.processing}>{t('Send code')}</button>
                                </div>
                            </form>
                            <form onSubmit={(e) => { e.preventDefault(); otpVerify.post('/storefront/otp/verify', { preserveScroll: true }); }} style={{ marginTop: 12 }}>
                                <label className="lbl">{t('6-digit code from the SMS')}</label>
                                <div style={{ display: 'flex', gap: 8 }}>
                                    <input className="fld" style={{ flex: 1 }} placeholder="123456" inputMode="numeric" value={otpVerify.data.code} onChange={(e) => otpVerify.setData('code', e.target.value)} required />
                                    <button className="btn" disabled={otpVerify.processing}>{t('Verify')}</button>
                                </div>
                            </form>
                        </>
                    )}
                </div>
            </div>
            <style>{`@media (max-width: 760px){ .sf-kpis{grid-template-columns:1fr !important} .sf-2col{grid-template-columns:1fr !important} }`}</style>
        </AppLayout>
    );
}
