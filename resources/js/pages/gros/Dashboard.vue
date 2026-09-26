<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import Card from '@/components/gros/Card.vue';
import DeltaBadge from '@/components/gros/DeltaBadge.vue';
import GoalModal from '@/components/gros/GoalModal.vue';
import LineChart from '@/components/gros/LineChart.vue';
import PeriodSelector from '@/components/gros/PeriodSelector.vue';
import ProgressBar from '@/components/gros/ProgressBar.vue';
import StatCard from '@/components/gros/StatCard.vue';
import TransactionModal from '@/components/gros/TransactionModal.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface UpcomingItem {
    name: string;
    amount: number;
    date: string;
    color: string;
    kind: 'subscription' | 'loan';
}
interface NetWorthMonth {
    ym: string;
    label: string;
    cash: number;
    invest: number;
    debt: number;
    value: number;
}
interface BudgetRow {
    id: number;
    category_id: number;
    limit_amount: number;
    period: string;
    spent: number;
    projected: number;
    elapsed: number;
    total: number;
}
interface Goal {
    id: number;
    name: string;
    target_amount: number;
    saved_amount: number;
    color: string;
    deadline: string | null;
}
interface Insight {
    tone: string;
    text: string;
}
interface Plan {
    configured: boolean;
    incomeIsEstimate: boolean;
    income: number;
    fixed: number;
    savings: number;
    disposable: number;
    spent: number;
    /** Z `spent` výdavky z udalostí — do tempa sa rátajú len raz. */
    eventSpent: number;
    safeToSpend: number;
    dailyLimit: number;
    projectedSpend: number;
    projectedLeftover: number;
    onTrack: boolean;
    daysInMonth: number;
    dayOfMonth: number;
    daysLeft: number;
    monthLabel: string;
    estimateSuggestion: number;
}

const props = defineProps<{
    period: { key: string; ref: string | null; from: string | null; to: string | null; label: string };
    dataRange: { min: string | null; max: string | null };
    accounts: { id: number; name: string }[];
    stats: { netWorth: number; grossWorth: number; cash: number; income: number; expense: number; saved: number; savedPct: number };
    prevStats: { label: string; income: number; expense: number; saved: number } | null;
    netWorthSeries: NetWorthMonth[];
    reserve: { avgExpense: number; months: number | null };
    anomalies: {
        id: number;
        date: string;
        note: string | null;
        amount: number;
        category: string | null;
        color: string | null;
        usual: number;
        times: number;
    }[];
    insights: Insight[];
    portfolio: { value: number; cost: number; gain: number; pct: number };
    upcoming: { items: UpcomingItem[]; count: number; total: number; days: number };
    budgets: BudgetRow[];
    goals: Goal[];
    loanOwed: number;
    plan: Plan;
}>();

const { eur, eurS, num, grad, primary, primarySoft, catColor, catName, hexToRgba, formatDate } = useGros();

const showTxn = ref(false);
const showGoal = ref(false);
const editGoal = ref<Goal | null>(null);

const isEmpty = computed(() => props.accounts.length === 0 && props.stats.netWorth === 0);
const cardShadow = '0 4px 18px rgba(60,55,40,.05)';

// Medziobdobné zmeny (▲/▼ vs predchádzajúce obdobie)
function deltaPct(cur: number, prev: number | undefined): number | null {
    return prev !== undefined && prev > 0 ? ((cur - prev) / prev) * 100 : null;
}
const incomeDelta = computed(() => deltaPct(props.stats.income, props.prevStats?.income));
const expenseDelta = computed(() => deltaPct(props.stats.expense, props.prevStats?.expense));
const savedDelta = computed(() => deltaPct(props.stats.saved, props.prevStats?.saved));

// ── Núdzový fond ────────────────────────────────────────────────────────
/** Odporúčaná rezerva: šesť mesiacov výdavkov. */
const RESERVE_TARGET = 6;

const reservePct = computed(() => Math.min(100, ((props.reserve.months ?? 0) / RESERVE_TARGET) * 100));

const reserveColor = computed(() => {
    const m = props.reserve.months ?? 0;
    if (m >= RESERVE_TARGET) return '#2ba35a';
    if (m >= 3) return '#e8954e';
    return '#e8544e';
});

const reserveLabel = computed(() => {
    const m = props.reserve.months ?? 0;
    if (m >= RESERVE_TARGET) return `Rezerva je plná — ${RESERVE_TARGET} mesiacov výdavkov máš pokrytých.`;
    const missing = Math.max(0, RESERVE_TARGET * props.reserve.avgExpense - (props.reserve.months ?? 0) * props.reserve.avgExpense);
    return `Do šesťmesačnej rezervy chýba ${eur(missing)}. Bez nej ťa prvý krach donúti predať investície.`;
});

// Vývoj čistého imania
const nwPoints = computed(() =>
    props.netWorthSeries.map((m) => ({
        label: m.label,
        value: m.value,
        title: `${m.label}: ${eurS(m.value)}\nHotovosť ${eurS(m.cash)} · Investície ${eur(m.invest)} · Dlhy −${eur(m.debt)}`,
    })),
);
const nwChange = computed(() => {
    if (props.netWorthSeries.length < 2) return null;
    const first = props.netWorthSeries[0].value;
    return props.netWorthSeries[props.netWorthSeries.length - 1].value - first;
});

// Rozpočty
const periodLabels: Record<string, string> = { week: 'týždeň', month: 'mesiac', year: 'rok' };
function budgetPct(b: BudgetRow): number {
    return b.limit_amount > 0 ? (b.spent / b.limit_amount) * 100 : 100;
}
function budgetColor(b: BudgetRow): string {
    if (b.spent > b.limit_amount) return '#e8544e';
    if (b.projected > b.limit_amount) return '#e8954e';
    return '#2ba35a';
}

// Ciele
function goalPct(g: Goal): number {
    return g.target_amount > 0 ? Math.min(100, (g.saved_amount / g.target_amount) * 100) : 0;
}

// Najbližšie platby
const upcomingAfter = computed(() => props.stats.cash - props.upcoming.total);

/**
 * Postrehy a nezvyčajné výdavky v jednom zozname — spolu najviac tri,
 * aby blok upozorňoval, nie aby sa z neho stal ďalší report.
 */
const attention = computed(() => {
    const items: ({ kind: 'insight'; tone: string; text: string } | { kind: 'anomaly'; a: (typeof props.anomalies)[number] })[] = [
        ...props.insights.map((i) => ({ kind: 'insight' as const, tone: i.tone, text: i.text })),
        ...props.anomalies.map((a) => ({ kind: 'anomaly' as const, a })),
    ];
    return items.slice(0, 3);
});

const toneBg: Record<string, string> = { good: '#e6f7ec', warn: '#fdeaea', info: '#eef6ff' };
const toneColor: Record<string, string> = { good: '#2ba35a', warn: '#c0453f', info: '#2a6ebd' };

// Plán míňania
const planPct = computed(() => {
    const d = props.plan.disposable;
    if (d <= 0) return 100;
    return Math.max(0, Math.min(100, (props.plan.spent / d) * 100));
});
const planColor = computed(() =>
    props.plan.safeToSpend < 0 ? '#e8544e' : props.plan.safeToSpend < props.plan.disposable * 0.2 ? '#e8954e' : '#2ba35a',
);
</script>

<template>
    <Head title="Prehľad" />
    <GrosLayout title="Prehľad" subtitle="Tvoje financie na jednom mieste">
        <template #action>
            <AddButton label="Pridať transakciu" @click="showTxn = true" />
        </template>

        <div class="gros-rise">
            <div
                v-if="!isEmpty"
                style="background: #fff; border-radius: 16px; padding: 12px 14px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-bottom: 14px"
            >
                <PeriodSelector :period="period" :data-range="dataRange" path="/dashboard" />
            </div>

            <div
                v-if="isEmpty"
                style="
                    background: #fff;
                    border-radius: 20px;
                    padding: 28px;
                    box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05);
                    margin-bottom: 16px;
                    text-align: center;
                "
            >
                <div class="font-display" style="font-weight: 800; font-size: 20px; margin-bottom: 6px">Vitaj v Groši 👋</div>
                <div style="color: #8a8c9a; font-size: 14px; font-weight: 500">
                    Začni pridaním účtu v sekcii <strong>Účty</strong> a potom si zapisuj transakcie. Prehľad sa naplní automaticky.
                </div>
            </div>

            <!-- Na čo sa pozrieť: postrehy + nezvyčajné výdavky v jednom -->
            <div
                v-if="!isEmpty && attention.length"
                style="background: #fff; border-radius: 20px; padding: 18px 22px; margin-bottom: 14px"
                :style="{ boxShadow: cardShadow }"
            >
                <div style="display: flex; align-items: center; gap: 8px">
                    <span style="font-size: 15px">📌</span>
                    <span style="font-size: 12.5px; font-weight: 800; color: #20212e">Na čo sa pozrieť</span>
                </div>

                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 12px">
                    <template v-for="(item, i) in attention" :key="i">
                        <div
                            v-if="item.kind === 'insight'"
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                padding: 10px 13px;
                                border-radius: 12px;
                                font-size: 13px;
                                font-weight: 600;
                            "
                            :style="{ background: toneBg[item.tone] || '#f5f4ef', color: toneColor[item.tone] || '#20212e' }"
                        >
                            {{ item.text }}
                        </div>
                        <div v-else style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; padding: 4px 2px">
                            <span
                                style="width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0"
                                :style="{ background: item.a.color ?? '#b0b2bd' }"
                            ></span>
                            <span style="font-weight: 700">{{ item.a.note || item.a.category || 'Bez popisu' }}</span>
                            <span style="font-size: 11.5px; color: #9a9cab; font-weight: 600"
                                >{{ formatDate(item.a.date) }} · {{ item.a.category }}</span
                            >
                            <span style="margin-left: auto; display: flex; align-items: baseline; gap: 8px">
                                <span style="font-size: 11.5px; color: #9a9cab; font-weight: 600">bežne {{ eur(item.a.usual) }}</span>
                                <span class="font-display" style="font-weight: 800; font-size: 15px">{{ eur(item.a.amount) }}</span>
                                <span
                                    style="
                                        font-size: 11px;
                                        font-weight: 800;
                                        color: #c0453f;
                                        background: #fdeaea;
                                        padding: 3px 7px;
                                        border-radius: 20px;
                                    "
                                >
                                    {{ num(item.a.times, 1) }}×
                                </span>
                            </span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Stat cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px">
                <div style="border-radius: 20px; padding: 20px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                    <div style="font-size: 12.5px; font-weight: 600; opacity: 0.9">Čisté imanie</div>
                    <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.9px; margin-top: 8px; white-space: nowrap">
                        {{ eurS(stats.netWorth) }}
                    </div>
                    <div style="font-size: 12px; font-weight: 600; opacity: 0.88; margin-top: 8px">
                        Hotovosť {{ eurS(stats.cash) }} + investície {{ eur(portfolio.value)
                        }}<template v-if="loanOwed > 0"> − dlhy {{ eur(loanOwed) }}</template>
                    </div>
                </div>

                <StatCard label="Príjmy" :value="eur(stats.income)" value-color="#2ba35a" icon-bg="#e6f7ec">
                    <template #icon>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2ba35a" stroke-width="2.6" stroke-linecap="round">
                            <path d="M12 19V6M6 12l6-6 6 6" />
                        </svg>
                    </template>
                    <DeltaBadge :pct="incomeDelta" :label="prevStats ? 'vs ' + prevStats.label : undefined" />
                </StatCard>

                <StatCard label="Výdavky" :value="eur(stats.expense)" value-color="#e8544e" icon-bg="#fdeaea">
                    <template #icon>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#e8544e" stroke-width="2.6" stroke-linecap="round">
                            <path d="M12 5v13M6 12l6 6 6-6" />
                        </svg>
                    </template>
                    <DeltaBadge :pct="expenseDelta" invert :label="prevStats ? 'vs ' + prevStats.label : undefined" />
                </StatCard>

                <StatCard label="Ušetrené" :value="eurS(stats.saved)" value-color="#0fa3b1" icon-bg="#e5f6f8">
                    <template #icon>
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="#0fa3b1"
                            stroke-width="2.4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M12 3v4M12 21a7 7 0 1 0 0-14 7 7 0 0 0 0 14z" />
                        </svg>
                    </template>
                    <DeltaBadge :pct="savedDelta" :label="prevStats ? 'vs ' + prevStats.label : undefined" />
                    <Link
                        v-if="stats.income > 0"
                        href="/analytics"
                        style="display: block; font-size: 12px; font-weight: 600; color: #9a9cab; margin-top: 7px"
                    >
                        miera úspor <strong style="color: #0fa3b1">{{ stats.savedPct }} %</strong> →
                    </Link>
                </StatCard>

                <StatCard
                    label="Rezerva"
                    :value="reserve.months !== null ? num(reserve.months, 1) + ' mes.' : '—'"
                    value-color="#9775fa"
                    icon-bg="#f0ebfe"
                >
                    <template #icon>
                        <svg
                            width="15"
                            height="15"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="#9775fa"
                            stroke-width="2.4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z" />
                        </svg>
                    </template>
                    <template v-if="reserve.months !== null">
                        <div style="height: 6px; border-radius: 4px; background: #f1efe8; overflow: hidden; margin-top: 10px">
                            <div style="height: 100%; border-radius: 4px" :style="{ width: reservePct + '%', background: reserveColor }"></div>
                        </div>
                        <Link
                            href="/reserve"
                            style="display: block; font-size: 12px; font-weight: 600; color: #9a9cab; margin-top: 7px; line-height: 1.5"
                        >
                            {{ reserveLabel }}
                        </Link>
                    </template>
                    <div v-else style="font-size: 12px; font-weight: 600; color: #9a9cab; margin-top: 7px">zatiaľ málo dát o výdavkoch</div>
                </StatCard>
            </div>

            <!-- Koľko môžem minúť (safe-to-spend) -->
            <div
                v-if="!isEmpty && plan.configured"
                style="background: #fff; border-radius: 20px; padding: 22px; margin-top: 14px"
                :style="{ boxShadow: cardShadow }"
            >
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">
                        Koľko môžem minúť · {{ plan.monthLabel }}
                    </div>
                    <Link
                        href="/settings/preferences"
                        style="font-size: 12px; font-weight: 700; color: #9a9cab; display: flex; align-items: center; gap: 5px; flex-shrink: 0"
                    >
                        <svg
                            width="13"
                            height="13"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z" />
                        </svg>
                        Upraviť plán
                    </Link>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; margin-top: 14px">
                    <div style="flex: 1; min-width: 210px">
                        <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">
                            {{ plan.safeToSpend >= 0 ? 'Zostáva na voľné míňanie' : 'Prekročený voľný rozpočet o' }}
                        </div>
                        <div
                            class="font-display"
                            style="font-weight: 800; font-size: 36px; letter-spacing: -1.2px; margin-top: 4px"
                            :style="{ color: planColor }"
                        >
                            {{ eurS(plan.safeToSpend) }}
                        </div>
                        <div style="font-size: 13px; font-weight: 600; color: #6a6c7a; margin-top: 5px">
                            <span v-if="plan.safeToSpend >= 0"
                                >≈ <strong style="color: #20212e">{{ eur(plan.dailyLimit) }}</strong> na deň · ešte {{ plan.daysLeft }} dní</span
                            >
                            <span v-else style="color: #e8544e">Míňaš z peňazí, ktoré si plánoval nechať.</span>
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 230px; font-size: 13px; font-weight: 600">
                        <div style="display: flex; justify-content: space-between; padding: 3px 0">
                            <span style="color: #8a8c9a">Mesačný príjem</span><span style="color: #2ba35a">+{{ eur(plan.income) }}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 3px 0">
                            <span style="color: #8a8c9a">Fixné (predplatné, splátky)</span><span style="color: #e8544e">−{{ eur(plan.fixed) }}</span>
                        </div>
                        <div v-if="plan.savings > 0" style="display: flex; justify-content: space-between; padding: 3px 0">
                            <span style="color: #8a8c9a">Sporiaci cieľ</span><span style="color: #0fa3b1">−{{ eur(plan.savings) }}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 6px 0; border-top: 1px solid #f1efe8; margin-top: 3px">
                            <span style="color: #20212e; font-weight: 700">Voľné na mesiac</span
                            ><span style="color: #20212e; font-weight: 800">{{ eur(plan.disposable) }}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 3px 0">
                            <span style="color: #8a8c9a">Už minuté</span><span style="color: #e8544e">−{{ eur(plan.spent) }}</span>
                        </div>
                        <div v-if="plan.eventSpent > 0" style="display: flex; justify-content: space-between; padding: 0 0 3px; font-size: 11.5px">
                            <span style="color: #b0b2bd">z toho udalosti (do tempa sa nerátajú)</span
                            ><span style="color: #b0b2bd">{{ eur(plan.eventSpent) }}</span>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 16px">
                    <ProgressBar :pct="planPct" :color="planColor" />
                </div>
                <div style="font-size: 11.5px; font-weight: 600; color: #9a9cab; margin-top: 6px">
                    Minuté {{ eur(plan.spent) }} z {{ eur(plan.disposable) }} voľných · deň {{ plan.dayOfMonth }}/{{ plan.daysInMonth }}
                </div>

                <div
                    style="display: flex; align-items: center; gap: 10px; margin-top: 14px; padding: 12px 14px; border-radius: 13px"
                    :style="{ background: plan.onTrack ? '#e9f7ee' : '#fdeeea' }"
                >
                    <span style="font-size: 18px; flex-shrink: 0">{{ plan.onTrack ? '✅' : '⚠️' }}</span>
                    <div style="font-size: 13px; font-weight: 600; line-height: 1.45" :style="{ color: plan.onTrack ? '#1f7a44' : '#c23b32' }">
                        <template v-if="plan.onTrack"
                            >Pri tomto tempe ti na konci mesiaca zostane <strong>{{ eurS(plan.projectedLeftover) }}</strong
                            >. Dobrá práca! 👏</template
                        >
                        <template v-else
                            >Pri tomto tempe minieš <strong>~{{ eur(plan.projectedSpend) }}</strong> a skončíš
                            <strong>{{ eurS(plan.projectedLeftover) }}</strong
                            >. Skús trochu pribrzdiť.</template
                        >
                    </div>
                </div>

                <div v-if="plan.incomeIsEstimate" style="font-size: 11.5px; font-weight: 600; color: #b0b2bd; margin-top: 10px">
                    Príjem {{ eur(plan.income) }} je odhad z histórie —
                    <Link href="/settings/preferences" style="color: #9a9cab; text-decoration: underline"
                        >nastav si presný príjem a sporiaci cieľ →</Link
                    >
                </div>
            </div>

            <!-- Platby + rozpočty + ciele -->
            <div v-if="!isEmpty" style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px">
                <div style="flex: 1; min-width: 280px">
                    <Card :title="`Platby · ${upcoming.days} dní`">
                        <div style="display: flex; flex-direction: column; gap: 13px; margin-top: 15px">
                            <div v-for="(s, i) in upcoming.items" :key="i" style="display: flex; align-items: center; gap: 12px">
                                <span
                                    style="
                                        width: 34px;
                                        height: 34px;
                                        border-radius: 11px;
                                        display: flex;
                                        align-items: center;
                                        justify-content: center;
                                        font-weight: 800;
                                        font-size: 14px;
                                        flex-shrink: 0;
                                    "
                                    :style="{ background: hexToRgba(s.color || '#4c8dff', 0.14), color: s.color || '#4c8dff' }"
                                >
                                    {{ s.kind === 'loan' ? '🏦' : s.name[0] }}
                                </span>
                                <div style="flex: 1; min-width: 0">
                                    <div style="font-size: 13.5px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                                        {{ s.name }}
                                    </div>
                                    <div style="font-size: 11.5px; color: #9a9cab; font-weight: 500">
                                        {{ formatDate(s.date) }}<span v-if="s.kind === 'loan'"> · splátka úveru</span>
                                    </div>
                                </div>
                                <div style="font-size: 14px; font-weight: 700">{{ eur(s.amount) }}</div>
                            </div>
                            <div v-if="!upcoming.items.length" style="color: #b0b2bd; font-weight: 600; font-size: 13px">
                                Žiadne platby v najbližších {{ upcoming.days }} dňoch.
                            </div>
                            <div v-if="upcoming.count > upcoming.items.length" style="font-size: 12px; font-weight: 600; color: #9a9cab">
                                + {{ upcoming.count - upcoming.items.length }} ďalších
                            </div>
                        </div>
                        <div
                            v-if="upcoming.items.length"
                            style="margin-top: 15px; padding-top: 13px; border-top: 1px solid #f1efe8; font-size: 13px; font-weight: 600"
                        >
                            <div style="display: flex; justify-content: space-between">
                                <span style="color: #8a8c9a">Spolu</span
                                ><span style="color: #e8544e; font-weight: 800">−{{ eur(upcoming.total) }}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 4px">
                                <span style="color: #8a8c9a">Z hotovosti zostane</span>
                                <span style="font-weight: 800" :style="{ color: upcomingAfter < 0 ? '#e8544e' : '#20212e' }">{{
                                    eurS(upcomingAfter)
                                }}</span>
                            </div>
                        </div>
                    </Card>
                </div>
                <div style="flex: 1; min-width: 280px">
                    <Card title="Rozpočty">
                        <template #right>
                            <Link href="/budgets" style="font-size: 12px; font-weight: 700; color: #9a9cab">Všetky →</Link>
                        </template>
                        <div v-if="budgets.length" style="display: flex; flex-direction: column; gap: 14px; margin-top: 16px">
                            <div v-for="b in budgets" :key="b.id">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px">
                                    <span style="font-size: 13.5px; font-weight: 600; display: flex; align-items: center; gap: 8px; min-width: 0">
                                        <span
                                            style="width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0"
                                            :style="{ background: catColor(b.category_id) }"
                                        ></span>
                                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{
                                            catName(b.category_id)
                                        }}</span>
                                        <span style="color: #b0b2bd; font-weight: 500; flex-shrink: 0"
                                            >· {{ periodLabels[b.period] ?? b.period }}</span
                                        >
                                    </span>
                                    <span style="font-size: 13px; font-weight: 700; white-space: nowrap"
                                        >{{ eur(b.spent) }} <span style="color: #9a9cab; font-weight: 600">/ {{ eur(b.limit_amount) }}</span></span
                                    >
                                </div>
                                <ProgressBar :pct="budgetPct(b)" :color="budgetColor(b)" :height="8" />
                                <div
                                    v-if="b.projected > b.limit_amount && b.spent <= b.limit_amount"
                                    style="font-size: 11.5px; font-weight: 600; color: #e8954e; margin-top: 4px"
                                >
                                    Pri tomto tempe skončíš na {{ eur(b.projected) }} — pribrzdi.
                                </div>
                                <div
                                    v-else-if="b.spent > b.limit_amount"
                                    style="font-size: 11.5px; font-weight: 600; color: #e8544e; margin-top: 4px"
                                >
                                    Prekročené o {{ eur(b.spent - b.limit_amount) }}.
                                </div>
                            </div>
                        </div>
                        <div v-else style="color: #b0b2bd; font-weight: 600; font-size: 13.5px; padding: 16px 0">
                            Zatiaľ žiadne rozpočty —
                            <Link href="/budgets" style="color: #9a9cab; text-decoration: underline">nastav si limity pre kategórie</Link>.
                        </div>
                    </Card>
                </div>
                <div style="flex: 1; min-width: 280px">
                    <Card title="Sporiace ciele">
                        <template #right>
                            <button
                                type="button"
                                style="font-size: 12px; font-weight: 700; padding: 5px 11px; border-radius: 10px; color: #fff"
                                :style="{ background: primary }"
                                @click="
                                    editGoal = null;
                                    showGoal = true;
                                "
                            >
                                + Pridať
                            </button>
                        </template>
                        <div v-if="goals.length" style="display: flex; flex-direction: column; gap: 14px; margin-top: 16px">
                            <button
                                v-for="g in goals"
                                :key="g.id"
                                type="button"
                                style="display: block; width: 100%; text-align: left"
                                @click="
                                    editGoal = g;
                                    showGoal = true;
                                "
                            >
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px">
                                    <span style="font-size: 13.5px; font-weight: 600; display: flex; align-items: center; gap: 8px; min-width: 0">
                                        <span
                                            style="width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0"
                                            :style="{ background: g.color }"
                                        ></span>
                                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ g.name }}</span>
                                        <span v-if="g.deadline" style="color: #b0b2bd; font-weight: 500; flex-shrink: 0"
                                            >· do {{ formatDate(g.deadline) }}</span
                                        >
                                    </span>
                                    <span style="font-size: 13px; font-weight: 700; white-space: nowrap"
                                        >{{ eur(g.saved_amount) }}
                                        <span style="color: #9a9cab; font-weight: 600">/ {{ eur(g.target_amount) }}</span></span
                                    >
                                </div>
                                <ProgressBar :pct="goalPct(g)" :color="g.color" :height="8" />
                                <div style="font-size: 11.5px; font-weight: 600; color: #9a9cab; margin-top: 4px">
                                    {{ num(goalPct(g)) }} % · zostáva {{ eur(Math.max(0, g.target_amount - g.saved_amount)) }}
                                </div>
                            </button>
                        </div>
                        <div v-else style="color: #b0b2bd; font-weight: 600; font-size: 13.5px; padding: 16px 0">
                            Pomenuj si, na čo šetríš — rezerva, dovolenka, auto — a sleduj progres.
                        </div>
                    </Card>
                </div>
            </div>

            <!-- Vývoj čistého imania -->
            <div v-if="netWorthSeries.length >= 2" style="margin-top: 14px">
                <Card title="Vývoj čistého imania">
                    <template #right>
                        <div
                            v-if="nwChange !== null"
                            style="font-size: 12px; font-weight: 700; padding: 4px 9px; border-radius: 20px"
                            :style="{ color: nwChange >= 0 ? '#2ba35a' : '#e8544e', background: nwChange >= 0 ? '#e6f7ec' : '#fdeaea' }"
                        >
                            {{ nwChange >= 0 ? '▲' : '▼' }} {{ eur(Math.abs(nwChange)) }} za {{ netWorthSeries.length }} mes.
                        </div>
                    </template>
                    <div style="margin-top: 14px">
                        <LineChart :points="nwPoints" :color="primary" :height="220" :fmt="eurS" />
                    </div>
                    <div
                        style="
                            display: flex;
                            flex-wrap: wrap;
                            gap: 16px;
                            margin-top: 12px;
                            padding-top: 12px;
                            border-top: 1px solid #f1efe8;
                            font-size: 12.5px;
                            font-weight: 600;
                            color: #6a6c7a;
                        "
                    >
                        <span
                            >Hotovosť <strong style="color: #20212e">{{ eurS(stats.cash) }}</strong></span
                        >
                        <span
                            >Investície <strong style="color: #20212e">{{ eur(portfolio.value) }}</strong></span
                        >
                        <span v-if="loanOwed > 0"
                            >Dlhy <strong style="color: #e8544e">−{{ eur(loanOwed) }}</strong></span
                        >
                    </div>
                </Card>
            </div>
        </div>

        <TransactionModal v-if="showTxn" :accounts="accounts" @close="showTxn = false" />
        <GoalModal v-if="showGoal" :goal="editGoal" @close="showGoal = false" />
    </GrosLayout>
</template>
