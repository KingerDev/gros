<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface LoanEdit {
    id: number;
    kind: string;
    name: string;
    balance: number | string;
    principal: number | string;
    payment: number | string;
    rate: number | string;
    next_payment: string;
}

const props = defineProps<{ loan?: LoanEdit | null }>();
const emit = defineEmits<{ close: [] }>();

const { primary, primarySoft } = useGros();
const editing = computed(() => !!props.loan);
const s = (v: number | string | undefined) => (v === 0 || v ? String(v).replace('.', ',') : '');

const form = useForm({
    kind: props.loan?.kind ?? 'owe',
    name: props.loan?.name ?? '',
    balance: s(props.loan?.balance),
    principal: s(props.loan?.principal),
    payment: s(props.loan?.payment),
    rate: s(props.loan?.rate),
    next_payment: props.loan?.next_payment?.slice(0, 10) ?? new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
});

const balanceLabel = computed(() => (form.kind === 'owe' ? 'Zostáva splatiť' : 'Má mi vrátiť'));
const dateLabel = computed(() => (form.kind === 'owe' ? 'Ďalšia splátka' : 'Termín vrátenia'));

function segStyle(v: string) {
    const active = form.kind === v;
    return { flex: '1', padding: '11px', borderRadius: '10px', fontSize: '14px', fontWeight: 700, color: active ? '#20212e' : '#8a8c9a', background: active ? '#fff' : 'transparent', boxShadow: active ? '0 2px 8px rgba(60,55,40,.08)' : 'none' };
}

const num2 = (v: string) => parseFloat(String(v).replace(/\s/g, '').replace(',', '.')) || 0;

function submit() {
    form
        .transform((d) => ({
            ...d,
            balance: num2(d.balance),
            principal: num2(d.principal) || num2(d.balance),
            payment: num2(d.payment),
            rate: num2(d.rate),
        }))
        .submit(editing.value ? 'put' : 'post', editing.value ? `/loans/${props.loan!.id}` : '/loans', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}

function destroy() {
    if (props.loan) form.delete(`/loans/${props.loan.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="editing ? 'Upraviť úver' : 'Nový úver'" @close="emit('close')">
        <div style="display: flex; gap: 8px; background: #f1efe8; padding: 4px; border-radius: 13px; margin-bottom: 18px">
            <button type="button" :style="segStyle('owe')" @click="form.kind = 'owe'">Dlžím</button>
            <button type="button" :style="segStyle('lent')" @click="form.kind = 'lent'">Požičal som</button>
        </div>
        <div style="margin-bottom: 16px">
            <label class="gros-label">Názov / veriteľ</label>
            <input v-model="form.name" type="text" placeholder="napr. Hypotéka VÚB" class="gros-input" />
        </div>
        <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap">
            <div style="flex: 1; min-width: 130px">
                <label class="gros-label">{{ balanceLabel }} (€)</label>
                <input v-model="form.balance" type="text" inputmode="decimal" placeholder="0,00" class="gros-input" style="font-weight: 700" />
            </div>
            <div style="flex: 1; min-width: 130px">
                <label class="gros-label">Pôvodná suma (€)</label>
                <input v-model="form.principal" type="text" inputmode="decimal" placeholder="0,00" class="gros-input" style="font-weight: 700" />
            </div>
        </div>
        <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap">
            <div style="flex: 1; min-width: 120px">
                <label class="gros-label">Mes. splátka (€)</label>
                <input v-model="form.payment" type="text" inputmode="decimal" placeholder="0,00" class="gros-input" style="font-weight: 700" />
            </div>
            <div style="flex: 1; min-width: 120px">
                <label class="gros-label">Úrok (% p.a.)</label>
                <input v-model="form.rate" type="text" inputmode="decimal" placeholder="0,0" class="gros-input" style="font-weight: 700" />
            </div>
        </div>
        <div style="margin-bottom: 24px">
            <label class="gros-label">{{ dateLabel }}</label>
            <input v-model="form.next_payment" type="date" class="gros-input" />
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
                {{ editing ? 'Uložiť zmeny' : 'Pridať úver' }}
            </button>
        </div>
    </Modal>
</template>
