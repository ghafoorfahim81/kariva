<script setup lang="ts">
import { X } from 'lucide-vue-next'
defineProps<{ title?: string; wide?: boolean }>()
const emit = defineEmits<{ close: [] }>()
</script>

<template>
  <div class="fixed inset-0 z-[160] flex items-start justify-center overflow-y-auto bg-slate-950/70 p-3 sm:p-8" @click.self="emit('close')">
    <section class="card w-full space-y-4 p-5 sm:p-6" :class="wide ? 'max-w-2xl' : 'max-w-lg'">
      <div class="flex items-center justify-between gap-3">
        <h2 class="text-xl font-extrabold">{{ title }}</h2>
        <button type="button" class="rounded-lg p-1 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Close" @click="emit('close')"><X /></button>
      </div>
      <slot />
    </section>
  </div>
</template>
