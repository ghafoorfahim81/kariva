// Favorites with real UUIDs, against the throwaway kariva_e2e database (login code on the page).
const { chromium } = require('playwright-core')
const path = require('path')
const API = 'http://127.0.0.1:8100/api/v1'
const WEB = 'http://localhost:5199/'
const shots = process.argv[2]
const results = []
const check = (name, ok, extra = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? '  -> ' + extra : ''}`) }

async function call(method, url, body, token, actor) {
  const res = await fetch(API + url, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}), ...(actor ? { 'X-Actor-Id': actor } : {}) }, body: body ? JSON.stringify(body) : undefined })
  const text = await res.text()
  if (!res.ok) throw new Error(`${method} ${url} ${res.status}: ${text.slice(0, 300)}`)
  return text ? JSON.parse(text) : null
}
async function signIn(phone) {
  const { code } = await call('POST', '/auth/otp', { phone })
  const r = await call('POST', '/auth/verify', { phone, code })
  return { token: r.token, actor: r.user.actors[0].id }
}
async function browserLogin(page, phone) {
  await page.goto(WEB)
  await page.waitForTimeout(2000)
  await page.getByRole('button', { name: 'ورود', exact: true }).first().click()
  await page.locator('input[type=tel]').fill(phone)
  await page.getByRole('button', { name: 'دریافت کد' }).click()
  await page.getByTestId('shown-code').waitFor({ timeout: 10000 })
  const code = ((await page.getByTestId('shown-code').innerText()).match(/\d{6}/) || [''])[0]
  await page.locator('input[autocomplete=one-time-code]').fill(code)
  await page.locator('input[autocomplete=one-time-code]').press('Enter')
  await page.waitForTimeout(3000)
}

/** Favorite chips are inside the section with the favorites heading. */
const favChips = (page) => page.locator('section', { hasText: 'موردعلاقه‌های من' }).locator('button.chip')
const goMe = async (page) => { await page.getByRole('button', { name: 'من', exact: true }).last().click(); await page.waitForTimeout(1500) }

/** Wait until the favorites section shows exactly `n` chips (up to 8 seconds). */
async function waitForChipCount(page, n, timeout = 8000) {
  await page.waitForFunction(
    (n) => {
      const sec = [...document.querySelectorAll('section')].find(s => s.textContent?.includes('موردعلاقه'))
      return sec ? sec.querySelectorAll('button.chip').length === n : n === 0
    },
    n, { timeout }
  )
}

;(async () => {
  const cats = (await call('GET', '/categories')).data
  const leaf = cats.find((c) => c.parent_id) ?? cats[0]
  const city = (await call('GET', '/provinces')).data[0].cities[0]
  const pro = await signIn('0700000011')
  await call('PATCH', `/actors/${pro.actor}`, { display_name: 'کریم برق‌کار', category_id: leaf.id, city_id: city.id, bio: 'ده سال تجربه' }, pro.token, pro.actor)
  const owner = await signIn('0700000022')
  const shop = await call('POST', '/actors', { display_name: 'شرکت نور کابل', category_id: leaf.id, city_id: city.id, bio: 'خدمات برق ساختمان' }, owner.token, owner.actor)
  await call('POST', `/actors/${shop.id}/services`, { title: 'برق‌کاری ساختمان', category_id: leaf.id, delivery_mode: 'onsite', pricing_type: 'negotiable', status: 'active' }, owner.token, shop.id)

  // Clean stale favorites from previous test runs (kariva_e2e DB is never wiped)
  const cust = await signIn('0700000033')
  try {
    const favs = await call('GET', '/favorites', null, cust.token, cust.actor)
    for (const f of (favs.data || [])) {
      await call('DELETE', `/favorites/${f.type}/${f.id}`, null, cust.token, cust.actor)
    }
  } catch { /* fresh user has no favorites yet */ }

  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  const errors = []
  const watch = (page) => {
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message))
    page.on('response', (r) => { if (r.status() >= 500) errors.push(`http ${r.status()}: ${r.url()}`) })
  }

  // ---- save two favorites
  let ctx = await browser.newContext({ viewport: { width: 390, height: 844 } })
  let page = await ctx.newPage(); watch(page)
  await browserLogin(page, '0700000033')
  await page.getByText('متخصص می‌خواهم').click()
  await page.getByText('کریم برق‌کار').first().waitFor({ timeout: 10000 })
  await page.getByRole('button', { name: 'علاقه‌مندی' }).first().click()
  await page.waitForTimeout(800)
  await page.locator('button.chip', { hasText: 'شرکت‌ها' }).click()
  await page.getByText('شرکت نور کابل').first().waitFor({ timeout: 10000 })
  await page.getByText('مشاهده صفحه تجاری').first().click()
  // Wait for the company profile AND its services to load before favoriting
  await page.locator('div.public-profile-body').last().getByText('برق‌کاری ساختمان').waitFor({ timeout: 8000 })
  // Use CSS class selector — robust against heart-character encoding and overlay stacking issues
  await page.locator('div.public-profile-body').last().locator('button.border-rose-300').click()
  await page.waitForTimeout(800)
  await page.mouse.click(4, 4)
  await page.waitForTimeout(500)
  // dismiss any stray modal/overlay before navigating (safety)
  await page.keyboard.press('Escape')
  await page.waitForTimeout(200)

  await goMe(page)
  await waitForChipCount(page, 2, 10000)
  let chips = await favChips(page).allInnerTexts()
  check('each favorite appears once with its own name', chips.length === 2 && chips.some((c) => c.includes('کریم برق‌کار')) && chips.some((c) => c.includes('شرکت نور کابل')), JSON.stringify(chips))
  check('no favorite shows my own name', !chips.some((c) => /کاربر/.test(c)))
  await page.screenshot({ path: path.join(shots, '40-favorites.png') })

  await page.reload(); await page.waitForTimeout(3000); await goMe(page)
  await waitForChipCount(page, 2, 8000)
  chips = await favChips(page).allInnerTexts()
  check('favorites come back from the server after a reload', chips.length === 2, JSON.stringify(chips))

  // ---- open them while they are not in the search results
  await page.getByRole('button', { name: 'خانه', exact: true }).last().click()
  await page.getByText('متخصص می‌خواهم').click()
  await page.locator('input[aria-label="Search discovery"]').fill('zzzzzz')
  await page.waitForTimeout(1500)
  await goMe(page)
  await favChips(page).filter({ hasText: 'شرکت نور کابل' }).click()
  // Wait for profile AND its async-loaded services
  await page.locator('div.public-profile-body').getByText('برق‌کاری ساختمان').waitFor({ timeout: 8000 })
  let t = await page.locator('body').innerText()
  check('a saved company opens with its own page', t.includes('خدمات برق ساختمان') && t.includes('برق‌کاری ساختمان'))
  await page.screenshot({ path: path.join(shots, '41-favorite-company.png') })
  await page.mouse.click(4, 4); await page.waitForTimeout(500)
  await favChips(page).filter({ hasText: 'کریم برق‌کار' }).click()
  await page.getByText('درباره متخصص').waitFor({ timeout: 8000 })
  // Scope the name check to the opened profile panel (not the whole page which also has the Me tab h2)
  const profileName = await page.locator('div.public-profile-body').locator('h2').first().innerText()
  check('a saved specialist opens with their own name', profileName.includes('کریم برق‌کار'), profileName)
  await page.locator('div.public-profile-body').last().locator('button.border-rose-300').click()
  await page.waitForTimeout(800)  // API call
  await page.mouse.click(4, 4); await page.waitForTimeout(500)
  // Wait until the chip count drops to 1 (chip for کریم برق‌کار removed)
  await waitForChipCount(page, 1, 5000)
  chips = await favChips(page).allInnerTexts()
  check('removing a favorite takes it off the list', chips.length === 1 && chips[0].includes('شرکت نور کابل'), JSON.stringify(chips))
  await ctx.close()

  // ---- names are fetched when search never returned the page
  ctx = await browser.newContext({ viewport: { width: 390, height: 844 } })
  page = await ctx.newPage(); watch(page)
  await page.route('**/api/v1/search/actors**', (route) => route.fulfill({ json: { data: [], next_cursor: null } }))
  await browserLogin(page, '0700000033')
  await goMe(page)
  // loadFavorites fetches actor details even when search is blocked; wait for the chip to appear
  await favChips(page).filter({ hasText: 'شرکت نور کابل' }).waitFor({ timeout: 10000 })
  chips = await favChips(page).allInnerTexts()
  check('a favorite outside every search still has its name', chips.length === 1 && chips[0].includes('شرکت نور کابل'), JSON.stringify(chips))
  await favChips(page).first().click()
  // Wait for the company bio to appear in the profile
  await page.locator('div.public-profile-body').getByText('خدمات برق ساختمان').waitFor({ timeout: 8000 })
  check('and it opens', true)
  await ctx.close()

  await browser.close()
  check('no page errors and no server 5xx', errors.length === 0, errors.slice(0, 4).join(' | '))
  const failed = results.filter((r) => !r).length
  console.log(`\n${results.length - failed}/${results.length} passed`)
  process.exit(failed ? 1 : 0)
})().catch((e) => { console.error('CRASH: ' + String(e.stack || e).slice(0, 700)); console.log(`${results.filter(Boolean).length}/${results.length} passed before the crash`); process.exit(2) })
