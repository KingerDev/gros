<script setup lang="ts">
import CategorySelect from '@/components/gros/CategorySelect.vue';
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

interface AccountOption {
    id: number;
    name: string;
}
interface TxnEdit {
    id: number;
    type: string;
    category_id: number | null;
    account_id: number;
    to_account_id: number | null;
    amount: number | string;
    date: string;
    note: string | null;
}

const props = defineProps<{
    accounts: AccountOption[];
    transaction?: TxnEdit | null;
    presetAccountId?: number | null;
}>();
const emit = defineEmits<{ close: [] }>();

const { primary, primarySoft, categoryById } = useGros();

const editing = computed(() => !!props.transaction);

const defaultAccount = props.transaction?.account_id ?? props.presetAccountId ?? props.accounts[0]?.id ?? null;
const otherAccount = props.accounts.find((a) => a.id !== defaultAccount)?.id ?? null;

const form = useForm<{
    type: 'income' | 'expense' | 'transfer';
    category_id: number | null;
    account_id: number | null;
    to_account_id: number | null;
    amount: string;
    date: string;
    note: string;
}>({
    type: (props.transaction?.type as 'income' | 'expense' | 'transfer') ?? 'expense',
    category_id: props.transaction?.category_id ?? null,
    account_id: defaultAccount,
    to_account_id: props.transaction?.to_account_id ?? otherAccount,
    amount: props.transaction ? String(props.transaction.amount).replace('.', ',') : '',
    date: props.transaction?.date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    note: props.transaction?.note ?? '',
});

const isTransfer = computed(() => form.type === 'transfer');

watch(
    () => form.type,
    (t) => {
        if (t === 'transfer') {
            if (!form.to_account_id || form.to_account_id === form.account_id) {
                form.to_account_id = props.accounts.find((a) => a.id !== form.account_id)?.id ?? null;
            }
        } else {
            const c = categoryById(form.category_id);
            if (c && c.type !== t) form.category_id = null;
        }
    },
);

// Ak sa zdrojový účet zhoduje s cieľovým pri prevode, prehoď cieľ
watch(
    () => form.account_id,
    () => {
        if (isTransfer.value && form.to_account_id === form.account_id) {
            form.to_account_id = props.accounts.find((a) => a.id !== form.account_id)?.id ?? null;
        }
    },
);

const segs = [
    { key: 'expense', label: 'Výdavok' },
    { key: 'income', label: 'Príjem' },
    { key: 'transfer', label: 'Prevod' },
] as const;

function segStyle(t: string) {
    const active = form.type === t;
    return {
        flex: '1',
        padding: '11px 6px',
        borderRadius: '10px',
        fontSize: '14px',
        fontWeight: 700,
        transition: 'all .15s',
        color: active ? '#20212e' : '#8a8c9a',
        background: active ? '#fff' : 'transparent',
        boxShadow: active ? '0 2px 8px rgba(60,55,40,.08)' : 'none',
    };
}

const title = computed(() => {
    const noun = isTransfer.value ? 'prevod' : 'transakciu';
    return editing.value ? `Upraviť ${noun}` : isTransfer.value ? 'Nový prevod' : 'Nová transakcia';
});
const submitLabel = computed(() => (editing.value ? 'Uložiť zmeny' : isTransfer.value ? 'Pridať prevod' : 'Pridať transakciu'));

function submit() {
    form
        .transform((data) => ({ ...data, amount: parseFloat(String(data.amount).replace(/\s/g, '').replace(',', '.')) || 0 }))
        .submit(editing.value ? 'put' : 'post', editing.value ? `/transactions/${props.transaction!.id}` : '/transactions', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}

function destroy() {
    if (!props.transaction) return;
    form.delete(`/transactions/${props.transaction.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="title" @close="emit('close')">
        <div style="display: flex; gap: 6px; background: #f1efe8; padding: 4px; border-radius: 13px; margin-bottom: 18px">
            <button v-for="s in segs" :key="s.key" type="button" :style="segStyle(s.key)" @click="form.type = s.key">{{ s.label }}</button>
        </div>

        <label class="gros-label">Suma</label>
        <div class="gros-amount-wrap" style="margin-bottom: 18px">
            <input v-model="form.amount" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
            <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
        </div>

        <!-- Kategória (len príjem/výdavok) -->
        <template v-if="!isTransfer">
            <label class="gros-label">Kategória</label>
            <div style="margin-bottom: 18px">
                <CategorySelect v-model="form.category_id" :type="form.type as 'income' | 'expense'" />
                <div v-if="form.errors.category_id" style="color: #e8544e; font-size: 12px; font-weight: 600; margin-top: 6px">Vyber kategóriu.</div>
            </div>

            <div style="display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap">
                <div style="flex: 1; min-width: 130px">
                    <label class="gros-label">Účet</label>
                    <select v-model="form.account_id" class="gros-select">
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>
                <div style="flex: 1; min-width: 130px">
                    <label class="gros-label">Dátum</label>
                    <input v-model="form.date" type="date" class="gros-input" />
                </div>
            </div>
        </template>

        <!-- Prevod: z účtu → na účet -->
        <template v-else>
            <div style="display: flex; align-items: flex-end; gap: 10px; margin-bottom: 18px; flex-wrap: wrap">
                <div style="flex: 1; min-width: 130px">
                    <label class="gros-label">Z účtu</label>
                    <select v-model="form.account_id" class="gros-select">
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>
                <div style="flex-shrink: 0; padding-bottom: 12px; color: #9a9cab">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </div>
                <div style="flex: 1; min-width: 130px">
                    <label class="gros-label">Na účet</label>
                    <select v-model="form.to_account_id" class="gros-select">
                        <option v-for="a in accounts.filter((x) => x.id !== form.account_id)" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>
            </div>
            <div v-if="form.errors.to_account_id" style="color: #e8544e; font-size: 12px; font-weight: 600; margin-bottom: 12px; margin-top: -8px">Vyber cieľový účet (iný ako zdrojový).</div>

            <div style="margin-bottom: 18px">
                <label class="gros-label">Dátum</label>
                <input v-model="form.date" type="date" class="gros-input" />
            </div>

            <div style="margin-bottom: 18px; padding: 12px 14px; background: #eef6ff; border-radius: 12px; font-size: 12.5px; font-weight: 600; color: #2a6ebd">
                Suma sa automaticky odpočíta zo zdrojového a pripočíta na cieľový účet.
            </div>
        </template>

        <div style="margin-bottom: 24px">
            <label class="gros-label">Poznámka</label>
            <input v-model="form.note" type="text" :placeholder="isTransfer ? 'napr. Presun do sporenia' : 'napr. Kaufland'" class="gros-input" />
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
                {{ submitLabel }}
            </button>
        </div>
    </Modal>
</template>
