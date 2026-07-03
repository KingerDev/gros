<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';

defineProps<{ title: string }>();
const emit = defineEmits<{ close: [] }>();

const isDesktop = useMediaQuery('(min-width: 860px)');
</script>

<template>
    <Teleport to="body">
        <div
            class="gros-fadein"
            style="position: fixed; inset: 0; background: rgba(30, 28, 40, 0.42); backdrop-filter: blur(3px); display: flex; align-items: flex-end; justify-content: center; z-index: 60"
            @click.self="emit('close')"
        >
            <div
                class="gros-pop gros-field"
                style="background: #fff; color: #20212e; padding: 26px; width: 100%; box-shadow: 0 30px 80px rgba(20, 18, 30, 0.3); max-height: 92vh; overflow-y: auto"
                :style="{
                    borderRadius: isDesktop ? '26px' : '26px 26px 0 0',
                    maxWidth: isDesktop ? '480px' : '560px',
                    marginTop: isDesktop ? 'auto' : '0',
                    marginBottom: isDesktop ? 'auto' : '0',
                }"
            >
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px">
                    <div class="font-display" style="font-weight: 800; font-size: 22px; letter-spacing: -0.5px; color: #20212e">{{ title }}</div>
                    <button
                        type="button"
                        style="width: 34px; height: 34px; border-radius: 11px; background: #f1efe8; display: flex; align-items: center; justify-content: center; color: #6a6c7a"
                        @click="emit('close')"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                    </button>
                </div>
                <slot />
            </div>
        </div>
    </Teleport>
</template>
