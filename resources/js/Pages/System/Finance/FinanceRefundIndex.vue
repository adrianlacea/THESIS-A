<template>
  <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div><h1 class="text-xl font-semibold text-slate-900">Customer Refunds</h1><p class="mt-1 text-sm text-slate-500">Review and release approved customer refund requests.</p></div>
      <Button v-if="canManage" label="Create Refund" icon="pi pi-plus" @click="router.push({ name: 'finance.refunds.create' })" />
    </header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Card v-for="item in summary" :key="item.label" class="rounded-2xl border border-slate-200 shadow-sm"><template #content><div class="p-5"><div class="flex items-center justify-between"><span class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ item.label }}</span><i :class="[item.icon, item.color]"></i></div><p class="mt-3 text-2xl font-semibold text-slate-900">{{ item.money ? currency(item.value) : item.value }}</p></div></template></Card>
    </div>

    <Card class="rounded-2xl border border-slate-200 shadow-sm"><template #content><div class="grid gap-3 p-5 md:grid-cols-4">
      <InputText v-model="filters.search" placeholder="Search order or customer" fluid @keyup.enter="load" />
      <Select v-model="filters.source" :options="sources" optionLabel="label" optionValue="value" fluid />
      <Select v-model="filters.status" :options="statuses" optionLabel="label" optionValue="value" fluid />
      <div class="flex gap-2"><Button label="Apply" icon="pi pi-filter" class="flex-1" :loading="loading" @click="load" /><Button icon="pi pi-filter-slash" outlined @click="clear" /></div>
    </div></template></Card>

    <Card class="rounded-2xl border border-slate-200 shadow-sm"><template #content><div class="p-5">
      <DataTable :value="refunds" :loading="loading" stripedRows paginator :rows="10" :rowsPerPageOptions="[10,20,50]" responsiveLayout="scroll">
        <Column header="Reference"><template #body="{ data }"><button class="font-medium text-blue-600 hover:underline" @click="open(data)">{{ data.order_number || `Refund #${data.id}` }}</button><small class="block text-slate-500">{{ sourceLabel(data.order_type) }}</small></template></Column>
        <Column field="customer_name" header="Customer" />
        <Column header="Amount"><template #body="{ data }"><span class="font-semibold text-rose-600">{{ currency(data.amount) }}</span></template></Column>
        <Column field="reason" header="Reason" />
        <Column header="Requested"><template #body="{ data }">{{ date(data.created_at) }}</template></Column>
        <Column header="Status"><template #body="{ data }"><Tag :value="label(data.status)" :severity="severity(data.status)" /></template></Column>
        <Column header="Action"><template #body="{ data }"><Button icon="pi pi-eye" text rounded @click="open(data)" /></template></Column>
        <template #empty><div class="py-10 text-center text-slate-500">No refund requests found.</div></template>
      </DataTable>
    </div></template></Card>
  </div>
</template>
<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'; import { useRouter } from 'vue-router'; import { useToast } from 'primevue/usetoast'; import { useAuthStore } from '@/stores/auth'; import financeService from '@/services/finance.service'; import Card from 'primevue/card'; import Button from 'primevue/button'; import InputText from 'primevue/inputtext'; import Select from 'primevue/select'; import DataTable from 'primevue/datatable'; import Column from 'primevue/column'; import Tag from 'primevue/tag'
const router=useRouter(),toast=useToast(),auth=useAuthStore(); const canManage=auth.hasPermission('finance.refunds.manage'); const loading=ref(false),refunds=ref<any[]>([]); const filters=reactive({search:'',source:'all',status:''}); const sources=[{label:'All Sources',value:'all'},{label:'Ecommerce Returns',value:'ecommerce_return'},{label:'Ecommerce',value:'ecommerce'},{label:'In Store',value:'sales'},{label:'Manual',value:'manual'}]; const statuses=[{label:'All Statuses',value:''},{label:'Pending Inspection',value:'pending_inspection'},{label:'Pending Finance Approval',value:'pending'},{label:'Approved — Awaiting Send',value:'approved'},{label:'Sent',value:'sent'},{label:'Rejected',value:'rejected'}]
const currency=(v:any)=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(Number(v||0)); const date=(v:any)=>v?new Date(v).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}):'-'; const label=(v:any)=>String(v||'pending').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase()); const sourceLabel=(v:string)=>({ecommerce_return:'Ecommerce Return',ecommerce:'Ecommerce',sales:'In Store',manual:'Manual'} as any)[v]||label(v); const severity=(v:string)=>['approved','sent'].includes(v)?'success':v==='rejected'?'danger':v==='pending_inspection'?'info':'warn'; const summary=computed(()=>[{label:'Pending Inspection',value:refunds.value.filter(x=>x.status==='pending_inspection').length,icon:'pi pi-search',color:'text-blue-600'},{label:'Pending Approval',value:refunds.value.filter(x=>x.status==='pending').length,icon:'pi pi-clock',color:'text-amber-600'},{label:'Awaiting Send',value:refunds.value.filter(x=>x.status==='approved').length,icon:'pi pi-send',color:'text-emerald-600'},{label:'Amount To Send',value:refunds.value.filter(x=>['pending','approved'].includes(x.status)).reduce((s,x)=>s+Number(x.amount||0),0),money:true,icon:'pi pi-wallet',color:'text-rose-600'}]);
const load=async()=>{loading.value=true;try{const r=await financeService.getRefunds({...filters});refunds.value=r?.data||[]}catch(e:any){toast.add({severity:'error',summary:'Load Failed',detail:e?.response?.data?.message||'Unable to load refunds.',life:3000})}finally{loading.value=false}}; const clear=()=>{filters.search='';filters.source='all';filters.status='';load()}; const open=(row:any)=>router.push({name:'finance.refunds.detail',params:{id:row.id}}); onMounted(load)
</script>
