import { useRef, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

export default function Branding({ sf, branding, charges, imageUsageMb, imageQuotaMb }) {
    const form = useForm({
        display_name: branding.display_name,
        theme_color: branding.theme_color,
        pixel_id: branding.pixel_id,
        ga4_id: branding.ga4_id,
        footer_text: branding.footer_text,
        capi_token: '',
        delivery_inside: charges.delivery_inside,
        delivery_outside: charges.delivery_outside,
        logo: null,
        remove_logo: false,
    });
    const fileRef = useRef(null);
    const [logoPreview, setLogoPreview] = useState(branding.logoUrl);

    const submit = (e) => {
        e.preventDefault();
        form.post('/storefront/branding', { preserveScroll: true, forceFormData: true });
    };

    return (
        <AppLayout>
            <Head title={t('Branding & Tracking')} />
            <h3 style={{ fontSize: 19, margin: '0 0 14px' }}>{t('Branding & Tracking')}</h3>
            <form onSubmit={submit} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, alignItems: 'start' }} className="sf-2col">
                <div className="card">
                    <h4 style={{ fontSize: 15.5, margin: '2px 0 10px' }}>{t('Shop branding')}</h4>
                    <label className="lbl">{t('Shop display name')}</label>
                    <input className="fld" value={form.data.display_name} onChange={(e) => form.setData('display_name', e.target.value)} required maxLength={80} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Logo')}</label>
                    <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                        {logoPreview && <img src={logoPreview} alt="" style={{ width: 52, height: 52, borderRadius: 10, objectFit: 'cover', border: '1px solid var(--border, #E7DEF0)' }} />}
                        <input ref={fileRef} type="file" accept="image/*" style={{ display: 'none' }} onChange={(e) => {
                            const f = e.target.files?.[0];
                            if (f) { form.setData('logo', f); form.setData('remove_logo', false); setLogoPreview(URL.createObjectURL(f)); }
                        }} />
                        <button type="button" className="btn" onClick={() => fileRef.current?.click()}>{t('Upload logo')}</button>
                        {logoPreview && <button type="button" className="btn" onClick={() => { form.setData('remove_logo', true); form.setData('logo', null); setLogoPreview(null); }}>{t('Remove')}</button>}
                    </div>
                    <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 6 }}>
                        {t('Images are converted to WebP. Storage used:')} {imageUsageMb} / {imageQuotaMb} MB
                    </div>
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Theme colour')}</label>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                        <input type="color" value={form.data.theme_color} onChange={(e) => form.setData('theme_color', e.target.value)} style={{ width: 52, height: 38, border: '1px solid var(--border, #E7DEF0)', borderRadius: 8, background: '#fff' }} />
                        <input className="fld" style={{ width: 120 }} value={form.data.theme_color} onChange={(e) => form.setData('theme_color', e.target.value)} />
                    </div>
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Footer text (optional)')}</label>
                    <input className="fld" value={form.data.footer_text} onChange={(e) => form.setData('footer_text', e.target.value)} maxLength={300} placeholder={t('e.g. a phone number or address line')} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Delivery charge — inside Dhaka (৳)')}</label>
                    <input className="fld" type="number" min="0" step="1" value={form.data.delivery_inside} onChange={(e) => form.setData('delivery_inside', e.target.value)} required />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Delivery charge — outside Dhaka (৳)')}</label>
                    <input className="fld" type="number" min="0" step="1" value={form.data.delivery_outside} onChange={(e) => form.setData('delivery_outside', e.target.value)} required />
                </div>

                <div className="card">
                    <h4 style={{ fontSize: 15.5, margin: '2px 0 10px' }}>{t('Ad tracking')}</h4>
                    <p style={{ fontSize: 13.5, color: 'var(--muted)', marginTop: 0 }}>
                        {t('PageView, ViewContent, InitiateCheckout and Purchase events fire from your storefront. Browser and server events share one event ID, so Meta counts each purchase once.')}
                    </p>
                    <label className="lbl">{t('Meta Pixel ID')}</label>
                    <input className="fld" value={form.data.pixel_id} onChange={(e) => form.setData('pixel_id', e.target.value)} placeholder="123456789012345" maxLength={40} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Meta Conversions API token')}</label>
                    <input className="fld" type="password" value={form.data.capi_token} onChange={(e) => form.setData('capi_token', e.target.value)}
                        placeholder={branding.capi_token_set ? '•••••••••• (saved — leave blank to keep)' : t('Paste the token from Meta Events Manager')} maxLength={500} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('GA4 measurement ID')}</label>
                    <input className="fld" value={form.data.ga4_id} onChange={(e) => form.setData('ga4_id', e.target.value)} placeholder="G-XXXXXXXXXX" maxLength={40} />
                    <button className="btn pri" style={{ marginTop: 18 }} disabled={form.processing}>{t('Save branding')}</button>
                </div>
            </form>
            <style>{`@media (max-width: 760px){ .sf-2col{grid-template-columns:1fr !important} }`}</style>
        </AppLayout>
    );
}
