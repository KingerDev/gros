// Groš — zdieľané typy a čisté pomocné funkcie (farby, formátovanie).

export interface GrosSettings {
    accent: string;
    showDecimals: boolean;
    privacyMode: boolean;
}

export interface GrosSummary {
    cash: number;
    portfolio: number;
    netWorth: number;
    accountCount: number;
}

export interface GrosRef {
    kindLabels: Record<string, string>;
    palette: string[];
    accentOptions: string[];
}

export interface Category {
    id: number;
    parent_id: number | null;
    name: string;
    type: 'income' | 'expense';
    color: string;
    icon: string | null;
}

/** Udalosť (dovolenka, svadba…) — skupina výdavkov naprieč kategóriami. */
export interface GrosEvent {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    color: string;
    icon: string | null;
}

export interface CategoryNode extends Category {
    children: Category[];
}

/** Posun farby pre gradient (identické s pôvodným dizajnom). */
export function shift(hex: string): string {
    const h = hex.replace('#', '');
    let r = parseInt(h.substr(0, 2), 16);
    let g = parseInt(h.substr(2, 2), 16);
    let b = parseInt(h.substr(4, 2), 16);
    r = Math.min(255, Math.round(r * 0.7 + 40));
    g = Math.min(255, Math.round(g * 0.6 + 30));
    b = Math.min(255, Math.round(b * 0.95 + 20));
    return '#' + [r, g, b].map((x) => x.toString(16).padStart(2, '0')).join('');
}

export function hexToRgba(hex: string, a: number): string {
    const h = hex.replace('#', '');
    const r = parseInt(h.substr(0, 2), 16);
    const g = parseInt(h.substr(2, 2), 16);
    const b = parseInt(h.substr(4, 2), 16);
    return `rgba(${r},${g},${b},${a})`;
}

/** 135° gradient z farby → jej posun. */
export function gradient(hex: string): string {
    return `linear-gradient(135deg,${hex},${shift(hex)})`;
}

/** Mäkký tieň farby. */
export function soft(hex: string): string {
    return hexToRgba(hex, 0.34);
}

/** „10. – 17. 9. 2026" — rozsah dátumov udalosti, bez opakovania mesiaca a roka. */
export function formatRange(from: string, to: string): string {
    const [fy, fm, fd] = from.slice(0, 10).split('-').map(Number);
    const [ty, tm, td] = to.slice(0, 10).split('-').map(Number);
    if (from.slice(0, 10) === to.slice(0, 10)) return `${fd}. ${fm}. ${fy}`;
    if (fy === ty && fm === tm) return `${fd}. – ${td}. ${tm}. ${ty}`;
    if (fy === ty) return `${fd}. ${fm}. – ${td}. ${tm}. ${ty}`;
    return `${fd}. ${fm}. ${fy} – ${td}. ${tm}. ${ty}`;
}

/** DD.MM.YYYY z ISO reťazca alebo Date. */
export function formatDate(iso: string): string {
    const d = String(iso).slice(0, 10).split('-');
    return d.length === 3 ? `${d[2]}.${d[1]}.${d[0]}` : iso;
}
