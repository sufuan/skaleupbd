import { Link, useForm, usePage } from '@inertiajs/react';
import { t } from '@/lib/i18n';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    shopName,
    roleLabel,
}) {
    const user = usePage().props.auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
        });

    const submit = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    const err = (msg) =>
        msg ? (
            <div style={{ color: 'var(--bad)', fontSize: 12.5, marginTop: 4 }}>
                {msg}
            </div>
        ) : null;

    return (
        <section className="card">
            <h3>{t('Profile Information')}</h3>
            <div className="card-sub">
                {t("Update your account's profile information and email address.")}
            </div>

            <div style={{ marginBottom: 16 }}>
                <div className="kv">
                    <span>{t('Shop')}</span>
                    <b>{shopName || t('Platform')}</b>
                </div>
                <div className="kv">
                    <span>{t('Role')}</span>
                    <b>{roleLabel ? t(roleLabel) : ''}</b>
                </div>
            </div>

            <form onSubmit={submit}>
                <div className="frow">
                    <label className="fl" htmlFor="name">
                        {t('Name')}
                    </label>
                    <input
                        id="name"
                        className="inp"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        autoFocus
                        autoComplete="name"
                    />
                    {err(errors.name)}
                </div>

                <div className="frow mt16">
                    <label className="fl" htmlFor="email">
                        {t('Email')}
                    </label>
                    <input
                        id="email"
                        type="email"
                        className="inp"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                    />
                    {err(errors.email)}
                </div>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div style={{ marginTop: 12, fontSize: 13.5 }}>
                        {t('Your email address is unverified.')}{' '}
                        <Link
                            href={route('verification.send')}
                            method="post"
                            as="button"
                            className="mini-link"
                        >
                            {t('Click here to re-send the verification email.')}
                        </Link>
                        {status === 'verification-link-sent' && (
                            <div
                                style={{
                                    marginTop: 6,
                                    fontWeight: 600,
                                    color: 'var(--ok)',
                                }}
                            >
                                {t('A new verification link has been sent to your email address.')}
                            </div>
                        )}
                    </div>
                )}

                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 12,
                        marginTop: 18,
                    }}
                >
                    <button className="btn pri" disabled={processing}>
                        {t('Save')}
                    </button>
                    {recentlySuccessful && (
                        <span
                            style={{
                                fontSize: 13.5,
                                fontWeight: 600,
                                color: 'var(--ok)',
                            }}
                        >
                            {t('Saved.')}
                        </span>
                    )}
                </div>
            </form>
        </section>
    );
}
