<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import AskAi from '@/components/gros/AskAi.vue';
import ExclusionModal from '@/components/gros/ExclusionModal.vue';
import PeriodSelector from '@/components/gros/PeriodSelector.vue';
import RefundModal from '@/components/gros/RefundModal.vue';
import TransactionModal from '@/components/gros/TransactionModal.vue';
import TxnTags from '@/components/gros/TxnTags.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface AccountRef {
    id: number;
    name: string;
    color: string;
}
interface RefundRow {
    id: number;
    amount: number | string;
    date: string;
    note: string | null;
    account_id: number;
}
interface Txn {
    id: number;
    type: string;
    category_id: number | null;
    amount: number | string;
    account_id: number;
    to_account_id: number | null;
    date: string;
    note: string | null;
    excluded_from_analytics: boolean;
    exclusion_reason: string | null;
    source: string | null;
    refund_for_id: number | null;
    refunded_amount: number | string;
    net_amount: number;
    refunds?: RefundRow[];
    account: AccountRef | null;
    to_account: AccountRef | null;
}

const props = defineProps<{
    transactions: Txn[];
    accounts: { id: number; name: string }[];
    period: { key: string; ref: string | null; from: string | null; to: string | null; label: string };
    dataRange: { min: string | null; max: string | null };
}>();

const { eur, primary, categoryById, catName, catColor, catGlyph, hexToRgba, formatDate } = useGros();

const filter = ref<'all' | 'income' | 'expense' | 'transfer'>('all');
const query = ref('');

function transferPath(t: Txn): string {
    return `${t.account?.name ?? '—'} → ${t.to_account?.name ?? '—'}`;
}

const showModal = ref(false);
const editTxn = ref<Txn | null>(null);

// ── Vyhľadávanie ────────────────────────────────────────────────────────
/** Bez diakritiky a malými — „restauracia" nájde „Reštaurácia". */
function normalize(s: string): string {
    return s
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

function typeLabel(t: Txn): string {
    if (t.refund_for_id) return 'vrátenie refundácia';
    return t.type === 'income' ? 'príjem' : t.type === 'transfer' ? 'prevod' : 'výdavok';
}

/** Všetko, v čom sa dá transakciu nájsť, zlepené do jedného reťazca. */
function haystack(t: Txn): string {
    const category = categoryById(t.category_id);
    const group = category?.parent_id ? catName(category.parent_id) : '';
    const amount = Number(t.amount);

    return normalize(
        [
            t.note ?? '',
            t.type === 'transfer' ? transferPath(t) : (category?.name ?? ''),
            group,
            t.account?.name ?? '',
            t.to_account?.name ?? '',
            typeLabel(t),
            t.exclusion_reason ?? '',
            (t.refunds ?? []).map((r) => r.note ?? '').join(' '),
            t.date,
            formatDate(t.date),
            amount.toFixed(2),
            amount.toFixed(2).replace('.', ','),
            String(amount),
        ].join(' '),
    );
}

/** Predpočítané reťazce — hľadá sa pri každom písmene, nech to netrhá. */
const haystacks = computed(() => {
    const map = new Map<number, string>();
    for (const t of props.transactions) map.set(t.id, haystack(t));
    return map;
});

/**
 * Jeden výraz z dopytu. Okrem textu rozumie aj sumám: `>100`, `<50`,
 * `100-200` a `=45`.
 */
function matchesTerm(t: Txn, term: string): boolean {
    const amount = Number(t.amount);
    const netAmount = net(t);

    // „2026-05" je dátum, nie rozsah súm — inak by pomlčka zmiatla výpočet
    if (/^\d{4}-\d{1,2}(-\d{1,2})?$/.test(term)) {
        return (haystacks.value.get(t.id) ?? '').includes(normalize(term));
    }

    const range = term.match(/^(\d+(?:[.,]\d+)?)-(\d+(?:[.,]\d+)?)$/);
    if (range) {
        const lo = Number(range[1].replace(',', '.'));
        const hi = Number(range[2].replace(',', '.'));
        return (amount >= lo && amount <= hi) || (netAmount >= lo && netAmount <= hi);
    }

    const compare = term.match(/^([<>]=?|=)(\d+(?:[.,]\d+)?)$/);
    if (compare) {
        const value = Number(compare[2].replace(',', '.'));
        switch (compare[1]) {
            case '>':
                return amount > value;
            case '>=':
                return amount >= value;
            case '<':
                return amount < value;
            case '<=':
                return amount <= value;
            default:
                return Math.abs(amount - value) < 0.005;
        }
    }

    return (haystacks.value.get(t.id) ?? '').includes(normalize(term));
}

const terms = computed(() => query.value.trim().split(/\s+/).filter(Boolean));

// Obdobie orezáva už server; tu ostáva filter typu a hľadanie
const filtered = computed(() =>
    props.transactions.filter((t) => (filter.value === 'all' || t.type === filter.value) && terms.value.every((term) => matchesTerm(t, term))),
);

const searching = computed(() => terms.value.length > 0);

/** Hľadanie beží len nad načítaným obdobím — inde treba prepnúť na celé. */
function searchEverywhere() {
    router.get('/transactions', { period: 'all' }, { preserveScroll: true, preserveState: true, replace: true });
}

/** Suma po odrátaní vrátení — to, čo transakcia reálne stála. */
function net(t: Txn): number {
    return Number(t.net_amount ?? Number(t.amount) - Number(t.refunded_amount ?? 0));
}

// Súčty ignorujú vylúčené transakcie aj vrátenia — rovnako ako analýzy a rozpočty
const counted = computed(() => filtered.value.filter((t) => !t.excluded_from_analytics && !t.refund_for_id));
const inSum = computed(() => counted.value.filter((t) => t.type === 'income').reduce((s, t) => s + Number(t.amount), 0));
const outSum = computed(() => counted.value.filter((t) => t.type === 'expense').reduce((s, t) => s + net(t), 0));
const excludedCount = computed(() => filtered.value.filter((t) => t.excluded_from_analytics).length);
const refundedSum = computed(() => counted.value.reduce((s, t) => s + Number(t.refunded_amount ?? 0), 0));

const excludeTxn = ref<Txn | null>(null);

// Držíme id, nie objekt — po rozpárovaní vrátenia sa modal prekreslí z čerstvých props
const refundTxnId = ref<number | null>(null);
const refundTxn = computed(() => props.transactions.find((t) => t.id === refundTxnId.value) ?? null);

/** Zaškrtnutie „vylúčiť" otvorí okienko s dôvodom; odškrtnutie vráti transakciu do analýzy hneď. */
function toggleExclusion(t: Txn) {
    if (t.excluded_from_analytics) {
        router.patch(`/transactions/${t.id}/exclusion`, { excluded_from_analytics: false }, { preserveScroll: true });
    } else {
        excludeTxn.value = t;
    }
}

function filterStyle(v: string) {
    const active = filter.value === v;
    return {
        padding: '9px 16px',
        borderRadius: '12px',
        fontSize: '13.5px',
        fontWeight: 700,
        color: active ? '#fff' : '#61637a',
        background: active ? primary.value : '#fff',
        boxShadow: active ? 'none' : '0 2px 8px rgba(60,55,40,.05)',
    };
}
function openNew() {
    editTxn.value = null;
    showModal.value = true;
}
function editRow(t: Txn) {
    editTxn.value = t;
    showModal.value = true;
}
function del(t: Txn) {
    router.delete(`/transactions/${t.id}`, { preserveScroll: true });
}

function csvEsc(v: string): string {
    const s = String(v ?? '');
    return /[",;\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}
function exportCsv() {
    const head = ['Dátum', 'Typ', 'Kategória', 'Poznámka', 'Účet', 'Suma (EUR)', 'Vrátené (EUR)', 'Čistá suma (EUR)'];
    const lines = [head.join(';')];
    filtered.value.forEach((t) => {
        lines.push(
            [
                formatDate(t.date),
                t.refund_for_id ? 'Vrátenie' : t.type === 'income' ? 'Príjem' : t.type === 'transfer' ? 'Prevod' : 'Výdavok',
                t.type === 'transfer' ? '' : catName(t.category_id),
                t.note ?? '',
                t.type === 'transfer' ? transferPath(t) : (t.account?.name ?? ''),
                (t.type === 'expense' ? '-' : '') + Number(t.amount).toFixed(2).replace('.', ','),
                Number(t.refunded_amount ?? 0)
                    .toFixed(2)
                    .replace('.', ','),
                (t.type === 'expense' ? '-' : '') + net(t).toFixed(2).replace('.', ','),
            ]
                .map(csvEsc)
                .join(';'),
        );
    });
    const csv = '﻿' + lines.join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    // Export je za zvolené obdobie — nech je to vidieť aj na názve súboru
    a.download = `gros-transakcie-${props.period.label.replace(/[^\p{L}\p{N}]+/gu, '-').toLowerCase()}.csv`;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }, 100);
}
</script>

<template>
    <Head title="Transakcie" />
    <GrosLayout title="Transakcie" subtitle="Príjmy a výdavky">
        <template #action>
            <AddButton label="Pridať transakciu" @click="openNew" />
        </template>

        <div class="gros-rise">
            <!-- Vyhľadávanie naprieč poznámkou, kategóriou, účtom, dátumom aj sumou -->
            <div style="position: relative; margin-bottom: 12px">
                <svg
                    width="17"
                    height="17"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="#b0b2bd"
                    stroke-width="2.3"
                    stroke-linecap="round"
                    style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); pointer-events: none"
                >
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>
                <input
                    v-model="query"
                    type="search"
                    placeholder="Hľadaj podľa poznámky, kategórie, účtu, dátumu alebo sumy…"
                    style="
                        width: 100%;
                        background: #fff;
                        border: none;
                        border-radius: 15px;
                        padding: 14px 44px;
                        font-size: 14.5px;
                        font-weight: 600;
                        color: #20212e;
                        outline: none;
                        box-shadow: 0 2px 8px rgba(60, 55, 40, 0.05);
                    "
                    @keydown.esc="query = ''"
                />
                <button
                    v-if="searching"
                    type="button"
                    style="
                        position: absolute;
                        right: 12px;
                        top: 50%;
                        transform: translateY(-50%);
                        width: 26px;
                        height: 26px;
                        border-radius: 9px;
                        background: #f1efe8;
                        color: #6a6c7a;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    "
                    title="Zrušiť hľadanie"
                    @click="query = ''"
                >
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div
                v-if="searching"
                style="
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    flex-wrap: wrap;
                    background: #eef6ff;
                    border-radius: 13px;
                    padding: 11px 15px;
                    margin-bottom: 12px;
                    font-size: 12.5px;
                    font-weight: 600;
                    color: #2a6ebd;
                "
            >
                <span>
                    <strong>{{ filtered.length }}</strong>
                    {{ filtered.length === 1 ? 'nájdená transakcia' : filtered.length < 5 ? 'nájdené transakcie' : 'nájdených transakcií' }} v období
                    {{ period.label.toLowerCase() }}
                </span>
                <button
                    v-if="period.key !== 'all'"
                    type="button"
                    style="margin-left: auto; font-weight: 800; text-decoration: underline; color: #2a6ebd"
                    @click="searchEverywhere"
                >
                    Hľadať v celom období →
                </button>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; flex-wrap: wrap">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap">
                    <button type="button" :style="filterStyle('all')" @click="filter = 'all'">Všetko</button>
                    <button type="button" :style="filterStyle('income')" @click="filter = 'income'">Príjmy</button>
                    <button type="button" :style="filterStyle('expense')" @click="filter = 'expense'">Výdavky</button>
                    <button type="button" :style="filterStyle('transfer')" @click="filter = 'transfer'">Prevody</button>
                </div>
                <button
                    type="button"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 7px;
                        background: #fff;
                        color: #20212e;
                        font-weight: 700;
                        font-size: 13px;
                        padding: 9px 14px;
                        border-radius: 12px;
                        box-shadow: 0 2px 8px rgba(60, 55, 40, 0.06);
                        white-space: nowrap;
                    "
                    @click="exportCsv"
                >
                    <svg
                        width="15"
                        height="15"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M12 3v12M8 11l4 4 4-4" />
                        <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
                    </svg>
                    Export CSV
                </button>
            </div>

            <div
                style="background: #fff; border-radius: 16px; padding: 12px 14px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-bottom: 16px"
            >
                <PeriodSelector :period="period" :data-range="dataRange" path="/transactions" />
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 4px 12px; flex-wrap: wrap">
                <div style="font-size: 13px; font-weight: 600; color: #6a6c7a">
                    {{ filtered.length }} transakcií · {{ period.label }}
                    <span v-if="excludedCount" style="color: #a06a1e">· {{ excludedCount }} mimo analýzy</span>
                    <span v-if="refundedSum" style="color: #2ba35a">· {{ eur(refundedSum) }} vrátené</span>
                </div>
                <div style="display: flex; align-items: center; gap: 16px">
                    <span style="font-size: 13px; font-weight: 700; color: #2ba35a">+ {{ eur(inSum) }}</span>
                    <span style="font-size: 13px; font-weight: 700; color: #e8544e" :title="refundedSum ? 'Po odrátaní vrátení' : undefined"
                        >− {{ eur(outSum) }}</span
                    >
                </div>
            </div>

            <div style="background: #fff; border-radius: 20px; padding: 8px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                <div
                    v-for="t in filtered"
                    :key="t.id"
                    style="display: flex; align-items: center; gap: 13px; padding: 13px 14px; border-radius: 14px; cursor: pointer"
                    :style="{ opacity: t.excluded_from_analytics ? 0.6 : 1 }"
                    @click="editRow(t)"
                >
                    <!-- Prevod -->
                    <template v-if="t.type === 'transfer'">
                        <span
                            style="
                                width: 42px;
                                height: 42px;
                                border-radius: 13px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                flex-shrink: 0;
                                background: #eef1f6;
                                color: #64748b;
                            "
                        >
                            <svg
                                width="20"
                                height="20"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M7 8h13l-3-3M17 16H4l3 3" />
                            </svg>
                        </span>
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 14.5px; font-weight: 700">{{ t.note || 'Prevod' }}</div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 500">{{ transferPath(t) }} · {{ formatDate(t.date) }}</div>
                        </div>
                        <div style="font-size: 15px; font-weight: 800; white-space: nowrap; color: #64748b">{{ eur(Number(t.amount)) }}</div>
                    </template>
                    <!-- Príjem / výdavok -->
                    <template v-else>
                        <span
                            style="
                                width: 42px;
                                height: 42px;
                                border-radius: 13px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-weight: 800;
                                font-size: 16px;
                                flex-shrink: 0;
                            "
                            :style="{ background: hexToRgba(catColor(t.category_id), 0.14), color: catColor(t.category_id) }"
                            >{{ catGlyph(t.category_id) }}</span
                        >
                        <div style="flex: 1; min-width: 0">
                            <div style="display: flex; align-items: center; gap: 7px; flex-wrap: wrap">
                                <span style="font-size: 14.5px; font-weight: 700">{{ t.note || catName(t.category_id) }}</span>
                                <TxnTags
                                    :source="t.source"
                                    :excluded="t.excluded_from_analytics"
                                    :reason="t.exclusion_reason"
                                    :is-refund="!!t.refund_for_id"
                                    :refunded-amount="Number(t.refunded_amount ?? 0)"
                                    :amount="Number(t.amount)"
                                />
                            </div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 500">
                                {{ t.refund_for_id ? 'Vrátenie peňazí' : catName(t.category_id) }} · {{ t.account?.name ?? '—' }} ·
                                {{ formatDate(t.date) }}
                            </div>
                            <div
                                v-if="Number(t.refunded_amount ?? 0) > 0"
                                style="font-size: 11.5px; color: #2ba35a; font-weight: 600; margin-top: 2px"
                            >
                                Vrátené {{ eur(Number(t.refunded_amount)) }} · reálne stálo {{ eur(net(t)) }}
                            </div>
                            <div
                                v-if="t.excluded_from_analytics && t.exclusion_reason"
                                style="font-size: 11.5px; color: #a06a1e; font-weight: 600; margin-top: 2px"
                            >
                                Dôvod: {{ t.exclusion_reason }}
                            </div>
                        </div>
                        <div style="white-space: nowrap; text-align: right">
                            <div style="font-size: 15px; font-weight: 800" :style="{ color: t.type === 'income' ? '#2ba35a' : '#e8544e' }">
                                {{ t.type === 'income' ? '+ ' : '− ' }}{{ eur(net(t)) }}
                            </div>
                            <div
                                v-if="Number(t.refunded_amount ?? 0) > 0"
                                style="font-size: 11.5px; font-weight: 600; color: #b0b2bd; text-decoration: line-through"
                            >
                                {{ eur(Number(t.amount)) }}
                            </div>
                        </div>
                        <button
                            v-if="t.type === 'expense'"
                            type="button"
                            style="
                                width: 32px;
                                height: 32px;
                                border-radius: 10px;
                                background: transparent;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                flex-shrink: 0;
                            "
                            :style="{ color: Number(t.refunded_amount ?? 0) > 0 ? '#2ba35a' : '#c4c2ba' }"
                            title="Vrátenie peňazí"
                            @click.stop="refundTxnId = t.id"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M3 12a9 9 0 1 0 3-6.7L3 8" />
                                <path d="M3 3v5h5" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            style="
                                width: 32px;
                                height: 32px;
                                border-radius: 10px;
                                background: transparent;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                flex-shrink: 0;
                            "
                            :style="{ color: t.excluded_from_analytics ? '#e0a33d' : '#c4c2ba' }"
                            :title="t.excluded_from_analytics ? 'Vrátiť do analýzy' : 'Vylúčiť z analýzy'"
                            @click.stop="toggleExclusion(t)"
                        >
                            <svg
                                v-if="t.excluded_from_analytics"
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z" />
                                <circle cx="12" cy="12" r="3" />
                                <path d="M3 3l18 18" />
                            </svg>
                            <svg
                                v-else
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                        </button>
                    </template>
                    <button
                        type="button"
                        style="
                            width: 32px;
                            height: 32px;
                            border-radius: 10px;
                            background: transparent;
                            color: #c4c2ba;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            flex-shrink: 0;
                        "
                        title="Zmazať"
                        @click.stop="del(t)"
                    >
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" />
                        </svg>
                    </button>
                </div>
                <div
                    v-if="!filtered.length"
                    style="padding: 40px 16px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px; line-height: 1.6"
                >
                    <template v-if="searching">
                        Pre „{{ query }}" sa nič nenašlo.
                        <template v-if="period.key !== 'all'">
                            <br />
                            <button
                                type="button"
                                style="color: #2a6ebd; font-weight: 700; text-decoration: underline; margin-top: 6px"
                                @click="searchEverywhere"
                            >
                                Skús hľadať v celom období
                            </button>
                        </template>
                    </template>
                    <template v-else>Žiadne transakcie v tomto období</template>
                </div>
            </div>

            <AskAi style="margin-top: 14px" :questions="['Aké boli moje najväčšie výdavky tento mesiac?', 'Míňam viac než zvyčajne?']" />
        </div>

        <TransactionModal v-if="showModal" :accounts="accounts" :transaction="editTxn" @close="showModal = false" />
        <ExclusionModal v-if="excludeTxn" :transaction-id="excludeTxn.id" :reason="excludeTxn.exclusion_reason" @close="excludeTxn = null" />
        <RefundModal v-if="refundTxn" :transaction="refundTxn" :accounts="accounts" :candidates="transactions" @close="refundTxnId = null" />
    </GrosLayout>
</template>
