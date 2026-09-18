<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

interface Candidate {
    id: number;
    date: string;
    note: string | null;
    category_id: number | null;
    account: string | null;
    amount: number;
    excluded: boolean;
    event_id: number | null;
    /** Iná udalosť, v ktorej transakcia práve je. */
    event: { name: string; color: string; icon: string | null } | null;
    in_range: boolean;
}

const props = defineProps<{
    event: { id: number; name: string; starts_on: string; ends_on: string; color: string };
    /** Udalosť zatiaľ nemá transakcie — výdavky z jej dní sa rovno predvyberú. */
    preselect: boolean;
    /** Počiatočný rozsah — pokryje aj už priradené položky mimo dní udalosti (letenka vopred). */
    from?: string;
    to?: string;
}>();
const emit = defineEmits<{ close: [] }>();

const { eur, primary, primarySoft, catName, catColor, catGlyph, hexToRgba, formatDate } = useGros();

const from = ref(props.from ?? props.event.starts_on);
const to = ref(props.to ?? props.event.ends_on);
const rows = ref<Candidate[]>([]);
const loading = ref(false);
const saving = ref(false);
const query = ref('');

/** Čo má byť po uložení v udalosti. */
const checked = ref(new Set<number>());
/** Riadky, ktoré už prešli počiatočným výberom — pri zmene rozsahu sa nevyberajú znova. */
const seen = new Set<number>();
let firstLoad = true;

async function load() {
    loading.value = true;
    try {
        const q = new URLSearchParams({ from: from.value, to: to.value });
        const r = await fetch(`/events/${props.event.id}/candidates?${q}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        rows.value = (await r.json()).transactions ?? [];

        const next = new Set(checked.value);
        for (const t of rows.value) {
            if (seen.has(t.id)) continue;
            seen.add(t.id);
            const mine = t.event_id === props.event.id;
            const suggested = firstLoad && props.preselect && t.in_range && t.event_id === null && !t.excluded;
            if (mine || suggested) next.add(t.id);
        }
        checked.value = next;
        firstLoad = false;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

/** Rýchle rozšírenie rozsahu — letenky a ubytovanie sa platia dopredu. */
function widenBefore(days: number) {
    // v UTC, aby posun nepreskočil deň cez časové pásmo
    const d = new Date(from.value + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() - days);
    from.value = d.toISOString().slice(0, 10);
    load();
}

function normalize(s: string): string {
    return s
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

const visible = computed(() => {
    const q = normalize(query.value.trim());
    if (!q) return rows.value;
    return rows.value.filter((t) => normalize(`${t.note ?? ''} ${catName(t.category_id)} ${t.account ?? ''} ${t.amount}`).includes(q));
});

function toggle(id: number) {
    const next = new Set(checked.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    checked.value = next;
}

function setAll(on: boolean) {
    const next = new Set(checked.value);
    for (const t of visible.value) {
        if (on) next.add(t.id);
        else next.delete(t.id);
    }
    checked.value = next;
}

const selectedSum = computed(() => rows.value.filter((t) => checked.value.has(t.id) && !t.excluded).reduce((s, t) => s + Number(t.amount), 0));
const selectedCount = computed(() => rows.value.filter((t) => checked.value.has(t.id)).length);

const changes = computed(() => {
    const add: number[] = [];
    const remove: number[] = [];
    for (const t of rows.value) {
        const mine = t.event_id === props.event.id;
        const on = checked.value.has(t.id);
        if (on && !mine) add.push(t.id);
        if (!on && mine) remove.push(t.id);
    }
    return { add, remove };
});
const dirty = computed(() => changes.value.add.length + changes.value.remove.length > 0);

function save() {
    saving.value = true;
    router.put(`/events/${props.event.id}/transactions`, changes.value, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <Modal title="Transakcie udalosti" @close="emit('close')">
        <div style="font-size: 12.5px; color: #8a8c9a; font-weight: 600; line-height: 1.55; margin-bottom: 14px">
            Zaškrtnuté výdavky budú patriť do „{{ event.name }}". Nájom či predplatné, ktoré s ňou nesúvisia, odškrtni.
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 8px">
            <div style="flex: 1; min-width: 120px">
                <label class="gros-label">Od</label>
                <input v-model="from" type="date" class="gros-input" @change="load" />
            </div>
            <div style="flex: 1; min-width: 120px">
                <label class="gros-label">Do</label>
                <input v-model="to" type="date" :min="from" class="gros-input" @change="load" />
            </div>
        </div>
        <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px">
            <span style="font-size: 11.5px; font-weight: 700; color: #9a9cab; align-self: center">Platené vopred?</span>
            <button
                v-for="d in [30, 90]"
                :key="d"
                type="button"
                style="font-size: 11.5px; font-weight: 700; padding: 6px 10px; border-radius: 9px; background: #f1efe8; color: #61637a"
                @click="widenBefore(d)"
            >
                + {{ d }} dní pred
            </button>
        </div>

        <input v-model="query" type="search" placeholder="Hľadať…" class="gros-input" style="margin-bottom: 10px" />

        <div
            style="
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 12px;
                font-weight: 700;
                color: #8a8c9a;
                margin-bottom: 8px;
            "
        >
            <span>{{ loading ? 'Načítavam…' : `${rows.length} výdavkov v rozsahu` }}</span>
            <span style="display: flex; gap: 12px">
                <button type="button" style="text-decoration: underline" @click="setAll(true)">Všetky</button>
                <button type="button" style="text-decoration: underline" @click="setAll(false)">Žiadne</button>
            </span>
        </div>

        <div style="max-height: 46vh; overflow-y: auto; border-radius: 14px; background: #faf9f5; padding: 4px; margin-bottom: 16px">
            <button
                v-for="t in visible"
                :key="t.id"
                type="button"
                style="display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; padding: 9px 10px; border-radius: 11px"
                :style="{ background: checked.has(t.id) ? hexToRgba(event.color, 0.1) : 'transparent', opacity: t.excluded ? 0.6 : 1 }"
                @click="toggle(t.id)"
            >
                <span
                    style="
                        width: 20px;
                        height: 20px;
                        border-radius: 6px;
                        flex-shrink: 0;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        border: 1.5px solid #d7d4c8;
                    "
                    :style="{ background: checked.has(t.id) ? event.color : '#fff', borderColor: checked.has(t.id) ? event.color : '#d7d4c8' }"
                >
                    <svg
                        v-if="checked.has(t.id)"
                        width="12"
                        height="12"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#fff"
                        stroke-width="3.4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M4 12l5 5L20 6" />
                    </svg>
                </span>
                <span
                    style="
                        width: 30px;
                        height: 30px;
                        border-radius: 9px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 13px;
                        font-weight: 800;
                        flex-shrink: 0;
                    "
                    :style="{ background: hexToRgba(catColor(t.category_id), 0.14), color: catColor(t.category_id) }"
                    >{{ catGlyph(t.category_id) }}</span
                >
                <span style="flex: 1; min-width: 0">
                    <span style="display: block; font-size: 13px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                        {{ t.note || catName(t.category_id) }}
                    </span>
                    <span style="display: block; font-size: 11px; color: #9a9cab; font-weight: 600">
                        {{ formatDate(t.date) }} · {{ catName(t.category_id) }}<template v-if="!t.in_range"> · mimo dní udalosti</template>
                    </span>
                    <span
                        v-if="t.event"
                        style="display: inline-block; font-size: 10.5px; font-weight: 800; padding: 2px 6px; border-radius: 6px; margin-top: 3px"
                        :style="{ background: hexToRgba(t.event.color, 0.14), color: t.event.color }"
                        >{{ t.event.icon }} {{ t.event.name }} — presunie sa</span
                    >
                </span>
                <span style="font-size: 13.5px; font-weight: 800; white-space: nowrap">{{ eur(Number(t.amount)) }}</span>
            </button>
            <div v-if="!loading && !visible.length" style="padding: 26px 10px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 13px">
                V tomto rozsahu nie sú žiadne výdavky.
            </div>
        </div>

        <button
            type="button"
            style="width: 100%; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
            :style="{ background: primary, boxShadow: `0 10px 22px ${primarySoft}`, opacity: saving || !dirty ? 0.6 : 1 }"
            :disabled="saving || !dirty"
            @click="save"
        >
            Uložiť · {{ selectedCount }} vybraných za {{ eur(selectedSum) }}
        </button>
    </Modal>
</template>
