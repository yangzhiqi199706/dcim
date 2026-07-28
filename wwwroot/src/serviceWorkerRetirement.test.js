import { retireServiceWorkers } from './serviceWorkerRetirement';

describe('retireServiceWorkers', () => {
    test('unregisters historical workers and clears their cache storage', async () => {
        const firstRegistration = { unregister: jest.fn().mockResolvedValue(true) };
        const secondRegistration = { unregister: jest.fn().mockResolvedValue(true) };
        const navigatorRef = {
            serviceWorker: {
                getRegistrations: jest.fn().mockResolvedValue([firstRegistration, secondRegistration]),
            },
        };
        const cachesRef = {
            keys: jest.fn().mockResolvedValue(['workbox-precache-v2', 'runtime-cache']),
            delete: jest.fn().mockResolvedValue(true),
        };

        await retireServiceWorkers({ navigatorRef, cachesRef });

        expect(navigatorRef.serviceWorker.getRegistrations).toHaveBeenCalledTimes(1);
        expect(firstRegistration.unregister).toHaveBeenCalledTimes(1);
        expect(secondRegistration.unregister).toHaveBeenCalledTimes(1);
        expect(cachesRef.delete).toHaveBeenCalledWith('workbox-precache-v2');
        expect(cachesRef.delete).toHaveBeenCalledWith('runtime-cache');
    });

    test('does nothing when the browser has no service worker support', async () => {
        const cachesRef = {
            keys: jest.fn(),
            delete: jest.fn(),
        };

        await expect(retireServiceWorkers({ navigatorRef: {}, cachesRef })).resolves.toBeUndefined();

        expect(cachesRef.keys).not.toHaveBeenCalled();
    });
});
