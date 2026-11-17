import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { fmt, StatusPill } from '@/Pages/Orders/ui';
import { t } from '@/lib/i18n';

const STATUS_TABS = ['All Parcels', 'In Transit', 'Assigned for Delivery', 'Cancelled', 'Pending Entry', 'Return Pending', 'Returned', 'Damage & Lost'];
const CALL_SLOTS = ['10AM', '1PM', '4PM', '7PM'];
const CALL_STATUSES = ['Not called', 'CONNECTED', 'NO ANSWER', 'BUSY', 'SWITCHED OFF', 'WRONG NUMBER'];
const RETURN_TAGS = ['Returned with Delivery Charge', 'Customer Fraud', 'Courier Fraud', 'Parcel Check & Return Without Delivery Charge'];
const CLAIM_STATUSES = ['Pending', 'Paid', 'Rejected'];

export default function Parcels({ parcels, counts, status, analysis, entries }) {
    const [tab, setTab] = useState('parcels');
    const patch = (id, field, value) => router.patch(`/delivery/parcels/${id}`, { [field]: value }, { preserveScroll: true });

    return (
        <AppLayout title={t('Courier Management')}>
            <div className="page-head">
                <div>
                    <h1>{t('Courier Management')}</h1>
                    <div className="mut sm">{t('Every parcel with a courier — tracking, calls, returns and claims')}</div>
                </div>
                <div className="flex" style={{ gap: 8 }}>
                    <button className={`btn ${tab === 'parcels' ? 'pri' : ''}`} onClick={() => setTab('parcels')}>{t('All Parcels')}</button>
                    <button className={`btn ${tab === 'returns' ? 'pri' : ''}`} onClick={() => setTab('returns')}>{t('Return Management')}</button>
                    <button className={`btn ${tab === 'analysis' ? 'pri' : ''}`} onClick={() => setTab('analysis')}>{t('Return Analysis')}</button>
                    <button className={`btn ${tab === 'entry' ? 'pri' : ''}`} onClick={() => setTab('entry')}>{t('Courier Entry')}</button>
                </div>
            </div>

            {tab === 'parcels' && (
                <>
                    <div className="flex" style={{ gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
                        {STATUS_TABS.map((s) => (
                            <button key={s} className={`btn ${status === s || (s === 'All Parcels' && !status) ? 'pri' : ''}`}
                                onClick={() => router.get('/delivery/parcels', s === 'All Parcels' ? {} : { status: s }, { preserveState: true })}>
                                {t(s)} ({counts[s] ?? 0})
                            </button>
                        ))}
                    </div>
                    <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                        {parcels.length === 0 ? (
                            <div style={{ padding: 34, textAlign: 'center' }} className="mut">{t('No parcels here yet')}</div>
                        ) : (
                            <div className="tbl-wrap"><table className="tbl">
                                <thead><tr>
                                    <th>{t('Invoice')}</th><th>{t('Customer')}</th><th>{t('Courier')}</th><th>{t('Tracking')}</th>
                                    <th>{t('Status')}</th><th>{t('COD')}</th><th>{t('Call')}</th><th>{t('In Charge')}</th><th style={{ textAlign: 'right' }}>{t('Actions')}</th>
                                </tr></thead>
                                <tbody>
                                    {parcels.map((p) => (
                                        <tr key={p.id}>
                                            <td><Link href={`/orders/${p.id}`} className="b" style={{ color: 'var(--acc)' }}>{p.invoice}</Link>
                                                <div className="sm mut">{p.day_tag} · {p.shipped_at}</div></td>
                                            <td><div className="b">{p.customer_name}</div><div className="sm mut">{p.customer_phone}</div></td>
                                            <td>{p.courier || '—'}</td>
                                            <td className="sm">{p.tracking_id || p.consignment_id || '—'}</td>
                                            <td><StatusPill status={p.status} />{p.courier_status && <div className="sm mut">{p.courier_status}</div>}</td>
                                            <td className="b">{fmt(p.cod)}</td>
                                            <td>
                                                <div className="flex" style={{ gap: 6 }}>
                                                    <select className="inp" style={{ width: 86 }} value={p.call_slot || ''} onChange={(e) => patch(p.id, 'call_slot', e.target.value)}>
                                                        <option value="">—</option>{CALL_SLOTS.map((s) => <option key={s} value={s}>{s}</option>)}
                                                    </select>
                                                    <select className="inp" style={{ width: 132 }} value={p.call_status || 'Not called'} onChange={(e) => patch(p.id, 'call_status', e.target.value)}>
                                                        {CALL_STATUSES.map((s) => <option key={s} value={s}>{t(s)}</option>)}
                                                    </select>
                                                </div>
                                            </td>
                                            <td><input className="inp" style={{ width: 110 }} defaultValue={p.in_charge || ''} placeholder={t('Who is in charge')}
                                                onBlur={(e) => e.target.value !== (p.in_charge || '') && patch(p.id, 'in_charge', e.target.value)} /></td>
                                            <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                                                <button className="btn" onClick={() => router.post(`/delivery/${p.id}/force-check`)}>{t('Check Status')}</button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table></div>
                        )}
                    </div>
                </>
            )}

            {tab === 'returns' && (
                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('Invoice')}</th><th>{t('Courier')}</th><th>{t('Status')}</th><th>{t('Return Tag')}</th><th>{t('Claim')}</th><th>{t('Compensation')}</th></tr></thead>
                        <tbody>
                            {parcels.filter((p) => p.status === 'Returned' || p.return_tag || p.claim_status).map((p) => (
                                <tr key={p.id}>
                                    <td><Link href={`/orders/${p.id}`} className="b" style={{ color: 'var(--acc)' }}>{p.invoice}</Link></td>
                                    <td>{p.courier || '—'}</td>
                                    <td><StatusPill status={p.status} /></td>
                                    <td><select className="inp" style={{ width: 250 }} value={p.return_tag || ''} onChange={(e) => patch(p.id, 'return_tag', e.target.value)}>
                                        <option value="">—</option>{RETURN_TAGS.map((x) => <option key={x} value={x}>{t(x)}</option>)}
                                    </select></td>
                                    <td><select className="inp" style={{ width: 120 }} value={p.claim_status || ''} onChange={(e) => patch(p.id, 'claim_status', e.target.value)}>
                                        <option value="">—</option>{CLAIM_STATUSES.map((x) => <option key={x} value={x}>{t(x)}</option>)}
                                    </select></td>
                                    <td><input className="inp" style={{ width: 110 }} type="number" defaultValue={p.claim_amount ?? ''} placeholder="৳"
                                        onBlur={(e) => patch(p.id, 'claim_amount', e.target.value === '' ? null : Number(e.target.value))} /></td>
                                </tr>
                            ))}
                            {parcels.filter((p) => p.status === 'Returned' || p.return_tag || p.claim_status).length === 0 && (
                                <tr><td colSpan={6} style={{ textAlign: 'center', padding: 26 }} className="mut">{t('No returns yet')}</td></tr>
                            )}
                        </tbody>
                    </table></div>
                </div>
            )}

            {tab === 'analysis' && (
                <div className="card">
                    <h3>{t('Return Analysis')} — {t('last 30 days')}</h3>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('Courier')}</th><th>{t('Delivered')}</th><th>{t('Returned')}</th><th>{t('Return Rate')}</th></tr></thead>
                        <tbody>
                            {analysis.map((a) => (
                                <tr key={a.courier}><td className="b">{a.courier}</td><td>{a.delivered}</td><td>{a.returned}</td><td>{a.return_rate}%</td></tr>
                            ))}
                            {analysis.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 22 }} className="mut">{t('No data yet')}</td></tr>}
                        </tbody>
                    </table></div>
                </div>
            )}

            {tab === 'entry' && (
                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <div style={{ padding: '14px 18px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <div className="b">{t('Courier Entry')} — {t('every booking attempt, kept as the audit record')}</div>
                        <a className="btn" href="/delivery/entries/export">{t('Export CSV')}</a>
                    </div>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('Invoice')}</th><th>{t('Courier')}</th><th>{t('Mode')}</th><th>{t('Booking Status')}</th><th>{t('Tracking')}</th><th>{t('When')}</th></tr></thead>
                        <tbody>
                            {entries.map((e) => (
                                <tr key={e.id}><td className="b">{e.invoice || '—'}</td><td>{e.courier || '—'}</td><td>{t(e.mode)}</td>
                                    <td>{t(e.booking_status)}{e.error && <div className="sm" style={{ color: 'var(--bad)' }}>{e.error}</div>}</td>
                                    <td className="sm">{e.tracking_id || '—'}</td><td className="sm">{e.at}</td></tr>
                            ))}
                            {entries.length === 0 && <tr><td colSpan={6} style={{ textAlign: 'center', padding: 26 }} className="mut">{t('No bookings yet')}</td></tr>}
                        </tbody>
                    </table></div>
                </div>
            )}

            <p className="sm mut" style={{ marginTop: 14 }}>{t('Courier permissions are managed in Settings → Security & Records and HRM → User & Role — they live in one place only.')}</p>
        </AppLayout>
    );
}
