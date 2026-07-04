import { useBreakpoints, breakpointsTailwind } from '@vueuse/core'

export function useResponsive() {
  const breakpoints = useBreakpoints(breakpointsTailwind)

  return {
    isMobile: breakpoints.smaller('md'),
    isTablet: breakpoints.between('md', 'lg'),
    isDesktop: breakpoints.greaterOrEqual('lg'),
  }
}
