/** Shapes returned by the API. Names come in three languages; the UI picks one and falls back to Dari (`name`). */

export interface Named {
  name: string
  name_ps: string | null
  name_en: string | null
}

export interface Category extends Named {
  id: string
  slug: string
  parent_id: string | null
  icon: string | null
  children?: Category[]
}

export interface City extends Named {
  id: string
  province_id: string
}

export interface Province extends Named {
  id: string
  code: string
  cities: City[]
}

export interface Pricing {
  type: 'fixed' | 'from' | 'negotiable'
  amount: string | null
  currency: 'AFN' | 'USD' | null
  unit: 'hour' | 'day' | 'meter' | 'project' | 'piece' | null
}

export interface Service {
  id: string
  actor_id: string
  category?: Category
  title: string
  description: string | null
  delivery_mode: 'onsite' | 'online' | 'both'
  pricing: Pricing
  images?: string[]
  status: 'draft' | 'active' | 'paused' | 'archived'
}

export interface Actor {
  id: string
  type: 'person' | 'business'
  display_name: string
  bio: string | null
  verified: boolean
  status: string
  contact_phone: string | null
  whatsapp: string | null
  category?: Category | null
  city?: City | null
  stats: { completed_count: number; rating_count: number; rating_avg: number | null }
  service_areas?: City[]
  services?: Service[]
  avatar_url?: string | null
  cover_url?: string | null
}

export interface MyActor {
  id: string
  type: 'person' | 'business'
  display_name: string
  status: string
  role: 'owner' | 'admin' | 'editor' | 'viewer'
  is_primary: boolean
  is_personal: boolean
}

export interface Me {
  id: string
  phone: string
  email: string | null
  locale: 'fa' | 'ps' | 'en'
  status: string
  actors: MyActor[]
}

export type Role = 'owner' | 'admin' | 'editor' | 'viewer'

export interface Member {
  user_id: string
  display_name: string | null
  role: Role
  is_primary: boolean
  /** Only managers see phone numbers. */
  phone: string | null
}

export interface Invitation {
  id: string
  actor_id: string
  kind: 'member' | 'transfer_primary'
  role: Role
  status: string
  expires_at: string
  phone?: string
  actor?: { id: string; display_name: string; type: string }
}

export interface PortfolioItem {
  id: string
  actor_id: string
  service_id: string | null
  title: string
  description: string | null
  images: string[]
}
