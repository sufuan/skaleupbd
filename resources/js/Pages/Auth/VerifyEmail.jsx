import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { Head, Link, useForm } from '@inertiajs/react';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title={t('Verify your email')} />
            <h1 className="auth-title">{t('Verify your email')}</h1>
            <p className="auth-sub">{t('We sent a verification link to your email. Please click it to finish setting up your account.')}</p>
            {status === 'verification-link-sent' && (
                <div className="auth-status">{t('A new verification link has been sent to your email.')}</div>
            )}
            <form onSubmit={submit}>
                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '11px 16px' }} disabled={processing}>
                    {t('Resend verification email')}
                </button>
            </form>
            <p className="sm mut" style={{ textAlign: 'center', marginTop: 18 }}>
                <Link href={route('logout')} method="post" as="button" className="auth-link">{t('Log out')}</Link>
            </p>
        </GuestLayout>
    );
}
