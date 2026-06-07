/* =========================================================
   AutoCheck – KFZ-Gutachten · main.js
   ========================================================= */
(function () {
  "use strict";

  const $  = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

  /* ---------- Footer year ---------- */
  const yearEl = $("#year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  /* ---------- Sticky header shadow ---------- */
  const header = $("#header");
  const onScroll = () => {
    if (header) header.classList.toggle("is-scrolled", window.scrollY > 8);
    if (toTop) toTop.classList.toggle("is-visible", window.scrollY > 600);
  };

  /* ---------- Mobile navigation ---------- */
  const nav = $("#nav");
  const navToggle = $("#navToggle");

  // backdrop element (created once)
  const backdrop = document.createElement("div");
  backdrop.className = "nav-backdrop";
  document.body.appendChild(backdrop);

  const closeNav = () => {
    nav.classList.remove("is-open");
    backdrop.classList.remove("is-open");
    header.classList.remove("nav-open");
    document.body.classList.remove("nav-open");
    if (navToggle) navToggle.setAttribute("aria-expanded", "false");
  };
  const openNav = () => {
    nav.classList.add("is-open");
    backdrop.classList.add("is-open");
    header.classList.add("nav-open");
    document.body.classList.add("nav-open");
    navToggle.setAttribute("aria-expanded", "true");
  };

  if (navToggle && nav) {
    navToggle.addEventListener("click", () =>
      nav.classList.contains("is-open") ? closeNav() : openNav()
    );
    backdrop.addEventListener("click", closeNav);
    $$(".nav__link, .nav__cta", nav).forEach((a) =>
      a.addEventListener("click", closeNav)
    );
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeNav();
    });
  }

  /* ---------- Back to top ---------- */
  const toTop = $("#toTop");
  if (toTop) {
    toTop.addEventListener("click", () =>
      window.scrollTo({ top: 0, behavior: "smooth" })
    );
  }

  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* ---------- Scroll reveal ---------- */
  const reveals = $$(".reveal");
  if ("IntersectionObserver" in window && reveals.length) {
    const io = new IntersectionObserver(
      (entries, obs) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            obs.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
    );
    reveals.forEach((el) => io.observe(el));
  } else {
    reveals.forEach((el) => el.classList.add("is-visible"));
  }

  /* ---------- Animated counters ---------- */
  const counters = $$("[data-count]");
  const runCounter = (el) => {
    const target = parseFloat(el.dataset.count);
    const decimals = parseInt(el.dataset.decimals || "0", 10);
    const duration = 1500;
    const start = performance.now();
    const format = (v) =>
      decimals > 0
        ? (v / Math.pow(10, decimals)).toFixed(decimals).replace(".", ",")
        : Math.round(v).toLocaleString("de-DE");

    const tick = (now) => {
      const p = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
      el.textContent = format(target * eased);
      if (p < 1) requestAnimationFrame(tick);
      else el.textContent = format(target);
    };
    requestAnimationFrame(tick);
  };

  if ("IntersectionObserver" in window && counters.length) {
    const co = new IntersectionObserver(
      (entries, obs) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            runCounter(entry.target);
            obs.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.6 }
    );
    counters.forEach((el) => co.observe(el));
  }

  /* ---------- Active nav link on scroll ---------- */
  const navLinks = $$(".nav__link");
  const sections = navLinks
    .map((l) => document.getElementById(l.getAttribute("href").slice(1)))
    .filter(Boolean);

  if ("IntersectionObserver" in window && sections.length) {
    const so = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const id = entry.target.id;
            navLinks.forEach((l) =>
              l.classList.toggle(
                "is-active",
                l.getAttribute("href") === "#" + id
              )
            );
          }
        });
      },
      { rootMargin: "-45% 0px -50% 0px" }
    );
    sections.forEach((s) => so.observe(s));
  }

  /* ---------- FAQ: only one open at a time ---------- */
  const faqItems = $$(".faq__item");
  faqItems.forEach((item) => {
    item.addEventListener("toggle", () => {
      if (item.open) {
        faqItems.forEach((other) => {
          if (other !== item) other.open = false;
        });
      }
    });
  });

  /* ---------- Contact form ---------- */
  const form = $("#contactForm");
  const note = $("#formNote");

  if (form) {
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      note.className = "form__note";

      if (!form.checkValidity()) {
        form.reportValidity();
        note.textContent = "Bitte füllen Sie alle Pflichtfelder (*) aus.";
        note.classList.add("is-error");
        return;
      }

      const data = new FormData(form);
      const name = (data.get("name") || "").toString().trim();
      const email = (data.get("email") || "").toString().trim();
      const phone = (data.get("phone") || "").toString().trim();
      const service = (data.get("service") || "").toString();
      const message = (data.get("message") || "").toString().trim();

      // Ohne Backend: E-Mail-Client mit vorausgefüllter Nachricht öffnen.
      // Für automatischen Versand z. B. Formspree / Netlify Forms anbinden (siehe README).
      const subject = `Anfrage: ${service} – ${name}`;
      const body =
        `Name: ${name}\n` +
        `E-Mail: ${email}\n` +
        `Telefon: ${phone || "-"}\n` +
        `Leistung: ${service}\n\n` +
        `Nachricht:\n${message}\n`;

      const mailto =
        "mailto:info@autocheck-gutachten.de" +
        "?subject=" + encodeURIComponent(subject) +
        "&body=" + encodeURIComponent(body);

      window.location.href = mailto;

      note.textContent =
        "Vielen Dank! Ihr E-Mail-Programm öffnet sich – bitte senden Sie die Nachricht ab. Wir melden uns schnellstmöglich.";
      note.classList.add("is-success");
      form.reset();
    });
  }
})();
