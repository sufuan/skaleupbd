import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { tk } from '@/lib/money';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const Tick = () => (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
);

// Two-step registration (owner order): step 1 is a dedicated
// choose-a-pack screen, step 2 is the account form. The server rules
// are unchanged - a valid active pack_id must ride along with the
// form POST or no account is created.
function Stepper({ current }) {
    return (
        <div className="reg-steps">
            <div className={`reg-step ${current === 1 ? 'on' : 'done'}`}>
                <span className="reg-dot">{current === 1 ? '1' : <Tick />}</span>
                <span>{t('Choose a pack')}<span className="reg-step-of">{t('Step 1 of 2')}</span></span>
            </div>
            <div className={`reg-step-line ${current === 2 ? 'done' : ''}`} />
            <div className={`reg-step ${current === 2 ? 'on' : ''}`}>
                <span className="reg-dot">2</span>
                <span>{t('Your details')}<span className="reg-step-of">{t('Step 2 of 2')}</span></span>
            </div>
        </div>
    );
}

export default function Register({ pack, packs = [], onboardingFee, verifyEnabled = false, verifyChannel = 'email' }) {
    const mobileRequired = verifyEnabled && verifyChannel !== 'email';
    const pageErrors = usePage().props.errors || {};
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        shop_name: '',
        email: '',
        mobile: '',
        password: '',
        password_confirmation: '',
        pack_id: pack?.id || '',
    });
    // Arrival from /pricing (?pack=, already validated server-side)
    // starts on step 2 with the pack chosen. A bounced submit (server
    // validation errors in the page props) also lands on step 2, so the
    // seller fixes the form instead of picking a pack all over again.
    const [step, setStep] = useState(() => (pack || Object.keys(pageErrors).length > 0 ? 2 : 1));
    const num = (n) => Number(n || 0).toLocaleString('en-IN');
    const fee = Number(onboardingFee || 0);
    const noPacks = packs.length === 0;
    const chosen = packs.find((p) => p.id === Number(data.pack_id)) || pack || null;

    const submit = (e) => {
        e.preventDefault();
        post(route('register'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    const pickPack = (p) => setData('pack_id', p.id);

    return (
        <GuestLayout wide={step === 1 && !noPacks}>
            <Head title={t('Create an account')} />
            <h1 className="auth-title">{t('Create an account')}</h1>
            <p className="auth-sub">{t('Start running your shop from one dashboard')}</p>
            {noPacks ? (
                <div style={{ border: '1px solid #E7DEF0', background: '#F6F1FA', borderRadius: 10, padding: '14px' }}>
                    <div style={{ fontSize: 15.5, fontWeight: 700 }}>{t('Registration is not open right now.')}</div>
                    <div style={{ fontSize: 13.5, color: '#5D5266', marginTop: 4 }}>{t('Please check back later, or contact us if you need help.')}</div>
                </div>
            ) : (
                <>
                    <Stepper current={step} />
                    {step === 1 && (
                        <div>
                            <h2 className="reg-sec-title">{t('Choose your pack')}</h2>
                            <p className="reg-sec-sub">{t('Pick a pack to begin. You pay only after your account is created.')}</p>
                            <div className="reg-grid" role="radiogroup" aria-label={t('Choose a pack')}>
                                {packs.map((p) => {
                                    const selected = Number(data.pack_id) === p.id;
                                    return (
                                        <div
                                            key={p.id}
                                            className={`reg-pack ${selected ? 'sel' : ''}`}
                                            role="radio"
                                            aria-checked={selected}
                                            tabIndex={0}
                                            onClick={() => pickPack(p)}
                                            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickPack(p); } }}
                                        >
                                            <div className="reg-pack-name">
                                                <span>{p.name}</span>
                                                <span className="reg-radio"><i /></span>
                                            </div>
                                            <div className="reg-pack-price">{tk(p.price_bdt)}<span> {p.validity_type === 'monthly' ? t('/ month') : t('/ year')}</span></div>
                                            <div className="reg-pack-line"><b>{num(p.credits)}</b> {t('points')}</div>
                                            <div className="reg-pack-note">{p.validity_type === 'monthly'
                                                ? t('Points reset every 30 days - unused points do not carry over.')
                                                : t('A wallet: points carry for the full 12 months.')}</div>
                                            <div className="reg-pack-total">
                                                {t('Total to pay after signup:')} <b>{tk(Number(p.price_bdt) + fee)}</b>
                                                {fee > 0 && <><br />({t('pack')} {tk(p.price_bdt)} + {t('one-time onboarding fee')} {tk(fee)})</>}
                                            </div>
                                            <span className="reg-pack-btn">{selected ? t('Selected') : t('Select')}</span>
                                        </div>
                                    );
                                })}
                            </div>
                            {errors.pack_id && <div className="auth-err">{errors.pack_id}</div>}
                            <div className="reg-actions">
                                <button
                                    type="button"
                                    className="btn pri"
                                    style={{ width: '100%', justifyContent: 'center', padding: '12px 16px', fontSize: 15 }}
                                    disabled={!data.pack_id}
                                    onClick={() => setStep(2)}
                                >
                                    {t('Continue')}
                                </button>
                            </div>
                        </div>
                    )}
                    {step === 2 && (
                        <div>
                            {chosen && (
                                <div className="reg-summary">
                                    <div>
                                        <div style={{ fontSize: 12, letterSpacing: '.05em', color: '#7A6A8A', fontWeight: 700 }}>{t('YOUR CHOSEN PACK')}</div>
                                        <div style={{ fontSize: 15.5, fontWeight: 700, marginTop: 3 }}>{chosen.name} - {tk(chosen.price_bdt)}</div>
                                        <div style={{ fontSize: 13, color: '#5D5266', marginTop: 2 }}>
                                            {num(chosen.credits)} {t('points')} · {chosen.validity_type === 'monthly' ? t('Monthly') : t('Yearly wallet')}
                                        </div>
                                        <div style={{ fontSize: 13.5, marginTop: 7 }}>
                                            {t('Total to pay after signup:')} <b>{tk(Number(chosen.price_bdt) + fee)}</b>
                                            {fee > 0 && <span style={{ color: '#5D5266' }}> ({t('pack')} {tk(chosen.price_bdt)} + {t('one-time onboarding fee')} {tk(fee)})</span>}
                                        </div>
                                    </div>
                                    <button type="button" className="auth-link reg-change" onClick={() => setStep(1)}>{t('Change pack')}</button>
                                </div>
                            )}
                            {errors.pack_id && <div className="auth-err" style={{ marginBottom: 12 }}>{errors.pack_id}</div>}
                            <form onSubmit={submit}>
                                <div className="frow">
                                    <label className="fl" htmlFor="name">{t('Name')}</label>
                                    <input id="name" type="text" name="name" value={data.name} className="inp" autoComplete="name" autoFocus onChange={(e) => setData('name', e.target.value)} />
                                    {errors.name && <div className="auth-err">{errors.name}</div>}
                                </div>
                                <div className="frow">
                                    <label className="fl" htmlFor="shop_name">{t('Shop name')}</label>
                                    <input id="shop_name" type="text" name="shop_name" value={data.shop_name} className="inp" autoComplete="organization" onChange={(e) => setData('shop_name', e.target.value)} />
                                    {errors.shop_name && <div className="auth-err">{errors.shop_name}</div>}
                                </div>
                                <div className="frow">
                                    <label className="fl" htmlFor="email">{t('Email')}</label>
                                    <input id="email" type="email" name="email" value={data.email} className="inp" autoComplete="username" onChange={(e) => setData('email', e.target.value)} />
                                    {errors.email && <div className="auth-err">{errors.email}</div>}
                                </div>
                                <div className="frow">
                                    <label className="fl" htmlFor="mobile">{t('Mobile number')}{mobileRequired ? ' *' : ''}</label>
                                    <input id="mobile" type="tel" name="mobile" value={data.mobile} className="inp" autoComplete="tel" inputMode="tel" placeholder="01XXXXXXXXX" onChange={(e) => setData('mobile', e.target.value)} />
                                    {verifyEnabled && <div className="sm mut" style={{ marginTop: 4 }}>{t('After signup we send a short code to check it is really you.')}</div>}
                                    {errors.mobile && <div className="auth-err">{errors.mobile}</div>}
                                </div>
                                <div className="frow">
                                    <label className="fl" htmlFor="password">{t('Password')}</label>
                                    <input id="password" type="password" name="password" value={data.password} className="inp" autoComplete="new-password" onChange={(e) => setData('password', e.target.value)} />
                                    {errors.password && <div className="auth-err">{errors.password}</div>}
                                </div>
                                <div className="frow">
                                    <label className="fl" htmlFor="password_confirmation">{t('Confirm password')}</label>
                                    <input id="password_confirmation" type="password" name="password_confirmation" value={data.password_confirmation} className="inp" autoComplete="new-password" onChange={(e) => setData('password_confirmation', e.target.value)} />
                                    {errors.password_confirmation && <div className="auth-err">{errors.password_confirmation}</div>}
                                </div>
                                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '11px 16px', marginTop: 6 }} disabled={processing || !data.pack_id || !data.name.trim() || !data.shop_name.trim() || !data.email.trim() || (mobileRequired && !data.mobile.trim()) || !data.password || !data.password_confirmation}>
                                    {t('Create an account')}
                                </button>
                                <div className="sm mut" style={{ marginTop: 10 }}>{t('Pay from your Points & Billing page once your shop is created.')}</div>
                            </form>
                        </div>
                    )}
                </>
            )}
            <p className="sm mut" style={{ textAlign: 'center', marginTop: 18 }}>
                {t('Already have an account?')} <Link href={route('login')} className="auth-link">{t('Log in')}</Link>
            </p>
        </GuestLayout>
    );
}
