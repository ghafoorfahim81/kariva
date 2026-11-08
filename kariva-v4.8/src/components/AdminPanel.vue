<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { admin } from '../api/endpoints'
import type { AdminActor, AdminOverview, AdminReport, AdminVerification, AuditRow } from '../api/endpoints'
import { errorText } from '../api/session'
import { dateText, notify, tr } from '../state/ui'

type Section = 'overview' | 'verification' | 'reports' | 'accounts' | 'audit'
const section = ref<Section>('overview')
const loading = ref(false)

const overview = ref<AdminOverview | null>(null)
const health = ref<{ status: string; checks: Record<string, { ok: boolean }> } | null>(null)
const verifications = ref<AdminVerification[]>([])
const reports = ref<AdminReport[]>([])
const accounts = ref<AdminActor[]>([])
const audit = ref<AuditRow[]>([])
const query = ref('')
const notes = ref<Record<string, string>>({})

const fail = (e: unknown) => notify(errorText(e))

async function load() {
  loading.value = true
  try {
    if (section.value === 'overview') {
      overview.value = await admin.overview()
      health.value = await admin.health().catch(() => null)
    } else if (section.value === 'verification') verifications.value = (await admin.verifications()).data
    else if (section.value === 'reports') reports.value = (await admin.reports()).data
    else if (section.value === 'accounts') accounts.value = (await admin.actors(query.value)).data
    else audit.value = (await admin.audit()).data
  } catch (e) {
    fail(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(section, load)

async function decide(v: AdminVerification, ok: boolean) {
  try {
    if (ok) await admin.approve(v.id, notes.value[v.id])
    else await admin.reject(v.id, notes.value[v.id] ?? '')
    verifications.value = verifications.value.filter((x) => x.id !== v.id)
    notify(ok ? tr('admin.approved') : tr('admin.rejected'))
  } catch (e) {
    fail(e)
  }
}

const REPORT_ACTION: Record<string, { key: string; label: string }> = {
  actor: { key: 'suspend_actor', label: tr('admin.suspend_page') },
  review: { key: 'delete_review', label: tr('admin.delete_review') },
  service: { key: 'archive_service', label: tr('admin.archive_service') },
}

async function closeReport(r: AdminReport, how: 'resolve' | 'dismiss', withAction = false) {
  try {
    if (how === 'resolve') await admin.resolve(r.id, withAction ? REPORT_ACTION[r.type]?.key : undefined, notes.value[r.id])
    else await admin.dismiss(r.id, notes.value[r.id])
    reports.value = reports.value.filter((x) => x.id !== r.id)
    notify(tr('admin.saved'))
  } catch (e) {
    fail(e)
  }
}

async function toggle(a: AdminActor) {
  try {
    const res = a.status === 'active' ? await admin.suspendActor(a.id) : await admin.unsuspendActor(a.id)
    a.status = res.status
  } catch (e) {
    fail(e)
  }
}

const NAMES: Record<Section, string> = { overview: tr('admin.dashboard'), verification: tr('admin.identity'), reports: tr('admin.reports'), accounts: tr('admin.accounts'), audit: tr('admin.audit') }
const REASONS: Record<string, string> = { spam: tr('admin.reason_spam'), fraud: tr('admin.reason_fraud'), abuse: tr('admin.reason_abuse'), inappropriate: tr('admin.reason_inappropriate'), other: tr('admin.reason_other') }
</script>

<template>
  <section class="space-y-4" data-testid="admin-panel">
    <h1 class="text-2xl font-extrabold">{{ tr('admin.panel') }}</h1>
    <nav class="flex gap-2 overflow-x-auto" :aria-label="tr('admin.sections')">
      <button v-for="(label, key) in NAMES" :key="key" type="button" class="shrink-0 rounded-xl px-3 py-2.5 text-sm font-extrabold" :class="section === key ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'" :data-testid="`admin-tab-${key}`" @click="section = key">{{ label }}</button>
    </nav>

    <div v-if="section === 'overview' && overview" class="space-y-4">
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <button type="button" class="card p-4 text-start" @click="section = 'verification'"><strong class="block text-2xl font-black">{{ overview.pending_verifications }}</strong><span class="soft text-xs">{{ tr('admin.pending_identity') }}</span></button>
        <button type="button" class="card p-4 text-start" @click="section = 'reports'"><strong class="block text-2xl font-black">{{ overview.open_reports }}</strong><span class="soft text-xs">{{ tr('admin.open_reports') }}</span></button>
        <div class="card p-4"><strong class="block text-2xl font-black">{{ overview.users }}</strong><span class="soft text-xs">{{ tr('admin.users') }}</span></div>
        <div class="card p-4"><strong class="block text-2xl font-black">{{ overview.verified_actors }}</strong><span class="soft text-xs">{{ tr('admin.verified_count') }}</span></div>
        <div class="card p-4"><strong class="block text-2xl font-black">{{ overview.orders_last_7_days }}</strong><span class="soft text-xs">{{ tr('admin.orders_this_week') }}</span></div>
        <div class="card p-4"><strong class="block text-2xl font-black">{{ overview.completed_orders }}</strong><span class="soft text-xs">{{ tr('admin.orders_completed') }}</span></div>
        <div class="card p-4"><strong class="block text-2xl font-black">{{ overview.open_needs }}</strong><span class="soft text-xs">{{ tr('admin.open_needs') }}</span></div>
        <div class="card p-4"><strong class="block text-2xl font-black">{{ overview.suspended_actors }}</strong><span class="soft text-xs">{{ tr('admin.suspended_count') }}</span></div>
      </div>
      <div v-if="health" class="card p-4">
        <h2 class="font-extrabold">{{ tr('admin.system_status') }} {{ health.status === 'ok' ? tr('admin.healthy') : tr('admin.needs_attention') }}</h2>
        <p class="soft mt-2 text-sm"><span v-for="(c, k) in health.checks" :key="k" class="me-3">{{ c.ok ? '🟢' : '🔴' }} {{ k }}</span></p>
      </div>
    </div>

    <div v-else-if="section === 'verification'" class="space-y-3">
      <p v-if="!verifications.length && !loading" class="soft py-8 text-center" data-testid="admin-empty">{{ tr('admin.no_pending_requests') }}</p>
      <article v-for="v in verifications" :key="v.id" class="card space-y-3 p-4" data-testid="admin-verification">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div><strong>{{ v.actor.display_name }}</strong><p class="soft text-xs">{{ v.kind === 'person' ? tr('admin.person') : tr('admin.company') }} · {{ v.requester_phone }} · {{ dateText(v.created_at) }}</p></div>
        </div>
        <div class="flex flex-wrap gap-2">
          <a v-for="d in v.documents" :key="d.id" :href="`/api/v1/admin/documents/${d.id}`" target="_blank" rel="noopener"><img :src="`/api/v1/admin/documents/${d.id}`" :alt="tr('admin.document')" class="h-28 w-28 rounded-xl border border-slate-200 object-cover dark:border-slate-700" /></a>
          <p v-if="!v.documents.length" class="soft text-sm">{{ tr('admin.no_documents') }}</p>
        </div>
        <input v-model="notes[v.id]" class="v3-input w-full" :placeholder="tr('admin.note_required_for_reject')" />
        <div class="flex gap-2"><button type="button" class="v3-primary" data-testid="admin-approve" @click="decide(v, true)">{{ tr('admin.approve') }}</button><button type="button" class="chip chip-off" data-testid="admin-reject" @click="decide(v, false)">{{ tr('admin.reject') }}</button></div>
      </article>
    </div>

    <div v-else-if="section === 'reports'" class="space-y-3">
      <p v-if="!reports.length && !loading" class="soft py-8 text-center" data-testid="admin-empty">{{ tr('admin.no_open_reports') }}</p>
      <article v-for="r in reports" :key="r.id" class="card space-y-2 p-4" data-testid="admin-report">
        <div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ r.subject?.title ?? r.type }}</strong><span class="rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800">{{ REASONS[r.reason] ?? r.reason }}</span></div>
        <p v-if="r.subject?.text" class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60">{{ r.subject.text }}</p>
        <p v-if="r.details" class="soft text-sm">{{ r.details }}</p>
        <p class="soft text-xs">{{ tr('admin.reporter') }} {{ r.reporter_phone }} · {{ dateText(r.created_at) }}</p>
        <input v-model="notes[r.id]" class="v3-input w-full" :placeholder="tr('admin.note')" />
        <div class="flex flex-wrap gap-2">
          <button v-if="REPORT_ACTION[r.type] && r.type !== 'message'" type="button" class="v3-primary" @click="closeReport(r, 'resolve', true)">{{ tr('admin.action_and_close', { action: REPORT_ACTION[r.type].label }) }}</button>
          <button type="button" class="chip chip-off" data-testid="admin-resolve" @click="closeReport(r, 'resolve')">{{ tr('admin.handled') }}</button>
          <button type="button" class="chip chip-off" data-testid="admin-dismiss" @click="closeReport(r, 'dismiss')">{{ tr('admin.dismiss_report') }}</button>
        </div>
      </article>
    </div>

    <div v-else-if="section === 'accounts'" class="space-y-3">
      <form class="flex gap-2" @submit.prevent="load"><input v-model="query" class="v3-input w-full" :placeholder="tr('admin.name_or_phone')" :aria-label="tr('admin.search')" /><button class="v3-primary !w-auto px-5">{{ tr('admin.search') }}</button></form>
      <div v-for="a in accounts" :key="a.id" class="card flex flex-wrap items-center justify-between gap-3 p-4">
        <div class="min-w-0"><strong>{{ a.display_name }} <span v-if="a.verified">✔</span></strong><p class="soft text-xs" dir="ltr">{{ a.phone }} · {{ a.type }}</p></div>
        <div class="flex items-center gap-2"><span class="rounded-full px-2 py-1 text-xs font-bold" :class="a.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">{{ a.status === 'active' ? tr('admin.active') : tr('admin.suspended_one') }}</span><button type="button" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold dark:border-slate-700" @click="toggle(a)">{{ a.status === 'active' ? tr('admin.suspended_one') : tr('admin.activate') }}</button></div>
      </div>
    </div>

    <div v-else-if="section === 'audit'" class="space-y-2">
      <div v-for="l in audit" :key="l.id" class="card p-3 text-sm"><strong dir="ltr">{{ l.action }}</strong><span class="soft ms-2 text-xs">{{ dateText(l.created_at, true) }}</span></div>
    </div>
  </section>
</template>
