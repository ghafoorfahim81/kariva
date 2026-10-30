import { computed, reactive, watch } from 'vue'
import { chat, notices } from '../api/endpoints'
import { session } from '../api/session'

/** Unread counters for the header and the bottom bar. In the MVP they are polled; Reverb replaces this later. */
export const live = reactive({ notices: 0, messages: 0 })

let timer: number | undefined

export async function refreshLive() {
  if (!session.user.value) {
    live.notices = 0
    live.messages = 0
    return
  }
  try {
    const n = await notices.list({ unread: true })
    live.notices = n.unread_count
    if (session.state.activeActorId) live.messages = (await chat.list()).reduce((sum, c) => sum + c.unread, 0)
  } catch {
    /* offline or logged out: keep the last numbers */
  }
}

export function startLive() {
  window.clearInterval(timer)
  timer = window.setInterval(() => {
    if (document.visibilityState === 'visible') void refreshLive()
  }, 20000)
  watch([() => session.state.user, () => session.state.activeActorId], () => void refreshLive(), { immediate: true })
}

export const hasUnread = computed(() => live.notices + live.messages > 0)
