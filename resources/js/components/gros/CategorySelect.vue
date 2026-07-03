<script setup lang="ts">
import { useGros } from '@/composables/useGros';
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { Category, CategoryNode } from '@/lib/gros';

const props = defineProps<{ type: 'income' | 'expense' }>();
const model = defineModel<number | null>({ required: true });

const { categoryTree, categoryById, recentCategoryIds, hexToRgba, primary } = useGros();

const open = ref(false);
const query = ref('');
const drill = ref<CategoryNode | null>(null); // rozkliknutá skupina
const trigger = ref<HTMLElement | null>(null);
const dropdown = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const rect = ref({ top: 0, left: 0, width: 0 });

const selected = computed(() => categoryById(model.value));

const norm = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
const q = computed(() => norm(query.value.trim()));

const groups = computed(() => categoryTree.value.filter((g) => g.type === props.type));

// vyhľadávanie: ploché výsledky (skupiny + podkategórie) daného typu
const searchResults = computed(() => {
    if (!q.value) return [];
    const out: { cat: Category; groupName: string | null }[] = [];
    for (const g of groups.value) {
        if (norm(g.name).includes(q.value)) out.push({ cat: g, groupName: null });
        for (const c of g.children) {
            if (norm(c.name).includes(q.value)) out.push({ cat: c, groupName: g.name });
        }
    }
    return out;
});

const recentItems = computed(() =>
    q.value || drill.value ? [] : recentCategoryIds.value.map((id) => categoryById(id)).filter((c): c is Category => !!c && c.type === props.type),
);

function updateRect() {
    const el = trigger.value;
    if (!el) return;
    const r = el.getBoundingClientRect();
    rect.value = { top: r.bottom + 6, left: r.left, width: r.width };
}

async function toggle() {
    if (open.value) {
        open.value = false;
        return;
    }
    updateRect();
    open.value = true;
    query.value = '';
    drill.value = null;
    await nextTick();
    searchInput.value?.focus();
}

function pick(id: number) {
    model.value = id;
    open.value = false;
}
function enterGroup(g: CategoryNode) {
    if (g.children.length) {
        drill.value = g;
    } else {
        pick(g.id);
    }
}

function onDocMouseDown(e: MouseEvent) {
    const t = e.target as Node;
    if (trigger.value?.contains(t) || dropdown.value?.contains(t)) return;
    open.value = false;
}
function onScrollResize() {
    if (open.value) updateRect();
}

watch(open, (v) => {
    if (v) {
        document.addEventListener('mousedown', onDocMouseDown);
        window.addEventListener('scroll', onScrollResize, true);
        window.addEventListener('resize', onScrollResize);
    } else {
        document.removeEventListener('mousedown', onDocMouseDown);
        window.removeEventListener('scroll', onScrollResize, true);
        window.removeEventListener('resize', onScrollResize);
    }
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocMouseDown);
    window.removeEventListener('scroll', onScrollResize, true);
    window.removeEventListener('resize', onScrollResize);
});

function glyph(c: Category) {
    return c.icon || (c.name[0] ?? '?');
}
</script>

<template>
    <div>
        <!-- Trigger -->
        <button
            ref="trigger"
            type="button"
            class="gros-select"
            style="display: flex; align-items: center; gap: 9px; text-align: left; cursor: pointer"
            @click="toggle"
        >
            <template v-if="selected">
                <span style="width: 24px; height: 24px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0" :style="{ background: hexToRgba(selected.color, 0.16), color: selected.color }">{{ glyph(selected) }}</span>
                <span style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ selected.name }}</span>
            </template>
            <span v-else style="flex: 1; color: #9a9cab">Vyber kategóriu…</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9a9cab" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0" :style="{ transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .15s' }"><path d="M6 9l6 6 6-6" /></svg>
        </button>

        <!-- Dropdown -->
        <Teleport to="body">
            <div
                v-if="open"
                ref="dropdown"
                class="gros-field"
                style="position: fixed; z-index: 90; background: #fff; color: #20212e; border-radius: 14px; box-shadow: 0 18px 46px rgba(20, 18, 30, 0.22); border: 1px solid #eceae2; overflow: hidden; display: flex; flex-direction: column; max-height: 340px"
                :style="{ top: rect.top + 'px', left: rect.left + 'px', width: rect.width + 'px' }"
            >
                <!-- Vyhľadávanie -->
                <div style="padding: 10px; border-bottom: 1px solid #f1efe8">
                    <div style="display: flex; align-items: center; gap: 8px; background: #faf9f5; border: 1.5px solid #eceae2; border-radius: 11px; padding: 0 11px">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#9a9cab" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
                        <input ref="searchInput" v-model="query" type="text" placeholder="Hľadať kategóriu…" style="flex: 1; border: none; background: transparent; padding: 10px 0; font-size: 14px; font-weight: 600; color: #20212e; outline: none" />
                    </div>
                </div>

                <div style="overflow-y: auto; padding: 6px">
                    <!-- Výsledky hľadania (ploché) -->
                    <template v-if="q">
                        <button
                            v-for="r in searchResults"
                            :key="'s' + r.cat.id"
                            type="button"
                            style="display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; padding: 9px 10px; border-radius: 10px; font-size: 14px; font-weight: 600"
                            :style="{ background: model === r.cat.id ? hexToRgba(r.cat.color, 0.12) : 'transparent' }"
                            @click="pick(r.cat.id)"
                        >
                            <span style="width: 26px; height: 26px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0" :style="{ background: hexToRgba(r.cat.color, 0.16), color: r.cat.color }">{{ glyph(r.cat) }}</span>
                            <span style="flex: 1">{{ r.cat.name }}</span>
                            <span v-if="r.groupName" style="font-size: 11px; color: #b0b2bd; font-weight: 600">{{ r.groupName }}</span>
                        </button>
                        <div v-if="!searchResults.length" style="padding: 20px 12px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 13px">Nič sa nenašlo.</div>
                    </template>

                    <!-- Rozkliknutá skupina: vyber podkategóriu alebo celú skupinu -->
                    <template v-else-if="drill">
                        <button type="button" style="display: flex; align-items: center; gap: 7px; width: 100%; text-align: left; padding: 8px 10px; border-radius: 9px; font-size: 12.5px; font-weight: 700; color: #61637a" @click="drill = null">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6" /></svg>
                            Späť
                        </button>
                        <!-- celá skupina -->
                        <button
                            type="button"
                            style="display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; padding: 10px; border-radius: 10px; font-size: 14px; font-weight: 700; margin-top: 2px"
                            :style="{ background: hexToRgba(drill.color, model === drill.id ? 0.18 : 0.08) }"
                            @click="pick(drill.id)"
                        >
                            <span style="width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0" :style="{ background: hexToRgba(drill.color, 0.2), color: drill.color }">{{ glyph(drill) }}</span>
                            <span style="flex: 1">Celé: {{ drill.name }}</span>
                            <svg v-if="model === drill.id" width="16" height="16" viewBox="0 0 24 24" fill="none" :stroke="drill.color" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
                        </button>
                        <div style="height: 1px; background: #f1efe8; margin: 6px 8px"></div>
                        <button
                            v-for="c in drill.children"
                            :key="c.id"
                            type="button"
                            style="display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; padding: 9px 10px; border-radius: 10px; font-size: 14px; font-weight: 600"
                            :style="{ background: model === c.id ? hexToRgba(c.color, 0.12) : 'transparent' }"
                            @click="pick(c.id)"
                        >
                            <span style="width: 26px; height: 26px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0" :style="{ background: hexToRgba(c.color, 0.16), color: c.color }">{{ glyph(c) }}</span>
                            <span style="flex: 1">{{ c.name }}</span>
                            <svg v-if="model === c.id" width="16" height="16" viewBox="0 0 24 24" fill="none" :stroke="c.color" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
                        </button>
                    </template>

                    <!-- Root: nedávne + hlavné kategórie -->
                    <template v-else>
                        <template v-if="recentItems.length">
                            <div style="font-size: 11px; font-weight: 700; color: #9a9cab; text-transform: uppercase; letter-spacing: 0.3px; padding: 8px 10px 4px">Nedávne</div>
                            <div style="display: flex; gap: 8px; overflow-x: auto; padding: 2px 8px 4px; scrollbar-width: thin">
                                <button
                                    v-for="c in recentItems"
                                    :key="'r' + c.id"
                                    type="button"
                                    style="display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; padding: 7px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; white-space: nowrap; cursor: pointer"
                                    :style="{ background: hexToRgba(c.color, model === c.id ? 0.22 : 0.12), color: model === c.id ? c.color : '#3a3b47', border: '1.5px solid ' + (model === c.id ? c.color : 'transparent') }"
                                    @click="pick(c.id)"
                                >
                                    <span v-if="c.icon">{{ c.icon }}</span>
                                    {{ c.name }}
                                </button>
                            </div>
                            <div style="height: 1px; background: #f1efe8; margin: 6px 8px"></div>
                        </template>

                        <button
                            v-for="g in groups"
                            :key="g.id"
                            type="button"
                            style="display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; padding: 10px; border-radius: 10px; font-size: 14px; font-weight: 600"
                            @click="enterGroup(g)"
                        >
                            <span style="width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0" :style="{ background: hexToRgba(g.color, 0.16), color: g.color }">{{ glyph(g) }}</span>
                            <span style="flex: 1">{{ g.name }}</span>
                            <span v-if="g.children.length" style="font-size: 11px; color: #b0b2bd; font-weight: 600">{{ g.children.length }}</span>
                            <svg v-if="g.children.length" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#c4c2ba" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0"><path d="M9 18l6-6-6-6" /></svg>
                        </button>
                        <div v-if="!groups.length" style="padding: 20px 12px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 13px">Žiadne kategórie tohto typu.</div>
                    </template>
                </div>

                <Link href="/categories" style="display: flex; align-items: center; gap: 6px; padding: 11px 14px; border-top: 1px solid #f1efe8; font-size: 12.5px; font-weight: 700" :style="{ color: primary }">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                    Pridať / spravovať kategórie
                </Link>
            </div>
        </Teleport>
    </div>
</template>
