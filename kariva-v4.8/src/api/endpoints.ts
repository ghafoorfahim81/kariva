import { del, get, patch, post, put, api } from './client'
import type { Actor, Category, Me, Province, Service } from './types'

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
  upload: (modelType: 'actor' | 'service' | 'portfolio_item', modelId: string, collection: string, file: File) => {
    const form = new FormData()
    form.set('model_type', modelType)
    form.set('model_id', modelId)
    form.set('collection', collection)
    form.set('file', file)
    return api<{ id: string; scan_status: string; url: string | null }>('POST', '/media', { form })
  },
  remove: (id: string) => del<void>(`/media/${id}`),
}
