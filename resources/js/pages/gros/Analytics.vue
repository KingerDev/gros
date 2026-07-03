<script setup lang="ts">
import CategoryDetailModal from '@/components/gros/CategoryDetailModal.vue';
import PeriodSelector from '@/components/gros/PeriodSelector.vue';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface CatRow { category_id: number; amount: number; count: number }
interface Month { ym: string; label: string; income: number; expense: number; net: number }
interface Merchant { merchant: string; amount: number; count: number }
interface Insight { tone: string; text: string }

const props = defineProps<{
    period: { key: string; ref: string | null; from: string | null; to: string | null; label: string };
    dataRange: { min: string | null; max: string | null };
    periodSummary: { income: number; expense: number; net: number; savingsRate: number; count: number };
    expenseByCategory: CatRow[];
    incomeByCategory: CatRow[];
    monthlySeries: Month[];
    topMerchants: Merchant[];
    insights: Insight[];
}>();

const { eur, eurS, num, grad, primary, primarySoft, catName, catColor, catGlyph, hexToRgba } = useGros();

const detailCat = ref<{ id: number; name: string } | null>(null);

const expTotal = computed(() => props.expenseByCategory.reduce((s, c) => s + c.amount, 0) || 1);
const expDonut = computed(() => {
    let acc = 0;
    const stops = props.expenseByCategory.map((c) => {
        const from = acc;
        const pct = (c.amount / expTotal.value) * 100;
        acc += pct;
        return `${catColor(c.category_id)} ${from.toFixed(2)}% ${acc.toFixed(2)}%`;
    });
    return stops.length ? `conic-gradient(${stops.join(', ')})` : '#f1efe8';
});

const trendMax = computed(() => Math.max(1, ...props.monthlySeries.map((m) => Math.max(m.income, m.expense))));
const merchantMax = computed(() => Math.max(1, ...props.topMerchants.map((m) => m.amount)));

const toneBg: Record<string, string> = { good: '#e6f7ec', warn: '#fdeaea', info: '#eef6ff' };
const toneColor: Record<string, string> = { good: '#2ba35a', warn: '#c0453f', info: '#2a6ebd' };

const cardShadow = '0 4px 18px rgba(60,55,40,.05)';
</script>

<template>
    <Head title="Analýzy" />
    <GrosLayout title="Analýzy" subtitle="Kam tečú peniaze a ako sa to vyvíja">
        <div class="gros-rise">
            <!-- Obdobie -->
            <div style="background: #fff; border-radius: 16px; padding: 12px 14px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-bottom: 14px">
                <PeriodSelector :period="period" :data-range="dataRange" path="/analytics" />
            </div>

            <!-- Postrehy -->
            <div v-if="insights.length" style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px">
                <div v-for="(ins, i) in insights" :key="i" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; border-radius: 13px; font-size: 13.5px; font-weight: 600" :style="{ background: toneBg[ins.tone] || '#f5f4ef', color: toneColor[ins.tone] || '#20212e' }">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0"><circle cx="12" cy="12" r="9" /><path d="M12 8h.01M11 12h1v4h1" /></svg>
                    {{ ins.text }}
                </div>
            </div>

            <!-- Súhrn -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px">
                <div style="border-radius: 20px; padding: 20px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                    <div style="font-size: 12.5px; font-weight: 600; opacity: 0.9">Čistý tok · {{ period.label }}</div>
                    <div class="font-display" style="font-weight: 800; font-size: 27px; letter-spacing: -0.9px; margin-top: 8px">{{ eurS(periodSummary.net) }}</div>
                    <div style="font-size: 12px; font-weight: 600; opacity: 0.9; margin-top: 6px">{{ periodSummary.count }} transakcií</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 20px" :style="{ boxShadow: cardShadow }">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Príjmy</div>
                    <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px; color: #2ba35a">{{ eur(periodSummary.income) }}</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 20px" :style="{ boxShadow: cardShadow }">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Výdavky</div>
                    <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px; color: #e8544e">{{ eur(periodSummary.expense) }}</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 20px" :style="{ boxShadow: cardShadow }">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Miera úspor</div>
                    <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px; color: #0fa3b1">{{ periodSummary.savingsRate }} %</div>
                </div>
            </div>

            <!-- Výdavky podľa kategórie + mesačný trend -->
            <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px">
                <div style="flex: 1.1; min-width: 320px; background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Výdavky podľa kategórie</div>
                    <div v-if="expenseByCategory.length" style="display: flex; flex-wrap: wrap; align-items: center; gap: 24px; margin-top: 18px">
                        <div style="position: relative; width: 140px; height: 140px; flex-shrink: 0; border-radius: 50%" :style="{ background: expDonut }">
                            <div style="position: absolute; inset: 24px; background: #fff; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center">
                                <div style="font-size: 11px; font-weight: 600; color: #9a9cab">Spolu</div>
                                <div class="font-display" style="font-weight: 800; font-size: 15px">{{ eur(periodSummary.expense) }}</div>
                            </div>
                        </div>
                        <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 9px">
                            <button
                                v-for="c in expenseByCategory.slice(0, 8)"
                                :key="c.category_id"
                                type="button"
                                style="display: flex; align-items: center; gap: 10px; text-align: left; padding: 4px 2px; border-radius: 8px"
                                @click="detailCat = { id: c.category_id, name: catName(c.category_id) }"
                            >
                                <span style="width: 11px; height: 11px; border-radius: 4px; flex-shrink: 0" :style="{ background: catColor(c.category_id) }"></span>
                                <span style="font-size: 13.5px; font-weight: 600; flex: 1">{{ catName(c.category_id) }}</span>
                                <span style="font-size: 13.5px; font-weight: 700">{{ eur(c.amount) }}</span>
                                <span style="font-size: 12px; font-weight: 600; color: #9a9cab; width: 36px; text-align: right">{{ num((c.amount / expTotal) * 100) }}%</span>
                            </button>
                        </div>
                    </div>
                    <div v-else style="color: #b0b2bd; font-weight: 600; font-size: 14px; padding: 24px 0">Žiadne výdavky v tomto období.</div>
                    <div style="font-size: 11.5px; color: #b0b2bd; font-weight: 600; margin-top: 14px">Klikni na kategóriu pre detail a vývoj.</div>
                </div>

                <div style="flex: 1.3; min-width: 340px; background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px">
                        <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Vývoj po mesiacoch</div>
                        <div style="display: flex; align-items: center; gap: 12px">
                            <span style="display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600; color: #6a6c7a"><span style="width: 9px; height: 9px; border-radius: 3px; background: #2ba35a"></span>Príjmy</span>
                            <span style="display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600; color: #6a6c7a"><span style="width: 9px; height: 9px; border-radius: 3px; background: #e8544e"></span>Výdavky</span>
                        </div>
                    </div>
                    <div style="overflow-x: auto; margin-top: 20px">
                        <div style="display: flex; align-items: flex-end; gap: 8px; height: 170px; min-width: 560px">
                            <div v-for="m in monthlySeries" :key="m.ym" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end; min-width: 26px">
                                <div style="display: flex; align-items: flex-end; gap: 2px; width: 100%; justify-content: center; flex: 1">
                                    <div :title="eur(m.income)" :style="{ width: '42%', maxWidth: '10px', height: (m.income / trendMax) * 100 + '%', background: '#2ba35a', borderRadius: '3px 3px 0 0' }"></div>
                                    <div :title="eur(m.expense)" :style="{ width: '42%', maxWidth: '10px', height: (m.expense / trendMax) * 100 + '%', background: '#e8544e', borderRadius: '3px 3px 0 0' }"></div>
                                </div>
                                <div style="font-size: 9.5px; font-weight: 700; color: #9a9cab; white-space: nowrap; transform: rotate(-45deg); transform-origin: center; height: 14px">{{ m.label }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top obchodníci + príjmy podľa kategórie -->
            <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px">
                <div style="flex: 1.3; min-width: 320px; background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Kde míňaš najviac</div>
                    <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px">
                        <div v-for="(mch, i) in topMerchants" :key="i">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 5px">
                                <span style="font-size: 13.5px; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ mch.merchant }}<span style="color: #b0b2bd; font-weight: 500"> · {{ mch.count }}×</span></span>
                                <span style="font-size: 13.5px; font-weight: 800; white-space: nowrap">{{ eur(mch.amount) }}</span>
                            </div>
                            <div style="height: 7px; background: #f1efe8; border-radius: 5px; overflow: hidden">
                                <div :style="{ height: '100%', width: (mch.amount / merchantMax) * 100 + '%', background: primary, borderRadius: '5px' }"></div>
                            </div>
                        </div>
                        <div v-if="!topMerchants.length" style="color: #b0b2bd; font-weight: 600; font-size: 14px; padding: 12px 0">Žiadne poznámky v tomto období.</div>
                    </div>
                </div>

                <div style="flex: 1; min-width: 280px; background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Príjmy podľa kategórie</div>
                    <div style="display: flex; flex-direction: column; gap: 11px; margin-top: 16px">
                        <button
                            v-for="c in incomeByCategory.slice(0, 8)"
                            :key="c.category_id"
                            type="button"
                            style="display: flex; align-items: center; gap: 11px; text-align: left; padding: 3px 2px; border-radius: 8px"
                            @click="detailCat = { id: c.category_id, name: catName(c.category_id) }"
                        >
                            <span style="width: 30px; height: 30px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0" :style="{ background: hexToRgba(catColor(c.category_id), 0.16), color: catColor(c.category_id) }">{{ catGlyph(c.category_id) }}</span>
                            <span style="font-size: 13.5px; font-weight: 600; flex: 1">{{ catName(c.category_id) }}</span>
                            <span style="font-size: 13.5px; font-weight: 700; color: #2ba35a">{{ eur(c.amount) }}</span>
                        </button>
                        <div v-if="!incomeByCategory.length" style="color: #b0b2bd; font-weight: 600; font-size: 14px; padding: 12px 0">Žiadne príjmy v tomto období.</div>
                    </div>
                </div>
            </div>
        </div>

        <CategoryDetailModal v-if="detailCat" :category-id="detailCat.id" :name="detailCat.name" @close="detailCat = null" />
    </GrosLayout>
</template>
