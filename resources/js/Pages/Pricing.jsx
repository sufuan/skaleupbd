import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { t } from '@/lib/i18n';
import { tk } from '@/lib/money';

// M19 - public pricing page. Same visual family as the landing page
// (own pp- styles), but its own layout: pack cards rendered live from
// the point packs, a volume helper, the credit rule in plain words,
// and the legal footer block verbatim (Meta verification).

const ACCENT = '#411550';
const num = (n) => Number(n || 0).toLocaleString('en-IN');

const CSS = `
.pp-root { background:#faf8fb; color:#241a2b; min-height:100vh; display:flex; flex-direction:column; font-family:inherit; }
.pp-wrap { width:100%; max-width:1120px; margin:0 auto; padding:0 20px; box-sizing:border-box; }
.pp-header { position:sticky; top:0; z-index:20; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid #e6ddea; }
.pp-header-in { display:flex; align-items:center; gap:18px; height:66px; }
.pp-brand { display:flex; align-items:center; gap:10px; text-decoration:none; margin-right:auto; }
.pp-mark { width:38px; height:38px; border-radius:10px; background:${ACCENT}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:19px; font-weight:700; flex:none; }
.pp-brand-name { font-size:19px; font-weight:700; color:${ACCENT}; white-space:nowrap; }
.pp-nav { display:flex; gap:26px; }
.pp-nav a { color:#4c4257; text-decoration:none; font-size:15px; font-weight:500; }
.pp-nav a:hover { color:${ACCENT}; }
.pp-login { color:${ACCENT}; text-decoration:none; font-size:15px; font-weight:600; white-space:nowrap; }
.pp-btn { display:inline-block; background:${ACCENT}; color:#fff; padding:10px 20px; border-radius:9px; font-size:15px; font-weight:600; text-decoration:none; white-space:nowrap; border:1px solid ${ACCENT}; }
.pp-btn:hover { background:#341040; }
.pp-hero { background:linear-gradient(180deg,#f3ecf7 0%,#faf8fb 100%); text-align:center; padding:64px 0 30px; }
.pp-hero h1 { font-size:40px; line-height:1.15; font-weight:800; margin:0 auto 14px; max-width:720px; }
.pp-hero h1 span { color:${ACCENT}; }
.pp-hero p { font-size:17px; line-height:1.65; color:#5d5266; max-width:620px; margin:0 auto; }
.pp-section { padding:44px 0; }
.pp-h2 { font-size:26px; font-weight:800; text-align:center; margin:0 0 8px; }
.pp-sub { text-align:center; color:#5d5266; font-size:15.5px; line-height:1.6; max-width:600px; margin:0 auto 34px; }
.pp-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }
.pp-pack { background:#fff; border:1px solid #e6ddea; border-radius:14px; padding:24px 22px; display:flex; flex-direction:column; }
.pp-pack.best { border:2px solid ${ACCENT}; box-shadow:0 10px 30px rgba(65,21,80,.12); }
.pp-pack-name { font-size:16px; font-weight:700; display:flex; align-items:center; gap:8px; }
.pp-best-tag { background:${ACCENT}; color:#fff; font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px; }
.pp-pack-price { font-size:32px; font-weight:800; margin:10px 0 2px; }
.pp-pack-price span { font-size:14px; font-weight:500; color:#5d5266; }
.pp-pack-line { font-size:14.5px; color:#241a2b; margin-top:6px; }
.pp-pack-line b { color:${ACCENT}; }
.pp-pack-note { font-size:13px; color:#5d5266; margin-top:4px; margin-bottom:16px; line-height:1.55; }
.pp-pack-btn { margin-top:auto; display:block; text-align:center; background:${ACCENT}; color:#fff; padding:11px 14px; border-radius:9px; font-size:15px; font-weight:600; text-decoration:none; }
.pp-pack-btn:hover { background:#341040; }
.pp-helper { background:#fff; border:1px solid #e6ddea; border-radius:14px; padding:26px 24px; max-width:720px; margin:0 auto; }
.pp-helper-row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; justify-content:center; }
.pp-helper input { width:170px; padding:11px 14px; border:1px solid #d9cde4; border-radius:9px; font-size:16px; text-align:center; }
.pp-helper-result { margin-top:18px; text-align:center; font-size:16px; line-height:1.65; }
.pp-helper-result b { color:${ACCENT}; }
.pp-faq { max-width:760px; margin:0 auto; display:grid; gap:12px; }
.pp-faq-item { background:#fff; border:1px solid #e6ddea; border-radius:12px; padding:18px 20px; }
.pp-faq-item h3 { font-size:15.5px; font-weight:700; margin:0 0 6px; }
.pp-faq-item p { font-size:14.5px; line-height:1.65; color:#5d5266; margin:0; }
.pp-cta { background:${ACCENT}; padding:56px 0; text-align:center; }
.pp-cta h2 { color:#fff; font-size:28px; font-weight:800; margin:0 0 10px; }
.pp-cta p { color:#e4d5ec; font-size:16px; margin:0 auto 24px; max-width:520px; line-height:1.6; }
.pp-btn-white { background:#fff; color:${ACCENT}; border-color:#fff; }
.pp-btn-white:hover { background:#f0e8f4; }
.pp-footer { background:#fff; border-top:1px solid #e6ddea; padding:44px 0 30px; margin-top:auto; }
.pp-footer-top { display:flex; justify-content:space-between; align-items:flex-start; gap:24px; flex-wrap:wrap; margin-bottom:28px; }
.pp-footer-links { display:flex; gap:22px; }
.pp-footer-links a { color:#4c4257; text-decoration:none; font-size:15px; font-weight:500; }
.pp-footer-links a:hover { color:${ACCENT}; }
.pp-legal { border-top:1px solid #efe9f2; padding-top:22px; font-size:14px; line-height:1.7; color:#4c4257; }
.pp-legal-name { font-weight:700; color:${ACCENT}; font-size:15px; }
.pp-copy { margin-top:10px; color:#8a7f96; }
@media (max-width: 900px) { .pp-grid { grid-template-columns:repeat(2,1fr); } .pp-hero h1 { font-size:34px; } }
@media (max-width: 600px) {
  .pp-nav { display:none; }
  .pp-header-in { gap:10px; }
  .pp-mark { width:32px; height:32px; font-size:16px; border-radius:8px; }
  .pp-brand-name { font-size:16px; }
  .pp-login { font-size:14px; }
  .pp-grid { grid-template-columns:1fr; }
  .pp-hero { padding:48px 0 22px; }
  .pp-hero h1 { font-size:29px; }
  .pp-h2, .pp-cta h2 { font-size:23px; }
  .pp-header .pp-btn { padding:8px 12px; font-size:13px; }
}
`;

function PackCard({ p, best, bestValue, loggedIn }) {
    return (
        <div className={`pp-pack ${best ? 'best' : ''}`}>
            <div className="pp-pack-name">{p.name}{best && <span className="pp-best-tag">{t('Best for you')}</span>}{bestValue && <span className="pp-best-tag">{t('Best value per point')}</span>}</div>
            <div className="pp-pack-price">{tk(p.price_bdt)}<span> {p.validity_type === 'monthly' ? t('/ month') : t('/ year')}</span></div>
            <div className="pp-pack-line"><b>{num(p.credits)}</b> {t('points')}</div>
            <div className="pp-pack-line">{t('Covers about')} <b>{num(p.orders)}</b> {t('orders')}</div>
            <div className="pp-pack-line">{tk(p.per_order)} {t('per order')}</div>
            <div className="pp-pack-note">{p.validity_type === 'monthly'
                ? t('Points reset every 30 days - unused points do not carry over.')
                : t('A wallet: points carry for the full 12 months.')}</div>
            {loggedIn
                ? <Link className="pp-pack-btn" href={`/billing?pack=${p.id}`}>{t('Buy this pack')}</Link>
                : <Link className="pp-pack-btn" href={`/register?pack=${p.id}`}>{t('Create your store with this pack')}</Link>}
        </div>
    );
}

export default function Pricing({ monthly, wallets, pointsPerOrder, onboardingFee }) {
    const loggedIn = !!usePage().props.auth?.user;
    const [orders, setOrders] = useState(300);
    const need = Math.max(0, Number(orders) || 0) * Math.max(1, pointsPerOrder);
    const fit = useMemo(() => monthly.filter((p) => p.credits >= need).sort((a, b) => a.price_bdt - b.price_bdt)[0] || null, [monthly, need]);
    const biggest = useMemo(() => [...monthly].sort((a, b) => b.credits - a.credits)[0] || null, [monthly]);
    const walletFit = useMemo(() => wallets.filter((p) => p.credits >= need * 12).sort((a, b) => a.price_bdt - b.price_bdt)[0] || null, [wallets, need]);
    // "Best value per point" is COMPUTED from the packs' real prices —
    // packs are editable in Settings, so a hardcoded claim would go stale.
    const bestRate = useMemo(() => Math.min(...[...monthly, ...wallets].map((p) => p.price_bdt / Math.max(1, p.credits))), [monthly, wallets]);
    const isBestValue = (p) => Math.abs(p.price_bdt / Math.max(1, p.credits) - bestRate) < 0.001;

    const faqs = [
        { q: t('When is a point used?'), a: t('A point is spent the moment a new order enters your shop - from Facebook, WhatsApp, your website or typed in by hand. Status changes never cost extra points.') },
        { q: t('What if an order is cancelled or returned?'), a: t('The point is not given back. A point pays for the order entering your system, not for its final result - the same simple rule for everyone.') },
        { q: t('Monthly pack or yearly wallet - what is the difference?'), a: t('Monthly pack points reset every 30 days - unused points do not carry over. Yearly wallet points carry for the full 12 months - better if your sales go up and down.') },
        { q: t('What happens when my points finish?'), a: t('Your shop becomes view-only: you can still log in and see everything, but new orders and changes pause until you top up. Nothing is deleted.') },
        { q: t('Is there a free trial?'), a: t('No trial - instead, your first purchase can be refunded within 7 days if the platform is not right for you.') },
        { q: t('What is the onboarding fee?'), a: `${t('A one-time')} ${tk(onboardingFee)} ${t('setup fee, charged only on your very first purchase - never again.')}` },
    ];

    return (
        <div className="pp-root">
            <Head title={`${t('Pricing')} - Scalenow Bd`} />
            <style>{CSS}</style>

            <header className="pp-header">
                <div className="pp-wrap pp-header-in">
                    <a className="pp-brand" href="/">
                        <span className="pp-mark">S</span>
                        <span className="pp-brand-name">Scalenow Bd</span>
                    </a>
                    <nav className="pp-nav">
                        <a href="/#features">Features</a>
                        <a href="/#how">How it works</a>
                        <Link href="/pricing">Pricing</Link>
                        <a href="#contact">Contact</a>
                    </nav>
                    <Link className="pp-login" href="/admin/login">Log in</Link>
                    <Link className="pp-btn" href={route('register')}>Create an account</Link>
                </div>
            </header>

            <section className="pp-hero">
                <div className="pp-wrap">
                    <h1>{t('Simple pricing:')} <span>{t('pay per order')}</span></h1>
                    <p>{t('Buy a pack of points. Every feature is in every pack - packs differ only in points and price. No hidden charges, no per-user fees.')} {t('A one-time onboarding fee of')} {tk(onboardingFee)} {t('applies to your first purchase only.')}</p>
                </div>
            </section>

            <section className="pp-section">
                <div className="pp-wrap">
                    <h2 className="pp-h2">{t('Find your pack')}</h2>
                    <p className="pp-sub">{t('Tell us how many orders you get in a month - we will point at the cheapest pack that covers you.')}</p>
                    <div className="pp-helper">
                        <div className="pp-helper-row">
                            <span style={{ fontSize: 15.5 }}>{t('I get about')}</span>
                            <input type="number" min="0" value={orders} onChange={(e) => setOrders(e.target.value)} aria-label={t('Orders per month')} />
                            <span style={{ fontSize: 15.5 }}>{t('orders per month')}</span>
                        </div>
                        <div className="pp-helper-result">
                            {need === 0 && <>{t('Enter your monthly orders to see a suggestion.')}</>}
                            {need > 0 && fit && <>{t('You need')} <b>{num(need)}</b> {t('points a month.')} <b>{fit.name}</b> {t('covers you for')} <b>{tk(fit.price_bdt)}</b> - {tk(fit.per_order)} {t('per order.')}</>}
                            {need > 0 && !fit && biggest && <>{t('You need')} <b>{num(need)}</b> {t('points a month - more than our biggest monthly pack')} (<b>{biggest.name}</b>, {num(biggest.credits)} {t('points')}). {t('Buy it and top up again mid-month, or talk to us about a bigger wallet.')}</>}
                            {walletFit && <div style={{ marginTop: 6 }}>{t('Or take')} <b>{walletFit.name}</b> - {tk(walletFit.price_bdt)} {t('once, and the points last the whole year.')}</div>}
                        </div>
                    </div>
                </div>
            </section>

            <section className="pp-section" style={{ paddingTop: 6 }}>
                <div className="pp-wrap">
                    <h2 className="pp-h2">{t('Monthly packs')}</h2>
                    <p className="pp-sub">{t('Points reset every 30 days.')}</p>
                    <div className="pp-grid">
                        {monthly.map((p) => <PackCard key={p.id} p={p} best={fit && fit.id === p.id} bestValue={isBestValue(p)} loggedIn={loggedIn} />)}
                    </div>
                    {wallets.length > 0 && (
                        <>
                            <h2 className="pp-h2" style={{ marginTop: 48 }}>{t('Yearly wallets')}</h2>
                            <p className="pp-sub">{t('One payment - points stay valid for 12 months.')}</p>
                            <div className="pp-grid">
                                {wallets.map((p) => <PackCard key={p.id} p={p} best={!fit && walletFit && walletFit.id === p.id} bestValue={isBestValue(p)} loggedIn={loggedIn} />)}
                            </div>
                        </>
                    )}
                    <p className="pp-sub" style={{ marginTop: 30, marginBottom: 0 }}>{t('Every order currently costs')} {num(pointsPerOrder)} {pointsPerOrder === 1 ? t('point') : t('points')}. {t('A one-time onboarding fee of')} {tk(onboardingFee)} {t('applies to your first purchase only.')}</p>
                </div>
            </section>

            <section className="pp-section" style={{ paddingTop: 6 }}>
                <div className="pp-wrap">
                    <h2 className="pp-h2">{t('Questions, answered plainly')}</h2>
                    <p className="pp-sub">{t('The rules are the same for every seller.')}</p>
                    <div className="pp-faq">
                        {faqs.map((f) => (
                            <div key={f.q} className="pp-faq-item"><h3>{f.q}</h3><p>{f.a}</p></div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="pp-cta">
                <div className="pp-wrap">
                    <h2>{t('Start with any pack - upgrade any time')}</h2>
                    <p>{t('Create your shop, buy points when you are ready, and take your first order today.')}</p>
                    <Link className="pp-btn pp-btn-white" href={route('register')}>Create an account</Link>
                </div>
            </section>

            <footer className="pp-footer" id="contact">
                <div className="pp-wrap">
                    <div className="pp-footer-top">
                        <a className="pp-brand" href="/">
                            <span className="pp-mark">S</span>
                            <span className="pp-brand-name">Scalenow Bd</span>
                        </a>
                        <div className="pp-footer-links">
                            <Link href="/pricing">Pricing</Link>
                            <Link href="/admin/login">Log in</Link>
                            <Link href={route('register')}>Create an account</Link>
                        </div>
                    </div>
                    <div className="pp-legal">
                        <div className="pp-legal-name">Scalenow Bd</div>
                        <div>Ward No-07, Pekherchar, Barurbat, Post Office: Pekherchar, Narsingdi Sadar, Narsingdi 1600, Bangladesh</div>
                        <div>Phone: +8801410033782</div>
                        <div className="pp-copy">© 2026 Scalenow Bd</div>
                    </div>
                </div>
            </footer>
        </div>
    );
}
