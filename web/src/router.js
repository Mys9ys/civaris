import { createRouter, createWebHistory } from 'vue-router'
import HomeView from './views/HomeView.vue'
import WorldView from './views/WorldView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'home', component: HomeView },
    { path: '/world/:id', name: 'world', component: WorldView, props: true },
  ],
})

export default router
