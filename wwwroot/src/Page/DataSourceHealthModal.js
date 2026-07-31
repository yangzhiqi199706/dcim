import React from 'react';
import { CloseOutlined, ReloadOutlined } from '@ant-design/icons';
import { HEALTH_STATUSES } from './dataSourceHealth';
import { t } from '../i18n';

const getCount = (counts, status) => Number(counts && counts[status]) || 0;

const DataSourceHealthModal = ({
    open,
    report,
    loading,
    loadError,
    onClose,
    onLocate,
    onRefresh,
}) => {
    if (!open) return null;

    const items = report && Array.isArray(report.items) ? report.items : [];
    const counts = report && report.counts ? report.counts : {};

    return (
        <div className="designerOverlay" role="presentation">
            <section className="designerDialog dataSourceHealthDialog" role="dialog" aria-modal="true" aria-label={t('designer.dataSourceHealth.title')}>
                <header className="designerDialogHeader">
                    <div>
                        <h2>{t('designer.dataSourceHealth.title')}</h2>
                        <p>{t('designer.dataSourceHealth.description')}</p>
                    </div>
                    <div className="dataSourceHealthActions">
                        <button
                            type="button"
                            className="dialogIconButton"
                            data-health-refresh
                            aria-label={t('designer.dataSourceHealth.refresh')}
                            title={t('designer.dataSourceHealth.refresh')}
                            disabled={loading}
                            onClick={() => onRefresh && onRefresh()}
                        >
                            <ReloadOutlined />
                        </button>
                        <button type="button" className="dialogIconButton" aria-label={t('common.close')} onClick={onClose}>
                            <CloseOutlined />
                        </button>
                    </div>
                </header>
                <div className="designerDialogBody">
                    <div className="dataSourceHealthCounts" aria-label={t('designer.dataSourceHealth.statusSummary')}>
                        {HEALTH_STATUSES.map((status) => (
                            <div className={`dataSourceHealthCount dataSourceHealthCount-${status}`} key={status}>
                                <span>{getCount(counts, status)}</span>
                                <span>{t(`designer.dataSourceHealth.status.${status}`)}</span>
                            </div>
                        ))}
                    </div>
                    {loading && <div className="dataSourceHealthNotice" data-health-loading>{t('designer.dataSourceHealth.loading')}</div>}
                    {loadError && <div className="dataSourceHealthNotice dataSourceHealthNotice-error" data-health-load-error>{t('designer.dataSourceHealth.snapshotUnavailable')}</div>}
                    {!loading && !loadError && !report && <div className="diagnosticEmpty">{t('designer.dataSourceHealth.noSnapshot')}</div>}
                    {report && items.length === 0 && <div className="diagnosticEmpty">{t('designer.dataSourceHealth.noTargets')}</div>}
                    {items.map((item) => (
                        <button
                            type="button"
                            key={`${item.elementId}-${item.index}`}
                            className={`dataSourceHealthItem dataSourceHealthItem-${item.status}`}
                            data-health-element-id={item.elementId}
                            disabled={!item.elementId}
                            onClick={() => onLocate && onLocate(item.elementId)}
                        >
                            <span className="dataSourceHealthItemTitle">{item.label}</span>
                            <span className="dataSourceHealthItemStatus">{t(`designer.dataSourceHealth.status.${item.status}`)}</span>
                            <span className="dataSourceHealthItemMeta">{item.bindingSummary || item.elementId || `#${item.index + 1}`}</span>
                        </button>
                    ))}
                </div>
                <footer className="designerDialogFooter">
                    <button type="button" className="dialogPrimaryButton" onClick={onClose}>{t('common.close')}</button>
                </footer>
            </section>
        </div>
    );
};

export default DataSourceHealthModal;
