import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { t } from '@/lib/i18n';

export default function DeleteUserForm() {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef();

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);

        clearErrors();
        reset();
    };

    return (
        <section className="card">
            <h3>{t('Delete Account')}</h3>
            <div className="card-sub">
                {t('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.')}
            </div>

            <button
                className="btn"
                style={{ color: 'var(--bad)', borderColor: '#f0caca' }}
                onClick={confirmUserDeletion}
            >
                {t('Delete Account')}
            </button>

            {confirmingUserDeletion && (
                <div className="overlay" onClick={closeModal}>
                    <div
                        className="modal"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <form onSubmit={deleteUser}>
                            <h3 style={{ fontSize: 17, marginBottom: 6 }}>
                                {t('Are you sure you want to delete your account?')}
                            </h3>
                            <p
                                style={{
                                    fontSize: 13.5,
                                    color: 'var(--mut)',
                                    lineHeight: 1.6,
                                    margin: '0 0 14px',
                                }}
                            >
                                {t('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.')}
                            </p>

                            <div className="frow">
                                <label className="fl" htmlFor="password">
                                    {t('Password')}
                                </label>
                                <input
                                    id="password"
                                    type="password"
                                    name="password"
                                    ref={passwordInput}
                                    className="inp"
                                    value={data.password}
                                    onChange={(e) =>
                                        setData('password', e.target.value)
                                    }
                                    autoFocus
                                    placeholder={t('Password')}
                                />
                                {errors.password && (
                                    <div
                                        style={{
                                            color: 'var(--bad)',
                                            fontSize: 12.5,
                                            marginTop: 4,
                                        }}
                                    >
                                        {errors.password}
                                    </div>
                                )}
                            </div>

                            <div
                                style={{
                                    display: 'flex',
                                    justifyContent: 'flex-end',
                                    gap: 10,
                                    marginTop: 18,
                                }}
                            >
                                <button
                                    type="button"
                                    className="btn"
                                    onClick={closeModal}
                                >
                                    {t('Cancel')}
                                </button>
                                <button
                                    className="btn"
                                    style={{
                                        background: 'var(--bad)',
                                        borderColor: 'var(--bad)',
                                        color: '#fff',
                                    }}
                                    disabled={processing}
                                >
                                    {t('Delete Account')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </section>
    );
}
