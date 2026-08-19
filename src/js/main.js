/* src/js/main.js — Landing: carrusel y modales de rubros/planes */
(() => {
  "use strict";

  /*******************************
   *  A) CARRUSEL DE RUBROS
   *******************************/
  const carousel = document.querySelector(".hero-carousel");
  const viewport = document.querySelector(".hc-viewport");
  const track    = document.querySelector(".hc-track");
  const cards    = [...document.querySelectorAll(".hc-card")];
  const prev     = document.querySelector(".hc-btn.prev");
  const next     = document.querySelector(".hc-btn.next");
  const dotsBox  = document.querySelector(".hc-dots");

  if (viewport && track && cards.length) {
    let current = 0;
    let perView = 1;
    let pages   = 0;
    let timer   = null;

    function calcPerView(){
      const w = viewport.clientWidth;
      const desired = (w >= 1200) ? 3
                     : (w >= 900)  ? 3
                     : (w >= 600)  ? 2
                     :                1;

      perView = Math.max(1, Math.min(desired, cards.length));
      pages   = Math.max(1, Math.ceil(cards.length / perView));
      current = Math.min(current, pages - 1);
      carousel?.style.setProperty("--perView", perView);
      buildDots();
      goTo(current, false);
      toggleNav();
      carousel?.classList.toggle("is-single-page", pages <= 1);
      startAutoplay();
    }

    function buildDots(){
      if (!dotsBox) return;
      dotsBox.innerHTML = "";
      for (let i = 0; i < pages; i++){
        const b = document.createElement("button");
        b.className = "dot" + (i === current ? " active" : "");
        b.setAttribute("role", "tab");
        b.setAttribute("aria-label", `Ir al grupo ${i + 1}`);
        b.addEventListener("click", () => goTo(i));
        dotsBox.appendChild(b);
      }
    }

    function toggleNav(){
      const showNav = pages > 1;
      [prev, next].forEach(btn => {
        if (!btn) return;
        btn.style.display = showNav ? "" : "none";
        btn.tabIndex = showNav ? 0 : -1;
        btn.setAttribute("aria-hidden", showNav ? "false" : "true");
      });
      if (dotsBox) dotsBox.style.display = showNav ? "" : "none";
    }

    function stopAutoplay(){
      if (timer){
        clearInterval(timer);
        timer = null;
      }
    }

    function startAutoplay(){
      stopAutoplay();
      if (pages <= 1) return;
      timer = setInterval(() => nav(1), 5000);
    }

    function goTo(page, animate = true){
      current = Math.max(0, Math.min(page, pages - 1));
      const startIndex = current * perView;
      const targetCard = cards[startIndex];
      const maxOffset = Math.max(0, track.scrollWidth - viewport.clientWidth);
      const offset = Math.min(targetCard ? targetCard.offsetLeft : 0, maxOffset);
      track.style.transition = animate ? "transform .5s ease" : "none";
      track.style.transform  = `translateX(-${offset}px)`;
      if (dotsBox) {
        [...dotsBox.children].forEach((d, i) => d.classList.toggle("active", i === current));
      }
    }

    function nav(delta){
      if (!pages) return;
      let target = current + delta;
      if (target >= pages) target = 0;
      if (target <  0)     target = pages - 1;
      goTo(target);
      startAutoplay();
    }

    prev?.addEventListener("click", () => nav(-1));
    next?.addEventListener("click", () => nav(1));
    window.addEventListener("resize", calcPerView);
    viewport.addEventListener("mouseenter", stopAutoplay);
    viewport.addEventListener("mouseleave", startAutoplay);
    calcPerView();
  }

  /*******************************
   *  B) MODALES FETCH (rubros / planes)
   *******************************/
  function bindFetchModal(triggerId, modalId, contentId, url, onOpen) {
    const trigger = document.getElementById(triggerId);
    const modal = document.getElementById(modalId);
    const container = document.getElementById(contentId);
    if (!trigger || !modal || !container) return;

    const overlay = modal.querySelector(".u-modal__overlay");
    let escBound = false;
    let loading = false;

    function onEsc(event) {
      if (event.key === "Escape") closeModal();
    }

    function bindEsc() {
      if (escBound) return;
      document.addEventListener("keydown", onEsc);
      escBound = true;
    }

    function unbindEsc() {
      if (!escBound) return;
      document.removeEventListener("keydown", onEsc);
      escBound = false;
    }

    function closeModal() {
      modal.classList.add("hidden");
      document.body.classList.remove("modal-open");
      container.innerHTML = "";
      unbindEsc();
      if (overlay) overlay.removeEventListener("click", closeModal);
    }

    function attachInnerHandlers(root) {
      root.querySelector(".cat-close, .plan-close")?.addEventListener("click", closeModal);
      root.querySelectorAll(".plan-btn").forEach((button) => {
        button.addEventListener("click", closeModal);
      });
      if (typeof onOpen === "function") onOpen(root);
    }

    function openWith(html) {
      container.innerHTML = html;
      attachInnerHandlers(container);
      modal.classList.remove("hidden");
      document.body.classList.add("modal-open");
      bindEsc();
      if (overlay) overlay.addEventListener("click", closeModal, { once: true });
      container.querySelector("button, [href], input, select, textarea")?.focus({ preventScroll: true });
    }

    async function loadModal() {
      if (loading) return;
      loading = true;
      try {
        const response = await fetch(url, {
          headers: { "X-Requested-With": "fetch" },
          cache: "no-store"
        });
        if (!response.ok) throw new Error("No se pudo cargar el contenido.");
        openWith(await response.text());
      } catch (error) {
        openWith(`<div class="cat-empty"><p>${error?.message || "Error al cargar."}</p></div>`);
      } finally {
        loading = false;
      }
    }

    trigger.addEventListener("click", loadModal);
    trigger.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        loadModal();
      }
    });
  }

  bindFetchModal(
    "btnRubrosDisponibles",
    "modal-rubros",
    "modal-rubros-content",
    "src/components/categorias.php",
    (root) => window.AgenduyRegister?.bindCategoryButtons?.(root)
  );

  const btnPlanes = document.getElementById("btnPlanes");
  if (btnPlanes) {
    btnPlanes.addEventListener("click", (e) => {
      e.preventDefault();
      document.getElementById("planes")?.scrollIntoView({ behavior: "smooth" });
    });
    btnPlanes.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        document.getElementById("planes")?.scrollIntoView({ behavior: "smooth" });
      }
    });
  }

  // Inicializar toggle de facturacion en la seccion inline
  (function () {
    const section = document.querySelector(".planes-section");
    if (!section) return;
    const toggle = section.querySelector("[data-landing-billing-toggle]");
    let period = "monthly";
    const apply = () => {
      if (toggle) {
        toggle.querySelectorAll("button").forEach((btn) => {
          btn.classList.toggle("is-active", btn.getAttribute("data-billing") === period);
        });
      }
      section.querySelectorAll("[data-landing-plan]").forEach((card) => {
        const monthly = parseFloat(card.getAttribute("data-monthly") || "0");
        const yearly = card.getAttribute("data-yearly");
        const hasAnnual = card.getAttribute("data-has-annual") === "1";
        const amountEl = card.querySelector("[data-landing-price-amount]");
        const periodEl = card.querySelector("[data-landing-price-period]");
        const note = card.querySelector("[data-landing-annual-note]");
        const ctaBtn = card.querySelector(".plan-card__cta");
        const useYearly = period === "yearly" && hasAnnual && yearly !== "";
        if (amountEl && periodEl && monthly > 0) {
          amountEl.textContent = Math.round(useYearly ? parseFloat(yearly) : monthly).toLocaleString("es-UY");
          periodEl.textContent = useYearly ? "/ año" : "/ mes";
        }
        if (note) note.hidden = !useYearly;
        if (ctaBtn) {
          ctaBtn.setAttribute("data-billing-period", useYearly ? "yearly" : "monthly");
        }
      });
    };
    if (toggle) {
      toggle.addEventListener("click", (e) => {
        const btn = e.target.closest("[data-billing]");
        if (!btn) return;
        period = btn.getAttribute("data-billing") || "monthly";
        apply();
      });
    }
    apply();
  })();

  // Lógica de Tabs de Rubros (Agenda vs Tienda)
  (function () {
    const tabBtns = document.querySelectorAll(".business-type-tabs .tab-btn");
    const tabPanes = document.querySelectorAll(".tab-contents .tab-pane");
    tabBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        const targetId = btn.getAttribute("data-tab-target");
        
        // Update active tab button style
        tabBtns.forEach((b) => {
          b.classList.remove("active");
          b.style.background = "var(--surface)";
          b.style.color = "var(--text)";
          b.style.borderColor = "var(--border)";
        });
        btn.classList.add("active");
        btn.style.background = "var(--primary)";
        btn.style.color = "#fff";
        btn.style.borderColor = "var(--primary)";

        // Toggle active pane visibility
        tabPanes.forEach((pane) => {
          if (pane.id === targetId) {
            pane.style.display = "block";
            pane.classList.add("active");
          } else {
            pane.style.display = "none";
            pane.classList.remove("active");
          }
        });

        // Toggle pricing plan features (Agenda vs Tienda)
        const planFeaturesAgenda = document.querySelectorAll(".plan-features-agenda");
        const planFeaturesTienda = document.querySelectorAll(".plan-features-tienda");
        if (targetId === "agenda-rubros") {
          planFeaturesAgenda.forEach((el) => el.style.display = "block");
          planFeaturesTienda.forEach((el) => el.style.display = "none");
        } else if (targetId === "tienda-rubros") {
          planFeaturesAgenda.forEach((el) => el.style.display = "none");
          planFeaturesTienda.forEach((el) => el.style.display = "block");
        }
      });
    });
  })();
})();
