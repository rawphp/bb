/// <reference lib="webworker" />
import { precacheAndRoute } from 'workbox-precaching';

declare const self: ServiceWorkerGlobalScope;

// CACHE (#18) adds the shell route and read caching; SHELL_PWA registers it.
precacheAndRoute(self.__WB_MANIFEST);
