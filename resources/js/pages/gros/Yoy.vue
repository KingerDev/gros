<script setup lang="ts">
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Year {
    year: number;
    income: number;
    expense: number;
    net: number;
    rate: number;
}

const props = defineProps<{ years: Year[] }>();

const { eur, eurS, num, grad, primary, primarySoft } = useGros();

const hasData = computed(() => props.years.length > 0);
const cur = computed(() => props.years[props.years.length - 1] ?? null);
const prev = computed(() => (props.years.length >= 2 ? props.years[props.years.length - 2] : null));

const yoyMax = computed(() => Math.max(1, ...props.years.map((y) => Math.max(y.income, y.expense))));

const netDelta = computed(() => (cur.value && prev.value ? cur.value.net - prev.value.net : null));
const incPct = computed(() => (cur.value && prev.value && prev.value.income > 0 ? ((cur.value.income - prev.value.income) / prev.value.income) * 100 : null));
const expPct = computed(() => (cur.value && prev.value && prev.value.expense > 0 ? ((cur.value.expense - prev.value.expense) / prev.value.expense) * 100 : null));

// Riadky tabuľky zostupne, s medziročnou zmenou čistého toku
const rows = computed(() =>
    [...props.years]
        .map((y, i) => {
            const prevYear = i > 0 ? props.years[i - 1] : null;
            const delta = prevYear ? y.net - prevYear.net : null;
            return { ...y, delta };
        })
        .reverse(),
);
</script>

<template>
    <Head title="Medziročne" />
    <GrosLayout title="Medziročne" subtitle="Ako sa mení tvoj cashflow po rokoch">
        <div class="gros-rise">
            <div v-if="!hasData" style="background: #fff; border-radius: 20px; padding: 40px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                Zatiaľ nemáš žiadne transakcie — medziročné porovnanie sa objaví, keď začneš zapisovať príjmy a výdavky.
            </div>

            <template v-else>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px">
                    <div style="border-radius: 20px; padding: 20px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                        <div style="font-size: 12.5px; font-weight: 600; opacity: 0.9">Čistý cashflow {{ cur!.year }}</div>
                        <div class="font-display" style="font-weight: 800; font-size: 28px; letter-spacing: -0.9px; margin-top: 8px">{{ eurS(cur!.net) }}</div>
                        <div v-if="netDelta !== null && prev" style="font-size: 12px; font-weight: 700; margin-top: 8px; display: inline-flex; align-items: center; gap: 5px; background: rgba(255, 255, 255, 0.2); padding: 4px 9px; border-radius: 20px">
                            {{ netDelta >= 0 ? '▲' : '▼' }} {{ eur(netDelta) }} vs {{ prev.year }}
                        </div>
                    </div>
                    <div style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                        <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Príjmy {{ cur!.year }}</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px; color: #2ba35a">{{ eur(cur!.income) }}</div>
                        <div v-if="incPct !== null" style="font-size: 12px; font-weight: 700; margin-top: 7px" :style="{ color: incPct >= 0 ? '#2ba35a' : '#e8544e' }">{{ incPct >= 0 ? '▲' : '▼' }} {{ num(Math.abs(incPct), 1) }} % medziročne</div>
                    </div>
                    <div style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                        <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Výdavky {{ cur!.year }}</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px; color: #e8544e">{{ eur(cur!.expense) }}</div>
                        <div v-if="expPct !== null" style="font-size: 12px; font-weight: 700; margin-top: 7px" :style="{ color: expPct <= 0 ? '#2ba35a' : '#e8544e' }">{{ expPct >= 0 ? '▲' : '▼' }} {{ num(Math.abs(expPct), 1) }} % medziročne</div>
                    </div>
                    <div style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                        <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Miera úspor {{ cur!.year }}</div>
                        <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 8px; color: #0fa3b1">{{ num(cur!.rate) }} %</div>
                        <div v-if="prev" style="font-size: 12px; font-weight: 700; color: #9a9cab; margin-top: 7px">{{ prev.year }} bolo {{ num(prev.rate) }} %</div>
                    </div>
                </div>

                <!-- grouped bar chart -->
                <div style="background: #fff; border-radius: 20px; padding: 24px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-top: 14px">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px">
                        <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Ročný cashflow podľa rokov</div>
                        <div style="display: flex; align-items: center; gap: 14px">
                            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a"><span style="width: 10px; height: 10px; border-radius: 3px; background: #2ba35a"></span>Príjmy</span>
                            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a"><span style="width: 10px; height: 10px; border-radius: 3px; background: #e8544e"></span>Výdavky</span>
                            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a"><span style="width: 10px; height: 10px; border-radius: 3px" :style="{ background: primary }"></span>Úspory</span>
                        </div>
                    </div>
                    <div style="display: flex; align-items: flex-end; justify-content: space-around; gap: 16px; height: 240px; margin-top: 26px">
                        <div v-for="y in years" :key="y.year" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 10px; height: 100%; justify-content: flex-end">
                            <div style="display: flex; align-items: flex-end; gap: 5px; width: 100%; justify-content: center; flex: 1">
                                <div :title="eur(y.income)" :style="{ width: '26%', maxWidth: '26px', height: (y.income / yoyMax) * 100 + '%', background: 'linear-gradient(180deg,#3fc274,#2ba35a)', borderRadius: '5px 5px 0 0', transition: 'height .6s ease' }"></div>
                                <div :title="eur(y.expense)" :style="{ width: '26%', maxWidth: '26px', height: (y.expense / yoyMax) * 100 + '%', background: 'linear-gradient(180deg,#ff7a63,#e8544e)', borderRadius: '5px 5px 0 0', transition: 'height .6s ease' }"></div>
                                <div :title="eurS(y.net)" :style="{ width: '26%', maxWidth: '26px', height: (Math.max(0, y.net) / yoyMax) * 100 + '%', background: y.net < 0 ? '#e8544e' : primary, borderRadius: '5px 5px 0 0', transition: 'height .6s ease' }"></div>
                            </div>
                            <div style="font-size: 13px; font-weight: 800; color: #20212e">{{ y.year }}</div>
                        </div>
                    </div>
                </div>

                <!-- table -->
                <div style="background: #fff; border-radius: 20px; padding: 10px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); margin-top: 14px; overflow-x: auto">
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px 10px; font-size: 11.5px; font-weight: 700; color: #9a9cab; text-transform: uppercase; letter-spacing: 0.4px; min-width: 460px">
                        <span style="width: 52px">Rok</span>
                        <span style="flex: 1; text-align: right">Príjmy</span>
                        <span style="flex: 1; text-align: right">Výdavky</span>
                        <span style="flex: 1; text-align: right">Čistý tok</span>
                        <span style="width: 100px; text-align: right">Zmena</span>
                    </div>
                    <div v-for="r in rows" :key="r.year" style="display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 14px; min-width: 460px">
                        <span class="font-display" style="width: 52px; font-weight: 800; font-size: 16px">{{ r.year }}</span>
                        <span style="flex: 1; text-align: right; font-size: 14px; font-weight: 700; color: #2ba35a">{{ eur(r.income) }}</span>
                        <span style="flex: 1; text-align: right; font-size: 14px; font-weight: 700; color: #e8544e">{{ eur(r.expense) }}</span>
                        <span style="flex: 1; text-align: right; font-size: 14px; font-weight: 800" :style="{ color: r.net < 0 ? '#e8544e' : '#20212e' }">{{ eurS(r.net) }}</span>
                        <span style="width: 100px; text-align: right; font-size: 12.5px; font-weight: 700" :style="{ color: r.delta === null ? '#9a9cab' : r.delta >= 0 ? '#2ba35a' : '#e8544e' }">
                            {{ r.delta === null ? '—' : (r.delta >= 0 ? '▲ ' : '▼ ') + eur(r.delta) }}
                        </span>
                    </div>
                </div>
            </template>
        </div>
    </GrosLayout>
</template>
