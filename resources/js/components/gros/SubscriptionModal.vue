<script setup lang="ts">
import CategorySelect from '@/components/gros/CategorySelect.vue';
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface SubEdit {
    id: number;
    name: string;
    amount: number | string;
    cycle: string;
    next_payment: string;
    account_id: number | null;
    category_id: number | null;
}

interface AccountOption {
    id: number;
    name: string;
}

const props = defineProps<{ subscription?: SubEdit | null; accounts: AccountOption[] }>();
const emit = defineEmits<{ close: [] }>();

const { primary, primarySoft } = useGros();
const editing = computed(() => !!props.subscription);
const cycles = [
    { value: 'monthly', label: 'Mesačne' },
    { value: 'yearly', label: 'Ročne' },
];

const form = useForm<{
    name: string;
    amount: string;
    cycle: string;
    next_payment: string;
    account_id: number | null;
    category_id: number | null;
}>({
    name: props.subscription?.name ?? '',
    amount: props.subscription ? String(props.subscription.amount).replace('.', ',') : '',
    cycle: props.subscription?.cycle ?? 'monthly',
    next_payment: props.subscription?.next_payment?.slice(0, 10) ?? new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
    account_id: props.subscription?.account_id ?? props.accounts[0]?.id ?? null,
    category_id: props.subscription?.category_id ?? null,
});

function submit() {
    form
        .transform((d) => ({ ...d, amount: parseFloat(String(d.amount).replace(/\s/g, '').replace(',', '.')) || 0 }))
        .submit(editing.value ? 'put' : 'post', editing.value ? `/subscriptions/${props.subscription!.id}` : '/subscriptions', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}
</script>

<template>
    <Modal :title="editing ? 'Upraviť predplatné' : 'Nové predplatné'" @close="emit('close')">
        <div style="margin-bottom: 16px">
            <label class="gros-label">Názov</label>
            <input v-model="form.name" type="text" placeholder="napr. Disney+" class="gros-input" />
        </div>
        <label class="gros-label">Suma</label>
        <div class="gros-amount-wrap" style="margin-bottom: 16px">
            <input v-model="form.amount" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
            <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
        </div>
        <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap">
            <div style="flex: 1; min-width: 120px">
                <label class="gros-label">Cyklus</label>
                <select v-model="form.cycle" class="gros-select">
                    <option v-for="c in cycles" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
            </div>
            <div style="flex: 1.2; min-width: 140px">
                <label class="gros-label">Ďalšia platba</label>
                <input v-model="form.next_payment" type="date" class="gros-input" />
            </div>
        </div>
        <div style="margin-bottom: 16px">
            <label class="gros-label">Účet (automatická platba)</label>
            <select v-model="form.account_id" class="gros-select">
                <option :value="null">Bez automatickej platby</option>
                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
        </div>
        <div v-if="form.account_id" style="margin-bottom: 24px">
            <label class="gros-label">Kategória (voliteľné)</label>
            <CategorySelect v-model="form.category_id" type="expense" />
        </div>
        <div v-else style="margin-bottom: 24px"></div>
        <button
            type="button"
            style="width: 100%; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
            :style="{ background: primary, boxShadow: `0 10px 22px ${primarySoft}`, opacity: form.processing ? 0.7 : 1 }"
            :disabled="form.processing"
            @click="submit"
        >
            {{ editing ? 'Uložiť zmeny' : 'Pridať predplatné' }}
        </button>
    </Modal>
</template>
