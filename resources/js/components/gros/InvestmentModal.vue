<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Investment {
    id: number;
    ticker: string;
    name: string;
    kind: string;
    quote_symbol: string | null;
    quote_source: string;
    current_price: number;
}

const props = defineProps<{ investment?: Investment | null }>();
const emit = defineEmits<{ close: [] }>();

const { primary, primarySoft } = useGros();
const editing = computed(() => !!props.investment);

const kinds = [
    { value: 'etf', label: 'ETF' },
    { value: 'stock', label: 'Akcia' },
    { value: 'crypto', label: 'Krypto' },
];

const auto = ref((props.investment?.quote_source ?? 'yahoo') !== 'manual');
const num2 = (v: string) => parseFloat(String(v).replace(/\s/g, '').replace(',', '.')) || 0;

const form = useForm<Record<string, unknown>>({
    ticker: props.investment?.ticker ?? '',
    name: props.investment?.name ?? '',
    kind: props.investment?.kind ?? 'etf',
    quote_symbol: props.investment?.quote_symbol ?? '',
    current_price: props.investment ? String(props.investment.current_price).replace('.', ',') : '',
    // prvý nákup (len pri novej)
    units: '',
    buy_price: '',
    date: new Date().toISOString().slice(0, 10),
});

const source = computed(() => (auto.value ? (form.kind === 'crypto' ? 'coingecko' : 'yahoo') : 'manual'));
const symbolHint = computed(() => (form.kind === 'crypto' ? 'napr. bitcoin, ethereum, solana (CoinGecko id)' : 'napr. VWCE.DE, ZPRV.DE, O (Yahoo symbol)'));

function submit() {
    form
        .transform((d) => ({
            ticker: d.ticker,
            name: d.name,
            kind: d.kind,
            quote_source: source.value,
            quote_symbol: auto.value ? d.quote_symbol : null,
            current_price: auto.value ? null : num2(d.current_price as string),
            units: !editing.value && d.units ? num2(d.units as string) : null,
            buy_price: !editing.value && d.buy_price ? num2(d.buy_price as string) : null,
            date: d.date,
        }))
        .submit(editing.value ? 'put' : 'post', editing.value ? `/investments/${props.investment!.id}` : '/investments', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}

function destroy() {
    if (props.investment) form.delete(`/investments/${props.investment.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="editing ? 'Upraviť investíciu' : 'Nová investícia'" @close="emit('close')">
        <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap">
            <div style="flex: 1; min-width: 120px">
                <label class="gros-label">Ticker</label>
                <input v-model="form.ticker" type="text" placeholder="napr. VWCE" class="gros-input" style="text-transform: uppercase; font-weight: 700" />
            </div>
            <div style="flex: 1; min-width: 120px">
                <label class="gros-label">Typ</label>
                <select v-model="form.kind" class="gros-select">
                    <option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</option>
                </select>
            </div>
        </div>
        <div style="margin-bottom: 16px">
            <label class="gros-label">Názov</label>
            <input v-model="form.name" type="text" placeholder="napr. Vanguard FTSE All-World" class="gros-input" />
        </div>

        <!-- Auto vs manuálne ceny -->
        <button type="button" style="display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; padding: 12px 14px; background: #faf9f5; border: 1.5px solid #eceae2; border-radius: 13px; margin-bottom: 16px" @click="auto = !auto">
            <div style="flex: 1">
                <div style="font-size: 14px; font-weight: 700">Automatické ceny online</div>
                <div style="font-size: 12px; color: #8a8c9a; font-weight: 500; margin-top: 2px">{{ auto ? 'Cena sa ťahá z internetu' : 'Cenu zadávaš ručne' }}</div>
            </div>
            <span style="width: 46px; height: 28px; border-radius: 20px; flex-shrink: 0; transition: background 0.2s; position: relative" :style="{ background: auto ? '#2ba35a' : '#dcdace' }">
                <span style="position: absolute; top: 3px; width: 22px; height: 22px; border-radius: 50%; background: #fff; transition: left 0.2s" :style="{ left: auto ? '21px' : '3px' }"></span>
            </span>
        </button>

        <div v-if="auto" style="margin-bottom: 16px">
            <label class="gros-label">Symbol pre ceny</label>
            <input v-model="form.quote_symbol" type="text" :placeholder="symbolHint" class="gros-input" />
            <div style="font-size: 11.5px; color: #9a9cab; font-weight: 500; margin-top: 5px">{{ symbolHint }}</div>
        </div>
        <div v-else style="margin-bottom: 16px">
            <label class="gros-label">Aktuálna cena / kus (€)</label>
            <input v-model="form.current_price" type="text" inputmode="decimal" placeholder="0,00" class="gros-input" style="font-weight: 700" />
        </div>

        <!-- prvý nákup (len nová) -->
        <div v-if="!editing" style="padding: 14px; background: #eef6ff; border-radius: 13px; margin-bottom: 24px">
            <div style="font-size: 13px; font-weight: 700; color: #2a6ebd; margin-bottom: 10px">Prvý nákup (voliteľné)</div>
            <div style="display: flex; gap: 12px; flex-wrap: wrap">
                <div style="flex: 1; min-width: 100px">
                    <label class="gros-label">Počet kusov</label>
                    <input v-model="form.units" type="text" inputmode="decimal" placeholder="0" class="gros-input" style="font-weight: 700" />
                </div>
                <div style="flex: 1; min-width: 100px">
                    <label class="gros-label">Cena / kus (€)</label>
                    <input v-model="form.buy_price" type="text" inputmode="decimal" placeholder="0,00" class="gros-input" style="font-weight: 700" />
                </div>
                <div style="flex: 1; min-width: 120px">
                    <label class="gros-label">Dátum</label>
                    <input v-model="form.date" type="date" class="gros-input" />
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 10px">
            <button
                v-if="editing"
                type="button"
                style="flex-shrink: 0; background: #fdeaea; color: #e8544e; font-weight: 800; font-size: 15px; padding: 15px 18px; border-radius: 14px"
                @click="destroy"
            >
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" /></svg>
            </button>
            <button
                type="button"
                style="flex: 1; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
                :style="{ background: primary, boxShadow: `0 10px 22px ${primarySoft}`, opacity: form.processing ? 0.7 : 1 }"
                :disabled="form.processing"
                @click="submit"
            >
                {{ editing ? 'Uložiť zmeny' : 'Pridať investíciu' }}
            </button>
        </div>
    </Modal>
</template>
