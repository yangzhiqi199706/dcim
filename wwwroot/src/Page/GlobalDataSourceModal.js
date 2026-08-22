import React, { useEffect, useState } from 'react';
import {
    CheckCircleOutlined,
    CloseOutlined,
    CloudServerOutlined,
    DatabaseOutlined,
    DisconnectOutlined,
    SafetyCertificateOutlined,
    WarningOutlined,
} from '@ant-design/icons';
import httpsend from '../Assets/httpsend';
import { normalizeDataSourceHost } from '../Assets/dataSource';
import { isVerifiedGlobalDataSourceHost, saveGlobalDataSource } from '../Assets/globalDataSource';
import { t } from '../i18n';

const GlobalDataSourceModal = ({ open, onClose, config, onChange }) => {
    const [mode, setMode] = useState('local');
    const [draft, setDraft] = useState('');
    const [verifiedHost, setVerifiedHost] = useState('');
    const [busy, setBusy] = useState('');
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        if (!open) return;
        const enabled = Boolean(config && config.enabled && config.host);
        setMode(enabled ? 'remote' : 'local');
        setDraft(enabled ? config.host : '');
        setVerifiedHost(enabled ? config.host : '');
        setBusy('');
        setNotice(null);
    }, [open, config]);

    if (!open) return null;

    const resolveCandidateHost = () => {
        try {
            const candidateHost = normalizeDataSourceHost(draft);
            if (!candidateHost) throw new Error('emptyDataSourceHost');
            return candidateHost;
        } catch (error) {
            setVerifiedHost('');
            setNotice({ type: 'error', text: t('globalDataSource.invalidAddress') });
            return '';
        }
    };

    const testConnection = async () => {
        const candidateHost = resolveCandidateHost();
        if (!candidateHost) return;
        setBusy('test');
        setNotice(null);
        try {
            const response = await httpsend.getDataFrom(candidateHost, 'GetDeviceListKey', { ComboBox: 'all' });
            if (!response || !Array.isArray(response.data)) throw new Error('invalidDataSourceResponse');
            setDraft(candidateHost);
            setVerifiedHost(candidateHost);
            setNotice({
                type: 'success',
                text: t('globalDataSource.connectionSuccess').replace('{count}', String(response.data.length)),
            });
        } catch (error) {
            setVerifiedHost('');
            setNotice({ type: 'error', text: t('globalDataSource.connectionFailed') });
        } finally {
            setBusy('');
        }
    };

    const applyConfiguration = () => {
        if (mode === 'local') {
            const next = saveGlobalDataSource({ enabled: false, host: '' });
            onChange(next);
            onClose();
            return;
        }
        const candidateHost = resolveCandidateHost();
        if (!candidateHost) return;
        if (!isVerifiedGlobalDataSourceHost(candidateHost, verifiedHost)) {
            setNotice({ type: 'error', text: t('globalDataSource.testRequired') });
            return;
        }
        const next = saveGlobalDataSource({ enabled: true, host: candidateHost });
        onChange(next);
        onClose();
    };

    const disableRemoteSource = () => {
        const next = saveGlobalDataSource({ enabled: false, host: '' });
        onChange(next);
        onClose();
    };

    return (
        <div className="designerOverlay" role="presentation">
            <section className="designerDialog globalDataSourceDialog" role="dialog" aria-modal="true" aria-label={t('globalDataSource.title')}>
                <header className="designerDialogHeader">
                    <div>
                        <h2><CloudServerOutlined /> {t('globalDataSource.title')}</h2>
                        <p>{t('globalDataSource.description')}</p>
                    </div>
                    <button type="button" className="dialogIconButton" aria-label={t('common.close')} onClick={onClose}>
                        <CloseOutlined />
                    </button>
                </header>
                <div className="designerDialogBody globalDataSourceBody">
                    <div className="globalDataSourceMode" role="group" aria-label={t('globalDataSource.mode')}>
                        <button
                            type="button"
                            className={mode === 'local' ? 'isActive' : ''}
                            aria-pressed={mode === 'local'}
                            onClick={() => { setMode('local'); setNotice(null); }}
                        >
                            <DatabaseOutlined />
                            <span>{t('globalDataSource.local')}</span>
                        </button>
                        <button
                            type="button"
                            className={mode === 'remote' ? 'isActive' : ''}
                            aria-pressed={mode === 'remote'}
                            onClick={() => { setMode('remote'); setNotice(null); }}
                        >
                            <CloudServerOutlined />
                            <span>{t('globalDataSource.remote')}</span>
                        </button>
                    </div>

                    {mode === 'remote' && (
                        <label className="globalDataSourceField">
                            <span>{t('globalDataSource.address')}</span>
                            <input
                                value={draft}
                                spellCheck={false}
                                autoComplete="off"
                                placeholder={t('globalDataSource.addressPlaceholder')}
                                onChange={(event) => { setDraft(event.target.value); setVerifiedHost(''); setNotice(null); }}
                            />
                            <small>{t('globalDataSource.defaultPort')}</small>
                        </label>
                    )}

                    <div className="globalDataSourceSafety">
                        <SafetyCertificateOutlined />
                        <div>
                            <strong>{t('globalDataSource.readOnlyTitle')}</strong>
                            <span>{t('globalDataSource.readOnlyDescription')}</span>
                        </div>
                    </div>

                    {notice && (
                        <div className={`globalDataSourceNotice globalDataSourceNotice-${notice.type}`} role={notice.type === 'error' ? 'alert' : 'status'}>
                            {notice.type === 'success' ? <CheckCircleOutlined /> : <WarningOutlined />}
                            <span>{notice.text}</span>
                        </div>
                    )}
                </div>
                <footer className="designerDialogFooter globalDataSourceFooter">
                    {config && config.enabled && (
                        <button type="button" className="dialogSecondaryButton" onClick={disableRemoteSource}>
                            <DisconnectOutlined />
                            {t('globalDataSource.disable')}
                        </button>
                    )}
                    <span className="globalDataSourceCurrent">
                        {config && config.enabled ? `${t('globalDataSource.current')}: ${config.host}` : t('globalDataSource.currentLocal')}
                    </span>
                    {mode === 'remote' && (
                        <button type="button" className="dialogSecondaryButton" disabled={Boolean(busy)} onClick={testConnection}>
                            <SafetyCertificateOutlined />
                            {busy === 'test' ? t('globalDataSource.testing') : t('globalDataSource.testConnection')}
                        </button>
                    )}
                    <button
                        type="button"
                        className="dialogPrimaryButton"
                        disabled={Boolean(busy) || (mode === 'remote' && !isVerifiedGlobalDataSourceHost(draft, verifiedHost))}
                        onClick={applyConfiguration}
                    >
                        {t('globalDataSource.apply')}
                    </button>
                </footer>
            </section>
        </div>
    );
};

export default GlobalDataSourceModal;
