<script setup lang="ts">
import AskAi from '@/components/gros/AskAi.vue';
import Card from '@/components/gros/Card.vue';
import FanChart from '@/components/gros/FanChart.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

interface Plan {
    year: number;
    duration: number;
    monthly: number;
    index_contributions: boolean;
    inflation: number;
    fees: number;
    haircut: number;
    withdrawal: number;
    engine: string;
    target_income: number | null;
    spending: number | null;
}
interface Freedom {
    monthly_spending: number;
    annual_spending: number;
    fire_number: number;
    progress_pct: number;
    year: number | null;
    year_safe: number | null;
    year_lucky: number | null;
    years_from_now: number | null;
    years_earlier: number | null;
    within_plan: boolean;
    required_monthly: number | null;
    required_extra: number | null;
    coast_year: number | null;
    coast_years_from_now: number | null;
    reached: boolean;
    horizon_year: number;
}
interface SeriesPoint {
    year: number;
    contributed: number;
    real_contributed: number;
    p10: number;
    p25: number;
    p50: number;
    p75: number;
    p90: number;
    real_p10: number;
    real_p25: number;
    real_p50: number;
    real_p75: number;
    real_p90: number;
}
interface Scenario {
    monthly: number;
    extra?: number;
    nominal_p50: number;
    real_p10: number;
    real_p50: number;
    real_p90: number;
    income_p50: number;
    contributed: number;
    freedom_year: number | null;
}
interface Projection {
    ok: boolean;
    reason?: string;
    years: number;
    start_value: number;
    freedom: Freedom | null;
    scenarios: { base: Scenario; ladder: Scenario[]; custom: Scenario | null; fire_number: number | null };
    withdrawal: {
        duration: number;
        annual_withdrawal: number;
        success_pct: number;
        depleted_year: number | null;
        depleted_after_years: number | null;
        median_left: number;
        rates: { rate: number; needed: number; success_pct: number }[];
        safe_rate: { rate: number; needed: number; success_pct: number } | null;
        target: number;
        current_rate: number;
    } | null;
    without_volatile?: {
        excluded: number;
        excluded_share: number;
        start_value: number;
        real_p50: number | null;
        income_p50: number | null;
        freedom_year: number | null;
        years_later: number | null;
    };
    series: SeriesPoint[];
    final: {
        nominal: { p10: number; p50: number; p90: number };
        real: { p10: number; p50: number; p90: number };
        contributed: number;
        real_contributed: number;
        growth: number;
        income: { p10: number; p50: number; p90: number };
        income_nominal_p50: number;
    };
    target: null | {
        income: number;
        success_pct: number;
        required_monthly: number | null;
        required_monthly_safe: number | null;
        required_delta: number | null;
    };
    engine: {
        key: string;
        label: string;
        note: string;
        currency: string;
        from: string;
        to: string;
        months: number;
        years: number;
        short_history: boolean;
        cagr: number;
        vol: number;
        worst_month: number;
        best_month: number;
        net_cagr: number;
        real_cagr: number;
        drag: number;
    };
    inflation: {
        used: number;
        sk_avg: number | null;
        sk_avg20: number | null;
        sk_latest: number | null;
        sk_from: string | null;
        sk_to: string | null;
        eu_avg20: number | null;
    };
    paths: number;
}

interface Profile {
    measured: {
        months: number;
        window: number;
        income: number;
        expense: number;
        savings: number;
        savings_rate: number | null;
        savings_flow: number;
        one_off: number;
        recurring_expense: number;
        recurring_savings: number;
        recurring_savings_rate: number | null;
        has_data: boolean;
    };
    assets: { portfolio: number; cash: number; debt: number; net_worth: number };
    reserve: { avgExpense: number; months: number | null };
    investable_cash: number;
}

interface Contributions {
    has_data: boolean;
    months: number;
    series: { ym: string; label: string; amount: number }[];
    first_purchase?: string;
    total?: number;
    mean?: number;
    median?: number;
    recommended: number;
    recent3?: number;
    lumps?: { ym: string; label: string; amount: number }[];
    lump_total?: number;
    lump_share?: number;
    reconciliation?: {
        recorded: number;
        booked: number;
        difference: number;
        mismatch: boolean;
        stale: { id: number; ticker: string; name: string; last_purchase: string; months_since: number }[];
    };
}

const props = defineProps<{
    plan: Plan;
    profile: Profile;
    contributions: Contributions;
    engines: { key: string; label: string; note: string; since: number; years: number; short_history: boolean }[];
    longestEngine: { key: string; label: string; since: number; years: number };
    inflationHistory: { avg: number; avg20: number; latest: number; from: string; to: string } | null;
}>();

const { eur, eurS, num, grad, primary, primarySoft } = useGros();

const form = ref<Plan>({ ...props.plan });
/** Započítať hotovosť nad šesťmesačnú rezervu do štartovacej sumy. */
const includeCash = ref(false);
/** „Čo keby som vkladal…" — neukladá sa do plánu, len porovnáva. */
const compare = ref<number | null>(null);
const projection = ref<Projection | null>(null);
const loading = ref(true);
const saving = ref(false);
/** true = dnešné eurá (po inflácii), false = nominálne sumy roku odchodu */
const realMode = ref(true);

let timer: ReturnType<typeof setTimeout> | undefined;

async function load() {
    loading.value = true;
    const q = new URLSearchParams({
        year: String(form.value.year),
        duration: String(form.value.duration),
        monthly: String(form.value.monthly || 0),
        index_contributions: form.value.index_contributions ? '1' : '0',
        inflation: String(form.value.inflation),
        fees: String(form.value.fees),
        haircut: String(form.value.haircut),
        withdrawal: String(form.value.withdrawal),
        engine: form.value.engine,
        include_cash: includeCash.value ? '1' : '0',
    });
    if (form.value.target_income) q.set('target_income', String(form.value.target_income));
    if (form.value.spending) q.set('spending', String(form.value.spending));
    if (compare.value) q.set('compare', String(compare.value));

    try {
        const r = await fetch('/retirement/simulate?' + q.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        projection.value = await r.json();
    } catch {
        projection.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

watch(
    [form, includeCash, compare],
    () => {
        clearTimeout(timer);
        timer = setTimeout(load, 350);
    },
    { deep: true },
);

function save() {
    saving.value = true;
    router.post(
        '/retirement',
        { ...form.value, target_income: form.value.target_income || null, spending: form.value.spending || null },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}

const freedom = computed(() => projection.value?.freedom ?? null);
const withdrawal = computed(() => projection.value?.withdrawal ?? null);

/** Farba podľa toho, či plán prežije dôchodok. */
const survivalTone = computed(() => {
    const s = withdrawal.value?.success_pct ?? 0;
    if (s >= 90) return { bg: '#e6f7ec', fg: '#2ba35a' };
    if (s >= 75) return { bg: '#fff6e5', fg: '#b8791a' };
    return { bg: '#fdeaea', fg: '#c0453f' };
});

/** Meraný vklad sa líši od toho v pláne — ponúkni prepnutie. */
const contributionMismatch = computed(() => {
    const c = props.contributions;
    if (!c.has_data || !c.recommended) return false;
    return Math.abs(c.recommended - form.value.monthly) > 5;
});

function useMeasuredContribution() {
    form.value.monthly = props.contributions.recommended;
}

/** Najväčší mesiac určuje výšku stĺpcov v prehľade vkladov. */
const contributionMax = computed(() => Math.max(1, ...props.contributions.series.map((m) => Math.abs(m.amount))));

/** Pozície, do ktorých sa možno stále prispieva, ale nákupy sa nezapisujú. */
const staleHoldings = computed(() => props.contributions.reconciliation?.stale ?? []);

/** Priznanie, že do pozície sa už neprispieva — upozornenie potom zmizne. */
function stopTracking(id: number) {
    router.patch(`/investments/${id}/contributing`, { contributing: false }, { preserveScroll: true });
}

// ── Čo keby som vkladal viac ────────────────────────────────────────────
const scenarios = computed(() => projection.value?.scenarios ?? null);

/** Súčasné tempo + okrúhle kroky nad ním + prípadný vlastný dopyt. */
const scenarioRows = computed(() => {
    const s = scenarios.value;
    if (!s) return [];
    const rows = [{ ...s.base, current: true }, ...s.ladder.map((r) => ({ ...r, current: false }))];
    if (s.custom) rows.push({ ...s.custom, current: false, custom: true } as never);
    return rows.sort((a, b) => a.monthly - b.monthly);
});

const scenarioMax = computed(() => Math.max(1, ...scenarioRows.value.map((r) => r.real_p50)));

/** O koľko rokov skôr než pri súčasnom tempe. */
function yearsEarlier(row: Scenario): number | null {
    const base = scenarios.value?.base.freedom_year;
    if (!base || !row.freedom_year) return null;
    return base - row.freedom_year;
}

/** Koľko rokov skôr než plánovaný odchod — a či to vôbec vyjde. */
const freedomTone = computed(() => {
    const f = freedom.value;
    if (!f?.reached) return { bg: '#fdeaea', fg: '#c0453f' };
    return f.within_plan ? { bg: '#e6f7ec', fg: '#2ba35a' } : { bg: '#fff6e5', fg: '#b8791a' };
});

/**
 * Meraný stav: koľko ti reálne mesačne ostáva na investovanie. Porovnáva sa
 * bežný tok — teda bez peňazí, ktoré už do portfólia odišli, a bez jednorazoviek.
 */
const contributionGap = computed(() => {
    const m = props.profile.measured;
    if (!m.has_data) return null;
    const available = m.recurring_savings;
    return { available, planned: form.value.monthly, delta: available - form.value.monthly };
});

const chartSeries = computed(() => {
    const s = projection.value?.series ?? [];
    return realMode.value
        ? s.map((p) => ({
              year: p.year,
              p10: p.real_p10,
              p25: p.real_p25,
              p50: p.real_p50,
              p75: p.real_p75,
              p90: p.real_p90,
              contributed: p.real_contributed,
          }))
        : s.map((p) => ({ year: p.year, p10: p.p10, p25: p.p25, p50: p.p50, p75: p.p75, p90: p.p90, contributed: p.contributed }));
});

const finalBlock = computed(() => {
    const f = projection.value?.final;
    if (!f) return null;
    return realMode.value ? f.real : f.nominal;
});

const yearsLeft = computed(() => projection.value?.years ?? Math.max(0, form.value.year - new Date().getFullYear()));

/** Podiel konečnej sumy, ktorý spravil trh (nie vlastné vklady). */
const growthShare = computed(() => {
    const f = projection.value?.final;
    if (!f || !f.nominal.p50) return 0;
    return Math.max(0, Math.round(((f.nominal.p50 - f.contributed) / f.nominal.p50) * 100));
});

const successTone = computed(() => {
    const p = projection.value?.target?.success_pct ?? 0;
    if (p >= 80) return { bg: '#e6f7ec', fg: '#2ba35a' };
    if (p >= 50) return { bg: '#fff6e5', fg: '#b8791a' };
    return { bg: '#fdeaea', fg: '#c0453f' };
});

/** Koľko je dnešných 1 000 € v cieľovom roku — ilustrácia sily inflácie. */
const inflationBite = computed(() => {
    const y = yearsLeft.value;
    const i = form.value.inflation / 100;
    return 1000 / (1 + i) ** y;
});

const engine = computed(() => props.engines.find((e) => e.key === form.value.engine) ?? null);
const engineNote = computed(() => engine.value?.note ?? '');

const labelStyle = 'font-size: 12px; font-weight: 700; color: #8a8c9a; margin-bottom: 6px; display: block';
const inputStyle =
    'width: 100%; background: #f7f6f2; border: 1.5px solid #eceae2; border-radius: 12px; padding: 11px 13px; font-size: 14.5px; font-weight: 700; color: #20212e; outline: none';
</script>

<template>
    <Head title="Dôchodok" />
    <GrosLayout title="Dôchodok" :subtitle="`Kam sa dostaneš do roku ${form.year} — prepočítané na historických dátach`">
        <template #action>
            <button
                type="button"
                style="
                    display: flex;
                    align-items: center;
                    gap: 7px;
                    color: #fff;
                    font-weight: 700;
                    font-size: 14px;
                    padding: 11px 16px;
                    border-radius: 13px;
                    white-space: nowrap;
                "
                :style="{ background: primary, boxShadow: `0 6px 14px ${primarySoft}`, opacity: saving ? 0.6 : 1 }"
                :disabled="saving"
                @click="save"
            >
                {{ saving ? 'Ukladám…' : 'Uložiť plán' }}
            </button>
        </template>

        <div class="gros-rise">
            <!-- ── Hlavný výsledok ─────────────────────────────────────── -->
            <div style="border-radius: 20px; padding: 26px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                <div style="display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 8px">
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9">
                        Stredný scenár v roku {{ form.year }}<span v-if="realMode"> — v dnešných eurách</span>
                    </div>
                    <div style="font-size: 12px; font-weight: 600; opacity: 0.8">o {{ yearsLeft }} rokov</div>
                </div>

                <div v-if="loading && !projection" class="font-display" style="font-weight: 800; font-size: 40px; margin-top: 8px; opacity: 0.5">
                    …
                </div>
                <template v-else-if="projection?.ok && finalBlock">
                    <div class="font-display" style="font-weight: 800; font-size: 40px; letter-spacing: -1.6px; margin-top: 6px">
                        {{ eur(finalBlock.p50) }}
                    </div>
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9; margin-top: 4px">
                        pesimisticky {{ eur(finalBlock.p10) }} · optimisticky {{ eur(finalBlock.p90) }}
                    </div>

                    <div style="display: flex; flex-wrap: wrap; gap: 24px; margin-top: 20px">
                        <div>
                            <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Mesačná renta (dnešné €)</div>
                            <div class="font-display" style="font-weight: 800; font-size: 22px; margin-top: 2px">
                                {{ eur(projection.final.income.p50) }}
                            </div>
                            <div style="font-size: 11px; opacity: 0.8; font-weight: 600; margin-top: 2px">
                                {{ eur(projection.final.income.p10) }} – {{ eur(projection.final.income.p90) }}
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Z toho tvoje vklady</div>
                            <div style="font-weight: 700; font-size: 16px; margin-top: 4px">{{ eur(projection.final.contributed) }}</div>
                            <div style="font-size: 11px; opacity: 0.8; font-weight: 600; margin-top: 2px">
                                trh spravil {{ growthShare }} % konečnej sumy
                            </div>
                        </div>
                        <div v-if="profile.measured.has_data && profile.measured.income > 0">
                            <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Oproti dnešnému príjmu</div>
                            <div style="font-weight: 700; font-size: 16px; margin-top: 4px">
                                {{ num((projection.final.income.p50 / profile.measured.income) * 100) }} %
                            </div>
                            <div style="font-size: 11px; opacity: 0.8; font-weight: 600; margin-top: 2px">
                                dnes zarábaš {{ eur(profile.measured.income) }}/mes.
                            </div>
                        </div>
                    </div>
                </template>
                <div v-else style="font-size: 15px; font-weight: 600; margin-top: 10px; opacity: 0.9">
                    {{ projection?.reason ?? 'Projekciu sa nepodarilo spočítať.' }}
                </div>
            </div>

            <!-- ── Rok slobody ─────────────────────────────────────────── -->
            <Card v-if="freedom" title="Kedy môžeš prestať pracovať" style="margin-top: 14px">
                <template #right>
                    <div style="font-size: 12px; color: #9a9cab; font-weight: 600">pri výdavkoch {{ eur(freedom.monthly_spending) }}/mes.</div>
                </template>

                <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px">
                    <div style="flex: 1.3; min-width: 230px; border-radius: 14px; padding: 18px 20px" :style="{ background: freedomTone.bg }">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Rok slobody</div>
                        <div
                            class="font-display"
                            style="font-weight: 800; font-size: 40px; letter-spacing: -1.4px; margin-top: 4px"
                            :style="{ color: freedomTone.fg }"
                        >
                            {{ freedom.year ?? 'po ' + freedom.horizon_year }}
                        </div>

                        <div v-if="freedom.reached" style="font-size: 12.5px; font-weight: 700; color: #6a6c7a; margin-top: 2px">
                            o {{ freedom.years_from_now }} rokov<template v-if="(freedom.years_earlier ?? 0) > 0">
                                · {{ freedom.years_earlier }} rokov pred plánom</template
                            ><template v-else-if="(freedom.years_earlier ?? 0) < 0">
                                · {{ Math.abs(freedom.years_earlier ?? 0) }} rokov po plánovanom odchode</template
                            ><template v-else> · presne v plánovanom roku</template>
                        </div>
                        <div v-else style="font-size: 12.5px; font-weight: 700; color: #6a6c7a; margin-top: 2px">
                            Pri tomto tempe to nevyjde ani do {{ freedom.horizon_year }}.
                        </div>

                        <div v-if="freedom.reached" style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 8px">
                            v zlom scenári {{ freedom.year_safe ?? 'nedosiahne' }} · v dobrom {{ freedom.year_lucky ?? '—' }}
                        </div>

                        <!-- nevyšlo do plánovaného roku → koľko treba pridať, aby vyšlo -->
                        <div
                            v-if="!freedom.within_plan && freedom.required_monthly !== null"
                            style="
                                border-top: 1px solid rgba(0, 0, 0, 0.07);
                                margin-top: 12px;
                                padding-top: 12px;
                                font-size: 12.5px;
                                font-weight: 600;
                                color: #6a6c7a;
                                line-height: 1.6;
                            "
                        >
                            Aby to vyšlo už v {{ form.year }}, stačí vkladať
                            <b class="font-display" style="font-size: 15px; color: #20212e">{{ eur(freedom.required_monthly) }}</b
                            >/mes.<template v-if="(freedom.required_extra ?? 0) > 0">
                                — o <b>{{ eur(freedom.required_extra ?? 0) }}</b> viac než teraz</template
                            >.
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 210px; background: #f7f6f2; border-radius: 14px; padding: 18px 20px">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Potrebuješ nasporiť</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; margin-top: 4px">{{ eur(freedom.fire_number) }}</div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px">
                            {{ eur(freedom.annual_spending) }} ročne pri výbere {{ num(form.withdrawal, 1) }} %
                        </div>
                        <div style="height: 8px; border-radius: 4px; background: #eceae2; overflow: hidden; margin-top: 12px">
                            <div
                                style="height: 100%; border-radius: 4px"
                                :style="{ width: Math.min(100, freedom.progress_pct) + '%', background: primary }"
                            ></div>
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 6px">
                            máš {{ num(freedom.progress_pct, 1) }} % z toho
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 210px; background: #f7f6f2; border-radius: 14px; padding: 18px 20px">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Coast FIRE</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; margin-top: 4px">{{ freedom.coast_year ?? '—' }}</div>
                        <div
                            v-if="freedom.coast_year"
                            style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 6px; line-height: 1.55"
                        >
                            Od tohto roku už nemusíš vložiť ani euro — to, čo budeš mať, dorastie do cieľa samo do {{ form.year }}. Odvtedy môžeš
                            pokojne zarábať menej.
                        </div>
                        <div v-else style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 6px; line-height: 1.55">
                            Do {{ form.year }} tento bod nenastane, lebo dovtedy nedosiahneš ani samotnú slobodu.
                            <template v-if="freedom.required_monthly !== null">
                                Objaví sa, keď vklad zdvihneš aspoň na {{ eur(freedom.required_monthly) }}/mes.
                            </template>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- ── Vstupy ──────────────────────────────────────────────── -->
            <Card title="Tvoj plán" style="margin-top: 14px">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-top: 16px">
                    <div>
                        <label :style="labelStyle">Rok odchodu</label>
                        <input v-model.number="form.year" type="number" min="2027" max="2120" :style="inputStyle" />
                    </div>
                    <div>
                        <label :style="labelStyle">Dĺžka dôchodku (rokov)</label>
                        <input v-model.number="form.duration" type="number" min="5" max="60" :style="inputStyle" />
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">
                            Odchod {{ form.year }} + {{ form.duration }} = do {{ form.year + form.duration }}
                        </div>
                    </div>
                    <div>
                        <label :style="labelStyle">Mesačný vklad (€)</label>
                        <input v-model.number="form.monthly" type="number" min="0" step="10" :style="inputStyle" />
                        <div v-if="contributions.has_data" style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">
                            Reálne posielaš {{ eur(contributions.recommended) }}/mes.
                            <button
                                v-if="contributionMismatch"
                                type="button"
                                style="color: #2a6ebd; font-weight: 800; text-decoration: underline"
                                @click="useMeasuredContribution"
                            >
                                použiť
                            </button>
                        </div>
                    </div>
                    <div>
                        <label :style="labelStyle">Výdavky v dôchodku (€/mes., dnešné €)</label>
                        <input v-model.number="form.spending" type="number" min="0" step="50" placeholder="merané z transakcií" :style="inputStyle" />
                        <div v-if="profile.measured.has_data" style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">
                            Teraz míňaš {{ eur(profile.measured.expense) }}/mes. (priemer za {{ profile.measured.months }} mes.)
                        </div>
                    </div>
                    <div>
                        <label :style="labelStyle">Historický vzor výnosov</label>
                        <select v-model="form.engine" :style="inputStyle">
                            <option v-for="e in engines" :key="e.key" :value="e.key">{{ e.label }} — {{ e.years }} r.</option>
                        </select>
                    </div>
                </div>

                <div style="font-size: 12px; color: #8a8c9a; font-weight: 600; line-height: 1.55; margin-top: 10px">{{ engineNote }}</div>

                <!-- krátka história je pri 40-ročnej projekcii slabina, nie detail -->
                <div
                    v-if="engine?.short_history"
                    style="
                        display: flex;
                        align-items: flex-start;
                        gap: 9px;
                        background: #fff6e5;
                        border-radius: 13px;
                        padding: 12px 15px;
                        margin-top: 10px;
                    "
                >
                    <span style="font-size: 15px">⚠️</span>
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a6516; line-height: 1.6">
                        Tento rad má len <b>{{ engine.years }} rokov</b> histórie, a projekcia beží na {{ yearsLeft }}. Navyše začína v roku
                        {{ engine.since }}, teda blízko dna krízy — obsahuje takmer výhradne rast, takže výnos vychádza nezvyčajne vysoko.
                        <button
                            type="button"
                            style="font-weight: 800; text-decoration: underline; color: #8a6516"
                            @click="form.engine = longestEngine.key"
                        >
                            Prepnúť na {{ longestEngine.label }} ({{ longestEngine.years }} r.) →
                        </button>
                    </div>
                </div>

                <!-- čo appka nameria z reálnych dát -->
                <div v-if="profile.measured.has_data" style="background: #f7f6f2; border-radius: 14px; padding: 14px 16px; margin-top: 14px">
                    <div style="font-size: 12px; font-weight: 800; color: #20212e">Namerané z tvojich transakcií</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 10px">
                        <div>
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Príjem</div>
                            <div style="font-weight: 800; font-size: 15px; margin-top: 2px">{{ eur(profile.measured.income) }}</div>
                        </div>
                        <div>
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Bežné výdavky</div>
                            <div style="font-weight: 800; font-size: 15px; margin-top: 2px">{{ eur(profile.measured.recurring_expense) }}</div>
                        </div>
                        <div>
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Reálne ti ostáva</div>
                            <div
                                style="font-weight: 800; font-size: 15px; margin-top: 2px"
                                :style="{ color: profile.measured.recurring_savings >= 0 ? '#2ba35a' : '#e8544e' }"
                            >
                                {{ eurS(profile.measured.recurring_savings) }}
                            </div>
                        </div>
                        <div v-if="profile.measured.recurring_savings_rate !== null">
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Miera úspor</div>
                            <div
                                style="font-weight: 800; font-size: 15px; margin-top: 2px"
                                :style="{ color: (profile.measured.recurring_savings_rate ?? 0) >= 0 ? '#2ba35a' : '#e8544e' }"
                            >
                                {{ num(profile.measured.recurring_savings_rate ?? 0, 1) }} %
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="profile.measured.savings_flow > 0 || profile.measured.one_off > 0"
                        style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; line-height: 1.6; margin-top: 10px"
                    >
                        Z hrubých výdavkov {{ eur(profile.measured.expense) }} sme odrátali
                        <template v-if="profile.measured.savings_flow > 0"
                            ><b>{{ eur(profile.measured.savings_flow) }}</b> poslaných do portfólia</template
                        ><template v-if="profile.measured.savings_flow > 0 && profile.measured.one_off > 0"> a </template
                        ><template v-if="profile.measured.one_off > 0"
                            ><b>{{ eur(profile.measured.one_off) }}</b> jednorazoviek</template
                        >. Ani jedno nie je bežná spotreba — prvé si odložil, druhé sa nezopakuje.
                    </div>

                    <div
                        v-if="contributionGap && Math.abs(contributionGap.delta) > 20"
                        style="
                            font-size: 12px;
                            font-weight: 600;
                            line-height: 1.55;
                            margin-top: 12px;
                            padding-top: 12px;
                            border-top: 1px solid #eceae2;
                        "
                        :style="{ color: contributionGap.delta > 0 ? '#6a6c7a' : '#c0453f' }"
                    >
                        <template v-if="contributionGap.delta > 0">
                            Mesačne ti ostáva {{ eurS(contributionGap.available) }}, ale do portfólia posielaš {{ eur(form.monthly) }}. Je tam
                            priestor o {{ eur(contributionGap.delta) }} navyše — pozri nižšie, čo by to spravilo.
                        </template>
                        <template v-else>
                            Plánuješ vkladať {{ eur(form.monthly) }}/mes., ale reálne ti mesačne ostáva {{ eurS(contributionGap.available) }}.
                            Projekcia počíta s plánom, nie s realitou — buď treba znížiť výdavky, alebo znížiť vklad v pláne.
                        </template>
                    </div>
                </div>

                <label style="display: flex; align-items: center; gap: 9px; margin-top: 14px; cursor: pointer">
                    <input v-model="form.index_contributions" type="checkbox" style="width: 17px; height: 17px; accent-color: #6c5ce7" />
                    <span style="font-size: 13.5px; font-weight: 600">Vklad rastie s infláciou (mzda rastie tiež)</span>
                </label>

                <label v-if="profile.investable_cash > 0" style="display: flex; align-items: center; gap: 9px; margin-top: 10px; cursor: pointer">
                    <input v-model="includeCash" type="checkbox" style="width: 17px; height: 17px; accent-color: #6c5ce7" />
                    <span style="font-size: 13.5px; font-weight: 600">
                        Započítať aj {{ eur(profile.investable_cash) }} hotovosti nad šesťmesačnú rezervu
                    </span>
                </label>

                <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; line-height: 1.6; margin-top: 10px">
                    Projekcia štartuje z investičného portfólia {{ eur(profile.assets.portfolio) }}. Hotovosť {{ eur(profile.assets.cash)
                    }}<template v-if="profile.assets.debt > 0"> a dlhy {{ eur(profile.assets.debt) }}</template> do nej nevstupujú — hotovosť
                    nezhodnocuje trh a dlhy sa splácajú z bežného rozpočtu.
                </div>

                <div style="margin-top: 14px">
                    <label :style="labelStyle">Cieľová renta (€/mes., dnešné €) — voliteľné</label>
                    <input v-model.number="form.target_income" type="number" min="0" step="50" placeholder="napr. 1500" :style="inputStyle" />
                </div>

                <div style="height: 1px; background: #f1efe8; margin: 18px 0"></div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px">
                    <div>
                        <label :style="labelStyle">Inflácia (% ročne)</label>
                        <input v-model.number="form.inflation" type="number" min="0" max="20" step="0.1" :style="inputStyle" />
                        <div v-if="inflationHistory" style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">
                            SK priemer 20 r.: {{ num(inflationHistory.avg20, 1) }} % · od 1997: {{ num(inflationHistory.avg, 1) }} %
                        </div>
                    </div>
                    <div>
                        <label :style="labelStyle">Poplatky (% ročne)</label>
                        <input v-model.number="form.fees" type="number" min="0" max="5" step="0.05" :style="inputStyle" />
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">TER ETF + spread, typicky 0,2–0,5 %</div>
                    </div>
                    <div>
                        <label :style="labelStyle">Konzervatívna zrážka (p.b.)</label>
                        <input v-model.number="form.haircut" type="number" min="0" max="8" step="0.25" :style="inputStyle" />
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">
                            O koľko horšia bude budúcnosť než minulosť
                        </div>
                    </div>
                    <div>
                        <label :style="labelStyle">Miera výberu (% ročne)</label>
                        <input v-model.number="form.withdrawal" type="number" min="0.5" max="10" step="0.1" :style="inputStyle" />
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">
                            Koľko si ročne vyberieš. 4 % = klasické pravidlo
                        </div>
                    </div>
                </div>
            </Card>

            <!-- ── Merané tempo vkladov ────────────────────────────────── -->
            <Card v-if="contributions.has_data" title="Koľko naozaj posielaš do portfólia" style="margin-top: 14px">
                <template #right>
                    <span style="font-size: 12px; font-weight: 600; color: #9a9cab">posledných {{ contributions.months }} mesiacov</span>
                </template>

                <div style="display: flex; flex-wrap: wrap; gap: 26px; margin-top: 16px">
                    <div>
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Opakujúce sa tempo</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; margin-top: 3px" :style="{ color: primary }">
                            {{ eur(contributions.recommended) }}
                        </div>
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 2px">medián mesiacov — s týmto počíta plán</div>
                    </div>
                    <div>
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Posledné 3 mesiace</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; margin-top: 3px">
                            {{ eur(contributions.recent3 ?? 0) }}
                        </div>
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 2px">kontrola, či medián sedí</div>
                    </div>
                    <div v-if="(contributions.lumps?.length ?? 0) > 0">
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Obyčajný priemer</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; margin-top: 3px; color: #b0b2bd">
                            {{ eur(contributions.mean ?? 0) }}
                        </div>
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 2px">nepoužívame — nafukujú ho jednorazovky</div>
                    </div>
                </div>

                <!-- mesačné stĺpce, jednorazové vklady odlíšené -->
                <div style="display: flex; align-items: flex-end; gap: 4px; height: 72px; margin-top: 20px">
                    <div
                        v-for="m in contributions.series"
                        :key="m.ym"
                        style="flex: 1; min-width: 0; border-radius: 4px 4px 0 0"
                        :style="{
                            height: Math.max(2, (Math.abs(m.amount) / contributionMax) * 100) + '%',
                            background: m.amount < 0 ? '#e8544e' : (contributions.lumps ?? []).some((l) => l.ym === m.ym) ? '#dcdace' : primary,
                        }"
                        :title="`${m.label}: ${eur(m.amount)}`"
                    ></div>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 11px; font-weight: 700; color: #b0b2bd; margin-top: 6px">
                    <span>{{ contributions.series[0]?.label }}</span>
                    <span>{{ contributions.series[contributions.series.length - 1]?.label }}</span>
                </div>

                <!-- kupuje sa, ale nezapisuje — meranie aj hodnota portfólia potom klamú -->
                <div
                    v-if="staleHoldings.length"
                    style="
                        display: flex;
                        align-items: flex-start;
                        gap: 9px;
                        background: #fff6e5;
                        border-radius: 13px;
                        padding: 13px 16px;
                        margin-top: 16px;
                    "
                >
                    <span style="font-size: 15px">⚠️</span>
                    <div style="flex: 1; min-width: 0; font-size: 12.5px; font-weight: 600; color: #8a6516; line-height: 1.6">
                        Tempo sa meria z nákupov na stránke Investície, nie z kategórie výdavkov — len nákupy hovoria, čo naozaj vlastníš. Tieto
                        pozície ale dlho nedostali nový nákup:
                        <div
                            v-for="s in staleHoldings"
                            :key="s.id"
                            style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 8px"
                        >
                            <b>{{ s.ticker }}</b>
                            <span style="font-weight: 600">posledný {{ s.last_purchase }} — pred {{ s.months_since }} mesiacmi</span>
                            <button
                                type="button"
                                style="
                                    margin-left: auto;
                                    font-size: 11.5px;
                                    font-weight: 800;
                                    padding: 5px 10px;
                                    border-radius: 9px;
                                    background: rgba(138, 101, 22, 0.14);
                                    color: #8a6516;
                                    white-space: nowrap;
                                "
                                @click="stopTracking(s.id)"
                            >
                                Už doň neprispievam
                            </button>
                        </div>
                        <div style="margin-top: 8px">
                            Ak doň naopak stále prispievaš, tie nákupy tu chýbajú a tempo aj hodnota portfólia sú podhodnotené.
                            <Link href="/investments" style="font-weight: 800; text-decoration: underline; color: #8a6516">Doplniť nákupy →</Link>
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="contributions.reconciliation?.mismatch"
                    style="
                        display: flex;
                        align-items: flex-start;
                        gap: 9px;
                        background: #fff6e5;
                        border-radius: 13px;
                        padding: 13px 16px;
                        margin-top: 16px;
                    "
                >
                    <span style="font-size: 15px">⚠️</span>
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a6516; line-height: 1.6">
                        Ako nákupy máš zapísaných {{ eur(contributions.reconciliation.recorded) }}, ale do kategórie Investície si zaúčtoval
                        {{ eur(contributions.reconciliation.booked) }}. Rozdiel {{ eur(Math.abs(contributions.reconciliation.difference)) }} znamená,
                        že jeden zo záznamov nie je úplný.
                    </div>
                </div>

                <div
                    v-if="(contributions.lumps?.length ?? 0) > 0"
                    style="
                        display: flex;
                        align-items: flex-start;
                        gap: 9px;
                        background: #f7f6f2;
                        border-radius: 13px;
                        padding: 13px 16px;
                        margin-top: 16px;
                    "
                >
                    <span style="font-size: 15px">💡</span>
                    <div style="font-size: 12.5px; font-weight: 600; color: #6a6c7a; line-height: 1.6">
                        <template v-for="(l, i) in contributions.lumps ?? []" :key="l.ym"
                            ><b>{{ l.label }}</b> {{ eur(l.amount)
                            }}<template v-if="i < (contributions.lumps?.length ?? 0) - 1">, </template></template
                        >
                        — spolu {{ num(contributions.lump_share ?? 0, 0) }} % objemu za celé obdobie. Sú to jednorazové vklady, nie tempo. Keby plán
                        počítal s obyčajným priemerom {{ eur(contributions.mean ?? 0) }}, predpokladal by, že takto posielaš každý mesiac — a výsledok
                        by bol nafúknutý. Preto sa berie medián.
                    </div>
                </div>
            </Card>

            <!-- ── Graf ────────────────────────────────────────────────── -->
            <Card title="Vývoj do dôchodku" style="margin-top: 14px">
                <template #right>
                    <div style="display: flex; background: #f7f6f2; border-radius: 11px; padding: 3px">
                        <button
                            v-for="opt in [
                                { k: true, l: 'Dnešné €' },
                                { k: false, l: 'Nominálne' },
                            ]"
                            :key="String(opt.k)"
                            type="button"
                            style="font-size: 12.5px; font-weight: 700; padding: 7px 13px; border-radius: 9px"
                            :style="
                                realMode === opt.k
                                    ? { background: '#fff', color: '#20212e', boxShadow: '0 2px 6px rgba(60,55,40,0.08)' }
                                    : { color: '#9a9cab' }
                            "
                            @click="realMode = opt.k"
                        >
                            {{ opt.l }}
                        </button>
                    </div>
                </template>

                <div v-if="loading && !projection" style="padding: 60px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px">
                    Simulujem tisíce scenárov na historických dátach…
                </div>
                <div
                    v-else-if="projection?.ok"
                    style="margin-top: 14px; opacity: 1"
                    :style="{ opacity: loading ? 0.55 : 1, transition: 'opacity .2s' }"
                >
                    <FanChart :series="chartSeries" />
                    <div style="font-size: 12px; color: #8a8c9a; font-weight: 600; line-height: 1.6; margin-top: 12px">
                        <template v-if="realMode">
                            Sumy sú prepočítané na dnešnú kúpnu silu pri inflácii {{ num(form.inflation, 1) }} % ročne — čísla hovoria, čo si za ne
                            kúpiš dnes.
                        </template>
                        <template v-else>
                            Sumy sú nominálne, teda čísla, ktoré uvidíš na účte v roku {{ form.year }}. Dnešných 1 000 € bude vtedy mať kúpnu silu
                            {{ eur(inflationBite) }}.
                        </template>
                    </div>
                </div>
            </Card>

            <!-- ── Vydrží mi to? ───────────────────────────────────────── -->
            <Card v-if="withdrawal" title="Vydrží mi to?" style="margin-top: 14px">
                <template #right>
                    <span style="font-size: 12px; font-weight: 600; color: #9a9cab">
                        {{ withdrawal.duration }} rokov dôchodku · výber {{ eur(withdrawal.annual_withdrawal) }}/rok
                    </span>
                </template>

                <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px">
                    <div style="flex: 1.2; min-width: 230px; border-radius: 14px; padding: 18px 20px" :style="{ background: survivalTone.bg }">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Šanca, že peniaze vydržia</div>
                        <div class="font-display" style="font-weight: 800; font-size: 38px; letter-spacing: -1.2px; margin-top: 4px" :style="{ color: survivalTone.fg }">
                            {{ num(withdrawal.success_pct, 1) }} %
                        </div>
                        <div style="font-size: 12px; color: #6a6c7a; font-weight: 600; line-height: 1.55; margin-top: 6px">
                            <template v-if="withdrawal.depleted_year">
                                V neúspešných cestách dôjdu okolo roku <b>{{ withdrawal.depleted_year }}</b> — teda po
                                {{ withdrawal.depleted_after_years }} rokoch dôchodku.
                            </template>
                            <template v-else>Žiadna zo simulovaných ciest nedopadla tak, že by peniaze došli.</template>
                        </div>
                    </div>
                    <div style="flex: 1; min-width: 200px; background: #f7f6f2; border-radius: 14px; padding: 18px 20px">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Medián zostatku na konci</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; margin-top: 4px">{{ eur(withdrawal.median_left) }}</div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; line-height: 1.55; margin-top: 6px">
                            v dnešných eurách. Vysoký zostatok znamená, že v polovici scenárov by si mohol míňať viac.
                        </div>
                    </div>
                </div>

                <!-- bezpečná miera výberu pre TÚTO dĺžku dôchodku -->
                <div style="font-size: 12.5px; font-weight: 800; color: #20212e; margin-top: 20px">
                    Aká miera výberu je pri {{ withdrawal.duration }} rokoch bezpečná
                </div>
                <div style="font-size: 12px; color: #8a8c9a; font-weight: 600; line-height: 1.6; margin-top: 5px">
                    Pravidlo 4 % pochádza z amerických dát a <b>30-ročného</b> dôchodku. Toto je prepočet na tvoju dĺžku, tvoje výdavky a
                    historický rad, ktorý máš zvolený.
                </div>

                <div style="display: flex; flex-direction: column; gap: 7px; margin-top: 14px">
                    <div
                        v-for="r in withdrawal.rates"
                        :key="r.rate"
                        style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; border-radius: 12px; padding: 11px 15px"
                        :style="
                            withdrawal.safe_rate && r.rate === withdrawal.safe_rate.rate
                                ? { background: '#e6f7ec', border: '1.5px solid #2ba35a' }
                                : { background: '#f7f6f2' }
                        "
                    >
                        <span class="font-display" style="font-weight: 800; font-size: 15px; min-width: 54px">{{ num(r.rate, 2) }} %</span>
                        <span style="font-size: 12.5px; font-weight: 600; color: #6a6c7a">treba {{ eur(r.needed) }}</span>
                        <span style="margin-left: auto; display: flex; align-items: center; gap: 10px">
                            <span style="width: 90px; height: 7px; border-radius: 4px; background: #eceae2; overflow: hidden; display: block">
                                <span style="display: block; height: 100%; border-radius: 4px" :style="{ width: r.success_pct + '%', background: r.success_pct >= withdrawal.target ? '#2ba35a' : '#e8954e' }"></span>
                            </span>
                            <span class="font-display" style="font-weight: 800; font-size: 14px; min-width: 52px; text-align: right" :style="{ color: r.success_pct >= withdrawal.target ? '#2ba35a' : '#8a8c9a' }">
                                {{ num(r.success_pct, 1) }} %
                            </span>
                        </span>
                    </div>
                </div>

                <div
                    style="display: flex; align-items: flex-start; gap: 9px; border-radius: 13px; padding: 13px 16px; margin-top: 14px"
                    :style="withdrawal.safe_rate ? { background: '#f7f6f2' } : { background: '#fdeaea' }"
                >
                    <span style="font-size: 15px">{{ withdrawal.safe_rate ? '🎯' : '⚠️' }}</span>
                    <div style="font-size: 12.5px; font-weight: 600; color: #6a6c7a; line-height: 1.6">
                        <template v-if="withdrawal.safe_rate">
                            Aby ti to vydržalo s pravdepodobnosťou aspoň {{ num(withdrawal.target, 0) }} %, vyberaj najviac
                            <b>{{ num(withdrawal.safe_rate.rate, 2) }} %</b> ročne — teda nasporiť
                            <b>{{ eur(withdrawal.safe_rate.needed) }}</b>.
                            <template v-if="withdrawal.current_rate > withdrawal.safe_rate.rate">
                                Máš nastavené {{ num(withdrawal.current_rate, 1) }} %, čo je viac.
                            </template>
                        </template>
                        <template v-else>
                            Pri {{ withdrawal.duration }} rokoch nedosiahne {{ num(withdrawal.target, 0) }} % úspešnosť ani výber 2,75 %. Skrátiť
                            dôchodok, znížiť výdavky alebo nasporiť viac.
                        </template>
                    </div>
                </div>

                <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; line-height: 1.65; margin-top: 14px">
                    Simuluje sa <b>súvislá cesta</b> od dneška cez sporenie až po posledný výber — tá istá náhodná postupnosť trhu, vrátane
                    prechodu medzi fázami. Kým sporíš, prepad na začiatku je výhodný (vklady nakupujú lacno); pri výbere je ten istý prepad
                    najhorší možný, lebo musíš predávať na dne a ten kapitál sa už zotavenia nezúčastní. Preto priemerný výnos o prežití
                    portfólia nehovorí takmer nič a rozhoduje poradie.
                </div>
            </Card>

            <!-- ── Čo keby som vkladal viac ────────────────────────────── -->
            <Card v-if="scenarios && scenarioRows.length" title="Čo keby som vkladal viac" style="margin-top: 14px">
                <template #right>
                    <div style="display: flex; align-items: center; gap: 8px">
                        <span style="font-size: 12px; font-weight: 600; color: #9a9cab">vlastná suma</span>
                        <input
                            v-model.number="compare"
                            type="number"
                            min="0"
                            step="25"
                            placeholder="napr. 250"
                            style="
                                width: 110px;
                                background: #f7f6f2;
                                border: 1.5px solid #eceae2;
                                border-radius: 11px;
                                padding: 8px 11px;
                                font-size: 13.5px;
                                font-weight: 700;
                                color: #20212e;
                                outline: none;
                            "
                        />
                    </div>
                </template>

                <div style="display: flex; flex-direction: column; gap: 9px; margin-top: 16px">
                    <div
                        v-for="row in scenarioRows"
                        :key="row.monthly"
                        style="border-radius: 14px; padding: 14px 16px"
                        :style="row.current ? { background: '#f7f6f2', border: `1.5px solid ${primary}` } : { background: '#f7f6f2' }"
                    >
                        <div style="display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap">
                            <span class="font-display" style="font-weight: 800; font-size: 17px; min-width: 92px">{{ eur(row.monthly) }}</span>
                            <span
                                v-if="row.current"
                                style="font-size: 10.5px; font-weight: 800; color: #fff; padding: 3px 8px; border-radius: 20px"
                                :style="{ background: primary }"
                                >TERAZ</span
                            >
                            <span v-else-if="row.extra" style="font-size: 11.5px; font-weight: 700; color: #2ba35a">+{{ eur(row.extra) }}/mes.</span>

                            <span style="margin-left: auto; display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap">
                                <span>
                                    <span style="font-size: 11px; color: #8a8c9a; font-weight: 700">renta </span>
                                    <span class="font-display" style="font-weight: 800; font-size: 16px">{{ eur(row.income_p50) }}</span>
                                </span>
                                <span v-if="row.freedom_year">
                                    <span style="font-size: 11px; color: #8a8c9a; font-weight: 700">sloboda </span>
                                    <span class="font-display" style="font-weight: 800; font-size: 16px">{{ row.freedom_year }}</span>
                                    <span v-if="(yearsEarlier(row) ?? 0) > 0" style="font-size: 11.5px; font-weight: 700; color: #2ba35a">
                                        · o {{ yearsEarlier(row) }} r. skôr
                                    </span>
                                </span>
                            </span>
                        </div>

                        <div style="height: 8px; border-radius: 4px; background: #eceae2; overflow: hidden; margin-top: 10px">
                            <div
                                style="height: 100%; border-radius: 4px"
                                :style="{ width: (row.real_p50 / scenarioMax) * 100 + '%', background: row.current ? primary : '#b8b6ac' }"
                            ></div>
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 7px">
                            V roku {{ form.year }} by si mal {{ eur(row.real_p50) }} v dnešných eurách · vložil by si {{ eur(row.contributed) }}
                        </div>
                    </div>
                </div>

                <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; line-height: 1.6; margin-top: 14px">
                    Všetko sú stredné scenáre v dnešných eurách. Rok slobody je prvý rok, v ktorom by portfólio pokrylo tvoje výdavky pri výbere
                    {{ num(form.withdrawal, 1) }} % ročne. Táto tabuľka nemení tvoj uložený plán — len ukazuje, čo by iné tempo spravilo.
                </div>
            </Card>

            <!-- ── Koľko z plánu stojí na krypte ───────────────────────── -->
            <Card v-if="projection?.without_volatile" title="Čo keby krypto skončilo na nule" style="margin-top: 14px">
                <template #right>
                    <span style="font-size: 12px; font-weight: 600; color: #9a9cab">
                        {{ num(projection.without_volatile.excluded_share, 0) }} % portfólia
                    </span>
                </template>

                <div style="font-size: 12.5px; color: #8a8c9a; font-weight: 600; line-height: 1.6; margin-top: 10px">
                    Projekcia počíta s výnosom diverzifikovaného akciového indexu. Tvoje portfólio je ale z veľkej časti krypto, ktoré sa správa inak.
                    Toto nie je predpoveď, že o všetko prídeš — je to odpoveď na otázku, koľko z tvojho plánu na tom jednom aktíve stojí.
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px">
                    <div style="flex: 1; min-width: 200px; background: #f7f6f2; border-radius: 14px; padding: 16px 18px">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">S krypto­menami</div>
                        <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 5px" :style="{ color: primary }">
                            {{ eur(projection.final.real.p50) }}
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 4px">
                            renta {{ eur(projection.final.income.p50) }}<template v-if="freedom?.year"> · sloboda {{ freedom.year }}</template>
                        </div>
                    </div>
                    <div style="flex: 1; min-width: 200px; background: #fdeaea; border-radius: 14px; padding: 16px 18px">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Bez nich</div>
                        <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 5px; color: #c0453f">
                            {{ eur(projection.without_volatile.real_p50 ?? 0) }}
                        </div>
                        <div style="font-size: 11.5px; color: #8a6a6a; font-weight: 600; margin-top: 4px">
                            renta {{ eur(projection.without_volatile.income_p50 ?? 0)
                            }}<template v-if="projection.without_volatile.freedom_year">
                                · sloboda {{ projection.without_volatile.freedom_year }}</template
                            >
                        </div>
                    </div>
                </div>

                <div
                    style="
                        display: flex;
                        align-items: flex-start;
                        gap: 9px;
                        background: #f7f6f2;
                        border-radius: 13px;
                        padding: 13px 16px;
                        margin-top: 12px;
                    "
                >
                    <span style="font-size: 15px">🎯</span>
                    <div style="font-size: 12.5px; font-weight: 600; color: #6a6c7a; line-height: 1.6">
                        <template v-if="projection.without_volatile.years_later">
                            Bez krypta by sloboda prišla o <b>{{ projection.without_volatile.years_later }} rokov neskôr</b>. Toľko rokov práce dnes
                            stojí na {{ eur(projection.without_volatile.excluded) }} v jednom aktíve.
                        </template>
                        <template v-else>
                            Rozdiel je {{ eur((projection.final.real.p50 ?? 0) - (projection.without_volatile.real_p50 ?? 0)) }} v dnešných eurách.
                        </template>
                        Čím viac nových peňazí pôjde do ETF, tým menej bude plán závisieť od jedného titulu — a to sa deje samo, aj bez predaja.
                    </div>
                </div>
            </Card>

            <!-- ── Cieľ ────────────────────────────────────────────────── -->
            <Card v-if="projection?.ok && projection.target" title="Dosiahneš svoj cieľ?" style="margin-top: 14px">
                <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px">
                    <div style="flex: 1; min-width: 190px; border-radius: 14px; padding: 16px 18px" :style="{ background: successTone.bg }">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Šanca na rentu {{ eur(projection.target.income) }}/mes.</div>
                        <div class="font-display" style="font-weight: 800; font-size: 30px; margin-top: 6px" :style="{ color: successTone.fg }">
                            {{ num(projection.target.success_pct, 1) }} %
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 4px">
                            scenárov končí nad cieľom pri vklade {{ eur(form.monthly) }}/mes.
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 190px; background: #f7f6f2; border-radius: 14px; padding: 16px 18px">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Stačí vkladať (stredný scenár)</div>
                        <div class="font-display" style="font-weight: 800; font-size: 30px; margin-top: 6px">
                            {{ projection.target.required_monthly === null ? '—' : eur(projection.target.required_monthly) }}
                        </div>
                        <div
                            v-if="projection.target.required_delta !== null"
                            style="font-size: 11.5px; font-weight: 700; margin-top: 4px"
                            :style="{ color: projection.target.required_delta > 0 ? '#e8544e' : '#2ba35a' }"
                        >
                            {{
                                projection.target.required_delta > 0
                                    ? 'o ' + eur(projection.target.required_delta) + ' viac než teraz'
                                    : 'o ' + eur(Math.abs(projection.target.required_delta)) + ' menej než teraz'
                            }}
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 190px; background: #f7f6f2; border-radius: 14px; padding: 16px 18px">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Na istotu (90 % scenárov)</div>
                        <div class="font-display" style="font-weight: 800; font-size: 30px; margin-top: 6px">
                            {{ projection.target.required_monthly_safe === null ? '—' : eur(projection.target.required_monthly_safe) }}
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 4px">
                            vklad, pri ktorom cieľ vyjde aj v zlých dekádach
                        </div>
                    </div>
                </div>
            </Card>

            <!-- ── Odkiaľ sú čísla ─────────────────────────────────────── -->
            <Card v-if="projection?.ok" title="Odkiaľ sú čísla" style="margin-top: 14px">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 16px">
                    <div style="background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Historický výnos</div>
                        <div class="font-display" style="font-weight: 800; font-size: 22px; margin-top: 4px">
                            {{ num(projection.engine.cagr, 1) }} % p.a.
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px">
                            {{ projection.engine.label }}<br />{{ projection.engine.from }} – {{ projection.engine.to }} ·
                            {{ num(projection.engine.years, 0) }} rokov
                        </div>
                    </div>
                    <div style="background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Po poplatkoch a zrážke</div>
                        <div class="font-display" style="font-weight: 800; font-size: 22px; margin-top: 4px">
                            {{ num(projection.engine.net_cagr, 1) }} % p.a.
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px">
                            −{{ num(projection.engine.drag, 2) }} p.b. ročne
                        </div>
                    </div>
                    <div style="background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Reálne, po inflácii</div>
                        <div
                            class="font-display"
                            style="font-weight: 800; font-size: 22px; margin-top: 4px"
                            :style="{ color: projection.engine.real_cagr >= 0 ? '#2ba35a' : '#e8544e' }"
                        >
                            {{ num(projection.engine.real_cagr, 1) }} % p.a.
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px">skutočný rast kúpnej sily</div>
                    </div>
                    <div style="background: #f7f6f2; border-radius: 14px; padding: 14px 16px">
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Kolísavosť trhu</div>
                        <div class="font-display" style="font-weight: 800; font-size: 22px; margin-top: 4px">
                            {{ num(projection.engine.vol, 1) }} %
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px">
                            najhorší mesiac {{ num(projection.engine.worst_month, 1) }} %
                        </div>
                    </div>
                </div>

                <div
                    style="
                        background: #f7f6f2;
                        border-radius: 14px;
                        padding: 16px 18px;
                        margin-top: 12px;
                        font-size: 12.5px;
                        color: #6a6c7a;
                        font-weight: 600;
                        line-height: 1.7;
                    "
                >
                    <div style="font-weight: 800; color: #20212e; margin-bottom: 6px">Ako to počítame</div>
                    Neberieme jeden „priemerný výnos". Namiesto toho {{ num(projection.paths) }}× náhodne poskladáme budúcnosť z reálnych
                    {{ projection.engine.months }} mesiacov histórie indexu {{ projection.engine.label }} — po dvanásťmesačných blokoch, aby ostali
                    zachované aj krízy a zotavenia tak, ako naozaj prebehli. Z toho vzniknú pásma: stredný scenár je medián, tmavší pás pokrýva
                    polovicu scenárov, svetlejší 80 %. <br /><br />
                    Infláciu {{ num(projection.inflation.used, 1) }} % berieme z reálnych dát ECB (HICP Slovensko,
                    {{ projection.inflation.sk_from }} – {{ projection.inflation.sk_to }}, priemer za 20 rokov
                    {{ num(projection.inflation.sk_avg20 ?? 0, 1) }} %, eurozóna {{ num(projection.inflation.eu_avg20 ?? 0, 1) }} %). Mesačná renta
                    vychádza z pravidla {{ num(form.withdrawal, 1) }} % — toľko si ročne vyberáš z portfólia, aby ti vydržalo. <br /><br />
                    <b style="color: #20212e">Čo to nie je:</b> záruka. Posledné dekády boli pre akcie mimoriadne priaznivé, preto je v modeli
                    konzervatívna zrážka {{ num(form.haircut, 2) }} p.b. Model neráta dane z výnosov, zmeny menového kurzu ani to, že do dôchodku
                    prídeš aj s iným majetkom či štátnym dôchodkom.
                </div>
            </Card>

            <AskAi style="margin-top: 14px" :questions="['Ako som na tom s dôchodkovým plánom?', 'Čo najviac ovplyvní, kedy budem slobodný?']" />
        </div>
    </GrosLayout>
</template>
