<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppHeader from '@/Components/AppHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import GlossaryTip from '@/Components/GlossaryTip.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

type PromotionType = 'seasonal' | 'flash' | 'clearance' | 'bundle' | 'other' | null;
type DiscountType = 'percent_off' | 'BOGO' | 'free_shipping' | 'bundle_pricing' | 'fixed_amount_off' | null;
type AdSpendBand = 'none' | 'low' | 'mid' | 'high' | 'very_high' | null;
type Audience = 'existing_customers' | 'new_acquisition' | 'both' | null;
type TargetingMode = 'all' | 'categories' | 'sku';
type Layer = 'rules' | 'nearest_neighbor' | 'ml';

interface SkuOption {
 id: number;
 sku_code: string;
 name: string;
 category: string;
}

interface PromotionRow {
 id: number;
 name: string;
 promotion_type: PromotionType;
 start_date: string;
 end_date: string;
 expected_uplift_pct: number;
 manual_uplift_pct: number | null;
 override_reason: string | null;
 discount_pct: number | null;
 discount_type: DiscountType;
 channel_mix: string[];
 ad_spend_band: AdSpendBand;
 audience: Audience;
 lead_announcement_days: number | null;
 affects_all_skus: boolean;
 applies_to_categories: string[];
 sku_ids: number[];
 sku_names: string[];
}

const props = defineProps<{
 promotions: PromotionRow[];
 skus: SkuOption[];
}>();

const PROMOTION_TYPES: { value: PromotionType; labelKey: string }[] = [
 { value: null, labelKey: 'promotions.type_none' },
 { value: 'seasonal', labelKey: 'promotions.type_seasonal' },
 { value: 'flash', labelKey: 'promotions.type_flash' },
 { value: 'clearance', labelKey: 'promotions.type_clearance' },
 { value: 'bundle', labelKey: 'promotions.type_bundle' },
 { value: 'other', labelKey: 'promotions.type_other' },
];

// Brief enum reference sets — must match Promotion model's constants.
const DISCOUNT_TYPES: { value: DiscountType; label: string }[] = [
 { value: null, label: '— Select discount type' },
 { value: 'percent_off', label: '% Off' },
 { value: 'BOGO', label: 'Buy One Get One' },
 { value: 'free_shipping', label: 'Free Shipping' },
 { value: 'bundle_pricing', label: 'Bundle Pricing' },
 { value: 'fixed_amount_off', label: 'Fixed Amount Off' },
];

const CHANNEL_TAGS: { value: string; label: string }[] = [
 { value: 'paid_social', label: 'Paid Social' },
 { value: 'email', label: 'Email' },
 { value: 'organic_social', label: 'Organic Social' },
 { value: 'in_store', label: 'In-Store' },
 { value: 'influencer', label: 'Influencer' },
 { value: 'display_ads', label: 'Display Ads' },
 { value: 'sms', label: 'SMS' },
];

const AD_SPEND_BANDS: { value: AdSpendBand; label: string }[] = [
 { value: null, label: '— Select spend band' },
 { value: 'none', label: 'None' },
 { value: 'low', label: 'Low (under $1K)' },
 { value: 'mid', label: 'Mid ($1K – $10K)' },
 { value: 'high', label: 'High ($10K – $50K)' },
 { value: 'very_high', label: 'Very High (over $50K)' },
];

const AUDIENCES: { value: Audience; label: string }[] = [
 { value: null, label: '— Select audience' },
 { value: 'existing_customers', label: 'Existing Customers' },
 { value: 'new_acquisition', label: 'New Acquisition' },
 { value: 'both', label: 'Both' },
];

const CATEGORIES: { value: string; labelKey: string }[] = [
 { value: 'equipment', labelKey: 'promotions.category_equipment' },
 { value: 'accessory', labelKey: 'promotions.category_accessory' },
 { value: 'bundle', labelKey: 'promotions.category_bundle' },
];

const LAYER_LABELS: Record<Layer, string> = {
 rules: 'rule-based',
 nearest_neighbor: 'data-driven',
 ml: 'ML model',
};

const LAYER_BADGE_CLASSES: Record<Layer, string> = {
 rules: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 ring-1 ring-slate-200 dark:ring-slate-700',
 nearest_neighbor: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 ring-1 ring-blue-200 dark:ring-blue-800',
 ml: 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 ring-1 ring-emerald-200 dark:ring-emerald-800',
};

// ─── Dialog state ──────────────────────────────────────────────────────────
type DialogMode = 'create' | 'edit' | null;
const dialogMode = ref<DialogMode>(null);
const editingId = ref<number | null>(null);
const targetingMode = ref<TargetingMode>('all');
const showOverride = ref(false);

function resolveTargetingMode(p: PromotionRow): TargetingMode {
 if (p.affects_all_skus) return 'all';
 if (p.applies_to_categories && p.applies_to_categories.length > 0) return 'categories';
 return 'sku';
}

function openCreate() {
 form.reset();
 form.clearErrors();
 editingId.value = null;
 targetingMode.value = 'all';
 showOverride.value = false;
 dialogMode.value = 'create';
 fetchPrediction();
}

function openEdit(p: PromotionRow) {
 form.name = p.name;
 form.promotion_type = p.promotion_type;
 form.start_date = p.start_date;
 form.end_date = p.end_date;
 form.discount_pct = p.discount_pct;
 form.discount_type = p.discount_type;
 form.channel_mix = [...(p.channel_mix ?? [])];
 form.ad_spend_band = p.ad_spend_band;
 form.audience = p.audience;
 form.lead_announcement_days = p.lead_announcement_days;
 form.manual_uplift_pct = p.manual_uplift_pct;
 form.override_reason = p.override_reason ?? '';
 form.affects_all_skus = p.affects_all_skus;
 form.applies_to_categories = [...(p.applies_to_categories ?? [])];
 form.sku_ids = [...p.sku_ids];
 form.clearErrors();
 editingId.value = p.id;
 targetingMode.value = resolveTargetingMode(p);
 showOverride.value = p.manual_uplift_pct !== null;
 dialogMode.value = 'edit';
 fetchPrediction();
}

function closeDialog() {
 dialogMode.value = null;
 editingId.value = null;
}

function onKeydown(e: KeyboardEvent) {
 if (e.key === 'Escape') closeDialog();
}
onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

// ─── Form ──────────────────────────────────────────────────────────────────
// expected_uplift_pct is NOT in the form — it's computed server-side by
// PredictionEngine on save (manual_uplift_pct wins when set, else the
// engine's prediction).
const form = useForm({
 name: '',
 promotion_type: null as PromotionType,
 start_date: '',
 end_date: '',
 discount_pct: null as number | null,
 discount_type: null as DiscountType,
 channel_mix: [] as string[],
 ad_spend_band: null as AdSpendBand,
 audience: null as Audience,
 lead_announcement_days: null as number | null,
 manual_uplift_pct: null as number | null,
 override_reason: '',
 affects_all_skus: true,
 applies_to_categories: [] as string[],
 sku_ids: [] as number[],
});

function onTargetingChange(mode: TargetingMode) {
 targetingMode.value = mode;
 form.affects_all_skus = mode === 'all';
 if (mode !== 'categories') form.applies_to_categories = [];
 if (mode !== 'sku') form.sku_ids = [];
}

function toggleChannel(channel: string) {
 const idx = form.channel_mix.indexOf(channel);
 if (idx === -1) form.channel_mix.push(channel);
 else form.channel_mix.splice(idx, 1);
}

function toggleOverride() {
 showOverride.value = ! showOverride.value;
 if (! showOverride.value) {
 // Collapsing → clear override fields so save uses the engine prediction.
 form.manual_uplift_pct = null;
 form.override_reason = '';
 }
}

function revertToEnginePrediction() {
 form.manual_uplift_pct = null;
 form.override_reason = '';
 showOverride.value = false;
}

// ─── Engine prediction (live preview from /promotions/suggest-uplift) ──────
interface UpliftPrediction {
 value: number;
 lower: number;
 upper: number;
 basis: string;
 sample_size: number;
 layer: Layer;
}

const prediction = ref<UpliftPrediction | null>(null);
const predictionLoading = ref(false);
let fetchTimer: ReturnType<typeof setTimeout> | null = null;

async function fetchPrediction(): Promise<void> {
 predictionLoading.value = true;
 try {
 const params = new URLSearchParams();
 if (form.promotion_type) params.append('promotion_type', form.promotion_type);
 if (form.discount_pct !== null) params.append('discount_pct', String(form.discount_pct));
 if (form.discount_type) params.append('discount_type', form.discount_type);
 form.channel_mix.forEach(c => params.append('channel_mix[]', c));
 if (form.ad_spend_band) params.append('ad_spend_band', form.ad_spend_band);
 if (form.audience) params.append('audience', form.audience);
 if (form.lead_announcement_days !== null) {
 params.append('lead_announcement_days', String(form.lead_announcement_days));
 }

 const res = await fetch(`/promotions/suggest-uplift?${params.toString()}`, {
 headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
 });
 if (! res.ok) throw new Error(`HTTP ${res.status}`);
 prediction.value = await res.json() as UpliftPrediction;
 } catch {
 prediction.value = null;
 } finally {
 predictionLoading.value = false;
 }
}

// Re-fetch on every Brief-defining field change. Debounced 300ms so rapid
// channel toggles don't spam the endpoint.
watch(
 () => [
 form.promotion_type,
 form.discount_pct,
 form.discount_type,
 form.channel_mix.slice(),
 form.ad_spend_band,
 form.audience,
 form.lead_announcement_days,
 ],
 () => {
 if (dialogMode.value === null) return;
 if (fetchTimer) clearTimeout(fetchTimer);
 fetchTimer = setTimeout(fetchPrediction, 300);
 },
 { deep: true }
);

const overrideActive = computed<boolean>(() => form.manual_uplift_pct !== null);

function submit() {
 if (dialogMode.value === 'create') {
 form.post('/promotions', { onSuccess: () => closeDialog() });
 } else if (dialogMode.value === 'edit' && editingId.value !== null) {
 form.patch(`/promotions/${editingId.value}`, { onSuccess: () => closeDialog() });
 }
}

// ─── Delete ────────────────────────────────────────────────────────────────
function deletePromotion(id: number) {
 if (!confirm(t('promotions.confirm_delete'))) return;
 router.delete(`/promotions/${id}`);
}

// ─── Category multi-select ─────────────────────────────────────────────────
function toggleCategory(cat: string) {
 const idx = form.applies_to_categories.indexOf(cat);
 if (idx === -1) form.applies_to_categories.push(cat);
 else form.applies_to_categories.splice(idx, 1);
}

// ─── SKU multi-select ─────────────────────────────────────────────────────
const skuSearch = ref('');

const filteredSkus = computed(() => {
 const q = skuSearch.value.trim().toLowerCase();
 if (!q) return props.skus;
 return props.skus.filter(s =>
 s.sku_code.toLowerCase().includes(q) || s.name.toLowerCase().includes(q)
 );
});

function toggleSku(id: number) {
 const idx = form.sku_ids.indexOf(id);
 if (idx === -1) form.sku_ids.push(id);
 else form.sku_ids.splice(idx, 1);
}

// ─── Display helpers ──────────────────────────────────────────────────────
function typeBadgeClass(type: PromotionType): string {
 const map: Record<string, string> = {
 seasonal: 'bg-blue-100 text-blue-700',
 flash: 'bg-orange-100 text-orange-700',
 clearance: 'bg-red-100 text-red-700',
 bundle: 'bg-purple-100 text-purple-700',
 other: 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400',
 };
 return type ? (map[type] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400') : 'bg-slate-100 text-slate-400 ';
}

function typeLabel(type: PromotionType): string {
 if (!type) return '—';
 const map: Record<string, string> = {
 seasonal: t('promotions.type_seasonal'),
 flash: t('promotions.type_flash'),
 clearance: t('promotions.type_clearance'),
 bundle: t('promotions.type_bundle'),
 other: t('promotions.type_other'),
 };
 return map[type] ?? type;
}

function affectsLabel(p: PromotionRow): string {
 if (p.affects_all_skus) return t('promotions.affects_all');
 if (p.applies_to_categories && p.applies_to_categories.length > 0) {
 return p.applies_to_categories
 .map(c => t(`promotions.category_${c}`))
 .join(', ');
 }
 if (p.sku_names.length === 0) return '—';
 return p.sku_names.slice(0, 2).join(', ') + (p.sku_names.length > 2 ? ` +${p.sku_names.length - 2}` : '');
}
</script>

<template>
 <div class="min-h-screen bg-[#F8FAFC] dark:bg-slate-950">
 <AppHeader />

 <main class="max-w-7xl mx-auto px-6 lg:px-8 py-8">
 <!-- Page header -->
 <div class="flex items-center justify-between mb-6">
 <div>
 <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ t('promotions.title') }}</h1>
 <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">{{ t('promotions.subtitle') }}</p>
 </div>
 <!-- Header CTA hidden when empty — the EmptyState card below shows
 the centered primary action. Two CTAs for the same action would
 dilute attention. -->
 <button
 v-if="promotions.length > 0"
 @click="openCreate"
 class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
 >
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
 </svg>
 {{ t('promotions.add') }}
 </button>
 </div>

 <!-- Table -->
 <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
 <table v-if="promotions.length > 0" class="min-w-full text-sm">
 <thead>
 <tr class="border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800">
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('promotions.name') }}</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('promotions.promotion_type') }}</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('promotions.start_date') }}</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('promotions.end_date') }}</th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('promotions.uplift_pct') }}</th>
 <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ t('promotions.affects') }}</th>
 <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide"></th>
 </tr>
 </thead>
 <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
 <tr
 v-for="p in promotions"
 :key="p.id"
 class="hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 transition-colors"
 >
 <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">{{ p.name }}</td>
 <td class="px-4 py-3">
 <span
 class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold"
 :class="typeBadgeClass(p.promotion_type)"
 >
 {{ typeLabel(p.promotion_type) }}
 </span>
 </td>
 <td class="px-4 py-3 text-slate-600 dark:text-slate-300 tabular-nums">{{ p.start_date }}</td>
 <td class="px-4 py-3 text-slate-600 dark:text-slate-300 tabular-nums">{{ p.end_date }}</td>
 <td class="px-4 py-3 text-right font-mono font-semibold text-blue-600 tabular-nums">+{{ p.expected_uplift_pct }}%</td>
 <td class="px-4 py-3 text-slate-600 dark:text-slate-300 text-xs">{{ affectsLabel(p) }}</td>
 <td class="px-4 py-3 text-right">
 <div class="flex items-center justify-end gap-2">
 <button
 @click="openEdit(p)"
 class="text-xs text-slate-500 dark:text-slate-400 hover:text-blue-600 hover:underline transition-colors cursor-pointer"
 >
 {{ t('promotions.edit') }}
 </button>
 <button
 @click="deletePromotion(p.id)"
 class="text-xs text-slate-400 dark:text-slate-500 hover:text-red-600 hover:underline transition-colors cursor-pointer"
 >
 {{ t('promotions.delete') }}
 </button>
 </div>
 </td>
 </tr>
 </tbody>
 </table>

 <EmptyState
 v-else
 title="No promotions scheduled."
 description="Add a promotion to account for demand spikes in your forecast."
 >
 <template #icon>
 <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
 </svg>
 </template>
 <template #action>
 <button
 @click="openCreate"
 class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors cursor-pointer"
 >
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
 </svg>
 {{ t('promotions.add') }}
 </button>
 </template>
 </EmptyState>
 </div>
 </main>
 </div>

 <!-- Create / Edit Dialog -->
 <Teleport to="body">
 <div
 v-if="dialogMode !== null"
 class="fixed inset-0 z-50 flex items-center justify-center p-4"
 >
 <!-- Backdrop -->
 <div class="absolute inset-0 bg-black/40" @click="closeDialog" />

 <!-- Panel -->
 <div
 role="dialog"
 aria-modal="true"
 class="relative z-10 w-full max-w-lg bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden max-h-[90vh] flex flex-col"
 >
 <!-- Header -->
 <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
 <h2 class="text-base font-bold text-slate-800 dark:text-slate-100">
 {{ dialogMode === 'create' ? t('promotions.add') : t('promotions.edit') }}
 </h2>
 <button
 @click="closeDialog"
 class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors cursor-pointer"
 aria-label="Close"
 >
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
 </svg>
 </button>
 </div>

 <!-- Form (scrollable) -->
 <form @submit.prevent="submit" class="px-5 py-4 space-y-4 overflow-y-auto">

 <!-- Name -->
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('promotions.name') }}</label>
 <input
 v-model="form.name"
 type="text"
 class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
 :class="{ 'border-red-400': form.errors.name }"
 />
 <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
 </div>

 <!-- Promotion Type -->
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('promotions.promotion_type') }}</label>
 <select
 v-model="form.promotion_type"
 class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white dark:bg-slate-900"
 :class="{ 'border-red-400': form.errors.promotion_type }"
 >
 <option
 v-for="opt in PROMOTION_TYPES"
 :key="String(opt.value)"
 :value="opt.value"
 >
 {{ t(opt.labelKey) }}
 </option>
 </select>
 <p v-if="form.errors.promotion_type" class="mt-1 text-xs text-red-500">{{ form.errors.promotion_type }}</p>
 </div>

 <!-- Dates -->
 <div class="grid grid-cols-2 gap-3">
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('promotions.start_date') }}</label>
 <input
 v-model="form.start_date"
 type="date"
 class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
 :class="{ 'border-red-400': form.errors.start_date }"
 />
 <p v-if="form.errors.start_date" class="mt-1 text-xs text-red-500">{{ form.errors.start_date }}</p>
 </div>
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">{{ t('promotions.end_date') }}</label>
 <input
 v-model="form.end_date"
 type="date"
 class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
 :class="{ 'border-red-400': form.errors.end_date }"
 />
 <p v-if="form.errors.end_date" class="mt-1 text-xs text-red-500">{{ form.errors.end_date }}</p>
 </div>
 </div>

 <!-- ─── Campaign Brief ─────────────────────────────────────── -->
 <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/30 p-3 space-y-3">
 <p class="text-xs font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-wide">Campaign Brief</p>

 <!-- Discount % + Discount Type -->
 <div class="grid grid-cols-2 gap-3">
 <div>
 <label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Discount %</label>
 <input
 v-model.number="form.discount_pct"
 type="number"
 min="0"
 max="100"
 step="0.5"
 placeholder="e.g. 30"
 class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white dark:bg-slate-900 tabular-nums"
 :class="{ 'border-red-400': form.errors.discount_pct }"
 />
 <p v-if="form.errors.discount_pct" class="mt-1 text-xs text-red-500">{{ form.errors.discount_pct }}</p>
 </div>
 <div>
 <label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Discount Type</label>
 <select
 v-model="form.discount_type"
 class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white dark:bg-slate-900"
 :class="{ 'border-red-400': form.errors.discount_type }"
 >
 <option v-for="opt in DISCOUNT_TYPES" :key="String(opt.value)" :value="opt.value">{{ opt.label }}</option>
 </select>
 <p v-if="form.errors.discount_type" class="mt-1 text-xs text-red-500">{{ form.errors.discount_type }}</p>
 </div>
 </div>

 <!-- Channel Mix (multi-select chips) -->
 <div>
 <label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5">Channel Mix</label>
 <div class="flex flex-wrap gap-1.5">
 <button
 v-for="ch in CHANNEL_TAGS"
 :key="ch.value"
 type="button"
 @click="toggleChannel(ch.value)"
 class="px-2.5 py-1 text-xs font-medium rounded-full ring-1 transition-colors cursor-pointer"
 :class="form.channel_mix.includes(ch.value)
 ? 'bg-blue-600 text-white ring-blue-600'
 : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 ring-slate-300 dark:ring-slate-600 hover:ring-blue-400'"
 >
 {{ ch.label }}
 </button>
 </div>
 </div>

 <!-- Ad Spend Band + Audience -->
 <div class="grid grid-cols-2 gap-3">
 <div>
 <label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Ad Spend</label>
 <select
 v-model="form.ad_spend_band"
 class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white dark:bg-slate-900"
 :class="{ 'border-red-400': form.errors.ad_spend_band }"
 >
 <option v-for="opt in AD_SPEND_BANDS" :key="String(opt.value)" :value="opt.value">{{ opt.label }}</option>
 </select>
 <p v-if="form.errors.ad_spend_band" class="mt-1 text-xs text-red-500">{{ form.errors.ad_spend_band }}</p>
 </div>
 <div>
 <label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Audience</label>
 <select
 v-model="form.audience"
 class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white dark:bg-slate-900"
 :class="{ 'border-red-400': form.errors.audience }"
 >
 <option v-for="opt in AUDIENCES" :key="String(opt.value)" :value="opt.value">{{ opt.label }}</option>
 </select>
 <p v-if="form.errors.audience" class="mt-1 text-xs text-red-500">{{ form.errors.audience }}</p>
 </div>
 </div>

 <!-- Lead announcement days -->
 <div>
 <label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Lead Announcement Days</label>
 <input
 v-model.number="form.lead_announcement_days"
 type="number"
 min="0"
 max="365"
 placeholder="0 for surprise launches"
 class="w-full sm:w-48 px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white dark:bg-slate-900 tabular-nums"
 :class="{ 'border-red-400': form.errors.lead_announcement_days }"
 />
 <p v-if="form.errors.lead_announcement_days" class="mt-1 text-xs text-red-500">{{ form.errors.lead_announcement_days }}</p>
 </div>
 </div>

 <!-- ─── Engine Prediction Panel ─────────────────────────────── -->
 <div class="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50/50 dark:bg-blue-900/20 p-3">
 <div class="flex items-start justify-between gap-3 mb-2">
 <div class="flex items-center gap-1">
 <p class="text-xs font-semibold text-blue-900 dark:text-blue-200 uppercase tracking-wide">Predicted Uplift</p>
 <GlossaryTip
 :definition="'Expected sales lift during the promotion vs. the prior 30-day baseline. The engine writes this number to the promotion record on save; the forecasting pipeline uses it to plan inventory for the campaign window.'"
 aria-term="predicted uplift"
 />
 </div>
 <span
 v-if="prediction"
 class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide"
 :class="LAYER_BADGE_CLASSES[prediction.layer]"
 >
 {{ LAYER_LABELS[prediction.layer] }}
 </span>
 </div>

 <!-- Loading state -->
 <div v-if="predictionLoading" class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
 <span class="inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin" />
 Computing prediction…
 </div>

 <!-- Override active — engine prediction shown as reference -->
 <div v-else-if="overrideActive && prediction" class="space-y-1.5">
 <div class="flex items-baseline gap-2">
 <span class="text-2xl font-bold text-amber-700 dark:text-amber-300 tabular-nums">{{ form.manual_uplift_pct }}%</span>
 <span class="text-xs font-medium text-amber-700 dark:text-amber-300">manual override</span>
 </div>
 <p class="text-xs text-slate-600 dark:text-slate-400">
 Engine suggested <span class="font-semibold tabular-nums">{{ prediction.value }}%</span>
 (range {{ prediction.lower }}–{{ prediction.upper }}%, {{ prediction.basis }})
 </p>
 </div>

 <!-- Normal — showing engine prediction -->
 <div v-else-if="prediction" class="space-y-1.5">
 <div class="flex items-baseline gap-3">
 <span class="text-2xl font-bold text-blue-700 dark:text-blue-300 tabular-nums">{{ prediction.value }}%</span>
 <span class="text-xs text-slate-500 dark:text-slate-400 tabular-nums">
 range {{ prediction.lower }}–{{ prediction.upper }}%
 </span>
 </div>
 <p class="text-xs text-slate-600 dark:text-slate-400">{{ prediction.basis }}</p>
 </div>

 <!-- Error / unreachable -->
 <p v-else class="text-xs text-slate-500 dark:text-slate-400">Engine prediction unavailable.</p>

 <!-- Override toggle -->
 <div class="mt-3 pt-2 border-t border-blue-200 dark:border-blue-800/60 flex items-center justify-between">
 <button
 v-if="!showOverride"
 type="button"
 @click="toggleOverride"
 class="text-xs font-medium text-blue-700 dark:text-blue-300 hover:text-blue-900 dark:hover:text-blue-100 underline cursor-pointer"
 >
 Adjust prediction →
 </button>
 <button
 v-else
 type="button"
 @click="revertToEnginePrediction"
 class="text-xs font-medium text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-slate-100 underline cursor-pointer"
 >
 ← Revert to engine prediction
 </button>
 </div>
 </div>

 <!-- ─── Override fields (collapsed by default) ──────────────── -->
 <div
 v-if="showOverride"
 class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50/40 dark:bg-amber-900/20 p-3 space-y-2.5"
 >
 <p class="text-xs font-semibold text-amber-900 dark:text-amber-200 uppercase tracking-wide">Manual Override</p>
 <div>
 <label class="block text-xs font-medium text-amber-900 dark:text-amber-200 mb-1">Manual Uplift %</label>
 <input
 v-model.number="form.manual_uplift_pct"
 type="number"
 min="0"
 max="500"
 step="0.5"
 placeholder="Override the engine's prediction"
 class="w-full px-3 py-2 text-sm border border-amber-300 dark:border-amber-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500 bg-white dark:bg-slate-900 tabular-nums"
 :class="{ 'border-red-400': form.errors.manual_uplift_pct }"
 />
 <p v-if="form.errors.manual_uplift_pct" class="mt-1 text-xs text-red-500">{{ form.errors.manual_uplift_pct }}</p>
 </div>
 <div>
 <label class="block text-xs font-medium text-amber-900 dark:text-amber-200 mb-1">
 Reason <span class="text-red-500">*</span>
 </label>
 <textarea
 v-model="form.override_reason"
 rows="2"
 maxlength="500"
 placeholder="e.g. 3x usual ad spend; CEO Twitter push; supplier shortage forcing price hike"
 class="w-full px-3 py-2 text-sm border border-amber-300 dark:border-amber-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500 bg-white dark:bg-slate-900"
 :class="{ 'border-red-400': form.errors.override_reason }"
 />
 <p v-if="form.errors.override_reason" class="mt-1 text-xs text-red-500">{{ form.errors.override_reason }}</p>
 <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
 Recorded in the audit log; feeds back into model calibration.
 </p>
 </div>
 </div>

 <!-- Applies To — three-way targeting -->
 <div>
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">{{ t('promotions.affects') }}</label>
 <div class="flex flex-col gap-2">
 <label class="flex items-center gap-2 cursor-pointer">
 <input
 type="radio"
 value="all"
 :checked="targetingMode === 'all'"
 @change="onTargetingChange('all')"
 class="accent-blue-600"
 />
 <span class="text-sm text-slate-700 dark:text-slate-200">{{ t('promotions.affects_all') }}</span>
 </label>
 <label class="flex items-center gap-2 cursor-pointer">
 <input
 type="radio"
 value="categories"
 :checked="targetingMode === 'categories'"
 @change="onTargetingChange('categories')"
 class="accent-blue-600"
 />
 <span class="text-sm text-slate-700 dark:text-slate-200">{{ t('promotions.affects_categories') }}</span>
 </label>
 <label class="flex items-center gap-2 cursor-pointer">
 <input
 type="radio"
 value="sku"
 :checked="targetingMode === 'sku'"
 @change="onTargetingChange('sku')"
 class="accent-blue-600"
 />
 <span class="text-sm text-slate-700 dark:text-slate-200">{{ t('promotions.affects_specific') }}</span>
 </label>
 </div>
 </div>

 <!-- Category checkboxes (shown when targeting = categories) -->
 <div v-if="targetingMode === 'categories'">
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">{{ t('promotions.categories') }}</label>
 <div class="flex flex-wrap gap-2">
 <label
 v-for="cat in CATEGORIES"
 :key="cat.value"
 class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer transition-colors"
 :class="form.applies_to_categories.includes(cat.value)
 ? 'border-blue-500 bg-blue-50 text-blue-700'
 : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 '"
 >
 <input
 type="checkbox"
 :checked="form.applies_to_categories.includes(cat.value)"
 @change="toggleCategory(cat.value)"
 class="accent-blue-600"
 />
 <span class="text-sm font-medium">{{ t(cat.labelKey) }}</span>
 </label>
 </div>
 <p v-if="form.errors.applies_to_categories" class="mt-1 text-xs text-red-500">{{ form.errors.applies_to_categories }}</p>
 </div>

 <!-- SKU multi-select (shown when targeting = sku) -->
 <div v-if="targetingMode === 'sku'">
 <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">{{ t('promotions.skus') }}</label>
 <input
 v-model="skuSearch"
 type="text"
 placeholder="Search SKUs…"
 class="w-full px-3 py-2 mb-2 text-sm border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
 />
 <div class="max-h-36 overflow-y-auto border border-slate-200 dark:border-slate-700 rounded-lg divide-y divide-slate-100 dark:divide-slate-800">
 <label
 v-for="sku in filteredSkus"
 :key="sku.id"
 class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800 dark:bg-slate-800 cursor-pointer"
 >
 <input
 type="checkbox"
 :checked="form.sku_ids.includes(sku.id)"
 @change="toggleSku(sku.id)"
 class="accent-blue-600"
 />
 <span class="font-mono text-xs text-slate-400 dark:text-slate-500">{{ sku.sku_code }}</span>
 <span class="text-sm text-slate-700 dark:text-slate-200">{{ sku.name }}</span>
 </label>
 <div v-if="filteredSkus.length === 0" class="px-3 py-4 text-center text-xs text-slate-400 dark:text-slate-500">
 No SKUs match your search.
 </div>
 </div>
 </div>

 <!-- Actions -->
 <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
 <button
 type="button"
 @click="closeDialog"
 class="px-4 py-2 text-sm text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-slate-100 hover:bg-slate-100 dark:hover:bg-slate-800 dark:bg-slate-800 rounded-lg transition-colors cursor-pointer"
 >
 {{ t('promotions.cancel') }}
 </button>
 <button
 type="submit"
 :disabled="form.processing"
 class="px-4 py-2 text-sm font-medium bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg transition-colors cursor-pointer"
 >
 {{ t('promotions.save') }}
 </button>
 </div>
 </form>
 </div>
 </div>
 </Teleport>
</template>
