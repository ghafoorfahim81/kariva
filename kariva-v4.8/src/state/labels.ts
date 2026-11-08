import type { Notice, OrderStatus } from '../api/types'
import { hasMessage, tr } from './ui'

export const orderStatusText = (s: OrderStatus): string => tr(`order_status.${s}`)

/** Colour of the status chip: open work is blue, success green, the rest grey or red. */
export const orderStatusClass = (s: OrderStatus): string =>
  ({
    invited: 'bg-amber-100 text-amber-800',
    requested: 'bg-blue-100 text-blue-800',
    quoted: 'bg-indigo-100 text-indigo-800',
    accepted: 'bg-cyan-100 text-cyan-800',
    provider_done: 'bg-teal-100 text-teal-800',
    completed: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-rose-100 text-rose-800',
    cancelled: 'bg-slate-200 text-slate-700',
    expired: 'bg-slate-200 text-slate-700',
    disputed: 'bg-orange-100 text-orange-800',
  })[s]

export const eventText = (e: string): string => {
  const key = `order_event.${e}`
  return hasMessage(key) ? tr(key) : e
}

export const REVIEW_TAGS = ['on_time', 'clean', 'honest', 'skilled', 'polite', 'fair_price']
export const tagText = (id: string): string => {
  const key = `review_tag.${id}`
  return hasMessage(key) ? tr(key) : id
}

export const noticeText = (n: Notice): string => {
  const key = `notice.${n.type}`
  return hasMessage(key) ? tr(key, { title: String(n.data.title ?? ''), by: String(n.data.by ?? '') }) : n.type
}
