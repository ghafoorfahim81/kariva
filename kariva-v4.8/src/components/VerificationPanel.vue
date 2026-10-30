<script setup lang="ts">
import { ref, watch } from 'vue'
import { media, verification } from '../api/endpoints'
import type { VerificationState } from '../api/endpoints'
import { errorText, session } from '../api/session'
import { dateText, notify, ui } from '../state/ui'

const state = ref<VerificationState | null>(null)
const busy = ref(false)
const files = ref<File[]>([])

async function load() {
  state.value = null
  if (!session.active.value) return
  try {
    state.value = await verification.get()
  } catch {
    state.value = null
  }
}
watch(() => session.state.activeActorId, load, { immediate: true })

const isOwner = () => session.active.value?.role === 'owner'

function pick(e: Event) {
  files.value = Array.from((e.target as HTMLInputElement).files ?? []).slice(0, 4)
}

/** Opens the request, then sends the photos. If a photo fails the request stays open and can be completed later. */
async function submit() {
  if (!files.value.length) {
    notify('عکس مدرک را انتخاب کنید')
    return
  }
  busy.value = true
  try {
    const req = await verification.request()
    for (const f of files.value) await media.upload('verification_request', req.id, 'document', f)
    files.value = []
    notify('درخواست ثبت شد')
    await load()
  } catch (e) {
    notify(errorText(e, ui.lang))
    await load()
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="state" class="rounded-2xl border border-blue-200 bg-blue-50/70 p-4 dark:border-blue-900 dark:bg-blue-950/20" data-testid="verification-panel">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="font-extrabold">🛡️ تأیید هویت</h2>
      <span class="rounded-full px-3 py-1 text-xs font-bold" :class="state.verified ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'" data-testid="verification-status">
        {{ state.verified ? '✔ تأیید شده' : state.request?.status === 'pending' ? '⏳ در انتظار بررسی' : 'تأیید نشده' }}
      </span>
    </div>
    <p v-if="state.request?.status === 'rejected' && state.request.note" class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/40 dark:text-rose-200">دلیل رد: {{ state.request.note }}</p>
    <p v-if="state.request?.status === 'pending'" class="soft mt-3 text-sm">درخواست شما از {{ dateText(state.request.created_at) }} در بررسی است.</p>
    <template v-if="!state.verified && state.request?.status !== 'pending'">
      <p class="soft mt-3 text-xs leading-6">عکس واضح تذکره (یا جواز کسب‌وکار) را بفرستید. عکس‌ها خصوصی می‌مانند و فقط مدیر آن را می‌بیند؛ پس از بررسی پاک می‌شوند. شمارهٔ مدرک ذخیره نمی‌شود.</p>
      <template v-if="isOwner()">
        <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="mt-3 block w-full text-sm" data-testid="verification-files" @change="pick" />
        <button type="button" class="v3-primary mt-3" :disabled="busy" data-testid="verification-submit" @click="submit">{{ busy ? '…' : 'ارسال درخواست' }}</button>
      </template>
      <p v-else class="soft mt-3 text-xs">فقط مالک صفحه می‌تواند درخواست بدهد.</p>
    </template>
  </div>
</template>
