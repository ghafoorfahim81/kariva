/** Thin fetch wrapper for the Kariva API: cookie session (Sanctum), CSRF, the active actor header, clean errors. */

export class ApiError extends Error {
  constructor(
    public status: number,
    /** Stable machine code such as "auth.otp_invalid"; the UI translates it. */
    public code: string,
    public errors: Record<string, string[]> = {},
    public data: Record<string, unknown> = {},
  ) {
    super(code)
  }
}

const BASE = '/api/v1'
let activeActorId: string | null = null
let csrfReady = false

export function setActiveActorHeader(id: string | null) {
  activeActorId = id
}

function xsrfToken(): string {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : ''
}

async function ensureCsrf() {
  if (csrfReady && xsrfToken()) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
  csrfReady = true
}

type Query = Record<string, string | number | boolean | null | undefined>

export async function api<T = unknown>(method: string, path: string, opts: { body?: unknown; query?: Query; form?: FormData } = {}): Promise<T> {
  const url = new URL(BASE + path, window.location.origin)
  for (const [k, v] of Object.entries(opts.query ?? {})) if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v))

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (activeActorId) headers['X-Actor-Id'] = activeActorId
  if (method !== 'GET') await ensureCsrf()

  let body: BodyInit | undefined
  if (opts.form) body = opts.form
  else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(opts.body)
  }

  for (let attempt = 0; attempt < 2; attempt++) {
    if (method !== 'GET') headers['X-XSRF-TOKEN'] = xsrfToken()
    const res = await fetch(url, { method, headers, body, credentials: 'include' })
    if (res.status === 204) return undefined as T

    const json = await res.json().catch(() => ({}))
    if (!res.ok) {
      const { code, message, errors, ...rest } = json as Record<string, unknown>
      // A CSRF mismatch clears the token so the next attempt fetches a fresh one.
      if (res.status === 419 && attempt === 0) { csrfReady = false; await ensureCsrf(); continue }
      throw new ApiError(res.status, (code as string) ?? (res.status === 422 ? 'validation' : String(message ?? 'error')), (errors as Record<string, string[]>) ?? {}, rest)
    }
    return json as T
  }
  /* istanbul ignore next */
  throw new ApiError(500, 'error')
}

export const get = <T>(path: string, query?: Query) => api<T>('GET', path, { query })
export const post = <T>(path: string, body?: unknown) => api<T>('POST', path, { body })
export const patch = <T>(path: string, body?: unknown) => api<T>('PATCH', path, { body })
export const put = <T>(path: string, body?: unknown) => api<T>('PUT', path, { body })
export const del = <T>(path: string) => api<T>('DELETE', path)
