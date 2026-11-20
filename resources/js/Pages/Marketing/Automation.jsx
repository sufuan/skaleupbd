import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { confirmDialog } from '@/lib/confirm';
import AppLayout from '@/Layouts/AppLayout';
import { fmt, fmtNum } from './parts';
import { t } from '@/lib/i18n';

const GROUPS = [
    ['order', 'Order news'],
    ['delivery', 'Delivery news'],
    ['winback', 'Bringing customers back'],
];

const USED_IN = {
    thankyou: 'Used in: Automation — when a new order comes',
    reminder: 'Used in: Automation — reminder',
    advance: 'Used in: Automation + the Send Advance SMS on the Order page',
    shipping: 'Used in: Automation — as soon as it is sent to the courier',
    delivered: 'Used in: Automation — when Delivered',
    back_in_stock: 'Used in: Automation — when stock returns',
    winback: 'Used in: Automation — sleeping customer offer',
    post_return: 'Used in: Automation — call after a return',
    offer: 'Used in: SMS campaign',
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
                        <span className="ft-lab">{t('Set the time yourself:')}</span> {t('the first one')}
                        <input type="number" className="ft-in" min="1" value={timing.first_hours ?? 2} onChange={(e) => setT('first_hours', e.target.value)} onBlur={saveTiming} /> {t('hours later')} · {t('the second one')}
                        <input type="number" className="ft-in" min="1" value={timing.second_hours ?? 20} onChange={(e) => setT('second_hours', e.target.value)} onBlur={saveTiming} /> {t('hours later')}
                        <span style={{ color: '#94A3B8' }}>{t('(the second one ≈ the next morning — you can give 36 or 48 too)')}</span>
                    </div>
                )}
                {flow.key === 'winback' && (
                    <div className="flow-time">
                        <span className="ft-lab">{t('Set the time yourself:')}</span>
                        <input type="number" className="ft-in" min="1" value={timing.dormant_days ?? 60} onChange={(e) => setT('dormant_days', e.target.value)} onBlur={saveTiming} /> {t('days without buying triggers it')}
                        <span style={{ color: '#94A3B8' }}>{t('(once a month)')}</span>
                    </div>
                )}
                {flow.key === 'post_return' && (
                    <div className="flow-time">
                        <span className="ft-lab">{t('Set the time yourself:')}</span> {t('after the return ends')}
                        <input type="number" className="ft-in" min="1" value={timing.days ?? 3} onChange={(e) => setT('days', e.target.value)} onBlur={saveTiming} /> {t('days later it goes')}
                    </div>
                )}
            </div>
            <div className="flow-side">
                <button type="button" className={`switch${enabled ? ' on' : ''}`} onClick={toggle} aria-label={flow.title}></button>
                <div className="flow-runs">{flow.key === 'reminder' ? t('Went to :count people today', { count: fmtNum(flow.sent_today) }) : t('Has run :count times so far', { count: fmtNum(flow.sent_total) })}</div>
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
    const openEdit = (tpl) => { setEditing(tpl); setTName(tpl.name); setTBody(tpl.body); setModal(true); };
    const saveTemplate = () => {
        const opts = { preserveScroll: true, onSuccess: () => setModal(false) };
        if (editing) router.patch(`/marketing/templates/${editing.id}`, { name: tName, body: tBody }, opts);
        else router.post('/marketing/templates', { name: tName, body: tBody }, opts);
    };
    const delTemplate = async (tpl) => { if (await confirmDialog(t('Delete this template?'))) router.delete(`/marketing/templates/${tpl.id}`, { preserveScroll: true }); };

    return (
        <AppLayout>
            <Head title={t('Marketing — Automation')} />
            <div className="mkt">
                {tab === 'auto' ? (
                    <>
                        <div style={{ marginBottom: 14 }}>
                            <h3 style={{ fontSize: 20 }}>{t('Automation')}</h3>
                            <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>{t('The jobs that happen on their own — just keep the switch on. Every card says in simple words when what happens.')}</p>
                        </div>
                        <div className="ptabs" style={{ marginBottom: 16 }}>
                            <button className="ptab act">{t('Automation')}</button>
                            <button className="ptab" onClick={() => setTab('templates')}>{t('Templates')}</button>
                        </div>
                        <div className="mk-strip">
                            <div className="mk-kpi"><b>{fmtNum(strip.sent)}</b><span>{t('SMS sent this month')}</span></div>
                            <div className="mk-kpi"><b>{fmt(strip.cost)}</b><span>{t('SMS cost this month')}</span></div>
                            <div className="mk-kpi"><b>{t(':count orders', { count: fmtNum(strip.orders) })}</b><span>{t('Came from Automation and campaigns (:sales)', { sales: fmt(strip.sales) })}</span></div>
                        </div>
                        {GROUPS.map(([g, label]) => (
                            <div key={g}>
                                <div className="mk-group">{t(label)}</div>
                                {flows.filter((f) => f.group === g).map((f) => <FlowCard key={f.key} flow={f} />)}
                            </div>
                        ))}
                    </>
                ) : (
                    <>
                        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 14 }}>
                            <div>
                                <h3 style={{ fontSize: 20 }}>{t('Templates')}</h3>
                                <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>{t('Ready-made texts — Automation and campaigns send these very texts. Change it in one place and it changes everywhere.')}</p>
                            </div>
                            <button className="btn-p" onClick={openNew}>+ {t('New Template')}</button>
                        </div>
                        <div className="ptabs" style={{ marginBottom: 16 }}>
                            <button className="ptab" onClick={() => setTab('auto')}>{t('Automation')}</button>
                            <button className="ptab act">{t('Templates')}</button>
                        </div>
                        <div>
                            {templates.map((tpl) => (
                                <div className="tpl-row" key={tpl.id}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'flex-start' }}>
                                        <div>
                                            <div className="tpl-name">{tpl.name}</div>
                                            <div className="tpl-text">{tpl.body}</div>
                                            <div className="tpl-used">{t(USED_IN[tpl.key] || 'Used in: SMS campaign')}</div>
                                        </div>
                                        <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
                                            <button className="btn-s" onClick={() => openEdit(tpl)}>{t('Edit')}</button>
                                            {!tpl.standard && <button className="btn-s" onClick={() => delTemplate(tpl)}>{t('Delete')}</button>}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}

                <div className={`mk-modal${modal ? ' open' : ''}`}>
                    <div className="mk-modal-box">
                        <h4 style={{ fontSize: 16.5, marginBottom: 6 }}>{editing ? t('Change Template') : t('New Template')}</h4>
                        <label style={{ fontSize: 13, color: '#64748B' }}>{t('Name')}</label>
                        <input type="text" value={tName} onChange={(e) => setTName(e.target.value)} placeholder={t('e.g. News of the new collection')} />
                        <label style={{ fontSize: 13, color: '#64748B' }}>{t('Text')}</label>
                        <textarea rows="4" value={tBody} onChange={(e) => setTBody(e.target.value)} placeholder={t('The SMS text — you can use fields like {name}, {invoice}, {coupon}')}></textarea>
                        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                            <button className="btn-s" onClick={() => setModal(false)}>{t('Discard')}</button>
                            <button className="btn-p" onClick={saveTemplate}>{t('Save')}</button>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
