<template>
  <div class="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3"><Button icon="pi pi-arrow-left" text rounded
          @click="router.push({name:'finance.refunds'})" />
        <div>
          <h1 class="text-xl font-semibold text-slate-900">Refund Detail</h1>
          <p class="text-sm text-slate-500">Finance authorization and customer refund posting.</p>
        </div>
      </div>
      <Tag v-if="refund" :value="label(refund.status)" :severity="severity(refund.status)" />
    </header>
    <div v-if="loading" class="py-12 text-center text-slate-500">Loading refund...</div>
    <template v-else-if="refund">
      <div class="grid gap-6 lg:grid-cols-3">
        <Card class="rounded-2xl border border-slate-200 shadow-sm lg:col-span-2"><template #title><span
              class="text-base">Refund Request</span></template><template #content>
            <div class="grid gap-4 text-sm sm:grid-cols-2 p-1">
              <Info label="Order Reference" :value="refund.order_number || `Refund #${refund.id}`" />
              <Info label="Customer" :value="refund.customer_name || '-'" />
              <Info label="Source" :value="label(refund.order_type)" />
              <Info label="Amount" :value="currency(refund.amount)" />
              <Info label="Refund Method" :value="refundMethodLabel(refund.refund_method)" />
              <Info label="Account Name" :value="refund.refund_account_name || '-'" />
              <Info label="Account / Mobile Number" :value="refund.refund_account_number || '-'" />
              <Info label="Reason" :value="refund.reason || '-'" class="sm:col-span-2" />
              <Info label="Branch" :value="refund.branch?.name || '-'" />
              <Info label="Requested" :value="dateTime(refund.created_at)" />
              <Info label="Requested By" :value="person(refund.requester)" />
              <Info label="Processed By" :value="person(refund.processor)" />
              <Info label="Processed At" :value="dateTime(refund.processed_at)" />
              <Info label="Payout Reference" :value="refund.payout_reference || '-'" />
              <Info label="Payout Provider" :value="refund.payout_provider === 'paymongo' ? 'PayMongo' : refund.payout_provider || '-'" />
              <Info label="PayMongo Refund ID" :value="refund.paymongo_refund_id || '-'" />
              <Info label="Sent By" :value="person(refund.sender)" />
              <Info label="Sent At" :value="dateTime(refund.sent_at)" />
              <Info label="Notes" :value="refund.notes || '-'" class="sm:col-span-2" />
            </div>
          </template></Card>
        <Card class="rounded-2xl border border-slate-200 shadow-sm"><template #title><span class="text-base">Finance
              Action</span></template><template #content>
            <div class="space-y-4">
              <p v-if="refund.status==='pending_inspection'" class="rounded-xl bg-blue-50 p-3 text-sm text-blue-700">
                Awaiting return delivery and inventory inspection before Finance can release funds.</p><Textarea
                v-model="notes" rows="5" fluid placeholder="Finance notes" />
              <p v-if="refund.status==='pending' && refund.order_type==='ecommerce_return' && !hasRefundDestination"
                class="rounded-xl bg-amber-50 p-3 text-sm text-amber-700">
                Waiting for the customer to provide a refund payment method in Ecommerce Order Details.
              </p>
              <div v-if="refund.status==='pending'" class="grid gap-2">
                <Button label="Approve Refund" icon="pi pi-check" :disabled="!canApprove"
                  :loading="processing==='approved'" @click="process('approved')" />
                <Button label="Reject Refund" icon="pi pi-times" severity="danger" outlined :disabled="!canReject"
                  :loading="processing==='rejected'" @click="process('rejected')" />
              </div>
              <div v-if="refund.status==='approved'" class="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                <p class="font-semibold text-emerald-900">Approved — send the money manually</p>
                <p class="text-sm text-emerald-800">
                  Send {{ currency(refund.amount) }} to {{ refund.refund_account_name }} via
                  {{ refundMethodLabel(refund.refund_method) }} ({{ refund.refund_account_number }}).
                </p>
                <p class="text-xs text-emerald-700">PayMongo refunds return to the original successful PayMongo payment source. Verify that it matches the customer's selected account before sending.</p>
                <Button label="Send Refund via PayMongo" icon="pi pi-send" fluid :loading="sending"
                  :disabled="!canSendPaymongo" @click="markSent('paymongo')" />
                <div class="flex items-center gap-2 text-xs text-emerald-800"><span class="h-px flex-1 bg-emerald-200" />or manual payout<span class="h-px flex-1 bg-emerald-200" /></div>
                <InputText v-model="payoutReference" fluid placeholder="Manual transaction/reference number" />
                <Button label="Confirm Manual Money Sent" icon="pi pi-check" severity="secondary" outlined fluid :loading="sending"
                  :disabled="!canSendManual" @click="markSent('manual')" />
              </div>
              <p v-if="refund.status==='sent'" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">
                Refund money was sent to the customer. Reference: {{ refund.payout_reference }}
              </p>
            </div>
          </template></Card>
      </div>
      <Card v-if="refund.ecommerce_return" class="rounded-2xl border border-slate-200 shadow-sm"><template #title><span
            class="text-base">Linked CRM Return</span></template><template #content>
          <div class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <Info label="Return Number" :value="refund.ecommerce_return.return_number || `Return #${refund.order_id}`" />
            <Info label="Return Status" :value="label(refund.ecommerce_return.status)" />
            <Info label="Resolution" :value="label(refund.ecommerce_return.return_type)" />
            <Info label="Requested Quantity" :value="String(refund.ecommerce_return.requested_quantity || 1)" />
            <Info label="Product" :value="refund.ecommerce_return.order_item?.product_name || '-'" />
            <Info label="SKU" :value="refund.ecommerce_return.order_item?.sku || '-'" />
            <Info label="Pickup Status" :value="label(refund.ecommerce_return.pickup?.status)" />
            <Info label="Destination" :value="refund.ecommerce_return.pickup?.destination_branch?.name || '-'" />
          </div>
        </template></Card>
    </template>
  </div>
</template>
<script setup lang="ts">
import { computed, defineComponent, h, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useAuthStore } from '@/stores/auth'
import financeService from '@/services/finance.service'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import InputText from 'primevue/inputtext'

const Info = defineComponent({
  props: { label: String, value: String },
  setup: p => () => h('div', [
    h('p', { class: 'text-xs font-medium uppercase tracking-wide text-slate-500' }, p.label),
    h('p', { class: 'mt-1 font-semibold text-slate-900' }, p.value || '-'),
  ]),
})

const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const loading = ref(false)
const refund = ref<any>(null)
const notes = ref('')
const payoutReference = ref('')
const processing = ref('')
const sending = ref(false)
const hasRefundDestination = computed(() => Boolean(
  refund.value?.refund_method && refund.value?.refund_account_name && refund.value?.refund_account_number,
))
const canApprove = computed(() => auth.hasPermission('finance.refunds.approve')
  && refund.value?.status === 'pending' && hasRefundDestination.value)
const canReject = computed(() => auth.hasPermission('finance.refunds.approve') && refund.value?.status === 'pending')
const canSendPaymongo = computed(() => auth.hasPermission('finance.refunds.approve')
  && refund.value?.status === 'approved' && hasRefundDestination.value)
const canSendManual = computed(() => canSendPaymongo.value && payoutReference.value.trim())
const label = (v: any) => String(v || '-').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase())
const refundMethodLabel = (v: any) => ({ gcash: 'GCash', card: 'Card', maya: 'Maya', bank_transfer: 'Bank Transfer' } as any)[String(v || '')] || '-'
const severity = (v: string) => v === 'sent' || v === 'approved' ? 'success' : v === 'rejected' ? 'danger' : v === 'pending_inspection' ? 'info' : 'warn'
const currency = (v: any) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(v || 0))
const dateTime = (v: any) => v ? new Date(v).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '-'
const person = (u: any) => u ? [u.fname, u.lname].filter(Boolean).join(' ') || u.email : '-'

const load = async () => {
  loading.value = true
  try {
    const response = await financeService.getRefund(String(route.params.id))
    refund.value = response?.data || null
    notes.value = refund.value?.notes || ''
    payoutReference.value = refund.value?.payout_reference || ''
  } catch (error: any) {
    toast.add({ severity: 'error', summary: 'Load Failed', detail: error?.response?.data?.message || 'Unable to load refund.', life: 3000 })
  } finally {
    loading.value = false
  }
}

const process = async (status: 'approved' | 'rejected') => {
  processing.value = status
  try {
    await financeService.updateRefundStatus(String(route.params.id), { status, notes: notes.value })
    toast.add({ severity: 'success', summary: 'Refund Updated', detail: status === 'approved' ? 'Refund approved. Send the money to the customer account next.' : 'Refund rejected.', life: 3000 })
    await load()
  } catch (error: any) {
    toast.add({ severity: 'error', summary: 'Processing Failed', detail: error?.response?.data?.message || 'Unable to process refund.', life: 3500 })
  } finally {
    processing.value = ''
  }
}

const markSent = async (provider: 'paymongo' | 'manual') => {
  if (provider === 'paymongo' ? !canSendPaymongo.value : !canSendManual.value) return
  sending.value = true
  try {
    await financeService.markRefundSent(String(route.params.id), {
      provider,
      payout_reference: payoutReference.value.trim() || undefined,
      notes: notes.value,
    })
    toast.add({ severity: 'success', summary: 'Refund Sent', detail: 'The refund was recorded as sent to the customer.', life: 3000 })
    await load()
  } catch (error: any) {
    toast.add({ severity: 'error', summary: 'Unable to Mark Sent', detail: error?.response?.data?.message || 'Unable to mark the refund as sent.', life: 3500 })
  } finally {
    sending.value = false
  }
}

onMounted(load)
</script>
