<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Order Details</h1>
      </div>
      <div class="flex items-center gap-2">
        <Button v-if="order?.can_cancel" label="Cancel" severity="danger" outlined @click="goCancelPage" />
        <Button label="Back" severity="secondary" outlined @click="goBack" />
      </div>
    </div>
  
    <div v-if="loading" class="space-y-4">
      <Card class="border border-slate-200 shadow-none">
        <template #content>
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <Skeleton v-for="idx in 6" :key="idx" height="1.25rem" />
          </div>
        </template>
      </Card>
    </div>

      <Card v-if="showTransitDetails" class="overflow-hidden border border-slate-200 shadow-none">
        <template #content>
          <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p class="font-semibold text-slate-900">Live Delivery Tracking</p>
                <p class="text-xs text-slate-500">The route follows available roads from the truck to your delivery address.</p>
              </div>
              <Tag value="Live" severity="success" />
            </div>
            <div v-if="order.delivery?.current_address" class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-900">
              <i class="pi pi-map-marker mr-2 text-blue-600"></i>{{ order.delivery.current_address }}
            </div>
            <div class="overflow-hidden rounded-2xl border border-slate-200">
              <div ref="trackingMapElement" class="h-[340px] w-full sm:h-[440px]"></div>
              <p v-if="!hasTrackingCoordinates" class="px-4 py-3 text-sm text-amber-700">Waiting for the driverâ€™s live GPS location.</p>
            </div>

            <div v-if="deliveryTimeline.length" class="space-y-3 border-t border-slate-100 pt-4">
              <p class="text-sm font-semibold text-slate-900">Delivery Logs</p>
              <div v-for="item in deliveryTimeline" :key="`${item.type}-${item.created_at}`" class="rounded-xl border border-slate-200 p-3">
                <div class="flex flex-wrap justify-between gap-2"><p class="text-sm font-medium text-slate-900">{{ item.title }}</p><span class="text-xs text-slate-400">{{ formatDateTime(item.created_at) }}</span></div>
                <p class="mt-1 text-xs text-slate-600">{{ formatTimelineDescription(item.description) }}</p>
                <div v-if="proofUrls(item).length" class="mt-3 flex flex-wrap gap-3">
                  <button v-for="proof in proofUrls(item)" :key="proof.url" type="button" class="group relative overflow-hidden rounded-xl border border-slate-200 bg-slate-50" @click="previewMedia(proof.url, proof.title)">
                    <img :src="proof.url" :alt="proof.title" class="h-28 w-36 object-cover transition group-hover:scale-105" />
                    <span class="absolute inset-x-0 bottom-0 bg-slate-950/65 px-2 py-1 text-xs text-white">{{ proof.title }}</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </template>
      </Card>
    <div v-if="loading" class="space-y-4">
      <Card class="border border-slate-200 shadow-none">
        <template #content>
          <div class="space-y-3">
            <Skeleton v-for="idx in 4" :key="idx" height="3rem" />
          </div>
        </template>
      </Card>
    </div>
  
    <template v-else-if="order">
      <Card class="border border-slate-200 shadow-none">
        <template #content>
          <div class="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
            <div><span class="text-slate-500">Order #:</span> <span class="font-semibold">{{ order.order_number }}</span>
            </div>
            <div><span class="text-slate-500">Date:</span> <span class="font-semibold">{{ formatDate(order.created_at)
                }}</span></div>
            <div><span class="text-slate-500">Estimated Delivery:</span> <span class="font-semibold">{{ formatEstimatedDelivery(order.delivery?.estimated_delivery_at) }}</span></div>
            <div><span class="text-slate-500">Status:</span>
              <Badge :value="statusLabel(order.primary_status || order.status)" />
            </div>
           
            <div class="md:col-span-2"><span class="text-slate-500">Shipping Address:</span> <span
                class="font-semibold">{{ order.shipping_address || '-' }}</span></div>
            <template v-if="showTransitDetails">
              <div><span class="text-slate-500">Tracking Number:</span> <span class="font-semibold">{{
                  order.delivery?.tracking_number || '-' }}</span></div>
              <div><span class="text-slate-500">Courier:</span> <span class="font-semibold">{{
                  order.delivery?.courier_name || '-' }}</span></div>
              <div><span class="text-slate-500">Courier Contact:</span> <span class="font-semibold">{{
                  order.delivery?.courier_contact || '-' }}</span></div>
            </template>
           
          </div>
        </template>
      </Card>
  
      <Card class="border border-slate-200 shadow-none">
        <template #content>
          <div class="space-y-3">
            <div class="flex items-center justify-between border-b border-slate-100 pb-2">
              <p class="text-sm font-semibold text-slate-800">Store: {{ order.store_name || 'Store' }}</p>
              <Button v-if="order.store_id" label="Chat" icon="pi pi-comments" size="small" severity="help" text
                @click="goChatStore" />
            </div>
  
            <div v-for="item in order.items || []" :key="item.id"
              class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-3 py-3">
              <div class="flex min-w-0 items-center gap-3">
                <img :src="normalizeImageUrl(item.image) || '/F.svg'" alt="Product"
                  class="h-14 w-14 rounded-xl border border-slate-200 object-cover" @error="onImageError" />
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-slate-900">{{ item.product_name }}</p>
                  <p class="truncate text-xs text-slate-500">Variant: {{ item.sku || 'Standard' }}</p>
                  <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                    <span>Unit: {{ item.unit_of_measurement || 'â€”' }}</span>
                    <span v-if="item.category_name">Category: {{ item.category_name }}</span>
                    <span v-if="item.brand">Brand: {{ item.brand }}</span>
                    <span v-if="item.weight_kg !== null && item.weight_kg !== undefined">Weight: {{ item.weight_kg }} kg</span>
                  </div>
                  <p v-if="item.description" class="mt-1 line-clamp-2 text-xs text-slate-500">{{ item.description }}</p>
                  <p v-if="item.dimensions && (item.dimensions.length_cm || item.dimensions.width_cm || item.dimensions.height_cm)" class="text-xs text-slate-500">
                    Dimensions: {{ item.dimensions.length_cm || 0 }} cm Ã— {{ item.dimensions.width_cm || 0 }} cm Ã— {{ item.dimensions.height_cm || 0 }} cm
                  </p>
                </div>
              </div>
  
              <div class="mt-2 flex w-full flex-col gap-2 sm:mt-0 sm:w-auto sm:flex-row sm:items-center sm:gap-5">
                <Tag :value="statusLabel(order.primary_status || order.status)" severity="secondary" class="w-fit" />

                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:flex sm:items-center sm:gap-5">
                  <p class="text-slate-600">{{ formatMoney(item.unit_price) }}</p>
                  <p class="font-semibold text-slate-700">Qty {{ item.quantity }}</p>
                  <p class="col-span-2 text-base font-semibold text-slate-900 sm:col-span-1">
                    {{ formatMoney(item.line_total) }}
                  </p>
                </div>

                <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                  <Button
                    v-if="item.can_return"
                    label="Return"
                    size="small"
                    severity="danger"
                    outlined
                    class="w-full sm:w-auto"
                    @click="goReturnPage(item.id)"
                  />
                  <Button
                    v-if="item.can_review"
                    label="Review"
                    severity="warn"
                    size="small"
                    class="w-full sm:w-auto"
                    @click="goReviewPage(item.id)"
                  />
                  <Button
                    v-if="canSetRefundMethod(item)"
                    :label="item.return_request.refund?.refund_method ? 'Update Refund Method' : 'Set Refund Method'"
                    icon="pi pi-wallet"
                    severity="info"
                    size="small"
                    outlined
                    class="w-full sm:w-auto"
                    @click="openRefundMethod(item.return_request)"
                  />
                </div>
              </div>
              <div v-if="item.return_request || item.review"
                class="w-full space-y-3 border-t border-slate-100 pt-3 text-xs text-slate-600">
                <p v-if="item.review">Your review: {{ item.review.rating }}/5</p>
                <div v-if="item.return_request?.refund?.refund_method"
                  class="rounded-xl border border-blue-100 bg-blue-50 p-3 text-sm text-blue-900">
                  <p class="font-semibold">Refund Account</p>
                  <p class="mt-1">{{ refundMethodLabel(item.return_request.refund.refund_method) }}</p>
                  <p>{{ item.return_request.refund.refund_account_name }}</p>
                  <p>{{ item.return_request.refund.refund_account_number }}</p>
                  <p v-if="item.return_request.refund.status === 'sent'" class="mt-2 font-medium">Finance sent the refund. Expected arrival: less than 30 days.</p>
                  <p v-else class="mt-2 font-medium">Estimated arrival: less than 30 days after Finance sends the refund.</p>
                </div>
                <div v-else-if="item.return_request?.return_type === 'refund'"
                  class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                  <p class="font-semibold">Refund approved</p>
                  <p class="mt-1">Choose where you want to receive the refund. Estimated arrival is less than 30 days after Finance sends it.</p>
                </div>

                <div v-if="item.return_request?.investigation_ticket"
                  class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                  <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p class="text-sm font-semibold text-slate-900">Return Investigation Ticket</p>
                      <p class="mt-0.5 text-xs text-slate-500">
                        Reference {{ item.return_request.investigation_ticket.reference_number || ('RET-' + String(item.return_request.investigation_ticket.id).padStart(6, '0')) }}
                      </p>
                    </div>
                    <Tag
                      :value="formatStatus(item.return_request.investigation_ticket.status)"
                      severity="warn"
                      class="text-xs"
                    />
                  </div>

                  <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                      <p class="text-slate-500">Expected investigation date</p>
                      <p class="mt-0.5 font-medium text-slate-800">
                        {{ formatDate(item.return_request.investigation_ticket.expected_investigation_date) }}
                      </p>
                    </div>
                    <div>
                      <p class="text-slate-500">Assigned investigation team</p>
                      <p class="mt-0.5 font-medium text-slate-800">
                        {{ investigationAssignees(item.return_request.investigation_ticket) }}
                      </p>
                    </div>
                  </div>

                  <div v-if="item.return_request.investigation_ticket.notes" class="mt-3">
                    <p class="text-slate-500">Investigation notes</p>
                    <p class="mt-0.5 whitespace-pre-line text-slate-700">
                      {{ item.return_request.investigation_ticket.notes }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
  
          <div class="mt-4 ml-auto max-w-sm space-y-2 text-sm">
            <div class="flex justify-between"><span>Subtotal</span><span>{{ formatMoney(order.subtotal) }}</span></div>
            <div class="flex justify-between"><span>VATable Sales</span><span>{{ formatMoney(vatableSales) }}</span></div>
            <div class="flex justify-between"><span>VAT Included (12%)</span><span>{{ formatMoney(order.tax_amount) }}</span></div>
            <div class="flex justify-between"><span>Shipping</span><span>{{ formatMoney(order.shipping_fee) }}</span></div>
            <div class="flex justify-between"><span>Discount</span><span>- {{ formatMoney(order.discount_amount) }}</span></div>
            <Divider />
            <div class="flex justify-between text-base font-bold"><span>Total</span><span>{{ formatMoney(order.total_amount) }}</span></div>
          </div>
        </template>
      </Card>
  
      <Card class="border border-slate-200 shadow-none">
        <template #content>
          <div class="mb-3">
            <p class="text-sm font-semibold text-slate-900">Order Timeline</p>
            <p class="text-xs text-slate-500">Track all status changes and delivery updates.</p>
          </div>
          <ul v-if="order.timeline?.length" class="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
            <li v-for="item in order.timeline" :key="`${item.type}-${item.created_at}`" class="flex gap-3 p-4">
              <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-blue-600"></span>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-900">{{ item.title }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ formatTimelineDescription(item.description) }}</p>
                <p class="mt-1 text-xs text-slate-400">
                  {{ formatDateTime(item.created_at) }} â€¢ {{ item.actor || 'System' }}
                </p>
                <div v-if="proofUrls(item).length" class="mt-3 flex flex-wrap gap-3">
                  <button v-for="proof in proofUrls(item)" :key="proof.url" type="button"
                    class="group relative overflow-hidden rounded-xl border border-slate-200 bg-slate-50"
                    @click="previewMedia(proof.url, proof.title)">
                    <img :src="proof.url" :alt="proof.title" class="h-28 w-36 object-cover transition group-hover:scale-105" />
                    <span class="absolute inset-x-0 bottom-0 bg-slate-950/65 px-2 py-1 text-xs text-white">{{ proof.title }}</span>
                  </button>
                </div>
              </div>
            </li>
          </ul>
          <p v-else class="text-sm text-slate-500">No timeline yet.</p>
        </template>
      </Card>
    </template>
  
    <Dialog v-model:visible="refundDialogVisible" modal header="Refund Payment Method" class="w-full max-w-lg">
      <div class="space-y-4">
        <p class="text-sm text-slate-600">Choose where Finance should send the approved refund.</p>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Payment method *</label>
          <Select v-model="refundForm.refund_method" :options="refundMethodOptions"
            optionLabel="label" optionValue="value" fluid placeholder="Select a payment method" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Account name *</label>
          <InputText v-model="refundForm.refund_account_name" fluid placeholder="Name registered on the account" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">
            {{ refundForm.refund_method === 'card' ? 'Card number or refund reference *' : 'GCash mobile number *' }}
          </label>
          <InputText v-model="refundForm.refund_account_number" fluid
            :placeholder="refundForm.refund_method === 'card' ? 'Card number or reference' : '09XXXXXXXXX'" />
          <p v-if="refundForm.refund_method === 'card'" class="mt-2 text-xs text-slate-500">Never enter your CVV or PIN.</p>
        </div>
      </div>
      <template #footer>
        <Button label="Cancel" severity="secondary" outlined @click="refundDialogVisible = false" />
        <Button label="Save Refund Method" icon="pi pi-save" :loading="savingRefundMethod"
          :disabled="!refundFormValid || savingRefundMethod" @click="saveRefundMethod" />
      </template>
    </Dialog>

    <Dialog v-model:visible="mediaPreview.visible" modal :header="mediaPreview.title" class="w-full max-w-4xl">
      <div class="flex items-center justify-center rounded-lg bg-slate-50 p-2">
        <img v-if="mediaPreview.url" :src="mediaPreview.url" alt="Delivery proof"
          class="max-h-[70vh] w-auto rounded-lg object-contain" />
      </div>
      <template #footer>
        <Button label="Open Full View" icon="pi pi-external-link" outlined @click="openExternal(mediaPreview.url)" />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import EcommerceMobileWrapper from '@/Layouts/EcommerceMobileWrapper.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ecommerceService from '@/services/ecommerce.service'
import paymongoService from '@/services/paymongo.service'
import Dialog from 'primevue/dialog'
import { showAlert } from '@/utils/swal'
import type { Map as MapboxMap, Marker as MapboxMarker } from 'mapbox-gl'
import 'mapbox-gl/dist/mapbox-gl.css'
import { fetchMapboxRoadRoute, requireMapboxToken } from '@/utils/mapbox'
defineOptions({
  layout: EcommerceMobileWrapper,
})


const route = useRoute()
const router = useRouter()
const loading = ref(false)
const order = ref<any>(null)
const refundDialogVisible = ref(false)
const savingRefundMethod = ref(false)
const activeReturnId = ref<number | null>(null)
const refundMethodOptions = [
  { label: 'GCash', value: 'gcash' },
  { label: 'Card', value: 'card' },
]
const refundForm = reactive({ refund_method: '', refund_account_name: '', refund_account_number: '' })
const refundFormValid = computed(() => Boolean(
  refundForm.refund_method
    && refundForm.refund_account_name.trim()
    && refundForm.refund_account_number.trim(),
))
const trackingMapElement = ref<HTMLElement | null>(null)
let trackingMap: MapboxMap | null = null
let mapboxgl: typeof import('mapbox-gl').default | null = null
let truckMarker: MapboxMarker | null = null
let destinationMarker: MapboxMarker | null = null
let trackingRefreshTimer: number | null = null
const vatableSales = computed(() => Math.max(
  0,
  Number(order.value?.subtotal || 0)
    - Number(order.value?.discount_amount || 0)
    - Number(order.value?.tax_amount || 0),
))
const mediaPreview = reactive({
  visible: false,
  url: '',
  title: 'Preview',
})

const deliveryTimeline = computed(() => (order.value?.timeline || []).filter((item: any) => item.type !== 'order_created'))
const currentPoint = computed<[number, number] | null>(() => {
  const latitude = Number(order.value?.delivery?.current_latitude)
  const longitude = Number(order.value?.delivery?.current_longitude)
  return Number.isFinite(latitude) && Number.isFinite(longitude) && latitude !== 0 && longitude !== 0 ? [latitude, longitude] : null
})
const destinationPoint = computed<[number, number] | null>(() => {
  const latitude = Number(order.value?.customer_latitude)
  const longitude = Number(order.value?.customer_longitude)
  return Number.isFinite(latitude) && Number.isFinite(longitude) && latitude !== 0 && longitude !== 0 ? [latitude, longitude] : null
})
const hasTrackingCoordinates = computed(() => Boolean(currentPoint.value && destinationPoint.value))

async function loadOrderDetail() {
  loading.value = true
  try {
    const response = await ecommerceService.getOrder(route.params.id as string)
    order.value = response.data?.data || null
    await syncPaymongoPaymentStatus()
    await nextTick()
    await renderTrackingMap()
    updateTrackingRefresh()
  } catch (error: any) {
    showAlert({ severity: 'error', summary: 'Error', detail: error?.response?.data?.message || 'Failed to load order details' })
    router.push({ name: 'ecommerce.orders' })
  } finally {
    loading.value = false
  }
}

async function syncPaymongoPaymentStatus() {
  const currentOrder = order.value
  if (!currentOrder?.id) return
  if (String(currentOrder.payment_method || '').toLowerCase() !== 'e_wallet') return
  if (String(currentOrder.payment_status || '').toLowerCase() === 'paid') return

  try {
    const latestIntentResponse = await paymongoService.getLatestIntentByPayable('ecommerce_order', Number(currentOrder.id))
    const latestIntent = latestIntentResponse?.data
    const intentId = latestIntent?.payment_intent_id
    if (!intentId) return

    await paymongoService.getIntent(String(intentId))

    const refreshed = await ecommerceService.getOrder(currentOrder.id)
    const refreshedOrder = refreshed.data?.data || currentOrder
    const beforeStatus = String(currentOrder.payment_status || '').toLowerCase()
    const afterStatus = String(refreshedOrder.payment_status || '').toLowerCase()

    order.value = refreshedOrder

    if (beforeStatus !== 'paid' && afterStatus === 'paid') {
      showAlert({ severity: 'success', summary: 'Payment Confirmed', detail: 'Your GCash payment was confirmed.' })
    }
  } catch {
    // Keep order page usable even if Online Payment status refresh fails.
  }
}

const showTransitDetails = computed(() => {
  const primary = String(order.value?.primary_status || '').toLowerCase()
  if (primary === 'in_transit') return true
  const deliveryStatus = String(order.value?.delivery?.status || '').toLowerCase()
  return ['in_transit', 'out_for_delivery', 'on_delivery'].includes(deliveryStatus)
})

function proofUrls(item: any) {
  const proofs: Array<{ url: string; title: string }> = []
  if (item?.meta?.proof_photo_url) proofs.push({ url: normalizeProofUrl(item.meta.proof_photo_url), title: 'Proof Photo' })
  if (item?.meta?.proof_signature_url) proofs.push({ url: normalizeProofUrl(item.meta.proof_signature_url), title: 'Signature' })
  return proofs
}

function normalizeProofUrl(raw: string) {
  if (!raw) return ''
  if (/^(https?:|data:)/.test(raw) || raw.startsWith('/api/') || raw.startsWith('/storage/')) return raw
  return normalizeImageUrl(raw)
}

async function renderTrackingMap() {
  if (!showTransitDetails.value || !trackingMapElement.value) {
    trackingMap?.remove()
    trackingMap = null
    truckMarker = null
    destinationMarker = null
    return
  }

  try {
    if (!trackingMap) {
      mapboxgl = (await import('mapbox-gl')).default
      mapboxgl.accessToken = requireMapboxToken()
      const center = currentPoint.value || destinationPoint.value || [14.5995, 120.9842]
      trackingMap = new mapboxgl.Map({
        container: trackingMapElement.value,
        style: 'mapbox://styles/mapbox/streets-v12',
        center: [center[1], center[0]],
        zoom: currentPoint.value || destinationPoint.value ? 13 : 10,
      })
      await new Promise<void>((resolve) => trackingMap!.once('load', () => resolve()))
    }

    if (!trackingMap || !mapboxgl) return

    truckMarker?.remove()
    truckMarker = null
    destinationMarker?.remove()
    destinationMarker = null

    if (currentPoint.value) {
      const truckElement = document.createElement('img')
      truckElement.src = '/images/truck-map-marker-orange.png'
      truckElement.alt = 'Truck location'
      truckElement.style.cssText = 'width:56px;height:56px;object-fit:contain;'
      truckMarker = new mapboxgl.Marker({ element: truckElement, anchor: 'center' })
        .setLngLat([currentPoint.value[1], currentPoint.value[0]])
        .setPopup(new mapboxgl.Popup({ offset: 25 }).setText('Truck location'))
        .addTo(trackingMap)
    }

    if (destinationPoint.value) {
      const destinationElement = document.createElement('div')
      destinationElement.style.cssText = 'width:18px;height:18px;border:3px solid white;border-radius:50%;background:#2563eb;box-shadow:0 1px 5px #0008;'
      destinationMarker = new mapboxgl.Marker({ element: destinationElement, anchor: 'center' })
        .setLngLat([destinationPoint.value[1], destinationPoint.value[0]])
        .setPopup(new mapboxgl.Popup({ offset: 15 }).setText('Your delivery address'))
        .addTo(trackingMap)
    }

    if (currentPoint.value && destinationPoint.value) {
      let routePoints: [number, number][] = [currentPoint.value, destinationPoint.value]
      try {
        const roadPoints = await fetchMapboxRoadRoute(currentPoint.value, destinationPoint.value)
        if (roadPoints.length > 1) routePoints = roadPoints
      } catch {
        // Keep the direct fallback line if Mapbox road routing is unavailable.
      }
      const routeFeature = {
        type: 'Feature' as const,
        properties: {},
        geometry: { type: 'LineString' as const, coordinates: routePoints.map(([lat, lng]) => [lng, lat]) },
      }
      if (trackingMap.getSource('customer-delivery-route')) {
        (trackingMap.getSource('customer-delivery-route') as import('mapbox-gl').GeoJSONSource).setData(routeFeature)
      } else {
        trackingMap.addSource('customer-delivery-route', { type: 'geojson', data: routeFeature })
        trackingMap.addLayer({ id: 'customer-delivery-route-line', type: 'line', source: 'customer-delivery-route', paint: { 'line-color': '#2563eb', 'line-width': 5, 'line-opacity': 0.8 } })
      }
      const bounds = new mapboxgl.LngLatBounds()
      routePoints.forEach(([lat, lng]) => bounds.extend([lng, lat]))
      trackingMap.fitBounds(bounds, { padding: 40, maxZoom: 15 })
    } else if (currentPoint.value || destinationPoint.value) {
      const point = currentPoint.value || destinationPoint.value!
      trackingMap.flyTo({ center: [point[1], point[0]], zoom: 13 })
    }
    trackingMap.resize()
  } catch (error: any) {
    showAlert({ severity: 'error', summary: 'Map Unavailable', detail: error?.message || 'Unable to load the delivery map.' })
  }
}

async function refreshTracking() {
  if (!order.value?.id || !showTransitDetails.value) return
  try {
    const response = await ecommerceService.getOrder(String(order.value.id))
    order.value = response.data?.data || order.value
    await nextTick()
    await renderTrackingMap()
    updateTrackingRefresh()
  } catch {
    // Preserve the last known position if a polling request temporarily fails.
  }
}

function updateTrackingRefresh() {
  if (!showTransitDetails.value) {
    if (trackingRefreshTimer !== null) window.clearInterval(trackingRefreshTimer)
    trackingRefreshTimer = null
    return
  }
  if (trackingRefreshTimer === null) trackingRefreshTimer = window.setInterval(refreshTracking, 15000)
}

function statusLabel(status: string) {
  const value = String(status || '').toLowerCase()
  if (value === 'pending') return 'Pending'
  if (value === 'packing') return 'Packing'
  if (value === 'in_transit') return 'In Transit'
  if (value === 'delivered') return 'Delivered'
  if (value === 'cancel_pending') return 'Cancel Pending'
  if (value === 'cancelled') return 'Cancelled'
  if (value === 'return_pending') return 'Return Pending'
  if (value === 'return_approved') return 'Return Approved'
  if (value === 'return_received') return 'Return Received'
  if (value === 'return_processing' || value === 'refund_pending') return 'Refund Pending'
  if (value === 'refunded') return 'Refunded'
  if (value === 'replaced') return 'Replaced'
  return formatStatus(status)
}

function formatDate(value: string) {
  if (!value) return '-'
  return new Date(value).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function investigationAssignees(ticket: any) {
  const names = (ticket?.assignees || [])
    .map((assignee: any) => assignee?.name)
    .filter(Boolean)

  return names.length ? names.join(', ') : 'To be assigned'
}

function formatEstimatedDelivery(value: string | null) {
  if (!value) return 'Not scheduled'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Not scheduled'
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatMoney(value: number | string | null | undefined) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0))
}

function formatDateTime(value: string) {
  if (!value) return '-'
  return new Date(value).toLocaleString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

function formatStatus(value: string) {
  if (!value) return '-'
  const normalized = String(value).toLowerCase()
  // Customer-facing: keep fulfillment status separate; "pending_cancellation" is driven by cancellation request.
  if (normalized === 'pending_cancellation') return 'Pending'
  return String(value).replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase())
}

function formatTimelineDescription(value: unknown) {
  const description = String(value || '-').trim()

  return description
    .replace(
      /\b(from|to)\s+([a-z][a-z0-9_]*)(?=[.,\s]|$)/gi,
      (_match, direction: string, status: string) =>
        `${direction.toLowerCase()} ${formatStatus(status)}`,
    )
    .replace(
      /\b[a-z0-9]+(?:_[a-z0-9]+)+\b/gi,
      (status) => formatStatus(status),
    )
}

function normalizeImageUrl(raw: string) {
  if (!raw) return ''
  if (raw.startsWith('http://') || raw.startsWith('https://') || raw.startsWith('data:')) return raw
  if (raw.startsWith('/storage/')) return raw
  if (raw.startsWith('storage/')) return `/${raw}`
  return `/storage/${raw.replace(/^\//, '')}`
}

function onImageError(event: Event) {
  const target = event.target as HTMLImageElement | null
  if (target) target.src = '/F.svg'
}

function previewMedia(url: string, title: string) {
  mediaPreview.url = url
  mediaPreview.title = title
  mediaPreview.visible = true
}

function openExternal(url: string) {
  if (!url) return
  window.open(url, '_blank')
}

function goBack() {
  router.push({ name: 'ecommerce.orders' })
}

function goCancelPage() {
  if (!order.value?.id) return
  router.push({ name: 'ecommerce.order-cancel', params: { id: order.value.id } })
}

function goReturnPage(itemId: number) {
  if (!order.value?.id) return
  router.push({ name: 'ecommerce.order-return', params: { id: order.value.id, itemId } })
}

function goReviewPage(itemId: number) {
  if (!order.value?.id) return
  router.push({ name: 'ecommerce.order-review', params: { id: order.value.id, itemId } })
}

function canSetRefundMethod(item: any) {
  const request = item?.return_request
  return request?.return_type === 'refund'
    && ['approved', 'received', 'refund_pending'].includes(String(request.status || ''))
    && (!request.refund || ['pending_inspection', 'pending'].includes(String(request.refund.status || '')))
}

function refundMethodLabel(value: string) {
  return ({ gcash: 'GCash', card: 'Card' } as Record<string, string>)[value] || formatStatus(value)
}

function openRefundMethod(returnRequest: any) {
  activeReturnId.value = Number(returnRequest.id)
  refundForm.refund_method = returnRequest.refund?.refund_method || ''
  refundForm.refund_account_name = returnRequest.refund?.refund_account_name || ''
  refundForm.refund_account_number = returnRequest.refund?.refund_account_number || ''
  refundDialogVisible.value = true
}

async function saveRefundMethod() {
  if (!activeReturnId.value || !refundFormValid.value || savingRefundMethod.value) return
  savingRefundMethod.value = true
  try {
    await ecommerceService.updateRefundPaymentMethod(activeReturnId.value, {
      refund_method: refundForm.refund_method as 'gcash' | 'card',
      refund_account_name: refundForm.refund_account_name.trim(),
      refund_account_number: refundForm.refund_account_number.trim(),
    })
    refundDialogVisible.value = false
    await showAlert({ severity: 'success', summary: 'Saved', detail: 'Your refund payment method was saved for Finance.' })
    await loadOrderDetail()
  } catch (error: any) {
    await showAlert({ severity: 'error', summary: 'Unable to Save', detail: error?.response?.data?.message || 'Unable to save refund payment method.' })
  } finally {
    savingRefundMethod.value = false
  }
}

function goChatStore() {
  if (!order.value?.store_id) return
  router.push({ name: 'ecommerce.chats', query: { store_id: String(order.value.store_id) } })
}

onMounted(loadOrderDetail)
onBeforeUnmount(() => {
  if (trackingRefreshTimer !== null) window.clearInterval(trackingRefreshTimer)
  if (trackingMap) trackingMap.remove()
  trackingMap = null
  truckMarker = null
  destinationMarker = null
})
</script>


