<template>
  <div class="mx-auto w-full max-w-4xl px-4 pb-8 sm:px-6">
    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
      <header class="border-b border-slate-200 bg-gradient-to-r from-orange-50 to-white px-5 py-5 sm:px-7">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">My updates</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-900">Notifications</h1>
            <p class="mt-1 text-sm text-slate-500">Order, return, refund, chat, and review updates appear here.</p>
          </div>
          <Button
            label="Mark all as read"
            icon="pi pi-check"
            size="small"
            text
            severity="secondary"
            :disabled="loading || markingAll || unreadCount === 0"
            :loading="markingAll"
            @click="markAllAsRead"
          />
        </div>

        <div class="mt-4 flex gap-2">
          <Button label="All" size="small" :outlined="filter !== 'all'" severity="warn" @click="setFilter('all')" />
          <Button
            :label="`Unread${unreadCount ? ` (${unreadCount})` : ''}`"
            size="small"
            :outlined="filter !== 'unread'"
            severity="warn"
            @click="setFilter('unread')"
          />
        </div>
      </header>

      <div class="p-4 sm:p-6">
        <div v-if="loading" class="space-y-3">
          <Skeleton v-for="item in 5" :key="item" height="6rem" border-radius="1rem" />
        </div>

        <div v-else-if="notifications.length === 0" class="flex min-h-64 flex-col items-center justify-center text-center">
          <span class="flex h-14 w-14 items-center justify-center rounded-full bg-orange-50 text-orange-500">
            <i class="pi pi-bell text-2xl" />
          </span>
          <h2 class="mt-4 font-semibold text-slate-900">{{ filter === 'unread' ? 'No unread notifications' : 'No notifications yet' }}</h2>
          <p class="mt-1 max-w-sm text-sm text-slate-500">We will show your Ecommerce activity here when there is an update.</p>
        </div>

        <div v-else class="space-y-3">
          <button
            v-for="notification in notifications"
            :key="notification.id"
            type="button"
            class="group w-full rounded-2xl border p-4 text-left transition hover:border-orange-300 hover:shadow-sm"
            :class="notification.is_read ? 'border-slate-200 bg-white' : 'border-orange-200 bg-orange-50/60'"
            @click="openNotification(notification)"
          >
            <div class="flex items-start gap-3">
              <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="iconClass(notification)">
                <i :class="notificationIcon(notification)" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="flex flex-wrap items-start justify-between gap-2">
                  <span class="font-semibold text-slate-900">{{ notification.title || 'Notification' }}</span>
                  <span class="whitespace-nowrap text-xs text-slate-500">{{ formatDate(notification.created_at) }}</span>
                </span>
                <span class="mt-1 block text-sm leading-6 text-slate-600">{{ notification.message || 'Tap to view details.' }}</span>
                <span
                  v-if="notification.data?.order_id"
                  class="mt-3 block rounded-xl border border-slate-200 bg-white p-3"
                >
                  <span class="flex flex-wrap items-center justify-between gap-2 text-xs">
                    <span class="font-semibold text-slate-800">Order #{{ notification.data.order_number || notification.data.order_id }}</span>
                    <span class="rounded-full bg-slate-100 px-2 py-1 font-medium capitalize text-slate-600">
                      {{ formatStatus(notification.data.order_status) }}
                    </span>
                  </span>
                  <span v-if="notification.data.return_number" class="mt-1 block text-xs font-medium text-orange-600">
                    Return #{{ notification.data.return_number }}
                    <template v-if="notification.data.return_type"> · {{ formatStatus(notification.data.return_type) }}</template>
                  </span>
                  <span v-if="notification.data.items?.length" class="mt-3 block space-y-2">
                    <span
                      v-for="item in notification.data.items.slice(0, 3)"
                      :key="item.id || item.product_id"
                      class="flex items-center gap-3"
                    >
                      <img
                        v-if="item.image_url"
                        :src="item.image_url"
                        :alt="item.product_name"
                        class="h-12 w-12 shrink-0 rounded-lg border border-slate-200 object-cover"
                      />
                      <span v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400">
                        <i class="pi pi-image" />
                      </span>
                      <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-slate-800">{{ item.product_name }}</span>
                        <span class="block text-xs text-slate-500">Quantity: {{ item.quantity }}</span>
                      </span>
                    </span>
                    <span v-if="notification.data.items.length > 3" class="block text-xs text-slate-500">
                      +{{ notification.data.items.length - 3 }} more item(s)
                    </span>
                  </span>
                  <span class="mt-3 flex items-center justify-end gap-1 text-xs font-semibold text-orange-600">
                    View order <i class="pi pi-arrow-right text-[10px]" />
                  </span>
                </span>
                <span
                  v-if="notification.entity_type === 'product_review' && notification.data?.reply"
                  class="mt-3 block rounded-xl border border-sky-100 bg-sky-50 px-3 py-2 text-sm italic text-slate-700"
                >
                  “{{ notification.data.reply }}”
                </span>
              </span>
              <span v-if="!notification.is_read" class="mt-2 h-2.5 w-2.5 shrink-0 rounded-full bg-orange-500" aria-label="Unread" />
            </div>
          </button>

          <div v-if="currentPage < lastPage" class="flex justify-center pt-3">
            <Button label="Load more" outlined severity="secondary" :loading="loadingMore" @click="loadMore" />
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import axiosClient from '@/axios'
import EcommerceMobileWrapper from '@/Layouts/EcommerceMobileWrapper.vue'

defineOptions({ layout: EcommerceMobileWrapper })

const router = useRouter()
const notifications = ref<any[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const markingAll = ref(false)
const filter = ref<'all' | 'unread'>('all')
const unreadCount = ref(0)
const currentPage = ref(1)
const lastPage = ref(1)

async function loadNotifications(page = 1, append = false) {
  const response = await axiosClient.get('/api/notifications', {
    params: {
      module: 'ecommerce',
      unread_only: filter.value === 'unread' ? 1 : 0,
      per_page: 15,
      page,
    },
    headers: { 'X-Suppress-Dialog': '1' },
  })
  const items = response.data?.data || []
  notifications.value = append ? [...notifications.value, ...items] : items
  currentPage.value = Number(response.data?.meta?.current_page || page)
  lastPage.value = Number(response.data?.meta?.last_page || 1)
}

async function loadUnreadCount() {
  const response = await axiosClient.get('/api/notifications/unread', {
    params: { module: 'ecommerce' },
    headers: { 'X-Suppress-Dialog': '1' },
  })
  unreadCount.value = Number(response.data?.data?.unread_count || 0)
}

async function refresh() {
  loading.value = true
  try {
    await Promise.all([loadNotifications(), loadUnreadCount()])
  } finally {
    loading.value = false
  }
}

async function setFilter(value: 'all' | 'unread') {
  if (filter.value === value) return
  filter.value = value
  await refresh()
}

async function loadMore() {
  loadingMore.value = true
  try {
    await loadNotifications(currentPage.value + 1, true)
  } finally {
    loadingMore.value = false
  }
}

async function markAllAsRead() {
  markingAll.value = true
  try {
    await axiosClient.put('/api/notifications/mark-all-read?module=ecommerce', {}, {
      headers: { 'X-Suppress-Dialog': '1' },
    })
    unreadCount.value = 0
    if (filter.value === 'unread') notifications.value = []
    else notifications.value = notifications.value.map((item) => ({ ...item, is_read: true, read_at: new Date().toISOString() }))
    window.dispatchEvent(new CustomEvent('ecommerce-notifications-updated'))
  } finally {
    markingAll.value = false
  }
}

async function openNotification(notification: any) {
  if (!notification?.is_read && notification?.id) {
    try {
      await axiosClient.put(`/api/notifications/${notification.id}/read`, {}, {
        headers: { 'X-Suppress-Dialog': '1' },
      })
      notification.is_read = true
      unreadCount.value = Math.max(0, unreadCount.value - 1)
      window.dispatchEvent(new CustomEvent('ecommerce-notifications-updated'))
      if (filter.value === 'unread') notifications.value = notifications.value.filter((item) => item.id !== notification.id)
    } catch {
      // Keep the destination accessible if updating the read state fails.
    }
  }

  if (notification?.link) router.push(notification.link)
}

function notificationIcon(notification: any) {
  const type = String(notification?.entity_type || '')
  if (type.includes('return') || type.includes('refund')) return 'pi pi-replay'
  if (type.includes('chat')) return 'pi pi-comments'
  if (type.includes('review')) return 'pi pi-star'
  if (type.includes('order')) return 'pi pi-box'
  return 'pi pi-bell'
}

function iconClass(notification: any) {
  const severity = String(notification?.severity || 'info')
  if (severity === 'success') return 'bg-emerald-100 text-emerald-700'
  if (severity === 'warn' || severity === 'warning') return 'bg-amber-100 text-amber-700'
  if (severity === 'danger' || severity === 'error') return 'bg-red-100 text-red-700'
  return 'bg-sky-100 text-sky-700'
}

function formatDate(value?: string | null) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat('en-PH', {
    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
  }).format(date)
}

function formatStatus(value?: string | null) {
  if (!value) return 'Order update'
  return String(value).replaceAll('_', ' ')
}

onMounted(refresh)
</script>
