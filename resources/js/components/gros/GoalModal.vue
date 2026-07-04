<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface GoalEdit {
    id: number;
    name: string;
    target_amount: number | string;
    saved_amount: number | string;
    color: string;
    deadline: string | null;
}

const props = defineProps<{ goal?: GoalEdit | null }>();
const emit = defineEmits<{ close: [] }>();

const { primary, primarySoft, ref: catalog } = useGros();
const editing = computed(() => !!props.goal);

const palette = computed(() => catalog.value?.palette ?? ['#4c8dff', '#9775fa', '#ff6b9d', '#22b8cf', '#f7931a', '#20c997']);

const form = useForm({
    name: props.goal?.name ?? '',
    target_amount: props.goal ? String(props.goal.target_amount).replace('.', ',') : '',
    saved_amount: props.goal ? String(props.goal.saved_amount).replace('.', ',') : '0',
    color: props.goal?.color ?? '#4c8dff',
    deadline: props.goal?.deadline?.slice(0, 10) ?? '',
});

function toNum(v: string): number {
    return parseFloat(String(v).replace(/\s/g, '').replace(',', '.')) || 0;
}

function submit() {
    form.transform((d) => ({
        ...d,
        target_amount: toNum(d.target_amount),
        saved_amount: toNum(d.saved_amount),
        deadline: d.deadline || null,
    })).submit(editing.value ? 'put' : 'post', editing.value ? `/goals/${props.goal!.id}` : '/goals', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

function destroy() {
    if (props.goal) form.delete(`/goals/${props.goal.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="editing ? 'Upraviť cieľ' : 'Nový sporiaci cieľ'" @close="emit('close')">
        <div style="margin-bottom: 16px">
            <label class="gros-label">Názov</label>
            <input v-model="form.name" type="text" placeholder="napr. Rezerva, Dovolenka" class="gros-input" />
        </div>
        <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap">
            <div style="flex: 1; min-width: 130px">
                <label class="gros-label">Cieľová suma</label>
                <div class="gros-amount-wrap">
                    <input v-model="form.target_amount" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
                    <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
                </div>
            </div>
            <div style="flex: 1; min-width: 130px">
                <label class="gros-label">Našetrené</label>
                <div class="gros-amount-wrap">
                    <input v-model="form.saved_amount" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
                    <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
                </div>
            </div>
        </div>
        <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap">
            <div style="flex: 1.2; min-width: 150px">
                <label class="gros-label">Farba</label>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; padding-top: 4px">
                    <button
                        v-for="c in palette"
                        :key="c"
                        type="button"
                        style="width: 30px; height: 30px; border-radius: 10px; transition: transform 0.12s"
                        :style="{
                            background: c,
                            transform: form.color === c ? 'scale(1.15)' : 'none',
                            boxShadow: form.color === c ? `0 0 0 2px #fff, 0 0 0 4px ${c}` : 'none',
                        }"
                        @click="form.color = c"
                    ></button>
                </div>
            </div>
            <div style="flex: 1; min-width: 140px">
                <label class="gros-label">Termín (voliteľné)</label>
                <input v-model="form.deadline" type="date" class="gros-input" />
            </div>
        </div>
        <div style="display: flex; gap: 10px">
            <button
                v-if="editing"
                type="button"
                style="
                    flex-shrink: 0;
                    background: #fdeaea;
                    color: #e8544e;
                    font-weight: 800;
                    font-size: 15px;
                    padding: 15px 18px;
                    border-radius: 14px;
                "
                @click="destroy"
            >
                <svg
                    width="17"
                    height="17"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" />
                </svg>
            </button>
            <button
                type="button"
                style="flex: 1; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
                :style="{ background: primary, boxShadow: `0 10px 22px ${primarySoft}`, opacity: form.processing ? 0.7 : 1 }"
                :disabled="form.processing"
                @click="submit"
            >
                {{ editing ? 'Uložiť zmeny' : 'Pridať cieľ' }}
            </button>
        </div>
    </Modal>
</template>
