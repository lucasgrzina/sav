<script setup lang="ts">
</script>

<template>
    <div class="brand-content">
        <!-- Logo -->
        <div class="auth-brand-logo" style="position: relative; z-index: 1">
            <div class="auth-brand-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2C9.5 2 7.5 3.8 7.5 6s2 4 4.5 4 4.5-1.8 4.5-4S14.5 2 12 2Z" fill="#fff" opacity="0.9"/>
                    <circle cx="6.5" cy="6.5" r="2" fill="#fff" opacity="0.6"/>
                    <circle cx="17.5" cy="6.5" r="2" fill="#fff" opacity="0.6"/>
                    <circle cx="4" cy="11" r="1.8" fill="#fff" opacity="0.5"/>
                    <circle cx="20" cy="11" r="1.8" fill="#fff" opacity="0.5"/>
                    <path d="M12 13c-4.5 0-8 2.5-8 6 0 2 3.5 3 8 3s8-1 8-3c0-3.5-3.5-6-8-6Z" fill="#fff" opacity="0.8"/>
                </svg>
            </div>
            <span class="auth-brand-name">Vet<span>Alert</span></span>
        </div>

        <!-- Headline -->
        <div style="position: relative; z-index: 1; margin-top: auto; padding-top: 48px">
            <p style="font-size: 11px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(255,255,255,0.8); margin: 0 0 16px">
                Sistema de monitoreo
            </p>
            <h1 class="auth-brand-headline">
                Alertas en<br />tiempo real<br />
                <span style="color: rgba(255,255,255,0.85)">para tu clínica.</span>
            </h1>
            <p class="auth-brand-sub">
                Monitoreá el estado de tus pacientes, recibí alertas críticas y respondé en segundos desde cualquier dispositivo.
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
                    <!-- Faint duplicate for depth -->
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
        <div class="auth-stats" style="position: relative; z-index: 1">
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
        </div>
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
