import { createRouter, createWebHistory } from 'vue-router'
import HomeView from './views/HomeView.vue'
import WorldView from './views/WorldView.vue'
import SettingsView from './views/SettingsView.vue'
import MapView from './views/MapView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'home', component: HomeView },
    { path: '/world/:id', name: 'world', component: WorldView, props: true },
    { path: '/settings', name: 'settings', component: SettingsView },
    { path: '/map', name: 'map', component: MapView },
  ],
})

export default router
