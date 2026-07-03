<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface AccountEdit {
    id: number;
    name: string;
    type: string;
    balance: number | string;
}

const props = defineProps<{ account?: AccountEdit | null }>();
const emit = defineEmits<{ close: [] }>();

const { primary, primarySoft } = useGros();

const editing = computed(() => !!props.account);
const types = ['Bežný účet', 'Sporiaci účet', 'Karta', 'Peňaženka', 'Investičný účet'];

const form = useForm({
    name: props.account?.name ?? '',
    type: props.account?.type ?? 'Bežný účet',
    balance: props.account ? String(props.account.balance).replace('.', ',') : '',
});

function submit() {
    form
        .transform((d) => ({ ...d, balance: parseFloat(String(d.balance).replace(/\s/g, '').replace(',', '.')) || 0 }))
        .submit(editing.value ? 'put' : 'post', editing.value ? `/accounts/${props.account!.id}` : '/accounts', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}

function destroy() {
    if (props.account) form.delete(`/accounts/${props.account.id}`, { onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="editing ? 'Upraviť účet' : 'Nový účet'" @close="emit('close')">
        <div style="margin-bottom: 16px">
            <label class="gros-label">Názov účtu</label>
            <input v-model="form.name" type="text" placeholder="napr. Bežný účet" class="gros-input" />
        </div>
        <div style="margin-bottom: 16px">
            <label class="gros-label">Typ</label>
            <select v-model="form.type" class="gros-select">
                <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
            </select>
        </div>
        <label class="gros-label">Zostatok</label>
        <div class="gros-amount-wrap" style="margin-bottom: 24px">
            <input v-model="form.balance" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
            <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
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
                {{ editing ? 'Uložiť zmeny' : 'Pridať účet' }}
            </button>
        </div>
    </Modal>
</template>
