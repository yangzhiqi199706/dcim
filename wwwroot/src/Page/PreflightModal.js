import React from 'react';
import { CloseOutlined } from '@ant-design/icons';
import { t } from '../i18n';

const findingLabel = (finding) => t(`designer.preflight.findings.${finding.code}`);

const PreflightModal = ({ open, findings = [], onClose, onLocate }) => {
    if (!open) return null;

    const errors = findings.filter((finding) => finding.severity === 'error').length;
    const warnings = findings.length - errors;

    return (
        <div className="designerOverlay" role="presentation">
            <section className="designerDialog preflightDialog" role="dialog" aria-modal="true" aria-label={t('designer.preflight.title')}>
                <header className="designerDialogHeader">
                    <div>
                        <h2>{t('designer.preflight.title')}</h2>
                        <p className="preflightSummary">
                            <span>{errors}</span>
                            <span>{t('designer.preflight.errorCount')}</span>
                            <span>{warnings}</span>
                            <span>{t('designer.preflight.warningCount')}</span>
                        </p>
                    </div>
                    <button type="button" className="dialogIconButton" aria-label={t('common.close')} onClick={onClose}>
                        <CloseOutlined />
                    </button>
                </header>
                <div className="designerDialogBody">
                    {findings.length === 0 && <div className="diagnosticEmpty">{t('designer.preflight.noIssues')}</div>}
                    {findings.map((finding) => (
                        <button
                            type="button"
                            key={`${finding.code}-${finding.index}-${finding.elementId}`}
                            className={`diagnosticFinding diagnosticFinding-${finding.severity}`}
                            data-element-id={finding.elementId}
                            onClick={() => onLocate && onLocate(finding.elementId)}
                        >
                            <span className="diagnosticFindingTitle">{findingLabel(finding)}</span>
                            <span className="diagnosticFindingMeta">{finding.elementId || `#${finding.index + 1}`}</span>
                        </button>
                    ))}
                </div>
            </section>
        </div>
    );
};

export default PreflightModal;
