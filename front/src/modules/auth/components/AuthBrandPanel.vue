<script setup lang="ts">
</script>

<template>
    <div class="brand-content">
        <!-- Logo -->
        <div class="auth-brand-logo" style="position: relative; z-index: 1">
            <img src="@/assets/logo.png" alt="SAV" style="width: 110px; max-width: 100%; background: #fff; padding: 8px 12px; border-radius: 12px" />
        </div>

        <!-- Headline -->
        <div style="position: relative; z-index: 1; margin-top: auto; padding-top: 48px">
            <p style="font-size: 11px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(255,255,255,0.8); margin: 0 0 16px">
                SISTEMA DE ALERTA VETERINARIA
            </p>
            <h1 class="auth-brand-headline">
                Alertas en<br />tiempo real
            </h1>
            <p class="auth-brand-sub">
                Actualizá los próximos trabajos en los establecimientos.
            </p>
        </div>

        <!-- ECG Animation -->
        <div class="ecg-wrapper" style="position: relative; z-index: 1">
            <div class="ecg-track">
                <svg height="80" viewBox="0 0 800 80" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                    <polyline
                        class="ecg-glow"
                        points="
                            0,40 60,40 75,32 88,48 95,8 104,72 112,40
                            130,38 148,40
                            210,40 225,32 238,48 245,8 254,72 262,40
                            280,38 298,40
                            360,40 375,32 388,48 395,8 404,72 412,40
                            430,38 448,40
                            510,40 525,32 538,48 545,8 554,72 562,40
                            580,38 598,40
                            660,40 675,32 688,48 695,8 704,72 712,40
                            730,38 748,40 800,40
                        "
                        stroke="rgba(255,255,255,0.9)"
                        stroke-width="1.8"
                        fill="none"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <polyline
                        points="
                            0,40 60,40 75,32 88,48 95,8 104,72 112,40
                            130,38 148,40
                            210,40 225,32 238,48 245,8 254,72 262,40
                            280,38 298,40
                            360,40 375,32 388,48 395,8 404,72 412,40
                            430,38 448,40
                            510,40 525,32 538,48 545,8 554,72 562,40
                            580,38 598,40
                            660,40 675,32 688,48 695,8 704,72 712,40
                            730,38 748,40 800,40
                        "
                        stroke="rgba(255,255,255,0.12)"
                        stroke-width="6"
                        fill="none"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </div>
        </div>

        <!-- Stats -->
        <!--div class="auth-stats" style="position: relative; z-index: 1">
            <div class="auth-stat">
                <div class="auth-stat-value">247</div>
                <div class="auth-stat-label">Alertas hoy</div>
            </div>
            <div class="auth-stat">
                <div class="auth-stat-value">&lt;2m</div>
                <div class="auth-stat-label">Respuesta</div>
            </div>
            <div class="auth-stat">
                <div class="auth-stat-value">1.2k</div>
                <div class="auth-stat-label">Pacientes</div>
            </div>
        </div-->
    </div>
</template>

<style scoped>
.brand-content {
    display: flex;
    flex-direction: column;
    height: 100%;
    position: relative;
    z-index: 1;
}
</style>
