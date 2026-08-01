<template>
  <div class="shell">
    <header class="top">
      <router-link to="/" class="brand">Civaris</router-link>
      <span class="tag">фаза 0</span>
    </header>
    <main>
      <router-view />
    </main>
  </div>
</template>

<style scoped>
.shell {
  max-width: 960px;
  margin: 0 auto;
  padding: 1.25rem 1rem 3rem;
}
.top {
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
  border-bottom: 1px solid #b7c4a4;
  padding-bottom: 0.75rem;
}
.brand {
  font-size: 1.6rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #1f3d28;
}
.tag {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #5a6b4e;
}
</style>
