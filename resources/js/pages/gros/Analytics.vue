<script setup lang="ts">
import Card from '@/components/gros/Card.vue';
import CategoryDetailModal from '@/components/gros/CategoryDetailModal.vue';
import DeltaBadge from '@/components/gros/DeltaBadge.vue';
import DonutChart from '@/components/gros/DonutChart.vue';
import MonthlyBars from '@/components/gros/MonthlyBars.vue';
import PeriodSelector from '@/components/gros/PeriodSelector.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface CatRow {
    category_id: number;
    amount: number;
    count: number;
    children: CatRow[];
}
interface Month {
    ym: string;
    label: string;
    income: number;
    expense: number;
    net: number;
}
interface Merchant {
    merchant: string;
    amount: number;
    count: number;
}
interface Insight {
    tone: string;
    text: string;
}
interface FvMonth {
    ym: string;
    label: string;
    fixed: number;
    variable: number;
    share: number;
}
interface PeriodReport {
    title: string;
    prevLabel: string | null;
    income: number;
    expense: number;
    net: number;
    rate: number;
    count: number;
    incomeDeltaPct: number | null;
    expenseDeltaPct: number | null;
    topCategory: { category_id: number; amount: number } | null;
    biggestExpense: { note: string | null; category_id: number | null; amount: number; date: string } | null;
}

const props = defineProps<{
    period: { key: string; ref: string | null; from: string | null; to: string | null; label: string };
    dataRange: { min: string | null; max: string | null };
    periodSummary: { income: number; expense: number; net: number; savingsRate: number; count: number };
    expenseByCategory: CatRow[];
    incomeByCategory: CatRow[];
    monthlySeries: Month[];
    topMerchants: Merchant[];
    insights: Insight[];
    periodReport: PeriodReport | null;
    fixedVsVariable: { series: FvMonth[]; recurringCount: number };
}>();

const { eur, eurS, num, grad, primary, primarySoft, catName, catColor, catGlyph, hexToRgba } = useGros();

const detailCat = ref<{ id: number; name: string } | null>(null);

// Rozbalené skupiny v rozklade podľa kategórie
const expandedCats = ref<number[]>([]);
function toggleCat(id: number) {
    const i = expandedCats.value.indexOf(id);
    if (i >= 0) expandedCats.value.splice(i, 1);
    else expandedCats.value.push(id);
}

const expTotal = computed(() => props.expenseByCategory.reduce((s, c) => s + c.amount, 0) || 1);
const merchantMax = computed(() => Math.max(1, ...props.topMerchants.map((m) => m.amount)));

const trendBars = computed(() =>
    props.monthlySeries.map((m) => ({
        label: m.label,
        bars: [
            { value: m.income, color: '#2ba35a', title: eur(m.income) },
            { value: m.expense, color: '#e8544e', title: eur(m.expense) },
        ],
    })),
);

// Fixné vs voľné — stacked stĺpce
const fvSeries = computed(() => props.fixedVsVariable.series);
const fvMax = computed(() => Math.max(1, ...fvSeries.value.map((m) => m.fixed + m.variable)));
const fvHasData = computed(() => fvSeries.value.some((m) => m.fixed + m.variable > 0));
const fvCurrentShare = computed(() => {
    const withData = fvSeries.value.filter((m) => m.fixed + m.variable > 0);
    return withData.length ? withData[withData.length - 1].share : 0;
});

const toneBg: Record<string, string> = { good: '#e6f7ec', warn: '#fdeaea', info: '#eef6ff' };
const toneColor: Record<string, string> = { good: '#2ba35a', warn: '#c0453f', info: '#2a6ebd' };
</script>

<template>
    <Head title="Analýzy" />
    <GrosLayout title="Analýzy" subtitle="Kam tečú peniaze a ako sa to vyvíja">
        <div class="gros-rise">
            <!-- Obdobie -->
            <div
                style="background: #fff; border-radius: 16px; padding: 12px 14px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-bottom: 14px"
            >
                <PeriodSelector :period="period" :data-range="dataRange" path="/analytics" />
            </div>

            <!-- Postrehy -->
            <div v-if="insights.length" style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px">
                <div
                    v-for="(ins, i) in insights"
                    :key="i"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        padding: 12px 15px;
                        border-radius: 13px;
                        font-size: 13.5px;
                        font-weight: 600;
                    "
                    :style="{ background: toneBg[ins.tone] || '#f5f4ef', color: toneColor[ins.tone] || '#20212e' }"
                >
                    <svg
                        width="17"
                        height="17"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        style="flex-shrink: 0"
                    >
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 8h.01M11 12h1v4h1" />
                    </svg>
                    {{ ins.text }}
                </div>
            </div>

            <!-- Obdobie v kocke -->
            <div v-if="periodReport" style="margin-bottom: 14px">
                <Card :title="periodReport.title">
                    <template #right>
                        <span style="font-size: 12px; font-weight: 600; color: #9a9cab"
                            >{{ periodReport.count }} transakcií<span v-if="periodReport.prevLabel"> · porovnané s {{ periodReport.prevLabel }}</span></span
                        >
                    </template>
                    <div style="display: flex; flex-wrap: wrap; gap: 22px; margin-top: 16px">
                        <div style="flex: 1; min-width: 130px">
                            <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Príjmy</div>
                            <div
                                class="font-display"
                                style="font-weight: 800; font-size: 24px; letter-spacing: -0.7px; margin-top: 5px; color: #2ba35a"
                            >
                                {{ eur(periodReport.income) }}
                            </div>
                            <DeltaBadge :pct="periodReport.incomeDeltaPct" />
                        </div>
                        <div style="flex: 1; min-width: 130px">
                            <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Výdavky</div>
                            <div
                                class="font-display"
                                style="font-weight: 800; font-size: 24px; letter-spacing: -0.7px; margin-top: 5px; color: #e8544e"
                            >
                                {{ eur(periodReport.expense) }}
                            </div>
                            <DeltaBadge :pct="periodReport.expenseDeltaPct" invert />
                        </div>
                        <div style="flex: 1; min-width: 130px">
                            <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Čistý tok</div>
                            <div
                                class="font-display"
                                style="font-weight: 800; font-size: 24px; letter-spacing: -0.7px; margin-top: 5px"
                                :style="{ color: periodReport.net >= 0 ? '#20212e' : '#e8544e' }"
                            >
                                {{ eurS(periodReport.net) }}
                            </div>
                            <div style="font-size: 12px; font-weight: 700; color: #0fa3b1; margin-top: 7px">miera úspor {{ periodReport.rate }} %</div>
                        </div>
                        <div
                            style="
                                flex: 1.4;
                                min-width: 200px;
                                font-size: 13px;
                                font-weight: 600;
                                display: flex;
                                flex-direction: column;
                                gap: 9px;
                                justify-content: center;
                            "
                        >
                            <div v-if="periodReport.topCategory" style="display: flex; align-items: center; gap: 8px">
                                <span
                                    style="width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0"
                                    :style="{ background: catColor(periodReport.topCategory.category_id) }"
                                ></span>
                                <span style="color: #6a6c7a">Najviac šlo na</span>
                                <span style="font-weight: 700">{{ catName(periodReport.topCategory.category_id) }}</span>
                                <span style="font-weight: 800; margin-left: auto">{{ eur(periodReport.topCategory.amount) }}</span>
                            </div>
                            <div v-if="periodReport.biggestExpense" style="display: flex; align-items: center; gap: 8px">
                                <span
                                    style="width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0"
                                    :style="{ background: catColor(periodReport.biggestExpense.category_id) }"
                                ></span>
                                <span style="color: #6a6c7a">Najväčší výdavok</span>
                                <span style="font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{
                                    periodReport.biggestExpense.note || catName(periodReport.biggestExpense.category_id)
                                }}</span>
                                <span style="font-weight: 800; margin-left: auto">{{ eur(periodReport.biggestExpense.amount) }}</span>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Súhrn -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px">
                <div style="border-radius: 20px; padding: 20px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                    <div style="font-size: 12.5px; font-weight: 600; opacity: 0.9">Čistý tok · {{ period.label }}</div>
                    <div class="font-display" style="font-weight: 800; font-size: 27px; letter-spacing: -0.9px; margin-top: 8px">
                        {{ eurS(periodSummary.net) }}
                    </div>
                    <div style="font-size: 12px; font-weight: 600; opacity: 0.9; margin-top: 6px">{{ periodSummary.count }} transakcií</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Príjmy</div>
                    <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px; color: #2ba35a">
                        {{ eur(periodSummary.income) }}
                    </div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Výdavky</div>
                    <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px; color: #e8544e">
                        {{ eur(periodSummary.expense) }}
                    </div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Miera úspor</div>
                    <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px; color: #0fa3b1">
                        {{ periodSummary.savingsRate }} %
                    </div>
                </div>
            </div>

            <!-- Výdavky podľa kategórie + mesačný trend -->
            <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px">
                <div style="flex: 1.1; min-width: 320px">
                    <Card title="Výdavky podľa kategórie">
                        <div v-if="expenseByCategory.length" style="display: flex; flex-wrap: wrap; align-items: center; gap: 24px; margin-top: 18px">
                            <DonutChart
                                :parts="expenseByCategory.map((c) => ({ color: catColor(c.category_id), value: c.amount }))"
                                :size="140"
                                :inset="24"
                            >
                                <div style="font-size: 11px; font-weight: 600; color: #9a9cab">Spolu</div>
                                <div class="font-display" style="font-weight: 800; font-size: 15px">{{ eur(periodSummary.expense) }}</div>
                            </DonutChart>
                            <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 9px">
                                <div v-for="c in expenseByCategory.slice(0, 8)" :key="c.category_id">
                                    <div style="display: flex; align-items: center; gap: 10px">
                                        <button
                                            type="button"
                                            style="
                                                display: flex;
                                                align-items: center;
                                                gap: 10px;
                                                text-align: left;
                                                padding: 4px 2px;
                                                border-radius: 8px;
                                                flex: 1;
                                                min-width: 0;
                                            "
                                            @click="detailCat = { id: c.category_id, name: catName(c.category_id) }"
                                        >
                                            <span
                                                style="width: 11px; height: 11px; border-radius: 4px; flex-shrink: 0"
                                                :style="{ background: catColor(c.category_id) }"
                                            ></span>
                                            <span style="font-size: 13.5px; font-weight: 600; flex: 1">{{ catName(c.category_id) }}</span>
                                            <span style="font-size: 13.5px; font-weight: 700">{{ eur(c.amount) }}</span>
                                            <span style="font-size: 12px; font-weight: 600; color: #9a9cab; width: 36px; text-align: right"
                                                >{{ num((c.amount / expTotal) * 100) }}%</span
                                            >
                                        </button>
                                        <!-- Rozbalenie podkategórií skupiny -->
                                        <button
                                            v-if="c.children.length"
                                            type="button"
                                            style="width: 20px; height: 20px; flex-shrink: 0; color: #b0b2bd; display: flex; align-items: center"
                                            :title="expandedCats.includes(c.category_id) ? 'Zbaliť' : 'Rozbaliť podkategórie'"
                                            @click="toggleCat(c.category_id)"
                                        >
                                            <svg
                                                width="14"
                                                height="14"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2.6"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                :style="{
                                                    transform: expandedCats.includes(c.category_id) ? 'rotate(180deg)' : 'none',
                                                    transition: 'transform .2s',
                                                }"
                                            >
                                                <path d="M6 9l6 6 6-6" />
                                            </svg>
                                        </button>
                                        <span v-else style="width: 20px; flex-shrink: 0"></span>
                                    </div>
                                    <div
                                        v-if="expandedCats.includes(c.category_id)"
                                        style="display: flex; flex-direction: column; gap: 6px; margin: 6px 0 4px 21px"
                                    >
                                        <button
                                            v-for="ch in c.children"
                                            :key="ch.category_id"
                                            type="button"
                                            style="display: flex; align-items: center; gap: 8px; text-align: left; padding: 2px"
                                            @click="detailCat = { id: ch.category_id, name: catName(ch.category_id) }"
                                        >
                                            <span
                                                style="width: 7px; height: 7px; border-radius: 2px; flex-shrink: 0; opacity: 0.6"
                                                :style="{ background: catColor(ch.category_id) }"
                                            ></span>
                                            <span style="font-size: 12.5px; font-weight: 600; color: #6a6c7a; flex: 1">{{
                                                catName(ch.category_id)
                                            }}</span>
                                            <span style="font-size: 12.5px; font-weight: 700; color: #6a6c7a">{{ eur(ch.amount) }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else style="color: #b0b2bd; font-weight: 600; font-size: 14px; padding: 24px 0">Žiadne výdavky v tomto období.</div>
                        <div style="font-size: 11.5px; color: #b0b2bd; font-weight: 600; margin-top: 14px">
                            Sumy sú za celú skupinu vrátane podkategórií — šípkou ju rozbalíš.
                        </div>
                    </Card>
                </div>

                <div style="flex: 1.3; min-width: 340px">
                    <Card title="Vývoj po mesiacoch">
                        <template #right>
                            <div style="display: flex; align-items: center; gap: 12px">
                                <span style="display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600; color: #6a6c7a"
                                    ><span style="width: 9px; height: 9px; border-radius: 3px; background: #2ba35a"></span>Príjmy</span
                                >
                                <span style="display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600; color: #6a6c7a"
                                    ><span style="width: 9px; height: 9px; border-radius: 3px; background: #e8544e"></span>Výdavky</span
                                >
                            </div>
                        </template>
                        <div style="overflow-x: auto; margin-top: 20px">
                            <div style="min-width: 560px">
                                <MonthlyBars :items="trendBars" :height="170" :bar-max="10" rotate-labels />
                            </div>
                        </div>
                    </Card>
                </div>
            </div>

            <!-- Fixné vs voľné výdavky -->
            <div style="margin-top: 14px">
                <Card title="Fixné vs. voľné výdavky · 12 mesiacov">
                    <template #right>
                        <div style="display: flex; align-items: center; gap: 12px">
                            <span style="display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600; color: #6a6c7a"
                                ><span style="width: 9px; height: 9px; border-radius: 3px" :style="{ background: primary }"></span>Fixné</span
                            >
                            <span style="display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600; color: #6a6c7a"
                                ><span style="width: 9px; height: 9px; border-radius: 3px" :style="{ background: hexToRgba(primary, 0.25) }"></span
                                >Voľné</span
                            >
                        </div>
                    </template>
                    <div v-if="fvHasData">
                        <div style="display: flex; flex-wrap: wrap; gap: 22px; align-items: flex-end; margin-top: 16px">
                            <div style="min-width: 150px">
                                <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Fixné tvoria aktuálne</div>
                                <div
                                    class="font-display"
                                    style="font-weight: 800; font-size: 32px; letter-spacing: -1px; margin-top: 4px"
                                    :style="{ color: primary }"
                                >
                                    {{ fvCurrentShare }} %
                                </div>
                                <div style="font-size: 12px; font-weight: 600; color: #9a9cab">z mesačných výdavkov</div>
                            </div>
                            <div style="flex: 1; min-width: 320px; display: flex; align-items: flex-end; gap: 7px; height: 150px">
                                <div
                                    v-for="m in fvSeries"
                                    :key="m.ym"
                                    style="
                                        flex: 1;
                                        display: flex;
                                        flex-direction: column;
                                        align-items: center;
                                        gap: 6px;
                                        height: 100%;
                                        justify-content: flex-end;
                                        min-width: 0;
                                    "
                                >
                                    <div
                                        :title="`${m.label}: fixné ${eur(m.fixed)} · voľné ${eur(m.variable)} (${m.share} % fixné)`"
                                        style="
                                            width: 100%;
                                            max-width: 22px;
                                            display: flex;
                                            flex-direction: column;
                                            justify-content: flex-end;
                                            border-radius: 4px 4px 0 0;
                                            overflow: hidden;
                                        "
                                        :style="{ height: ((m.fixed + m.variable) / fvMax) * 100 + '%' }"
                                    >
                                        <div
                                            :style="{
                                                height: m.fixed + m.variable > 0 ? (m.variable / (m.fixed + m.variable)) * 100 + '%' : '0',
                                                background: hexToRgba(primary, 0.25),
                                            }"
                                        ></div>
                                        <div
                                            :style="{
                                                height: m.fixed + m.variable > 0 ? (m.fixed / (m.fixed + m.variable)) * 100 + '%' : '0',
                                                background: primary,
                                            }"
                                        ></div>
                                    </div>
                                    <div style="font-size: 9.5px; font-weight: 700; color: #9a9cab; white-space: nowrap">{{ m.label }}</div>
                                </div>
                            </div>
                        </div>
                        <div style="font-size: 11.5px; color: #b0b2bd; font-weight: 600; margin-top: 14px">
                            Fixné = opakujúce sa platby (rovnaká poznámka aspoň v 3 mesiacoch) — nájom, energie, predplatné, splátky. Ak podiel
                            fixných rastie, ukrajujú ti stále viac z príjmu.
                        </div>
                    </div>
                    <div v-else style="color: #b0b2bd; font-weight: 600; font-size: 14px; padding: 24px 0">
                        Zatiaľ málo dát — zapisuj výdavky s poznámkou a graf sa naplní.
                    </div>
                </Card>
            </div>

            <!-- Top obchodníci + príjmy podľa kategórie -->
            <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px">
                <div style="flex: 1.3; min-width: 320px">
                    <Card title="Kde míňaš najviac">
                        <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px">
                            <div v-for="(mch, i) in topMerchants" :key="i">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 5px">
                                    <span
                                        style="
                                            font-size: 13.5px;
                                            font-weight: 600;
                                            min-width: 0;
                                            overflow: hidden;
                                            text-overflow: ellipsis;
                                            white-space: nowrap;
                                        "
                                        >{{ mch.merchant }}<span style="color: #b0b2bd; font-weight: 500"> · {{ mch.count }}×</span></span
                                    >
                                    <span style="font-size: 13.5px; font-weight: 800; white-space: nowrap">{{ eur(mch.amount) }}</span>
                                </div>
                                <div style="height: 7px; background: #f1efe8; border-radius: 5px; overflow: hidden">
                                    <div
                                        :style="{
                                            height: '100%',
                                            width: (mch.amount / merchantMax) * 100 + '%',
                                            background: primary,
                                            borderRadius: '5px',
                                        }"
                                    ></div>
                                </div>
                            </div>
                            <div v-if="!topMerchants.length" style="color: #b0b2bd; font-weight: 600; font-size: 14px; padding: 12px 0">
                                Žiadne poznámky v tomto období.
                            </div>
                        </div>
                    </Card>
                </div>

                <div style="flex: 1; min-width: 280px">
                    <Card title="Príjmy podľa kategórie">
                        <div style="display: flex; flex-direction: column; gap: 11px; margin-top: 16px">
                            <button
                                v-for="c in incomeByCategory.slice(0, 8)"
                                :key="c.category_id"
                                type="button"
                                style="display: flex; align-items: center; gap: 11px; text-align: left; padding: 3px 2px; border-radius: 8px"
                                @click="detailCat = { id: c.category_id, name: catName(c.category_id) }"
                            >
                                <span
                                    style="
                                        width: 30px;
                                        height: 30px;
                                        border-radius: 9px;
                                        display: flex;
                                        align-items: center;
                                        justify-content: center;
                                        font-size: 14px;
                                        flex-shrink: 0;
                                    "
                                    :style="{ background: hexToRgba(catColor(c.category_id), 0.16), color: catColor(c.category_id) }"
                                    >{{ catGlyph(c.category_id) }}</span
                                >
                                <span style="font-size: 13.5px; font-weight: 600; flex: 1">{{ catName(c.category_id) }}</span>
                                <span style="font-size: 13.5px; font-weight: 700; color: #2ba35a">{{ eur(c.amount) }}</span>
                            </button>
                            <div v-if="!incomeByCategory.length" style="color: #b0b2bd; font-weight: 600; font-size: 14px; padding: 12px 0">
                                Žiadne príjmy v tomto období.
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </div>

        <CategoryDetailModal v-if="detailCat" :category-id="detailCat.id" :name="detailCat.name" @close="detailCat = null" />
    </GrosLayout>
</template>
