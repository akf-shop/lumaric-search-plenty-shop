/**
 * lumaric-search.js
 *
 * Loaded on every page via the "LumaricSearch::GlobalScripts" container.
 *
 * Responsibilities
 * ────────────────
 * 1. SUGGEST / SEARCH INPUT
 *    Adds the "lumaric-search" CSS class to the shop's search input so that
 *    the Lumaric client.js widget can attach to it.
 *
 * 2. SEARCH RESULTS ORDERING
 *    On pages that contain the #lumaric-search-items <script> element (injected
 *    by searchResultItems.twig on the search-results page), reads the ordered
 *    item list produced by the PHP SearchController and re-orders product cards
 *    in the DOM to match Lumaric's ranking.
 *    Also appends click-tracking parameters to every product link.
 *
 * 3. SEARCH FORM INTERCEPT (optional, for full Lumaric results page sorting)
 *    When Lumaric search is enabled, intercepts the search form submission,
 *    calls /lumaric/search/field, and—before the page navigates—injects a
 *    hidden form field with the ordered SKU list so the server can pre-sort.
 *    For shops that use the native IO search this step is a progressive
 *    enhancement; if it is not needed it can be disabled via config.
 */
(async () => {
    'use strict';

    // ── 1. Mark the search input for the Lumaric suggest widget ──────────────
    function attachSuggestClass() {
        // plentyShop / Ceres search inputs
        const selectors = [
            'input[name="query"]',
            'input.search-input',
            '#search-input',
            '.header-search-input',
        ];
        for (const sel of selectors) {
            const input = document.querySelector(sel);
            if (input) {
                input.classList.add('lumaric-search');
                break;
            }
        }
    }

    // ── 2. Re-order search result items to match Lumaric ranking ─────────────

    /**
     * @typedef {{ link: string, document_id: string|number, position: string|number, product_number: string }} LumaricItem
     */

    /**
     * Reads the #lumaric-search-items <script> element, annotates product links
     * with click-tracking parameters, and re-sorts product cards in the DOM.
     */
    function applyLumaricSearchItems() {
        const scriptEl = document.getElementById('lumaric-search-items');
        if (!scriptEl) return;

        const token = scriptEl.getAttribute('data-lumaric-search-token') || '';
        /** @type {LumaricItem[]} */
        let items;
        try {
            items = JSON.parse(scriptEl.textContent || '[]');
        } catch {
            return;
        }
        if (!Array.isArray(items) || items.length === 0) return;

        // Build a map: product_number → item metadata
        /** @type {Map<string, LumaricItem>} */
        const itemMap = new Map(items.map(item => [String(item.product_number), item]));

        // Locate the product grid / list wrapper
        // Ceres uses .product-list or .search-result-list; adjust selector as needed
        const listWrapper = document.querySelector(
            '.product-list, .search-result-list, [data-component="item-list"], .js-listing-wrapper'
        );
        if (!listWrapper) return;

        /** @type {NodeListOf<HTMLElement>} */
        const cards = listWrapper.querySelectorAll('[data-item-id], .product-item, .product-list-item');
        if (cards.length === 0) return;

        // Sort cards according to Lumaric order
        const sortedCards = [...cards].sort((a, b) => {
            const skuA = a.getAttribute('data-sku') || a.getAttribute('data-item-number') || '';
            const skuB = b.getAttribute('data-sku') || b.getAttribute('data-item-number') || '';
            const posA = itemMap.has(skuA) ? Number(itemMap.get(skuA).position) : Infinity;
            const posB = itemMap.has(skuB) ? Number(itemMap.get(skuB).position) : Infinity;
            return posA - posB;
        });

        // Re-append in sorted order (moves nodes, no cloning needed)
        for (const card of sortedCards) {
            listWrapper.appendChild(card);
        }

        // ── Annotate product links with click-tracking parameters ─────────────
        if (!token) return;

        for (const card of sortedCards) {
            // Find the variation SKU stored on the card – fall back to searching links
            const sku = card.getAttribute('data-sku') || card.getAttribute('data-item-number');
            const itemMeta = sku ? itemMap.get(sku) : null;
            if (!itemMeta) continue;

            const links = card.querySelectorAll('a[href]');
            for (const anchor of links) {
                try {
                    const url = new URL(/** @type {HTMLAnchorElement} */ (anchor).href);
                    url.searchParams.set('lumaricDocumentId',       String(itemMeta.document_id));
                    url.searchParams.set('lumaricSearchToken',       token);
                    url.searchParams.set('lumaricDocumentPosition',  String(itemMeta.position));
                    /** @type {HTMLAnchorElement} */ (anchor).href = url.toString();
                } catch {
                    // href is not a valid absolute URL — skip
                }
            }
        }
    }

    // ── 3. Intercept search form submission and pre-sort via Lumaric API ──────

    /**
     * Intercepts the native shop search form.  Before the form is submitted the
     * handler calls /lumaric/search/field, receives ordered SKUs, and injects
     * them as a hidden input so the server (or a subsequent Vue update) can use
     * Lumaric's ranking.
     *
     * This is a best-effort progressive enhancement: if the /lumaric/search/field
     * endpoint is unavailable the form submits normally.
     */
    async function interceptSearchForm() {
        const form = document.querySelector(
            'form[action*="search"], .search-form, #search-form, [data-component="search-bar"] form'
        );
        if (!form) return;

        form.addEventListener('submit', async (event) => {
            const input = form.querySelector('input[name="query"], input[type="search"]');
            if (!input) return;

            const query = /** @type {HTMLInputElement} */ (input).value.trim();
            if (!query) return;

            // Prevent double-intercept
            if (form.dataset.lumaricIntercepted) return;
            form.dataset.lumaricIntercepted = '1';

            event.preventDefault();

            try {
                const res = await fetch('/lumaric/search/field', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ query, page: 1, pageSize: 100 }),
                });

                if (res.ok) {
                    const data = await res.json();
                    if (data && Array.isArray(data.items) && data.items.length > 0) {
                        // Provide ordered SKUs as a hidden field for server-side use
                        const hidden  = document.createElement('input');
                        hidden.type   = 'hidden';
                        hidden.name   = 'lumaricOrderedSkus';
                        hidden.value  = data.items.map((/** @type {{product_number:string}} */ i) => i.product_number).join(',');
                        form.appendChild(hidden);

                        if (data.token) {
                            const tokenInput  = document.createElement('input');
                            tokenInput.type   = 'hidden';
                            tokenInput.name   = 'lumaricToken';
                            tokenInput.value  = data.token;
                            form.appendChild(tokenInput);
                        }
                    }
                }
            } catch {
                // Silently fall back to native search
            }

            form.submit();
        });
    }

    // ── Initialise ────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        attachSuggestClass();
        applyLumaricSearchItems();
        interceptSearchForm();
    });
})();
