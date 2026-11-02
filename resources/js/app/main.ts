import '../../css/app.css';
import { createApp } from 'vue';
import { applyStoredAppearance } from '../design/appearance';
import App from './App.vue';
import { keepWarm } from './call';
import { makeRouter } from './router';

applyStoredAppearance();
createApp(App).use(makeRouter()).mount('#app');
keepWarm();
