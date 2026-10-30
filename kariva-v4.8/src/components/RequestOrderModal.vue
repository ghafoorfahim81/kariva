<script setup lang="ts">
import { ref } from 'vue'
import { orders } from '../api/endpoints'
import { errorText } from '../api/session'
import type { Order } from '../api/types'
import { notify, tr, ui } from '../state/ui'
import Sheet from './Sheet.vue'

/** Ask a specialist or company for work: for one of its services, or directly. */
const props = defineProps<{ providerActorId?: string; serviceId?: string; name: string; title?: string }>()
const emit = defineEmits<{ close: []; created: [order: Order] }>()

const title = ref(props.title ?? '')
const description = ref('')
const busy = ref(false)

async function submit() {
  if (busy.value) return
  busy.value = true
  try {
    const order = await orders.create(
      props.serviceId
        ? { service_id: props.serviceId, description: description.value.trim() || null }
        : { provider_actor_id: props.providerActorId, title: title.value.trim(), description: description.value.trim() || null },
    )
    notify(tr('درخواست شما فرستاده شد ✓', 'Your request was sent ✓'))
    emit('created', order)
  } catch (e) {
    notify(errorText(e, ui.lang))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Sheet :title="tr('درخواست خدمت', 'Request a service')" @close="emit('close')">
    <form class="space-y-3" @submit.prevent="submit">
      <p class="soft text-sm">{{ tr('از:', 'From:') }} <b>{{ name }}</b></p>
      <label v-if="!serviceId" class="block text-sm font-bold">
        {{ tr('چه کاری می‌خواهید؟', 'What do you need?') }}
        <input v-model="title" required minlength="2" maxlength="150" class="v3-input mt-1" :placeholder="tr('مثلاً: نصب سیم‌کشی', 'e.g. wiring a house')" />
      </label>
      <p v-else-if="title" class="font-bold">{{ title }}</p>
      <label class="block text-sm font-bold">
        {{ tr('توضیح (اختیاری)', 'Details (optional)') }}
        <textarea v-model="description" rows="4" maxlength="2000" class="v3-input mt-1" :placeholder="tr('محل، زمان، اندازه...', 'Place, time, size...')"></textarea>
      </label>
      <button type="submit" :disabled="busy" class="v3-primary disabled:opacity-60">{{ tr('ارسال درخواست', 'Send request') }}</button>
    </form>
  </Sheet>
</template>
