import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

// Inbox - copied from the prototype messages.html msg-inbox panel:
// Inbox/Report toggle, 3-pane inbox (list · chat · customer panel).
const CH = {
    facebook: { label: 'Messenger', bg: '#dbeafe', tx: '#1d4ed8', avatar: '#411550' },
    whatsapp: { label: 'WhatsApp', bg: '#dcfce7', tx: '#065f46', avatar: '#065f46' },
    instagram: { label: 'Instagram', bg: '#fce7f3', tx: '#be185d', avatar: '#be185d' },
};
const BTN = { background: 'var(--acc-fill)', color: '#fff', border: 'none', padding: '9px 16px', borderRadius: 8, fontWeight: 600, cursor: 'pointer', fontSize: 13.5 };
const BTN2 = { background: 'var(--card)', color: 'var(--acc-ink)', border: '1px solid var(--line)', padding: '9px 14px', borderRadius: 8, fontWeight: 600, cursor: 'pointer', fontSize: 13.5 };
const BUB_C = { alignSelf: 'flex-start', background: 'var(--sel-bg)', borderRadius: 14, padding: '10px 13px', maxWidth: '75%', fontSize: 13.5, whiteSpace: 'pre-wrap' };
const BUB_B = { alignSelf: 'flex-end', background: 'var(--acc-fill)', color: '#fff', borderRadius: 14, padding: '10px 13px', maxWidth: '75%', fontSize: 13.5, whiteSpace: 'pre-wrap' };
const CAP = { alignSelf: 'flex-end', fontSize: 11.5, color: 'var(--note-ink)', marginTop: -4 };
const PILL = (bg, tx) => ({ display: 'inline-block', fontSize: 11, fontWeight: 700, borderRadius: 99, padding: '2px 9px', background: bg, color: tx });

// Approving/rejecting a draft changes the sidebar Inbox badge count;
// the layout listens for this event and refetches /sidebar-counts at
// once instead of waiting for its next poll.
const refreshCounts = () => window.dispatchEvent(new Event('skaleup:counts-refresh'));

function DraftCard({ draft }) {
    const [editing, setEditing] = useState(false);
    const [body, setBody] = useState(draft.body);
    const isOrder = draft.kind === 'order_summary';
    const approve = () => router.post(`/messages/drafts/${draft.id}/approve`, editing ? { body } : {}, { preserveScroll: true, onSuccess: refreshCounts });
    return (
        <div style={{ background: 'var(--note-bg)', border: '1px solid var(--note-line)', borderRadius: 12, padding: 13, marginTop: 10 }}>
            <b style={{ fontSize: 13.5 }}>{isOrder ? t('AI Order Summary') : t('AI Suggested Reply')}</b>{' '}
            {draft.status === 'flagged'
                ? <span style={PILL('#fee2e2', '#dc2626')}>{t('Money figures did not match - check before sending')}</span>
                : <span style={PILL('#fef3c7', '#92400e')}>{t('Needs your approval')}</span>}
            {editing
                ? <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={4} style={{ width: '100%', padding: '9px 11px', border: '1px solid var(--field-line)', borderRadius: 9, fontSize: 13.5, margin: '8px 0' }} />
                : <p style={{ fontSize: 13.5, margin: '8px 0', whiteSpace: 'pre-wrap' }}>{draft.body}</p>}
            <div style={{ fontSize: 12, color: 'var(--note-ink)', marginBottom: 9 }}>
                {draft.no_rule
                    ? <>{t('No rule matched - the AI drafted this from the product description. Check it before sending.')}</>
                    : <>{t('Rule')}: {draft.rule}{draft.ladder_step ? ` - ${t('ladder step')} ${draft.ladder_step}` : ''} · <Link href="/settings?group=ai" style={{ color: 'var(--note-ink)', fontWeight: 700 }}>{t('Change the rule in Settings')}</Link></>}
            </div>
            <button type="button" style={BTN} onClick={approve}>{isOrder ? t('Approve & Create Order') : t('Approve & Send')}</button>{' '}
            {!isOrder && <><button type="button" style={BTN2} onClick={() => setEditing(!editing)}>{t('Edit')}</button>{' '}</>}
            <button type="button" style={BTN2} onClick={() => router.post(`/messages/drafts/${draft.id}/reject`, {}, { preserveScroll: true, onSuccess: refreshCounts })}>{t('Reject')}</button>
        </div>
    );
}

export default function Inbox({ conversations, selected, report, aiMode, aiPanic, listTruncated }) {
    // "Pause all AI" flips the platform-global panic switch, so the
    // control belongs to platform admins only (the server refuses
    // everyone else); sellers see the status pill but no button.
    const isPlatformAdmin = !!usePage().props.auth?.user?.is_platform_admin;
    const [view, setView] = useState('main');
    const [channel, setChannel] = useState('all');
    const [search, setSearch] = useState('');
    const [detailOpen, setDetailOpen] = useState(false);
    const reply = useForm({ body: '' });
    const ch = selected ? (CH[selected.channel] || CH.facebook) : null;
    // Y1-F3: "Create Order" carries the conversation's context into
    // the New Order form - the customer name, phone and address from
    // the pane beside this thread, plus the product the conversation
    // is about - instead of opening a blank form.
    const createOrderHref = selected ? '/orders/create?' + new URLSearchParams({
        customer_name: selected.customer_name || '',
        customer_phone: selected.customer_phone || '',
        customer_address: selected.customer_address || '',
        ...(selected.product ? { product_id: String(selected.product.id) } : {}),
    }).toString() : '/orders/create';
    const q = search.toLowerCase();
    const list = conversations.filter((c) => (channel === 'all' || c.channel === channel) && (c.customer_name.toLowerCase().includes(q) || (c.phone || '').toLowerCase().includes(q) || (c.snippet || '').toLowerCase().includes(q)));

    const sendReply = (e) => {
        e.preventDefault();
        reply.post(`/messages/conversations/${selected.id}/reply`, { preserveScroll: true, onSuccess: () => reply.reset() });
    };

    // Live thread: pull the fresh conversation list + open chat every few
    // seconds so a new customer message appears on its own - the same way
    // the sidebar badges update - with no page refresh. It must NOT skip
    // while the tab reports hidden: the badge poll never checks that, and
    // a seller who leaves the inbox open in a background tab (or a browser
    // that reports the page occluded) would otherwise never see a new
    // conversation arrive until a manual reload. Skips only while a reply
    // is being sent or a poll is already in flight; typed text is kept
    // (state is preserved across the partial reload).
    const sendingRef = useRef(false);
    sendingRef.current = reply.processing;
    const pollingRef = useRef(false);
    useEffect(() => {
        const id = setInterval(() => {
            if (pollingRef.current || sendingRef.current) return;
            pollingRef.current = true;
            router.reload({
                only: ['conversations', 'selected', 'report'],
                preserveState: true,
                preserveScroll: true,
                onFinish: () => { pollingRef.current = false; },
            });
        }, 8000);
        return () => clearInterval(id);
    }, []);

    // Phone only: the panes stack, so a newly opened conversation sits below
    // the whole list - bring its card into view. Fires only when the open
    // conversation actually changes, never on the 8-second poll reload.
    const threadRef = useRef(null);
    const prevSelRef = useRef(null);
    useEffect(() => {
        const selId = selected?.id ?? null;
        if (selId === prevSelRef.current) return;
        prevSelRef.current = selId;
        if (selId && window.matchMedia('(max-width: 900px)').matches) {
            const tm = setTimeout(() => threadRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80);
            return () => clearTimeout(tm);
        }
    }, [selected?.id]);

    const toggleBtn = (active) => ({ background: active ? 'var(--acc-fill)' : 'var(--tgl-bg)', color: active ? '#fff' : 'var(--tgl-ink)', border: 'none', padding: '8px 18px', borderRadius: 99, fontWeight: 700, cursor: 'pointer', fontSize: 13 });
    const chip = (active) => ({ display: 'inline-block', fontSize: 11, fontWeight: 700, borderRadius: 99, background: active ? 'var(--acc-fill)' : 'var(--tgl-bg)', color: active ? '#fff' : 'var(--tgl-ink)', cursor: 'pointer', padding: '5px 12px' });

    return (
        <AppLayout title={t('Inbox')}>
            <div className="msg-pg">
            <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
                <button type="button" style={toggleBtn(view === 'main')} onClick={() => setView('main')}>{t('Inbox')}</button>
                <button type="button" style={toggleBtn(view === 'report')} onClick={() => setView('report')}>{t('Report')}</button>
            </div>

            {view === 'report' && (
                <div>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 14, marginBottom: 16 }}>
                        <div className="card kpi"><div className="lbl">{t('CONVERSATIONS')}</div><div className="val">{report.conversations}</div><div className="sub">{t('All channels')}</div></div>
                        <div className="card kpi"><div className="lbl">{t('AI REPLIES')}</div><div className="val">{report.ai_replies}</div><div className="sub">{report.ai_approved} {t('approved by staff')}</div></div>
                        <div className="card kpi"><div className="lbl">{t('DRAFTS WAITING')}</div><div className="val">{report.drafts_waiting}</div><div className="sub">{t('AI made them - you send')}</div></div>
                        <div className="card kpi"><div className="lbl">{t('ORDERS FROM CHAT')}</div><div className="val">{report.orders_from_chat}</div><div className="sub">৳{Number(report.orders_revenue).toLocaleString()} {t('revenue')}</div></div>
                    </div>
                    <div className="card">
                        <h3 style={{ fontSize: 16, marginBottom: 10 }}>{t('Which rule ran how many times')}</h3>
                        <table className="tbl"><thead><tr><th>{t('Rule')}</th><th>{t('Times run')}</th><th>{t('Last ran')}</th><th></th></tr></thead>
                            <tbody>
                                {report.rule_stats.map((r) => (
                                    <tr key={r.name}><td>{r.name}</td><td>{r.count}</td><td>{r.last}</td><td><Link href="/settings?group=ai" style={{ color: 'var(--acc-ink)', fontWeight: 600 }}>{t('See the rule in Settings')}</Link></td></tr>
                                ))}
                                {report.rule_stats.length === 0 && <tr><td colSpan={4} className="mut">{t('No rules yet')}</td></tr>}
                            </tbody></table>
                    </div>
                </div>
            )}

            {view === 'main' && (
                <>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14, flexWrap: 'wrap' }}>
                        <h3 style={{ fontSize: 19 }}>{t('Inbox')}</h3>
                        {aiPanic
                            ? <span style={PILL('#fee2e2', '#dc2626')}>{t('AI is paused - Pause all AI is on')}</span>
                            : <span style={PILL('#dcfce7', '#15803d')}>{aiMode === 'auto' || aiMode === 'full_auto' ? t('AI is on · Auto - safe replies go by themselves') : t('AI is on · Draft only - AI writes, you send')}</span>}
                        <span style={{ fontSize: 12.5, color: 'var(--mut-ink)' }}>{t('First-week rule - a wrong answer must not reach the customer')}</span>
                        {isPlatformAdmin && <button type="button" onClick={() => router.post('/messages/panic', {}, { preserveScroll: true })} style={{ ...BTN2, marginLeft: 'auto' }}>{aiPanic ? t('Resume AI') : t('Pause all AI')}</button>}
                    </div>

                    <div className="msg-panes">
                        <div className="card msg-list" style={{ padding: 10 }}>
                            <input placeholder={t('Search conversations...')} value={search} onChange={(e) => setSearch(e.target.value)} style={{ width: '100%', padding: '9px 11px', border: '1px solid var(--field-line)', borderRadius: 8, marginBottom: 9, background: 'var(--field-bg)', color: 'var(--ink)' }} />
                            <div style={{ display: 'flex', gap: 6, marginBottom: 10, flexWrap: 'wrap' }}>
                                {[['all', 'All'], ['facebook', 'Messenger'], ['whatsapp', 'WhatsApp'], ['instagram', 'Instagram']].map(([v, l]) => (
                                    <span key={v} style={chip(channel === v)} onClick={() => setChannel(v)}>{t(l)}</span>
                                ))}
                            </div>
                            <div className="msg-list-scroll">
                                {list.map((c) => {
                                    const cc = CH[c.channel] || CH.facebook;
                                    return (
                                        <Link key={c.id} href={`/messages/inbox?c=${c.id}`} style={{ display: 'block', padding: 10, borderRadius: 10, cursor: 'pointer', background: selected && selected.id === c.id ? 'var(--sel-bg)' : '', textDecoration: 'none', color: 'inherit' }}>
                                            <div style={{ display: 'flex', justifyContent: 'space-between' }}><b>{c.customer_name}</b><span style={{ fontSize: 11, color: 'var(--faint-ink)' }}>{c.time}</span></div>
                                            <div style={{ fontSize: 12, color: 'var(--mut-ink)' }}>{c.snippet}</div>
                                            <span style={PILL(cc.bg, cc.tx)}>{cc.label}</span>{' '}
                                            {c.has_draft && <span style={PILL('#fef3c7', '#92400e')}>{t('Draft ready')}</span>}
                                            {c.ai_replied && <span style={PILL('#dcfce7', '#15803d')}>{t('AI replied')}</span>}
                                            {c.needs_human && <span style={PILL('#fee2e2', '#dc2626')}>{t('Needs a human')}</span>}
                                            {c.unread > 0 && <span style={PILL('var(--acc-fill)', '#fff')}>{c.unread}</span>}
                                        </Link>
                                    );
                                })}
                                {list.length === 0 && <div style={{ fontSize: 13, color: 'var(--mut-ink)', padding: 8 }}>{t('No conversations')}</div>}
                            </div>
                            {listTruncated && <div style={{ fontSize: 12, color: 'var(--mut-ink)', padding: '6px 8px 2px' }}>{t('Showing the latest 100 conversations')}</div>}
                        </div>

                        {selected && list.length > 0 && (
                            <div className="card msg-thread" ref={threadRef} style={{ flex: 1, minWidth: 0, padding: 18 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
                                    <b style={{ fontSize: 15.5 }}>{selected.customer_name}</b>
                                    <span style={PILL(ch.bg, ch.tx)}>{ch.label}</span>
                                    <span style={selected.window_open ? PILL('var(--tgl-bg)', 'var(--tgl-ink)') : PILL('#fee2e2', '#dc2626')}>{selected.window_open ? t('24h window: Open') : t('24h window: Closed')}</span>
                                    <span style={{ ...PILL('var(--tgl-bg)', 'var(--tgl-ink)'), marginLeft: 'auto' }}>{selected.ai_paused ? t('AI: Off for this chat') : t('AI: On for this chat')}</span>
                                    <button type="button" className="msg-detail-toggle" style={{ ...BTN2, padding: '7px 13px' }} onClick={() => setDetailOpen(true)}>{t('Customer details')}</button>
                                    <Link href={createOrderHref} style={{ ...BTN, padding: '7px 13px', textDecoration: 'none' }}>{t('Create Order')}</Link>
                                </div>
                                <div className="msg-scroll" style={{ display: 'flex', flexDirection: 'column', gap: 9, padding: '12px 2px' }}>
                                    {selected.thread_truncated && <div style={{ alignSelf: 'center', fontSize: 12, color: 'var(--mut-ink)' }}>{t('Showing the latest 300 messages')}</div>}
                                    {selected.messages.map((m) => (
                                        <div key={m.id} style={{ display: 'contents' }}>
                                            <div style={m.direction === 'in' ? BUB_C : BUB_B}>
                                                {m.attachment_type === 'image' && m.attachment_path && (
                                                    <img src={'/storage/' + m.attachment_path} alt="" style={{ maxWidth: 220, borderRadius: 8, display: 'block', marginBottom: m.body ? 6 : 0 }} />
                                                )}
                                                {(m.attachment_type === 'audio' || m.attachment_type === 'voice') && (
                                                    <div style={{ fontSize: 12.5, opacity: 0.85, marginBottom: m.body ? 4 : 0 }}>{t('Voice message')}{m.transcript ? ' - ' + m.transcript : ''}</div>
                                                )}
                                                {m.body ? <div>{m.body}</div> : null}
                                            </div>
                                            {m.direction === 'out' && m.send_status === 'failed' && (
                                                <div style={{ ...CAP, color: '#dc2626' }}>{t('Failed to send - Meta did not accept it')}</div>
                                            )}
                                            {m.direction === 'out' && m.sender === 'ai' && m.rule && (
                                                <div style={CAP}>{t('Rule')}: {m.rule} · <Link href="/settings?group=ai" style={{ color: 'var(--note-ink)', fontWeight: 700 }}>{t('Settings')}</Link></div>
                                            )}
                                        </div>
                                    ))}
                                    {selected.messages.length === 0 && <div style={{ color: 'var(--mut-ink)', fontSize: 13 }}>{t('No messages yet')}</div>}
                                </div>
                                <div>
                                    {selected.drafts.map((d) => <DraftCard key={d.id} draft={d} />)}
                                </div>
                                <form onSubmit={sendReply} style={{ display: 'flex', gap: 9, marginTop: 10 }}>
                                    <input placeholder={t('Type a message...')} value={reply.data.body} onChange={(e) => reply.setData('body', e.target.value)} style={{ flex: 1, minWidth: 0, padding: '10px 12px', border: '1px solid var(--field-line)', borderRadius: 9, background: 'var(--field-bg)', color: 'var(--ink)' }} />
                                    <button type="submit" style={BTN} disabled={reply.processing}>{t('Send')}</button>
                                </form>
                            </div>
                        )}

                        {selected && list.length > 0 && detailOpen && (
                            <div className="msg-detail-backdrop" onClick={() => setDetailOpen(false)} />
                        )}

                        {selected && list.length > 0 && (
                            <div className={"card msg-detail" + (detailOpen ? " open" : "")} style={{ padding: 16 }}>
                                <div style={{ textAlign: 'right' }}>
                                    <button type="button" className="msg-detail-close" style={{ ...BTN2, padding: '4px 11px' }} onClick={() => setDetailOpen(false)}>✕</button>
                                </div>
                                <div style={{ textAlign: 'center', marginBottom: 10 }}>
                                    <div style={{ width: 52, height: 52, borderRadius: '50%', background: ch.avatar, color: '#fff', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 19 }}>{selected.customer_name.replace('+', '').charAt(0).toUpperCase()}</div>
                                    <div style={{ fontWeight: 700, marginTop: 6 }}>{selected.customer_name}</div>
                                    <div style={{ fontSize: 12, color: 'var(--mut-ink)' }}>{[selected.customer_phone, selected.customer_address].filter(Boolean).join(' · ')}</div>
                                </div>
                                <div style={{ display: 'flex', justifyContent: 'space-around', textAlign: 'center', borderTop: '1px solid #eef2f7', borderBottom: '1px solid #eef2f7', padding: '10px 0' }}>
                                    <div><b>{selected.stats.orders}</b><div style={{ fontSize: 11, color: 'var(--mut-ink)' }}>{t('Orders')}</div></div>
                                    <div><b style={{ color: '#16a34a' }}>{selected.stats.success !== null ? selected.stats.success + '%' : t('New')}</b><div style={{ fontSize: 11, color: 'var(--mut-ink)' }}>{t('Success rate')}</div></div>
                                    <div><b>৳{Number(selected.stats.ltv).toLocaleString()}</b><div style={{ fontSize: 11, color: 'var(--mut-ink)' }}>{t('Lifetime value')}</div></div>
                                </div>
                                {selected.product && selected.ladder.length > 1 && (
                                    <div style={{ marginTop: 12 }}>
                                        <b style={{ fontSize: 13 }}>{t('Negotiation - where the bargaining stands')}</b>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 5, marginTop: 9, flexWrap: 'wrap' }}>
                                            {selected.ladder.map((rung, i) => {
                                                const isFloor = i === selected.ladder.length - 1;
                                                const isNow = selected.neg_offer !== null && Math.abs(rung - selected.neg_offer) < 0.01;
                                                return (
                                                    <span key={i} style={{ display: 'contents' }}>
                                                        {i > 0 && <span>→</span>}
                                                        <span style={{ background: isNow ? 'var(--acc-fill)' : isFloor ? '#fee2e2' : 'var(--tgl-bg)', color: isNow ? '#fff' : isFloor ? '#dc2626' : 'inherit', borderRadius: 8, padding: '5px 9px', fontSize: 12.5, fontWeight: isNow || isFloor ? 700 : 400 }}>৳{Number(rung).toLocaleString()}{i === 0 ? ` ${t('price')}` : isNow ? ` ${t('now')}` : isFloor ? ` ${t('floor')}` : ''}</span>
                                                    </span>
                                                );
                                            })}
                                        </div>
                                        <div style={{ fontSize: 12, color: 'var(--mut-ink)', marginTop: 7 }}>{t('The AI never goes below the floor - held back by code.')}</div>
                                    </div>
                                )}
                                {selected.orders.length > 0 && (
                                    <div style={{ marginTop: 12 }}>
                                        <b style={{ fontSize: 13 }}>{t('Previous orders')}</b>
                                        <div style={{ fontSize: 12.5, color: 'var(--tgl-ink)', marginTop: 6 }}>
                                            {selected.orders.map((o) => <div key={o.invoice}>{o.invoice} · {o.status} · ৳{Number(o.total).toLocaleString()}</div>)}
                                        </div>
                                    </div>
                                )}
                                <button type="button" onClick={() => router.post(`/messages/conversations/${selected.id}/toggle-ai`, {}, { preserveScroll: true })} style={{ ...BTN2, width: '100%', marginTop: 14 }}>{selected.ai_paused ? t('Give the chat back to AI') : t('Take over chat')}</button>
                            </div>
                        )}

                        {list.length === 0 && (
                            <div className="card" style={{ flex: 1, minWidth: 0, padding: 18 }}>
                                <div style={{ fontSize: 13.5, color: 'var(--mut-ink)', padding: 8 }}>{t('No conversations')}</div>
                            </div>
                        )}
                    </div>
                </>
            )}
            </div>
        </AppLayout>
    );
}
