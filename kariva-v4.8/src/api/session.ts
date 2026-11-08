import { computed, reactive } from 'vue'
import { ApiError, setActiveActorHeader } from './client'
import { hasMessage, tr } from '../state/ui'
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
export function errorText(e: unknown): string {
  const key = `error.${e instanceof ApiError ? e.code : ''}`
  return tr(hasMessage(key) ? key : 'error.unknown')
}
