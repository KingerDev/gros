<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface BudgetEdit {
    id: number;
    category_id: number;
    limit_amount: number | string;
    period: string;
}

const props = defineProps<{ budget?: BudgetEdit | null }>();
const emit = defineEmits<{ close: [] }>();

const { categoryTree, primary, primarySoft } = useGros();
const editing = computed(() => !!props.budget);

// Skupiny výdavkov s ich listami (na optgroupy)
const expenseGroups = computed(() => categoryTree.value.filter((g) => g.type === 'expense'));
const firstLeaf = computed(() => {
    for (const g of expenseGroups.value) {
        if (g.children.length) return g.children[0].id;
        return g.id;
    }
    return null;
});

const form = useForm<{ category_id: number | null; limit_amount: string; period: string }>({
    category_id: props.budget?.category_id ?? firstLeaf.value,
    limit_amount: props.budget ? String(props.budget.limit_amount).replace('.', ',') : '',
    period: props.budget?.period ?? 'month',
});

function segStyle(v: string) {
    const active = form.period === v;
    return { flex: '1', padding: '11px', borderRadius: '10px', fontSize: '14px', fontWeight: 700, color: active ? '#20212e' : '#8a8c9a', background: active ? '#fff' : 'transparent', boxShadow: active ? '0 2px 8px rgba(60,55,40,.08)' : 'none' };
}

function submit() {
    form
        .transform((d) => ({ ...d, limit_amount: parseFloat(String(d.limit_amount).replace(/\s/g, '').replace(',', '.')) || 0 }))
        .submit(editing.value ? 'put' : 'post', editing.value ? `/budgets/${props.budget!.id}` : '/budgets', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
}

function destroy() {
    if (props.budget) form.delete(`/budgets/${props.budget.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="editing ? 'Upraviť rozpočet' : 'Nový rozpočet'" @close="emit('close')">
        <div style="margin-bottom: 16px">
            <label class="gros-label">Kategória</label>
            <select v-model="form.category_id" class="gros-select">
                <template v-for="g in expenseGroups" :key="g.id">
                    <optgroup v-if="g.children.length" :label="(g.icon ? g.icon + ' ' : '') + g.name">
                        <option v-for="c in g.children" :key="c.id" :value="c.id">{{ (c.icon ? c.icon + ' ' : '') + c.name }}</option>
                    </optgroup>
                    <option v-else :value="g.id">{{ (g.icon ? g.icon + ' ' : '') + g.name }}</option>
                </template>
            </select>
        </div>
        <label class="gros-label">Limit</label>
        <div class="gros-amount-wrap" style="margin-bottom: 16px">
            <input v-model="form.limit_amount" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
            <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
        </div>
        <label class="gros-label">Obdobie</label>
        <div style="display: flex; gap: 8px; background: #f1efe8; padding: 4px; border-radius: 13px; margin-bottom: 24px">
            <button type="button" :style="segStyle('week')" @click="form.period = 'week'">Týždeň</button>
            <button type="button" :style="segStyle('month')" @click="form.period = 'month'">Mesiac</button>
            <button type="button" :style="segStyle('year')" @click="form.period = 'year'">Rok</button>
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
                {{ editing ? 'Uložiť zmeny' : 'Pridať rozpočet' }}
            </button>
        </div>
    </Modal>
</template>
