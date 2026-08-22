const isSelectableShape = (shape) => (
    !!shape
    && shape.id !== undefined
    && shape.id !== null
    && String(shape.id).length > 0
    && shape.draggable !== false
);

export const getSelectAllSelectionState = (shapes) => {
    const ids = [];
    const seen = new Set();

    (Array.isArray(shapes) ? shapes : []).forEach((shape) => {
        if (!isSelectableShape(shape) || seen.has(shape.id)) return;
        seen.add(shape.id);
        ids.push(shape.id);
    });

    if (ids.length === 0) {
        return { selectedId: null, selectedIds: [] };
    }

    if (ids.length === 1) {
        return { selectedId: ids[0], selectedIds: [] };
    }

    return { selectedId: ids[0], selectedIds: ids };
};
