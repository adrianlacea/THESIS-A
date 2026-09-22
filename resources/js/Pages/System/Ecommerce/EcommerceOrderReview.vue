<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900">Review Purchased Item</h1>
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
          </div>

          <div class="space-y-2">
            <label class="text-sm font-semibold text-slate-700">Rating</label>
            <Rating v-model="form.rating" :cancel="false" />
          </div>

          <div class="space-y-2">
            <label class="text-sm font-semibold text-slate-700">Review (optional)</label>
            <Textarea v-model="form.review_text" rows="4" fluid placeholder="Share your experience with this product." />
          </div>

          <div class="space-y-2">
            <label for="review-attachment" class="text-sm font-semibold text-slate-700">Photo (optional)</label>
            <input id="review-attachment" type="file" accept="image/jpeg,image/png,image/webp"
              class="block w-full rounded-lg border border-slate-200 p-2 text-sm text-slate-600"
              @change="onAttachmentChange" />
            <p class="text-xs text-slate-500">JPG, PNG, or WebP up to 5 MB.</p>
            <img v-if="attachmentPreview" :src="attachmentPreview" alt="Review photo preview"
              class="h-24 w-24 rounded-xl border border-slate-200 object-cover" />
          </div>

          <Button label="Submit Review" severity="info" :loading="submitting" @click="submitReview" />
        </div>
      </template>
    </Card>
  </div>
</template>

<script setup lang="ts">
import EcommerceMobileWrapper from '@/Layouts/EcommerceMobileWrapper.vue'
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ecommerceService from '@/services/ecommerce.service'
import Textarea from 'primevue/textarea'
import Rating from 'primevue/rating'
import { showAlert } from '@/utils/swal'
defineOptions({
  layout: EcommerceMobileWrapper,
})


const route = useRoute()
const router = useRouter()
const loading = ref(false)
const submitting = ref(false)
const order = ref<any>(null)
const attachment = ref<File | null>(null)
const attachmentPreview = ref('')
const form = reactive({
  rating: 5,
  review_text: '',
})

const selectedItem = computed(() => {
  const itemId = Number(route.params.itemId)
  return (order.value?.items || []).find((item: any) => Number(item.id) === itemId) || null
})

function clearAttachmentPreview() {
  if (attachmentPreview.value) URL.revokeObjectURL(attachmentPreview.value)
  attachmentPreview.value = ''
}

function onAttachmentChange(event: Event) {
  clearAttachmentPreview()
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] || null
  if (file && file.size > 5 * 1024 * 1024) {
    attachment.value = null
    input.value = ''
    showAlert({ severity: 'warn', summary: 'Photo too large', detail: 'Choose an image up to 5 MB.' })
    return
  }
  attachment.value = file
  if (file) attachmentPreview.value = URL.createObjectURL(file)
}

async function loadOrder() {
  loading.value = true
  try {
    const response = await ecommerceService.getOrder(route.params.id as string)
    order.value = response.data?.data || null
    if (!selectedItem.value || !selectedItem.value.can_review) {
      showAlert({ severity: 'warn', summary: 'Not Allowed', detail: 'Review is not available for this item.' })
      goBack()
    }
  } catch (error: any) {
    showAlert({ severity: 'error', summary: 'Error', detail: error?.response?.data?.message || 'Failed to load order item.' })
    goBack()
  } finally {
    loading.value = false
  }
}

async function submitReview() {
  if (!selectedItem.value) return
  if (!form.rating || form.rating < 1) {
    showAlert({ severity: 'warn', summary: 'Required', detail: 'Please provide at least 1 star.' })
    return
  }

  submitting.value = true
  try {
    const response = await ecommerceService.submitItemReview(selectedItem.value.id, {
      rating: Number(form.rating),
      review_text: form.review_text.trim() || undefined,
      attachment: attachment.value,
    })

    const productRating = response.data?.data?.product_rating
    if (productRating) {
      window.dispatchEvent(new CustomEvent('ecommerce-product-rating-updated', {
        detail: productRating,
      }))
    }

    const average = Number(productRating?.average_rating || 0).toFixed(1)
    const total = Number(productRating?.total_reviews || 0)
    await showAlert({
      severity: 'success',
      summary: 'Rating Updated',
      detail: productRating
        ? `Your review is published. Product rating is now ${average}/5 from ${total} ${total === 1 ? 'review' : 'reviews'}.`
        : 'Thank you for your review!',
    })
    goBack()
  } catch (error: any) {
    showAlert({ severity: 'error', summary: 'Failed', detail: error?.response?.data?.message || 'Unable to submit review.' })
  } finally {
    submitting.value = false
  }
}

function goBack() {
  router.push({ name: 'ecommerce.order-detail', params: { id: route.params.id } })
}

onMounted(loadOrder)
onBeforeUnmount(clearAttachmentPreview)
</script>
