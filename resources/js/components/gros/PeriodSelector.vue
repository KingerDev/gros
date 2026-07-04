<script setup lang="ts">
import { useGros } from '@/composables/useGros';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface PeriodData {
    key: string;
    ref: string | null;
    from: string | null;
    to: string | null;
    label: string;
}

const props = defineProps<{
    period: PeriodData;
    dataRange: { min: string | null; max: string | null };
    path: string;
}>();

const { primary } = useGros();

const MONTHS = ['Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún', 'Júl', 'August', 'September', 'Október', 'November', 'December'];

function go(params: Record<string, string>) {
    router.get(props.path, params, { preserveScroll: true, preserveState: true, replace: true });
}

// aktuálny mesiac/rok referencie
const curYm = computed(() => props.period.ref ?? new Date().toISOString().slice(0, 7));

// medze
const maxYm = computed(() => {
    const now = new Date().toISOString().slice(0, 7);
    const dmax = props.dataRange.max?.slice(0, 7) ?? now;
    return dmax > now ? dmax : now;
});
const minYm = computed(() => props.dataRange.min?.slice(0, 7) ?? '2000-01');

function stepMonth(delta: number) {
    const [y, m] = curYm.value.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    go({ period: 'month', ref: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}
function stepYear(delta: number) {
    const y = Number(props.period.ref ?? new Date().getFullYear()) + delta;
    go({ period: 'year', ref: String(y) });
}

const canPrevM = computed(() => curYm.value > minYm.value);
const canNextM = computed(() => curYm.value < maxYm.value);
const canPrevY = computed(() => Number(props.period.ref ?? 0) > Number(minYm.value.slice(0, 4)));
const canNextY = computed(() => Number(props.period.ref ?? 9999) < Number(maxYm.value.slice(0, 4)));

const stepperLabel = computed(() => {
    if (props.period.key === 'month') {
        const [y, m] = curYm.value.split('-').map(Number);
        return `${MONTHS[m - 1]} ${y}`;
    }
    return props.period.ref ?? '';
});

// roky s dátami (na priamy výber roka)
const years = computed(() => {
    const min = Number(minYm.value.slice(0, 4));
    const max = Number(maxYm.value.slice(0, 4));
    const out: number[] = [];
    for (let y = max; y >= min; y--) out.push(y);
    return out;
});
const curYear = computed(() => Number(props.period.ref ?? new Date().getFullYear()));

function chip(active: boolean) {
    return {
        padding: '8px 14px',
        borderRadius: '11px',
        fontSize: '13px',
        fontWeight: 700,
        cursor: 'pointer',
        color: active ? '#fff' : '#61637a',
        background: active ? primary.value : '#fff',
        boxShadow: active ? 'none' : '0 2px 8px rgba(60,55,40,.05)',
    };
}
</script>

<template>
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap">
        <button type="button" :style="chip(period.key === 'month')" @click="go({ period: 'month' })">Mesiac</button>
        <button type="button" :style="chip(period.key === 'year')" @click="go({ period: 'year' })">Rok</button>
        <button type="button" :style="chip(period.key === '30d')" @click="go({ period: '30d' })">30 dní</button>
        <button type="button" :style="chip(period.key === 'all')" @click="go({ period: 'all' })">Celé</button>

        <!-- krokovanie -->
        <div
            v-if="period.key === 'month' || period.key === 'year'"
            style="
                display: flex;
                align-items: center;
                gap: 4px;
                background: #fff;
                border-radius: 11px;
                padding: 4px;
                box-shadow: 0 2px 8px rgba(60, 55, 40, 0.05);
                margin-left: 4px;
            "
        >
            <button
                type="button"
                style="width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #61637a"
                :disabled="period.key === 'month' ? !canPrevM : !canPrevY"
                :style="{ opacity: (period.key === 'month' ? canPrevM : canPrevY) ? 1 : 0.3 }"
                @click="period.key === 'month' ? stepMonth(-1) : stepYear(-1)"
            >
                <svg
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.4"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M15 18l-6-6 6-6" />
                </svg>
            </button>
            <span v-if="period.key === 'month'" style="font-size: 13px; font-weight: 700; min-width: 96px; text-align: center; white-space: nowrap">{{
                stepperLabel
            }}</span>
            <select
                v-else
                :value="curYear"
                style="
                    font-size: 13px;
                    font-weight: 700;
                    min-width: 96px;
                    text-align: center;
                    background: transparent;
                    border: none;
                    cursor: pointer;
                    appearance: none;
                    color: inherit;
                "
                @change="go({ period: 'year', ref: ($event.target as HTMLSelectElement).value })"
            >
                <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
            <button
                type="button"
                style="width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #61637a"
                :disabled="period.key === 'month' ? !canNextM : !canNextY"
                :style="{ opacity: (period.key === 'month' ? canNextM : canNextY) ? 1 : 0.3 }"
                @click="period.key === 'month' ? stepMonth(1) : stepYear(1)"
            >
                <svg
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.4"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M9 18l6-6-6-6" />
                </svg>
            </button>
        </div>
    </div>
</template>
