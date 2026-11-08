<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { actors, reviews, trust } from '../api/endpoints'
import { errorText, session } from '../api/session'
import type { Actor, Review } from '../api/types'
import { tagText } from '../state/labels'
import { dateText, notify, tr } from '../state/ui'
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
    notify(errorText(e))
  }
}

async function reply(r: Review) {
  const body = replyText.value[r.id]?.trim()
  if (!body) return
  try {
    r.reply = (await reviews.reply(r.id, body)).reply
    notify(tr('reviews.your_reply_was_saved'))
  } catch (e) {
    notify(errorText(e))
  }
}

async function report(r: Review) {
  if (!session.signedIn.value) return emit('login')
  const reason = window.prompt(tr('reviews.why_are_you_reporting_this_review'))
  if (!reason?.trim()) return
  try {
    await trust.report('review', r.id, reason.trim())
    notify(tr('reviews.reported_an_admin_will_check'))
  } catch (e) {
    notify(errorText(e))
  }
}

onMounted(async () => {
  actor.value = await actors.get(props.actorId).catch(() => null)
  await load()
})
</script>

<template>
  <Sheet wide :title="tr('reviews.ratings_reviews') + ' · ' + name" @close="emit('close')">
    <p class="text-lg font-extrabold text-amber-600">
      <template v-if="actor && actor.stats.rating_count">⭐ {{ actor.stats.rating_avg?.toFixed(1) }} · {{ actor.stats.rating_count }} {{ tr('reviews.reviews') }}</template>
      <template v-else>☆ —</template>
      <span v-if="actor" class="soft ms-3 text-sm font-normal">{{ actor.stats.completed_count }} {{ tr('reviews.completed_jobs') }}</span>
    </p>
    <p class="soft text-xs">{{ tr('reviews.a_review_is_possible_only_after') }}</p>
    <p v-if="!list.length" class="card p-6 text-center soft">{{ tr('reviews.no_reviews_yet') }}</p>
    <article v-for="r in list" :key="r.id" class="space-y-2 rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
      <div class="flex items-center justify-between gap-2">
        <strong>{{ r.customer?.display_name || '—' }}</strong>
        <span class="text-amber-500">{{ '★'.repeat(r.stars) }}<span class="text-slate-300">{{ '★'.repeat(5 - r.stars) }}</span></span>
      </div>
      <div v-if="r.tags.length" class="flex flex-wrap gap-1"><span v-for="t in r.tags" :key="t" class="chip chip-off !py-0.5 text-xs">{{ tagText(t) }}</span></div>
      <p v-if="r.body" class="whitespace-pre-wrap text-sm leading-7">{{ r.body }}</p>
      <p class="soft text-xs">{{ dateText(r.created_at) }}</p>
      <div v-if="r.reply" class="rounded-xl bg-blue-50 p-3 text-sm dark:bg-slate-800"><b>{{ tr('reviews.reply') }}</b> {{ r.reply.body }}</div>
      <form v-else-if="isOwner()" class="flex gap-2" @submit.prevent="reply(r)">
        <input v-model="replyText[r.id]" maxlength="1000" class="v3-input min-w-0 flex-1" :placeholder="tr('reviews.your_reply')" />
        <button class="v3-primary !w-auto px-4">{{ tr('reviews.reply_2') }}</button>
      </form>
      <button class="text-xs text-slate-500 underline" @click="report(r)">{{ tr('reviews.report_review') }}</button>
    </article>
    <button v-if="cursor" class="chip chip-off" @click="load(true)">{{ tr('common.more') }}</button>
  </Sheet>
</template>
