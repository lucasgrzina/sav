<script setup lang="ts">
const features = [
    'Protocolos de reproducción',
    'Calendarios sanitarios',
    'Otros eventos',
    'Tratamientos',
    'IATF',
    'MOET',
    'IVF',
]

// Floating alert icons: position, size, duration and delay vary per instance
const alerts = [
    { top: '8%',  left: '62%', size: 64,  duration: 18, delay: 0,   dx: '-120px', dy: '90px' },
    { top: '30%', left: '78%', size: 110, duration: 24, delay: -6,  dx: '-160px', dy: '120px' },
    { top: '58%', left: '55%', size: 48,  duration: 16, delay: -3,  dx: '90px',   dy: '-110px' },
    { top: '72%', left: '80%', size: 84,  duration: 22, delay: -10, dx: '-140px', dy: '-90px' },
    { top: '88%', left: '35%', size: 56,  duration: 20, delay: -14, dx: '120px',  dy: '-140px' },
]
</script>

<template>
    <div class="brand-content">
        <!-- Floating alert icons -->
        <svg
            v-for="(a, i) in alerts"
            :key="i"
            class="brand-alert"
            viewBox="0 0 100 100"
            aria-hidden="true"
            :style="{
                top: a.top,
                left: a.left,
                width: `${a.size}px`,
                height: `${a.size}px`,
                '--dx': a.dx,
                '--dy': a.dy,
                animationDuration: `${a.duration}s`,
                animationDelay: `${a.delay}s`,
            }"
        >
            <path d="M50 10 L92 86 Q94 90 89 90 L11 90 Q6 90 8 86 Z" fill="none" stroke="#fff" stroke-width="7" stroke-linejoin="round" />
            <path d="M50 42 L68 78 L32 78 Z" fill="none" stroke="#fff" stroke-width="6" stroke-linejoin="round" />
        </svg>

        <!-- Headline -->
        <div style="position: relative; z-index: 1; padding-top: 48px">
            <p style="font-size: 11px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(255,255,255,0.8); margin: 0 0 16px">
                Software de Alertas Veterinarias
            </p>
            <h1 class="auth-brand-headline">
                Alertas en<br />tiempo real
            </h1>
            <p class="auth-brand-sub">
                Actualizá los próximos trabajos en los establecimientos.
            </p>

            <ul class="brand-features">
                <li
                    v-for="(feature, index) in features"
                    :key="feature"
                    :style="{ animationDelay: `${0.4 + index * 0.18}s` }"
                >
                    {{ feature }}
                </li>
            </ul>
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

.brand-features {
    list-style: none;
    margin: 32px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.brand-features li {
    position: relative;
    padding-left: 22px;
    font-size: 15px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.9);
    opacity: 0;
    animation: bullet-in 0.6s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}

.brand-features li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #fff;
    transform: translateY(-50%);
}

.brand-alert {
    position: absolute;
    z-index: 0;
    opacity: 0.14;
    pointer-events: none;
    filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.5));
    animation: alert-drift ease-in-out infinite alternate;
}

@keyframes alert-drift {
    0%   { transform: translate(0, 0) rotate(-8deg) scale(1); }
    50%  { transform: translate(calc(var(--dx) * 0.5), calc(var(--dy) * 0.4)) rotate(6deg) scale(1.12); }
    100% { transform: translate(var(--dx), var(--dy)) rotate(-4deg) scale(0.95); }
}

@media (prefers-reduced-motion: reduce) {
    .brand-alert {
        animation: none;
    }
    .brand-features li {
        animation: none;
        opacity: 1;
    }
}
</style>
