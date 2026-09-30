import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ADDRESS, classicCheckout, config, fakeFetch, key, load, options, settle, type } from "./helpers.js";

/**
 * The checkout page is the most sensitive page a shop has. This script puts a
 * key and somebody else's API answers into it, so: only a public key may run
 * here, the key rides in a header and nowhere else, nothing a shopper types
 * can reshape the request, and nothing the API answers is ever parsed as HTML.
 */

const SECRET = "lc_live_0123456789abcdef";

beforeEach(() => {
  vi.useFakeTimers();
  classicCheckout();
});

afterEach(() => {
  window.LocioCheckout?.stop?.();
  vi.useRealTimers();
  vi.restoreAllMocks();
  document.body.innerHTML = "";
});

describe("the key", () => {
  it("refuses a secret key, warns once, and does not repeat it", async () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const { calls } = fakeFetch();
    load(config({ publicKey: SECRET }));

    const input = document.getElementById("billing_address_1");
    expect(input.hasAttribute("role")).toBe(false);
    type(input, "1 george");
    await settle();
    expect(calls).toHaveLength(0);

    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls.flat().map(String).join(" ")).not.toContain(SECRET);
    expect(warn.mock.calls.flat().map(String).join(" ")).not.toContain("0123456789abcdef");
  });

  it("refuses a secret key from any tenant, in any case", () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    for (const publicKey of ["pf_live_abc", "LC_LIVE_abc", "xyz_live_abc"]) {
      classicCheckout();
      load(config({ publicKey }));
      expect(document.getElementById("billing_address_1").hasAttribute("role")).toBe(false);
    }
    expect(warn).toHaveBeenCalledTimes(3);
  });

  it("does not expose the key on the namespace it defines", () => {
    fakeFetch();
    const api = load();
    expect(JSON.stringify(Object.keys(api))).not.toContain("lc_pub_");
    for (const value of Object.values(api)) {
      if (typeof value === "string") expect(value).not.toContain("lc_pub_");
    }
  });
});

describe("what reaches the wire", () => {
  it("sends no cookies and refuses to follow a redirect", async () => {
    const { calls } = fakeFetch();
    load();
    type(document.getElementById("billing_address_1"), "1 george");
    await settle();
    expect(calls[0].init.credentials).toBe("omit");
    expect(calls[0].init.redirect).toBe("error");
    expect(calls[0].init.method ?? "GET").toBe("GET");
  });

  it("keeps the key out of the URL", async () => {
    const { calls } = fakeFetch();
    load();
    type(document.getElementById("billing_address_1"), "1 george");
    await settle();
    expect(calls[0].url).not.toContain("lc_pub_");
  });

  it("encodes the term so it cannot add a parameter", async () => {
    const { calls } = fakeFetch();
    load();
    type(document.getElementById("billing_address_1"), "1 george&limit=1000&key=x#frag");
    await settle();
    const url = new URL(calls[0].url);
    expect(url.searchParams.get("q")).toBe("1 george&limit=1000&key=x#frag");
    expect(url.searchParams.get("limit")).toBe("6");
    expect(url.searchParams.has("key")).toBe(false);
    expect(url.hash).toBe("");
  });

  it("sends requests only to the configured host", async () => {
    const { calls } = fakeFetch();
    load(config({ baseUrl: "https://api.locio.com.au/" }));
    type(document.getElementById("billing_address_1"), "//evil.example/x");
    await settle();
    expect(new URL(calls[0].url).host).toBe("api.locio.com.au");
    expect(new URL(calls[0].url).pathname).toBe("/v1/addresses");
  });
});

describe("what comes back", () => {
  it("renders an address as text, never as markup", async () => {
    const evil = {
      ...ADDRESS,
      formatted: '<img src=x onerror="window.__pwned=1">1 George St',
      components: { ...ADDRESS.components, street_name: '<img src=x onerror="window.__pwned=1">' },
    };
    fakeFetch({ data: [evil] });
    load();
    const input = document.getElementById("billing_address_1");
    type(input, "1 george");
    await settle();

    expect(document.querySelector("img")).toBeNull();
    expect(options(input)[0].textContent).toContain("<img");
    key(input, "ArrowDown");
    key(input, "Enter");
    expect(document.querySelector("img")).toBeNull();
    expect(window.__pwned).toBeUndefined();
  });

  it("renders the note as text, never as markup", async () => {
    fakeFetch({ data: [], note: '<a href="https://evil.example">click</a>' });
    load();
    type(document.getElementById("billing_address_1"), "1 george");
    await settle();
    expect(document.querySelector('a[href="https://evil.example"]')).toBeNull();
    expect(document.body.textContent).toContain('<a href="https://evil.example">click</a>');
  });

  it("renders the configured strings as text too", async () => {
    fakeFetch({ data: [ADDRESS] });
    load(config({ i18n: { label: "x", noResults: "y", poweredBy: "<b>G-NAF</b>" } }));
    type(document.getElementById("billing_address_1"), "1 george");
    await settle();
    expect(document.querySelector("b")).toBeNull();
    expect(document.body.textContent).toContain("<b>G-NAF</b>");
  });

  it("survives a body that is not the envelope", async () => {
    for (const body of ["null", "42", '"text"', "[1,2]", "{", '{"data":"nope"}', '{"data":[null,1,"x"]}']) {
      classicCheckout();
      fakeFetch(body);
      load();
      const input = document.getElementById("billing_address_1");
      type(input, "1 george");
      await settle();
      expect(options(input)).toHaveLength(0);
    }
  });

  it("does not trust the id it is given to be anything but text", async () => {
    fakeFetch({ data: [{ ...ADDRESS, id: '"><script>window.__pwned=1</script>' }] });
    load();
    const input = document.getElementById("billing_address_1");
    type(input, "1 george");
    await settle();
    key(input, "ArrowDown");
    key(input, "Enter");
    const hidden = input.form.querySelector('input[name="locio_billing_address_id"]');
    expect(hidden.value).toBe('"><script>window.__pwned=1</script>');
    expect(document.querySelector("script")).toBeNull();
  });
});
