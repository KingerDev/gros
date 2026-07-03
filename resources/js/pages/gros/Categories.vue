<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import CategoryModal from '@/components/gros/CategoryModal.vue';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { Category } from '@/lib/gros';

interface Group extends Category {
    children: Category[];
}

const props = defineProps<{ groups: Group[]; txnCounts: Record<number, number> }>();

const { hexToRgba } = useGros();

const expenseGroups = computed(() => props.groups.filter((g) => g.type === 'expense'));
const incomeGroups = computed(() => props.groups.filter((g) => g.type === 'income'));

const modal = ref<{ mode: 'group' | 'child' | 'edit'; parent?: Category | null; category?: Category | null } | null>(null);
const confirmId = ref<number | null>(null);

function del(c: Category) {
    router.delete(`/categories/${c.id}`, { preserveScroll: true, onSuccess: () => (confirmId.value = null) });
}
function glyph(c: Category) {
    return c.icon || (c.name[0] ?? '?');
}
</script>

<template>
    <Head title="Kategórie" />
    <GrosLayout title="Kategórie" subtitle="Spravuj svoje kategórie a podkategórie">
        <template #action>
            <AddButton label="Pridať skupinu" @click="modal = { mode: 'group' }" />
        </template>

        <div class="gros-rise">
            <template v-for="(section, si) in [{ label: 'Výdavky', groups: expenseGroups }, { label: 'Príjmy', groups: incomeGroups }]" :key="si">
                <div class="font-display" style="font-weight: 800; font-size: 18px; letter-spacing: -0.3px; margin: 8px 2px 12px" :style="{ marginTop: si === 0 ? '0' : '26px' }">{{ section.label }}</div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px">
                    <div v-for="g in section.groups" :key="g.id" style="background: #fff; border-radius: 20px; padding: 18px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                        <!-- group header -->
                        <div style="display: flex; align-items: center; gap: 11px">
                            <span style="width: 42px; height: 42px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 800; flex-shrink: 0" :style="{ background: hexToRgba(g.color, 0.16), color: g.color }">{{ glyph(g) }}</span>
                            <div style="flex: 1; min-width: 0">
                                <div style="font-size: 15.5px; font-weight: 800">{{ g.name }}</div>
                                <div style="font-size: 12px; color: #9a9cab; font-weight: 600">{{ g.children.length }} podkategórií</div>
                            </div>
                            <button type="button" style="width: 32px; height: 32px; border-radius: 9px; background: #f5f4ef; color: #9a9cab; display: flex; align-items: center; justify-content: center" title="Upraviť" @click="modal = { mode: 'edit', category: g }">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
                            </button>
                            <button type="button" style="width: 32px; height: 32px; border-radius: 9px; background: #f5f4ef; color: #c4c2ba; display: flex; align-items: center; justify-content: center" title="Zmazať" @click="confirmId = g.id">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" /></svg>
                            </button>
                        </div>

                        <!-- delete confirm -->
                        <div v-if="confirmId === g.id" style="margin-top: 12px; padding: 12px 14px; background: #fdeaea; border-radius: 12px; font-size: 12.5px; font-weight: 600; color: #c0453f">
                            Zmazať skupinu aj s podkategóriami? Transakciám sa zruší kategória.
                            <div style="display: flex; gap: 8px; margin-top: 10px">
                                <button type="button" style="background: #e8544e; color: #fff; font-weight: 700; padding: 8px 14px; border-radius: 10px; font-size: 13px" @click="del(g)">Zmazať</button>
                                <button type="button" style="background: #fff; color: #6a6c7a; font-weight: 700; padding: 8px 14px; border-radius: 10px; font-size: 13px" @click="confirmId = null">Nechať</button>
                            </div>
                        </div>

                        <!-- children -->
                        <div style="display: flex; flex-direction: column; gap: 4px; margin-top: 12px">
                            <div v-for="c in g.children" :key="c.id" style="display: flex; align-items: center; gap: 10px; padding: 9px 8px; border-radius: 11px" @mouseenter="() => {}">
                                <span style="width: 30px; height: 30px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0" :style="{ background: hexToRgba(c.color, 0.16), color: c.color }">{{ glyph(c) }}</span>
                                <span style="flex: 1; font-size: 13.5px; font-weight: 600">{{ c.name }}</span>
                                <span v-if="txnCounts[c.id]" style="font-size: 11px; font-weight: 700; color: #9a9cab; background: #f5f4ef; padding: 3px 8px; border-radius: 20px">{{ txnCounts[c.id] }}×</span>
                                <button type="button" style="width: 28px; height: 28px; border-radius: 8px; color: #b8b6ac; display: flex; align-items: center; justify-content: center" title="Upraviť" @click="modal = { mode: 'edit', category: c }">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
                                </button>
                                <button v-if="confirmId !== c.id" type="button" style="width: 28px; height: 28px; border-radius: 8px; color: #c4c2ba; display: flex; align-items: center; justify-content: center" title="Zmazať" @click="confirmId = c.id">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" /></svg>
                                </button>
                                <template v-else>
                                    <button type="button" style="font-size: 12px; font-weight: 700; color: #e8544e; padding: 4px 8px" @click="del(c)">Zmazať</button>
                                    <button type="button" style="font-size: 12px; font-weight: 700; color: #9a9cab; padding: 4px 6px" @click="confirmId = null">Nie</button>
                                </template>
                            </div>
                        </div>

                        <button type="button" style="display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 10px; padding: 10px; border-radius: 12px; border: 1.5px dashed #dcdace; color: #9a9cab; font-size: 13px; font-weight: 700" @click="modal = { mode: 'child', parent: g }">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                            Podkategória
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <CategoryModal v-if="modal" :mode="modal.mode" :parent="modal.parent" :category="modal.category" @close="modal = null" />
    </GrosLayout>
</template>
