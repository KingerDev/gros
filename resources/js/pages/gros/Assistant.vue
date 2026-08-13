<script setup lang="ts">
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

interface Msg {
    id: number;
    role: string;
    content: string | null;
    tools: string[];
    at: string | null;
}
interface ChatRef {
    id: number;
    title: string;
    at: string | null;
}

const props = defineProps<{
    chats: ChatRef[];
    chat: { id: number; title: string | null } | null;
    messages: Msg[];
    configured: boolean;
    suggestions: string[];
    prefill: string | null;
}>();

const { primary, primarySoft, grad, formatDate } = useGros();

const messages = ref<Msg[]>([...props.messages]);
const chatId = ref<number | null>(props.chat?.id ?? null);
const input = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const thread = ref<HTMLElement | null>(null);

watch(
    () => props.messages,
    (m) => {
        messages.value = [...m];
        chatId.value = props.chat?.id ?? null;
    },
);

async function scrollDown() {
    await nextTick();
    thread.value?.scrollTo({ top: thread.value.scrollHeight, behavior: 'smooth' });
}

async function send(text?: string) {
    const question = (text ?? input.value).trim();
    if (!question || sending.value) return;

    input.value = '';
    error.value = null;
    sending.value = true;

    // otázka sa ukáže hneď, odpoveď dorazí po prepočte
    messages.value = [...messages.value, { id: -Date.now(), role: 'user', content: question, tools: [], at: null }];
    scrollDown();

    try {
        const r = await fetch('/assistant/send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ message: question, chat_id: chatId.value }),
        });
        const data = await r.json();

        messages.value = data.messages ?? messages.value;
        if (data.chat_id) chatId.value = data.chat_id;
        if (!data.ok) error.value = data.error ?? 'Nepodarilo sa získať odpoveď.';

        // nový chat sa musí objaviť v zozname vľavo
        if (!props.chat && data.chat_id) router.visit(`/assistant/${data.chat_id}`, { preserveScroll: true, preserveState: true });
    } catch {
        error.value = 'Spojenie zlyhalo.';
    } finally {
        sending.value = false;
        scrollDown();
    }
}

function newChat() {
    router.visit('/assistant');
}
function removeChat(id: number) {
    router.delete(`/assistant/${id}`, { preserveScroll: true });
}

const empty = computed(() => messages.value.length === 0);

// otázka prišla z inej stránky — netreba čakať, kým ju používateľ odklikne
onMounted(() => {
    if (props.prefill && props.configured && !messages.value.length) send(props.prefill);
});

const toolLabels: Record<string, string> = {
    spending_summary: 'súhrn výdavkov',
    compare_periods: 'porovnanie období',
    list_transactions: 'konkrétne transakcie',
    monthly_trend: 'mesačný vývoj',
    financial_overview: 'celkový prehľad',
    investment_portfolio: 'portfólio',
    recurring_costs: 'predplatné a úvery',
};
</script>

<template>
    <Head title="Asistent" />
    <GrosLayout title="Asistent" subtitle="Spýtaj sa na svoje financie">
        <template #action>
            <button
                type="button"
                style="display: flex; align-items: center; gap: 7px; color: #fff; font-weight: 700; font-size: 14px; padding: 11px 16px; border-radius: 13px; white-space: nowrap"
                :style="{ background: primary, boxShadow: `0 6px 14px ${primarySoft}` }"
                @click="newChat"
            >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                Nový chat
            </button>
        </template>

        <div class="gros-rise">
            <div
                v-if="!configured"
                style="display: flex; align-items: flex-start; gap: 10px; background: #fdeaea; border-radius: 14px; padding: 14px 17px; margin-bottom: 14px"
            >
                <span style="font-size: 16px">⚠️</span>
                <span style="font-size: 13px; font-weight: 600; color: #c0453f; line-height: 1.55">
                    Chýba <code>OPENAI_API_KEY</code> v <code>.env</code>. Bez neho asistent neodpovie.
                </span>
            </div>

            <div style="display: flex; gap: 14px; align-items: flex-start">
                <!-- ── Uložené chaty ──────────────────────────────────── -->
                <div
                    v-if="chats.length"
                    style="width: 232px; flex-shrink: 0; background: #fff; border-radius: 20px; padding: 10px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); max-height: 640px; overflow-y: auto"
                    class="gros-chatlist"
                >
                    <div style="font-size: 11.5px; font-weight: 800; color: #8a8c9a; padding: 8px 10px 6px">História</div>
                    <div v-for="c in chats" :key="c.id" style="display: flex; align-items: center; gap: 4px">
                        <Link
                            :href="`/assistant/${c.id}`"
                            style="flex: 1; min-width: 0; display: block; padding: 9px 10px; border-radius: 11px; font-size: 12.5px; font-weight: 600; line-height: 1.4"
                            :style="chat?.id === c.id ? { background: '#f1efe8', color: '#20212e', fontWeight: 800 } : { color: '#6a6c7a' }"
                        >
                            <span style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ c.title }}</span>
                            <span v-if="c.at" style="display: block; font-size: 10.5px; color: #b0b2bd; font-weight: 600; margin-top: 2px">{{ formatDate(c.at) }}</span>
                        </Link>
                        <button
                            type="button"
                            style="width: 26px; height: 26px; border-radius: 8px; color: #c8c6bd; flex-shrink: 0; display: flex; align-items: center; justify-content: center"
                            title="Zmazať chat"
                            @click="removeChat(c.id)"
                        >
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" /></svg>
                        </button>
                    </div>
                </div>

                <!-- ── Konverzácia ────────────────────────────────────── -->
                <div style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 12px">
                    <div
                        ref="thread"
                        style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); min-height: 300px; max-height: 560px; overflow-y: auto"
                    >
                        <!-- prázdny stav s návrhmi -->
                        <div v-if="empty" style="padding: 20px 4px">
                            <div class="font-display" style="font-weight: 800; font-size: 20px; letter-spacing: -0.5px">Na čo sa chceš spýtať?</div>
                            <div style="font-size: 13px; color: #8a8c9a; font-weight: 600; line-height: 1.6; margin-top: 8px">
                                Asistent vidí tvoje transakcie, účty, portfólio, úvery aj plány. Odpovede podkladá konkrétnymi položkami —
                                nič si nedomýšľa.
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 18px">
                                <button
                                    v-for="s in suggestions"
                                    :key="s"
                                    type="button"
                                    style="text-align: left; font-size: 13px; font-weight: 600; color: #20212e; background: #f7f6f2; border-radius: 13px; padding: 13px 16px; line-height: 1.4"
                                    @click="send(s)"
                                >
                                    {{ s }}
                                </button>
                            </div>
                        </div>

                        <div v-else style="display: flex; flex-direction: column; gap: 14px">
                            <div v-for="m in messages" :key="m.id" :style="{ display: 'flex', justifyContent: m.role === 'user' ? 'flex-end' : 'flex-start' }">
                                <div style="max-width: 86%">
                                    <div
                                        v-if="m.role === 'assistant' && m.tools.length"
                                        style="font-size: 11px; font-weight: 700; color: #b0b2bd; margin-bottom: 5px; padding-left: 2px"
                                    >
                                        pozrel sa na: {{ m.tools.map((t) => toolLabels[t] ?? t).join(' · ') }}
                                    </div>
                                    <div
                                        style="border-radius: 16px; padding: 13px 16px; font-size: 14px; font-weight: 500; line-height: 1.6; white-space: pre-wrap; word-break: break-word"
                                        :style="m.role === 'user' ? { background: grad, color: '#fff', fontWeight: 600 } : { background: '#f7f6f2', color: '#20212e' }"
                                    >{{ m.content }}</div>
                                </div>
                            </div>

                            <div v-if="sending" style="display: flex; align-items: center; gap: 8px; color: #b0b2bd; font-size: 13px; font-weight: 600; padding-left: 2px">
                                <span class="gros-dot"></span> prezerám tvoje dáta…
                            </div>
                        </div>
                    </div>

                    <div v-if="error" style="background: #fdeaea; border-radius: 13px; padding: 12px 15px; font-size: 12.5px; font-weight: 600; color: #c0453f; line-height: 1.55">
                        {{ error }}
                    </div>

                    <!-- ── Vstup ──────────────────────────────────────── -->
                    <div style="display: flex; gap: 9px; align-items: flex-end">
                        <textarea
                            v-model="input"
                            rows="1"
                            placeholder="Napíš otázku…"
                            style="flex: 1; resize: none; background: #fff; border: none; border-radius: 15px; padding: 15px 18px; font-size: 14.5px; font-weight: 500; color: #20212e; outline: none; box-shadow: 0 2px 8px rgba(60, 55, 40, 0.05); max-height: 140px"
                            @keydown.enter.exact.prevent="send()"
                            @input="(e) => { const t = e.target as HTMLTextAreaElement; t.style.height = 'auto'; t.style.height = Math.min(140, t.scrollHeight) + 'px'; }"
                        ></textarea>
                        <button
                            type="button"
                            style="width: 50px; height: 50px; border-radius: 15px; color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0"
                            :style="{ background: primary, boxShadow: `0 6px 14px ${primarySoft}`, opacity: sending || !input.trim() ? 0.45 : 1 }"
                            :disabled="sending || !input.trim()"
                            @click="send()"
                        >
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                        </button>
                    </div>
                    <div style="font-size: 11px; color: #b0b2bd; font-weight: 600; padding-left: 4px">
                        Asistent má prístup len na čítanie a odpovede opiera o tvoje skutočné dáta. Aj tak si dôležité čísla over.
                    </div>
                </div>
            </div>
        </div>
    </GrosLayout>
</template>

<style scoped>
.gros-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
    animation: gros-pulse 1s ease-in-out infinite;
}
@keyframes gros-pulse {
    0%,
    100% {
        opacity: 0.25;
    }
    50% {
        opacity: 1;
    }
}
.gros-chatlist {
    display: none;
}
@media (min-width: 860px) {
    .gros-chatlist {
        display: block;
    }
}
</style>
