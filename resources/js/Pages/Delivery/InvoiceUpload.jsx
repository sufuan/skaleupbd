import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { fmt } from '@/Pages/Orders/ui';
import { t } from '@/lib/i18n';

const TABS = [
    ['upload', 'Invoice Upload'],
    ['payouts', 'Steadfast Payouts'],
    ['history', 'Settlement History'],
    ['receivable', 'COD Receivable'],
    ['reconciliation', 'Reconciliation'],
    ['differences', 'Differences'],
];

export default function InvoiceUpload({ methods, history, payments, receivables, buckets, differences }) {
    const [tab, setTab] = useState('upload');
    const [preview, setPreview] = useState(null);
    const [previewError, setPreviewError] = useState('');
    const [busy, setBusy] = useState(false);
    const fileRef = useRef(null);
    const [manual, setManual] = useState({ invoice: '', paid_amount: '' });
    const [extraLines, setExtraLines] = useState([]);
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
        const lines = [...(preview?.lines || []), ...extraLines];
        router.post('/delivery/invoice/confirm', { lines }, { onSuccess: () => { setPreview(null); setExtraLines([]); } });
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

    const recon = {};
    receivables.forEach((r) => {
        const k = r.courier || '—';
        recon[k] = recon[k] || { expected: 0, settled: 0 };
        recon[k].expected += r.expected; recon[k].settled += r.settled;
    });

    return (
        <AppLayout title={t('Courier Invoice Upload')}>
            <div className="page-head">
                <div>
                    <h1>{t('Courier Invoice Upload')}</h1>
                    <div className="mut sm">{t('Match the courier\'s statement to your parcels — the money lands in the Courier Ledger')}</div>
                </div>
            </div>

            <div className="flex" style={{ gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
                {TABS.map(([key, label]) => (
                    <button key={key} className={`btn ${tab === key ? 'pri' : ''}`} onClick={() => setTab(key)}>{t(label)}</button>
                ))}
            </div>

            {tab === 'upload' && (
                <div className="card">
                    <h3>{t('Invoice Upload')}</h3>
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
                            <div className="frow mt16" style={{ gap: 8 }}>
                                <input className="inp" style={{ maxWidth: 180 }} placeholder={t('Invoice')} value={manual.invoice} onChange={(e) => setManual({ ...manual, invoice: e.target.value })} />
                                <input className="inp" style={{ maxWidth: 150 }} type="number" placeholder={t('Paid Amount')} value={manual.paid_amount} onChange={(e) => setManual({ ...manual, paid_amount: e.target.value })} />
                                <button className="btn" onClick={() => { if (manual.invoice && manual.paid_amount) { setExtraLines((x) => [...x, { invoice: manual.invoice, paid_amount: Number(manual.paid_amount) }]); setManual({ invoice: '', paid_amount: '' }); } }}>{t('Search & Add Orders')}</button>
                            </div>
                            {extraLines.map((l, i) => <p key={i} className="sm">{l.invoice} — {fmt(l.paid_amount)} (added by hand)</p>)}
                            <div style={{ marginTop: 16 }}>
                                <button className="btn pri" disabled={preview.matched.length === 0 && extraLines.length === 0} onClick={confirmUpload}>{t('Confirm & Save')}</button>
                            </div>
                        </div>
                    )}

                    <hr style={{ border: 'none', borderTop: '1px solid var(--line)', margin: '22px 0' }} />
                    <h3>{t('One-line Payment Entry')}</h3>
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
            )}

            {tab === 'payouts' && (
                <div className="card">
                    <h3>{t('Steadfast Payouts')}</h3>
                    <div className="card-sub">{t('Steadfast pays through its API — pull the payouts and every parcel inside them is matched by itself. Other couriers settle by statement upload or a payment entry.')}</div>
                    {methods.filter((m) => m.class === 'A').map((m) => (
                        <div key={m.id} className="conn-row">
                            <div style={{ flex: 1 }}><div className="b">{m.label}</div><div className="sm mut">{t('Class A — full API')}</div></div>
                            <button className="btn pri" onClick={() => router.post('/delivery/pull', { method_id: m.id })}>{t('Pull Payouts')}</button>
                        </div>
                    ))}
                    {methods.filter((m) => m.class === 'A').length === 0 && <p className="mut sm">{t('No Class A courier is configured')}</p>}
                </div>
            )}

            {tab === 'history' && (
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

            {tab === 'receivable' && (
                <div className="card">
                    <h3>{t('COD Receivable')}</h3>
                    <div className="card-sub">{t('Money the couriers have collected on delivered parcels but not paid you yet, by how long it has been waiting')}</div>
                    <div className="flex" style={{ gap: 22, flexWrap: 'wrap', margin: '10px 0 16px' }}>
                        {Object.entries(buckets).map(([k, v]) => (
                            <div key={k}><div className="b" style={{ fontSize: 20 }}>{fmt(v)}</div><div className="sm mut">{k} {t('days')}</div></div>
                        ))}
                    </div>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('Invoice')}</th><th>{t('Courier')}</th><th>{t('COD')}</th><th>{t('Committed Charge')}</th><th>{t('Expected')}</th><th>{t('Settled')}</th><th>{t('Difference')}</th><th>{t('Age')}</th></tr></thead>
                        <tbody>
                            {receivables.map((r, i) => (
                                <tr key={i}><td className="b">{r.invoice}</td><td>{r.courier}</td><td>{fmt(r.cod)}</td><td>{fmt(r.committed_charge)}</td>
                                    <td>{fmt(r.expected)}</td><td>{fmt(r.settled)}</td>
                                    <td style={{ color: r.difference < 0 ? 'var(--bad)' : 'inherit' }}>{fmt(r.difference)}</td><td>{r.bucket}</td></tr>
                            ))}
                            {receivables.length === 0 && <tr><td colSpan={8} style={{ textAlign: 'center', padding: 22 }} className="mut">{t('Nothing is owed right now')}</td></tr>}
                        </tbody>
                    </table></div>
                </div>
            )}

            {tab === 'reconciliation' && (
                <div className="card">
                    <h3>{t('Reconciliation')}</h3>
                    <div className="card-sub">{t('Expected uses the rate card in Settings (Dhaka → Inside charge, anywhere else → Outside charge, weight up to 0.5 kg)')}</div>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('Courier')}</th><th>{t('Expected')}</th><th>{t('Settled')}</th><th>{t('Difference')}</th></tr></thead>
                        <tbody>
                            {Object.entries(recon).map(([k, v]) => (
                                <tr key={k}><td className="b">{k}</td><td>{fmt(v.expected)}</td><td>{fmt(v.settled)}</td>
                                    <td style={{ color: v.settled - v.expected < 0 ? 'var(--bad)' : 'inherit' }}>{fmt(v.settled - v.expected)}</td></tr>
                            ))}
                            {Object.keys(recon).length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 22 }} className="mut">{t('Nothing to reconcile yet')}</td></tr>}
                        </tbody>
                    </table></div>
                </div>
            )}

            {tab === 'differences' && (
                <div className="card">
                    <h3>{t('Differences')}</h3>
                    <div className="card-sub">{t('Parcels that were paid less than expected. Each one also raises a task in the Work Inbox — nothing passes silently.')}</div>
                    <div className="tbl-wrap"><table className="tbl">
                        <thead><tr><th>{t('Invoice')}</th><th>{t('Courier')}</th><th>{t('Type')}</th><th>{t('Severity')}</th><th>{t('Expected')}</th><th>{t('Actual')}</th><th>{t('Difference')}</th></tr></thead>
                        <tbody>
                            {differences.map((d, i) => (
                                <tr key={i}><td className="b">{d.invoice}</td><td>{d.courier}</td><td>{t(d.type)}</td>
                                    <td><span className={`pill ${d.severity === 'High' ? 'red' : d.severity === 'Medium' ? 'amber' : ''}`}>{t(d.severity)}</span></td>
                                    <td>{fmt(d.expected)}</td><td>{fmt(d.actual)}</td><td style={{ color: 'var(--bad)' }}>{fmt(d.difference)}</td></tr>
                            ))}
                            {differences.length === 0 && <tr><td colSpan={7} style={{ textAlign: 'center', padding: 22 }} className="mut">{t('No differences — every parcel matches')}</td></tr>}
                        </tbody>
                    </table></div>
                </div>
            )}
        </AppLayout>
    );
}
