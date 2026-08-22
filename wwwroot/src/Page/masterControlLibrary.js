export const MASTER_CONTROL_KIND = 'master-control';

const clone = (value) => JSON.parse(JSON.stringify(value));

const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const createFallbackId = (index) => `master-control-${Date.now()}-${index}-${Math.random().toString(36).slice(2, 8)}`;

export const isMasterControlDefinition = (definition) => (
    !!definition
    && definition.kind === MASTER_CONTROL_KIND
    && Array.isArray(definition.shapes)
    && definition.shapes.length > 0
);

export const createMasterControlDefinition = (name, shapes) => {
    const sourceShapes = Array.isArray(shapes) ? shapes.filter(Boolean) : [];
    if (sourceShapes.length === 0) return null;

    const copiedShapes = clone(sourceShapes);
    const originX = Math.min(...copiedShapes.map((shape) => toNumber(shape.x)));
    const originY = Math.min(...copiedShapes.map((shape) => toNumber(shape.y)));

    return {
        kind: MASTER_CONTROL_KIND,
        version: 1,
        name: String(name || '').trim(),
        shapes: copiedShapes.map((shape) => ({
            ...shape,
            x: toNumber(shape.x) - originX,
            y: toNumber(shape.y) - originY,
        })),
    };
};

export const instantiateMasterControl = (definition, point, idFactory) => {
    if (!isMasterControlDefinition(definition)) {
        return { shapes: [], ids: [] };
    }

    const createId = typeof idFactory === 'function' ? idFactory : createFallbackId;
    const dropX = toNumber(point && point.x);
    const dropY = toNumber(point && point.y);
    const groupIds = new Map();
    const groupPrefix = `group_master_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

    const shapes = clone(definition.shapes).map((shape, index) => {
        const sourceGroupId = shape.groupId;
        let groupId = null;
        if (sourceGroupId) {
            if (!groupIds.has(sourceGroupId)) {
                groupIds.set(sourceGroupId, `${groupPrefix}_${groupIds.size}`);
            }
            groupId = groupIds.get(sourceGroupId);
        }

        const id = String(createId(index) || createFallbackId(index));
        return {
            ...shape,
            id,
            x: dropX + toNumber(shape.x),
            y: dropY + toNumber(shape.y),
            groupId,
        };
    });

    return {
        shapes,
        ids: shapes.map((shape) => shape.id),
    };
};

export default {
    createMasterControlDefinition,
    instantiateMasterControl,
    isMasterControlDefinition,
};
