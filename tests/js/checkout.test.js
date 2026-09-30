import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  ADDRESS,
  UNIT,
  blocksCheckout,
  classicCheckout,
  config,
  fakeFetch,
  fakeWp,
  key,
  load,
  options,
  settle,
  type,
} from "./helpers.js";

beforeEach(() => {
  vi.useFakeTimers();
  delete window.wp;
  delete window.jQuery;
});

afterEach(() => {
  window.LocioCheckout?.stop?.();
  vi.useRealTimers();
  document.body.innerHTML = "";
});

describe("mapping an address onto checkout fields", () => {
  let toFields;
  beforeEach(() => {
    document.body.innerHTML = "";
    fakeFetch();
    toFields = load().toFields;
  });

  it("maps a plain street address", () => {
    expect(toFields(ADDRESS)).toEqual({
      address_1: "1 George Street",
      address_2: "",
      city: "Sydney",
      state: "NSW",
      postcode: "2000",
    });
  });

  it("puts a unit on the second line", () => {
    expect(toFields(UNIT)).toMatchObject({ address_1: "145 Sydney Road", address_2: "Unit 3", city: "Coburg", state: "VIC" });
  });

  it("puts a unit, a level and a building name on the second line, in that order", () => {
    const a = {
      formatted: "",
      components: {
        flat_type: "APARTMENT",
        flat_number: "1204",
        level_type: "LEVEL",
        level_number: "12",
        building_name: "HARBOUR TOWER",
        number_first: "88",
        street_name: "GEORGE",
        street_type: "STREET",
        locality_name: "THE ROCKS",
        state: "NSW",
        postcode: "2000",
      },
    };
    expect(toFields(a)).toMatchObject({
      address_1: "88 George Street",
      address_2: "Apartment 1204, Level 12, Harbour Tower",
      city: "The Rocks",
    });
  });

  it("writes a number range with its suffixes", () => {
    const a = {
      formatted: "",
      components: {
        number_first: "12",
        number_first_suffix: "A",
        number_last: "14",
        number_last_suffix: "B",
        street_name: "SMITH",
        street_type: "STREET",
        street_suffix: "N",
      },
    };
    expect(toFields(a).address_1).toBe("12A-14B Smith Street N");
  });

  it("writes a lot when there is no street number", () => {
    const a = { formatted: "", components: { lot_number: "12", street_name: "BOUNDARY", street_type: "ROAD" } };
    expect(toFields(a).address_1).toBe("Lot 12 Boundary Road");
  });

  it("prefers the street number to the lot", () => {
    const a = { formatted: "", components: { lot_number: "12", number_first: "5", street_name: "BOUNDARY", street_type: "ROAD" } };
    expect(toFields(a).address_1).toBe("5 Boundary Road");
  });

  it("title cases hyphens and apostrophes", () => {
    const a = { formatted: "", components: { number_first: "3", street_name: "O'CONNELL", street_type: "STREET", locality_name: "BRIGHTON-LE-SANDS" } };
    expect(toFields(a)).toMatchObject({ address_1: "3 O'Connell Street", city: "Brighton-Le-Sands" });
  });

  it("falls back to the formatted line when there are no components", () => {
    const a = { formatted: "1 GEORGE STREET, SYDNEY NSW 2000", locality: "Sydney" };
    expect(toFields(a)).toEqual({ address_1: "1 George Street", address_2: "", city: "Sydney", state: "", postcode: "" });
  });

  it("leaves a missing field empty rather than writing undefined", () => {
    const a = { formatted: "", components: { street_name: "GEORGE", street_type: "STREET" } };
    const f = toFields(a);
    expect(f.address_1).toBe("George Street");
    expect(Object.values(f).join(" ")).not.toMatch(/undefined|null/);
  });

  it("reads the id under whichever name the service used", () => {
    const { addressId } = window.LocioCheckout;
    expect(addressId({ id: "A" })).toBe("A");
    expect(addressId({ address_detail_pid: "B" })).toBe("B");
    expect(addressId({ gnaf_pid: "C" })).toBe("C");
    expect(addressId({})).toBe("");
  });
});

describe("asking for suggestions", () => {
  it("waits for the debounce and sends one request", async () => {
    classicCheckout();
    const { calls } = fakeFetch();
    load();
    const input = document.getElementById("billing_address_1");
    type(input, "1 geo");
    type(input, "1 geor");
    type(input, "1 george");
    await vi.advanceTimersByTimeAsync(150);
    expect(calls).toHaveLength(0);
    await settle();
    expect(calls).toHaveLength(1);
    const url = new URL(calls[0].url);
    expect(url.origin + url.pathname).toBe("https://api.locio.com.au/v1/addresses");
    expect(url.searchParams.get("q")).toBe("1 george");
    expect(url.searchParams.get("limit")).toBe("6");
    expect(calls[0].init.headers.Authorization).toBe("Bearer lc_pub_0123456789abcdef");
    expect(calls[0].init.headers.Accept).toBe("application/json");
  });

  it("sends nothing under the minimum length", async () => {
    classicCheckout();
    const { calls } = fakeFetch();
    load();
    type(document.getElementById("billing_address_1"), "1 ");
    await settle();
    expect(calls).toHaveLength(0);
  });

  it("cancels the request a newer keystroke replaced", async () => {
    classicCheckout();
    const { calls } = fakeFetch();
    load();
    const input = document.getElementById("billing_address_1");
    type(input, "1 george");
    await vi.advanceTimersByTimeAsync(250);
    type(input, "1 george st");
    expect(calls[0].init.signal.aborted).toBe(true);
    await settle();
    expect(calls).toHaveLength(2);
    expect(calls[1].init.signal.aborted).toBe(false);
  });

  it("shows the options as an accessible listbox", async () => {
    classicCheckout();
    fakeFetch();
    load();
    const input = document.getElementById("billing_address_1");
    expect(input.getAttribute("role")).toBe("combobox");
    expect(input.getAttribute("aria-autocomplete")).toBe("list");
    expect(input.getAttribute("aria-expanded")).toBe("false");
    type(input, "1 george");
    await settle();
    const list = document.getElementById(input.getAttribute("aria-controls"));
    expect(list.getAttribute("role")).toBe("listbox");
    expect(list.getAttribute("aria-label")).toBe("Address suggestions");
    expect(input.getAttribute("aria-expanded")).toBe("true");
    expect(options(input).map((o) => o.textContent)).toEqual([
      "1 George Street, Sydney NSW 2000",
      "Unit 3, 145 Sydney Road, Coburg VIC 3058",
    ]);
    expect(document.body.textContent).toContain("Addresses from G-NAF");
  });

  it("shows the service's note when a country is not covered", async () => {
    classicCheckout();
    fakeFetch({ data: [], country_code: "NZ", note: "We hold no addresses for New Zealand yet." });
    load();
    const input = document.getElementById("billing_address_1");
    type(input, "1 queen st");
    await settle();
    expect(document.body.textContent).toContain("We hold no addresses for New Zealand yet.");
    expect(document.body.textContent).not.toContain("No matching address");
    expect(input.getAttribute("aria-expanded")).toBe("false");
  });

  it("says no match when there is no note", async () => {
    classicCheckout();
    fakeFetch({ data: [] });
    load();
    type(document.getElementById("billing_address_1"), "zzzz");
    await settle();
    expect(document.body.textContent).toContain("No matching address");
  });

  it("fails quietly on an error", async () => {
    classicCheckout();
    fakeFetch({ title: "too many requests" }, 429);
    const alert = vi.fn();
    window.alert = alert;
    load();
    const input = document.getElementById("billing_address_1");
    type(input, "1 george");
    await settle();
    expect(options(input)).toHaveLength(0);
    expect(input.getAttribute("aria-expanded")).toBe("false");
    expect(alert).not.toHaveBeenCalled();
    expect(input.value).toBe("1 george");
  });

  it("fails quietly when the network does", async () => {
    classicCheckout();
    window.fetch = globalThis.fetch = vi.fn(() => Promise.reject(new TypeError("Failed to fetch")));
    load();
    const input = document.getElementById("billing_address_1");
    type(input, "1 george");
    await settle();
    expect(input.getAttribute("aria-expanded")).toBe("false");
  });
});

describe("the country gate", () => {
  it("suggests nothing while another country is chosen", async () => {
    classicCheckout();
    const { calls } = fakeFetch();
    load();
    document.getElementById("billing_country").value = "NZ";
    type(document.getElementById("billing_address_1"), "1 queen st");
    await settle();
    expect(calls).toHaveLength(0);
  });

  it("suggests while no country is chosen yet", async () => {
    classicCheckout();
    const country = document.getElementById("shipping_country");
    country.insertAdjacentHTML("afterbegin", '<option value="">Choose</option>');
    country.value = "";
    const { calls } = fakeFetch();
    load();
    type(document.getElementById("shipping_address_1"), "1 george");
    await settle();
    expect(calls).toHaveLength(1);
  });

  it("gates each address form on its own country", async () => {
    blocksCheckout();
    fakeWp();
    const { calls } = fakeFetch();
    load();
    document.getElementById("billing-country").value = "NZ";
    type(document.getElementById("shipping-address_1"), "1 george");
    await settle();
    expect(calls).toHaveLength(1);
    type(document.getElementById("billing-address_1"), "1 george");
    await settle();
    expect(calls).toHaveLength(1);
  });
});

describe("the keyboard", () => {
  async function open() {
    classicCheckout();
    fakeFetch();
    load();
    const input = document.getElementById("billing_address_1");
    type(input, "1 george");
    await settle();
    return input;
  }

  it("moves through the options and wraps", async () => {
    const input = await open();
    const [first, second] = options(input);
    key(input, "ArrowDown");
    expect(input.getAttribute("aria-activedescendant")).toBe(first.id);
    expect(first.getAttribute("aria-selected")).toBe("true");
    key(input, "ArrowDown");
    expect(input.getAttribute("aria-activedescendant")).toBe(second.id);
    key(input, "ArrowDown");
    expect(input.getAttribute("aria-activedescendant")).toBe(first.id);
    key(input, "ArrowUp");
    expect(input.getAttribute("aria-activedescendant")).toBe(second.id);
  });

  it("picks with Enter and does not submit the checkout", async () => {
    const input = await open();
    const submit = vi.fn((e) => e.preventDefault());
    input.form.addEventListener("submit", submit);
    key(input, "ArrowDown");
    const event = key(input, "Enter");
    expect(event.defaultPrevented).toBe(true);
    expect(input.value).toBe("1 George Street");
    expect(submit).not.toHaveBeenCalled();
    expect(input.getAttribute("aria-expanded")).toBe("false");
  });

  it("leaves Enter alone when nothing is highlighted", async () => {
    const input = await open();
    expect(key(input, "Enter").defaultPrevented).toBe(false);
  });

  it("closes with Escape", async () => {
    const input = await open();
    key(input, "Escape");
    expect(input.getAttribute("aria-expanded")).toBe("false");
    expect(input.hasAttribute("aria-activedescendant")).toBe(false);
  });

  it("closes a moment after focus leaves, so a tap still lands", async () => {
    const input = await open();
    input.dispatchEvent(new FocusEvent("blur"));
    expect(input.getAttribute("aria-expanded")).toBe("true");
    await vi.advanceTimersByTimeAsync(300);
    expect(input.getAttribute("aria-expanded")).toBe("false");
  });

  it("picks with a click", async () => {
    const input = await open();
    const second = options(input)[1];
    const down = new MouseEvent("mousedown", { bubbles: true, cancelable: true });
    second.dispatchEvent(down);
    expect(down.defaultPrevented).toBe(true);
    second.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    expect(input.value).toBe("145 Sydney Road");
    expect(document.getElementById("billing_address_2").value).toBe("Unit 3");
  });
});

describe("classic checkout", () => {
  it("fills every field and fires change so WooCommerce recalculates", async () => {
    classicCheckout();
    fakeFetch();
    const triggered = [];
    window.jQuery = vi.fn((el) => ({
      trigger: (name) => triggered.push([el.id, name]),
      on: () => {},
      off: () => {},
    }));
    load();
    const state = document.getElementById("billing_state");
    const changed = vi.fn();
    state.addEventListener("change", changed);
    const input = document.getElementById("billing_address_1");
    type(input, "1 george");
    await settle();
    key(input, "ArrowDown");
    key(input, "Enter");

    expect(input.value).toBe("1 George Street");
    expect(document.getElementById("billing_address_2").value).toBe("");
    expect(document.getElementById("billing_city").value).toBe("Sydney");
    expect(state.value).toBe("NSW");
    expect(document.getElementById("billing_postcode").value).toBe("2000");
    expect(changed).toHaveBeenCalled();
    expect(triggered).toContainEqual(["billing_state", "change"]);
  });

  it("records the id in a hidden field and clears it on a hand edit", async () => {
    classicCheckout();
    fakeFetch();
    load();
    const input = document.getElementById("shipping_address_1");
    type(input, "145 sydney");
    await settle();
    key(input, "ArrowDown");
    key(input, "ArrowDown");
    key(input, "Enter");
    const hidden = input.form.querySelector('input[name="locio_shipping_address_id"]');
    expect(hidden.type).toBe("hidden");
    expect(hidden.value).toBe("GAVIC411711441");

    type(input, "145 Sydney Road West");
    expect(hidden.value).toBe("");
  });

  it("reuses a hidden field that is already there", async () => {
    classicCheckout();
    document.querySelector("form").insertAdjacentHTML("beforeend", '<input type="hidden" name="locio_billing_address_id" value="">');
    fakeFetch();
    load();
    const input = document.getElementById("billing_address_1");
    type(input, "1 george");
    await settle();
    key(input, "ArrowDown");
    key(input, "Enter");
    const hidden = document.querySelectorAll('input[name="locio_billing_address_id"]');
    expect(hidden).toHaveLength(1);
    expect(hidden[0].value).toBe("GANSW705023327");
  });

  it("attaches once however often it is asked", async () => {
    classicCheckout();
    const { calls } = fakeFetch();
    const api = load();
    api.scan();
    api.scan();
    const input = document.getElementById("billing_address_1");
    type(input, "1 george");
    await settle();
    expect(calls).toHaveLength(1);
    expect(document.querySelectorAll('[role="listbox"]')).toHaveLength(2);
  });

  it("attaches again when WooCommerce re-renders the fields", async () => {
    classicCheckout();
    const { calls } = fakeFetch();
    load();
    document.querySelector(".woocommerce-billing-fields").outerHTML = `
      <div class="woocommerce-billing-fields">
        <select id="billing_country"><option value="AU" selected>Australia</option></select>
        <p class="form-row"><input id="billing_address_1"></p>
      </div>`;
    await vi.advanceTimersByTimeAsync(0);
    const input = document.getElementById("billing_address_1");
    expect(input.getAttribute("role")).toBe("combobox");
    type(input, "1 george");
    await settle();
    expect(calls).toHaveLength(1);
  });

  it("listens for updated_checkout when jQuery is there", () => {
    classicCheckout();
    fakeFetch();
    const on = vi.fn();
    window.jQuery = vi.fn(() => ({ on, off: vi.fn(), trigger: vi.fn() }));
    load();
    expect(on).toHaveBeenCalledWith("updated_checkout", expect.any(Function));
  });
});

describe("block checkout", () => {
  it("attaches when the blocks mount late", async () => {
    document.body.innerHTML = "<div id='root'></div>";
    fakeFetch();
    fakeWp();
    load();
    const root = document.getElementById("root");
    blocksCheckout();
    document.body.prepend(root);
    await vi.advanceTimersByTimeAsync(0);
    expect(document.getElementById("shipping-address_1").getAttribute("role")).toBe("combobox");
  });

  it("fills through the store rather than the inputs", async () => {
    blocksCheckout();
    fakeFetch();
    const { cart, checkout } = fakeWp();
    load();
    const input = document.getElementById("shipping-address_1");
    type(input, "145 sydney");
    await settle();
    key(input, "ArrowDown");
    key(input, "ArrowDown");
    key(input, "Enter");

    expect(cart.setShippingAddress).toHaveBeenCalledWith({
      address_1: "145 Sydney Road",
      address_2: "Unit 3",
      city: "Coburg",
      state: "VIC",
      postcode: "3058",
      country: "AU",
    });
    expect(cart.setBillingAddress).not.toHaveBeenCalled();
    expect(checkout.__internalSetExtensionData).toHaveBeenCalledWith("locio", {
      shipping_address_id: "GAVIC411711441",
    });
    expect(input.form.querySelector('input[name="locio_shipping_address_id"]').value).toBe("GAVIC411711441");
  });

  it("uses setExtensionData where the internal one is missing", async () => {
    blocksCheckout();
    fakeFetch();
    const { cart, checkout } = fakeWp({ internal: false });
    load();
    const input = document.getElementById("billing-address_1");
    type(input, "1 george");
    await settle();
    key(input, "ArrowDown");
    key(input, "Enter");
    expect(cart.setBillingAddress).toHaveBeenCalled();
    expect(checkout.setExtensionData).toHaveBeenCalledWith("locio", { billing_address_id: "GANSW705023327" });
  });

  it("clears the stored id when the address is edited by hand", async () => {
    blocksCheckout();
    fakeFetch();
    const { checkout } = fakeWp();
    load();
    const input = document.getElementById("billing-address_1");
    type(input, "1 george");
    await settle();
    key(input, "ArrowDown");
    key(input, "Enter");
    input.value = "1 George Street";
    type(input, "1 George Street Rear");
    expect(checkout.__internalSetExtensionData).toHaveBeenLastCalledWith("locio", { billing_address_id: "" });
  });

  it("does nothing to the store when wp.data is absent", async () => {
    blocksCheckout();
    fakeFetch();
    load();
    const input = document.getElementById("billing-address_1");
    type(input, "1 george");
    await settle();
    key(input, "ArrowDown");
    expect(() => key(input, "Enter")).not.toThrow();
  });
});

describe("starting up", () => {
  it("does nothing without a config", () => {
    classicCheckout();
    fakeFetch();
    load(null);
    expect(document.getElementById("billing_address_1").hasAttribute("role")).toBe(false);
  });

  it("does nothing without a key", () => {
    classicCheckout();
    fakeFetch();
    load(config({ publicKey: "" }));
    expect(document.getElementById("billing_address_1").hasAttribute("role")).toBe(false);
  });

  it("attaches only to the types it was given", () => {
    classicCheckout();
    fakeFetch();
    load(config({ types: ["shipping"] }));
    expect(document.getElementById("billing_address_1").hasAttribute("role")).toBe(false);
    expect(document.getElementById("shipping_address_1").getAttribute("role")).toBe("combobox");
  });

  it("stops cleanly", async () => {
    classicCheckout();
    const { calls } = fakeFetch();
    const api = load();
    api.stop();
    type(document.getElementById("billing_address_1"), "1 george");
    await settle();
    expect(calls).toHaveLength(0);
  });
});
