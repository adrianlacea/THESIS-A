<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900">Request Item Return</h1>
      <Button label="Back" severity="secondary" outlined @click="goBack" />
    </div>

    <Card class="border border-slate-200 shadow-none">
      <template #content>
        <div v-if="loading" class="space-y-3">
          <Skeleton v-for="idx in 5" :key="idx" height="1.2rem" />
        </div>
        <div v-else-if="selectedItem" class="space-y-4">
          <div class="rounded-xl border border-slate-200 p-3 text-sm">
            <p><span class="text-slate-500">Order #:</span> <span class="font-semibold">{{ order?.order_number }}</span></p>
            <p><span class="text-slate-500">Item:</span> <span class="font-semibold">{{ selectedItem.product_name }}</span></p>
            <p><span class="text-slate-500">Quantity:</span> <span class="font-semibold">{{ selectedItem.quantity }}</span></p>
          </div>

          <div class="space-y-2">
            <label class="text-sm font-semibold text-slate-700">Return quantity <span class="text-red-500">*</span></label>
            <InputNumber v-model="form.requested_quantity" :min="1" :max="selectedItem.quantity" fluid />
            <small class="text-xs text-slate-500">Max: {{ selectedItem.quantity }}</small>
          </div>

          <div class="space-y-2">
            <label class="text-sm font-semibold text-slate-700">Reason <span class="text-red-500">*</span></label>
            <Select
              v-model="form.reason"
              :options="reasonOptions"
              optionLabel="label"
              optionValue="value"
              fluid
              placeholder="Select a reason"
              showClear
            />
          </div>

          <div class="space-y-2">
            <label class="text-sm font-semibold text-slate-700">Additional details (optional)</label>
            <Textarea v-model="form.details" rows="3" fluid placeholder="Add details (damage, wrong item, etc.)." />
          </div>

          <div class="space-y-2">
            <label class="text-sm font-semibold text-slate-700">Upload photos <span class="text-red-500">*</span></label>
            <input ref="evidenceInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="onEvidenceChange" />
            <div class="flex flex-wrap items-center gap-2">
              <Button
                icon="pi pi-upload"
                label="Choose photos"
                size="small"
                outlined
                severity="secondary"
                @click="openEvidencePicker"
              />
              <span v-if="form.evidence_images.length" class="text-xs text-slate-500">
                {{ form.evidence_images.length }} selected
              </span>
            </div>
            <div v-if="evidenceItems.length" class="flex flex-wrap gap-3 pt-1">
              <button v-for="(item, index) in evidenceItems" :key="item.url" type="button"
                class="group relative h-24 w-24 overflow-hidden rounded-xl border border-slate-200 bg-slate-50"
                :aria-label="`Preview ${item.name}`" @click="evidenceDialogVisible = true">
                <img :src="item.url" :alt="item.name" class="h-full w-full object-cover" />
                <span class="absolute inset-x-0 bottom-0 bg-black/60 px-1 py-1 text-[10px] text-white">Preview</span>
                <span role="button" tabindex="0" aria-label="Remove photo"
                  class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-red-600 shadow"
                  @click.stop="removeEvidence(index)" @keydown.enter.stop="removeEvidence(index)">
                  <i class="pi pi-times text-xs" />
                </span>
              </button>
            </div>
            <div v-if="form.evidence_images.length" class="flex flex-wrap gap-2">
              <Button icon="pi pi-times" label="Clear" size="small" text severity="danger" @click="clearEvidence" />
              <span class="text-xs text-slate-500 self-center">{{ form.evidence_images.length }} file(s)</span>
            </div>
            <small class="text-xs text-slate-500">At least 1 photo is required. Up to 5 JPG, PNG, or WebP images, 4MB each.</small>
          </div>

          <Button label="Submit Return Request" severity="warn" :loading="submitting"
            :disabled="!isFormValid || submitting || confirming" @click="confirmSubmitReturn" />
        </div>
      </template>
    </Card>
  </div>

  <Dialog v-model:visible="evidenceDialogVisible" modal header="Photos" class="w-full max-w-5xl">
    <Galleria
      v-if="evidenceItems.length"
      :value="evidenceItems"
      :numVisible="6"
      :circular="true"
      :showItemNavigators="true"
      :showThumbnails="true"
      containerStyle="max-width: 100%"
    >
      <template #item="{ item }">
        <div class="flex justify-center bg-black/5 rounded-lg overflow-hidden">
          <img :src="item.url" :alt="item.name" class="max-h-[520px] w-auto object-contain" />
        </div>
      </template>
      <template #thumbnail="{ item }">
        <img :src="item.url" :alt="item.name" class="h-14 w-14 object-cover rounded-md" />
      </template>
    </Galleria>
    <div v-else class="py-10 text-center text-sm text-gray-600">No photos selected.</div>
    <template #footer>
      <Button label="Close" severity="secondary" outlined @click="evidenceDialogVisible = false" />
    </template>
  </Dialog>

</template>

<script setup lang="ts">
import EcommerceMobileWrapper from '@/Layouts/EcommerceMobileWrapper.vue'
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ecommerceService from '@/services/ecommerce.service'
import Textarea from 'primevue/textarea'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import { confirmAlert, showAlert } from '@/utils/swal'
defineOptions({
  layout: EcommerceMobileWrapper,
})


const route = useRoute()
const router = useRouter()
const loading = ref(false)
const submitting = ref(false)
const confirming = ref(false)
const order = ref<any>(null)
const form = reactive({
  requested_quantity: 1,
  reason: '',
  details: '',
  evidence_images: [] as File[],
})

const evidenceInput = ref<HTMLInputElement | null>(null)
function openEvidencePicker() {
  evidenceInput.value?.click()
}

const reasonOptions = [
  { label: 'Damaged item', value: 'Damaged item' },
  { label: 'Wrong item received', value: 'Wrong item received' },
  { label: 'Missing parts', value: 'Missing parts' },
  { label: 'Quality issue', value: 'Quality issue' },
  { label: 'Other', value: 'Other' },
]

const selectedItem = computed(() => {
  const itemId = Number(route.params.itemId)
  return (order.value?.items || []).find((item: any) => Number(item.id) === itemId) || null
})

const isFormValid = computed(() => {
  const maxQty = Number(selectedItem.value?.quantity || 0)
  const quantity = Number(form.requested_quantity || 0)
  return Boolean(
    selectedItem.value
    && String(form.reason || '').trim()
    && quantity >= 1
    && quantity <= maxQty
    && form.evidence_images.length >= 1
  )
})

const evidenceDialogVisible = ref(false)
const evidenceItems = ref<Array<{ name: string; url: string }>>([])

function revokeEvidencePreviews() {
  evidenceItems.value.forEach((item) => URL.revokeObjectURL(item.url))
  evidenceItems.value = []
}

function rebuildEvidencePreviews() {
  revokeEvidencePreviews()
  evidenceItems.value = form.evidence_images.map((file, idx) => ({
    name: file.name || `Photo ${idx + 1}`,
    url: URL.createObjectURL(file),
  }))
}

function onEvidenceChange(event: any) {
  const files = Array.from(event?.target?.files || []) as File[]
  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp']
  const validFiles = files.filter((file) => allowedTypes.includes(file.type) && file.size <= 4 * 1024 * 1024)
  form.evidence_images = validFiles.slice(0, 5)
  rebuildEvidencePreviews()

  if (validFiles.length !== files.length) {
    showAlert({ severity: 'warn', summary: 'Invalid photo', detail: 'Use JPG, PNG, or WebP images up to 4MB each.' })
  } else if (files.length > 5) {
    showAlert({ severity: 'warn', summary: 'Photo limit', detail: 'You can upload up to 5 photos.' })
  }
}

function removeEvidence(index: number) {
  form.evidence_images.splice(index, 1)
  rebuildEvidencePreviews()
  if (!form.evidence_images.length && evidenceInput.value) evidenceInput.value.value = ''
}

function clearEvidence() {
  form.evidence_images = []
  revokeEvidencePreviews()
  if (evidenceInput.value) {
    evidenceInput.value.value = ''
  }
}

async function loadOrder() {
  loading.value = true
  try {
    const response = await ecommerceService.getOrder(route.params.id as string)
    order.value = response.data?.data || null
    if (!selectedItem.value || !selectedItem.value.can_return) {
      showAlert({ severity: 'warn', summary: 'Not Allowed', detail: 'Return request is not available for this item.' })
      goBack()
      return
    }
    form.requested_quantity = Math.max(1, Math.min(Number(selectedItem.value.quantity || 1), form.requested_quantity))
  } catch (error: any) {
    showAlert({ severity: 'error', summary: 'Error', detail: error?.response?.data?.message || 'Failed to load order item.' })
    goBack()
  } finally {
    loading.value = false
  }
}

async function submitReturn() {
  if (!selectedItem.value || submitting.value) return
  if (!isFormValid.value) {
    showAlert({ severity: 'warn', summary: 'Required', detail: 'Complete the return quantity, reason, and upload at least one photo.' })
    return
  }

  const maxQty = Number(selectedItem.value.quantity || 1)
  const qty = Math.max(1, Math.min(maxQty, Number(form.requested_quantity || 1)))
  if (qty < 1 || qty > maxQty) {
    showAlert({ severity: 'warn', summary: 'Invalid', detail: `Return quantity must be between 1 and ${maxQty}.` })
    return
  }

  submitting.value = true
  try {
    await ecommerceService.requestOrderReturn(selectedItem.value.id, {
      reason: String(form.reason).trim(),
      details: form.details.trim() || undefined,
      requested_quantity: qty,
      evidence_images: form.evidence_images,
    })
    await showAlert({ severity: 'success', summary: 'Successful', detail: 'Return request sent for store verification.' })
    goBack()
  } catch (error: any) {
    await showAlert({ severity: 'error', summary: 'Failed', detail: error?.response?.data?.message || 'Unable to submit return request.' })
  } finally {
    submitting.value = false
  }
}

async function confirmSubmitReturn() {
  if (!isFormValid.value || submitting.value || confirming.value) return

  confirming.value = true
  try {
    const confirmed = await confirmAlert({
      title: 'Submit return request?',
      text: 'Please confirm you want to submit this return request for verification.',
      confirmText: 'Submit',
      cancelText: 'Cancel',
      reverseButtons: true,
    })
    if (confirmed) await submitReturn()
  } finally {
    confirming.value = false
  }
}

function goBack() {
  router.push({ name: 'ecommerce.order-detail', params: { id: route.params.id } })
}

onMounted(loadOrder)
onBeforeUnmount(revokeEvidencePreviews)
</script>
