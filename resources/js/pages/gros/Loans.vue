<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import AskAi from '@/components/gros/AskAi.vue';
import LoanModal from '@/components/gros/LoanModal.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Loan {
    id: number;
    kind: string;
    name: string;
    balance: number | string;
    principal: number | string;
    payment: number | string;
    rate: number | string;
    next_payment: string;
    color: string;
    account_id: number | null;
    category_id: number | null;
}

interface LoanPlan {
    ok: boolean;
    reason?: string;
    rate: number;
    months: number;
    payoff_date: string;
    total_interest: number;
    total_paid: number;
    with_extra: { extra: number; months: number; months_saved: number; payoff_date: string; interest_saved: number } | null;
    compare: {
        horizon_months: number;
        real_return: number;
        repay_first: number;
        invest_first: number;
        advantage: number;
        verdict: 'invest' | 'repay';
    } | null;
}

const props = defineProps<{
    loans: Loan[];
    accounts: { id: number; name: string }[];
    totals: { owed: number; lent: number; monthlyPayment: number };
    plan: {
        extra: number;
        real_return: number;
        loans: Record<number, LoanPlan>;
        priority_id: number | null;
    };
}>();

const { eur, num, primary, primarySoft, hexToRgba, formatDate } = useGros();

const extra = ref(props.plan.extra);

/** Prepočet beží na serveri — umorovanie je iteratívne, nie vzorec. */
function reloadPlan() {
    router.get('/loans', { extra: extra.value }, { preserveScroll: true, preserveState: true, replace: true, only: ['plan'] });
}

let extraTimer: ReturnType<typeof setTimeout> | undefined;
watch(extra, () => {
    clearTimeout(extraTimer);
    extraTimer = setTimeout(reloadPlan, 400);
});

function planFor(l: Loan): LoanPlan | null {
    return props.plan.loans[l.id] ?? null;
}

/** Dlhy, pri ktorých má porovnanie „splatiť vs. investovať" zmysel. */
const debtsWithPlan = computed(() =>
    props.loans
        .filter((l) => l.kind === 'owe')
        .map((l) => ({ loan: l, plan: planFor(l) }))
        .filter((x): x is { loan: Loan; plan: LoanPlan } => Boolean(x.plan?.ok && x.plan.compare)),
);

function monthsLabel(months: number): string {
    const y = Math.floor(months / 12);
    const m = months % 12;
    if (y === 0) return `${m} mes.`;
    if (m === 0) return `${y} r.`;
    return `${y} r. ${m} mes.`;
}

const modalOpen = ref(false);
const editLoan = ref<Loan | null>(null);

const sortedLoans = computed(() =>
    [...props.loans].sort((a, b) => (a.kind === b.kind ? (a.next_payment < b.next_payment ? -1 : 1) : a.kind === 'owe' ? -1 : 1)),
);

function openNew() {
    editLoan.value = null;
    modalOpen.value = true;
}
function openEdit(l: Loan) {
    editLoan.value = l;
    modalOpen.value = true;
}

function paidPct(l: Loan): number {
    const p = Number(l.principal);
    return p > 0 ? Math.max(0, Math.min(100, ((p - Number(l.balance)) / p) * 100)) : 0;
}
function showProgress(l: Loan): boolean {
    return l.kind === 'owe' && Number(l.principal) > Number(l.balance);
}
</script>

<template>
    <Head title="Úvery" />
    <GrosLayout title="Úvery" subtitle="Dlhy a požičané peniaze">
        <template #action>
            <AddButton label="Pridať úver" @click="openNew" />
        </template>

        <div class="gros-rise">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px">
                <div
                    style="
                        background: linear-gradient(135deg, #e8544e, #f0692a);
                        border-radius: 20px;
                        padding: 22px;
                        color: #fff;
                        box-shadow: 0 16px 34px rgba(232, 84, 78, 0.32);
                    "
                >
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9">Celkovo dlžím</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px">
                        {{ eur(totals.owed) }}
                    </div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 13px; font-weight: 600; color: #8a8c9a">Požičal som</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px; color: #2ba35a">
                        {{ eur(totals.lent) }}
                    </div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 13px; font-weight: 600; color: #8a8c9a">Mesačné splátky</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px">
                        {{ eur(totals.monthlyPayment) }}
                    </div>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 22px 2px 12px">
                <div class="font-display" style="font-weight: 700; font-size: 17px">Prehľad úverov</div>
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
                    @click="openNew"
                >
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    Pridať úver
                </button>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px">
                <div
                    v-for="l in sortedLoans"
                    :key="l.id"
                    style="
                        background: #fff;
                        border-radius: 20px;
                        padding: 20px;
                        box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05);
                        position: relative;
                        overflow: hidden;
                        cursor: pointer;
                    "
                    @click="openEdit(l)"
                >
                    <div style="position: absolute; top: 0; left: 0; right: 0; height: 5px" :style="{ background: l.color }"></div>
                    <div style="display: flex; align-items: center; gap: 12px">
                        <span
                            style="
                                width: 44px;
                                height: 44px;
                                border-radius: 13px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-weight: 800;
                                font-size: 18px;
                                flex-shrink: 0;
                            "
                            :style="{ background: hexToRgba(l.color, 0.14), color: l.color }"
                            >{{ l.name[0] }}</span
                        >
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 15px; font-weight: 700">{{ l.name }}</div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 500">{{ l.kind === 'owe' ? 'Dlžím' : 'Požičal som' }}</div>
                        </div>
                        <span
                            style="font-size: 11px; font-weight: 700; padding: 4px 9px; border-radius: 20px"
                            :style="{ color: l.color, background: hexToRgba(l.color, 0.14) }"
                            >{{ Number(l.rate) ? num(Number(l.rate), 1) + ' % p.a.' : 'bez úroku' }}</span
                        >
                    </div>
                    <div style="display: flex; align-items: flex-end; justify-content: space-between; margin-top: 16px">
                        <div>
                            <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600">
                                {{ l.kind === 'owe' ? 'Zostáva splatiť' : 'Má mi vrátiť' }}
                            </div>
                            <div class="font-display" style="font-weight: 800; font-size: 24px; letter-spacing: -0.7px; margin-top: 2px">
                                {{ eur(Number(l.balance)) }}
                            </div>
                        </div>
                        <div style="text-align: right">
                            <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600">Mes. splátka</div>
                            <div style="font-size: 15px; font-weight: 800; margin-top: 2px">
                                {{ Number(l.payment) ? eur(Number(l.payment)) : '—' }}
                            </div>
                        </div>
                    </div>
                    <template v-if="showProgress(l)">
                        <div style="height: 8px; background: #f1efe8; border-radius: 5px; overflow: hidden; margin-top: 14px">
                            <div :style="{ height: '100%', width: paidPct(l).toFixed(1) + '%', background: l.color, borderRadius: '5px' }"></div>
                        </div>
                        <div
                            style="
                                display: flex;
                                justify-content: space-between;
                                font-size: 11.5px;
                                font-weight: 600;
                                color: #9a9cab;
                                margin-top: 7px;
                            "
                        >
                            <span>Splatené {{ num(paidPct(l)) }}%</span>
                            <span>z {{ eur(Number(l.principal)) }}</span>
                        </div>
                    </template>
                    <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; margin-top: 12px; display: flex; align-items: center; gap: 6px">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                            <rect x="4" y="5" width="16" height="16" rx="3" />
                            <path d="M4 10h16M9 3v4M15 3v4" />
                        </svg>
                        {{ (l.kind === 'owe' ? 'Ďalšia splátka ' : 'Termín vrátenia ') + formatDate(l.next_payment) }}
                    </div>

                    <!-- kedy to bude splatené a čo to celé stojí -->
                    <div
                        v-if="planFor(l)?.ok"
                        style="border-top: 1px solid #f1efe8; margin-top: 12px; padding-top: 12px; display: flex; flex-wrap: wrap; gap: 16px"
                    >
                        <div>
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Doplatené</div>
                            <div style="font-size: 13.5px; font-weight: 800; margin-top: 2px">
                                {{ formatDate((planFor(l) as LoanPlan).payoff_date) }}
                            </div>
                            <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 1px">
                                o {{ monthsLabel((planFor(l) as LoanPlan).months) }}
                            </div>
                        </div>
                        <div v-if="(planFor(l) as LoanPlan).total_interest > 0">
                            <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Zaplatíš na úrokoch</div>
                            <div style="font-size: 13.5px; font-weight: 800; margin-top: 2px; color: #e8544e">
                                {{ eur((planFor(l) as LoanPlan).total_interest) }}
                            </div>
                            <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 1px">
                                spolu {{ eur((planFor(l) as LoanPlan).total_paid) }}
                            </div>
                        </div>
                    </div>
                    <div
                        v-else-if="planFor(l) && !(planFor(l) as LoanPlan).ok"
                        style="
                            border-top: 1px solid #f1efe8;
                            margin-top: 12px;
                            padding-top: 12px;
                            font-size: 11.5px;
                            font-weight: 700;
                            color: #c0453f;
                        "
                    >
                        {{ (planFor(l) as LoanPlan).reason }}
                    </div>
                </div>

                <button
                    type="button"
                    style="
                        border: 2px dashed #dcdace;
                        border-radius: 20px;
                        padding: 20px;
                        background: transparent;
                        color: #9a9cab;
                        font-weight: 700;
                        font-size: 14px;
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        justify-content: center;
                        gap: 8px;
                        min-height: 150px;
                    "
                    @click="openNew"
                >
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    Pridať úver
                </button>
            </div>

            <!-- Doplatiť skôr, alebo investovať? -->
            <div
                v-if="debtsWithPlan.length"
                style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-top: 14px"
            >
                <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Doplatiť skôr, alebo investovať?</div>
                <div style="font-size: 12.5px; color: #8a8c9a; font-weight: 600; line-height: 1.6; margin-top: 6px">
                    Keby ti mesačne zvýšilo niečo navyše — má to ísť na splátku, alebo do portfólia? Obe cesty porovnávame k tomu istému dátumu, kedy
                    by úver skončil aj bez mimoriadnych splátok. Vtedy si v oboch prípadoch bez dlhu, takže rozhoduje len to, čo zostane v portfóliu.
                    Očakávaný reálny výnos {{ num(plan.real_return, 1) }} % po inflácii.
                </div>

                <div style="display: flex; align-items: center; gap: 12px; margin-top: 16px; flex-wrap: wrap">
                    <label style="font-size: 12.5px; font-weight: 700; color: #8a8c9a">Voľných navyše mesačne</label>
                    <input
                        v-model.number="extra"
                        type="number"
                        min="10"
                        max="10000"
                        step="10"
                        style="
                            width: 120px;
                            background: #f7f6f2;
                            border: 1.5px solid #eceae2;
                            border-radius: 12px;
                            padding: 10px 12px;
                            font-size: 14.5px;
                            font-weight: 700;
                            color: #20212e;
                            outline: none;
                        "
                    />
                </div>

                <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px">
                    <div v-for="d in debtsWithPlan" :key="d.loan.id" style="background: #f7f6f2; border-radius: 14px; padding: 16px 18px">
                        <div style="display: flex; align-items: center; gap: 9px; flex-wrap: wrap">
                            <span style="width: 9px; height: 9px; border-radius: 3px" :style="{ background: d.loan.color }"></span>
                            <span style="font-size: 14px; font-weight: 800">{{ d.loan.name }}</span>
                            <span style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">
                                {{ Number(d.loan.rate) ? num(Number(d.loan.rate), 1) + ' % p.a.' : 'bez úroku' }}
                            </span>
                            <span
                                v-if="plan.priority_id === d.loan.id"
                                style="
                                    font-size: 10.5px;
                                    font-weight: 800;
                                    color: #c0453f;
                                    background: #fdeaea;
                                    padding: 3px 8px;
                                    border-radius: 20px;
                                    text-transform: uppercase;
                                    letter-spacing: 0.3px;
                                "
                                >Najdrahší dlh</span
                            >
                        </div>

                        <div v-if="d.plan.with_extra" style="font-size: 12px; font-weight: 600; color: #6a6c7a; margin-top: 10px; line-height: 1.55">
                            S {{ eur(extra) }} navyše by bol úver splatený o {{ monthsLabel(d.plan.with_extra.months_saved) }} skôr, už
                            {{ formatDate(d.plan.with_extra.payoff_date)
                            }}<template v-if="d.plan.with_extra.interest_saved > 0">
                                — a ušetríš {{ eur(d.plan.with_extra.interest_saved) }} na úrokoch</template
                            >.
                        </div>

                        <div style="display: flex; flex-wrap: wrap; gap: 22px; margin-top: 14px">
                            <div>
                                <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Splácať skôr</div>
                                <div
                                    class="font-display"
                                    style="font-weight: 800; font-size: 20px; margin-top: 3px"
                                    :style="{ color: d.plan.compare!.verdict === 'repay' ? '#2ba35a' : '#20212e' }"
                                >
                                    {{ eur(d.plan.compare!.repay_first) }}
                                </div>
                                <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 2px">v portfóliu po doplatení</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Investovať hneď</div>
                                <div
                                    class="font-display"
                                    style="font-weight: 800; font-size: 20px; margin-top: 3px"
                                    :style="{ color: d.plan.compare!.verdict === 'invest' ? '#2ba35a' : '#20212e' }"
                                >
                                    {{ eur(d.plan.compare!.invest_first) }}
                                </div>
                                <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 2px">v portfóliu, dlh podľa plánu</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; font-weight: 700; color: #8a8c9a">Merané k</div>
                                <div class="font-display" style="font-weight: 800; font-size: 20px; margin-top: 3px">
                                    {{ formatDate(d.plan.payoff_date) }}
                                </div>
                                <div style="font-size: 11px; color: #9a9cab; font-weight: 600; margin-top: 2px">
                                    o {{ monthsLabel(d.plan.months) }}
                                </div>
                            </div>
                        </div>

                        <div
                            style="
                                display: flex;
                                align-items: center;
                                gap: 9px;
                                border-radius: 12px;
                                padding: 11px 14px;
                                margin-top: 14px;
                                font-size: 12.5px;
                                font-weight: 700;
                                line-height: 1.5;
                            "
                            :style="
                                d.plan.compare!.verdict === 'invest'
                                    ? { background: '#eef6ff', color: '#2a6ebd' }
                                    : { background: '#fff6e5', color: '#8a6516' }
                            "
                        >
                            <span style="font-size: 15px">{{ d.plan.compare!.verdict === 'invest' ? '📈' : '🏦' }}</span>
                            <span v-if="d.plan.compare!.verdict === 'invest'">
                                Investuj — pri úroku {{ num(Number(d.loan.rate), 1) }} % skončíš o
                                {{ eur(Math.abs(d.plan.compare!.advantage)) }} lepšie, než keby si splácal rýchlejšie.
                            </span>
                            <span v-else>
                                Splácaj rýchlejšie — úrok {{ num(Number(d.loan.rate), 1) }} % je drahší než očakávaný výnos, získaš tým
                                {{ eur(Math.abs(d.plan.compare!.advantage)) }}.
                            </span>
                        </div>
                    </div>
                </div>

                <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; line-height: 1.6; margin-top: 14px">
                    Porovnanie neráta s tým, že splatený dlh je istota a výnos portfólia nie. Pri podobných číslach je splatenie dlhu pokojnejšia
                    voľba — a najprv patrí núdzový fond, až potom čokoľvek iné.
                </div>
            </div>

            <AskAi style="margin-top: 14px" :questions="['Oplatí sa mi doplácať úver skôr?', 'Koľko ma úver celkovo stojí?']" />
        </div>

        <LoanModal v-if="modalOpen" :loan="editLoan" :accounts="accounts" @close="modalOpen = false" />
    </GrosLayout>
</template>
