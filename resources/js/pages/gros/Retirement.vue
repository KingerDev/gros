<script setup lang="ts">
import Card from '@/components/gros/Card.vue';
import ScenarioChart from '@/components/gros/ScenarioChart.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

interface Plan {
    year: number;
    birth_year: number | null;
    monthly: number;
    withdrawal: number | null;
    spending: number | null;
    housing: number | null;
    crypto_mode: CryptoMode;
}
type CryptoMode = 'exclude' | 'flat' | 'stocks';
interface Recommended {
    rate: number;
    duration: number | null;
    age: number | null;
}
/** Pravidlo vkladu, ako ho spočítal server. */
interface Rule {
    mode: 'fixed' | 'percent';
    pct: number;
    income: number;
    fixed: number;
    base: number;
    monthly: number;
    measured: number;
    later: { from: number; income: number | null; fixed: number; fixed_is_own: boolean; base: number | null; monthly: number | null } | null;
}
interface Freedom {
    monthly_spending: number;
    fire_number: number;
    progress_pct: number;
    earliest: number | null;
    year: number | null;
    latest: number | null;
    within_plan: boolean;
    required_monthly: number | null;
    required_extra: number | null;
    required_from: number | null;
    horizon_year: number;
}
interface Scenario {
    key: 'low' | 'mid' | 'high';
    label: string;
    real_return: number;
    final: number;
    income: number;
    freedom_year: number | null;
}
interface Projection {
    ok: boolean;
    params: {
        year: number;
        monthly: number;
        withdrawal: number;
        withdrawal_recommended: Recommended;
        withdrawal_is_recommended: boolean;
        spending: number | null;
    };
    rule: Rule;
    crypto: { value: number; mode: CryptoMode; counted: number };
    crypto_alternatives: Partial<Record<CryptoMode, { final: number; freedom_year: number | null }>>;
    years: number;
    start_value: number;
    contributed: number;
    series: { year: number; contributed: number; low: number; mid: number; high: number }[];
    scenarios: Scenario[];
    freedom: Freedom | null;
}

interface Profile {
    measured: {
        months: number;
        window: number;
        income: number;
        expense: number;
        savings_flow: number;
        consumption: number;
        savings: number;
        savings_rate: number | null;
        has_data: boolean;
    };
    assets: { portfolio: number; cash: number; debt: number; net_worth: number };
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

interface SpendingBasis {
    consumption: number;
    housing_now: number;
    housing_later: number | null;
    auto: number;
    missing_housing: boolean;
    months: number;
}

const props = defineProps<{
    plan: Plan & { withdrawal_recommended: Recommended };
    rule: Rule;
    spending_basis: SpendingBasis;
    profile: Profile;
    contributions: Contributions;
    allocation: { other_pct: number; crypto_pct: number; stock_pct: number };
    averageWage: { year: number; gross: number; net_estimate: number; source: string; url: string };
}>();

const { eur, eurS, num, grad, primary, primarySoft } = useGros();

const form = ref<Plan>({
    year: props.plan.year,
    birth_year: props.plan.birth_year,
    monthly: props.plan.monthly,
    withdrawal: props.plan.withdrawal,
    spending: props.plan.spending,
    housing: props.plan.housing,
    crypto_mode: props.plan.crypto_mode,
});

const cryptoOptions: { key: CryptoMode; label: string; note: string }[] = [
    { key: 'exclude', label: 'Nerátať', note: 'Plán stojí len na ETF a vkladoch. Čokoľvek z krypta je bonus navyše.' },
    { key: 'flat', label: 'Dnešná hodnota', note: 'Krypto si udrží dnešnú kúpnu silu — bez rastu aj bez prepadu.' },
    { key: 'stocks', label: 'Ako akcie', note: 'Rovnaké 3 / 5 / 7 % ako zvyšok. Pre krypto optimistický predpoklad bez opory v dátach.' },
];
const cryptoValue = computed(() => projection.value?.crypto.value ?? 0);
const cryptoAlternatives = computed(() => projection.value?.crypto_alternatives ?? {});

/** Vyplní príjem neskôr odhadom čistej priemernej mzdy. */
function useAverageWage() {
    ruleForm.value.later_income = props.averageWage.net_estimate;
}

/** Ako sa určuje mesačný vklad — pevná suma, alebo podiel z príjmu. */
const ruleForm = ref({
    mode: props.rule.mode,
    pct: props.rule.pct,
    later_on: props.rule.later !== null,
    later_from: props.rule.later?.from ?? new Date().getFullYear() + 3,
    later_income: props.rule.later?.income ?? null,
    later_fixed: props.rule.later?.fixed_is_own ? props.rule.later.fixed : null,
    later_monthly: props.rule.later?.monthly ?? null,
});

/** Prázdne pole z inputu príde ako '' — pre server je to „nezadané". */
const val = (v: unknown): string => (v === null || v === undefined || v === '' ? '' : String(v));

/** Parametre pravidla vkladu — posielajú sa vždy všetky, aj prázdne. */
function ruleParams(): Record<string, string> {
    const r = ruleForm.value;
    return {
        contribution_mode: r.mode,
        contribution_pct: val(r.pct),
        contribution_later_from: r.later_on ? val(r.later_from) : '',
        contribution_later_income: r.later_on ? val(r.later_income) : '',
        contribution_later_fixed: r.later_on ? val(r.later_fixed) : '',
        contribution_later_monthly: r.later_on ? val(r.later_monthly) : '',
    };
}
/** Započítať hotovosť nad cieľ rezervy do štartovacej sumy. */
const includeCash = ref(false);
const projection = ref<Projection | null>(null);
const loading = ref(true);
const saving = ref(false);

let timer: ReturnType<typeof setTimeout> | undefined;

async function load() {
    loading.value = true;
    const q = new URLSearchParams({
        year: String(form.value.year),
        birth_year: val(form.value.birth_year),
        monthly: String(form.value.monthly || 0),
        withdrawal: val(form.value.withdrawal),
        include_cash: includeCash.value ? '1' : '0',
        crypto_mode: form.value.crypto_mode,
        ...ruleParams(),
    });
    if (form.value.spending) q.set('spending', String(form.value.spending));
    if (form.value.housing !== null && (form.value.housing as unknown) !== '') q.set('housing', String(form.value.housing));

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
    [form, ruleForm, includeCash],
    () => {
        clearTimeout(timer);
        timer = setTimeout(load, 300);
    },
    { deep: true },
);

function save() {
    saving.value = true;
    router.post(
        '/retirement',
        {
            ...form.value,
            monthly: form.value.monthly || 0,
            birth_year: val(form.value.birth_year) || null,
            withdrawal: val(form.value.withdrawal) || null,
            spending: form.value.spending || null,
            housing: val(form.value.housing) || null,
            ...Object.fromEntries(Object.entries(ruleParams()).map(([k, v]) => [k, v === '' ? null : v])),
        },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}

const freedom = computed(() => projection.value?.freedom ?? null);
const byKey = computed(() => Object.fromEntries((projection.value?.scenarios ?? []).map((s) => [s.key, s])) as Record<string, Scenario>);
const mid = computed(() => byKey.value.mid ?? null);
const low = computed(() => byKey.value.low ?? null);
const high = computed(() => byKey.value.high ?? null);

const chartLabels = computed(() => ({
    low: `${low.value?.label ?? 'Opatrný'} ${num(low.value?.real_return ?? 3, 0)} %`,
    mid: `${mid.value?.label ?? 'Stredný'} ${num(mid.value?.real_return ?? 5, 0)} %`,
    high: `${high.value?.label ?? 'Priaznivý'} ${num(high.value?.real_return ?? 7, 0)} %`,
}));

/** Rozpätie rokov slobody — od priaznivého po opatrný scenár. */
const freedomRange = computed(() => {
    const f = freedom.value;
    if (!f || f.earliest === null) return null;
    if (f.latest === null) return `${f.earliest} – po ${f.horizon_year}`;
    return f.earliest === f.latest ? String(f.earliest) : `${f.earliest} – ${f.latest}`;
});

const freedomTone = computed(() => {
    const f = freedom.value;
    if (!f || f.year === null) return { bg: '#fdeaea', fg: '#c0453f' };
    return f.within_plan ? { bg: '#e6f7ec', fg: '#2ba35a' } : { bg: '#fff6e5', fg: '#b8791a' };
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

/** Pravidlo vkladu po poslednom prepočte (server) — pred ním to, čo prišlo so stránkou. */
const rule = computed<Rule>(() => projection.value?.rule ?? props.rule);
/** Vklad, s ktorým projekcia naozaj počíta. */
const effectiveMonthly = computed(() => rule.value.monthly);

/** Odporúčaná miera výberu a s akou sa naozaj počíta. */
const recommended = computed<Recommended>(() => projection.value?.params.withdrawal_recommended ?? props.plan.withdrawal_recommended);
const withdrawalUsed = computed(() => projection.value?.params.withdrawal ?? form.value.withdrawal ?? recommended.value.rate);

/** Plánovaný vklad oproti tomu, čo ti po spotrebe naozaj ostáva. */
const contributionGap = computed(() => {
    const m = props.profile.measured;
    if (!m.has_data) return null;
    return { available: m.savings, planned: effectiveMonthly.value, delta: m.savings - effectiveMonthly.value };
});

/** Rozpis automatických výdavkov v dôchodku — prepočíta sa hneď, ako zmeníš bývanie. */
const spendingBasis = computed(() => {
    const b = props.spending_basis;
    const later = form.value.housing === null || (form.value.housing as unknown) === '' ? null : Number(form.value.housing);
    const auto = later === null ? b.consumption : Math.max(0, b.consumption - b.housing_now) + later;
    return { ...b, housing_later: later, auto, missing_housing: later === null && b.housing_now < 50 };
});
const autoSpending = computed(() => spendingBasis.value.auto);

/** Odkiaľ je mesačný vklad — zmeraný z nákupov, alebo vlastný. */
const monthlySource = computed<'measured' | 'own'>(() =>
    ruleForm.value.mode === 'fixed' && props.contributions.has_data && Math.abs(props.contributions.recommended - form.value.monthly) <= 5
        ? 'measured'
        : 'own',
);

type Source = 'measured' | 'own' | 'assumption';
const sourceLabel: Record<Source, string> = { measured: 'namerané', own: 'tvoje nastavenie', assumption: 'predpoklad' };
const sourceStyle: Record<Source, Record<string, string>> = {
    measured: { background: '#e6f7ec', color: '#2ba35a' },
    own: { background: '#eef6ff', color: '#2a6ebd' },
    assumption: { background: '#f1efe8', color: '#8a8c9a' },
};

/** Každý vstup projekcie: hodnota a odkiaľ je. */
const inputRows = computed(() => {
    const b = spendingBasis.value;
    const m = props.profile.measured;
    const rows: { label: string; value: string; source: Source; note: string }[] = [
        {
            label: 'Štartovacia suma',
            value: eur(projection.value?.start_value ?? props.profile.assets.portfolio),
            source: 'measured',
            note:
                `Portfólio ${eur(props.profile.assets.portfolio)} podľa stránky Investície` +
                (cryptoValue.value > 0 && form.value.crypto_mode === 'exclude' ? `, bez krypta ${eur(cryptoValue.value)}` : '') +
                (includeCash.value ? ` + hotovosť nad cieľ rezervy ${eur(props.profile.investable_cash)}` : '') +
                '. Dlhy sa nerátajú.',
        },
        ...(cryptoValue.value > 0
            ? [
                  {
                      label: 'Krypto',
                      value: cryptoOptions.find((o) => o.key === form.value.crypto_mode)?.label ?? '',
                      source: 'own' as Source,
                      note: `${eur(cryptoValue.value)} — ${cryptoOptions.find((o) => o.key === form.value.crypto_mode)?.note ?? ''}`,
                  },
              ]
            : []),
        {
            label: 'Mesačný vklad',
            value: eur(effectiveMonthly.value) + '/mes.',
            source: monthlySource.value,
            note:
                rule.value.mode === 'percent'
                    ? `${num(rule.value.pct, 0)} % z príjmu ${eur(rule.value.income)} po povinných platbách ${eur(rule.value.fixed)} (splátky z Úverov).`
                    : monthlySource.value === 'measured'
                      ? `Medián tvojich nákupov za posledných ${props.contributions.months} mesiacov.`
                      : props.contributions.has_data
                        ? `Zadal si sám; podľa nákupov reálne posielaš ${eur(props.contributions.recommended)}/mes.`
                        : 'Zadal si sám — v portfóliu zatiaľ nie sú nákupy, z ktorých by sa dal zmerať.',
        },
        ...(rule.value.later
            ? [
                  {
                      label: `Vklad od roku ${rule.value.later.from}`,
                      value: rule.value.later.monthly === null ? 'nezadané' : eur(rule.value.later.monthly) + '/mes.',
                      source: 'own' as Source,
                      note:
                          rule.value.mode === 'percent'
                              ? rule.value.later.income === null
                                  ? 'Doplň čistý príjem po škole.'
                                  : `${num(rule.value.pct, 0)} % z príjmu ${eur(rule.value.later.income)} po povinných platbách ${eur(rule.value.later.fixed)}${rule.value.later.fixed_is_own ? '' : ' (splátky + bývanie neskôr)'}.` +
                                    (rule.value.later.income === props.averageWage.net_estimate
                                        ? ` Príjem je odhad čistej priemernej mzdy — hrubá ${eur(props.averageWage.gross)} za rok ${props.averageWage.year} (${props.averageWage.source}).`
                                        : '')
                              : 'Zadal si sám.',
                  },
              ]
            : []),
        {
            label: 'Výdavky v dôchodku',
            value: eur(form.value.spending || b.auto) + '/mes.',
            source: form.value.spending ? 'own' : 'measured',
            note: form.value.spending
                ? 'Zadal si sám.'
                : b.housing_later === null
                  ? `Dnešná spotreba za ${b.months} mes. (bez vkladov do portfólia, s jednorazovkami aj dovolenkami).`
                  : `Spotreba ${eur(b.consumption)} − dnešné bývanie ${eur(b.housing_now)} + bývanie neskôr ${eur(b.housing_later)}.`,
        },
        {
            label: 'Dnešné bývanie',
            value: eur(b.housing_now) + '/mes.',
            source: 'measured',
            note: 'Priemer kategórie Bývanie a jej podkategórií.',
        },
        {
            label: 'Bývanie neskôr',
            value: b.housing_later === null ? 'nezadané' : eur(b.housing_later) + '/mes.',
            source: 'own',
            note: 'Nahradí dnešné bývanie vo výdavkoch v dôchodku.',
        },
        { label: 'Rok odchodu', value: String(form.value.year), source: 'own', note: `O ${yearsLeft.value} rokov.` },
        {
            label: 'Miera výberu',
            value: num(withdrawalUsed.value, 2) + ' %',
            source: val(form.value.withdrawal) === '' ? 'assumption' : 'own',
            note:
                val(form.value.withdrawal) !== ''
                    ? 'Zadal si sám.'
                    : recommended.value.duration === null
                      ? 'Bežné pravidlo pre 30-ročný dôchodok. Doplň rok narodenia, nech sa prispôsobí dĺžke tvojho dôchodku.'
                      : `Odporúčaná pre dôchodok od ${recommended.value.age} do 90 rokov (${recommended.value.duration} r.).`,
        },
        {
            label: 'Výnos',
            value: '3 / 5 / 7 %',
            source: 'assumption',
            note: 'Ročne po inflácii aj poplatkoch — opatrný, stredný a priaznivý scenár.',
        },
        { label: 'Štátny dôchodok', value: 'neráta sa', source: 'own', note: 'Na desaťročia dopredu sa naň nespoliehame.' },
        {
            label: 'Dane z výnosov',
            value: 'nerátajú sa',
            source: 'assumption',
            note: 'Zjednodušenie — prípadná daň pri predaji investícií sa neodráta.',
        },
    ];
    if (m.has_data) {
        rows.push({
            label: 'Príjem',
            value: eur(m.income) + '/mes.',
            source: 'measured',
            note: `Priemer za ${m.months} mes. bez prijatých pôžičiek. Do projekcie nevstupuje, slúži na porovnanie s vkladom.`,
        });
    }
    return rows;
});

const yearsLeft = computed(() => projection.value?.years ?? Math.max(0, form.value.year - new Date().getFullYear()));

const labelStyle = 'font-size: 12px; font-weight: 700; color: #8a8c9a; margin-bottom: 6px; display: block';
const inputStyle =
    'width: 100%; background: #f7f6f2; border: 1.5px solid #eceae2; border-radius: 12px; padding: 11px 13px; font-size: 14.5px; font-weight: 700; color: #20212e; outline: none';
</script>

<template>
    <Head title="Dôchodok" />
    <GrosLayout title="Dôchodok" :subtitle="`Kam sa dostaneš do roku ${form.year} — v troch scenároch, v dnešných eurách`">
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
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9">V roku {{ form.year }}, v dnešných eurách</div>
                    <div style="font-size: 12px; font-weight: 600; opacity: 0.8">o {{ yearsLeft }} rokov</div>
                </div>

                <div v-if="loading && !projection" class="font-display" style="font-weight: 800; font-size: 40px; margin-top: 8px; opacity: 0.5">
                    …
                </div>
                <template v-else-if="projection?.ok && mid && low && high">
                    <div class="font-display" style="font-weight: 800; font-size: 40px; letter-spacing: -1.6px; margin-top: 6px">
                        {{ eur(low.final) }} – {{ eur(high.final) }}
                    </div>
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9; margin-top: 4px">
                        pri {{ num(mid.real_return, 0) }} % ročne {{ eur(mid.final) }} · rozpätie je {{ num(low.real_return, 0) }} až
                        {{ num(high.real_return, 0) }} % po inflácii
                    </div>

                    <div style="display: flex; flex-wrap: wrap; gap: 24px; margin-top: 20px">
                        <div>
                            <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Mesačne pri výbere {{ num(withdrawalUsed, 2) }} %</div>
                            <div class="font-display" style="font-weight: 800; font-size: 22px; margin-top: 2px">
                                {{ eur(low.income) }} – {{ eur(high.income) }}
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Z toho tvoje vklady</div>
                            <div style="font-weight: 700; font-size: 16px; margin-top: 4px">{{ eur(projection.contributed) }}</div>
                        </div>
                    </div>
                </template>
                <div v-else style="font-size: 15px; font-weight: 600; margin-top: 10px; opacity: 0.9">Projekciu sa nepodarilo spočítať.</div>
            </div>

            <!-- dnešná spotreba bez bývania o výdavkoch v dôchodku nič nehovorí -->
            <div
                v-if="spendingBasis.missing_housing && !form.spending"
                style="
                    display: flex;
                    align-items: flex-start;
                    gap: 10px;
                    background: #fff6e5;
                    border-radius: 16px;
                    padding: 15px 18px;
                    margin-top: 14px;
                "
            >
                <span style="font-size: 16px">⚠️</span>
                <div style="font-size: 12.5px; font-weight: 600; color: #8a6516; line-height: 1.6">
                    Dnes za bývanie neplatíš ({{ eur(spendingBasis.housing_now) }}/mes.), takže výdavky v dôchodku vychádzajú len
                    {{ eur(spendingBasis.auto) }}/mes. To je takmer isto málo. Doplň v pláne nižšie, koľko by si platil za bývanie — priemerná
                    ponuková cena nájmu bytu je podľa Deloitte Rent Index (Q1 2026) 736 € na Slovensku a 948 € v Bratislave. Kým to nevyplníš, sú
                    čísla nižšie príliš optimistické.
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
                            style="font-weight: 800; font-size: 36px; letter-spacing: -1.2px; margin-top: 4px"
                            :style="{ color: freedomTone.fg }"
                        >
                            {{ freedomRange ?? 'po ' + freedom.horizon_year }}
                        </div>
                        <div style="font-size: 12.5px; font-weight: 700; color: #6a6c7a; margin-top: 2px">
                            <template v-if="freedom.year !== null">
                                pri {{ num(mid?.real_return ?? 5, 0) }} % ročne okolo roku {{ freedom.year
                                }}<template v-if="freedom.within_plan"> — stihne sa do plánovaného odchodu</template
                                ><template v-else> — po plánovanom odchode</template>
                            </template>
                            <template v-else>Pri tomto tempe to v strednom scenári nevyjde ani do {{ freedom.horizon_year }}.</template>
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
                            Aby to v strednom scenári vyšlo už v {{ form.year }}, treba<template v-if="freedom.required_from">
                                od roku {{ freedom.required_from }}</template
                            >
                            vkladať
                            <b class="font-display" style="font-size: 15px; color: #20212e">{{ eur(freedom.required_monthly) }}</b
                            >/mes.<template v-if="(freedom.required_extra ?? 0) > 0">
                                — o <b>{{ eur(freedom.required_extra ?? 0) }}</b> viac než teraz</template
                            >.
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 210px; background: #f7f6f2; border-radius: 14px; padding: 18px 20px">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Potrebuješ nasporiť</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; margin-top: 4px">{{ eur(freedom.fire_number) }}</div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; margin-top: 3px; line-height: 1.5">
                            {{ eur(freedom.monthly_spending * 12) }} ročne pri výbere {{ num(withdrawalUsed, 2) }} %
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
                </div>
            </Card>

            <!-- ── Graf ────────────────────────────────────────────────── -->
            <Card v-if="projection?.ok" title="Vývoj do dôchodku" style="margin-top: 14px">
                <template #right>
                    <span style="font-size: 12px; font-weight: 600; color: #9a9cab">dnešné eurá</span>
                </template>
                <div style="margin-top: 14px">
                    <ScenarioChart :series="projection.series" :labels="chartLabels" />
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
                        <label :style="labelStyle">Rok narodenia</label>
                        <input v-model.number="form.birth_year" type="number" min="1920" max="2025" placeholder="napr. 2004" :style="inputStyle" />
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">na dĺžku dôchodku a mieru výberu</div>
                    </div>
                    <div>
                        <label :style="labelStyle">Výdavky v dôchodku (€/mes., dnešné €)</label>
                        <input
                            v-model.number="form.spending"
                            type="number"
                            min="0"
                            step="50"
                            :placeholder="'automaticky ' + eur(autoSpending)"
                            :style="inputStyle"
                        />
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">
                            <template v-if="form.spending">tvoja suma</template>
                            <template v-else>spotreba + bývanie, rozpis nižšie</template>
                        </div>
                    </div>
                    <div>
                        <label :style="labelStyle">Bývanie neskôr (€/mes., dnešné €)</label>
                        <input v-model.number="form.housing" type="number" min="0" step="50" placeholder="napr. 700" :style="inputStyle" />
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">
                            dnes platíš {{ eur(spendingBasis.housing_now) }}/mes.
                        </div>
                    </div>
                    <div>
                        <label :style="labelStyle">Miera výberu (% ročne)</label>
                        <input
                            v-model.number="form.withdrawal"
                            type="number"
                            min="0.5"
                            max="10"
                            step="0.05"
                            :placeholder="'odporúčané ' + num(recommended.rate, 2) + ' %'"
                            :style="inputStyle"
                        />
                        <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 5px">
                            <template v-if="recommended.duration !== null"
                                >dôchodok {{ recommended.duration }} r. → {{ num(recommended.rate, 2) }} %</template
                            >
                            <template v-else>4 % platí pre 30-ročný dôchodok</template>
                        </div>
                    </div>
                </div>

                <!-- ── Mesačný vklad ── -->
                <div style="background: #f7f6f2; border-radius: 14px; padding: 15px 17px; margin-top: 14px">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
                        <div style="font-size: 12.5px; font-weight: 800; color: #20212e">Mesačný vklad</div>
                        <div style="display: flex; background: #eceae2; border-radius: 10px; padding: 3px; margin-left: auto">
                            <button
                                v-for="opt in [
                                    { k: 'percent', l: '% z príjmu' },
                                    { k: 'fixed', l: 'Pevná suma' },
                                ] as const"
                                :key="opt.k"
                                type="button"
                                style="font-size: 12px; font-weight: 700; padding: 7px 12px; border-radius: 8px; white-space: nowrap"
                                :style="ruleForm.mode === opt.k ? { background: '#fff', color: '#20212e' } : { color: '#9a9cab' }"
                                @click="ruleForm.mode = opt.k"
                            >
                                {{ opt.l }}
                            </button>
                        </div>
                    </div>

                    <template v-if="ruleForm.mode === 'percent'">
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 12px">
                            <input
                                v-model.number="ruleForm.pct"
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                :style="inputStyle + '; width: 90px; background: #fff'"
                            />
                            <span style="font-size: 13px; font-weight: 700; color: #6a6c7a">% z príjmu po povinných platbách</span>
                        </div>
                        <div style="font-size: 12px; color: #6a6c7a; font-weight: 600; line-height: 1.6; margin-top: 10px">
                            Príjem {{ eur(rule.income) }} − povinné platby {{ eur(rule.fixed) }} (splátky z Úverov) = {{ eur(rule.base) }} →
                            <b style="color: #20212e">vklad {{ eur(rule.monthly) }}/mes.</b>
                        </div>
                    </template>
                    <template v-else>
                        <input
                            v-model.number="form.monthly"
                            type="number"
                            min="0"
                            step="10"
                            :style="inputStyle + '; background: #fff; margin-top: 12px'"
                        />
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
                    </template>

                    <label style="display: flex; align-items: center; gap: 9px; margin-top: 14px; cursor: pointer">
                        <input v-model="ruleForm.later_on" type="checkbox" style="width: 17px; height: 17px; accent-color: #6c5ce7" />
                        <span style="font-size: 13px; font-weight: 600">Od istého roku sa zmení príjem (napr. po škole)</span>
                    </label>
                    <div
                        v-if="ruleForm.later_on"
                        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 12px"
                    >
                        <div>
                            <label :style="labelStyle">Od roku</label>
                            <input
                                v-model.number="ruleForm.later_from"
                                type="number"
                                min="2026"
                                max="2120"
                                :style="inputStyle + '; background: #fff'"
                            />
                        </div>
                        <template v-if="ruleForm.mode === 'percent'">
                            <div>
                                <label :style="labelStyle">Čistý príjem (€/mes.)</label>
                                <input
                                    v-model.number="ruleForm.later_income"
                                    type="number"
                                    min="0"
                                    step="50"
                                    placeholder="napr. 1300"
                                    :style="inputStyle + '; background: #fff'"
                                />
                                <button
                                    type="button"
                                    style="
                                        font-size: 11px;
                                        font-weight: 800;
                                        color: #2a6ebd;
                                        text-decoration: underline;
                                        margin-top: 5px;
                                        text-align: left;
                                    "
                                    @click="useAverageWage"
                                >
                                    priemerná mzda SR ≈ {{ eur(averageWage.net_estimate) }} čistého
                                </button>
                            </div>
                            <div>
                                <label :style="labelStyle">Povinné platby (€/mes.)</label>
                                <input
                                    v-model.number="ruleForm.later_fixed"
                                    type="number"
                                    min="0"
                                    step="50"
                                    :placeholder="'automaticky ' + eur(rule.later?.fixed ?? rule.fixed)"
                                    :style="inputStyle + '; background: #fff'"
                                />
                            </div>
                        </template>
                        <div v-else>
                            <label :style="labelStyle">Vklad od toho roku (€/mes.)</label>
                            <input
                                v-model.number="ruleForm.later_monthly"
                                type="number"
                                min="0"
                                step="10"
                                :style="inputStyle + '; background: #fff'"
                            />
                        </div>
                    </div>
                    <div
                        v-if="ruleForm.later_on && rule.later"
                        style="font-size: 12px; color: #6a6c7a; font-weight: 600; line-height: 1.6; margin-top: 10px"
                    >
                        <template v-if="rule.mode === 'percent' && rule.later.base !== null">
                            Od {{ rule.later.from }}: príjem {{ eur(rule.later.income ?? 0) }} − povinné platby {{ eur(rule.later.fixed)
                            }}<template v-if="!rule.later.fixed_is_own"> (splátky + bývanie neskôr)</template> = {{ eur(rule.later.base) }} →
                            <b style="color: #20212e">vklad {{ eur(rule.later.monthly ?? 0) }}/mes.</b>
                        </template>
                        <template v-else-if="rule.later.monthly === null"
                            >Doplň {{ rule.mode === 'percent' ? 'príjem' : 'vklad' }} od roku {{ rule.later.from }}.</template
                        >
                        <template v-else>Od {{ rule.later.from }} vklad {{ eur(rule.later.monthly) }}/mes.</template>
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
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Spotreba</div>
                            <div style="font-weight: 800; font-size: 15px; margin-top: 2px">{{ eur(profile.measured.consumption) }}</div>
                        </div>
                        <div>
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Ostáva ti</div>
                            <div
                                style="font-weight: 800; font-size: 15px; margin-top: 2px"
                                :style="{ color: profile.measured.savings >= 0 ? '#2ba35a' : '#e8544e' }"
                            >
                                {{ eurS(profile.measured.savings) }}
                            </div>
                        </div>
                        <div v-if="profile.measured.savings_rate !== null">
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Miera úspor</div>
                            <div
                                style="font-weight: 800; font-size: 15px; margin-top: 2px"
                                :style="{ color: (profile.measured.savings_rate ?? 0) >= 0 ? '#2ba35a' : '#e8544e' }"
                            >
                                {{ num(profile.measured.savings_rate ?? 0, 1) }} %
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="profile.measured.savings_flow > 0"
                        style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; line-height: 1.6; margin-top: 10px"
                    >
                        Z hrubých výdavkov {{ eur(profile.measured.expense) }} sme odrátali <b>{{ eur(profile.measured.savings_flow) }}</b> poslaných
                        do portfólia — tie nie sú spotreba, len presun. Jednorazové výdavky aj dovolenky v spotrebe ostávajú, lebo nejaké prídu vždy.
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
                            priestor o {{ eur(contributionGap.delta) }} navyše.
                        </template>
                        <template v-else>
                            Plánuješ vkladať {{ eur(form.monthly) }}/mes., ale reálne ti mesačne ostáva {{ eurS(contributionGap.available) }}.
                            Projekcia počíta s plánom, nie s realitou — buď treba znížiť výdavky, alebo znížiť vklad v pláne.
                        </template>
                    </div>
                </div>

                <label v-if="profile.investable_cash > 0" style="display: flex; align-items: center; gap: 9px; margin-top: 14px; cursor: pointer">
                    <input v-model="includeCash" type="checkbox" style="width: 17px; height: 17px; accent-color: #6c5ce7" />
                    <span style="font-size: 13.5px; font-weight: 600">
                        Započítať aj {{ eur(profile.investable_cash) }} hotovosti nad cieľ rezervy
                    </span>
                </label>

                <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; line-height: 1.6; margin-top: 10px">
                    Projekcia štartuje z investičného portfólia {{ eur(profile.assets.portfolio) }}. Hotovosť {{ eur(profile.assets.cash)
                    }}<template v-if="profile.assets.debt > 0"> a dlhy {{ eur(profile.assets.debt) }}</template> do nej nevstupujú — hotovosť
                    nezhodnocuje trh a dlhy sa splácajú z bežného rozpočtu.
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

            <!-- ── Krypto v pláne ──────────────────────────────────────── -->
            <Card v-if="cryptoValue > 0" title="Krypto v pláne" style="margin-top: 14px">
                <template #right>
                    <span style="font-size: 12px; font-weight: 600; color: #9a9cab"
                        >{{ eur(cryptoValue) }} · {{ num(allocation.crypto_pct, 0) }} % portfólia</span
                    >
                </template>
                <div style="font-size: 12.5px; color: #6a6c7a; font-weight: 600; line-height: 1.65; margin-top: 10px">
                    Scenáre 3 / 5 / 7 % vychádzajú z dlhodobých výnosov rozložených akcií. Pre krypto taký údaj neexistuje — za pár rokov môže stúpnuť
                    niekoľkonásobne aj stratiť väčšinu hodnoty. Preto si vyber, ako s ním plán počíta:
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; margin-top: 14px">
                    <button
                        v-for="o in cryptoOptions"
                        :key="o.key"
                        type="button"
                        style="text-align: left; border-radius: 14px; padding: 14px 16px; border: 1.5px solid transparent"
                        :style="form.crypto_mode === o.key ? { background: '#f1effc', borderColor: primary } : { background: '#f7f6f2' }"
                        @click="form.crypto_mode = o.key"
                    >
                        <div style="font-size: 13px; font-weight: 800">{{ o.label }}</div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; line-height: 1.5; margin-top: 3px">{{ o.note }}</div>
                        <div v-if="cryptoAlternatives[o.key]" style="font-size: 12px; font-weight: 700; color: #20212e; margin-top: 8px">
                            sloboda {{ cryptoAlternatives[o.key]?.freedom_year ?? '—' }} · v {{ form.year }}
                            {{ eur(cryptoAlternatives[o.key]?.final ?? 0) }}
                        </div>
                    </button>
                </div>
                <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; line-height: 1.6; margin-top: 10px">
                    Čísla pri voľbách sú stredný scenár. Rozdiel medzi nimi ukazuje, koľko z plánu stojí na krypte.
                </div>
            </Card>

            <!-- jednotlivé akcie — scenáre počítajú s rozloženými ETF -->
            <div
                v-if="allocation.stock_pct >= 20"
                style="
                    display: flex;
                    align-items: flex-start;
                    gap: 10px;
                    background: #fff6e5;
                    border-radius: 16px;
                    padding: 15px 18px;
                    margin-top: 14px;
                "
            >
                <span style="font-size: 16px">⚠️</span>
                <div style="font-size: 12.5px; font-weight: 600; color: #8a6516; line-height: 1.6">
                    Jednotlivé akcie tvoria {{ num(allocation.stock_pct, 0) }} % portfólia. Scenáre 3 / 5 / 7 % platia pre rozložené ETF — jedna firma
                    môže dopadnúť výrazne lepšie aj horšie.
                </div>
            </div>

            <!-- ── Scenáre ─────────────────────────────────────────────── -->
            <Card v-if="projection?.ok" title="Tri scenáre" style="margin-top: 14px">
                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 14px">
                    <div
                        v-for="s in projection.scenarios"
                        :key="s.key"
                        style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap; border-radius: 13px; padding: 13px 16px"
                        :style="{ background: s.key === 'mid' ? '#f1effc' : '#f7f6f2' }"
                    >
                        <div style="min-width: 120px">
                            <div style="font-size: 13px; font-weight: 800">{{ s.label }}</div>
                            <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600">{{ num(s.real_return, 0) }} % ročne po inflácii</div>
                        </div>
                        <div style="min-width: 110px">
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">V roku {{ form.year }}</div>
                            <div class="font-display" style="font-size: 17px; font-weight: 800">{{ eur(s.final) }}</div>
                        </div>
                        <div style="min-width: 100px">
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Mesačne</div>
                            <div class="font-display" style="font-size: 17px; font-weight: 800">{{ eur(s.income) }}</div>
                        </div>
                        <div v-if="freedom" style="min-width: 90px">
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Sloboda</div>
                            <div class="font-display" style="font-size: 17px; font-weight: 800">{{ s.freedom_year ?? '—' }}</div>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- ── Z čoho to počítame ──────────────────────────────────── -->
            <Card title="Z čoho to počítame" style="margin-top: 14px">
                <div style="display: flex; flex-direction: column; margin-top: 10px">
                    <div
                        v-for="row in inputRows"
                        :key="row.label"
                        style="display: flex; align-items: flex-start; gap: 12px; padding: 11px 0; border-bottom: 1px solid #f5f4ef"
                    >
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 13px; font-weight: 700">{{ row.label }}</div>
                            <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; line-height: 1.5; margin-top: 2px">{{ row.note }}</div>
                        </div>
                        <div style="text-align: right; flex-shrink: 0">
                            <div class="font-display" style="font-size: 14.5px; font-weight: 800">{{ row.value }}</div>
                            <span
                                style="
                                    display: inline-block;
                                    font-size: 10.5px;
                                    font-weight: 800;
                                    padding: 2px 8px;
                                    border-radius: 20px;
                                    margin-top: 4px;
                                "
                                :style="sourceStyle[row.source]"
                                >{{ sourceLabel[row.source] }}</span
                            >
                        </div>
                    </div>
                </div>
            </Card>

            <!-- ── Ako to počítame ─────────────────────────────────────── -->
            <Card title="Ako to počítame" style="margin-top: 14px">
                <div style="font-size: 12.5px; color: #6a6c7a; font-weight: 600; line-height: 1.7; margin-top: 12px">
                    Nikto nevie, aký výnos trh prinesie na 30–40 rokov, a práve od toho výsledok závisí najviac. Preto appka nepredstiera presnosť a
                    ukazuje tri predpoklady: 3, 5 a 7 % ročne po inflácii aj poplatkoch. Stredných 5 % zodpovedá dlhodobému reálnemu výnosu svetových
                    akcií za vyše sto rokov (Dimson, Marsh, Staunton); posledné dekády boli lepšie, ale na to sa spoliehať nedá. <br /><br />
                    Všetko je v dnešných eurách — vklad aj výdavky berieme, ako keby rástli s infláciou. Suma potrebná na slobodu je ročné výdavky
                    delené mierou výberu. So štátnym dôchodkom sa nepočíta — ak nejaký príde, je to rezerva navyše. <br /><br />
                    Výnosy predpokladajú rozložené svetové akciové ETF. S kryptom sa počíta podľa tvojej voľby v karte Krypto v pláne — na neho sa
                    dlhodobý výnos predpokladať nedá. <br /><br />
                    <b style="color: #20212e">Čo to nie je:</b> predpoveď ani záruka. Model neráta s daňami, s kolísaním trhu tesne pred odchodom ani
                    s tým, že sa ti zmení príjem či výdavky.
                </div>
            </Card>
        </div>
    </GrosLayout>
</template>
