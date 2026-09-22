<template>
  <Head v-if="pageTitle" :title="pageTitle" />
  <div class="min-h-screen bg-linear-to-b from-[#e0e7f5] to-[#f6f9fd]">
    <Toast />
    <ConfirmDialog />
    <header class="sticky top-0 z-20 hidden border-b border-slate-200/70 bg-white/90 backdrop-blur-sm md:block">
      <div
        class="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-between gap-2 px-2 py-1.5 sm:px-3 sm:py-2 md:px-4">
        <button link severity="secondary" @click="router.push({name: 'ecommerce.home'})"
          class="flex items-center justify-center rounded-lg">
          <span class="portal-brand text-orange-500 text-base sm:text-lg">FURNISYNC</span>
        </button>

        <form class="mx-4 min-w-0 max-w-xl flex-1" role="search" @submit.prevent="submitProductSearch">
          <IconField class="w-full">
            <InputIcon class="pi pi-search text-slate-400" />
            <InputText
              v-model="productSearch"
              type="search"
              placeholder="Search furniture products"
              aria-label="Search all available furniture products"
              size="small"
              class="w-full !rounded-full !border-slate-200 !bg-slate-50 !text-sm focus:!border-orange-400 focus:!ring-orange-100"
            />
          </IconField>
        </form>
  
        <div class="hidden items-center gap-2 md:flex">
          <div class="relative flex h-10 w-10 items-center justify-center">
            <Button
              icon="pi pi-shopping-cart"
              text
              rounded
              class="header-action-button"
              aria-label="Cart"
              v-tooltip.bottom="'Cart'"
              @click="goCart"
            />
            <Badge
              v-if="cartCount > 0"
              :value="cartCount > 99 ? '99+' : String(cartCount)"
              severity="secondary"
              class="header-action-badge"
            />
          </div>
          <div v-if="isLoggedIn" class="relative flex h-10 w-10 items-center justify-center">
            <Button
              icon="pi pi-bell"
              text
              rounded
              class="header-action-button"
              aria-label="Notifications"
              v-tooltip.bottom="'Notifications'"
              @click="goNotifications"
            />
            <Badge
              v-if="unreadNotificationCount > 0"
              :value="unreadNotificationCount > 99 ? '99+' : String(unreadNotificationCount)"
              severity="danger"
              class="header-action-badge"
            />
          </div>
          <Button v-if="!isLoggedIn" label="Login" rounded class="compact-button !text-sm" size="small" fluid
            @click="goLogin" />
          <div v-else class="flex h-10 w-10 items-center justify-center">
            <Button
              icon="pi pi-user"
              rounded
              text
              class="header-action-button"
              aria-label="Account"
              v-tooltip.bottom="'Account'"
              @click="toggleProfilePopover"
            />
          </div>
        </div>
      </div>
    </header>

    <div class="sticky top-0 z-20 border-b border-slate-200/70 bg-white/95 px-3 py-2 backdrop-blur-sm md:hidden">
      <form role="search" @submit.prevent="submitProductSearch">
        <IconField class="w-full">
          <InputIcon class="pi pi-search text-slate-400" />
          <InputText
            v-model="productSearch"
            type="search"
            placeholder="Search furniture products"
            aria-label="Search all available furniture products"
            size="small"
            class="w-full !rounded-full !border-slate-200 !bg-slate-50 !text-sm focus:!border-orange-400 focus:!ring-orange-100"
          />
        </IconField>
      </form>
    </div>
  
    <main class="mx-auto w-full max-w-7xl px-3 pb-6 pt-3 sm:px-4 md:px-6 md:pb-8 md:pt-4">
      <slot />
    </main>
  
    <!-- <ScrollTop /> -->
  
    <Popover ref="profilePopoverRef" class="w-64 p-3">
  
      <div class="">
        <p class="text-base font-semibold leading-5 text-slate-900">{{ customerFullName }}</p>
        <p class="mt-1 mb-3 truncate text-xs text-slate-500">{{ customerEmail }}</p>
        <div v-if="chatThreads.length" class="rounded-md border border-slate-200 p-2">
          <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Recent Chats</p>
          <button v-for="thread in chatThreads" :key="thread.id" type="button"
            class="flex w-full items-center justify-between rounded px-2 py-1 text-left text-xs text-slate-700 hover:bg-slate-100"
            @click="goChatThread(thread.store_id)">
            <span class="truncate">{{ thread.store_name }}</span>
            <Tag v-if="thread.unread_count" :value="thread.unread_count" severity="warn" />
          </button>
        </div>
        <div class="space-y-1 border-t border-slate-200 pt-2">
          <Button label="Profile" icon="pi pi-user" text severity="secondary"
            class="w-full !justify-start !px-2 !py-2 text-left"
            :pt="{ root: { class: '!justify-start' }, label: { class: '!text-left' } }" @click="goProfile" />
          <Button label="Orders" icon="pi pi-shopping-bag" text severity="secondary"
            class="w-full !justify-start !px-2 !py-2 text-left"
            :pt="{ root: { class: '!justify-start' }, label: { class: '!text-left' } }" @click="goOrders" />
          <Button label="Chats" icon="pi pi-comments" text severity="secondary"
            class="w-full !justify-start !px-2 !py-2 text-left"
            :pt="{ root: { class: '!justify-start' }, label: { class: '!text-left' } }" @click="goChats" />
          <Button label="Logout" icon="pi pi-sign-out" text severity="danger"
            class="w-full !justify-start !px-2 !py-2 text-left"
            :pt="{ root: { class: '!justify-start' }, label: { class: '!text-left' } }" @click="logoutCustomer" />
        </div>
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Head, usePage } from '@inertiajs/vue3'
import ecommerceService from '@/services/ecommerce.service'
import { useAuthStore } from '@/stores/auth'
import Popover from 'primevue/popover'
import { useToast } from 'primevue/usetoast'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'
import { useConfirm } from 'primevue/useconfirm'
import axiosClient from '@/axios'

const route = useRoute()
const router = useRouter()
const page = usePage()
const authStore = useAuthStore()
const toast = useToast()
const confirm = useConfirm()
const pageTitle = computed(() => String(page.props?.title || ''))
const productSearch = ref('')

const cartCount = ref(0)
const unreadNotificationCount = ref(0)
const isLoggedIn = computed(() => authStore.isAuthenticated)
const profilePopoverRef = ref()
const chatThreads = ref<any[]>([])
const customerFullName = computed(() => {
  const first = authStore.user?.first_name || authStore.user?.fname || ''
  const last = authStore.user?.last_name || authStore.user?.lname || ''
  return `${first} ${last}`.trim() || 'Customer'
})
const customerEmail = computed(() => authStore.user?.email || 'No email available')

async function loadCartCount() {
  if (!isLoggedIn.value) {
    cartCount.value = 0
    return
  }

  try {
    const response = await ecommerceService.getCarts()
    const carts = response.data?.data || []
    cartCount.value = carts.reduce((sum: number, c: any) => sum + Number(c.items_count || 0), 0)
  } catch {
    cartCount.value = 0
  }
}

function goCart() {
  router.push({ name: 'ecommerce.cart' })
}

function searchFromUrl() {
  const query = String(page.url || '').split('?')[1] || ''
  return new URLSearchParams(query).get('search') || ''
}

function submitProductSearch() {
  const search = productSearch.value.trim()
  router.push({
    name: 'ecommerce.products',
    query: search ? { search } : {},
  })
}

function goLogin() {
  router.push({ name: 'customer.login', query: { redirect: route.fullPath || '/shop' } })
}

function goProfile() {
  profilePopoverRef.value?.hide()
  router.push({ name: 'ecommerce.profile' })
}

function goNotifications() {
  router.push({ name: 'ecommerce.notifications' })
}

async function loadUnreadNotificationCount() {
  if (!isLoggedIn.value) {
    unreadNotificationCount.value = 0
    return
  }
  try {
    const response = await axiosClient.get('/api/notifications/unread', {
      params: { module: 'ecommerce' },
      headers: { 'X-Suppress-Dialog': '1' },
    })
    unreadNotificationCount.value = Number(response.data?.data?.unread_count || 0)
  } catch {
    unreadNotificationCount.value = 0
  }
}

function toggleProfilePopover(event: Event) {
  loadChatThreads()
  profilePopoverRef.value?.toggle(event)
}

function goOrders() {
  profilePopoverRef.value?.hide()
  router.push({ name: 'ecommerce.orders' })
}

function goChats() {
  profilePopoverRef.value?.hide()
  router.push({ name: 'ecommerce.chats' })
}

function goChatThread(storeId: number) {
  profilePopoverRef.value?.hide()
  router.push({ name: 'ecommerce.chats', query: { store_id: String(storeId) } })
}

async function logoutCustomer() {
  profilePopoverRef.value?.hide()
  confirm.require({
    message: 'Are you sure you want to log out?',
    header: 'Confirm Logout',
    rejectProps: {
      label: 'Cancel',
      severity: 'secondary',
      outlined: true,
    },
    acceptProps: {
      label: 'Log out',
      severity: 'danger',
    },
    accept: async () => {
      await authStore.logout({ redirect: false })
      toast.add({ severity: 'success', summary: 'Logged out', detail: 'See you again soon!', life: 1600 })
      router.push({ name: 'ecommerce.home' })
    },
  })
}

function handleCartUpdated() {
  loadCartCount()
}

function handleNotificationsUpdated() {
  loadUnreadNotificationCount()
}

async function loadChatThreads() {
  if (!isLoggedIn.value) {
    chatThreads.value = []
    return
  }
  try {
    const response = await ecommerceService.getChatThreads({ per_page: 5 })
    const payload = response?.data?.data
    chatThreads.value = Array.isArray(payload) ? payload : (Array.isArray(payload?.data) ? payload.data : [])
  } catch {
    chatThreads.value = []
  }
}

watch(() => route.fullPath, () => {
  loadCartCount()
  loadUnreadNotificationCount()
})
watch(() => page.url, () => {
  productSearch.value = searchFromUrl()
}, { immediate: true })
watch(isLoggedIn, () => {
  loadCartCount()
  loadChatThreads()
  loadUnreadNotificationCount()
})

onMounted(() => {
  loadCartCount()
  loadChatThreads()
  loadUnreadNotificationCount()
  window.addEventListener('ecommerce-cart-updated', handleCartUpdated)
  window.addEventListener('ecommerce-notifications-updated', handleNotificationsUpdated)
})

onUnmounted(() => {
  window.removeEventListener('ecommerce-cart-updated', handleCartUpdated)
  window.removeEventListener('ecommerce-notifications-updated', handleNotificationsUpdated)
})
</script>

<style scoped>
@font-face {
  font-family: 'Barabara';
  src: url('/fonts/BARABARA-final.otf') format('opentype');
  font-weight: 400;
  font-style: normal;
  font-display: swap;
}

.portal-brand {
  font-family: 'Barabara', sans-serif;
}

.header-action-button {
  width: 2.5rem !important;
  height: 2.5rem !important;
  min-width: 2.5rem !important;
  padding: 0 !important;
}

.header-action-badge {
  position: absolute !important;
  right: -0.2rem !important;
  top: -0.2rem !important;
  min-width: 1.15rem !important;
  height: 1.15rem !important;
  padding: 0 0.25rem !important;
  font-size: 0.65rem !important;
  line-height: 1.15rem !important;
}

@media (max-width: 640px) {
  .compact-button :deep(.p-button-label) {
    display: none;
  }

  .compact-button :deep(.p-button-icon) {
    margin-right: 0;
  }
}
</style>
