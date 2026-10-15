import { createRouter, createWebHistory } from 'vue-router'
import HomeView from './views/HomeView.vue'
import WorldView from './views/WorldView.vue'
import VillageMonitorView from './views/VillageMonitorView.vue'
import SettingsView from './views/SettingsView.vue'
import CatalogView from './views/CatalogView.vue'
import MapView from './views/MapView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'home', component: HomeView },
    { path: '/world/:id', name: 'world', component: WorldView, props: true },
    {
      path: '/world/:id/village/:settlementId',
      name: 'village-monitor',
      component: VillageMonitorView,
      props: true,
    },
    { path: '/settings', name: 'settings', component: SettingsView },
    { path: '/catalog', name: 'catalog', component: CatalogView },
    { path: '/map', name: 'map', component: MapView },
  ],
})

export default router
