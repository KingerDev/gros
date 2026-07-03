<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ investmentId: number; ticker: string; presetType?: 'buy' | 'sell'; autoPrice?: boolean }>();
const emit = defineEmits<{ close: [] }>();

const { primary, primarySoft, eur } = useGros();

const priceMode = ref<'unit' | 'total'>('unit');
const fetching = ref(false);
const fetchMsg = ref<string | null>(null);

const form = useForm<{ type: 'buy' | 'sell'; units: string; amount: string; date: string }>({
    type: props.presetType ?? 'buy',
    units: '',
    amount: '',
    date: new Date().toISOString().slice(0, 10),
});

const num2 = (v: string) => parseFloat(String(v).replace(/\s/g, '').replace(',', '.')) || 0;
const title = computed(() => (form.type === 'buy' ? `Nákup · ${props.ticker}` : `Predaj · ${props.ticker}`));

// per-kus cena, ktorú pošleme na backend
const perUnit = computed(() => {
    if (priceMode.value === 'unit') return num2(form.amount);
    const u = num2(form.units);
    return u > 0 ? num2(form.amount) / u : 0;
});

function segStyle(active: boolean) {
    return { flex: '1', padding: '11px', borderRadius: '10px', fontSize: '14px', fontWeight: 700, color: active ? '#20212e' : '#8a8c9a', background: active ? '#fff' : 'transparent', boxShadow: active ? '0 2px 8px rgba(60,55,40,.08)' : 'none' };
}

async function fetchPrice() {
    fetchMsg.value = null;
    fetching.value = true;
    try {
        const r = await fetch(`/investments/${props.investmentId}/price?date=${form.date}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const j = await r.json();
        if (j.price != null) {
            priceMode.value = 'unit';
            form.amount = String(j.price).replace('.', ',');
            fetchMsg.value = `Cena k ${form.date}: ${eur(j.price)} / kus`;
        } else {
            fetchMsg.value = 'Cena sa k tomuto dátumu nenašla — zadaj ju ručne.';
        }
    } catch {
        fetchMsg.value = 'Nepodarilo sa zistiť cenu.';
    } finally {
        fetching.value = false;
    }
}

function submit() {
    form
        .transform((d) => ({ type: d.type, units: num2(d.units), price: perUnit.value, date: d.date }))
        .post(`/investments/${props.investmentId}/lots`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="title" @close="emit('close')">
        <div style="display: flex; gap: 8px; background: #f1efe8; padding: 4px; border-radius: 13px; margin-bottom: 18px">
            <button type="button" :style="segStyle(form.type === 'buy')" @click="form.type = 'buy'">Nákup</button>
            <button type="button" :style="segStyle(form.type === 'sell')" @click="form.type = 'sell'">Predaj</button>
        </div>

        <div style="margin-bottom: 16px">
            <label class="gros-label">Počet kusov</label>
            <input v-model="form.units" type="text" inputmode="decimal" placeholder="0" class="gros-input" style="font-weight: 700" />
        </div>

        <div style="display: flex; gap: 8px; background: #f1efe8; padding: 4px; border-radius: 12px; margin-bottom: 12px">
            <button type="button" :style="segStyle(priceMode === 'unit')" @click="priceMode = 'unit'">Cena za kus</button>
            <button type="button" :style="segStyle(priceMode === 'total')" @click="priceMode = 'total'">Celková suma</button>
        </div>

        <div style="margin-bottom: 6px">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px">
                <label class="gros-label" style="margin-bottom: 0">{{ priceMode === 'unit' ? `${form.type === 'buy' ? 'Nákupná' : 'Predajná'} cena / kus (€)` : 'Celková suma (€)' }}</label>
                <button
                    v-if="autoPrice && priceMode === 'unit'"
                    type="button"
                    style="display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700"
                    :style="{ color: primary, opacity: fetching ? 0.6 : 1 }"
                    :disabled="fetching"
                    @click="fetchPrice"
                >
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" :style="{ animation: fetching ? 'spin 0.8s linear infinite' : 'none' }"><path d="M21 12a9 9 0 1 1-3-6.7L21 8" /><path d="M21 3v5h-5" /></svg>
                    {{ fetching ? 'Zisťujem…' : 'Zistiť cenu k dátumu' }}
                </button>
            </div>
            <input v-model="form.amount" type="text" inputmode="decimal" placeholder="0,00" class="gros-input" style="font-weight: 700; margin-top: 7px" />
        </div>
        <div v-if="priceMode === 'total' && num2(form.units) > 0 && num2(form.amount) > 0" style="font-size: 12px; color: #9a9cab; font-weight: 600; margin-bottom: 16px">
            = {{ eur(perUnit) }} / kus
        </div>
        <div v-if="fetchMsg" style="font-size: 12px; color: #2a6ebd; font-weight: 600; margin-bottom: 16px">{{ fetchMsg }}</div>
        <div v-else style="margin-bottom: 16px"></div>

        <div style="margin-bottom: 24px">
            <label class="gros-label">Dátum</label>
            <input v-model="form.date" type="date" class="gros-input" />
        </div>

        <button
            type="button"
            style="width: 100%; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
            :style="{ background: primary, boxShadow: `0 10px 22px ${primarySoft}`, opacity: form.processing ? 0.7 : 1 }"
            :disabled="form.processing"
            @click="submit"
        >
            {{ form.type === 'buy' ? 'Pridať nákup' : 'Pridať predaj' }}
        </button>
    </Modal>
</template>

<style scoped>
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
