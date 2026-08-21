export const normalizeImageAssetSrc = (source) => {
    if (typeof source !== 'string') {
        return '';
    }

    const sourceText = source.trim();
    if (!sourceText) {
        return sourceText;
    }

    const imagePathMatch = sourceText
        .replace(/\\/g, '/')
        .match(/(?:^|\/)(?:VIBuilder\/)?Images\/(.+)$/i);

    return imagePathMatch ? `Images/${imagePathMatch[1]}` : sourceText;
};
