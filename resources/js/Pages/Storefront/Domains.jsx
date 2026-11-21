import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import DomainLocked from './DomainLocked';
import { t } from '@/lib/i18n';

const DSTATUS = {
    none: ['No custom domain', ''],
    pending_dns: ['Waiting for DNS', 'amber'],
    verified: ['Verified — being connected', 'amber'],
    active: ['Active', 'green'],
};

export default function Domains({ sf }) {
    const form = useForm({ custom_domain: '' });
    const [label, tone] = DSTATUS[sf.domainStatus] || [sf.domainStatus, ''];

    const Row = ({ type, name, value }) => (
        <tr>
            <td style={{ fontWeight: 700 }}>{type}</td>
            <td style={{ fontFamily: 'monospace', fontSize: 13 }}>{name}</td>
            <td style={{ fontFamily: 'monospace', fontSize: 13, wordBreak: 'break-all' }}>{value}</td>
            <td><button type="button" className="btn" onClick={() => navigator.clipboard?.writeText(value)}>{t('Copy')}</button></td>
        </tr>
    );

    return (
        <AppLayout>
            <Head title={t('Domains')} />
            <h3 style={{ fontSize: 19, margin: '0 0 14px' }}>{t('Domains')}</h3>
            {sf.domainLocked && <DomainLocked />}

            <div className="card" style={{ maxWidth: 720, marginBottom: 12 }}>
                <h4 style={{ fontSize: 15.5, margin: '2px 0 8px' }}>{t('Free subdomain')}</h4>
                {sf.subdomainUrl
                    ? <div style={{ fontSize: 15 }}><a href={sf.subdomainUrl} target="_blank" rel="noreferrer" style={{ fontWeight: 700 }}>{sf.subdomainUrl}</a></div>
                    : <div style={{ fontSize: 14.5, color: 'var(--muted)' }}>{t('Not set yet — choose it in Storefront → Subdomain.')}</div>}
            </div>

            <div className="card" style={{ maxWidth: 720 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <h4 style={{ fontSize: 15.5, margin: '2px 0', flex: 1 }}>{t('Your own domain')}</h4>
                    <span className={`pill ${tone}`}>{t(label)}</span>
                </div>
                {sf.domainLocked && !sf.customDomain ? (
                    <p style={{ fontSize: 14, color: 'var(--muted)' }}>
                        {t('Custom domains unlock together with your free shop address — after your first pack payment is confirmed.')}
                    </p>
                ) : !sf.customDomain ? (
                    <form onSubmit={(e) => { e.preventDefault(); form.post('/storefront/domains', { preserveScroll: true }); }}>
                        <p style={{ fontSize: 14, color: 'var(--muted)' }}>
                            {t('Connect a domain you own (e.g. yourshop.com). You keep your nameservers and email as they are — you only add three DNS records.')}
                        </p>
                        <label className="lbl">{t('Domain name')}</label>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                            <input className="fld" style={{ flex: '1 1 220px' }} placeholder="yourshop.com" value={form.data.custom_domain} onChange={(e) => form.setData('custom_domain', e.target.value.toLowerCase())} required />
                            <button className="btn pri" disabled={form.processing}>{t('Connect domain')}</button>
                        </div>
                    </form>
                ) : (
                    <>
                        <div style={{ fontSize: 16, fontWeight: 700 }}>{sf.customDomain}</div>
                        <p style={{ fontSize: 14, color: 'var(--muted)' }}>
                            {t('Add these records at your domain registrar, then press Verify. DNS usually updates in minutes, sometimes a few hours (up to 48 hours).')}
                        </p>
                        <div className="tbl-wrap"><table className="tbl">
                            <thead><tr><th>{t('Type')}</th><th>{t('Name')}</th><th>{t('Value')}</th><th></th></tr></thead>
                            <tbody>
                                <Row type="TXT" name="_scalenow-verify" value={sf.domainTxtToken || ''} />
                                <Row type="A" name="@" value={sf.serverIp} />
                                <Row type="CNAME" name="www" value={sf.cnameTarget} />
                            </tbody>
                        </table></div>
                        <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
                            {sf.domainStatus === 'pending_dns' && <button className="btn pri" onClick={() => router.post('/storefront/domains/verify', {}, { preserveScroll: true })}>{t('Verify DNS')}</button>}
                            {sf.domainStatus === 'verified' && <span style={{ fontSize: 14.5 }}>{t('Verified. Our team is connecting your domain and its SSL certificate — this can take a few hours.')}</span>}
                            {sf.domainStatus === 'active' && <span style={{ fontSize: 14.5 }}>{t('Live at')} <a href={sf.customDomainUrl} target="_blank" rel="noreferrer" style={{ fontWeight: 700 }}>{sf.customDomainUrl}</a></span>}
                            <button className="btn" onClick={() => { if (confirm(t('Remove this custom domain?'))) router.delete('/storefront/domains', { preserveScroll: true }); }}>{t('Remove domain')}</button>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
