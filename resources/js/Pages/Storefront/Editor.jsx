import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog } from '@/lib/confirm';
import { t } from '@/lib/i18n';

/* Page Builder v2 - three-pane workspace over the v2 block JSON.
   The canvas is the REAL public renderer in an iframe (draft preview),
   so what the seller sees is exactly what customers get. All edits go
   through commit() (undo snapshots + typing coalescing + autosave);
   undo only ever touches the draft, never the live page. */

const TYPE_LABEL = { hero: 'Top banner', offer: 'Offer strip', products: 'Products', faq: 'Questions & answers', order_form: 'Order form', info: 'Delivery & payment info', countdown: 'Countdown' };
const TYPES = ['hero', 'offer', 'products', 'faq', 'order_form', 'info', 'countdown'];
const HIDEABLE = ['hero', 'offer', 'faq', 'info', 'countdown'];
const LOCKED = ['products', 'order_form'];

const svg = (children, size = 18) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{children}</svg>
);
const Ic = {
    desktop: (s = 16) => svg(<><rect x="3" y="4" width="18" height="12" rx="2" /><path d="M9 20h6M12 16v4" /></>, s),
    mobile: (s = 16) => svg(<><rect x="7" y="3" width="10" height="18" rx="2" /><path d="M11 18.5h2" /></>, s),
    eye: (s = 16) => svg(<><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z" /><circle cx="12" cy="12" r="3" /></>, s),
    eyeOff: (s = 16) => svg(<><path d="M4 4l16 16" /><path d="M10.6 6.1A9.8 9.8 0 0 1 12 6c6.5 0 10 6 10 6a17.4 17.4 0 0 1-3.2 3.1M6.6 6.9A16.9 16.9 0 0 0 2 12s3.5 6 10 6c1.5 0 2.9-.3 4.1-.8" /></>, s),
    lock: (s = 15) => svg(<><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></>, s),
    trash: (s = 15) => svg(<><path d="M4 7h16M9 7V5h6v2m-8 0 1 13h8l1-13" /></>, s),
    undo: (s = 17) => svg(<><polyline points="9 14 4 9 9 4" /><path d="M20 20v-7a4 4 0 0 0-4-4H4" /></>, s),
    redo: (s = 17) => svg(<><polyline points="15 14 20 9 15 4" /><path d="M4 20v-7a4 4 0 0 1 4-4h12" /></>, s),
    check: (s = 16) => svg(<path d="M4 12.5 9.5 18 20 6.5" />, s),
    warn: (s = 16) => svg(<><path d="M12 3 2.5 20h19L12 3z" /><path d="M12 10v4M12 17.2v.3" /></>, s),
    err: (s = 16) => svg(<><circle cx="12" cy="12" r="9" /><path d="M9 9l6 6M15 9l-6 6" /></>, s),
    grip: (s = 14) => (
        <svg width={s} height={s + 4} viewBox="0 0 10 16" fill="currentColor" aria-hidden="true">
            <circle cx="2.5" cy="3" r="1.4" /><circle cx="7.5" cy="3" r="1.4" /><circle cx="2.5" cy="8" r="1.4" /><circle cx="7.5" cy="8" r="1.4" /><circle cx="2.5" cy="13" r="1.4" /><circle cx="7.5" cy="13" r="1.4" />
        </svg>
    ),
};

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const clone = (x) => JSON.parse(JSON.stringify(x));

function computeChecks(blocks, products) {
    const out = [];
    const byId = Object.fromEntries(products.map((p) => [p.id, p]));
    for (const b of blocks) {
        const p = b.props || {};
        if (b.type === 'hero') {
            if (!String(p.title || '').trim()) out.push({ level: 'error', blockId: b.id, msg: t('Top banner title is empty') });
            if (p.image && !String(p.alt || '').trim()) out.push({ level: 'warning', blockId: b.id, msg: t('Picture has no description') });
        }
        if (b.type === 'products') {
            const ids = p.product_ids || [];
            if (ids.length > 0) {
                const oos = ids.map((id) => byId[id]).filter((pr) => pr && Number(pr.stock) <= 0);
                if (oos.length > 0) out.push({ level: 'warning', blockId: b.id, msg: `${t('Some chosen products are out of stock:')} ${oos.map((x) => x.name).join(', ')}` });
            } else if (products.length === 0) {
                // Never an error (owner rule): an empty store may publish;
                // products appear by themselves once added to Inventory.
                out.push({ level: 'warning', blockId: b.id, msg: t('Add a product in Inventory - your page will show it automatically.') });
            }
        }
        if (b.type === 'order_form' && p.product_id) {
            const pr = byId[p.product_id];
            if (pr && Number(pr.stock) <= 0) out.push({ level: 'warning', blockId: b.id, msg: t('The order form product is out of stock') });
        }
        if (b.type === 'faq') {
            const bad = (p.items || []).some((it) => (String(it.q || '').trim() === '') !== (String(it.a || '').trim() === ''));
            if (bad) out.push({ level: 'warning', blockId: b.id, msg: t('A question or answer is empty') });
        }
        if (b.type === 'countdown' && !p.hidden) {
            // An ERROR (blocks publish) like the empty hero title: the
            // seller's own incomplete input, never a product/stock rule.
            const end = p.ends_at ? new Date(String(p.ends_at).replace(' ', 'T')) : null;
            if (!end || Number.isNaN(end.getTime()) || end.getTime() <= Date.now()) {
                out.push({ level: 'error', blockId: b.id, msg: t('Countdown end time is missing or has passed') });
            }
        }
    }
    return out;
}

export default function Editor({ sf, page, previewUrl, products, branding, presetColors }) {
    const initialBlocks = useMemo(() => clone(page.draft?.blocks ?? []), []); // eslint-disable-line react-hooks/exhaustive-deps
    const [blocks, setBlocks] = useState(initialBlocks);
    const [sel, setSel] = useState(() => (initialBlocks[0]?.id ?? null));
    const [past, setPast] = useState([]);
    const [future, setFuture] = useState([]);
    const [device, setDevice] = useState('desktop');
    const [tab, setTab] = useState('sections');
    const [sheet, setSheet] = useState(''); // '' | 'left' | 'right' (small screens)
    const [dlg, setDlg] = useState(''); // '' | 'publish' | 'versions'
    const [addOpen, setAddOpen] = useState(false);
    const [saveState, setSaveState] = useState('idle'); // idle | saving | saved | failed
    const [savedAt, setSavedAt] = useState('');
    const [colour, setColour] = useState(branding?.theme_color || '#411550');
    const [colourState, setColourState] = useState(''); // '' | saving | saved

    const blocksRef = useRef(initialBlocks);
    const coalRef = useRef({ key: '', time: 0 });
    const saveTimer = useRef(null);
    const inFlight = useRef(null);
    const queuedRef = useRef(false);
    const iframeRef = useRef(null);
    const inspectorRef = useRef(null);
    const listRef = useRef(null);
    const scrollRef = useRef(0);
    const pendingReloadRef = useRef(false);
    const colourTimer = useRef(null);

    const checks = useMemo(() => computeChecks(blocks, products), [blocks, products]);
    const errorCount = checks.filter((c) => c.level === 'error').length;
    const warnCount = checks.length - errorCount;
    // Footer wording must be honest: publish-blocking errors are
    // "problems", never lumped in as "warnings".
    const checksText = checks.length === 0 ? t('No problems') : [
        errorCount ? `${errorCount} ${t('problems')}` : null,
        warnCount ? `${warnCount} ${t('warnings')}` : null,
    ].filter(Boolean).join(' · ');

    const pubBlocks = useMemo(
        () => (page.published && Array.isArray(page.published.blocks) ? page.published.blocks : null),
        [page.published],
    );
    const firstPublish = pubBlocks === null;
    const diff = useMemo(() => {
        if (!pubBlocks) return { added: [], edited: [], removed: [], reordered: false };
        const lm = Object.fromEntries(pubBlocks.map((b) => [b.id, b]));
        const cm = Object.fromEntries(blocks.map((b) => [b.id, b]));
        return {
            added: blocks.filter((b) => !lm[b.id]).map((b) => b.type),
            edited: blocks.filter((b) => lm[b.id] && JSON.stringify(lm[b.id]) !== JSON.stringify(b)).map((b) => b.type),
            removed: pubBlocks.filter((b) => !cm[b.id]).map((b) => b.type),
            reordered: pubBlocks.map((b) => b.id).filter((id) => cm[id]).join() !== blocks.map((b) => b.id).filter((id) => lm[id]).join(),
        };
    }, [blocks, pubBlocks]);
    const hasDiff = firstPublish || diff.added.length > 0 || diff.edited.length > 0 || diff.removed.length > 0 || diff.reordered;
    const isLive = page.status === 'live';
    const chipText = isLive
        ? (hasDiff ? `${t('Live')} · ${t('Unpublished changes')}` : t('Live'))
        : page.status === 'paused' ? t('Paused') : t('Draft');
    const chipClass = isLive ? (hasDiff ? 'amber' : 'green') : page.status === 'paused' ? '' : 'amber';

    const selBlock = blocks.find((b) => b.id === sel) || null;
    const selIdx = blocks.findIndex((b) => b.id === sel);
    const missingTypes = TYPES.filter((ty) => !blocks.some((b) => b.type === ty));

    // ------------------------------------------------------------ saving
    const postHighlight = () => {
        const f = iframeRef.current;
        if (f?.contentWindow && sel) {
            try { f.contentWindow.postMessage({ sfHighlight: sel }, '*'); } catch { /* bridge is an enhancement */ }
        }
    };

    const doReloadPreview = () => {
        const f = iframeRef.current;
        if (!f) return;
        try { scrollRef.current = f.contentWindow.scrollY || 0; } catch { scrollRef.current = 0; }
        try { f.contentWindow.location.reload(); } catch { /* same-origin in practice */ }
    };

    const reloadPreview = () => {
        const ae = document.activeElement;
        if (inspectorRef.current && ae && inspectorRef.current.contains(ae) && /INPUT|TEXTAREA|SELECT/.test(ae.tagName)) {
            pendingReloadRef.current = true; // never yank the preview mid-typing
            return;
        }
        doReloadPreview();
    };

    const doSave = () => {
        if (inFlight.current) { queuedRef.current = true; return inFlight.current; }
        setSaveState('saving');
        const run = (async () => {
            let attempt = 0;
            while (true) { // eslint-disable-line no-constant-condition
                try {
                    const res = await fetch(`/storefront/pages/${page.id}/draft`, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
                        body: JSON.stringify({ blocks: { version: 2, blocks: blocksRef.current } }),
                    });
                    if (!res.ok) throw new Error('save failed');
                    const j = await res.json();
                    inFlight.current = null;
                    setSavedAt(j.saved_at || '');
                    setSaveState('saved');
                    reloadPreview();
                    if (queuedRef.current) { queuedRef.current = false; return doSave(); }
                    return true;
                } catch {
                    attempt += 1;
                    if (attempt > 2) { inFlight.current = null; setSaveState('failed'); return false; }
                    await new Promise((r) => setTimeout(r, attempt === 1 ? 2000 : 5000));
                }
            }
        })();
        inFlight.current = run;
        return run;
    };

    const scheduleSave = () => {
        setSaveState('saving');
        if (saveTimer.current) clearTimeout(saveTimer.current);
        saveTimer.current = setTimeout(() => { saveTimer.current = null; doSave(); }, 600);
    };

    const flushSave = async () => {
        if (saveTimer.current) { clearTimeout(saveTimer.current); saveTimer.current = null; }
        await doSave();
    };

    // ------------------------------------------------------------ edits
    const applyCommit = (next, key) => {
        const now = Date.now();
        if (!(key && key === coalRef.current.key && now - coalRef.current.time < 900)) {
            // Snapshot NOW, synchronously — the same deferred-updater
            // trap as undo/redo: stringify inside the setPast updater
            // can run after blocksRef.current has been overwritten
            // with `next`, pushing the post-edit state as the "past".
            const snapshot = JSON.stringify(blocksRef.current);
            setPast((p) => [...p.slice(-49), snapshot]);
            setFuture([]);
        }
        coalRef.current = { key: key || '', time: now };
        blocksRef.current = next;
        setBlocks(next);
        scheduleSave();
    };

    const setProp = (id, key, value, coalesce = true) => {
        applyCommit(
            blocksRef.current.map((b) => (b.id === id ? { ...b, props: { ...b.props, [key]: value } } : b)),
            coalesce ? `${id}:${key}` : null,
        );
    };

    const undo = () => {
        if (!past.length) return;
        const prev = past[past.length - 1];
        // Snapshot NOW, synchronously: blocksRef.current is overwritten
        // below, so reading it inside a deferred setState updater would
        // capture the state being undone TO, not the one being left.
        const current = JSON.stringify(blocksRef.current);
        setPast(past.slice(0, -1));
        setFuture((f) => [...f, current]);
        const next = JSON.parse(prev);
        blocksRef.current = next;
        setBlocks(next);
        coalRef.current = { key: '', time: 0 };
        if (!next.some((b) => b.id === sel)) setSel(next[0]?.id ?? null);
        scheduleSave();
    };

    const redo = () => {
        if (!future.length) return;
        const nxt = future[future.length - 1];
        // Same synchronous snapshot as undo() — see the note there.
        const current = JSON.stringify(blocksRef.current);
        setFuture(future.slice(0, -1));
        setPast((p) => [...p, current]);
        const next = JSON.parse(nxt);
        blocksRef.current = next;
        setBlocks(next);
        coalRef.current = { key: '', time: 0 };
        if (!next.some((b) => b.id === sel)) setSel(next[0]?.id ?? null);
        scheduleSave();
    };

    const moveBlock = (id, dir) => {
        const i = blocksRef.current.findIndex((b) => b.id === id);
        const j = i + dir;
        if (i < 0 || j < 0 || j >= blocksRef.current.length) return;
        const next = [...blocksRef.current];
        [next[i], next[j]] = [next[j], next[i]];
        applyCommit(next, null);
    };

    const toggleHidden = (id) => {
        const b = blocksRef.current.find((x) => x.id === id);
        if (b) setProp(id, 'hidden', !b.props?.hidden, false);
    };

    const addBlock = (type) => {
        const defaults = {
            hero: { title: '', subtitle: '', image: '', alt: '', hidden: false },
            offer: { text: '', hidden: false },
            products: { title: '', product_ids: [] },
            faq: { items: [{ q: '', a: '' }], hidden: false },
            order_form: { title: '', product_id: null },
            info: { hidden: false },
            countdown: { ends_at: null, hidden: false },
        };
        const nb = { id: `b${Date.now().toString(36)}${Math.floor(Math.random() * 999)}`, type, props: defaults[type] };
        const at = selIdx >= 0 ? selIdx + 1 : blocksRef.current.length;
        const next = [...blocksRef.current];
        next.splice(at, 0, nb);
        applyCommit(next, null);
        setSel(nb.id);
        setAddOpen(false);
    };

    const removeBlock = async (id) => {
        if (!(await confirmDialog(t('Remove this section from the page?')))) return;
        const next = blocksRef.current.filter((b) => b.id !== id);
        applyCommit(next, null);
        if (sel === id) setSel(next[0]?.id ?? null);
    };

    // Pointer drag reorder (prototype pattern): move the row node live,
    // commit the resulting order once on release. Move/up listeners sit
    // on window - handle-scoped listeners miss moves once the pointer
    // leaves the (possibly re-rendered) grip element.
    const onHandleDown = (e, id) => {
        e.preventDefault();
        e.stopPropagation();
        const list = listRef.current;
        const row = e.currentTarget.closest('[data-row]');
        if (!list || !row) return;
        row.classList.add('ed-dragging');
        const move = (ev) => {
            const el = document.elementFromPoint(ev.clientX, ev.clientY);
            const over = el?.closest ? el.closest('[data-row]') : null;
            if (over && over !== row && over.parentNode === list) {
                const r = over.getBoundingClientRect();
                list.insertBefore(row, ev.clientY < r.top + r.height / 2 ? over : over.nextSibling);
            }
        };
        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            row.classList.remove('ed-dragging');
            const order = [...list.querySelectorAll('[data-row]')].map((n) => n.getAttribute('data-row'));
            const cur = blocksRef.current.map((b) => b.id);
            if (order.join('|') !== cur.join('|')) {
                const map = Object.fromEntries(blocksRef.current.map((b) => [b.id, b]));
                applyCommit(order.map((x) => map[x]), null);
            }
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        select(id);
    };

    const select = (id) => setSel(id);

    const jumpToCheck = (blockId) => {
        setSel(blockId);
        if (window.innerWidth < 1100) setSheet('right');
    };

    // ------------------------------------------------------------ colour (store-level, saves at once)
    const saveColour = (c) => {
        if (!/^#[0-9a-fA-F]{6}$/.test(c)) return;
        setColour(c);
        setColourState('saving');
        if (colourTimer.current) clearTimeout(colourTimer.current);
        colourTimer.current = setTimeout(async () => {
            const body = new URLSearchParams({
                display_name: branding?.display_name || '',
                theme_color: c,
                pixel_id: branding?.pixel_id || '',
                ga4_id: branding?.ga4_id || '',
                footer_text: branding?.footer_text || '',
                delivery_inside: String(branding?.delivery_inside ?? 60),
                delivery_outside: String(branding?.delivery_outside ?? 120),
            });
            try {
                const res = await fetch('/storefront/branding', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-XSRF-TOKEN': xsrf() },
                    body,
                });
                if (res.ok) {
                    setColourState('saved');
                    doReloadPreview();
                    setTimeout(() => setColourState(''), 2600);
                } else setColourState('');
            } catch { setColourState(''); }
        }, 350);
    };

    // ------------------------------------------------------------ publish / versions
    const openPublish = async () => { await flushSave(); setDlg('publish'); };
    const confirmPublish = () => {
        router.post(`/storefront/pages/${page.id}/publish`, {}, { preserveScroll: true, onSuccess: () => setDlg('') });
    };
    const restore = async (v) => {
        if (!(await confirmDialog(t('Bring this version into your draft?')))) return;
        await flushSave();
        setDlg('');
        router.post(`/storefront/pages/${page.id}/restore/${v.id}`, {}, { preserveScroll: true });
    };
    const unpublish = async () => {
        if (!(await confirmDialog(`${t('Unpublish page')} - ${t('Your draft is kept.')}`))) return;
        setDlg('');
        router.post(`/storefront/pages/${page.id}/pause`, {}, { preserveScroll: true });
    };

    // ------------------------------------------------------------ effects
    // Adopt a server-side draft change (restore / publish visit): our own
    // autosave never changes the prop (JSON endpoint), so any change here
    // is external - take it and clear undo/redo.
    const propDraftJson = useMemo(() => JSON.stringify(page.draft?.blocks ?? []), [page.draft]);
    const adoptedRef = useRef(propDraftJson);
    useEffect(() => {
        if (adoptedRef.current !== propDraftJson) {
            adoptedRef.current = propDraftJson;
            const next = JSON.parse(propDraftJson);
            blocksRef.current = next;
            setBlocks(next);
            setPast([]);
            setFuture([]);
            setSel((s) => (next.some((b) => b.id === s) ? s : next[0]?.id ?? null));
        }
    }, [propDraftJson]);

    useEffect(() => { postHighlight(); }, [sel]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const onMsg = (e) => {
            if (iframeRef.current && e.source === iframeRef.current.contentWindow && e.data?.sfSelect) {
                setSel(e.data.sfSelect);
            }
        };
        window.addEventListener('message', onMsg);
        return () => window.removeEventListener('message', onMsg);
    }, []);

    useEffect(() => {
        const onKey = (e) => {
            const tag = (e.target?.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target?.isContentEditable) return;
            const m = e.ctrlKey || e.metaKey;
            if (m && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); }
            else if (m && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }); // rebind every render so past/future closures stay fresh

    useEffect(() => () => {
        if (saveTimer.current) clearTimeout(saveTimer.current);
        if (colourTimer.current) clearTimeout(colourTimer.current);
    }, []);

    const onFrameLoad = () => {
        try { iframeRef.current?.contentWindow?.scrollTo(0, scrollRef.current || 0); } catch { /* noop */ }
        postHighlight();
    };

    const onInspectorBlur = (e) => {
        if (pendingReloadRef.current && inspectorRef.current && !inspectorRef.current.contains(e.relatedTarget)) {
            pendingReloadRef.current = false;
            doReloadPreview();
        }
    };

    const uploadImage = async (blockId, file) => {
        const fd = new FormData();
        fd.append('image', file);
        try {
            const res = await fetch('/storefront/images', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' } });
            if (res.ok) { const j = await res.json(); setProp(blockId, 'image', j.path || j.url, false); }
        } catch { /* keep the old image */ }
    };

    const openSheet = (which) => {
        if (which === 'edit') { setSheet(sheet === 'right' ? '' : 'right'); return; }
        setTab(which);
        setSheet(sheet === 'left' && tab === which ? '' : 'left');
    };

    // ------------------------------------------------------------ render helpers
    const Field = ({ label, value, max, children }) => (
        <div className="ed-fg">
            <div className="ed-fl"><span>{label}</span>{max ? <span className="ed-cnt">{[...String(value ?? '')].length}/{max}</span> : null}</div>
            {children}
        </div>
    );

    const checkItem = (c) => (
        <button key={`${c.blockId}-${c.msg}`} type="button" className={`ed-ck ${c.level === 'error' ? 'e' : 'w'}`} onClick={() => jumpToCheck(c.blockId)}>
            {c.level === 'error' ? Ic.err(16) : Ic.warn(16)}
            <span>{c.msg}<small>{t(TYPE_LABEL[blocks.find((b) => b.id === c.blockId)?.type] || '')}</small></span>
        </button>
    );

    const inspectorForm = (b) => {
        const p = b.props || {};
        if (b.type === 'hero') return (
            <>
                <Field label={t('Big title')} value={p.title} max={60}>
                    <input className="ed-fld" maxLength={60} value={p.title || ''} onChange={(e) => setProp(b.id, 'title', e.target.value)} />
                </Field>
                <Field label={t('Small line under it')} value={p.subtitle} max={120}>
                    <input className="ed-fld" maxLength={120} value={p.subtitle || ''} onChange={(e) => setProp(b.id, 'subtitle', e.target.value)} />
                </Field>
                <Field label={t('Picture (optional)')}>
                    {p.image ? <img src={p.image.startsWith('http') || p.image.startsWith('/') ? p.image : `/storage/${p.image}`} alt="" className="ed-thumb" /> : null}
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <label className="btn" style={{ cursor: 'pointer' }}>{t('Change picture')}
                            <input type="file" accept="image/*" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadImage(b.id, f); e.target.value = ''; }} />
                        </label>
                        {p.image ? <button type="button" className="btn" onClick={() => { setProp(b.id, 'image', '', false); setProp(b.id, 'alt', '', false); }}>{t('Remove picture')}</button> : null}
                    </div>
                </Field>
                <Field label={t('Picture description')} value={p.alt} max={120}>
                    <input className="ed-fld" maxLength={120} value={p.alt || ''} onChange={(e) => setProp(b.id, 'alt', e.target.value)} />
                </Field>
            </>
        );
        if (b.type === 'offer') return (
            <Field label={t('Offer text')} value={p.text} max={80}>
                <input className="ed-fld" maxLength={80} value={p.text || ''} onChange={(e) => setProp(b.id, 'text', e.target.value)} placeholder={t('e.g. Eid offer - 20% off everything')} />
            </Field>
        );
        if (b.type === 'products') {
            const ids = p.product_ids || [];
            const toggle = (pid) => {
                if (!ids.includes(pid) && ids.length >= 24) return;
                setProp(b.id, 'product_ids', ids.includes(pid) ? ids.filter((x) => x !== pid) : [...ids, pid], false);
            };
            return (
                <>
                    <Field label={t('Section title')} value={p.title} max={120}>
                        <input className="ed-fld" maxLength={120} value={p.title || ''} onChange={(e) => setProp(b.id, 'title', e.target.value)} />
                    </Field>
                    <Field label={t('Choose products')}>
                        <p className="ed-note" style={{ marginTop: 0 }}>{t('Leave all unticked to show everything - products come from your Inventory with true prices.')}</p>
                        <div className="ed-pick">
                            {products.map((pr) => (
                                <label key={pr.id} className="ed-pk">
                                    <input type="checkbox" checked={ids.includes(pr.id)} disabled={!ids.includes(pr.id) && ids.length >= 24} onChange={() => toggle(pr.id)} />
                                    <span style={{ flex: 1 }}>{pr.name}</span>
                                    <small>৳{Number(pr.price).toLocaleString('en-IN')} · {t('Stock')} {pr.stock}</small>
                                </label>
                            ))}
                            {products.length === 0 && <span className="ed-note">{t('No products in Inventory yet.')}</span>}
                        </div>
                        {ids.length >= 24 && <p className="ed-note">{t('You can choose up to 24 products.')}</p>}
                    </Field>
                </>
            );
        }
        if (b.type === 'faq') {
            const items = p.items || [];
            const setItem = (i, key, value) => setProp(b.id, 'items', items.map((it, j) => (j === i ? { ...it, [key]: value } : it)));
            return (
                <div className="ed-fg">
                    {items.map((it, i) => (
                        <div key={i} className="ed-qa">
                            <div className="ed-fl"><span>{t('Question')} {i + 1}</span><span className="ed-cnt">{[...String(it.q || '')].length}/200</span></div>
                            <input className="ed-fld" maxLength={200} value={it.q || ''} onChange={(e) => setItem(i, 'q', e.target.value)} placeholder={t('Question')} />
                            <div className="ed-fl" style={{ marginTop: 8 }}><span>{t('Answer')}</span><span className="ed-cnt">{[...String(it.a || '')].length}/500</span></div>
                            <textarea className="ed-fld ed-ta" maxLength={500} value={it.a || ''} onChange={(e) => setItem(i, 'a', e.target.value)} placeholder={t('Answer')} />
                            <button type="button" className="btn ed-qa-rm" onClick={() => setProp(b.id, 'items', items.filter((_, j) => j !== i), false)}>{t('Remove')}</button>
                        </div>
                    ))}
                    <button type="button" className="btn" disabled={items.length >= 10} onClick={() => setProp(b.id, 'items', [...items, { q: '', a: '' }], false)}>+ {t('Add question')} ({items.length}/10)</button>
                </div>
            );
        }
        if (b.type === 'order_form') return (
            <>
                <Field label={t('Section title')} value={p.title} max={120}>
                    <input className="ed-fld" maxLength={120} value={p.title || ''} onChange={(e) => setProp(b.id, 'title', e.target.value)} />
                </Field>
                <Field label={t('Product for this form')}>
                    <select className="ed-fld" value={p.product_id || ''} onChange={(e) => setProp(b.id, 'product_id', e.target.value ? Number(e.target.value) : null, false)}>
                        <option value="">{t('The first product on this page')}</option>
                        {products.map((pr) => <option key={pr.id} value={pr.id}>{pr.name}</option>)}
                    </select>
                </Field>
                <p className="ed-note">{t('Name, mobile number and address - the customer fills these in.')}</p>
            </>
        );
        if (b.type === 'info') return (
            <p className="ed-note" style={{ marginTop: 12 }}>{t('Everything here comes from your Store Settings - nothing to write in the builder.')}</p>
        );
        if (b.type === 'countdown') return (
            <>
                <Field label={t('Offer end time')}>
                    <input type="datetime-local" className="ed-fld"
                        value={p.ends_at ? String(p.ends_at).replace(' ', 'T').slice(0, 16) : ''}
                        onChange={(e) => setProp(b.id, 'ends_at', e.target.value ? `${e.target.value.replace('T', ' ')}:00` : null, false)} />
                </Field>
                <p className="ed-note">{t('When the time passes, the countdown hides by itself.')}</p>
            </>
        );
        return null;
    };

    return (
        <AppLayout>
            <Head title={`${t('Edit page')} - ${page.name}`} />
            <div className="ed-wrap">
                {/* ------------------------------------------------ top bar */}
                <div className="ed-top">
                    <Link className="btn" href="/storefront/pages">← {t('My Pages')}</Link>
                    <h3 className="ed-name">{page.name}</h3>
                    <span className={`pill ${chipClass}`}>{chipText}</span>
                    <span className="ed-spacer" />
                    <div className="ed-seg" role="group" aria-label={t('Preview')}>
                        <button type="button" className={device === 'desktop' ? 'on' : ''} onClick={() => setDevice('desktop')} aria-label={t('Desktop')}>{Ic.desktop()}<span className="ed-seg-t">{t('Desktop')}</span></button>
                        <button type="button" className={device === 'mobile' ? 'on' : ''} onClick={() => setDevice('mobile')} aria-label={t('Mobile')}>{Ic.mobile()}<span className="ed-seg-t">{t('Mobile')}</span></button>
                    </div>
                    <button type="button" className="ed-ib" disabled={!past.length} onClick={undo} title="Ctrl+Z" aria-label={t('Undo')}>{Ic.undo()}</button>
                    <button type="button" className="ed-ib" disabled={!future.length} onClick={redo} title="Ctrl+Shift+Z" aria-label={t('Redo')}>{Ic.redo()}</button>
                    <span className="ed-save" role="status">
                        {saveState === 'saving' ? t('Saving…')
                            : saveState === 'failed' ? <button type="button" className="ed-retry" onClick={() => doSave()}>{t('Save failed')}</button>
                            : saveState === 'saved' ? `${t('Draft saved')}${savedAt ? ` · ${savedAt}` : ''}` : ''}
                    </span>
                    <button type="button" className="btn" onClick={() => setDlg('versions')}>{t('Versions')}</button>
                    <button type="button" className="btn pri" disabled={isLive && !hasDiff} onClick={openPublish}>{isLive ? t('Publish changes') : t('Publish')}</button>
                </div>

                <div className="ed-grid">
                    {/* ------------------------------------------------ left panel */}
                    <div className={`ed-panel ed-left ${sheet === 'left' ? 'sheet-open' : ''}`}>
                        <div className="ed-tabs">
                            <button type="button" className={tab === 'sections' ? 'on' : ''} onClick={() => setTab('sections')}>{t('Sections')}</button>
                            <button type="button" className={tab === 'design' ? 'on' : ''} onClick={() => setTab('design')}>{t('Design')}</button>
                            <button type="button" className={tab === 'check' ? 'on' : ''} onClick={() => setTab('check')}>{t('Checks')}{checks.length > 0 && <span className={`ed-bdg ${errorCount ? 'e' : ''}`}>{checks.length}</span>}</button>
                        </div>

                        {tab === 'sections' && (
                            <div className="ed-tabpane">
                                <div className="ed-list" ref={listRef}>
                                    {blocks.map((b, i) => (
                                        <div key={b.id} data-row={b.id} tabIndex={0}
                                            className={`ed-row ${b.id === sel ? 'sel' : ''} ${b.props?.hidden ? 'off' : ''}`}
                                            onClick={() => select(b.id)}
                                            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(b.id); } }}>
                                            <span className="ed-grip" title={t('Drag to reorder')} onPointerDown={(e) => onHandleDown(e, b.id)}>{Ic.grip()}</span>
                                            <span className="ed-row-nm">{t(TYPE_LABEL[b.type] || b.type)}</span>
                                            {LOCKED.includes(b.type)
                                                ? <span className="ed-lock" title={t('Locked')}>{Ic.lock(15)}</span>
                                                : (
                                                    <button type="button" className="ed-eye" aria-label={b.props?.hidden ? t('Show') : t('Hide')}
                                                        onClick={(e) => { e.stopPropagation(); toggleHidden(b.id); }}>
                                                        {b.props?.hidden ? Ic.eyeOff() : Ic.eye()}
                                                    </button>
                                                )}
                                            {b.id === sel && (
                                                <span className="ed-mvs">
                                                    <button type="button" disabled={i === 0} onClick={(e) => { e.stopPropagation(); moveBlock(b.id, -1); }} aria-label={t('Move up')}>↑</button>
                                                    <button type="button" disabled={i === blocks.length - 1} onClick={(e) => { e.stopPropagation(); moveBlock(b.id, 1); }} aria-label={t('Move down')}>↓</button>
                                                </span>
                                            )}
                                        </div>
                                    ))}
                                    {blocks.length === 0 && <p className="ed-note">{t('This page has no sections yet. Add one below.')}</p>}
                                </div>
                                <button type="button" className="ed-add" onClick={() => setAddOpen((o) => !o)}>+ {t('Add section')}</button>
                                {addOpen && (
                                    <div className="ed-pop">
                                        {missingTypes.length ? missingTypes.map((ty) => (
                                            <button key={ty} type="button" onClick={() => addBlock(ty)}>{t(TYPE_LABEL[ty])}</button>
                                        )) : <div className="ed-note" style={{ padding: 8 }}>{t('All sections are added')}</div>}
                                    </div>
                                )}
                            </div>
                        )}

                        {tab === 'design' && (
                            <div className="ed-tabpane">
                                <div className="ed-fl"><span>{t('Store colour')}</span>{colourState === 'saved' && <span className="ed-ok">{t('Colour saved')}</span>}</div>
                                <div className="ed-swatches">
                                    {(presetColors || []).map((c) => (
                                        <button key={c} type="button" aria-label={c} onClick={() => saveColour(c)}
                                            className={colour.toLowerCase() === c.toLowerCase() ? 'on' : ''}
                                            style={{ background: c }} />
                                    ))}
                                    <input type="color" className="ed-colorpick" value={/^#[0-9a-fA-F]{6}$/.test(colour) ? colour : '#411550'}
                                        onChange={(e) => saveColour(e.target.value)} aria-label={t('Custom colour')} />
                                </div>
                                <input className="ed-fld" style={{ maxWidth: 130 }} maxLength={7} value={colour}
                                    onChange={(e) => { setColour(e.target.value); if (/^#[0-9a-fA-F]{6}$/.test(e.target.value)) saveColour(e.target.value); }}
                                    aria-label={t('Custom colour')} />
                                <p className="ed-note">{t('Logo, delivery charges and Pixel live in Store Settings.')}</p>
                            </div>
                        )}

                        {tab === 'check' && (
                            <div className="ed-tabpane">
                                {checks.length ? checks.map(checkItem) : (
                                    <div className="ed-ck ok">{Ic.check(16)}<span>{t('No problems')}</span></div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* ------------------------------------------------ canvas */}
                    <div className="ed-canvas">
                        <div className={`ed-frame ${device === 'mobile' ? 'm' : 'd'}`}>
                            {device === 'desktop' && (
                                <div className="ed-chrome"><i /><i /><i /><span className="ed-url">{page.url}</span></div>
                            )}
                            <iframe ref={iframeRef} src={previewUrl} title={t('Preview')} className="ed-ifr" onLoad={onFrameLoad} />
                        </div>
                    </div>

                    {/* ------------------------------------------------ inspector */}
                    <div className={`ed-panel ed-right ${sheet === 'right' ? 'sheet-open' : ''}`} ref={inspectorRef} onBlurCapture={onInspectorBlur}>
                        {selBlock ? (
                            <>
                                <div className="ed-ins-head">
                                    <b>{t(TYPE_LABEL[selBlock.type] || selBlock.type)}</b>
                                    {LOCKED.includes(selBlock.type) && <span className="pill green">{t('Locked')}</span>}
                                    <span className="ed-spacer" />
                                    <button type="button" className="ed-ib sm" disabled={selIdx <= 0} onClick={() => moveBlock(selBlock.id, -1)} aria-label={t('Move up')}>↑</button>
                                    <button type="button" className="ed-ib sm" disabled={selIdx >= blocks.length - 1} onClick={() => moveBlock(selBlock.id, 1)} aria-label={t('Move down')}>↓</button>
                                </div>
                                {LOCKED.includes(selBlock.type) && (
                                    <div className="ed-locknote">{Ic.lock(14)}<span>{t('This section always shows - its prices and fields come from your shop.')}</span></div>
                                )}
                                {inspectorForm(selBlock)}
                                {HIDEABLE.includes(selBlock.type) && (
                                    <div className="ed-swrow">
                                        <span>{t('Show on page')}</span>
                                        <button type="button" role="switch" aria-checked={!selBlock.props?.hidden} aria-label={t('Show on page')}
                                            className={`switch ${!selBlock.props?.hidden ? 'on' : ''}`}
                                            onClick={() => toggleHidden(selBlock.id)} />
                                    </div>
                                )}
                                {HIDEABLE.includes(selBlock.type) && (
                                    <button type="button" className="btn ed-rm" onClick={() => removeBlock(selBlock.id)}>{Ic.trash(15)} {t('Remove')}</button>
                                )}
                            </>
                        ) : (
                            <p className="ed-note" style={{ padding: 6 }}>{t('Choose a section')}</p>
                        )}
                    </div>
                </div>

                {/* ------------------------------------------------ status bar */}
                <div className="ed-status">
                    <span>{blocks.length} {t('Sections')} · {checksText} · {t('Prices and stock come live from Inventory')}</span>
                </div>

                {/* ------------------------------------------------ mobile tab bar */}
                <div className="ed-bottombar">
                    <button type="button" onClick={() => openSheet('sections')}>{t('Sections')}</button>
                    <button type="button" onClick={() => openSheet('design')}>{t('Design')}</button>
                    <button type="button" onClick={() => openSheet('check')}>{t('Checks')}{checks.length > 0 && <span className="ed-bdg">{checks.length}</span>}</button>
                    <button type="button" onClick={() => openSheet('edit')}>{t('Edit page')}</button>
                </div>

                {/* ------------------------------------------------ publish dialog */}
                {dlg === 'publish' && (
                    <div className="ed-mo" onClick={(e) => { if (e.target === e.currentTarget) setDlg(''); }}>
                        <div className="ed-md card">
                            <h3 style={{ marginTop: 0 }}>{isLive ? t('Publish these changes?') : t('Publish this page?')}</h3>
                            {firstPublish ? (
                                <p className="ed-diffline">{t('First publish - the whole page goes live.')}</p>
                            ) : (
                                <div className="ed-diff">
                                    {diff.added.length > 0 && <p><b>{t('Added sections')}:</b> {diff.added.map((ty) => t(TYPE_LABEL[ty])).join(', ')}</p>}
                                    {diff.edited.length > 0 && <p><b>{t('Edited sections')}:</b> {diff.edited.map((ty) => t(TYPE_LABEL[ty])).join(', ')}</p>}
                                    {diff.removed.length > 0 && <p><b>{t('Removed sections')}:</b> {diff.removed.map((ty) => t(TYPE_LABEL[ty])).join(', ')}</p>}
                                    {diff.reordered && <p><b>{t('Sections reordered')}</b></p>}
                                    {!hasDiff && <p className="ed-note">{t('No problems')}</p>}
                                </div>
                            )}
                            <div style={{ marginTop: 10 }}>
                                {checks.length ? checks.map(checkItem) : <div className="ed-ck ok">{Ic.check(16)}<span>{t('No problems')}</span></div>}
                            </div>
                            {errorCount > 0 && <p className="ed-errnote">{t('Fix the problems above to publish.')}</p>}
                            <div className="ed-ac">
                                <button type="button" className="btn" onClick={() => setDlg('')}>{t('Cancel')}</button>
                                <button type="button" className="btn pri" disabled={errorCount > 0} onClick={confirmPublish}>{isLive ? t('Publish changes') : t('Publish')}</button>
                            </div>
                        </div>
                    </div>
                )}

                {/* ------------------------------------------------ versions dialog */}
                {dlg === 'versions' && (
                    <div className="ed-mo" onClick={(e) => { if (e.target === e.currentTarget) setDlg(''); }}>
                        <div className="ed-md card">
                            <h3 style={{ marginTop: 0 }}>{t('Versions')}</h3>
                            {(page.versions || []).length ? (page.versions || []).map((v) => (
                                <div key={v.id} className="ed-vr">
                                    <span>{v.at}</span>
                                    <button type="button" className="btn" onClick={() => restore(v)}>{t('Bring into draft')}</button>
                                </div>
                            )) : <p className="ed-note">{t('No versions yet - publish once and earlier versions will appear here.')}</p>}
                            {isLive && (
                                <div style={{ marginTop: 14, borderTop: '1px solid var(--line)', paddingTop: 12 }}>
                                    <button type="button" className="btn" onClick={unpublish}>{t('Unpublish page')}</button>
                                    <p className="ed-note">{t('Your draft is kept.')}</p>
                                </div>
                            )}
                            <div className="ed-ac">
                                <button type="button" className="btn" onClick={() => setDlg('')}>{t('Cancel')}</button>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <style>{`
.ed-wrap{position:relative}
.ed-top{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:14px}
.ed-name{font-size:19px;margin:0}
.ed-spacer{flex:1}
.ed-seg{display:flex;background:var(--chip);border-radius:20px;padding:3px;gap:2px}
.ed-seg button{display:flex;align-items:center;gap:6px;border-radius:16px;padding:5px 12px;font-size:12.5px;font-weight:600;color:var(--mut)}
.ed-seg button.on{background:var(--acc);color:#fff}
.ed-ib{min-width:36px;height:36px;border:1px solid var(--line);background:#fff;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-size:16px}
.ed-ib:disabled{opacity:.4;cursor:default}
.ed-ib.sm{min-width:30px;height:30px;font-size:14px}
.ed-save{font-size:12.5px;color:var(--mut);white-space:nowrap;min-width:86px;text-align:right}
.ed-retry{color:var(--bad);font-weight:700;font-size:12.5px;text-decoration:underline}
.ed-grid{display:grid;grid-template-columns:276px minmax(0,1fr) 320px;gap:14px;align-items:start}
.ed-panel{background:var(--card);border:1px solid var(--line);border-radius:var(--rad);padding:14px;position:sticky;top:80px;max-height:calc(100vh - 110px);overflow-y:auto}
.ed-tabs{display:flex;border-bottom:1px solid var(--line);margin:-2px -4px 12px}
.ed-tabs button{flex:1;height:40px;color:var(--mut);font-weight:600;border-bottom:2px solid transparent;display:flex;align-items:center;justify-content:center;gap:6px}
.ed-tabs button.on{color:var(--acc);border-color:var(--acc)}
.ed-bdg{background:#fdf0dc;color:var(--warn);border-radius:99px;padding:0 7px;font-size:11px;font-weight:700;line-height:18px}
.ed-bdg.e{background:#fdeaea;color:var(--bad)}
.ed-row{display:flex;align-items:center;gap:6px;padding:7px 8px;border:1px solid var(--line);border-radius:10px;margin-bottom:6px;cursor:pointer;background:#fff;min-height:42px}
.ed-row.sel{border-color:var(--acc);background:var(--chip)}
.ed-row.off{opacity:.55}
.ed-row.ed-dragging{box-shadow:0 8px 20px rgba(34,22,49,.25);position:relative;z-index:3}
.ed-grip{width:20px;display:flex;align-items:center;justify-content:center;color:var(--mut);cursor:grab;touch-action:none;flex-shrink:0}
.ed-row-nm{flex:1;font-weight:500;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ed-lock{color:var(--mut);display:flex}
.ed-eye{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:7px;color:var(--mut)}
.ed-eye:hover{background:#fff}
.ed-mvs{display:flex;gap:2px}
.ed-mvs button{width:28px;height:28px;border-radius:6px;font-size:14px}
.ed-mvs button:disabled{opacity:.35}
.ed-add{width:100%;height:40px;border:1.5px dashed #d9cde4;background:none;border-radius:10px;color:var(--acc);font-weight:600;margin-top:2px}
.ed-pop{border:1px solid var(--line);border-radius:10px;padding:6px;margin-top:8px;background:#fbf9fd}
.ed-pop button{display:block;width:100%;text-align:left;padding:9px 10px;border-radius:7px;font-weight:500}
.ed-pop button:hover{background:var(--chip)}
.ed-note{color:var(--mut);font-size:13px;line-height:1.5}
.ed-ok{color:var(--ok);font-size:12.5px;font-weight:600}
.ed-swatches{display:flex;gap:9px;flex-wrap:wrap;margin:10px 0}
.ed-swatches>button{width:34px;height:34px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 1px var(--line);cursor:pointer;padding:0}
.ed-swatches>button.on{box-shadow:0 0 0 2px var(--acc)}
.ed-colorpick{width:38px;height:38px;border:1px solid var(--line);border-radius:50%;background:#fff;padding:3px;cursor:pointer}
.ed-ck{display:flex;gap:8px;align-items:flex-start;width:100%;text-align:left;padding:9px 10px;border-radius:9px;margin-bottom:6px;font-size:13.5px}
.ed-ck span{flex:1}
.ed-ck small{display:block;color:inherit;opacity:.75;font-size:12px}
.ed-ck.w{background:#fdf0dc;color:#7a5200}
.ed-ck.e{background:#fdeaea;color:#8b1e1e}
.ed-ck.ok{background:#e2f4ec;color:var(--ok);cursor:default}
.ed-canvas{min-width:0}
.ed-frame{background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden;box-shadow:0 10px 30px rgba(34,22,49,.08);margin:0 auto}
.ed-frame.d{width:100%}
.ed-frame.m{width:390px;max-width:100%;border-radius:26px;border:7px solid #221631}
.ed-chrome{display:flex;align-items:center;gap:6px;padding:9px 12px;background:#f4effa;border-bottom:1px solid var(--line)}
.ed-chrome i{width:10px;height:10px;border-radius:50%;background:#d9cde4}
.ed-url{margin-left:8px;flex:1;background:#fff;border-radius:7px;padding:4px 12px;font-size:12.5px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ed-ifr{display:block;width:100%;height:720px;border:0;background:#fff}
.ed-ins-head{display:flex;align-items:center;gap:8px;padding-bottom:10px;border-bottom:1px solid var(--line);margin-bottom:6px}
.ed-locknote{display:flex;gap:8px;align-items:flex-start;background:#e2f4ec;color:var(--ok);border-radius:9px;padding:9px 11px;font-size:13px;margin:8px 0 4px}
.ed-fg{padding-top:12px}
.ed-fl{display:flex;justify-content:space-between;align-items:baseline;font-weight:600;font-size:13.5px;margin-bottom:5px}
.ed-cnt{font-weight:400;color:var(--mut);font-size:12px}
.ed-fld{display:block;width:100%;height:40px;border-radius:8px;font-size:14px;border:1px solid #d9cde4;background:#fff;padding:0 12px;font-family:inherit;color:var(--ink);box-sizing:border-box}
.ed-ta{height:76px;padding:9px 12px;resize:vertical}
.ed-thumb{max-width:100%;max-height:96px;border-radius:8px;display:block;margin-bottom:8px;border:1px solid var(--line);object-fit:cover}
.ed-pick{display:grid;gap:2px;max-height:230px;overflow-y:auto}
.ed-pk{display:flex;align-items:center;gap:8px;padding:7px 4px;border-bottom:1px solid var(--line);font-size:14px;cursor:pointer}
.ed-pk input{width:17px;height:17px;flex-shrink:0}
.ed-pk small{color:var(--mut);white-space:nowrap}
.ed-qa{border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:10px;background:#fbf9fd}
.ed-qa-rm{margin-top:8px;color:#b91c1c;border-color:#f3c2bf}
.ed-swrow{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 0 4px;border-top:1px solid var(--line);margin-top:14px;font-weight:500;font-size:14px}
.ed-rm{margin-top:12px;color:#b91c1c;border-color:#f3c2bf}
.ed-status{margin-top:12px;font-size:12.5px;color:var(--mut)}
.ed-bottombar{display:none}
.ed-mo{position:fixed;inset:0;z-index:80;background:rgba(20,10,30,.5);display:flex;align-items:center;justify-content:center;padding:16px}
.ed-md{width:min(520px,100%);max-height:88vh;overflow-y:auto}
.ed-diff p{margin:4px 0;font-size:14px}
.ed-diffline{font-size:14.5px}
.ed-vr{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--line);font-size:14px}
.ed-ac{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}
.ed-errnote{color:var(--bad);font-size:13.5px;font-weight:600}
@media (max-width:1100px){
  .ed-grid{grid-template-columns:minmax(0,1fr)}
  .ed-wrap{padding-bottom:70px}
  .ed-panel{position:fixed;left:0;right:0;bottom:56px;top:auto;max-height:72vh;border-radius:16px 16px 0 0;box-shadow:0 -12px 40px rgba(34,22,49,.25);transform:translateY(130%);transition:transform .22s;z-index:60}
  .ed-panel.sheet-open{transform:none}
  .ed-bottombar{display:flex;position:fixed;left:0;right:0;bottom:0;height:56px;background:#fff;border-top:1px solid var(--line);z-index:61;gap:6px;padding:6px 10px}
  .ed-bottombar button{flex:1;border:1px solid var(--line);border-radius:10px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;background:#fff}
  .ed-row{min-height:46px}
  .ed-ifr{height:600px}
  .ed-seg-t{display:none}
  .ed-save{display:none}
}
@media (max-width:700px){
  .ed-fld{height:46px;font-size:16px}
  .ed-ta{height:88px}
  .ed-ib{min-width:42px;height:42px}
  .ed-frame.m,.ed-frame.d{width:100%;border:0;border-radius:0;box-shadow:none}
  .ed-chrome{display:none}
  .ed-ifr{height:70vh}
}
`}</style>
        </AppLayout>
    );
}
