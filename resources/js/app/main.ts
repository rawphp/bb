import '../../css/app.css';
import { createApp } from 'vue';
import { applyStoredAppearance } from '../design/appearance';
import App from './App.vue';
import { keepWarm } from './call';
import PwaOverlay from './PwaOverlay.vue';
import { makeRouter } from './router';
import { registerServiceWorker } from './updates';

applyStoredAppearance();
createApp(App).use(makeRouter()).mount('#app');
keepWarm();

// The service worker exists only in a production build.
if (import.meta.env.PROD) registerServiceWorker();
createApp(PwaOverlay).mount(document.body.appendChild(document.createElement('div')));
