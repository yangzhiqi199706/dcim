const getNavigator = () => (typeof navigator === 'undefined' ? null : navigator);
const getCaches = () => (typeof caches === 'undefined' ? null : caches);

export const retireServiceWorkers = async ({
    navigatorRef = getNavigator(),
    cachesRef = getCaches(),
} = {}) => {
    const serviceWorker = navigatorRef && navigatorRef.serviceWorker;
    if (!serviceWorker || typeof serviceWorker.getRegistrations !== 'function') return;

    const registrations = await serviceWorker.getRegistrations();
    await Promise.all(registrations.map((registration) => registration.unregister()));

    if (!cachesRef || typeof cachesRef.keys !== 'function' || typeof cachesRef.delete !== 'function') return;

    const cacheKeys = await cachesRef.keys();
    await Promise.all(cacheKeys.map((cacheKey) => cachesRef.delete(cacheKey)));
};
