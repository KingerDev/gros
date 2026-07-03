<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import InvestmentDetailModal from '@/components/gros/InvestmentDetailModal.vue';
import InvestmentModal from '@/components/gros/InvestmentModal.vue';
import LotModal from '@/components/gros/LotModal.vue';
import PortfolioChart from '@/components/gros/PortfolioChart.vue';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head, router } from '@inertiajs/vue3';
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
interface HistPoint { ym: string; label: string; value: number; invested: number }
interface HistHolding { ticker: string; name: string; color: string; value: number; gain: number; pct: number }
const history = ref<{ series: HistPoint[]; current: { value: number; invested: number; gain: number; pct: number }; holdings: HistHolding[] } | null>(null);
const historyLoading = ref(true);

onMounted(async () => {
    try {
        const r = await fetch('/investments/history', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        history.value = await r.json();
    } catch {
        history.value = null;
    } finally {
        historyLoading.value = false;
    }
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
    const ts = props.investments.map((i) => i.last_price_at).filter(Boolean).map((s) => new Date(s as string).getTime());
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
    router.post('/investments/refresh', {}, { preserveScroll: true, onStart: () => (refreshing.value = true), onFinish: () => (refreshing.value = false) });
}
function openBuy() {
    if (detail.value) lotModal.value = { investmentId: detail.value.id, ticker: detail.value.ticker, presetType: 'buy', autoPrice: detail.value.quote_source !== 'manual' };
}
function openSell() {
    if (detail.value) lotModal.value = { investmentId: detail.value.id, ticker: detail.value.ticker, presetType: 'sell', autoPrice: detail.value.quote_source !== 'manual' };
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
                style="display: flex; align-items: center; gap: 7px; background: #fff; color: #20212e; font-weight: 700; font-size: 14px; padding: 11px 15px; border-radius: 13px; box-shadow: 0 2px 8px rgba(60, 55, 40, 0.06); white-space: nowrap"
                :style="{ opacity: refreshing ? 0.6 : 1 }"
                :disabled="refreshing"
                @click="refresh"
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" :style="{ animation: refreshing ? 'spin 0.8s linear infinite' : 'none' }"><path d="M21 12a9 9 0 1 1-3-6.7L21 8" /><path d="M21 3v5h-5" /></svg>
                {{ refreshing ? 'Sťahujem…' : 'Ceny' }}
            </button>
            <AddButton label="Pridať investíciu" @click="showNew = true" />
        </template>

        <div class="gros-rise">
            <div style="display: flex; flex-wrap: wrap; gap: 14px">
                <div style="flex: 1.4; min-width: 280px; border-radius: 20px; padding: 24px 26px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9">Hodnota portfólia</div>
                    <div class="font-display" style="font-weight: 800; font-size: 34px; letter-spacing: -1.2px; margin-top: 6px">{{ eur(totals.value) }}</div>
                    <div style="display: flex; gap: 20px; margin-top: 16px">
                        <div><div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Vklad</div><div style="font-weight: 700; font-size: 15px; margin-top: 2px">{{ eur(totals.cost) }}</div></div>
                        <div><div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Zisk</div><div style="font-weight: 700; font-size: 15px; margin-top: 2px">{{ totals.gain >= 0 ? '+' : '−' }}{{ eur(totals.gain) }} · {{ totals.gain >= 0 ? '+' : '−' }}{{ num(Math.abs(totals.pct), 1) }} %</div></div>
                    </div>
                    <div v-if="autoCount && freshLabel" style="font-size: 11.5px; opacity: 0.8; font-weight: 600; margin-top: 14px">Ceny aktualizované {{ freshLabel }}</div>
                </div>
                <div style="flex: 1; min-width: 220px; background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); display: flex; align-items: center; gap: 20px">
                    <div style="position: relative; width: 120px; height: 120px; flex-shrink: 0; border-radius: 50%" :style="{ background: allocDonut }">
                        <div class="font-display" style="position: absolute; inset: 22px; background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; text-align: center; line-height: 1.2">{{ investments.length }}<br />pozície</div>
                    </div>
                    <div style="flex: 1; display: flex; flex-direction: column; gap: 8px">
                        <div v-for="h in investments" :key="h.id" style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600">
                            <span style="width: 9px; height: 9px; border-radius: 3px" :style="{ background: h.color }"></span><span style="flex: 1">{{ h.ticker }}</span><span style="color: #9a9cab">{{ num((h.value / portValue) * 100) }}%</span>
                        </div>
                        <div v-if="!investments.length" style="color: #b0b2bd; font-weight: 600; font-size: 12.5px">Žiadne pozície.</div>
                    </div>
                </div>
            </div>

            <!-- Vývoj portfólia v čase -->
            <div v-if="historyLoading || (history && history.series.length)" style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-top: 14px">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Vývoj portfólia</div>
                    <div v-if="history" style="display: flex; align-items: center; gap: 18px">
                        <div><span style="font-size: 12px; color: #9a9cab; font-weight: 600">Vklad </span><span style="font-weight: 800; font-size: 14px">{{ eur(history.current.invested) }}</span></div>
                        <div>
                            <span style="font-size: 12px; color: #9a9cab; font-weight: 600">Zisk </span>
                            <span style="font-weight: 800; font-size: 14px" :style="{ color: history.current.gain >= 0 ? '#2ba35a' : '#e8544e' }">{{ history.current.gain >= 0 ? '+' : '−' }}{{ eur(history.current.gain) }} · {{ history.current.gain >= 0 ? '+' : '−' }}{{ num(Math.abs(history.current.pct), 1) }} %</span>
                        </div>
                    </div>
                </div>

                <div v-if="historyLoading" style="padding: 40px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px">Počítam vývoj z tvojich nákupov a historických cien…</div>
                <div v-else-if="history" style="margin-top: 14px">
                    <PortfolioChart :series="history.series" />
                    <div v-if="bestWorst" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px">
                        <div style="flex: 1; min-width: 200px; display: flex; align-items: center; gap: 10px; background: #e6f7ec; border-radius: 13px; padding: 12px 14px">
                            <span style="font-size: 11px; font-weight: 700; color: #2ba35a; text-transform: uppercase; letter-spacing: 0.3px">Najlepšia</span>
                            <span style="font-weight: 700; font-size: 13.5px">{{ bestWorst.best.ticker }}</span>
                            <span style="margin-left: auto; font-weight: 800; font-size: 13.5px; color: #2ba35a">+{{ num(Math.abs(bestWorst.best.pct), 1) }} %</span>
                        </div>
                        <div style="flex: 1; min-width: 200px; display: flex; align-items: center; gap: 10px; background: #fdeaea; border-radius: 13px; padding: 12px 14px">
                            <span style="font-size: 11px; font-weight: 700; color: #e8544e; text-transform: uppercase; letter-spacing: 0.3px">Najhoršia</span>
                            <span style="font-weight: 700; font-size: 13.5px">{{ bestWorst.worst.ticker }}</span>
                            <span style="margin-left: auto; font-weight: 800; font-size: 13.5px" :style="{ color: bestWorst.worst.pct >= 0 ? '#2ba35a' : '#e8544e' }">{{ bestWorst.worst.pct >= 0 ? '+' : '−' }}{{ num(Math.abs(bestWorst.worst.pct), 1) }} %</span>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 22px 2px 12px">
                <div class="font-display" style="font-weight: 700; font-size: 17px">Tvoje pozície</div>
                <button
                    type="button"
                    style="display: flex; align-items: center; gap: 6px; color: #fff; font-weight: 700; font-size: 13px; padding: 9px 14px; border-radius: 11px; white-space: nowrap"
                    :style="{ background: primary, boxShadow: `0 6px 14px ${primarySoft}` }"
                    @click="showNew = true"
                >
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
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
                    <span style="width: 44px; height: 44px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex-shrink: 0" :style="{ background: hexToRgba(h.color, 0.14), color: h.color }">{{ h.ticker.slice(0, 4) }}</span>
                    <div style="flex: 1; min-width: 0">
                        <div style="font-size: 14px; font-weight: 700">{{ h.name }}</div>
                        <div style="font-size: 12px; color: #9a9cab; font-weight: 500">{{ kindLabel(h.kind) }} · {{ unitsFmt(Number(h.units)) }} · Ø {{ eur(Number(h.buy_price)) }}<span v-if="h.quote_source !== 'manual'"> · online</span></div>
                    </div>
                    <div style="text-align: right">
                        <div style="font-size: 15px; font-weight: 800">{{ eur(h.value) }}</div>
                        <div style="font-size: 12.5px; font-weight: 700" :style="{ color: h.gain >= 0 ? '#2ba35a' : '#e8544e' }">
                            {{ h.gain >= 0 ? '+' : '−' }}{{ eur(h.gain) }} · {{ h.gain >= 0 ? '+' : '−' }}{{ num(h.cost > 0 ? Math.abs((h.gain / h.cost) * 100) : 0, 1) }}%
                        </div>
                    </div>
                </div>
                <div v-if="!investments.length" style="padding: 40px 16px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px">Zatiaľ žiadne investície</div>
            </div>
        </div>

        <InvestmentModal v-if="showNew" @close="showNew = false" />
        <InvestmentModal v-if="editInvestment" :investment="editInvestment" @close="editInvestment = null" />
        <InvestmentDetailModal v-if="detail" :investment="detail" @close="detailId = null" @buy="openBuy" @sell="openSell" @edit="openEdit" />
        <LotModal v-if="lotModal" :investment-id="lotModal.investmentId" :ticker="lotModal.ticker" :preset-type="lotModal.presetType" :auto-price="lotModal.autoPrice" @close="lotModal = null" />
    </GrosLayout>
</template>

<style scoped>
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
