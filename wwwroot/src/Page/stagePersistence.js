const positiveNumberOr = (value, fallback) => {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? Math.round(number) : fallback;
};

export const resolveLogicalStageSize = (stage, fallbackWidth = 1920, fallbackHeight = 1080) => {
    const attrs = stage && stage.attrs && typeof stage.attrs === 'object'
        ? stage.attrs
        : {};
    const scaleX = Number(attrs.scaleX) > 0 ? Number(attrs.scaleX) : 1;
    const scaleY = Number(attrs.scaleY) > 0 ? Number(attrs.scaleY) : 1;

    return {
        width: positiveNumberOr(positiveNumberOr(attrs.width, fallbackWidth) / scaleX, fallbackWidth),
        height: positiveNumberOr(positiveNumberOr(attrs.height, fallbackHeight) / scaleY, fallbackHeight),
    };
};

export const normalizeStageForPersistence = (stage, width, height) => {
    if (!stage || typeof stage !== 'object') return stage;

    const normalized = JSON.parse(JSON.stringify(stage));
    const attrs = normalized.attrs && typeof normalized.attrs === 'object'
        ? normalized.attrs
        : {};
    const logicalWidth = positiveNumberOr(width, positiveNumberOr(attrs.width, 1920));
    const logicalHeight = positiveNumberOr(height, positiveNumberOr(attrs.height, 1080));

    normalized.attrs = {
        ...attrs,
        width: logicalWidth,
        height: logicalHeight,
        scaleX: 1,
        scaleY: 1,
    };
    return normalized;
};
