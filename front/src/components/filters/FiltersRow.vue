<template>
    <div class="filters-row">
        <slot></slot>
    </div>
</template>
<style scoped>
.filters-row {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 16px;
  align-items: flex-end;
}
</style>