<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Category } from '@/lib/gros';

const props = defineProps<{
    mode: 'group' | 'child' | 'edit';
    parent?: Category | null;
    category?: Category | null;
}>();
const emit = defineEmits<{ close: [] }>();

const { ref: gref, primary, primarySoft, hexToRgba } = useGros();

const quickIcons = ['🛒', '🍔', '☕', '🏠', '⚡', '🚗', '⛽', '🛍️', '💻', '🎉', '🎮', '💊', '🏋️', '🔁', '📺', '💳', '🧾', '💰', '💵', '💼', '🎁', '✈️', '📦', '➕'];

const form = useForm<{ name: string; type: string; color: string; icon: string | null; parent_id: number | null }>({
    name: props.category?.name ?? '',
    type: props.category?.type ?? props.parent?.type ?? 'expense',
    color: props.category?.color ?? props.parent?.color ?? gref.value.palette[0],
    icon: props.category?.icon ?? null,
    parent_id: props.mode === 'child' ? (props.parent?.id ?? null) : null,
});

const title = computed(() => (props.mode === 'group' ? 'Nová skupina' : props.mode === 'child' ? `Podkategória do „${props.parent?.name}"` : 'Upraviť kategóriu'));

function submit() {
    if (props.mode === 'edit') {
        form.transform((d) => ({ name: d.name, color: d.color, icon: d.icon })).put(`/categories/${props.category!.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
    } else {
        form.post('/categories', { preserveScroll: true, onSuccess: () => emit('close') });
    }
}
</script>

<template>
    <Modal :title="title" @close="emit('close')">
        <div style="margin-bottom: 16px">
            <label class="gros-label">Názov</label>
            <input v-model="form.name" type="text" placeholder="napr. Potraviny" class="gros-input" />
        </div>

        <div v-if="mode === 'group'" style="margin-bottom: 16px">
            <label class="gros-label">Typ</label>
            <div style="display: flex; gap: 8px; background: #f1efe8; padding: 4px; border-radius: 13px">
                <button type="button" :style="{ flex: 1, padding: '11px', borderRadius: '10px', fontSize: '14px', fontWeight: 700, color: form.type === 'expense' ? '#20212e' : '#8a8c9a', background: form.type === 'expense' ? '#fff' : 'transparent' }" @click="form.type = 'expense'">Výdavok</button>
                <button type="button" :style="{ flex: 1, padding: '11px', borderRadius: '10px', fontSize: '14px', fontWeight: 700, color: form.type === 'income' ? '#20212e' : '#8a8c9a', background: form.type === 'income' ? '#fff' : 'transparent' }" @click="form.type = 'income'">Príjem</button>
            </div>
        </div>

        <label class="gros-label">Farba</label>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px">
            <button
                v-for="c in gref.palette"
                :key="c"
                type="button"
                style="width: 34px; height: 34px; border-radius: 10px; cursor: pointer; border: 3px solid transparent; position: relative"
                :style="{ background: c, borderColor: form.color === c ? '#20212e' : 'transparent' }"
                @click="form.color = c"
            ></button>
        </div>

        <label class="gros-label">Ikona (voliteľné)</label>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px">
            <div style="width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0" :style="{ background: hexToRgba(form.color, 0.14) }">{{ form.icon || (form.name[0] ?? '?') }}</div>
            <input v-model="form.icon" type="text" maxlength="4" placeholder="napr. 🛒" class="gros-input" style="flex: 1" />
            <button v-if="form.icon" type="button" style="font-size: 12px; font-weight: 700; color: #9a9cab; padding: 8px" @click="form.icon = null">Zrušiť</button>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 24px">
            <button v-for="e in quickIcons" :key="e" type="button" style="width: 34px; height: 34px; border-radius: 9px; background: #f5f4ef; font-size: 17px" :style="{ outline: form.icon === e ? '2px solid ' + form.color : 'none' }" @click="form.icon = e">{{ e }}</button>
        </div>

        <button
            type="button"
            style="width: 100%; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
            :style="{ background: primary, boxShadow: `0 10px 22px ${primarySoft}`, opacity: form.processing ? 0.7 : 1 }"
            :disabled="form.processing"
            @click="submit"
        >
            {{ mode === 'edit' ? 'Uložiť zmeny' : 'Pridať' }}
        </button>
    </Modal>
</template>
