<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import ExclusionModal from '@/components/gros/ExclusionModal.vue';
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
    account: AccountRef | null;
    to_account: AccountRef | null;
}

const props = defineProps<{ transactions: Txn[]; accounts: { id: number; name: string }[] }>();

const { eur, primary, catName, catColor, catGlyph, hexToRgba, formatDate } = useGros();

const filter = ref<'all' | 'income' | 'expense' | 'transfer'>('all');
const period = ref<'all' | '7' | '30' | 'year'>('all');

function transferPath(t: Txn): string {
    return `${t.account?.name ?? '—'} → ${t.to_account?.name ?? '—'}`;
}

const showModal = ref(false);
const editTxn = ref<Txn | null>(null);

function cutoff(): string | null {
    if (period.value === 'all') return null;
    const today = new Date();
    if (period.value === 'year') return `${today.getFullYear()}-01-01`;
    const days = period.value === '7' ? 7 : 30;
    const c = new Date(today.getTime() - days * 86400000);
    return c.toISOString().slice(0, 10);
}

const filtered = computed(() => {
    const cut = cutoff();
    return props.transactions
        .filter((t) => (filter.value === 'all' ? true : t.type === filter.value))
        .filter((t) => !cut || t.date.slice(0, 10) >= cut);
});

// Súčty ignorujú vylúčené transakcie — rovnako ako analýzy a rozpočty
const counted = computed(() => filtered.value.filter((t) => !t.excluded_from_analytics));
const inSum = computed(() => counted.value.filter((t) => t.type === 'income').reduce((s, t) => s + Number(t.amount), 0));
const outSum = computed(() => counted.value.filter((t) => t.type === 'expense').reduce((s, t) => s + Number(t.amount), 0));
const excludedCount = computed(() => filtered.value.length - counted.value.length);

const excludeTxn = ref<Txn | null>(null);

/** Zaškrtnutie „vylúčiť" otvorí okienko s dôvodom; odškrtnutie vráti transakciu do analýzy hneď. */
function toggleExclusion(t: Txn) {
    if (t.excluded_from_analytics) {
        router.patch(`/transactions/${t.id}/exclusion`, { excluded_from_analytics: false }, { preserveScroll: true });
    } else {
        excludeTxn.value = t;
    }
}

const periodLabel = computed(() => ({ all: 'celé obdobie', '7': 'posledných 7 dní', '30': 'posledných 30 dní', year: 'tento rok' })[period.value]);

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
function periodStyle(v: string) {
    const active = period.value === v;
    return {
        padding: '7px 13px',
        borderRadius: '10px',
        fontSize: '12.5px',
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
    const head = ['Dátum', 'Typ', 'Kategória', 'Poznámka', 'Účet', 'Suma (EUR)'];
    const lines = [head.join(';')];
    filtered.value.forEach((t) => {
        lines.push(
            [
                formatDate(t.date),
                t.type === 'income' ? 'Príjem' : t.type === 'transfer' ? 'Prevod' : 'Výdavok',
                t.type === 'transfer' ? '' : catName(t.category_id),
                t.note ?? '',
                t.type === 'transfer' ? transferPath(t) : (t.account?.name ?? ''),
                (t.type === 'expense' ? '-' : '') + Number(t.amount).toFixed(2).replace('.', ','),
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
    a.download = 'gros-transakcie.csv';
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

            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap">
                <span style="font-size: 12.5px; font-weight: 700; color: #9a9cab; margin-right: 2px">Obdobie</span>
                <button type="button" :style="periodStyle('all')" @click="period = 'all'">Celé</button>
                <button type="button" :style="periodStyle('7')" @click="period = '7'">7 dní</button>
                <button type="button" :style="periodStyle('30')" @click="period = '30'">30 dní</button>
                <button type="button" :style="periodStyle('year')" @click="period = 'year'">Tento rok</button>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 4px 12px; flex-wrap: wrap">
                <div style="font-size: 13px; font-weight: 600; color: #6a6c7a">
                    {{ filtered.length }} transakcií · {{ periodLabel }}
                    <span v-if="excludedCount" style="color: #a06a1e">· {{ excludedCount }} mimo analýzy</span>
                </div>
                <div style="display: flex; align-items: center; gap: 16px">
                    <span style="font-size: 13px; font-weight: 700; color: #2ba35a">+ {{ eur(inSum) }}</span>
                    <span style="font-size: 13px; font-weight: 700; color: #e8544e">− {{ eur(outSum) }}</span>
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
                                <TxnTags :source="t.source" :excluded="t.excluded_from_analytics" :reason="t.exclusion_reason" />
                            </div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 500">
                                {{ catName(t.category_id) }} · {{ t.account?.name ?? '—' }} · {{ formatDate(t.date) }}
                            </div>
                            <div
                                v-if="t.excluded_from_analytics && t.exclusion_reason"
                                style="font-size: 11.5px; color: #a06a1e; font-weight: 600; margin-top: 2px"
                            >
                                Dôvod: {{ t.exclusion_reason }}
                            </div>
                        </div>
                        <div
                            style="font-size: 15px; font-weight: 800; white-space: nowrap"
                            :style="{ color: t.type === 'income' ? '#2ba35a' : '#e8544e' }"
                        >
                            {{ t.type === 'income' ? '+ ' : '− ' }}{{ eur(Number(t.amount)) }}
                        </div>
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
                <div v-if="!filtered.length" style="padding: 40px 16px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px">
                    Žiadne transakcie v tomto období
                </div>
            </div>
        </div>

        <TransactionModal v-if="showModal" :accounts="accounts" :transaction="editTxn" @close="showModal = false" />
        <ExclusionModal v-if="excludeTxn" :transaction-id="excludeTxn.id" :reason="excludeTxn.exclusion_reason" @close="excludeTxn = null" />
    </GrosLayout>
</template>
