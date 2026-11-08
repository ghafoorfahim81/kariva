// Fails the build when UI text is written directly in the code, or when a translation's placeholders differ from Dari.
// Missing keys are caught by the type checker: ps.ts and en.ts must have exactly the keys of fa.ts.
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const SRC = new URL('../src/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')

// Dari that is data, not UI text. Each entry says why it stays.
const ALLOWED = {
  'App.vue': [
    "'۰۱۲۳۴۵۶۷۸۹'", // digit normalizing
    "'٠١٢٣٤٥٦٧٨٩'", // digit normalizing
    '[۰-۹]', // digit normalizing
    '[٠-٩]', // digit normalizing
    '[,٬\\s]', // number normalizing
    "'کاربر '", // compared with the default name the server gives a new user
    "cityByName('کابل')", // default city looked up in server data
    '>دری<', // a language's name in its own language
    '>پښتو<', // a language's name in its own language
    ...["'برق'", "'نل'", "'نجار'", "'رنگ'", "'خیاط'", "'آشپز'", "'مکانیک'", "'گرافیک'"], // search terms sent to the server, which holds Dari names
  ],
}

const walk = (dir) => readdirSync(dir).flatMap((f) => {
  const p = join(dir, f)
  return statSync(p).isDirectory() ? (f === 'i18n' ? [] : walk(p)) : /\.(vue|ts)$/.test(f) ? [p] : []
})

const problems = []
for (const file of walk(SRC)) {
  const rel = file.slice(SRC.length).replaceAll('\\', '/')
  let text = readFileSync(file, 'utf8')
  for (const allowed of ALLOWED[rel] ?? []) text = text.split(allowed).join('')
  text.split(/\r?\n/).forEach((line, i) => {
    const m = line.match(/.{0,40}[؀-ۿ]+.{0,40}/)
    if (m) problems.push(`${rel}:${i + 1}: Dari in code, use a translation key: ${m[0]}`)
  })
}

const entries = (lang) => [...readFileSync(join(SRC, 'i18n', `${lang}.ts`), 'utf8').matchAll(/^ {2}"([^"]+)": "((?:[^"\\]|\\.)*)",$/gm)].map((m) => [m[1], m[2]])
const holes = (text) => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join()
const dari = new Map(entries('fa'))
for (const lang of ['ps', 'en']) {
  for (const [key, text] of entries(lang)) {
    if (dari.has(key) && holes(text) !== holes(dari.get(key))) problems.push(`i18n/${lang}.ts: placeholders of "${key}" differ from Dari`)
  }
}

if (problems.length) {
  console.error(problems.join('\n'))
  process.exit(1)
}
console.log(`i18n ok: ${dari.size} keys`)
