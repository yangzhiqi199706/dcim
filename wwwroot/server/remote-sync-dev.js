function productionOnly(_req, res) {
  return res.json({ code: 400, msg: 'REMOTE_SYNC_PRODUCTION_ONLY', data: [] });
}

function jobNotFound(_req, res) {
  return res.json({ code: 400, msg: 'REMOTE_SYNC_JOB_NOT_FOUND', data: [] });
}

function createRemoteSyncDevelopmentHandlers() {
  return {
    preflight: productionOnly,
    start: productionOnly,
    status: jobNotFound,
  };
}

module.exports = {
  createRemoteSyncDevelopmentHandlers,
};
