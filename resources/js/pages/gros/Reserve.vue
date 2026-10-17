<script setup lang="ts">
import Card from '@/components/gros/Card.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Milestone {
    key: string;
    label: string;
    amount: number;
    note: string;
    reached: boolean;
    progress_pct: number;
    missing: number;
}
interface Profile {
    months: number;
    source: string;
    account_id: number | null;
    essential_category_ids: number[] | null;
    recurring_transaction_ids: number[];
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
        /** Udalosti (dovolenka…) — jednorazové z definície, jeden riadok za udalosť. */
        one_off_events: { id: number; name: string; starts_on: string; color: string; icon: string | null; amount: number }[];
        one_off_monthly: number;
        breakdown: { category_id: number; monthly: number }[];
        has_data: boolean;
    };
    months: number;
    target: number;
    held: number;
    held_basis: { source: string; cash: number; month_spending: number; goals: number; account: string | null };
    covered_months: number | null;
    gap: number;
    progress_pct: number;
    milestones: Milestone[];
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
    months: props.report.profile.months,
    source: props.report.profile.source,
    account_id: props.report.profile.account_id,
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

/** Rýchla voľba počtu mesiacov — uloží sa hneď. */
function setMonths(m: number) {
    form.value.months = m;
    save();
}

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

type Source = 'measured' | 'own' | 'assumption';
const sourceLabel: Record<Source, string> = { measured: 'namerané', own: 'tvoje nastavenie', assumption: 'predpoklad' };
const sourceStyle: Record<Source, Record<string, string>> = {
    measured: { background: '#e6f7ec', color: '#2ba35a' },
    own: { background: '#eef6ff', color: '#2a6ebd' },
    assumption: { background: '#f1efe8', color: '#8a8c9a' },
};

/** Každý vstup výpočtu rezervy: hodnota a odkiaľ je. */
const inputRows = computed(() => {
    const r = props.report;
    const e = r.expenses;
    const essentialNames = form.value.essential_category_ids.map(catName).filter((n) => n !== '—');
    const h = r.held_basis;
    const goals = h.goals > 0 ? ` mínus nasporené na ciele ${eur(h.goals)}` : '';
    const heldNote =
        h.source === 'account' && h.account
            ? `Zostatok účtu ${h.account}.`
            : h.source === 'cash_minus_month'
              ? `Hotovosť na účtoch ${eur(h.cash)}${goals} mínus mesiac bežných výdavkov ${eur(h.month_spending)} — tie sú na nájom a jedlo, nie na núdzu.`
              : `Hotovosť na účtoch ${eur(h.cash)}${goals}, vrátane peňazí na tento mesiac.`;
    const excluded = [
        e.savings_excluded > 0 ? `vklady do portfólia ${eur(e.savings_excluded)}/mes.` : null,
        e.one_off_monthly > 0 ? `jednorazovky a udalosti ${eur(e.one_off_monthly)}/mes.` : null,
    ].filter(Boolean);

    return [
        {
            label: 'Nevyhnutné výdavky',
            value: eur(e.essential) + '/mes.',
            source: 'measured' as Source,
            note: `Priemer za ${e.months_counted} ukončených mesiacov${e.loan_payments > 0 ? `, vrátane splátok úverov ${eur(e.loan_payments)}` : ''}.`,
        },
        {
            label: 'Nevyhnutné kategórie',
            value: `${essentialNames.length}`,
            source: (r.profile.essential_category_ids === null ? 'assumption' : 'own') as Source,
            note: essentialNames.length ? essentialNames.join(', ') : 'Žiadna kategória nie je označená ako nevyhnutná.',
        },
        {
            label: 'Vyňaté z priemeru',
            value: excluded.length ? `${excluded.length}×` : 'nič',
            source: 'measured' as Source,
            note: excluded.length ? excluded.join(' · ') + '.' : 'Žiadne vklady do portfólia ani jednorazovky.',
        },
        { label: 'Počet mesiacov', value: num(r.months, 1), source: 'own' as Source, note: 'Tvoja voľba; bežné odporúčanie je 3–6.' },
        { label: 'Odložené', value: eur(r.held), source: 'measured' as Source, note: heldNote },
        {
            label: 'Nárazník',
            value: eur(r.milestones[0]?.amount ?? 0),
            source: 'assumption' as Source,
            note: 'Pol mesiaca nevyhnutných výdavkov, aspoň 1 000 €.',
        },
    ];
});

const labelStyle = 'font-size: 12px; font-weight: 700; color: #8a8c9a; margin-bottom: 6px; display: block';
const inputStyle =
    'width: 100%; background: #f7f6f2; border: 1.5px solid #eceae2; border-radius: 12px; padding: 11px 13px; font-size: 14.5px; font-weight: 700; color: #20212e; outline: none';
</script>

<template>
    <Head title="Rezerva" />
    <GrosLayout title="Rezerva" subtitle="Nevyhnutné výdavky na pár mesiacov bez príjmu">
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
                    {{ num(report.months, 1) }} mes. nevyhnutných výdavkov po {{ eur(report.expenses.essential) }}
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
                        </div>
                    </div>
                </div>
            </Card>

            <!-- ── Koľko mesiacov ──────────────────────────────────────── -->
            <Card title="Koľko mesiacov" style="margin-top: 14px">
                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px">
                    <button
                        v-for="m in [3, 4, 5, 6]"
                        :key="m"
                        type="button"
                        style="font-size: 13px; font-weight: 800; padding: 9px 16px; border-radius: 11px"
                        :style="form.months === m ? { background: primary, color: '#fff' } : { background: '#f7f6f2', color: '#6a6c7a' }"
                        @click="setMonths(m)"
                    >
                        {{ m }} mes.
                    </button>
                </div>
                <div style="font-size: 12px; color: #8a8c9a; font-weight: 600; line-height: 1.65; margin-top: 12px">
                    Bežné odporúčanie je 3–6 mesiacov. Bližšie k <b>3</b>, keď máš istú prácu, nárok na dávku v nezamestnanosti a v domácnosti sú dva
                    príjmy. Bližšie k <b>6</b> (aj viac), keď máš živnosť alebo nepravidelný príjem, si na príjme sám alebo od teba niekto závisí.
                    Rozhodnutie je tvoje — appka len zráta sumu. <br /><br />
                    <b>Študent na brigáde:</b> na dohode o brigádnickej práci študentov sa poistenie v nezamestnanosti neplatí, takže nárok na dávku
                    nevzniká. Brigáda sa ale zvyčajne nahradí za pár týždňov a nevyhnutné výdavky bez bývania sú nízke — 3 mesiace väčšinou stačia.
                    Viac bude treba až po škole, keď pribudne nájom.
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
                    {{ showSettings ? 'Skryť nastavenie' : 'Upraviť zdroj a kategórie →' }}
                </button>
            </Card>

            <!-- ── Jednorazovky ────────────────────────────────────────── -->
            <Card
                v-if="report.expenses.one_offs.length || report.expenses.one_off_events.length"
                title="Jednorazové výdavky"
                style="margin-top: 14px"
            >
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
                    <!-- udalosti sa neprepínajú: to, že sa nebudú opakovať, povedal používateľ sám -->
                    <Link
                        v-for="e in report.expenses.one_off_events"
                        :key="'e' + e.id"
                        :href="`/events/${e.id}`"
                        style="
                            display: flex;
                            align-items: center;
                            gap: 11px;
                            flex-wrap: wrap;
                            border-radius: 13px;
                            padding: 12px 14px;
                            background: #f7f6f2;
                            color: #20212e;
                        "
                    >
                        <span style="font-size: 15px; flex-shrink: 0">{{ e.icon ?? '✈️' }}</span>
                        <div style="flex: 1; min-width: 130px">
                            <div style="font-size: 13px; font-weight: 700">{{ e.name }}</div>
                            <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; margin-top: 2px">Udalosť · všetky jej výdavky</div>
                        </div>
                        <div class="font-display" style="font-weight: 800; font-size: 15px">{{ eur(e.amount) }}</div>
                        <span
                            style="font-size: 11.5px; font-weight: 700; padding: 7px 12px; border-radius: 10px; white-space: nowrap"
                            :style="{ background: hexToRgba(e.color, 0.14), color: e.color }"
                            >Neráta sa · udalosť</span
                        >
                    </Link>
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
                        <label :style="labelStyle">Odkiaľ rátať rezervu</label>
                        <select v-model="form.source" :style="inputStyle">
                            <option value="cash_minus_month">Hotovosť mínus jeden mesiac výdavkov</option>
                            <option value="account">Vyhradený účet</option>
                            <option value="all_cash">Všetka hotovosť na účtoch</option>
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
                        <label :style="labelStyle">Počet mesiacov</label>
                        <input v-model.number="form.months" type="number" min="1" max="12" step="0.5" :style="inputStyle" />
                    </div>
                </div>

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
                    „3–6 mesiacov" je pravidlo palca, nie výsledok výpočtu. Merané z tvojich dát sú len nevyhnutné výdavky a to, koľko máš odložené.
                </div>
            </Card>
        </div>
    </GrosLayout>
</template>
