export const createEmptyPreviewResponse = () => ({ data: [] });

const resolveFallback = (fallback) => {
    if (typeof fallback === 'function') {
        return fallback();
    }
    return fallback || createEmptyPreviewResponse();
};

const runTaskWithStatus = (task) => {
    let request;
    try {
        request = task.run();
    } catch (error) {
        return Promise.resolve({
            key: task.key,
            value: resolveFallback(task.fallback),
            failed: true
        });
    }

    return Promise.resolve(request)
        .then(value => ({
            key: task.key,
            value: value || resolveFallback(task.fallback),
            failed: false
        }))
        .catch(() => ({
            key: task.key,
            value: resolveFallback(task.fallback),
            failed: true
        }));
};

export const runPreviewDataBatchWithStatus = (tasks) => {
    const enabledTasks = (Array.isArray(tasks) ? tasks : [])
        .filter(task => task && task.key && typeof task.run === 'function');

    return Promise.all(enabledTasks.map(runTaskWithStatus))
        .then(entries => entries.reduce((result, entry) => {
            result.values[entry.key] = entry.value;
            if (entry.failed) result.failedKeys.push(entry.key);
            return result;
        }, { values: {}, failedKeys: [] }));
};

export const runPreviewDataBatch = (tasks) => runPreviewDataBatchWithStatus(tasks)
    .then(result => result.values);

export const mergeSuccessfulPreviewData = (previous, batchResult) => {
    const values = batchResult && batchResult.values ? batchResult.values : {};
    const failedKeys = new Set(batchResult && Array.isArray(batchResult.failedKeys)
        ? batchResult.failedKeys
        : []);

    return Object.keys(values).reduce((result, key) => {
        if (!failedKeys.has(key)) result[key] = values[key];
        return result;
    }, { ...(previous || {}) });
};

export const createNonOverlappingRunner = () => {
    let inFlight = false;

    return async (run) => {
        if (inFlight) {
            return { started: false };
        }

        inFlight = true;
        try {
            return {
                started: true,
                value: await run()
            };
        } finally {
            inFlight = false;
        }
    };
};

export const createPreviewRefreshChannels = () => ({
    realtime: createNonOverlappingRunner(),
    background: createNonOverlappingRunner()
});
