<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Prechod do asistenta s hotovou otázkou. Otázka sa odošle hneď po otvorení,
 * takže z ktorejkoľvek stránky je odpoveď na dva kliky.
 */
const props = defineProps<{ questions: string[]; label?: string }>();

const links = computed(() => props.questions.map((q) => ({ q, href: `/assistant?q=${encodeURIComponent(q)}` })));
</script>

<template>
    <div style="background: #fff; border-radius: 20px; padding: 18px 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
        <div style="display: flex; align-items: center; gap: 8px">
            <span style="font-size: 15px">✨</span>
            <span style="font-size: 12.5px; font-weight: 800; color: #20212e">{{ label ?? 'Spýtaj sa asistenta' }}</span>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 7px; margin-top: 12px">
            <Link
                v-for="l in links"
                :key="l.q"
                :href="l.href"
                style="font-size: 12.5px; font-weight: 600; color: #6a6c7a; background: #f7f6f2; border-radius: 11px; padding: 9px 13px; line-height: 1.35"
            >
                {{ l.q }}
            </Link>
        </div>
    </div>
</template>
