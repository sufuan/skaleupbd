import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { fmt, fmtNum } from './parts';
import { t } from '@/lib/i18n';

const PERIODS = [['today', 'Today'], ['7d', '7 days'], ['30d', '30 days']];
const STAGES = [
    { name: 'Wrote to you', def: 'Messaged you at least once in this period.' },
    { name: 'Showed interest', def: 'Asked about the price, stock, size or delivery.' },
    { name: 'Ready to order', def: 'Shared a phone number or address, or said they will take it.' },
    { name: 'Ordered', def: 'An order was created for them.' },
];
const STUCK_LABELS = ['Only wrote', 'Interested, did not move on', 'Ready to order, not ordered', 'Ordered'];
const STUCK_COLORS = ['#94A3B8', '#411550', '#7C3AED', '#12805C'];
const TASK_TEXT = {
    complaint: { title: ':count complaints waiting for a reply', reason: 'They mentioned a wrong product, a delay or damage. Look at these first.', cta: 'See complaints', urgent: 'Urgent' },
    ready: { title: ':count people are ready to order, but have not ordered', reason: 'They shared contact details. A fast follow-up wins the sale.', cta: 'Send message', urgent: 'Now' },
    interested: { title: ':count people asked about price or delivery and stopped', reason: 'They asked, then went quiet. A message can bring them back.', cta: 'Send offer', urgent: 'Today' },
};
const REASON_LABELS = {
    intent_price: 'Asked about the price', intent_stock: 'Asked about stock', intent_delivery: 'Asked about delivery',
    intent_bargain: 'Tried to bargain', order_intent: 'Said they will take it', contact_shared: 'Shared phone or address',
    order_created: 'An order was created', closed_ordered: 'Closed — ordered', closed_spam: 'Closed — spam',
    angry: 'Sounded angry', intent_disagreement: 'Rule and AI read this differently', backfill: 'Worked out from old messages',
};

const xsrf = () => (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '';
async function api(path, opts = {}) {
    const res = await fetch(path, {
        credentials: 'same-origin',
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf()), Accept: 'application/json', 'Content-Type': 'application/json' },
        ...opts,
    });
    return res.ok ? res.json().catch(() => ({})) : null;
}

export default function Growth({ summary, tasks, can_revenue }) {
    const [period, setPeriod] = useState(summary.period || '30d');
    const [taskList, setTaskList] = useState(tasks || []);
    const [openStage, setOpenStage] = useState(null);
    const [drawer, setDrawer] = useState(null); // { task, customers }
    const [why, setWhy] = useState({}); // conversation_id -> events
    const [busy, setBusy] = useState(false);
    const [toast, setToast] = useState('');

    const showToast = (m) => { setToast(m); setTimeout(() => setToast(''), 2600); };
    const goPeriod = (p) => { setPeriod(p); router.get('/marketing/growth', { period: p }, { preserveScroll: true }); };

    const openTask = async (task) => {
        const data = await api(`/marketing/growth/tasks/${task.id}/customers`);
        if (data) setDrawer({ task, customers: data.customers });
    };
    const setStatus = async (task, status) => {
        const data = await api(`/marketing/growth/tasks/${task.id}/status`, { method: 'POST', body: JSON.stringify({ status }) });
        if (data) setTaskList((l) => l.map((x) => (x.id === task.id ? { ...x, status } : x)));
    };
    const markDrafted = (cid) => setDrawer((d) => d ? { ...d, customers: d.customers.map((c) => (c.conversation_id === cid ? { ...c, drafted: true } : c)) } : d);
    const draftOne = async (task, c) => {
        setBusy(true);
        const data = await api(`/marketing/growth/tasks/${task.id}/drafts`, { method: 'POST', body: JSON.stringify({ conversation_id: c.conversation_id }) });
        setBusy(false);
        if (data && data.created) { markDrafted(c.conversation_id); showToast(t('Draft made — review and send it from the Inbox')); }
    };
    const draftAll = async (task) => {
        setBusy(true);
        const data = await api(`/marketing/growth/tasks/${task.id}/drafts`, { method: 'POST', body: JSON.stringify({}) });
        setBusy(false);
        if (data) {
            setDrawer((d) => d ? { ...d, customers: d.customers.map((c) => (c.window_open ? { ...c, drafted: true } : c)) } : d);
            showToast(t(':count drafts made in the Inbox', { count: fmtNum(data.created) }));
        }
    };
    const logCall = async (task, c) => {
        // Logging a call never touches the draft state — and the Call
        // button itself is never disabled: a call is always possible,
        // inside or outside the 24h messaging window.
        const data = await api(`/marketing/growth/tasks/${task.id}/calls`, { method: 'POST', body: JSON.stringify({ conversation_id: c.conversation_id }) });
        if (data) { showToast(t('Call logged')); }
    };
    const loadWhy = async (c) => {
        if (why[c.conversation_id]) { setWhy((w) => ({ ...w, [c.conversation_id]: null })); return; }
        const data = await api(`/marketing/growth/conversations/${c.conversation_id}/why`);
        if (data) setWhy((w) => ({ ...w, [c.conversation_id]: data.events }));
    };

    const s = summary;
    const reached = s.reached || {};
    const stuck = s.stuck || {};
    const stuckTotal = [stuck[0] || 0, stuck[1] || 0, stuck[2] || 0, s.ordered || 0];

    return (
        <AppLayout>
            <Head title={t('Marketing — Sales Growth')} />
            <div className="mkt">
                <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
                    <div>
                        <h3 style={{ fontSize: 20 }}>{t('Sales Growth')} {s.estimated && !s.data_check && <span className="chip chip-amber" style={{ verticalAlign: 'middle' }}>{t('Estimated')}</span>}</h3>
                        <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>{t('Who is stuck, and what to do today so more of them order.')}</p>
                    </div>
                    <div className="ptabs" style={{ marginBottom: 0, borderBottom: 'none' }}>
                        {PERIODS.map(([p, label]) => (
                            <button key={p} className={`ptab${period === p ? ' act' : ''}`} onClick={() => goPeriod(p)}>{t(label)}</button>
                        ))}
                    </div>
                </div>

                {s.data_check ? (
                    <div className="gw-card" style={{ textAlign: 'center', padding: '34px 20px' }}>
                        <div style={{ fontSize: 16, fontWeight: 700, color: '#411550' }}>{t('Data check in progress')}</div>
                        <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 6 }}>{t('The numbers are being verified. They will appear again when everything adds up.')}</p>
                    </div>
                ) : (
                    <>
                        <div className="mk-strip">
                            <div className="mk-kpi"><b>{fmtNum(s.wrote)}</b><span>{t('Wrote to you')}</span><div className="gw-prev">{t('Previous period: :count', { count: fmtNum(s.prev.wrote) })}</div></div>
                            <div className="mk-kpi"><b>{fmtNum(s.ordered)}</b><span>{t('Ordered')}</span><div className="gw-prev">{t('Previous period: :count', { count: fmtNum(s.prev.ordered) })}</div></div>
                            <div className="mk-kpi"><b>{s.conversion_pct === null ? t('Not enough data') : s.conversion_pct + '%'}</b><span>{t('Wrote to order')}</span><div className="gw-prev">{s.prev.conversion_pct === null ? t('Previous period: not enough data') : t('Previous period: :pct%', { pct: s.prev.conversion_pct })}</div></div>
                        </div>

                        {s.low_data && (
                            <div className="gw-note">{period === '30d' ? t('Not much data in this period (:count people). Percentages would not be trustworthy yet.', { count: fmtNum(s.wrote) }) : t('Not much data in this period (:count people). Percentages would not be trustworthy — try 30 days.', { count: fmtNum(s.wrote) })}</div>
                        )}

                        <div className="gw-cols">
                            <section className="gw-card">
                                <h4 className="gw-h">{t('Stages of a sale')}</h4>
                                <p className="gw-sub">{t('How many people reached each stage. Tap a stage to see what it means.')}</p>
                                <div style={{ marginTop: 10 }}>
                                    {STAGES.map((st, i) => {
                                        const v = reached[i] || 0;
                                        const w = s.wrote > 0 ? Math.max(5, Math.round(v / s.wrote * 100)) : 0;
                                        return (
                                            <div key={st.name}>
                                                <button type="button" className={`gw-stage${openStage === i ? ' on' : ''}`} onClick={() => setOpenStage(openStage === i ? null : i)}>
                                                    <span className="gw-stage-name"><b>{t(st.name)}</b>
                                                        <small>{i === 0 ? t('Start') : (s.step_pct && s.step_pct[i] !== null && s.step_pct[i] !== undefined ? t(':pct% of the stage before', { pct: s.step_pct[i] }) : '')}</small>
                                                    </span>
                                                    <span className="gw-bar" style={{ width: w + '%' }}>{v ? fmtNum(v) : '—'}</span>
                                                </button>
                                                {i < 3 && reached[i] - (reached[i + 1] || 0) > 0 && (
                                                    <div className="gw-drop">{t(':count people did not move to the next stage', { count: fmtNum(reached[i] - (reached[i + 1] || 0)) })}</div>
                                                )}
                                                {openStage === i && <div className="gw-def"><b>{t(st.name)}:</b> {t(st.def)}</div>}
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>

                            <section className="gw-card">
                                <h4 className="gw-h">{t('What to do today')}</h4>
                                <p className="gw-sub">{t('Most urgent first. At most 5 tasks.')}</p>
                                {taskList.filter((x) => x.status === 'open' || x.status === 'snoozed').length === 0 && (
                                    <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 12 }}>{t('Nothing to do today. New tasks appear here when customers get stuck.')}</p>
                                )}
                                {taskList.map((task) => {
                                    const tx = TASK_TEXT[task.kind];
                                    if (!tx || task.status === 'done') return null;
                                    return (
                                        <div key={task.id} className="gw-task">
                                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'flex-start' }}>
                                                <b style={{ fontSize: 15 }}>{t(tx.title, { count: fmtNum(task.count) })}</b>
                                                <span className={`gw-pill${task.kind === 'complaint' ? ' hot' : ''}`}>{t(tx.urgent)}</span>
                                            </div>
                                            <div style={{ color: '#64748B', fontSize: 13, marginTop: 2 }}>{t(tx.reason)}</div>
                                            {task.est_value !== null && task.est_value !== undefined && (
                                                <div style={{ color: '#64748B', fontSize: 13 }}>{t('If 1 in 10 comes back: about :amount (an estimate)', { amount: fmt(task.est_value) })}</div>
                                            )}
                                            <div style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
                                                <button type="button" className="btn-p" onClick={() => openTask(task)}>{t(tx.cta)} →</button>
                                                {task.status === 'open'
                                                    ? <button type="button" className="btn-s" onClick={() => setStatus(task, 'done')}>{t('Done')}</button>
                                                    : <button type="button" className="btn-s" onClick={() => setStatus(task, 'open')}>{t('Reopen')}</button>}
                                            </div>
                                        </div>
                                    );
                                })}
                                {taskList.some((x) => x.status === 'done') && (
                                    <div style={{ marginTop: 10 }}>
                                        {taskList.filter((x) => x.status === 'done').map((task) => (
                                            <div key={task.id} className="gw-done-row">
                                                <span>{t(TASK_TEXT[task.kind]?.title || '', { count: fmtNum(task.count) })}</span>
                                                <button type="button" className="btn-s" onClick={() => setStatus(task, 'open')}>{t('Reopen')}</button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>
                        </div>

                        <section className="gw-card" style={{ marginTop: 12 }}>
                            <h4 className="gw-h">{t('Where people are stuck now')}</h4>
                            <p className="gw-sub">{t('Everyone is in exactly one place, so the parts add up to the total.')}</p>
                            <div className="gw-stuckbar">
                                {stuckTotal.map((v, i) => v > 0 && s.wrote > 0 ? <i key={i} style={{ width: (v / s.wrote * 100) + '%', background: STUCK_COLORS[i] }} title={fmtNum(v)} /> : null)}
                            </div>
                            <div className="gw-legend">
                                {STUCK_LABELS.map((label, i) => (
                                    <span key={label}><i style={{ background: STUCK_COLORS[i] }} />{t(label)}: <b>{fmtNum(stuckTotal[i])}</b></span>
                                ))}
                            </div>
                            <details style={{ marginTop: 10, fontSize: 13 }}>
                                <summary style={{ cursor: 'pointer', color: '#411550', fontWeight: 600 }}>{t('How the numbers add up')}</summary>
                                <p style={{ color: '#64748B', marginTop: 6 }}>
                                    {fmtNum(s.wrote)} = {stuckTotal.map((v) => fmtNum(v)).join(' + ')}. {t('The stage numbers on the left mean "reached this stage", so each one is smaller than the one before. This split means "is here now".')}
                                </p>
                            </details>
                        </section>
                    </>
                )}
            </div>

            {drawer && (
                <div className="mkt gw-overlay" onClick={(e) => { if (e.target === e.currentTarget) setDrawer(null); }}>
                    <div className="gw-drawer">
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                            <h4 style={{ margin: 0, fontSize: 16 }}>{t(TASK_TEXT[drawer.task.kind]?.title || '', { count: fmtNum(drawer.task.count) })}</h4>
                            <button type="button" className="btn-s" onClick={() => setDrawer(null)}>{t('Close')}</button>
                        </div>
                        <p style={{ color: '#64748B', fontSize: 13, marginTop: 8 }}>{t('The AI makes a draft for each person. You review and send it from the Inbox — nothing is sent on its own.')}</p>
                        <button type="button" className="btn-p" style={{ width: '100%', marginTop: 8 }} disabled={busy} onClick={() => draftAll(drawer.task)}>{t('Make drafts for everyone who can be messaged')}</button>
                        <p style={{ color: '#64748B', fontSize: 13, marginTop: 8 }}>{t('People whose 24 hours have passed cannot be messaged — call them instead.')}</p>
                        {drawer.customers.map((c) => (
                            <div key={c.conversation_id} className="gw-cu">
                                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'center' }}>
                                    <b>{c.name}</b>
                                    <span className={`gw-pill${c.window_open ? ' ok' : ' hot'}`}>{c.window_open ? t('Can be messaged') : t('24 hours have passed')}</span>
                                </div>
                                {c.last_message && <div style={{ color: '#64748B', fontSize: 13, margin: '2px 0' }}>{c.last_message}</div>}
                                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                                    <span style={{ color: '#64748B', fontSize: 12.5 }}>{c.channel}{c.hours_since !== null ? ' · ' + t(':hours hours ago', { hours: fmtNum(c.hours_since) }) : ''}{c.phone ? ' · ' + c.phone : ''}</span>
                                    <span style={{ display: 'inline-flex', gap: 6 }}>
                                        <button type="button" className="btn-s" onClick={() => loadWhy(c)}>{t('Why this stage?')}</button>
                                        <button type="button" className="btn-p" disabled={!c.window_open || c.drafted || busy} onClick={() => draftOne(drawer.task, c)}>{c.drafted ? t('Done') : t('Draft')}</button>
                                        <button type="button" className="btn-s" onClick={() => logCall(drawer.task, c)}>{t('Call')}</button>
                                    </span>
                                </div>
                                {why[c.conversation_id] && (
                                    <div className="gw-why">
                                        {why[c.conversation_id].length === 0 && <div>{t('No recorded reason yet.')}</div>}
                                        {why[c.conversation_id].map((e, i) => (
                                            <div key={i}>{t(REASON_LABELS[e.reason] || e.reason)}{e.evidence?.intent ? ' · ' + e.evidence.intent : ''}</div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}
            {toast && <div className="gw-toast">{toast}</div>}
        </AppLayout>
    );
}
