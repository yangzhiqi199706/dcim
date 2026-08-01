const LOCAL_DEVELOPMENT_HOSTS = new Set(['localhost', '127.0.0.1', '::1', '[::1]']);

export const shouldRequireDesignerLogin = (hostname = '') => (
    !LOCAL_DEVELOPMENT_HOSTS.has(String(hostname).trim().toLowerCase())
);
