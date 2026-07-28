import React from 'react';
import ReactDOM from 'react-dom/client';
import './Assets/style.css';
import Home from './Page/Home';
import { retireServiceWorkers } from './serviceWorkerRetirement';

ReactDOM.createRoot(document.getElementById('root')).render(
  // <React.StrictMode>
    <Home />
  // </React.StrictMode>
);

if (process.env.NODE_ENV === 'production') {
  retireServiceWorkers().catch(() => {});
}
