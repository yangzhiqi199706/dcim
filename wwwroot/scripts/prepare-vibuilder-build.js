const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');

const sourceDir = path.join(__dirname, '..', 'build');
const targetDir = path.join(os.tmpdir(), 'vibuilder-build');
const publicPath = '/VIBuilder';

function withPublicPath(url) {
    if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith(`${publicPath}/`)) {
        return url;
    }
    return `${publicPath}${url}`;
}

function rewriteFile(filePath, transform) {
    const source = fs.readFileSync(filePath, 'utf8');
    fs.writeFileSync(filePath, transform(source), 'utf8');
}

function copyDirectory(source, target) {
    fs.mkdirSync(target, { recursive: true });
    fs.readdirSync(source, { withFileTypes: true }).forEach((entry) => {
        const sourcePath = path.join(source, entry.name);
        const targetPath = path.join(target, entry.name);
        if (entry.isDirectory()) {
            copyDirectory(sourcePath, targetPath);
        } else {
            fs.copyFileSync(sourcePath, targetPath);
        }
    });
}

if (!fs.existsSync(sourceDir)) {
    throw new Error(`Build directory does not exist: ${sourceDir}`);
}

fs.rmSync(targetDir, { recursive: true, force: true });
copyDirectory(sourceDir, targetDir);

const manifestPath = path.join(targetDir, 'asset-manifest.json');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const runtimeVersion = crypto.createHash('sha256').update(manifest['main.js']).digest('hex').slice(0, 12);

const indexPath = path.join(targetDir, 'index.html');
rewriteFile(indexPath, (source) => source
    .replace(/(["'])\/(?!VIBuilder\/)(runtime-endpoints\.js|static\/)/g, `$1${publicPath}/$2`)
    .replace(`${publicPath}/runtime-endpoints.js`, `${publicPath}/runtime-endpoints.js?v=${runtimeVersion}`)
    .replace('p.p="/"', `p.p="${publicPath}/"`));

const runtimeEndpointsPath = path.join(targetDir, 'runtime-endpoints.js');
rewriteFile(runtimeEndpointsPath, (source) => source
    .replace(/appPort:\s*'[^']*'/, "appPort: '8086/VIBuilder'"));

Object.keys(manifest).forEach((key) => {
    manifest[key] = withPublicPath(manifest[key]);
});
fs.writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');

const indexRevision = crypto.createHash('sha256').update(fs.readFileSync(indexPath, 'utf8')).digest('hex');

fs.readdirSync(targetDir)
    .filter((file) => file.startsWith('precache-manifest.') && file.endsWith('.js'))
    .forEach((file) => {
        rewriteFile(path.join(targetDir, file), (source) => source
            .replace(/("url":\s*")\/(?!VIBuilder\/)/g, `$1${publicPath}/`)
            .replace(/("revision":\s*")[^"]+("\s*,\s*"url":\s*"\/VIBuilder\/index\.html")/, `$1${indexRevision}$2`));
    });

const serviceWorkerPath = path.join(targetDir, 'service-worker.js');
if (fs.existsSync(serviceWorkerPath)) {
    rewriteFile(serviceWorkerPath, (source) => source
        .replace(/(["'])\/(?!VIBuilder\/)(precache-manifest\.[^"']+\.js|index\.html)/g, `$1${publicPath}/$2`)
        .concat(`\n// VIBuilder deployment revision: ${indexRevision}\n`));
}

console.log(`Prepared VIBuilder deployment output: ${targetDir}`);
