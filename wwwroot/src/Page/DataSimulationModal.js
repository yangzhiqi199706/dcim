import React from 'react';
import { CloseOutlined, ReloadOutlined } from '@ant-design/icons';
import { t } from '../i18n';
import { getSimulationElementLabel } from './simulationOverrides';

const hasOwn = (value, key) => Object.prototype.hasOwnProperty.call(value || {}, key);

const DataSimulationModal = ({
    open,
    enabled,
    elements = [],
    values = {},
    onClose,
    onEnabledChange,
    onReset,
    onValuesChange,
}) => {
    if (!open) return null;

    return (
        <div className="designerOverlay" role="presentation">
            <section className="designerDialog simulationDialog" role="dialog" aria-modal="true" aria-label={t('designer.simulation.title')}>
                <header className="designerDialogHeader">
                    <div>
                        <h2>{t('designer.simulation.title')}</h2>
                        <p>{t('designer.simulation.localOnly')}</p>
                    </div>
                    <button type="button" className="dialogIconButton" aria-label={t('common.close')} onClick={onClose}>
                        <CloseOutlined />
                    </button>
                </header>
                <div className="designerDialogBody">
                    <label className="simulationToggle">
                        <input type="checkbox" checked={enabled} onChange={(event) => onEnabledChange(event.target.checked)} />
                        <span>{t('designer.simulation.enable')}</span>
                    </label>
                    {elements.length === 0 && <div className="diagnosticEmpty">{t('designer.simulation.noTargets')}</div>}
                    {elements.map((element, index) => {
                        const inputId = `simulation-value-${index}`;
                        const label = getSimulationElementLabel(element, index);
                        return (
                            <div className="simulationValueRow" key={element.id}>
                                <label htmlFor={inputId}>{label}</label>
                                <input
                                    id={inputId}
                                    type="text"
                                    data-simulation-id={element.id}
                                    value={hasOwn(values, element.id) ? values[element.id] : ''}
                                    placeholder={t('designer.simulation.valuePlaceholder')}
                                    onChange={(event) => onValuesChange({ ...values, [element.id]: event.target.value })}
                                />
                            </div>
                        );
                    })}
                </div>
                <footer className="designerDialogFooter">
                    <button type="button" className="dialogSecondaryButton" onClick={onReset}>
                        <ReloadOutlined />
                        {t('designer.simulation.reset')}
                    </button>
                    <button type="button" className="dialogPrimaryButton" onClick={onClose}>{t('common.close')}</button>
                </footer>
            </section>
        </div>
    );
};

export default DataSimulationModal;
