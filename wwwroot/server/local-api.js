const express = require('express');
const { attachLocalApiRoutes } = require('./local-api-routes');

const app = express();
const port = Number(process.env.LOCAL_API_PORT || 8086);

app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

// Allow frontend served from another port (for production static hosting).
app.use((req, res, next) => {
  res.header('Access-Control-Allow-Origin', '*');
  res.header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  res.header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
  if (req.method === 'OPTIONS') {
    return res.sendStatus(204);
  }
  return next();
});

attachLocalApiRoutes(app, '/api/local');
// Compatibility route for old clients still posting to /imgData on port 8068.
attachLocalApiRoutes(app, '');

app.listen(port, () => {
  // eslint-disable-next-line no-console
  console.log(`[local-api] listening on http://0.0.0.0:${port}`);
});

