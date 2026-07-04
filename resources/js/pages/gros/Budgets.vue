<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import BudgetModal from '@/components/gros/BudgetModal.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Budget {
    id: number;
    category_id: number;
    limit_amount: number;
    period: string;
    spent: number;
    projected: number;
    elapsed: number;
    total: number;
    is_group: boolean;
}

const props = defineProps<{
    budgets: Budget[];
    totals: { limit: number; spent: number; overCount: number };
}>();

const { eur, eurS, num, grad, primary, primarySoft, catColor, catName, catGlyph, hexToRgba } = useGros();

const modalOpen = ref(false);
const editBudget = ref<Budget | null>(null);

const periodLabels: Record<string, string> = { week: 'Týždenný rozpočet', month: 'Mesačný rozpočet', year: 'Ročný rozpočet' };

const spentPct = computed(() => (props.totals.limit > 0 ? (props.totals.spent / props.totals.limit) * 100 : 0));
const left = computed(() => props.totals.limit - props.totals.spent);

function openNew() {
    editBudget.value = null;
    modalOpen.value = true;
}
function openEdit(b: Budget) {
    editBudget.value = b;
    modalOpen.value = true;
}

function pct(b: Budget): number {
    return b.limit_amount > 0 ? (b.spent / b.limit_amount) * 100 : 0;
}
function pace(b: Budget) {
    const midPeriod = b.elapsed < b.total; // ešte nie je koniec obdobia
    const alreadyOver = b.spent > b.limit_amount;
    const willOver = !alreadyOver && b.projected > b.limit_amount;
    return {
        show: midPeriod && !alreadyOver && b.spent > 0,
        willOver,
        projected: b.projected,
        overBy: b.projected - b.limit_amount,
    };
}
function status(b: Budget) {
    const over = b.spent > b.limit_amount;
    const near = !over && pct(b) >= 80;
    const c = catColor(b.category_id);
    return {
        over,
        near,
        barColor: over ? '#e8544e' : near ? '#f0a020' : c,
        statusLabel: over ? 'Prekročené' : near ? 'Takmer' : 'V norme',
        badgeColor: over ? '#e8544e' : near ? '#b5730f' : '#2ba35a',
        badgeBg: over ? '#fdeaea' : near ? '#fdf1dc' : '#e6f7ec',
        leftLabel: over ? `Prekročené o ${eur(b.spent - b.limit_amount)}` : `${eur(b.limit_amount - b.spent)} ostáva`,
        leftColor: over ? '#e8544e' : '#9a9cab',
    };
}
</script>

<template>
    <Head title="Rozpočty" />
    <GrosLayout title="Rozpočty" subtitle="Limity na kategórie a ich čerpanie">
        <template #action>
            <AddButton label="Pridať rozpočet" @click="openNew" />
        </template>

        <div class="gros-rise">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px">
                <div style="border-radius: 20px; padding: 20px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                    <div style="font-size: 12.5px; font-weight: 600; opacity: 0.9">Rozpočet spolu</div>
                    <div class="font-display" style="font-weight: 800; font-size: 28px; letter-spacing: -0.9px; margin-top: 8px">
                        {{ eur(totals.limit) }}
                    </div>
                    <div style="font-size: 12px; font-weight: 600; opacity: 0.9; margin-top: 6px">za sledované obdobia</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Vyčerpané</div>
                    <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px">
                        {{ eur(totals.spent) }}
                    </div>
                    <div style="font-size: 12px; font-weight: 700; color: #9a9cab; margin-top: 6px">{{ num(spentPct) }} % z rozpočtu</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Ostáva</div>
                    <div
                        class="font-display"
                        style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px"
                        :style="{ color: left < 0 ? '#e8544e' : '#0fa3b1' }"
                    >
                        {{ eurS(left) }}
                    </div>
                    <div style="font-size: 12px; font-weight: 700; color: #9a9cab; margin-top: 6px">
                        {{ totals.overCount === 0 ? 'všetky v norme' : totals.overCount + ' prekročené' }}
                    </div>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 22px 2px 12px">
                <div class="font-display" style="font-weight: 700; font-size: 17px">Rozpočty podľa kategórie</div>
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
                    Pridať rozpočet
                </button>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px">
                <div
                    v-for="b in budgets"
                    :key="b.id"
                    style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); cursor: pointer"
                    @click="openEdit(b)"
                >
                    <div style="display: flex; align-items: center; gap: 12px">
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
                            :style="{ background: hexToRgba(catColor(b.category_id), 0.14), color: catColor(b.category_id) }"
                            >{{ catGlyph(b.category_id) }}</span
                        >
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 15px; font-weight: 700">{{ catName(b.category_id) }}</div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 600">
                                {{ periodLabels[b.period] }}<span v-if="b.is_group"> · celá skupina</span>
                            </div>
                        </div>
                        <span
                            style="font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap"
                            :style="{ color: status(b).badgeColor, background: status(b).badgeBg }"
                            >{{ status(b).statusLabel }}</span
                        >
                    </div>
                    <div style="display: flex; align-items: flex-end; justify-content: space-between; margin-top: 16px">
                        <div class="font-display" style="font-weight: 800; font-size: 22px; letter-spacing: -0.6px">{{ eur(b.spent) }}</div>
                        <div style="font-size: 13px; font-weight: 600; color: #9a9cab">z {{ eur(b.limit_amount) }}</div>
                    </div>
                    <div style="height: 10px; background: #f1efe8; border-radius: 6px; overflow: hidden; margin-top: 12px">
                        <div
                            :style="{
                                height: '100%',
                                width: Math.min(100, pct(b)).toFixed(1) + '%',
                                background: status(b).barColor,
                                borderRadius: '6px',
                                transition: 'width .5s ease',
                            }"
                        ></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; margin-top: 8px">
                        <span :style="{ color: catColor(b.category_id) }">{{ num(pct(b)) }} % vyčerpané</span>
                        <span :style="{ color: status(b).leftColor }">{{ status(b).leftLabel }}</span>
                    </div>
                    <div
                        v-if="pace(b).show"
                        style="
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            font-size: 11.5px;
                            font-weight: 700;
                            margin-top: 10px;
                            padding: 7px 10px;
                            border-radius: 10px;
                        "
                        :style="{ background: pace(b).willOver ? '#fdf1dc' : '#f5f4ef', color: pace(b).willOver ? '#b5730f' : '#9a9cab' }"
                    >
                        <span>{{ pace(b).willOver ? '⚠️' : '📈' }}</span>
                        <span v-if="pace(b).willOver">Pri tomto tempe prekročíš o ~{{ eur(pace(b).overBy) }}</span>
                        <span v-else>Pri tomto tempe: ~{{ eur(pace(b).projected) }} do konca obdobia</span>
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
                    Pridať rozpočet
                </button>
            </div>
        </div>

        <BudgetModal v-if="modalOpen" :budget="editBudget" @close="modalOpen = false" />
    </GrosLayout>
</template>
