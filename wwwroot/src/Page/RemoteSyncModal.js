import React, { useEffect, useMemo, useState } from 'react';
import {
    CheckCircleOutlined,
    CloseOutlined,
    CloudDownloadOutlined,
    DatabaseOutlined,
    FolderOpenOutlined,
    SafetyCertificateOutlined,
    WarningOutlined,
} from '@ant-design/icons';
import httpsend from '../Assets/httpsend';
import { t } from '../i18n';
import {
    REMOTE_SYNC_TARGET,
    createRemoteSyncPayload,
    getRemoteSyncMessageKey,
    isRemoteSyncJobActive,
    validateRemoteSyncForm,
} from './remoteSync';

const createInitialForm = () => ({
    remoteHost: '',
    sshPort: 22,
    sshUser: 'root',
    sshPassword: '',
    dbUser: 'root',
    dbPassword: '',
    confirmed: false,
});

const getResponseError = (response) => {
    if (!response) return t('designer.remoteSync.errors.requestFailed');
    if (response.msg === 'REMOTE_SYNC_PRODUCTION_ONLY') return t('designer.remoteSync.errors.productionOnly');
    if (response.msg === 'REMOTE_SYNC_JOB_NOT_FOUND') return t('designer.remoteSync.errors.jobNotFound');
    const messageKey = getRemoteSyncMessageKey(response.msg);
    if (messageKey) return t(messageKey);
    return /^REMOTE_SYNC_/.test(String(response.msg || ''))
        ? t('designer.remoteSync.errors.requestFailed')
        : (response.msg || t('designer.remoteSync.errors.requestFailed'));
};

const getStatusMessage = (message) => {
    const messageKey = getRemoteSyncMessageKey(message);
    if (messageKey) return t(messageKey);
    return /^REMOTE_SYNC_/.test(String(message || '')) ? '' : String(message || '');
};

const getProgress = (status) => {
    const value = Number(status && status.progress);
    if (!Number.isFinite(value)) return 0;
    return Math.min(100, Math.max(0, Math.round(value)));
};

const RemoteSyncModal = ({ open, onClose }) => {
    const [form, setForm] = useState(createInitialForm);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState('');
    const [requestError, setRequestError] = useState('');
    const [preflight, setPreflight] = useState(null);
    const [jobId, setJobId] = useState('');
    const [status, setStatus] = useState(null);
    const active = isRemoteSyncJobActive(status);
    const progress = getProgress(status);

    const phaseLabel = useMemo(() => {
        const phase = status && (status.phase || status.state);
        return phase ? t(`designer.remoteSync.phases.${phase}`) : '';
    }, [status]);

    const updateField = (name, value) => {
        setForm((current) => ({ ...current, [name]: value }));
        setErrors((current) => ({ ...current, [name]: undefined }));
        setRequestError('');
        if (name !== 'confirmed') setPreflight(null);
    };

    const validate = () => {
        const nextErrors = validateRemoteSyncForm(form);
        setErrors(nextErrors);
        return Object.keys(nextErrors).length === 0;
    };

    const requestStatus = async (targetJobId = jobId) => {
        const response = await httpsend.getDataLocal('remoteSyncStatus', {
            token: localStorage.getItem('wl') || '',
            jobId: targetJobId || '',
        });
        if (!response || response.code !== 100) {
            if (targetJobId) setRequestError(getResponseError(response));
            return null;
        }
        const nextStatus = response.data || null;
        if (nextStatus) {
            setStatus(nextStatus);
            if (nextStatus.jobId) setJobId(nextStatus.jobId);
        }
        return nextStatus;
    };

    useEffect(() => {
        if (!open) {
            setForm(createInitialForm());
            setErrors({});
            setBusy('');
            setRequestError('');
            setPreflight(null);
            setJobId('');
            setStatus(null);
            return undefined;
        }

        let cancelled = false;
        const restoreActiveJob = async () => {
            try {
                const response = await httpsend.getDataLocal('remoteSyncStatus', {
                    token: localStorage.getItem('wl') || '',
                    jobId: '',
                });
                if (!cancelled && response && response.code === 100 && response.data) {
                    setStatus(response.data);
                    setJobId(response.data.jobId || '');
                }
            } catch (error) {
                // An absent previous job is not an opening error.
            }
        };
        restoreActiveJob();
        return () => {
            cancelled = true;
        };
    }, [open]);

    useEffect(() => {
        if (!open || !jobId || !active) return undefined;
        const timer = window.setTimeout(() => {
            requestStatus(jobId).catch(() => {
                setRequestError(t('designer.remoteSync.errors.requestFailed'));
            });
        }, 1500);
        return () => window.clearTimeout(timer);
    }, [open, jobId, active, status]);

    const runPreflight = async () => {
        if (!validate()) return;
        setBusy('preflight');
        setRequestError('');
        try {
            const response = await httpsend.getDataLocal('remoteSyncPreflight', createRemoteSyncPayload(
                form,
                localStorage.getItem('wl') || ''
            ));
            if (!response || response.code !== 100) {
                setRequestError(getResponseError(response));
                setPreflight(null);
                return;
            }
            setPreflight(response.data || {});
        } catch (error) {
            setRequestError(t('designer.remoteSync.errors.requestFailed'));
            setPreflight(null);
        } finally {
            setBusy('');
        }
    };

    const startSync = async () => {
        if (!validate()) return;
        setBusy('start');
        setRequestError('');
        try {
            const response = await httpsend.getDataLocal('remoteSyncStart', createRemoteSyncPayload(
                form,
                localStorage.getItem('wl') || ''
            ));
            if (!response || response.code !== 100) {
                setRequestError(getResponseError(response));
                return;
            }
            const nextStatus = response.data || {};
            setJobId(nextStatus.jobId || '');
            setStatus(nextStatus);
            setForm((current) => ({
                ...current,
                sshPassword: '',
                dbPassword: '',
                confirmed: false,
            }));
        } catch (error) {
            setRequestError(t('designer.remoteSync.errors.requestFailed'));
        } finally {
            setBusy('');
        }
    };

    if (!open) return null;

    const closeDialog = () => {
        if (active) return;
        onClose();
    };
    const fieldError = (name) => errors[name]
        ? t(`designer.remoteSync.validation.${errors[name]}`)
        : '';

    return (
        <div className="designerOverlay" role="presentation">
            <section className="designerDialog remoteSyncDialog" role="dialog" aria-modal="true" aria-label={t('designer.remoteSync.title')}>
                <header className="designerDialogHeader">
                    <div>
                        <h2><CloudDownloadOutlined /> {t('designer.remoteSync.title')}</h2>
                        <p>{t('designer.remoteSync.description')}</p>
                    </div>
                    <button
                        type="button"
                        className="dialogIconButton"
                        aria-label={t('common.close')}
                        disabled={active}
                        onClick={closeDialog}
                    >
                        <CloseOutlined />
                    </button>
                </header>
                <div className="designerDialogBody remoteSyncBody">
                    <div className="remoteSyncTargets">
                        <div><DatabaseOutlined /><span>{t('designer.remoteSync.database')}</span><strong>{REMOTE_SYNC_TARGET.databaseName}</strong></div>
                        <div><FolderOpenOutlined /><span>{t('designer.remoteSync.images')}</span><strong>{REMOTE_SYNC_TARGET.imagesPath}</strong></div>
                    </div>

                    {!jobId && (
                        <div className="remoteSyncForm">
                            <label className="remoteSyncField remoteSyncFieldWide">
                                <span>{t('designer.remoteSync.remoteHost')}</span>
                                <input
                                    value={form.remoteHost}
                                    spellCheck={false}
                                    autoComplete="off"
                                    placeholder="192.168.0.60"
                                    aria-invalid={Boolean(errors.remoteHost)}
                                    onChange={(event) => updateField('remoteHost', event.target.value)}
                                />
                                {errors.remoteHost && <small>{fieldError('remoteHost')}</small>}
                            </label>
                            <label className="remoteSyncField">
                                <span>{t('designer.remoteSync.sshPort')}</span>
                                <input
                                    type="number"
                                    min="1"
                                    max="65535"
                                    value={form.sshPort}
                                    aria-invalid={Boolean(errors.sshPort)}
                                    onChange={(event) => updateField('sshPort', event.target.value)}
                                />
                                {errors.sshPort && <small>{fieldError('sshPort')}</small>}
                            </label>
                            <label className="remoteSyncField">
                                <span>{t('designer.remoteSync.sshUser')}</span>
                                <input
                                    value={form.sshUser}
                                    autoComplete="username"
                                    aria-invalid={Boolean(errors.sshUser)}
                                    onChange={(event) => updateField('sshUser', event.target.value)}
                                />
                                {errors.sshUser && <small>{fieldError('sshUser')}</small>}
                            </label>
                            <label className="remoteSyncField">
                                <span>{t('designer.remoteSync.sshPassword')}</span>
                                <input
                                    type="password"
                                    value={form.sshPassword}
                                    autoComplete="new-password"
                                    aria-invalid={Boolean(errors.sshPassword)}
                                    onChange={(event) => updateField('sshPassword', event.target.value)}
                                />
                                {errors.sshPassword && <small>{fieldError('sshPassword')}</small>}
                            </label>
                            <label className="remoteSyncField">
                                <span>{t('designer.remoteSync.dbUser')}</span>
                                <input
                                    value={form.dbUser}
                                    autoComplete="off"
                                    aria-invalid={Boolean(errors.dbUser)}
                                    onChange={(event) => updateField('dbUser', event.target.value)}
                                />
                                {errors.dbUser && <small>{fieldError('dbUser')}</small>}
                            </label>
                            <label className="remoteSyncField">
                                <span>{t('designer.remoteSync.dbPassword')}</span>
                                <input
                                    type="password"
                                    value={form.dbPassword}
                                    autoComplete="new-password"
                                    aria-invalid={Boolean(errors.dbPassword)}
                                    onChange={(event) => updateField('dbPassword', event.target.value)}
                                />
                                {errors.dbPassword && <small>{fieldError('dbPassword')}</small>}
                            </label>
                            <label className={`remoteSyncConfirm ${errors.confirmed ? 'hasError' : ''}`}>
                                <input
                                    type="checkbox"
                                    data-remote-sync-confirm
                                    checked={form.confirmed}
                                    onChange={(event) => updateField('confirmed', event.target.checked)}
                                />
                                <span>{t('designer.remoteSync.confirm')}</span>
                            </label>
                            {errors.confirmed && <div className="remoteSyncInlineError">{fieldError('confirmed')}</div>}
                        </div>
                    )}

                    {preflight && (
                        <div className="remoteSyncNotice remoteSyncNoticeSuccess">
                            <SafetyCertificateOutlined />
                            <div>
                                <strong>{t('designer.remoteSync.preflightPassed')}</strong>
                                {preflight.fingerprint && <span>{t('designer.remoteSync.fingerprint')}: {preflight.fingerprint}</span>}
                                {preflight.remoteImagesSize && <span>{t('designer.remoteSync.remoteImagesSize')}: {preflight.remoteImagesSize}</span>}
                            </div>
                        </div>
                    )}
                    {requestError && (
                        <div className="remoteSyncNotice remoteSyncNoticeError" role="alert">
                            <WarningOutlined />
                            <span>{requestError}</span>
                        </div>
                    )}
                    {status && (
                        <div className={`remoteSyncStatus remoteSyncStatus-${status.state || ''}`}>
                            <div className="remoteSyncStatusTitle">
                                {status.state === 'completed' ? <CheckCircleOutlined /> : <CloudDownloadOutlined />}
                                <strong>{phaseLabel || status.message || t('designer.remoteSync.running')}</strong>
                                <span>{progress}%</span>
                            </div>
                            <div className="remoteSyncProgress" aria-label={t('designer.remoteSync.progress')} aria-valuemin="0" aria-valuemax="100" aria-valuenow={progress} role="progressbar">
                                <span style={{ width: `${progress}%` }} />
                            </div>
                            {getStatusMessage(status.message) && <p>{getStatusMessage(status.message)}</p>}
                            {status.backupPath && <p>{t('designer.remoteSync.backupPath')}: {status.backupPath}</p>}
                        </div>
                    )}
                </div>
                <footer className="designerDialogFooter remoteSyncFooter">
                    <span className="remoteSyncPrivacy">{t('designer.remoteSync.passwordNotice')}</span>
                    {!jobId && (
                        <>
                            <button type="button" className="dialogSecondaryButton" disabled={Boolean(busy)} onClick={runPreflight}>
                                <SafetyCertificateOutlined />
                                {busy === 'preflight' ? t('designer.remoteSync.checking') : t('designer.remoteSync.checkConnection')}
                            </button>
                            <button type="button" className="dialogDangerButton" disabled={Boolean(busy)} onClick={startSync}>
                                <CloudDownloadOutlined />
                                {busy === 'start' ? t('designer.remoteSync.starting') : t('designer.remoteSync.syncToLocal')}
                            </button>
                        </>
                    )}
                    {jobId && !active && <button type="button" className="dialogPrimaryButton" onClick={closeDialog}>{t('common.close')}</button>}
                </footer>
            </section>
        </div>
    );
};

export default RemoteSyncModal;
