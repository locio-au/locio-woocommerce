import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { vi } from "vitest";

// Read from the project root: under jsdom, import.meta.url is not a file URL.
const SOURCE = readFileSync(resolve(process.cwd(), "assets/checkout.js"), "utf8");

export const PUBLIC_KEY = "lc_pub_0123456789abcdef";

export function config(overrides = {}) {
  return {
    publicKey: PUBLIC_KEY,
    baseUrl: "https://api.locio.com.au",
    country: "AU",
    types: ["billing", "shipping"],
    minChars: 3,
    limit: 6,
    i18n: {
      label: "Address suggestions",
      noResults: "No matching address",
      poweredBy: "Addresses from G-NAF",
    },
    ...overrides,
  };
}

/** Runs assets/checkout.js the way a page would, after the config is set. Null: no config at all. */
export function load(cfg = config()) {
  window.LocioCheckout?.stop?.();
  delete window.LocioCheckout;
  if (cfg === null) delete window.locioCheckout;
  else window.locioCheckout = cfg;
  new Function(SOURCE)();
  return window.LocioCheckout;
}

export const ADDRESS = {
  id: "GANSW705023327",
  address_detail_pid: "GANSW705023327",
  country_code: "AU",
  formatted: "1 GEORGE STREET, SYDNEY NSW 2000",
  lat: -33.86,
  lng: 151.21,
  components: {
    number_first: "1",
    street_name: "GEORGE",
    street_type: "STREET",
    locality_name: "SYDNEY",
    state: "NSW",
    postcode: "2000",
  },
};

export const UNIT = {
  id: "GAVIC411711441",
  formatted: "UNIT 3, 145 SYDNEY ROAD, COBURG VIC 3058",
  lat: -37.74,
  lng: 144.96,
  components: {
    flat_type: "UNIT",
    flat_number: "3",
    number_first: "145",
    street_name: "SYDNEY",
    street_type: "ROAD",
    locality_name: "COBURG",
    state: "VIC",
    postcode: "3058",
  },
};

/** A fetch that answers every call with one body, and records the calls. */
export function fakeFetch(body = { data: [ADDRESS, UNIT] }, status = 200) {
  const calls = [];
  const fn = vi.fn((url, init) => {
    calls.push({ url: String(url), init });
    return new Promise((resolve, reject) => {
      const signal = init && init.signal;
      if (signal && signal.aborted) {
        reject(new DOMException("aborted", "AbortError"));
        return;
      }
      signal &&
        signal.addEventListener("abort", () => reject(new DOMException("aborted", "AbortError")));
      resolve(
        new Response(typeof body === "string" ? body : JSON.stringify(body), {
          status,
          headers: { "Content-Type": "application/json" },
        }),
      );
    });
  });
  window.fetch = fn;
  globalThis.fetch = fn;
  return { fn, calls };
}

export function classicCheckout() {
  document.body.innerHTML = `
    <form class="checkout woocommerce-checkout" name="checkout">
      ${["billing", "shipping"]
        .map(
          (t) => `
        <div class="woocommerce-${t}-fields">
          <select id="${t}_country" name="${t}_country"><option value="AU" selected>Australia</option><option value="NZ">New Zealand</option></select>
          <p class="form-row"><input id="${t}_address_1" name="${t}_address_1" autocomplete="address-line1"></p>
          <p class="form-row"><input id="${t}_address_2" name="${t}_address_2"></p>
          <p class="form-row"><input id="${t}_city" name="${t}_city"></p>
          <p class="form-row"><select id="${t}_state" name="${t}_state">
            <option value="">Select</option><option value="NSW">New South Wales</option><option value="VIC">Victoria</option>
          </select></p>
          <p class="form-row"><input id="${t}_postcode" name="${t}_postcode"></p>
        </div>`,
        )
        .join("")}
      <button type="submit" id="place_order">Place order</button>
    </form>`;
}

export function blocksCheckout() {
  document.body.innerHTML = `
    <form class="wc-block-checkout__form">
      ${["shipping", "billing"]
        .map(
          (t) => `
        <div id="${t}" class="wc-block-components-address-form">
          <select id="${t}-country"><option value="AU" selected>Australia</option><option value="NZ">New Zealand</option></select>
          <div class="wc-block-components-text-input"><input id="${t}-address_1"></div>
          <div class="wc-block-components-text-input"><input id="${t}-address_2"></div>
          <div class="wc-block-components-text-input"><input id="${t}-city"></div>
          <select id="${t}-state"><option value=""></option><option value="NSW">NSW</option><option value="VIC">VIC</option></select>
          <div class="wc-block-components-text-input"><input id="${t}-postcode"></div>
        </div>`,
        )
        .join("")}
    </form>`;
}

/** A fake wp.data with the two stores the script dispatches to. */
export function fakeWp({ internal = true } = {}) {
  const cart = { setBillingAddress: vi.fn(), setShippingAddress: vi.fn() };
  const checkout = internal
    ? { __internalSetExtensionData: vi.fn() }
    : { setExtensionData: vi.fn() };
  window.wp = {
    data: {
      dispatch: (store) => (store === "wc/store/cart" ? cart : store === "wc/store/checkout" ? checkout : {}),
    },
  };
  return { cart, checkout };
}

export function type(input, value) {
  input.focus();
  input.value = value;
  input.dispatchEvent(new Event("input", { bubbles: true }));
}

export function key(input, name) {
  const event = new KeyboardEvent("keydown", { key: name, bubbles: true, cancelable: true });
  input.dispatchEvent(event);
  return event;
}

/** Let the debounce fire and the fetch settle. */
export async function settle() {
  await vi.advanceTimersByTimeAsync(250);
  await vi.advanceTimersByTimeAsync(0);
}

export function options(input) {
  const list = document.getElementById(input.getAttribute("aria-controls"));
  return list ? Array.from(list.querySelectorAll('[role="option"]')) : [];
}
