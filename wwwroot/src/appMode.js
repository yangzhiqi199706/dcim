export const APP_MODE_DESIGNER = 'designer';
export const APP_MODE_PREVIEW = 'preview';

export const resolveAppMode = (search = '') => {
    const params = new URLSearchParams(search);
    return params.get('type') || params.get('swiper')
        ? APP_MODE_PREVIEW
        : APP_MODE_DESIGNER;
};
