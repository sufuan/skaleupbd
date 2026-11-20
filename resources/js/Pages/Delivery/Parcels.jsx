import { useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { StatusPill } from '@/Pages/Orders/ui';
import { t } from '@/lib/i18n';

const TABS = [['parcels', 'All Parcels'], ['returns', 'Return Management'], ['analysis', 'Return Analysis'], ['entry', 'Courier Entry'], ['settings', 'Courier Settings']];
const STATUS_PILLS = ['In Transit', 'Assigned for Delivery', 'Cancelled', 'Pending Entry', 'Return Pending', 'Returned', 'Damage & Lost'];
const CALL_SLOTS = ['10AM', '1PM', '4PM', '7PM'];
const CALL_STATUSES = ['Not called', 'CONNECTED', 'NO ANSWER', 'BUSY', 'SWITCHED OFF', 'WRONG NUMBER'];
const RETURN_TAGS = ['Returned with Delivery Charge', 'Customer Fraud', 'Courier Fraud', 'Parcel Check & Return Without Delivery Charge'];
const CLAIM_STATUSES = ['Pending', 'Paid', 'Rejected'];

const I = {
    phone: (<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#64748B" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" /></svg>),
    tag: (<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8 8a2 2 0 0 0 2.828 0l7.172-7.172a2 2 0 0 0-2.828-2.828z" /><circle cx="7.5" cy="7.5" r="1" fill="currentColor" /></svg>),
    history: (<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" /><path d="M3 3v5h5" /><polyline points="12 7 12 12 15 14" /></svg>),
    person: (<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>),
};

export default function Parcels({ parcels, counts, status, analysis, entries, filters, couriers, incharges }) {
    const [tab, setTab] = useState('parcels');
    const [picked, setPicked] = useState([]);
    const [editor, setEditor] = useState(null); // {parcel, mode: 'call'|'charge'}
    const [f, setF] = useState({
        search: filters?.search || '', courier: filters?.courier || '',
        age_from: filters?.age_from ?? '', age_to: filters?.age_to ?? '',
        tag: filters?.tag || '', in_charge: filters?.in_charge || '',
    });
    const tagInputRef = useRef(null);
    const patch = (id, field, value) => router.patch(`/delivery/parcels/${id}`, { [field]: value }, { preserveScroll: true });

    const go = (over) => router.get('/delivery/parcels', { status, ...f, ...over }, { preserveState: true, preserveScroll: true });
    const pickStatus = (s) => router.get('/delivery/parcels', { ...f, status: s }, { preserveState: true, preserveScroll: true });
    const applyFilters = (e) => { e?.preventDefault(); go({}); };

    const callCell = (p) => {
        const recorded = p.call_status && p.call_status !== 'Not called';
        const am = recorded && p.call_slot === '10AM' ? p.call_status : null;
        const pm = recorded && p.call_slot && p.call_slot !== '10AM' ? p.call_status : null;
        const dots = <span style={{ color: '#CBD5E1' }}>••</span>;
        return <span className="muted-sm">AM {am || dots}<br />PM {pm || dots}</span>;
    };

    return (
        <AppLayout title={t('Courier Management')}>
            <div className="cm-pg" style={{ maxWidth: 1520, margin: '0 auto', paddingTop: 30 }}>
                <div className="ptabs">
                    {TABS.map(([key, label]) => (
                        <span key={key} className={`ptab ${tab === key ? 'act' : ''}`} onClick={() => {
                            setTab(key);
                            if ((key === 'parcels' || key === 'returns') && status !== 'all') router.get('/delivery/parcels', { status: 'all' }, { preserveState: true });
                        }}>{t(label)}</span>
                    ))}
                </div>

                <div className="page-head" style={{ flexWrap: 'nowrap' }}>
                    <div>
                        <h1 style={{ fontSize: 24, fontWeight: 700 }}>{t('Courier Management')}</h1>
                        <p>{t('Track and manage parcels in transit, returns, and cancellations')}</p>
                    </div>
                    <span style={{ marginLeft: 'auto', flexShrink: 0 }}>
                        <button className="ctl-btn" onClick={() => { setTab('parcels'); setTimeout(() => tagInputRef.current?.focus(), 80); }}>{t('Manage Tags')}</button>
                    </span>
                </div>

                {tab === 'parcels' && (
                    <>
                        <div className="card" style={{ padding: 24 }}>
                            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                                <span style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                    {STATUS_PILLS.map((s) => status === s
                                        ? <button key={s} className="btn-primary" style={{ borderRadius: 99, padding: '7px 14px', fontSize: 13.5 }} onClick={() => pickStatus(s)}>{t(s)}</button>
                                        : <button key={s} className="ctl-btn" style={{ borderRadius: 99, padding: '7px 14px', fontSize: 13.5, fontWeight: 500 }} onClick={() => pickStatus(s)}>{t(s)}</button>)}
                                </span>
                            </div>
                        </div>

                        <form className="card" style={{ marginTop: 16, padding: 24 }} onSubmit={applyFilters}>
                            <div className="fld-grid2" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 12 }}>
                                <div className="fld-l" style={{ fontSize: 11, letterSpacing: '.04em' }}>{t('COURIER AGE')}
                                    <span style={{ display: 'flex', gap: 8 }}>
                                        <input className="fld" style={{ width: 100, flex: 'none' }} placeholder={t('From')} type="number" min="0" value={f.age_from} onChange={(e) => setF({ ...f, age_from: e.target.value })} />
                                        <input className="fld" style={{ width: 100, flex: 'none' }} placeholder={t('To')} type="number" min="0" value={f.age_to} onChange={(e) => setF({ ...f, age_to: e.target.value })} />
                                    </span>
                                    <span className="muted-sm" style={{ fontWeight: 400, letterSpacing: 0 }}>{t('Days since sent to courier. Blank To means plus.')}</span>
                                </div>
                                <div className="fld-l" style={{ fontSize: 11, letterSpacing: '.04em' }}>{t('RIDER ATTEMPT')}
                                    <span style={{ display: 'flex', gap: 8 }}>
                                        <input className="fld" style={{ width: 100, flex: 'none' }} placeholder={t('From')} type="number" min="0" disabled />
                                        <input className="fld" style={{ width: 100, flex: 'none' }} placeholder={t('To')} type="number" min="0" disabled />
                                    </span>
                                    <span className="muted-sm" style={{ fontWeight: 400, letterSpacing: 0 }}>{t('Latest rider attempt number. Blank To means plus.')}</span>
                                </div>
                            </div>
                            <div className="parcels-filter-row" style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                                <label className="fld-l" style={{ fontSize: 11, letterSpacing: '.04em', flex: '0 0 320px' }}>{t('SEARCH')}
                                    <input className="fld" placeholder={t('Search invoice, name, phone...')} value={f.search} onChange={(e) => setF({ ...f, search: e.target.value })} />
                                </label>
                                <label className="fld-l" style={{ fontSize: 11, letterSpacing: '.04em', flex: '0 0 175px' }}>{t('COURIER')}
                                    <select className="fld" value={f.courier} onChange={(e) => { const v = e.target.value; setF({ ...f, courier: v }); go({ courier: v }); }}>
                                        <option value="">{t('All Couriers')}</option>
                                        {(couriers || []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                                    </select>
                                </label>
                                <label className="fld-l" style={{ fontSize: 11, letterSpacing: '.04em', flex: '0 0 445px' }}>{t('CUSTOM TAGS')}
                                    <input ref={tagInputRef} className="fld" placeholder={t('Filter custom tags')} value={f.tag} onChange={(e) => setF({ ...f, tag: e.target.value })} />
                                </label>
                                <label className="fld-l" style={{ fontSize: 11, letterSpacing: '.04em', flex: '0 0 200px' }}>{t('IN CHARGE OF')}
                                    <select className="fld" value={f.in_charge} onChange={(e) => { const v = e.target.value; setF({ ...f, in_charge: v }); go({ in_charge: v }); }}>
                                        <option value="">{t('Everyone')}</option>
                                        {(incharges || []).map((n) => <option key={n} value={n}>{n}</option>)}
                                    </select>
                                </label>
                                <button type="submit" className="btn-primary" style={{ alignSelf: 'flex-end' }}>{t('Search')}</button>
                            </div>
                        </form>

                        <p style={{ margin: '14px 2px', fontWeight: 600 }}>
                            {t('Total')} {t(status === 'all' ? 'All Parcels' : status)} {t('Parcels')}: {status === 'all' ? counts['All Parcels'] ?? parcels.length : counts[status] ?? parcels.length}
                            <br /><span className="muted-sm" style={{ fontWeight: 400 }}>{t('Showing')} {parcels.length} {t('parcels')}</span>
                        </p>

                        <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                            {parcels.length === 0 ? (
                                <div style={{ padding: 34, textAlign: 'center' }} className="mut">{t('No parcels here yet')}</div>
                            ) : (
                                <div style={{ overflowX: 'auto' }}><table className="cm-tbl" style={{ minWidth: 1500 }}>
                                    <thead><tr>
                                        <th><input type="checkbox" checked={parcels.length > 0 && picked.length === parcels.length}
                                            onChange={(e) => setPicked(e.target.checked ? parcels.map((p) => p.id) : [])} /></th>
                                        <th>{t('Invoice')}</th><th>{t('Products')}</th><th>{t('Customer')}</th><th>{t('Customer calls')}</th>
                                        <th>{t('Amount')}</th><th>{t('Courier tracking')}</th><th>{t('Counts')}</th><th>{t('Rider')}</th>
                                        <th>{t('Rider calls')}</th><th>{t('Tags')}</th><th>{t('In charge')}</th><th>{t('Actions')}</th>
                                    </tr></thead>
                                    <tbody>
                                        {parcels.map((p) => (
                                            <tr key={p.id}>
                                                <td><input type="checkbox" checked={picked.includes(p.id)} onChange={() => setPicked((x) => x.includes(p.id) ? x.filter((i) => i !== p.id) : [...x, p.id])} /></td>
                                                <td><Link href={`/orders/${p.id}`} style={{ color: '#2563EB', textDecoration: 'underline' }}>{p.invoice}</Link></td>
                                                <td><span style={{ display: 'inline-flex', alignItems: 'center' }}>
                                                    <span style={{ display: 'inline-block', width: 32, height: 32, borderRadius: 6, background: '#F4EDF7', verticalAlign: 'middle', marginRight: 8, flexShrink: 0 }} />
                                                    <span>{p.product_name || '—'}<br /><span className="muted-sm">{p.product_sku || ''}</span></span>
                                                </span></td>
                                                <td>{p.customer_name} · {p.customer_phone}<br />
                                                    <span className="muted-sm">{p.address || p.district || ''}</span>{' '}
                                                    <span style={{ whiteSpace: 'nowrap' }}>{I.phone}</span></td>
                                                <td>{callCell(p)}</td>
                                                <td><b>{Number(p.cod).toLocaleString('en-IN')}</b></td>
                                                <td>{p.courier || '—'}<br /><span className="muted-sm">{p.tracking_id || p.consignment_id || ''}</span></td>
                                                <td><span className="pill green">{t('Courier age')} {p.age_days}d</span>
                                                    {p.courier_status && <div className="muted-sm" style={{ marginTop: 4 }}>{p.courier_status}</div>}</td>
                                                <td>—</td>
                                                <td><span className="muted-sm">AM <span style={{ color: '#CBD5E1' }}>••</span><br />PM <span style={{ color: '#CBD5E1' }}>••</span></span></td>
                                                <td>{(p.tags || []).length > 0
                                                    ? <span style={{ display: 'inline-flex', gap: 4, flexWrap: 'wrap' }}>{p.tags.map((tg) => <span key={tg} style={{ background: '#FCE7F3', color: '#BE185D', borderRadius: 999, padding: '3px 9px', fontSize: 12, fontWeight: 600 }}>{tg}</span>)}</span>
                                                    : '—'}</td>
                                                <td>{p.in_charge || '—'}</td>
                                                <td><span style={{ display: 'flex', gap: 4 }}>
                                                    <button className="mini-btn" title={t('Record call')} onClick={() => setEditor({ parcel: p, mode: 'call' })}>{I.phone}</button>
                                                    <Link className="mini-btn" title={t('Manage tags')} href={`/orders/${p.id}`}>{I.tag}</Link>
                                                    <button className="mini-btn" title={t('Check courier status')} onClick={() => router.post(`/delivery/${p.id}/force-check`, {}, { preserveScroll: true })}>{I.history}</button>
                                                    <button className="mini-btn" title={t('Set who is in charge')} onClick={() => setEditor({ parcel: p, mode: 'charge' })}>{I.person}</button>
                                                </span></td>
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
                                        <td>{p.courier_label || p.courier || '—'}</td>
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

                {tab === 'settings' && (
                    <div className="card">
                        <h3>{t('Courier Settings')}</h3>
                        <p className="mut" style={{ marginTop: 8 }}>{t('Courier permissions are managed in Settings → Security & Records and HRM → User & Role — they live in one place only.')}</p>
                    </div>
                )}

                {editor && (
                    <div className="overlay" onClick={() => setEditor(null)}>
                        <div className="modal" onClick={(e) => e.stopPropagation()}>
                            <h3 style={{ marginTop: 0 }}>{editor.mode === 'call' ? t('Record call') : t('Set who is in charge')} — {editor.parcel.invoice}</h3>
                            {editor.mode === 'call' ? (
                                <>
                                    <div className="frow mt16"><label className="fl">{t('Call')}</label>
                                        <div className="flex" style={{ gap: 6 }}>
                                            <select id="cmCallSlot" className="inp" style={{ width: 100 }} defaultValue={editor.parcel.call_slot || ''}>
                                                <option value="">—</option>{CALL_SLOTS.map((s) => <option key={s} value={s}>{s}</option>)}
                                            </select>
                                            <select id="cmCallStatus" className="inp" style={{ width: 150 }} defaultValue={editor.parcel.call_status || 'Not called'}>
                                                {CALL_STATUSES.map((s) => <option key={s} value={s}>{t(s)}</option>)}
                                            </select>
                                        </div>
                                    </div>
                                    <div className="frow" style={{ gap: 8, marginTop: 18, justifyContent: 'flex-end' }}>
                                        <button className="btn" onClick={() => setEditor(null)}>{t('Cancel')}</button>
                                        <button className="btn pri" onClick={() => {
                                            router.patch(`/delivery/parcels/${editor.parcel.id}`, {
                                                call_slot: document.getElementById('cmCallSlot').value,
                                                call_status: document.getElementById('cmCallStatus').value,
                                            }, { preserveScroll: true });
                                            setEditor(null);
                                        }}>{t('Save')}</button>
                                    </div>
                                </>
                            ) : (
                                <>
                                    <div className="frow mt16"><label className="fl">{t('In charge')}</label>
                                        <input id="cmCharge" className="inp" defaultValue={editor.parcel.in_charge || ''} placeholder={t('Who is in charge')} />
                                    </div>
                                    <div className="frow" style={{ gap: 8, marginTop: 18, justifyContent: 'flex-end' }}>
                                        <button className="btn" onClick={() => setEditor(null)}>{t('Cancel')}</button>
                                        <button className="btn pri" onClick={() => {
                                            patch(editor.parcel.id, 'in_charge', document.getElementById('cmCharge').value);
                                            setEditor(null);
                                        }}>{t('Save')}</button>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
