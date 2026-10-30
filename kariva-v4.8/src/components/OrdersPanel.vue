<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { MessageCircle, Phone } from 'lucide-vue-next'
import { orders } from '../api/endpoints'
import { errorText, session } from '../api/session'
import type { Order } from '../api/types'
import { eventText, orderStatusClass, orderStatusText, REVIEW_TAGS } from '../state/labels'
import { dateText, money, notify, tr, ui } from '../state/ui'
import { refreshLive } from '../state/live'
import Sheet from './Sheet.vue'

const props = defineProps<{ openId?: string | null }>()
const emit = defineEmits<{ chat: [conversationId: string]; login: [] }>()

const side = ref<'customer' | 'provider'>('customer')
const filter = ref<'open' | 'done' | 'all'>('open')
const list = ref<Order[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)
const detail = ref<Order | null>(null)
const busy = ref(false)

// forms
const quote = ref({ amount: '', currency: 'AFN', price_unit: 'project', days: '', note: '' })
const review = ref({ stars: 5, tags: [] as string[], body: '' })
const code = ref('')
const pastJob = ref<{ phone: string; title: string; description: string } | null>(null)

const statusFilter = computed(() => (filter.value === 'open' ? 'invited,requested,quoted,accepted,provider_done' : filter.value === 'done' ? 'completed,rejected,cancelled,expired,disputed' : undefined))

async function load(more = false) {
  if (!session.state.activeActorId) return
  loading.value = true
  try {
    const page = await orders.list({ role: side.value, status: statusFilter.value, cursor: more ? cursor.value : null })
    list.value = more ? [...list.value, ...page.data] : page.data
    cursor.value = page.next_cursor
  } catch (e) {
    notify(errorText(e, ui.lang))
  } finally {
    loading.value = false
  }
}

async function open(id: string) {
  try {
    detail.value = await orders.get(id)
    code.value = ''
    quote.value = { amount: '', currency: detail.value.agreed?.currency ?? 'AFN', price_unit: 'project', days: '', note: '' }
    review.value = { stars: 5, tags: [], body: '' }
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

/** Runs an action on the open order, then refreshes the card, the list and the counters. */
async function act(fn: () => Promise<Order | void>, done?: string) {
  if (busy.value || !detail.value) return
  busy.value = true
  try {
    const result = await fn()
    if (result) detail.value = await orders.get(result.id)
    else detail.value = await orders.get(detail.value.id)
    if (done) notify(done)
    await load()
    void refreshLive()
  } catch (e) {
    notify(errorText(e, ui.lang))
    if (detail.value) detail.value = await orders.get(detail.value.id).catch(() => detail.value)
  } finally {
    busy.value = false
  }
}

const o = computed(() => detail.value)
const isCustomer = computed(() => o.value?.my_side === 'customer')
const isProvider = computed(() => o.value?.my_side === 'provider')
const other = computed(() => (isCustomer.value ? o.value?.provider : o.value?.customer))
/** Whose turn it is: the provider answers a request and finishes work; the customer accepts a price and confirms. */
const waitingForMe = (x: Order) =>
  (x.my_side === 'provider' && ['requested', 'accepted'].includes(x.status)) ||
  (x.my_side === 'customer' && ['quoted', 'provider_done'].includes(x.status)) ||
  x.status === 'invited'

function sendQuote() {
  const q = quote.value
  void act(
    () => orders.quote(o.value!.id, { amount: q.amount, currency: q.currency, price_unit: q.price_unit, days: q.days ? Number(q.days) : null, note: q.note.trim() || null }),
    tr('قیمت شما فرستاده شد ✓', 'Your price was sent ✓'),
  )
}

function sendReview() {
  void act(async () => {
    await orders.review(o.value!.id, { stars: review.value.stars, tags: review.value.tags, body: review.value.body.trim() || null })
  }, tr('نظر شما ثبت شد ✓', 'Your review was saved ✓'))
}

function toggleTag(t: string) {
  const i = review.value.tags.indexOf(t)
  if (i >= 0) review.value.tags.splice(i, 1)
  else review.value.tags.push(t)
}

function confirmCode() {
  void act(() => orders.confirmInvited(o.value!.id, code.value), tr('کار گذشته تأیید شد ✓', 'Past job confirmed ✓'))
}

async function resend() {
  try {
    await orders.resendCode(o.value!.id)
    notify(tr('کد دوباره فرستاده شد', 'The code was sent again'))
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

async function createPastJob() {
  const f = pastJob.value
  if (!f || busy.value) return
  busy.value = true
  try {
    const order = await orders.invite({ phone: f.phone, title: f.title.trim(), description: f.description.trim() || null })
    pastJob.value = null
    side.value = 'provider'
    await load()
    await open(order.id)
    notify(order.code_sent === false ? tr('کار ثبت شد ولی کد فرستاده نشد؛ بعداً دوباره بفرستید', 'Saved, but the code could not be sent; try again later') : tr('کد تأیید به مشتری فرستاده شد', 'A code was sent to the customer'))
  } catch (e) {
    notify(errorText(e, ui.lang))
  } finally {
    busy.value = false
  }
}

function close() {
  detail.value = null
}

watch([side, filter, () => session.state.activeActorId], () => void load())
watch(() => props.openId, (id) => id && void open(id))
onMounted(() => {
  void load()
  if (props.openId) void open(props.openId)
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-extrabold">{{ tr('سفارش‌ها', 'Orders') }}</h1>
        <p class="soft mt-1 text-sm">{{ tr('درخواست، قیمت، توافق و تکمیل کار در یک جا.', 'Request, price, agreement and completion in one place.') }}</p>
      </div>
      <button v-if="session.signedIn.value && side === 'provider'" class="v3-primary !w-auto px-5" @click="pastJob = { phone: '', title: '', description: '' }">+ {{ tr('کار گذشته', 'Past job') }}</button>
    </div>

    <div v-if="!session.signedIn.value" class="card space-y-3 p-8 text-center">
      <p class="soft text-sm">{{ tr('برای دیدن سفارش‌ها وارد شوید.', 'Log in to see your orders.') }}</p>
      <button class="v3-primary !w-auto px-6" @click="emit('login')">{{ tr('ورود', 'Log in') }}</button>
    </div>

    <template v-else>
      <div class="flex gap-2 rounded-2xl bg-slate-100 p-1.5 dark:bg-slate-800">
        <button class="flex-1 rounded-xl px-4 py-3 text-sm font-bold" :class="side === 'customer' ? 'bg-white shadow dark:bg-slate-700' : ''" @click="side = 'customer'">{{ tr('سفارش‌های من (مشتری)', 'My orders (customer)') }}</button>
        <button class="flex-1 rounded-xl px-4 py-3 text-sm font-bold" :class="side === 'provider' ? 'bg-white shadow dark:bg-slate-700' : ''" @click="side = 'provider'">{{ tr('درخواست‌های رسیده (ارائه‌دهنده)', 'Received (provider)') }}</button>
      </div>
      <p class="soft text-xs">{{ tr('اکنون به‌عنوان', 'Acting as') }} <b>{{ session.active.value?.display_name }}</b></p>
      <div class="flex gap-2">
        <button class="chip" :class="filter === 'open' ? 'chip-on' : 'chip-off'" @click="filter = 'open'">{{ tr('جاری', 'Open') }}</button>
        <button class="chip" :class="filter === 'done' ? 'chip-on' : 'chip-off'" @click="filter = 'done'">{{ tr('پایان‌یافته', 'Finished') }}</button>
        <button class="chip" :class="filter === 'all' ? 'chip-on' : 'chip-off'" @click="filter = 'all'">{{ tr('همه', 'All') }}</button>
      </div>

      <div v-if="!list.length && !loading" class="card p-8 text-center">
        <p class="mb-3 text-4xl">📦</p>
        <h2 class="font-bold">{{ tr('هنوز سفارشی نیست', 'No orders yet') }}</h2>
        <p class="soft mt-2 text-sm">{{ side === 'customer' ? tr('از «کشف متخصصان» یک خدمت درخواست کنید.', 'Request a service from “Discover”.') : tr('وقتی مشتری درخواست بدهد اینجا می‌بینید.', 'Requests from customers will show up here.') }}</p>
      </div>

      <div class="grid gap-3 lg:grid-cols-2">
        <article v-for="x in list" :key="x.id" class="card cursor-pointer space-y-2 p-4 transition hover:shadow-lg" role="button" tabindex="0" @click="open(x.id)" @keydown.enter="open(x.id)">
          <div class="flex items-start justify-between gap-2">
            <h3 class="font-extrabold">{{ x.title }}</h3>
            <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold" :class="orderStatusClass(x.status)">{{ orderStatusText(x.status) }}</span>
          </div>
          <p class="soft text-sm">{{ side === 'customer' ? x.provider?.display_name : x.customer?.display_name }} · {{ dateText(x.created_at) }}</p>
          <p v-if="x.agreed" class="font-bold text-blue-600">{{ money(x.agreed.amount, x.agreed.currency, x.agreed.unit) }}</p>
          <p v-else-if="x.pending_quote" class="font-bold text-indigo-600">{{ tr('پیشنهاد:', 'Offer:') }} {{ money(x.pending_quote.amount, x.pending_quote.currency, x.pending_quote.unit) }}</p>
          <p v-if="waitingForMe(x)" class="text-xs font-bold text-amber-600">● {{ tr('منتظر اقدام شما', 'Waiting for you') }}</p>
        </article>
      </div>
      <button v-if="cursor" class="chip chip-off mx-auto block" :disabled="loading" @click="load(true)">{{ tr('بیشتر', 'More') }}</button>
    </template>

    <!-- ---- order detail ---- -->
    <Sheet v-if="o" wide :title="o.title" @close="close">
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full px-3 py-1 text-xs font-bold" :class="orderStatusClass(o.status)">{{ orderStatusText(o.status) }}</span>
        <span class="soft text-xs">{{ isCustomer ? tr('شما مشتری هستید', 'You are the customer') : tr('شما ارائه‌دهنده هستید', 'You are the provider') }}</span>
      </div>
      <p v-if="o.description" class="whitespace-pre-wrap text-sm leading-7">{{ o.description }}</p>

      <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
          <p class="soft text-xs">{{ isCustomer ? tr('ارائه‌دهنده', 'Provider') : tr('مشتری', 'Customer') }}</p>
          <strong>{{ other?.display_name }}</strong>
          <div v-if="isCustomer && o.provider?.contact_phone" class="mt-2 flex gap-2">
            <a :href="'tel:' + o.provider.contact_phone" class="chip chip-on inline-flex items-center gap-1"><Phone :size="14" /> {{ tr('زنگ', 'Call') }}</a>
            <a v-if="o.provider.whatsapp" :href="'https://wa.me/' + o.provider.whatsapp.replace('+', '')" target="_blank" rel="noopener" class="chip chip-off">WhatsApp</a>
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
          <p class="soft text-xs">{{ o.agreed ? tr('قیمت توافق‌شده (قفل)', 'Agreed price (locked)') : tr('قیمت', 'Price') }}</p>
          <strong v-if="o.agreed" class="text-blue-600">{{ money(o.agreed.amount, o.agreed.currency, o.agreed.unit) }}</strong>
          <strong v-else-if="o.pending_quote" class="text-indigo-600">{{ money(o.pending_quote.amount, o.pending_quote.currency, o.pending_quote.unit) }}<span v-if="o.pending_quote.days" class="soft text-xs"> · {{ o.pending_quote.days }} {{ tr('روز', 'days') }}</span></strong>
          <span v-else class="soft text-sm">—</span>
          <p v-if="o.pending_quote?.note" class="soft mt-1 text-xs">{{ o.pending_quote.note }}</p>
        </div>
      </div>

      <!-- provider: price -->
      <form v-if="isProvider && ['requested', 'quoted'].includes(o.status)" class="space-y-3 rounded-2xl border border-blue-200 p-4 dark:border-blue-900" @submit.prevent="sendQuote">
        <h3 class="font-extrabold">{{ o.status === 'quoted' ? tr('قیمت تازه بدهید', 'Give a new price') : tr('قیمت بدهید', 'Give a price') }}</h3>
        <div class="grid grid-cols-3 gap-2">
          <input v-model="quote.amount" required inputmode="decimal" dir="ltr" class="v3-input" :placeholder="tr('مبلغ', 'Amount')" />
          <select v-model="quote.currency" class="v3-input"><option value="AFN">{{ tr('افغانی', 'AFN') }}</option><option value="USD">{{ tr('دالر', 'USD') }}</option></select>
          <select v-model="quote.price_unit" class="v3-input">
            <option value="project">{{ tr('پروژه', 'project') }}</option><option value="hour">{{ tr('ساعت', 'hour') }}</option><option value="day">{{ tr('روز', 'day') }}</option><option value="meter">{{ tr('متر', 'meter') }}</option><option value="piece">{{ tr('دانه', 'piece') }}</option>
          </select>
        </div>
        <input v-model="quote.days" inputmode="numeric" dir="ltr" class="v3-input" :placeholder="tr('مدت کار (روز، اختیاری)', 'Duration in days (optional)')" />
        <input v-model="quote.note" maxlength="1000" class="v3-input" :placeholder="tr('توضیح (اختیاری)', 'Note (optional)')" />
        <button :disabled="busy" class="v3-primary disabled:opacity-60">{{ tr('ارسال قیمت', 'Send price') }}</button>
      </form>

      <!-- invited past job: the code -->
      <div v-if="o.status === 'invited'" class="space-y-3 rounded-2xl border border-amber-300 p-4">
        <p class="text-sm leading-7">{{ tr('این «کار گذشته» وقتی حساب می‌شود که مشتری با کد تأیید کند. کد به شماره‌ی مشتری رسیده است؛ خودش یا شما آن را اینجا وارد کنید.', 'This past job counts once the customer confirms with the code sent to their phone. Enter the code here.') }}</p>
        <form class="flex gap-2" @submit.prevent="confirmCode">
          <input v-model="code" required inputmode="numeric" maxlength="6" dir="ltr" class="v3-input min-w-0 flex-1" placeholder="• • • • • •" autocomplete="one-time-code" />
          <button :disabled="busy" class="v3-primary !w-auto px-5 disabled:opacity-60">{{ tr('تأیید', 'Confirm') }}</button>
        </form>
        <button v-if="isProvider" type="button" class="text-sm font-bold text-blue-600" @click="resend">{{ tr('فرستادن دوباره‌ی کد', 'Send the code again') }}</button>
      </div>

      <!-- actions -->
      <div class="flex flex-wrap gap-2">
        <button v-if="isCustomer && o.status === 'quoted'" :disabled="busy" class="v3-primary !w-auto px-5" @click="act(() => orders.step(o!.id, 'accept'), tr('توافق ثبت شد ✓', 'Agreement saved ✓'))">{{ tr('قبول قیمت', 'Accept the price') }}</button>
        <button v-if="isProvider && o.status === 'accepted'" :disabled="busy" class="v3-primary !w-auto px-5" @click="act(() => orders.step(o!.id, 'done'), tr('پایان کار اعلام شد', 'Marked as done'))">{{ tr('کار تمام شد', 'Work is done') }}</button>
        <button v-if="isCustomer && o.status === 'provider_done'" :disabled="busy" class="v3-primary !w-auto px-5" @click="act(() => orders.step(o!.id, 'confirm'), tr('سفارش تکمیل شد؛ حالا نظر بدهید', 'Completed; you can leave a review'))">{{ tr('تأیید تکمیل کار', 'Confirm the work') }}</button>
        <button v-if="isCustomer && o.status === 'provider_done'" :disabled="busy" class="chip chip-off !border-orange-300 !text-orange-700" @click="act(() => orders.step(o!.id, 'dispute'), tr('مشکل ثبت شد', 'Problem reported'))">{{ tr('مشکل دارم', 'I have a problem') }}</button>
        <button v-if="isProvider && ['requested', 'quoted'].includes(o.status)" :disabled="busy" class="chip chip-off !border-rose-300 !text-rose-600" @click="act(() => orders.step(o!.id, 'reject'))">{{ tr('رد درخواست', 'Decline') }}</button>
        <button v-if="isCustomer && ['requested', 'quoted'].includes(o.status)" :disabled="busy" class="chip chip-off !border-rose-300 !text-rose-600" @click="act(() => orders.step(o!.id, 'cancel'))">{{ tr('لغو درخواست', 'Cancel') }}</button>
        <button v-if="o.conversation_id" class="chip chip-off inline-flex items-center gap-1" @click="emit('chat', o.conversation_id!)"><MessageCircle :size="15" /> {{ tr('پیام', 'Message') }}</button>
      </div>

      <!-- review -->
      <div v-if="isCustomer && o.status === 'completed' && !o.review" class="space-y-3 rounded-2xl border border-emerald-300 p-4">
        <h3 class="font-extrabold">{{ tr('نظر شما', 'Your review') }}</h3>
        <div class="flex gap-1" role="radiogroup" :aria-label="tr('امتیاز', 'Stars')">
          <button v-for="n in 5" :key="n" type="button" class="text-3xl leading-none" :class="n <= review.stars ? 'text-amber-400' : 'text-slate-300'" :aria-label="String(n)" @click="review.stars = n">★</button>
        </div>
        <div class="flex flex-wrap gap-2">
          <button v-for="t in REVIEW_TAGS" :key="t[0]" type="button" class="chip" :class="review.tags.includes(t[0]) ? 'chip-on' : 'chip-off'" @click="toggleTag(t[0])">{{ tr(t[1], t[2]) }}</button>
        </div>
        <textarea v-model="review.body" rows="3" maxlength="1000" class="v3-input" :placeholder="tr('تجربه‌ی واقعی خود را بنویسید (اختیاری)', 'Describe your real experience (optional)')"></textarea>
        <button :disabled="busy" class="v3-primary disabled:opacity-60" @click="sendReview">{{ tr('ثبت نظر', 'Submit review') }}</button>
      </div>
      <p v-else-if="o.review" class="rounded-xl bg-emerald-50 p-3 text-sm dark:bg-emerald-950/30">⭐ {{ o.review.stars }} · {{ tr('نظر ثبت شده است', 'Review saved') }}</p>

      <!-- history -->
      <div class="space-y-2 border-t border-slate-200 pt-4 dark:border-slate-700">
        <h3 class="font-extrabold">🕒 {{ tr('تاریخچه', 'History') }}</h3>
        <ol class="space-y-1 text-sm">
          <li v-for="(e, i) in o.events" :key="i" class="flex justify-between gap-3"><span>{{ eventText(e.event) }}</span><span class="soft text-xs">{{ dateText(e.at, true) }}</span></li>
        </ol>
      </div>
    </Sheet>

    <!-- ---- past job ---- -->
    <Sheet v-if="pastJob" :title="tr('ثبت «کار گذشته»', 'Add a past job')" @close="pastJob = null">
      <form class="space-y-3" @submit.prevent="createPastJob">
        <p class="soft text-sm leading-7">{{ tr('برای شروع سرد: کاری که قبلاً برای یک مشتری انجام داده‌اید. شماره‌ی مشتری را بنویسید؛ به او کد می‌رسد و بعد از تأیید، این کار روی پروفایل شما حساب می‌شود. حداکثر ۵ مورد در ماه.', 'A job you did before for a customer. Enter their number; they get a code, and after they confirm it counts on your profile. At most 5 per month.') }}</p>
        <input v-model="pastJob.phone" required type="tel" inputmode="tel" dir="ltr" class="v3-input" placeholder="0771234567" />
        <input v-model="pastJob.title" required minlength="2" maxlength="150" class="v3-input" :placeholder="tr('چه کاری بود؟', 'What was the job?')" />
        <textarea v-model="pastJob.description" rows="3" maxlength="2000" class="v3-input" :placeholder="tr('توضیح (اختیاری)', 'Details (optional)')"></textarea>
        <button :disabled="busy" class="v3-primary disabled:opacity-60">{{ tr('ثبت و فرستادن کد', 'Save and send the code') }}</button>
      </form>
    </Sheet>
  </div>
</template>
