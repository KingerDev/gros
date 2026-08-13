<script setup lang="ts">
import AskAi from '@/components/gros/AskAi.vue';
import Card from '@/components/gros/Card.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Factor {
    key: string;
    label: string;
    delta: number;
    note: string;
}
interface Milestone {
    key: string;
    label: string;
    amount: number;
    note: string;
    reached: boolean;
    progress_pct: number;
    missing: number;
    monthly_basis?: number;
    is_estimate?: boolean;
    from_rent_index?: boolean;
}
interface RentRef {
    label: string;
    rent: number;
}
interface AfterSchool {
    available: boolean;
    rent: number | null;
    rent_full: number | null;
    rent_share: number;
    basis: string | null;
    current_without_housing: number;
    housing_now: number;
    estimate: number | null;
    source: string;
    url: string;
    cities: Record<string, RentRef>;
    sizes: Record<string, RentRef>;
    sr_average: number;
    income: { source: string; url: string; gross: Record<string, number>; net_ratio: number };
}
interface Profile {
    income_type: string;
    graduation_year: number | null;
    post_graduation_expenses: number | null;
    after_school_city: string | null;
    after_school_size: string | null;
    after_school_share: number;
    household: string;
    unemployment_benefit: boolean;
    health_risk: boolean;
    source: string;
    account_id: number | null;
    essential_category_ids: number[] | null;
    recurring_transaction_ids: number[];
    months_override: number | null;
}
interface Report {
    profile: Profile;
    expenses: {
        months_counted: number;
        essential: number;
        total: number;
        discretionary: number;
        essential_share: number | null;
        loan_payments: number;
        loans: { name: string; payment: number; next_payment: string | null; color: string }[];
        savings_excluded: number;
        one_offs: {
            id: number;
            date: string;
            category_id: number | null;
            note: string | null;
            amount: number;
            treat_as_recurring: boolean;
            monthly_impact: number;
        }[];
        one_off_monthly: number;
        breakdown: { category_id: number; monthly: number }[];
        has_data: boolean;
    };
    income: { months: number; average: number; volatility: number | null; is_volatile: boolean; worst: number | null };
    months: { months: number; recommended: number; raw: number; clamped: boolean; overridden: boolean; factors: Factor[] };
    target: number;
    held: number;
    covered_months: number | null;
    gap: number;
    progress_pct: number;
    milestones: Milestone[];
    after_school: AfterSchool;
    plan: {
        gap: number;
        monthly_surplus: number;
        options: { share: number; monthly: number; months: number; investing: number }[];
        possible: boolean;
    };
    cost: {
        real_return: number;
        annual_opportunity: number;
        max_drawdown: number | null;
        forced_sale_loss: number | null;
        portfolio_value: number;
    };
    sources: { label: string; url: string }[];
}

const props = defineProps<{
    report: Report;
    accounts: { id: number; name: string; balance: number | string }[];
    expenseCategories: { id: number; name: string; parent_id: number | null; color: string; icon: string | null }[];
    essentialIds: number[];
}>();

const { eur, num, grad, primary, primarySoft, hexToRgba } = useGros();

const form = ref({
    income_type: props.report.profile.income_type,
    graduation_year: props.report.profile.graduation_year,
    post_graduation_expenses: props.report.profile.post_graduation_expenses,
    after_school_city: props.report.profile.after_school_city,
    after_school_size: props.report.profile.after_school_size,
    after_school_share: props.report.profile.after_school_share,
    household: props.report.profile.household,
    unemployment_benefit: props.report.profile.unemployment_benefit,
    health_risk: props.report.profile.health_risk,
    source: props.report.profile.source,
    account_id: props.report.profile.account_id,
    months_override: props.report.profile.months_override,
    essential_category_ids: [...props.essentialIds],
    recurring_transaction_ids: [...props.report.profile.recurring_transaction_ids],
});

/** Prepne, či sa označený výdavok berie ako opakujúci sa (a teda ráta). */
function toggleRecurring(id: number) {
    const i = form.value.recurring_transaction_ids.indexOf(id);
    if (i >= 0) form.value.recurring_transaction_ids.splice(i, 1);
    else form.value.recurring_transaction_ids.push(id);
    save();
}
const saving = ref(false);
const showSettings = ref(false);

function save() {
    saving.value = true;
    router.post('/reserve', { ...form.value }, { preserveScroll: true, onFinish: () => (saving.value = false) });
}

function toggleCategory(id: number) {
    const i = form.value.essential_category_ids.indexOf(id);
    if (i >= 0) form.value.essential_category_ids.splice(i, 1);
    else form.value.essential_category_ids.push(id);
}

/** Skupiny s podkategóriami — na označovanie nevyhnutných výdavkov. */
const categoryGroups = computed(() =>
    props.expenseCategories
        .filter((c) => c.parent_id === null)
        .map((g) => ({ ...g, children: props.expenseCategories.filter((c) => c.parent_id === g.id) })),
);

const catName = (id: number) => props.expenseCategories.find((c) => c.id === id)?.name ?? '—';
const catColor = (id: number) => props.expenseCategories.find((c) => c.id === id)?.color ?? '#94a3b8';

/** Prvý nedosiahnutý míľnik — na ňom sa oplatí sústrediť. */
const nextMilestone = computed(() => props.report.milestones.find((m) => !m.reached) ?? null);

const statusTone = computed(() => {
    const pct = props.report.progress_pct;
    if (pct >= 100) return { bg: '#e6f7ec', fg: '#2ba35a', label: 'Rezerva je hotová' };
    if (pct >= 50) return { bg: '#fff6e5', fg: '#b8791a', label: 'Rozostavaná' };
    return { bg: '#fdeaea', fg: '#c0453f', label: 'Chýba rezerva' };
});

const labelStyle = 'font-size: 12px; font-weight: 700; color: #8a8c9a; margin-bottom: 6px; display: block';
const inputStyle =
    'width: 100%; background: #f7f6f2; border: 1.5px solid #eceae2; border-radius: 12px; padding: 11px 13px; font-size: 14.5px; font-weight: 700; color: #20212e; outline: none';
</script>

<template>
    <Head title="Rezerva" />
    <GrosLayout title="Rezerva" subtitle="Koľko núdzového fondu má zmysel držať práve tebe">
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
                {{ saving ? 'Ukladám…' : 'Uložiť' }}
            </button>
        </template>

        <div class="gros-rise">
            <!-- ── Hlavné číslo ────────────────────────────────────────── -->
            <div style="border-radius: 20px; padding: 26px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                <div style="font-size: 13px; font-weight: 600; opacity: 0.9">Tvoj cieľ rezervy</div>
                <div class="font-display" style="font-weight: 800; font-size: 40px; letter-spacing: -1.6px; margin-top: 6px">
                    {{ eur(report.target) }}
                </div>
                <div style="font-size: 13px; font-weight: 600; opacity: 0.9; margin-top: 4px">
                    {{ num(report.months.months, 0) }} mesiacov nevyhnutných výdavkov po {{ eur(report.expenses.essential) }}
                </div>

                <div style="height: 10px; border-radius: 5px; background: rgba(255, 255, 255, 0.25); overflow: hidden; margin-top: 18px">
                    <div style="height: 100%; border-radius: 5px; background: #fff" :style="{ width: report.progress_pct + '%' }"></div>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 24px; margin-top: 16px">
                    <div>
                        <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Máš odložené</div>
                        <div style="font-weight: 700; font-size: 17px; margin-top: 2px">{{ eur(report.held) }}</div>
                    </div>
                    <div v-if="report.covered_months !== null">
                        <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Vydržíš</div>
                        <div style="font-weight: 700; font-size: 17px; margin-top: 2px">{{ num(report.covered_months, 1) }} mes.</div>
                    </div>
                    <div v-if="report.gap > 0">
                        <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Chýba</div>
                        <div style="font-weight: 700; font-size: 17px; margin-top: 2px">{{ eur(report.gap) }}</div>
                    </div>
                    <div v-else>
                        <div style="font-size: 11.5px; opacity: 0.85; font-weight: 600">Stav</div>
                        <div style="font-weight: 700; font-size: 17px; margin-top: 2px">Hotovo ✓</div>
                    </div>
                </div>
            </div>

            <!-- ── Míľniky ─────────────────────────────────────────────── -->
            <Card title="Rezerva sa nestavia naraz" style="margin-top: 14px">
                <template #right>
                    <span
                        style="font-size: 11.5px; font-weight: 800; padding: 4px 10px; border-radius: 20px"
                        :style="{ background: statusTone.bg, color: statusTone.fg }"
                    >
                        {{ statusTone.label }}
                    </span>
                </template>

                <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px">
                    <div
                        v-for="m in report.milestones"
                        :key="m.key"
                        style="border-radius: 14px; padding: 15px 17px"
                        :style="
                            m.reached
                                ? { background: '#e6f7ec' }
                                : nextMilestone?.key === m.key
                                  ? { background: '#f7f6f2', border: `1.5px solid ${primary}` }
                                  : { background: '#f7f6f2' }
                        "
                    >
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
                            <span style="font-size: 15px">{{ m.reached ? '✅' : nextMilestone?.key === m.key ? '🎯' : '⚪' }}</span>
                            <span style="font-size: 14px; font-weight: 800">{{ m.label }}</span>
                            <span class="font-display" style="font-size: 15px; font-weight: 800; margin-left: auto">{{ eur(m.amount) }}</span>
                        </div>
                        <div v-if="!m.reached" style="height: 7px; border-radius: 4px; background: #eceae2; overflow: hidden; margin-top: 10px">
                            <div style="height: 100%; border-radius: 4px" :style="{ width: m.progress_pct + '%', background: primary }"></div>
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; line-height: 1.55; margin-top: 8px">
                            <template v-if="!m.reached">Chýba {{ eur(m.missing) }} · </template>{{ m.note }}
                            <template v-if="m.from_rent_index">
                                Počítame s {{ eur(m.monthly_basis ?? 0) }}/mes.: dnešné výdavky bez bývania ({{
                                    eur(report.after_school.current_without_housing)
                                }}) plus nájom {{ eur(report.after_school.rent ?? 0) }} podľa {{ report.after_school.source
                                }}<template v-if="report.after_school.basis"> ({{ report.after_school.basis }})</template>.
                            </template>
                            <template v-else-if="m.is_estimate">
                                Počítame s {{ eur(m.monthly_basis ?? 0) }}/mes., čo sú tvoje dnešné výdavky — po škole to bude takmer isto viac. Vyber
                                si v nastavení mesto, kde budeš bývať.
                            </template>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- ── Prečo práve toľko ───────────────────────────────────── -->
            <Card title="Prečo práve toľko mesiacov" style="margin-top: 14px">
                <template #right>
                    <span style="font-size: 12px; font-weight: 600; color: #9a9cab">
                        odporúčané {{ report.months.recommended }} mes.<template v-if="report.months.overridden"> · máš vlastné</template>
                    </span>
                </template>

                <div style="display: flex; flex-direction: column; gap: 2px; margin-top: 16px">
                    <div
                        v-for="f in report.months.factors"
                        :key="f.key"
                        style="display: flex; align-items: flex-start; gap: 12px; padding: 11px 0; border-bottom: 1px solid #f5f4ef"
                    >
                        <span
                            style="
                                font-size: 12.5px;
                                font-weight: 800;
                                min-width: 34px;
                                text-align: center;
                                border-radius: 8px;
                                padding: 3px 0;
                                flex-shrink: 0;
                            "
                            :style="
                                f.delta > 0
                                    ? { background: '#fdeaea', color: '#c0453f' }
                                    : f.delta < 0
                                      ? { background: '#e6f7ec', color: '#2ba35a' }
                                      : { background: '#f1efe8', color: '#8a8c9a' }
                            "
                        >
                            {{ f.delta > 0 ? '+' : '' }}{{ f.delta }}
                        </span>
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 13px; font-weight: 700">{{ f.label }}</div>
                            <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; line-height: 1.55; margin-top: 2px">{{ f.note }}</div>
                        </div>
                    </div>
                </div>

                <div
                    style="
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        background: #f7f6f2;
                        border-radius: 12px;
                        padding: 13px 16px;
                        margin-top: 14px;
                    "
                >
                    <span style="font-size: 13px; font-weight: 700; color: #6a6c7a">Spolu</span>
                    <span class="font-display" style="font-size: 20px; font-weight: 800; margin-left: auto"
                        >{{ report.months.recommended }} mesiacov</span
                    >
                </div>
                <div v-if="report.months.clamped" style="font-size: 11.5px; color: #9a9cab; font-weight: 600; margin-top: 8px">
                    Výsledok je orezaný na rozumné rozpätie 3–12 mesiacov.
                </div>
            </Card>

            <!-- ── Ako to naplniť ──────────────────────────────────────── -->
            <Card v-if="report.gap > 0" title="Ako to naplniť bez toho, aby si prestal investovať" style="margin-top: 14px">
                <div v-if="report.plan.options.length" style="display: flex; flex-direction: column; gap: 10px; margin-top: 16px">
                    <div
                        v-for="o in report.plan.options"
                        :key="o.share"
                        style="
                            display: flex;
                            align-items: center;
                            gap: 14px;
                            flex-wrap: wrap;
                            background: #f7f6f2;
                            border-radius: 14px;
                            padding: 14px 17px;
                        "
                    >
                        <div style="min-width: 110px">
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Do rezervy</div>
                            <div class="font-display" style="font-size: 19px; font-weight: 800; margin-top: 2px">{{ eur(o.monthly) }}</div>
                        </div>
                        <div style="min-width: 110px">
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Ostáva na investície</div>
                            <div class="font-display" style="font-size: 19px; font-weight: 800; margin-top: 2px" :style="{ color: primary }">
                                {{ eur(o.investing) }}
                            </div>
                        </div>
                        <div style="flex: 1; min-width: 150px; font-size: 12.5px; font-weight: 700; color: #6a6c7a; line-height: 1.5">
                            Rezerva hotová za <span style="color: #20212e">{{ o.months }} mesiacov</span>
                        </div>
                    </div>
                </div>
                <div v-else style="font-size: 13px; font-weight: 600; color: #c0453f; line-height: 1.6; margin-top: 14px">
                    Podľa transakcií ti mesačne nič neostáva ({{ eur(report.plan.monthly_surplus) }}), takže rezervu z čoho stavať nie je. Prvý krok
                    nie je rezerva ani investovanie, ale dostať mesačný tok nad nulu.
                </div>
            </Card>

            <!-- ── Čo to stojí a čo to šetrí ───────────────────────────── -->
            <Card title="Čo ťa rezerva stojí — a čo ti šetrí" style="margin-top: 14px">
                <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px">
                    <div style="flex: 1; min-width: 220px; background: #f7f6f2; border-radius: 14px; padding: 16px 18px">
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Cena rezervy</div>
                        <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 5px">
                            {{ eur(report.cost.annual_opportunity) }}<span style="font-size: 14px">/rok</span>
                        </div>
                        <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; line-height: 1.55; margin-top: 6px">
                            Toľko by {{ eur(report.target) }} zarobilo pri reálnom výnose {{ num(report.cost.real_return, 1) }} %. Neschovávam to — je
                            to poistné, ktoré platíš.
                        </div>
                    </div>

                    <div
                        v-if="report.cost.forced_sale_loss !== null && report.cost.portfolio_value > 0"
                        style="flex: 1; min-width: 220px; background: #fdeaea; border-radius: 14px; padding: 16px 18px"
                    >
                        <div style="font-size: 12px; font-weight: 700; color: #6a6c7a">Cena toho, že ju nemáš</div>
                        <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 5px; color: #c0453f">
                            {{ eur(report.cost.forced_sale_loss) }}
                        </div>
                        <div style="font-size: 11.5px; color: #8a6a6a; font-weight: 600; line-height: 1.55; margin-top: 6px">
                            Toľko by si stratil, keby si musel predať portfólio na dne. Tvoje portfólio už zažilo prepad
                            {{ num(report.cost.max_drawdown ?? 0, 1) }} % — bez rezervy by práve vtedy bolo jediným zdrojom peňazí.
                        </div>
                    </div>
                </div>

                <div
                    style="
                        font-size: 12.5px;
                        color: #6a6c7a;
                        font-weight: 600;
                        line-height: 1.7;
                        background: #f7f6f2;
                        border-radius: 14px;
                        padding: 16px 18px;
                        margin-top: 12px;
                    "
                >
                    Rezerva nie je konkurencia investovaniu, je jeho podmienka. Bez nej je každý prepad trhu zároveň núdzou — a predávaš práve vtedy,
                    keď je to najhoršie. S ňou vieš prepad presedieť a nechať portfólio zotaviť sa. Tá strata
                    <template v-if="report.cost.forced_sale_loss">{{ eur(report.cost.forced_sale_loss) }}</template> je násobne väčšia než
                    {{ eur(report.cost.annual_opportunity) }} ušlého výnosu ročne.
                </div>
            </Card>

            <!-- ── Čo počítame ako nevyhnutné ──────────────────────────── -->
            <Card title="Čo sa ráta ako nevyhnutné" style="margin-top: 14px">
                <template #right>
                    <span style="font-size: 12px; font-weight: 600; color: #9a9cab">priemer za {{ report.expenses.months_counted }} mes.</span>
                </template>

                <div style="display: flex; flex-wrap: wrap; gap: 22px; margin-top: 16px">
                    <div>
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Nevyhnutné</div>
                        <div class="font-display" style="font-weight: 800; font-size: 22px; margin-top: 3px">
                            {{ eur(report.expenses.essential) }}
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Celkové výdavky</div>
                        <div class="font-display" style="font-weight: 800; font-size: 22px; margin-top: 3px; color: #6a6c7a">
                            {{ eur(report.expenses.total) }}
                        </div>
                    </div>
                    <div v-if="report.expenses.essential_share !== null">
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Podiel nevyhnutných</div>
                        <div class="font-display" style="font-weight: 800; font-size: 22px; margin-top: 3px">
                            {{ num(report.expenses.essential_share, 0) }} %
                        </div>
                    </div>
                    <div v-if="report.income.volatility !== null">
                        <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Kolísanie príjmu</div>
                        <div
                            class="font-display"
                            style="font-weight: 800; font-size: 22px; margin-top: 3px"
                            :style="{ color: report.income.is_volatile ? '#e8544e' : '#2ba35a' }"
                        >
                            {{ num(report.income.volatility, 0) }} %
                        </div>
                    </div>
                </div>

                <div
                    v-if="report.expenses.savings_excluded > 0"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 9px;
                        background: #eef6ff;
                        border-radius: 13px;
                        padding: 12px 15px;
                        margin-top: 14px;
                    "
                >
                    <span style="font-size: 15px">ℹ️</span>
                    <span style="font-size: 12.5px; font-weight: 600; color: #2a6ebd; line-height: 1.55">
                        Z výdavkov sme vyňali {{ eur(report.expenses.savings_excluded) }}/mes. v kategórii Investície — to je sporenie, nie spotreba.
                        V kríze tie peniaze jednoducho prestaneš posielať, takže rezervu na ne netreba.
                    </span>
                </div>

                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 16px">
                    <!-- splátky úverov sa berú zo stránky Úvery, nie z transakcií -->
                    <div
                        v-for="l in report.expenses.loans"
                        :key="l.name"
                        style="display: flex; align-items: center; gap: 9px; font-size: 13px; font-weight: 700"
                    >
                        <span style="width: 9px; height: 9px; border-radius: 3px" :style="{ background: l.color }"></span>
                        <span style="flex: 1; min-width: 0">Splátka — {{ l.name }}</span>
                        <span style="font-size: 11px; font-weight: 700; color: #8a8c9a; background: #f1efe8; padding: 3px 8px; border-radius: 20px"
                            >z úverov</span
                        >
                        <span>{{ eur(l.payment) }}</span>
                    </div>
                    <div
                        v-for="b in report.expenses.breakdown"
                        :key="b.category_id"
                        style="display: flex; align-items: center; gap: 9px; font-size: 13px; font-weight: 700"
                    >
                        <span style="width: 9px; height: 9px; border-radius: 3px" :style="{ background: catColor(b.category_id) }"></span>
                        <span style="flex: 1; min-width: 0">{{ catName(b.category_id) }}</span>
                        <span>{{ eur(b.monthly) }}</span>
                    </div>
                </div>
                <div
                    v-if="report.expenses.loans.length"
                    style="font-size: 11.5px; color: #9a9cab; font-weight: 600; line-height: 1.55; margin-top: 10px"
                >
                    Splátky sa berú priamo zo stránky Úvery — platia sa aj v mesiaci, keď sa zabudne zaúčtovať transakcia, takže ich netreba
                    dohľadávať v histórii.
                </div>

                <button
                    type="button"
                    style="font-size: 12.5px; font-weight: 700; color: #9a9cab; margin-top: 16px; text-decoration: underline"
                    @click="showSettings = !showSettings"
                >
                    {{ showSettings ? 'Skryť nastavenie' : 'Upraviť profil a kategórie →' }}
                </button>
            </Card>

            <!-- ── Jednorazovky ────────────────────────────────────────── -->
            <Card v-if="report.expenses.one_offs.length" title="Jednorazové výdavky" style="margin-top: 14px">
                <template #right>
                    <span style="font-size: 12px; font-weight: 600; color: #9a9cab"
                        >nafúkli by priemer o {{ eur(report.expenses.one_off_monthly) }}/mes.</span
                    >
                </template>

                <div style="font-size: 12.5px; color: #8a8c9a; font-weight: 600; line-height: 1.6; margin-top: 10px">
                    Rovnátka, havária, nový notebook — veci, ktoré sa nebudú opakovať, ale v ročnom priemere vyzerajú ako pravidelný náklad. Nájdeme
                    ich tak, že kategória v danom mesiaci vyskočí niekoľkonásobne nad svoj bežný mesiac. Štandardne sa do rezervy nerátajú. Ak sa
                    niečo z toho predsa opakuje (napr. ročné poistenie), prepni to.
                </div>

                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 16px">
                    <div
                        v-for="o in report.expenses.one_offs"
                        :key="o.id"
                        style="display: flex; align-items: center; gap: 11px; flex-wrap: wrap; border-radius: 13px; padding: 12px 14px"
                        :style="{ background: o.treat_as_recurring ? '#fff6e5' : '#f7f6f2' }"
                    >
                        <span
                            style="width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0"
                            :style="{ background: o.category_id ? catColor(o.category_id) : '#b0b2bd' }"
                        ></span>
                        <div style="flex: 1; min-width: 130px">
                            <div style="font-size: 13px; font-weight: 700">
                                {{ o.note || (o.category_id ? catName(o.category_id) : 'Bez popisu') }}
                            </div>
                            <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; margin-top: 2px">
                                {{ o.date }}<template v-if="o.category_id"> · {{ catName(o.category_id) }}</template>
                            </div>
                        </div>
                        <div class="font-display" style="font-weight: 800; font-size: 15px">{{ eur(o.amount) }}</div>
                        <button
                            type="button"
                            style="font-size: 11.5px; font-weight: 700; padding: 7px 12px; border-radius: 10px; white-space: nowrap"
                            :style="o.treat_as_recurring ? { background: '#b8791a', color: '#fff' } : { background: '#eceae2', color: '#6a6c7a' }"
                            @click="toggleRecurring(o.id)"
                        >
                            {{ o.treat_as_recurring ? 'Ráta sa · opakuje sa' : 'Neráta sa · jednorazovo' }}
                        </button>
                    </div>
                </div>
            </Card>

            <!-- ── Nastavenie ──────────────────────────────────────────── -->
            <Card v-if="showSettings" title="Nastavenie" style="margin-top: 14px">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-top: 16px">
                    <div>
                        <label :style="labelStyle">Príjem</label>
                        <select v-model="form.income_type" :style="inputStyle">
                            <option value="student">Študent s prácou popri škole</option>
                            <option value="stable">Zamestnanec, stabilný príjem</option>
                            <option value="variable">Zamestnanec, premenlivý príjem</option>
                            <option value="self_employed">SZČO / živnostník</option>
                            <option value="mixed">Zamestnanie + živnosť</option>
                        </select>
                    </div>
                    <template v-if="form.income_type === 'student'">
                        <div>
                            <label :style="labelStyle">Kedy končíš školu</label>
                            <input
                                v-model.number="form.graduation_year"
                                type="number"
                                min="2026"
                                max="2060"
                                placeholder="napr. 2029"
                                :style="inputStyle"
                            />
                        </div>
                        <div>
                            <label :style="labelStyle">Kde budeš bývať</label>
                            <select v-model="form.after_school_city" :style="inputStyle">
                                <option :value="null">— podľa veľkosti bytu —</option>
                                <option v-for="(c, key) in report.after_school.cities" :key="key" :value="key">
                                    {{ c.label }} — {{ eur(c.rent) }}
                                </option>
                            </select>
                        </div>
                        <div v-if="!form.after_school_city">
                            <label :style="labelStyle">Veľkosť bytu</label>
                            <select v-model="form.after_school_size" :style="inputStyle">
                                <option :value="null">— nevybrané —</option>
                                <option v-for="(s, key) in report.after_school.sizes" :key="key" :value="key">
                                    {{ s.label }} — {{ eur(s.rent) }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label :style="labelStyle">Nájom platíš</label>
                            <select v-model.number="form.after_school_share" :style="inputStyle">
                                <option :value="1">celý sám</option>
                                <option :value="0.5">na polovicu</option>
                                <option :value="0.34">v trojici</option>
                            </select>
                        </div>
                        <div>
                            <label :style="labelStyle">Vlastný odhad výdavkov po škole (€/mes.)</label>
                            <input
                                v-model.number="form.post_graduation_expenses"
                                type="number"
                                min="0"
                                step="50"
                                :placeholder="report.after_school.estimate ? 'odhad ' + eur(report.after_school.estimate) : 'napr. 1400'"
                                :style="inputStyle"
                            />
                        </div>
                    </template>
                    <div>
                        <label :style="labelStyle">Domácnosť</label>
                        <select v-model="form.household" :style="inputStyle">
                            <option value="single">Len ja</option>
                            <option value="dual_income">Dvaja, oba príjmy</option>
                            <option value="single_income_couple">Dvaja, jeden príjem</option>
                            <option value="dependents">S deťmi / závislými osobami</option>
                        </select>
                    </div>
                    <div>
                        <label :style="labelStyle">Odkiaľ rátať rezervu</label>
                        <select v-model="form.source" :style="inputStyle">
                            <option value="all_cash">Všetka hotovosť na účtoch</option>
                            <option value="account">Vyhradený účet</option>
                            <option value="cash_minus_month">Hotovosť mínus jeden mesiac výdavkov</option>
                        </select>
                    </div>
                    <div v-if="form.source === 'account'">
                        <label :style="labelStyle">Účet rezervy</label>
                        <select v-model.number="form.account_id" :style="inputStyle">
                            <option :value="null">— vyber účet —</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ eur(Number(a.balance)) }})</option>
                        </select>
                    </div>
                    <div>
                        <label :style="labelStyle">Vlastný počet mesiacov</label>
                        <input
                            v-model.number="form.months_override"
                            type="number"
                            min="0"
                            max="24"
                            step="0.5"
                            placeholder="nechaj prázdne = odporúčané"
                            :style="inputStyle"
                        />
                    </div>
                </div>

                <label style="display: flex; align-items: center; gap: 9px; margin-top: 14px; cursor: pointer">
                    <input v-model="form.unemployment_benefit" type="checkbox" style="width: 17px; height: 17px; accent-color: #6c5ce7" />
                    <span style="font-size: 13.5px; font-weight: 600"
                        >Mám nárok na dávku v nezamestnanosti (730 dní poistenia za posledné 4 roky)</span
                    >
                </label>
                <label style="display: flex; align-items: center; gap: 9px; margin-top: 10px; cursor: pointer">
                    <input v-model="form.health_risk" type="checkbox" style="width: 17px; height: 17px; accent-color: #6c5ce7" />
                    <span style="font-size: 13.5px; font-weight: 600">Zdravotné riziko alebo vyššie pravidelné náklady na zdravie</span>
                </label>

                <div style="height: 1px; background: #f1efe8; margin: 18px 0"></div>

                <div style="font-size: 12.5px; font-weight: 800; color: #20212e">Ktoré kategórie sú nevyhnutné</div>
                <div style="font-size: 11.5px; color: #8a8c9a; font-weight: 600; line-height: 1.55; margin-top: 4px">
                    Nie životná úroveň, ale prežitie — nájom, energie, potraviny, splátky, lieky. Reštaurácie a dovolenky sem nepatria, tie sa v kríze
                    škrtnú prvé.
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px; margin-top: 16px">
                    <div v-for="g in categoryGroups" :key="g.id">
                        <div style="font-size: 12px; font-weight: 800; color: #6a6c7a; margin-bottom: 7px">{{ g.icon }} {{ g.name }}</div>
                        <div style="display: flex; flex-wrap: wrap; gap: 6px">
                            <button
                                v-for="c in [g, ...g.children]"
                                :key="c.id"
                                type="button"
                                style="font-size: 12px; font-weight: 700; padding: 7px 11px; border-radius: 10px; border: 1.5px solid transparent"
                                :style="
                                    form.essential_category_ids.includes(c.id)
                                        ? { background: hexToRgba(c.color, 0.16), color: c.color, borderColor: c.color }
                                        : { background: '#f7f6f2', color: '#9a9cab' }
                                "
                                @click="toggleCategory(c.id)"
                            >
                                {{ c.id === g.id ? 'celá skupina' : c.name }}
                            </button>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- ── Zdroje ──────────────────────────────────────────────── -->
            <Card title="Odkiaľ sú odporúčania" style="margin-top: 14px">
                <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 14px">
                    <a
                        v-for="s in report.sources"
                        :key="s.url"
                        :href="s.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        style="font-size: 12.5px; font-weight: 600; color: #6a6c7a; line-height: 1.55; text-decoration: underline"
                        >{{ s.label }}</a
                    >
                </div>
                <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; line-height: 1.6; margin-top: 14px">
                    Sú to odporúčania, nie zákony — a nie sú to peniaze na mieru tvojej situácie od licencovaného poradcu. Ber ich ako východisko,
                    ktoré si vieš posunúť vlastným počtom mesiacov.
                </div>
            </Card>

            <AskAi style="margin-top: 14px" :questions="['Mám dosť veľkú rezervu?', 'Ako rýchlo si viem rezervu doplniť?']" />
        </div>
    </GrosLayout>
</template>
