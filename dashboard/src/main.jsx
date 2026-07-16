import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles/app.css';

const root = document.getElementById('spliteezy-app');
if (root) {
  createRoot(root).render(
    <StrictMode>
      <App config={window.SpliteezyAdmin} />
    </StrictMode>
  );
}
