import { useState } from 'react';
import { router } from '@inertiajs/react';
import { t } from '@/lib/i18n';

/** Credential fields per courier (§10.7a). secret fields are never shown back. */
export const FIELD_DEFS = {
    PATHAO: [
        ['client_id', 'Client Id'], ['client_secret', 'Client Secret', 'secret'],
        ['client_email', 'Client Email'], ['password', 'Password', 'secret'], ['store_id', 'Store Id'],
        ['default_city_id', 'Default City ID'], ['default_zone_id', 'Default Zone ID'], ['default_area_id', 'Default Area ID'],
        ['webhook_secret', 'Webhook Secret (Optional)', 'secret'], ['sandbox', 'Sandbox Mode (Test)', 'check'],
    ],
    STEADFAST: [
        ['api_key', 'ApiKey', 'secret'], ['api_secret', 'Api Secret', 'secret'],
        ['webhook_token', 'Webhook Token (Optional)', 'secret'],
    ],
    REDX: [
        ['access_token', 'Access Token', 'secret'], ['pickup_store', 'Pickup Store ID'],
        ['default_area', 'Default Area'], ['default_area_id', 'Default Area ID'],
    ],
    CARRYBEE: [
        ['client_id', 'Client ID'], ['client_secret', 'Client Secret', 'secret'], ['client_context', 'Client Context', 'secret'],
        ['store_id', 'Store ID'], ['product_type', 'Product Type Code'], ['delivery_type', 'Delivery Type Code'],
        ['default_weight', 'Default Parcel Weight (kg)'],
        ['default_city_id', 'Default City ID'], ['default_zone_id', 'Default Zone ID'], ['default_area_id', 'Default Area ID'],
        ['webhook_secret', 'Webhook Secret (Optional)', 'secret'],
        ['sandbox', 'Sandbox Mode (Test)', 'check'],
    ],
    ECOURIER: [
        ['api_key', 'API Key', 'secret'], ['api_secret', 'API Secret', 'secret'], ['user_id', 'User ID'],
        ['package_code', 'Default Package Code (Optional)'], ['pickup_hub_id', 'Pickup Hub ID (Optional)'],
    ],
    PAPERFLY: [
        ['api_key', 'API Key', 'secret'],
        ['username', 'Panel Username'], ['password', 'Panel Password', 'secret'],
        ['pickup_store', 'Pickup Store Name'],
        ['webhook_secret', 'Webhook Secret (Optional)', 'secret'],
    ],
    BAHOK: [],
    OTHERS: [],
};

/** Plain "how to get the API" guidance per courier (acquisition research 2026-11-17). */
const GUIDE = {
    STEADFAST: 'How to get these: register free at steadfast.com.bd/register, then in the merchant panel open API → Your API keys and copy the Api-Key and Secret-Key. There is no fee for API access.',
    PATHAO: 'How to get these: register free at merchant.pathao.com/register, add your store and payment info, then open the Developer API section in the panel for the Client ID and Secret. The username is your merchant login email. A new store takes about an hour to be approved.',
    REDX: 'How to get this: register at redx.com.bd, then in the merchant panel open Settings → developer section and generate the access token. If that section is missing, ask RedX support to enable Open API access. There is no fee for API access.',
    CARRYBEE: 'How to get these: register free at merchant.carrybee.com/register, then ask your Carrybee account manager (KAM) or support for the API credentials — they issue the Client ID, Secret and Context. There is no fee for API access. Sandbox access also comes from Carrybee technical support (ecomsupport@carrybee.com) — tick Sandbox Mode only with sandbox credentials they give you.',
    ECOURIER: 'How to get these: register at ecourier.com.bd and wait for eCourier to activate your account, then copy the API-KEY, API-SECRET and USER-ID from the panel\'s API credentials section — or ask eCourier support. The fraud check needs eCourier support to switch it on.',
    PAPERFLY: 'How to get these: sign in to the Paperfly merchant panel (go.paperfly.com.bd) and open Developer Guide — your API Key is printed inside the Order Creation code sample there. Tracking signs in with your panel username + password, so keep those here too. There is no sandbox: every booking is a real parcel.',
    BAHOK: 'Bahok has no public API — book in the Bahok app and record the CN number when sending, like a manual courier.',
    OTHERS: 'A manual courier needs no credentials — staff book in the courier\'s own app or branch and write the CN number when sending.',
};

const TYPE_LABELS = { PATHAO: 'Pathao', STEADFAST: 'Steadfast', REDX: 'RedX', CARRYBEE: 'Carrybee', PAPERFLY: 'Paperfly', ECOURIER: 'eCourier', BAHOK: 'Bahok', OTHERS: 'Manual (Other)' };

function MethodModal({ method, onClose }) {
    const isNew = !method?.id;
    const [type, setType] = useState(method?.courier_type || 'STEADFAST');
    const [name, setName] = useState(method?.name || '');
    const [inside, setInside] = useState(method?.charge_inside_dhaka ?? 60);
    const [outside, setOutside] = useState(method?.charge_outside_dhaka ?? 120);
    const [note, setNote] = useState(method?.default_shipping_note || '');
    const [creds, setCreds] = useState(() => {
        const out = {};
        (FIELD_DEFS[method?.courier_type || 'STEADFAST'] || []).forEach(([key, , kind]) => {
            const saved = method?.credentials?.[key];
            out[key] = kind === 'check' ? (saved?.value === 'yes') : (saved?.value ?? '');
        });
        return out;
    });

    const switchType = (tp) => {
        setType(tp);
        const out = {};
        (FIELD_DEFS[tp] || []).forEach(([key, , kind]) => { out[key] = kind === 'check' ? false : ''; });
        setCreds(out);
        if (!name) setName(TYPE_LABELS[tp]);
    };

    const save = () => {
        const payload = {
            name: name || TYPE_LABELS[type],
            courier_type: type,
            charge_inside_dhaka: Number(inside) || 0,
            charge_outside_dhaka: Number(outside) || 0,
            default_shipping_note: note || null,
            credentials: Object.fromEntries((FIELD_DEFS[type] || []).map(([key, , kind]) => [
                key, kind === 'check' ? (creds[key] ? 'yes' : 'no') : (creds[key] ?? ''),
            ])),
        };
        if (isNew) router.post('/settings/couriers', payload, { onSuccess: onClose });
        else router.patch(`/settings/couriers/${method.id}`, payload, { onSuccess: onClose });
    };

    return (
        <div className="overlay" onClick={onClose}>
            <div className="modal" style={{ maxWidth: 600 }} onClick={(e) => e.stopPropagation()}>
                <h3 style={{ marginTop: 0 }}>{isNew ? t('Add Courier') : `${t('Configure')} — ${method.label}`}</h3>
                {isNew && (
                    <div className="frow"><label className="fl">{t('Courier')}</label>
                        <select className="inp" value={type} onChange={(e) => switchType(e.target.value)}>
                            {Object.entries(TYPE_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </select></div>
                )}
                <div className="frow mt16"><label className="fl">{t('Name')}</label>
                    <input className="inp" value={name} onChange={(e) => setName(e.target.value)} placeholder={TYPE_LABELS[type]} /></div>
                {(FIELD_DEFS[type] || []).map(([key, label, kind]) => (
                    <div className="frow mt16" key={key}>
                        <label className="fl">{t(label)}</label>
                        {kind === 'check' ? (
                            <input type="checkbox" checked={!!creds[key]} onChange={(e) => setCreds({ ...creds, [key]: e.target.checked })} />
                        ) : (
                            <input className="inp" type={kind === 'secret' ? 'password' : 'text'} autoComplete="new-password"
                                value={kind === 'secret' ? (creds[key] ?? '') : (creds[key] ?? '')}
                                placeholder={method?.credentials?.[key]?.set ? `${t('Saved')} ${method.credentials[key].masked} — ${t('leave blank to keep')}` : ''}
                                onChange={(e) => setCreds({ ...creds, [key]: e.target.value })} />
                        )}
                    </div>
                ))}
                {GUIDE[type] && <p className="sm mut" style={{ marginTop: 10 }}>{t(GUIDE[type])}</p>}
                <div className="frow mt16"><label className="fl">{t('Inside Dhaka Charge')}</label>
                    <input className="inp" type="number" value={inside} onChange={(e) => setInside(e.target.value)} /></div>
                <div className="frow mt16"><label className="fl">{t('Outside Dhaka Charge')}</label>
                    <input className="inp" type="number" value={outside} onChange={(e) => setOutside(e.target.value)} /></div>
                <div className="frow mt16"><label className="fl">{t('Default Shipping Note')}</label>
                    <input className="inp" value={note} onChange={(e) => setNote(e.target.value)} /></div>
                {method?.webhook_url && (
                    <div className="frow mt16"><label className="fl">{t('Webhook URL')}</label>
                        <input className="inp" readOnly value={method.webhook_url} /></div>
                )}
                <div className="frow" style={{ gap: 8, marginTop: 20, justifyContent: 'flex-end' }}>
                    <button className="btn" onClick={onClose}>{t('Cancel')}</button>
                    <button className="btn pri" onClick={save}>{t('Save')}</button>
                </div>
            </div>
        </div>
    );
}

export default function CourierSettings({ couriers }) {
    const { methods = [], rules = [], products = [] } = couriers || {};
    const [editing, setEditing] = useState(null); // null | {} (new) | method
    const [rule, setRule] = useState({ scope: 'district', product_id: '', category: '', district: '', area: '', courier_method_id: '', priority: 10 });

    const saveRule = () => {
        router.post('/settings/courier-rules', {
            scope: rule.scope,
            product_id: rule.scope === 'product' ? Number(rule.product_id) || null : null,
            category: rule.scope === 'category' ? rule.category : null,
            district: rule.scope === 'district' ? rule.district : null,
            area: rule.scope === 'area' ? rule.area : null,
            courier_method_id: Number(rule.courier_method_id),
            priority: Number(rule.priority) || 10,
        }, { onSuccess: () => setRule({ scope: 'district', product_id: '', category: '', district: '', area: '', courier_method_id: '', priority: 10 }) });
    };

    return (
        <div>
            <div className="card">
                <h3>{t('Courier Integration')}</h3>
                <div className="card-sub">{t('Your own account with each courier — delivery charges live here, per courier. Only couriers switched on here appear in Send to Courier.')}</div>
                {methods.map((m) => (
                    <div className="conn-row" key={m.id}>
                        <div style={{ flex: 1 }}>
                            <div className="b">{m.name}
                                {m.is_default && <span className="pill green" style={{ marginLeft: 8 }}>{t('Default')}</span>}
                                {m.is_preferred && <span className="pill blue" style={{ marginLeft: 8 }}>{t('Fallback')}</span>}
                                <span className="pill" style={{ marginLeft: 8 }}>{t('Class')} {m.class}</span>
                            </div>
                            <div className="sm mut">{m.label} · {t('Inside Dhaka')} ৳{m.charge_inside_dhaka} · {t('Outside Dhaka')} ৳{m.charge_outside_dhaka}
                                {m.stores.length > 0 && <> · {m.stores.length} {t('pickup stores')}</>}</div>
                        </div>
                        <span className={`pill ${m.is_active ? 'green' : ''}`}>{m.is_active ? t('On') : t('Off')}</span>
                        <button className="btn" onClick={() => setEditing(m)}>{t('Configure')}</button>
                        {m.booking_mode === 'api' && <button className="btn" onClick={() => router.post(`/settings/couriers/${m.id}/test`)}>{t('Test Connection')}</button>}
                        {(m.courier_type === 'PATHAO' || m.courier_type === 'CARRYBEE') && <button className="btn" onClick={() => router.post(`/settings/couriers/${m.id}/sync-stores`)}>{t('Sync Stores')}</button>}
                        {!m.is_default && <button className="btn" onClick={() => router.post(`/settings/couriers/${m.id}/default`)}>{t('Set Default')}</button>}
                        {!m.is_preferred && <button className="btn" onClick={() => router.post(`/settings/couriers/${m.id}/preferred`)}>{t('Set Fallback')}</button>}
                        <button className="btn" onClick={() => router.post(`/settings/couriers/${m.id}/toggle`)}>{m.is_active ? t('Turn Off') : t('Turn On')}</button>
                        <button className="btn" onClick={() => { if (confirm(t('Remove this courier connection?'))) router.delete(`/settings/couriers/${m.id}`); }}>{t('Remove')}</button>
                    </div>
                ))}
                {methods.length === 0 && <p className="mut sm">{t('No courier connected yet — add your first courier below.')}</p>}
                <button className="btn pri" style={{ marginTop: 14 }} onClick={() => setEditing({})}>{t('Add Courier')}</button>
            </div>

            <div className="card" style={{ marginTop: 16 }}>
                <h3>{t('Which courier is suggested')}</h3>
                <div className="card-sub">{t('Rules are checked in this order: a courier picked on the order itself, then these rules, then the Default courier, then the best success rate.')}</div>
                {rules.map((r) => (
                    <div className="conn-row" key={r.id}>
                        <div style={{ flex: 1 }}>
                            <span className="b">{t(r.scope === 'product' ? 'Product rule' : r.scope === 'category' ? 'Category rule' : r.scope === 'district' ? 'District rule' : 'Area rule')}</span>
                            <span className="sm mut"> — {r.scope === 'product' ? (products.find((p) => p.id === r.product_id)?.name || r.product_id) : r.scope === 'category' ? r.category : r.scope === 'district' ? r.district : r.area} → {r.method_label} · {t('Priority')} {r.priority}</span>
                        </div>
                        <button className="btn" onClick={() => router.delete(`/settings/courier-rules/${r.id}`)}>{t('Remove')}</button>
                    </div>
                ))}
                <div className="flex" style={{ gap: 8, flexWrap: 'wrap', marginTop: 12 }}>
                    <select className="inp" style={{ maxWidth: 170 }} value={rule.scope} onChange={(e) => setRule({ ...rule, scope: e.target.value })}>
                        <option value="product">{t('Product rule')}</option>
                        <option value="category">{t('Category rule')}</option>
                        <option value="district">{t('District rule')}</option>
                        <option value="area">{t('Area rule')}</option>
                    </select>
                    {rule.scope === 'product' && (
                        <select className="inp" style={{ maxWidth: 220 }} value={rule.product_id} onChange={(e) => setRule({ ...rule, product_id: e.target.value })}>
                            <option value="">{t('Pick the product')}</option>
                            {products.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.sku})</option>)}
                        </select>
                    )}
                    {rule.scope === 'category' && <input className="inp" style={{ maxWidth: 200 }} placeholder={t('Category word in the product name')} value={rule.category} onChange={(e) => setRule({ ...rule, category: e.target.value })} />}
                    {rule.scope === 'district' && <input className="inp" style={{ maxWidth: 180 }} placeholder={t('District')} value={rule.district} onChange={(e) => setRule({ ...rule, district: e.target.value })} />}
                    {rule.scope === 'area' && <input className="inp" style={{ maxWidth: 180 }} placeholder={t('Area')} value={rule.area} onChange={(e) => setRule({ ...rule, area: e.target.value })} />}
                    <select className="inp" style={{ maxWidth: 200 }} value={rule.courier_method_id} onChange={(e) => setRule({ ...rule, courier_method_id: e.target.value })}>
                        <option value="">{t('Send with courier')}</option>
                        {methods.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                    <input className="inp" style={{ maxWidth: 90 }} type="number" title={t('Priority')} value={rule.priority} onChange={(e) => setRule({ ...rule, priority: e.target.value })} />
                    <button className="btn pri" disabled={!rule.courier_method_id} onClick={saveRule}>{t('Add Rule')}</button>
                </div>
            </div>

            {editing !== null && (
                <MethodModal method={editing.id ? editing : null} onClose={() => setEditing(null)} />
            )}
        </div>
    );
}
