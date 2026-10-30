<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Bell } from 'lucide-vue-next'
import { notices } from '../api/endpoints'
import { errorText, session } from '../api/session'
import type { Notice } from '../api/types'
import { noticeText } from '../state/labels'
import { refreshLive } from '../state/live'
import { dateText, notify, tr, ui } from '../state/ui'

const emit = defineEmits<{ go: [notice: Notice]; login: [] }>()

const list = ref<Notice[]>([])
const cursor = ref<string | null>(null)
const unreadOnly = ref(false)
const loading = ref(false)

async function load(more = false) {
  if (!session.signedIn.value) return
  loading.value = true
  try {
    const page = await notices.list({ unread: unreadOnly.value, cursor: more ? cursor.value : null })
    list.value = more ? [...list.value, ...page.data] : page.data
    cursor.value = page.next_cursor
  } catch (e) {
    notify(errorText(e, ui.lang))
  } finally {
    loading.value = false
  }
}

async function open(n: Notice) {
  if (!n.read) {
    n.read = true
    await notices.read(n.id).catch(() => undefined)
    void refreshLive()
  }
  emit('go', n)
}

async function readAll() {
  await notices.readAll().catch(() => undefined)
  list.value.forEach((n) => (n.read = true))
  void refreshLive()
}

onMounted(() => load())
</script>

<template>
  <section class="card overflow-hidden">
    <div class="space-y-3 border-b border-slate-100 p-5 dark:border-slate-800">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-extrabold">{{ tr('اعلان‌ها', 'Notifications') }}</h1>
        <button v-if="session.signedIn.value" class="text-sm font-bold text-blue-600" @click="readAll">{{ tr('همه خوانده شد', 'Mark all read') }}</button>
      </div>
      <div class="flex gap-2">
        <button class="chip" :class="!unreadOnly ? 'chip-on' : 'chip-off'" @click="unreadOnly = false; load()">{{ tr('همه', 'All') }}</button>
        <button class="chip" :class="unreadOnly ? 'chip-on' : 'chip-off'" @click="unreadOnly = true; load()">{{ tr('خوانده‌نشده', 'Unread') }}</button>
      </div>
    </div>
    <div v-if="!session.signedIn.value" class="space-y-3 p-8 text-center">
      <p class="soft text-sm">{{ tr('برای دیدن اعلان‌ها وارد شوید.', 'Log in to see notifications.') }}</p>
      <button class="v3-primary !w-auto px-6" @click="emit('login')">{{ tr('ورود', 'Log in') }}</button>
    </div>
    <div v-else-if="!list.length && !loading" class="p-12 text-center">
      <Bell :size="36" class="soft mx-auto mb-3" />
      <p class="font-bold">{{ tr('اعلان جدیدی نیست', 'No notifications') }}</p>
    </div>
    <button v-for="n in list" :key="n.id" class="flex w-full items-start gap-3 border-b border-slate-100 p-4 text-start transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800" @click="open(n)">
      <span class="mt-2 h-2.5 w-2.5 shrink-0 rounded-full" :class="n.read ? 'bg-transparent' : 'bg-blue-600'"></span>
      <span class="min-w-0 flex-1">
        <span class="block text-sm leading-7" :class="n.read ? '' : 'font-bold'">{{ noticeText(n) }}</span>
        <span class="soft text-xs">{{ dateText(n.created_at, true) }}</span>
      </span>
    </button>
    <button v-if="cursor" class="chip chip-off m-4" :disabled="loading" @click="load(true)">{{ tr('بیشتر', 'More') }}</button>
  </section>
</template>
