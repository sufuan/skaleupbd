import CourierSettings from './CourierSettings';
import PaymentGatewayForm from './PaymentGatewayForm';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { confirmDialog } from '@/lib/confirm';

const GROUPS = [
    { id: 'home', label: 'Settings Dashboard', tabs: [] },
    { id: 'business', label: 'Business Settings', tabs: [['profile', 'Business Profile'], ['merchant', 'Merchant Profile']] },
    { id: 'api', label: 'API Connection', tabs: [['courier', 'Courier Integration'], ['channels', 'Messaging Channels'], ['app', 'App Integration'], ['website', 'Website Integration'], ['gateway', 'Payment Gateway']] },
    { id: 'calls', label: 'Calls & Orders', tabs: [['sources', 'Order Sources'], ['templates', 'Templates'], ['pbx', 'PBX & Auto Call']] },
    { id: 'ai', label: 'AI & Auto Reply', tabs: [['brain', 'Rule Brain'], ['ailvl', 'AI Reply'], ['negotiation', 'Price & Negotiation'], ['knowledge', 'Knowledge']] },
    { id: 'security', label: 'Security & Records', tabs: [['devices', 'Device Approvals'], ['records', 'Deletion Logs & History'], ['advanced', 'Advanced Settings']] },
];

function Sw({ on = false }) {
    const [v, setV] = useState(on);
    return <button className={`switch ${v ? 'on' : ''}`} onClick={() => setV(!v)} aria-label="toggle" />;
}
function SaveBar() {
    return <div className="flex mt16"><span className="spacer" /><button className="btn pri">{t('Save Changes')}</button></div>;
}
function Field({ label, value, type = 'text', area = false }) {
    return <div className="frow"><label className="fl">{t(label)}</label>{area
        ? <textarea className="inp" rows="3" defaultValue={value} />
        : <input className="inp" type={type} defaultValue={value} />}</div>;
}
function ConnRow({ name, desc, status, pillCls }) {
    return (
        <div className="conn-row">
            <div className="conn-ic"><b>{name[0]}</b></div>
            <div style={{ flex: 1 }}><div className="b">{name}</div><div className="sm mut">{t(desc)}</div></div>
            <span className={`pill ${pillCls}`}>{t(status)}</span>
            <button className="btn">{t('Configure')}</button>
        </div>
    );
}

function Home() {
    const checks = [
        ['Business profile filled', true], ['At least one courier connected', true],
        ['Facebook Page connected', true], ['WhatsApp connected', true],
        ['Order sources set up', false], ['AI reply level chosen', false],
    ];
    const cards = [
        ['Business Settings', 'Profile complete', 'green'], ['API Connection', '4 of 6 connected', 'amber'],
        ['Calls & Orders', '3 sources active', 'green'], ['AI & Auto Reply', 'Draft only', 'amber'],
        ['Security & Records', '2 devices pending', 'amber'],
    ];
    return (
        <>
            <div className="frow"><input className="inp" placeholder={t('Search settings…')} /></div>
            <div className="card mb12">
                <h3>{t('Setup Checklist')}</h3><div className="card-sub">{t('When everything is done, the shop is fully live')}</div>
                {checks.map(([label, ok]) => (
                    <div className="check-row" key={label}>
                        <span className={`check-ic ${ok ? 'ok' : 'no'}`}>{ok ? '✓' : '!'}</span>{t(label)}
                        {!ok && <><span className="spacer" /><button className="btn">{t('Set up')}</button></>}
                    </div>
                ))}
            </div>
            <div className="grid set-home-grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
                {cards.map(([label, s, c]) => (
                    <div className="card stat-card" key={label}><div><div className="b">{t(label)}</div><div className="sm mut">{t(s)}</div></div><span className={`pill ${c}`}>{c === 'green' ? t('Done') : t('Pending')}</span></div>
                ))}
            </div>
        </>
    );
}

function Business({ tab, m2 }) {
    if (tab === 'merchant') return (
        <div className="card"><h3>{t('Merchant Profile')}</h3><div className="card-sub">{t('Verification details for courier and payment')}</div>
            <Field label="Trade Licence No." value="TRAD/DNCC/012345/2023" /><Field label="TIN" value="123456789012" />
            <Field label="Bank Account Name" value="Demo Shop" /><Field label="Bank / Branch" value="Sonali Bank — Mirpur 10" />
            <Field label="Account Number" value="•••• •••• 4832" /><SaveBar /></div>
    );
    return <BusinessProfileForm m2={m2} />;
}

function BusinessProfileForm({ m2 }) {
    const form = useForm({ ...m2.business });
    return (
        <form className="card" onSubmit={(e) => { e.preventDefault(); form.post('/settings/business', { preserveScroll: true }); }}>
            <h3>{t('Business Profile')}</h3><div className="card-sub">{t('This information shows on invoices and the storefront')}</div>
            <div className="frow"><label className="fl">{t('Shop Name')}</label><input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></div>
            <div className="frow"><label className="fl">{t('Phone')}</label><input className="inp" value={form.data.mobile} onChange={(e) => form.setData('mobile', e.target.value)} /></div>
            <div className="frow"><label className="fl">{t('Address')}</label><textarea className="inp" rows="3" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} /></div>
            <div className="frow"><label className="fl">{t('Invoice Prefix')}</label><input className="inp" style={{ maxWidth: 140 }} value={form.data.invoice_slug} onChange={(e) => form.setData('invoice_slug', e.target.value)} />
                <div className="sm mut mt8">{t('Invoice numbers look like')} {form.data.invoice_slug || 'OB'}287 — {t('changing the prefix only affects new orders')}</div></div>
            <div className="frow"><label className="fl">{t('Default Delivery Charge (৳)')}</label><input className="inp" type="number" min="0" style={{ maxWidth: 180 }} value={form.data.default_delivery_cost} onChange={(e) => form.setData('default_delivery_cost', e.target.value)} /></div>
            <div className="flex mt16"><span className="spacer" /><button type="submit" className="btn pri" disabled={form.processing}>{t('Save Changes')}</button></div>
        </form>
    );
}

function Api({ tab, couriers, channels, paymentGateway, platformPaymentGateway }) {
    if (tab === 'channels') return <ChannelsForm channels={channels} />;
    if (tab === 'gateway') return (
        <>
            {paymentGateway && (
                <PaymentGatewayForm
                    title={t('Payment Gateway — your shop')}
                    subtitle={t('Your own merchant accounts. Your customers pay YOU here — for example the advance-payment numbers shown on your storefront. Only your shop sees these.')}
                    data={paymentGateway}
                    action="/settings/payment-gateway"
                />
            )}
            {platformPaymentGateway && (
                <div style={{ marginTop: paymentGateway ? 12 : 0 }}>
                    <PaymentGatewayForm
                        title={t('Payment Gateway — platform (SkaleUp)')}
                        subtitle={t('The platform\'s own merchant accounts. Sellers pay US for points packs with these numbers — they appear on every seller\'s Billing page. Sellers never see or change these.')}
                        data={platformPaymentGateway}
                        action="/settings/payment-gateway/platform"
                    />
                </div>
            )}
        </>
    );
    if (tab === 'app') return (
        <div className="card"><h3>{t('App Integration')}</h3><div className="card-sub">{t('External API for outside apps and AI systems')}</div>
            <Field label="API Key (sk_…)" value="sk_••••••••••••••••••48" /><Field label="Webhook URL" value="https://demoshop.com.bd/hooks/skaleup" />
            <div className="sm mut">{t('Making a new key closes the old one right away. A key is shown in full only once, when it is created.')}</div><SaveBar /></div>
    );
    if (tab === 'website') return (
        <div className="card"><h3>{t('Website Integration')}</h3><div className="card-sub">{t('Orders will come in from your own website / WooCommerce')}</div>
            <Field label="Website URL" value="https://demoshop.com.bd" /><Field label="Platform" value="WooCommerce" />
            <ConnRow name="WooCommerce" desc="Plugin connected — order sync is on" status="Connected" pillCls="green" /><SaveBar /></div>
    );
    return <CourierSettings couriers={couriers} />;
}

function ChannelsForm({ channels }) {
    const c = channels || {};
    const form = useForm({
        app_id: c.app_id || '',
        app_secret: '',
        facebook_account_id: c.facebook?.account_id || '',
        facebook_page_name: c.facebook?.page_name || '',
        facebook_token: '',
        whatsapp_account_id: c.whatsapp?.account_id || '',
        whatsapp_waba_id: c.whatsapp?.waba_id || '',
        whatsapp_phone_number: c.whatsapp?.phone_number || '',
        whatsapp_token: '',
        instagram_account_id: c.instagram?.account_id || '',
        instagram_username: c.instagram?.username || '',
        instagram_token: '',
    });
    const [checks, setChecks] = useState({});
    const save = (e) => { e.preventDefault(); form.post('/settings/channels', { preserveScroll: true }); };
    const check = async (channel) => {
        setChecks((s) => ({ ...s, [channel]: t('Checking...') }));
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const res = await fetch('/settings/channels/check', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, Accept: 'application/json' }, body: JSON.stringify({ channel }) });
            const j = await res.json();
            setChecks((s) => ({ ...s, [channel]: j.detail || (j.ok ? 'Connected' : 'Failed') }));
        } catch { setChecks((s) => ({ ...s, [channel]: t('Check failed — try again') })); }
    };
    const savedNote = (set) => set ? t('Saved — leave blank to keep it') : t('Not saved yet');
    const block = (title, channelKey, cfg, children) => (
        <div className="card" style={{ marginBottom: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <h3 style={{ margin: 0 }}>{t(title)}</h3>
                <span style={{ marginLeft: 'auto', display: 'flex', gap: 8, alignItems: 'center' }}>
                    {checks[channelKey] ? <span style={{ fontSize: 12.5 }}>{checks[channelKey]}</span> : null}
                    <button type="button" className="btn" onClick={() => check(channelKey)}>{t('Check connection')}</button>
                </span>
            </div>
            {children}
        </div>
    );

    return (
        <form onSubmit={save}>
            <div className="card" style={{ marginBottom: 12 }}>
                <h3>{t('Meta App')}</h3>
                <div className="card-sub">{t('Messages from Facebook, WhatsApp and Instagram arrive at this webhook address. Put the same address and verify token in the Meta app.')}</div>
                <div className="frow"><label className="fl">{t('Webhook URL')}</label><input className="inp" readOnly value={c.webhook_url || ''} onFocus={(e) => e.target.select()} /></div>
                <div className="frow"><label className="fl">{t('Verify Token')}</label><input className="inp" readOnly value={c.verify_token || ''} onFocus={(e) => e.target.select()} /></div>
                <div className="frow"><label className="fl">{t('App ID')}</label><input className="inp" value={form.data.app_id} onChange={(e) => form.setData('app_id', e.target.value)} /></div>
                <div className="frow"><label className="fl">{t('App Secret')}</label><input className="inp" type="password" autoComplete="new-password" value={form.data.app_secret} onChange={(e) => form.setData('app_secret', e.target.value)} placeholder={savedNote(c.app_secret_set)} /></div>
            </div>

            {block('Facebook Page (Messenger)', 'facebook', c.facebook, (
                <>
                    <div className="frow"><label className="fl">{t('Page ID')}</label><input className="inp" value={form.data.facebook_account_id} onChange={(e) => form.setData('facebook_account_id', e.target.value)} /></div>
                    <div className="frow"><label className="fl">{t('Page name')}</label><input className="inp" value={form.data.facebook_page_name} onChange={(e) => form.setData('facebook_page_name', e.target.value)} /></div>
                    <div className="frow"><label className="fl">{t('Page Access Token')}</label><input className="inp" type="password" autoComplete="new-password" value={form.data.facebook_token} onChange={(e) => form.setData('facebook_token', e.target.value)} placeholder={savedNote(c.facebook?.token_set)} /></div>
                </>
            ))}

            {block('WhatsApp', 'whatsapp', c.whatsapp, (
                <>
                    <div className="frow"><label className="fl">{t('Phone Number ID')}</label><input className="inp" value={form.data.whatsapp_account_id} onChange={(e) => form.setData('whatsapp_account_id', e.target.value)} /></div>
                    <div className="frow"><label className="fl">{t('WhatsApp Business Account ID')}</label><input className="inp" value={form.data.whatsapp_waba_id} onChange={(e) => form.setData('whatsapp_waba_id', e.target.value)} /></div>
                    <div className="frow"><label className="fl">{t('Phone number')}</label><input className="inp" value={form.data.whatsapp_phone_number} onChange={(e) => form.setData('whatsapp_phone_number', e.target.value)} /></div>
                    <div className="frow"><label className="fl">{t('Permanent Access Token')}</label><input className="inp" type="password" autoComplete="new-password" value={form.data.whatsapp_token} onChange={(e) => form.setData('whatsapp_token', e.target.value)} placeholder={savedNote(c.whatsapp?.token_set)} /></div>
                </>
            ))}

            {block('Instagram', 'instagram', c.instagram, (
                <>
                    <div className="frow"><label className="fl">{t('Instagram User ID')}</label><input className="inp" value={form.data.instagram_account_id} onChange={(e) => form.setData('instagram_account_id', e.target.value)} /></div>
                    <div className="frow"><label className="fl">{t('Username')}</label><input className="inp" value={form.data.instagram_username} onChange={(e) => form.setData('instagram_username', e.target.value)} /></div>
                    <div className="frow"><label className="fl">{t('Access Token')}</label><input className="inp" type="password" autoComplete="new-password" value={form.data.instagram_token} onChange={(e) => form.setData('instagram_token', e.target.value)} placeholder={savedNote(c.instagram?.token_set)} /></div>
                </>
            ))}

            <div className="flex mt16"><span className="spacer" /><button className="btn pri" disabled={form.processing}>{t('Save Changes')}</button></div>
        </form>
    );
}

function Calls({ tab, m2 }) {
    if (tab === 'templates') return (<><TemplatesForm m2={m2} /><MarketingSmsForm ms={m2.marketing_sms} /></>);
    if (tab === 'pbx') return (
        <div className="card"><h3>{t('PBX & Auto Call')}</h3><div className="card-sub">{t('Auto Call rate ৳1.00/min — real robot calls start after the move to a server; for now staff call themselves and write the outcome')}</div>
            <div className="conn-row"><div style={{ flex: 1 }}><div className="b">{t('PBX Status')}</div><div className="sm mut">{t('Line active — 3 agents')}</div></div><span className="pill green">{t('Active')}</span><Sw on /></div>
            <div className="conn-row"><div style={{ flex: 1 }}><div className="b">{t('Auto Call')}</div><div className="sm mut">{t('Balance 420 min')}</div></div><span className="pill amber">{t('Manual mode')}</span><Sw /></div>
            <SaveBar /></div>
    );
    return <SourcesForm m2={m2} />;
}

function SourcesForm({ m2 }) {
    const [rows, setRows] = useState(m2.sources);
    useEffect(() => { setRows(m2.sources); }, [m2.sources]);
    const save = () => router.post('/settings/sources', { sources: rows.map((r) => ({ id: r.id, label: r.label, is_visible: !!r.is_visible })) }, { preserveScroll: true });
    return (
        <div className="card"><h3>{t('Order Sources')}</h3><div className="card-sub">{t('Which places orders may enter from — hidden sources do not appear on the New Order form')}</div>
            {rows.map((r, i) => (
                <div className="conn-row" key={r.id}>
                    <div style={{ flex: 1 }}><input className="inp" style={{ maxWidth: 260 }} value={r.label}
                        onChange={(e) => setRows(rows.map((x, j) => j === i ? { ...x, label: e.target.value } : x))} />
                        <div className="sm mut">{r.code}</div></div>
                    <button className={`switch ${r.is_visible ? 'on' : ''}`} aria-label="toggle source"
                        onClick={() => setRows(rows.map((x, j) => j === i ? { ...x, is_visible: !x.is_visible } : x))} />
                </div>
            ))}
            <div className="flex mt16">
                <button className="btn" onClick={() => router.post('/settings/sources/reset', {}, { preserveScroll: true })}>{t('Reset to Default')}</button>
                <span className="spacer" /><button className="btn pri" onClick={save}>{t('Save Changes')}</button>
            </div>
        </div>
    );
}

function MarketingSmsForm({ ms }) {
    const blank = { rate: ms?.rate ?? 1.5, cap_day: ms?.cap_day ?? 1, cap_week: ms?.cap_week ?? 3, add_balance: '', provider: ms?.provider ?? 'nishchit', mode: ms?.mode ?? 'demo', sender_id: ms?.sender_id ?? '', api_key: '', webhook_secret: '' };
    const form = useForm(blank);
    useEffect(() => { form.setData(blank); }, [ms]);
    const [check, setCheck] = useState(null);
    const runCheck = async () => {
        setCheck({ busy: true });
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        try {
            const res = await fetch('/settings/sms-check', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ key: form.data.api_key || '' }) });
            setCheck(await res.json());
        } catch {
            setCheck({ ok: false, error: t('Check failed — try again') });
        }
    };
    const test = useForm({ phone: '', message: 'SkaleUp test SMS' });
    return (
        <>
            <form className="card" style={{ marginTop: 16 }} onSubmit={(e) => { e.preventDefault(); form.post('/settings/marketing-sms', { preserveScroll: true, onSuccess: () => form.reset('api_key', 'webhook_secret') }); }}>
                <h3>{t('Marketing SMS')}</h3><div className="card-sub">{t('How campaign and automation SMS are priced and sent — the balance is prepaid and every SMS is charged from it')}</div>
                <div className="frow"><label className="fl">{t('SMS Balance')}</label><div className="b">৳{Number(ms?.balance ?? 0).toLocaleString('en-IN')}</div></div>
                <div className="frow"><label className="fl">{t('Add Balance (৳)')}</label><input className="inp" type="number" min="0" step="any" style={{ maxWidth: 200 }} value={form.data.add_balance} onChange={(e) => form.setData('add_balance', e.target.value)} placeholder="0" /></div>
                <div className="frow"><label className="fl">{t('Rate per SMS part (৳)')}</label><input className="inp" type="number" min="0" step="any" style={{ maxWidth: 200 }} value={form.data.rate} onChange={(e) => form.setData('rate', e.target.value)} /></div>
                <div className="frow"><label className="fl">{t('Frequency cap per customer')}</label>
                    <span style={{ display: 'inline-flex', gap: 8, alignItems: 'center' }}>
                        <input className="inp" type="number" min="0" style={{ maxWidth: 90 }} value={form.data.cap_day} onChange={(e) => form.setData('cap_day', e.target.value)} /> {t('per day')} ·
                        <input className="inp" type="number" min="0" style={{ maxWidth: 90 }} value={form.data.cap_week} onChange={(e) => form.setData('cap_week', e.target.value)} /> {t('per week')}
                    </span>
                    <div className="sm mut mt8">{t('Marketing SMS only — order news (thank-you, courier, delivered) is never capped')}</div></div>

                <div className="b" style={{ marginTop: 16 }}>{t('SMS Provider')}</div>
                <div className="sm mut">{t('Connect your own SMS provider account — in Live mode, SMS go out through your account')}</div>
                <div className="frow"><label className="fl">{t('Status')}</label>
                    {check && !check.busy
                        ? (check.ok ? <span className="pill green">{t('Connected')}</span> : <span className="pill red">{t('Not connected')}</span>)
                        : (ms?.has_key ? <span className="pill green">{t('Connected')}</span> : <span className="pill amber">{t('Not connected')}</span>)}
                    <button type="button" className="btn" style={{ marginLeft: 10 }} disabled={check?.busy} onClick={runCheck}>{t('Check connection')}</button></div>
                {check && !check.busy && (check.ok
                    ? <div className="sm" style={{ color: '#16A34A' }}>{t('Provider balance')}: ৳{Number(check.balance ?? 0).toLocaleString('en-IN')}</div>
                    : <div className="sm" style={{ color: '#DC2626' }}>{check.error}</div>)}
                <div className="frow"><label className="fl">{t('SMS provider')}</label>
                    <select className="inp" style={{ maxWidth: 300 }} value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}>
                        <option value="nishchit">Nishchit</option>
                        <option value="amarsms">Amar SMS</option>
                    </select></div>
                <div className="frow"><label className="fl">{t('Mode')}</label>
                    <select className="inp" style={{ maxWidth: 300 }} value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value)}>
                        <option value="demo">{t('Demo — logged only, no real SMS sent')}</option>
                        <option value="live">{t('Live — real SMS via your provider')}</option>
                    </select></div>
                <div className="frow"><label className="fl">{t('Sender ID (masking name)')}</label><input className="inp" style={{ maxWidth: 300 }} value={form.data.sender_id} onChange={(e) => form.setData('sender_id', e.target.value)} /></div>
                <div className="frow"><label className="fl">{t('API key')}</label><input className="inp" type="password" autoComplete="new-password" style={{ maxWidth: 300 }} value={form.data.api_key} onChange={(e) => form.setData('api_key', e.target.value)} placeholder={ms?.has_key ? t('Saved — leave blank to keep it') : ''} /></div>
                <div className="frow"><label className="fl">{t('Webhook secret')}</label><input className="inp" type="password" autoComplete="new-password" style={{ maxWidth: 300 }} value={form.data.webhook_secret} onChange={(e) => form.setData('webhook_secret', e.target.value)} placeholder={ms?.has_webhook_secret ? t('Saved — leave blank to keep it') : ''} /></div>
                <div className="flex mt16"><span className="spacer" /><button className="btn pri" disabled={form.processing}>{t('Save Changes')}</button></div>
            </form>

            <form className="card" style={{ marginTop: 16 }} onSubmit={(e) => { e.preventDefault(); test.post('/settings/sms-test', { preserveScroll: true }); }}>
                <h3>{t('Send test SMS')}</h3>
                <div className="card-sub">{t('Sends one SMS right now in the current mode — in Demo mode it is only logged, in Live mode your provider sends it for real')}</div>
                <div className="frow"><label className="fl">{t('Phone number')}</label><input className="inp" style={{ maxWidth: 300 }} value={test.data.phone} onChange={(e) => test.setData('phone', e.target.value)} placeholder="01XXXXXXXXX" /></div>
                <div className="frow"><label className="fl">{t('Message')}</label><input className="inp" style={{ maxWidth: 420 }} value={test.data.message} onChange={(e) => test.setData('message', e.target.value)} /></div>
                <div className="flex mt16"><span className="spacer" /><button className="btn pri" disabled={test.processing}>{t('Send test SMS')}</button></div>
            </form>
        </>
    );
}

function TemplatesForm({ m2 }) {
    const form = useForm({ reminder: m2.templates.reminder, advance: m2.templates.advance, invoice_template: m2.templates.invoice_template });
    useEffect(() => { form.setData({ reminder: m2.templates.reminder, advance: m2.templates.advance, invoice_template: m2.templates.invoice_template }); }, [m2.templates]);
    return (
        <form className="card" onSubmit={(e) => { e.preventDefault(); form.post('/settings/templates', { preserveScroll: true }); }}>
            <h3>{t('Templates')}</h3><div className="card-sub">{t('The invoice design and the SMS texts used by the order actions Send Reminder SMS and Send Advance SMS')}</div>
            <div className="frow"><label className="fl">{t('Invoice Template')}</label>
                <select className="inp" style={{ maxWidth: 300 }} value={form.data.invoice_template} onChange={(e) => form.setData('invoice_template', e.target.value)}>
                    <option value="a4">{t('A4 Full Page')}</option>
                    <option value="a5">{t('A5 Half Page')}</option>
                    <option value="thermal">{t('Thermal 4.5 × 3.5 inch')}</option>
                </select></div>
            <div className="frow"><label className="fl">{t('Order Reminder SMS')}</label>
                <textarea className="inp" rows="3" value={form.data.reminder} onChange={(e) => form.setData('reminder', e.target.value)} />
                <div className="sm mut mt8">{'{name}'} · {'{invoice}'} · {'{amount}'} · {'{shop}'} {t('are filled in from the order')}</div></div>
            <div className="frow"><label className="fl">{t('Advance Payment SMS')}</label>
                <textarea className="inp" rows="3" value={form.data.advance} onChange={(e) => form.setData('advance', e.target.value)} />
                <div className="sm mut mt8">{'{name}'} · {'{invoice}'} · {'{advance}'} · {'{shop}'} {t('are filled in from the order')}</div></div>
            <div className="flex mt16"><span className="spacer" /><button type="submit" className="btn pri" disabled={form.processing}>{t('Save Changes')}</button></div>
        </form>
    );
}

function Ai({ tab, ai }) {
    const rules = ai?.rules || [];
    const proposals = (ai?.proposals || []).filter((p) => p.status === 'pending');
    const [testText, setTestText] = useState('');
    const [testResult, setTestResult] = useState(null);
    const runTest = async () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const res = await fetch('/settings/ai-rules/test', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ text: testText }) });
        setTestResult(await res.json());
    };
    if (tab === 'ailvl') {
        return <><AiReplyForm ai={ai} /><TalkerForm ai={ai} /><BehaviorForm ai={ai} /></>;
    }
    if (tab === 'negotiation') {
        return <NegotiationForm ai={ai} />;
    }
    if (tab === 'knowledge') {
        return <KnowledgeForm ai={ai} />;
    }
    return (
        <div className="card"><h3>{t('Rule Brain')}</h3><div className="card-sub">{t('When a rule matches, the AI gives that answer — every number comes from live data')}</div>
            {rules.map((r) => <RuleRow key={r.id} rule={r} />)}
            {rules.length === 0 && <div className="sm mut">{t('No rules yet — add the first one below')}</div>}
            <RuleAddForm />
            <div className="rule-card note-box">
                <div className="b">{t('Learning from your edits')}</div>
                <div className="sm mut mt8">{t('The AI reads staff edits and conversations and proposes new rules here')} — <b>{t('no rule ever turns on unless a human approves it.')}</b> {proposals.length > 0 ? `${proposals.length} ${t('proposals are waiting now.')}` : t('No proposals are waiting.')}</div>
                {proposals.map((p) => (
                    <div key={p.id} className="rule-card" style={{ background: '#fff' }}>
                        <div className="b">{p.title}</div>
                        <div className="sm mut mt8">{p.detail}</div>
                        <div className="flex mt8" style={{ gap: 8 }}>
                            <button type="button" className="btn pri" onClick={() => router.post(`/settings/ai-proposals/${p.id}/approve`, {}, { preserveScroll: true })}>{t('Approve')}</button>
                            <button type="button" className="btn" onClick={() => router.post(`/settings/ai-proposals/${p.id}/reject`, {}, { preserveScroll: true })}>{t('Reject')}</button>
                        </div>
                    </div>
                ))}
            </div>
            <div className="frow"><label className="fl">{t('Try a message')}</label>
                <div className="flex" style={{ gap: 8, flex: 1 }}>
                    <input className="inp" style={{ flex: 1 }} placeholder={t('e.g. How much is this?')} value={testText} onChange={(e) => setTestText(e.target.value)} />
                    <button type="button" className="btn pri" onClick={runTest}>{t('Test')}</button>
                </div>
            </div>
            {testResult && (
                <div className="rule-card">
                    {testResult.matched
                        ? <><div className="b">{t('Rule')}: {testResult.rule} ({testResult.action})</div><div className="sm mt8">{testResult.reply}</div><div className="sm mut mt8">{testResult.verified ? t('Money figures verified against live data') : t('Money figures did NOT match — this would be held as a flagged draft')}</div></>
                        : <div className="sm">{testResult.note}</div>}
                </div>
            )}
        </div>
    );
}

function RuleRow({ rule }) {
    const form = useForm({ name: rule.name, trigger_type: rule.trigger_type, match_text: rule.match_text || '', action: rule.action, reply_template: rule.reply_template || '', active: rule.active });
    const [open, setOpen] = useState(false);
    const submit = (e) => { e.preventDefault(); form.patch(`/settings/ai-rules/${rule.id}`, { preserveScroll: true }); };
    return (
        <div className="rule-card">
            <div className="flex"><div style={{ flex: 1 }}><div className="b">{rule.name}</div>
                <div className="sm mut">{rule.trigger_type === 'intent' ? `${t('Intent')}: ${rule.match_text}` : `${t('Keywords')}: ${rule.match_text}`} · {rule.action}{rule.reply_template ? ` — ${rule.reply_template.slice(0, 70)}` : ''}</div></div>
                <button type="button" className={`switch ${form.data.active ? 'on' : ''}`} aria-label="toggle" onClick={() => { const next = !form.data.active; form.setData('active', next); router.patch(`/settings/ai-rules/${rule.id}`, { ...form.data, active: next }, { preserveScroll: true }); }} />
            </div>
            <div className="flex mt8" style={{ gap: 8 }}>
                <button type="button" className="btn" onClick={() => setOpen(!open)}>{t('Edit')}</button>
                <button type="button" className="btn" onClick={async () => { if (await confirmDialog(t('Delete this rule?'))) router.delete(`/settings/ai-rules/${rule.id}`, { preserveScroll: true }); }}>{t('Delete')}</button>
            </div>
            {open && (
                <form onSubmit={submit} className="mt8">
                    <div className="frow"><label className="fl">{t('Rule name')}</label><input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></div>
                    <div className="frow"><label className="fl">{t('Trigger')}</label>
                        <select className="inp" value={form.data.trigger_type} onChange={(e) => form.setData('trigger_type', e.target.value)}>
                            <option value="intent">{t('Intent')}</option><option value="keyword">{t('Keywords')}</option>
                        </select></div>
                    <div className="frow"><label className="fl">{t('Intent / Keywords')}</label><input className="inp" value={form.data.match_text} onChange={(e) => form.setData('match_text', e.target.value)} /></div>
                    <div className="frow"><label className="fl">{t('Action')}</label>
                        <select className="inp" value={form.data.action} onChange={(e) => form.setData('action', e.target.value)}>
                            <option value="answer">{t('Answer')}</option><option value="draft">{t('Draft')}</option><option value="hide">{t('Hide (comments)')}</option><option value="handover">{t('Hand to human')}</option>
                        </select></div>
                    <div className="frow"><label className="fl">{t('Reply template')}</label><textarea className="inp" rows="3" value={form.data.reply_template} onChange={(e) => form.setData('reply_template', e.target.value)} /></div>
                    <div className="sm mut">{'{product} · {price} · {stock} · {delivery_inside} · {delivery_outside} '}{t('are filled in from live data')}</div>
                    <SaveBar />
                </form>
            )}
        </div>
    );
}

function RuleAddForm() {
    const form = useForm({ name: '', trigger_type: 'keyword', match_text: '', action: 'answer', reply_template: '' });
    const [open, setOpen] = useState(false);
    if (!open) return <div className="mt8"><button type="button" className="btn pri" onClick={() => setOpen(true)}>{t('Add Rule')}</button></div>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/ai-rules', { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } }); }} className="rule-card">
            <div className="frow"><label className="fl">{t('Rule name')}</label><input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
            <div className="frow"><label className="fl">{t('Trigger')}</label>
                <select className="inp" value={form.data.trigger_type} onChange={(e) => form.setData('trigger_type', e.target.value)}>
                    <option value="intent">{t('Intent')}</option><option value="keyword">{t('Keywords')}</option>
                </select></div>
            <div className="frow"><label className="fl">{t('Intent / Keywords')}</label><input className="inp" value={form.data.match_text} onChange={(e) => form.setData('match_text', e.target.value)} placeholder={t('price, delivery, stock — or keywords separated by commas')} /></div>
            <div className="frow"><label className="fl">{t('Action')}</label>
                <select className="inp" value={form.data.action} onChange={(e) => form.setData('action', e.target.value)}>
                    <option value="answer">{t('Answer')}</option><option value="draft">{t('Draft')}</option><option value="hide">{t('Hide (comments)')}</option><option value="handover">{t('Hand to human')}</option>
                </select></div>
            <div className="frow"><label className="fl">{t('Reply template')}</label><textarea className="inp" rows="3" value={form.data.reply_template} onChange={(e) => form.setData('reply_template', e.target.value)} /></div>
            <SaveBar />
        </form>
    );
}

function AiReplyForm({ ai }) {
    const a = ai?.autonomy || {};
    const form = useForm({ facebook: a.facebook || 'draft', whatsapp: a.whatsapp || 'draft', instagram: a.instagram || 'draft', comment: a.comment || 'draft', working_hours: ai?.working_hours || '' });
    const MODES = [['off', 'Off'], ['draft', 'Draft only — AI writes, a human sends'], ['auto', 'Auto — simple answers sent by itself'], ['full_auto', 'Full auto — everything including bargaining']];
    const rows = [['facebook', 'Messenger'], ['whatsapp', 'WhatsApp'], ['instagram', 'Instagram'], ['comment', 'Comments']];
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/ai-reply', { preserveScroll: true }); }}>
            <div className="card"><h3>{t('AI Reply')}</h3><div className="card-sub">{t('How much the AI may do alone, per channel — for a new channel, starting with Draft only for the first 7 days is recommended')}</div>
                {rows.map(([key, label]) => (
                    <div className="frow" key={key}><label className="fl">{label}{key === 'comment' ? <span className="mut"> ({t('runs one step more cautiously')})</span> : ''}</label>
                        <select className="inp" value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)}>
                            {MODES.map(([v, l]) => <option key={v} value={v}>{t(l)}</option>)}
                        </select></div>
                ))}
                <div className="frow"><label className="fl">{t('Working Hours')}</label><input className="inp" value={form.data.working_hours} onChange={(e) => form.setData('working_hours', e.target.value)} /></div>
                <div className="sm mut">{t('Outside working hours the AI only drafts. The first 7 days in Draft only is a recommendation, not a lock.')}</div>
                <SaveBar />
            </div>
        </form>
    );
}

function TalkerForm({ ai }) {
    const tk = ai?.talker || {};
    const form = useForm({
        enabled: !!tk.enabled,
        normal_provider: tk.normal_provider || 'gemini',
        normal_model: tk.normal_model || 'gemini-3.5-flash',
        complex_provider: tk.complex_provider || 'claude',
        complex_model: tk.complex_model || 'claude-sonnet-4-5',
        route_angry: tk.route_angry !== false,
        route_long_bargain: tk.route_long_bargain !== false,
        gemini_key: '',
        claude_key: '',
    });
    const [checks, setChecks] = useState({});
    const runCheck = async (provider, typedKey) => {
        setChecks((c) => ({ ...c, [provider]: { busy: true } }));
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        try {
            const res = await fetch('/settings/ai-talker/test', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ provider, key: typedKey || '' }) });
            const json = await res.json();
            setChecks((c) => ({ ...c, [provider]: json }));
        } catch {
            setChecks((c) => ({ ...c, [provider]: { ok: false, detail: t('Check failed — try again') } }));
        }
    };
    const tier = (name, label) => {
        const provider = form.data[name + '_provider'];
        const keyField = provider + '_key';
        const keySet = provider === 'gemini' ? tk.gemini_key_set : tk.claude_key_set;
        const check = checks[provider];
        return (
            <>
                <div className="b" style={{ marginTop: 14 }}>{t(label)}</div>
                <div className="frow"><label className="fl">{t('Provider')}</label>
                    <select className="inp" value={provider} onChange={(e) => form.setData(name + '_provider', e.target.value)}>
                        <option value="gemini">Gemini (Google)</option>
                        <option value="claude">Claude (Anthropic)</option>
                    </select></div>
                <div className="frow"><label className="fl">{t('Model')}</label><input className="inp" value={form.data[name + '_model']} onChange={(e) => form.setData(name + '_model', e.target.value)} /></div>
                <div className="frow"><label className="fl">{t('API key')}</label>
                    <div className="flex" style={{ gap: 8, flex: 1 }}>
                        <input className="inp" type="password" autoComplete="new-password" style={{ flex: 1 }} value={form.data[keyField]} onChange={(e) => form.setData(keyField, e.target.value)} placeholder={keySet ? t('Saved — leave blank to keep it') : ''} />
                        <button type="button" className="btn" disabled={check?.busy} onClick={() => runCheck(provider, form.data[keyField])}>{t('Check connection')}</button>
                    </div></div>
                {check && !check.busy && (
                    <div className="sm" style={{ color: check.ok ? '#16A34A' : '#DC2626' }}>{check.detail}</div>
                )}
            </>
        );
    };
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/ai-talker', { preserveScroll: true, onSuccess: () => form.reset('gemini_key', 'claude_key') }); }}>
            <div className="card" style={{ marginTop: 16 }}><h3>{t('AI Model')}</h3>
                <div className="card-sub">{t('The model only writes the reply in a natural voice — the Rule Brain still decides every fact and figure, and every figure is re-checked before sending')}</div>
                <div className="frow"><label className="fl">{t('Use AI model for replies')}</label>
                    <button type="button" className={`switch ${form.data.enabled ? 'on' : ''}`} aria-label="toggle" onClick={() => form.setData('enabled', !form.data.enabled)} /></div>
                {tier('normal', 'Everyday replies')}
                {tier('complex', 'Complex replies')}
                <div className="b" style={{ marginTop: 14 }}>{t('Which replies use the complex model')}</div>
                <div className="frow"><label className="fl">{t('Angry customers')}</label>
                    <button type="button" className={`switch ${form.data.route_angry ? 'on' : ''}`} aria-label="toggle" onClick={() => form.setData('route_angry', !form.data.route_angry)} /></div>
                <div className="frow"><label className="fl">{t('Long bargaining (3rd offer onwards)')}</label>
                    <button type="button" className={`switch ${form.data.route_long_bargain ? 'on' : ''}`} aria-label="toggle" onClick={() => form.setData('route_long_bargain', !form.data.route_long_bargain)} /></div>
                <SaveBar />
            </div>
        </form>
    );
}

function BehaviorForm({ ai }) {
    const b = ai?.behavior || {};
    const form = useForm({
        angry_holding: !!b.angry_holding,
        honorific: b.honorific || 'auto',
        daily_llm_cap: b.daily_llm_cap ?? 0,
        debounce_seconds: b.debounce_seconds ?? 0,
    });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/ai-behavior', { preserveScroll: true }); }}>
            <div className="card" style={{ marginTop: 16 }}><h3>{t('AI Behaviour')}</h3>
                <div className="card-sub">{t('How the AI speaks and how much it may spend — code enforces every limit here')}</div>
                <div className="frow"><label className="fl">{t('Holding line for angry customers')}</label>
                    <button type="button" className={`switch ${form.data.angry_holding ? 'on' : ''}`} aria-label="toggle" onClick={() => form.setData('angry_holding', !form.data.angry_holding)} /></div>
                <div className="sm mut">{t('One short line — "our admin has been told" — then a human takes over. Off = fully silent handover.')}</div>
                <div className="frow"><label className="fl">{t('How replies address the customer')}</label>
                    <select className="inp" value={form.data.honorific} onChange={(e) => form.setData('honorific', e.target.value)}>
                        <option value="auto">{t('Auto — from the customer name')}</option>
                        <option value="apu">{t('Everyone: আপু')}</option>
                        <option value="bhaiya">{t('Everyone: ভাইয়া')}</option>
                        <option value="none">{t('No honorific')}</option>
                    </select></div>
                <div className="frow"><label className="fl">{t('Daily AI model call limit (0 = no limit)')}</label><input className="inp" type="number" min="0" value={form.data.daily_llm_cap} onChange={(e) => form.setData('daily_llm_cap', e.target.value)} /></div>
                <div className="frow"><label className="fl">{t('Wait seconds before answering a burst of messages (0 = off)')}</label><input className="inp" type="number" min="0" max="60" value={form.data.debounce_seconds} onChange={(e) => form.setData('debounce_seconds', e.target.value)} /></div>
                <div className="sm mut">{t('Customers often send 3-4 short messages in a row. With this on, the AI waits, reads them together, and answers once.')}</div>
                <SaveBar />
            </div>
        </form>
    );
}

function NegotiationForm({ ai }) {
    const n = ai?.negotiation || { max: 15, step: 5, free_delivery_min: 1000 };
    const form = useForm({ max_discount: n.max, step: n.step, free_delivery_min: n.free_delivery_min });
    const price = 600;
    const rungs = [];
    for (let d = 0; d <= form.data.max_discount; d += Math.max(1, Number(form.data.step) || 5)) rungs.push(Math.round(price * (1 - d / 100)));
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/ai-negotiation', { preserveScroll: true }); }}>
            <div className="card"><h3>{t('Price & Negotiation')}</h3><div className="card-sub">{t('The AI lowers the price step by step — never below the floor, held back by code')}</div>
                <div className="frow"><label className="fl">{t('Maximum discount (%)')}</label><input className="inp" type="number" value={form.data.max_discount} onChange={(e) => form.setData('max_discount', e.target.value)} /></div>
                <div className="frow"><label className="fl">{t('Discount per step (%)')}</label><input className="inp" type="number" value={form.data.step} onChange={(e) => form.setData('step', e.target.value)} /></div>
                <div className="rule-card"><div className="b">{t('Price ladder (example)')}</div>
                    <div className="flex mt8" style={{ gap: 8, flexWrap: 'wrap' }}>
                        {rungs.map((x, i) => <span key={i} style={{ display: 'contents' }}>{i > 0 && <span>→</span>}<span className={i === rungs.length - 1 ? 'pill red' : 'pill'}>৳{x}{i === rungs.length - 1 ? ' (floor)' : ''}</span></span>)}
                    </div></div>
                <div className="frow"><label className="fl">{t('Free delivery (at least ৳)')}</label><input className="inp" type="number" value={form.data.free_delivery_min} onChange={(e) => form.setData('free_delivery_min', e.target.value)} /></div>
                <SaveBar />
            </div>
        </form>
    );
}

function KnowledgeForm({ ai }) {
    const entries = ai?.knowledge || [];
    const add = useForm({ title: '', body: '' });
    return (
        <div className="card"><h3>{t('Knowledge')}</h3><div className="card-sub">{t('The AI reads these notes to build answers — if it is not here, the AI will not make it up')}</div>
            {entries.map((e) => <KnowledgeRow key={e.id} entry={e} />)}
            <form onSubmit={(ev) => { ev.preventDefault(); add.post('/settings/ai-knowledge', { preserveScroll: true, onSuccess: () => add.reset() }); }} className="rule-card">
                <div className="frow"><label className="fl">{t('New note title')}</label><input className="inp" value={add.data.title} onChange={(e) => add.setData('title', e.target.value)} required /></div>
                <div className="frow"><label className="fl">{t('Note')}</label><textarea className="inp" rows="3" value={add.data.body} onChange={(e) => add.setData('body', e.target.value)} required /></div>
                <SaveBar />
            </form>
        </div>
    );
}

function KnowledgeRow({ entry }) {
    const form = useForm({ title: entry.title, body: entry.body });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.patch(`/settings/ai-knowledge/${entry.id}`, { preserveScroll: true }); }} className="rule-card">
            <div className="frow"><label className="fl">{entry.title}</label><textarea className="inp" rows="3" value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} /></div>
            <div className="flex" style={{ gap: 8 }}>
                <span className="spacer" />
                <button type="button" className="btn" onClick={async () => { if (await confirmDialog(t('Delete this note?'))) router.delete(`/settings/ai-knowledge/${entry.id}`, { preserveScroll: true }); }}>{t('Delete')}</button>
                <button type="submit" className="btn pri">{t('Save Changes')}</button>
            </div>
        </form>
    );
}

function Security({ tab, totpEnabled, totpEnrolled, m2 }) {
    if (tab === 'records') return (
        <>
            <div className="card mb12"><h3>{t('Deletion Logs')}</h3><div className="card-sub">{t('Records of deleted items are kept for 7 days')}</div>
                <table className="tbl"><thead><tr><th>{t('Item')}</th><th>{t('Deleted By')}</th><th>{t('Reason')}</th><th>{t('When')}</th></tr></thead>
                    <tbody>
                        {m2.deletionLogs.map((l, i) => <tr key={i}><td>{t('Order')} {l.ref}</td><td>{l.by}</td><td>{l.reason}</td><td>{l.when}</td></tr>)}
                        {m2.deletionLogs.length === 0 && <tr><td colSpan="4" className="mut">{t('Nothing has been deleted yet')}</td></tr>}
                    </tbody></table></div>
            <div className="card"><h3>{t('Settings Change History')}</h3><div className="card-sub">{t('Who changed which setting, and when — record only, no rollback (v1)')}</div>
                <table className="tbl"><thead><tr><th>{t('Setting')}</th><th>{t('Change')}</th><th>{t('By')}</th><th>{t('When')}</th></tr></thead>
                    <tbody>
                        {m2.changeHistory.map((c, i) => <tr key={i}><td>{c.key}</td><td>{c.old} → {c.new}</td><td>{c.by}</td><td>{c.when}</td></tr>)}
                        {m2.changeHistory.length === 0 && <tr><td colSpan="4" className="mut">{t('No setting has been changed yet')}</td></tr>}
                    </tbody></table></div>
        </>
    );
    if (tab === 'advanced') {
        const toggleTotp = () => router.post('/settings/totp', { enabled: !totpEnabled }, { preserveScroll: true });
        return (
            <>
                <div className="card mb12"><h3>{t('Advanced Settings')}</h3><div className="card-sub">{t('Change with care — these affect the whole shop')}</div>
                    <div className="conn-row">
                        <div style={{ flex: 1 }}>
                            <div className="b">{t('Two-Factor Login (TOTP)')}</div>
                            <div className="sm mut">{totpEnabled
                                ? (totpEnrolled ? t('On — your account asks for an authenticator code at every login') : t('On — set up your authenticator to finish turning it on for your account'))
                                : t('When on, every login also needs a 6-digit code from Google Authenticator')}</div>
                        </div>
                        {totpEnabled && !totpEnrolled && <Link href="/totp/setup" className="btn pri">{t('Set up authenticator')}</Link>}
                        <button className={`switch ${totpEnabled ? 'on' : ''}`} onClick={toggleTotp} aria-label="toggle two-factor login" />
                    </div>
                    <div className="conn-row"><div style={{ flex: 1 }}><div className="b">{t('Require Device Approval')}</div><div className="sm mut">{t('A new device cannot enter until an admin approves it')}</div></div><Sw on /></div>
                    <div className="conn-row"><div style={{ flex: 1 }}><div className="b" style={{ color: 'var(--bad)' }}>{t('Reset Shop Data')}</div><div className="sm mut">{t('Every order and product will be deleted — this cannot be undone')}</div></div><button className="btn" style={{ color: 'var(--bad)', borderColor: '#f0caca' }}>{t('Reset')}</button></div>
                </div>
                <OrderTogglesForm m2={m2} />
                <InventorySettingsForm m2={m2} />
                <FinanceSettingsForm m2={m2} />
            </>
        );
    }
    return (
        <div className="card"><h3>{t('Device Approvals')}</h3><div className="card-sub">{t('When someone logs in from a new device, approve it here')}</div>
            {[['Chrome — Windows (Dhaka)', 'Pending'], ['bKash App — Android', 'Pending'], ['Chrome — Mac (this device)', 'Approved']].map(([d, s2]) => (
                <div className="conn-row" key={d}><div style={{ flex: 1 }} className="b">{d}</div>
                    <span className={`pill ${s2 === 'Pending' ? 'amber' : 'green'}`}>{t(s2)}</span>
                    {s2 === 'Pending' && <button className="btn pri">{t('Approve')}</button>}</div>
            ))}<SaveBar /></div>
    );
}

function FinanceSettingsForm({ m2 }) {
    const [fin, setFin] = useState(m2.finance);
    useEffect(() => { setFin(m2.finance); }, [m2.finance]);
    if (!fin) return null;
    return (
        <div className="card"><h3>{t('Finance')}</h3><div className="card-sub">{t('How the Finance module behaves for this shop')}</div>
            <div className="conn-row">
                <div style={{ flex: 1 }}><div className="b">{t('Daily Closing')}</div><div className="sm mut">{t('Match the cash in hand against the books every day; a mismatch raises a Work Inbox task')}</div></div>
                <button className={`switch ${fin.daily_closing ? 'on' : ''}`} aria-label="Daily Closing" onClick={() => setFin({ ...fin, daily_closing: !fin.daily_closing })} />
            </div>
            <div className="conn-row">
                <div style={{ flex: 1 }}><div className="b">{t('Advance Money Goes To')}</div><div className="sm mut">{t('When a customer pays an order advance (bKash/Nagad), the money lands in this account')}</div></div>
                <select className="inp" style={{ maxWidth: 240 }} value={fin.advance_account_id || ''} onChange={(e) => setFin({ ...fin, advance_account_id: Number(e.target.value) || 0 })}>
                    <option value="">{t('First mobile account')}</option>
                    {(fin.accounts || []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </select>
            </div>
            <div className="flex mt16"><span className="spacer" /><button className="btn pri" onClick={() => router.post('/settings/finance', { daily_closing: !!fin.daily_closing, advance_account_id: fin.advance_account_id || 0 }, { preserveScroll: true })}>{t('Save Changes')}</button></div>
        </div>
    );
}

function InventorySettingsForm({ m2 }) {
    const [inv, setInv] = useState(m2.inventory);
    useEffect(() => { setInv(m2.inventory); }, [m2.inventory]);
    const rows = [
        ['branch_stock_enabled', 'Multi Warehouse / Branch Stock', 'Each branch keeps its own stock count; transfers move stock between branches'],
        ['warehouse_cartons', 'Warehouse Cartons', 'Transfers also record how many cartons move'],
        ['stock_management_disabled', 'Disable Stock Management', 'Orders flow without reserving or deducting stock'],
    ];
    return (
        <div className="card"><h3>{t('Inventory')}</h3><div className="card-sub">{t('How stock is kept across the shop')}</div>
            {rows.map(([key, label, desc]) => (
                <div className="conn-row" key={key}>
                    <div style={{ flex: 1 }}><div className="b">{t(label)}</div><div className="sm mut">{t(desc)}</div></div>
                    <button className={`switch ${inv[key] ? 'on' : ''}`} aria-label={label} onClick={() => setInv({ ...inv, [key]: !inv[key] })} />
                </div>
            ))}
            <div className="conn-row">
                <div style={{ flex: 1 }}><div className="b">{t('Stock Valuation')}</div><div className="sm mut">{t('How the Stock Ledger values your stock')}</div></div>
                <select className="inp" style={{ maxWidth: 240 }} value={inv.valuation} onChange={(e) => setInv({ ...inv, valuation: e.target.value })}>
                    <option value="average_cost">{t('Product Average Cost')}</option>
                </select>
            </div>
            <div className="flex mt16"><span className="spacer" /><button className="btn pri" onClick={() => router.post('/settings/inventory', inv, { preserveScroll: true })}>{t('Save Changes')}</button></div>
        </div>
    );
}

function OrderTogglesForm({ m2 }) {
    const [tg, setTg] = useState(m2.toggles);
    useEffect(() => { setTg(m2.toggles); }, [m2.toggles]);
    const rows = [
        ['show_email', 'Show Email Field', 'The New Order form shows an email field'],
        ['show_alt_number', 'Show Alternative Number Field', 'The New Order form shows a second phone number field'],
        ['mandatory_source', 'Order Source is Mandatory', 'A new order cannot be saved without choosing its source'],
        ['mandatory_shipping_note', 'Shipping Note is Mandatory', 'A new order cannot be saved without a shipping note'],
        ['mandatory_advance_txn', 'Advance Needs a Transaction ID', 'When an advance is taken, the bKash/Nagad transaction ID must be written'],
        ['cross_sale', 'Cross Sale Suggestions', 'Related products can be offered while taking an order'],
    ];
    return (
        <div className="card"><h3>{t('New Order Form')}</h3><div className="card-sub">{t('These switches shape the New Order form — change one and the form changes')}</div>
            {rows.map(([key, label, desc]) => (
                <div className="conn-row" key={key}>
                    <div style={{ flex: 1 }}><div className="b">{t(label)}</div><div className="sm mut">{t(desc)}</div></div>
                    <button className={`switch ${tg[key] ? 'on' : ''}`} aria-label={label} onClick={() => setTg({ ...tg, [key]: !tg[key] })} />
                </div>
            ))}
            <div className="flex mt16"><span className="spacer" /><button className="btn pri" onClick={() => router.post('/settings/order-toggles', tg, { preserveScroll: true })}>{t('Save Changes')}</button></div>
        </div>
    );
}

const pkgTk = (n) => `৳${Number(n || 0).toLocaleString('en-IN')}`;

function PackagesSettings({ data }) {
    const packs = data?.packs || [];
    const pricing0 = data?.pricing || {};
    const [editing, setEditing] = useState(null);
    const addForm = useForm({ name: '', price_bdt: '', credits: '', validity_type: 'monthly' });
    const editForm = useForm({ name: '', price_bdt: '', credits: '', validity_type: 'monthly', active: true });
    const add = (e) => { e.preventDefault(); addForm.post('/settings/packages', { preserveScroll: true, onSuccess: () => addForm.reset() }); };
    const openEdit = (p) => { setEditing(p); editForm.setData({ name: p.name, price_bdt: p.price_bdt, credits: p.credits, validity_type: p.validity_type, active: p.active }); };
    const saveEdit = (e) => { e.preventDefault(); editForm.patch(`/settings/packages/${editing.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) }); };
    const remove = async (p) => { if (await confirmDialog(`${t('Delete pack')} ${p.name}?`)) router.delete(`/settings/packages/${p.id}`, { preserveScroll: true }); };
    const fields = (form) => (
        <>
            <div className="frow"><label className="fl">{t('Pack name')}</label><input className="inp" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>
            <div className="frow"><label className="fl">{t('Price (৳)')}</label><input className="inp" type="number" min="0" value={form.data.price_bdt} onChange={(e) => form.setData('price_bdt', e.target.value)} required /></div>
            <div className="frow"><label className="fl">{t('Points (credits)')}</label><input className="inp" type="number" min="1" value={form.data.credits} onChange={(e) => form.setData('credits', e.target.value)} required /></div>
            <div className="frow"><label className="fl">{t('Validity')}</label>
                <select className="inp" value={form.data.validity_type} onChange={(e) => form.setData('validity_type', e.target.value)}>
                    <option value="monthly">{t('Monthly — points reset every 30 days')}</option>
                    <option value="yearly_wallet">{t('Yearly wallet — points carry for 12 months')}</option>
                </select>
            </div>
            {Number(form.data.credits) > 0 && Number(form.data.price_bdt) >= 0 && form.data.price_bdt !== '' && (
                <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{t('Per-order rate')}: {pkgTk((Number(form.data.price_bdt) / Number(form.data.credits)).toFixed(2))} / {t('order')}</div>
            )}
        </>
    );

    return (
        <div>
            <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 14 }}>{t('Sellers buy points with these packs. One point is spent when an order enters — the packs differ only in points and price; every feature is in every pack.')}</div>
            <div className="plat-pkgs" style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12, marginBottom: 14 }}>
                {packs.map((p) => (
                    <div key={p.id} className="card" style={{ display: 'flex', flexDirection: 'column' }}>
                        <div style={{ display: 'flex', alignItems: 'center' }}>
                            <div style={{ fontSize: 16, fontWeight: 700 }}>{p.name}</div>
                            <span style={{ marginLeft: 'auto' }}>{p.active ? <span className="pill green">{t('Active')}</span> : <span className="pill">{t('Off')}</span>}</span>
                        </div>
                        <div style={{ fontSize: 26, fontWeight: 700, margin: '8px 0 2px' }}>{pkgTk(p.price_bdt)}</div>
                        <div style={{ fontSize: 13.5 }}>{Number(p.credits).toLocaleString('en-IN')} {t('points')} · {pkgTk(p.rate)} / {t('order')}</div>
                        <div style={{ fontSize: 12.5, color: 'var(--muted)', marginTop: 2 }}>{p.validity_type === 'monthly' ? t('Monthly — points reset every 30 days') : t('Yearly wallet — points carry for 12 months')}</div>
                        <div style={{ marginTop: 'auto', display: 'flex', gap: 8, alignItems: 'center', paddingTop: 10 }}>
                            <span style={{ fontSize: 12.5, color: 'var(--muted)' }}>{p.sellers} {t('sellers')}</span>
                            <button className="btn" style={{ marginLeft: 'auto' }} onClick={() => openEdit(p)}>{t('Edit')}</button>
                            <button className="btn" style={{ color: '#B91C1C' }} onClick={() => remove(p)}>{t('Delete')}</button>
                        </div>
                    </div>
                ))}
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, alignItems: 'start' }}>
                <div className="card">
                    <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Add a pack')}</h4>
                    <form onSubmit={add}>
                        {fields(addForm)}
                        <div className="flex mt16"><span className="spacer" /><button className="btn pri" disabled={addForm.processing}>{t('Create pack')}</button></div>
                    </form>
                </div>
                <PricingSettingsForm pricing={pricing0} />
            </div>

            {editing && (
                <div className="modal-overlay" onClick={() => setEditing(null)}>
                    <div className="modal" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 560, maxHeight: '88vh', overflowY: 'auto' }}>
                        <h3 style={{ fontSize: 17, marginBottom: 14 }}>{t('Edit pack')} — {editing.name}</h3>
                        <form onSubmit={saveEdit}>
                            {fields(editForm)}
                            <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 10, fontSize: 13.5 }}>
                                <input type="checkbox" checked={!!editForm.data.active} onChange={(e) => editForm.setData('active', e.target.checked)} /> {t('Pack is active (sellers can buy it)')}
                            </label>
                            <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
                                <button type="button" className="btn" onClick={() => setEditing(null)}>{t('Cancel')}</button>
                                <button className="btn pri" disabled={editForm.processing} style={{ marginLeft: 'auto' }}>{t('Save')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

function PricingSettingsForm({ pricing }) {
    const form = useForm({
        points_per_order: pricing.points_per_order ?? 1,
        onboarding_fee_bdt: pricing.onboarding_fee_bdt ?? 1000,
        low_balance_threshold: pricing.low_balance_threshold ?? 20,
        payment_instructions: pricing.payment_instructions ?? '',
        enforcement_enabled: pricing.enforcement_enabled ?? true,
    });
    useEffect(() => {
        form.setData({
            points_per_order: pricing.points_per_order ?? 1,
            onboarding_fee_bdt: pricing.onboarding_fee_bdt ?? 1000,
            low_balance_threshold: pricing.low_balance_threshold ?? 20,
            payment_instructions: pricing.payment_instructions ?? '',
            enforcement_enabled: pricing.enforcement_enabled ?? true,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pricing.points_per_order, pricing.onboarding_fee_bdt, pricing.low_balance_threshold, pricing.payment_instructions, pricing.enforcement_enabled]);
    const save = (e) => { e.preventDefault(); form.post('/settings/pricing', { preserveScroll: true }); };
    return (
        <div className="card">
            <h4 style={{ fontSize: 15, marginBottom: 4 }}>{t('Pricing settings')}</h4>
            <div style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 10 }}>{t('These numbers drive the whole points system — change one here and it applies everywhere at once.')}</div>
            <form onSubmit={save}>
                <div className="frow"><label className="fl">{t('Points per order')}</label><input className="inp" type="number" min="1" max="100" value={form.data.points_per_order} onChange={(e) => form.setData('points_per_order', e.target.value)} required /></div>
                <div className="frow"><label className="fl">{t('Onboarding fee (৳, one-time)')}</label><input className="inp" type="number" min="0" value={form.data.onboarding_fee_bdt} onChange={(e) => form.setData('onboarding_fee_bdt', e.target.value)} required /></div>
                <div className="frow"><label className="fl">{t('Low-balance warning at (points)')}</label><input className="inp" type="number" min="0" value={form.data.low_balance_threshold} onChange={(e) => form.setData('low_balance_threshold', e.target.value)} required /></div>
                <div className="frow"><label className="fl">{t('How to pay (shown to sellers)')}</label><textarea className="inp" rows={3} value={form.data.payment_instructions} onChange={(e) => form.setData('payment_instructions', e.target.value)} placeholder={t('e.g. Send money to bKash 01XXXXXXXXX, then tell us the transaction ID')} /></div>
                <div className="conn-row">
                    <div style={{ flex: 1 }}><div className="b">{t('Points system on')}</div><div className="sm mut">{t('When off, orders are never blocked and no points are spent. Turn off only in an emergency.')}</div></div>
                    <button type="button" className={`switch ${form.data.enforcement_enabled ? 'on' : ''}`} aria-label={t('Points system on')} onClick={() => form.setData('enforcement_enabled', !form.data.enforcement_enabled)} />
                </div>
                <div className="flex mt16"><span className="spacer" /><button className="btn pri" disabled={form.processing}>{t('Save pricing settings')}</button></div>
            </form>
        </div>
    );
}

export default function SettingsIndex({ group: initial = 'home', totpEnabled = false, totpEnrolled = false, m2, couriers, ai, isPlatform = false, packagesData = null, channels = null, paymentGateway = null, platformPaymentGateway = null }) {
    const groups = isPlatform ? [...GROUPS, { id: 'packages', label: 'Seller Packages', tabs: [] }] : GROUPS;
    const [group, setGroup] = useState(initial);
    const g = groups.find((x) => x.id === group) || groups[0];
    const [tab, setTab] = useState(g.tabs[0]?.[0]);
    const pick = (id) => { setGroup(id); const ng = groups.find((x) => x.id === id); setTab(ng?.tabs[0]?.[0]); };
    useEffect(() => { pick(initial); }, [initial]);
    return (
        <AppLayout>
            <Head title={t('Settings')} />
            <h1 className="page-h">{t('Settings')}</h1>
            <div style={{ height: 14 }} />
            <div className="set-wrap">
                <div className="set-menu">
                    {groups.map((x) => (
                        <div key={x.id} className={`nav-item ${group === x.id ? 'act' : ''}`} onClick={() => pick(x.id)}>{t(x.label)}</div>
                    ))}
                </div>
                <div className="set-body">
                    {g.tabs.length > 0 && (
                        <div className="ptabs">
                            {g.tabs.map(([id, label]) => <span key={id} className={`ptab ${tab === id ? 'act' : ''}`} onClick={() => setTab(id)}>{t(label)}</span>)}
                        </div>
                    )}
                    {group === 'home' && <Home />}
                    {group === 'business' && <Business tab={tab} m2={m2} />}
                    {group === 'api' && <Api tab={tab} couriers={couriers} channels={channels} paymentGateway={paymentGateway} platformPaymentGateway={platformPaymentGateway} />}
                    {group === 'calls' && <Calls tab={tab} m2={m2} />}
                    {group === 'ai' && <Ai tab={tab} ai={ai} />}
                    {group === 'security' && <Security tab={tab} totpEnabled={totpEnabled} totpEnrolled={totpEnrolled} m2={m2} />}
                    {group === 'packages' && isPlatform && <PackagesSettings data={packagesData} />}
                </div>
            </div>
        </AppLayout>
    );
}
