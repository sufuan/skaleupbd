import { useEffect, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import DomainLocked from './DomainLocked';
import { t } from '@/lib/i18n';

const TEMPLATES = [
    ['single', 'Single product', 'One product, one page — best for a single hot item.'],
    ['store', 'Full store', 'All your products in a nice list, with an order form.'],
    ['offer', 'Offer page', 'A big offer banner on top, products below — best for campaigns.'],
];

export default function Wizard({ sf, onboarding, presetColors }) {
    const saved = onboarding;
    const phoneVerified = !!sf.phoneVerified;
    const hasPaid = !!sf.hasPaid;
    const domainRoot = sf.platformDomain;
    const initial = (saved && saved.payload) || {};
    const [step, setStep] = useState(saved ? Math.min(Number(saved.step) || 0, 4) : 0);
    const [hasWebsite, setHasWebsite] = useState(initial.has_website ?? null);
    const [template, setTemplate] = useState(initial.template || 'store');
    const [name, setName] = useState(initial.name || '');
    const [color, setColor] = useState(initial.color || '#411550');
    const [logoUrl, setLogoUrl] = useState(sf.logoUrl || null);
    const [slug, setSlug] = useState(initial.slug || '');
    const [agree, setAgree] = useState(false);
    const [avail, setAvail] = useState(null);
    const [pubError, setPubError] = useState(null);
    const [suggestions, setSuggestions] = useState([]);

    const otpSend = useForm({ phone: '' });
    const otpVerify = useForm({ code: '' });
    const [verified, setVerified] = useState(!!phoneVerified);
    const flash = usePage().props.flash || {};

    // Publish failures return as flash (message + slug suggestions).
    useEffect(() => {
        if (step === 4 && flash.error) setPubError(flash.error);
        if (flash.slug_suggestions && flash.slug_suggestions.length) setSuggestions(flash.slug_suggestions);
    }, [flash.error, flash.slug_suggestions]);

    // Verified state follows the server prop after the OTP round-trip.
    useEffect(() => { if (sf.phoneVerified) setVerified(true); }, [sf.phoneVerified]);

    const uploadLogoFile = async (file) => {
        const fd = new FormData();
        fd.append('logo', file);
        const xsrf = (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '';
        try {
            const res = await fetch('/storefront/logo', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' } });
            if (res.ok) { const j = await res.json(); setLogoUrl(j.url); }
        } catch { /* keep the old logo */ }
    };

    // The onboarding endpoint answers JSON ({ok, step}); the wizard
    // advances client-side and the saved step restores on reload.
    const save = async (s, payload, next) => {
        const xsrf = (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '';
        try {
            const res = await fetch('/storefront/onboarding', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' },
                body: JSON.stringify({ step: next !== undefined ? next : s, payload }),
            });
            if (res.ok && next !== undefined) setStep(next);
            return res.ok;
        } catch { return false; }
    };

    const checkSlug = async (value) => {
        setSlug(value);
        const v = value.trim().toLowerCase();
        if (v.length < 3) { setAvail(null); return; }
        try {
            const res = await fetch(`/storefront/availability?slug=${encodeURIComponent(v)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (res.ok) setAvail(await res.json());
        } catch { /* keep typing */ }
    };

    const publish = () => {
        setPubError(null);
        setSuggestions([]);
        router.post('/storefront/wizard/publish', { template, name, color, slug, agree }, { preserveScroll: true });
    };

    const stepsBar = (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 14 }}>
            {[t('Website?'), t('Design'), t('Name & colour'), t('Mobile verify'), t('Your link')].map((label, i) => (
                <span key={label} className={`pill ${i === step ? '' : i < step ? 'green' : 'amber'}`} style={i === step ? { background: '#411550', color: '#fff' } : undefined}>
                    {i + 1}. {label}
                </span>
            ))}
        </div>
    );

    return (
        <AppLayout>
            <Head title={t('Create your store')} />
            <h3 style={{ fontSize: 19, margin: '0 0 6px' }}>{t('Create your store')}</h3>
            <p style={{ color: 'var(--muted)', fontSize: 14, margin: '0 0 14px' }}>{t('Five easy steps. Your products come in automatically from your Inventory — nothing to add by hand.')}</p>
            {stepsBar}

            {!hasPaid && <DomainLocked />}

            {step === 0 && (
                <div className="card">
                    <div style={{ fontWeight: 700, fontSize: 16, marginBottom: 10 }}>{t('Do you already have your own website?')}</div>
                    <div style={{ display: 'grid', gap: 10 }}>
                        <button type="button" className="btn" style={{ justifyContent: 'flex-start', height: 'auto', padding: '12px 14px' }} onClick={async () => { setHasWebsite(true); if (await save(0, { has_website: true })) router.visit('/storefront/connect'); }}>
                            {t('Yes, I have my own website')} — {t('connect it and take orders there')}
                        </button>
                        <button type="button" className="btn pri" style={{ justifyContent: 'flex-start', height: 'auto', padding: '12px 14px' }} onClick={() => { setHasWebsite(false); save(0, { has_website: false }, 1); }}>
                            {t('No — make my store here')} — {t('free address, ready in minutes')}
                        </button>
                    </div>
                </div>
            )}

            {step === 1 && (
                <div className="card">
                    <div style={{ fontWeight: 700, fontSize: 16, marginBottom: 10 }}>{t('Choose a design')}</div>
                    <div style={{ display: 'grid', gap: 10 }}>
                        {TEMPLATES.map(([key, label, desc]) => (
                            <button key={key} type="button" onClick={() => setTemplate(key)}
                                style={{ textAlign: 'left', border: `2px solid ${template === key ? '#411550' : '#D9CDE4'}`, background: template === key ? '#F7F2FA' : '#fff', borderRadius: 10, padding: '12px 14px', cursor: 'pointer', fontFamily: 'inherit' }}>
                                <div style={{ fontWeight: 700, fontSize: 15 }}>{t(label)}</div>
                                <div style={{ fontSize: 13.5, color: 'var(--muted)' }}>{t(desc)}</div>
                            </button>
                        ))}
                    </div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                        <button className="btn" onClick={() => setStep(0)}>{t('Back')}</button>
                        <button className="btn pri" onClick={() => save(1, { template }, 2)}>{t('Save & continue')}</button>
                    </div>
                </div>
            )}

            {step === 2 && (
                <div className="card sf-form">
                    <div style={{ fontWeight: 700, fontSize: 16, marginBottom: 10 }}>{t('Store name, logo & colour')}</div>
                    <label className="lbl">{t('Store name')}</label>
                    <input className="fld" value={name} onChange={(e) => setName(e.target.value)} maxLength={60} placeholder={t('e.g. Rahim Fashion House')} />
                    <label className="lbl" style={{ marginTop: 12 }}>{t('Logo (optional)')}</label>
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
                            <button key={c} type="button" aria-label={c} onClick={() => setColor(c)}
                                style={{ width: 36, height: 36, borderRadius: '50%', background: c, border: color === c ? '3px solid #411550' : '2px solid #D9CDE4', cursor: 'pointer' }} />
                        ))}
                        <input type="color" value={color} onChange={(e) => setColor(e.target.value)} style={{ width: 44, height: 36, border: '1px solid #D9CDE4', borderRadius: 8, background: '#fff', cursor: 'pointer' }} />
                        <input className="fld" style={{ width: 110, marginTop: 0 }} value={color} onChange={(e) => setColor(e.target.value)} maxLength={7} />
                    </div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
                        <button className="btn" onClick={() => setStep(1)}>{t('Back')}</button>
                        <button className="btn pri" disabled={name.trim().length < 2} onClick={() => save(2, { name, color }, 3)}>{t('Save & continue')}</button>
                    </div>
                </div>
            )}

            {step === 3 && (
                <div className="card sf-form">
                    <div style={{ fontWeight: 700, fontSize: 16, marginBottom: 6 }}>{t('Verify your mobile number')}</div>
                    <p style={{ fontSize: 14, color: 'var(--muted)', margin: '0 0 10px' }}>{t('We send a small code by SMS. This proves the store is really yours.')}</p>
                    {verified ? (
                        <div className="pill green" style={{ fontSize: 14, padding: '6px 12px' }}>{t('Mobile verified')}</div>
                    ) : (
                        <>
                            <label className="lbl">{t('Mobile number')}</label>
                            <div style={{ display: 'flex', gap: 8 }}>
                                <input className="fld" style={{ flex: 1 }} value={otpSend.data.phone} onChange={(e) => otpSend.setData('phone', e.target.value)} placeholder="01XXXXXXXXX" inputMode="tel" />
                                <button className="btn" disabled={otpSend.processing} onClick={() => otpSend.post('/storefront/otp/send', { preserveScroll: true })}>{t('Send code')}</button>
                            </div>
                            {otpSend.errors.phone && <div style={{ color: '#B91C1C', fontSize: 13, marginTop: 5 }}>{otpSend.errors.phone}</div>}
                            <label className="lbl" style={{ marginTop: 12 }}>{t('Code from the SMS')}</label>
                            <div style={{ display: 'flex', gap: 8 }}>
                                <input className="fld" style={{ flex: 1 }} value={otpVerify.data.code} onChange={(e) => otpVerify.setData('code', e.target.value)} placeholder="123456" inputMode="numeric" />
                                <button className="btn pri" disabled={otpVerify.processing} onClick={() => otpVerify.post('/storefront/otp/verify', { preserveScroll: true })}>{t('Verify')}</button>
                            </div>
                            {otpVerify.errors.code && <div style={{ color: '#B91C1C', fontSize: 13, marginTop: 5 }}>{otpVerify.errors.code}</div>}
                        </>
                    )}
                    <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
                        <button className="btn" onClick={() => setStep(2)}>{t('Back')}</button>
                        <button className="btn pri" disabled={!verified} onClick={() => save(3, {}, 4)}>{t('Save & continue')}</button>
                    </div>
                </div>
            )}

            {step === 4 && (
                <div className="card sf-form">
                    <div style={{ fontWeight: 700, fontSize: 16, marginBottom: 6 }}>{t('Choose your link')}</div>
                    <p style={{ fontSize: 14, color: 'var(--muted)', margin: '0 0 10px' }}>{t('This is the address customers will visit. Choose carefully — it cannot be changed later.')}</p>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                        <input className="fld" style={{ flex: '1 1 180px' }} value={slug} onChange={(e) => checkSlug(e.target.value)} placeholder="your-shop-name" />
                        <span style={{ fontSize: 14.5, color: 'var(--muted)' }}>.{domainRoot}</span>
                    </div>
                    {avail && <div style={{ fontSize: 13.5, marginTop: 6, color: avail.state === 'available' ? '#15803d' : '#B91C1C' }}>{avail.message}</div>}
                    {suggestions.length > 0 && (
                        <div style={{ marginTop: 8, display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                            <span style={{ fontSize: 13.5, color: 'var(--muted)' }}>{t('Try one of these:')}</span>
                            {suggestions.map((s) => <button key={s} type="button" className="btn" onClick={() => checkSlug(s)}>{s}</button>)}
                        </div>
                    )}
                    <div style={{ background: '#F7F2FA', border: '1px solid #E7DEF0', borderRadius: 10, padding: '12px 14px', marginTop: 14, fontSize: 14, display: 'grid', gap: 5 }}>
                        <div>{t('One store, one link — pages inside the store are unlimited.')}</div>
                        <div style={{ fontWeight: 700 }}>Only one subdomain will be applied</div>
                        <div>{t('Your products and prices always stay true — the store reads them from your Inventory.')}</div>
                    </div>
                    <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', marginTop: 12, fontSize: 14.5, cursor: 'pointer' }}>
                        <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} style={{ marginTop: 3 }} />
                        <span>{t('I agree to the store rules — honest products, real prices, and delivery as promised.')}</span>
                    </label>
                    {pubError && <div style={{ color: '#B91C1C', fontSize: 14, marginTop: 8 }}>{pubError}</div>}
                    <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
                        <button className="btn" onClick={() => setStep(3)}>{t('Back')}</button>
                        <button className="btn pri" disabled={!agree || slug.trim().length < 3} onClick={publish}>{t('Publish my store')}</button>
                    </div>
                    {!hasPaid && <p style={{ fontSize: 13.5, color: '#B45309', marginTop: 10 }}>{t('Publishing opens after your first pack payment is approved.')} <Link href="/billing">{t('Go to Points & Billing')}</Link></p>}
                </div>
            )}

            <style>{`.sf-form .lbl { display:block; font-size:13px; font-weight:600; }
.sf-form .fld { display:block; width:100%; height:40px; border-radius:8px; font-size:14px; border:1px solid #D9CDE4; background:#fff; padding:0 12px; margin-top:5px; font-family:inherit; color:var(--ink); box-sizing:border-box; }`}</style>
        </AppLayout>
    );
}
