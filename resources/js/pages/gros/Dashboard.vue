<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import PeriodSelector from '@/components/gros/PeriodSelector.vue';
import TransactionModal from '@/components/gros/TransactionModal.vue';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface SpendCat { category_id: number; amount: number }
interface Holding { ticker: string; name: string; value: number; color: string }
interface AssetPart { name: string; value: number; color: string }
interface HistoryMonth { label: string; income: number; expense: number; saved: number }
interface Upcoming { name: string; amount: number; next_payment: string; color: string }
interface TopExpense { category_id: number | null; note: string | null; amount: number }

const props = defineProps<{
    period: { key: string; ref: string | null; from: string | null; to: string | null; label: string };
    dataRange: { min: string | null; max: string | null };
    accounts: { id: number; name: string }[];
    stats: { netWorth: number; cash: number; income: number; expense: number; saved: number; savedPct: number };
    portfolio: { value: number; cost: number; gain: number; pct: number };
    spendCats: SpendCat[];
    upcoming: Upcoming[];
    holdings: Holding[];
    assetParts: AssetPart[];
    topExpenses: TopExpense[];
    history: HistoryMonth[];
    loanOwed: number;
}>();

const { eur, eurS, num, grad, primary, primarySoft, catColor, catName, hexToRgba, formatDate } = useGros();

const showTxn = ref(false);

const flowMax = computed(() => Math.max(props.stats.income, props.stats.expense) || 1);
const spendTotal = computed(() => props.spendCats.reduce((s, c) => s + c.amount, 0) || 1);

const spendDonut = computed(() => {
    let acc = 0;
    const stops = props.spendCats.map((c) => {
        const from = acc;
        const pct = (c.amount / spendTotal.value) * 100;
        acc += pct;
        return `${catColor(c.category_id)} ${from.toFixed(2)}% ${acc.toFixed(2)}%`;
    });
    return stops.length ? `conic-gradient(${stops.join(', ')})` : '#f1efe8';
});

const histMax = computed(() => Math.max(1, ...props.history.map((m) => Math.max(m.income, m.expense))));
const portValue = computed(() => props.portfolio.value || 1);
const assetTotal = computed(() => props.assetParts.reduce((s, p) => s + p.value, 0) || 1);
const topMax = computed(() => Math.max(1, ...props.topExpenses.map((e) => e.amount)));

function saveRate(m: HistoryMonth): number {
    return m.income > 0 ? Math.max(0, (m.income - m.expense) / m.income) : 0;
}

const isEmpty = computed(() => props.accounts.length === 0 && props.stats.netWorth === 0);

const cardShadow = '0 4px 18px rgba(60,55,40,.05)';
</script>

<template>
    <Head title="Prehľad" />
    <GrosLayout title="Prehľad" subtitle="Tvoje financie na jednom mieste">
        <template #action>
            <AddButton label="Pridať transakciu" @click="showTxn = true" />
        </template>

        <div class="gros-rise">
            <div v-if="!isEmpty" style="background: #fff; border-radius: 16px; padding: 12px 14px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-bottom: 14px">
                <PeriodSelector :period="period" :data-range="dataRange" path="/dashboard" />
            </div>

            <div v-if="isEmpty" style="background: #fff; border-radius: 20px; padding: 28px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-bottom: 16px; text-align: center">
                <div class="font-display" style="font-weight: 800; font-size: 20px; margin-bottom: 6px">Vitaj v Groši 👋</div>
                <div style="color: #8a8c9a; font-size: 14px; font-weight: 500">Začni pridaním účtu v sekcii <strong>Účty</strong> a potom si zapisuj transakcie. Prehľad sa naplní automaticky.</div>
            </div>

            <!-- Stat cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(196px, 1fr)); gap: 14px">
                <div style="border-radius: 20px; padding: 20px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                    <div style="font-size: 12.5px; font-weight: 600; opacity: 0.9">Čisté imanie</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px">{{ eurS(stats.netWorth) }}</div>
                    <div style="font-size: 12px; font-weight: 600; opacity: 0.88; margin-top: 8px">Hotovosť {{ eur(stats.cash) }} + investície {{ eur(portfolio.value) }}</div>
                </div>

                <div style="background: #fff; border-radius: 20px; padding: 20px" :style="{ boxShadow: cardShadow }">
                    <div style="display: flex; align-items: center; gap: 8px">
                        <span style="width: 28px; height: 28px; border-radius: 9px; background: #e6f7ec; display: flex; align-items: center; justify-content: center">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2ba35a" stroke-width="2.6" stroke-linecap="round"><path d="M12 19V6M6 12l6-6 6 6" /></svg>
                        </span>
                        <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Príjmy · {{ period.label }}</div>
                    </div>
                    <div class="font-display" style="font-weight: 800; font-size: 28px; letter-spacing: -0.8px; margin-top: 10px; color: #2ba35a">{{ eur(stats.income) }}</div>
                </div>

                <div style="background: #fff; border-radius: 20px; padding: 20px" :style="{ boxShadow: cardShadow }">
                    <div style="display: flex; align-items: center; gap: 8px">
                        <span style="width: 28px; height: 28px; border-radius: 9px; background: #fdeaea; display: flex; align-items: center; justify-content: center">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#e8544e" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v13M6 12l6 6 6-6" /></svg>
                        </span>
                        <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Výdavky · {{ period.label }}</div>
                    </div>
                    <div class="font-display" style="font-weight: 800; font-size: 28px; letter-spacing: -0.8px; margin-top: 10px; color: #e8544e">{{ eur(stats.expense) }}</div>
                </div>

                <div style="background: #fff; border-radius: 20px; padding: 20px" :style="{ boxShadow: cardShadow }">
                    <div style="display: flex; align-items: center; gap: 8px">
                        <span style="width: 28px; height: 28px; border-radius: 9px; background: #e5f6f8; display: flex; align-items: center; justify-content: center">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0fa3b1" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 21a7 7 0 1 0 0-14 7 7 0 0 0 0 14z" /></svg>
                        </span>
                        <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Ušetrené · {{ period.label }}</div>
                    </div>
                    <div class="font-display" style="font-weight: 800; font-size: 28px; letter-spacing: -0.8px; margin-top: 10px; color: #0fa3b1">{{ eurS(stats.saved) }}</div>
                </div>
            </div>

            <!-- Flow + categories / portfolio + upcoming -->
            <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px">
                <div style="flex: 2; min-width: 340px; display: flex; flex-direction: column; gap: 14px">
                    <div style="background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                        <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Tok peňazí · {{ period.label }}</div>
                        <div style="margin-top: 16px; display: flex; flex-direction: column; gap: 14px">
                            <div>
                                <div style="display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 7px">
                                    <span style="color: #6a6c7a">Príjmy</span><span style="color: #2ba35a">{{ eur(stats.income) }}</span>
                                </div>
                                <div style="height: 14px; background: #f1efe8; border-radius: 8px; overflow: hidden">
                                    <div :style="{ height: '100%', width: (stats.income / flowMax) * 100 + '%', background: 'linear-gradient(90deg,#3fc274,#2ba35a)', borderRadius: '8px', transition: 'width .6s ease' }"></div>
                                </div>
                            </div>
                            <div>
                                <div style="display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 7px">
                                    <span style="color: #6a6c7a">Výdavky</span><span style="color: #e8544e">{{ eur(stats.expense) }}</span>
                                </div>
                                <div style="height: 14px; background: #f1efe8; border-radius: 8px; overflow: hidden">
                                    <div :style="{ height: '100%', width: (stats.expense / flowMax) * 100 + '%', background: 'linear-gradient(90deg,#ff7a63,#e8544e)', borderRadius: '8px', transition: 'width .6s ease' }"></div>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 18px; padding-top: 16px; border-top: 1px solid #f1efe8; display: flex; align-items: center; justify-content: space-between">
                            <div style="font-size: 13px; font-weight: 600; color: #6a6c7a">Zostatok obdobia</div>
                            <div style="display: flex; align-items: center; gap: 9px">
                                <span style="font-size: 12px; font-weight: 700; color: #0fa3b1; background: #e5f6f8; padding: 4px 9px; border-radius: 20px">{{ stats.savedPct }} %</span>
                                <span class="font-display" style="font-weight: 800; font-size: 20px; color: #0fa3b1">{{ eurS(stats.saved) }}</span>
                            </div>
                        </div>
                    </div>

                    <div style="background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                        <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Výdavky podľa kategórie · {{ period.label }}</div>
                        <div v-if="spendCats.length" style="display: flex; flex-wrap: wrap; align-items: center; gap: 26px; margin-top: 18px">
                            <div style="position: relative; width: 150px; height: 150px; flex-shrink: 0; border-radius: 50%" :style="{ background: spendDonut }">
                                <div style="position: absolute; inset: 26px; background: #fff; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center">
                                    <div style="font-size: 11px; font-weight: 600; color: #9a9cab">Spolu</div>
                                    <div class="font-display" style="font-weight: 800; font-size: 16px; letter-spacing: -0.4px">{{ eur(spendTotal) }}</div>
                                </div>
                            </div>
                            <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 11px">
                                <div v-for="c in spendCats" :key="c.category_id" style="display: flex; align-items: center; gap: 11px">
                                    <span style="width: 11px; height: 11px; border-radius: 4px; flex-shrink: 0" :style="{ background: catColor(c.category_id) }"></span>
                                    <span style="font-size: 13.5px; font-weight: 600; flex: 1">{{ catName(c.category_id) }}</span>
                                    <span style="font-size: 13.5px; font-weight: 700">{{ eur(c.amount) }}</span>
                                    <span style="font-size: 12px; font-weight: 600; color: #9a9cab; width: 38px; text-align: right">{{ num((c.amount / spendTotal) * 100) }}%</span>
                                </div>
                            </div>
                        </div>
                        <div v-else style="color: #b0b2bd; font-weight: 600; font-size: 14px; padding: 24px 0">Zatiaľ žiadne výdavky.</div>
                    </div>
                </div>

                <div style="flex: 1; min-width: 270px; display: flex; flex-direction: column; gap: 14px">
                    <div style="background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                        <div style="display: flex; align-items: center; justify-content: space-between">
                            <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Portfólio</div>
                            <span style="font-size: 12px; font-weight: 700; color: #2ba35a; background: #e6f7ec; padding: 4px 9px; border-radius: 20px">{{ portfolio.gain >= 0 ? '+' : '−' }}{{ num(Math.abs(portfolio.pct), 1) }} %</span>
                        </div>
                        <div class="font-display" style="font-weight: 800; font-size: 27px; letter-spacing: -0.9px; margin-top: 12px">{{ eur(portfolio.value) }}</div>
                        <div style="font-size: 13px; font-weight: 600; margin-top: 3px" :style="{ color: portfolio.gain >= 0 ? '#2ba35a' : '#e8544e' }">{{ portfolio.gain >= 0 ? '+' : '−' }}{{ eur(portfolio.gain) }}</div>
                        <div v-if="holdings.length" style="display: flex; height: 10px; border-radius: 6px; overflow: hidden; margin-top: 16px; gap: 2px">
                            <div v-for="h in holdings" :key="h.ticker" :style="{ width: (h.value / portValue) * 100 + '%', background: h.color }"></div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 9px; margin-top: 14px">
                            <div v-for="h in holdings" :key="h.ticker" style="display: flex; align-items: center; gap: 9px">
                                <span style="width: 9px; height: 9px; border-radius: 3px" :style="{ background: h.color }"></span>
                                <span style="font-size: 13px; font-weight: 700">{{ h.ticker }}</span>
                                <span style="font-size: 12px; color: #9a9cab; flex: 1">{{ num((h.value / portValue) * 100) }}%</span>
                                <span style="font-size: 13px; font-weight: 700">{{ eur(h.value) }}</span>
                            </div>
                            <div v-if="!holdings.length" style="color: #b0b2bd; font-weight: 600; font-size: 13px">Žiadne investície.</div>
                        </div>
                    </div>

                    <div style="background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                        <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Najbližšie platby</div>
                        <div style="display: flex; flex-direction: column; gap: 13px; margin-top: 15px">
                            <div v-for="s in upcoming" :key="s.name" style="display: flex; align-items: center; gap: 12px">
                                <span style="width: 34px; height: 34px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex-shrink: 0" :style="{ background: hexToRgba(s.color, 0.14), color: s.color }">{{ s.name[0] }}</span>
                                <div style="flex: 1; min-width: 0">
                                    <div style="font-size: 13.5px; font-weight: 700">{{ s.name }}</div>
                                    <div style="font-size: 11.5px; color: #9a9cab; font-weight: 500">{{ formatDate(s.next_payment) }}</div>
                                </div>
                                <div style="font-size: 14px; font-weight: 700">{{ eur(s.amount) }}</div>
                            </div>
                            <div v-if="!upcoming.length" style="color: #b0b2bd; font-weight: 600; font-size: 13px">Žiadne predplatné.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analytics: income vs expense + savings rate -->
            <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px">
                <div style="flex: 2; min-width: 340px; background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px">
                        <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Príjmy vs výdavky · 6 mesiacov</div>
                        <div style="display: flex; align-items: center; gap: 14px">
                            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a"><span style="width: 10px; height: 10px; border-radius: 3px; background: #2ba35a"></span>Príjmy</span>
                            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a"><span style="width: 10px; height: 10px; border-radius: 3px; background: #e8544e"></span>Výdavky</span>
                        </div>
                    </div>
                    <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; height: 180px; margin-top: 22px">
                        <div v-for="m in history" :key="m.label" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 8px; height: 100%; justify-content: flex-end">
                            <div style="display: flex; align-items: flex-end; gap: 4px; width: 100%; justify-content: center; flex: 1">
                                <div :title="eur(m.income)" :style="{ width: '38%', maxWidth: '20px', height: (m.income / histMax) * 100 + '%', background: 'linear-gradient(180deg,#3fc274,#2ba35a)', borderRadius: '5px 5px 0 0', transition: 'height .5s ease' }"></div>
                                <div :title="eur(m.expense)" :style="{ width: '38%', maxWidth: '20px', height: (m.expense / histMax) * 100 + '%', background: 'linear-gradient(180deg,#ff7a63,#e8544e)', borderRadius: '5px 5px 0 0', transition: 'height .5s ease' }"></div>
                            </div>
                            <div style="font-size: 11.5px; font-weight: 700; color: #9a9cab">{{ m.label }}</div>
                        </div>
                    </div>
                </div>

                <div style="flex: 1; min-width: 250px; border-radius: 20px; padding: 22px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Miera úspor · {{ period.label }}</div>
                    <div class="font-display" style="font-weight: 800; font-size: 40px; letter-spacing: -1.4px; margin-top: 10px">{{ stats.savedPct }} %</div>
                    <div style="font-size: 12.5px; font-weight: 600; opacity: 0.9">z príjmov za {{ period.label }}</div>
                    <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 6px; height: 60px; margin-top: 22px">
                        <div v-for="m in history" :key="m.label" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end">
                            <div :style="{ width: '100%', maxWidth: '16px', height: saveRate(m) * 100 + '%', background: 'rgba(255,255,255,.85)', borderRadius: '4px', transition: 'height .5s ease' }"></div>
                            <div style="font-size: 10px; font-weight: 700; opacity: 0.8">{{ m.label }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Asset composition + top expenses -->
            <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px">
                <div style="flex: 1.3; min-width: 300px; background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Zloženie majetku</div>
                    <div v-if="assetParts.length" style="display: flex; height: 16px; border-radius: 8px; overflow: hidden; margin-top: 18px; gap: 2px">
                        <div v-for="p in assetParts" :key="p.name" :title="p.name" :style="{ width: (p.value / assetTotal) * 100 + '%', background: p.color }"></div>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 11px; margin-top: 16px">
                        <div v-for="p in assetParts" :key="p.name" style="display: flex; align-items: center; gap: 11px">
                            <span style="width: 11px; height: 11px; border-radius: 4px; flex-shrink: 0" :style="{ background: p.color }"></span>
                            <span style="font-size: 13.5px; font-weight: 600; flex: 1">{{ p.name }}</span>
                            <span style="font-size: 13.5px; font-weight: 700">{{ eur(p.value) }}</span>
                            <span style="font-size: 12px; font-weight: 600; color: #9a9cab; width: 38px; text-align: right">{{ num((p.value / assetTotal) * 100) }}%</span>
                        </div>
                        <div v-if="!assetParts.length" style="color: #b0b2bd; font-weight: 600; font-size: 13px; padding: 8px 0">Zatiaľ žiadny majetok.</div>
                    </div>
                    <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1efe8; display: flex; align-items: center; justify-content: space-between">
                        <span style="font-size: 13px; font-weight: 600; color: #6a6c7a; display: flex; align-items: center; gap: 8px"><span style="width: 11px; height: 11px; border-radius: 4px; background: #e8544e"></span>Dlhy (záväzky)</span>
                        <span style="font-size: 14px; font-weight: 800; color: #e8544e">− {{ eur(loanOwed) }}</span>
                    </div>
                </div>

                <div style="flex: 1; min-width: 280px; background: #fff; border-radius: 20px; padding: 22px" :style="{ boxShadow: cardShadow }">
                    <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Najväčšie výdavky · {{ period.label }}</div>
                    <div style="display: flex; flex-direction: column; gap: 13px; margin-top: 16px">
                        <div v-for="(e, i) in topExpenses" :key="i">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px">
                                <span style="font-size: 13.5px; font-weight: 600; display: flex; align-items: center; gap: 8px; min-width: 0"><span style="width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0" :style="{ background: catColor(e.category_id) }"></span>{{ e.note || catName(e.category_id) }}</span>
                                <span style="font-size: 13.5px; font-weight: 800; white-space: nowrap">{{ eur(e.amount) }}</span>
                            </div>
                            <div style="height: 8px; background: #f1efe8; border-radius: 5px; overflow: hidden">
                                <div :style="{ height: '100%', width: (e.amount / topMax) * 100 + '%', background: catColor(e.category_id), borderRadius: '5px' }"></div>
                            </div>
                        </div>
                        <div v-if="!topExpenses.length" style="color: #b0b2bd; font-weight: 600; font-size: 13px">Žiadne výdavky tento mesiac.</div>
                    </div>
                </div>
            </div>
        </div>

        <TransactionModal v-if="showTxn" :accounts="accounts" @close="showTxn = false" />
    </GrosLayout>
</template>
