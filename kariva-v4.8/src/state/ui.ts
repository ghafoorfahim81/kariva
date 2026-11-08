import { reactive } from 'vue'
import { en } from '../i18n/en'
import { fa, type MessageKey } from '../i18n/fa'
import { ps } from '../i18n/ps'

/** Language and toast shared between App.vue and the screens that live in their own files. */
export type Lang = 'fa' | 'ps' | 'en'

const LANG_KEY = 'kariva-lang'

function rememberedLang(): Lang {
  try {
    const saved = localStorage.getItem(LANG_KEY)
    return saved === 'ps' || saved === 'en' ? saved : 'fa'
  } catch {
    return 'fa'
  }
}

export const ui = reactive<{ lang: Lang; toast: (msg: string) => void }>({
  lang: rememberedLang(),
  toast: () => undefined,
})

export function rememberLang(lang: Lang) {
  ui.lang = lang
  try {
    localStorage.setItem(LANG_KEY, lang)
  } catch {
    /* private mode: the choice just is not remembered */
  }
}

const CATALOG: Record<Lang, Record<MessageKey, string>> = { fa, ps, en }

export const hasMessage = (key: string): key is MessageKey => Object.hasOwn(fa, key)

/** UI text for a translation key; {name} placeholders are filled from params. User-written text goes in params, untranslated. */
export function tr(key: MessageKey, params?: Record<string, string | number>): string {
  const text = CATALOG[ui.lang][key]
  return params ? text.replace(/\{(\w+)\}/g, (whole, name) => (name in params ? String(params[name]) : whole)) : text
}

export const notify = (msg: string) => ui.toast(msg)

/** Name in the chosen language, falling back to Dari. */
export const nameOf = (x: { name: string; name_ps: string | null; name_en: string | null } | null | undefined) =>
  !x ? '' : (ui.lang === 'en' ? x.name_en : ui.lang === 'ps' ? x.name_ps : null) || x.name

export function money(amount: string | number, currency: string | null, unit?: string | null): string {
  const n = Number(amount)
  const text = n.toLocaleString(ui.lang === 'en' ? 'en-US' : 'fa-AF', { maximumFractionDigits: 2 })
  const cur = currency === 'USD' ? tr('common.usd') : tr('common.afn')
  const unitKey = `unit.${unit}`
  const u = unit ? ' / ' + (hasMessage(unitKey) ? tr(unitKey) : unit) : ''
  return `${text} ${cur}${u}`
}

/** Times are stored in UTC and shown in the Jalali calendar (Asia/Kabul). */
export function dateText(iso: string | null | undefined, withTime = false): string {
  if (!iso) return ''
  const d = new Date(iso)
  const locale = ui.lang === 'en' ? 'en-GB' : 'fa-AF-u-ca-persian'
  return new Intl.DateTimeFormat(locale, { timeZone: 'Asia/Kabul', dateStyle: 'medium', ...(withTime ? { timeStyle: 'short' } : {}) }).format(d)
}
