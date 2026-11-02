import '../../css/app.css';
import { createApp } from 'vue';
import { applyStoredAppearance } from '../design/appearance';
import App from './App.vue';
import { keepSynced } from './call';
import { listenForInstall } from './install';
import PwaOverlay from './PwaOverlay.vue';
import { makeRouter } from './router';
import { registerServiceWorker } from './updates';

applyStoredAppearance();
// Chrome fires its install prompt once, early; setup's "Start my plan" offers it (PWA-003).
listenForInstall();
createApp(App).use(makeRouter()).mount('#app');
keepSynced();

// The service worker exists only in a production build.
if (import.meta.env.PROD) registerServiceWorker();
// A second root restarts useId(), so its ids get their own prefix to stay unique in the page.
const overlay = createApp(PwaOverlay);
overlay.config.idPrefix = 'pwa';
overlay.mount(document.body.appendChild(document.createElement('div')));
