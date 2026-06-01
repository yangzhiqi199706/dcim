function toNumber(value) {
  const normalized = String(value == null ? '' : value).replace(/[^\d.-]/g, '');
  const num = Number(normalized);
  return Number.isFinite(num) ? num : null;
}

function centerOf(item) {
  return {
    x: Number(item.x || 0) + Number(item.width || 0) / 2,
    y: Number(item.y || 0) + Number(item.height || 0) / 2,
  };
}

function isWetHtmlLabel(text) {
  return /^[A-Za-z0-9_-]+\s*#$/.test(String(text || '').trim());
}

function isLeakWaterLabel(text) {
  const normalized = String(text || '').trim().toLowerCase();
  return normalized === 'leak'
    || normalized === 'leakwater'
    || normalized.includes('\u6f0f\u6c34')
    || normalized.includes('\u6f0f\u6db2');
}

function candidateScore(label, numberItem) {
  const labelCenter = centerOf(label);
  const itemCenter = centerOf(numberItem);
  const dx = itemCenter.x - labelCenter.x;
  const dy = itemCenter.y - labelCenter.y;
  if (dx < -40 || dx > 260 || dy < -50 || dy > 140) return null;
  const leftSidePenalty = dx < 0 ? 1000 : 0;
  return leftSidePenalty + Math.abs(dx) + Math.abs(dy) * 1.5;
}

function groupNumbersByNearestLabel(labels, items) {
  const groups = new Map(labels.map((label) => [label, []]));
  items.forEach((item) => {
    const value = toNumber(item.text);
    if (value == null) return;
    const best = labels
      .map((label) => ({ label, score: candidateScore(label, item) }))
      .filter((candidate) => candidate.score != null)
      .sort((a, b) => a.score - b.score)[0];
    if (!best) return;
    groups.get(best.label).push({ item, value, distance: best.score });
  });
  return groups;
}

function detectWetHtmlComponentsFromOcr(ocrItems) {
  if (!Array.isArray(ocrItems)) return [];
  const labels = ocrItems.filter((item) => isWetHtmlLabel(item.text));
  const groupedNumbers = groupNumbersByNearestLabel(
    labels,
    ocrItems.filter((item) => !labels.includes(item)),
  );
  return labels
    .map((label) => {
      const numbers = (groupedNumbers.get(label) || [])
        .sort((a, b) => a.distance - b.distance);
      if (numbers.length < 2) return null;
      return {
        selector: { className: 'wetHtml' },
        x: Number(label.x || 0),
        y: Number(label.y || 0),
        sourceOcrItems: [label, numbers[0].item, numbers[1].item],
        attrs: {
          text: String(label.text || '').trim(),
          dataWen: numbers[0].value,
          dataWet: numbers[1].value,
        },
      };
    })
    .filter(Boolean);
}

function detectLeakWaterComponentsFromOcr(ocrItems) {
  if (!Array.isArray(ocrItems)) return [];
  return ocrItems
    .filter((item) => isLeakWaterLabel(item.text))
    .map((item) => ({
      selector: { className: 'leakWater' },
      x: Number(item.x || 0),
      y: Number(item.y || 0),
      sourceOcrItems: [item],
    }));
}

function detectBusinessComponentsFromOcr(ocrItems) {
  return [
    ...detectWetHtmlComponentsFromOcr(ocrItems),
    ...detectLeakWaterComponentsFromOcr(ocrItems),
  ];
}

module.exports = {
  detectBusinessComponentsFromOcr,
  detectLeakWaterComponentsFromOcr,
  detectWetHtmlComponentsFromOcr,
};
