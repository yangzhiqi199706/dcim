const toFiniteNumber = (value) => {
    const numberValue = Number(value);
    return Number.isFinite(numberValue) ? numberValue : 0;
};

const translateGroupMetrics = (metrics, deltaX, deltaY) => {
    if (!metrics) return null;
    return {
        ...metrics,
        x: metrics.x + deltaX,
        y: metrics.y + deltaY,
        left: metrics.left + deltaX,
        top: metrics.top + deltaY,
        right: metrics.right + deltaX,
        bottom: metrics.bottom + deltaY,
        centerX: metrics.centerX + deltaX,
        centerY: metrics.centerY + deltaY,
    };
};

export const createMultiDragSession = ({
    ids = [],
    draggedId = null,
    startPositions = {},
    nodesById = {},
    groupMetrics = null,
    guideCandidates = null,
}) => ({
    active: ids.length > 1 && Boolean(draggedId),
    ids: [...ids],
    draggedId,
    startPositions,
    nodesById,
    groupMetrics,
    guideCandidates,
    pendingPositions: null,
});

export const calculateMultiDragFrame = (session, draggedPosition) => {
    if (!session || !session.draggedId || !draggedPosition) return null;
    const startPosition = session.startPositions[session.draggedId];
    if (!startPosition) return null;
    const delta = {
        x: toFiniteNumber(draggedPosition.x) - toFiniteNumber(startPosition.x),
        y: toFiniteNumber(draggedPosition.y) - toFiniteNumber(startPosition.y),
    };
    const positions = session.ids.reduce((nextPositions, id) => {
        const basePosition = session.startPositions[id];
        if (!basePosition) return nextPositions;
        nextPositions[id] = {
            x: toFiniteNumber(basePosition.x) + delta.x,
            y: toFiniteNumber(basePosition.y) + delta.y,
        };
        return nextPositions;
    }, {});
    return {
        delta,
        positions,
        groupMetrics: translateGroupMetrics(session.groupMetrics, delta.x, delta.y),
    };
};

export const offsetMultiDragPositions = (positionMap, offsetX, offsetY) => (
    Object.keys(positionMap || {}).reduce((nextPositions, id) => {
        nextPositions[id] = {
            x: positionMap[id].x + offsetX,
            y: positionMap[id].y + offsetY,
        };
        return nextPositions;
    }, {})
);
