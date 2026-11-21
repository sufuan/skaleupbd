import { useEffect, useRef, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const STATE_STYLE = {
    available: ['#12713C', '✓'],
    taken: ['#B3261E', '✕'],
    blocked: ['#B3261E', '✕'],
    invalid: ['#B3261E', '✕'],
    checking: ['#6F6A7D', '…'],
};

export default function Subdomain({ sf }) {
    const form = useForm({ subdomain: sf.subdomain || '' });
    const [check, setCheck] = useState(null);
    const timer = useRef(null);

    useEffect(() => {
        const slug = (form.data.subdomain || '').trim().toLowerCase();
        if (!slug || slug === (sf.subdomain || '')) { setCheck(null); return; }
        setCheck({ state: 'checking', message: 'Checking…' });
        clearTimeout(timer.current);
        timer.current = setTimeout(async () => {
            try {
                const res = await fetch(`/storefront/availability?slug=${encodeURIComponent(slug)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                setCheck(await res.json());
            } catch { setCheck({ state: 'invalid', message: 'Could not check. Try again.' }); }
        }, 450);
        return () => clearTimeout(timer.current);
    }, [form.data.subdomain]);

    const submit = (e) => { e.preventDefault(); form.post('/storefront/subdomain', { preserveScroll: true }); };
    const [color, mark] = STATE_STYLE[check?.state] || ['#6F6A7D', ''];

    return (
        <AppLayout>
            <Head title={t('Subdomain')} />
            <h3 style={{ fontSize: 19, margin: '0 0 14px' }}>{t('Shop address')}</h3>
            <div className="card" style={{ maxWidth: 620 }}>
                <p style={{ fontSize: 14.5, color: 'var(--muted)', marginTop: 0 }}>
                    {t('This is your free shop address. Customers open it to see your storefront and order. It keeps working even after you connect your own domain.')}
                </p>
                <form onSubmit={submit}>
                    <label className="lbl">{t('Subdomain')}</label>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                        <input className="fld" style={{ flex: '1 1 180px', fontWeight: 600 }} placeholder="yourshop"
                            value={form.data.subdomain}
                            onChange={(e) => form.setData('subdomain', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                            maxLength={30} required />
                        <span style={{ color: 'var(--muted)', fontSize: 15 }}>.{sf.platformDomain}</span>
                    </div>
                    {check && (
                        <div style={{ color, fontSize: 14, marginTop: 8, fontWeight: 600 }} data-availability={check.state}>
                            {mark} {t(check.message)}
                        </div>
                    )}
                    <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 8 }}>
                        {t('3–30 characters: lowercase letters, numbers and hyphens. Brand and platform names are reserved.')}
                    </div>
                    <button className="btn pri" style={{ marginTop: 14 }} disabled={form.processing || (check && check.state !== 'available' && form.data.subdomain !== sf.subdomain)}>
                        {t('Save address')}
                    </button>
                </form>
                {sf.subdomainUrl && (
                    <div style={{ marginTop: 16, fontSize: 14.5 }}>
                        {t('Live address:')} <a href={sf.subdomainUrl} target="_blank" rel="noreferrer" style={{ fontWeight: 700 }}>{sf.subdomainUrl}</a>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
