import React, { useMemo, useState } from 'react';
import { Button, Dropdown, Tooltip } from 'antd';
import {
    AimOutlined,
    AlignCenterOutlined,
    AlignLeftOutlined,
    AlignRightOutlined,
    ApartmentOutlined,
    ArrowDownOutlined,
    ArrowUpOutlined,
    BlockOutlined,
    ColumnHeightOutlined,
    ColumnWidthOutlined,
    CopyOutlined,
    DatabaseOutlined,
    DeleteOutlined,
    ExperimentOutlined,
    EyeOutlined,
    FileAddOutlined,
    FontSizeOutlined,
    KeyOutlined,
    LockOutlined,
    LogoutOutlined,
    MoreOutlined,
    SafetyCertificateOutlined,
    SaveOutlined,
    SwapOutlined,
    UndoOutlined,
    UnlockOutlined,
    VerticalAlignBottomOutlined,
    VerticalAlignTopOutlined,
} from '@ant-design/icons';
import { t } from '../i18n';
import { getRibbonToolbarGroups, RIBBON_TOOLBAR_TABS } from './RibbonToolbarModel';

const COMMANDS = {
    undo: { icon: UndoOutlined, labelKey: 'ribbonToolbar.commands.undo' },
    copy: { icon: CopyOutlined, labelKey: 'ribbonToolbar.commands.copy' },
    lock: { icon: LockOutlined, labelKey: 'ribbonToolbar.commands.lock' },
    unlock: { icon: UnlockOutlined, labelKey: 'ribbonToolbar.commands.unlock' },
    delete: { icon: DeleteOutlined, labelKey: 'ribbonToolbar.commands.delete', tone: 'danger' },
    group: { icon: ApartmentOutlined, labelKey: 'ribbonToolbar.commands.group' },
    ungroup: { icon: BlockOutlined, labelKey: 'ribbonToolbar.commands.ungroup' },
    saveMasterControl: { icon: SaveOutlined, labelKey: 'masterControls.save' },
    layerUp: { icon: ArrowUpOutlined, labelKey: 'ribbonToolbar.commands.layerUp' },
    layerDown: { icon: ArrowDownOutlined, labelKey: 'ribbonToolbar.commands.layerDown' },
    layerTop: { icon: VerticalAlignTopOutlined, labelKey: 'ribbonToolbar.commands.layerTop' },
    layerBottom: { icon: VerticalAlignBottomOutlined, labelKey: 'ribbonToolbar.commands.layerBottom' },
    alignLeft: { icon: AlignLeftOutlined, labelKey: 'ribbonToolbar.commands.alignLeft' },
    alignRight: { icon: AlignRightOutlined, labelKey: 'ribbonToolbar.commands.alignRight' },
    alignTop: { icon: VerticalAlignTopOutlined, labelKey: 'ribbonToolbar.commands.alignTop' },
    alignBottom: { icon: VerticalAlignBottomOutlined, labelKey: 'ribbonToolbar.commands.alignBottom' },
    alignCenter: { icon: AlignCenterOutlined, labelKey: 'ribbonToolbar.commands.alignCenter' },
    alignMiddle: { icon: ColumnHeightOutlined, labelKey: 'ribbonToolbar.commands.alignMiddle' },
    equalHeight: { icon: ColumnHeightOutlined, labelKey: 'ribbonToolbar.commands.equalHeight' },
    equalWidth: { icon: ColumnWidthOutlined, labelKey: 'ribbonToolbar.commands.equalWidth' },
    equalGrid: { icon: BlockOutlined, labelKey: 'ribbonToolbar.commands.equalGrid' },
    equalVertical: { icon: ColumnHeightOutlined, labelKey: 'ribbonToolbar.commands.equalVertical' },
    equalHorizontal: { icon: ColumnWidthOutlined, labelKey: 'ribbonToolbar.commands.equalHorizontal' },
    preflight: { icon: SafetyCertificateOutlined, labelKey: 'ribbonToolbar.commands.preflight' },
    dataHealth: { icon: DatabaseOutlined, labelKey: 'ribbonToolbar.commands.dataHealth' },
    simulation: { icon: ExperimentOutlined, labelKey: 'ribbonToolbar.commands.simulation' },
    replaceDevice: { icon: SwapOutlined, labelKey: 'ribbonToolbar.commands.replaceDevice' },
    replaceParameter: { icon: SwapOutlined, labelKey: 'ribbonToolbar.commands.replaceParameter' },
    replaceText: { icon: FontSizeOutlined, labelKey: 'ribbonToolbar.commands.replaceText' },
    newPage: { icon: FileAddOutlined, labelKey: 'ribbonToolbar.commands.newPage' },
    preview: { icon: EyeOutlined, labelKey: 'ribbonToolbar.commands.preview' },
    saveTemplate: { icon: SaveOutlined, labelKey: 'ribbonToolbar.commands.saveTemplate' },
    savePage: { icon: SaveOutlined, labelKey: 'ribbonToolbar.commands.savePage' },
    snap: { icon: AimOutlined, labelKey: 'ribbonToolbar.commands.snap' },
};

const SELECTION_COMMANDS = new Set([
    'copy', 'lock', 'unlock', 'delete', 'layerUp', 'layerDown', 'layerTop', 'layerBottom',
    'alignLeft', 'alignRight', 'alignTop', 'alignBottom', 'alignCenter', 'alignMiddle',
    'equalHeight', 'equalWidth', 'equalGrid', 'equalVertical', 'equalHorizontal',
]);

function DesignerRibbonToolbar({
    title,
    saveStatusText,
    isDirty,
    selectedCount,
    canGroupSelection,
    canUngroupSelection,
    canSaveMasterControl,
    simulationEnabled,
    snapEnabled,
    snapThreshold,
    commandHandlers,
}) {
    const [activeTab, setActiveTab] = useState('edit');
    const groups = getRibbonToolbarGroups(activeTab);
    const isPageAvailable = commandHandlers.hasPersistedPage;
    const moreItems = useMemo(() => ([
        {
            key: 'shortcuts',
            icon: <KeyOutlined />,
            label: t('ribbonToolbar.commands.shortcuts'),
            onClick: commandHandlers.openShortcuts,
        },
        {
            key: 'exit',
            icon: <LogoutOutlined />,
            label: t('ribbonToolbar.commands.exit'),
            onClick: commandHandlers.exit,
        },
    ]), [commandHandlers.openShortcuts, commandHandlers.exit]);

    const isCommandDisabled = (command) => {
        if (SELECTION_COMMANDS.has(command)) return selectedCount < 1;
        if (command === 'group') return !canGroupSelection;
        if (command === 'ungroup') return !canUngroupSelection;
        if (command === 'saveMasterControl') return !canSaveMasterControl;
        if (command === 'replaceParameter') return selectedCount < 2;
        if (['replaceDevice', 'replaceText', 'preview', 'saveTemplate', 'savePage'].includes(command)) return !isPageAvailable;
        return false;
    };

    const runCommand = (command) => {
        const handler = commandHandlers[command];
        if (handler) handler();
    };

    const renderCommand = (command) => {
        if (command === 'snap') {
            return (
                <Tooltip key={command} title={t('ribbonToolbar.commands.snap')}>
                    <Button
                        className="ribbonCommand"
                        type={snapEnabled ? 'primary' : 'default'}
                        aria-label={t('ribbonToolbar.commands.snap')}
                        icon={<AimOutlined />}
                        onClick={commandHandlers.snap}
                    >
                        <span className="ribbonCommandLabel">{snapEnabled ? t('designer.snapOn') : t('designer.snapOff')}</span>
                    </Button>
                </Tooltip>
            );
        }

        const definition = COMMANDS[command];
        if (!definition) return null;
        const Icon = definition.icon;
        const label = t(definition.labelKey);

        return (
            <Tooltip key={command} title={label}>
                <Button
                    className={`ribbonCommand ${definition.tone === 'danger' ? 'isDanger' : ''}`.trim()}
                    type={command === 'simulation' && simulationEnabled ? 'primary' : 'default'}
                    disabled={isCommandDisabled(command)}
                    aria-label={label}
                    icon={<Icon />}
                    onClick={() => runCommand(command)}
                >
                    <span className="ribbonCommandLabel">{label}</span>
                </Button>
            </Tooltip>
        );
    };

    return (
        <header className="designerRibbon">
            <div className="ribbonGlobalRow">
                <div className="ribbonBrand">{title}</div>
                <div className="ribbonGlobalActions">
                    <span className={`saveStatus ${isDirty ? 'dirty' : ''}`}>{saveStatusText}</span>
                    <Tooltip title={t('ribbonToolbar.commands.preview')}>
                        <Button className="ribbonGlobalIcon" disabled={!isPageAvailable} icon={<EyeOutlined />} onClick={commandHandlers.preview} />
                    </Tooltip>
                    <Button className="ribbonSaveAction" type="primary" disabled={!isPageAvailable} icon={<SaveOutlined />} onClick={commandHandlers.savePage}>
                        {t('ribbonToolbar.commands.savePage')}
                    </Button>
                    <Dropdown menu={{ items: moreItems }} trigger={['click']}>
                        <Button className="ribbonToolbarMore" aria-label={t('ribbonToolbar.commands.more')} title={t('ribbonToolbar.commands.more')} icon={<MoreOutlined />} />
                    </Dropdown>
                </div>
            </div>
            <div className="ribbonContextRow">
                <div className="ribbonTabs" role="tablist" aria-label={t('ribbonToolbar.tabs.label')}>
                    {RIBBON_TOOLBAR_TABS.map((tab) => (
                        <button
                            key={tab.id}
                            id={`ribbon-tab-${tab.id}`}
                            className={`ribbonTab ${activeTab === tab.id ? 'isActive' : ''}`.trim()}
                            type="button"
                            role="tab"
                            aria-selected={activeTab === tab.id}
                            aria-controls={`ribbon-panel-${tab.id}`}
                            onClick={() => setActiveTab(tab.id)}
                        >
                            {t(tab.labelKey)}
                        </button>
                    ))}
                </div>
                <div id={`ribbon-panel-${activeTab}`} className="ribbonGroups" role="tabpanel" aria-labelledby={`ribbon-tab-${activeTab}`}>
                    {groups.map((group) => (
                        <div className={`ribbonGroup ribbonGroup-${group.id}`} key={group.id}>
                            <div className="ribbonCommands">{group.commands.map(renderCommand)}</div>
                            <span className="ribbonGroupLabel">{t(group.labelKey)}</span>
                        </div>
                    ))}
                    {activeTab === 'edit' && (
                        <div className="ribbonGroup ribbonSnapGroup">
                            <div className="ribbonCommands">
                                {renderCommand('snap')}
                                <select
                                    className="ribbonSnapThreshold"
                                    aria-label={t('ribbonToolbar.commands.snapThreshold')}
                                    value={String(snapThreshold)}
                                    onChange={(event) => commandHandlers.setSnapThreshold(Number(event.target.value))}
                                >
                                    <option value="4">4px</option>
                                    <option value="6">6px</option>
                                    <option value="8">8px</option>
                                    <option value="10">10px</option>
                                </select>
                            </div>
                            <span className="ribbonGroupLabel">{t('ribbonToolbar.groups.precision')}</span>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}

export default DesignerRibbonToolbar;
