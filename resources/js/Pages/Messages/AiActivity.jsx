import { Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

// AI Activity - copied from the prototype messages.html msg-activity panel:
// what the AI did today, the draft queue, handovers, and per-rule counts.
// Rules are changed in Settings; here you only watch and approve.
const CH = { facebook: 'Messenger', whatsapp: 'WhatsApp', instagram: 'Instagram' };
const PILL = (bg, tx) => ({ display: 'inline-block', fontSize: 11, fontWeight: 700, borderRadius: 99, padding: '2px 9px', background: bg, color: tx });

export default function AiActivity({ stats, metrics, draftQueue, handed, ruleStats, feed }) {
    const MINI = ({ v, label }) => (
        <div><div style={{ fontSize: 20, fontWeight: 700 }}>{v}</div><div style={{ fontSize: 12, color: '#64748B' }}>{label}</div></div>
    );
    return (
        <AppLayout title={t('AI Activity')}>
            <div className="msg-pg">
                <h3 style={{ fontSize: 19, marginBottom: 4 }}>{t('AI Activity')}</h3>
                <p style={{ fontSize: 12.5, color: '#64748B', margin: '0 0 14px' }}>{t('What the AI did today - all in one place. To change a rule, change it in Settings; here you only watch and approve.')}</p>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 14, marginBottom: 18 }}>
                    <div className="card kpi"><div className="val">{stats.ai_replies_today}</div><div className="sub">{t('AI replies today')}</div></div>
                    <div className="card kpi"><div className="val">{stats.sent_by_ai}</div><div className="sub">{t('Sent by AI itself')}</div></div>
                    <div className="card kpi"><div className="val">{stats.drafts_waiting}</div><div className="sub">{t('Drafts waiting for you')}</div></div>
                    <div className="card kpi"><div className="val">{stats.handed}</div><div className="sub">{t('Handed to human')}</div></div>
                </div>

                {metrics && (
                    <div className="card" style={{ marginBottom: 18 }}>
                        <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Last 7 days - how the AI is doing')}</h4>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 12 }}>
                            <MINI v={metrics.auto_sent} label={t('Auto-sent')} />
                            <MINI v={metrics.drafts} label={t('Drafts made')} />
                            <MINI v={metrics.flagged} label={t('Flagged by the money guard')} />
                            <MINI v={metrics.handed} label={t('Handed to human')} />
                            <MINI v={metrics.auto_rate === null ? '-' : metrics.auto_rate + '%'} label={t('Auto-send rate')} />
                            <MINI v={metrics.talker_share === null ? '-' : metrics.talker_share + '%'} label={t('Model-phrased')} />
                            <MINI v={metrics.avg_latency_s === null ? '-' : metrics.avg_latency_s + 's'} label={t('Avg answer time')} />
                        </div>
                        <div style={{ fontSize: 12, color: '#94A3B8', marginTop: 8 }}>{t('Counted from the AI activity log of the last 7 days.')}</div>
                    </div>
                )}

                <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Draft queue - waiting for your approval')}</h4>
                {draftQueue.map((d) => (
                    <div key={d.kind + d.id} className="card" style={{ padding: '13px 15px', marginBottom: 10 }}>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                            <b>{d.customer}</b>
                            <span style={PILL('#f3f4f6', '#374151')}>{CH[d.channel] || d.channel}</span>
                            {d.status === 'flagged' && <span style={PILL('#fee2e2', '#dc2626')}>{t('Money figures did not match')}</span>}
                        </div>
                        {d.customer_text && <div style={{ fontSize: 13, color: '#64748B', marginTop: 5 }}>{t('Customer')}: "{d.customer_text}"</div>}
                        <div style={{ fontSize: 13.5, marginTop: 4 }}>{d.summary_kind === 'order_summary' ? <>{t('Order details came in - the AI made an order summary.')}</> : <>{t('AI draft')}: "{d.body}"{d.rule ? ` (${d.rule})` : ''}</>}</div>
                        <div style={{ marginTop: 9, display: 'flex', gap: 7 }}>
                            {d.kind === 'chat'
                                ? <>
                                    <button type="button" className="ctl-btn pri" onClick={() => router.post(`/messages/drafts/${d.id}/approve`, {}, { preserveScroll: true })}>{t('Approve & Send')}</button>
                                    <Link href={`/messages/inbox?c=${d.conversation_id}`} className="ctl-btn" style={{ textDecoration: 'none' }}>{t('Open chat')}</Link>
                                </>
                                : <>
                                    <button type="button" className="ctl-btn pri" onClick={() => router.post(`/messages/comment-replies/${d.id}/approve`, {}, { preserveScroll: true })}>{t('Approve & Send')}</button>
                                    <Link href="/messages/comments" className="ctl-btn" style={{ textDecoration: 'none' }}>{t('Open comments')}</Link>
                                </>}
                        </div>
                    </div>
                ))}
                {draftQueue.length === 0 && <div className="card" style={{ padding: 16, color: '#64748B', fontSize: 13.5, marginBottom: 18 }}>{t('No drafts are waiting.')}</div>}

                <h4 style={{ fontSize: 15, margin: '18px 0 10px' }}>{t('Handed to a human - why?')}</h4>
                {handed.map((h) => (
                    <div key={h.id} className="card" style={{ padding: '13px 15px', marginBottom: 10 }}>
                        <b>{h.customer}</b> <span style={PILL('#f3f4f6', '#374151')}>{CH[h.channel] || h.channel}</span>
                        <div style={{ fontSize: 13.5, marginTop: 5 }}>{h.reason}</div>
                        {h.last && <div style={{ fontSize: 13, color: '#64748B', marginTop: 3 }}>"{h.last}" - {t('the AI stopped and called you.')}</div>}
                        <div style={{ marginTop: 9 }}><Link href={`/messages/inbox?c=${h.id}`} className="ctl-btn" style={{ textDecoration: 'none' }}>{t('Open chat')}</Link></div>
                    </div>
                ))}
                {handed.length === 0 && <div className="card" style={{ padding: 16, color: '#64748B', fontSize: 13.5 }}>{t('Nothing has been handed to a human.')}</div>}

                <div className="card" style={{ marginTop: 18 }}>
                    <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Which rule ran how many times - today')}</h4>
                    <table className="tbl"><thead><tr><th>{t('Rule')}</th><th>{t('Times run')}</th><th>{t('Last ran')}</th><th></th></tr></thead>
                        <tbody>
                            {ruleStats.map((r) => (
                                <tr key={r.name}><td>{r.name}</td><td>{r.count}</td><td>{r.last}</td><td><Link href="/settings?group=ai" style={{ color: '#411550', fontWeight: 600 }}>{t('See the rule in Settings')}</Link></td></tr>
                            ))}
                            {ruleStats.length === 0 && <tr><td colSpan={4} className="mut">{t('No rules yet')}</td></tr>}
                        </tbody></table>
                </div>

                {feed.length > 0 && (
                    <div className="card" style={{ marginTop: 18 }}>
                        <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Latest activity')}</h4>
                        {feed.map((a, i) => (
                            <div key={i} style={{ fontSize: 13, padding: '6px 0', borderTop: i ? '1px solid #eef2f7' : 'none' }}>
                                <b>{a.action}</b> - {a.detail} <span style={{ color: '#94A3B8' }}>· {a.when}</span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
