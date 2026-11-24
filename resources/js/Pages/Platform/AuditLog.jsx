import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const STATE = {
    closed: ['Closed', ''],
    open: ['Still open', 'amber'],
    expired: ['Auto-expired', ''],
    replaced: ['Replaced', ''],
};

export default function AuditLog({ rows, pagination, decisions = [] }) {
    const { page, last_page, total, from, to } = pagination;
    return (
        <AppLayout>
            <Head title={t('Audit Log')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <h3 style={{ fontSize: 19 }}>{t('Audit Log')}</h3>
                <span className="pill">{total} {t('views recorded')}</span>
            </div>
            <div style={{ fontSize: 13, color: 'var(--muted)', margin: '6px 0 12px' }}>{t('Read-only shop views by platform admins, with the time in and out.')}</div>

            <h4 style={{ fontSize: 15, margin: '4px 0 8px' }}>{t('Seller decisions')}</h4>
            <div style={{ fontSize: 13, color: 'var(--muted)', margin: '0 0 12px' }}>{t('Every approval and rejection of a shop, with who decided and the note they gave. A rejection stays here even if the shop is approved later.')}</div>
            <div className="card" style={{ padding: 0, overflow: 'hidden', marginBottom: 20 }}>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead><tr><th>{t('Admin')}</th><th>{t('Shop')}</th><th>{t('Decision')}</th><th>{t('Note')}</th><th>{t('When')}</th></tr></thead>
                        <tbody>
                            {decisions.map((d, i) => (
                                <tr key={i}>
                                    <td>
                                        <div style={{ fontWeight: 600 }}>{d.admin_name}</div>
                                        <div style={{ fontSize: 12, color: 'var(--muted)' }}>{d.admin_email}</div>
                                    </td>
                                    <td>{d.shop}</td>
                                    <td>{d.action === 'rejected' ? <span className="pill red">{t('Rejected')}</span> : <span className="pill green">{t('Approved')}</span>}</td>
                                    <td>{d.note || '-'}</td>
                                    <td style={{ whiteSpace: 'nowrap' }}>{d.when}</td>
                                </tr>
                            ))}
                            {decisions.length === 0 && <tr><td colSpan={5} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('No seller decisions recorded yet')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>

            <h4 style={{ fontSize: 15, margin: '4px 0 8px' }}>{t('Shop views')}</h4>

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead><tr><th>{t('Admin')}</th><th>{t('Shop')}</th><th>{t('Entered')}</th><th>{t('Exited')}</th><th>{t('Duration')}</th><th>{t('Status')}</th></tr></thead>
                        <tbody>
                            {rows.map((r, i) => (
                                <tr key={i}>
                                    <td>
                                        <div style={{ fontWeight: 600 }}>{r.admin_name}</div>
                                        <div style={{ fontSize: 12, color: 'var(--muted)' }}>{r.admin_email}</div>
                                    </td>
                                    <td>{r.shop}</td>
                                    <td>{r.entered_at || '-'}</td>
                                    <td>{r.exited_at || '-'}</td>
                                    <td>{r.duration || '-'}</td>
                                    <td>
                                        <span className={`pill ${(STATE[r.state] || ['', ''])[1]}`}>{t((STATE[r.state] || [r.state])[0])}</span>
                                        {r.blocked > 0 && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>{r.blocked} {t(r.blocked === 1 ? 'write blocked' : 'writes blocked')}</div>}
                                    </td>
                                </tr>
                            ))}
                            {rows.length === 0 && <tr><td colSpan={6} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('No shop views recorded yet')}</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>

            {last_page > 1 && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12 }}>
                    <span style={{ fontSize: 13, color: 'var(--muted)' }}>{t('Showing')} {from} - {to} {t('of')} {total}</span>
                    <span className="spacer" />
                    {page > 1 && <Link className="btn" href={`/admin/audit-log?page=${page - 1}`}>{t('Previous')}</Link>}
                    {page < last_page && <Link className="btn" href={`/admin/audit-log?page=${page + 1}`}>{t('Next')}</Link>}
                </div>
            )}
        </AppLayout>
    );
}
