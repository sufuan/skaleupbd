import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { fmt } from '@/Pages/Orders/ui';
import { t } from '@/lib/i18n';

const SEARCH_ICON = (<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="7" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>);

export default function InvoiceUpload({ methods, history, payments, receivables }) {
    const [section, setSection] = useState('Manual Entry');
    const [guideOpen, setGuideOpen] = useState(false);
    const [preview, setPreview] = useState(null);
    const [previewError, setPreviewError] = useState('');
    const [busy, setBusy] = useState(false);
    const fileRef = useRef(null);
    // Search & Add (manual settlement lines)
    const [invoiceNo, setInvoiceNo] = useState('');
    const [addError, setAddError] = useState('');
    const [manualLines, setManualLines] = useState([]);
    const [codChargePct, setCodChargePct] = useState(0);
    const [depositAccount, setDepositAccount] = useState('');
    // One-line payment entry
    const [pay, setPay] = useState({ method_id: '', amount: '', date: '', reference: '', deposit_account: '' });
    const [proposal, setProposal] = useState(null);

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    const doPreview = async () => {
        const file = fileRef.current?.files?.[0];
        if (!file) return;
        setBusy(true); setPreviewError(''); setPreview(null);
        const fd = new FormData();
        fd.append('file', file);
        try {
            const res = await fetch('/delivery/invoice/preview', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
            const data = await res.json();
            if (!data.ok) { setPreviewError(data.error || 'Could not read the file'); return; }
            setPreview(data);
        } catch { setPreviewError('Upload failed — try again'); }
        setBusy(false);
    };

    const confirmUpload = () => {
        router.post('/delivery/invoice/confirm', { lines: preview?.lines || [] }, { onSuccess: () => setPreview(null) });
    };

    const searchAdd = () => {
        const inv = invoiceNo.trim();
        if (!inv) return;
        setAddError('');
        if (manualLines.some((l) => l.invoice === inv)) { setAddError('This invoice is already added'); return; }
        const found = receivables.find((r) => r.invoice === inv && r.unsettled);
        if (!found) { setAddError('No unpaid delivered parcel with this invoice'); return; }
        const pct = Math.min(100, Math.max(0, Number(codChargePct) || 0));
        const amount = Math.round(found.expected * (1 - pct / 100) * 100) / 100;
        setManualLines((x) => [...x, { invoice: inv, paid_amount: amount, expected: found.expected, courier: found.courier }]);
        setInvoiceNo('');
    };

    const saveManual = () => {
        router.post('/delivery/invoice/confirm', {
            lines: manualLines.map((l) => ({ invoice: l.invoice, paid_amount: l.paid_amount })),
            source: 'manual', deposit_account: depositAccount || null,
        }, { onSuccess: () => setManualLines([]) });
    };

    const propose = async () => {
        if (!pay.method_id || !pay.amount) return;
        const res = await fetch('/delivery/payments/propose', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ method_id: Number(pay.method_id), amount: Number(pay.amount) }),
        });
        setProposal(await res.json());
    };

    const savePayment = () => {
        router.post('/delivery/payments', {
            method_id: Number(pay.method_id), amount: Number(pay.amount), date: pay.date,
            reference: pay.reference || null, deposit_account: pay.deposit_account || null,
            allocations: proposal.allocations,
        }, { onSuccess: () => { setProposal(null); setPay({ method_id: '', amount: '', date: '', reference: '', deposit_account: '' }); } });
    };

    return (
        <AppLayout title={t('Update Courier Payment')}>
            <div className="inv-pg" style={{ maxWidth: 1520, margin: '0 auto', paddingTop: 30 }}>
                <div className="page-head">
                    <div><h1 style={{ fontSize: 24, fontWeight: 700 }}>{t('Update Courier Payment')}</h1></div>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16, flexWrap: 'wrap' }}>
                    <span style={{ display: 'inline-flex', flexDirection: 'column', gap: 6 }}>
                        <span style={{ fontSize: 13, fontWeight: 600, color: '#64748B' }}>{t('Select Section')}</span>
                        <select id="invSection" className="fld" style={{ width: '100%', maxWidth: 445 }} value={section} onChange={(e) => setSection(e.target.value)}>
                            <option>Manual Entry</option>
                            <option>Invoice History</option>
                        </select>
                    </span>
                    <button className="ctl-btn" style={{ alignSelf: 'flex-end' }} onClick={() => setSection('Invoice History')}>{t('Invoice History')}</button>
                    <button className="ctl-btn" style={{ marginLeft: 'auto', alignSelf: 'flex-end' }} onClick={() => setGuideOpen(true)}>{t('? Invalid ফিক্স গাইড')}</button>
                </div>

                {section === 'Manual Entry' && (
                    <>
                        <div className="card" style={{ marginBottom: 16, padding: 24 }}>
                            <h3 className="big">{t('Search & Add Orders')}</h3>
                            <label className="fld-l">{t('Invoice Number')}
                                <span style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 6 }}>
                                    <input className="fld" style={{ flex: 1 }} placeholder={t('Enter invoice number')} value={invoiceNo}
                                        onChange={(e) => setInvoiceNo(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') searchAdd(); }} />
                                    <button className="btn-primary" style={{ whiteSpace: 'nowrap', height: 42 }} onClick={searchAdd}>{SEARCH_ICON} {t('Search & Add')}</button>
                                </span>
                            </label>
                            {addError && <p className="sm" style={{ color: 'var(--bad)', marginTop: 8 }}>{t(addError)}</p>}
                            {manualLines.length > 0 && (
                                <div className="tbl-wrap" style={{ marginTop: 14 }}><table className="tbl">
                                    <thead><tr><th>{t('Invoice')}</th><th>{t('Courier')}</th><th>{t('Expected')}</th><th>{t('Paid Amount')}</th><th /></tr></thead>
                                    <tbody>{manualLines.map((l, i) => (
                                        <tr key={i}><td className="b">{l.invoice}</td><td>{l.courier}</td><td>{fmt(l.expected)}</td>
                                            <td><input className="inp" style={{ width: 130 }} type="number" value={l.paid_amount}
                                                onChange={(e) => setManualLines((x) => x.map((y, j) => j === i ? { ...y, paid_amount: Number(e.target.value) } : y))} /></td>
                                            <td><button className="btn" onClick={() => setManualLines((x) => x.filter((_, j) => j !== i))}>{t('Remove')}</button></td></tr>
                                    ))}</tbody>
                                </table></div>
                            )}
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginTop: 16 }}>
                                <label className="fld-l">{t('COD Charge (%)')}
                                    <input className="fld" type="number" min="0" max="100" value={codChargePct} onChange={(e) => setCodChargePct(e.target.value)} style={{ marginTop: 6 }} />
                                </label>
                                <label className="fld-l">{t('Deposit Account (Optional)')}
                                    <select className="fld" value={depositAccount} onChange={(e) => setDepositAccount(e.target.value)} style={{ marginTop: 6 }}>
                                        <option value="">{t('No account selected')}</option>
                                        <option>Main Cash</option>
                                        <option>bKash Merchant</option>
                                    </select>
                                </label>
                            </div>
                            <p className="muted-sm" style={{ marginTop: 10 }}>{t("If an account is selected, this save's settlement is deposited and its COD charge is withdrawn as an expense.")}</p>
                            {manualLines.length > 0 && (
                                <div style={{ marginTop: 14, textAlign: 'right' }}>
                                    <button className="btn-primary" onClick={saveManual}>{t('Confirm & Save')}</button>
                                </div>
                            )}
                        </div>

                        <div className="card" style={{ marginBottom: 16, padding: 24 }}>
                            <h3 className="big">{t('Invoice Upload')}</h3>
                            <div className="card-sub">{t('Upload the courier statement as CSV or XLSX (PDF is not accepted). Lines are matched by invoice number; a parcel that is already settled is blocked as a duplicate.')}</div>
                            <div className="flex" style={{ gap: 8, flexWrap: 'wrap' }}>
                                <input ref={fileRef} type="file" accept=".csv,.xlsx,.xls" className="inp" style={{ maxWidth: 340 }} />
                                <button className="btn pri" disabled={busy} onClick={doPreview}>{busy ? t('Reading…') : t('Preview')}</button>
                            </div>
                            {previewError && <p className="sm" style={{ color: 'var(--bad)', marginTop: 10 }}>{t(previewError)}</p>}
                            {preview && (
                                <div style={{ marginTop: 16 }}>
                                    <div className="flex" style={{ gap: 18, flexWrap: 'wrap' }}>
                                        <span className="pill green">{t('Matched')} {preview.matched.length}</span>
                                        <span className="pill amber">{t('Unmatched')} {preview.unmatched.length}</span>
                                        <span className="pill red">{t('Duplicate')} {preview.duplicates.length}</span>
                                    </div>
                                    {preview.matched.length > 0 && (
                                        <div className="tbl-wrap" style={{ marginTop: 12 }}><table className="tbl">
                                            <thead><tr><th>{t('Invoice')}</th><th>{t('Paid Amount')}</th><th>{t('Expected')}</th></tr></thead>
                                            <tbody>{preview.matched.map((m, i) => <tr key={i}><td className="b">{m.invoice}</td><td>{fmt(m.paid_amount)}</td><td>{fmt(m.expected)}</td></tr>)}</tbody>
                                        </table></div>
                                    )}
                                    {preview.unmatched.map((m, i) => <p key={'u' + i} className="sm" style={{ color: 'var(--bad)' }}>{m.invoice} — {t(m.reason)}</p>)}
                                    {preview.duplicates.map((m, i) => <p key={'d' + i} className="sm" style={{ color: 'var(--bad)' }}>{m.invoice} — {t(m.reason)}</p>)}
                                    <div style={{ marginTop: 16 }}>
                                        <button className="btn pri" disabled={preview.matched.length === 0} onClick={confirmUpload}>{t('Confirm & Save')}</button>
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="card" style={{ marginBottom: 16, padding: 24 }}>
                            <h3 className="big">{t('One-line Payment Entry')}</h3>
                            <div className="card-sub">{t('For couriers without a settlement API: write the payment once (total + date + reference) and confirm which parcels it covers — oldest first.')}</div>
                            <div className="flex" style={{ gap: 8, flexWrap: 'wrap' }}>
                                <select className="inp" style={{ maxWidth: 200 }} value={pay.method_id} onChange={(e) => setPay({ ...pay, method_id: e.target.value })}>
                                    <option value="">{t('Pick the courier')}</option>
                                    {methods.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
                                </select>
                                <input className="inp" style={{ maxWidth: 150 }} type="number" placeholder={t('Amount')} value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} />
                                <input className="inp" style={{ maxWidth: 170 }} type="date" value={pay.date} onChange={(e) => setPay({ ...pay, date: e.target.value })} />
                                <input className="inp" style={{ maxWidth: 180 }} placeholder={t('Reference')} value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} />
                                <input className="inp" style={{ maxWidth: 180 }} placeholder={t('Deposit Account')} value={pay.deposit_account} onChange={(e) => setPay({ ...pay, deposit_account: e.target.value })} />
                                <button className="btn" onClick={propose}>{t('Propose Allocation')}</button>
                            </div>
                            {proposal && (
                                <div style={{ marginTop: 14 }}>
                                    {proposal.allocations.length === 0 && <p className="sm mut">{t('No unpaid delivered parcels for this courier')}</p>}
                                    {proposal.allocations.map((a, i) => <p key={i} className="sm">{a.invoice} — {fmt(a.amount)} {t('of')} {fmt(a.expected)}</p>)}
                                    {proposal.leftover > 0 && <p className="sm" style={{ color: 'var(--bad)' }}>{fmt(proposal.leftover)} {t('cannot be matched to any parcel')}</p>}
                                    {proposal.allocations.length > 0 && <button className="btn pri" style={{ marginTop: 10 }} onClick={savePayment}>{t('Confirm Payment')}</button>}
                                </div>
                            )}
                        </div>

                        <div className="card" style={{ padding: 24 }}>
                            <h3 className="big">{t('Steadfast Payouts')}</h3>
                            <div className="card-sub">{t('Steadfast pays through its API — pull the payouts and every parcel inside them is matched by itself. Other couriers settle by statement upload or a payment entry.')}</div>
                            {methods.filter((m) => m.class === 'A').map((m) => (
                                <div key={m.id} className="conn-row">
                                    <div style={{ flex: 1 }}><div className="b">{m.label}</div><div className="sm mut">{t('Class A — full API')}</div></div>
                                    <button className="btn pri" onClick={() => router.post('/delivery/pull', { method_id: m.id })}>{t('Pull Payouts')}</button>
                                </div>
                            ))}
                            {methods.filter((m) => m.class === 'A').length === 0 && <p className="mut sm">{t('No Class A courier is configured')}</p>}
                        </div>
                    </>
                )}

                {section === 'Invoice History' && (
                    <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                        <div className="tbl-wrap"><table className="tbl">
                            <thead><tr><th>{t('Date')}</th><th>{t('Courier')}</th><th>{t('Payment')}</th><th>{t('Amount')}</th><th>{t('Parcels')}</th><th>{t('Source')}</th></tr></thead>
                            <tbody>
                                {history.map((s) => (
                                    <tr key={s.id}><td>{s.date}</td><td className="b">{s.courier}</td><td className="sm">{s.payment_id || s.reference || '—'}</td>
                                        <td className="b">{fmt(s.amount)}</td>
                                        <td className="sm">{s.parcels.map((p) => (
                                            <span key={p.allocation_id} style={{ marginRight: 10 }}>{p.invoice} {fmt(p.amount)}
                                                {p.disputed ? <span className="pill red">{t('Disputed')}</span> : (
                                                    <button className="btn" style={{ padding: '2px 8px', marginLeft: 4 }} onClick={() => router.post(`/delivery/allocations/${p.allocation_id}/dispute`)}>{t('Mark disputed')}</button>
                                                )}
                                            </span>
                                        ))}</td>
                                        <td>{t(s.source)}</td></tr>
                                ))}
                                {history.length === 0 && <tr><td colSpan={6} style={{ textAlign: 'center', padding: 26 }} className="mut">{t('No settlements yet')}</td></tr>}
                            </tbody>
                        </table></div>
                        {payments.length > 0 && (
                            <div style={{ padding: '14px 18px' }}>
                                <div className="b" style={{ marginBottom: 8 }}>{t('Recorded Payments')}</div>
                                {payments.map((p) => <p key={p.id} className="sm">{p.date} · {p.courier} · {fmt(p.amount)} · {p.reference || '—'} · {t(p.status)}</p>)}
                            </div>
                        )}
                    </div>
                )}

                {guideOpen && (
                    <div className="overlay" onClick={() => setGuideOpen(false)}>
                        <div className="modal" onClick={(e) => e.stopPropagation()}>
                            <h3 style={{ marginTop: 0 }}>{t('Fixing invalid lines')}</h3>
                            <ul className="sm" style={{ lineHeight: 1.8, paddingLeft: 18, listStyle: 'disc' }}>
                                <li>{t('No booked order with this invoice — check the invoice number for a typo, or book the parcel first.')}</li>
                                <li>{t('This parcel is already settled — the money is already recorded; remove the line.')}</li>
                                <li>{t('Same invoice twice in this file — keep one line only.')}</li>
                                <li>{t('PDF is not accepted — download the CSV or XLSX statement from the courier panel instead.')}</li>
                            </ul>
                            <div style={{ textAlign: 'right', marginTop: 14 }}>
                                <button className="btn pri" onClick={() => setGuideOpen(false)}>{t('Close')}</button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
