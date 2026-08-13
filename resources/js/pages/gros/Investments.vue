<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import AskAi from '@/components/gros/AskAi.vue';
import InvestmentDetailModal from '@/components/gros/InvestmentDetailModal.vue';
import InvestmentModal from '@/components/gros/InvestmentModal.vue';
import LotModal from '@/components/gros/LotModal.vue';
import PortfolioChart from '@/components/gros/PortfolioChart.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

interface Lot {
    id: number;
    type: string;
    units: number;
    price: number;
    date: string;
    note: string | null;
}
interface Investment {
    id: number;
    ticker: string;
    name: string;
    kind: string;
    quote_symbol: string | null;
    quote_source: string;
    units: number;
    buy_price: number;
    current_price: number;
    last_price_at: string | null;
    color: string;
    value: number;
    cost: number;
    gain: number;
    realized: number;
    lots: Lot[];
}

const props = defineProps<{
    investments: Investment[];
    totals: { value: number; cost: number; gain: number; pct: number };
}>();

const { eur, num, fmtUnits, grad, primary, primarySoft, kindLabel, hexToRgba } = useGros();

const showNew = ref(false);
const editInvestment = ref<Investment | null>(null);
const detailId = ref<number | null>(null);
const lotModal = ref<{ investmentId: number; ticker: string; presetType: 'buy' | 'sell'; autoPrice: boolean } | null>(null);
const refreshing = ref(false);

// história portfólia (lazy)
interface HistPoint {
    ym: string;
    label: string;
    value: number;
    invested: number;
}
interface HistHolding {
    ticker: string;
    name: string;
    color: string;
    value: number;
    gain: number;
    pct: number;
}
const history = ref<{ series: HistPoint[]; current: { value: number; invested: number; gain: number; pct: number }; holdings: HistHolding[] } | null>(
    null,
);
const historyLoading = ref(true);

// hlbšia analýza (lazy)
interface Analytics {
    ok: boolean;
    reason?: string;
    value: number;
    cost: number;
    invested: number;
    withdrawn: number;
    unrealized: number;
    realized: number;
    profit_total: number;
    xirr: number | null;
    simple_pct: number;
    years_investing: number;
    first_purchase: string;
    avg_monthly_contribution: number;
    risk:
        | { ok: false; months: number }
        | {
              ok: true;
              months: number;
              twr_cagr: number;
              volatility: number;
              max_drawdown: number;
              drawdown_from: string | null;
              drawdown_to: string | null;
              best_month: number;
              worst_month: number;
              positive_share: number;
              return_per_risk: number | null;
          };
    allocation: {
        by_kind: { kind: string; value: number; pct: number }[];
        positions: number;
        effective_positions: number;
        top_weight: number;
        hhi: number;
    };
    benchmark: { label: string; value: number; xirr: number | null; diff: number; from: string } | null;
    contribution_split: { contributed: number; growth: number; growth_pct: number };
}
const analytics = ref<Analytics | null>(null);
const analyticsLoading = ref(true);

onMounted(async () => {
    try {
        const r = await fetch('/investments/history', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        history.value = await r.json();
    } catch {
        history.value = null;
    } finally {
        historyLoading.value = false;
    }

    try {
        const r = await fetch('/investments/analytics', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        analytics.value = await r.json();
    } catch {
        analytics.value = null;
    } finally {
        analyticsLoading.value = false;
    }
});

const risk = computed(() => {
    const r = analytics.value?.risk;
    return r && r.ok ? r : null;
});

/** Farba pre výnos/stratu. */
function tone(v: number): string {
    return v >= 0 ? '#2ba35a' : '#e8544e';
}

const kindColors: Record<string, string> = { etf: '#4c8dff', stock: '#9775fa', crypto: '#f7931a' };

/** Upozornenie na koncentráciu — jedna pozícia či trieda aktív ťahá všetko. */
const concentrationNote = computed(() => {
    const a = analytics.value;
    if (!a?.ok) return null;
    const top = a.allocation.top_weight;
    const risky = a.allocation.by_kind.find((k) => k.kind === 'crypto');
    if (risky && risky.pct >= 50) return `Krypto tvorí ${num(risky.pct, 1)} % portfólia — kolísavosť aj prepady budú podľa toho.`;
    if (top >= 50) return `Najväčšia pozícia je ${num(top, 1)} % portfólia. Jeden titul rozhoduje o výsledku.`;
    if (a.allocation.effective_positions < 3) return `Efektívne máš len ${num(a.allocation.effective_positions, 1)} pozície — rozloženie je úzke.`;
    return null;
});

const bestWorst = computed(() => {
    const h = history.value?.holdings ?? [];
    if (h.length < 2) return null;
    return { best: h[0], worst: h[h.length - 1] };
});

// detail sa vždy dopočíta z čerstvých props (po Inertia reloade)
const detail = computed(() => props.investments.find((i) => i.id === detailId.value) ?? null);

const portValue = computed(() => props.totals.value || 1);
const allocDonut = computed(() => {
    let acc = 0;
    const stops = props.investments
        .filter((h) => h.value > 0)
        .map((h) => {
            const from = acc;
            const pct = (h.value / portValue.value) * 100;
            acc += pct;
            return `${h.color} ${from.toFixed(2)}% ${acc.toFixed(2)}%`;
        });
    return stops.length ? `conic-gradient(${stops.join(', ')})` : '#f1efe8';
});

const autoCount = computed(() => props.investments.filter((i) => i.quote_source !== 'manual').length);
const freshest = computed(() => {
    const ts = props.investments
        .map((i) => i.last_price_at)
        .filter(Boolean)
        .map((s) => new Date(s as string).getTime());
    return ts.length ? Math.max(...ts) : null;
});
const freshLabel = computed(() => {
    if (!freshest.value) return null;
    const s = Math.floor((Date.now() - freshest.value) / 1000);
    if (s < 90) return 'pred chvíľou';
    if (s < 3600) return `pred ${Math.floor(s / 60)} min`;
    if (s < 86400) return `pred ${Math.floor(s / 3600)} h`;
    return `pred ${Math.floor(s / 86400)} dňami`;
});

function unitsFmt(u: number): string {
    return fmtUnits(u) + ' ks';
}
function refresh() {
    router.post(
        '/investments/refresh',
        {},
        { preserveScroll: true, onStart: () => (refreshing.value = true), onFinish: () => (refreshing.value = false) },
    );
}
function openBuy() {
    if (detail.value)
        lotModal.value = {
            investmentId: detail.value.id,
            ticker: detail.value.ticker,
            presetType: 'buy',
            autoPrice: detail.value.quote_source !== 'manual',
        };
}
function openSell() {
    if (detail.value)
        lotModal.value = {
            investmentId: detail.value.id,
            ticker: detail.value.ticker,
            presetType: 'sell',
            autoPrice: detail.value.quote_source !== 'manual',
        };
}
function openEdit() {
    if (detail.value) {
        editInvestment.value = detail.value;
        detailId.value = null;
    }
}
</script>

<template>
    <Head title="Investície" />
    <GrosLayout title="Investície" subtitle="ETF, akcie a krypto — s aktuálnymi cenami">
        <template #action>
            <button
                type="button"
                style="
                    display: flex;
                    align-items: center;
                    gap: 7px;
                    background: #fff;
                    color: #20212e;
                    font-weight: 700;
                    font-size: 14px;
                    padding: 11px 15px;
                    border-radius: 13px;
                    box-shadow: 0 2px 8px rgba(60, 55, 40, 0.06);
                    white-space: nowrap;
                "
                :style="{ opacity: refreshing ? 0.6 : 1 }"
                :disabled="refreshing"
                @click="refresh"
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
                    :style="{ animation: refreshing ? 'spin 0.8s linear infinite' : 'none' }"
                >
                    <path d="M21 12a9 9 0 1 1-3-6.7L21 8" />
                    <path d="M21 3v5h-5" />
                </svg>
                {{ refreshing ? 'Sťahujem…' : 'Ceny' }}
            </button>
            <AddButton label="Pridať investíciu" @click="showNew = true" />
        </template>

        <div class="gros-rise">
            <div style="display: flex; flex-wrap: wrap; gap: 14px">
                <div
                    style="flex: 1.4; min-width: 280px; border-radius: 20px; padding: 24px 26px; color: #fff"
                    :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }"
                >
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9">Hodnota portfólia</div>
                    <div class="font-display" style="font-weight: 800; font-size: 34px; letter-spacing: -1.2px; margin-top: 6px">
                        {{ eur(totals.value) }}
                    </div>
                    <div style="display: flex; gap: 20px; margin-top: 16px">
                        <div>
                            <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Vklad</div>
                            <div style="font-weight: 700; font-size: 15px; margin-top: 2px">{{ eur(totals.cost) }}</div>
                        </div>
                        <div>
                            <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Zisk</div>
                            <div style="font-weight: 700; font-size: 15px; margin-top: 2px">
                                {{ totals.gain >= 0 ? '+' : '−' }}{{ eur(totals.gain) }} · {{ totals.gain >= 0 ? '+' : '−'
                                }}{{ num(Math.abs(totals.pct), 1) }} %
                            </div>
                        </div>
                    </div>
                    <div v-if="autoCount && freshLabel" style="font-size: 11.5px; opacity: 0.8; font-weight: 600; margin-top: 14px">
                        Ceny aktualizované {{ freshLabel }}
                    </div>
                </div>
                <div
                    style="
                        flex: 1;
                        min-width: 220px;
                        background: #fff;
                        border-radius: 20px;
                        padding: 22px;
                        box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05);
                        display: flex;
                        align-items: center;
                        gap: 20px;
                    "
                >
                    <div
                        style="position: relative; width: 120px; height: 120px; flex-shrink: 0; border-radius: 50%"
                        :style="{ background: allocDonut }"
                    >
                        <div
                            class="font-display"
                            style="
                                position: absolute;
                                inset: 22px;
                                background: #fff;
                                border-radius: 50%;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-weight: 800;
                                font-size: 13px;
                                text-align: center;
                                line-height: 1.2;
                            "
                        >
                            {{ investments.length }}<br />pozície
                        </div>
                    </div>
                    <div style="flex: 1; display: flex; flex-direction: column; gap: 8px">
                        <div
                            v-for="h in investments"
                            :key="h.id"
                            style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600"
                        >
                            <span style="width: 9px; height: 9px; border-radius: 3px" :style="{ background: h.color }"></span
                            ><span style="flex: 1">{{ h.ticker }}</span
                            ><span style="color: #9a9cab">{{ num((h.value / portValue) * 100) }}%</span>
                        </div>
                        <div v-if="!investments.length" style="color: #b0b2bd; font-weight: 600; font-size: 12.5px">Žiadne pozície.</div>
                    </div>
                </div>
            </div>

            <!-- Vývoj portfólia v čase -->
            <div
                v-if="historyLoading || (history && history.series.length)"
                style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-top: 14px"
            >
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Vývoj portfólia</div>
                    <div v-if="history" style="display: flex; align-items: center; gap: 18px">
                        <div>
                            <span style="font-size: 12px; color: #9a9cab; font-weight: 600">Vklad </span
                            ><span style="font-weight: 800; font-size: 14px">{{ eur(history.current.invested) }}</span>
                        </div>
                        <div>
                            <span style="font-size: 12px; color: #9a9cab; font-weight: 600">Zisk </span>
                            <span style="font-weight: 800; font-size: 14px" :style="{ color: history.current.gain >= 0 ? '#2ba35a' : '#e8544e' }"
                                >{{ history.current.gain >= 0 ? '+' : '−' }}{{ eur(history.current.gain) }} ·
                                {{ history.current.gain >= 0 ? '+' : '−' }}{{ num(Math.abs(history.current.pct), 1) }} %</span
                            >
                        </div>
                    </div>
                </div>

                <div v-if="historyLoading" style="padding: 40px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px">
                    Počítam vývoj z tvojich nákupov a historických cien…
                </div>
                <div v-else-if="history" style="margin-top: 14px">
                    <PortfolioChart :series="history.series" />
                    <div v-if="bestWorst" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px">
                        <div
                            style="
                                flex: 1;
                                min-width: 200px;
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                background: #e6f7ec;
                                border-radius: 13px;
                                padding: 12px 14px;
                            "
                        >
                            <span style="font-size: 11px; font-weight: 700; color: #2ba35a; text-transform: uppercase; letter-spacing: 0.3px"
                                >Najlepšia</span
                            >
                            <span style="font-weight: 700; font-size: 13.5px">{{ bestWorst.best.ticker }}</span>
                            <span style="margin-left: auto; font-weight: 800; font-size: 13.5px; color: #2ba35a"
                                >+{{ num(Math.abs(bestWorst.best.pct), 1) }} %</span
                            >
                        </div>
                        <div
                            style="
                                flex: 1;
                                min-width: 200px;
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                background: #fdeaea;
                                border-radius: 13px;
                                padding: 12px 14px;
                            "
                        >
                            <span style="font-size: 11px; font-weight: 700; color: #e8544e; text-transform: uppercase; letter-spacing: 0.3px"
                                >Najhoršia</span
                            >
                            <span style="font-weight: 700; font-size: 13.5px">{{ bestWorst.worst.ticker }}</span>
                            <span
                                style="margin-left: auto; font-weight: 800; font-size: 13.5px"
                                :style="{ color: bestWorst.worst.pct >= 0 ? '#2ba35a' : '#e8544e' }"
                                >{{ bestWorst.worst.pct >= 0 ? '+' : '−' }}{{ num(Math.abs(bestWorst.worst.pct), 1) }} %</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analýza portfólia -->
            <div
                v-if="analyticsLoading || analytics?.ok"
                style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-top: 14px"
            >
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Analýza</div>
                    <div v-if="analytics?.ok" style="font-size: 12px; color: #9a9cab; font-weight: 600">
                        investuješ {{ num(analytics.years_investing, 1) }} r. · priemerne {{ eur(analytics.avg_monthly_contribution) }}/mes.
                    </div>
                </div>

                <div v-if="analyticsLoading" style="padding: 40px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px">
                    Počítam ukazovatele…
                </div>

                <template v-else-if="analytics?.ok">
                    <!-- kľúčové ukazovatele -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 16px">
                        <div style="background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                            <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Ročný výnos (p.a.)</div>
                            <div
                                class="font-display"
                                style="font-weight: 800; font-size: 24px; margin-top: 4px"
                                :style="{ color: tone(analytics.xirr ?? 0) }"
                            >
                                {{ analytics.xirr === null ? '—' : (analytics.xirr >= 0 ? '+' : '−') + num(Math.abs(analytics.xirr), 1) + ' %' }}
                            </div>
                            <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px">
                                XIRR — zohľadňuje, kedy si vkladal. Hrubý zisk je {{ num(analytics.simple_pct, 1) }} %.
                            </div>
                        </div>
                        <div v-if="risk" style="background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                            <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Kolísavosť</div>
                            <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 4px">{{ num(risk.volatility, 1) }} %</div>
                            <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px">
                                {{ num(risk.positive_share) }} % mesiacov v pluse (z {{ risk.months }})
                            </div>
                        </div>
                        <div v-if="risk" style="background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                            <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Najhorší prepad</div>
                            <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 4px; color: #e8544e">
                                {{ num(risk.max_drawdown, 1) }} %
                            </div>
                            <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px">
                                <template v-if="risk.drawdown_from">{{ risk.drawdown_from }} → {{ risk.drawdown_to }}</template>
                                <template v-else>od vrcholu ku dnu</template>
                            </div>
                        </div>
                        <div style="background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                            <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Rozloženie</div>
                            <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 4px">
                                {{ num(analytics.allocation.effective_positions, 1) }}
                            </div>
                            <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px">
                                efektívnych pozícií z {{ analytics.allocation.positions }} · najväčšia {{ num(analytics.allocation.top_weight, 1) }} %
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="concentrationNote"
                        style="
                            display: flex;
                            align-items: center;
                            gap: 9px;
                            background: #fff6e5;
                            border-radius: 13px;
                            padding: 12px 15px;
                            margin-top: 10px;
                        "
                    >
                        <span style="font-size: 15px">⚠️</span>
                        <span style="font-size: 12.5px; font-weight: 600; color: #8a6516; line-height: 1.5">{{ concentrationNote }}</span>
                    </div>

                    <!-- rozloženie podľa druhu -->
                    <div style="margin-top: 20px">
                        <div style="font-size: 12.5px; font-weight: 700; color: #8a8c9a; margin-bottom: 9px">Podľa druhu aktíva</div>
                        <div style="display: flex; gap: 2px; height: 12px; border-radius: 6px; overflow: hidden">
                            <div
                                v-for="k in analytics.allocation.by_kind"
                                :key="k.kind"
                                :style="{ width: k.pct + '%', background: kindColors[k.kind] ?? '#b0b2bd' }"
                                :title="`${kindLabel(k.kind)} — ${num(k.pct, 1)} %`"
                            ></div>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 16px; margin-top: 10px">
                            <span
                                v-for="k in analytics.allocation.by_kind"
                                :key="k.kind"
                                style="display: flex; align-items: center; gap: 7px; font-size: 12.5px; font-weight: 600"
                            >
                                <span
                                    style="width: 9px; height: 9px; border-radius: 3px"
                                    :style="{ background: kindColors[k.kind] ?? '#b0b2bd' }"
                                ></span>
                                {{ kindLabel(k.kind) }}
                                <span style="color: #9a9cab">{{ num(k.pct, 1) }} % · {{ eur(k.value) }}</span>
                            </span>
                        </div>
                    </div>

                    <!-- porovnanie s benchmarkom -->
                    <div v-if="analytics.benchmark" style="margin-top: 20px; background: #f7f6f2; border-radius: 14px; padding: 16px 18px">
                        <div style="font-size: 12.5px; font-weight: 700; color: #20212e">Čo keby tie isté peniaze išli do svetového ETF</div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px; line-height: 1.55">
                            Rovnaké sumy, v rovnaké dni, do {{ analytics.benchmark.label }} od {{ analytics.benchmark.from }}.
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 22px; margin-top: 14px">
                            <div>
                                <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Tvoje portfólio</div>
                                <div class="font-display" style="font-weight: 800; font-size: 20px; margin-top: 3px">{{ eur(analytics.value) }}</div>
                                <div
                                    v-if="analytics.xirr !== null"
                                    style="font-size: 11.5px; font-weight: 700; margin-top: 2px"
                                    :style="{ color: tone(analytics.xirr) }"
                                >
                                    {{ num(analytics.xirr, 1) }} % p.a.
                                </div>
                            </div>
                            <div>
                                <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Svetové ETF</div>
                                <div class="font-display" style="font-weight: 800; font-size: 20px; margin-top: 3px; color: #6a6c7a">
                                    {{ eur(analytics.benchmark.value) }}
                                </div>
                                <div
                                    v-if="analytics.benchmark.xirr !== null"
                                    style="font-size: 11.5px; font-weight: 700; color: #9a9cab; margin-top: 2px"
                                >
                                    {{ num(analytics.benchmark.xirr, 1) }} % p.a.
                                </div>
                            </div>
                            <div>
                                <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Rozdiel</div>
                                <div
                                    class="font-display"
                                    style="font-weight: 800; font-size: 20px; margin-top: 3px"
                                    :style="{ color: tone(analytics.benchmark.diff) }"
                                >
                                    {{ analytics.benchmark.diff >= 0 ? '+' : '−' }}{{ eur(analytics.benchmark.diff) }}
                                </div>
                                <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 2px">
                                    {{ analytics.benchmark.diff >= 0 ? 'v tvoj prospech' : 'ETF by bolo lepšie' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- vklady vs trh + realizovaný zisk -->
                    <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px">
                        <div style="flex: 1; min-width: 200px; background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                            <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Koľko spravil trh</div>
                            <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 4px">
                                <span
                                    class="font-display"
                                    style="font-weight: 800; font-size: 22px"
                                    :style="{ color: tone(analytics.contribution_split.growth) }"
                                >
                                    {{ analytics.contribution_split.growth >= 0 ? '+' : '−' }}{{ eur(analytics.contribution_split.growth) }}
                                </span>
                                <span style="font-size: 12px; font-weight: 700; color: #9a9cab"
                                    >{{ num(analytics.contribution_split.growth_pct, 1) }} % hodnoty</span
                                >
                            </div>
                            <div style="display: flex; gap: 2px; height: 8px; border-radius: 4px; overflow: hidden; margin-top: 10px">
                                <div :style="{ width: 100 - analytics.contribution_split.growth_pct + '%', background: '#b8b6ac' }"></div>
                                <div :style="{ width: analytics.contribution_split.growth_pct + '%', background: primary }"></div>
                            </div>
                            <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 7px">
                                vložil si {{ eur(analytics.contribution_split.contributed) }}
                            </div>
                        </div>
                        <div style="flex: 1; min-width: 200px; background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                            <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Celkový zisk vrátane predajov</div>
                            <div
                                class="font-display"
                                style="font-weight: 800; font-size: 22px; margin-top: 4px"
                                :style="{ color: tone(analytics.profit_total) }"
                            >
                                {{ analytics.profit_total >= 0 ? '+' : '−' }}{{ eur(analytics.profit_total) }}
                            </div>
                            <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 6px; line-height: 1.55">
                                nerealizovaný {{ eur(analytics.unrealized) }} · realizovaný {{ eur(analytics.realized) }}<br />
                                vložené {{ eur(analytics.invested)
                                }}<span v-if="analytics.withdrawn > 0"> · vybraté {{ eur(analytics.withdrawn) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- prechod na dôchodkovú projekciu -->
                    <Link
                        href="/retirement"
                        style="display: flex; align-items: center; gap: 12px; border-radius: 14px; padding: 15px 18px; margin-top: 12px; color: #fff"
                        :style="{ background: grad, boxShadow: `0 8px 20px ${primarySoft}` }"
                    >
                        <span style="font-size: 20px">🏖️</span>
                        <span style="flex: 1">
                            <span style="display: block; font-weight: 800; font-size: 14px">Kam ťa to dotiahne do dôchodku?</span>
                            <span style="display: block; font-size: 12px; font-weight: 600; opacity: 0.9; margin-top: 2px">
                                Projekcia na historických dátach — s infláciou, pásmami pravdepodobnosti a mesačnou rentou
                            </span>
                        </span>
                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </Link>
                </template>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 22px 2px 12px">
                <div class="font-display" style="font-weight: 700; font-size: 17px">Tvoje pozície</div>
                <button
                    type="button"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 6px;
                        color: #fff;
                        font-weight: 700;
                        font-size: 13px;
                        padding: 9px 14px;
                        border-radius: 11px;
                        white-space: nowrap;
                    "
                    :style="{ background: primary, boxShadow: `0 6px 14px ${primarySoft}` }"
                    @click="showNew = true"
                >
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    Pridať investíciu
                </button>
            </div>

            <div style="background: #fff; border-radius: 20px; padding: 10px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                <div
                    v-for="h in investments"
                    :key="h.id"
                    style="display: flex; align-items: center; gap: 13px; padding: 14px; border-radius: 14px; cursor: pointer"
                    @click="detailId = h.id"
                >
                    <span
                        style="
                            width: 44px;
                            height: 44px;
                            border-radius: 13px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            font-weight: 800;
                            font-size: 14px;
                            flex-shrink: 0;
                        "
                        :style="{ background: hexToRgba(h.color, 0.14), color: h.color }"
                        >{{ h.ticker.slice(0, 4) }}</span
                    >
                    <div style="flex: 1; min-width: 0">
                        <div style="font-size: 14px; font-weight: 700">{{ h.name }}</div>
                        <div style="font-size: 12px; color: #9a9cab; font-weight: 500">
                            {{ kindLabel(h.kind) }} · {{ unitsFmt(Number(h.units)) }} · Ø {{ eur(Number(h.buy_price))
                            }}<span v-if="h.quote_source !== 'manual'"> · online</span>
                        </div>
                    </div>
                    <div style="text-align: right">
                        <div style="font-size: 15px; font-weight: 800">{{ eur(h.value) }}</div>
                        <div style="font-size: 12.5px; font-weight: 700" :style="{ color: h.gain >= 0 ? '#2ba35a' : '#e8544e' }">
                            {{ h.gain >= 0 ? '+' : '−' }}{{ eur(h.gain) }} · {{ h.gain >= 0 ? '+' : '−'
                            }}{{ num(h.cost > 0 ? Math.abs((h.gain / h.cost) * 100) : 0, 1) }}%
                        </div>
                    </div>
                </div>
                <div v-if="!investments.length" style="padding: 40px 16px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px">
                    Zatiaľ žiadne investície
                </div>
            </div>

            <AskAi
                style="margin-top: 14px"
                :questions="['Ako som na tom s investíciami?', 'Aké je moje portfólio rizikové?', 'Koľko som reálne zarobil na investíciách?']"
            />
        </div>

        <InvestmentModal v-if="showNew" @close="showNew = false" />
        <InvestmentModal v-if="editInvestment" :investment="editInvestment" @close="editInvestment = null" />
        <InvestmentDetailModal v-if="detail" :investment="detail" @close="detailId = null" @buy="openBuy" @sell="openSell" @edit="openEdit" />
        <LotModal
            v-if="lotModal"
            :investment-id="lotModal.investmentId"
            :ticker="lotModal.ticker"
            :preset-type="lotModal.presetType"
            :auto-price="lotModal.autoPrice"
            @close="lotModal = null"
        />
    </GrosLayout>
</template>

<style scoped>
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
