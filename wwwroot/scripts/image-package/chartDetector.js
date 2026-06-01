const { readPngRgba } = require('./shapeDetector');

function brightnessAt(image, x, y) {
  const offset = (y * image.width + x) * 4;
  const r = image.pixels[offset];
  const g = image.pixels[offset + 1];
  const b = image.pixels[offset + 2];
  const a = image.pixels[offset + 3];
  if (a < 32) return 0;
  return Math.max(r, g, b) - Math.min(r, g, b) + Math.max(r, g, b) * 0.35;
}

function activeMask(image, threshold) {
  const mask = [];
  for (let y = 0; y < image.height; y += 1) {
    for (let x = 0; x < image.width; x += 1) {
      mask.push(brightnessAt(image, x, y) >= threshold);
    }
  }
  return mask;
}

function findRuns(values, minLength) {
  const runs = [];
  let start = -1;
  for (let i = 0; i <= values.length; i += 1) {
    const active = i < values.length && values[i];
    if (active && start < 0) start = i;
    if ((!active || i === values.length) && start >= 0) {
      const end = i - 1;
      if (end - start + 1 >= minLength) runs.push({ start, end });
      start = -1;
    }
  }
  return runs;
}

function boundsFromRuns(runs) {
  return runs.reduce((bounds, run) => ({
    x1: Math.min(bounds.x1, run.x1),
    y1: Math.min(bounds.y1, run.y1),
    x2: Math.max(bounds.x2, run.x2),
    y2: Math.max(bounds.y2, run.y2),
  }), { x1: Infinity, y1: Infinity, x2: -Infinity, y2: -Infinity });
}

function expandBounds(bounds, image, padding) {
  return {
    x: Math.max(0, bounds.x1 - padding),
    y: Math.max(0, bounds.y1 - padding),
    width: Math.min(image.width - Math.max(0, bounds.x1 - padding), bounds.x2 - bounds.x1 + 1 + padding * 2),
    height: Math.min(image.height - Math.max(0, bounds.y1 - padding), bounds.y2 - bounds.y1 + 1 + padding * 2),
  };
}

function detectBarChart(image, mask, options) {
  const minBarHeight = options.minBarHeight || Math.max(12, Math.round(image.height * 0.18));
  const minBarWidth = options.minBarWidth || 3;
  const columns = [];

  for (let x = 0; x < image.width; x += 1) {
    const values = [];
    for (let y = 0; y < image.height; y += 1) {
      values.push(mask[y * image.width + x]);
    }
    const longest = findRuns(values, minBarHeight)
      .sort((a, b) => (b.end - b.start) - (a.end - a.start))[0];
    columns.push(Boolean(longest));
  }

  const barRuns = findRuns(columns, minBarWidth);
  if (barRuns.length < 3) return null;

  const runs = barRuns.map((run) => {
    let y1 = image.height;
    let y2 = 0;
    for (let x = run.start; x <= run.end; x += 1) {
      for (let y = 0; y < image.height; y += 1) {
        if (mask[y * image.width + x]) {
          y1 = Math.min(y1, y);
          y2 = Math.max(y2, y);
        }
      }
    }
    return { x1: run.start, y1, x2: run.end, y2 };
  });
  const bounds = boundsFromRuns(runs);
  return { cat: 'bar', ...expandBounds(bounds, image, options.padding || 8), title: 'Bar Chart' };
}

function detectLineChart(image, mask, options) {
  const minColumns = options.minLineColumns || Math.max(24, Math.round(image.width * 0.45));
  const points = [];
  for (let x = 0; x < image.width; x += 1) {
    let ySum = 0;
    let count = 0;
    for (let y = 0; y < image.height; y += 1) {
      if (mask[y * image.width + x]) {
        ySum += y;
        count += 1;
      }
    }
    if (count > 0 && count <= 5) {
      points.push({ x, y: Math.round(ySum / count) });
    }
  }
  if (points.length < minColumns) return null;

  const xs = points.map((point) => point.x);
  const ys = points.map((point) => point.y);
  const width = Math.max(...xs) - Math.min(...xs) + 1;
  const height = Math.max(...ys) - Math.min(...ys) + 1;
  if (width < minColumns || height < 6) return null;

  const bounds = {
    x1: Math.min(...xs),
    y1: Math.min(...ys),
    x2: Math.max(...xs),
    y2: Math.max(...ys),
  };
  return { cat: 'line', ...expandBounds(bounds, image, options.padding || 8), title: 'Line Chart' };
}

function detectChartRegionsFromPng(filePath, options = {}) {
  const image = readPngRgba(filePath);
  const mask = activeMask(image, options.colorThreshold || 120);
  const bar = detectBarChart(image, mask, options);
  const line = bar ? null : detectLineChart(image, mask, options);
  return { charts: [bar, line].filter(Boolean) };
}

module.exports = {
  detectChartRegionsFromPng,
};
