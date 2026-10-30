<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { actors, reviews, trust } from '../api/endpoints'
import { errorText, session } from '../api/session'
import type { Actor, Review } from '../api/types'
import { tagText } from '../state/labels'
import { dateText, notify, tr, ui } from '../state/ui'
import Sheet from './Sheet.vue'

/** Public reviews of one person or page. The provider (acting as that actor) can reply once; anyone signed in can report. */
const props = defineProps<{ actorId: string; name: string }>()
const emit = defineEmits<{ close: []; login: [] }>()

const actor = ref<Actor | null>(null)
const list = ref<Review[]>([])
const cursor = ref<string | null>(null)
const replyText = ref<Record<string, string>>({})

const isOwner = () => session.state.activeActorId === props.actorId

async function load(more = false) {
  try {
    const page = await reviews.ofActor(props.actorId, more ? cursor.value : null)
    list.value = more ? [...list.value, ...page.data] : page.data
    cursor.value = page.next_cursor
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

async function reply(r: Review) {
  const body = replyText.value[r.id]?.trim()
  if (!body) return
  try {
    r.reply = (await reviews.reply(r.id, body)).reply
    notify(tr('پاسخ شما ثبت شد ✓', 'Your reply was saved ✓'))
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

async function report(r: Review) {
  if (!session.signedIn.value) return emit('login')
  const reason = window.prompt(tr('دلیل گزارش را کوتاه بنویسید', 'Why are you reporting this review?'))
  if (!reason?.trim()) return
  try {
    await trust.report('review', r.id, reason.trim())
    notify(tr('گزارش شما ثبت شد؛ مدیر بررسی می‌کند', 'Reported; an admin will check'))
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

onMounted(async () => {
  actor.value = await actors.get(props.actorId).catch(() => null)
  await load()
})
</script>

<template>
  <Sheet wide :title="tr('امتیازات و نظرات', 'Ratings & reviews') + ' · ' + name" @close="emit('close')">
    <p class="text-lg font-extrabold text-amber-600">
      <template v-if="actor && actor.stats.rating_count">⭐ {{ actor.stats.rating_avg?.toFixed(1) }} · {{ actor.stats.rating_count }} {{ tr('نظر', 'reviews') }}</template>
      <template v-else>☆ —</template>
      <span v-if="actor" class="soft ms-3 text-sm font-normal">{{ actor.stats.completed_count }} {{ tr('کار تکمیل‌شده', 'completed jobs') }}</span>
    </p>
    <p class="soft text-xs">{{ tr('نظر فقط بعد از سفارش تکمیل‌شده ثبت می‌شود؛ پس هر نظر واقعی است.', 'A review is possible only after a completed order, so every review is real.') }}</p>
    <p v-if="!list.length" class="card p-6 text-center soft">{{ tr('هنوز نظری ثبت نشده است.', 'No reviews yet.') }}</p>
    <article v-for="r in list" :key="r.id" class="space-y-2 rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
      <div class="flex items-center justify-between gap-2">
        <strong>{{ r.customer?.display_name || '—' }}</strong>
        <span class="text-amber-500">{{ '★'.repeat(r.stars) }}<span class="text-slate-300">{{ '★'.repeat(5 - r.stars) }}</span></span>
      </div>
      <div v-if="r.tags.length" class="flex flex-wrap gap-1"><span v-for="t in r.tags" :key="t" class="chip chip-off !py-0.5 text-xs">{{ tagText(t) }}</span></div>
      <p v-if="r.body" class="whitespace-pre-wrap text-sm leading-7">{{ r.body }}</p>
      <p class="soft text-xs">{{ dateText(r.created_at) }}</p>
      <div v-if="r.reply" class="rounded-xl bg-blue-50 p-3 text-sm dark:bg-slate-800"><b>{{ tr('پاسخ متخصص:', 'Reply:') }}</b> {{ r.reply.body }}</div>
      <form v-else-if="isOwner()" class="flex gap-2" @submit.prevent="reply(r)">
        <input v-model="replyText[r.id]" maxlength="1000" class="v3-input min-w-0 flex-1" :placeholder="tr('پاسخ شما', 'Your reply')" />
        <button class="v3-primary !w-auto px-4">{{ tr('پاسخ', 'Reply') }}</button>
      </form>
      <button class="text-xs text-slate-500 underline" @click="report(r)">{{ tr('گزارش نظر', 'Report review') }}</button>
    </article>
    <button v-if="cursor" class="chip chip-off" @click="load(true)">{{ tr('بیشتر', 'More') }}</button>
  </Sheet>
</template>
