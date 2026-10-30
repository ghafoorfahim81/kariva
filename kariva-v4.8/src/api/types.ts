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

export type OrderStatus = 'invited' | 'requested' | 'quoted' | 'accepted' | 'provider_done' | 'completed' | 'rejected' | 'cancelled' | 'expired' | 'disputed'

export interface Quote {
  id: string
  amount: string
  currency: 'AFN' | 'USD'
  unit: string
  days: number | null
  note: string | null
  status: string
  created_at: string
}

export interface OrderParty {
  id: string
  display_name: string
  type: 'person' | 'business'
  contact_phone?: string | null
  whatsapp?: string | null
}

export interface Order {
  id: string
  origin: 'service' | 'direct' | 'offer' | 'invited'
  status: OrderStatus
  my_side: 'customer' | 'provider' | null
  title: string
  description: string | null
  service_id: string | null
  customer?: OrderParty
  provider?: OrderParty
  agreed: { amount: string; currency: 'AFN' | 'USD'; unit: string } | null
  pending_quote?: Quote | null
  quotes?: Quote[]
  events?: { event: string; by_actor_id: string | null; from: string | null; to: string | null; at: string }[]
  conversation_id?: string | null
  review?: { id: string; stars: number } | null
  code_sent?: boolean
  created_at: string
  completed_at: string | null
}

export interface Need {
  id: string
  kind: 'service' | 'job'
  status: string
  title: string
  description: string | null
  category: Category | null
  city: City | null
  actor: { id: string; display_name: string; type: string }
  is_mine: boolean
  pending_offers?: number
  expires_at: string | null
  created_at: string
}

export interface Offer {
  id: string
  need_id: string
  status: 'pending' | 'accepted' | 'rejected' | 'withdrawn'
  amount: string
  currency: 'AFN' | 'USD'
  unit: string
  days: number | null
  message: string | null
  provider: OrderParty
  created_at: string
}

export interface ConversationSummary {
  id: string
  kind: 'order' | 'direct'
  order_id: string | null
  order_title: string | null
  order_status: string | null
  other: { id: string; display_name: string; type: string }
  last_message: string | null
  last_message_at: string | null
  unread: number
}

export interface ChatMessage {
  id: string
  sender_actor_id: string
  mine: boolean
  kind: 'text' | 'media'
  body: string | null
  created_at: string
}

export interface Notice {
  id: string
  type: string
  data: Record<string, unknown>
  read: boolean
  created_at: string
}

export interface Review {
  id: string
  order_id: string
  stars: number
  tags: string[]
  body: string | null
  reply: { body: string; at: string } | null
  created_at: string
  customer?: { id: string; display_name: string | null }
}
