<script setup lang="ts">
defineProps<{
    title?: string;
    subtitle?: string;
    compact?: boolean;
}>();
</script>

<template>
    <div class="form-section" :class="{'form-section-compact': compact}">
        <div v-if="title" class="fs-header">
            <h3 class="fs-title">{{ title }}</h3>
            <p v-if="subtitle" class="fs-subtitle">{{ subtitle }}</p>
        </div>
        <div class="fs-body">
            <slot />
        </div>
    </div>
</template>

<style scoped>
.form-section {
    background:    var(--dt-bg);
    border:        1px solid var(--dt-border2);
    border-radius: var(--dt-radius-xl, 16px);
    box-shadow:    var(--dt-card-shadow, none);
    overflow:      hidden;
}

.fs-header {
    padding:       20px 24px 16px;
    border-bottom: 1px solid var(--dt-border);
}

.fs-title {
    font-family:    'Syne', sans-serif;
    font-size:      15px;
    font-weight:    700;
    letter-spacing: -0.01em;
    color:          var(--dt-title);
    margin:         0 0 4px;
}

.fs-subtitle {
    font-size: 12.5px;
    color:     var(--dt-muted);
    margin:    0;
}

.fs-body { padding: 20px 24px; }

.form-section-compact .fs-body { padding: 20px 24px; }
.form-section-compact .fs-header { padding: 10px 10px; }
.form-section-compact .fs-body { padding: 10px 10px; }
</style>
