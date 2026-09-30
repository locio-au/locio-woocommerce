/*!
 * Locio address suggestions for WooCommerce checkout.
 *
 * Suggests Australian addresses from G-NAF as a shopper types the first
 * address line, then fills the street, suburb, state and postcode, and
 * records the address id so the order can carry it. Works on the classic
 * checkout and the block checkout.
 *
 * Plain script, no dependencies. Configured by window.locioCheckout, which
 * the plugin prints before this file.
 */
(function () {
  "use strict";

  // A secret key in a page can be read by anyone who opens it.
  var SECRET_KEY = /^[a-z]{2,}_live_/i;
  var DEBOUNCE_MS = 200;
  var BLUR_CLOSE_MS = 150;
  var STATES = { NSW: 1, VIC: 1, QLD: 1, WA: 1, SA: 1, TAS: 1, ACT: 1, NT: 1 };

  // ---- Pure functions: the mapping from a G-NAF record to checkout fields.

  function text(value) {
    return value === undefined || value === null ? "" : String(value).trim();
  }

  /** "SMITH STREET" -> "Smith Street", "O'CONNELL" -> "O'Connell". */
  function titleCase(value) {
    return text(value).replace(/\S+/g, capitalise);
  }

  function capitalise(word) {
    return word
      .toLowerCase()
      .replace(/(^|[\s\-'(\/])([a-z])/g, function (_, before, letter) {
        return before + letter.toUpperCase();
      });
  }

  /** Title case for a whole line, keeping state codes as the codes they are. */
  function displayCase(value) {
    return text(value)
      .split(/(\s+|,)/)
      .map(function (word) {
        return STATES[word.toUpperCase()] ? word.toUpperCase() : capitalise(word);
      })
      .join("");
  }

  function join(parts, separator) {
    return parts.filter(function (p) { return p !== ""; }).join(separator);
  }

  function addressId(address) {
    if (!address || typeof address !== "object") return "";
    return text(address.id) || text(address.address_detail_pid) || text(address.gnaf_pid);
  }

  function streetNumber(c) {
    var first = text(c.number_first) + text(c.number_first_suffix);
    if (first) {
      var last = text(c.number_last) + text(c.number_last_suffix);
      return last ? first + "-" + last : first;
    }
    // A new subdivision has a lot and no number yet.
    return text(c.lot_number) ? "Lot " + text(c.lot_number) : "";
  }

  /** The fields a WooCommerce address form has, from one address. */
  function toFields(address) {
    var c = address && typeof address.components === "object" && address.components ? address.components : {};
    var street = join([titleCase(c.street_name), titleCase(c.street_type), titleCase(c.street_suffix)], " ");
    var line1 = join([streetNumber(c), street], " ");
    if (!line1) {
      // No components: the first part of the formatted line is the street.
      line1 = displayCase(text(address && address.formatted).split(",")[0]);
    }
    var flat = join([titleCase(c.flat_type), text(c.flat_number)], " ");
    var level = join([titleCase(c.level_type), text(c.level_number)], " ");
    return {
      address_1: line1,
      address_2: join([flat, level, titleCase(c.building_name)], ", "),
      city: titleCase(c.locality_name) || titleCase(address && address.locality),
      state: text(c.state).toUpperCase(),
      postcode: text(c.postcode),
    };
  }

  // ---- The page.

  var state = null;

  function start(cfg) {
    stop();
    state = { cfg: cfg, attached: [], observer: null, jq: null, seq: 0 };

    var root = document.body || document.documentElement;
    if (typeof MutationObserver === "function" && root) {
      // Blocks mount after this script runs, and the classic checkout swaps
      // its fields when the country changes: attach whenever inputs appear.
      state.observer = new MutationObserver(scan);
      state.observer.observe(root, { childList: true, subtree: true });
    }
    if (typeof window.jQuery === "function" && document.body) {
      state.jq = window.jQuery(document.body);
      state.jq.on("updated_checkout", scan);
    }
    scan();
  }

  function stop() {
    if (!state) return;
    if (state.observer) state.observer.disconnect();
    if (state.jq && state.jq.off) state.jq.off("updated_checkout", scan);
    state.attached.forEach(function (detach) { detach(); });
    state = null;
  }

  /** Attach to every address line that is on the page and not yet attached. */
  function scan() {
    if (!state) return;
    var types = Array.isArray(state.cfg.types) ? state.cfg.types : ["billing", "shipping"];
    types.forEach(function (type) {
      if (type !== "billing" && type !== "shipping") return;
      var classic = document.getElementById(type + "_address_1");
      if (classic) attach(classic, type, "classic");
      var block = document.getElementById(type + "-address_1");
      if (block) attach(block, type, "blocks");
    });
  }

  function field(type, mode, name) {
    return document.getElementById(type + (mode === "classic" ? "_" : "-") + name);
  }

  function countryAllows(type, mode) {
    var country = field(type, mode, "country");
    if (!country) return true;
    var value = text(country.value).toUpperCase();
    return value === "" || value === "AU";
  }

  function attach(input, type, mode) {
    if (input.getAttribute("data-locio") === "1") return;
    input.setAttribute("data-locio", "1");

    var cfg = state.cfg;
    var i18n = cfg.i18n || {};
    var listId = "locio-" + type + "-" + mode + "-list";
    var minChars = Number(cfg.minChars) > 0 ? Number(cfg.minChars) : 3;
    var limit = Number(cfg.limit) > 0 ? Math.floor(Number(cfg.limit)) : 6;

    var panel = document.createElement("div");
    panel.className = "locio-panel";
    panel.hidden = true;
    var list = document.createElement("ul");
    list.id = listId;
    list.className = "locio-list";
    list.setAttribute("role", "listbox");
    list.setAttribute("aria-label", text(i18n.label) || "Address suggestions");
    var status = document.createElement("p");
    status.className = "locio-status";
    status.setAttribute("role", "status");
    var credit = document.createElement("p");
    credit.className = "locio-credit";
    credit.textContent = text(i18n.poweredBy) || "Addresses from G-NAF";
    panel.appendChild(list);
    panel.appendChild(status);
    panel.appendChild(credit);

    var anchor = input.parentNode;
    anchor.classList.add("locio-anchor");
    anchor.insertBefore(panel, input.nextSibling);

    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("aria-controls", listId);

    var results = [];
    var active = -1;
    var timer = null;
    var blurTimer = null;
    var controller = null;
    var picked = null;
    var filling = false;

    function close() {
      results = [];
      active = -1;
      while (list.firstChild) list.removeChild(list.firstChild);
      status.textContent = "";
      panel.hidden = true;
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
    }

    function highlight(index) {
      active = index;
      Array.prototype.forEach.call(list.children, function (li, i) {
        li.setAttribute("aria-selected", i === index ? "true" : "false");
        li.classList.toggle("locio-active", i === index);
      });
      if (index >= 0) {
        input.setAttribute("aria-activedescendant", listId + "-" + index);
        var li = list.children[index];
        if (li && li.scrollIntoView) li.scrollIntoView({ block: "nearest" });
      } else {
        input.removeAttribute("aria-activedescendant");
      }
    }

    function show(addresses, note) {
      close();
      results = addresses;
      addresses.forEach(function (address, i) {
        var li = document.createElement("li");
        li.id = listId + "-" + i;
        li.className = "locio-option";
        li.setAttribute("role", "option");
        li.setAttribute("aria-selected", "false");
        // textContent, always: this is somebody else's data on a checkout page.
        li.textContent = displayCase(address.formatted);
        li.addEventListener("mousedown", function (e) {
          // Keep focus in the input, so blur does not close the list first.
          e.preventDefault();
        });
        li.addEventListener("click", function () { choose(i); });
        list.appendChild(li);
      });
      if (!addresses.length) {
        status.textContent = text(note) || text(i18n.noResults) || "No matching address";
      }
      panel.hidden = false;
      input.setAttribute("aria-expanded", addresses.length ? "true" : "false");
    }

    function search(term) {
      if (controller) controller.abort();
      controller = typeof AbortController === "function" ? new AbortController() : null;
      var mine = ++state.seq;
      var url =
        text(cfg.baseUrl).replace(/\/+$/, "") +
        "/v1/addresses?" +
        new URLSearchParams({ q: term, limit: String(limit) }).toString();

      window
        .fetch(url, {
          method: "GET",
          headers: { Authorization: "Bearer " + cfg.publicKey, Accept: "application/json" },
          credentials: "omit",
          redirect: "error",
          signal: controller ? controller.signal : undefined,
        })
        .then(function (res) {
          if (!res.ok) throw new Error("HTTP " + res.status);
          return res.json();
        })
        .then(function (body) {
          if (!state || mine !== state.seq) return;
          var envelope = body && typeof body === "object" && !Array.isArray(body) ? body : {};
          var data = Array.isArray(envelope.data) ? envelope.data : [];
          var addresses = data.filter(function (a) {
            return a && typeof a === "object" && !Array.isArray(a);
          });
          show(addresses, typeof envelope.note === "string" ? envelope.note : "");
        })
        .catch(function () {
          // Quietly: a lookup that fails must never get in the way of typing.
          if (state && mine === state.seq) close();
        });
    }

    function storeId(id) {
      var form = input.form || (input.closest && input.closest("form"));
      if (form) {
        var name = "locio_" + type + "_address_id";
        var hidden = form.querySelector('input[name="' + name + '"]');
        if (!hidden) {
          hidden = document.createElement("input");
          hidden.type = "hidden";
          hidden.name = name;
          form.appendChild(hidden);
        }
        hidden.value = id;
      }
      if (mode === "blocks") {
        var checkout = store("wc/store/checkout");
        var data = {};
        data[type + "_address_id"] = id;
        if (checkout && typeof checkout.__internalSetExtensionData === "function") {
          checkout.__internalSetExtensionData("locio", data);
        } else if (checkout && typeof checkout.setExtensionData === "function") {
          checkout.setExtensionData("locio", data);
        }
      }
    }

    function fillClassic(fields) {
      filling = true;
      ["address_1", "address_2", "city", "state", "postcode"].forEach(function (name) {
        var el = field(type, "classic", name);
        if (!el) return;
        el.value = fields[name];
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
        // select2 and WooCommerce listen through jQuery.
        if (name === "state" && typeof window.jQuery === "function") {
          window.jQuery(el).trigger("change");
        }
      });
      filling = false;
    }

    function fillBlocks(fields) {
      var cart = store("wc/store/cart");
      var address = {
        address_1: fields.address_1,
        address_2: fields.address_2,
        city: fields.city,
        state: fields.state,
        postcode: fields.postcode,
        country: "AU",
      };
      var setter = type === "billing" ? "setBillingAddress" : "setShippingAddress";
      if (cart && typeof cart[setter] === "function") {
        cart[setter](address);
      } else {
        // No store to write through: set the input so the pick is not lost.
        filling = true;
        input.value = fields.address_1;
        filling = false;
      }
    }

    function choose(index) {
      var address = results[index];
      if (!address) return;
      var fields = toFields(address);
      if (mode === "classic") fillClassic(fields);
      else fillBlocks(fields);
      picked = fields.address_1;
      storeId(addressId(address));
      close();
    }

    function onInput() {
      if (filling) return;
      // A newer keystroke makes the request in flight worthless.
      if (controller) controller.abort();
      if (picked !== null && input.value !== picked) {
        // Edited by hand after a pick: the stored id no longer describes it.
        picked = null;
        storeId("");
      }
      clearTimeout(timer);
      var term = text(input.value);
      if (term.length < minChars || !countryAllows(type, mode)) {
        if (state) state.seq++;
        close();
        return;
      }
      timer = setTimeout(function () { search(term); }, DEBOUNCE_MS);
    }

    function onKeyDown(e) {
      if (panel.hidden || !results.length) return;
      if (e.key === "ArrowDown") {
        e.preventDefault();
        highlight((active + 1) % results.length);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        highlight(active <= 0 ? results.length - 1 : active - 1);
      } else if (e.key === "Enter" && active >= 0) {
        // Picking, not placing the order.
        e.preventDefault();
        e.stopPropagation();
        choose(active);
      } else if (e.key === "Escape") {
        close();
      }
    }

    function onBlur() {
      clearTimeout(blurTimer);
      blurTimer = setTimeout(close, BLUR_CLOSE_MS);
    }

    function onFocus() {
      clearTimeout(blurTimer);
    }

    input.addEventListener("input", onInput);
    input.addEventListener("keydown", onKeyDown);
    input.addEventListener("blur", onBlur);
    input.addEventListener("focus", onFocus);

    state.attached.push(function () {
      clearTimeout(timer);
      clearTimeout(blurTimer);
      if (controller) controller.abort();
      input.removeEventListener("input", onInput);
      input.removeEventListener("keydown", onKeyDown);
      input.removeEventListener("blur", onBlur);
      input.removeEventListener("focus", onFocus);
      input.removeAttribute("data-locio");
      input.removeAttribute("role");
      input.removeAttribute("aria-autocomplete");
      input.removeAttribute("aria-expanded");
      input.removeAttribute("aria-controls");
      input.removeAttribute("aria-activedescendant");
      if (panel.parentNode) panel.parentNode.removeChild(panel);
    });
  }

  function store(name) {
    var wp = window.wp;
    if (!wp || !wp.data || typeof wp.data.dispatch !== "function") return null;
    try {
      return wp.data.dispatch(name) || null;
    } catch (e) {
      return null;
    }
  }

  // Always defined, so tests and other scripts can reach the pure functions.
  // Holds no configuration: the key is never on it.
  window.LocioCheckout = {
    toFields: toFields,
    titleCase: titleCase,
    displayCase: displayCase,
    addressId: addressId,
    scan: scan,
    stop: stop,
  };

  var cfg = window.locioCheckout;
  if (!cfg || typeof cfg !== "object" || !text(cfg.publicKey)) return;
  if (SECRET_KEY.test(text(cfg.publicKey))) {
    // Never repeat the key: this line ends up in shared screenshots.
    console.warn(
      "Locio: address suggestions are off, because the key configured is a secret key " +
        "and this page is public. Set a public key (lc_pub_...) in WooCommerce > Settings > Locio.",
    );
    return;
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { start(cfg); });
  } else {
    start(cfg);
  }
})();
