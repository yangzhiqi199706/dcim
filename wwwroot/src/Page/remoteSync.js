export const REMOTE_SYNC_TARGET = Object.freeze({
    databaseName: 'dcim',
    imagesPath: '/dcim/admin/localhost_8086/wwwroot/public/VIBuilder/Images/',
});

const LOCAL_HOSTS = new Set(['127.0.0.1', 'localhost', '0.0.0.0', '::1']);
const ACTIVE_JOB_STATES = new Set([
    'queued',
    'preflight',
    'preparing_remote',
    'stopping_service',
    'backing_up',
    'transferring',
    'validating',
    'restoring_database',
    'switching_images',
    'starting_service',
    'running',
    'rolling_back',
]);

const REMOTE_SYNC_MESSAGE_KEYS = Object.freeze({
    REMOTE_SYNC_ADMIN_AUTH_REQUIRED: 'designer.remoteSync.errors.adminAuthRequired',
    REMOTE_SYNC_INPUT_INVALID: 'designer.remoteSync.errors.invalidInput',
    REMOTE_SYNC_LOCAL_TARGET_REJECTED: 'designer.remoteSync.errors.localTarget',
    REMOTE_SYNC_ALREADY_RUNNING: 'designer.remoteSync.errors.alreadyRunning',
    REMOTE_SYNC_LOCAL_TOOL_MISSING: 'designer.remoteSync.errors.missingTool',
    REMOTE_SYNC_CONNECTION_TIMEOUT: 'designer.remoteSync.errors.connectionTimeout',
    REMOTE_SYNC_SSH_AUTH_FAILED: 'designer.remoteSync.errors.sshAuthFailed',
    REMOTE_SYNC_HOST_KEY_CHANGED: 'designer.remoteSync.errors.hostKeyChanged',
    REMOTE_SYNC_PREFLIGHT_FAILED: 'designer.remoteSync.errors.preflightFailed',
    REMOTE_SYNC_PREFLIGHT_INCOMPLETE: 'designer.remoteSync.errors.preflightFailed',
    REMOTE_SYNC_REMOTE_SNAPSHOT_FAILED: 'designer.remoteSync.errors.snapshotFailed',
    REMOTE_SYNC_REMOTE_SNAPSHOT_INCOMPLETE: 'designer.remoteSync.errors.snapshotFailed',
    REMOTE_SYNC_TRANSFER_FAILED: 'designer.remoteSync.errors.transferFailed',
    REMOTE_SYNC_CHECKSUM_MISMATCH: 'designer.remoteSync.errors.validationFailed',
    REMOTE_SYNC_DATABASE_DUMP_INVALID: 'designer.remoteSync.errors.validationFailed',
    REMOTE_SYNC_IMAGES_ARCHIVE_INVALID: 'designer.remoteSync.errors.validationFailed',
    REMOTE_SYNC_IMAGES_ARCHIVE_UNSAFE: 'designer.remoteSync.errors.validationFailed',
    REMOTE_SYNC_INSUFFICIENT_DISK_SPACE: 'designer.remoteSync.errors.insufficientDisk',
    REMOTE_SYNC_DATABASE_VALIDATION_IMPORT_FAILED: 'designer.remoteSync.errors.databaseValidationFailed',
    REMOTE_SYNC_DATABASE_VALIDATION_EMPTY: 'designer.remoteSync.errors.databaseValidationFailed',
    REMOTE_SYNC_SERVICE_STATUS_FAILED: 'designer.remoteSync.errors.serviceFailed',
    REMOTE_SYNC_SERVICE_STOP_FAILED: 'designer.remoteSync.errors.serviceFailed',
    REMOTE_SYNC_LOCAL_DATABASE_BACKUP_FAILED: 'designer.remoteSync.errors.backupFailed',
    REMOTE_SYNC_LOCAL_IMAGES_BACKUP_FAILED: 'designer.remoteSync.errors.backupFailed',
    REMOTE_SYNC_DATABASE_RESTORE_FAILED: 'designer.remoteSync.errors.databaseRestoreFailed',
    REMOTE_SYNC_IMAGES_SWITCH_FAILED: 'designer.remoteSync.errors.imagesReplaceFailed',
    REMOTE_SYNC_IMAGES_ROLLBACK_FAILED: 'designer.remoteSync.errors.rollbackFailed',
    REMOTE_SYNC_DATABASE_ROLLBACK_FAILED: 'designer.remoteSync.errors.rollbackFailed',
    REMOTE_SYNC_MAINTENANCE: 'designer.remoteSync.errors.maintenance',
    REMOTE_SYNC_UNEXPECTED_ERROR: 'designer.remoteSync.errors.requestFailed',
});

const clean = (value) => String(value === undefined || value === null ? '' : value).trim();

export function validateRemoteSyncForm(form = {}) {
    const errors = {};
    const host = clean(form.remoteHost).toLowerCase();
    const sshPort = Number(form.sshPort);

    if (!host) {
        errors.remoteHost = 'required';
    } else if (LOCAL_HOSTS.has(host)) {
        errors.remoteHost = 'localHost';
    } else if (!/^[a-z0-9.-]+$/i.test(host)) {
        errors.remoteHost = 'invalid';
    }

    if (!Number.isInteger(sshPort) || sshPort < 1 || sshPort > 65535) {
        errors.sshPort = 'invalid';
    }
    if (!clean(form.sshUser)) errors.sshUser = 'required';
    if (!String(form.sshPassword || '')) errors.sshPassword = 'required';
    if (!clean(form.dbUser)) errors.dbUser = 'required';
    if (!String(form.dbPassword || '')) errors.dbPassword = 'required';
    if (!form.confirmed) errors.confirmed = 'required';

    return errors;
}

export function createRemoteSyncPayload(form = {}, token = '') {
    return {
        token: clean(token),
        remoteHost: clean(form.remoteHost),
        sshPort: Number(form.sshPort || 22),
        sshUser: clean(form.sshUser),
        sshPassword: String(form.sshPassword || ''),
        dbUser: clean(form.dbUser),
        dbPassword: String(form.dbPassword || ''),
    };
}

export function isRemoteSyncJobActive(status) {
    return Boolean(status && ACTIVE_JOB_STATES.has(String(status.state || status.phase || '')));
}

export function getRemoteSyncMessageKey(message) {
    const code = clean(message).split(':')[0];
    return REMOTE_SYNC_MESSAGE_KEYS[code] || '';
}
