import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { confirmDialog } from '@/lib/confirm';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

// Comments - copied from the prototype messages.html msg-comments panel.
// One list for Facebook + Instagram comments; no bargaining in public —
// a price question gets the fixed public answer + one private reply.
const CH = {
    facebook: { label: 'Facebook', bg: '#dbeafe', tx: '#1d4ed8', avatar: '#411550' },
    instagram: { label: 'Instagram', bg: '#fce7f3', tx: '#be185d', avatar: '#be185d' },
};
const STATUS = {
    awaiting: { label: 'Awaiting reply', bg: '#FEF3C7', tx: '#92400E' },
    replied: { label: 'Replied', bg: '#dcfce7', tx: '#15803d' },
    private_sent: { label: 'Private reply sent', bg: '#DBEAFE', tx: '#1D4ED8' },
    hidden: { label: 'Hidden', bg: '#E5E7EB', tx: '#374151' },
    done: { label: 'No reply needed', bg: '#f3f4f6', tx: '#374151' },
};
const PILL = (bg, tx) => ({ display: 'inline-block', fontSize: 11, fontWeight: 700, borderRadius: 99, padding: '2px 9px', background: bg, color: tx });
const chip = (active) => ({ display: 'inline-block', fontSize: 12, fontWeight: 700, borderRadius: 99, padding: '5px 13px', background: active ? '#411550' : '#f3f4f6', color: active ? '#fff' : '#374151', cursor: 'pointer' });

function CommentCard({ c }) {
    const [replyOpen, setReplyOpen] = useState(false);
    const [replyText, setReplyText] = useState('');
    const ch = CH[c.channel] || CH.facebook;
    const st = STATUS[c.status] || STATUS.awaiting;
    const drafts = c.replies.filter((r) => r.status === 'draft' || r.status === 'flagged');
    const sent = c.replies.filter((r) => r.status === 'sent');
    const post = (url, data = {}) => router.post(url, data, { preserveScroll: true });

    return (
        <div className="card" style={{ padding: '14px 16px', marginBottom: 12, opacity: c.status === 'hidden' ? 0.55 : 1 }}>
            <div style={{ display: 'flex', gap: 11 }}>
                <div style={{ width: 38, height: 38, borderRadius: '50%', background: ch.avatar, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 15, flexShrink: 0 }}>{c.author_name.charAt(0)}</div>
                <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                        <b style={{ fontSize: 14 }}>{c.author_name}</b>
                        <span style={PILL(ch.bg, ch.tx)}>{ch.label}</span>
                        <span style={{ fontSize: 12, color: '#64748B' }}>{c.post_label} · {c.time}</span>
                        <span style={{ marginLeft: 'auto' }}><span style={PILL(st.bg, st.tx)}>{t(st.label)}</span></span>
                    </div>
                    <p style={{ fontSize: 14, margin: '7px 0 0', color: '#1E293B' }}>"{c.body}"</p>

                    {sent.map((r) => (
                        <div key={r.id} style={{ marginTop: 10, background: '#F4EDF7', borderRadius: 10, padding: '9px 12px', fontSize: 13.5 }}>
                            <b>{shop_name}</b> <span style={{ color: '#64748B', fontSize: 12 }}>({t('your Page')})</span><br />{r.body}
                            {r.rule && <div style={{ fontSize: 12, color: '#92400e', marginTop: 6 }}>{t('Rule')}: {r.rule} · <Link href="/settings?group=ai" style={{ color: '#92400e', fontWeight: 700 }}>{t('See the rule in Settings')}</Link></div>}
                        </div>
                    ))}
                    {c.private_reply_body && (
                        <div style={{ fontSize: 12, color: '#1d4ed8', marginTop: 8 }}>{t('Private reply sent')} - "{c.private_reply_body}"</div>
                    )}
                    {c.hidden_by_ai && (
                        <div style={{ fontSize: 12, color: '#92400e', marginTop: 8 }}>{t('The AI hid this - spam pattern matched · unhide it if it is wrong')} · <Link href="/messages/activity" style={{ color: '#92400e', fontWeight: 700 }}>{t('See it in AI Activity')}</Link></div>
                    )}

                    {drafts.map((r) => (
                        <DraftReply key={r.id} reply={r} />
                    ))}

                    <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', marginTop: 10 }}>
                        <button type="button" className="ctl-btn" onClick={() => setReplyOpen(!replyOpen)}>{t('Reply')}</button>
                        {c.private_reply_at
                            ? <button type="button" className="ctl-btn" disabled>{t('Private reply sent')}</button>
                            : <button type="button" className="ctl-btn" onClick={() => post(`/messages/comments/${c.id}/private-reply`)}>{t('Private Reply')}</button>}
                        <button type="button" className="ctl-btn" onClick={() => post(`/messages/comments/${c.id}/like`)}>{c.liked ? t('Liked') : t('Like')}</button>
                        <button type="button" className="ctl-btn" onClick={() => post(`/messages/comments/${c.id}/hide`)}>{c.status === 'hidden' ? t('Unhide') : t('Hide')}</button>
                        <button type="button" className="ctl-btn" onClick={async () => { if (await confirmDialog(t('Delete this comment?'))) router.delete(`/messages/comments/${c.id}`, { preserveScroll: true }); }}>{t('Delete')}</button>
                        <button type="button" className="ctl-btn" onClick={() => post(`/messages/comments/${c.id}/done`)}>{t('Done - no reply needed')}</button>
                    </div>
                    {replyOpen && (
                        <div style={{ marginTop: 10 }}>
                            <textarea rows={2} value={replyText} onChange={(e) => setReplyText(e.target.value)} placeholder={t('Write your reply...')} style={{ width: '100%', padding: '9px 11px', border: '1px solid #d1d5db', borderRadius: 9, fontSize: 13.5 }} />
                            <div style={{ marginTop: 7, display: 'flex', gap: 7 }}>
                                <button type="button" className="ctl-btn pri" onClick={() => { post(`/messages/comments/${c.id}/reply`, { body: replyText }); setReplyOpen(false); setReplyText(''); }}>{t('Send reply')}</button>
                                <button type="button" className="ctl-btn" onClick={() => setReplyOpen(false)}>{t('Cancel')}</button>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function DraftReply({ reply }) {
    const [editing, setEditing] = useState(false);
    const [body, setBody] = useState(reply.body);
    return (
        <div style={{ background: '#FFFBEB', border: '1px solid #FDE68A', borderRadius: 12, padding: 12, marginTop: 10 }}>
            <b style={{ fontSize: 13 }}>AI Draft</b>{' '}
            {reply.status === 'flagged'
                ? <span style={PILL('#fee2e2', '#dc2626')}>{t('Money figures did not match - check before sending')}</span>
                : <span style={PILL('#fef3c7', '#92400e')}>{t('Draft ready')}</span>}
            {editing
                ? <textarea rows={3} value={body} onChange={(e) => setBody(e.target.value)} style={{ width: '100%', padding: '9px 11px', border: '1px solid #d1d5db', borderRadius: 9, fontSize: 13.5, margin: '7px 0' }} />
                : <p style={{ fontSize: 13.5, margin: '7px 0' }}>{reply.body}</p>}
            <div style={{ fontSize: 12, color: '#92400e', marginBottom: 9 }}>{t('Rule')}: {reply.rule} · {t('comments are a public place - check before sending')} · <Link href="/settings?group=ai" style={{ color: '#92400e', fontWeight: 700 }}>{t('Settings')}</Link></div>
            <button type="button" className="ctl-btn pri" onClick={() => router.post(`/messages/comment-replies/${reply.id}/approve`, editing ? { body } : {}, { preserveScroll: true })}>{t('Approve & Send')}</button>{' '}
            <button type="button" className="ctl-btn" onClick={() => setEditing(!editing)}>{t('Edit')}</button>{' '}
            <button type="button" className="ctl-btn" onClick={() => router.post(`/messages/comment-replies/${reply.id}/reject`, {}, { preserveScroll: true })}>{t('Reject')}</button>
        </div>
    );
}

export default function Comments({ comments, counts, posts, shop_name }) {
    const [channel, setChannel] = useState('all');
    const [status, setStatus] = useState('all');
    const [post, setPost] = useState('all');
    const list = comments.filter((c) => (channel === 'all' || c.channel === channel) && (status === 'all' || c.status === status) && (post === 'all' || c.post_label === post));

    return (
        <AppLayout title={t('Comments')}>
            <div className="msg-pg">
                <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 6, flexWrap: 'wrap' }}>
                    <h3 style={{ fontSize: 19 }}>{t('Comments')}</h3>
                    <span style={{ fontSize: 12.5, color: '#64748B' }}>{t('Today')} {counts.today} {t('comments came in')} · {t('reply left')} {counts.awaiting} · {counts.ai_handled} {t('handled by AI')}</span>
                </div>
                <p style={{ fontSize: 12.5, color: '#64748B', margin: '0 0 14px' }}>{t('All Facebook and Instagram post comments in one place. No bargaining in public comments - a price question gets the fixed answer and is brought to the Inbox by Private Reply, bargaining happens there.')}</p>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 10, flexWrap: 'wrap' }}>
                    {[['all', 'All'], ['facebook', 'Facebook'], ['instagram', 'Instagram']].map(([v, l]) => (
                        <span key={v} style={chip(channel === v)} onClick={() => setChannel(v)}>{l}</span>
                    ))}
                    <select value={post} onChange={(e) => setPost(e.target.value)} style={{ marginLeft: 'auto', width: 240, maxWidth: '100%', padding: '8px 10px', border: '1px solid #d1d5db', borderRadius: 9, fontSize: 13 }}>
                        <option value="all">{t('All posts')}</option>
                        {posts.map((p) => <option key={p} value={p}>{p}</option>)}
                    </select>
                </div>
                <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>
                    {[['all', t('All')], ['awaiting', t('Awaiting reply')], ['replied', t('Replied')], ['private_sent', t('Private reply sent')], ['hidden', t('Hidden')], ['done', t('No reply needed')]].map(([v, l]) => (
                        <span key={v} style={chip(status === v)} onClick={() => setStatus(v)}>{l}</span>
                    ))}
                </div>
                <div>
                    {list.map((c) => <CommentCard key={c.id} c={c} />)}
                    {list.length === 0 && <div className="card" style={{ padding: 22, textAlign: 'center', color: '#64748B', fontSize: 13.5 }}>{t('No comments in this filter.')}</div>}
                </div>
            </div>
        </AppLayout>
    );
}
