export const arePreviewElementPropsEqual = (previous, next) => (
    previous.shapeProps === next.shapeProps
    && previous.id === next.id
    && previous.wheight === next.wheight
    && previous.wwidth === next.wwidth
    && previous.wscale === next.wscale
    && previous.isSwiper === next.isSwiper
    && previous.useSlaveId === next.useSlaveId
);

export const getAlarmListRows = (data) => (
    Array.isArray(data) ? data.filter(Boolean) : []
);
