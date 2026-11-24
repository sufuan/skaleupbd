import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

export default function Storefronts({ storefronts, reports, activeDomains, domainCap, domainWarnAt }) {
    const note = useForm({ note: '' });
    const resolve = useForm({ status: 'resolved', note: '' });
    const [takedown, setTakedown] = useState(null);
    const [reason, setReason] = useState('');
    const confirmTakedown = () => {
        if (!takedown) return;
        router.post(`/admin/storefronts/${takedown.id}/takedown`, { note: reason }, { preserveScroll: true, onSuccess: () => setTakedown(null) });
    };
    const capTone = activeDomains >= domainCap ? '#B3261E' : activeDomains >= domainWarnAt ? '#B45309' : 'inherit';

    return (
        <AppLayout>
            <Head title={t('Storefronts')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
                <h3 style={{ fontSize: 19, margin: 0, flex: 1 }}>{t('Storefronts')}</h3>
                <span style={{ fontSize: 14.5, color: capTone, fontWeight: activeDomains >= domainWarnAt ? 700 : 400 }}>
                    {t('Custom domains active:')} {activeDomains} / {domainCap}
                    {activeDomains >= domainCap ? ` - ${t('cap reached, new sellers join the waitlist')}` : activeDomains >= domainWarnAt ? ` - ${t('warning level')}` : ''}
                </span>
            </div>

            <div className="card" style={{ padding: 0, overflow: 'hidden', marginBottom: 14 }}>
                <div className="tbl-wrap"><table className="tbl">
                    <thead><tr><th>{t('Shop')}</th><th>{t('Address')}</th><th>{t('Custom domain')}</th><th>{t('Status')}</th><th>{t('Storefront orders')}</th><th>{t('Reports')}</th><th></th></tr></thead>
                    <tbody>
                        {storefronts.map((s) => (
                            <tr key={s.id}>
                                <td style={{ fontWeight: 600 }}>{s.shop}</td>
                                <td>{s.subdomainUrl ? <a href={s.subdomainUrl} target="_blank" rel="noreferrer">{s.subdomainUrl.replace(/^https?:\/\//, '')}</a> : '-'}</td>
                                <td>{s.customDomain ? <>{s.customDomain} <span className={`pill ${s.domainStatus === 'active' ? 'green' : 'amber'}`}>{s.domainStatus}</span></> : '-'}</td>
                                <td><span className={`pill ${s.status === 'published' ? 'green' : s.status === 'taken_down' ? 'red' : 'amber'}`}>{s.status}</span></td>
                                <td>{s.orders}</td>
                                <td>{s.openReports > 0 ? <span className="pill red">{s.openReports}</span> : 0}</td>
                                <td style={{ whiteSpace: 'nowrap' }}>
                                    {s.domainStatus === 'verified' && <button className="btn" onClick={() => router.post(`/admin/storefronts/${s.id}/domain-added`, {}, { preserveScroll: true })}>{t('Mark domain added')}</button>}{' '}
                                    {s.status !== 'taken_down'
                                        ? <button className="btn" onClick={() => { setReason(''); setTakedown(s); }}>{t('Take down')}</button>
                                        : <button className="btn pri" onClick={() => router.post(`/admin/storefronts/${s.id}/restore`, {}, { preserveScroll: true })}>{t('Restore')}</button>}
                                </td>
                            </tr>
                        ))}
                        {storefronts.length === 0 && <tr><td colSpan={7} style={{ textAlign: 'center', padding: 20, color: 'var(--muted)' }}>{t('No storefronts yet')}</td></tr>}
                    </tbody>
                </table></div>
            </div>

            <h4 style={{ fontSize: 16, margin: '18px 0 10px' }}>{t('Page reports')}</h4>
            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="tbl-wrap"><table className="tbl">
                    <thead><tr><th>{t('When')}</th><th>{t('Shop')}</th><th>{t('Reason')}</th><th>{t('Contact')}</th><th>{t('Status')}</th><th></th></tr></thead>
                    <tbody>
                        {reports.map((r) => (
                            <tr key={r.id}>
                                <td style={{ whiteSpace: 'nowrap' }}>{r.when}</td>
                                <td>{r.shop}</td>
                                <td style={{ maxWidth: 380 }}>{r.reason}</td>
                                <td>{r.contact || '-'}</td>
                                <td><span className={`pill ${r.status === 'open' ? 'red' : ''}`}>{r.status}</span></td>
                                <td style={{ whiteSpace: 'nowrap' }}>
                                    {r.status === 'open' && <>
                                        <button className="btn" onClick={() => router.post(`/admin/storefronts/reports/${r.id}/resolve`, { status: 'resolved' }, { preserveScroll: true })}>{t('Resolve')}</button>{' '}
                                        <button className="btn" onClick={() => router.post(`/admin/storefronts/reports/${r.id}/resolve`, { status: 'dismissed' }, { preserveScroll: true })}>{t('Dismiss')}</button>
                                    </>}
                                </td>
                            </tr>
                        ))}
                        {reports.length === 0 && <tr><td colSpan={6} style={{ textAlign: 'center', padding: 20, color: 'var(--muted)' }}>{t('No reports')}</td></tr>}
                    </tbody>
                </table></div>
            </div>

            {takedown && (
                <div className="overlay" onClick={() => setTakedown(null)}>
                    <div className="modal" style={{ maxWidth: 420 }} onClick={(e) => e.stopPropagation()}>
                        <p style={{ fontSize: 15, lineHeight: 1.55, margin: '4px 0 6px', fontWeight: 600 }}>{t('Take down')} - {takedown.shop}</p>
                        <p className="sm mut" style={{ margin: '0 0 14px' }}>{t('This storefront will stop showing to customers until you restore it.')}</p>
                        <label className="fl" style={{ display: 'block', marginBottom: 6 }}>{t('Reason for the takedown (optional)')}</label>
                        <input className="inp" style={{ width: '100%' }} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
                        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 18 }}>
                            <button type="button" className="btn" onClick={() => setTakedown(null)}>{t('Cancel')}</button>
                            <button type="button" className="btn pri" style={{ background: '#B91C1C' }} onClick={confirmTakedown}>{t('Take down')}</button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
