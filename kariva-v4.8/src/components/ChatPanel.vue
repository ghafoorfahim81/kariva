<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ChevronLeft, Send } from 'lucide-vue-next'
import { chat } from '../api/endpoints'
import { errorText, session } from '../api/session'
import type { ChatMessage, ConversationSummary } from '../api/types'
import { refreshLive } from '../state/live'
import { dateText, notify, tr, ui } from '../state/ui'

/** Conversations of the acting actor. `openConversationId` opens one; `openWithActor` opens (or creates) a direct one. */
const props = defineProps<{ openConversationId?: string | null; openWithActor?: string | null }>()
const emit = defineEmits<{ login: []; opened: [] }>()

const conversations = ref<ConversationSummary[]>([])
const currentId = ref<string | null>(null)
const messages = ref<ChatMessage[]>([])
const draft = ref('')
const sending = ref(false)
const scroller = ref<HTMLElement | null>(null)
let poll: number | undefined

const current = () => conversations.value.find((c) => c.id === currentId.value)

async function loadList() {
  if (!session.state.activeActorId) return
  try {
    conversations.value = await chat.list()
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

async function loadMessages(scroll = false) {
  const id = currentId.value
  if (!id) return
  try {
    const page = await chat.messages(id)
    if (id !== currentId.value) return
    const grew = page.data.length !== messages.value.length || page.data[0]?.id !== messages.value[0]?.id
    messages.value = page.data
    if (grew) {
      void chat.read(id).then(refreshLive).catch(() => undefined)
      if (scroll || grew) await nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight }))
    }
  } catch {
    /* a failed poll is retried by the next one */
  }
}

async function open(id: string) {
  currentId.value = id
  messages.value = []
  await loadMessages(true)
  await loadList()
  emit('opened')
}

async function openWith(actorId: string) {
  try {
    const c = await chat.direct(actorId)
    await loadList()
    await open(c.id)
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

async function send() {
  const body = draft.value.trim()
  if (!body || !currentId.value || sending.value) return
  sending.value = true
  try {
    await chat.send(currentId.value, body)
    draft.value = ''
    await loadMessages(true)
    await loadList()
  } catch (e) {
    notify(errorText(e, ui.lang))
  } finally {
    sending.value = false
  }
}

const ordered = () => [...messages.value].reverse()

onMounted(async () => {
  await loadList()
  if (props.openConversationId) await open(props.openConversationId)
  else if (props.openWithActor) await openWith(props.openWithActor)
  poll = window.setInterval(() => {
    if (document.visibilityState !== 'visible') return
    void loadMessages()
    void loadList()
  }, 6000)
})
onBeforeUnmount(() => window.clearInterval(poll))
watch(() => props.openConversationId, (id) => id && void open(id))
watch(() => props.openWithActor, (id) => id && void openWith(id))
watch(() => session.state.activeActorId, () => {
  currentId.value = null
  void loadList()
})
</script>

<template>
  <section class="card overflow-hidden">
    <div class="border-b border-slate-200 p-4 dark:border-slate-800">
      <h1 class="text-xl font-extrabold">{{ tr('پیام‌ها', 'Messages') }}</h1>
      <p v-if="session.signedIn.value" class="soft text-xs">{{ tr('اکنون به‌عنوان', 'Acting as') }} <b>{{ session.active.value?.display_name }}</b></p>
    </div>
    <div v-if="!session.signedIn.value" class="space-y-3 p-8 text-center">
      <p class="soft text-sm">{{ tr('برای پیام دادن وارد شوید.', 'Log in to send messages.') }}</p>
      <button class="v3-primary !w-auto px-6" @click="emit('login')">{{ tr('ورود', 'Log in') }}</button>
    </div>
    <div v-else class="grid min-h-[480px] md:grid-cols-[260px_1fr]">
      <aside class="border-slate-200 md:border-e dark:border-slate-800" :class="currentId ? 'hidden md:block' : ''">
        <p v-if="!conversations.length" class="soft p-6 text-center text-sm">{{ tr('هنوز گفت‌وگویی نیست. از پروفایل یا سفارش پیام بدهید.', 'No conversations yet. Message someone from a profile or an order.') }}</p>
        <button v-for="c in conversations" :key="c.id" class="flex w-full items-center gap-3 border-b border-slate-100 p-3 text-start hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800" :class="c.id === currentId ? 'bg-blue-50 dark:bg-slate-800' : ''" @click="open(c.id)">
          <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-blue-100 font-bold text-blue-700">{{ c.other.display_name.slice(0, 1) }}</span>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-bold">{{ c.other.display_name }}</span>
            <span class="soft block truncate text-xs">{{ c.order_title ? '📦 ' + c.order_title : c.last_message || '' }}</span>
          </span>
          <span v-if="c.unread" class="grid min-h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1 text-[10px] font-black text-white">{{ c.unread > 9 ? '9+' : c.unread }}</span>
        </button>
      </aside>
      <div class="flex min-h-[480px] flex-col" :class="currentId ? '' : 'hidden md:flex'">
        <template v-if="currentId">
          <div class="flex items-center gap-2 border-b border-slate-200 p-3 dark:border-slate-800">
            <button class="md:hidden" :aria-label="tr('بازگشت', 'Back')" @click="currentId = null"><ChevronLeft :size="22" /></button>
            <strong>{{ current()?.other.display_name }}</strong>
            <span v-if="current()?.order_title" class="soft truncate text-xs">· {{ current()?.order_title }}</span>
          </div>
          <div ref="scroller" class="max-h-[55vh] min-h-[320px] flex-1 space-y-2 overflow-y-auto p-4">
            <p v-if="!messages.length" class="soft text-center text-sm">{{ tr('اولین پیام را بفرستید.', 'Send the first message.') }}</p>
            <div v-for="m in ordered()" :key="m.id" class="flex" :class="m.mine ? 'justify-start' : 'justify-end'">
              <div class="max-w-[80%] rounded-2xl px-4 py-2 text-sm leading-7" :class="m.mine ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800'">
                <p class="whitespace-pre-wrap break-words">{{ m.body }}</p>
                <p class="mt-1 text-[10px] opacity-70">{{ dateText(m.created_at, true) }}</p>
              </div>
            </div>
          </div>
          <form class="flex gap-2 border-t border-slate-200 p-3 dark:border-slate-800" @submit.prevent="send">
            <input v-model="draft" maxlength="2000" class="v3-input min-w-0 flex-1" :placeholder="tr('پیام...', 'Message...')" />
            <button :disabled="sending || !draft.trim()" class="v3-primary !w-auto px-4 disabled:opacity-50" :aria-label="tr('ارسال', 'Send')"><Send :size="18" /></button>
          </form>
        </template>
        <p v-else class="soft m-auto p-6 text-center text-sm">{{ tr('یک گفت‌وگو را انتخاب کنید.', 'Choose a conversation.') }}</p>
      </div>
    </div>
  </section>
</template>
