<script setup lang="ts">
import { ref } from 'vue'
import { orders } from '../api/endpoints'
import { errorText } from '../api/session'
import type { Order } from '../api/types'
import { notify, tr } from '../state/ui'
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
    notify(tr('request.your_request_was_sent'))
    emit('created', order)
  } catch (e) {
    notify(errorText(e))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Sheet :title="tr('request.request_a_service')" @close="emit('close')">
    <form class="space-y-3" @submit.prevent="submit">
      <p class="soft text-sm">{{ tr('request.from') }} <b>{{ name }}</b></p>
      <label v-if="!serviceId" class="block text-sm font-bold">
        {{ tr('request.what_do_you_need') }}
        <input v-model="title" required minlength="2" maxlength="150" class="v3-input mt-1" :placeholder="tr('request.e_g_wiring_a_house')" />
      </label>
      <p v-else-if="title" class="font-bold">{{ title }}</p>
      <label class="block text-sm font-bold">
        {{ tr('common.details_optional') }}
        <textarea v-model="description" rows="4" maxlength="2000" class="v3-input mt-1" :placeholder="tr('request.place_time_size')"></textarea>
      </label>
      <button type="submit" :disabled="busy" class="v3-primary disabled:opacity-60">{{ tr('request.send_request') }}</button>
    </form>
  </Sheet>
</template>
