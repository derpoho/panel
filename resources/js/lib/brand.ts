/**
 * The bloom and grain that the sign-in pages, the welcome flow and the error
 * page draw behind them. serveros.com's website keeps its own
 * copy with the rest of its brand pieces.
 */

/** The hero's gradient, in the product's cobalt and Mindcloud's shape:
    ink navy at the top, deepening into navy and cobalt lower down, then
    a pale sky rim into the page's pale grey. */
export const bloom =
    'radial-gradient(201.67% 105.6% at 50% -6.34%, #00000a 0%, #010a24 24%, #052070 40%, #1f5fe0 60%, #7fa8f5 78.5%, #f7f7f8 96.5%)';

/** Fine grain: fractal noise drawn once as a tile, laid over the bloom. */
export const grain = `url("data:image/svg+xml,${encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240"><filter id="n"><feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="3" stitchTiles="stitch"/><feColorMatrix values="0 0 0 0 0.5 0 0 0 0 0.5 0 0 0 0 0.5 0 0 0 9 -3.6"/></filter><rect width="100%" height="100%" filter="url(#n)"/></svg>',
)}")`;
