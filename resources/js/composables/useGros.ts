import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    formatDate,
    gradient,
    hexToRgba,
    shift,
    soft,
    type Category,
    type CategoryNode,
    type GrosRef,
    type GrosSettings,
    type GrosSummary,
} from '@/lib/gros';

/**
 * Centrálny prístup k nastaveniam Groš, formátovaniu peňazí a kategóriám
 * (stromová štruktúra, mapa id → kategória, nedávno použité).
 */
export function useGros() {
    const page = usePage();

    const settings = computed<GrosSettings>(() => (page.props.settings as GrosSettings) ?? { accent: '#6c5ce7', showDecimals: true, privacyMode: false });
    const ref = computed<GrosRef>(() => page.props.ref as GrosRef);
    const summary = computed<GrosSummary | null>(() => (page.props.summary as GrosSummary) ?? null);

    const categories = computed<Category[]>(() => (page.props.categories as Category[]) ?? []);
    const recentCategoryIds = computed<number[]>(() => (page.props.recentCategoryIds as number[]) ?? []);

    const categoryMap = computed(() => {
        const m = new Map<number, Category>();
        for (const c of categories.value) m.set(c.id, c);
        return m;
    });

    /** Strom: skupiny (parent_id === null) s poľom children. */
    const categoryTree = computed<CategoryNode[]>(() => {
        const groups = categories.value.filter((c) => c.parent_id === null);
        return groups.map((g) => ({
            ...g,
            children: categories.value.filter((c) => c.parent_id === g.id),
        }));
    });

    /** Listy (priraditeľné na transakciu) daného typu, zoskupené pod svojou skupinou. */
    function leafGroups(type: 'income' | 'expense'): CategoryNode[] {
        return categoryTree.value
            .filter((g) => g.type === type)
            .map((g) => ({ ...g, children: g.children.length ? g.children : [] }));
    }

    const primary = computed(() => settings.value.accent || '#6c5ce7');
    const grad = computed(() => gradient(primary.value));
    const primarySoft = computed(() => soft(primary.value));

    function eur(n: number): string {
        if (settings.value.privacyMode) return '••••• €';
        const dec = settings.value.showDecimals === false ? 0 : 2;
        return new Intl.NumberFormat('sk-SK', { minimumFractionDigits: dec, maximumFractionDigits: dec }).format(Math.abs(n)) + ' €';
    }

    function eurS(n: number): string {
        if (settings.value.privacyMode) return '••••• €';
        return (n < 0 ? '− ' : '') + eur(n);
    }

    function num(n: number, d = 0): string {
        return new Intl.NumberFormat('sk-SK', { minimumFractionDigits: d, maximumFractionDigits: d }).format(n);
    }

    /** Počet kusov — až 8 desatinných miest, bez núteného zaokrúhľovania (napr. BTC). */
    function fmtUnits(n: number): string {
        return new Intl.NumberFormat('sk-SK', { maximumFractionDigits: 8 }).format(n);
    }

    function categoryById(id: number | null | undefined): Category | undefined {
        return id == null ? undefined : categoryMap.value.get(id);
    }
    function catName(id: number | null | undefined): string {
        return categoryById(id)?.name ?? 'Bez kategórie';
    }
    function catColor(id: number | null | undefined): string {
        return categoryById(id)?.color ?? '#94a3b8';
    }
    function catIcon(id: number | null | undefined): string | null {
        return categoryById(id)?.icon ?? null;
    }
    /** Ikona alebo prvé písmeno názvu (fallback pre avatar). */
    function catGlyph(id: number | null | undefined): string {
        const c = categoryById(id);
        return c?.icon || (c?.name?.[0] ?? '?');
    }

    function kindLabel(kind: string): string {
        return ref.value?.kindLabels?.[kind] ?? kind;
    }

    return {
        settings,
        ref,
        summary,
        categories,
        recentCategoryIds,
        categoryMap,
        categoryTree,
        leafGroups,
        categoryById,
        catName,
        catColor,
        catIcon,
        catGlyph,
        primary,
        grad,
        primarySoft,
        eur,
        eurS,
        num,
        fmtUnits,
        kindLabel,
        formatDate,
        hexToRgba,
        gradient,
        shift,
        soft,
    };
}
