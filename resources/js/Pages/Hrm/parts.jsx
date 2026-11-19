import { router } from '@inertiajs/react';

export const fmt = (n) => '৳' + Number(Math.round(Number(n) || 0)).toLocaleString('en-IN');

export function StatusChip({ row }) {
    if (row.status_key === 'present') return <span className="chip chip-green">এসেছে</span>;
    if (row.status_key === 'grace') return <span className="chip chip-amber">দেরি (grace-এর ভিতরে)</span>;
    if (row.status_key === 'late') return <span className="chip chip-red">দেরি</span>;
    if (row.status_key === 'leave') return <span className="chip chip-blue">ছুটি</span>;
    return <span className="chip chip-grey">আসেনি</span>;
}

export function TodayTable({ rows }) {
    return (
        <table className="x-table">
            <thead>
                <tr><th>নাম</th><th>Department</th><th>Check-in</th><th>Check-out</th><th>অবস্থা</th></tr>
            </thead>
            <tbody>
                {rows.map((r) => (
                    <tr key={r.user_id}>
                        <td>{r.name}</td>
                        <td>{r.department || '—'}</td>
                        <td>{r.check_in || '—'}</td>
                        <td>{r.check_out || '—'}</td>
                        <td><StatusChip row={r} /></td>
                    </tr>
                ))}
                {rows.length === 0 && (
                    <tr><td colSpan={5} style={{ color: '#94A3B8' }}>এখনো কোনো কর্মী যোগ হয়নি।</td></tr>
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
            <h4>ছুটির আবেদন — সিদ্ধান্ত বাকি</h4>
            {leaves.map((l) => (
                <div key={l.id} className="leave-row" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, padding: '9px 0', borderBottom: '1px solid #F1F5F9', flexWrap: 'wrap' }}>
                    <div style={{ fontSize: 13.5 }}>
                        <b>{l.name}</b> — {l.from} থেকে {l.to} ({l.days} দিন)
                        {l.reason && <span style={{ color: '#64748B' }}> · কারণ: {l.reason}</span>}
                    </div>
                    {canManage && (
                        <div className="row-actions">
                            <button type="button" className="btn-mini" onClick={() => decide(l.id, 'approved')}>অনুমোদন</button>
                            <button type="button" className="btn-mini danger" onClick={() => decide(l.id, 'rejected')}>বাতিল</button>
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}
