import { router } from '@inertiajs/react';
import { t } from '@/lib/i18n';

export const fmt = (n) => '৳' + Number(Math.round(Number(n) || 0)).toLocaleString('en-IN');

export function StatusChip({ row }) {
    if (row.status_key === 'present') return <span className="chip chip-green">{t('Present')}</span>;
    if (row.status_key === 'grace') return <span className="chip chip-amber">{t('Late (within grace)')}</span>;
    if (row.status_key === 'late') return <span className="chip chip-red">{t('Late')}</span>;
    if (row.status_key === 'leave') return <span className="chip chip-blue">{t('Leave')}</span>;
    return <span className="chip chip-grey">{t('Absent')}</span>;
}

export function TodayTable({ rows }) {
    return (
        <table className="x-table">
            <thead>
                <tr><th>{t('Name')}</th><th>{t('Department')}</th><th>{t('Check-in')}</th><th>{t('Check-out')}</th><th>{t('Status')}</th></tr>
            </thead>
            <tbody>
                {rows.map((r) => (
                    <tr key={r.user_id}>
                        <td>{r.name}</td>
                        <td>{r.department || '-'}</td>
                        <td>{r.check_in || '-'}</td>
                        <td>{r.check_out || '-'}</td>
                        <td><StatusChip row={r} /></td>
                    </tr>
                ))}
                {rows.length === 0 && (
                    <tr><td colSpan={5} style={{ color: '#94A3B8' }}>{t('No staff added yet - Admin logins are not counted as staff.')}</td></tr>
                )}
            </tbody>
        </table>
    );
}

export function LeaveCards({ leaves, canManage }) {
    if (!leaves || leaves.length === 0) return null;
    const decide = (id, decision) => router.post(`/hrm/leaves/${id}/decide`, { decision }, { preserveScroll: true });
    return (
        <div className="x-card">
            <h4>{t('Leave applications - decision pending')}</h4>
            {leaves.map((l) => (
                <div key={l.id} className="leave-row" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, padding: '9px 0', borderBottom: '1px solid #F1F5F9', flexWrap: 'wrap' }}>
                    <div style={{ fontSize: 13.5 }}>
                        <b>{l.name}</b> - {t(l.days === 1 ? 'Leave from :from to :to (:days day)' : 'Leave from :from to :to (:days days)', { from: l.from, to: l.to, days: l.days })}
                        {l.reason && <span style={{ color: '#64748B' }}> · {t('Reason')}: {l.reason}</span>}
                    </div>
                    {canManage && (
                        <div className="row-actions">
                            <button type="button" className="btn-mini" onClick={() => decide(l.id, 'approved')}>{t('Approve')}</button>
                            <button type="button" className="btn-mini danger" onClick={() => decide(l.id, 'rejected')}>{t('Reject')}</button>
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}
