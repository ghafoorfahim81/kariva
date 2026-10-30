import type { Notice, OrderStatus } from '../api/types'
import { tr } from './ui'

export const orderStatusText = (s: OrderStatus): string => {
  const m: Record<OrderStatus, [string, string]> = {
    invited: ['منتظر تأیید مشتری (کار گذشته)', 'Waiting for the customer (past job)'],
    requested: ['درخواست فرستاده شد', 'Request sent'],
    quoted: ['قیمت داده شد', 'Price given'],
    accepted: ['قبول شد؛ کار جریان دارد', 'Accepted; work in progress'],
    provider_done: ['کار تمام شد؛ منتظر تأیید مشتری', 'Work done; waiting for the customer'],
    completed: ['تکمیل شد', 'Completed'],
    rejected: ['رد شد', 'Rejected'],
    cancelled: ['لغو شد', 'Cancelled'],
    expired: ['مهلت تمام شد', 'Expired'],
    disputed: ['اختلاف؛ در بررسی', 'Disputed; under review'],
  }
  return tr(...m[s])
}

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
  const m: Record<string, [string, string]> = {
    created: ['درخواست ثبت شد', 'Request created'],
    created_from_offer: ['پیشنهاد پذیرفته شد و سفارش ساخته شد', 'Offer accepted; order created'],
    quoted: ['قیمت پیشنهاد شد', 'A price was given'],
    accepted: ['مشتری قیمت را پذیرفت', 'The customer accepted the price'],
    rejected: ['ارائه‌دهنده رد کرد', 'The provider declined'],
    cancelled: ['مشتری لغو کرد', 'The customer cancelled'],
    provider_done: ['ارائه‌دهنده پایان کار را اعلام کرد', 'The provider finished the work'],
    completed: ['مشتری تکمیل را تأیید کرد', 'The customer confirmed completion'],
    auto_completed: ['به‌طور خودکار تکمیل شد', 'Completed automatically'],
    disputed: ['مشتری اعلام اختلاف کرد', 'The customer reported a problem'],
    expired: ['مهلت تمام شد', 'Expired'],
    invited: ['کار گذشته ثبت شد', 'Past job recorded'],
    confirmed_by_code: ['مشتری با کد تأیید کرد', 'Confirmed by the customer with a code'],
  }
  return m[e] ? tr(...m[e]) : e
}

export const REVIEW_TAGS: [string, string, string][] = [
  ['on_time', 'به‌موقع', 'On time'],
  ['clean', 'تمیز', 'Clean'],
  ['honest', 'درستکار', 'Honest'],
  ['skilled', 'ماهر', 'Skilled'],
  ['polite', 'خوش‌برخورد', 'Polite'],
  ['fair_price', 'قیمت منصفانه', 'Fair price'],
]
export const tagText = (id: string) => {
  const t = REVIEW_TAGS.find((x) => x[0] === id)
  return t ? tr(t[1], t[2]) : id
}

export const noticeText = (n: Notice): string => {
  const title = String(n.data.title ?? '')
  const by = String(n.data.by ?? '')
  const m: Record<string, [string, string]> = {
    'order.requested': [`درخواست تازه: ${title}`, `New request: ${title}`],
    'order.quoted': [`${by} قیمت داد: ${title}`, `${by} gave a price: ${title}`],
    'order.accepted': [`قیمت پذیرفته شد: ${title}`, `Price accepted: ${title}`],
    'order.rejected': [`درخواست رد شد: ${title}`, `Request declined: ${title}`],
    'order.cancelled': [`درخواست لغو شد: ${title}`, `Request cancelled: ${title}`],
    'order.provider_done': [`کار تمام شد؛ لطفاً تأیید کنید: ${title}`, `Work finished; please confirm: ${title}`],
    'order.completed': [`سفارش تکمیل شد: ${title}`, `Order completed: ${title}`],
    'order.review_invite': [`برای «${title}» نظر بدهید`, `Please review: ${title}`],
    'order.disputed': [`مشتری مشکل اعلام کرد: ${title}`, `The customer reported a problem: ${title}`],
    'order.expired': [`مهلت سفارش تمام شد: ${title}`, `Order expired: ${title}`],
    'offer.received': [`${by} پیشنهاد داد: ${title}`, `${by} sent an offer: ${title}`],
    'offer.accepted': [`پیشنهاد شما پذیرفته شد: ${title}`, `Your offer was accepted: ${title}`],
    'offer.rejected': [`پیشنهاد شما رد شد: ${title}`, `Your offer was declined: ${title}`],
    'need.expired': [`مهلت نیازمندی تمام شد: ${title}`, `Your need expired: ${title}`],
    'review.received': ['یک نظر تازه گرفتید', 'You received a new review'],
    'review.replied': ['ارائه‌دهنده به نظر شما پاسخ داد', 'The provider replied to your review'],
    'verification.approved': ['هویت شما تأیید شد ✔', 'Your identity was verified ✔'],
    'verification.rejected': ['درخواست تأیید رد شد؛ دلیل را ببینید', 'Verification declined; see the reason'],
    'report.resolved': ['گزارش شما بررسی و رسیدگی شد', 'Your report was reviewed and acted on'],
    'report.dismissed': ['گزارش شما بررسی شد؛ تخلفی دیده نشد', 'Your report was reviewed; no violation found'],
    'actor.suspended': ['صفحهٔ شما تعلیق شد', 'Your page was suspended'],
    'actor.unsuspended': ['تعلیق صفحهٔ شما برداشته شد', 'Your page suspension was lifted'],
    'message.new': [`پیام تازه از ${by}`, `New message from ${by}`],
  }
  return m[n.type] ? tr(...m[n.type]) : n.type
}
