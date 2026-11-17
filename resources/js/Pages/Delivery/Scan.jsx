import { useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const MODES = [
    ['shipping', 'Scan To Shipping'],
    ['return', 'Scan To Return'],
    ['rts', 'Scan To RTS'],
];
const RETURN_TAGS = ['Returned with Delivery Charge', 'Customer Fraud', 'Courier Fraud', 'Parcel Check & Return Without Delivery Charge'];

export default function Scan({ todayEvents }) {
    const [mode, setMode] = useState('shipping');
    const [code, setCode] = useState('');
    const [returnTag, setReturnTag] = useState(RETURN_TAGS[0]);
    const [instant, setInstant] = useState(true);
    const [pending, setPending] = useState([]); // {code, type}
    const [log, setLog] = useState([]); // {code, note, ok, invoice, at}
    const [counts, setCounts] = useState({ total: 0, shipped: 0, returned: 0, errors: 0 });
    const [cameraNote, setCameraNote] = useState('');
    const inputRef = useRef(null);
    const videoRef = useRef(null);

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    const applyScan = async (scanCode, scanType) => {
        try {
            const res = await fetch('/delivery/scan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ code: scanCode, type: scanType, return_tag: scanType === 'return' ? returnTag : null }),
            });
            const data = await res.json();
            setCounts((c) => ({
                total: c.total + 1,
                shipped: c.shipped + (data.ok && scanType === 'shipping' ? 1 : 0),
                returned: c.returned + (data.ok && scanType !== 'shipping' ? 1 : 0),
                errors: c.errors + (data.ok ? 0 : 1),
            }));
            setLog((l) => [{ code: scanCode, note: data.note, ok: data.ok, invoice: data.invoice, at: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }, ...l]);
            return data.ok;
        } catch {
            setCounts((c) => ({ ...c, total: c.total + 1, errors: c.errors + 1 }));
            setLog((l) => [{ code: scanCode, note: 'Network error', ok: false, at: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }, ...l]);
            return false;
        }
    };

    const submit = async (e) => {
        e.preventDefault();
        const value = code.trim();
        if (!value) return;
        setCode('');
        inputRef.current?.focus();
        if (instant) {
            await applyScan(value, mode);
        } else {
            setPending((p) => [{ code: value, type: mode }, ...p]);
        }
    };

    const completeAll = async () => {
        const queue = [...pending].reverse();
        setPending([]);
        for (const item of queue) {
            await applyScan(item.code, item.type);
        }
    };

    const startCamera = async () => {
        setCameraNote('');
        try {
            if (!('BarcodeDetector' in window)) {
                setCameraNote('This browser cannot scan with the camera — use a USB scanner or type the code.');
                return;
            }
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                videoRef.current.play();
                const detector = new window.BarcodeDetector();
                const tick = async () => {
                    if (!videoRef.current || !videoRef.current.srcObject) return;
                    try {
                        const codes = await detector.detect(videoRef.current);
                        if (codes.length) {
                            setCode(codes[0].rawValue);
                            stream.getTracks().forEach((tr) => tr.stop());
                            videoRef.current.srcObject = null;
                            return;
                        }
                    } catch { /* keep trying */ }
                    setTimeout(tick, 400);
                };
                tick();
            }
        } catch {
            setCameraNote('Camera could not be opened — use a USB scanner or type the code.');
        }
    };

    return (
        <AppLayout title={t('Scan To Update')}>
            <div className="page-head">
                <div>
                    <h1>{t('Scan To Update')}</h1>
                    <div className="mut sm">{t('Scan the invoice or the courier tracking code — the parcel status updates at once')}</div>
                </div>
            </div>

            <div className="flex" style={{ gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
                {MODES.map(([key, label]) => (
                    <button key={key} className={`btn ${mode === key ? 'pri' : ''}`} onClick={() => setMode(key)}>{t(label)}</button>
                ))}
                <label className="flex" style={{ gap: 6, alignItems: 'center', marginLeft: 8 }}>
                    <input type="checkbox" checked={instant} onChange={(e) => setInstant(e.target.checked)} /> {t('Apply instantly')}
                </label>
            </div>

            <div className="card" style={{ marginBottom: 14 }}>
                <form onSubmit={submit} className="flex" style={{ gap: 8, flexWrap: 'wrap' }}>
                    <input ref={inputRef} className="inp" style={{ flex: 1, minWidth: 240, fontSize: 17, padding: '13px 16px' }}
                        placeholder={t('Scan or type the invoice / tracking code, then press Enter')}
                        value={code} onChange={(e) => setCode(e.target.value)} autoFocus />
                    <button className="btn pri" type="submit">{t('Scan')}</button>
                    <button className="btn" type="button" onClick={startCamera}>{t('Use phone camera')}</button>
                </form>
                {mode === 'return' && (
                    <div className="frow mt16"><label className="fl">{t('Returned stock handling')}</label>
                        <select className="inp" value={returnTag} onChange={(e) => setReturnTag(e.target.value)}>
                            {RETURN_TAGS.map((x) => <option key={x} value={x}>{t(x)}</option>)}
                        </select>
                        <span className="sm mut">{t('Automatic — the stock goes back in by itself')}</span>
                    </div>
                )}
                {cameraNote && <p className="sm" style={{ color: 'var(--bad)', marginTop: 10 }}>{t(cameraNote)}</p>}
                <video ref={videoRef} style={{ width: '100%', maxWidth: 420, borderRadius: 12, marginTop: 10, display: 'block' }} muted playsInline />
            </div>

            <div className="grid2" style={{ marginBottom: 14 }}>
                <div className="card">
                    <h3>{t('SCAN SESSION SUMMARY')}</h3>
                    <div className="flex" style={{ gap: 22, flexWrap: 'wrap', marginTop: 8 }}>
                        <div><div className="b" style={{ fontSize: 22 }}>{counts.total}</div><div className="sm mut">{t('Total Scanned')}</div></div>
                        <div><div className="b" style={{ fontSize: 22 }}>{counts.shipped}</div><div className="sm mut">{t('Shipped')}</div></div>
                        <div><div className="b" style={{ fontSize: 22 }}>{counts.returned}</div><div className="sm mut">{t('Returned To Store')}</div></div>
                        <div><div className="b" style={{ fontSize: 22, color: 'var(--bad)' }}>{counts.errors}</div><div className="sm mut">{t('Error')}</div></div>
                        <div><div className="b" style={{ fontSize: 22 }}>{pending.length}</div><div className="sm mut">{t('Processing')}</div></div>
                    </div>
                    {pending.length > 0 && (
                        <div style={{ marginTop: 14 }}>
                            <div className="flex" style={{ gap: 8, flexWrap: 'wrap' }}>
                                <button className="btn pri" onClick={completeAll}>{t('Complete All')}</button>
                                {MODES.map(([key, label]) => (
                                    <span key={key} className="pill">{t('Pending')} {t(label.replace('Scan To ', ''))} ({pending.filter((p) => p.type === key).length})</span>
                                ))}
                            </div>
                            <div style={{ marginTop: 10 }}>
                                {pending.map((p, i) => (
                                    <div key={i} className="flex" style={{ justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--line)' }}>
                                        <span className="b">{p.code}</span><span className="sm mut">{t(MODES.find((m) => m[0] === p.type)?.[1] || '')}</span>
                                        <button className="btn" onClick={async () => { setPending((x) => x.filter((_, j) => j !== i)); await applyScan(p.code, p.type); }}>{t('Apply')}</button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
                <div className="card">
                    <h3>{t('SCAN ACTIVITY LOG')}</h3>
                    {log.length === 0 && todayEvents.length === 0 && <p className="mut sm">{t('Nothing scanned yet in this session')}</p>}
                    {log.map((l, i) => (
                        <div key={i} className="flex" style={{ justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--line)' }}>
                            <span className="b">{l.invoice || l.code}</span>
                            <span className="sm" style={{ color: l.ok ? 'var(--ok)' : 'var(--bad)' }}>{t(l.note)}</span>
                            <span className="sm mut">{l.at}</span>
                        </div>
                    ))}
                    {todayEvents.map((e) => (
                        <div key={'t' + e.id} className="flex" style={{ justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--line)' }}>
                            <span className="b">{e.code}</span>
                            <span className="sm" style={{ color: e.result === 'success' ? 'var(--ok)' : 'var(--bad)' }}>{t(e.note || e.result)}</span>
                            <span className="sm mut">{e.at}</span>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
