import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

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
    return <div className="flex mt16"><span className="sm mut">Prototype — এই Step-এ Settings শুধু সাজানো হয়েছে; module ধরে ধরে এগুলো dynamic হবে।</span><span className="spacer" /><button className="btn pri">Save Changes</button></div>;
}
function Field({ label, value, type = 'text', area = false }) {
    return <div className="frow"><label className="fl">{label}</label>{area
        ? <textarea className="inp" rows="3" defaultValue={value} />
        : <input className="inp" type={type} defaultValue={value} />}</div>;
}
function ConnRow({ name, desc, status, pillCls }) {
    return (
        <div className="conn-row">
            <div className="conn-ic"><b>{name[0]}</b></div>
            <div style={{ flex: 1 }}><div className="b">{name}</div><div className="sm mut">{desc}</div></div>
            <span className={`pill ${pillCls}`}>{status}</span>
            <button className="btn">Configure</button>
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
        ['Calls & Orders', '3 sources active', 'green'], ['AI & Auto Reply', 'Draft only (day 3 of 7)', 'amber'],
        ['Security & Records', '2 devices pending', 'amber'],
    ];
    return (
        <>
            <div className="frow"><input className="inp" placeholder="Search settings… (যেমন courier, WhatsApp, AI)" /></div>
            <div className="card mb12">
                <h3>Setup Checklist</h3><div className="card-sub">সব হয়ে গেলে shop পুরোপুরি চালু</div>
                {checks.map(([t, ok]) => (
                    <div className="check-row" key={t}>
                        <span className={`check-ic ${ok ? 'ok' : 'no'}`}>{ok ? '✓' : '!'}</span>{t}
                        {!ok && <><span className="spacer" /><button className="btn">Set up</button></>}
                    </div>
                ))}
            </div>
            <div className="grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
                {cards.map(([t, s, c]) => (
                    <div className="card stat-card" key={t}><div><div className="b">{t}</div><div className="sm mut">{s}</div></div><span className={`pill ${c}`}>{c === 'green' ? 'Done' : 'Pending'}</span></div>
                ))}
            </div>
        </>
    );
}

function Business({ tab }) {
    if (tab === 'merchant') return (
        <div className="card"><h3>Merchant Profile</h3><div className="card-sub">Courier আর payment-এর জন্য যাচাইয়ের তথ্য</div>
            <Field label="Trade Licence No." value="TRAD/DNCC/012345/2023" /><Field label="TIN" value="123456789012" />
            <Field label="Bank Account Name" value="Demo Shop" /><Field label="Bank / Branch" value="Sonali Bank — Mirpur 10" />
            <Field label="Account Number" value="•••• •••• 4832" /><SaveBar /></div>
    );
    return (
        <div className="card"><h3>Business Profile</h3><div className="card-sub">Invoice আর storefront-এ এই তথ্য দেখায়</div>
            <Field label="Shop Name" value="Demo Shop" /><Field label="Phone" value="01712-445566" />
            <Field label="Email" value="hello@demoshop.com.bd" /><Field label="Address" value="House 12, Road 5, Mirpur 10, Dhaka" area />
            <Field label="Business Category" value="Fashion & Lifestyle" /><SaveBar /></div>
    );
}

function Api({ tab }) {
    if (tab === 'channels') return (
        <div className="card"><h3>Messaging Channels</h3><div className="card-sub">এক shop-এ প্রতি channel একটি account (v1 নিয়ম)</div>
            <ConnRow name="Facebook Page" desc="Demo Shop BD — Page ID 1473954169130280" status="Connected" pillCls="green" />
            <ConnRow name="WhatsApp" desc="+880 1712-445566 — WABA connected" status="Connected" pillCls="green" />
            <ConnRow name="Instagram" desc="Professional account লাগবে — এই channel আপাতত parked" status="Not Connected" pillCls="" />
            <SaveBar /></div>
    );
    if (tab === 'app') return (
        <div className="card"><h3>App Integration</h3><div className="card-sub">বাইরের app/AI system-এর জন্য External API</div>
            <Field label="API Key (ecds_…)" value="ecds_••••••••••••••••••48" /><Field label="Webhook URL" value="https://demoshop.com.bd/hooks/skaleup" />
            <div className="sm mut">নতুন key বানালে পুরনোটা সঙ্গে সঙ্গে বন্ধ হয়ে যায়। Key শুধু তৈরির সময় একবার পুরো দেখায়।</div><SaveBar /></div>
    );
    if (tab === 'website') return (
        <div className="card"><h3>Website Integration</h3><div className="card-sub">নিজের website / WooCommerce থেকে order আসবে</div>
            <Field label="Website URL" value="https://demoshop.com.bd" /><Field label="Platform" value="WooCommerce" />
            <ConnRow name="WooCommerce" desc="Plugin connected — order sync চালু" status="Connected" pillCls="green" /><SaveBar /></div>
    );
    return (
        <div className="card"><h3>Courier Integration</h3><div className="card-sub">প্রতি courier-এ নিজের account — delivery charge courier অনুযায়ী এখানেই থাকে</div>
            <ConnRow name="Pathao" desc="Merchant ID সংযুক্ত — Inside Dhaka ৳60 · Outside ৳120" status="Connected" pillCls="green" />
            <ConnRow name="Steadfast" desc="API key সংযুক্ত — Inside Dhaka ৳60 · Outside ৳110" status="Connected" pillCls="green" />
            <ConnRow name="RedX" desc="এখনো সংযুক্ত নয়" status="Not Connected" pillCls="" />
            <ConnRow name="eCourier" desc="এখনো সংযুক্ত নয়" status="Not Connected" pillCls="" />
            <ConnRow name="Paperfly" desc="এখনো সংযুক্ত নয়" status="Not Connected" pillCls="" />
            <ConnRow name="Carrybee" desc="এখনো সংযুক্ত নয়" status="Not Connected" pillCls="" />
            <ConnRow name="Sundarban" desc="Manual courier — credential লাগে না, CN নম্বর হাতে লিখতে হয়" status="Manual" pillCls="blue" />
            <div className="frow mt16"><label className="fl">Default Courier</label>
                <select className="inp" defaultValue="steadfast"><option value="steadfast">Steadfast</option><option value="pathao">Pathao</option></select></div>
            <SaveBar /></div>
    );
}

function Calls({ tab }) {
    if (tab === 'templates') return (
        <div className="card"><h3>Templates</h3><div className="card-sub">SMS / message template — automation আর order action-এ ব্যবহৃত হয়</div>
            {[['Order Confirmation', 'আসসালামু আলাইকুম {name}, আপনার order #{invoice} নিশ্চিত হয়েছে…'], ['Delivery Update', 'আপনার parcel {courier}-এ পাঠানো হয়েছে, CN: {cn}'], ['Advance Request', 'Order নিশ্চিত করতে ৳{amount} advance পাঠান…']].map(([t, x]) => (
                <div className="rule-card" key={t}><div className="b">{t}</div><div className="sm mut mt8">{x}</div></div>
            ))}<SaveBar /></div>
    );
    if (tab === 'pbx') return (
        <div className="card"><h3>PBX & Auto Call</h3><div className="card-sub">Auto Call rate ৳1.00/min — আসল robot call VPS-এ গেলে চালু হবে; এখন staff নিজে call করে outcome লিখে</div>
            <div className="conn-row"><div style={{ flex: 1 }}><div className="b">PBX Status</div><div className="sm mut">Line active — 3 agents</div></div><span className="pill green">Active</span><Sw on /></div>
            <div className="conn-row"><div style={{ flex: 1 }}><div className="b">Auto Call</div><div className="sm mut">Balance 420 min</div></div><span className="pill amber">Manual mode</span><Sw /></div>
            <SaveBar /></div>
    );
    return (
        <div className="card"><h3>Order Sources</h3><div className="card-sub">কোন কোন জায়গা থেকে order ঢুকতে পারবে</div>
            {[['Website (WooCommerce)', true], ['Facebook Page', true], ['Manual / Phone Order', true], ['Landing Page (Storefront)', false]].map(([t, on]) => (
                <div className="conn-row" key={t}><div style={{ flex: 1 }} className="b">{t}</div><Sw on={on} /></div>
            ))}<SaveBar /></div>
    );
}

function Ai({ tab }) {
    if (tab === 'ailvl') return (
        <div className="card"><h3>AI Reply</h3><div className="card-sub">Channel প্রতি AI কতটা নিজে করতে পারবে — নতুন channel-এর প্রথম 7 দিন Draft only বাধ্যতামূলক</div>
            {[['Messenger', 'Draft only — AI লিখবে, মানুষ পাঠাবে', 'Day 3 of 7'], ['WhatsApp', 'Draft only — AI লিখবে, মানুষ পাঠাবে', 'Day 3 of 7']].map(([c, v, d]) => (
                <div className="frow" key={c}><label className="fl">{c} <span className="mut">({d})</span></label>
                    <select className="inp" defaultValue={v}><option>{v}</option><option>বন্ধ</option><option>Auto — সহজ উত্তর নিজে পাঠাবে</option><option>Full auto — দামাদামিসহ সব নিজে</option></select></div>
            ))}
            <Field label="Working Hours" value="সকাল 9টা — রাত 11টা (এর বাইরে AI শুধু draft রাখবে)" /><SaveBar /></div>
    );
    if (tab === 'negotiation') return (
        <div className="card"><h3>Price & Negotiation</h3><div className="card-sub">AI ধাপে ধাপে দাম কমাবে — floor-এর নিচে কখনোই না, code দিয়ে আটকানো</div>
            <Field label="সর্বোচ্চ ছাড় (%)" value="15" type="number" /><Field label="প্রতি ধাপে ছাড় (%)" value="5" type="number" />
            <div className="rule-card"><div className="b">দামের সিঁড়ি (উদাহরণ)</div>
                <div className="flex mt8" style={{ gap: 8, flexWrap: 'wrap' }}>
                    {['৳600', '→', '৳570', '→', '৳540', '→', '৳510 (floor)'].map((x, i) => <span key={i} className={x.includes('floor') ? 'pill red' : 'pill'}>{x}</span>)}
                </div></div>
            <Field label="Free Delivery (কমপক্ষে ৳)" value="1000" type="number" /><SaveBar /></div>
    );
    if (tab === 'knowledge') return (
        <div className="card"><h3>Knowledge</h3><div className="card-sub">AI উত্তর বানাতে এই নোটগুলো পড়ে — এখানে না থাকলে AI বানিয়ে বলবে না</div>
            <Field label="Delivery & Return Policy" value="ঢাকার ভিতরে 24–48 ঘণ্টা, বাইরে 3–5 দিন। ত্রুটিপূর্ণ পণ্য 7 দিনে বদলি।" area />
            <Field label="Payment Info" value="Cash on Delivery, bKash 01712-445566" area />
            <Field label="Tone & Banned Words" value="ভদ্র, সংক্ষিপ্ত বাংলা। 'সস্তা' শব্দটা ব্যবহার করা যাবে না।" area /><SaveBar /></div>
    );
    return (
        <div className="card"><h3>Rule Brain</h3><div className="card-sub">নিয়ম মিললে AI সেই উত্তরই দেয় — সব সংখ্যা live data থেকে আসে</div>
            {[['Default First Reply', 'প্রথম message-এ স্বাগত + কীভাবে সাহায্য করতে পারি', true], ['দাম জানতে চাইলে', 'Product-এর live দাম + delivery charge', true], ['Delivery charge জিজ্ঞাসা', 'জেলা অনুযায়ী charge table থেকে', true], ['Stock জিজ্ঞাসা', 'Live stock থেকে আছে/নেই', false]].map(([t, d, on]) => (
                <div className="rule-card" key={t}><div className="flex"><div style={{ flex: 1 }}><div className="b">{t}</div><div className="sm mut">{d}</div></div><Sw on={on} /></div></div>
            ))}
            <div className="rule-card" style={{ background: '#f8f4fd' }}>
                <div className="b">তোমার Edit থেকে শেখা</div>
                <div className="sm mut mt8">Staff-দের edit আর conversation পড়ে AI নতুন নিয়মের প্রস্তাব এখানে রাখে — **মানুষ approve না করলে কোনো নিয়ম চালু হয় না।** এখন 2টা প্রস্তাব অপেক্ষায় আছে।</div>
            </div>
            <div className="frow"><label className="fl">Try a message</label><input className="inp" placeholder="যেমন: এটার দাম কত?" /></div>
            <SaveBar /></div>
    );
}

function Security({ tab }) {
    if (tab === 'records') return (
        <>
            <div className="card mb12"><h3>Deletion Logs</h3><div className="card-sub">মুছে ফেলা জিনিসের record 7 দিন থাকে</div>
                <table className="tbl"><thead><tr><th>Item</th><th>Deleted By</th><th>When</th></tr></thead>
                    <tbody><tr><td>Order OB271 (duplicate)</td><td>M. Fahim</td><td>14 Nov, 6:20 PM</td></tr>
                    <tr><td>Product — Cotton Panjabi (old)</td><td>Admin</td><td>13 Nov, 11:02 AM</td></tr></tbody></table></div>
            <div className="card"><h3>Settings Change History</h3><div className="card-sub">কে কখন কোন setting বদলাল — শুধু record, rollback নেই (v1)</div>
                <table className="tbl"><thead><tr><th>Setting</th><th>Change</th><th>By</th><th>When</th></tr></thead>
                    <tbody><tr><td>Default Courier</td><td>Pathao → Steadfast</td><td>Admin</td><td>15 Nov, 9:14 AM</td></tr>
                    <tr><td>Max Discount</td><td>10% → 15%</td><td>Admin</td><td>14 Nov, 8:40 PM</td></tr></tbody></table></div>
        </>
    );
    if (tab === 'advanced') return (
        <div className="card"><h3>Advanced Settings</h3><div className="card-sub">সাবধানে বদলাও — এগুলো পুরো shop-এ effect ফেলে</div>
            <div className="conn-row"><div style={{ flex: 1 }}><div className="b">Two-Factor Login (TOTP)</div><div className="sm mut">Super Admin / Manager / Accountant-এর জন্য</div></div><Sw /></div>
            <div className="conn-row"><div style={{ flex: 1 }}><div className="b">Require Device Approval</div><div className="sm mut">নতুন device admin approve না করলে ঢুকতে পারবে না</div></div><Sw on /></div>
            <div className="conn-row"><div style={{ flex: 1 }}><div className="b" style={{ color: 'var(--bad)' }}>Reset Shop Data</div><div className="sm mut">সব order/product মুছে যাবে — ফেরত আনা যাবে না</div></div><button className="btn" style={{ color: 'var(--bad)', borderColor: '#f0caca' }}>Reset</button></div>
            <SaveBar /></div>
    );
    return (
        <div className="card"><h3>Device Approvals</h3><div className="card-sub">নতুন device থেকে login চাইলে এখানে approve করতে হয়</div>
            {[['Chrome — Windows (Dhaka)', 'Pending'], ['bKash App — Android', 'Pending'], ['Chrome — Mac (এই device)', 'Approved']].map(([d, s]) => (
                <div className="conn-row" key={d}><div style={{ flex: 1 }} className="b">{d}</div>
                    <span className={`pill ${s === 'Pending' ? 'amber' : 'green'}`}>{s}</span>
                    {s === 'Pending' && <button className="btn pri">Approve</button>}</div>
            ))}<SaveBar /></div>
    );
}

export default function SettingsIndex({ group: initial = 'home' }) {
    const [group, setGroup] = useState(initial);
    const g = GROUPS.find((x) => x.id === group) || GROUPS[0];
    const [tab, setTab] = useState(g.tabs[0]?.[0]);
    const pick = (id) => { setGroup(id); const ng = GROUPS.find((x) => x.id === id); setTab(ng.tabs[0]?.[0]); };
    return (
        <AppLayout>
            <Head title="Settings" />
            <h1 className="page-h">Settings</h1>
            <div className="page-sub">সব setting এক জায়গায় — module ধরে ধরে এগুলো dynamic হবে</div>
            <div className="set-wrap">
                <div className="set-menu">
                    {GROUPS.map((x) => (
                        <div key={x.id} className={`nav-item ${group === x.id ? 'act' : ''}`} onClick={() => pick(x.id)}>{x.label}</div>
                    ))}
                </div>
                <div className="set-body">
                    {g.tabs.length > 0 && (
                        <div className="ptabs">
                            {g.tabs.map(([id, label]) => <span key={id} className={`ptab ${tab === id ? 'act' : ''}`} onClick={() => setTab(id)}>{label}</span>)}
                        </div>
                    )}
                    {group === 'home' && <Home />}
                    {group === 'business' && <Business tab={tab} />}
                    {group === 'api' && <Api tab={tab} />}
                    {group === 'calls' && <Calls tab={tab} />}
                    {group === 'ai' && <Ai tab={tab} />}
                    {group === 'security' && <Security tab={tab} />}
                </div>
            </div>
        </AppLayout>
    );
}
