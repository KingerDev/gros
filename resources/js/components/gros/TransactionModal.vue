<script setup lang="ts">
import CategorySelect from '@/components/gros/CategorySelect.vue';
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

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
    excluded_from_analytics?: boolean;
    exclusion_reason?: string | null;
    refund_for_id?: number | null;
}

const props = defineProps<{
    accounts: AccountOption[];
    transaction?: TxnEdit | null;
    presetAccountId?: number | null;
}>();
const emit = defineEmits<{ close: [] }>();

const { primary, primarySoft, categoryById, catName, catColor, catGlyph, hexToRgba } = useGros();

const editing = computed(() => !!props.transaction);
// Spárované vrátenie: typ ani kategória sa meniť nedajú — patrí k svojmu nákupu
const isRefund = computed(() => !!props.transaction?.refund_for_id);

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
    excluded_from_analytics: boolean;
    exclusion_reason: string;
}>({
    type: (props.transaction?.type as 'income' | 'expense' | 'transfer') ?? 'expense',
    category_id: props.transaction?.category_id ?? null,
    account_id: defaultAccount,
    to_account_id: props.transaction?.to_account_id ?? otherAccount,
    amount: props.transaction ? String(props.transaction.amount).replace('.', ',') : '',
    date: props.transaction?.date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    note: props.transaction?.note ?? '',
    excluded_from_analytics: props.transaction?.excluded_from_analytics ?? false,
    exclusion_reason: props.transaction?.exclusion_reason ?? '',
});

const reasonInput = ref<HTMLInputElement | null>(null);

/**
 * Návrh kategórie podľa poznámky. Ozve sa len vtedy, keď kategória ešte nie je
 * vybraná — do už zadanej voľby nikdy nesiahne.
 */
const suggestedCat = ref<number | null>(null);
let suggestTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => [form.note, form.type] as const,
    ([note, type]) => {
        clearTimeout(suggestTimer);
        suggestedCat.value = null;
        if (type === 'transfer' || isRefund.value || note.trim().length < 3) return;

        suggestTimer = setTimeout(async () => {
            const q = new URLSearchParams({ note: note.trim(), type });
            try {
                const r = await fetch(`/transactions/suggest-category?${q}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const id = (await r.json()).category_id as number | null;
                // Kým odpoveď letela, používateľ mohol písať ďalej alebo si kategóriu vybrať
                if (id && note === form.note && !form.category_id) suggestedCat.value = id;
            } catch {
                suggestedCat.value = null;
            }
        }, 400);
    },
);

watch(
    () => form.category_id,
    (id) => {
        if (id) suggestedCat.value = null;
    },
);

function applySuggestion() {
    form.category_id = suggestedCat.value;
    suggestedCat.value = null;
}

// Zaškrtnutie hneď pýta dôvod — bez neho sa transakcia vylúčiť nedá
function toggleExcluded() {
    form.excluded_from_analytics = !form.excluded_from_analytics;
    if (form.excluded_from_analytics) {
        nextTick(() => reasonInput.value?.focus());
    } else {
        form.exclusion_reason = '';
    }
}

const isTransfer = computed(() => form.type === 'transfer');

// Zúženie typu tu, nie v šablóne — zvislítko v type union tam eslint číta
// ako zastaraný Vue filter.
const categoryType = computed(() => (form.type === 'income' ? 'income' : 'expense'));

watch(
    () => form.type,
    (t) => {
        if (t === 'transfer') {
            if (!form.to_account_id || form.to_account_id === form.account_id) {
                form.to_account_id = props.accounts.find((a) => a.id !== form.account_id)?.id ?? null;
            }
            // prevody do analýzy nevstupujú tak či tak
            form.excluded_from_analytics = false;
            form.exclusion_reason = '';
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
    if (isRefund.value) return 'Upraviť vrátenie';
    const noun = isTransfer.value ? 'prevod' : 'transakciu';
    return editing.value ? `Upraviť ${noun}` : isTransfer.value ? 'Nový prevod' : 'Nová transakcia';
});
const submitLabel = computed(() => (editing.value ? 'Uložiť zmeny' : isTransfer.value ? 'Pridať prevod' : 'Pridať transakciu'));

function submit(andAnother = false) {
    form.transform((data) => ({ ...data, amount: parseFloat(String(data.amount).replace(/\s/g, '').replace(',', '.')) || 0 })).submit(
        editing.value ? 'put' : 'post',
        editing.value ? `/transactions/${props.transaction!.id}` : '/transactions',
        {
            preserveScroll: true,
            preserveState: andAnother ? true : undefined,
            onSuccess: () => {
                if (andAnother && !editing.value) {
                    // ponechá typ, kategóriu, účet aj dátum — vyčistí len sumu, poznámku a vylúčenie
                    form.amount = '';
                    form.note = '';
                    form.excluded_from_analytics = false;
                    form.exclusion_reason = '';
                } else {
                    emit('close');
                }
            },
        },
    );
}

function destroy() {
    if (!props.transaction) return;
    form.delete(`/transactions/${props.transaction.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="title" @close="emit('close')">
        <div v-if="!isRefund" style="display: flex; gap: 6px; background: #f1efe8; padding: 4px; border-radius: 13px; margin-bottom: 18px">
            <button v-for="s in segs" :key="s.key" type="button" :style="segStyle(s.key)" @click="form.type = s.key">{{ s.label }}</button>
        </div>

        <div
            v-else
            style="
                margin-bottom: 18px;
                padding: 12px 14px;
                background: #eefaf1;
                border-radius: 12px;
                font-size: 12.5px;
                font-weight: 600;
                color: #2ba35a;
                line-height: 1.5;
            "
        >
            Vrátenie peňazí spárované s nákupom. Znižuje ten nákup v analýzach — ako príjem sa neráta. Rozpárovať sa dá v okne vrátení pri nákupe.
        </div>

        <label class="gros-label">Suma</label>
        <div class="gros-amount-wrap" style="margin-bottom: 18px">
            <input v-model="form.amount" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
            <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
        </div>
        <div v-if="form.errors.amount" style="color: #e8544e; font-size: 12px; font-weight: 600; margin: -12px 0 14px">{{ form.errors.amount }}</div>

        <!-- Vrátenie: len účet a dátum, kategóriu preberá z nákupu -->
        <template v-if="isRefund">
            <div style="display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap">
                <div style="flex: 1; min-width: 130px">
                    <label class="gros-label">Na účet</label>
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

        <!-- Kategória (len príjem/výdavok) -->
        <template v-else-if="!isTransfer">
            <label class="gros-label">Kategória</label>
            <div style="margin-bottom: 18px">
                <CategorySelect v-model="form.category_id" :type="categoryType" />
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
                    <svg
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M5 12h14M13 6l6 6-6 6" />
                    </svg>
                </div>
                <div style="flex: 1; min-width: 130px">
                    <label class="gros-label">Na účet</label>
                    <select v-model="form.to_account_id" class="gros-select">
                        <option v-for="a in accounts.filter((x) => x.id !== form.account_id)" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>
            </div>
            <div v-if="form.errors.to_account_id" style="color: #e8544e; font-size: 12px; font-weight: 600; margin-bottom: 12px; margin-top: -8px">
                Vyber cieľový účet (iný ako zdrojový).
            </div>

            <div style="margin-bottom: 18px">
                <label class="gros-label">Dátum</label>
                <input v-model="form.date" type="date" class="gros-input" />
            </div>

            <div
                style="
                    margin-bottom: 18px;
                    padding: 12px 14px;
                    background: #eef6ff;
                    border-radius: 12px;
                    font-size: 12.5px;
                    font-weight: 600;
                    color: #2a6ebd;
                "
            >
                Suma sa automaticky odpočíta zo zdrojového a pripočíta na cieľový účet.
            </div>
        </template>

        <div style="margin-bottom: 18px">
            <label class="gros-label">Poznámka</label>
            <input v-model="form.note" type="text" :placeholder="isTransfer ? 'napr. Presun do sporenia' : 'napr. Kaufland'" class="gros-input" />
            <!-- Návrh kategórie podľa toho, kam si rovnakú poznámku zaraďoval doteraz -->
            <button
                v-if="suggestedCat"
                type="button"
                style="
                    display: flex;
                    align-items: center;
                    gap: 7px;
                    margin-top: 8px;
                    padding: 7px 11px;
                    border-radius: 11px;
                    font-size: 12.5px;
                    font-weight: 700;
                    background: #f5f4ef;
                    color: #6a6c7a;
                "
                @click="applySuggestion"
            >
                <span
                    style="
                        width: 20px;
                        height: 20px;
                        border-radius: 7px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 11px;
                        flex-shrink: 0;
                    "
                    :style="{ background: hexToRgba(catColor(suggestedCat), 0.18), color: catColor(suggestedCat) }"
                    >{{ catGlyph(suggestedCat) }}</span
                >
                Zaradiť do „{{ catName(suggestedCat) }}"
            </button>
        </div>

        <!-- Vylúčenie z analýzy (len príjem/výdavok — prevody a vrátenia sa do analýz nerátajú) -->
        <div
            v-if="!isTransfer && !isRefund"
            style="margin-bottom: 24px; padding: 14px; border-radius: 14px; background: #faf9f5; border: 1.5px solid #eceae2"
        >
            <button type="button" style="display: flex; align-items: flex-start; gap: 11px; width: 100%; text-align: left" @click="toggleExcluded">
                <span
                    style="
                        width: 22px;
                        height: 22px;
                        border-radius: 7px;
                        flex-shrink: 0;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        border: 1.5px solid #d7d4c8;
                        margin-top: 1px;
                    "
                    :style="{
                        background: form.excluded_from_analytics ? primary : '#fff',
                        borderColor: form.excluded_from_analytics ? primary : '#d7d4c8',
                    }"
                >
                    <svg
                        v-if="form.excluded_from_analytics"
                        width="13"
                        height="13"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#fff"
                        stroke-width="3.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M4 12l5 5L20 6" />
                    </svg>
                </span>
                <span style="flex: 1; min-width: 0">
                    <span style="display: block; font-size: 14px; font-weight: 700; color: #20212e">Vylúčiť z analýzy</span>
                    <span style="display: block; font-size: 12px; font-weight: 500; color: #9a9cab; margin-top: 2px; line-height: 1.45">
                        Ostane v zozname aj v zostatku účtu, ale nebude v analýzach, prehľade ani v rozpočte kategórie.
                    </span>
                </span>
            </button>

            <div v-if="form.excluded_from_analytics" style="margin-top: 13px">
                <label class="gros-label">Dôvod vylúčenia</label>
                <input
                    ref="reasonInput"
                    v-model="form.exclusion_reason"
                    type="text"
                    maxlength="191"
                    placeholder="napr. Preplatené firmou"
                    class="gros-input"
                />
                <div v-if="form.errors.exclusion_reason" style="color: #e8544e; font-size: 12px; font-weight: 600; margin-top: 6px">
                    Napíš dôvod vylúčenia.
                </div>
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
                v-if="!editing"
                type="button"
                style="flex: 1; font-weight: 800; font-size: 14px; padding: 15px 10px; border-radius: 14px; background: #f1efe8; color: #61637a"
                :style="{ opacity: form.processing ? 0.7 : 1 }"
                :disabled="form.processing"
                @click="submit(true)"
            >
                + Pridať a ďalšiu
            </button>
            <button
                type="button"
                style="flex: 1; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
                :style="{ background: primary, boxShadow: `0 10px 22px ${primarySoft}`, opacity: form.processing ? 0.7 : 1 }"
                :disabled="form.processing"
                @click="submit(false)"
            >
                {{ editing ? submitLabel : 'Pridať' }}
            </button>
        </div>
    </Modal>
</template>
