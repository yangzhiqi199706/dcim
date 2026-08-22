import {
    REMOTE_SYNC_TARGET,
    createRemoteSyncPayload,
    getRemoteSyncMessageKey,
    isRemoteSyncJobActive,
    validateRemoteSyncForm,
} from './remoteSync';

const validForm = {
    remoteHost: '192.168.0.60',
    sshPort: 22,
    sshUser: 'root',
    sshPassword: 'system-secret',
    dbUser: 'root',
    dbPassword: 'database-secret',
    confirmed: true,
};

describe('remote sync contract', () => {
    test('uses the fixed database and VIBuilder Images target', () => {
        expect(REMOTE_SYNC_TARGET).toEqual({
            databaseName: 'dcim',
            imagesPath: '/dcim/admin/localhost_8086/wwwroot/public/VIBuilder/Images/',
        });
    });

    test('accepts a complete remote server form', () => {
        expect(validateRemoteSyncForm(validForm)).toEqual({});
    });

    test.each(['127.0.0.1', 'localhost', '0.0.0.0', '::1'])('rejects local target %s', (remoteHost) => {
        expect(validateRemoteSyncForm({ ...validForm, remoteHost }).remoteHost).toBe('localHost');
    });

    test('requires both passwords and destructive confirmation', () => {
        const errors = validateRemoteSyncForm({
            ...validForm,
            sshPassword: '',
            dbPassword: '',
            confirmed: false,
        });

        expect(errors.sshPassword).toBe('required');
        expect(errors.dbPassword).toBe('required');
        expect(errors.confirmed).toBe('required');
    });

    test('creates a request without allowing the fixed targets to be overridden', () => {
        const payload = createRemoteSyncPayload({
            ...validForm,
            databaseName: 'other',
            imagesPath: '/tmp/other',
        }, 'login-token');

        expect(payload).toEqual({
            token: 'login-token',
            remoteHost: '192.168.0.60',
            sshPort: 22,
            sshUser: 'root',
            sshPassword: 'system-secret',
            dbUser: 'root',
            dbPassword: 'database-secret',
        });
        expect(payload.databaseName).toBeUndefined();
        expect(payload.imagesPath).toBeUndefined();
    });

    test('polls only active jobs', () => {
        expect(isRemoteSyncJobActive({ state: 'running' })).toBe(true);
        expect(isRemoteSyncJobActive({ state: 'rolling_back' })).toBe(true);
        expect(isRemoteSyncJobActive({ state: 'completed' })).toBe(false);
        expect(isRemoteSyncJobActive({ state: 'failed' })).toBe(false);
    });

    test('maps production error codes to localized messages', () => {
        expect(getRemoteSyncMessageKey('REMOTE_SYNC_SSH_AUTH_FAILED')).toBe('designer.remoteSync.errors.sshAuthFailed');
        expect(getRemoteSyncMessageKey('REMOTE_SYNC_HOST_KEY_CHANGED')).toBe('designer.remoteSync.errors.hostKeyChanged');
        expect(getRemoteSyncMessageKey('REMOTE_SYNC_INSUFFICIENT_DISK_SPACE')).toBe('designer.remoteSync.errors.insufficientDisk');
        expect(getRemoteSyncMessageKey('REMOTE_SYNC_ALREADY_RUNNING')).toBe('designer.remoteSync.errors.alreadyRunning');
        expect(getRemoteSyncMessageKey('REMOTE_SYNC_ADMIN_AUTH_REQUIRED')).toBe('designer.remoteSync.errors.adminAuthRequired');
        expect(getRemoteSyncMessageKey('UNEXPECTED_MESSAGE')).toBe('');
    });
});
