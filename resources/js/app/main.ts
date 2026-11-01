import '../../css/app.css';
import { createApp } from 'vue';
import App from './App.vue';
import { makeRouter } from './router';

createApp(App).use(makeRouter()).mount('#app');
