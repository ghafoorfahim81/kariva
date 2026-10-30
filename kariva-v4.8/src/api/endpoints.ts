import { del, get, patch, post, put, api } from './client'
import type { Actor, Category, ChatMessage, ConversationSummary, Invitation, Me, Member, Need, Notice, Offer, Order, PortfolioItem, Province, Review, Role, Service } from './types'

export const auth = {
  sendOtp: (phone: string) => post<void>('/auth/otp', { phone }),
  verify: (phone: string, code: string) => post<{ user: Me }>('/auth/verify', { phone, code }),
  logout: () => post<void>('/auth/logout'),
  me: () => get<Me>('/me'),
}

export const catalog = {
  categories: () => get<{ data: Category[] }>('/categories').then((r) => r.data),
  provinces: () => get<{ data: Province[] }>('/provinces').then((r) => r.data),
}

export interface ActorInput {
  display_name?: string
  bio?: string | null
  category_id?: string | null
  city_id?: string | null
  contact_phone?: string | null
  whatsapp?: string | null
}

export const actors = {
  search: (q: { q?: string; category?: string; city?: string; type?: 'person' | 'business'; cursor?: string | null; limit?: number }) =>
    get<{ data: Actor[]; next_cursor: string | null }>('/search/actors', q),
  get: (id: string) => get<Actor>(`/actors/${id}`),
  createPage: (input: ActorInput) => post<Actor>('/actors', input),
  update: (id: string, input: ActorInput) => patch<Actor>(`/actors/${id}`, input),
  syncAreas: (id: string, cityIds: string[]) => put<Actor>(`/actors/${id}/service-areas`, { city_ids: cityIds }),
}

export interface ServiceInput {
  title?: string
  description?: string | null
  category_id?: string
  delivery_mode?: Service['delivery_mode']
  pricing_type?: Service['pricing']['type']
  price_amount?: string | null
  currency?: string | null
  price_unit?: string | null
  status?: Service['status']
}

export const services = {
  list: (actorId: string, all = false) => get<{ data: Service[] }>(`/actors/${actorId}/services`, { all: all ? 1 : undefined }).then((r) => r.data),
  create: (actorId: string, input: ServiceInput) => post<Service>(`/actors/${actorId}/services`, input),
  update: (id: string, input: ServiceInput) => patch<Service>(`/services/${id}`, input),
  archive: (id: string) => del<void>(`/services/${id}`),
}

export const media = {
  upload: (modelType: 'actor' | 'service' | 'portfolio_item' | 'verification_request', modelId: string, collection: string, file: File) => {
    const form = new FormData()
    form.set('model_type', modelType)
    form.set('model_id', modelId)
    form.set('collection', collection)
    form.set('file', file)
    return api<{ id: string; scan_status: string; url: string | null }>('POST', '/media', { form })
  },
  remove: (id: string) => del<void>(`/media/${id}`),
  /** Removes the avatar or cover of an actor. */
  removeSlot: (actorId: string, collection: 'avatar' | 'cover') => del<void>(`/actors/${actorId}/photos/${collection}`),
}

export const team = {
  members: (actorId: string) => get<{ data: Member[] }>(`/actors/${actorId}/members`).then((r) => r.data),
  setRole: (actorId: string, userId: string, role: Role) => patch<void>(`/actors/${actorId}/members/${userId}`, { role }),
  remove: (actorId: string, userId: string) => del<void>(`/actors/${actorId}/members/${userId}`),
  invitations: (actorId: string) => get<{ data: Invitation[] }>(`/actors/${actorId}/invitations`).then((r) => r.data),
  invite: (actorId: string, phone: string, role: Role, kind: 'member' | 'transfer_primary' = 'member') =>
    post<Invitation>(`/actors/${actorId}/invitations`, { phone, role, kind }),
  cancelInvitation: (actorId: string, id: string) => del<void>(`/actors/${actorId}/invitations/${id}`),
  mine: () => get<{ data: Invitation[] }>('/invitations').then((r) => r.data),
  respond: (id: string, accept: boolean) => post<Invitation>(`/invitations/${id}/respond`, { accept }),
  requestDeletion: (actorId: string) => post<void>(`/actors/${actorId}/deletion-request`),
  cancelDeletion: (actorId: string) => del<void>(`/actors/${actorId}/deletion-request`),
  confirmDeletion: (actorId: string) => post<void>(`/actors/${actorId}/deletion-confirm`),
}

export const portfolio = {
  list: (actorId: string) => get<{ data: PortfolioItem[] }>(`/actors/${actorId}/portfolio`).then((r) => r.data),
  create: (actorId: string, input: { title: string; description?: string | null; service_id?: string | null }) =>
    post<PortfolioItem>(`/actors/${actorId}/portfolio`, input),
  update: (id: string, input: { title?: string; description?: string | null }) => patch<PortfolioItem>(`/portfolio/${id}`, input),
  remove: (id: string) => del<void>(`/portfolio/${id}`),
}

export interface QuoteInput {
  amount: string
  currency: string
  price_unit: string
  days?: number | null
  note?: string | null
}

type Page<T> = { data: T[]; next_cursor: string | null }

export const orders = {
  list: (q: { role?: 'customer' | 'provider'; status?: string; cursor?: string | null } = {}) => get<Page<Order>>('/orders', q),
  get: (id: string) => get<Order>(`/orders/${id}`),
  create: (input: { service_id?: string; provider_actor_id?: string; title?: string; description?: string | null }) => post<Order>('/orders', input),
  quote: (id: string, input: QuoteInput) => post<Order>(`/orders/${id}/quote`, input),
  step: (id: string, step: 'accept' | 'reject' | 'cancel' | 'done' | 'confirm' | 'dispute') => post<Order>(`/orders/${id}/${step}`),
  review: (id: string, input: { stars: number; tags?: string[]; body?: string | null }) => post<Review>(`/orders/${id}/review`, input),
  invite: (input: { phone: string; title: string; description?: string | null }) => post<Order>('/invited-jobs', input),
  resendCode: (id: string) => post<void>(`/orders/${id}/invited-code`),
  confirmInvited: (id: string, code: string) => post<Order>(`/orders/${id}/confirm-invited`, { code }),
}

export const needs = {
  board: (q: { kind?: string; category?: string; city?: string; cursor?: string | null } = {}) => get<Page<Need>>('/needs', q),
  mine: (cursor?: string | null) => get<Page<Need>>('/my/needs', { cursor }),
  get: (id: string) => get<Need>(`/needs/${id}`),
  create: (input: { kind: 'service' | 'job'; category_id: string; city_id?: string | null; title: string; description?: string | null }) => post<Need>('/needs', input),
  close: (id: string) => post<Need>(`/needs/${id}/close`),
  offers: (id: string) => get<{ data: Offer[] }>(`/needs/${id}/offers`).then((r) => r.data),
  offer: (id: string, input: { amount: string; currency: string; price_unit: string; days?: number | null; message?: string | null }) => post<Offer>(`/needs/${id}/offers`, input),
  withdraw: (offerId: string) => post<Offer>(`/offers/${offerId}/withdraw`),
  rejectOffer: (offerId: string) => post<Offer>(`/offers/${offerId}/reject`),
  acceptOffer: (offerId: string) => post<Order>(`/offers/${offerId}/accept`),
}

export const chat = {
  list: () => get<{ data: ConversationSummary[] }>('/conversations').then((r) => r.data),
  direct: (actorId: string) => post<{ id: string; other: { id: string; display_name: string } }>('/conversations/direct', { actor_id: actorId }),
  messages: (id: string, cursor?: string | null) => get<Page<ChatMessage>>(`/conversations/${id}/messages`, { cursor }),
  send: (id: string, body: string) => post<ChatMessage>(`/conversations/${id}/messages`, { body }),
  read: (id: string) => post<void>(`/conversations/${id}/read`),
}

export const notices = {
  list: (q: { unread?: boolean; cursor?: string | null } = {}) => get<Page<Notice> & { unread_count: number }>('/notifications', { unread: q.unread ? 1 : undefined, cursor: q.cursor }),
  read: (id: string) => post<void>(`/notifications/${id}/read`),
  readAll: () => post<void>('/notifications/read-all'),
}

export const reviews = {
  ofActor: (actorId: string, cursor?: string | null) => get<Page<Review>>(`/actors/${actorId}/reviews`, { cursor }),
  reply: (id: string, body: string) => post<Review>(`/reviews/${id}/reply`, { body }),
}

export const trust = {
  report: (type: 'actor' | 'service' | 'review' | 'message', id: string, reason: string, details?: string) => post<{ id: string }>('/reports', { type, id, reason, details }),
  favorites: () => get<{ data: { type: string; id: string }[] }>('/favorites').then((r) => r.data),
  addFavorite: (type: 'actor' | 'service' | 'need', id: string) => put<unknown>(`/favorites/${type}/${id}`),
  removeFavorite: (type: 'actor' | 'service' | 'need', id: string) => del<void>(`/favorites/${type}/${id}`),
}

export interface VerificationState {
  verified: boolean
  request: { id: string; kind: string; status: 'pending' | 'approved' | 'rejected'; note: string | null; documents: number; created_at: string; reviewed_at: string | null } | null
}

export const verification = {
  get: () => get<VerificationState>('/verification'),
  request: () => post<{ id: string }>('/verification'),
}

type AdminPage<T> = { data: T[]; next_cursor: string | null }

export interface AdminVerification {
  id: string
  status: string
  kind: string
  actor: { id: string; display_name: string; type: string }
  requester_phone: string | null
  documents: { id: string; mime: string; size: number; scan_status: string }[]
  note: string | null
  created_at: string
}
export interface AdminReport {
  id: string
  status: string
  type: 'actor' | 'service' | 'review' | 'message'
  subject: { title: string | null; text: string | null; actor_id: string } | null
  reason: string
  details: string | null
  reporter_phone: string | null
  created_at: string
}
export interface AdminActor {
  id: string
  type: string
  display_name: string
  status: string
  verified: boolean
  phone: string | null
  user_id: string | null
  user_status: string | null
}
export interface AdminOverview {
  users: number
  actors: { person: number; business: number }
  verified_actors: number
  pending_verifications: number
  open_reports: number
  suspended_actors: number
  orders_last_7_days: number
  completed_orders: number
  open_needs: number
}
export interface AuditRow {
  id: string
  action: string
  created_at: string
  meta: Record<string, unknown> | null
}

export const admin = {
  overview: () => get<AdminOverview>('/admin/overview'),
  verifications: (cursor?: string | null) => get<AdminPage<AdminVerification>>('/admin/verifications', { cursor }),
  approve: (id: string, note?: string) => post<AdminVerification>(`/admin/verifications/${id}/approve`, { note }),
  reject: (id: string, note: string) => post<AdminVerification>(`/admin/verifications/${id}/reject`, { note }),
  reports: (cursor?: string | null) => get<AdminPage<AdminReport>>('/admin/reports', { cursor }),
  resolve: (id: string, action?: string, note?: string) => post<AdminReport>(`/admin/reports/${id}/resolve`, { action, note }),
  dismiss: (id: string, note?: string) => post<AdminReport>(`/admin/reports/${id}/dismiss`, { note }),
  actors: (q: string, cursor?: string | null) => get<AdminPage<AdminActor>>('/admin/actors', { q, cursor }),
  suspendActor: (id: string) => post<{ status: string }>(`/admin/actors/${id}/suspend`),
  unsuspendActor: (id: string) => post<{ status: string }>(`/admin/actors/${id}/unsuspend`),
  suspendUser: (id: string) => post<{ status: string }>(`/admin/users/${id}/suspend`),
  unsuspendUser: (id: string) => post<{ status: string }>(`/admin/users/${id}/unsuspend`),
  audit: (cursor?: string | null) => get<AdminPage<AuditRow>>('/admin/audit-logs', { cursor }),
  health: () => get<{ status: string; checks: Record<string, { ok: boolean }> }>('/admin/health'),
}
