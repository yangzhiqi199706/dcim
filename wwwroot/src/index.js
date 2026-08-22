import React, { Suspense } from 'react';
import ReactDOM from 'react-dom/client';
import { APP_MODE_PREVIEW, resolveAppMode } from './appMode';
import { retireServiceWorkers } from './serviceWorkerRetirement';

const PreviewApp = React.lazy(() => import('./Page/PreviewApp'));
const DesignerApp = React.lazy(() => import('./Page/DesignerApp'));
const RibbonToolbarPreviewApp = React.lazy(() => import('./RibbonToolbarPreviewApp'));

function AppRouter() {
  const isLocalRibbonPreview = window.location.hostname === 'localhost'
    && new URLSearchParams(window.location.search).get('ribbonPreview') === '1';
  const App = isLocalRibbonPreview
    ? RibbonToolbarPreviewApp
    : (resolveAppMode(window.location.search) === APP_MODE_PREVIEW ? PreviewApp : DesignerApp);

  return (
    <Suspense fallback={<div aria-busy="true" />}>
      <App />
    </Suspense>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <AppRouter />
);

if (process.env.NODE_ENV === 'production') {
  retireServiceWorkers().catch(() => {});
}
