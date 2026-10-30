import { computed, reactive } from 'vue'
import { ApiError, setActiveActorHeader } from './client'
import { auth } from './endpoints'
import type { Me, MyActor } from './types'

const ACTOR_KEY = 'kariva.activeActor'

const state = reactive<{ user: Me | null; activeActorId: string | null; ready: boolean }>({ user: null, activeActorId: null, ready: false })

function remembered(): string | null {
  try {
    return localStorage.getItem(ACTOR_KEY)
  } catch {
    return null
  }
}

function adopt(user: Me | null) {
  state.user = user
  // keep the remembered actor only while the user can still act as it; otherwise fall back to the personal actor
  const mine = user?.actors ?? []
  const wanted = state.activeActorId ?? remembered()
  state.activeActorId = mine.find((a) => a.id === wanted)?.id ?? mine.find((a) => a.is_personal)?.id ?? mine[0]?.id ?? null
  setActiveActorHeader(state.activeActorId)
  try {
    if (state.activeActorId) localStorage.setItem(ACTOR_KEY, state.activeActorId)
    else localStorage.removeItem(ACTOR_KEY)
  } catch {
    /* private mode: the choice just is not remembered */
  }
}

/** The signed-in user and the actor (person or business page) they are currently acting as. */
export const session = {
  state,
  user: computed(() => state.user),
  signedIn: computed(() => state.user !== null),
  actors: computed<MyActor[]>(() => state.user?.actors ?? []),
  active: computed<MyActor | null>(() => state.user?.actors.find((a) => a.id === state.activeActorId) ?? null),
  personal: computed<MyActor | null>(() => state.user?.actors.find((a) => a.is_personal) ?? null),

  /** Called once at start: restores the cookie session, if there is one. */
  async restore() {
    try {
      adopt(await auth.me())
    } catch (e) {
      if (!(e instanceof ApiError) || e.status !== 401) console.warn('session restore failed', e)
      adopt(null)
    } finally {
      state.ready = true
    }
  },
  async verify(phone: string, code: string) {
    adopt((await auth.verify(phone, code)).user)
  },
  async refresh() {
    adopt(await auth.me())
  },
  switchTo(actorId: string) {
    if (state.user?.actors.some((a) => a.id === actorId)) {
      state.activeActorId = actorId
      adopt(state.user)
    }
  },
  async logout() {
    await auth.logout().catch(() => undefined)
    state.activeActorId = null
    adopt(null)
  },
}

/** Text for an API error code. Unknown codes fall back to a generic line, never to a raw code. */
const MESSAGES: Record<string, { fa: string; en: string }> = {
  'auth.otp_invalid': { fa: 'کد درست نیست یا مهلت آن تمام شده', en: 'The code is wrong or has expired' },
  'auth.otp_rate_limited': { fa: 'چند بار درخواست کد کرده‌اید؛ کمی بعد دوباره تلاش کنید', en: 'Too many code requests. Try again later' },
  'auth.account_unavailable': { fa: 'این حساب فعال نیست', en: 'This account is not available' },
  'actor.forbidden': { fa: 'دسترسی ندارید', en: 'You do not have access' },
  'media.invalid_type': { fa: 'فقط عکس (JPG، PNG، WebP) پذیرفته می‌شود', en: 'Only photos (JPG, PNG, WebP) are accepted' },
  'media.too_large': { fa: 'حجم عکس زیاد است', en: 'The photo is too large' },
  'media.limit_reached': { fa: 'به سقف تعداد عکس رسیده‌اید', en: 'Photo limit reached' },
  'actor.has_open_orders': { fa: 'تا وقتی سفارش باز دارید صفحه حذف نمی‌شود', en: 'A page with open orders cannot be deleted' },
  'invitation.already_pending': { fa: 'برای این شماره دعوت‌نامه‌ی فعال وجود دارد', en: 'This number already has a pending invitation' },
  'invitation.already_member': { fa: 'این شخص عضو همین صفحه است', en: 'This person is already a member' },
  'invitation.cannot_invite_self': { fa: 'نمی‌توانید خودتان را دعوت کنید', en: 'You cannot invite yourself' },
  'invitation.expired': { fa: 'مهلت دعوت‌نامه تمام شده است', en: 'This invitation has expired' },
  'invitation.not_pending': { fa: 'به این دعوت‌نامه قبلاً پاسخ داده شده', en: 'This invitation was already answered' },
  'invitation.inviter_not_primary': { fa: 'مالک اصلی صفحه تغییر کرده؛ دعوت‌نامه معتبر نیست', en: 'Ownership changed; this invitation is no longer valid' },
  'member.primary_locked': { fa: 'مالک اصلی را نمی‌توان تغییر داد یا حذف کرد', en: 'The primary owner cannot be changed or removed' },
  'member.admin_cannot_manage_owners': { fa: 'مدیر نمی‌تواند مالکان را تغییر دهد', en: 'Admins cannot manage owners' },
  'member.last_owner': { fa: 'آخرین مالک را نمی‌توان برداشت', en: 'The last owner cannot be removed' },
  'actor.deletion_not_requested': { fa: 'ابتدا باید درخواست حذف ثبت شود', en: 'Deletion must be requested first' },
  'media.infected': { fa: 'این فایل ایمن نبود و پذیرفته نشد', en: 'This file was not safe and was rejected' },
  'order.service_unavailable': { fa: 'این خدمت فعلاً در دسترس نیست', en: 'This service is not available right now' },
  'order.cannot_order_from_self': { fa: 'نمی‌توانید از خودتان سفارش بگیرید', en: 'You cannot order from yourself' },
  'order.actor_unavailable': { fa: 'این صفحه فعلاً سفارش جدید نمی‌پذیرد', en: 'This page is not taking new orders right now' },
  'order.invalid_state': { fa: 'وضعیت سفارش تغییر کرده؛ صفحه را تازه کنید', en: 'The order changed. Please refresh' },
  'order.wrong_side': { fa: 'این کار از دست شما برنمی‌آید', en: 'You cannot do this on this order' },
  'order.no_pending_quote': { fa: 'قیمتی برای پذیرفتن وجود ندارد', en: 'There is no price to accept' },
  'invited.limit_reached': { fa: 'در یک ماه فقط ۵ «کار گذشته» می‌توانید ثبت کنید', en: 'You can add only 5 past jobs per month' },
  'review.exists': { fa: 'برای این سفارش قبلاً نظر ثبت شده', en: 'This order already has a review' },
  'review.already_replied': { fa: 'قبلاً پاسخ داده‌اید', en: 'You already replied' },
  'need.not_open': { fa: 'این نیازمندی دیگر باز نیست', en: 'This need is no longer open' },
  'need.offers_not_allowed': { fa: 'برای فرصت کاری پیشنهاد قیمت نمی‌دهند؛ پیام بدهید', en: 'Job opportunities take messages, not offers' },
  'offer.already_made': { fa: 'قبلاً پیشنهاد داده‌اید', en: 'You already sent an offer' },
  'offer.own_need': { fa: 'روی نیازمندی خودتان پیشنهاد نمی‌دهید', en: 'You cannot offer on your own need' },
  'offer.not_pending': { fa: 'این پیشنهاد دیگر در انتظار نیست', en: 'This offer is no longer pending' },
  'report.already_open': { fa: 'گزارش شما قبلاً ثبت شده و در بررسی است', en: 'Your report is already being reviewed' },
  'conversation.actor_unavailable': { fa: 'این صفحه در دسترس نیست', en: 'This page is not available' },
  'conversation.with_self': { fa: 'با خودتان نمی‌توانید گفت‌وگو کنید', en: 'You cannot chat with yourself' },
  validation: { fa: 'اطلاعات واردشده درست نیست', en: 'Some information is not valid' },
  rule_violation: { fa: 'این کار مجاز نیست', en: 'This is not allowed' },
}

export function errorText(e: unknown, lang: 'fa' | 'ps' | 'en' = 'fa'): string {
  const code = e instanceof ApiError ? e.code : ''
  const m = MESSAGES[code]
  if (m) return lang === 'en' ? m.en : m.fa
  return lang === 'en' ? 'Something went wrong. Please try again' : 'مشکلی پیش آمد؛ دوباره تلاش کنید'
}
