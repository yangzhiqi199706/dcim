import React, { useState } from 'react';
import DesignerRibbonToolbar from './Page/DesignerRibbonToolbar';
import { t } from './i18n';
import './Assets/base.css';
import './Assets/designer.css';

const commandKeys = [
    'undo', 'copy', 'lock', 'unlock', 'delete', 'group', 'ungroup',
    'layerUp', 'layerDown', 'layerTop', 'layerBottom',
    'alignLeft', 'alignRight', 'alignTop', 'alignBottom', 'alignCenter', 'alignMiddle',
    'equalHeight', 'equalWidth', 'equalGrid', 'equalVertical', 'equalHorizontal',
    'preflight', 'dataHealth', 'simulation', 'replaceDevice', 'replaceParameter',
    'replaceText', 'newPage', 'preview', 'saveTemplate', 'savePage',
];

function RibbonToolbarPreviewApp() {
    const [snapEnabled, setSnapEnabled] = useState(true);
    const [snapThreshold, setSnapThreshold] = useState(6);
    const [isDirty, setIsDirty] = useState(true);
    const [notice, setNotice] = useState(t('ribbonToolbar.preview.ready'));

    const runPreviewCommand = (command) => () => {
        setIsDirty(command !== 'savePage');
        setNotice(t('ribbonToolbar.preview.command').replace('{command}', t(`ribbonToolbar.commands.${command}`)));
    };

    const commandHandlers = commandKeys.reduce((handlers, command) => {
        handlers[command] = runPreviewCommand(command);
        return handlers;
    }, {
        hasPersistedPage: true,
        snap: () => {
            setSnapEnabled((current) => !current);
            setNotice(t('ribbonToolbar.preview.snapChanged'));
        },
        setSnapThreshold: (value) => {
            setSnapThreshold(value);
            setNotice(t('ribbonToolbar.preview.thresholdChanged').replace('{value}', String(value)));
        },
        openShortcuts: () => setNotice(t('ribbonToolbar.preview.shortcuts')),
        exit: () => setNotice(t('ribbonToolbar.preview.exit')),
    });

    return (
        <main className="designerShell ribbonPreviewCanvas" aria-label={t('ribbonToolbar.preview.title')}>
            <DesignerRibbonToolbar
                title={t('ribbonToolbar.preview.title')}
                saveStatusText={isDirty ? t('designer.modified') : t('designer.saved')}
                isDirty={isDirty}
                selectedCount={2}
                canGroupSelection={true}
                canUngroupSelection={true}
                simulationEnabled={false}
                snapEnabled={snapEnabled}
                snapThreshold={snapThreshold}
                commandHandlers={commandHandlers}
            />
            <section className="ribbonPreviewStage">
                <div className="ribbonPreviewHud">
                    <span>{t('ribbonToolbar.preview.canvasTitle')}</span>
                    <span>{notice}</span>
                </div>
                <div className="ribbonPreviewBoard" aria-label={t('ribbonToolbar.preview.canvasTitle')}>
                    <div className="ribbonPreviewWidget ribbonPreviewWidget-main">
                        <div className="ribbonPreviewWidgetTitle">{t('ribbonToolbar.preview.widgetPrimary')}</div>
                        <div className="ribbonPreviewChart">
                            <span />
                            <span />
                            <span />
                            <span />
                            <span />
                            <span />
                        </div>
                    </div>
                    <div className="ribbonPreviewWidget ribbonPreviewWidget-side">
                        <div className="ribbonPreviewWidgetTitle">{t('ribbonToolbar.preview.widgetSecondary')}</div>
                        <div className="ribbonPreviewMetric">78.4<span>%</span></div>
                        <div className="ribbonPreviewLine"><span /></div>
                    </div>
                    <div className="ribbonPreviewWidget ribbonPreviewWidget-footer">
                        <div className="ribbonPreviewWidgetTitle">{t('ribbonToolbar.preview.widgetFooter')}</div>
                        <div className="ribbonPreviewRows">
                            <span />
                            <span />
                            <span />
                        </div>
                    </div>
                </div>
            </section>
        </main>
    );
}

export default RibbonToolbarPreviewApp;
