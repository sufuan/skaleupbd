import CourierSettings from './CourierSettings';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const GROUPS = [
    { id: 'home', label: 'Settings Dashboard', tabs: [] },
    { id: 'business', label: 'Business Settings', tabs: [['profile', 'Business Profile'], ['merchant', 'Merchant Profile']] },
    { id: 'api', label: 'API Connection', tabs: [['courier', 'Courier Integration'], ['channels', 'Messaging Channels'], ['app', 'App Integration'], ['website', 'Website Integration']] },
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
            <div className="grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
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

function Api({ tab, couriers }) {
    if (tab === 'channels') return (
        <div className="card"><h3>{t('Messaging Channels')}</h3><div className="card-sub">{t('One account per channel per shop (v1 rule)')}</div>
            <ConnRow name="Facebook Page" desc="Demo Shop BD — Page ID 1473954169130280" status="Connected" pillCls="green" />
            <ConnRow name="WhatsApp" desc="+880 1712-445566 — WABA connected" status="Connected" pillCls="green" />
            <ConnRow name="Instagram" desc="A Professional account is needed — this channel is parked for now" status="Not Connected" pillCls="" />
            <SaveBar /></div>
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

function Calls({ tab, m2 }) {
    if (tab === 'templates') return <TemplatesForm m2={m2} />;
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

function Ai({ tab }) {
    if (tab === 'ailvl') return (
        <div className="card"><h3>{t('AI Reply')}</h3><div className="card-sub">{t('How much the AI may do alone, per channel — for a new channel, starting with Draft only for the first 7 days is recommended')}</div>
            {[['Messenger', 'Draft only — AI writes, a human sends', 'New channel'], ['WhatsApp', 'Draft only — AI writes, a human sends', 'New channel']].map(([c, v, d]) => (
                <div className="frow" key={c}><label className="fl">{c} <span className="mut">({t(d)})</span></label>
                    <select className="inp" defaultValue={v}><option value={v}>{t(v)}</option><option>{t('Off')}</option><option>{t('Auto — simple answers sent by itself')}</option><option>{t('Full auto — everything including bargaining')}</option></select></div>
            ))}
            <Field label="Working Hours" value="সকাল 9টা — রাত 11টা (এর বাইরে AI শুধু draft রাখবে)" /><SaveBar /></div>
    );
    if (tab === 'negotiation') return (
        <div className="card"><h3>{t('Price & Negotiation')}</h3><div className="card-sub">{t('The AI lowers the price step by step — never below the floor, held back by code')}</div>
            <Field label="Maximum discount (%)" value="15" type="number" /><Field label="Discount per step (%)" value="5" type="number" />
            <div className="rule-card"><div className="b">{t('Price ladder (example)')}</div>
                <div className="flex mt8" style={{ gap: 8, flexWrap: 'wrap' }}>
                    {['৳600', '→', '৳570', '→', '৳540', '→', '৳510 (floor)'].map((x, i) => <span key={i} className={x.includes('floor') ? 'pill red' : 'pill'}>{x}</span>)}
                </div></div>
            <Field label="Free delivery (at least ৳)" value="1000" type="number" /><SaveBar /></div>
    );
    if (tab === 'knowledge') return (
        <div className="card"><h3>{t('Knowledge')}</h3><div className="card-sub">{t('The AI reads these notes to build answers — if it is not here, the AI will not make it up')}</div>
            <Field label="Delivery & Return Policy" value="ঢাকার ভিতরে 24–48 ঘণ্টা, বাইরে 3–5 দিন। ত্রুটিপূর্ণ পণ্য 7 দিনে বদলি।" area />
            <Field label="Payment Info" value="Cash on Delivery, bKash 01712-445566" area />
            <Field label="Tone & Banned Words" value="ভদ্র, সংক্ষিপ্ত বাংলা। 'সস্তা' শব্দটা ব্যবহার করা যাবে না।" area /><SaveBar /></div>
    );
    return (
        <div className="card"><h3>{t('Rule Brain')}</h3><div className="card-sub">{t('When a rule matches, the AI gives that answer — every number comes from live data')}</div>
            {[['Default First Reply', 'Welcome on the first message + how can I help', true], ['When they ask the price', 'The product\'s live price + delivery charge', true], ['Delivery charge question', 'From the charge table, by district', true], ['Stock question', 'In stock or not, from live stock', false]].map(([label, d, on]) => (
                <div className="rule-card" key={label}><div className="flex"><div style={{ flex: 1 }}><div className="b">{t(label)}</div><div className="sm mut">{t(d)}</div></div><Sw on={on} /></div></div>
            ))}
            <div className="rule-card note-box">
                <div className="b">{t('Learning from your edits')}</div>
                <div className="sm mut mt8">{t('The AI reads staff edits and conversations and proposes new rules here')} — <b>{t('no rule ever turns on unless a human approves it.')}</b> {t('2 proposals are waiting now.')}</div>
            </div>
            <div className="frow"><label className="fl">{t('Try a message')}</label><input className="inp" placeholder={t('e.g. How much is this?')} /></div>
            <SaveBar /></div>
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

export default function SettingsIndex({ group: initial = 'home', totpEnabled = false, totpEnrolled = false, m2, couriers }) {
    const [group, setGroup] = useState(initial);
    const g = GROUPS.find((x) => x.id === group) || GROUPS[0];
    const [tab, setTab] = useState(g.tabs[0]?.[0]);
    const pick = (id) => { setGroup(id); const ng = GROUPS.find((x) => x.id === id); setTab(ng.tabs[0]?.[0]); };
    useEffect(() => { pick(initial); }, [initial]);
    return (
        <AppLayout>
            <Head title={t('Settings')} />
            <h1 className="page-h">{t('Settings')}</h1>
            <div style={{ height: 14 }} />
            <div className="set-wrap">
                <div className="set-menu">
                    {GROUPS.map((x) => (
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
                    {group === 'api' && <Api tab={tab} couriers={couriers} />}
                    {group === 'calls' && <Calls tab={tab} m2={m2} />}
                    {group === 'ai' && <Ai tab={tab} />}
                    {group === 'security' && <Security tab={tab} totpEnabled={totpEnabled} totpEnrolled={totpEnrolled} m2={m2} />}
                </div>
            </div>
        </AppLayout>
    );
}
