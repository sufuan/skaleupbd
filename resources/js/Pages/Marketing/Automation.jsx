import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { confirmDialog } from '@/lib/confirm';
import AppLayout from '@/Layouts/AppLayout';
import { fmt, fmtNum } from './parts';

const GROUPS = [
    ['order', 'Order-এর খবর'],
    ['delivery', 'Delivery-র খবর'],
    ['winback', 'Customer ফিরিয়ে আনা'],
];

const USED_IN = {
    thankyou: 'ব্যবহার হয়: Automation — নতুন order এলে',
    reminder: 'ব্যবহার হয়: Automation — reminder',
    advance: 'ব্যবহার হয়: Automation + Order page-এর Send Advance SMS',
    shipping: 'ব্যবহার হয়: Automation — courier-এ পাঠালেই',
    delivered: 'ব্যবহার হয়: Automation — Delivered হলে',
    back_in_stock: 'ব্যবহার হয়: Automation — stock ফিরলে',
    winback: 'ব্যবহার হয়: Automation — ঘুমন্ত customer offer',
    post_return: 'ব্যবহার হয়: Automation — return-এর পর আহ্বান',
    offer: 'ব্যবহার হয়: SMS campaign',
};

function FlowCard({ flow }) {
    const [enabled, setEnabled] = useState(flow.enabled);
    const [timing, setTiming] = useState(flow.timing || {});
    const save = (en, tm) => router.post(`/marketing/flows/${flow.key}`, { enabled: en, timing: tm }, { preserveScroll: true });
    const toggle = () => { const en = !enabled; setEnabled(en); save(en, timing); };
    const setT = (k, v) => setTiming((p) => ({ ...p, [k]: v }));
    const saveTiming = () => save(enabled, timing);

    return (
        <div className="flow-card">
            <div className="flow-main">
                <div className="flow-title">{flow.title}</div>
                <div className="flow-when">{flow.when}</div>
                <div className="flow-tpl">{flow.body}</div>
                {flow.key === 'reminder' && (
                    <div className="flow-time">
                        <span className="ft-lab">সময় নিজে ঠিক করো:</span> প্রথমটা
                        <input type="number" className="ft-in" min="1" value={timing.first_hours ?? 2} onChange={(e) => setT('first_hours', e.target.value)} onBlur={saveTiming} /> ঘণ্টা পর · দ্বিতীয়টা
                        <input type="number" className="ft-in" min="1" value={timing.second_hours ?? 20} onChange={(e) => setT('second_hours', e.target.value)} onBlur={saveTiming} /> ঘণ্টা পর
                        <span style={{ color: '#94A3B8' }}>(দ্বিতীয়টা ≈ পরদিন সকাল — চাইলে 36 বা 48-ও দিতে পারো)</span>
                    </div>
                )}
                {flow.key === 'winback' && (
                    <div className="flow-time">
                        <span className="ft-lab">সময় নিজে ঠিক করো:</span>
                        <input type="number" className="ft-in" min="1" value={timing.dormant_days ?? 60} onChange={(e) => setT('dormant_days', e.target.value)} onBlur={saveTiming} /> দিন ধরে না কিনলে যাবে
                        <span style={{ color: '#94A3B8' }}>(মাসে একবার করে)</span>
                    </div>
                )}
                {flow.key === 'post_return' && (
                    <div className="flow-time">
                        <span className="ft-lab">সময় নিজে ঠিক করো:</span> return শেষ হওয়ার
                        <input type="number" className="ft-in" min="1" value={timing.days ?? 3} onChange={(e) => setT('days', e.target.value)} onBlur={saveTiming} /> দিন পর যাবে
                    </div>
                )}
            </div>
            <div className="flow-side">
                <button type="button" className={`switch${enabled ? ' on' : ''}`} onClick={toggle} aria-label={flow.title}></button>
                <div className="flow-runs">{flow.key === 'reminder' ? `আজ ${fmtNum(flow.sent_today)} জনকে গেছে` : `এ পর্যন্ত ${fmtNum(flow.sent_total)} বার চলেছে`}</div>
            </div>
        </div>
    );
}

export default function Automation({ flows, templates, strip }) {
    const [tab, setTab] = useState('auto');
    const [modal, setModal] = useState(false);
    const [editing, setEditing] = useState(null);
    const [tName, setTName] = useState('');
    const [tBody, setTBody] = useState('');

    const openNew = () => { setEditing(null); setTName(''); setTBody(''); setModal(true); };
    const openEdit = (t) => { setEditing(t); setTName(t.name); setTBody(t.body); setModal(true); };
    const saveTemplate = () => {
        const opts = { preserveScroll: true, onSuccess: () => setModal(false) };
        if (editing) router.patch(`/marketing/templates/${editing.id}`, { name: tName, body: tBody }, opts);
        else router.post('/marketing/templates', { name: tName, body: tBody }, opts);
    };
    const delTemplate = async (t) => { if (await confirmDialog('এই template-টা মুছে ফেলবে?')) router.delete(`/marketing/templates/${t.id}`, { preserveScroll: true }); };

    return (
        <AppLayout>
            <Head title="Marketing — Automation" />
            <div className="mkt">
                {tab === 'auto' ? (
                    <>
                        <div style={{ marginBottom: 14 }}>
                            <h3 style={{ fontSize: 20 }}>Automation</h3>
                            <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>যে কাজগুলো নিজে নিজে হয়ে যাবে — শুধু switch চালু করে রাখো। প্রতিটা card-এ সহজ ভাষায় লেখা আছে কখন কী হবে।</p>
                        </div>
                        <div className="ptabs" style={{ marginBottom: 16 }}>
                            <button className="ptab act">Automation</button>
                            <button className="ptab" onClick={() => setTab('templates')}>Templates</button>
                        </div>
                        <div className="mk-strip">
                            <div className="mk-kpi"><b>{fmtNum(strip.sent)}</b><span>এই মাসে পাঠানো SMS</span></div>
                            <div className="mk-kpi"><b>{fmt(strip.cost)}</b><span>এই মাসের SMS খরচ</span></div>
                            <div className="mk-kpi"><b>{fmtNum(strip.orders)}টা order</b><span>Automation ও campaign থেকে এসেছে ({fmt(strip.sales)})</span></div>
                        </div>
                        {GROUPS.map(([g, label]) => (
                            <div key={g}>
                                <div className="mk-group">{label}</div>
                                {flows.filter((f) => f.group === g).map((f) => <FlowCard key={f.key} flow={f} />)}
                            </div>
                        ))}
                    </>
                ) : (
                    <>
                        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 14 }}>
                            <div>
                                <h3 style={{ fontSize: 20 }}>Templates</h3>
                                <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>তৈরি লেখা — Automation আর campaign এই লেখাগুলোই পাঠায়। এক জায়গায় বদলালে সব জায়গায় বদলে যাবে।</p>
                            </div>
                            <button className="btn-p" onClick={openNew}>+ নতুন Template</button>
                        </div>
                        <div className="ptabs" style={{ marginBottom: 16 }}>
                            <button className="ptab" onClick={() => setTab('auto')}>Automation</button>
                            <button className="ptab act">Templates</button>
                        </div>
                        <div>
                            {templates.map((t) => (
                                <div className="tpl-row" key={t.id}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'flex-start' }}>
                                        <div>
                                            <div className="tpl-name">{t.name}</div>
                                            <div className="tpl-text">{t.body}</div>
                                            <div className="tpl-used">{USED_IN[t.key] || 'ব্যবহার হয়: SMS campaign'}</div>
                                        </div>
                                        <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
                                            <button className="btn-s" onClick={() => openEdit(t)}>Edit</button>
                                            {!t.standard && <button className="btn-s" onClick={() => delTemplate(t)}>Delete</button>}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}

                <div className={`mk-modal${modal ? ' open' : ''}`}>
                    <div className="mk-modal-box">
                        <h4 style={{ fontSize: 16.5, marginBottom: 6 }}>{editing ? 'Template বদলাও' : 'নতুন Template'}</h4>
                        <label style={{ fontSize: 13, color: '#64748B' }}>নাম</label>
                        <input type="text" value={tName} onChange={(e) => setTName(e.target.value)} placeholder="যেমন: নতুন collection-এর খবর" />
                        <label style={{ fontSize: 13, color: '#64748B' }}>লেখা</label>
                        <textarea rows="4" value={tBody} onChange={(e) => setTBody(e.target.value)} placeholder="SMS-এর লেখা — {name}, {invoice}, {coupon} জাতীয় ঘর ব্যবহার করা যাবে"></textarea>
                        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                            <button className="btn-s" onClick={() => setModal(false)}>বাদ দাও</button>
                            <button className="btn-p" onClick={saveTemplate}>Save করো</button>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
