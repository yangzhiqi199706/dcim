function round(value) {
  return Math.round(Number(value) * 100) / 100;
}

function positiveNumber(value, fallback = 0) {
  const num = Number(value);
  return Number.isFinite(num) && num > 0 ? num : fallback;
}

function createCoordinateMapper(sourceSize, targetSize = { width: 1920, height: 1080 }) {
  const scaleX = targetSize.width / positiveNumber(sourceSize.width, targetSize.width);
  const scaleY = targetSize.height / positiveNumber(sourceSize.height, targetSize.height);
  return {
    box(box) {
      return {
        x: round(Number(box.x || 0) * scaleX),
        y: round(Number(box.y || 0) * scaleY),
        width: round(positiveNumber(box.width) * scaleX),
        height: round(positiveNumber(box.height) * scaleY),
      };
    },
    point(point) {
      return {
        x: round(Number(point.x || 0) * scaleX),
        y: round(Number(point.y || 0) * scaleY),
      };
    },
  };
}

function normalizeOcrItems(rawItems) {
  if (!Array.isArray(rawItems)) return [];
  return rawItems
    .map((item) => {
      const box = item.box || item;
      const text = String(item.text || '').trim();
      const width = positiveNumber(box.width);
      const height = positiveNumber(box.height);
      if (!text || !width || !height) return null;
      return {
        text,
        x: Number(box.x || 0),
        y: Number(box.y || 0),
        width,
        height,
        confidence: Number.isFinite(Number(item.confidence)) ? Number(item.confidence) : 1,
      };
    })
    .filter(Boolean);
}

function createRectCandidates(rawRects) {
  if (!Array.isArray(rawRects)) return [];
  return rawRects
    .map((rect) => ({
      kind: 'rect',
      x: Number(rect.x || 0),
      y: Number(rect.y || 0),
      width: positiveNumber(rect.width),
      height: positiveNumber(rect.height),
      fill: rect.fill || 'rgba(22,50,107,0.25)',
      stroke: rect.stroke || '#00ffff',
      strokeWidth: Number.isFinite(Number(rect.strokeWidth)) ? Number(rect.strokeWidth) : 1,
      opacity: Number.isFinite(Number(rect.opacity)) ? Number(rect.opacity) : 1,
    }))
    .filter((rect) => rect.width >= 4 && rect.height >= 4);
}

function createLineCandidates(rawLines) {
  if (!Array.isArray(rawLines)) return [];
  return rawLines
    .map((line) => ({
      kind: 'line',
      points: [
        Number(line.x1 || 0),
        Number(line.y1 || 0),
        Number(line.x2 || 0),
        Number(line.y2 || 0),
      ],
      stroke: line.stroke || '#00ffff',
      strokeWidth: Number.isFinite(Number(line.strokeWidth)) ? Number(line.strokeWidth) : 1,
      opacity: Number.isFinite(Number(line.opacity)) ? Number(line.opacity) : 1,
    }))
    .filter((line) => line.points.some((value) => value !== 0));
}

module.exports = {
  createCoordinateMapper,
  normalizeOcrItems,
  createRectCandidates,
  createLineCandidates,
};
