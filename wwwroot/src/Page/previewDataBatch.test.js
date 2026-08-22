import {
    createPreviewRefreshChannels,
    createEmptyPreviewResponse,
    createNonOverlappingRunner,
    mergeSuccessfulPreviewData,
    runPreviewDataBatch,
    runPreviewDataBatchWithStatus
} from './previewDataBatch';

describe('runPreviewDataBatch', () => {
    test('starts every enabled request before waiting for any result', async () => {
        const starts = [];
        let resolveRealtime;
        const realtime = new Promise(resolve => {
            resolveRealtime = resolve;
        });

        const resultPromise = runPreviewDataBatch([
            {
                key: 'protocol',
                run: () => {
                    starts.push('protocol');
                    return Promise.resolve({ data: ['protocol'] });
                },
                fallback: createEmptyPreviewResponse
            },
            {
                key: 'realtime',
                run: () => {
                    starts.push('realtime');
                    return realtime;
                },
                fallback: createEmptyPreviewResponse
            }
        ]);

        expect(starts).toEqual(['protocol', 'realtime']);

        resolveRealtime({ data: ['realtime'] });
        await expect(resultPromise).resolves.toEqual({
            protocol: { data: ['protocol'] },
            realtime: { data: ['realtime'] }
        });
    });

    test('replaces a rejected optional request with its empty fallback', async () => {
        await expect(runPreviewDataBatch([
            {
                key: 'alarm',
                run: () => Promise.reject(new Error('offline')),
                fallback: createEmptyPreviewResponse
            },
            {
                key: 'realtime',
                run: () => Promise.resolve({ data: ['online'] }),
                fallback: createEmptyPreviewResponse
            }
        ])).resolves.toEqual({
            alarm: { data: [] },
            realtime: { data: ['online'] }
        });
    });

    test('reports failed task keys while preserving the fallback value', async () => {
        await expect(runPreviewDataBatchWithStatus([
            {
                key: 'realtime',
                run: () => Promise.reject(new Error('offline')),
                fallback: createEmptyPreviewResponse
            },
            {
                key: 'alarm',
                run: () => Promise.resolve({ data: ['fresh'] }),
                fallback: createEmptyPreviewResponse
            }
        ])).resolves.toEqual({
            values: {
                realtime: { data: [] },
                alarm: { data: ['fresh'] }
            },
            failedKeys: ['realtime']
        });
    });

    test('retains the last successful response for failed refresh tasks', () => {
        const previous = {
            realtime: { data: ['last-known'] },
            alarm: { data: ['previous-alarm'] }
        };

        expect(mergeSuccessfulPreviewData(previous, {
            values: {
                realtime: { data: [] },
                alarm: { data: ['fresh-alarm'] }
            },
            failedKeys: ['realtime']
        })).toEqual({
            realtime: { data: ['last-known'] },
            alarm: { data: ['fresh-alarm'] }
        });
    });
});

describe('createNonOverlappingRunner', () => {
    test('skips a refresh while the current refresh is pending', async () => {
        const runSingleFlight = createNonOverlappingRunner();
        let resolveFirst;
        const first = runSingleFlight(() => new Promise(resolve => {
            resolveFirst = resolve;
        }));
        const secondTask = jest.fn(() => Promise.resolve('second'));

        await expect(runSingleFlight(secondTask)).resolves.toEqual({ started: false });
        expect(secondTask).not.toHaveBeenCalled();

        resolveFirst('first');
        await expect(first).resolves.toEqual({ started: true, value: 'first' });
    });

    test('accepts a later refresh after a rejected refresh settles', async () => {
        const runSingleFlight = createNonOverlappingRunner();

        await expect(runSingleFlight(() => Promise.reject(new Error('offline')))).rejects.toThrow('offline');
        await expect(runSingleFlight(() => Promise.resolve('recovered'))).resolves.toEqual({
            started: true,
            value: 'recovered'
        });
    });

    test('allows realtime work while a background refresh remains pending', async () => {
        const channels = createPreviewRefreshChannels();
        let resolveBackground;
        const background = channels.background(() => new Promise(resolve => {
            resolveBackground = resolve;
        }));
        const realtimeTask = jest.fn(() => Promise.resolve('realtime'));

        await expect(channels.realtime(realtimeTask)).resolves.toEqual({
            started: true,
            value: 'realtime'
        });
        expect(realtimeTask).toHaveBeenCalledTimes(1);

        resolveBackground('background');
        await expect(background).resolves.toEqual({ started: true, value: 'background' });
    });
});
