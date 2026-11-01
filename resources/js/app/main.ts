import '../../css/app.css';
import { createApp } from 'vue';
import App from './App.vue';
import { keepWarm } from './call';
import { makeRouter } from './router';

createApp(App).use(makeRouter()).mount('#app');
keepWarm();
