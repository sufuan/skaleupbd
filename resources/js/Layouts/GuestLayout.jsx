import LanguageSwitcher from '@/Components/LanguageSwitcher';
import { t } from '@/lib/i18n';

const Tick = () => (
    <span className="tick">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
    </span>
);

export default function GuestLayout({ children }) {
    return (
        <div className="auth-wrap">
            <div className="auth-brand">
                <div className="brand-row">
                    <div className="brand-mark">S</div>
                    <div>
                        <div className="brand-name">SkaleUp BD</div>
                        <div className="brand-tag">{t('F-commerce seller platform')}</div>
                    </div>
                </div>
                <h1>{t('Orders, delivery and customer chats - one dashboard')}</h1>
                <p className="lead">{t('Run your whole shop from one place: website, Facebook, WhatsApp and phone orders together, with AI help for replies and bargaining.')}</p>
                <div className="brand-points">
                    <div className="brand-point"><Tick />{t('All channels in one inbox - Messenger, WhatsApp, website')}</div>
                    <div className="brand-point"><Tick />{t('Courier booking and delivery tracking built in')}</div>
                    <div className="brand-point"><Tick />{t('AI replies and price bargaining inside your rules')}</div>
                </div>
                <div className="brand-foot">SkaleUp BD · {t('Made for sellers in Bangladesh')}</div>
            </div>
            <div className="auth-side">
                <div className="auth-top"><LanguageSwitcher /></div>
                <div className="auth-box">{children}</div>
            </div>
        </div>
    );
}
