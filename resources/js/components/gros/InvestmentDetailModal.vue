<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Lot {
    id: number;
    type: string;
    units: number;
    price: number;
    date: string;
    note: string | null;
}
interface Investment {
    id: number;
    ticker: string;
    name: string;
    kind: string;
    quote_symbol: string | null;
    quote_source: string;
    units: number;
    buy_price: number;
    current_price: number;
    last_price_at: string | null;
    color: string;
    value: number;
    cost: number;
    gain: number;
    realized: number;
    lots: Lot[];
}

const props = defineProps<{ investment: Investment }>();
const emit = defineEmits<{ close: []; buy: []; sell: []; edit: [] }>();

const { eur, num, fmtUnits, kindLabel, hexToRgba, formatDate } = useGros();

function ago(iso: string | null): string {
    if (!iso) return 'nikdy';
    const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 90) return 'pred chvíľou';
    if (s < 3600) return `pred ${Math.floor(s / 60)} min`;
    if (s < 86400) return `pred ${Math.floor(s / 3600)} h`;
    return `pred ${Math.floor(s / 86400)} dňami`;
}

function delLot(l: Lot) {
    router.delete(`/investments/${props.investment.id}/lots/${l.id}`, { preserveScroll: true });
}

const gainColor = computed(() => (props.investment.gain >= 0 ? '#2ba35a' : '#e8544e'));
const pct = computed(() => (props.investment.cost > 0 ? (props.investment.gain / props.investment.cost) * 100 : 0));
</script>

<template>
    <Modal :title="investment.ticker" @close="emit('close')">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px; margin-top: -8px">
            <span style="width: 46px; height: 46px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; flex-shrink: 0" :style="{ background: hexToRgba(investment.color, 0.16), color: investment.color }">{{ investment.ticker.slice(0, 4) }}</span>
            <div style="flex: 1; min-width: 0">
                <div style="font-size: 15px; font-weight: 700">{{ investment.name }}</div>
                <div style="font-size: 12px; color: #9a9cab; font-weight: 500">
                    {{ kindLabel(investment.kind) }}
                    <span v-if="investment.quote_source !== 'manual'"> · online ({{ investment.quote_symbol }}) · {{ ago(investment.last_price_at) }}</span>
                    <span v-else> · manuálna cena</span>
                </div>
            </div>
        </div>

        <!-- Hodnota + výnos -->
        <div style="background: #faf9f5; border-radius: 16px; padding: 18px; margin-bottom: 16px">
            <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 10px">
                <div>
                    <div style="font-size: 12px; font-weight: 600; color: #8a8c9a">Aktuálna hodnota</div>
                    <div class="font-display" style="font-weight: 800; font-size: 28px; letter-spacing: -0.8px">{{ eur(investment.value) }}</div>
                </div>
                <div style="text-align: right">
                    <div style="font-size: 13px; font-weight: 800" :style="{ color: gainColor }">{{ investment.gain >= 0 ? '+' : '−' }}{{ eur(investment.gain) }}</div>
                    <div style="font-size: 13px; font-weight: 700" :style="{ color: gainColor }">{{ investment.gain >= 0 ? '+' : '−' }}{{ num(Math.abs(pct), 1) }} %</div>
                </div>
            </div>
            <div style="display: flex; gap: 18px; margin-top: 14px; flex-wrap: wrap">
                <div><div style="font-size: 11px; color: #9a9cab; font-weight: 600">Kusov</div><div style="font-size: 14px; font-weight: 700">{{ fmtUnits(investment.units) }}</div></div>
                <div><div style="font-size: 11px; color: #9a9cab; font-weight: 600">Ø nákup</div><div style="font-size: 14px; font-weight: 700">{{ eur(investment.buy_price) }}</div></div>
                <div><div style="font-size: 11px; color: #9a9cab; font-weight: 600">Cena teraz</div><div style="font-size: 14px; font-weight: 700">{{ eur(investment.current_price) }}</div></div>
                <div><div style="font-size: 11px; color: #9a9cab; font-weight: 600">Vklad</div><div style="font-size: 14px; font-weight: 700">{{ eur(investment.cost) }}</div></div>
                <div v-if="investment.realized !== 0"><div style="font-size: 11px; color: #9a9cab; font-weight: 600">Realizované</div><div style="font-size: 14px; font-weight: 700" :style="{ color: investment.realized >= 0 ? '#2ba35a' : '#e8544e' }">{{ investment.realized >= 0 ? '+' : '−' }}{{ eur(investment.realized) }}</div></div>
            </div>
        </div>

        <!-- Akcie -->
        <div style="display: flex; gap: 8px; margin-bottom: 18px">
            <button type="button" style="flex: 1; background: #e6f7ec; color: #2ba35a; font-weight: 700; font-size: 14px; padding: 12px; border-radius: 12px" @click="emit('buy')">Kúpiť</button>
            <button type="button" style="flex: 1; background: #fdeaea; color: #e8544e; font-weight: 700; font-size: 14px; padding: 12px; border-radius: 12px" @click="emit('sell')">Predať</button>
            <button type="button" style="background: #f1efe8; color: #61637a; font-weight: 700; font-size: 14px; padding: 12px 16px; border-radius: 12px" @click="emit('edit')">Upraviť</button>
        </div>

        <!-- História nákupov/predajov -->
        <div style="font-size: 13px; font-weight: 700; color: #6a6c7a; margin-bottom: 8px">História ({{ investment.lots.length }})</div>
        <div style="display: flex; flex-direction: column; gap: 4px">
            <div v-for="l in [...investment.lots].reverse()" :key="l.id" style="display: flex; align-items: center; gap: 10px; padding: 10px 8px; border-radius: 11px" style-hover="background:#faf9f5">
                <span style="width: 30px; height: 30px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0" :style="{ background: l.type === 'buy' ? '#e6f7ec' : '#fdeaea', color: l.type === 'buy' ? '#2ba35a' : '#e8544e' }">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path v-if="l.type === 'buy'" d="M12 5v14M5 12h14" /><path v-else d="M5 12h14" /></svg>
                </span>
                <div style="flex: 1; min-width: 0">
                    <div style="font-size: 13.5px; font-weight: 700">{{ l.type === 'buy' ? 'Nákup' : 'Predaj' }} · {{ fmtUnits(l.units) }} ks</div>
                    <div style="font-size: 11.5px; color: #9a9cab; font-weight: 500">{{ eur(l.price) }} / kus · {{ formatDate(l.date) }}</div>
                </div>
                <div style="font-size: 13.5px; font-weight: 700; white-space: nowrap">{{ eur(l.units * l.price) }}</div>
                <button type="button" style="width: 28px; height: 28px; border-radius: 8px; color: #c4c2ba; display: flex; align-items: center; justify-content: center; flex-shrink: 0" title="Zmazať" @click="delLot(l)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" /></svg>
                </button>
            </div>
            <div v-if="!investment.lots.length" style="padding: 20px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 13px">Žiadne nákupy. Klikni „Kúpiť".</div>
        </div>
    </Modal>
</template>
