<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface EventEdit {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    budget: number | null;
    color: string;
    icon: string | null;
    note: string | null;
}

const props = defineProps<{ event?: EventEdit | null }>();
const emit = defineEmits<{ close: [] }>();

const { ref: gref, primary, primarySoft, hexToRgba } = useGros();

const quickIcons = ['✈️', '🏖️', '⛰️', '🏙️', '🎿', '🚗', '⛺', '🚢', '💍', '🎉', '🏠', '📦', '🎓', '🎄', '🏥', '🎁'];

const editing = computed(() => !!props.event);
const today = new Date().toISOString().slice(0, 10);

const form = useForm<{ name: string; starts_on: string; ends_on: string; budget: string; color: string; icon: string | null; note: string }>({
    name: props.event?.name ?? '',
    starts_on: props.event?.starts_on ?? today,
    ends_on: props.event?.ends_on ?? today,
    budget: props.event?.budget ? String(props.event.budget).replace('.', ',') : '',
    color: props.event?.color ?? '#22b8cf',
    icon: props.event?.icon ?? '✈️',
    note: props.event?.note ?? '',
});

/** Koniec pred začiatkom nedáva zmysel — posunie sa spolu so začiatkom. */
function onStartChange() {
    if (form.ends_on < form.starts_on) form.ends_on = form.starts_on;
}

function submit() {
    form.transform((d) => ({
        ...d,
        budget: d.budget.trim() ? parseFloat(d.budget.replace(/\s/g, '').replace(',', '.')) || null : null,
        note: d.note.trim() || null,
    })).submit(editing.value ? 'put' : 'post', editing.value ? `/events/${props.event!.id}` : '/events', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

function destroy() {
    if (props.event) form.delete(`/events/${props.event.id}`, { onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="editing ? 'Upraviť udalosť' : 'Nová udalosť'" @close="emit('close')">
        <div style="margin-bottom: 16px">
            <label class="gros-label">Názov</label>
            <input v-model="form.name" type="text" maxlength="191" placeholder="napr. Dovolenka Dublin 2026" class="gros-input" />
            <div v-if="form.errors.name" style="color: #e8544e; font-size: 12px; font-weight: 600; margin-top: 6px">Napíš názov.</div>
        </div>

        <div style="display: flex; gap: 12px; margin-bottom: 6px; flex-wrap: wrap">
            <div style="flex: 1; min-width: 130px">
                <label class="gros-label">Od</label>
                <input v-model="form.starts_on" type="date" class="gros-input" @change="onStartChange" />
            </div>
            <div style="flex: 1; min-width: 130px">
                <label class="gros-label">Do</label>
                <input v-model="form.ends_on" type="date" :min="form.starts_on" class="gros-input" />
            </div>
        </div>
        <div style="font-size: 11.5px; color: #b0b2bd; font-weight: 600; margin-bottom: 16px">
            Podľa dátumov ti ponúkneme transakcie na priradenie. Letenku kúpenú vopred pridáš tiež — stačí rozšíriť rozsah pri výbere.
        </div>

        <label class="gros-label">Rozpočet (voliteľné)</label>
        <div class="gros-amount-wrap" style="margin-bottom: 16px">
            <input v-model="form.budget" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
            <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
        </div>

        <label class="gros-label">Farba</label>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px">
            <button
                v-for="c in gref.palette"
                :key="c"
                type="button"
                style="width: 34px; height: 34px; border-radius: 10px; cursor: pointer; border: 3px solid transparent"
                :style="{ background: c, borderColor: form.color === c ? '#20212e' : 'transparent' }"
                @click="form.color = c"
            ></button>
        </div>

        <label class="gros-label">Ikona</label>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px">
            <div
                style="
                    width: 46px;
                    height: 46px;
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 22px;
                    flex-shrink: 0;
                "
                :style="{ background: hexToRgba(form.color, 0.14) }"
            >
                {{ form.icon || (form.name[0] ?? '?') }}
            </div>
            <input v-model="form.icon" type="text" maxlength="4" placeholder="napr. ✈️" class="gros-input" style="flex: 1" />
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px">
            <button
                v-for="e in quickIcons"
                :key="e"
                type="button"
                style="width: 34px; height: 34px; border-radius: 9px; background: #f5f4ef; font-size: 17px"
                :style="{ outline: form.icon === e ? '2px solid ' + form.color : 'none' }"
                @click="form.icon = e"
            >
                {{ e }}
            </button>
        </div>

        <div style="margin-bottom: 24px">
            <label class="gros-label">Poznámka (voliteľné)</label>
            <input v-model="form.note" type="text" maxlength="191" placeholder="napr. s Janou, 4 noci v Temple Bar" class="gros-input" />
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
                title="Zmazať udalosť (transakcie ostanú)"
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
                {{ editing ? 'Uložiť zmeny' : 'Vytvoriť udalosť' }}
            </button>
        </div>
        <div v-if="editing" style="font-size: 11.5px; color: #b0b2bd; font-weight: 600; margin-top: 10px; text-align: center">
            Zmazaním udalosti sa transakcie nezmažú — vrátia sa medzi bežné výdavky.
        </div>
    </Modal>
</template>
