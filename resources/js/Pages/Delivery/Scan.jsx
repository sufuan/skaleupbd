import { useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const MODES = [
    ['shipping', 'Scan To Shipping'],
    ['return', 'Scan To Return'],
    ['rts', 'Scan To RTS'],
];
const RETURN_TAGS = ['Returned with Delivery Charge', 'Customer Fraud', 'Courier Fraud', 'Parcel Check & Return Without Delivery Charge'];
const FILTERS = ['All Status', 'Success', 'Failed', 'Duplicate'];
const VOLUMES = ['Low', 'Med', 'High'];

const I = {
    history: (<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" /><path d="M3 3v5h5" /><polyline points="12 7 12 12 15 14" /></svg>),
    download: (<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>),
    frame: (<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#411550" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2" /><path d="M17 3h2a2 2 0 0 1 2 2v2" /><path d="M21 17v2a2 2 0 0 1-2 2h-2" /><path d="M7 21H5a2 2 0 0 1-2-2v-2" /><line x1="7" y1="12" x2="17" y2="12" /></svg>),
    expand: (<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748B" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 3h6v6" /><path d="M9 21H3v-6" /><path d="M21 3l-7 7" /><path d="M3 21l7-7" /></svg>),
    check: (<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16A34A" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><polyline points="8.5 12.2 11 14.7 15.5 9.5" /></svg>),
    cross: (<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><line x1="9" y1="9" x2="15" y2="15" /><line x1="15" y1="9" x2="9" y2="15" /></svg>),
    copy: (<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#D97706" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>),
    speaker: (<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#64748B" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" /><path d="M15.54 8.46a5 5 0 0 1 0 7.07" /><path d="M19.07 4.93a10 10 0 0 1 0 14.14" /></svg>),
};

export default function Scan({ todayEvents }) {
    // Cross-business search is a platform-admin tool — sellers must never
    // see the toggle (their scans only ever search their own shop).
    const isPlatformAdmin = !!usePage().props.auth?.user?.is_platform_admin;
    const [mode, setMode] = useState('shipping');
    const [inputMode, setInputMode] = useState('keyboard');
    const [code, setCode] = useState('');
    const [manualCode, setManualCode] = useState('');
    const [returnTag, setReturnTag] = useState(RETURN_TAGS[0]);
    const [log, setLog] = useState([]); // {code, invoice, note, kind: success|failed|duplicate, at}
    const [filter, setFilter] = useState('All Status');
    const [volume, setVolume] = useState('High');
    const [allBiz, setAllBiz] = useState(false);
    const [cameraNote, setCameraNote] = useState('');
    const inputRef = useRef(null);
    const videoRef = useRef(null);
    const audioRef = useRef(null);

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const modeLabel = MODES.find((m) => m[0] === mode)?.[1] || '';

    const beep = (kind) => {
        try {
            audioRef.current = audioRef.current || new (window.AudioContext || window.webkitAudioContext)();
            const ctx = audioRef.current;
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            gain.gain.value = volume === 'Low' ? 0.03 : volume === 'Med' ? 0.08 : 0.15;
            osc.frequency.value = kind === 'success' ? 880 : kind === 'duplicate' ? 520 : 180;
            osc.type = kind === 'failed' ? 'sawtooth' : 'sine';
            osc.connect(gain); gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.15);
        } catch { /* sound is a nicety, never block a scan */ }
    };

    const applyScan = async (scanCode, scanType) => {
        const at = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        try {
            const res = await fetch('/delivery/scan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ code: scanCode, type: scanType, return_tag: scanType === 'return' ? returnTag : null, all_biz: isPlatformAdmin && allBiz }),
            });
            const data = await res.json();
            const kind = data.ok ? 'success' : data.duplicate ? 'duplicate' : 'failed';
            beep(kind);
            setLog((l) => [{ code: scanCode, invoice: data.invoice, note: data.note, kind, at }, ...l]);
            return data.ok;
        } catch {
            beep('failed');
            setLog((l) => [{ code: scanCode, invoice: undefined, note: 'Network error', kind: 'failed', at }, ...l]);
            return false;
        }
    };

    const submit = async (e) => {
        e.preventDefault();
        const value = code.trim();
        if (!value) return;
        setCode('');
        inputRef.current?.focus();
        await applyScan(value, mode);
    };

    const submitManual = async () => {
        const value = manualCode.trim();
        if (!value) return;
        setManualCode('');
        await applyScan(value, mode);
        inputRef.current?.focus();
    };

    const startCamera = async () => {
        setInputMode('camera');
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
                            const value = codes[0].rawValue;
                            stream.getTracks().forEach((tr) => tr.stop());
                            videoRef.current.srcObject = null;
                            setInputMode('keyboard');
                            await applyScan(value, mode);
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

    const session = useMemo(() => ({
        unique: new Set(log.map((l) => l.invoice || l.code)).size,
        success: log.filter((l) => l.kind === 'success').length,
        failed: log.filter((l) => l.kind === 'failed').length,
        duplicate: log.filter((l) => l.kind === 'duplicate').length,
    }), [log]);

    const allEntries = useMemo(() => [
        ...log,
        ...todayEvents.map((e) => ({ code: e.code, invoice: undefined, note: e.note || e.result, kind: e.result === 'success' ? 'success' : e.result === 'duplicate' ? 'duplicate' : 'failed', at: e.at })),
    ], [log, todayEvents]);
    const shownEntries = filter === 'All Status' ? allEntries : allEntries.filter((e) => e.kind === filter.toLowerCase());

    const exportResult = () => {
        const rows = [['Time', 'Code', 'Invoice', 'Result', 'Note'], ...allEntries.map((e) => [e.at, e.code, e.invoice || '', e.kind, (e.note || '').replaceAll(',', ';')])];
        const blob = new Blob([rows.map((r) => r.join(',')).join('\n')], { type: 'text/csv' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'scan-results.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    };

    const shiftMode = (dir) => {
        const idx = MODES.findIndex((m) => m[0] === mode);
        const next = MODES[(idx + dir + MODES.length) % MODES.length];
        setMode(next[0]);
    };

    const statCard = (label, value, color, icon) => (
        <div className="stat">
            <div style={{ display: 'flex', alignItems: 'center' }}>
                <span className="stat-l">{label}</span>
                <span className="stat-ic">{icon}</span>
            </div>
            <b style={{ color }}>{value}</b>
        </div>
    );

    return (
        <AppLayout title={t('Logistics Scanner Console')}>
            <div className="scan-pg" style={{ maxWidth: 1500, margin: '0 auto' }}>
                <div className="page-head" style={{ marginTop: 24, flexWrap: 'nowrap' }}>
                    <div>
                        <h1 style={{ fontSize: 24, fontWeight: 700, color: '#411550' }}>{t('Logistics Scanner Console')}</h1>
                        <p>{t('Scan order barcodes or invoices to perform high-speed state changes.')}</p>
                    </div>
                    <span style={{ marginLeft: 'auto', display: 'flex', gap: 8, flexShrink: 0 }}>
                        <button className="ctl-btn" onClick={() => document.getElementById('scanLog')?.scrollIntoView({ behavior: 'smooth' })}>{I.history} {t('Scan History')}</button>
                        <button className="btn-primary" onClick={exportResult}>{I.download} {t('Export Result')}</button>
                    </span>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <button className="ctl-btn" style={{ padding: '6px 9px' }} onClick={() => shiftMode(-1)}>‹</button>
                    <div className="ptabs" style={{ flex: 1, marginBottom: 0 }}>
                        {MODES.map(([key, label]) => (
                            <span key={key} className={`ptab ${mode === key ? 'act' : ''}`} onClick={() => setMode(key)}>{t(label)}</span>
                        ))}
                    </div>
                    <button className="ctl-btn" style={{ padding: '6px 9px' }} onClick={() => shiftMode(1)}>›</button>
                </div>

                <div className="scan-grid">
                    <div>
                        <div className="card" style={{ padding: 24 }}>
                            <h3 style={{ fontSize: 16, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 8, borderBottom: '1px solid var(--line)', paddingBottom: 12 }}>
                                {I.frame} {t(modeLabel)}
                            </h3>
                            <div className="seg" style={{ margin: '14px 0' }}>
                                <button className={inputMode === 'keyboard' ? 'on' : ''} onClick={() => setInputMode('keyboard')}>{t('Keyboard scanner')}</button>
                                <button className={inputMode === 'camera' ? 'on' : ''} onClick={startCamera}>{t('Use phone camera')}</button>
                            </div>
                            <div style={{ border: '1px solid var(--line)', borderRadius: 10, padding: 14 }}>
                                <div className="muted-sm" style={{ marginBottom: 8 }}>{t('Click input to activate scanner or type manually')}</div>
                                <form onSubmit={submit}>
                                    <input ref={inputRef} className="fld" style={{ fontSize: 16, padding: 14 }} placeholder={t('Scan or type invoice number and press Enter...')}
                                        value={code} onChange={(e) => setCode(e.target.value)} autoFocus />
                                </form>
                            </div>
                            {mode === 'return' && (
                                <div style={{ marginTop: 14 }}>
                                    <label className="fld-l">{t('Returned stock handling')}</label>
                                    <select className="fld" style={{ marginTop: 6 }} value={returnTag} onChange={(e) => setReturnTag(e.target.value)}>
                                        {RETURN_TAGS.map((x) => <option key={x} value={x}>{t(x)}</option>)}
                                    </select>
                                </div>
                            )}
                            {cameraNote && <p className="sm" style={{ color: 'var(--bad)', marginTop: 10 }}>{t(cameraNote)}</p>}
                            <video ref={videoRef} style={{ width: '100%', maxWidth: 420, borderRadius: 12, marginTop: 10, display: inputMode === 'camera' ? 'block' : 'none' }} muted playsInline />
                            <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 14 }}>
                                <span style={{ fontSize: 12, fontWeight: 700, letterSpacing: '.03em' }}>{t('MANUAL OVERRIDE:')}</span>
                                <input className="fld" style={{ flex: 1 }} placeholder={t('Enter invoice # manually...')} value={manualCode}
                                    onChange={(e) => setManualCode(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') submitManual(); }} />
                                <button className="btn-primary" disabled={!manualCode.trim()} onClick={submitManual}
                                    style={!manualCode.trim() ? { background: '#9F8AA6', borderColor: '#9F8AA6', color: '#fff' } : undefined}>{t('Submit')}</button>
                            </div>
                        </div>

                        <div style={{ border: '1.5px dashed #D8CFE4', borderRadius: 12, padding: 16, marginTop: 16, background: '#fff' }}>
                            <b style={{ fontSize: 14 }}>{t('Scanner Instructions & Shortcuts')}</b>
                            <ul className="muted-sm" style={{ margin: '8px 0 0', paddingLeft: 18, lineHeight: 1.7, listStyle: 'disc' }}>
                                <li>{t('For barcode hardware scanners, keep this page open and focused. No input click is necessary.')}</li>
                                <li>{t('Toggle between camera scan and keyboard scan modes using the selector buttons above.')}</li>
                                <li>{t('Change the beep volume with the Low / Med / High buttons under the summary.')}</li>
                            </ul>
                        </div>
                    </div>

                    <div>
                        <h3 className="scan-h" style={{ marginBottom: 14 }}>{t('SCAN SESSION SUMMARY')}</h3>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                            {statCard(t('UNIQUE PARCELS'), session.unique, '#411550', I.expand)}
                            {statCard(t('SUCCESSFUL UPDATES'), session.success, '#16A34A', I.check)}
                            {statCard(t('FAILED ATTEMPTS'), session.failed, '#DC2626', I.cross)}
                            {statCard(t('DUPLICATE / ALREADY UPDATED'), session.duplicate, '#D97706', I.copy)}
                        </div>
                    </div>
                </div>

                <div style={{ marginTop: 16, display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                    {isPlatformAdmin && (
                        <span className="pill-box">
                            {t('Search all businesses')}
                            <button className={`switch ${allBiz ? 'on' : ''}`} onClick={() => setAllBiz(!allBiz)} aria-label={t('Search all businesses')} />
                        </span>
                    )}
                    <span className="pill-box">
                        {I.speaker}
                        <span className="seg">
                            {VOLUMES.map((v) => <button key={v} className={volume === v ? 'on' : ''} onClick={() => setVolume(v)}>{t(v)}</button>)}
                        </span>
                    </span>
                    <span className="pill-box" style={{ marginLeft: 'auto' }}>
                        <span className="muted-sm" style={{ fontWeight: 700 }}>{t('FILTER')}</span>
                        <select className="fld" style={{ width: 170 }} value={filter} onChange={(e) => setFilter(e.target.value)}>
                            {FILTERS.map((f) => <option key={f} value={f}>{t(f)}</option>)}
                        </select>
                    </span>
                </div>

                <div id="scanLog" style={{ marginTop: 22 }}>
                    <h3 className="scan-h">{t('SCAN ACTIVITY LOG')}</h3>
                    <p className="muted-sm" style={{ marginTop: 6 }}>{log.length > 0 ? t(log[0].note) : t('Ready to scan')}</p>
                    <div style={{ marginTop: 6 }}>
                        {shownEntries.length === 0 && <p className="muted-sm">{t('No items scanned yet')}</p>}
                        {shownEntries.map((l, i) => (
                            <div key={i} className="flex" style={{ justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--line)' }}>
                                <span className="b">{l.invoice || l.code}</span>
                                <span className="sm" style={{ color: l.kind === 'success' ? 'var(--ok)' : l.kind === 'duplicate' ? '#D97706' : 'var(--bad)' }}>{t(l.note)}</span>
                                <span className="sm mut">{l.at}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
