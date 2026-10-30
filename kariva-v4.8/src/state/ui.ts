import { reactive } from 'vue'

/** Language and toast shared between App.vue and the screens that live in their own files. */
export type Lang = 'fa' | 'ps' | 'en'

export const ui = reactive<{ lang: Lang; toast: (msg: string) => void }>({
  lang: 'fa',
  toast: () => undefined,
})

/** Dari first, English third; Pashto texts are not written yet and fall back to Dari. */
export const tr = (fa: string, en: string) => (ui.lang === 'en' ? en : fa)

export const notify = (msg: string) => ui.toast(msg)

/** Name in the chosen language, falling back to Dari. */
export const nameOf = (x: { name: string; name_ps: string | null; name_en: string | null } | null | undefined) =>
  !x ? '' : (ui.lang === 'en' ? x.name_en : ui.lang === 'ps' ? x.name_ps : null) || x.name

const UNIT_FA: Record<string, string> = { hour: 'ساعت', day: 'روز', meter: 'متر', project: 'پروژه', piece: 'دانه' }
const UNIT_EN: Record<string, string> = { hour: 'hour', day: 'day', meter: 'meter', project: 'project', piece: 'piece' }

export function money(amount: string | number, currency: string | null, unit?: string | null): string {
  const n = Number(amount)
  const text = n.toLocaleString(ui.lang === 'en' ? 'en-US' : 'fa-AF', { maximumFractionDigits: 2 })
  const cur = currency === 'USD' ? tr('دالر', 'USD') : tr('افغانی', 'AFN')
  const u = unit ? ' / ' + ((ui.lang === 'en' ? UNIT_EN : UNIT_FA)[unit] ?? unit) : ''
  return `${text} ${cur}${u}`
}

/** Times are stored in UTC and shown in the Jalali calendar (Asia/Kabul). */
export function dateText(iso: string | null | undefined, withTime = false): string {
  if (!iso) return ''
  const d = new Date(iso)
  const locale = ui.lang === 'en' ? 'en-GB' : 'fa-AF-u-ca-persian'
  return new Intl.DateTimeFormat(locale, { timeZone: 'Asia/Kabul', dateStyle: 'medium', ...(withTime ? { timeStyle: 'short' } : {}) }).format(d)
}
