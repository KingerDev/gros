<script setup lang="ts">
import Card from '@/components/gros/Card.vue';
import DonutChart from '@/components/gros/DonutChart.vue';
import EventAssignModal from '@/components/gros/EventAssignModal.vue';
import EventModal from '@/components/gros/EventModal.vue';
import MonthlyBars from '@/components/gros/MonthlyBars.vue';
import ProgressBar from '@/components/gros/ProgressBar.vue';
import StatCard from '@/components/gros/StatCard.vue';
import TransactionModal from '@/components/gros/TransactionModal.vue';
import TxnTags from '@/components/gros/TxnTags.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface EventCard {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    days: number;
    budget: number | null;
    color: string;
    icon: string | null;
    note: string | null;
}
interface CatRow {
    category_id: number;
    amount: number;
    count: number;
    children: CatRow[];
}
interface Txn {
    id: number;
    type: string;
    category_id: number | null;
    event_id: number | null;
    amount: number | string;
    account_id: number;
    to_account_id: number | null;
    date: string;
    note: string | null;
    excluded_from_analytics: boolean;
    exclusion_reason: string | null;
    source: string | null;
    refund_for_id: number | null;
    refunded_amount: number | string;
    net_amount: number;
    account: { id: number; name: string; color: string } | null;
}

const props = defineProps<{
    event: EventCard;
    total: number;
    count: number;
    per_day: number;
    refunded: number;
    excluded: { count: number; amount: number };
    byCategory: CatRow[];
    byDay: { date: string | null; label: string; amount: number }[];
    byAccount: { account_id: number; name: string; color: string; amount: number; count: number }[];
    transactions: Txn[];
    accounts: { id: number; name: string }[];
}>();

const { eur, num, primary, primarySoft, catName, catColor, catGlyph, hexToRgba, formatDate, formatRange } = useGros();

const showEdit = ref(false);
const showAssign = ref(false);
const editTxn = ref<Txn | null>(null);

const daysLabel = computed(() => (props.event.days === 1 ? 'deň' : props.event.days < 5 ? 'dni' : 'dní'));

const budgetPct = computed(() => (props.event.budget ? (props.total / props.event.budget) * 100 : 0));
const budgetLeft = computed(() => (props.event.budget ?? 0) - props.total);

const catTotal = computed(() => props.byCategory.reduce((s, c) => s + c.amount, 0) || 1);
const expandedCats = ref<number[]>([]);
function toggleCat(id: number) {
    const i = expandedCats.value.indexOf(id);
    if (i >= 0) expandedCats.value.splice(i, 1);
    else expandedCats.value.push(id);
}

const dayBars = computed(() =>
    props.byDay.map((d) => ({
        label: d.label,
        bars: [{ value: d.amount, color: d.date ? props.event.color : hexToRgba(props.event.color, 0.45), title: `${d.label}: ${eur(d.amount)}` }],
    })),
);
const busiestDay = computed(() =>
    props.byDay.filter((d) => d.date).reduce<(typeof props.byDay)[number] | null>((b, d) => (!b || d.amount > b.amount ? d : b), null),
);

/** Rozsah pre okno priradenia — aj s položkami zaplatenými vopred alebo potom. */
const assignRange = computed(() => {
    const dates = props.transactions.map((t) => t.date.slice(0, 10));
    const from = [props.event.starts_on, ...dates].sort()[0];
    const to = [props.event.ends_on, ...dates].sort().reverse()[0];
    return { from, to };
});

function removeFromEvent(t: Txn) {
    router.patch('/transactions/event', { ids: [t.id], event_id: null }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="event.name" />
    <GrosLayout :title="event.name" :subtitle="`${formatRange(event.starts_on, event.ends_on)} · ${event.days} ${daysLabel}`">
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
                    padding: 11px 17px;
                    border-radius: 13px;
                    white-space: nowrap;
                "
                :style="{ background: primary, boxShadow: `0 8px 18px ${primarySoft}` }"
                @click="showAssign = true"
            >
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Transakcie
            </button>
        </template>

        <div class="gros-rise">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; flex-wrap: wrap">
                <Link href="/events" style="font-size: 13px; font-weight: 700; color: #9a9cab">← Všetky udalosti</Link>
                <button
                    type="button"
                    style="font-size: 13px; font-weight: 700; color: #61637a; background: #fff; padding: 8px 13px; border-radius: 11px"
                    @click="showEdit = true"
                >
                    Upraviť udalosť
                </button>
            </div>

            <!-- Hlavička -->
            <div
                style="
                    border-radius: 22px;
                    padding: 22px;
                    color: #fff;
                    display: flex;
                    align-items: center;
                    gap: 16px;
                    flex-wrap: wrap;
                    margin-bottom: 14px;
                "
                :style="{
                    background: `linear-gradient(135deg, ${event.color}, ${hexToRgba(event.color, 0.72)})`,
                    boxShadow: `0 14px 30px ${hexToRgba(event.color, 0.3)}`,
                }"
            >
                <span
                    style="
                        width: 58px;
                        height: 58px;
                        border-radius: 17px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 30px;
                        background: rgba(255, 255, 255, 0.22);
                        flex-shrink: 0;
                    "
                    >{{ event.icon || event.name[0] }}</span
                >
                <div style="flex: 1; min-width: 180px">
                    <div style="font-size: 13px; font-weight: 700; opacity: 0.85">Spolu za udalosť</div>
                    <div class="font-display" style="font-weight: 800; font-size: 36px; letter-spacing: -1px; line-height: 1.1">{{ eur(total) }}</div>
                    <div v-if="event.note" style="font-size: 12.5px; font-weight: 600; opacity: 0.85; margin-top: 4px">{{ event.note }}</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 14px">
                <StatCard label="Na deň" :value="eur(per_day)">
                    <div style="font-size: 12px; color: #9a9cab; font-weight: 600; margin-top: 4px">{{ event.days }} {{ daysLabel }}</div>
                </StatCard>
                <StatCard label="Transakcie" :value="num(count)">
                    <div v-if="refunded > 0" style="font-size: 12px; color: #2ba35a; font-weight: 600; margin-top: 4px">
                        {{ eur(refunded) }} vrátené
                    </div>
                    <div v-if="excluded.count" style="font-size: 12px; color: #a06a1e; font-weight: 600; margin-top: 4px">
                        + {{ excluded.count }} mimo analýzy ({{ eur(excluded.amount) }})
                    </div>
                </StatCard>
                <StatCard v-if="busiestDay && busiestDay.amount > 0" label="Najdrahší deň" :value="eur(busiestDay.amount)">
                    <div style="font-size: 12px; color: #9a9cab; font-weight: 600; margin-top: 4px">{{ formatDate(busiestDay.date!) }}</div>
                </StatCard>
                <StatCard
                    v-if="event.budget"
                    label="Rozpočet"
                    :value="budgetLeft >= 0 ? eur(budgetLeft) : '− ' + eur(budgetLeft)"
                    :value-color="budgetLeft >= 0 ? '#20212e' : '#e8544e'"
                >
                    <div style="font-size: 12px; color: #9a9cab; font-weight: 600; margin: 4px 0 8px">
                        {{ budgetLeft >= 0 ? 'ostáva' : 'nad rozpočet' }} z {{ eur(event.budget) }}
                    </div>
                    <ProgressBar :pct="budgetPct" :color="budgetPct > 100 ? '#e8544e' : event.color" />
                </StatCard>
            </div>

            <!-- Prázdna udalosť -->
            <div
                v-if="!transactions.length"
                style="background: #fff; border-radius: 20px; padding: 36px 20px; text-align: center; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)"
            >
                <div style="font-size: 30px">🧾</div>
                <div style="font-size: 15px; font-weight: 800; margin-top: 8px">Priraď výdavky z cesty</div>
                <div style="font-size: 13px; color: #9a9cab; font-weight: 600; margin-top: 6px; line-height: 1.6">
                    Ponúkneme ti všetky výdavky z {{ formatRange(event.starts_on, event.ends_on) }} — stačí odškrtnúť tie, ktoré s ňou nesúvisia.
                </div>
                <button
                    type="button"
                    style="margin-top: 16px; color: #fff; font-weight: 800; font-size: 14px; padding: 12px 20px; border-radius: 13px"
                    :style="{ background: primary, boxShadow: `0 8px 18px ${primarySoft}` }"
                    @click="showAssign = true"
                >
                    Vybrať transakcie
                </button>
            </div>

            <template v-else>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; margin-bottom: 14px">
                    <!-- Podľa kategórie -->
                    <Card title="Na čo išli peniaze">
                        <div style="display: flex; align-items: center; gap: 20px; margin-top: 16px; flex-wrap: wrap">
                            <DonutChart :parts="byCategory.map((c) => ({ color: catColor(c.category_id), value: c.amount }))" :size="130" :inset="22">
                                <div style="font-size: 11px; color: #9a9cab; font-weight: 700">Spolu</div>
                                <div class="font-display" style="font-weight: 800; font-size: 15px">{{ eur(total) }}</div>
                            </DonutChart>
                            <div style="flex: 1; min-width: 180px; display: flex; flex-direction: column; gap: 4px">
                                <template v-for="c in byCategory" :key="c.category_id">
                                    <button
                                        type="button"
                                        style="
                                            display: flex;
                                            align-items: center;
                                            gap: 9px;
                                            padding: 6px 4px;
                                            border-radius: 9px;
                                            text-align: left;
                                            width: 100%;
                                        "
                                        :style="{ cursor: c.children.length ? 'pointer' : 'default' }"
                                        @click="c.children.length && toggleCat(c.category_id)"
                                    >
                                        <span
                                            style="width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0"
                                            :style="{ background: catColor(c.category_id) }"
                                        ></span>
                                        <span style="flex: 1; font-size: 13px; font-weight: 700; min-width: 0">
                                            {{ catName(c.category_id) }}
                                            <span v-if="c.children.length" style="color: #b0b2bd">{{
                                                expandedCats.includes(c.category_id) ? '▾' : '▸'
                                            }}</span>
                                        </span>
                                        <span style="font-size: 12px; color: #9a9cab; font-weight: 700"
                                            >{{ Math.round((c.amount / catTotal) * 100) }} %</span
                                        >
                                        <span style="font-size: 13px; font-weight: 800; min-width: 74px; text-align: right">{{ eur(c.amount) }}</span>
                                    </button>
                                    <template v-if="expandedCats.includes(c.category_id)">
                                        <div
                                            v-for="ch in c.children"
                                            :key="ch.category_id"
                                            style="
                                                display: flex;
                                                align-items: center;
                                                gap: 9px;
                                                padding: 3px 4px 3px 23px;
                                                font-size: 12.5px;
                                                font-weight: 600;
                                                color: #6a6c7a;
                                            "
                                        >
                                            <span style="flex: 1">{{ catName(ch.category_id) }}</span>
                                            <span style="font-weight: 700">{{ eur(ch.amount) }}</span>
                                        </div>
                                    </template>
                                </template>
                            </div>
                        </div>
                    </Card>

                    <div style="display: flex; flex-direction: column; gap: 14px">
                        <!-- Po dňoch -->
                        <Card v-if="byDay.length" title="Po dňoch">
                            <div style="margin-top: 16px; overflow-x: auto">
                                <div :style="{ minWidth: byDay.length > 12 ? byDay.length * 34 + 'px' : 'auto' }">
                                    <MonthlyBars :items="dayBars" :height="140" :bar-max="26" :rotate-labels="byDay.length > 10" />
                                </div>
                            </div>
                            <div
                                v-if="byDay.some((d) => !d.date)"
                                style="font-size: 11.5px; color: #9a9cab; font-weight: 600; margin-top: 10px; line-height: 1.5"
                            >
                                „Vopred" a „Potom" sú výdavky mimo dní udalosti — napríklad letenka alebo ubytovanie zaplatené dopredu.
                            </div>
                        </Card>

                        <!-- Podľa účtu -->
                        <Card v-if="byAccount.length > 1" title="Z ktorého účtu">
                            <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 14px">
                                <div v-for="a in byAccount" :key="a.account_id">
                                    <div style="display: flex; justify-content: space-between; font-size: 13px; font-weight: 700; margin-bottom: 5px">
                                        <span>{{ a.name }}</span
                                        ><span>{{ eur(a.amount) }}</span>
                                    </div>
                                    <ProgressBar :pct="(a.amount / (total || 1)) * 100" :color="a.color" :height="7" />
                                </div>
                            </div>
                        </Card>
                    </div>
                </div>

                <!-- Transakcie -->
                <div style="background: #fff; border-radius: 20px; padding: 8px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 14px 6px">
                        <div class="font-display" style="font-weight: 700; font-size: 17px">Transakcie</div>
                        <button type="button" style="font-size: 12.5px; font-weight: 700; color: #9a9cab" @click="showAssign = true">
                            Pridať / odobrať →
                        </button>
                    </div>
                    <div
                        v-for="t in transactions"
                        :key="t.id"
                        style="display: flex; align-items: center; gap: 13px; padding: 12px 14px; border-radius: 14px; cursor: pointer"
                        :style="{ opacity: t.excluded_from_analytics ? 0.6 : 1 }"
                        @click="editTxn = t"
                    >
                        <span
                            style="
                                width: 40px;
                                height: 40px;
                                border-radius: 12px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-weight: 800;
                                font-size: 16px;
                                flex-shrink: 0;
                            "
                            :style="{ background: hexToRgba(catColor(t.category_id), 0.14), color: catColor(t.category_id) }"
                            >{{ catGlyph(t.category_id) }}</span
                        >
                        <div style="flex: 1; min-width: 0">
                            <div style="display: flex; align-items: center; gap: 7px; flex-wrap: wrap">
                                <span style="font-size: 14px; font-weight: 700">{{ t.note || catName(t.category_id) }}</span>
                                <TxnTags
                                    :source="t.source"
                                    :excluded="t.excluded_from_analytics"
                                    :reason="t.exclusion_reason"
                                    :refunded-amount="Number(t.refunded_amount ?? 0)"
                                    :amount="Number(t.amount)"
                                />
                            </div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 500">
                                {{ catName(t.category_id) }} · {{ t.account?.name ?? '—' }} · {{ formatDate(t.date) }}
                            </div>
                        </div>
                        <div style="font-size: 14.5px; font-weight: 800; white-space: nowrap; color: #e8544e">− {{ eur(t.net_amount) }}</div>
                        <button
                            type="button"
                            style="
                                width: 32px;
                                height: 32px;
                                border-radius: 10px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                flex-shrink: 0;
                                color: #c4c2ba;
                            "
                            title="Vybrať z udalosti (transakcia ostane)"
                            @click.stop="removeFromEvent(t)"
                        >
                            <svg
                                width="15"
                                height="15"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.4"
                                stroke-linecap="round"
                            >
                                <path d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <EventModal v-if="showEdit" :event="event" @close="showEdit = false" />
        <EventAssignModal
            v-if="showAssign"
            :event="event"
            :preselect="!transactions.length"
            :from="assignRange.from"
            :to="assignRange.to"
            @close="showAssign = false"
        />
        <TransactionModal v-if="editTxn" :accounts="accounts" :transaction="editTxn" @close="editTxn = null" />
    </GrosLayout>
</template>
