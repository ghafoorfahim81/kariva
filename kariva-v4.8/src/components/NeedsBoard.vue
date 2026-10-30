<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { catalog, chat, needs } from '../api/endpoints'
import { errorText, session } from '../api/session'
import type { Category, Need, Offer, Province } from '../api/types'
import { refreshLive } from '../state/live'
import { dateText, money, nameOf, notify, tr, ui } from '../state/ui'
import Sheet from './Sheet.vue'

const emit = defineEmits<{ chat: [conversationId: string]; order: [orderId: string]; login: [] }>()

const view = ref<'board' | 'mine'>('board')
const kind = ref<'all' | 'service' | 'job'>('all')
const categoryId = ref('')
const cityId = ref('')
const items = ref<Need[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)
const categories = ref<Category[]>([])
const provinces = ref<Province[]>([])

const posting = ref(false)
const form = ref({ kind: 'service' as 'service' | 'job', category_id: '', city_id: '', title: '', description: '' })
const offerFor = ref<Need | null>(null)
const offerForm = ref({ amount: '', currency: 'AFN', price_unit: 'project', days: '', message: '' })
const offersOf = ref<Need | null>(null)
const offers = ref<Offer[]>([])
const busy = ref(false)

const leaf = computed(() => categories.value.flatMap((r) => (r.children?.length ? r.children : [r])))

async function load(more = false) {
  loading.value = true
  try {
    const page =
      view.value === 'mine'
        ? await needs.mine(more ? cursor.value : null)
        : await needs.board({ kind: kind.value === 'all' ? undefined : kind.value, category: categoryId.value || undefined, city: cityId.value || undefined, cursor: more ? cursor.value : null })
    items.value = more ? [...items.value, ...page.data] : page.data
    cursor.value = page.next_cursor
  } catch (e) {
    notify(errorText(e, ui.lang))
  } finally {
    loading.value = false
  }
}

function needLogin(): boolean {
  if (session.signedIn.value && session.state.activeActorId) return false
  emit('login')
  return true
}

async function post() {
  if (busy.value || needLogin()) return
  busy.value = true
  try {
    await needs.create({ kind: form.value.kind, category_id: form.value.category_id, city_id: form.value.city_id || null, title: form.value.title.trim(), description: form.value.description.trim() || null })
    posting.value = false
    form.value = { ...form.value, title: '', description: '' }
    notify(tr('نیازمندی شما ثبت شد ✓', 'Your need was posted ✓'))
    view.value = 'mine'
    await load()
  } catch (e) {
    notify(errorText(e, ui.lang))
  } finally {
    busy.value = false
  }
}

async function sendOffer() {
  if (!offerFor.value || busy.value) return
  busy.value = true
  const f = offerForm.value
  try {
    await needs.offer(offerFor.value.id, { amount: f.amount, currency: f.currency, price_unit: f.price_unit, days: f.days ? Number(f.days) : null, message: f.message.trim() || null })
    offerFor.value = null
    notify(tr('پیشنهاد شما فرستاده شد ✓', 'Your offer was sent ✓'))
  } catch (e) {
    notify(errorText(e, ui.lang))
  } finally {
    busy.value = false
  }
}

async function talk(n: Need) {
  if (needLogin()) return
  try {
    const c = await chat.direct(n.actor.id)
    emit('chat', c.id)
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

async function showOffers(n: Need) {
  try {
    offers.value = await needs.offers(n.id)
    offersOf.value = n
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

async function decide(o: Offer, what: 'accept' | 'reject') {
  if (busy.value) return
  busy.value = true
  try {
    if (what === 'accept') {
      const order = await needs.acceptOffer(o.id)
      offersOf.value = null
      notify(tr('پیشنهاد پذیرفته شد؛ سفارش ساخته شد ✓', 'Offer accepted; the order was created ✓'))
      void refreshLive()
      emit('order', order.id)
    } else {
      await needs.rejectOffer(o.id)
      offers.value = await needs.offers(offersOf.value!.id)
    }
    await load()
  } catch (e) {
    notify(errorText(e, ui.lang))
  } finally {
    busy.value = false
  }
}

async function closeNeed(n: Need) {
  if (!window.confirm(tr('این نیازمندی بسته شود؟', 'Close this need?'))) return
  try {
    await needs.close(n.id)
    await load()
  } catch (e) {
    notify(errorText(e, ui.lang))
  }
}

const statusText = (s: string) => ({ open: tr('باز', 'Open'), closed: tr('بسته', 'Closed'), expired: tr('منقضی', 'Expired'), cancelled: tr('لغو', 'Cancelled') })[s] ?? s

watch([view, kind, categoryId, cityId, () => session.state.activeActorId], () => void load())
onMounted(async () => {
  void load()
  ;[categories.value, provinces.value] = await Promise.all([catalog.categories(), catalog.provinces()]).catch(() => [[], []] as [Category[], Province[]])
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-extrabold">📣 {{ tr('نیازمندی‌ها', 'Needs') }}</h1>
        <p class="soft mt-1 text-sm">{{ tr('کار یا خدمتی می‌خواهید؟ بگذارید متخصصان پیشنهاد بدهند.', 'Need work or a service? Let specialists make you offers.') }}</p>
      </div>
      <button class="v3-primary !w-auto px-5" @click="!needLogin() && (posting = true)">+ {{ tr('ثبت نیازمندی', 'Post a need') }}</button>
    </div>

    <div class="flex gap-2 rounded-2xl bg-slate-100 p-1.5 dark:bg-slate-800">
      <button class="flex-1 rounded-xl px-4 py-3 text-sm font-bold" :class="view === 'board' ? 'bg-white shadow dark:bg-slate-700' : ''" @click="view = 'board'">{{ tr('همه‌ی نیازمندی‌ها', 'All needs') }}</button>
      <button class="flex-1 rounded-xl px-4 py-3 text-sm font-bold" :class="view === 'mine' ? 'bg-white shadow dark:bg-slate-700' : ''" @click="needLogin() || (view = 'mine')">{{ tr('نیازمندی‌های من', 'My needs') }}</button>
    </div>

    <div v-if="view === 'board'" class="card grid gap-3 p-4 sm:grid-cols-3">
      <select v-model="kind" class="v3-input"><option value="all">{{ tr('همه', 'All') }}</option><option value="service">{{ tr('پروژه / خدمت', 'Project / service') }}</option><option value="job">{{ tr('استخدام', 'Hiring') }}</option></select>
      <select v-model="categoryId" class="v3-input"><option value="">{{ tr('تمام حرفه‌ها', 'All professions') }}</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ nameOf(c) }}</option></select>
      <select v-model="cityId" class="v3-input"><option value="">{{ tr('تمام شهرها', 'All cities') }}</option><optgroup v-for="p in provinces" :key="p.id" :label="nameOf(p)"><option v-for="c in p.cities" :key="c.id" :value="c.id">{{ nameOf(c) }}</option></optgroup></select>
    </div>

    <p v-if="!items.length && !loading" class="card p-8 text-center soft">{{ view === 'mine' ? tr('هنوز نیازمندی‌ای ثبت نکرده‌اید.', 'You have not posted a need yet.') : tr('نیازمندی‌ای پیدا نشد.', 'No needs found.') }}</p>

    <div class="grid gap-4 lg:grid-cols-2">
      <article v-for="n in items" :key="n.id" class="card space-y-3 p-5">
        <div class="flex items-start justify-between gap-2">
          <h3 class="font-extrabold">{{ n.title }}</h3>
          <span class="chip chip-off shrink-0 !py-1 text-xs">{{ n.kind === 'job' ? tr('استخدام', 'Hiring') : tr('پروژه', 'Project') }}</span>
        </div>
        <p v-if="n.description" class="soft whitespace-pre-wrap text-sm leading-7">{{ n.description }}</p>
        <p class="soft text-xs">{{ nameOf(n.category) }}<span v-if="n.city"> · {{ nameOf(n.city) }}</span> · {{ n.actor.display_name }} · {{ dateText(n.created_at) }}</p>
        <template v-if="view === 'mine' || n.is_mine">
          <p class="text-xs font-bold"><span class="rounded-full bg-slate-100 px-2 py-1 dark:bg-slate-800">{{ statusText(n.status) }}</span><span v-if="n.pending_offers" class="ms-2 text-blue-600">{{ n.pending_offers }} {{ tr('پیشنهاد', 'offers') }}</span></p>
          <div class="flex flex-wrap gap-2">
            <button v-if="n.kind === 'service'" class="v3-primary !w-auto px-4 !py-2" @click="showOffers(n)">{{ tr('دیدن پیشنهادها', 'See offers') }}</button>
            <button v-if="n.status === 'open'" class="chip chip-off !border-rose-300 !text-rose-600" @click="closeNeed(n)">{{ tr('بستن', 'Close') }}</button>
          </div>
        </template>
        <div v-else class="flex flex-wrap gap-2">
          <button v-if="n.kind === 'service'" class="v3-primary !w-auto px-4 !py-2" @click="!needLogin() && (offerFor = n)">{{ tr('پیشنهاد قیمت', 'Make an offer') }}</button>
          <button class="chip chip-off" @click="talk(n)">{{ n.kind === 'job' ? tr('پیام برای همکاری', 'Message about the job') : tr('پیام', 'Message') }}</button>
        </div>
      </article>
    </div>
    <button v-if="cursor" class="chip chip-off mx-auto block" :disabled="loading" @click="load(true)">{{ tr('بیشتر', 'More') }}</button>

    <!-- post a need -->
    <Sheet v-if="posting" :title="tr('ثبت نیازمندی', 'Post a need')" @close="posting = false">
      <form class="space-y-3" @submit.prevent="post">
        <div class="flex gap-2">
          <button type="button" class="chip flex-1" :class="form.kind === 'service' ? 'chip-on' : 'chip-off'" @click="form.kind = 'service'">{{ tr('خدمت / پروژه می‌خواهم', 'I need a service') }}</button>
          <button type="button" class="chip flex-1" :class="form.kind === 'job' ? 'chip-on' : 'chip-off'" @click="form.kind = 'job'">{{ tr('کارگر استخدام می‌کنم', 'I am hiring') }}</button>
        </div>
        <p class="soft text-xs">{{ form.kind === 'service' ? tr('متخصصان قیمت پیشنهاد می‌دهند؛ با پذیرفتن یکی، سفارش ساخته می‌شود.', 'Specialists send prices; accepting one creates an order.') : tr('برای استخدام پیشنهاد قیمت نیست؛ علاقه‌مندان پیام می‌دهند.', 'No price offers for hiring; interested people message you.') }}</p>
        <input v-model="form.title" required minlength="2" maxlength="150" class="v3-input" :placeholder="tr('چه چیزی لازم دارید؟', 'What do you need?')" />
        <select v-model="form.category_id" required class="v3-input">
          <option value="">{{ tr('— حرفه —', '— Profession —') }}</option>
          <optgroup v-for="r in categories" :key="r.id" :label="nameOf(r)"><option v-for="c in (r.children?.length ? r.children : [r])" :key="c.id" :value="c.id">{{ nameOf(c) }}</option></optgroup>
        </select>
        <select v-model="form.city_id" class="v3-input">
          <option value="">{{ tr('— شهر (اختیاری) —', '— City (optional) —') }}</option>
          <optgroup v-for="p in provinces" :key="p.id" :label="nameOf(p)"><option v-for="c in p.cities" :key="c.id" :value="c.id">{{ nameOf(c) }}</option></optgroup>
        </select>
        <textarea v-model="form.description" rows="3" maxlength="2000" class="v3-input" :placeholder="tr('توضیح (اختیاری)', 'Details (optional)')"></textarea>
        <button :disabled="busy" class="v3-primary disabled:opacity-60">{{ tr('ثبت', 'Post') }}</button>
      </form>
    </Sheet>

    <!-- make an offer -->
    <Sheet v-if="offerFor" :title="offerFor.title" @close="offerFor = null">
      <form class="space-y-3" @submit.prevent="sendOffer">
        <div class="grid grid-cols-3 gap-2">
          <input v-model="offerForm.amount" required inputmode="decimal" dir="ltr" class="v3-input" :placeholder="tr('مبلغ', 'Amount')" />
          <select v-model="offerForm.currency" class="v3-input"><option value="AFN">{{ tr('افغانی', 'AFN') }}</option><option value="USD">{{ tr('دالر', 'USD') }}</option></select>
          <select v-model="offerForm.price_unit" class="v3-input"><option value="project">{{ tr('پروژه', 'project') }}</option><option value="hour">{{ tr('ساعت', 'hour') }}</option><option value="day">{{ tr('روز', 'day') }}</option><option value="meter">{{ tr('متر', 'meter') }}</option><option value="piece">{{ tr('دانه', 'piece') }}</option></select>
        </div>
        <input v-model="offerForm.days" inputmode="numeric" dir="ltr" class="v3-input" :placeholder="tr('مدت کار (روز، اختیاری)', 'Days (optional)')" />
        <textarea v-model="offerForm.message" rows="3" maxlength="1000" class="v3-input" :placeholder="tr('پیام برای مشتری', 'Message to the customer')"></textarea>
        <p class="soft text-xs">{{ tr('پیشنهاد به‌نام صفحه‌ای که اکنون با آن کار می‌کنید فرستاده می‌شود:', 'Sent as:') }} <b>{{ session.active.value?.display_name }}</b></p>
        <button :disabled="busy" class="v3-primary disabled:opacity-60">{{ tr('ارسال پیشنهاد', 'Send offer') }}</button>
      </form>
    </Sheet>

    <!-- offers of my need -->
    <Sheet v-if="offersOf" wide :title="tr('پیشنهادها', 'Offers') + ' · ' + offersOf.title" @close="offersOf = null">
      <p v-if="!offers.length" class="soft text-sm">{{ tr('هنوز پیشنهادی نرسیده.', 'No offers yet.') }}</p>
      <article v-for="o in offers" :key="o.id" class="space-y-2 rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <strong>{{ o.provider.display_name }}</strong>
          <span class="font-bold text-blue-600">{{ money(o.amount, o.currency, o.unit) }}<span v-if="o.days" class="soft text-xs"> · {{ o.days }} {{ tr('روز', 'days') }}</span></span>
        </div>
        <p v-if="o.message" class="text-sm">{{ o.message }}</p>
        <p v-if="o.status !== 'pending'" class="soft text-xs">{{ o.status }}</p>
        <div v-if="o.status === 'pending' && offersOf.status === 'open'" class="flex gap-2">
          <button :disabled="busy" class="v3-primary !w-auto px-4 !py-2 disabled:opacity-60" @click="decide(o, 'accept')">{{ tr('قبول و ساخت سفارش', 'Accept and create order') }}</button>
          <button :disabled="busy" class="chip chip-off !border-rose-300 !text-rose-600" @click="decide(o, 'reject')">{{ tr('رد', 'Decline') }}</button>
        </div>
      </article>
    </Sheet>
  </div>
</template>
