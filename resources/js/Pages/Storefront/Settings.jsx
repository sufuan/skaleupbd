import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';

function Section({ title, open, onToggle, children }) {
    return (
        <div className="card" style={{ marginBottom: 12 }}>
            <button type="button" onClick={onToggle} style={{ width: '100%', display: 'flex', alignItems: 'center', background: 'none', border: 'none', cursor: 'pointer', fontFamily: 'inherit', padding: 0 }}>
                <span style={{ fontWeight: 700, fontSize: 15.5, flex: 1, textAlign: 'left' }}>{title}</span>
                <span style={{ fontSize: 18, color: 'var(--muted)' }}>{open ? '⌄' : '›'}</span>
            </button>
            {open && <div style={{ marginTop: 12 }}>{children}</div>}
        </div>
    );
}

export default function Settings({ sf, identity: identityIn, contact: contactIn, tracking: trackingIn, charges, presetColors, state, domains, reports, pageConnected }) {
    const [open, setOpen] = useState('identity');
    const toggle = (key) => setOpen(open === key ? '' : key);

    const identity = useForm({ section: 'identity', display_name: identityIn.display_name || '', theme_color: identityIn.theme_color || '#411550' });
    const contact = useForm({ section: 'contact', phone: contactIn.phone || '', whatsapp: contactIn.whatsapp || '', facebook: contactIn.facebook || '', address: contactIn.address || '', return_policy: contactIn.return_policy || '', footer_text: contactIn.footer_text || '' });
    const ordering = useForm({ section: 'ordering', delivery_inside: charges.delivery_inside ?? 60, delivery_outside: charges.delivery_outside ?? 120 });
    const tracking = useForm({ section: 'tracking', pixel_id: trackingIn.pixel_id || '', capi_token: '', ga4_id: trackingIn.ga4_id || '' });
    const domainForm = useForm({ domain: '' });
    const [logoUrl, setLogoUrl] = useState(identityIn.logoUrl || null);

    const save = (form) => form.post('/storefront/settings', { preserveScroll: true });

    const uploadLogoFile = async (file) => {
        const fd = new FormData();
        fd.append('logo', file);
        const xsrf = (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '';
        try {
            const res = await fetch('/storefront/logo', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' } });
            if (res.ok) { const j = await res.json(); setLogoUrl(j.url); }
        } catch { /* keep the old logo */ }
    };

    return (
        <AppLayout>
            <Head title={t('Store Settings')} />
            <h3 style={{ fontSize: 19, margin: '0 0 14px' }}>{t('Store Settings')}</h3>

            <Section title={t('Store name, logo & colour')} open={open === 'identity'} onToggle={() => toggle('identity')}>
                <div className="sf-form">
                    <label className="lbl">{t('Shop display name')}</label>
                    <input className="fld" value={identity.data.display_name} onChange={(e) => identity.setData('display_name', e.target.value)} maxLength={40} />
                    {identity.errors.display_name && <div style={{ color: '#B91C1C', fontSize: 13, marginTop: 5 }}>{identity.errors.display_name}</div>}
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Logo')}</label>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 6 }}>
                        {logoUrl && <img src={logoUrl} alt="" style={{ width: 52, height: 52, objectFit: 'contain', borderRadius: 8, border: '1px solid #E7DEF0' }} />}
                        <input type="file" accept="image/*" onChange={(e) => {
                            const file = e.target.files && e.target.files[0];
                            if (file) uploadLogoFile(file);
                        }} />
                    </div>
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Store colour')}</label>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 6, alignItems: 'center' }}>
                        {(presetColors || []).map((c) => (
                            <button key={c} type="button" aria-label={c} onClick={() => identity.setData('theme_color', c)}
                                style={{ width: 36, height: 36, borderRadius: '50%', background: c, border: identity.data.theme_color === c ? '3px solid #411550' : '2px solid #D9CDE4', cursor: 'pointer' }} />
                        ))}
                        <input type="color" value={identity.data.theme_color} onChange={(e) => identity.setData('theme_color', e.target.value)} style={{ width: 44, height: 36, border: '1px solid #D9CDE4', borderRadius: 8, background: '#fff', cursor: 'pointer' }} />
                        <input className="fld" style={{ width: 110, marginTop: 0 }} value={identity.data.theme_color} onChange={(e) => identity.setData('theme_color', e.target.value)} maxLength={7} />
                    </div>
                    <div style={{ fontSize: 13.5, color: 'var(--muted)', marginTop: 12 }}>
                        {t('Store type:')} <b>{state && state.mode === 'connect' ? t('Your own website (connected)') : t('Store here on SkaleUp')}</b>
                    </div>
                    <button className="btn pri" style={{ marginTop: 14 }} disabled={identity.processing} onClick={() => save(identity)}>{t('Save')}</button>
                </div>
            </Section>

            <Section title={t('Your link')} open={open === 'link'} onToggle={() => toggle('link')}>
                <div style={{ fontSize: 14.5, display: 'grid', gap: 8 }}>
                    <div>{t('Your store address - share it everywhere:')}</div>
                    <div style={{ fontWeight: 700, fontSize: 16, wordBreak: 'break-all', background: '#F7F2FA', border: '1px solid #E7DEF0', borderRadius: 8, padding: '9px 14px' }}>{sf.subdomainUrl || t('Not set yet')}</div>
                    <div style={{ fontWeight: 700 }}>Only one subdomain will be applied</div>
                    <div style={{ color: 'var(--muted)' }}>{t('One store gets one address, chosen when the store is made. It never changes - so customers always find you at the same place. Pages inside the store are unlimited, and every page link is made automatically.')}</div>
                    {sf.customDomainUrl && <div>{t('Your own domain:')} <b>{sf.customDomainUrl}</b></div>}
                </div>
            </Section>

            <Section title={t('Contact & address')} open={open === 'contact'} onToggle={() => toggle('contact')}>
                <div className="sf-form">
                    <label className="lbl">{t('Contact phone')}</label>
                    <input className="fld" value={contact.data.phone} onChange={(e) => contact.setData('phone', e.target.value)} placeholder="01XXXXXXXXX" />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('WhatsApp number (optional)')}</label>
                    <input className="fld" value={contact.data.whatsapp} onChange={(e) => contact.setData('whatsapp', e.target.value)} placeholder="01XXXXXXXXX" />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Facebook page link (optional)')}</label>
                    <input className="fld" value={contact.data.facebook} onChange={(e) => contact.setData('facebook', e.target.value)} placeholder="https://facebook.com/yourpage" />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Shop address')}</label>
                    <textarea className="fld" style={{ height: 74, paddingTop: 9 }} value={contact.data.address} onChange={(e) => contact.setData('address', e.target.value)} maxLength={300} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Return policy (shown on your store)')}</label>
                    <textarea className="fld" style={{ height: 74, paddingTop: 9 }} value={contact.data.return_policy} onChange={(e) => contact.setData('return_policy', e.target.value)} maxLength={1000} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Footer text (optional)')}</label>
                    <input className="fld" value={contact.data.footer_text} onChange={(e) => contact.setData('footer_text', e.target.value)} maxLength={300} />
                    <button className="btn pri" style={{ marginTop: 14 }} disabled={contact.processing} onClick={() => save(contact)}>{t('Save')}</button>
                </div>
            </Section>

            <Section title={t('Orders & delivery')} open={open === 'ordering'} onToggle={() => toggle('ordering')}>
                <div className="sf-form">
                    <label className="lbl">{t('Delivery charge - inside Dhaka (৳)')}</label>
                    <input className="fld" type="number" min="0" value={ordering.data.delivery_inside} onChange={(e) => ordering.setData('delivery_inside', e.target.value)} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Delivery charge - outside Dhaka (৳)')}</label>
                    <input className="fld" type="number" min="0" value={ordering.data.delivery_outside} onChange={(e) => ordering.setData('delivery_outside', e.target.value)} />
                    <button className="btn pri" style={{ marginTop: 14 }} disabled={ordering.processing} onClick={() => save(ordering)}>{t('Save')}</button>
                    <p style={{ fontSize: 13.5, color: 'var(--muted)', margin: '14px 0 0' }}>
                        {pageConnected
                            ? t('Your Facebook Page is connected - an Order on Messenger button shows on your store by itself.')
                            : t('Connect your Facebook Page (Settings → API Connection) and an Order on Messenger button will show on your store by itself.')}
                    </p>
                </div>
            </Section>

            <Section title={t('Advanced - domain & tracking')} open={open === 'advanced'} onToggle={() => toggle('advanced')}>
                <div style={{ fontWeight: 700, fontSize: 15, marginBottom: 6 }}>{t('Your own domain')}</div>
                <p style={{ fontSize: 14, color: 'var(--muted)', margin: '0 0 10px' }}>{t('Have your own domain (like yourshop.com)? Point it here in two steps: add it below, then add the TXT record we show at your domain provider. We check it for you - no waiting for anyone.')}</p>
                <div className="sf-form" style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap' }}>
                    <div style={{ flex: '1 1 220px' }}>
                        <label className="lbl">{t('Domain')}</label>
                        <input className="fld" value={domainForm.data.domain} onChange={(e) => domainForm.setData('domain', e.target.value)} placeholder="yourshop.com" />
                    </div>
                    <button className="btn pri" disabled={domainForm.processing} onClick={() => domainForm.post('/storefront/domains', { preserveScroll: true })}>{t('Add domain')}</button>
                </div>
                {domainForm.errors.domain && <div style={{ color: '#B91C1C', fontSize: 13, marginTop: 5 }}>{domainForm.errors.domain}</div>}
                <div style={{ display: 'grid', gap: 10, marginTop: 12 }}>
                    {(domains || []).map((d) => (
                        <div key={d.id} style={{ border: '1px solid #E7DEF0', borderRadius: 10, padding: '11px 13px' }}>
                            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                                <b>{d.domain}</b>
                                <span className={`pill ${d.status === 'verified' || d.status === 'active' ? 'green' : 'amber'}`}>{t(d.status === 'verified' || d.status === 'active' ? 'Verified' : 'Waiting for TXT record')}</span>
                                {d.isPrimary && <span className="pill">{t('Main domain')}</span>}
                                <span style={{ flex: 1 }} />
                                {!d.isPrimary && <button className="btn" onClick={() => router.post(`/storefront/domains/${d.id}/primary`, {}, { preserveScroll: true })}>{t('Make main')}</button>}
                                {d.status !== 'verified' && d.status !== 'active' && <button className="btn" onClick={() => router.post(`/storefront/domains/${d.id}/verify`, {}, { preserveScroll: true })}>{t('Check now')}</button>}
                                <button className="btn" style={{ color: '#B91C1C', borderColor: '#F3C2BF' }} onClick={async () => { if (await confirmDialog(t('Remove this domain from your store?'))) router.delete(`/storefront/domains/${d.id}`, { preserveScroll: true }); }}>{t('Remove')}</button>
                            </div>
                            {d.status !== 'verified' && d.status !== 'active' && (
                                <div style={{ fontSize: 13.5, marginTop: 8, background: '#F7F2FA', borderRadius: 8, padding: '9px 12px', wordBreak: 'break-all' }}>
                                    {t('Add this TXT record at your domain provider:')} <b>_skaleup-verify.{d.domain}</b> → <b>{d.txtValue}</b>
                                </div>
                            )}
                        </div>
                    ))}
                    {(domains || []).length === 0 && <div style={{ fontSize: 14, color: 'var(--muted)' }}>{t('No own domain added yet.')}</div>}
                </div>

                <div style={{ fontWeight: 700, fontSize: 15, margin: '20px 0 6px' }}>{t('Tracking')}</div>
                <div className="sf-form">
                    <label className="lbl">{t('Meta Pixel ID')}</label>
                    <input className="fld" value={tracking.data.pixel_id} onChange={(e) => tracking.setData('pixel_id', e.target.value)} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Meta Conversions API token')}</label>
                    <input className="fld" type="password" value={tracking.data.capi_token} onChange={(e) => tracking.setData('capi_token', e.target.value)} placeholder={trackingIn.capi_token_set ? '••••••••' : ''} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('GA4 measurement ID')}</label>
                    <input className="fld" value={tracking.data.ga4_id} onChange={(e) => tracking.setData('ga4_id', e.target.value)} placeholder="G-XXXXXXXXXX" />
                    <button className="btn pri" style={{ marginTop: 14 }} disabled={tracking.processing} onClick={() => save(tracking)}>{t('Save')}</button>
                </div>

                {(reports || []).length > 0 && (
                    <>
                        <div style={{ fontWeight: 700, fontSize: 15, margin: '20px 0 6px' }}>{t('Reports about your store')}</div>
                        <div style={{ display: 'grid', gap: 6 }}>
                            {reports.map((r) => (
                                <div key={r.id} style={{ fontSize: 14, borderBottom: '1px solid #F0EAF7', padding: '6px 0' }}>
                                    <span className={`pill ${r.status === 'open' ? 'red' : ''}`}>{r.status}</span> {r.reason} <span style={{ color: 'var(--muted)' }}>· {r.created}</span>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </Section>

            <style>{`.sf-form .lbl { display:block; font-size:13px; font-weight:600; }
.sf-form .fld { display:block; width:100%; height:40px; border-radius:8px; font-size:14px; border:1px solid #D9CDE4; background:#fff; padding:0 12px; margin-top:5px; font-family:inherit; color:var(--ink); box-sizing:border-box; }`}</style>
        </AppLayout>
    );
}
