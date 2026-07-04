import { theme as antTheme } from 'ant-design-vue';

export type Palette = 'green' | 'blue' | 'red' | 'yellow';
export type Mode    = 'dark' | 'light';

function rgba(hex: string, alpha: number): string {
    const h = hex.replace('#', '');
    const r = parseInt(h.slice(0, 2), 16);
    const g = parseInt(h.slice(2, 4), 16);
    const b = parseInt(h.slice(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

interface PaletteSpec {
    darkPrimary:   string;
    darkHover:     string;
    darkContrast:  string;
    darkBg:        string;
    darkSidebar:   string;
    darkCard:      string;
    darkInput:     string;
    darkElevated:  string;
    lightPrimary:  string;
    lightHover:    string;
    lightContrast: string;
    lightBg:       string;
}

const SPECS: Record<Palette, PaletteSpec> = {
    green: {
        darkPrimary:   '#1AE5A0',
        darkHover:     '#2DFFB5',
        darkContrast:  '#060F1C',
        darkBg:        '#060F1C',
        darkSidebar:   '#07111F',
        darkCard:      '#0E2038',
        darkInput:     '#0F2035',
        darkElevated:  '#162840',
        lightPrimary:  '#0A9066',
        lightHover:    '#0BB877',
        lightContrast: '#FFFFFF',
        lightBg:       '#EDF1F7',
    },
    blue: {
        darkPrimary:   '#38BDF8',
        darkHover:     '#7DD3FC',
        darkContrast:  '#060F1C',
        darkBg:        '#060F1C',
        darkSidebar:   '#07111F',
        darkCard:      '#0E2038',
        darkInput:     '#0D1E35',
        darkElevated:  '#152840',
        lightPrimary:  '#0284C7',
        lightHover:    '#0369A1',
        lightContrast: '#FFFFFF',
        lightBg:       '#EEF5FA',
    },
    red: {
        darkPrimary:   '#FF5A6A',
        darkHover:     '#FF8C99',
        darkContrast:  '#1C0608',
        darkBg:        '#140608',
        darkSidebar:   '#1C0A0C',
        darkCard:      '#2A1012',
        darkInput:     '#261014',
        darkElevated:  '#2E1418',
        lightPrimary:  '#DC2626',
        lightHover:    '#B91C1C',
        lightContrast: '#FFFFFF',
        lightBg:       '#FDF4F4',
    },
    yellow: {
        darkPrimary:   '#FBBF24',
        darkHover:     '#FCD34D',
        darkContrast:  '#14110A',
        darkBg:        '#14110A',
        darkSidebar:   '#1C1810',
        darkCard:      '#28241A',
        darkInput:     '#241E14',
        darkElevated:  '#2C2618',
        lightPrimary:  '#B45309',
        lightHover:    '#92400E',
        lightContrast: '#FFFFFF',
        lightBg:       '#FDFAF2',
    },
};

const DARK_SHARED = {
    colorText:            '#C8E2EF',
    colorTextSecondary:   '#6B8CAE',
    colorTextPlaceholder: 'rgba(107, 140, 174, 0.5)',
    borderRadius:         8,
    fontFamily:           '"Figtree", system-ui, sans-serif',
    controlHeight:        40,
    fontSize:             14,
};

const LIGHT_SHARED = {
    colorBgContainer:     '#FFFFFF',
    colorBgElevated:      '#F8FAFC',
    colorText:            '#334F6F',
    colorTextSecondary:   '#8294A8',
    colorTextPlaceholder: 'rgba(130, 148, 168, 0.5)',
    colorError:           '#DC2626',
    borderRadius:         8,
    fontFamily:           '"Figtree", system-ui, sans-serif',
    controlHeight:        40,
    fontSize:             14,
};

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function buildDashTheme(palette: Palette, mode: Mode): any {
    const s = SPECS[palette];

    if (mode === 'dark') {
        return {
            algorithm: antTheme.darkAlgorithm,
            token: {
                ...DARK_SHARED,
                colorPrimary:         s.darkPrimary,
                colorTextLightSolid:  s.darkContrast,
                colorBgContainer:     s.darkInput,
                colorBgElevated:      s.darkElevated,
                colorBgLayout:        s.darkBg,
                colorBorder:          rgba(s.darkPrimary, 0.18),
                colorBorderSecondary: rgba(s.darkPrimary, 0.10),
                colorLink:            s.darkPrimary,
                colorLinkHover:       s.darkHover,
                colorError:           '#FF5A6A',
                colorWarning:         '#FBBF24',
                colorInfo:            '#38BDF8',
            },
            components: {
                Input: {
                    colorBgContainer:  rgba(s.darkPrimary, 0.04),
                    activeBorderColor: s.darkPrimary,
                    hoverBorderColor:  rgba(s.darkPrimary, 0.40),
                    activeShadow:      `0 0 0 2px ${rgba(s.darkPrimary, 0.12)}`,
                },
                Button: {
                    fontWeight: 600,
                },
                Checkbox: {
                    colorBgContainer: 'transparent',
                },
                Table: {
                    headerBg:    s.darkElevated,
                    borderColor: rgba(s.darkPrimary, 0.12),
                    rowHoverBg:  rgba(s.darkPrimary, 0.04),
                },
                Card: {
                    colorBgContainer:     s.darkCard,
                    colorBorderSecondary: rgba(s.darkPrimary, 0.12),
                },
                Modal: {
                    contentBg: s.darkCard,
                    headerBg:  s.darkCard,
                },
                Select: {
                    colorBgContainer:  s.darkInput,
                    optionActiveBg:    rgba(s.darkPrimary, 0.08),
                    optionSelectedBg:  rgba(s.darkPrimary, 0.12),
                },
                Dropdown: {
                    colorBgElevated: s.darkElevated,
                },
                Tag: {
                    defaultBg:    rgba(s.darkPrimary, 0.10),
                    defaultColor: s.darkPrimary,
                },
                Steps: {
                    colorPrimary: s.darkPrimary,
                },
                Switch: {
                    colorPrimary: s.darkPrimary,
                },
                Pagination: {
                    itemActiveBg: rgba(s.darkPrimary, 0.12),
                },
                Badge: {
                    colorPrimary: s.darkPrimary,
                },
            },
        };
    }

    return {
        algorithm: antTheme.defaultAlgorithm,
        token: {
            ...LIGHT_SHARED,
            colorPrimary:    s.lightPrimary,
            colorBgLayout:   s.lightBg,
            colorLink:       s.lightPrimary,
            colorLinkHover:  s.lightHover,
        },
        components: {
            Button: {
                fontWeight:   600,
                primaryColor: s.lightContrast,
            },
            Table: {
                rowHoverBg: rgba(s.lightPrimary, 0.04),
            },
            Tag: {
                defaultBg:    rgba(s.lightPrimary, 0.08),
                defaultColor: s.lightPrimary,
            },
            Steps: {
                colorPrimary: s.lightPrimary,
            },
        },
    };
}

export const PALETTE_OPTIONS: { key: Palette; label: string; color: string }[] = [
    { key: 'green',  label: 'Verde',    color: '#1AE5A0' },
    { key: 'blue',   label: 'Azul',     color: '#38BDF8' },
    { key: 'red',    label: 'Rojo',     color: '#FF5A6A' },
    { key: 'yellow', label: 'Amarillo', color: '#FBBF24' },
];
