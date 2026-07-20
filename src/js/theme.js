/* src/js/theme.js - Toggle claro/oscuro con persistencia en localStorage.
   El tema inicial lo aplica un script inline en <head> antes de pintar. */
(() => {
  "use strict";

  const STORAGE_KEY = "agendarte-theme";
  const LEGACY_KEY = "agenduy-theme";
  const root = document.documentElement;
  const btn = document.getElementById("theme-toggle");
  if (!btn) return;

  function readStoredTheme() {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved === "dark" || saved === "light") return saved;
      const legacy = localStorage.getItem(LEGACY_KEY);
      if (legacy === "dark" || legacy === "light") return legacy;
    } catch (e) { /* almacenamiento no disponible */ }
    return root.getAttribute("data-theme") === "dark" ? "dark" : "light";
  }

  function apply(theme) {
    root.setAttribute("data-theme", theme);
    btn.setAttribute(
      "aria-label",
      theme === "dark" ? "Cambiar a tema claro" : "Cambiar a tema oscuro"
    );
    btn.setAttribute("aria-pressed", theme === "dark" ? "true" : "false");
  }

  apply(readStoredTheme());

  btn.addEventListener("click", () => {
    const next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
    apply(next);
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch (e) { /* el tema queda solo para esta vista */ }
  });
})();
