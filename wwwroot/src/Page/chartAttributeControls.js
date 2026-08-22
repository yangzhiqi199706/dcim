export const chartAttributeControlDefs = [
    {
        attrName: '\u56fe\u8868\u5916\u89c2',
        attrCode: 'chartStyle',
        attrType: 'chartStyleSelect',
        attrWhere: 'Echart'
    },
    {
        attrName: '\u56fe\u8868\u52a8\u6548',
        attrCode: 'chartAnimation',
        attrType: 'chartAnimationSelect',
        attrWhere: 'Echart'
    },
    {
        attrName: '\u56fe\u8868\u6837\u5f0f',
        attrCode: 'chartBarStyle',
        attrType: 'chartBarStyleSelect',
        attrWhere: 'Echart',
        chartCats: ['bar']
    }
];

export const ensureChartAttributeControls = (moduleAttr, shouldAddControls, chartCat) => {
    if (!shouldAddControls || !Array.isArray(moduleAttr)) return moduleAttr;

    const nextModuleAttr = moduleAttr.map(group => ({
        ...group,
        attrGroupContent: Array.isArray(group.attrGroupContent)
            ? group.attrGroupContent.slice()
            : []
    }));

    const targetGroup = nextModuleAttr.find(group =>
        group.attrGroupContent.some(item => item && item.attrWhere === 'Echart')
    );
    if (!targetGroup) return nextModuleAttr;

    chartAttributeControlDefs.forEach((control) => {
        if (control.chartCats && !control.chartCats.includes(chartCat)) return;
        if (targetGroup.attrGroupContent.some(item => item && item.attrCode === control.attrCode)) return;
        const styleIndex = targetGroup.attrGroupContent.findIndex(item => item && item.attrCode === 'chartStyle');
        const animationIndex = targetGroup.attrGroupContent.findIndex(item => item && item.attrCode === 'chartAnimation');
        const titleIndex = targetGroup.attrGroupContent.findIndex(item => item && item.attrCode === 'titleSwitch');
        let insertIndex = titleIndex >= 0 ? titleIndex : targetGroup.attrGroupContent.length;
        if (control.attrCode === 'chartAnimation' && styleIndex >= 0) {
            insertIndex = styleIndex + 1;
        }
        if (control.attrCode === 'chartBarStyle') {
            insertIndex = animationIndex >= 0
                ? animationIndex + 1
                : (styleIndex >= 0 ? styleIndex + 1 : insertIndex);
        }
        const { chartCats, ...controlAttrs } = control;
        targetGroup.attrGroupContent.splice(insertIndex, 0, { ...controlAttrs });
    });

    return nextModuleAttr;
};
