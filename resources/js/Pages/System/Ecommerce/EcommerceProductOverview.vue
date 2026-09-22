<template>
  <div class="mx-auto max-w-6xl px-2 py-4 sm:px-4 md:px-6 md:py-8">
    <div v-if="loading" class="space-y-6">
      <div class="grid grid-cols-1 gap-8 rounded-2xl border border-slate-200 bg-white p-5 md:grid-cols-2">
        <Skeleton height="420px" borderRadius="1rem" />
        <div class="space-y-4">
          <Skeleton width="120px" height="26px" />
          <Skeleton width="85%" height="40px" />
          <Skeleton width="95%" height="18px" />
          <Skeleton width="95%" height="18px" />
          <Skeleton width="45%" height="40px" />
          <Skeleton width="40%" height="20px" />
          <div class="flex gap-3 pt-2">
            <Skeleton width="180px" height="42px" />
            <Skeleton width="160px" height="42px" />
          </div>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex gap-2 pb-4">
          <Skeleton width="180px" height="34px" />
          <Skeleton width="120px" height="34px" />
          <Skeleton width="150px" height="34px" />
        </div>
        <Skeleton width="100%" height="18px" class="mb-2" />
        <Skeleton width="92%" height="18px" class="mb-2" />
        <Skeleton width="70%" height="18px" />
      </div>
    </div>
  
    <Card v-else-if="product" class="plain-card overflow-hidden">
      <template #content>
        <div class="grid grid-cols-1 gap-5 md:gap-8 md:grid-cols-2">
          <div class="overflow-visible rounded-2xl border border-slate-200 bg-slate-50">
            <div class="flex items-center justify-between p-3">
              <div class="flex flex-wrap gap-2">
                <Tag v-if="product?.is_new_arrival" value="New" severity="info" />
                <Tag v-if="product?.is_bestseller" value="Bestseller" severity="success" />
                <Tag v-if="product?.is_featured" value="Featured" severity="warning" />
              </div>
              <Button v-if="selectedModel3D" :label="show3DViewer ? ' Photo' : '3D'" @click="toggle3DViewer" />
            </div>
  
            <div v-if="show3DViewer && selectedModel3D" class="relative w-full aspect-square">
              <Model3DPreview :model-url="selectedModel3D.url" :model-format="selectedModel3D.model_format"
                :camera-x="selectedModel3D?.camera_settings?.angle_x ?? 0"
                :camera-y="selectedModel3D?.camera_settings?.angle_y ?? 15"
                :zoom="selectedModel3D?.camera_settings?.zoom ?? 1.5" height="100%" />
            </div>
  
            <div v-else-if="primaryImage" class="group relative w-full aspect-square cursor-zoom-in"
              @mouseenter="startHoverZoom" @mousemove="updateHoverZoom"
              @mouseleave="stopHoverZoom">
              <img :src="primaryImage" :alt="product.product_name" class="absolute inset-0 h-full w-full object-cover"
                @error="handleImageError" />
              <div v-if="hoverZoomVisible"
                class="pointer-events-none absolute z-20 hidden aspect-square w-[36%] border border-slate-500/50 bg-white/25 shadow-inner backdrop-brightness-105 md:block"
                :style="hoverLensStyle" aria-hidden="true" />
              <div v-if="hoverZoomVisible"
                class="pointer-events-none absolute left-full top-0 z-30 ml-4 hidden aspect-square w-[min(42vw,28rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl md:block"
                aria-hidden="true">
                <div
                  class="absolute left-0 right-0 top-0 z-10 bg-white/90 px-3 py-2 text-xs font-medium text-slate-500 backdrop-blur">
                  Move over the image to inspect details
                </div>
                <div class="h-full w-full bg-no-repeat" :style="hoverZoomStyle" />
              </div>
            </div>
            <div v-else class="flex aspect-square items-center justify-center text-slate-400">
              <i class="pi pi-image text-5xl opacity-30" />
            </div>
  
            <div v-if="!show3DViewer && galleryImages.length > 1" class="border-t border-slate-200 bg-white p-3">
              <div class="flex gap-2 overflow-x-auto pb-1">
                <button v-for="(img, idx) in galleryImages" :key="`${img}-${idx}`" type="button"
                  class="shrink-0 h-16 w-16 overflow-hidden rounded-xl border transition"
                  :class="img === primaryImage ? 'border-blue-500' : 'border-slate-200 hover:border-slate-300'"
                  @click="selectedImage = img">
                  <img :src="img" alt="Product image" class="h-full w-full object-cover" @error="handleImageError" />
                </button>
              </div>
            </div>
          </div>
  
          <div class="space-y-4">
            <Tag :value="stockLabel" :severity="stockSeverity" />
            <div class="space-y-2">
              <h1 class="text-2xl md:text-3xl font-semibold text-slate-900">{{ product.product_name }}</h1>
              <div class="flex items-center flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
                <span v-if="product.brand">{{ product.brand }}</span>
                <span v-if="product.brand && product.collection_name" class="text-slate-300">•</span>
                <span v-if="product.collection_name">{{ product.collection_name }}</span>
                <span v-if="(product.brand || product.collection_name) && product.category"
                  class="text-slate-300">•</span>
                <span v-if="product.category">{{ product.category }}</span>
              </div>
              <div v-if="product.tags?.length" class="flex flex-wrap gap-2 pt-1">
                <Tag v-for="tag in product.tags" :key="`ecommerce-tag-${tag.id}`" :value="tag.tag_name" severity="info" />
              </div>

            </div>
  
            <div class="space-y-1">
              <div v-if="hasProductDiscount" class="flex flex-wrap items-center gap-2">
                <span class="text-lg font-medium text-slate-400 line-through">{{ formatCurrency(displayBasePrice) }}</span>
                <Tag :value="`${productDiscountPercentage}% OFF`" severity="danger" class="text-xs" />
              </div>
              <p class="text-3xl font-bold text-orange-600">{{ formatCurrency(displaySellingPrice) }}</p>
              <p class="text-xs font-medium text-slate-500">VAT included</p>
              <p class="text-sm text-slate-500">
                {{ product.quantity_available || 0 }} stocks available
                <span v-if="product.assembly_required" class="mx-2 text-slate-300">•</span>
                <span v-if="product.assembly_required">Assembly required</span>
              </p>
            </div>
  
            <div class="space-y-2">
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Specs</p>
              <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                  <p class="text-[11px] text-slate-500">L</p>
                  <p class="font-semibold text-slate-900">{{ displayDimensions?.length_cm ?? '—' }}<span
                      v-if="displayDimensions?.length_cm"> cm</span></p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                  <p class="text-[11px] text-slate-500">W</p>
                  <p class="font-semibold text-slate-900">{{ displayDimensions?.width_cm ?? '—' }}<span
                      v-if="displayDimensions?.width_cm"> cm</span></p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                  <p class="text-[11px] text-slate-500">H</p>
                  <p class="font-semibold text-slate-900">{{ displayDimensions?.height_cm ?? '—' }}<span
                      v-if="displayDimensions?.height_cm"> cm</span></p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                  <p class="text-[11px] text-slate-500">Weight</p>
                  <p class="font-semibold text-slate-900">{{ displayDimensions?.weight_kg ?? '—' }}<span
                      v-if="displayDimensions?.weight_kg"> kg</span></p>
                </div>
              </div>
            </div>
  
            <div v-if="product?.variations?.length" class="space-y-2">
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Variations</p>
              <div class="flex flex-wrap gap-2">
                <Button v-for="variation in product.variations" :key="variation.id" size="small"
                  :severity="selectedVariationId === variation.id ? 'warn' : 'secondary'"
                  :outlined="selectedVariationId !== variation.id" :disabled="!isVariationSelectable(variation)"
                  @click="selectVariation(variation.id)">
                  {{ variationLabel(variation) }}
                </Button>
              </div>
              <p v-if="selectedVariation" class="text-sm text-slate-600">
                Variation Price: <span class="font-semibold">{{ formatCurrency(selectedVariation.final_price ||
                  product.price) }}</span>
                <span v-if="selectedModel3D" class="ml-2 text-xs text-emerald-600">(Has 3D model)</span>
              </p>
            </div>
  
            <div class="flex w-full flex-col gap-2 pt-2 sm:flex-row sm:items-stretch sm:gap-3">
              <div
                class="flex min-w-0 flex-1 items-center overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <Button type="button" icon="pi pi-minus" severity="secondary" text size="small"
                  class="!h-10 !w-10 !rounded-none !border-0 !p-0 !text-slate-500 hover:!bg-orange-50 hover:!text-orange-600"
                  aria-label="Decrease quantity" :disabled="quantity <= 1" @click="changeProductQuantity(-1)" />
                <InputNumber v-model="quantity" :min="1" :max="maxPurchasableQty" :useGrouping="false"
                  inputId="product-quantity"
                  inputClass="!w-full !border-0 !text-center !text-sm !font-semibold !text-slate-900 !shadow-none"
                  class="min-w-0 flex-1" aria-label="Quantity" @update:modelValue="normalizeProductQuantity" />
                <Button type="button" icon="pi pi-plus" severity="secondary" text size="small"
                  class="!h-10 !w-10 !rounded-none !border-0 !p-0 !text-slate-500 hover:!bg-orange-50 hover:!text-orange-600"
                  aria-label="Increase quantity" :disabled="quantity >= maxPurchasableQty" @click="changeProductQuantity(1)" />
              </div>
              <Button label="Add to Cart" severity="warn" outlined fluid class="flex-1" :disabled="!canPurchase"
                @click="addToCart" />
              <Button label="Buy Now" fluid class="flex-1" :disabled="!canPurchase" @click="buyNow" />
            </div>
          </div>
        </div>
      </template>
    </Card>
  
    <Card v-if="product" class="mt-6 plain-card">
      <template #content>
        <button type="button"
          class="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 p-4 text-left transition hover:border-slate-300"
          @click="goStorePage">
          <div class="flex items-center gap-3">
            <img v-if="storeInfo?.logo" :src="storeInfo.logo" :alt="storeInfo.name"
              class="h-12 w-12 rounded-xl border border-slate-200 object-cover" />
            <div v-else
              class="flex h-12 w-12 items-center justify-center rounded-xl border border-slate-200 bg-slate-100 text-sm font-semibold text-slate-700">
              {{ (storeInfo?.name || product.store_name || 'S').slice(0, 1).toUpperCase() }}
  
            </div>
            <div>
              <p class="text-base font-semibold text-slate-900">{{ storeInfo?.name || product.store_name || 'Store' }}</p>
  
            </div>
            <div class="text-left">
              <p class="text-sm font-semibold text-amber-500">★ {{ storeRating.toFixed(2) }}</p>
              <p class="text-xs text-slate-500">{{ storeRatingCount }} reviews</p>
            </div>
          </div>
  
          <Button label="Chat" icon="pi pi-comments" severity="help" text size="small" @click="goChatStore" />
        </button>
        <div class="mt-2 justify-between flex gap-3">
          <!-- <Button label="Report" icon="pi pi-exclamation-triangle" severity="danger" text size="small"
                  @click="openReportDialog" /> -->
  
        </div>
      </template>
    </Card>
  
    <div v-if="product" class="mt-6 rounded-2xl border border-slate-200 bg-white">
      <div class="flex flex-wrap gap-2 border-b border-slate-200 p-3">
        <Button label="Product Description" size="small" :severity="activeTab === 'description' ? 'warn' : 'secondary'"
          :outlined="activeTab !== 'description'" @click="activeTab = 'description'" />
        <Button label="Reviews" size="small" :severity="activeTab === 'reviews' ? 'warn' : 'secondary'"
          :outlined="activeTab !== 'reviews'" @click="activeTab = 'reviews'" />
        <Button label="Recommended" size="small" :severity="activeTab === 'recommended' ? 'warn' : 'secondary'"
          :outlined="activeTab !== 'recommended'" @click="activeTab = 'recommended'" />
      </div>
  
      <div v-if="activeTab === 'description'" class="p-5">
        <h3 class="text-lg font-semibold text-slate-900">Product Description</h3>
        <div v-if="product.description"
          class="mt-3 text-sm leading-7 text-slate-600 [&_p]:mb-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_a]:text-blue-600 [&_a]:underline"
          v-html="displayDescriptionHtml"></div>
        <p v-else class="mt-3 text-sm leading-7 text-slate-600">No description available for this product yet.</p>
      </div>
  
      <div v-else-if="activeTab === 'reviews'" class="p-5">
        <div class="grid grid-cols-1 gap-6 border-b border-slate-200 pb-4 lg:grid-cols-12">
          <div class="lg:col-span-3">
            <p class="text-sm text-slate-600">Average Rating</p>
            <div
              class="mt-3 inline-flex h-40 w-40 items-center justify-center rounded-full border-[12px] border-amber-400 text-center">
              <div>
                <i class="pi pi-star-fill text-amber-500"></i>
                <p class="mt-1 text-4xl font-semibold text-slate-900">{{ averageRating }}</p>
                <p class="text-sm text-slate-500">/ 5</p>
              </div>
            </div>
          </div>
          <div class="lg:col-span-9">
            <p class="text-sm text-slate-600">Our customer satisfaction</p>
            <div class="mt-4 space-y-2">
              <div v-for="row in ratingBreakdown" :key="row.star" class="flex items-center gap-3">
                <span class="w-10 text-sm text-slate-600">{{ row.star }}★</span>
                <div class="h-2 flex-1 rounded-full bg-slate-200">
                  <div class="h-2 rounded-full bg-amber-400" :style="{ width: `${row.percent}%` }"></div>
                </div>
                <span class="w-12 text-right text-xs text-slate-500">{{ row.count }}</span>
              </div>
            </div>
          </div>
        </div>
  
        <div class="mt-4 space-y-4">
          <div v-for="review in reviews" :key="review.id" class="rounded-xl border border-slate-200 p-4">
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm font-semibold text-slate-900">{{ review.customer_name }}</p>
                <p class="text-xs text-slate-500">{{ formatDate(review.created_at) }}</p>
              </div>
              <div class="text-sm text-amber-500">{{ '★'.repeat(review.rating) }}</div>
            </div>
            <p class="mt-3 text-sm text-slate-600">{{ review.review_text || 'No review text provided.' }}</p>
            <a v-if="review.attachment_url && !brokenReviewAttachments.includes(Number(review.id))"
              :href="review.attachment_url" target="_blank" rel="noopener"
              class="mt-3 inline-block overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
              <img :src="review.attachment_url" alt="Customer review attachment"
                class="block h-24 w-24 object-cover transition hover:scale-105"
                @error="hideBrokenReviewAttachment(review.id)" />
            </a>
            <div v-if="review.store_reply" class="mt-4 rounded-xl border border-orange-100 bg-orange-50 px-4 py-3">
              <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-orange-700">Store Reply</p>
                <p v-if="review.replied_at" class="text-xs text-slate-500">{{ formatDate(review.replied_at) }}</p>
              </div>
              <p class="mt-1 text-sm text-slate-700">{{ review.store_reply }}</p>
            </div>
          </div>
        </div>
      </div>
  
      <div v-else class="p-5">
        <div v-if="recommendedProducts.length" class="grid grid-cols-2 gap-4 md:grid-cols-4">
          <button v-for="item in recommendedProducts" :key="item.id" type="button"
            class="rounded-xl border border-slate-200 p-2 text-left transition hover:border-slate-300"
            @click="goToRecommended(item.id)">
            <div class="h-28 overflow-hidden rounded-lg bg-slate-100">
              <img v-if="item.image" :src="normalizeImageUrl(item.image)" :alt="item.product_name"
                class="h-full w-full object-cover" @error="onImageError" />
              <div v-else class="flex h-full items-center justify-center text-slate-400">
                <i class="pi pi-image" />
              </div>
            </div>
            <p class="mt-2 line-clamp-2 text-xs font-medium text-slate-800">{{ item.product_name }}</p>
            <p class="mt-1 text-sm font-semibold text-slate-900">{{ formatCurrency(item.price) }}</p>
          </button>
        </div>
        <p v-else class="text-sm text-slate-500">No recommended products yet.</p>
      </div>
    </div>
  
    <div v-else class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-slate-500">
      Product not found.
    </div>
  
    <Dialog v-model:visible="reportDialog" modal header="Report Store" class="w-full max-w-xl">
      <div class="space-y-3">
        <div>
          <label class="text-sm text-slate-600">Reason</label>
          <InputText v-model="reportForm.reason" fluid placeholder="e.g. Fake listing, Scam, Offensive content" />
        </div>
        <div>
          <label class="text-sm text-slate-600">Details (optional)</label>
          <Textarea v-model="reportForm.details" rows="4" fluid
            placeholder="Share what happened and any order/product context..." />
        </div>
        <div>
          <label class="text-sm text-slate-600">Evidence images (optional, up to 5)</label>
          <input type="file" accept="image/*" multiple class="mt-1 block w-full text-sm" @change="onEvidenceChange" />
          <p v-if="reportForm.evidence_images.length" class="text-xs text-slate-500">
            {{ reportForm.evidence_images.length }} file(s) selected
          </p>
        </div>
      </div>
      <template #footer>
        <Button text severity="secondary" label="Cancel" @click="reportDialog = false" />
        <Button :loading="reporting" severity="danger" label="Submit Report" @click="submitReport" />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import EcommerceMobileWrapper from '@/Layouts/EcommerceMobileWrapper.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ecommerceService from '@/services/ecommerce.service'
import { useAuthStore } from '@/stores/auth'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import InputNumber from 'primevue/inputnumber'
import Skeleton from 'primevue/skeleton'
import Carousel from 'primevue/carousel'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Model3DPreview from '@/Components/merchandising/Model3DPreview.vue'
import { showAlert, confirmAlert } from '@/utils/swal'
defineOptions({
  layout: EcommerceMobileWrapper,
})


const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const loading = ref(false)
const quantity = ref(1)
const product = ref<any>(null)
const selectedVariationId = ref<number | null>(null)
const activeTab = ref<'description' | 'reviews' | 'recommended'>(
  String(route.query.tab) === 'reviews' ? 'reviews' : (String(route.query.tab) === 'recommended' ? 'recommended' : 'description'),
)
const recommendedProducts = ref<any[]>([])
const storeInfo = ref<{ id: number; name: string; logo: string | null; rating_avg: number; rating_count: number } | null>(null)
const show3DViewer = ref(false)
const selectedImage = ref<string | null>(null)
const brokenImages = ref<string[]>([])
const brokenReviewAttachments = ref<number[]>([])
const hoverZoomVisible = ref(false)
const hoverX = ref(50)
const hoverY = ref(50)
const reportDialog = ref(false)
const reporting = ref(false)
const reportForm = ref({
  reason: '',
  details: '',
  evidence_images: [] as File[],
})

const displayDescriptionHtml = computed(() => {
  const html = String(product.value?.description || '').trim()
  if (!html) return ''
  // PrimeVue Editor outputs HTML; render it and normalize NBSP for nicer wrapping.
  return html.replace(/&nbsp;/g, ' ')
})
const selectedVariation = computed(() =>
  (product.value?.variations || []).find((v: any) => Number(v.id) === Number(selectedVariationId.value)) || null
)
const displayBasePrice = computed(() => Number(selectedVariation.value?.base_price ?? product.value?.base_price ?? product.value?.price ?? 0))
const displaySellingPrice = computed(() => Number(selectedVariation.value?.final_price ?? product.value?.discounted_price ?? product.value?.price ?? displayBasePrice.value))
const hasProductDiscount = computed(() => displayBasePrice.value > 0 && displaySellingPrice.value < displayBasePrice.value)
const productDiscountPercentage = computed(() => hasProductDiscount.value ? Math.round((1 - displaySellingPrice.value / displayBasePrice.value) * 100) : 0)
const hoverLensStyle = computed(() => ({ left: `${Math.max(18, Math.min(82, hoverX.value)) - 18}%`, top: `${Math.max(18, Math.min(82, hoverY.value)) - 18}%` }))
const hoverZoomStyle = computed(() => ({ backgroundImage: `url("${primaryImage.value || ''}")`, backgroundSize: '278% 278%', backgroundPosition: `${hoverX.value}% ${hoverY.value}%` }))
const productHasVariations = computed(() => Array.isArray(product.value?.variations) && product.value.variations.length > 0)

function isVariationSelectable(variation: any): boolean {
  if (!variation) return false
  if (typeof variation.is_selectable === 'boolean') return variation.is_selectable
  const qty = Number(variation.quantity_available || 0)
  const status = String(variation.stock_status || 'out_of_stock')
  return qty > 0 && status !== 'out_of_stock'
}

const purchasableQty = computed(() => {
  if (!product.value) return 0
  if (productHasVariations.value) {
    if (!selectedVariation.value) return 0
    if (!isVariationSelectable(selectedVariation.value)) return 0
    return Math.max(0, Number(selectedVariation.value.quantity_available || 0))
  }
  return Math.max(0, Number(product.value.quantity_available || 0))
})

const maxPurchasableQty = computed(() => Math.max(1, purchasableQty.value || 1))
const canPurchase = computed(() => purchasableQty.value > 0)

function normalizeProductQuantity() {
  quantity.value = Math.max(1, Math.min(maxPurchasableQty.value, Number(quantity.value || 1)))
}

function changeProductQuantity(amount: number) {
  quantity.value = Number(quantity.value || 1) + amount
  normalizeProductQuantity()
}
function startHoverZoom(event: MouseEvent) { hoverZoomVisible.value = true; updateHoverZoom(event) }
function updateHoverZoom(event: MouseEvent) { const element = event.currentTarget as HTMLElement; const rect = element.getBoundingClientRect(); hoverX.value = Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100)); hoverY.value = Math.max(0, Math.min(100, ((event.clientY - rect.top) / rect.height) * 100)) }
function stopHoverZoom() { hoverZoomVisible.value = false }
const selectedModel3D = computed(() => {
  const v: any = selectedVariation.value
  // Only fall back to parent when the selected variation has no own media/specs.
  if (v && variationHasOwnMediaOrSpecs.value) {
    return v?.model_3d || null
  }
  return product.value?.model_3d || null
})

// If a variation does not provide its own media/specs, fall back to the parent product.
const variationHasOwnMediaOrSpecs = computed(() => {
  const v: any = selectedVariation.value
  if (!v) return false

  const has3d = !!v.model_3d
  const varImages = Array.isArray(v.images) ? v.images : (v.image ? [v.image] : [])
  const hasImages = varImages.some((img: any) => {
    const raw = typeof img === 'string' ? img : (img?.url || img?.image_url || img?.src || '')
    return Boolean(String(raw || '').trim())
  })
  const dims = v.dimensions || v
  const hasSpecs = ['length_cm', 'width_cm', 'height_cm', 'weight_kg'].some((k) => {
    const val = dims?.[k]
    return val !== null && val !== undefined && val !== ''
  })

  return has3d || hasImages || hasSpecs
})

const displayDimensions = computed(() => {
  const v: any = selectedVariation.value
  const p: any = product.value

  const parentDims = p?.dimensions || p || {}
  const variationDims = v?.dimensions || v || {}

  const pick = (key: 'length_cm' | 'width_cm' | 'height_cm' | 'weight_kg') => {
    const vv = variationDims?.[key]
    if (vv !== null && vv !== undefined && vv !== '') return vv
    const pv = parentDims?.[key]
    if (pv !== null && pv !== undefined && pv !== '') return pv
    return null
  }

  return {
    length_cm: pick('length_cm'),
    width_cm: pick('width_cm'),
    height_cm: pick('height_cm'),
    weight_kg: pick('weight_kg'),
  }
})

const displayImagesRaw = computed<any[]>(() => {
  const v: any = selectedVariation.value
  if (variationHasOwnMediaOrSpecs.value) {
    const imgs = Array.isArray(v?.images) ? v.images : (v?.image ? [v.image] : [])
    if (imgs.length) return imgs
  }
  const images = product.value?.images
  return Array.isArray(images) ? images : (product.value?.image ? [product.value.image] : [])
})
const galleryImages = computed<string[]>(() => {
  return displayImagesRaw.value
    .map((img: any) => normalizeImageUrl(typeof img === 'string' ? img : (img?.url || img?.image_url || img?.src || '')))
    .filter((url): url is string => Boolean(url) && !brokenImages.value.includes(url))
})
const primaryImage = computed(() => {
  return selectedImage.value || galleryImages.value[0] || product.value?.image || null
})
const storeRating = computed(() => Number(storeInfo.value?.rating_avg ?? 0))
const storeRatingCount = computed(() => Number(storeInfo.value?.rating_count ?? 0))
const reviews = computed(() => product.value?.reviews?.data || [])
const averageRating = computed(() => {
  return Number(product.value?.reviews_summary?.average_rating || 0).toFixed(1)
})
const ratingBreakdown = computed(() => {
  const counts = (product.value?.reviews_summary?.breakdown || []).map((row: any) => ({
    star: Number(row.star),
    count: Number(row.count || 0),
  }))
  const max = Math.max(...counts.map(c => c.count), 1)
  return counts.map(c => ({
    ...c,
    percent: Math.round((c.count / max) * 100),
  }))
})

async function toggle3DViewer() {
  show3DViewer.value = !show3DViewer.value
}

const stockLabel = computed(() => {
  const status = String(product.value?.stock_status || '').toLowerCase()
  if (status === 'in_stock') return 'In Stock'
  if (status === 'low_stock') return 'Low Stock'
  return 'Out of Stock'
})

const stockSeverity = computed(() => {
  const status = String(product.value?.stock_status || '').toLowerCase()
  if (status === 'in_stock') return 'success'
  if (status === 'low_stock') return 'warning'
  return 'danger'
})

const formatCurrency = (val: any) =>
  new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(val || 0))

function displayDimension(value: any, unit: 'cm' | 'kg') {
  if (value === null || value === undefined || value === '') return '-'
  const num = Number(value)
  if (Number.isNaN(num)) return '-'
  return `${num.toFixed(2)} ${unit}`
}

function formatDate(value: string) {
  if (!value) return '-'
  return new Date(value).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function normalizeImageUrl(raw: string) {
  if (!raw) return ''
  if (raw.startsWith('http://') || raw.startsWith('https://') || raw.startsWith('data:')) return raw
  if (raw.startsWith('/storage/')) return raw
  if (raw.startsWith('storage/')) return `/${raw}`
  return `/storage/${raw.replace(/^\//, '')}`
}

function handleImageError(event: Event) {
  const target = event.target as HTMLImageElement | null
  if (!target?.src) return
  const url = target.currentSrc || target.src
  if (!brokenImages.value.includes(url)) {
    brokenImages.value = [...brokenImages.value, url]
  }
  if (selectedImage.value === url) {
    selectedImage.value = null
  }
}

function onImageError(event: Event) {
  const target = event.target as HTMLImageElement | null
  if (target) target.src = '/F.svg'
}

function hideBrokenReviewAttachment(reviewId: number | string) {
  const id = Number(reviewId)
  if (!brokenReviewAttachments.value.includes(id)) {
    brokenReviewAttachments.value = [...brokenReviewAttachments.value, id]
  }
}

function variationLabel(variation: any) {
  const parts = [variation.color, variation.size, variation.material].filter(Boolean)
  return parts.length ? parts.join(' / ') : variation.variation_name
}

function selectVariation(variationId: number) {
  const v = (product.value?.variations || []).find((vv: any) => Number(vv.id) === Number(variationId)) || null
  if (!isVariationSelectable(v)) {
    showAlert({ severity: 'warn', summary: 'Out of stock', detail: 'This variation is out of stock.' })
    return
  }
  selectedVariationId.value = Number(variationId)
}

watch(selectedVariationId, () => {
  selectedImage.value = null
  show3DViewer.value = false
  if (Number(quantity.value || 1) > maxPurchasableQty.value) {
    quantity.value = maxPurchasableQty.value
  }
})

async function loadProduct() {
  loading.value = true
  try {
    const response = await ecommerceService.getProduct(String(route.params.id))
    product.value = response.data?.data || response.data
    selectedImage.value = null
    selectedVariationId.value = null
  } catch {
    product.value = null
    showAlert({ severity: 'error', summary: 'Error', detail: 'Unable to load product.' })
  } finally {
    loading.value = false
  }
}

async function loadRecommendedProducts() {
  try {
    const response = await ecommerceService.getActiveStockProducts({ per_page: 8, search: '' })
    const all = response.data?.data?.data || []
    recommendedProducts.value = all.filter((p: any) => Number(p.id) !== Number(product.value?.id)).slice(0, 4)
  } catch {
    recommendedProducts.value = []
  }
}

async function loadStoreInfo() {
  if (!product.value?.store_id) {
    storeInfo.value = null
    return
  }

  try {
    const response = await ecommerceService.getStore(product.value.store_id)
    const store = response.data?.data || {}
    storeInfo.value = {
      id: Number(store.id || product.value.store_id),
      name: String(store.store_name || product.value.store_name || 'Store'),
      logo: store.logo || product.value.store_logo || null,
      rating_avg: Number(store.rating_avg || 0),
      rating_count: Number(store.rating_count || 0),
    }
  } catch {
    storeInfo.value = {
      id: Number(product.value.store_id),
      name: String(product.value.store_name || 'Store'),
      logo: product.value.store_logo || null,
      rating_avg: 0,
      rating_count: 0,
    }
  }
}

async function addToCart() {
  if (!product.value?.id) return
  if (productHasVariations.value && !selectedVariationId.value) {
    showAlert({ severity: 'warn', summary: 'Variation required', detail: 'Please select a variation first.' })
    return
  }
  if (!canPurchase.value) {
    showAlert({ severity: 'warn', summary: 'Out of stock', detail: 'This item is out of stock.' })
    return
  }
  if (!requireCustomerLogin()) return
  try {
    await ecommerceService.addToCart({
      product_id: Number(product.value.id),
      variation_id: selectedVariationId.value ? Number(selectedVariationId.value) : null,
      quantity: Number(quantity.value || 1),
      store_id: product.value?.store_id ? Number(product.value.store_id) : null,
    })
    window.dispatchEvent(new Event('ecommerce-cart-updated'))
    showAlert({ severity: 'success', summary: 'Added to cart', detail: `${product.value.product_name}` })
  } catch (error: any) {
    if (Number(error?.response?.status) === 401) return
    showAlert({ severity: 'error', summary: 'Error', detail: 'Could not add to cart.' })
  }
}

async function buyNow() {
  if (!product.value?.id) return
  if (productHasVariations.value && !selectedVariationId.value) {
    showAlert({ severity: 'warn', summary: 'Variation required', detail: 'Please select a variation first.' })
    return
  }
  if (!canPurchase.value) {
    showAlert({ severity: 'warn', summary: 'Out of stock', detail: 'This item is out of stock.' })
    return
  }
  if (!requireCustomerLogin()) return
  try {
    const confirmed = await confirmAlert({
      title: 'Proceed to checkout?',
      text: 'Are you sure you want to buy this item now? This will add it to your cart and take you to checkout.',
      cancelText: 'No, go back to shop',
      confirmText: 'Yes, buy now',
      reverseButtons: true,
    })

    if (!confirmed) {
      goStorePage()
      return
    }

    await ecommerceService.addToCart({
      product_id: Number(product.value.id),
      variation_id: selectedVariationId.value ? Number(selectedVariationId.value) : null,
      quantity: Number(quantity.value || 1),
      store_id: product.value?.store_id ? Number(product.value.store_id) : null,
    })
    window.dispatchEvent(new Event('ecommerce-cart-updated'))
    router.push({ name: 'ecommerce.checkout' })
  } catch (error: any) {
    if (Number(error?.response?.status) === 401) return
    showAlert({ severity: 'error', summary: 'Error', detail: 'Could not process Buy Now.' })
  }
}

function requireCustomerLogin() {
  const hasToken = Boolean(localStorage.getItem('auth_token') || localStorage.getItem('access_token'))
  if (hasToken && authStore.isCustomer) return true

  showAlert({
    severity: 'info',
    summary: 'Login required',
    detail: 'Please sign in with your customer account to add items and continue to checkout.',
  })
  router.push({ name: 'customer.login', query: { redirect: route.fullPath || '/shop' } })
  return false
}

function goBack() {
  router.push({ name: 'ecommerce.products' })
}

function goToRecommended(id: number) {
  router.push({ name: 'ecommerce.product', params: { id } })
}

function goStorePage() {
  const storeId = Number(storeInfo.value?.id || product.value?.store_id || 0)
  if (!storeId) return
  router.push({ name: 'ecommerce.store-profile', params: { storeId } })
}

function goChatStore() {
  const storeId = Number(storeInfo.value?.id || product.value?.store_id || 0)
  if (!storeId) return
  router.push({
    name: 'ecommerce.chats',
    query: {
      store_id: String(storeId),
      product_id: String(product.value?.id || ''),
      product_name: String(product.value?.product_name || ''),
    },
  })
}

function openReportDialog() {
  reportForm.value = { reason: '', details: '', evidence_images: [] }
  reportDialog.value = true
}

function onEvidenceChange(event: Event) {
  const input = event.target as HTMLInputElement | null
  const files = input?.files ? Array.from(input.files) : []
  reportForm.value.evidence_images = files.slice(0, 5)
}

async function submitReport() {
  const storeId = Number(storeInfo.value?.id || product.value?.store_id || 0)
  if (!storeId) {
    showAlert({ severity: 'warn', summary: 'Report', detail: 'Store information is missing.' })
    return
  }

  if (!reportForm.value.reason.trim()) {
    showAlert({ severity: 'warn', summary: 'Report', detail: 'Please provide a reason.' })
    return
  }

  reporting.value = true
  try {
    await ecommerceService.reportViolation({
      store_id: storeId,
      reason: reportForm.value.reason.trim(),
      details: reportForm.value.details.trim() || undefined,
      evidence_images: reportForm.value.evidence_images,
    })
    reportDialog.value = false
    showAlert({ severity: 'success', summary: 'Report submitted', detail: 'Thanks for letting us know.' })
  } catch (error: any) {
    showAlert({
      severity: 'error',
      summary: 'Report failed',
      detail: error?.response?.data?.message || 'Unable to submit report.'
    })
  } finally {
    reporting.value = false
  }
}

onMounted(async () => {
  await loadProduct()
  await loadStoreInfo()
  await loadRecommendedProducts()
})
watch(() => route.params.id, async () => {
  show3DViewer.value = false
  await loadProduct()
  await loadStoreInfo()
  await loadRecommendedProducts()
})
</script>

<style scoped>
:deep(.plain-card.p-card) {
  border: 1px solid #e2e8f0;
  box-shadow: none;
}
</style>
