/**
 * AccuPOS Sync - Inventory report helpers
 * - поиск по названию / EAN13
 * - сортировка по колонкам
 * - печать только блока отчёта
 */

(function () {
  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function normalize(s) {
    return String(s || "").toLowerCase().trim();
  }

  function getCellText(row, idx) {
    var td = row.children[idx];
    return td ? normalize(td.textContent) : "";
  }

  function getCellNumber(row, idx) {
    var td = row.children[idx];
    if (!td) return 0;
    var v = normalize(td.textContent).replace(",", ".");
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }

  function initInventoryReport(root) {
    var table = qs("table[data-accupos-inventory-table]", root);
    if (!table) return;

    var tbody = qs("tbody", table);
    if (!tbody) return;

    var rows = qsa("tr", tbody);
    var searchInput = qs("[data-accupos-inventory-search]", root);
    var resetBtn = qs("[data-accupos-inventory-reset]", root);

    // Storage key (scoped per page)
    var storageKey = "accupos_inventory_state:" + window.location.pathname + window.location.search;
    function saveState(state) {
      try {
        window.localStorage.setItem(storageKey, JSON.stringify(state));
      } catch (e) {}
    }
    function loadState() {
      try {
        var raw = window.localStorage.getItem(storageKey);
        return raw ? JSON.parse(raw) : null;
      } catch (e) {
        return null;
      }
    }

    // --- Search ---
    if (searchInput) {
      searchInput.addEventListener("input", function () {
        var q = normalize(searchInput.value);
        rows.forEach(function (tr) {
          if (!q) {
            tr.style.display = "";
            return;
          }
          var hay = normalize(tr.textContent);
          tr.style.display = hay.indexOf(q) !== -1 ? "" : "none";
        });

        // persist search only (sort persisted elsewhere)
        var st = loadState() || {};
        st.q = searchInput.value || "";
        saveState(st);
      });
    }

    if (resetBtn && searchInput) {
      resetBtn.addEventListener("click", function (e) {
        e.preventDefault();
        searchInput.value = "";
        rows.forEach(function (tr) {
          tr.style.display = "";
        });
        // clear persisted state
        try {
          window.localStorage.removeItem(storageKey);
        } catch (e2) {}
        // reset sort to default
        applySort(0, "text", true);
      });
    }

    // --- Sort ---
    var headers = qsa("thead th[data-sort-idx]", table);
    var sortState = { idx: -1, dir: 1, type: "text" };

    function applySort(idx, type, forceAsc) {
      if (forceAsc) {
        sortState.idx = idx;
        sortState.dir = 1;
      } else if (sortState.idx === idx) {
        sortState.dir = sortState.dir * -1;
      } else {
        sortState.idx = idx;
        sortState.dir = 1;
      }
      sortState.type = type || "text";

      // reset UI
      headers.forEach(function (h) {
        h.classList.remove("accupos-sort-asc");
        h.classList.remove("accupos-sort-desc");
      });
      var active = headers.filter(function (h) {
        return parseInt(h.getAttribute("data-sort-idx"), 10) === idx;
      })[0];
      if (active) {
        active.classList.add(sortState.dir === 1 ? "accupos-sort-asc" : "accupos-sort-desc");
      }

      var sorted = rows
        .map(function (tr, i) {
          return { tr: tr, i: i };
        })
        .sort(function (a, b) {
          var va, vb;
          if (sortState.type === "number") {
            va = getCellNumber(a.tr, idx);
            vb = getCellNumber(b.tr, idx);
            if (va !== vb) return (va - vb) * sortState.dir;
          } else {
            va = getCellText(a.tr, idx);
            vb = getCellText(b.tr, idx);
            if (va < vb) return -1 * sortState.dir;
            if (va > vb) return 1 * sortState.dir;
          }
          // stable fallback by original index
          return a.i - b.i;
        })
        .map(function (x) {
          return x.tr;
        });

      sorted.forEach(function (tr) {
        tbody.appendChild(tr);
      });

      // persist sort
      var st = loadState() || {};
      st.sort = { idx: sortState.idx, dir: sortState.dir, type: sortState.type };
      saveState(st);
    }

    headers.forEach(function (th) {
      th.style.cursor = "pointer";
      th.addEventListener("click", function () {
        var idx = parseInt(th.getAttribute("data-sort-idx"), 10);
        var type = th.getAttribute("data-sort-type") || "text";
        applySort(idx, type);
      });
    });

    // restore state (search + sort) or default sort
    var initial = loadState();
    if (initial && searchInput && typeof initial.q === "string" && initial.q.length) {
      searchInput.value = initial.q;
      // apply filter immediately
      var q0 = normalize(searchInput.value);
      rows.forEach(function (tr) {
        var hay = normalize(tr.textContent);
        tr.style.display = hay.indexOf(q0) !== -1 ? "" : "none";
      });
    }

    if (initial && initial.sort && typeof initial.sort.idx === "number") {
      applySort(initial.sort.idx, initial.sort.type || "text", true);
      if (initial.sort.dir === -1) {
        // toggle once to get desc
        applySort(initial.sort.idx, initial.sort.type || "text");
      }
    } else {
      // default sort by name (col 0)
      applySort(0, "text", true);
    }

    // --- Print only report ---
    var printBtn = qs("[data-accupos-print-inventory]", root);
    if (printBtn) {
      printBtn.addEventListener("click", function (e) {
        e.preventDefault();
        var panel = root;
        // на всякий случай поднимаемся до панели отчёта
        if (panel && panel.closest) {
          panel = panel.closest(".accupos-inventory-report") || panel;
        }

        // Рендерим только отчёт в отдельный контейнер для печати
        var printRoot = document.getElementById("accupos-print-root");
        if (!printRoot) {
          printRoot = document.createElement("div");
          printRoot.id = "accupos-print-root";
          document.body.appendChild(printRoot);
        }

        // очистка предыдущей печати
        while (printRoot.firstChild) {
          printRoot.removeChild(printRoot.firstChild);
        }

        var clone = panel.cloneNode(true);
        // убираем UI элементы (поиск/кнопки), если они попали в клон
        qsa(".accupos-no-print", clone).forEach(function (el) {
          if (el && el.parentNode) el.parentNode.removeChild(el);
        });

        printRoot.appendChild(clone);

        function cleanup() {
          document.documentElement.classList.remove("accupos-print-inventory");
          // оставляем контейнер в DOM (дёшево), но очищаем содержимое
          while (printRoot.firstChild) {
            printRoot.removeChild(printRoot.firstChild);
          }
        }

        document.documentElement.classList.add("accupos-print-inventory");

        // afterprint отрабатывает не везде, поэтому делаем fallback timeout
        try {
          window.addEventListener(
            "afterprint",
            function () {
              cleanup();
            },
            { once: true }
          );
        } catch (e2) {}

        window.print();

        setTimeout(function () {
          cleanup();
        }, 500);
      });
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    qsa("[data-accupos-inventory-root]").forEach(function (root) {
      initInventoryReport(root);
    });
  });
})();


