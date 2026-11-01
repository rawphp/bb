import '../../css/app.css';
import { createApp } from 'vue';
import { applyStoredAppearance } from '../design/appearance';
import App from './App.vue';
import { makeRouter } from './router';

applyStoredAppearance();
createApp(App).use(makeRouter()).mount('#app');
