import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';

export default function Connect({ endpoint, businessPublicId, apiKey, freshKey, apiOrders }) {
    const [copied, setCopied] = useState('');
    const webhook = useForm({ webhook_url: apiKey?.webhook_url || '' });
    const apiBase = endpoint;

    const copy = async (label, text) => {
        try { await navigator.clipboard.writeText(text); setCopied(label); setTimeout(() => setCopied(''), 1800); } catch { /* ignore */ }
    };
    const newKey = async () => {
        if (apiKey && !(await confirmDialog(t('Make a new connection key? The old key will stop working at once.')))) return;
        router.post('/storefront/connect/key', {}, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={t('Connect Website')} />
            <h3 style={{ fontSize: 19, margin: '0 0 6px' }}>{t('Connect Website')}</h3>
            <p style={{ color: 'var(--muted)', fontSize: 14, margin: '0 0 14px', maxWidth: 640 }}>
                {t('Your own website sends its orders here with a secret key, and they land in the same Orders list as every other order. Give the key and the address below to your website maker - that is all they need.')}
            </p>

            <div className="card sf-form" style={{ marginBottom: 12 }}>
                <div style={{ fontWeight: 700, fontSize: 15.5, marginBottom: 8 }}>1. {t('Connection key')}</div>
                {freshKey ? (
                    <div style={{ background: '#F0FDF4', border: '1px solid #BBF7D0', borderRadius: 10, padding: '12px 14px' }}>
                        <div style={{ fontSize: 14, marginBottom: 6 }}>{t('Your new key - copy it now. For safety it is shown only this once.')}</div>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                            <code style={{ fontWeight: 700, fontSize: 14.5, wordBreak: 'break-all', flex: '1 1 260px' }}>{freshKey}</code>
                            <button className="btn" onClick={() => copy('key', freshKey)}>{copied === 'key' ? t('Copied!') : t('Copy')}</button>
                        </div>
                    </div>
                ) : apiKey ? (
                    <div style={{ fontSize: 14.5 }}>{t('A key is active, ending in')} <b>…{apiKey.last4}</b>. {t('The full key was shown once when it was made.')}</div>
                ) : (
                    <div style={{ fontSize: 14.5, color: 'var(--muted)' }}>{t('No key yet. Make one to connect your website.')}</div>
                )}
                <button className="btn pri" style={{ marginTop: 12 }} onClick={newKey}>{apiKey ? t('Make a new key') : t('Make my connection key')}</button>
                <div style={{ marginTop: 16, display: 'grid', gap: 10 }}>
                    <div>
                        <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 4 }}>{t('Orders address (API)')}</div>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                            <code style={{ fontSize: 14, wordBreak: 'break-all', flex: '1 1 260px', background: '#F7F2FA', border: '1px solid #E7DEF0', borderRadius: 8, padding: '8px 12px' }}>{apiBase}</code>
                            <button className="btn" onClick={() => copy('api', apiBase)}>{copied === 'api' ? t('Copied!') : t('Copy')}</button>
                        </div>
                    </div>
                    <div>
                        <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 4 }}>{t('Your business ID (send it with every order)')}</div>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                            <code style={{ fontSize: 14, wordBreak: 'break-all', flex: '1 1 260px', background: '#F7F2FA', border: '1px solid #E7DEF0', borderRadius: 8, padding: '8px 12px' }}>{businessPublicId}</code>
                            <button className="btn" onClick={() => copy('biz', businessPublicId)}>{copied === 'biz' ? t('Copied!') : t('Copy')}</button>
                        </div>
                    </div>
                    <pre style={{ background: '#1F1430', color: '#E9E2F5', borderRadius: 10, padding: '12px 14px', fontSize: 12.5, overflowX: 'auto', margin: 0 }}>{`POST ${apiBase}
x-api-key: <your key>
businessId: ${businessPublicId}
{ "customerName": "Karim", "customerNumber": "017...",
  "customerAddress": "...", "district": "Dhaka",
  "products": [{ "productId": 1, "quantity": 1, "price": 1250 }] }`}</pre>
                </div>
            </div>

            <div className="card sf-form" style={{ marginBottom: 12 }}>
                <div style={{ fontWeight: 700, fontSize: 15.5, marginBottom: 8 }}>2. {t('Order updates back to your website (webhook)')}</div>
                <p style={{ fontSize: 14, color: 'var(--muted)', margin: '0 0 8px' }}>{t('Optional. When an order changes here, we send a signed note to this address so your website stays in step.')}</p>
                <label className="lbl">{t('Webhook URL')}</label>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <input className="fld" style={{ flex: '1 1 260px' }} value={webhook.data.webhook_url} onChange={(e) => webhook.setData('webhook_url', e.target.value)} placeholder="https://yourshop.com/skaleup-webhook" />
                    <button className="btn pri" disabled={webhook.processing} onClick={() => webhook.post('/storefront/connect/webhook', { preserveScroll: true })}>{t('Save')}</button>
                </div>
                {webhook.errors.webhook_url && <div style={{ color: '#B91C1C', fontSize: 13, marginTop: 5 }}>{webhook.errors.webhook_url}</div>}
            </div>

            <div className="card" style={{ marginBottom: 12 }}>
                <div style={{ fontWeight: 700, fontSize: 15.5, marginBottom: 8 }}>3. {t('Try a test order')}</div>
                <p style={{ fontSize: 14, color: 'var(--muted)', margin: '0 0 10px' }}>{t('Sends one real test order through the connection, so you can see it arrive in your Orders list. It uses one point like any order - delete it afterwards if you like.')}</p>
                <button className="btn pri" onClick={() => router.post('/storefront/connect/test-order', {}, { preserveScroll: true })}>{t('Send a test order')}</button>
            </div>

            <div className="card" style={{ padding: 0, overflowX: 'auto' }}>
                <div style={{ fontWeight: 700, fontSize: 15.5, padding: '14px 16px 4px' }}>{t('Latest orders from your website')}</div>
                <table className="tbl" style={{ width: '100%', minWidth: 520 }}>
                    <thead><tr><th>{t('Order')}</th><th>{t('Customer')}</th><th>{t('Total')}</th><th>{t('Status')}</th><th>{t('When')}</th></tr></thead>
                    <tbody>
                        {(apiOrders || []).map((o) => (
                            <tr key={o.invoice}><td style={{ fontWeight: 600 }}>{o.invoice}</td><td>{o.customer}</td><td>৳{Number(o.total).toLocaleString('en-IN')}</td><td>{o.status}</td><td style={{ whiteSpace: 'nowrap', fontSize: 13 }}>{o.at}</td></tr>
                        ))}
                        {(apiOrders || []).length === 0 && <tr><td colSpan={5} style={{ textAlign: 'center', color: 'var(--muted)', padding: 16 }}>{t('No orders from your website yet.')}</td></tr>}
                    </tbody>
                </table>
            </div>

            <style>{`.sf-form .lbl { display:block; font-size:13px; font-weight:600; }
.sf-form .fld { display:block; width:100%; height:40px; border-radius:8px; font-size:14px; border:1px solid #D9CDE4; background:#fff; padding:0 12px; margin-top:5px; font-family:inherit; color:var(--ink); box-sizing:border-box; }`}</style>
        </AppLayout>
    );
}
