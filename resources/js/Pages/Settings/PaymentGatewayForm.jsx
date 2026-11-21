import { useForm } from '@inertiajs/react';
import { t } from '@/lib/i18n';

/**
 * Payment Gateway settings card — ONE component, two scopes.
 * The seller scope (action /settings/payment-gateway) holds the
 * shop's own merchant accounts for its customers; the platform
 * scope (action /settings/payment-gateway/platform) holds the
 * platform's own accounts that sellers pay for points packs.
 * The server resolves the scope from the signed-in user — the form
 * itself never names a scope.
 */
export default function PaymentGatewayForm({ title, subtitle, data, action }) {
    const form = useForm({
        bkash_number: data?.bkash_number || '', bkash_enabled: !!data?.bkash_enabled,
        nagad_number: data?.nagad_number || '', nagad_enabled: !!data?.nagad_enabled,
        rocket_number: data?.rocket_number || '', rocket_enabled: !!data?.rocket_enabled,
        ssl_store_id: data?.ssl_store_id || '', ssl_enabled: !!data?.ssl_enabled, ssl_password: '',
        shurjo_username: data?.shurjo_username || '', shurjo_enabled: !!data?.shurjo_enabled, shurjo_password: '',
        instructions: data?.instructions || '',
    });
    const submit = (e) => { e.preventDefault(); form.post(action, { preserveScroll: true, onSuccess: () => form.reset('ssl_password', 'shurjo_password') }); };

    const numberRow = (label, numKey, enKey) => (
        <div style={{ padding: '10px 0', borderBottom: '1px solid var(--border, #E7DEF0)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <label className="lbl" style={{ flex: 1, margin: 0 }}>{label}</label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13.5 }}>
                    <input type="checkbox" checked={form.data[enKey]} onChange={(e) => form.setData(enKey, e.target.checked)} /> {t('Enabled')}
                </label>
            </div>
            <input className="fld" placeholder="01XXXXXXXXX" value={form.data[numKey]} onChange={(e) => form.setData(numKey, e.target.value)} />
        </div>
    );

    return (
        <div className="card">
            <h4 style={{ fontSize: 15.5, margin: '2px 0 4px' }}>{title}</h4>
            <p style={{ fontSize: 13.5, color: 'var(--muted)', margin: '0 0 6px' }}>{subtitle}</p>
            <style>{`.pg-form .lbl { display:block; font-size:13px; font-weight:600; margin-top:10px; }
.pg-form .fld { display:block; width:100%; height:40px; border-radius:8px; font-size:14px; border:1px solid #D9CDE4; background:#fff; padding:0 12px; margin-top:5px; font-family:inherit; color:var(--ink); box-sizing:border-box; }
.pg-form textarea.fld { height:auto; padding:8px 12px; }`}</style>
            <form onSubmit={submit} className="pg-form">
                {numberRow(t('bKash number (Personal)'), 'bkash_number', 'bkash_enabled')}
                {numberRow(t('Nagad number (Personal)'), 'nagad_number', 'nagad_enabled')}
                {numberRow(t('Rocket number (Personal)'), 'rocket_number', 'rocket_enabled')}

                <div style={{ padding: '12px 0', borderBottom: '1px solid var(--border, #E7DEF0)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <b style={{ fontSize: 14.5, flex: 1 }}>SSLCommerz</b>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13.5 }}>
                            <input type="checkbox" checked={form.data.ssl_enabled} onChange={(e) => form.setData('ssl_enabled', e.target.checked)} /> {t('Enabled')}
                        </label>
                    </div>
                    <label className="lbl">{t('Store ID')}</label>
                    <input className="fld" value={form.data.ssl_store_id} onChange={(e) => form.setData('ssl_store_id', e.target.value)} />
                    <label className="lbl">{t('Store password')}</label>
                    <input className="fld" type="password" autoComplete="new-password" placeholder={data?.ssl_password_set ? t('Saved — leave blank to keep') : ''} value={form.data.ssl_password} onChange={(e) => form.setData('ssl_password', e.target.value)} />
                </div>

                <div style={{ padding: '12px 0', borderBottom: '1px solid var(--border, #E7DEF0)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <b style={{ fontSize: 14.5, flex: 1 }}>ShurjoPay</b>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13.5 }}>
                            <input type="checkbox" checked={form.data.shurjo_enabled} onChange={(e) => form.setData('shurjo_enabled', e.target.checked)} /> {t('Enabled')}
                        </label>
                    </div>
                    <label className="lbl">{t('Username')}</label>
                    <input className="fld" value={form.data.shurjo_username} onChange={(e) => form.setData('shurjo_username', e.target.value)} />
                    <label className="lbl">{t('Password')}</label>
                    <input className="fld" type="password" autoComplete="new-password" placeholder={data?.shurjo_password_set ? t('Saved — leave blank to keep') : ''} value={form.data.shurjo_password} onChange={(e) => form.setData('shurjo_password', e.target.value)} />
                </div>

                <label className="lbl" style={{ marginTop: 12 }}>{t('Payment instructions (shown to the payer)')}</label>
                <textarea className="fld" rows={2} placeholder={t('e.g. Send money, then share the TrxID')} value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} />
                <button className="btn-primary" disabled={form.processing} style={{ marginTop: 14 }}>{t('Save payment gateway')}</button>
            </form>
        </div>
    );
}
