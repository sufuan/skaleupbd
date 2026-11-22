import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

export default function Published({ url }) {
    const shopUrl = url;
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try { await navigator.clipboard.writeText(shopUrl); setCopied(true); setTimeout(() => setCopied(false), 2000); } catch { /* clipboard unavailable */ }
    };
    const wa = `https://wa.me/?text=${encodeURIComponent(shopUrl)}`;

    return (
        <AppLayout>
            <Head title={t('Your store is live')} />
            <div className="card" style={{ textAlign: 'center', padding: '34px 20px' }}>
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" style={{ margin: '0 auto 10px', display: 'block' }}>
                    <circle cx="12" cy="12" r="11" fill="#15803d" />
                    <path d="M7 12.5l3.2 3L17 9" stroke="#fff" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                <h3 style={{ fontSize: 22, margin: '0 0 6px' }}>{t('Your store is live!')}</h3>
                <p style={{ color: 'var(--muted)', fontSize: 14.5, margin: '0 0 16px' }}>{t('Share this link with your customers — on Facebook, Messenger, anywhere.')}</p>
                <div style={{ display: 'flex', gap: 8, justifyContent: 'center', flexWrap: 'wrap', alignItems: 'center' }}>
                    <span style={{ fontWeight: 700, fontSize: 16, wordBreak: 'break-all', background: '#F7F2FA', border: '1px solid #E7DEF0', borderRadius: 8, padding: '9px 14px' }}>{shopUrl}</span>
                    <button className="btn" onClick={copy}>{copied ? t('Copied!') : t('Copy link')}</button>
                    <a className="btn pri" href={wa} target="_blank" rel="noreferrer">{t('Share on WhatsApp')}</a>
                </div>
                <div style={{ display: 'flex', gap: 8, justifyContent: 'center', marginTop: 22, flexWrap: 'wrap' }}>
                    <a className="btn" href={shopUrl} target="_blank" rel="noreferrer">{t('View shop')}</a>
                    <Link className="btn" href="/storefront/pages">{t('My Pages')}</Link>
                    <Link className="btn" href="/storefront">{t('Overview')}</Link>
                </div>
            </div>
        </AppLayout>
    );
}
