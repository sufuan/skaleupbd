import { Head, Link } from '@inertiajs/react';

// Public landing page for Scalenow Bd. Professional front page for the
// seller platform (header, hero, features, how it works, CTA, footer).
// The footer carries the legal business name and address verbatim —
// required for Meta Business Verification - so those strings are
// hardcoded in Latin script, NOT passed through t().
const ACCENT = '#411550';

function Icon({ children }) {
    return (
        <svg
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {children}
        </svg>
    );
}

const FEATURES = [
    {
        title: 'Orders & Delivery',
        text: 'Take orders, confirm them and book couriers like Pathao, Steadfast and RedX without leaving your dashboard.',
        icon: (
            <>
                <rect x="1" y="4" width="14" height="12" rx="1" />
                <path d="M15 9h4l3 3v4h-7V9z" />
                <circle cx="6" cy="18.5" r="1.8" />
                <circle cx="18" cy="18.5" r="1.8" />
            </>
        ),
    },
    {
        title: 'One Inbox',
        text: 'Facebook Messenger and WhatsApp messages land in a single inbox, so no customer question is ever missed.',
        icon: (
            <>
                <polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
                <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
            </>
        ),
    },
    {
        title: 'AI Auto-Reply',
        text: 'Common questions about price, delivery and stock are answered automatically, and your team can step in any time.',
        icon: <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />,
    },
    {
        title: 'Inventory',
        text: 'Keep track of products and stock across your shop, with low-stock warnings before you run out.',
        icon: (
            <>
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                <polyline points="3.29 7 12 12 20.71 7" />
                <line x1="12" y1="22" x2="12" y2="12" />
            </>
        ),
    },
    {
        title: 'SMS Marketing',
        text: 'Send order updates and offers to your customers by SMS, straight from the same dashboard.',
        icon: (
            <>
                <rect x="5" y="2" width="14" height="20" rx="2" />
                <line x1="12" y1="18" x2="12.01" y2="18" />
            </>
        ),
    },
    {
        title: 'Reports',
        text: 'See your sales, courier performance and profit in clear reports, so you always know how the shop is doing.',
        icon: (
            <>
                <line x1="18" y1="20" x2="18" y2="10" />
                <line x1="12" y1="20" x2="12" y2="4" />
                <line x1="6" y1="20" x2="6" y2="14" />
            </>
        ),
    },
];

const STEPS = [
    {
        title: 'Create your shop account',
        text: 'Sign up in a few minutes and your shop dashboard is ready.',
    },
    {
        title: 'Connect your Facebook Page',
        text: 'Link the Page and WhatsApp number your customers already use.',
    },
    {
        title: 'Orders and messages in one place',
        text: 'Sell, reply and deliver from one simple dashboard.',
    },
];

const CSS = `
.lp-root { background:#faf8fb; color:#241a2b; min-height:100vh; display:flex; flex-direction:column; }
.lp-wrap { width:100%; max-width:1120px; margin:0 auto; padding:0 20px; box-sizing:border-box; }
.lp-header { position:sticky; top:0; z-index:20; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid #e6ddea; }
.lp-header-in { display:flex; align-items:center; gap:18px; height:66px; }
.lp-brand { display:flex; align-items:center; gap:10px; text-decoration:none; margin-right:auto; }
.lp-mark { width:38px; height:38px; border-radius:10px; background:${ACCENT}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:19px; font-weight:700; flex:none; }
.lp-brand-name { font-size:19px; font-weight:700; color:${ACCENT}; white-space:nowrap; }
.lp-nav { display:flex; gap:26px; }
.lp-nav a { color:#4c4257; text-decoration:none; font-size:15px; font-weight:500; }
.lp-nav a:hover { color:${ACCENT}; }
.lp-login { color:${ACCENT}; text-decoration:none; font-size:15px; font-weight:600; white-space:nowrap; }
.lp-btn { display:inline-block; background:${ACCENT}; color:#fff; padding:10px 20px; border-radius:9px; font-size:15px; font-weight:600; text-decoration:none; white-space:nowrap; border:1px solid ${ACCENT}; }
.lp-btn:hover { background:#341040; }
.lp-btn-lg { padding:13px 30px; font-size:16px; border-radius:10px; }
.lp-btn-outline { background:transparent; color:${ACCENT}; }
.lp-btn-outline:hover { background:#f0e8f4; }
.lp-btn-white { background:#fff; color:${ACCENT}; border-color:#fff; }
.lp-btn-white:hover { background:#f0e8f4; }
.lp-hero { background:linear-gradient(180deg,#f3ecf7 0%,#faf8fb 100%); text-align:center; padding:84px 0 76px; }
.lp-hero h1 { font-size:44px; line-height:1.15; font-weight:800; color:#241a2b; margin:0 auto 18px; max-width:760px; }
.lp-hero h1 span { color:${ACCENT}; }
.lp-hero p { font-size:18px; line-height:1.65; color:#5d5266; max-width:640px; margin:0 auto 32px; }
.lp-hero-btns { display:flex; gap:14px; justify-content:center; flex-wrap:wrap; }
.lp-chips { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:38px; }
.lp-chip { background:#fff; border:1px solid #e6ddea; color:#4c4257; font-size:14px; font-weight:500; padding:7px 14px; border-radius:999px; }
.lp-section { padding:72px 0; scroll-margin-top:80px; }
.lp-section-alt { background:#fff; }
.lp-h2 { font-size:30px; font-weight:800; text-align:center; margin:0 0 10px; color:#241a2b; }
.lp-sub { text-align:center; color:#5d5266; font-size:16px; line-height:1.6; max-width:560px; margin:0 auto 44px; }
.lp-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
.lp-card { background:#fff; border:1px solid #e6ddea; border-radius:14px; padding:26px 24px; }
.lp-section-alt .lp-card { background:#faf8fb; }
.lp-card-icon { width:48px; height:48px; border-radius:12px; background:#f0e8f4; color:${ACCENT}; display:flex; align-items:center; justify-content:center; margin-bottom:16px; }
.lp-card h3 { font-size:17px; font-weight:700; margin:0 0 8px; color:#241a2b; }
.lp-card p { font-size:15px; line-height:1.6; color:#5d5266; margin:0; }
.lp-steps { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
.lp-step { text-align:center; padding:10px 14px; }
.lp-step-num { width:46px; height:46px; border-radius:50%; background:${ACCENT}; color:#fff; font-size:19px; font-weight:700; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; }
.lp-step h3 { font-size:17px; font-weight:700; margin:0 0 8px; }
.lp-step p { font-size:15px; line-height:1.6; color:#5d5266; margin:0; }
.lp-cta { background:${ACCENT}; padding:64px 0; text-align:center; }
.lp-cta h2 { color:#fff; font-size:30px; font-weight:800; margin:0 0 12px; }
.lp-cta p { color:#e4d5ec; font-size:16px; line-height:1.6; margin:0 auto 28px; max-width:520px; }
.lp-footer { background:#fff; border-top:1px solid #e6ddea; padding:44px 0 30px; margin-top:auto; }
.lp-footer-top { display:flex; justify-content:space-between; align-items:flex-start; gap:24px; flex-wrap:wrap; margin-bottom:28px; }
.lp-footer-links { display:flex; gap:22px; }
.lp-footer-links a { color:#4c4257; text-decoration:none; font-size:15px; font-weight:500; }
.lp-footer-links a:hover { color:${ACCENT}; }
.lp-legal { border-top:1px solid #efe9f2; padding-top:22px; font-size:14px; line-height:1.7; color:#4c4257; }
.lp-legal-name { font-weight:700; color:${ACCENT}; font-size:15px; }
.lp-copy { margin-top:10px; color:#8a7f96; }
@media (max-width: 900px) {
  .lp-grid { grid-template-columns:repeat(2,1fr); }
  .lp-steps { grid-template-columns:1fr; max-width:440px; margin:0 auto; }
  .lp-hero h1 { font-size:36px; }
}
@media (max-width: 600px) {
  .lp-nav { display:none; }
  .lp-header-in { gap:10px; }
  .lp-brand { gap:8px; }
  .lp-mark { width:32px; height:32px; font-size:16px; border-radius:8px; }
  .lp-brand-name { font-size:16px; }
  .lp-login { font-size:14px; }
  .lp-grid { grid-template-columns:1fr; }
  .lp-hero { padding:56px 0 52px; }
  .lp-hero h1 { font-size:30px; }
  .lp-hero p { font-size:16px; }
  .lp-section { padding:52px 0; }
  .lp-h2, .lp-cta h2 { font-size:25px; }
  .lp-header .lp-btn { padding:8px 12px; font-size:13px; }
}
`;

export default function Landing() {
    return (
        <div className="lp-root">
            <Head title="Scalenow Bd" />
            <style>{CSS}</style>

            <header className="lp-header">
                <div className="lp-wrap lp-header-in">
                    <a className="lp-brand" href="/">
                        <span className="lp-mark">S</span>
                        <span className="lp-brand-name">Scalenow Bd</span>
                    </a>
                    <nav className="lp-nav">
                        <a href="#features">Features</a>
                        <a href="#how">How it works</a>
                        <Link href="/pricing">Pricing</Link>
                        <a href="#contact">Contact</a>
                    </nav>
                    <Link className="lp-login" href="/admin/login">Log in</Link>
                    <Link className="lp-btn" href={route('register')}>Create an account</Link>
                </div>
            </header>

            <section className="lp-hero">
                <div className="lp-wrap">
                    <h1>Run your whole F-commerce business from <span>one dashboard</span></h1>
                    <p>
                        Orders from Facebook and WhatsApp, courier booking, one inbox for every
                        message, and AI replies that answer your customers - everything your
                        online shop needs, in one simple place.
                    </p>
                    <div className="lp-hero-btns">
                        <Link className="lp-btn lp-btn-lg" href={route('register')}>Create an account</Link>
                        <Link className="lp-btn lp-btn-lg lp-btn-outline" href="/admin/login">Log in</Link>
                    </div>
                    <div className="lp-chips">
                        <span className="lp-chip">Facebook Page orders</span>
                        <span className="lp-chip">Messenger &amp; WhatsApp inbox</span>
                        <span className="lp-chip">Courier booking</span>
                        <span className="lp-chip">AI auto-reply</span>
                    </div>
                </div>
            </section>

            <section className="lp-section" id="features">
                <div className="lp-wrap">
                    <h2 className="lp-h2">Everything in one place</h2>
                    <p className="lp-sub">
                        No more jumping between apps and notebooks. Your orders, messages,
                        stock and reports live together in Scalenow Bd.
                    </p>
                    <div className="lp-grid">
                        {FEATURES.map((f) => (
                            <div className="lp-card" key={f.title}>
                                <div className="lp-card-icon"><Icon>{f.icon}</Icon></div>
                                <h3>{f.title}</h3>
                                <p>{f.text}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="lp-section lp-section-alt" id="how">
                <div className="lp-wrap">
                    <h2 className="lp-h2">How it works</h2>
                    <p className="lp-sub">Three simple steps and your shop is running in one place.</p>
                    <div className="lp-steps">
                        {STEPS.map((s, i) => (
                            <div className="lp-step" key={s.title}>
                                <div className="lp-step-num">{i + 1}</div>
                                <h3>{s.title}</h3>
                                <p>{s.text}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="lp-cta">
                <div className="lp-wrap">
                    <h2>Start selling smarter today</h2>
                    <p>Create your free shop account and bring your orders and messages together.</p>
                    <Link className="lp-btn lp-btn-lg lp-btn-white" href={route('register')}>Create an account</Link>
                </div>
            </section>

            <footer className="lp-footer" id="contact">
                <div className="lp-wrap">
                    <div className="lp-footer-top">
                        <a className="lp-brand" href="/">
                            <span className="lp-mark">S</span>
                            <span className="lp-brand-name">Scalenow Bd</span>
                        </a>
                        <div className="lp-footer-links">
                            <Link href="/pricing">Pricing</Link>
                            <Link href="/admin/login">Log in</Link>
                            <Link href={route('register')}>Create an account</Link>
                        </div>
                    </div>
                    <div className="lp-legal">
                        <div className="lp-legal-name">Scalenow Bd</div>
                        <div>Ward No-07, Pekherchar, Barurbat, Post Office: Pekherchar, Narsingdi Sadar, Narsingdi 1600, Bangladesh</div>
                        <div>Phone: +8801410033782</div>
                        <div className="lp-copy">© 2026 Scalenow Bd</div>
                    </div>
                </div>
            </footer>
        </div>
    );
}
